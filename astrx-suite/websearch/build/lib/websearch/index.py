"""Document store + FTS5 inverted index + link graph, all in one SQLite file.

Schema
------
``docs``      one row per indexed page (url, title, description, body, host,
              lang, fetched_at, content_hash, http_status, incoming, rank).
``fts``       an external-content FTS5 table over (title, description, body),
              kept in sync with ``docs`` by triggers.  Field weighting for
              ranking is applied at query time via ``bm25(fts, wt, wd, wb)``.
``links``     the (src -> dst) link graph, used for incoming-link counts and a
              PageRank-lite signal.

The same database file also holds the crawl frontier (see :mod:`frontier`).

DEFERRED (by decision, not oversight): index scale-out / FTS sharding by
host-hash with fan-out query.  That is an architecture change gated on whether
web-scale is actually a goal; until then this stays a single SQLite file, which
keeps operations (backup, WAL checkpoint, read-only query handle) trivial.  All
ranking signals here are computed offline over that one file.
"""

import hashlib
import os
import re
import sqlite3
import time

from . import canonical, dedup

SCHEMA = """
PRAGMA journal_mode=WAL;
PRAGMA synchronous=NORMAL;
PRAGMA foreign_keys=ON;

CREATE TABLE IF NOT EXISTS docs (
    id            INTEGER PRIMARY KEY,
    url           TEXT UNIQUE NOT NULL,
    title         TEXT NOT NULL DEFAULT '',
    description   TEXT NOT NULL DEFAULT '',
    body          TEXT NOT NULL DEFAULT '',
    host          TEXT NOT NULL DEFAULT '',
    lang          TEXT NOT NULL DEFAULT '',
    fetched_at    REAL NOT NULL DEFAULT 0,
    content_hash  TEXT NOT NULL DEFAULT '',
    http_status   INTEGER NOT NULL DEFAULT 0,
    incoming      INTEGER NOT NULL DEFAULT 0,
    rank          REAL NOT NULL DEFAULT 0,   -- internal page PageRank-lite
    host_rank     REAL NOT NULL DEFAULT 0,   -- cross-domain host authority
    etag          TEXT NOT NULL DEFAULT '',  -- validators for conditional GET
    last_modified TEXT NOT NULL DEFAULT '',
    content_type  TEXT NOT NULL DEFAULT '',
    simhash       INTEGER NOT NULL DEFAULT 0 -- 64-bit near-dup fingerprint
);
CREATE INDEX IF NOT EXISTS ix_docs_host ON docs(host);
CREATE INDEX IF NOT EXISTS ix_docs_hash ON docs(content_hash);
CREATE INDEX IF NOT EXISTS ix_docs_fetched ON docs(fetched_at);

-- Cross-domain host authority (offline PageRank over the inter-host graph).
CREATE TABLE IF NOT EXISTS host_authority (
    host TEXT PRIMARY KEY,
    rank REAL NOT NULL DEFAULT 0
) WITHOUT ROWID;

CREATE VIRTUAL TABLE IF NOT EXISTS fts USING fts5 (
    title, description, body,
    content='docs', content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);

CREATE TRIGGER IF NOT EXISTS docs_ai AFTER INSERT ON docs BEGIN
    INSERT INTO fts(rowid, title, description, body)
    VALUES (new.id, new.title, new.description, new.body);
END;
CREATE TRIGGER IF NOT EXISTS docs_ad AFTER DELETE ON docs BEGIN
    INSERT INTO fts(fts, rowid, title, description, body)
    VALUES ('delete', old.id, old.title, old.description, old.body);
END;
CREATE TRIGGER IF NOT EXISTS docs_au AFTER UPDATE ON docs BEGIN
    INSERT INTO fts(fts, rowid, title, description, body)
    VALUES ('delete', old.id, old.title, old.description, old.body);
    INSERT INTO fts(rowid, title, description, body)
    VALUES (new.id, new.title, new.description, new.body);
END;

CREATE TABLE IF NOT EXISTS links (
    src      TEXT NOT NULL,
    dst      TEXT NOT NULL,
    internal INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (src, dst)
) WITHOUT ROWID;
CREATE INDEX IF NOT EXISTS ix_links_dst ON links(dst);

-- Image vertical: <img> metadata harvested from the ALREADY-crawled HTML of a
-- page (resolved src, alt, title, a little surrounding context), keyed by the
-- source document.  The image bytes are NEVER downloaded -- not here, not by the
-- crawler, not by the server; only metadata already present in the fetched HTML
-- is stored, and the browser (never the server) loads the thumbnail at view
-- time.  So this vertical adds no new fetch and no new SSRF surface.
CREATE TABLE IF NOT EXISTS images (
    id       INTEGER PRIMARY KEY,
    doc_id   INTEGER NOT NULL,
    page_url TEXT NOT NULL DEFAULT '',   -- source page (already crawled)
    src      TEXT NOT NULL,              -- absolute image URL (resolved)
    alt      TEXT NOT NULL DEFAULT '',
    title    TEXT NOT NULL DEFAULT '',
    context  TEXT NOT NULL DEFAULT '',   -- nearby text, for FTS relevance
    host     TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (doc_id) REFERENCES docs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_images_doc ON images(doc_id);

CREATE VIRTUAL TABLE IF NOT EXISTS images_fts USING fts5 (
    alt, title, context,
    content='images', content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);
CREATE TRIGGER IF NOT EXISTS images_ai AFTER INSERT ON images BEGIN
    INSERT INTO images_fts(rowid, alt, title, context)
    VALUES (new.id, new.alt, new.title, new.context);
END;
CREATE TRIGGER IF NOT EXISTS images_ad AFTER DELETE ON images BEGIN
    INSERT INTO images_fts(images_fts, rowid, alt, title, context)
    VALUES ('delete', old.id, old.alt, old.title, old.context);
END;
CREATE TRIGGER IF NOT EXISTS images_au AFTER UPDATE ON images BEGIN
    INSERT INTO images_fts(images_fts, rowid, alt, title, context)
    VALUES ('delete', old.id, old.alt, old.title, old.context);
    INSERT INTO images_fts(rowid, alt, title, context)
    VALUES (new.id, new.alt, new.title, new.context);
END;

-- Video vertical: video signals harvested from the ALREADY-crawled HTML of a
-- page -- <video>/<source>, known-player <iframe>, Open Graph / Twitter player
-- cards, schema.org VideoObject and direct media <a href>.  Exactly like the
-- image vertical, NOTHING is fetched: no media, no thumbnail, no embed is
-- downloaded -- not here, not by the crawler, not by the server; only metadata
-- already present in the fetched HTML is stored, and the browser (never the
-- server) loads any thumbnail/link at view time.  So this vertical adds no new
-- fetch and no new SSRF surface.  Every stored URL host has already had the
-- internal-IP denylist applied by the crawler.
CREATE TABLE IF NOT EXISTS videos (
    id            INTEGER PRIMARY KEY,
    doc_id        INTEGER NOT NULL,
    page_url      TEXT NOT NULL DEFAULT '',   -- source page (already crawled)
    video_url     TEXT NOT NULL DEFAULT '',   -- direct media / stream URL
    embed_url     TEXT NOT NULL DEFAULT '',   -- player embed URL
    watch_url     TEXT NOT NULL DEFAULT '',   -- canonical watch URL (if derived)
    title         TEXT NOT NULL DEFAULT '',
    thumbnail_url TEXT NOT NULL DEFAULT '',
    source        TEXT NOT NULL DEFAULT '',   -- player/source key
    duration      INTEGER,                    -- seconds, nullable
    context       TEXT NOT NULL DEFAULT '',   -- nearby text, for FTS relevance
    host          TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (doc_id) REFERENCES docs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_videos_doc ON videos(doc_id);

CREATE VIRTUAL TABLE IF NOT EXISTS videos_fts USING fts5 (
    title, context,
    content='videos', content_rowid='id',
    tokenize='unicode61 remove_diacritics 2'
);
CREATE TRIGGER IF NOT EXISTS videos_ai AFTER INSERT ON videos BEGIN
    INSERT INTO videos_fts(rowid, title, context)
    VALUES (new.id, new.title, new.context);
END;
CREATE TRIGGER IF NOT EXISTS videos_ad AFTER DELETE ON videos BEGIN
    INSERT INTO videos_fts(videos_fts, rowid, title, context)
    VALUES ('delete', old.id, old.title, old.context);
END;
CREATE TRIGGER IF NOT EXISTS videos_au AFTER UPDATE ON videos BEGIN
    INSERT INTO videos_fts(videos_fts, rowid, title, context)
    VALUES ('delete', old.id, old.title, old.context);
    INSERT INTO videos_fts(rowid, title, context)
    VALUES (new.id, new.title, new.context);
END;

-- Term dictionary over the document FTS index: powers /suggest prefix
-- autocomplete and the edit-distance "did you mean" fallback.  It reads live
-- from the fts index (no extra storage of its own).
CREATE VIRTUAL TABLE IF NOT EXISTS fts_vocab USING fts5vocab('fts', 'row');
"""


