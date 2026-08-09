"""Feature 3: multi-worker crawl driver, DNS cache, HTTP keep-alive.

Every path here still routes through the SSRF-checked connector -- the last test
proves a keep-alive crawl refuses internal IPs on the same terms as the default.
"""

import os
import socket
import tempfile
import unittest
from unittest import mock

from websearch import canonical, httpclient, index
from websearch.crawler import Crawler, CrawlConfig, MultiCrawler
try:
    from tests.common import make_config
except ImportError:  # discover -s tests (top-level = tests/)
    from common import make_config
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite

INTERNAL_SEEDS = ["http://169.254.169.254/latest/meta-data/",
                  "http://127.0.0.1:9/secret"]


class MultiWorkerTest(unittest.TestCase):
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

    def test_multi_worker_indexes_corpus_once(self):
        cfg = make_config(self.site, workers=3)
        mc = MultiCrawler(self.db, cfg)
        mc.add_seeds([self.site.url("/")])
        stats = mc.run()

        conn = index.connect(self.db)
        urls = sorted(r[0].replace(self.site.base, "")
                      for r in conn.execute("SELECT url FROM docs"))
        for p in ("/", "/python", "/rust", "/go", "/search-engines"):
            self.assertIn(p, urls)
        # No URL indexed twice despite concurrent workers.
        dups = conn.execute(
            "SELECT url, COUNT(*) c FROM docs GROUP BY url HAVING c>1"
        ).fetchall()
        self.assertEqual(dups, [])
        # The home page was leased by exactly one worker (atomic frontier lease).
        self.assertEqual(self.site.hits.get("/", 0), 1)
        self.assertGreater(stats["indexed"], 5)
        conn.close()


class DnsCacheTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def test_repeated_fetch_resolves_once(self):
        httpclient.clear_dns_cache()
        ah = [canonical.authority_of(self.site.base)]
        real = socket.getaddrinfo
        calls = []

        def counting(host, port, *a, **k):
            calls.append((host, port))
            return real(host, port, *a, **k)

        with mock.patch("websearch.httpclient.socket.getaddrinfo", counting):
            r1 = httpclient.fetch(self.site.url("/python"), allow_hosts=ah)
            r2 = httpclient.fetch(self.site.url("/rust"), allow_hosts=ah)
        self.assertEqual(r1.status, 200)
        self.assertEqual(r2.status, 200)
        # Same host:port -> resolved once, served from cache the second time.
        self.assertEqual(len(calls), 1, calls)

    def test_cache_does_not_defeat_ssrf_denylist(self):
        httpclient.clear_dns_cache()
        for _ in range(2):                 # second call hits the DNS cache
            res = httpclient.fetch("http://169.254.169.254/", timeout=2)
            self.assertEqual(res.status, 0)
            self.assertTrue((res.error or "").startswith("blocked-internal"))


class KeepAliveTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def test_fetcher_reuses_connection(self):
        httpclient.clear_dns_cache()
        ah = [canonical.authority_of(self.site.base)]
        f = httpclient.Fetcher(keep_alive=True)
        try:
            r1 = f.fetch(self.site.url("/ka"), allow_hosts=ah)
            r2 = f.fetch(self.site.url("/ka"), allow_hosts=ah)
            self.assertEqual(r1.status, 200)
            self.assertEqual(r2.status, 200)
            self.assertIn(b"keepalivemarker", r1.body)
            self.assertEqual(f.opened, 1)       # one socket for two requests
            self.assertGreaterEqual(f.reused, 1)
        finally:
            f.close()

    def test_fetcher_blocks_internal_redirect_hop(self):
        # The pooled connector must refuse an internal IP reached via redirect,
        # exactly like the stateless fetch() path.
        f = httpclient.Fetcher(keep_alive=True)
        try:
            res = f.fetch(
                self.site.url("/redirect-internal"),
                allow=lambda u: True,
                allow_hosts=[canonical.authority_of(self.site.base)])
            self.assertEqual(res.redirects, 1)
            self.assertTrue((res.error or "").startswith("blocked-internal"))
        finally:
            f.close()

    def test_keep_alive_crawler_still_blocks_internal(self):
        fd, db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        try:
            conn = index.connect(db)
            cfg = CrawlConfig(
                scope_hosts=["169.254.169.254", "127.0.0.1"],
                base_delay=0.0, jitter=0.0, respect_robots=True,
                total_budget=10, allow_hosts=[], keep_alive=True)
            cr = Crawler(conn, cfg)
            cr.add_seeds(INTERNAL_SEEDS)
            cr.run()
            self.assertEqual(
                conn.execute("SELECT COUNT(*) FROM docs").fetchone()[0], 0)
            rows = {r[0]: (r[1], r[2]) for r in conn.execute(
                "SELECT url, status, reason FROM frontier")}
            for u in INTERNAL_SEEDS:
                self.assertEqual(rows[u][0], "error", u)
                self.assertTrue((rows[u][1] or "").startswith("blocked-internal"))
            conn.close()
        finally:
            for suffix in ("", "-wal", "-shm"):
                try:
                    os.remove(db + suffix)
                except OSError:
                    pass


if __name__ == "__main__":
    unittest.main()
