"""Pure recrawl-scheduling arithmetic (no DB, no clock ownership).

Both crawlers refresh their index on a per-page interval and back that interval
off when a page is seen unchanged (a 304 or an identical content hash). The
*decision* math is tiny and identical; the durable requeue itself stays in each
crawler's store (its SQL is schema-specific and frozen). Extracting the pure
arithmetic gives it one tested home.
"""

from __future__ import annotations


def is_due(fetched_at: float, interval: float, now: float) -> bool:
    """True if a page fetched at *fetched_at* on interval *interval* is due at
    *now*. A page never fetched (``fetched_at <= 0``) is not scheduled here."""
    if not fetched_at or fetched_at <= 0:
        return False
    return fetched_at + float(interval) <= now


def next_due(fetched_at: float, interval: float) -> float:
    """The timestamp at which the page becomes due for recrawl."""
    return float(fetched_at) + float(interval)


def backoff_interval(current, factor: float, max_interval=None, base=None):
    """Grow a recrawl interval multiplicatively when a page is unchanged.

    Mirrors the historical behaviour exactly: fall back to *base* when there is
    no current interval, multiply by *factor*, and cap at *max_interval*. Returns
    0.0 when there is nothing to grow (no current interval and no base), matching
    the "leave it alone" branch of the original inline code.
    """
    cur = current or base or 0.0
    nxt = cur * factor if cur else 0.0
    if max_interval and nxt:
        nxt = min(nxt, max_interval)
    return nxt
