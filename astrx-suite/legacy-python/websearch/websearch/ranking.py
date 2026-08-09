"""Query parsing, safe FTS5 MATCH construction, scoring and snippets.

Ranking is deliberately *explicit*: FTS5's ``bm25()`` provides the text
relevance base (with per-field weights) and Python then folds in link
popularity, PageRank-lite, freshness and a proximity/phrase bonus.  The exact
formula is documented in :func:`score` and in the README.
"""

import html
import math
import re
import time
from datetime import datetime, timezone

from . import dedup

# ---- tunable weights (documented in the README) ---------------------------

W_TITLE = 10.0     # bm25 field weight: title
W_DESC = 4.0       # bm25 field weight: meta description
W_BODY = 1.0       # bm25 field weight: body text

K_LINK = 0.30      # coefficient on ln(1 + incoming internal links)
K_AUTH = 0.80      # coefficient on cross-domain host authority (0..1)
K_PR = K_AUTH      # back-compat alias (the "pagerank" weight is now authority)
K_FRESH = 0.20     # coefficient on freshness (0..1)
K_PROX = 0.60      # coefficient on proximity / phrase bonus (0..1)
K_QUALITY = 0.15   # anti-SEO: reward substantive content, penalise thin/doorway
OPTIC_BOOST = 0.80    # ranking optic: additive boost for a boost:host
OPTIC_PENALTY = 1.50  # ranking optic: additive penalty for a penalize:host
FRESH_HALFLIFE_DAYS = 30.0

CANDIDATE_CAP = 400   # how many bm25-ordered rows to re-rank in Python
SIMHASH_HAMMING = 3   # <=this many differing bits across hosts -> near-duplicate
                      # (set < 0 to disable near-dup collapsing)

_WORD = re.compile(r"[^\W_]+", re.UNICODE)
_TOKEN = re.compile(r'"[^"]*"|\S+', re.UNICODE)


# ---- query parsing ---------------------------------------------------------

class Query:
    __slots__ = ("optional", "required", "excluded", "phrases", "highlight",
                 "intitle", "site", "lang", "filetype", "after", "before",
                 "boost", "penalize", "raw")

    def __init__(self, raw):
        self.raw = raw
        self.optional = []   # plain terms (OR group)
        self.required = []   # +term
        self.excluded = []   # -term
        self.phrases = []    # list[list[str]]  each a phrase of words
        self.highlight = []  # every positive word, for snippet marking
        self.intitle = []    # intitle: terms (matched against the title column)
        self.site = None     # site:/host: host suffix filter
        self.lang = None     # lang: two-letter filter
        self.filetype = None # filetype: extension/type filter
        self.after = None    # after:/date: lower bound (epoch seconds)
        self.before = None   # before:/date: upper bound (epoch seconds)
        self.boost = []      # boost:host  ranking optic (raise these hosts)
        self.penalize = []   # penalize:host  ranking optic (lower these hosts)

    @property
    def is_empty(self):
        return not (self.optional or self.required or self.phrases
                    or self.intitle)

    @property
    def has_filter(self):
        return bool(self.site or self.lang or self.filetype
                    or self.after is not None or self.before is not None)


def _words(text):
    return _WORD.findall(text.lower())


# key:value operators pulled out of the query before FTS tokenization.
_OPERATOR = re.compile(
    r"^(site|host|lang|filetype|intitle|before|after|date|boost|penalize):(.+)$",
    re.IGNORECASE)


def _parse_date(value):
    """``YYYY-MM-DD`` -> UTC epoch seconds, or ``None`` if unparseable."""
    try:
        dt = datetime.strptime(value.strip(), "%Y-%m-%d")
    except ValueError:
        return None
    return dt.replace(tzinfo=timezone.utc).timestamp()


