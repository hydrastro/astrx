"""Roadmap #5 - liveness/uptime tracking + dead-onion aging (never hard-delete)."""

import os
import tempfile
import time
import unittest

from onioncrawler.storage import Storage
from onioncrawler.canonical import canonicalize

HOST = "l" * 56 + ".onion"


class TestLiveness(unittest.TestCase):
    def setUp(self):
        self.db = os.path.join(tempfile.mkdtemp(), "live.db")
        self.st = Storage(self.db)
        self.st.ensure_host(HOST)

    def tearDown(self):
        self.st.close()

    def test_consecutive_failures_flip_host_down(self):
        st = self.st
        self.assertFalse(st.record_fetch_down(HOST, threshold=3))  # 1
        self.assertFalse(st.record_fetch_down(HOST, threshold=3))  # 2
        self.assertTrue(st.record_fetch_down(HOST, threshold=3))   # 3 -> down
        h = dict(st.get_host(HOST))
        self.assertEqual(h["up"], 0)
        self.assertEqual(h["consecutive_failures"], 3)
        hist = st.uptime_history(HOST)
        self.assertEqual(hist[0]["up"], 0)  # a down transition was recorded

    def test_recovery_resets_and_records_up(self):
        st = self.st
        for _ in range(3):
            st.record_fetch_down(HOST, threshold=3)
        self.assertTrue(st.record_fetch_up(HOST))  # down -> up transition
        h = dict(st.get_host(HOST))
        self.assertEqual(h["up"], 1)
        self.assertEqual(h["consecutive_failures"], 0)
        self.assertEqual(h["down_recrawls"], 0)
        self.assertEqual(st.uptime_history(HOST)[0]["up"], 1)
        # a second successful fetch is not a transition
        self.assertFalse(st.record_fetch_up(HOST))

    def test_dead_aging_hides_pages_but_keeps_them(self):
        st = self.st
        url = f"http://{HOST}/p"
        st.store_page(url, HOST, "T", "deadhosttoken body content", "c1",
                      200, "text/html", 10, time.time())
        self.assertEqual(st.search("deadhosttoken")[1], 1)

        for _ in range(3):
            st.record_fetch_down(HOST, threshold=3)
        # aging: down across N=3 recrawl cycles -> 'dead'
        self.assertEqual(st.age_dead_hosts(threshold=3), 0)  # cycle 1
        self.assertEqual(dict(st.get_host(HOST))["state"], "active")
        self.assertEqual(st.age_dead_hosts(threshold=3), 0)  # cycle 2
        self.assertEqual(st.age_dead_hosts(threshold=3), 1)  # cycle 3 -> dead
        self.assertEqual(dict(st.get_host(HOST))["state"], "dead")

        # hidden from search, but the row is NOT deleted (never hard-delete)
        self.assertEqual(st.search("deadhosttoken")[1], 0)
        self.assertIsNotNone(st.get_page(url))

        # comes back up -> revived and searchable again
        st.record_fetch_up(HOST)
        self.assertEqual(dict(st.get_host(HOST))["state"], "active")
        self.assertEqual(st.search("deadhosttoken")[1], 1)

    def test_dead_host_cannot_stall_frontier(self):
        # regression: a dead host must not leave queued frontier rows (which
        # never lease) or get its done pages requeued -> would hang a crawl.
        st = self.st
        for i in range(2):
            st.enqueue(canonicalize(f"http://{HOST}/q{i}"), depth=1,
                       priority=0, force=True)
        st.store_page(f"http://{HOST}/done", HOST, "T", "b", "c9",
                      200, "text/html", 10, 0.0, interval=1)
        st.db.execute(
            "INSERT INTO frontier(url,host,depth,status,enqueued_at,lease_expires) "
            "VALUES(?,?,0,'done',0,0)", (f"http://{HOST}/done", HOST))
        for _ in range(3):
            st.record_fetch_down(HOST, threshold=3)
        for _ in range(3):
            st.age_dead_hosts(threshold=3)
        self.assertEqual(dict(st.get_host(HOST))["state"], "dead")
        # queued rows dead-lettered; done page NOT requeued onto the dead host
        self.assertEqual(st.db.execute(
            "SELECT count(*) c FROM frontier WHERE host=? AND status='queued'",
            (HOST,)).fetchone()["c"], 0)
        self.assertEqual(st.mark_recrawl_due(now=1e9, default_interval=0), 0)
        # a deliberate re-seed revives the host and enqueues again
        st.add_seed(canonicalize(f"http://{HOST}/reseed"))
        self.assertEqual(dict(st.get_host(HOST))["state"], "active")


if __name__ == "__main__":
    unittest.main()