def connect(path, read_only=False):
    """Open (and, unless read-only, initialise) the database at *path*."""
    if read_only:
        try:
            conn = sqlite3.connect(
                "file:%s?mode=ro" % path, uri=True, timeout=30)
        except sqlite3.OperationalError:
            # Fall back to a normal handle (still only used for SELECTs).
            conn = sqlite3.connect(path, timeout=30)
        conn.row_factory = sqlite3.Row
        return conn
    conn = sqlite3.connect(path, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.executescript(SCHEMA)
    _migrate(conn)
    conn.commit()
    return conn


# Columns added after the original 1.0 schema; added idempotently so a database
# created by an earlier build keeps working after an upgrade.  (Fresh databases
# already have them from SCHEMA above.)
_DOC_COLUMNS = (
    ("host_rank", "REAL NOT NULL DEFAULT 0"),
    ("etag", "TEXT NOT NULL DEFAULT ''"),
    ("last_modified", "TEXT NOT NULL DEFAULT ''"),
    ("content_type", "TEXT NOT NULL DEFAULT ''"),
    ("simhash", "INTEGER NOT NULL DEFAULT 0"),
)


def _migrate(conn):
    """Add any columns/tables missing from an older on-disk database."""
    have = {r[1] for r in conn.execute("PRAGMA table_info(docs)")}
    for name, decl in _DOC_COLUMNS:
        if name not in have:
            # `name`/`decl` are module constants, never user input.
            conn.execute("ALTER TABLE docs ADD COLUMN %s %s" % (name, decl))
    conn.execute(
        "CREATE TABLE IF NOT EXISTS host_authority ("
        "host TEXT PRIMARY KEY, rank REAL NOT NULL DEFAULT 0) WITHOUT ROWID")


def content_hash(*parts):
    h = hashlib.sha256()
    for p in parts:
        h.update((p or "").encode("utf-8", "replace"))
        h.update(b"\x00")
    return h.hexdigest()


def hash_exists(conn, chash):
    row = conn.execute(
        "SELECT 1 FROM docs WHERE content_hash=? LIMIT 1", (chash,)
    ).fetchone()
    return row is not None


def upsert_document(conn, url, title, description, body, host=None, lang="",
                    fetched_at=None, chash=None, http_status=200,
                    etag="", last_modified="", content_type="", simhash=0):
    """Insert or replace the document for *url*.  Returns its rowid."""
    if host is None:
        host = canonical.host_of(url)
    if fetched_at is None:
        fetched_at = time.time()
    if chash is None:
        chash = content_hash(title, description, body)
    cur = conn.execute("SELECT id FROM docs WHERE url=?", (url,))
    row = cur.fetchone()
    if row is None:
        conn.execute(
            "INSERT INTO docs (url,title,description,body,host,lang,"
            "fetched_at,content_hash,http_status,etag,last_modified,"
            "content_type,simhash) "
            "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            (url, title, description, body, host, lang, fetched_at, chash,
             http_status, etag or "", last_modified or "", content_type or "",
             int(simhash or 0)),
        )
        rid = conn.execute("SELECT id FROM docs WHERE url=?", (url,)).fetchone()[0]
    else:
        rid = row[0]
        conn.execute(
            "UPDATE docs SET title=?,description=?,body=?,host=?,lang=?,"
            "fetched_at=?,content_hash=?,http_status=?,etag=?,last_modified=?,"
            "content_type=?,simhash=? WHERE id=?",
            (title, description, body, host, lang, fetched_at, chash,
             http_status, etag or "", last_modified or "", content_type or "",
             int(simhash or 0), rid),
        )
    return rid


