"""Query autocomplete: prefix completion + a bounded edit-distance fallback.

Two signals feed the ``/suggest`` endpoint (see :mod:`websearch.server`):

  * **prefix completion** of the final query word from the FTS term dictionary
    (``fts5vocab``, via :func:`index.vocab_prefix`) and from recently-popular
    queries tracked in-process by the server, and
  * an edit-distance **"did you mean"** fallback over a *bounded* sample of
    terms (:func:`index.vocab_candidates`), used only when the prefix pass is
    thin.

Everything is bounded so a long or adversarial query cannot burn CPU: the query
is length-capped, the fuzzy pass scans only a capped candidate set and only for
fragments of a minimum length, each Levenshtein call early-exits once it
provably exceeds the cap, and the suggestion list itself is capped.  Pure
standard library.
"""

from . import index

MAX_SUGGESTIONS = 10
MAX_QUERY_LEN = 64
FUZZY_MIN_LEN = 3          # don't fuzzy-match very short fragments


def levenshtein(a, b, max_dist):
    """Levenshtein edit distance between *a* and *b*, capped at ``max_dist``.

    Returns ``max_dist + 1`` as soon as the distance provably exceeds
    ``max_dist`` (row-minimum early exit and a length-difference shortcut), so
    callers can rely on the bound rather than paying full O(len(a)*len(b)) for
    obviously-distant pairs.
    """
    la, lb = len(a), len(b)
    if abs(la - lb) > max_dist:
        return max_dist + 1
    if a == b:
        return 0
    prev = list(range(lb + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        row_best = i
        for j, cb in enumerate(b, 1):
            cost = 0 if ca == cb else 1
            v = min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost)
            cur.append(v)
            if v < row_best:
                row_best = v
        if row_best > max_dist:
            return max_dist + 1
        prev = cur
    return prev[-1]


def suggest(conn, q, popular=None, limit=MAX_SUGGESTIONS):
    """Return up to *limit* autocomplete suggestions for query *q*.

    *popular* is an optional iterable of recently-popular query strings
    (most-frequent first).  Suggestions are de-duplicated case-insensitively and
    preserve the earlier (typed) words while completing the final one.
    """
    q = (q or "").strip()
    if not q:
        return []
    low = q[:MAX_QUERY_LEN].lower()
    words = low.split()
    if not words:
        return []
    last = words[-1]
    head = " ".join(words[:-1])
    prefix_head = (head + " ") if head else ""

    out = []
    seen = set()

    def _add(s):
        s = s.strip()
        key = s.lower()
        if s and key not in seen and len(out) < limit:
            seen.add(key)
            out.append(s)

    # (0) recently-popular queries that extend exactly what was typed.
    for pq in (popular or ()):
        pl = (pq or "").strip().lower()
        if pl and pl != low and pl.startswith(low):
            _add(pq)
        if len(out) >= limit:
            return out[:limit]

    # (a) prefix completion of the final word from the indexed term dictionary.
    for term, _cnt in index.vocab_prefix(conn, last, limit=limit * 2):
        if term != last:
            _add(prefix_head + term)
        if len(out) >= limit:
            return out[:limit]

    # (b) edit-distance "did you mean" fallback -- only when the prefix pass was
    #     thin, and only over the bounded candidate sample.
    if len(out) < max(3, limit // 2) and len(last) >= FUZZY_MIN_LEN:
        max_dist = 1 if len(last) <= 4 else 2
        cands = []
        for term, cnt in index.vocab_candidates(conn, last):
            if term.startswith(last) or term == last:
                continue          # prefix pass already covers these
            d = levenshtein(last, term, max_dist)
            if d <= max_dist:
                cands.append((d, -cnt, term))
        cands.sort()
        for _d, _nc, term in cands:
            _add(prefix_head + term)
            if len(out) >= limit:
                break
    return out[:limit]
