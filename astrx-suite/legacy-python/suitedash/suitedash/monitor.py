"""Thread-safe glue holding the alert engine + history across poll sweeps.

:class:`suitedash.server.DashboardServer` owns one :class:`Monitor`.  Every real
poll sweep (not a cache hit) calls :meth:`ingest`, which — under a single lock —
records history and advances alert state atomically.  Renderers call
:meth:`snapshot` to get an immutable, copied view they can walk without holding
the lock.

Because suitedash polls on request, "one poll sweep" is one real probe of the
service list; alert debounce (``for_polls``) and history sampling advance per
sweep, not on a wall-clock timer.  A Prometheus scrape of ``/metrics`` also
drives a sweep, giving a steady cadence when one is configured.
"""

from __future__ import annotations

import threading
from typing import Dict, List

from .alerts import AlertEngine, AlertEvent, AlertView
from .config import Config
from .history import History


class MonitorSnapshot:
    """An immutable, copied view of alert + history state for rendering/JSON."""

    __slots__ = ("alerts", "series", "events", "rules_total", "firing_count")

    def __init__(
        self,
        alerts: List[AlertView],
        series: Dict[str, Dict[str, List[float]]],
        events: List[AlertEvent],
        rules_total: int,
    ):
        self.alerts = alerts
        self.series = series
        self.events = events
        self.rules_total = rules_total
        self.firing_count = sum(1 for a in alerts if a.firing)

    def series_for(self, service: str) -> Dict[str, List[float]]:
        return self.series.get(service, {})


class Monitor:
    """Stateful, lock-guarded owner of the alert engine and history buffers."""

    def __init__(self, config: Config):
        self._lock = threading.Lock()
        self._engine = AlertEngine(config.alert_rules, alert_history=config.alert_history)
        self._history = History(config.history_capacity, config.history_max_series)
        self._rules_total = len(config.alert_rules)

    def ingest(self, results) -> None:
        """Record history and advance alerts for one poll sweep (atomically)."""
        with self._lock:
            self._history.record(results)
            self._engine.update(results)

    def snapshot(self) -> MonitorSnapshot:
        """Return a copied, lock-free view of the current state."""
        with self._lock:
            return MonitorSnapshot(
                alerts=self._engine.views(),
                series=self._history.all_series(),
                events=self._engine.events(),
                rules_total=self._rules_total,
            )
