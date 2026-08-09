"""End-to-end server tests: rendered page, escaping, CSP, /api/status, and that
the page stays bounded even with a slow service in the mix. Fully offline."""

import http.client
import json
import os
import sys
import threading
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from suitedash.config import Config, ServiceConfig
from suitedash.server import DashboardServer

try:
    from tests.mockservice import free_port, json_service, prometheus_service, slow_service
except ImportError:  # pragma: no cover
    from mockservice import free_port, json_service, prometheus_service, slow_service


def _get(port, path):
    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    try:
        conn.request("GET", path)
        r = conn.getresponse()
        body = r.read()
        headers = dict(r.getheaders())
        return r.status, headers, body.decode("utf-8", "replace")
    finally:
        conn.close()


class TestServer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.prom = prometheus_service()
        cls.json = json_service()
        cls.slow = slow_service(sleep=5.0)
        cls.refused_port = free_port()
        cls.config = Config(
            host="127.0.0.1",
            port=0,  # ephemeral
            refresh_seconds=7,
            timeout_seconds=0.6,
            verbose=False,
            services=[
                ServiceConfig(
                    name="alpha",
                    base_url=cls.prom.base_url,
                    health_path="/health",
                    metrics_path="/metrics",
                    metrics_keys=("alpha_requests_total", "alpha_uptime_seconds"),
                    label="prometheus mock",
                ),
                ServiceConfig(
                    name="beta",
                    base_url=cls.json.base_url,
                    health_path="/health",
                    metrics_path="/api/stats",
                    metrics_keys=("docs", "queue_pending", "ratio"),
                ),
                ServiceConfig(
                    name="delta",  # black hole -> DOWN
                    base_url=cls.slow.base_url,
                    health_path="/health",
                ),
                # A hostile service name to prove HTML escaping.
                ServiceConfig(
                    name='<script>x</script>&"',
                    base_url="http://127.0.0.1:%d" % cls.refused_port,
                ),
            ],
        )
        cls.httpd = DashboardServer(cls.config)
        cls.port = cls.httpd.server_address[1]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()
        cls.prom.stop()
        cls.json.stop()
        cls.slow.stop()

    def test_page_renders_badges_and_metrics(self):
        status, headers, body = _get(self.port, "/")
        self.assertEqual(status, 200)
        self.assertIn("text/html", headers.get("Content-Type", ""))
        self.assertIn("UP", body)
        self.assertIn("DOWN", body)
        # Surfaced metric numbers appear on the cards.
        self.assertIn("alpha_requests_total", body)
        self.assertIn("42", body)  # prometheus value
        self.assertIn("1,000", body)  # json value, thousands-formatted
        # Overall summary reflects 2 down (delta + hostile refused).
        self.assertIn("2 of 4 services DOWN", body)

    def test_meta_refresh_present(self):
        _, _, body = _get(self.port, "/")
        self.assertIn('<meta http-equiv="refresh" content="7">', body)

    def test_everything_is_escaped(self):
        _, _, body = _get(self.port, "/")
        self.assertNotIn("<script>x</script>", body)  # raw injection absent
        self.assertIn("&lt;script&gt;x&lt;/script&gt;", body)  # escaped form present

    def test_security_headers(self):
        _, headers, _ = _get(self.port, "/")
        self.assertIn("default-src 'none'", headers.get("Content-Security-Policy", ""))
        self.assertEqual(headers.get("X-Content-Type-Options"), "nosniff")
        self.assertEqual(headers.get("X-Frame-Options"), "DENY")

    def test_api_status_matches_page(self):
        status, headers, body = _get(self.port, "/api/status")
        self.assertEqual(status, 200)
        self.assertIn("application/json", headers.get("Content-Type", ""))
        data = json.loads(body)
        svcs = data["services"]
        self.assertTrue(svcs["alpha"]["up"])
        self.assertTrue(svcs["beta"]["up"])
        self.assertFalse(svcs["delta"]["up"])
        # Parsed numbers surface in the JSON, both parsers represented.
        self.assertEqual(svcs["alpha"]["metrics"]["alpha_requests_total"], 42)
        self.assertEqual(svcs["beta"]["metrics"]["docs"], 1000)
        self.assertEqual(svcs["beta"]["metrics"]["ratio"], 0.5)
        # Latency present for an UP service, absent for a DOWN one.
        self.assertIsNotNone(svcs["alpha"]["latency_ms"])
        self.assertIsNone(svcs["delta"]["latency_ms"])
        # Summary roll-up.
        self.assertEqual(data["summary"]["down"], 2)
        self.assertEqual(data["summary"]["total"], 4)

    def test_page_is_bounded_with_slow_service(self):
        start = time.monotonic()
        status, _, _ = _get(self.port, "/")
        elapsed = time.monotonic() - start
        self.assertEqual(status, 200)
        # timeout 0.6 + slack; must not wait on the 5s black hole.
        self.assertLess(elapsed, 2.5, "page did not render within the bound")

    def test_dashboard_healthz(self):
        status, _, body = _get(self.port, "/healthz")
        self.assertEqual(status, 200)
        self.assertEqual(body.strip(), "ok")

    def test_unknown_path_404(self):
        status, _, _ = _get(self.port, "/nope")
        self.assertEqual(status, 404)


if __name__ == "__main__":
    unittest.main()
