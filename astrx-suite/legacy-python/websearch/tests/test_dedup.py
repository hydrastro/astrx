"""Feature 5: near-duplicate (SimHash) collapsing of cross-host mirrors."""

import os
import tempfile
import unittest

from websearch import dedup, index, ranking

# Realistic (long) bodies so SimHash is meaningful; the mirror differs only in a
# footer date + a marker token -- exactly the "same article, different domain"
# case exact-hash dedup misses.
_WORDS = (
    "alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi "
    "omicron pi rho sigma tau upsilon phi chi psi omega search engine crawler "
    "inverted index ranking relevance freshness authority document corpus token "
    "frontier robots canonical simhash dedup pagerank fetch parser").split()
_BASE = " ".join(_WORDS * 6)
_BODY_A = _BASE + " sharedterm distinctivemarkeraaa footer2020"
_BODY_B = _BASE + " sharedterm distinctivemarkerbbb footer2021"  # near-dup mirror
_WORDS2 = (
    "mountain river valley weather rainfall hydrology geology soil climate "
    "forest ocean desert glacier volcano earthquake tundra savanna prairie "
    "wetland estuary basin canyon plateau ridge summit").split()
_BODY_C = " ".join(_WORDS2 * 8) + " sharedterm"


class SimhashUnitTest(unittest.TestCase):
    def test_near_and_far(self):
        a = dedup.simhash(_BODY_A)
        b = dedup.simhash(_BODY_B)
        c = dedup.simhash(_BODY_C)
        self.assertLessEqual(dedup.hamming(a, b), ranking.SIMHASH_HAMMING)
        self.assertGreater(dedup.hamming(a, c), ranking.SIMHASH_HAMMING)

    def test_signed64_roundtrips_for_hamming(self):
        h = dedup.simhash(_BODY_A)
        self.assertEqual(dedup.hamming(dedup.signed64(h), h), 0)


class CollapseTest(unittest.TestCase):
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

    def _add(self, url, host, body):
        index.upsert_document(
            self.conn, url, "Doc", "", body, host=host, lang="en",
            simhash=dedup.signed64(dedup.simhash(body)))

    def test_cross_host_mirror_collapsed(self):
        self._add("http://mirror1.test/a", "mirror1.test", _BODY_A)
        self._add("http://mirror2.test/a", "mirror2.test", _BODY_B)  # mirror
        self._add("http://other.test/c", "other.test", _BODY_C)
        self.conn.commit()

        results, total, _, _ = ranking.search(self.conn, "sharedterm")
        hosts = [r.host for r in results]
        # The two mirrors collapse to one; the distinct page stays.
        self.assertIn("other.test", hosts)
        self.assertEqual(len({"mirror1.test", "mirror2.test"} & set(hosts)), 1)
        self.assertEqual(total, 2)

    def test_same_host_near_dups_not_collapsed(self):
        # Same host -> NOT treated as mirrors (protects paginated/templated pages).
        self._add("http://one.test/a", "one.test", _BODY_A)
        self._add("http://one.test/b", "one.test", _BODY_B)
        self.conn.commit()
        results, total, _, _ = ranking.search(self.conn, "sharedterm")
        self.assertEqual(total, 2)
        self.assertEqual(len(results), 2)

    def test_collapse_can_be_disabled(self):
        self._add("http://mirror1.test/a", "mirror1.test", _BODY_A)
        self._add("http://mirror2.test/a", "mirror2.test", _BODY_B)
        self.conn.commit()
        old = ranking.SIMHASH_HAMMING
        ranking.SIMHASH_HAMMING = -1
        try:
            _, total, _, _ = ranking.search(self.conn, "sharedterm")
            self.assertEqual(total, 2)      # both mirrors shown when disabled
        finally:
            ranking.SIMHASH_HAMMING = old


if __name__ == "__main__":
    unittest.main()
