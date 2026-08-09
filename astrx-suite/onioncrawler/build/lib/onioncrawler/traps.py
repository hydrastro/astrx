"""Pure, unit-testable predicates for bot-trap / tarpit detection.

The *stateful* counters (per-template counts, per-host budgets, the global
skeleton set) live in storage.py so they are crash-safe. The *stateless*
structural checks now live once in :mod:`crawlcore.traps` (shared with
websearch); this module re-exports them so onioncrawler's call sites and tests
(``traps.is_path_trap``, ``traps.looks_like_pagination`` ...) are unchanged.
"""

from __future__ import annotations

from crawlcore.traps import (
    path_segments,
    too_deep,
    repeated_segment,
    cyclic_path,
    is_path_trap,
    numericish,
    looks_like_pagination,
)

__all__ = [
    "path_segments",
    "too_deep",
    "repeated_segment",
    "cyclic_path",
    "is_path_trap",
    "numericish",
    "looks_like_pagination",
]
