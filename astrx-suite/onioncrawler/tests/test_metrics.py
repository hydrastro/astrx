"""Roadmap #10 - /metrics + /health + structured logs exposing the counters
that were already collected (frontier/pages/hosts/trap/liveness)."""

import io
import json
import http.client
import os
import re
import tempfile
import threading
import time
import unittest
from http.server import ThreadingHTTPServer

from onioncrawler.storage import Storage
from onioncrawler.config import Config
from onioncrawler.search import SearchApp, make_handler
from onioncrawler.log import make_logger

HOST = "a" * 56 + ".onion"


class TestStorageMetrics(unittest.TestCase):
    def test_metric_gauges(self):
        st = Storage(os.path.join(tempfile.mkdtemp(), "m.db"))
        st.ensure_host(HOST)
        st.store_page(f"http://{HOST}/1", HOST, "T", "metricbody one",
                      "c1", 200, "text/html", 10, time.time())
        st.log_trap(HOST, f"http://{HOST}/x", "path-trap")
        m = st.metrics()
        self.assertEqual(m["pages"], 1)
        self.assertEqual(m["hosts"], 1)
        self.assertEqual(m["hosts_up"], 1)
        self.assertEqual(m["trap_events"], 1)
        for key in ("frontier_queued", "hosts_dead", "link_edges", "errors"):
            self.assertIn(key, m)
        st.close()


class TestStructuredLog(unittest.TestCase):
    def test_json_line_emitted(self):
        buf = io.StringIO()
        log = make_logger(stream=buf, component="test")
        log("crawl_progress", pages=5, hosts=2)
        line = buf.getvalue().strip()
        rec = json.loads(line)
        self.assertEqual(rec["event"], "crawl_progress")
        self.assertEqual(rec["pages"], 5)
        self.assertEqual(rec["component"], "test")
        self.assertIn("ts", rec)


class TestMetricsEndpoints(unittest.TestCase):
    def setUp(self):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "me.db"))
        self.st.ensure_host(HOST)
        self.st.store_page(f"http://{HOST}/1", HOST, "T", "body one", "c1",
                           200, "text/html", 10, time.time())
        cfg = Config()
        cfg.rate_limit_enabled = False
        self.app = SearchApp(self.st, cfg)
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.app))
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.st.close()

    def _get(self, path):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        c.request("GET", path)
        r = c.getresponse()
        data = r.read().decode("utf-8")
        ctype = r.getheader("Content-Type")
        c.close()
        return r.status, ctype, data

    def test_metrics_prometheus_text(self):
        # Default config sets no token, so /metrics stays open (compose poller).
        status, ctype, body = self._get("/metrics")
        self.assertEqual(status, 200)
        self.assertIn("text/plain", ctype)
        self.assertIn("onioncrawler_pages 1", body)
        self.assertIn("# TYPE onioncrawler_pages gauge", body)
        self.assertIn("onioncrawler_hosts 1", body)

    def test_metrics_no_sensitive_data_leak(self):
        # AUDIT: /metrics must expose ONLY aggregate numeric counters -- never an
        # onion host, IP address, query string or seed URL.
        _status, _ctype, body = self._get("/metrics")
        self.assertNotIn(".onion", body)
        self.assertNotIn(HOST, body)
        self.assertNotIn("http://", body)
        # No IPv4 literal anywhere in the payload.
        self.assertIsNone(re.search(r"\b\d{1,3}(?:\.\d{1,3}){3}\b", body))
        # Every non-comment line is `onioncrawler_<name> <int>` -- a bare integer
        # value, nothing free-form that could smuggle a leak.
        for line in body.splitlines():
            if not line or line.startswith("#"):
                continue
            name, _, val = line.partition(" ")
            self.assertTrue(name.startswith("onioncrawler_"), name)
            self.assertRegex(val, r"^-?\d+$")

    def test_health_no_sensitive_data_leak(self):
        # /health JSON is likewise aggregate-only: every value is a number.
        _status, _ctype, body = self._get("/health")
        obj = json.loads(body)
        self.assertNotIn(".onion", body)
        self.assertIsNone(re.search(r"\b\d{1,3}(?:\.\d{1,3}){3}\b", body))
        for k, v in obj.items():
            if k == "status":
                self.assertEqual(v, "ok")
            else:
                self.assertIsInstance(v, int)

    def test_health_json(self):
        status, ctype, body = self._get("/health")
        self.assertEqual(status, 200)
        self.assertIn("application/json", ctype)
        obj = json.loads(body)
        self.assertEqual(obj["status"], "ok")
        self.assertEqual(obj["pages"], 1)
        self.assertIn("hosts_up", obj)

    def test_healthz_plaintext(self):
        status, _, body = self._get("/healthz")
        self.assertEqual(status, 200)
        self.assertEqual(body, "ok")


class TestMetricsTokenGate(unittest.TestCase):
    """Optional metrics token: OFF by default (above), but when set it gates
    /metrics and /health while /healthz stays a trivial open liveness probe."""

    TOKEN = "s3cr3t-metrics-token"

    def setUp(self):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "mt.db"))
        self.st.ensure_host(HOST)
        self.st.store_page(f"http://{HOST}/1", HOST, "T", "body one", "c1",
                           200, "text/html", 10, time.time())
        cfg = Config()
        cfg.rate_limit_enabled = False
        cfg.metrics_token = self.TOKEN
        self.app = SearchApp(self.st, cfg)
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.app))
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.st.close()

    def _get(self, path, headers=None):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        c.request("GET", path, headers=headers or {})
        r = c.getresponse()
        body = r.read().decode("utf-8")
        c.close()
        return r.status, body

    def test_absent_or_wrong_token_refused(self):
        self.assertEqual(self._get("/metrics")[0], 401)            # absent
        self.assertEqual(self._get("/metrics?token=nope")[0], 401)  # wrong
        self.assertEqual(self._get("/health")[0], 401)             # /health gated too
        self.assertEqual(
            self._get("/metrics", {"Authorization": "Bearer wrong"})[0], 401)

    def test_correct_token_allowed_all_channels(self):
        # query param
        status, body = self._get(f"/metrics?token={self.TOKEN}")
        self.assertEqual(status, 200)
        self.assertIn("onioncrawler_pages 1", body)
        # X-Metrics-Token header
        self.assertEqual(
            self._get("/metrics", {"X-Metrics-Token": self.TOKEN})[0], 200)
        # Authorization: Bearer
        self.assertEqual(
            self._get("/metrics", {"Authorization": f"Bearer {self.TOKEN}"})[0], 200)
        # /health with the token
        self.assertEqual(self._get(f"/health?token={self.TOKEN}")[0], 200)

    def test_healthz_stays_open_with_token_set(self):
        # /healthz is a trivial liveness probe -- never gated (container check).
        status, body = self._get("/healthz")
        self.assertEqual(status, 200)
        self.assertEqual(body, "ok")


if __name__ == "__main__":
    unittest.main()
