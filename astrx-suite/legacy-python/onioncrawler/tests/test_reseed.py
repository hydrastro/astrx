"""Scheduled re-seed + curated known-onions seed list: import (dedup/validate/
blocklist-check) + re-enqueue the curated roots respecting caps + recrawl."""

import os
import tempfile
import unittest

from onioncrawler import seedlist
from onioncrawler.storage import Storage
from onioncrawler.canonical import canonicalize
from onioncrawler.abuse import AbuseFilter

try:  # package-mode discovery (python3 -m unittest discover)
    from .fixtures import Fixture, ONION_MAIN, ONION_BLOCKED
    from .helpers import build_crawler
except ImportError:  # top-level discovery (python3 -m unittest discover -s tests)
    from fixtures import Fixture, ONION_MAIN, ONION_BLOCKED
    from helpers import build_crawler


def onion(label):
    return (label * 60)[:56] + ".onion"


NEW_ROOT = onion("newroot")


class TestSeedListLoad(unittest.TestCase):
    def test_load_dedups_validates_drops_clearnet(self):
        d = tempfile.mkdtemp()
        p = os.path.join(d, "seeds.txt")
        with open(p, "w") as fh:
            fh.write(
                "# curated roots\n\n"
                f"http://{ONION_MAIN}/\n"
                f"{ONION_MAIN}\n"                    # bare host -> same canonical
                "http://example.com/\n"             # clearnet -> dropped
                "not a url\n"
                f"http://{NEW_ROOT}/wiki  # inline comment\n"
                f"http://{ONION_MAIN}/\n"            # duplicate
            )
        seeds = seedlist.load_seed_list(p)
        # only the two distinct valid onion roots survive, order-preserving
        self.assertEqual(seeds, [f"http://{ONION_MAIN}/", f"http://{NEW_ROOT}/wiki"])

    def test_missing_file_is_empty(self):
        self.assertEqual(seedlist.load_seed_list("/no/such/file"), [])

    def test_bounded_read_ignores_giant_junk_line_and_caps(self):
        # F3 regression: the file is streamed with bounded per-line reads (never
        # readlines()), so a giant newline-less junk line cannot OOM, and valid
        # roots after it are still found. max_seeds caps the accepted count.
        d = tempfile.mkdtemp()
        p = os.path.join(d, "big.txt")
        with open(p, "w") as fh:
            fh.write("z" * (300 * 1024))          # 300 KiB, no newline -> junk
            fh.write("\n")
            fh.write(f"http://{ONION_MAIN}/\n")
            fh.write(f"http://{NEW_ROOT}/\n")
        seeds = seedlist.load_seed_list(p)
        self.assertEqual(seeds, [f"http://{ONION_MAIN}/", f"http://{NEW_ROOT}/"])
        # accepted-root cap is honoured
        self.assertEqual(seedlist.load_seed_list(p, max_seeds=1),
                         [f"http://{ONION_MAIN}/"])