def _apply_operator(q, key, value):
    key = key.lower()
    value = value.strip()
    if not value:
        return
    if key in ("site", "host"):
        q.site = value.lower().strip("/").lstrip(".")
    elif key == "lang":
        w = _words(value)
        if w:
            q.lang = w[0][:8]
    elif key == "filetype":
        ft = _words(value)
        if ft:
            q.filetype = ft[0]
    elif key == "intitle":
        for w in _words(value):
            q.intitle.append(w)
            q.highlight.append(w)
    elif key == "before":
        ts = _parse_date(value)
        if ts is not None:
            q.before = ts
    elif key == "after":
        ts = _parse_date(value)
        if ts is not None:
            q.after = ts
    elif key == "date":
        lo, _, hi = value.partition("..")
        a = _parse_date(lo)
        if a is not None:
            q.after = a
        if hi:
            b = _parse_date(hi)
            if b is not None:
                q.before = b + 86400.0   # inclusive end day
    elif key in ("boost", "penalize"):
        h = value.lower().strip("/").lstrip(".")
        if h:
            (q.boost if key == "boost" else q.penalize).append(h)


def parse_query(raw):
    """Parse a user query into a structured :class:`Query`.

    Supported syntax: ``"exact phrase"``, ``+required``, ``-excluded``, bare
    optional terms, and the ``key:value`` operators ``site:``/``host:``,
    ``lang:``, ``filetype:``, ``intitle:``, ``before:``/``after:``/``date:``.
    All free text is discarded down to word tokens, so no user input can reach
    FTS5 as an operator; operators become structured filters (never raw SQL).
    """
    q = Query(raw or "")
    for tok in _TOKEN.findall(q.raw or ""):
        if tok.startswith('"') and tok.endswith('"') and len(tok) >= 2:
            words = _words(tok)
            if len(words) >= 2:
                q.phrases.append(words)
                q.highlight.extend(words)
            elif words:
                q.required.append(words[0])
                q.highlight.append(words[0])
            continue
        m = _OPERATOR.match(tok)
        if m:
            _apply_operator(q, m.group(1), m.group(2))
            continue
        sign = ""
        if tok[:1] in "+-":
            sign = tok[0]
            tok = tok[1:]
        words = _words(tok)
        if not words:
            continue
        if sign == "-":
            q.excluded.extend(words)
        elif sign == "+":
            q.required.extend(words)
            q.highlight.extend(words)
        else:
            q.optional.extend(words)
            q.highlight.extend(words)
    # de-duplicate while preserving order
    q.highlight = list(dict.fromkeys(q.highlight))
    return q


def _fts_term(word):
    """Quote a single token so FTS5 treats it as a literal string."""
    return '"' + word.replace('"', '""') + '"'


def build_match(q):
    """Build a safe FTS5 MATCH expression, or ``None`` if nothing to match."""
    clauses = []
    if q.optional:
        clauses.append("(" + " OR ".join(_fts_term(w) for w in q.optional) + ")")
    for w in q.required:
        clauses.append(_fts_term(w))
    for phrase in q.phrases:
        clauses.append('"' + " ".join(phrase) + '"')
    # intitle: -> FTS5 column filter, so the term must appear in the title.
    for w in q.intitle:
        clauses.append("title : " + _fts_term(w))
    if not clauses:
        return None
    expr = " AND ".join(clauses)
    if q.excluded:
        expr = "(" + expr + ")"
        for w in q.excluded:
            expr += " NOT " + _fts_term(w)
    return expr


# extension/type aliases for filetype: (content_type match + URL suffix match).
_FILETYPE_CT = {
    "pdf": "application/pdf", "txt": "text/plain", "text": "text/plain",
    "html": "text/html", "htm": "text/html", "md": "text/markdown",
    "markdown": "text/markdown", "json": "application/json",
    "csv": "text/csv", "xml": "application/xml",
}