def touch_revalidated(conn, url, fetched_at=None, etag=None, last_modified=None):
    """A conditional GET returned 304: the stored body is still current.

    Bump ``fetched_at`` (so freshness recovers and the recrawl clock resets) and
    refresh any validators the server re-sent, without touching the indexed
    content or its FTS rows.
    """
    if fetched_at is None:
        fetched_at = time.time()
    sets = ["fetched_at=?"]
    params = [fetched_at]
    if etag:
        sets.append("etag=?")
        params.append(etag)
    if last_modified:
        sets.append("last_modified=?")
        params.append(last_modified)
    params.append(url)
    conn.execute("UPDATE docs SET %s WHERE url=?" % ",".join(sets), params)


def get_validators(conn, url):
    """Return ``(etag, last_modified)`` for *url*, or ``('', '')`` if unknown."""
    row = conn.execute(
        "SELECT etag, last_modified FROM docs WHERE url=?", (url,)).fetchone()
    if row is None:
        return "", ""
    return row["etag"] or "", row["last_modified"] or ""


def due_for_recrawl(conn, interval, now=None):
    """Docs whose ``fetched_at + interval`` has passed -> ``[(url, host), ...]``.

    This is the recrawl scheduler's due-list: it is what turns a write-once
    index into one that refreshes itself.
    """
    if now is None:
        now = time.time()
    cutoff = now - float(interval)
    return [
        (r["url"], r["host"]) for r in conn.execute(
            "SELECT url, host FROM docs WHERE fetched_at > 0 "
            "AND fetched_at <= ? ORDER BY fetched_at", (cutoff,))
    ]


