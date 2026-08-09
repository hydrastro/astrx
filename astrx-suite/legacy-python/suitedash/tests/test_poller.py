"""The poller: correct UP/DOWN per service, order preserved, and hard bounded
wall-clock even when a service is a black hole."""

import os
import sys
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from suitedash.config import ServiceConfig
from suitedash.poller import poll_all, summarize

try:
    from tests.mockservice import free_port, json_service, prometheus_service, slow_service
except ImportError:  # pragma: no cover
    from mockservice import free_port, json_service, prometheus_service, slow_service


class TestPoller(unittest.TestCase):
    def setUp(self):
        self.timeout = 0.6
        self.prom = prometheus_service()
        self.json = json_service()
        self.slow = slow_service(sleep=5.0)
        self.services = [
            ServiceConfig(
                name="alpha",
                base_url=self.prom.base_url,
                health_path="/health",
                metrics_path="/metrics",
                metrics_keys=("alpha_requests_total",),
            ),
            ServiceConfig(
                name="beta",
                base_url=self.json.base_url,
                health_path="/health",
                metrics_path="/api/stats",
                metrics_keys=("docs", "queue_pending"),
            ),
            ServiceConfig(
                name="gamma",  # refused
                base_url="http://127.0.0.1:%d" % free_port(),
            ),
            ServiceConfig(
                name="delta",  # black hole
                base_url=self.slow.base_url,
                health_path="/health",
            ),
        ]

    def tearDown(self):
        self.prom.stop()
        self.json.stop()
        self.slow.stop()

    def test_up_down_per_service(self):
        results = poll_all(self.services, self.timeout)
        self.assertTrue(results["alpha"].up)
        self.assertTrue(results["beta"].up)
        self.assertFalse(results["gamma"].up)  # refused
        self.assertFalse(results["delta"].up)  # timed out

    def test_metrics_from_both_parsers_surfaced(self):
        results = poll_all(self.services, self.timeout)
        # Prometheus-text parser
        self.assertEqual(results["alpha"].metrics["alpha_requests_total"], 42.0)
        # JSON parser (flattened one level)
        self.assertEqual(results["beta"].metrics["docs"], 1000.0)
        self.assertEqual(results["beta"].metrics["queue_pending"], 7.0)

    def test_order_is_preserved(self):
        results = poll_all(self.services, self.timeout)
        self.assertEqual(list(results.keys()), ["alpha", "beta", "gamma", "delta"])

    def test_bounded_wall_time_with_black_hole(self):
        start = time.monotonic()
        results = poll_all(self.services, self.timeout)
        elapsed = time.monotonic() - start
        # Concurrent probes -> whole sweep is ~one timeout, never the sum, and
        # never blocks on the 5s straggler.
        self.assertLess(elapsed, self.timeout + 1.5, "poll_all did not stay bounded")
        self.assertFalse(results["delta"].up)

    def test_summary(self):
        s = summarize(poll_all(self.services, self.timeout))
        self.assertEqual(s["total"], 4)
        self.assertEqual(s["up"], 2)
        self.assertEqual(s["down"], 2)
        self.assertFalse(s["all_up"])


if __name__ == "__main__":
    unittest.main()
