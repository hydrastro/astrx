"""Index tests: FTS population, upsert semantics, link counts, PageRank."""

import os
import tempfile
import unittest

from websearch import index


class IndexTest(unittest.TestCase):
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
            ("http://a.test/1", "Alpha", "first", "the alpha document body text"),
            ("http://a.test/2", "Beta", "second", "the beta document mentions alpha"),
            ("http://b.test/3", "Gamma", "third", "gamma text without the others"),
        ]
        for u, t, d, b in docs:
            index.upsert_document(self.conn, u, t, d, b, lang="en")
        index.add_links(self.conn, "http://a.test/2", [("http://a.test/1", True)])
        index.add_links(self.conn, "http://b.test/3", [("http://a.test/1", True)])
        self.conn.commit()

    def test_fts_is_populated(self):
        self._seed()
        # FTS row count mirrors docs, and MATCH finds the right rows.
        n_docs = self.conn.execute("SELECT COUNT(*) FROM docs").fetchone()[0]
        n_fts = self.conn.execute("SELECT COUNT(*) FROM fts").fetchone()[0]
        self.assertEqual(n_docs, 3)
        self.assertEqual(n_fts, 3)
        rows = self.conn.execute(
            "SELECT d.url FROM fts JOIN docs d ON d.id=fts.rowid "
            "WHERE fts MATCH 'alpha' ORDER BY bm25(fts)").fetchall()
        self.assertEqual({r[0] for r in rows},
                         {"http://a.test/1", "http://a.test/2"})

    def test_upsert_updates_in_place(self):
        index.upsert_document(self.conn, "http://a.test/x", "T", "D",
                              "original body word", lang="en")
        index.upsert_document(self.conn, "http://a.test/x", "T", "D",
                              "replaced body token", lang="en")
        self.conn.commit()
        self.assertEqual(
            self.conn.execute("SELECT COUNT(*) FROM docs").fetchone()[0], 1)
        # Old term gone from the FTS index, new term present (triggers work).
        self.assertEqual(
            self.conn.execute(
                "SELECT COUNT(*) FROM fts WHERE fts MATCH 'original'"
            ).fetchone()[0], 0)
        self.assertEqual(
            self.conn.execute(
                "SELECT COUNT(*) FROM fts WHERE fts MATCH 'replaced'"
            ).fetchone()[0], 1)

    def test_incoming_and_pagerank(self):
        self._seed()
        index.finalize(self.conn)
        inc = dict(self.conn.execute("SELECT url, incoming FROM docs"))
        self.assertEqual(inc["http://a.test/1"], 2)   # linked from /2 and /3
        self.assertEqual(inc["http://a.test/2"], 0)
        ranks = [r[0] for r in self.conn.execute("SELECT rank FROM docs")]
        self.assertTrue(all(0.0 <= x <= 1.0 for x in ranks))
        # The most-linked page should carry the highest PageRank.
        best = self.conn.execute(
            "SELECT url FROM docs ORDER BY rank DESC LIMIT 1").fetchone()[0]
        self.assertEqual(best, "http://a.test/1")

    def test_stats(self):
        self._seed()
        st = index.stats(self.conn)
        self.assertEqual(st["docs"], 3)
        self.assertEqual(st["hosts"], 2)
        self.assertEqual(st["links"], 2)


if __name__ == "__main__":
    unittest.main()