def add_links(conn, src, edges):
    """Record outbound links.  *edges* is an iterable of ``(dst, internal)``."""
    conn.executemany(
        "INSERT OR IGNORE INTO links (src, dst, internal) VALUES (?,?,?)",
        [(src, dst, 1 if internal else 0) for dst, internal in edges],
    )


def recompute_incoming(conn):
    """Refresh ``docs.incoming`` from the internal link graph."""
    conn.execute(
        "UPDATE docs SET incoming = ("
        "  SELECT COUNT(*) FROM links "
        "  WHERE links.dst = docs.url AND links.internal = 1)"
    )


def compute_pagerank(conn, damping=0.85, iterations=30, tol=1e-6):
    """PageRank-lite over the internal graph restricted to indexed docs.

    Results are written to ``docs.rank`` normalised to ``0..1`` (max = 1).
    Optional signal: with a single doc or no edges every page gets rank 0.
    """
    docs = [r[0] for r in conn.execute("SELECT url FROM docs")]
    n = len(docs)
    if n == 0:
        return
    idx = {u: i for i, u in enumerate(docs)}
    out = [[] for _ in range(n)]
    have_edges = False
    for src, dst in conn.execute(
        "SELECT src, dst FROM links WHERE internal=1"
    ):
        si = idx.get(src)
        di = idx.get(dst)
        if si is not None and di is not None and si != di:
            out[si].append(di)
            have_edges = True
    if not have_edges:
        conn.execute("UPDATE docs SET rank=0")
        return

    pr = [1.0 / n] * n
    base = (1.0 - damping) / n
    for _ in range(iterations):
        new = [base] * n
        dangling = 0.0
        for i in range(n):
            if out[i]:
                share = damping * pr[i] / len(out[i])
                for j in out[i]:
                    new[j] += share
            else:
                dangling += damping * pr[i] / n
        if dangling:
            for i in range(n):
                new[i] += dangling
        delta = sum(abs(new[i] - pr[i]) for i in range(n))
        pr = new
        if delta < tol:
            break

    top = max(pr) or 1.0
    conn.executemany(
        "UPDATE docs SET rank=? WHERE url=?",
        [(pr[idx[u]] / top, u) for u in docs],
    )


