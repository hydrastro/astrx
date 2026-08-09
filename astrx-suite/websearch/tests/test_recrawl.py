"""Feature 2: freshness validators, conditional GET / 304, recrawl scheduler."""

import os
import tempfile
import time
import unittest

from websearch import canonical, httpclient, index
from websearch.crawler import Crawler
try:
    from tests.common import make_config
except ImportError:  # discover -s tests (top-level = tests/)
    from common import make_config
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


class ConditionalGetTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    def test_plain_get_returns_validators(self):
        res = httpclient.fetch(
            self.site.url("/etag"),
            allow_hosts=[canonical.authority_of(self.site.base)])
        self.assertEqual(res.status, 200)
        self.assertEqual(res.headers.get("etag"), '"etagv1"')
        self.assertIn("last-modified", res.headers)

    def test_conditional_get_returns_304(self):
        res = httpclient.fetch(
            self.site.url("/etag"),
            allow_hosts=[canonical.authority_of(self.site.base)],
            extra_headers={"If-None-Match": '"etagv1"'})
        self.assertEqual(res.status, 304)
        self.assertEqual(res.body, b"")


class RecrawlSchedulerTest(unittest.TestCase):
    def setUp(self):
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.conn = index.connect(self.db)

    def tearDown(self):
        self.conn.close()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def test_due_for_recrawl_selects_only_stale(self):
        now = 1_000_000.0
        index.upsert_document(self.conn, "http://x/fresh", "F", "", "fresh body",
                              fetched_at=now - 10)
        index.upsert_document(self.conn, "http://x/stale", "S", "", "stale body",
                              fetched_at=now - 10_000)
        self.conn.commit()
        due = index.due_for_recrawl(self.conn, interval=100, now=now)
        urls = {u for u, _ in due}
        self.assertIn("http://x/stale", urls)
        self.assertNotIn("http://x/fresh", urls)


class RecrawlEndToEndTest(unittest.TestCase):
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

    def _crawl_etag(self):
        conn = index.connect(self.db)
        cr = Crawler(conn, make_config(self.site))
        cr.add_seeds([self.site.url("/etag")])
        stats = cr.run()
        conn.commit()
        return conn, stats

    def test_first_crawl_stores_validators(self):
        conn, stats = self._crawl_etag()
        self.assertEqual(stats["indexed"], 1)
        row = conn.execute(
            "SELECT etag, last_modified FROM docs WHERE url LIKE '%/etag'"
        ).fetchone()
        self.assertEqual(row["etag"], '"etagv1"')
        self.assertTrue(row["last_modified"])
        conn.close()

    def test_recrawl_304_revalidates_without_reindex(self):
        conn, _ = self._crawl_etag()
        before = conn.execute(
            "SELECT fetched_at FROM docs WHERE url LIKE '%/etag'").fetchone()[0]
        n_before = conn.execute("SELECT COUNT(*) FROM docs").fetchone()[0]
        conn.close()

        time.sleep(0.02)
        conn2 = index.connect(self.db)
        cr2 = Crawler(conn2, make_config(self.site))
        queued = cr2.enqueue_recrawls(interval=0)      # everything is due
        self.assertGreaterEqual(queued, 1)
        stats = cr2.run()

        # The page came back 304: revalidated, not re-indexed.
        self.assertGreaterEqual(stats["unchanged"], 1)
        self.assertEqual(stats["indexed"], 0)
        n_after = conn2.execute("SELECT COUNT(*) FROM docs").fetchone()[0]
        self.assertEqual(n_after, n_before)          # no duplicate row
        after = conn2.execute(
            "SELECT fetched_at FROM docs WHERE url LIKE '%/etag'").fetchone()[0]
        self.assertGreater(after, before)            # freshness clock reset
        conn2.close()


if __name__ == "__main__":
    unittest.main()
