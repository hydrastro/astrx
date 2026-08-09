"""End-to-end tests for the new features on the live server: the aggregate
/metrics exporter, the alerts panel + /api/status alert state, and inline-SVG
sparklines — including hostile-name escaping in HTML and in the metrics label.
Fully offline (mock services on loopback)."""

import http.client
import json
import os
import re
import sys
import threading
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from suitedash.config import AlertRule, Config, ServiceConfig
from suitedash.server import DashboardServer

try:
    from tests.mockservice import MockService, free_port, prometheus_service, resp
except ImportError:  # pragma: no cover
    from mockservice import MockService, free_port, prometheus_service, resp

HOSTILE = 'ha"x'  # a quote, to prove escaping in HTML attrs and the metric label


def _get(port, path):
    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    try:
        conn.request("GET", path)
        r = conn.getresponse()
        body = r.read()
        return r.status, dict(r.getheaders()), body.decode("utf-8", "replace")
    finally:
        conn.close()


class TestFeaturesServer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.prom = prometheus_service()
        cls.refused = free_port()
        cls.config = Config(
            host="127.0.0.1", port=0, refresh_seconds=0,
            timeout_seconds=0.6, verbose=False,
            services=[
                ServiceConfig(
                    name="alpha", base_url=cls.prom.base_url,
                    health_path="/health", metrics_path="/metrics",
                    metrics_keys=("alpha_requests_total", "alpha_uptime_seconds"),
                ),
                ServiceConfig(name=HOSTILE, base_url="http://127.0.0.1:%d" % cls.refused),
            ],
            alert_rules=[
                AlertRule(
                    id="alpha-busy", service="alpha", kind="metric",
                    metric="alpha_requests_total", op=">", threshold=0, for_polls=1,
                    severity="warning", description="alpha is serving requests",
                ),
                AlertRule(id="any-down", service="*", kind="down", for_polls=1,
                          severity="critical", description="a service is down"),
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

    # -- aggregate /metrics exporter ----------------------------------- #

    def test_metrics_endpoint_federates_with_service_labels(self):
        status, headers, body = _get(self.port, "/metrics")
        self.assertEqual(status, 200)
        self.assertIn("text/plain", headers.get("Content-Type", ""))
        self.assertEqual(headers.get("X-Content-Type-Options"), "nosniff")
        self.assertIn("suitedash_up 1", body)
        self.assertIn('suitedash_service_up{service="alpha"} 1', body)
        # A real upstream series, relabelled with service="alpha".
        self.assertRegex(body, r'alpha_requests_total\{service="alpha"[^}]*\}\s+42')

    def test_metrics_endpoint_escapes_hostile_service_label(self):
        _, _, body = _get(self.port, "/metrics")
        # The down hostile service still yields a service_up=0 line, escaped.
        self.assertIn('suitedash_service_up{service="ha\\"x"} 0', body)
        # No unescaped quote break-out anywhere.
        self.assertNotIn('service="ha"x"', body)

    # -- alerts: panel + JSON ------------------------------------------ #

    def test_alerts_panel_renders_firing(self):
        _, _, body = _get(self.port, "/")
        self.assertIn("alert firing", body)
        self.assertIn("alpha is serving requests", body)
        self.assertIn("a service is down", body)

    def test_hostile_service_name_escaped_in_alert_panel(self):
        _, _, body = _get(self.port, "/")
        self.assertIn("ha&quot;x", body)      # escaped form present
        self.assertNotIn('>ha"x<', body)      # raw name never emitted unescaped

    def test_api_status_exposes_alert_state(self):
        _, _, body = _get(self.port, "/api/status")
        data = json.loads(body)
        self.assertIn("alerts", data)
        self.assertGreaterEqual(data["alerts"]["firing"], 1)
        by_rule = {(a["service"], a["rule"]): a for a in data["alerts"]["states"]}
        self.assertTrue(by_rule[("alpha", "alpha-busy")]["firing"])
        self.assertEqual(by_rule[("alpha", "alpha-busy")]["last_value"], 42)
        self.assertTrue(by_rule[(HOSTILE, "any-down")]["firing"])

    # -- sparklines ----------------------------------------------------- #

    def test_sparkline_svg_present_and_wellformed_on_page(self):
        # Poll twice so the ring has >1 sample, then inspect the page.
        _get(self.port, "/")
        _, _, body = _get(self.port, "/")
        self.assertIn("<svg", body)
        self.assertIn("<polyline", body)
        # Every inline <svg>…</svg> fragment must be well-formed XML on its own.
        import xml.sax
        for frag in re.findall(r"<svg\b.*?</svg>", body, re.S):
            xml.sax.parseString(frag.encode("utf-8"), xml.sax.ContentHandler())


class TestMetricsEndpointHostileUpstream(unittest.TestCase):
    """A hostile upstream must not take down the aggregate /metrics endpoint."""

    def test_metrics_stays_200_on_recursion_bomb_json_upstream(self):
        # A deeply-nested JSON body makes json.loads raise RecursionError inside
        # the exporter; before the fix that escaped render and 500'd /metrics.
        svc = MockService(
            routes={
                "/health": resp(body="ok\n"),
                "/metrics": resp(body="[" * 5000, ctype="application/json"),
            }
        ).start()
        cfg = Config(
            host="127.0.0.1", port=0, refresh_seconds=0, timeout_seconds=0.6,
            verbose=False,
            services=[ServiceConfig(name="bomb", base_url=svc.base_url)],
        )
        httpd = DashboardServer(cfg)
        port = httpd.server_address[1]
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()
        try:
            status, _, body = _get(port, "/metrics")
            self.assertEqual(status, 200)  # not 500
            self.assertIn("suitedash_up 1", body)
            self.assertIn('suitedash_service_up{service="bomb"} 1', body)
            self.assertIn('suitedash_service_metric_count{service="bomb"} 0', body)
        finally:
            httpd.shutdown()
            httpd.server_close()
            svc.stop()


if __name__ == "__main__":
    unittest.main()