def compute_host_authority(conn, damping=0.85, iterations=50, tol=1e-6):
    """Cross-domain host-level PageRank -- the *real* authority signal.

    Builds a graph whose nodes are hosts and whose edges are **cross-domain**
    links (``host_of(src) != host_of(dst)``): a page on host A linking to a page
    on host B is an endorsement A->B.  Same-host (internal navigation) links are
    ignored -- those inflate a site's own pages and are exactly what the old
    per-site ``rank`` signal wrongly rewarded.

    Edge weight = number of distinct source pages carrying the link.  Results are
    written to ``host_authority`` and denormalised into ``docs.host_rank``,
    normalised to ``0..1`` (max = 1).  With no cross-domain edges every host
    scores 0 (the signal simply contributes nothing).
    """
    adj = {}                 # src_host -> {dst_host: weight}
    hosts = set()
    for src, dst in conn.execute("SELECT src, dst FROM links"):
        sh = canonical.host_of(src)
        dh = canonical.host_of(dst)
        if not sh or not dh or sh == dh:
            continue
        hosts.add(sh)
        hosts.add(dh)
        d = adj.setdefault(sh, {})
        d[dh] = d.get(dh, 0.0) + 1.0
    # Every indexed host is a node even if it has no cross-domain edges.
    for (h,) in conn.execute("SELECT DISTINCT host FROM docs WHERE host<>''"):
        if h:
            hosts.add(h)

    conn.execute("DELETE FROM host_authority")
    if not hosts:
        conn.execute("UPDATE docs SET host_rank=0")
        return
    nodes = sorted(hosts)
    idx = {h: i for i, h in enumerate(nodes)}
    n = len(nodes)
    out = [[] for _ in range(n)]      # list of (dst_index, weight)
    out_w = [0.0] * n
    have_edges = False
    for sh, targets in adj.items():
        si = idx[sh]
        for dh, w in targets.items():
            out[si].append((idx[dh], w))
            out_w[si] += w
            have_edges = True

    if not have_edges:
        conn.execute("UPDATE docs SET host_rank=0")
        return

    pr = [1.0 / n] * n
    base = (1.0 - damping) / n
    for _ in range(iterations):
        new = [base] * n
        dangling = 0.0
        for i in range(n):
            if out_w[i] > 0:
                factor = damping * pr[i] / out_w[i]
                for j, w in out[i]:
                    new[j] += factor * w
            else:
                dangling += damping * pr[i] / n
        if dangling:
            for i in range(n):
                new[i] += dangling
        delta = sum(abs(new[i] - pr[i]) for i in range(n))
        pr = new
        if delta < tol:
            break

    top = max(pr) or 1.0
    conn.executemany(
        "INSERT OR REPLACE INTO host_authority (host, rank) VALUES (?,?)",
        [(h, pr[idx[h]] / top) for h in nodes],
    )
    # Denormalise onto docs for a single-table ranking read.
    conn.execute(
        "UPDATE docs SET host_rank = COALESCE("
        "  (SELECT rank FROM host_authority WHERE host_authority.host = docs.host)"
        ", 0)")


def finalize(conn):
    """Post-crawl: recompute link counts, page PageRank and host authority.

    Also truncates the WAL so a subsequent read-only connection (the query
    server) sees a complete main database file.
    """
    recompute_incoming(conn)
    compute_pagerank(conn)
    compute_host_authority(conn)
    conn.commit()
    try:
        conn.execute("PRAGMA wal_checkpoint(TRUNCATE)")
    except sqlite3.OperationalError:
        pass


def stats(conn):
    """Return a dict of index statistics for the /about page and CLI."""
    d = {}
    d["docs"] = conn.execute("SELECT COUNT(*) FROM docs").fetchone()[0]
    d["hosts"] = conn.execute(
        "SELECT COUNT(DISTINCT host) FROM docs").fetchone()[0]
    d["links"] = conn.execute("SELECT COUNT(*) FROM links").fetchone()[0]
    row = conn.execute(
        "SELECT MIN(fetched_at), MAX(fetched_at) FROM docs "
        "WHERE fetched_at > 0").fetchone()
    d["oldest"] = row[0]
    d["newest"] = row[1]
    d["top_hosts"] = [
        (r[0], r[1]) for r in conn.execute(
            "SELECT host, COUNT(*) c FROM docs GROUP BY host "
            "ORDER BY c DESC LIMIT 10")
    ]
    d["languages"] = [
        (r[0], r[1]) for r in conn.execute(
            "SELECT lang, COUNT(*) c FROM docs WHERE lang<>'' "
            "GROUP BY lang ORDER BY c DESC LIMIT 10")
    ]
    # Frontier stats live in the same DB; tolerate their absence.
    try:
        d["frontier"] = {
            r[0]: r[1] for r in conn.execute(
                "SELECT status, COUNT(*) FROM frontier GROUP BY status")
        }
    except sqlite3.OperationalError:
        d["frontier"] = {}
    return d


# ---- image vertical --------------------------------------------------------
# Store <img> metadata already present in crawled HTML.  NO network fetch and NO
# SSRF surface: the crawler already downloaded the page; the image bytes are
# never fetched by us -- the browser loads the thumbnail from `src` at view time.

MAX_IMAGES_PER_DOC = 100
_IMG_WORD = re.compile(r"[^\W_]+", re.UNICODE)


