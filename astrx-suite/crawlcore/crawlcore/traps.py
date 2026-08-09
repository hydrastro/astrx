"""Pure, stateless structural bot-trap / tarpit predicates.

These are the *stateless* half of trap defense - shape checks on an already
parsed path (and, for the calendar-bomb check, already parsed query pairs). The
*stateful* counters (per-template counts, per-host budgets, skeleton sets) stay
in each crawler's own crash-safe store; only these pure predicates are shared.

Two calling conventions are supported so both crawlers can adopt this without
changing their public signatures:

  * path-based (``too_deep`` / ``repeated_segment`` / ``cyclic_path`` /
    ``is_path_trap`` / ``looks_like_pagination`` / ``numericish``) - used by
    onioncrawler, which has already parsed the path.
  * count helpers (``depth`` / ``segment_repeat_max`` / ``query_param_count``)
    - used by websearch's URL-string trap heuristics.
"""

from __future__ import annotations

import re

_NUMERICISH = re.compile(r"^[0-9]+$")
_DATEISH = re.compile(r"^\d{4}(-\d{1,2}(-\d{1,2})?)?$")


def path_segments(path: str) -> list[str]:
    """Non-empty ``/``-separated segments of *path*."""
    return [s for s in path.split("/") if s]


def depth(path: str) -> int:
    """Number of non-empty path segments."""
    return len(path_segments(path))


def too_deep(path: str, max_segments: int) -> bool:
    """True if the path has more than *max_segments* non-empty segments."""
    return len(path_segments(path)) > max_segments


def segment_repeat_max(path: str) -> int:
    """Largest number of times any single path segment repeats.

    ``/a/b/a/a`` -> 3 (the segment ``a``). Detects ``/x/x/x/...`` style traps.
    """
    counts: dict[str, int] = {}
    top = 0
    for s in path_segments(path):
        counts[s] = counts.get(s, 0) + 1
        if counts[s] > top:
            top = counts[s]
    return top


def repeated_segment(path: str, max_repeats: int) -> bool:
    """True if any single path segment repeats more than *max_repeats* times.

    Catches /a/a/a/a and /x/junk/x/junk-ish accumulation where a segment is
    appended over and over.
    """
    counts: dict[str, int] = {}
    for s in path_segments(path):
        counts[s] = counts.get(s, 0) + 1
        if counts[s] > max_repeats:
            return True
    return False


def cyclic_path(path: str, max_cycle_len: int = 3, max_cycles: int = 2) -> bool:
    """Detect a repeating *sequence* of segments, e.g. /a/b/a/b/a/b.

    For cycle length L in 1..max_cycle_len, if the tail of the path is the same
    L-gram repeated more than *max_cycles* times, it is a trap.
    """
    segs = path_segments(path)
    n = len(segs)
    for L in range(1, max_cycle_len + 1):
        if n < L * (max_cycles + 1):
            continue
        block = segs[-L:]
        reps = 1
        i = n - 2 * L
        while i >= 0 and segs[i : i + L] == block:
            reps += 1
            i -= L
        if reps > max_cycles:
            return True
    return False


def is_path_trap(path: str, max_segments: int, max_repeats: int) -> bool:
    """Combined path-shape trap check (too deep OR repeated OR cyclic)."""
    return (
        too_deep(path, max_segments)
        or repeated_segment(path, max_repeats)
        or cyclic_path(path)
    )


def numericish(value: str) -> bool:
    """A single query value that looks like a counter/date (calendar bomb)."""
    v = value.strip().lower()
    return bool(_NUMERICISH.match(v) or _DATEISH.match(v))


def looks_like_pagination(query_pairs) -> bool:
    """True if every (non-empty) query value is numeric/date-ish (page=/year=)."""
    pairs = list(query_pairs)
    if not pairs:
        return False
    return all(numericish(v) for (_, v) in pairs if v != "")


def query_param_count(query: str) -> int:
    """Number of query parameters in a raw query string (``a=1&b=2`` -> 2)."""
    from urllib.parse import parse_qsl

    return len(parse_qsl(query, keep_blank_values=True))
