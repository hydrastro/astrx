"""Crawler tests: fetching, robots, dedup, traps and resume."""

import os
import tempfile
import unittest
from urllib.parse import urlsplit

from websearch import index
try:
    from tests.common import crawl_fixture, rel_urls
except ImportError:  # discover -s tests (top-level = tests/)
    from common import crawl_fixture, rel_urls
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


def _target(url):
    """The request-line target (path + query) the fixture records as a hit."""
    s = urlsplit(url)
    return s.path + (("?" + s.query) if s.query else "")


class CrawlerTest(unittest.TestCase):
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

    def test_fetches_and_indexes_corpus(self):
        conn, stats = crawl_fixture(self.site, self.db)
        urls = rel_urls(conn, self.site)
        for expected in ("/", "/search-engines", "/python", "/rust", "/go"):
            self.assertIn(expected, urls, "expected %s to be indexed" % expected)
        self.assertGreater(stats["indexed"], 5)
        conn.close()

    def test_honors_robots_txt(self):
        conn, stats = crawl_fixture(self.site, self.db)
        urls = rel_urls(conn, self.site)
        self.assertNotIn("/private/secret", urls)
        row = conn.execute(
            "SELECT status, reason FROM frontier WHERE url LIKE '%/private/secret'"
        ).fetchone()
        self.assertIsNotNone(row)
        self.assertEqual(row["status"], "skipped")
        self.assertEqual(row["reason"], "robots")
        self.assertGreaterEqual(stats["robots_blocked"], 1)
        # The disallowed page must never have been requested.
        self.assertEqual(self.site.hits.get("/private/secret", 0), 0)
        conn.close()

    def test_url_dedup_no_refetch(self):
        conn, _ = crawl_fixture(self.site, self.db)
        # Every URL appears once in the frontier (PRIMARY KEY) and the home page
        # is requested exactly once despite many inbound links.
        self.assertEqual(self.site.hits.get("/", 0), 1)
        dup_rows = conn.execute(
            "SELECT url, COUNT(*) c FROM frontier GROUP BY url HAVING c > 1"
        ).fetchall()
        self.assertEqual(dup_rows, [])
        conn.close()

    def test_content_hash_dedup(self):
        conn, stats = crawl_fixture(self.site, self.db)
        urls = rel_urls(conn, self.site)
        indexed_dups = [u for u in urls if u in ("/dup-a", "/dup-b")]
        self.assertEqual(len(indexed_dups), 1,
                         "identical pages should be de-duplicated")
        self.assertGreaterEqual(stats["dups"], 1)
        conn.close()

    def test_canonical_alias_not_indexed(self):
        conn, _ = crawl_fixture(self.site, self.db)
        urls = rel_urls(conn, self.site)
        self.assertNotIn("/alias", urls)
        self.assertIn("/python", urls)
        conn.close()

    def test_redirect_followed(self):
        conn, _ = crawl_fixture(self.site, self.db)
        self.assertIn("/go", rel_urls(conn, self.site))
        conn.close()

    def test_gzip_decoded(self):
        conn, _ = crawl_fixture(self.site, self.db)
        row = conn.execute(
            "SELECT 1 FROM docs WHERE body LIKE '%gzipmarker%'").fetchone()
        self.assertIsNotNone(row, "gzip-encoded page body should be decoded")
        conn.close()

    def test_trap_bounded(self):
        # A generous but finite budget; the crawl must terminate on its own.
        conn, stats = crawl_fixture(self.site, self.db, total_budget=500)
        urls = rel_urls(conn, self.site)
        trap = [u for u in urls if u.startswith("/trap")]
        # Bounded, and never recurses past the segment-repeat cap.
        self.assertLessEqual(len(trap), 20)
        self.assertFalse(any("/x/x/x/x" in u for u in trap),
                         "segment-repeat trap guard failed")
        # No trap URL exceeds the query-parameter cap of 3.
        for u in urls:
            self.assertLessEqual(u.count("=") if u.startswith("/trap") else 0, 3)
        conn.close()

    def test_resume_does_not_refetch_done(self):
        # Phase 1: stop after only a few pages.
        conn1, _ = crawl_fixture(self.site, self.db, finalize=False,
                                 total_budget=3)
        done1 = [r["url"] for r in conn1.execute(
            "SELECT url FROM frontier WHERE status IN ('done','error')")]
        docs1 = conn1.execute("SELECT COUNT(*) FROM docs").fetchone()[0]
        conn1.commit()
        conn1.close()
        self.assertGreaterEqual(len(done1), 1)
        hits_after_1 = dict(self.site.hits)

        # Phase 2: resume on the same DB with a full budget.
        conn2, _ = crawl_fixture(self.site, self.db, total_budget=500)
        docs2 = conn2.execute("SELECT COUNT(*) FROM docs").fetchone()[0]
        hits_after_2 = dict(self.site.hits)

        # URLs completed in phase 1 must not have been requested again.
        for url in done1:
            tgt = _target(url)
            self.assertEqual(
                hits_after_2.get(tgt, 0), hits_after_1.get(tgt, 0),
                "resumed crawl refetched a completed URL: %s" % url)
        self.assertGreater(docs2, docs1, "resume should index more pages")
        conn2.close()


if __name__ == "__main__":
    unittest.main()
