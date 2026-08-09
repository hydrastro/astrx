"""(e) RESUME: crawl K pages then stop, assert queued+done persisted, restart
and finish WITHOUT refetching 'done' URLs, reaching the same final page set.
Plus a focused crash-safety test for lease reclamation."""

import os
import tempfile
import time
import unittest
from urllib.parse import urlsplit

try:  # package-mode discovery (python3 -m unittest discover)
    from .fixtures import Fixture, ONION_MAIN
    from .helpers import build_crawler
except ImportError:  # top-level discovery (python3 -m unittest discover -s tests)
    from fixtures import Fixture, ONION_MAIN
    from helpers import build_crawler


def _path_of(url):
    s = urlsplit(url)
    return s.path + (("?" + s.query) if s.query else "")


def _final_page_urls(storage):
    return {r["url"] for r in storage.db.execute("SELECT url FROM pages")}


class TestResume(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()

    def test_resume_continues_without_duplicate_work(self):
        fx = Fixture().start()
        db = os.path.join(self.dir, "resume.db")
        try:
            # ---- Run 1: stop after 4 fetched pages -----------------------
            cfg1, st1, cr1 = build_crawler(db, fx, workers=1, max_pages_this_run=4)
            cr1.run([fx.seed_url()])
            s1 = st1.stats()
            done1 = {r["url"] for r in st1.db.execute(
                "SELECT url FROM frontier WHERE status='done'")}
            queued1 = s1["frontier_by_status"].get("queued", 0)

            # state persisted: some done, some still queued, none stuck leased
            self.assertGreaterEqual(len(done1), 1)
            self.assertGreater(queued1, 0, "expected queued work to remain")
            self.assertEqual(s1["frontier_by_status"].get("leased", 0), 0)
            requests_after_run1 = len(fx.state.requests)
            st1.close()

            # ---- Run 2: restart (fresh objects), resume, no cap ----------
            cfg2, st2, cr2 = build_crawler(db, fx, workers=1)
            cr2.run()  # no seeds: pure resume from the DB frontier
            s2 = st2.stats()

            # finished: frontier drained
            self.assertEqual(s2["frontier_by_status"].get("queued", 0), 0)
            self.assertEqual(s2["frontier_by_status"].get("leased", 0), 0)

            # 'done' URLs from run 1 were NOT refetched in run 2
            run2_paths = [t for (_h, t) in fx.state.requests[requests_after_run1:]]
            for url in done1:
                self.assertNotIn(_path_of(url), run2_paths,
                                 f"resume refetched a done URL: {url}")
                # and they are still 'done' in the DB
                row = st2.db.execute(
                    "SELECT status FROM frontier WHERE url=?", (url,)).fetchone()
                self.assertEqual(row["status"], "done")

            resumed_pages = _final_page_urls(st2)
            st2.close()
        finally:
            fx.stop()

        # ---- Fresh single uncapped crawl for comparison ------------------
        fx2 = Fixture().start()
        db2 = os.path.join(self.dir, "fresh.db")
        try:
            cfg3, st3, cr3 = build_crawler(db2, fx2, workers=1)
            cr3.run([fx2.seed_url()])
            fresh_pages = _final_page_urls(st3)
            st3.close()
        finally:
            fx2.stop()

        # resume reaches exactly the same final page set as a single run
        self.assertEqual(resumed_pages, fresh_pages)
        self.assertIn(f"http://{ONION_MAIN}/page-a", fresh_pages)
        self.assertTrue(len(fresh_pages) >= 5)

    def test_lease_reclaim_after_crash(self):
        """A leased row whose lease has expired is reclaimed to 'queued' on the
        next lease() (simulates a worker that was killed mid-fetch)."""
        fx = Fixture().start()
        db = os.path.join(self.dir, "crash.db")
        try:
            cfg, st, cr = build_crawler(db, fx, workers=1)
            cr.add_seeds([fx.seed_url()])
            # lease the seed (as a worker would) then simulate a crash: the
            # process dies without completing, leaving the row 'leased'.
            leased = st.lease(time.time(), lease_ttl=1000)
            self.assertIsNotNone(leased)
            row = st.db.execute("SELECT status, lease_expires FROM frontier "
                                "WHERE id=?", (leased["id"],)).fetchone()
            self.assertEqual(row["status"], "leased")

            # nothing else can be leased for that host while parked
            # force the lease to look expired (as time would after a crash)
            st.db.execute("UPDATE frontier SET lease_expires=? WHERE id=?",
                          (time.time() - 1, leased["id"]))
            st.db.execute("UPDATE hosts SET next_allowed=0")

            # restart path: reclaim expired leases
            reclaimed = st.reclaim_expired()
            self.assertGreaterEqual(reclaimed, 1)
            row2 = st.db.execute("SELECT status FROM frontier WHERE id=?",
                                 (leased["id"],)).fetchone()
            self.assertEqual(row2["status"], "queued")

            # and it can now be leased again (no lost URL)
            again = st.lease(time.time(), lease_ttl=10)
            self.assertEqual(again["id"], leased["id"])
            st.close()
        finally:
            fx.stop()


if __name__ == "__main__":
    unittest.main()
