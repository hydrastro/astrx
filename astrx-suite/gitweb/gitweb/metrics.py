"""Tiny thread-safe, dependency-free request metrics.

A single process-wide :class:`Metrics` instance accumulates request counters and
latency so ``/metrics`` can expose them in a Prometheus-style text exposition
format and ``/health`` can answer cheaply.  Everything is plain integers/floats
under one lock; there is no background thread and nothing is ever written to
disk.
"""

from __future__ import annotations

import threading
import time
from typing import Dict


class Metrics:
    """Process-wide request counters and latency accumulator."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self.started = time.time()
        self.total = 0
        self.in_flight = 0
        self.rejected = 0  # connections dropped by the worker-pool limiter
        self.by_status: Dict[int, int] = {}
        self.by_action: Dict[str, int] = {}
        self.latency_sum = 0.0
        self.latency_count = 0

    def begin(self) -> None:
        with self._lock:
            self.in_flight += 1

    def end(self, status: int, action: str, elapsed: float) -> None:
        with self._lock:
            self.total += 1
            self.in_flight = max(0, self.in_flight - 1)
            self.by_status[status] = self.by_status.get(status, 0) + 1
            if action:
                self.by_action[action] = self.by_action.get(action, 0) + 1
            self.latency_sum += elapsed
            self.latency_count += 1

    def reject(self) -> None:
        with self._lock:
            self.rejected += 1

    def snapshot(self) -> dict:
        with self._lock:
            return {
                "uptime": time.time() - self.started,
                "total": self.total,
                "in_flight": self.in_flight,
                "rejected": self.rejected,
                "by_status": dict(self.by_status),
                "by_action": dict(self.by_action),
                "latency_sum": self.latency_sum,
                "latency_count": self.latency_count,
            }

    def render_prometheus(self) -> str:
        """Render the current snapshot as Prometheus text exposition format."""
        s = self.snapshot()
        lines = [
            "# HELP gitweb_uptime_seconds Seconds since the server started.",
            "# TYPE gitweb_uptime_seconds gauge",
            f"gitweb_uptime_seconds {s['uptime']:.3f}",
            "# HELP gitweb_requests_total Total HTTP requests served.",
            "# TYPE gitweb_requests_total counter",
            f"gitweb_requests_total {s['total']}",
            "# HELP gitweb_requests_in_flight Requests currently being handled.",
            "# TYPE gitweb_requests_in_flight gauge",
            f"gitweb_requests_in_flight {s['in_flight']}",
            "# HELP gitweb_connections_rejected_total Connections dropped by the worker-pool limiter.",
            "# TYPE gitweb_connections_rejected_total counter",
            f"gitweb_connections_rejected_total {s['rejected']}",
            "# HELP gitweb_request_latency_seconds_sum Cumulative request handling time.",
            "# TYPE gitweb_request_latency_seconds_sum counter",
            f"gitweb_request_latency_seconds_sum {s['latency_sum']:.6f}",
            "# HELP gitweb_request_latency_seconds_count Number of timed requests.",
            "# TYPE gitweb_request_latency_seconds_count counter",
            f"gitweb_request_latency_seconds_count {s['latency_count']}",
        ]
        lines.append("# HELP gitweb_responses_total Responses by HTTP status.")
        lines.append("# TYPE gitweb_responses_total counter")
        for status in sorted(s["by_status"]):
            lines.append(
                f'gitweb_responses_total{{status="{status}"}} {s["by_status"][status]}'
            )
        lines.append("# HELP gitweb_action_total Requests by resolved action.")
        lines.append("# TYPE gitweb_action_total counter")
        for action in sorted(s["by_action"]):
            lines.append(
                f'gitweb_action_total{{action="{action}"}} {s["by_action"][action]}'
            )
        return "\n".join(lines) + "\n"


#: The process-wide instance shared by every request.
REGISTRY = Metrics()
