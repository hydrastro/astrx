"""More-like-this: SimHash-neighbour retrieval + no-JS /similar view."""

import os
import tempfile
import threading
import unittest
from urllib.parse import urlencode
from urllib.request import urlopen

from websearch import dedup, index, server


_BASE = (("alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu "
          "nu xi omicron pi rho sigma tau upsilon phi chi psi omega search "
          "engine crawler inverted index ranking relevance freshness authority "
          "document corpus token frontier robots canonical simhash dedup fetch "
          "parser ") * 6)
_BODY_A = _BASE + " sharedterm distinctivemarkeraaa footer2020"
_BODY_B = _BASE + " sharedterm distinctivemarkerbbb footer2021"   # near-dup
_BODY_C = (("mountain river valley weather rainfall hydrology geology soil "
            "climate forest ocean desert glacier volcano earthquake tundra "
            "savanna prairie wetland estuary basin canyon plateau ridge "
            "summit ") * 8) + " sharedterm"


class MoreLikeThisIndexTest(unittest.TestCase):
    def setUp(self):
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.conn = index.connect(self.db)
        self.ids = {}
        for url, host, body in (
            ("http://a.test/x", "a.test", _BODY_A),
            ("http://b.test/x", "b.test", _BODY_B),
            ("http://c.test/x", "c.test", _BODY_C),
        ):
            self.ids[url] = index.upsert_document(
                self.conn, url, "Doc", "desc for " + host, body, host=host,
                lang="en", simhash=dedup.signed64(dedup.simhash(body)))
        self.conn.commit()

    def tearDown(self):
        self.conn.close()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def test_returns_near_excludes_self_and_far(self):
        # Sanity on the fixture: B is near A, C is far (relative to MLT_HAMMING).
        a = dedup.simhash(_BODY_A)
        self.assertLessEqual(dedup.hamming(a, dedup.simhash(_BODY_B)),
                             index.MLT_HAMMING)
        self.assertGreater(dedup.hamming(a, dedup.simhash(_BODY_C)),
                           index.MLT_HAMMING)

        src, results = index.more_like_this(
            self.conn, doc_id=self.ids["http://a.test/x"])
        urls = [r["url"] for r in results]
        self.assertEqual(src["url"], "http://a.test/x")
        self.assertIn("http://b.test/x", urls)            # near neighbour kept
        self.assertNotIn("http://a.test/x", urls)         # self excluded
        self.assertNotIn("http://c.test/x", urls)         # far doc excluded

    def test_lookup_by_url(self):
        src, results = index.more_like_this(
            self.conn, url="http://a.test/x")
        self.assertEqual(src["url"], "http://a.test/x")
        self.assertIn("http://b.test/x", [r["url"] for r in results])

    def test_unknown_doc(self):
        src, results = index.more_like_this(self.conn, doc_id=999999)
        self.assertIsNone(src)
        self.assertEqual(results, [])

    def test_doc_without_fingerprint(self):
        rid = index.upsert_document(self.conn, "http://d.test/x", "D", "", "",
                                    host="d.test", simhash=0)
        self.conn.commit()
        src, results = index.more_like_this(self.conn, doc_id=rid)
        self.assertIsNotNone(src)
        self.assertEqual(results, [])


class SimilarServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        conn = index.connect(cls.db)
        cls.ids = {}
        for url, host, body in (
            ("http://a.test/x", "a.test", _BODY_A),
            ("http://b.test/x", "b.test", _BODY_B),
            ("http://c.test/x", "c.test", _BODY_C),
        ):
            cls.ids[url] = index.upsert_document(
                conn, url, "Doc " + host, "desc", body, host=host, lang="en",
                simhash=dedup.signed64(dedup.simhash(body)))
        index.finalize(conn)
        conn.close()
        cls.httpd = server.make_server(cls.db, host="127.0.0.1", port=0)
        cls.port = cls.httpd.server_address[1]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever,
                                      kwargs={"poll_interval": 0.05}, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()
        cls.thread.join(timeout=3)
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _get(self, path):
        with urlopen("http://127.0.0.1:%d%s" % (self.port, path), timeout=5) as r:
            return r.status, r.read().decode("utf-8"), r.headers

    def test_similar_by_url_renders_neighbour(self):
        status, body, _ = self._get(
            "/similar?" + urlencode({"url": "http://a.test/x"}))
        self.assertEqual(status, 200)
        self.assertIn("http://b.test/x", body)            # near neighbour shown
        self.assertNotIn("http://c.test/x", body)         # far doc absent
        self.assertIn("Documents similar to", body)

    def test_similar_by_id(self):
        status, body, _ = self._get(
            "/similar?" + urlencode({"id": self.ids["http://a.test/x"]}))
        self.assertEqual(status, 200)
        self.assertIn("http://b.test/x", body)

    def test_results_offer_similar_link(self):
        _, body, _ = self._get("/search?" + urlencode({"q": "sharedterm"}))
        self.assertIn("/similar?", body)


if __name__ == "__main__":
    unittest.main()