def replace_images(conn, doc_id, page_url, host, images):
    """Replace the stored ``<img>`` metadata for document *doc_id*.

    *images* is an iterable of ``(src, alt, title, context)`` where *src* is an
    ALREADY-ABSOLUTE http(s) URL (the crawler resolves it against the page base
    before calling this).  Old rows for the doc are cleared first (so a recrawl
    refreshes them), then up to :data:`MAX_IMAGES_PER_DOC` are inserted.  Returns
    the number stored.  This method performs no network I/O whatsoever.
    """
    conn.execute("DELETE FROM images WHERE doc_id=?", (doc_id,))
    rows = []
    for src, alt, title, context in images:
        if not src:
            continue
        rows.append((doc_id, page_url or "", src, alt or "", title or "",
                     context or "", host or ""))
        if len(rows) >= MAX_IMAGES_PER_DOC:
            break
    if rows:
        conn.executemany(
            "INSERT INTO images (doc_id,page_url,src,alt,title,context,host) "
            "VALUES (?,?,?,?,?,?,?)", rows)
    return len(rows)


def image_search(conn, query, limit=30):
    """Full-text search over harvested image ``alt``/``title``/``context``.

    Returns a list of dicts ``{src, alt, title, page_url, host}``.  The query is
    reduced to word tokens and each is quoted as an FTS5 string literal, so no
    user input can reach FTS5 as an operator; bounded by *limit* and by a cap on
    the number of query terms.  Tolerates an old DB that lacks the image tables.
    """
    words = _IMG_WORD.findall((query or "").lower())[:12]
    if not words:
        return []
    match = " AND ".join('"' + w.replace('"', '""') + '"' for w in words)
    try:
        rows = conn.execute(
            "SELECT i.src, i.alt, i.title, i.page_url, i.host "
            "FROM images_fts f JOIN images i ON i.id = f.rowid "
            "WHERE images_fts MATCH ? ORDER BY bm25(images_fts) LIMIT ?",
            (match, int(limit))).fetchall()
    except sqlite3.OperationalError:
        return []
    return [{"src": r[0], "alt": r[1], "title": r[2],
             "page_url": r[3], "host": r[4]} for r in rows]


# ---- video vertical --------------------------------------------------------
# Store video metadata already present in crawled HTML.  NO network fetch and NO
# SSRF surface: the crawler already downloaded the page; no media/thumbnail/embed
# is ever fetched by us -- the browser loads them from their ORIGINAL URL at view
# time, and the crawler has already dropped any URL whose host is an internal-IP
# literal before calling this.

MAX_VIDEOS_PER_DOC = 100


def replace_videos(conn, doc_id, page_url, host, videos):
    """Replace the stored video metadata for document *doc_id*.

    *videos* is an iterable of ``(video_url, embed_url, watch_url, title,
    thumbnail_url, source, duration, context)`` tuples whose URLs are ALREADY
    absolute http(s) and already internal-IP-filtered by the crawler.  Old rows
    for the doc are cleared first (so a recrawl refreshes them), then up to
    :data:`MAX_VIDEOS_PER_DOC` are inserted.  A row with no linkable URL at all
    (video/embed/watch all empty) is skipped.  Returns the number stored.  This
    method performs no network I/O whatsoever.
    """
    conn.execute("DELETE FROM videos WHERE doc_id=?", (doc_id,))
    rows = []
    for (video_url, embed_url, watch_url, title, thumbnail_url, source,
         duration, context) in videos:
        if not (video_url or embed_url or watch_url):
            continue
        dur = None
        if duration is not None:
            try:
                dur = int(duration)
            except (TypeError, ValueError):
                dur = None
            if dur is not None and dur < 0:
                dur = None
        rows.append((doc_id, page_url or "", video_url or "", embed_url or "",
                     watch_url or "", title or "", thumbnail_url or "",
                     source or "", dur, context or "", host or ""))
        if len(rows) >= MAX_VIDEOS_PER_DOC:
            break
    if rows:
        conn.executemany(
            "INSERT INTO videos (doc_id,page_url,video_url,embed_url,watch_url,"
            "title,thumbnail_url,source,duration,context,host) "
            "VALUES (?,?,?,?,?,?,?,?,?,?,?)", rows)
    return len(rows)


def video_search(conn, query, limit=30):
    """Full-text search over harvested video ``title``/``context``.

    Returns a list of dicts with the stored video fields.  The query is reduced
    to word tokens and each is quoted as an FTS5 string literal, so no user input
    can reach FTS5 as an operator; bounded by *limit* and a cap on query terms.
    Tolerates an old DB that lacks the video tables.
    """
    words = _IMG_WORD.findall((query or "").lower())[:12]
    if not words:
        return []
    match = " AND ".join('"' + w.replace('"', '""') + '"' for w in words)
    try:
        rows = conn.execute(
            "SELECT v.video_url, v.embed_url, v.watch_url, v.title, "
            "v.thumbnail_url, v.source, v.duration, v.page_url, v.host "
            "FROM videos_fts f JOIN videos v ON v.id = f.rowid "
            "WHERE videos_fts MATCH ? ORDER BY bm25(videos_fts) LIMIT ?",
            (match, int(limit))).fetchall()
    except sqlite3.OperationalError:
        return []
    return [{"video_url": r[0], "embed_url": r[1], "watch_url": r[2],
             "title": r[3], "thumbnail_url": r[4], "source": r[5],
             "duration": r[6], "page_url": r[7], "host": r[8]} for r in rows]


