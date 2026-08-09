"""Bounded in-memory history + hand-emitted inline-SVG sparklines (no JS).

A :class:`Ring` is a fixed-capacity :class:`collections.deque` of recent numeric
samples; :class:`History` keeps one ring per ``(service, metric)`` and evicts the
least-recently-updated series once ``max_series`` distinct pairs exist, so memory
is doubly bounded (capacity x series).  History is purely in-memory and resets on
restart — that is intentional; suitedash is a live status view, not a TSDB.

:func:`sparkline_svg` renders a tiny ``<svg><polyline/></svg>`` from a point list
*by hand* — no external library, no script.  Every numeric input is filtered for
finiteness and clamped to a safe magnitude before any range arithmetic, and every
emitted coordinate is clamped into the viewport and formatted as a finite decimal,
so NaN/Inf/huge/empty/one-point inputs can never produce invalid XML or an
exploding path.
"""

from __future__ import annotations

import math
from collections import OrderedDict, deque
from typing import Dict, Iterable, List, Tuple

#: Bounds for the ring capacity and series count (defensive clamps applied here
#: as well as in the config loader, so a direct constructor call stays bounded).
MIN_CAPACITY = 2
MAX_CAPACITY = 10_000
MAX_SERIES = 100_000

#: Values are clamped to +/- this before range math so ``max - min`` can never
#: overflow to +Inf (e.g. 1e308 - (-1e308)) and poison the coordinate scaling.
_CLAMP = 1e12


class Ring:
    """A fixed-capacity ring of ``float`` samples; oldest evicted on overflow."""

    __slots__ = ("_buf",)

    def __init__(self, capacity: int):
        cap = max(1, min(MAX_CAPACITY, int(capacity)))
        self._buf: "deque[float]" = deque(maxlen=cap)

    def push(self, value: float) -> None:
        self._buf.append(float(value))

    def values(self) -> List[float]:
        return list(self._buf)

    def __len__(self) -> int:
        return len(self._buf)


class History:
    """Per-``(service, metric)`` ring buffers, bounded in capacity and count."""

    def __init__(self, capacity: int = 60, max_series: int = 256):
        self.capacity = max(MIN_CAPACITY, min(MAX_CAPACITY, int(capacity)))
        self.max_series = max(1, min(MAX_SERIES, int(max_series)))
        self._rings: "OrderedDict[Tuple[str, str], Ring]" = OrderedDict()

    def record(self, results) -> None:
        """Append this sweep's finite metric samples for every UP service."""
        for name, r in results.items():
            if not getattr(r, "up", False):
                continue
            for metric, v in r.metrics.items():
                if v is None:
                    continue
                try:
                    fv = float(v)
                except (TypeError, ValueError):
                    continue
                if not math.isfinite(fv):
                    continue
                key = (name, metric)
                ring = self._rings.get(key)
                if ring is None:
                    if len(self._rings) >= self.max_series:
                        self._rings.popitem(last=False)  # evict oldest series
                    ring = Ring(self.capacity)
                    self._rings[key] = ring
                else:
                    self._rings.move_to_end(key)  # mark most-recently-updated
                ring.push(fv)

    def series(self, service: str, metric: str) -> List[float]:
        ring = self._rings.get((service, metric))
        return ring.values() if ring is not None else []

    def all_series(self) -> Dict[str, Dict[str, List[float]]]:
        """A copy of every ring as ``{service: {metric: [values]}}``."""
        out: Dict[str, Dict[str, List[float]]] = {}
        for (svc, metric), ring in self._rings.items():
            out.setdefault(svc, {})[metric] = ring.values()
        return out


def _fmt_coord(v: float) -> str:
    """Finite, trimmed decimal for an SVG coordinate ('0' for anything odd)."""
    if not isinstance(v, (int, float)) or not math.isfinite(v):
        return "0"
    s = "%.2f" % float(v)
    if "." in s:
        s = s.rstrip("0").rstrip(".")
    return s or "0"


def _clampf(v: float, lo: float, hi: float) -> float:
    if not math.isfinite(v):
        return lo
    if v < lo:
        return lo
    if v > hi:
        return hi
    return v


def _safe_dim(v, default: float) -> float:
    """A finite float for a viewport dimension, or ``default`` for anything odd
    (non-numeric, NaN, Inf) — so a bad width/height can never raise here."""
    try:
        f = float(v)
    except (TypeError, ValueError):
        return default
    return f if math.isfinite(f) else default


def sparkline_svg(
    points: Iterable[float], width: float = 100.0, height: float = 20.0
) -> str:
    """Return a well-formed inline ``<svg>`` sparkline for ``points``.

    Robust by construction: non-finite and non-numeric points are dropped, the
    remaining values are clamped to a safe magnitude, and every emitted number is
    clamped into the ``width x height`` viewport.  An empty series yields a valid
    empty ``<svg></svg>``; a single point yields a flat mid-line; a flat series
    (all-equal, incl. huge values) yields a mid-line — never invalid XML.
    """
    w = _clampf(_safe_dim(width, 100.0), 1.0, 100_000.0)
    h = _clampf(_safe_dim(height, 20.0), 1.0, 100_000.0)
    pad = 1.0 if h > 4 else 0.0

    clean: List[float] = []
    for p in points:
        try:
            v = float(p)
        except (TypeError, ValueError):
            continue
        if not math.isfinite(v):
            continue
        clean.append(_clampf(v, -_CLAMP, _CLAMP))

    open_tag = (
        '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" '
        'viewBox="0 0 %s %s" class="spark" preserveAspectRatio="none" role="img">'
        % (_fmt_coord(w), _fmt_coord(h), _fmt_coord(w), _fmt_coord(h))
    )
    if not clean:
        return open_tag + "</svg>"

    lo, hi = min(clean), max(clean)
    span = hi - lo
    usable = max(0.0, h - 2 * pad)

    def y_for(v: float) -> float:
        if not math.isfinite(span) or span <= 0:
            norm = 0.5
        else:
            norm = (v - lo) / span
            if not math.isfinite(norm):
                norm = 0.5
        norm = _clampf(norm, 0.0, 1.0)
        return _clampf(pad + (1.0 - norm) * usable, 0.0, h)

    n = len(clean)
    if n == 1:
        y = _fmt_coord(y_for(clean[0]))
        pts = "%s,%s %s,%s" % (_fmt_coord(0.0), y, _fmt_coord(w), y)
    else:
        step = w / (n - 1)
        coords = []
        for i, v in enumerate(clean):
            x = _clampf(i * step, 0.0, w)
            coords.append("%s,%s" % (_fmt_coord(x), _fmt_coord(y_for(v))))
        pts = " ".join(coords)

    return (
        open_tag
        + '<polyline fill="none" stroke="currentColor" stroke-width="1" points="%s"/>'
        % pts
        + "</svg>"
    )
