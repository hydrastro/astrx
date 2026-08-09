"""Linear (backtracking-free) robots.txt path-glob matcher, shared by both
crawlers' robots parsers so there is ONE tested, ReDoS-safe implementation.

A robots path pattern uses ``*`` as a wildcard (any run of characters) and a
trailing ``$`` as an end-anchor; matching is ALWAYS anchored at the start of the
path (the de-facto robots rule). This is implemented with ``str`` prefix / find /
endswith scans only -- there is no regex and therefore no catastrophic
backtracking, so a hostile robots.txt whose Disallow pattern is (e.g.)
``/a*a*a*...*$`` can never hang the crawl (ReDoS).

Semantics are identical to ``re.match`` of the translated pattern
(``*`` -> ``.*``, optional trailing ``$``), which is what both crawlers relied
on before this extraction:

  * no wildcard, unanchored -> ``path.startswith(pattern)``
  * no wildcard, anchored   -> ``path == pattern``
  * wildcards               -> the literal segments must appear in order, the
                               first anchored at the start of the path, and (when
                               ``$``) the last segment must sit at the very end.
"""

from __future__ import annotations

import re

# Bound the pattern length as belt-and-suspenders. The matcher is linear
# regardless, but truncating keeps even pathological input trivially cheap.
# Truncating a literal only makes a Disallow prefix SHORTER (matching more, i.e.
# fetching less), so it can never turn a Disallow into an accidental over-fetch.
MAX_PATTERN_LEN = 4096

_STAR_RUN = re.compile(r"\*+")


def compile_glob(pattern: str):
    """Split a robots path pattern into ``(anchored_end, literal_segments)``.

    ``*`` is a wildcard, a trailing ``$`` anchors to the end of the path. Runs of
    ``*`` are collapsed (``.*.*`` == ``.*``) so the segment list stays minimal.
    """
    if len(pattern) > MAX_PATTERN_LEN:
        pattern = pattern[:MAX_PATTERN_LEN]
    anchored = pattern.endswith("$")
    if anchored:
        pattern = pattern[:-1]
    pattern = _STAR_RUN.sub("*", pattern)   # collapse runs of '*' (linear)
    return anchored, pattern.split("*")


def glob_match(segments, anchored: bool, path: str) -> bool:
    """Linear wildcard match of *path* against pre-split robots *segments*.

    Mirrors ``re.match`` of the translated pattern: anchored at the start of
    *path*, wildcards between literal segments, optional end-anchor.
    """
    n = len(segments)
    if n == 1:                       # no wildcard
        seg = segments[0]
        return path == seg if anchored else path.startswith(seg)
    if not path.startswith(segments[0]):
        return False
    pos = len(segments[0])
    for seg in segments[1:-1]:
        if not seg:
            continue
        idx = path.find(seg, pos)
        if idx == -1:
            return False
        pos = idx + len(seg)
    last = segments[-1]
    if not last:                     # pattern ended with '*' (or '*$')
        return True
    if anchored:
        return path.endswith(last) and len(path) - len(last) >= pos
    return path.find(last, pos) != -1
