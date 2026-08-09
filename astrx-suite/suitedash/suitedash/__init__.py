"""suitedash — a zero-dependency ops/status dashboard for the astrx-suite.

Standard-library only (Python 3.11).  One no-JavaScript page shows, per suite
service, an UP/DOWN badge, response latency, and a few key numbers pulled from
its ``/metrics`` (or JSON stats) endpoint, plus a machine-readable
``/api/status`` JSON view.

The poller is deliberately *tolerant*: suite services are inconsistent (health
lives at ``/health`` vs ``/healthz`` vs nowhere; metrics come as Prometheus
text on one service and JSON on another).  suitedash tries the configured
health path, then a set of known fallbacks, and parses metrics as *both*
Prometheus text and JSON.  Every probe is bounded by a short per-service
timeout so a hung service renders as DOWN without ever blocking the page.

See ``README.md`` for configuration, running, and Tor deployment.
"""

from __future__ import annotations

__version__ = "1.0.0"

__all__ = ["__version__"]