class TestReseedStorage(unittest.TestCase):
    def setUp(self):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "reseed.db"))

    def tearDown(self):
        self.st.close()

    def test_done_root_requeued_new_root_added_idempotent(self):
        cu = canonicalize(f"http://{ONION_MAIN}/")
        self.st.add_seed(cu)
        self.st.db.execute(
            "UPDATE frontier SET status='done' WHERE url=?", (cu.url,))
        # reseed re-enqueues the done root
        self.assertEqual(self.st.reseed_url(cu), "requeued")
        self.assertEqual(self.st.db.execute(
            "SELECT status FROM frontier WHERE url=?", (cu.url,)).fetchone()["status"],
            "queued")
        # a brand-new curated root is enqueued fresh
        new = canonicalize(f"http://{NEW_ROOT}/")
        self.assertEqual(self.st.reseed_url(new), "ok")
        # reseeding does not grow the frontier for known roots (respects recrawl)
        before = self.st.db.execute("SELECT count(*) c FROM frontier").fetchone()["c"]
        self.st.reseed_url(cu)
        self.st.reseed_url(new)
        after = self.st.db.execute("SELECT count(*) c FROM frontier").fetchone()["c"]
        self.assertEqual(before, after, "reseed must be idempotent (no dup rows)")

    def test_reseed_respects_host_state_caps(self):
        # A trapped host must never receive a queued frontier row from a reseed
        # (it can't be leased -> would stall termination). This is the cap-respect.
        self.st.ensure_host(ONION_MAIN)
        self.st.set_host_state(ONION_MAIN, "trapped", "duplicate-ratio")
        cu = canonicalize(f"http://{ONION_MAIN}/x")
        self.assertEqual(self.st.reseed_url(cu, caps={}), "host-dead")
        self.assertEqual(self.st.db.execute(
            "SELECT count(*) c FROM frontier WHERE host=? AND status='queued'",
            (ONION_MAIN,)).fetchone()["c"], 0)

    def test_reseed_revives_dead_host(self):
        cu = canonicalize(f"http://{ONION_MAIN}/")
        self.st.add_seed(cu)
        # demote the host to dead (never hard-deleted)
        self.st.db.execute(
            "UPDATE hosts SET state='dead', up=0 WHERE host=?", (ONION_MAIN,))
        # a reseed un-ages it back to active and re-enqueues the root
        res = self.st.reseed_url(cu)
        self.assertEqual(res, "requeued")
        self.assertEqual(dict(self.st.get_host(ONION_MAIN))["state"], "active")

    def test_reseed_untrusted_caps_bound_new_roots(self):
        # force=False (untrusted) reseed honours the unique-URL budget: new roots
        # beyond the cap are refused ('capped'), never enqueued.
        abuse = AbuseFilter()
        seeds = [f"http://{onion(chr(c))}/" for c in range(ord('a'), ord('a') + 6)]
        res = seedlist.reseed(self.st, abuse, seeds, caps={"max_unique_urls": 2},
                              force=False)
        self.assertEqual(res["added"], 2)
        self.assertGreaterEqual(res["capped"], 4)
        self.assertLessEqual(self.st.db.execute(
            "SELECT count(*) c FROM frontier").fetchone()["c"], 2)


class TestReseedAggregateAndBlocklist(unittest.TestCase):
    def test_seedlist_reseed_counts_and_blocklist(self):
        st = Storage(os.path.join(tempfile.mkdtemp(), "agg.db"))
        try:
            abuse = AbuseFilter(hosts=[ONION_BLOCKED])
            seeds = [f"http://{ONION_MAIN}/", f"http://{NEW_ROOT}/",
                     f"http://{ONION_BLOCKED}/x", "http://example.com/"]
            res = seedlist.reseed(st, abuse, seeds)
            self.assertEqual(res["added"], 2)       # two new onion roots
            self.assertEqual(res["blocked"], 1)     # blocklisted host refused
            self.assertEqual(res["not-onion"], 1)   # clearnet dropped
            # the blocklisted host never got a frontier row
            self.assertIsNone(st.db.execute(
                "SELECT 1 FROM frontier WHERE host=?", (ONION_BLOCKED,)).fetchone())
        finally:
            st.close()


class TestCrawlerReseedEndToEnd(unittest.TestCase):
    def test_crawl_to_done_then_reseed_reenqueues_roots(self):
        with Fixture() as fx:
            db = os.path.join(tempfile.mkdtemp(), "crawl.db")
            cfg, st, crawler = build_crawler(db, fx, workers=1)
            try:
                crawler.run([fx.seed_url()])
                seed = fx.seed_url()
                # after the crawl the seed root is 'done'
                self.assertEqual(st.db.execute(
                    "SELECT status FROM frontier WHERE url=?", (seed,)).fetchone()["status"],
                    "done")
                # scheduled reseed re-enqueues the curated roots (+ adds a new one)
                res = crawler.reseed([seed, f"http://{NEW_ROOT}/"])
                self.assertEqual(res["reseeded"], 1)
                self.assertEqual(res["added"], 1)
                self.assertEqual(st.db.execute(
                    "SELECT status FROM frontier WHERE url=?", (seed,)).fetchone()["status"],
                    "queued", "curated root must be re-enqueued for recrawl")
            finally:
                st.close()


if __name__ == "__main__":
    unittest.main()
