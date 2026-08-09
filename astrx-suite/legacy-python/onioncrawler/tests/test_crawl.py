"""(d) A full crawl via DirectFetcher against the fixture: terminates (does NOT
hang in the trap), respects robots, dedups, drops the blocklisted page, and
never touches non-onion or blocked hosts."""

import os
import tempfile
import threading
import unittest

try:  # package-mode discovery (python3 -m unittest discover)
    from .fixtures import Fixture, ONION_MAIN, ONION_BLOCKED
    from .helpers import build_crawler
except ImportError:  # top-level discovery (python3 -m unittest discover -s tests)
    from fixtures import Fixture, ONION_MAIN, ONION_BLOCKED
    from helpers import build_crawler


def run_with_timeout(crawler, seeds, timeout=30.0):
    result = {}

    def _go():
        result["stats"] = crawler.run(seeds)

    t = threading.Thread(target=_go)
    t.start()
    t.join(timeout)
    if t.is_alive():
        crawler.stop.set()
        t.join(5)
        raise AssertionError("crawl did not terminate: likely stuck in a trap")
    return result["stats"]


class TestFullCrawl(unittest.TestCase):
    def setUp(self):
        self.fx = Fixture().start()
        self.dir = tempfile.mkdtemp()
        self.db = os.path.join(self.dir, "crawl.db")
        self.cfg, self.storage, self.crawler = build_crawler(
            self.db, self.fx, workers=2)

    def tearDown(self):
        self.storage.close()
        self.fx.stop()

    def _pages(self):
        return {r["url"]: dict(r) for r in
                self.storage.db.execute("SELECT * FROM pages")}

    def test_crawl_terminates_and_behaves(self):
        stats = run_with_timeout(self.crawler, [self.fx.seed_url()])
        pages = self._pages()
        paths = self.fx.state.paths()
        hosts = set(self.fx.state.hosts())

        # -- terminates & drains the frontier
        self.assertEqual(stats["frontier_by_status"].get("queued", 0), 0)
        self.assertEqual(stats["frontier_by_status"].get("leased", 0), 0)

        # -- robots.txt honored: /secret never requested
        self.assertFalse(any(p.startswith("/secret") for p in paths),
                         f"robots-disallowed path was fetched: {paths}")

        # -- normal interlinked pages indexed
        self.assertIn(f"http://{ONION_MAIN}/page-a", pages)
        self.assertIn(f"http://{ONION_MAIN}/page-b", pages)
        self.assertIn(f"http://{ONION_MAIN}/about", pages)  # gzip page decoded

        # -- <title> extracted (regression guard: title lives inside <head>)
        self.assertIn("Page B", pages[f"http://{ONION_MAIN}/page-b"]["title"])

        # -- content dedup: exactly one of the duplicate pages stored
        dup_urls = [u for u in pages if u.endswith("/dup-a") or u.endswith("/dup-b")]
        self.assertEqual(len(dup_urls), 1, f"dedup failed: {dup_urls}")
        self.assertGreaterEqual(stats["duplicates"], 1)

        # -- abuse keyword page fetched but DROPPED from the index
        self.assertTrue(any(p == "/blocked-keyword" for p in paths))
        self.assertFalse(any(u.endswith("/blocked-keyword") for u in pages),
                         "keyword-blocked page must not be indexed")

        # -- noindex meta honored (fetched, not stored)
        self.assertFalse(any(u.endswith("/noindex") for u in pages))

        # -- blocked host never fetched, never indexed, no clearnet leak
        self.assertNotIn(ONION_BLOCKED, hosts)
        self.assertEqual(hosts, {ONION_MAIN},
                         f"unexpected host contacted (leak?): {hosts}")
        self.assertFalse(any(dict(r)["host"] == ONION_BLOCKED for r in
                             self.storage.db.execute("SELECT host FROM pages")))

        # -- trap requests are bounded (did not explode)
        self.assertLessEqual(self.fx.state.cal_requests, 6,
                             "calendar/query bomb not capped")
        self.assertLessEqual(self.fx.state.loop_requests, 4,
                             "cyclic path trap not capped")
        self.assertLessEqual(self.fx.state.deep_requests, 8,
                             "deep path trap not capped")

        # -- trap log recorded reasons
        reasons = {r["reason"] for r in
                   self.storage.db.execute("SELECT reason FROM trap_log")}
        self.assertTrue(any("robots-disallow" == x for x in reasons))
        self.assertTrue(any(x.startswith("blocked-keyword") for x in reasons))
        self.assertTrue(any(x in ("template-cap", "path-trap", "skeleton-cap")
                            for x in reasons))


if __name__ == "__main__":
    unittest.main()
