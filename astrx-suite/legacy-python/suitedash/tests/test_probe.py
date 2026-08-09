"""Unit tests for the tolerant fetch, the two metric parsers, and probe_service."""

import http.client
import os
import sys
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from suitedash.config import ServiceConfig
from suitedash.probe import (
    fetch,
    flatten_json,
    parse_metrics,
    parse_prometheus,
    probe_service,
)

try:
    from tests.mockservice import (
        MockService,
        drip_service,
        free_port,
        head_drip_service,
        json_service,
        prometheus_service,
        resp,
    )
except ImportError:  # pragma: no cover - direct file run
    from mockservice import (
        MockService,
        drip_service,
        free_port,
        head_drip_service,
        json_service,
        prometheus_service,
        resp,
    )


class TestPrometheusParser(unittest.TestCase):
    def test_parses_name_value_lines(self):
        text = (
            "# HELP x_total help\n"
            "# TYPE x_total counter\n"
            "x_total 42\n"
            "x_ratio 0.75\n"
        )
        m = parse_prometheus(text)
        self.assertEqual(m["x_total"], 42.0)
        self.assertEqual(m["x_ratio"], 0.75)

    def test_ignores_comments_blanks_and_bad_lines(self):
        text = "# a comment\n\n   \nlonelytoken\n# TYPE y gauge\ny 3\n"
        m = parse_prometheus(text)
        self.assertEqual(m, {"y": 3.0})

    def test_labeled_series_resolve_by_base_name(self):
        text = 'r_total{code="200"} 40\nr_total{code="500"} 2\n'
        m = parse_prometheus(text)
        # Base name resolves (first series wins) and the full token is kept too.
        self.assertEqual(m["r_total"], 40.0)
        self.assertEqual(m['r_total{code="200"}'], 40.0)

    def test_non_finite_values_are_dropped(self):
        m = parse_prometheus("good 1\nbad NaN\ninf_v +Inf\nneg -Inf\n")
        self.assertEqual(m, {"good": 1.0})

    def test_trailing_timestamp_tolerated(self):
        m = parse_prometheus("z 9 1699999999000\n")
        self.assertEqual(m["z"], 9.0)


class TestJsonParser(unittest.TestCase):
    def test_flatten_one_level(self):
        obj = {
            "docs": 1000,
            "ok": True,
            "ratio": "0.5",
            "tags": ["a", "b"],  # ignored
            "nothing": None,  # ignored
            "queue": {"pending": 7, "done": 300, "name": "q"},  # one level
        }
        m = flatten_json(obj)
        self.assertEqual(m["docs"], 1000.0)
        self.assertEqual(m["ok"], 1.0)
        self.assertEqual(m["ratio"], 0.5)
        self.assertEqual(m["queue_pending"], 7.0)
        self.assertEqual(m["queue_done"], 300.0)
        self.assertNotIn("tags", m)
        self.assertNotIn("nothing", m)
        self.assertNotIn("queue_name", m)  # non-numeric leaf skipped

    def test_non_dict_returns_empty(self):
        self.assertEqual(flatten_json([1, 2, 3]), {})


class TestAutodetect(unittest.TestCase):
    def test_prometheus_text(self):
        m = parse_metrics(b"a 1\nb 2\n", "text/plain")
        self.assertEqual(m, {"a": 1.0, "b": 2.0})

    def test_json_with_content_type(self):
        m = parse_metrics(b'{"a": 1, "b": 2}', "application/json")
        self.assertEqual(m, {"a": 1.0, "b": 2.0})

    def test_json_without_content_type_hint(self):
        m = parse_metrics(b'{"a": 5}', "")
        self.assertEqual(m, {"a": 5.0})

    def test_empty_body(self):
        self.assertEqual(parse_metrics(b"", "text/plain"), {})