# ---- suggest / autocomplete term source ------------------------------------

FUZZY_SCAN_CAP = 2000


def _prefix_upper(prefix):
    """Smallest string strictly greater than every string starting with *prefix*.

    Used as the exclusive upper bound of a term-index range scan.  Returns
    ``None`` -- so callers fall back to a GLOB scan -- when a valid successor
    character cannot be formed, namely:

      * *prefix* ends at the maximum code point (U+10FFFF), or
      * incrementing its last character lands in the UTF-16 surrogate range
        (U+D800..U+DFFF).  A lone surrogate is a legal Python ``str`` char but is
        NOT UTF-8 encodable, so binding it as a SQLite parameter raises
        ``UnicodeEncodeError``.  Without this guard a query fragment ending in
        U+D7FF would make ``_prefix_upper`` return U+D800 and crash the vocab
        range scan (and, upstream, the ``/suggest`` endpoint).
    """
    nxt = ord(prefix[-1]) + 1
    if 0xD800 <= nxt <= 0xDFFF:
        return None
    try:
        return prefix[:-1] + chr(nxt)
    except ValueError:
        return None


def vocab_prefix(conn, prefix, limit=10):
    """Indexed terms beginning with *prefix*, most-frequent first.

    Uses the ``fts5vocab`` term dictionary with a range scan on the (sorted)
    term index, so cost is O(matches), not O(vocabulary).  Returns
    ``[(term, doc_count), ...]``.  Tolerates a DB without the vocab table.
    """
    prefix = (prefix or "").lower()
    if not prefix:
        return []
    hi = _prefix_upper(prefix)
    try:
        if hi is not None:
            rows = conn.execute(
                "SELECT term, doc FROM fts_vocab WHERE term >= ? AND term < ? "
                "ORDER BY doc DESC, term LIMIT ?",
                (prefix, hi, int(limit))).fetchall()
        else:
            rows = conn.execute(
                "SELECT term, doc FROM fts_vocab WHERE term >= ? AND term GLOB ? "
                "ORDER BY doc DESC, term LIMIT ?",
                (prefix, prefix + "*", int(limit))).fetchall()
    except (sqlite3.OperationalError, UnicodeEncodeError):
        # OperationalError: DB lacks the vocab table.  UnicodeEncodeError:
        # belt-and-suspenders for any lone-surrogate bound value (see
        # _prefix_upper) so a hostile fragment can never reach the endpoint.
        return []
    return [(r[0], r[1]) for r in rows]


def vocab_candidates(conn, word, limit=FUZZY_SCAN_CAP):
    """A BOUNDED sample of frequent terms sharing *word*'s first character.

    The edit-distance "did you mean" pass scans only this capped set (typos
    usually preserve the first letter), so a long or adversarial query can never
    provoke an unbounded vocabulary scan.  Returns ``[(term, doc_count), ...]``.
    """
    word = (word or "").lower()
    if not word:
        return []
    c0 = word[0]
    hi = _prefix_upper(c0)
    try:
        if hi is not None:
            rows = conn.execute(
                "SELECT term, doc FROM fts_vocab WHERE term >= ? AND term < ? "
                "ORDER BY doc DESC LIMIT ?", (c0, hi, int(limit))).fetchall()
        else:
            rows = conn.execute(
                "SELECT term, doc FROM fts_vocab WHERE term >= ? "
                "ORDER BY doc DESC LIMIT ?", (c0, int(limit))).fetchall()
    except (sqlite3.OperationalError, UnicodeEncodeError):
        return []
    return [(r[0], r[1]) for r in rows]


# ---- more-like-this (SimHash Hamming neighbours) ---------------------------

MLT_HAMMING = 12       # <= this many differing SimHash bits -> "related"
MLT_SCAN_CAP = 20000   # hard bound on the candidate scan