def _like_escape(value):
    """Escape LIKE metacharacters so *value* matches literally under ``ESCAPE '\\'``.

    The ``%`` we prepend for a suffix match stays a wildcard; user-supplied
    ``%``/``_`` inside *value* are neutralised, so ``site:%`` cannot broaden the
    filter to every host.
    """
    return (value.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_"))


def _filter_sql(q):
    """Translate the structured filters into a parameterized SQL fragment.

    Returns ``(sql_fragment, params)`` where *sql_fragment* is either empty or
    begins with ``' AND '`` and every value is a bound parameter -- no user text
    is ever interpolated into SQL.  Suffix LIKE filters escape wildcard
    metacharacters in the user value (``ESCAPE '\\'``) so an operator value can
    never turn into a ``%``/``_`` wildcard.
    """
    clauses = []
    params = []
    if q.site:
        clauses.append("(d.host = ? OR d.host LIKE ? ESCAPE '\\')")
        params.append(q.site)
        params.append("%." + _like_escape(q.site))
    if q.lang:
        clauses.append("d.lang = ?")
        params.append(q.lang)
    if q.filetype:
        ct = _FILETYPE_CT.get(q.filetype)
        if ct:
            clauses.append(
                "(d.content_type = ? OR lower(d.url) LIKE ? ESCAPE '\\')")
            params.append(ct)
            params.append("%." + _like_escape(q.filetype))
        else:
            clauses.append("lower(d.url) LIKE ? ESCAPE '\\'")
            params.append("%." + _like_escape(q.filetype))
    if q.after is not None:
        clauses.append("d.fetched_at >= ?")
        params.append(q.after)
    if q.before is not None:
        clauses.append("d.fetched_at < ?")
        params.append(q.before)
    if not clauses:
        return "", []
    return " AND " + " AND ".join(clauses), params


# ---- scoring ---------------------------------------------------------------

def _freshness(fetched_at, now):
    if not fetched_at:
        return 0.0
    age_days = max(0.0, (now - fetched_at) / 86400.0)
    return math.exp(-age_days / FRESH_HALFLIFE_DAYS)


def _proximity_bonus(text, phrases, terms):
    """0..1 bonus.  Exact phrase present -> strong; otherwise reward terms that
    appear close together in the body."""
    if not text:
        return 0.0
    low = text.lower()
    bonus = 0.0
    for phrase in phrases:
        needle = " ".join(phrase)
        if needle and needle in low:
            bonus = max(bonus, 1.0)
    if bonus >= 1.0 or len(terms) < 2:
        return bonus
    tokens = _WORD.findall(low)
    positions = {}
    wanted = set(terms)
    for i, tk in enumerate(tokens):
        if tk in wanted:
            positions.setdefault(tk, []).append(i)
    present = [p for p in positions if positions[p]]
    if len(present) < 2:
        return bonus
    firsts = [positions[t][0] for t in present]
    span = max(firsts) - min(firsts)
    coverage = len(present) / len(wanted)
    tightness = 1.0 / (1.0 + span / max(1, len(present)))
    return max(bonus, 0.5 * coverage + 0.5 * tightness)


def _content_quality(row):
    """Anti-SEO signal in 0..1 from body substance.  Thin/doorway pages (almost
    no body text) score ~0; a page with a paragraph or more scores ~1.  Computed
    at query time from the stored body, so no crawl/schema change is needed."""
    n = len((row["body"] or ""))
    if n >= 1200:
        return 1.0
    if n <= 100:
        return 0.0
    return (n - 100) / 1100.0


def score(row, q, now):
    """The explicit ranking function.

    ``final = relevance
              + K_LINK * ln(1 + incoming)
              + K_AUTH * host_authority
              + K_FRESH* freshness
              + K_PROX * proximity``

    where ``relevance = -bm25(fts, W_TITLE, W_DESC, W_BODY)`` (SQLite returns a
    negative bm25, so negating makes larger = better).  The text relevance base
    dominates; the other signals act as documented tie-breakers/boosts.  The
    authority term is the offline cross-domain host PageRank (``docs.host_rank``);
    the old per-site internal PageRank (``docs.rank``) is still computed and
    exposed but no longer drives ranking.
    """
    relevance = -row["bm"]
    link = K_LINK * math.log1p(row["incoming"] or 0)
    authority = K_AUTH * (row["host_rank"] or 0.0)
    fresh = K_FRESH * _freshness(row["fetched_at"], now)
    prox = K_PROX * _proximity_bonus(
        (row["title"] or "") + " . " + (row["body"] or ""),
        q.phrases, q.highlight)
    quality = K_QUALITY * _content_quality(row)
    # ranking optics: per-query host boost / penalty (site allow/deny-lean).
    optic = 0.0
    if q.boost or q.penalize:
        host = (row["host"] or "").lower()
        if host in q.boost:
            optic += OPTIC_BOOST
        if host in q.penalize:
            optic -= OPTIC_PENALTY
    total = relevance + link + authority + fresh + prox + quality + optic
    return total, {
        "relevance": relevance, "link": link, "authority": authority,
        "pagerank": K_PR * (row["rank"] or 0.0),  # informational, not summed
        "freshness": fresh, "proximity": prox, "quality": quality,
        "optic": optic,
    }


# ---- snippets --------------------------------------------------------------

def make_snippet(body, terms, width=280):
    """Query-biased, HTML-safe snippet with matched terms wrapped in <mark>.

    The text is HTML-escaped *first*, then whole-word matches are highlighted,
    so no attacker-controlled markup can survive (see the XSS test).
    """
    if not body:
        return ""
    termset = {t.lower() for t in terms}
    low = body.lower()
    tokens = list(_WORD.finditer(low))

    start = 0
    if termset and tokens:
        # Slide a window of `width` chars to maximise matched-term hits.
        hits = [m.start() for m in tokens if m.group() in termset]
        if hits:
            best_pos, best_count = hits[0], 0
            for h in hits:
                lo, hi = h, h + width
                c = sum(1 for x in hits if lo <= x < hi)
                if c > best_count:
                    best_count, best_pos = c, h
            start = max(0, best_pos - width // 4)

    end = min(len(body), start + width)
    # snap to word boundaries for tidiness
    if start > 0:
        sp = body.rfind(" ", start, start + 40)
        if sp != -1:
            start = sp + 1
    if end < len(body):
        sp = body.find(" ", end - 40, end)
        if sp != -1:
            end = sp
    fragment = body[start:end].strip()

    out = []
    if start > 0:
        out.append("&hellip; ")
    pos = 0
    for m in _WORD.finditer(fragment):
        s, e = m.start(), m.end()
        if s > pos:
            out.append(html.escape(fragment[pos:s]))
        word = fragment[s:e]
        if word.lower() in termset:
            out.append("<mark>" + html.escape(word) + "</mark>")
        else:
            out.append(html.escape(word))
        pos = e
    if pos < len(fragment):
        out.append(html.escape(fragment[pos:]))
    if end < len(body):
        out.append(" &hellip;")
    return "".join(out)


# ---- top-level search ------------------------------------------------------

class SearchResult:
    __slots__ = ("url", "title", "description", "snippet", "host",
                 "fetched_at", "score", "signals", "lang", "simhash")

    def __init__(self, **kw):
        for k in self.__slots__:
            setattr(self, k, kw.get(k))

    def as_dict(self):
        return {
            "url": self.url, "title": self.title, "host": self.host,
            "snippet_html": self.snippet, "score": round(self.score, 6),
            "fetched_at": self.fetched_at, "lang": self.lang,
            # 64-bit near-dup fingerprint, exposed so a federation aggregator can
            # collapse cross-host mirrors across shards exactly as a single node
            # does (JSON carries the full 64-bit integer without loss).
            "simhash": int(self.simhash or 0),
            "signals": {k: round(v, 6) for k, v in (self.signals or {}).items()},
        }


def _collapse_near_dups(scored, threshold=None):
    """Drop cross-host near-duplicates (mirrors) from a scored candidate list.

    Walks the list in descending score order and keeps the best representative
    of each SimHash cluster, folding out any later result whose fingerprint is
    within *threshold* bits of an already-kept result **on a different host**.
    Restricting to cross-host keeps genuine mirrors collapsing while never
    merging distinct same-site pages that merely share a template.  Exact-hash
    dedup already ran at crawl time; this is the fuzzy layer on top.
    """
    if threshold is None:
        threshold = SIMHASH_HAMMING
    if threshold < 0:
        return scored
    kept = []
    seen = []                      # list of (simhash, host) for kept results
    for item in scored:
        row = item[2]
        h = row["simhash"] or 0
        host = row["host"] or ""
        if h:
            if any(host != khost and dedup.near(h, kh, threshold)
                   for kh, khost in seen):
                continue           # a mirror of something already shown
            seen.append((h, host))
        kept.append(item)
    return kept


# Downloadable-file verticals: content types + URL suffixes treated as "files".
_FILE_CTS = (
    "application/pdf", "application/zip", "application/epub+zip",
    "application/msword", "application/vnd.ms-excel",
    "application/vnd.ms-powerpoint", "application/x-tar", "application/gzip",
    "application/x-7z-compressed", "application/rtf",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
)
_FILE_EXTS = ("pdf", "zip", "epub", "doc", "docx", "ppt", "pptx", "xls", "xlsx",
              "odt", "ods", "odp", "rtf", "tar", "gz", "tgz", "bz2", "7z", "rar",
              "csv", "djvu", "mobi", "azw3")


def _docfilter_sql(only_files):
    """SQL fragment restricting to downloadable files (content-type or suffix)."""
    if not only_files:
        return "", []
    clauses = ["d.content_type = ?" for _ in _FILE_CTS]
    params = list(_FILE_CTS)
    for ext in _FILE_EXTS:
        clauses.append("lower(d.url) LIKE ? ESCAPE '\\'")
        params.append("%." + ext)
    return " AND (" + " OR ".join(clauses) + ")", params


def search(conn, raw_query, page=1, page_size=10, now=None, sort="relevance",
           only_files=False):
    """Run a full search.  Returns ``(results, total, elapsed_seconds, query)``.

    ``sort='fresh'`` re-orders the matched candidates newest-first (the *news*
    vertical); ``only_files=True`` restricts to downloadable documents (the
    *files* vertical).  Both are query-time — no schema or crawl change.
    """
    if now is None:
        now = time.time()
    started = time.perf_counter()
    q = parse_query(raw_query)
    match = build_match(q)
    fsql, fparams = _filter_sql(q)
    dsql, dparams = _docfilter_sql(only_files)
    fsql += dsql
    fparams += dparams
    if match is None and not fsql:
        # Nothing to match and no filter to browse by.
        return [], 0, time.perf_counter() - started, q

    cols = ("d.url, d.title, d.description, d.body, d.host, "
            "d.fetched_at, d.incoming, d.rank, d.host_rank, d.lang, d.simhash")
    try:
        if match is not None:
            total = conn.execute(
                "SELECT COUNT(*) FROM fts JOIN docs d ON d.id = fts.rowid "
                "WHERE fts MATCH ?" + fsql, [match] + fparams
            ).fetchone()[0]
            rows = conn.execute(
                "SELECT " + cols + ", bm25(fts, ?, ?, ?) AS bm "
                "FROM fts JOIN docs d ON d.id = fts.rowid "
                "WHERE fts MATCH ?" + fsql + " ORDER BY bm LIMIT ?",
                [W_TITLE, W_DESC, W_BODY, match] + fparams + [CANDIDATE_CAP],
            ).fetchall()
        else:
            # Pure-filter browse (e.g. bare `site:` / `lang:`): no text scoring,
            # order by authority then recency.
            total = conn.execute(
                "SELECT COUNT(*) FROM docs d WHERE 1=1" + fsql, fparams
            ).fetchone()[0]
            rows = conn.execute(
                "SELECT " + cols + ", 0.0 AS bm FROM docs d WHERE 1=1" + fsql +
                " ORDER BY d.host_rank DESC, d.fetched_at DESC LIMIT ?",
                fparams + [CANDIDATE_CAP],
            ).fetchall()
    except Exception:
        # Any FTS/SQL problem -> empty result set rather than a 500.
        return [], 0, time.perf_counter() - started, q

    scored = []
    for r in rows:
        s, signals = score(r, q, now)
        scored.append((s, signals, r))
    scored.sort(key=lambda t: t[0], reverse=True)
    if sort == "fresh":
        # News vertical: re-order the matched candidates newest-first.  (The
        # candidate pool is still the top bm25 matches, so this surfaces the most
        # *recent relevant* pages, not merely the newest pages overall.)
        scored.sort(key=lambda t: t[2]["fetched_at"] or 0, reverse=True)

    # Fuzzy dedup: collapse cross-host mirrors so a result page is not three
    # copies of the same article on three domains.
    scored = _collapse_near_dups(scored)

    # Only the top CANDIDATE_CAP bm25 rows are re-ranked and paginable, so never
    # advertise (or page past) more results than actually exist here -- otherwise
    # the pager offers empty pages beyond the candidate window.
    total = min(total, len(scored))

    lo = max(0, (page - 1) * page_size)
    hi = lo + page_size
    results = []
    for s, signals, r in scored[lo:hi]:
        results.append(SearchResult(
            url=r["url"], title=r["title"] or r["url"],
            description=r["description"],
            snippet=make_snippet(r["body"] or r["description"] or "",
                                 q.highlight),
            host=r["host"], fetched_at=r["fetched_at"], score=s,
            signals=signals, lang=r["lang"], simhash=r["simhash"],
        ))
    elapsed = time.perf_counter() - started
    return results, total, elapsed, q
