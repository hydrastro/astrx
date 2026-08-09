"""Feature 1: cross-domain inlink authority (host-level PageRank).

Proves that authority flows across *domains* (the previously-discarded
``internal=0`` edges), that same-host navigation links do NOT manufacture
authority, and that the signal reaches the ranking API as ``authority``.
"""

import os
import tempfile
import unittest

from websearch import index, ranking


class HostAuthorityTest(unittest.TestCase):
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

    def _seed(self):
        docs = [
            ("http://hub.test/1", "Hub", "authority hub",
             "the hub page authoritymarker everyone links to"),
            ("http://one.test/1", "One", "site one", "one authoritymarker text"),
            ("http://two.test/1", "Two", "site two", "two authoritymarker text"),
            ("http://three.test/1", "Three", "site three",
             "three authoritymarker text"),
            ("http://lonely.test/1", "Lonely", "no inlinks",
             "lonely authoritymarker page nobody links to"),
        ]
        for u, t, d, b in docs:
            index.upsert_document(self.conn, u, t, d, b, lang="en")
        # Cross-domain endorsements of hub.test (internal=0 -- the edges the old
        # code recorded and threw away).
        index.add_links(self.conn, "http://one.test/1",
                        [("http://hub.test/1", False)])
        index.add_links(self.conn, "http://two.test/1",
                        [("http://hub.test/1", False)])
        index.add_links(self.conn, "http://three.test/1",
                        [("http://hub.test/1", False)])
        index.add_links(self.conn, "http://hub.test/1",
                        [("http://one.test/1", False)])
        # Same-host nav link: must be ignored, cannot mint authority.
        index.add_links(self.conn, "http://lonely.test/1",
                        [("http://lonely.test/99", True)])
        self.conn.commit()

    def test_cross_domain_authority_beats_internal(self):
        self._seed()
        index.compute_host_authority(self.conn)
        auth = dict(self.conn.execute("SELECT host, rank FROM host_authority"))
        # Every indexed host is a node.
        for h in ("hub.test", "one.test", "two.test", "three.test",
                  "lonely.test"):
            self.assertIn(h, auth)
        # The thrice-endorsed hub is the most authoritative host.
        self.assertEqual(max(auth, key=auth.get), "hub.test")
        self.assertAlmostEqual(auth["hub.test"], 1.0)  # normalised max
        # Same-host links minted no authority for lonely.test.
        self.assertLess(auth["lonely.test"], auth["hub.test"])
        self.assertLess(auth["lonely.test"], auth["one.test"])

    def test_host_rank_denormalised_onto_docs(self):
        self._seed()
        index.compute_host_authority(self.conn)
        hr = dict(self.conn.execute("SELECT url, host_rank FROM docs"))
        self.assertAlmostEqual(hr["http://hub.test/1"], 1.0)
        self.assertLess(hr["http://lonely.test/1"], hr["http://hub.test/1"])

    def test_authority_signal_exposed_in_ranking(self):
        self._seed()
        index.finalize(self.conn)
        results, total, _, _ = ranking.search(self.conn, "authoritymarker")
        self.assertGreater(total, 0)
        by_host = {r.host: r for r in results}
        self.assertIn("hub.test", by_host)
        # The authority signal is present and strongest for the hub host.
        self.assertIn("authority", by_host["hub.test"].signals)
        self.assertGreater(by_host["hub.test"].signals["authority"], 0.0)
        if "lonely.test" in by_host:
            self.assertGreater(by_host["hub.test"].signals["authority"],
                               by_host["lonely.test"].signals["authority"])


if __name__ == "__main__":
    unittest.main()
