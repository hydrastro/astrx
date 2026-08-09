"""Loopback mock services for offline tests.

``MockService`` stands up a real :class:`http.server.ThreadingHTTPServer` on
``127.0.0.1:0`` (an ephemeral port) that answers a fixed route table.  A route
can carry custom headers (for redirect tests) and an artificial ``sleep`` (for
the "slower than the timeout" black-hole case).  ``free_port`` returns a
loopback port with nothing listening, so connecting to it is refused — the
DOWN case — without needing an external network.
"""

from __future__ import annotations

import os
import socket
import sys
import threading
from collections import namedtuple
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

Response = namedtuple("Response", "status ctype body headers sleep drip head_drip")


def resp(status=200, ctype="text/plain; charset=utf-8", body=b"", headers=None,
         sleep=0.0, drip=0.0, head_drip=0.0):
    """Build a :class:`Response`; ``body`` may be ``str`` or ``bytes``.

    ``drip`` > 0 sends the body one byte every ``drip`` seconds (after valid
    headers) — a slow-drip peer whose every ``recv`` lands inside a sane socket
    timeout, so only a *total* wall-clock deadline can reap it.

    ``head_drip`` > 0 dribbles the STATUS LINE + HEADER block one byte every
    ``head_drip`` seconds (before the body). This pins the client's *header*
    read (``getresponse()``), which the per-recv socket timeout alone cannot
    bound — only a total wall-clock deadline over the header read can.
    """
    if isinstance(body, str):
        body = body.encode("utf-8")
    return Response(status, ctype, body, dict(headers or {}), float(sleep),
                    float(drip), float(head_drip))


def _raw_head(status, ctype, body_len, min_len):
    """Build a valid HTTP/1.1 response head, padded to at least ``min_len`` bytes
    with one long harmless header (so a byte-per-``head_drip`` dribble of it runs
    for ``min_len * head_drip`` seconds if the client never reaps it)."""
    head = (
        "HTTP/1.1 %d OK\r\n"
        "Content-Type: %s\r\n"
        "Content-Length: %d\r\n"
        "Connection: close\r\n"
    ) % (status, ctype, body_len)
    pad = max(0, min_len - len(head) - len("X-Pad: \r\n") - len("\r\n"))
    if pad:
        head += "X-Pad: " + ("a" * pad) + "\r\n"
    return (head + "\r\n").encode("latin-1")


class _QuietServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def handle_error(self, request, client_address):  # swallow disconnect noise
        pass


class MockService:
    """A configurable loopback HTTP service for tests."""

    def __init__(self, routes=None, catch_all=None):
        # routes: dict[path -> Response]; catch_all: Response for unmatched paths.
        self.routes = dict(routes or {})
        self.catch_all = catch_all
        self._httpd = None
        self._thread = None

    def start(self) -> "MockService":
        outer = self

        class Handler(BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *a):  # silence
                pass

            def do_HEAD(self):
                self.do_GET()

            def do_GET(self):
                path = self.path.split("?", 1)[0]
                r = outer.routes.get(path, outer.catch_all)
                if r is None:
                    self._emit(resp(status=404, body=b"nope"))
                    return
                self._emit(r)

            def _emit(self, r: Response):
                import time as _t

                if r.sleep:
                    _t.sleep(r.sleep)
                if r.head_drip:
                    # Dribble the status line + headers one byte at a time. Each
                    # byte lands inside the client's per-recv socket timeout, so
                    # only a total wall-clock deadline over getresponse() reaps it.
                    raw = _raw_head(r.status, r.ctype, len(r.body), 200)
                    self.close_connection = True
                    try:
                        for i in range(len(raw)):
                            _t.sleep(r.head_drip)
                            self.wfile.write(raw[i:i + 1])
                            self.wfile.flush()
                        if self.command != "HEAD":
                            self.wfile.write(r.body)
                            self.wfile.flush()
                    except (BrokenPipeError, ConnectionResetError, OSError):
                        pass  # client gave up (reaped at its deadline) — expected
                    return
                try:
                    self.send_response(r.status)
                    for k, v in r.headers.items():
                        self.send_header(k, v)
                    self.send_header("Content-Type", r.ctype)
                    self.send_header("Content-Length", str(len(r.body)))
                    self.send_header("Connection", "close")
                    self.end_headers()
                    if self.command != "HEAD":
                        if r.drip:
                            for i in range(len(r.body)):
                                _t.sleep(r.drip)
                                self.wfile.write(r.body[i:i + 1])
                                self.wfile.flush()
                        else:
                            self.wfile.write(r.body)
                except (BrokenPipeError, ConnectionResetError, OSError):
                    pass  # client gave up (timeout case) — that's expected

        self._httpd = _QuietServer(("127.0.0.1", 0), Handler)
        self._thread = threading.Thread(target=self._httpd.serve_forever, daemon=True)
        self._thread.start()
        return self

    @property
    def port(self) -> int:
        return self._httpd.server_address[1]

    @property
    def base_url(self) -> str:
        return "http://127.0.0.1:%d" % self.port

    def stop(self) -> None:
        if self._httpd is not None:
            self._httpd.shutdown()
            self._httpd.server_close()
            self._httpd = None

    def __enter__(self):
        return self.start()

    def __exit__(self, *exc):
        self.stop()


