"""Roadmap #8 - near-duplicate / mirror clustering via SimHash; collapse fuzzy
duplicates in results (the exact-hash drop already exists; this is the fuzzy
version)."""

import os
import tempfile
import unittest

from onioncrawler.storage import Storage
from onioncrawler.simhash import simhash64, hamming, is_near_duplicate

HOST = "m" * 56 + ".onion"
# A realistic page body (many distinct tokens). Two copies differing by a single
# word are near-duplicates (small Hamming); a body of entirely different words is
# far. Both carry the shared query token 'mirrortoken'.
_BASE = "mirrortoken " + " ".join("word%d" % i for i in range(300))
_FAR = "mirrortoken " + " ".join("term%d" % i for i in range(300))


class TestSimHash(unittest.TestCase):
    def test_near_and_far(self):
        a = simhash64(_BASE + " uniquealpha")
        b = simhash64(_BASE + " uniquebeta")
        c = simhash64(_FAR)
        self.assertEqual(simhash64(_BASE + " x"), simhash64(_BASE + " x"))
        self.assertLessEqual(hamming(a, b), 3, "one-word edit should be near")
        self.assertGreater(hamming(a, c), 3, "distinct text should be far")
        self.assertTrue(is_near_duplicate(a, b, 3))
        self.assertFalse(is_near_duplicate(a, c, 3))
        self.assertFalse(is_near_duplicate(0, b, 3))  # empty never matches

    def test_fits_signed_sqlite_integer(self):
        # must be storable as a signed 64-bit INTEGER
        for t in (_BASE, _FAR, "x", "a b c d e f g"):
            v = simhash64(t)
            self.assertGreaterEqual(v, -(1 << 63))
            self.assertLess(v, 1 << 63)


class TestClusterAndCollapse(unittest.TestCase):
    def setUp(self):
        self.db = os.path.join(tempfile.mkdtemp(), "dup.db")
        self.st = Storage(self.db)
        self.st.ensure_host(HOST)
        # three pages: /1 and /2 are near-duplicate mirrors, /3 is distinct.
        # distinct content_hashes so exact dedup does NOT drop them.
        self.st.store_page(f"http://{HOST}/1", HOST, "T", _BASE + " uniquealpha",
                           "c1", 200, "text/html", 10, 1.0)
        self.st.store_page(f"http://{HOST}/2", HOST, "T", _BASE + " uniquebeta",
                           "c2", 200, "text/html", 10, 2.0)
        self.st.store_page(f"http://{HOST}/3", HOST, "T", _FAR,
                           "c3", 200, "text/html", 10, 3.0)

    def tearDown(self):
        self.st.close()

    def test_collapse_via_simhash_without_clustering(self):
        st = self.st
        _, total = st.search("mirrortoken")            # raw: all three match
        self.assertEqual(total, 3)
        res, total2 = st.search("mirrortoken", collapse=True)
        self.assertEqual(total2, 3, "raw total is unchanged by collapse")
        urls = [r["url"] for r in res]
        self.assertEqual(len(urls), 2, "one mirror should be collapsed away")
        self.assertIn(f"http://{HOST}/3", urls)
        self.assertEqual(
            sum(1 for u in urls if u.endswith("/1") or u.endswith("/2")), 1)

    def test_cluster_mirrors_assigns_shared_id(self):
        st = self.st
        multi = st.cluster_mirrors(threshold=3)
        self.assertEqual(multi, 1, "exactly one multi-page mirror cluster")
        cids = {r["url"]: r["cluster_id"] for r in
                st.db.execute("SELECT url, cluster_id FROM pages")}
        self.assertEqual(cids[f"http://{HOST}/1"], cids[f"http://{HOST}/2"])
        self.assertNotEqual(cids[f"http://{HOST}/1"], cids[f"http://{HOST}/3"])


if __name__ == "__main__":
    unittest.main()