def get_doc(conn, doc_id=None, url=None):
    """Fetch one document row by id or url (``None`` if not found)."""
    if doc_id is not None:
        return conn.execute(
            "SELECT id,url,title,description,body,host,lang,fetched_at,simhash "
            "FROM docs WHERE id=?", (int(doc_id),)).fetchone()
    if url is not None:
        return conn.execute(
            "SELECT id,url,title,description,body,host,lang,fetched_at,simhash "
            "FROM docs WHERE url=?", (url,)).fetchone()
    return None


def more_like_this(conn, doc_id=None, url=None, limit=20,
                   max_hamming=MLT_HAMMING, scan_cap=MLT_SCAN_CAP):
    """Documents near a given doc by SimHash Hamming distance.

    Reuses the crawl-time SimHash fingerprint (``docs.simhash``): scans a bounded
    candidate set, keeps rows within *max_hamming* bits of the source, EXCLUDES
    the source itself, and returns ``(source_row, [neighbour_rows])`` ordered by
    ascending distance then recency, capped at *limit*.  ``source_row`` is
    ``None`` when the id/url is unknown; the neighbour list is empty when the
    source has no fingerprint.  No network I/O.
    """
    src = get_doc(conn, doc_id=doc_id, url=url)
    if src is None:
        return None, []
    sh = src["simhash"] or 0
    if not sh:
        return src, []
    rows = conn.execute(
        "SELECT id,url,title,description,host,lang,fetched_at,simhash "
        "FROM docs WHERE simhash <> 0 AND id <> ? "
        "ORDER BY fetched_at DESC LIMIT ?",
        (src["id"], int(scan_cap))).fetchall()
    scored = []
    for r in rows:
        dist = dedup.hamming(sh, r["simhash"])
        if dist <= max_hamming:
            scored.append((dist, -(r["fetched_at"] or 0.0), r))
    scored.sort(key=lambda t: (t[0], t[1]))
    return src, [r for _d, _f, r in scored[:limit]]


# ---- backup ----------------------------------------------------------------

# An RFC 3986 scheme prefix (``scheme:``) at the very start of a destination.
# SQLite's ``VACUUM INTO`` resolves ``file:`` URIs -- including query parameters
# such as ``?mode=rwc`` -- regardless of the connection's URI flag, and such a
# URI has no "://" (e.g. ``file:/path`` or ``file:path``), so a bare "://"
# substring check is not enough.  Refusing ANY leading ``scheme:`` keeps the
# destination a plain filesystem path, so the exists() clobber guard below
# (which tests the literal string) cannot be side-stepped by a URI form that
# resolves elsewhere.  A normal path never has a scheme prefix; ``./name:x`` or
# an absolute ``/name:x`` are unaffected (they don't start with a scheme).
_URI_SCHEME = re.compile(r"^[A-Za-z][A-Za-z0-9+.\-]*:")


def backup(src_path, dest_path, integrity_check=True):
    """Safe on-line backup of the index DB via ``VACUUM INTO``.

    Produces a defragmented copy of *src_path* at *dest_path* while the source
    may still be in use (``VACUUM INTO`` takes only a read transaction, so the
    live query server keeps serving).  Purely local file I/O -- no network.
    *dest_path* must be a plain filesystem path (a URI is refused) and must not
    already exist (SQLite refuses to overwrite, which guards a live DB against
    being clobbered).  Optionally runs ``PRAGMA integrity_check`` on the copy.
    Returns the document count in the resulting database.
    """
    if _URI_SCHEME.match(dest_path):
        raise ValueError(
            "backup destination must be a local filesystem path, not a "
            "URI/scheme (got %r)" % (dest_path,))
    # Refuse to write over anything that already exists: never clobber a live DB
    # (and don't rely on VACUUM INTO's own -- version-dependent -- refusal).
    if os.path.exists(dest_path):
        raise FileExistsError("backup destination already exists: %s" % dest_path)
    src = sqlite3.connect(src_path, timeout=30)
    try:
        # Bound parameter -> the path is never interpolated into SQL text.
        src.execute("VACUUM main INTO ?", (dest_path,))
    finally:
        src.close()
    dst = sqlite3.connect(dest_path, timeout=30)
    try:
        if integrity_check:
            res = dst.execute("PRAGMA integrity_check").fetchone()
            if not res or res[0] != "ok":
                raise sqlite3.DatabaseError(
                    "integrity_check failed on backup: %r" % (res,))
        return dst.execute("SELECT COUNT(*) FROM docs").fetchone()[0]
    finally:
        dst.close()
