"""The no-JavaScript HTTP dashboard.

A thin :class:`http.server.ThreadingHTTPServer` with a bounded worker pool (so a
Slowloris client cannot exhaust threads) and a long-lived probe pool shared by
every request.  Routes:

* ``GET /``            server-rendered HTML status page (auto-refreshing)
* ``GET /api/status``  the same snapshot as JSON (incl. alert state)
* ``GET /metrics``     aggregate Prometheus exposition federating every service
* ``GET /healthz``     the dashboard's own liveness ("ok")
* ``GET /favicon.ico`` 204

Every response carries a strict CSP (``default-src 'none'`` + inline styles
only), ``nosniff``, ``DENY`` framing and ``no-referrer``.  Binds ``127.0.0.1``
by default; a Tor onion service or reverse proxy is the intended front.
"""

from __future__ import annotations

import threading
import time
from concurrent.futures import ThreadPoolExecutor
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from .config import Config
from .exporter import CONTENT_TYPE as METRICS_CONTENT_TYPE
from .exporter import render_federated_metrics
from .monitor import Monitor
from .poller import poll_all
from .render import render_page, render_status_json

_CSP = (
    "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; "
    "form-action 'none'; frame-ancestors 'none'"
)


class DashboardHandler(BaseHTTPRequestHandler):
    """Handle the handful of GET routes; delegate all work to poll/render."""

    protocol_version = "HTTP/1.1"
    server_version = "suitedash/1.0"

    # -- security + response helpers ----------------------------------- #

    def _headers(self, ctype: str, length: int, status: int = 200) -> None:
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(length))
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy", _CSP)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Connection", "close")
        self.end_headers()

    def _send(self, body, ctype: str, status: int = 200) -> None:
        if isinstance(body, str):
            body = body.encode("utf-8")
        self._headers(ctype, len(body), status)
        if self.command != "HEAD":
            try:
                self.wfile.write(body)
            except (BrokenPipeError, ConnectionResetError):  # pragma: no cover
                pass

    def log_message(self, fmt, *args):  # pragma: no cover - respect --quiet
        if getattr(self.server, "verbose", False):
            super().log_message(fmt, *args)

    # -- routing ------------------------------------------------------- #

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        path = self.path.split("?", 1)[0].rstrip("/") or "/"
        try:
            if path == "/":
                results = self.server.poll()
                snapshot = self.server.monitor.snapshot()
                self._send(
                    render_page(results, self.server.config, snapshot),
                    "text/html; charset=utf-8",
                )
            elif path == "/api/status":
                results = self.server.poll()
                snapshot = self.server.monitor.snapshot()
                self._send(
                    render_status_json(results, snapshot),
                    "application/json; charset=utf-8",
                )
            elif path == "/metrics":
                results = self.server.poll()
                self._send(render_federated_metrics(results), METRICS_CONTENT_TYPE)
            elif path == "/healthz":
                self._send("ok\n", "text/plain; charset=utf-8")
            elif path == "/favicon.ico":
                self._send(b"", "image/x-icon", status=204)
            else:
                self._send("not found\n", "text/plain; charset=utf-8", status=404)
        except Exception:  # pragma: no cover - never leak a stack trace
            self._send("internal error\n", "text/plain; charset=utf-8", status=500)


class DashboardServer(ThreadingHTTPServer):
    """Bounded threading server holding the shared probe pool and poll cache."""

    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, config: Config, handler=DashboardHandler):
        super().__init__((config.host, config.port), handler)
        self.config = config
        self.verbose = config.verbose
        # Stateful alert + history tracker, fed once per real poll sweep.
        self.monitor = Monitor(config)
        self.max_workers = max(1, int(config.max_workers))
        self._conn_slots = threading.BoundedSemaphore(self.max_workers)
        # A separate, generously-sized pool for outbound probes so a slow
        # service straggler never starves inbound request handling.
        self._pool = ThreadPoolExecutor(
            max_workers=max(4, len(config.services) * 2 + 2),
            thread_name_prefix="suitedash-probe",
        )
        self._cache_lock = threading.Lock()
        self._cache = None  # (monotonic_ts, results)

    # -- polling with an optional short TTL cache ---------------------- #

    def poll(self):
        ttl = self.config.cache_ttl
        if ttl and ttl > 0:
            with self._cache_lock:
                if self._cache is not None:
                    ts, cached = self._cache
                    if time.monotonic() - ts < ttl:
                        return cached
        results = poll_all(
            self.config.services, self.config.timeout_seconds, executor=self._pool
        )
        # Advance alert state + append history on every REAL sweep (never on a
        # cache hit), so "for N polls" debounce counts actual probes.
        self.monitor.ingest(results)
        if ttl and ttl > 0:
            with self._cache_lock:
                self._cache = (time.monotonic(), results)
        return results

    # -- bounded connection handling (Slowloris guard) ----------------- #

    def process_request(self, request, client_address):
        if not self._conn_slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:  # pragma: no cover - defensive
            self._conn_slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self._conn_slots.release()

    def server_close(self):
        try:
            super().server_close()
        finally:
            self._pool.shutdown(wait=False, cancel_futures=True)


def serve(config: Config) -> None:
    """Create and run the dashboard until interrupted."""
    httpd = DashboardServer(config)
    host, port = httpd.server_address[0], httpd.server_address[1]
    if config.verbose:
        print(
            "suitedash serving %d service(s) at http://%s:%d/  (JSON: /api/status)"
            % (len(config.services), host, port)
        )
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.shutdown()
        httpd.server_close()