class TestFetch(unittest.TestCase):
    def test_fetch_ok(self):
        svc = prometheus_service()
        try:
            r = fetch(svc.base_url, "/health", timeout=2.0)
            self.assertEqual(r.status, 200)
            self.assertEqual(r.body, b"ok\n")
            self.assertGreaterEqual(r.latency_ms, 0.0)
        finally:
            svc.stop()

    def test_does_not_follow_redirects(self):
        # follow_location=0 posture: a 3xx is returned as-is, never chased.
        svc = MockService(
            routes={"/redir": resp(status=302, headers={"Location": "http://127.0.0.1:1/x"})}
        ).start()
        try:
            r = fetch(svc.base_url, "/redir", timeout=2.0)
            self.assertEqual(r.status, 302)
        finally:
            svc.stop()

    def test_rejects_non_http_scheme(self):
        with self.assertRaises(ValueError):
            fetch("ftp://127.0.0.1", "/x", timeout=1.0)

    def test_slow_drip_body_is_reaped_by_total_deadline(self):
        # Regression: a backend that dribbles the body one byte per <timeout
        # window keeps every recv alive, so the per-socket timeout never fires.
        # Before the total wall-clock deadline this ran ~16x past `timeout`
        # (200 bytes * 0.1s = ~20s); it must now abort within ~timeout.
        svc = drip_service(nbytes=200, gap=0.1)  # ~20s if never reaped
        timeout = 0.5
        try:
            start = time.monotonic()
            with self.assertRaises((TimeoutError, OSError)):
                fetch(svc.base_url, "/metrics", timeout=timeout)
            elapsed = time.monotonic() - start
            self.assertLessEqual(
                elapsed, 2 * timeout,
                "slow-drip fetch was not reaped near the timeout (%.2fs)" % elapsed,
            )
        finally:
            svc.stop()

    def test_slow_drip_headers_is_reaped_by_total_deadline(self):
        # Regression: a backend that dribbles the STATUS LINE + HEADER block one
        # byte per <timeout window keeps every recv alive, so getresponse()'s
        # per-recv socket timeout never fires. Bounded only by http.client's
        # _MAXLINE/_MAXHEADERS this pins a probe-pool worker for ~_MAXLINE*timeout
        # (hours). The header read must now be reaped by the same total wall-clock
        # deadline as the body, near `timeout` — not run for the ~20s the head
        # would otherwise take (200-byte head * 0.1s).
        svc = head_drip_service(gap=0.1)  # ~20s of header dribble if never reaped
        timeout = 0.5
        try:
            start = time.monotonic()
            with self.assertRaises((TimeoutError, OSError, http.client.HTTPException)):
                fetch(svc.base_url, "/metrics", timeout=timeout)
            elapsed = time.monotonic() - start
            self.assertLessEqual(
                elapsed, 2 * timeout,
                "slow-drip HEADER fetch was not reaped near the timeout (%.2fs)" % elapsed,
            )
        finally:
            svc.stop()

    def test_slow_drip_headers_probe_service_reports_down(self):
        # The whole probe (not just fetch) must degrade a header-dribbling backend
        # to a bounded DOWN result, never a hang — this is what keeps one hostile
        # engine from wedging the shared probe pool.
        svc = head_drip_service(gap=0.1)
        timeout = 0.5
        try:
            cfg = ServiceConfig(
                name="drip", base_url=svc.base_url,
                health_path="/health", metrics_path="/metrics",
            )
            start = time.monotonic()
            r = probe_service(cfg, timeout=timeout)
            elapsed = time.monotonic() - start
            self.assertFalse(r.up)
            # health tries the configured path + fallbacks within ONE timeout
            # budget, so the whole probe stays bounded (a small multiple of it).
            self.assertLessEqual(
                elapsed, 3 * timeout,
                "probe_service hung on a header-dribbling backend (%.2fs)" % elapsed,
            )
        finally:
            svc.stop()


class TestProbeService(unittest.TestCase):
    def test_up_with_prometheus_metrics(self):
        svc = prometheus_service()
        try:
            cfg = ServiceConfig(
                name="alpha",
                base_url=svc.base_url,
                health_path="/health",
                metrics_path="/metrics",
                metrics_keys=("alpha_requests_total", "alpha_uptime_seconds"),
            )
            r = probe_service(cfg, timeout=2.0)
            self.assertTrue(r.up)
            self.assertEqual(r.health_path, "/health")
            self.assertEqual(r.metrics["alpha_requests_total"], 42.0)
            self.assertEqual(r.metrics["alpha_uptime_seconds"], 123.5)
            self.assertIsNotNone(r.latency_ms)
        finally:
            svc.stop()

    def test_up_via_health_fallback_with_json_metrics(self):
        svc = json_service()
        try:
            cfg = ServiceConfig(
                name="beta",
                base_url=svc.base_url,
                health_path="/health",  # 404 -> must fall back to /api/stats
                metrics_path="/api/stats",
                metrics_keys=("docs", "queue_pending", "ratio"),
            )
            r = probe_service(cfg, timeout=2.0)
            self.assertTrue(r.up)
            self.assertEqual(r.health_path, "/api/stats")  # discovered by fallback
            self.assertEqual(r.metrics["docs"], 1000.0)
            self.assertEqual(r.metrics["queue_pending"], 7.0)
            self.assertEqual(r.metrics["ratio"], 0.5)
        finally:
            svc.stop()

    def test_down_on_refused_connection(self):
        cfg = ServiceConfig(name="gamma", base_url="http://127.0.0.1:%d" % free_port())
        r = probe_service(cfg, timeout=1.0)
        self.assertFalse(r.up)
        self.assertIsNone(r.latency_ms)
        self.assertIn("refused", (r.error or "").lower())

    def test_missing_metric_key_surfaces_as_none(self):
        svc = prometheus_service()
        try:
            cfg = ServiceConfig(
                name="alpha",
                base_url=svc.base_url,
                health_path="/health",
                metrics_path="/metrics",
                metrics_keys=("alpha_requests_total", "does_not_exist"),
            )
            r = probe_service(cfg, timeout=2.0)
            self.assertEqual(r.metrics["alpha_requests_total"], 42.0)
            self.assertIsNone(r.metrics["does_not_exist"])
        finally:
            svc.stop()


if __name__ == "__main__":
    unittest.main()
