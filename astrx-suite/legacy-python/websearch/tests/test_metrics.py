"""Feature 8: /metrics endpoint + the (previously dead) verbose flag wired to logs."""

import os
import tempfile
import threading
import unittest
import urllib.request

from websearch import index, server
from websearch.crawler import Crawler
try:
    from tests.common import crawl_fixture, make_config
except ImportError:  # discover -s tests (top-level = tests/)
    from common import crawl_fixture, make_config
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


class MetricsEndpointTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(cls.db)
        conn, _ = crawl_fixture(cls.site, cls.db)
        conn.close()
        cls.httpd = server.make_server(cls.db, host="127.0.0.1", port=0)
        cls.port = cls.httpd.server_address[1]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever,
                                      kwargs={"poll_interval": 0.05}, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()
        cls.thread.join(timeout=3)
        cls.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _get(self, path):
        with urllib.request.urlopen(
                "http://127.0.0.1:%d%s" % (self.port, path), timeout=5) as r:
            return r.status, r.read().decode("utf-8"), r.headers

    def test_metrics_exposes_counters_and_index_stats(self):
        status, body, headers = self._get("/metrics")
        self.assertEqual(status, 200)
        self.assertIn("text/plain", headers.get("Content-Type", ""))
        self.assertIn("websearch_requests_total", body)
        self.assertIn("websearch_docs", body)

    def test_search_counter_increments(self):
        self._get("/search?q=python")
        self._get("/api/search?q=python")
        _, body, _ = self._get("/metrics")
        lines = dict(
            l.split(" ", 1) for l in body.splitlines() if not l.startswith("#"))
        self.assertGreaterEqual(int(lines["websearch_searches_total"]), 1)
        self.assertGreaterEqual(int(lines["websearch_api_searches_total"]), 1)


class VerboseCrawlLogTest(unittest.TestCase):
    def setUp(self):
        self.site = FixtureSite().start()
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(self.db)

    def tearDown(self):
        self.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def _crawler(self, verbose):
        conn = index.connect(self.db)
        cr = Crawler(conn, make_config(self.site), verbose=verbose)
        cr.add_seeds([self.site.url("/")])
        return conn, cr

    def test_verbose_emits_structured_records(self):
        conn, cr = self._crawler(verbose=True)
        with self.assertLogs("websearch.crawler", level="INFO") as cm:
            cr.run()
        conn.close()
        msgs = [r.getMessage() for r in cm.records]
        self.assertTrue(any(m.startswith("indexed") for m in msgs), msgs[:3])

    def test_quiet_by_default(self):
        conn, cr = self._crawler(verbose=False)
        with self.assertNoLogs("websearch.crawler", level="INFO"):
            cr.run()
        conn.close()


if __name__ == "__main__":
    unittest.main()