def free_port() -> int:
    """Return a loopback port that is currently free (connections are refused)."""
    s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    try:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]
    finally:
        s.close()


# --------------------------------------------------------------------------- #
# Canned service flavours used across tests.
# --------------------------------------------------------------------------- #

PROM_METRICS = (
    "# HELP alpha_requests_total Total requests served.\n"
    "# TYPE alpha_requests_total counter\n"
    "alpha_requests_total 42\n"
    "\n"  # blank line tolerated
    "# TYPE alpha_uptime_seconds gauge\n"
    "alpha_uptime_seconds 123.5\n"
    "alpha_responses_total{status=\"200\"} 40\n"
    "alpha_responses_total{status=\"404\"} 2\n"
    "alpha_broken NaN\n"  # non-finite -> dropped
)

JSON_STATS = (
    '{"docs": 1000, "hosts": 25, "ok": true, "ratio": "0.5", '
    '"tags": ["a", "b"], "nothing": null, '
    '"queue": {"pending": 7, "done": 300}}'
)


def prometheus_service() -> MockService:
    """Healthy service: ``/health``->ok, ``/metrics``->Prometheus text."""
    return MockService(
        routes={
            "/health": resp(body="ok\n"),
            "/metrics": resp(body=PROM_METRICS, ctype="text/plain; version=0.0.4"),
        }
    ).start()


def json_service() -> MockService:
    """Healthy service whose health is only reachable via a fallback path, and
    whose metrics are JSON at ``/api/stats``."""
    return MockService(
        routes={
            # /health intentionally 404 so the prober must fall back.
            "/api/stats": resp(body=JSON_STATS, ctype="application/json"),
        }
    ).start()


def slow_service(sleep=5.0) -> MockService:
    """Black hole: every path sleeps far past any sane timeout before replying."""
    return MockService(catch_all=resp(body="late", sleep=sleep)).start()


def drip_service(nbytes=200, gap=0.1) -> MockService:
    """Slow-drip black hole: answers with valid headers then dribbles the body
    one byte per ``gap`` seconds on every path. Each recv lands inside a sane
    socket timeout, so only a total wall-clock read deadline can reap it."""
    return MockService(catch_all=resp(body=b"x" * nbytes, drip=gap)).start()


def head_drip_service(gap=0.1) -> MockService:
    """Slow-drip black hole that dribbles the STATUS LINE + HEADERS (a ~200-byte
    head, so ~20s at gap=0.1 if never reaped) one byte per ``gap`` seconds on
    every path, before any body. Each recv lands inside a sane socket timeout, so
    only a total wall-clock deadline over the *header* read can reap it."""
    return MockService(catch_all=resp(body=b"ok", head_drip=gap)).start()
