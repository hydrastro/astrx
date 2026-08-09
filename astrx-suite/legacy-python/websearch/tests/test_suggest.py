"""Suggest / autocomplete: bounded Levenshtein, prefix completion, fuzzy fallback."""

import json
import os
import tempfile
import threading
import unittest
from urllib.parse import urlencode
from urllib.request import urlopen

from websearch import index, server, suggest


_DOCS = [
    ("http://s.test/1", "Inverted Index", "",
     "the inverted index maps terms to documents inverted inverted"),
    ("http://s.test/2", "Search Engine", "",
     "a search engine builds an inverted index and a ranking engine"),
    ("http://s.test/3", "Python", "",
     "python programming language python python scripting"),
    ("http://s.test/4", "Ranking", "",
     "ranking relevance bm25 ranking freshness"),
    # Many terms sharing a prefix, to exercise the suggestion cap.
    ("http://s.test/5", "Caps", "", " ".join("cap%02d" % i for i in range(20))),
]


def _seed(conn):
    for u, t, d, b in _DOCS:
        index.upsert_document(conn, u, t, d, b, lang="en")
    conn.commit()


class LevenshteinTest(unittest.TestCase):
    def test_basic_distances(self):
        self.assertEqual(suggest.levenshtein("kitten", "kitten", 2), 0)
        self.assertEqual(suggest.levenshtein("kitten", "sitten", 2), 1)
        self.assertEqual(suggest.levenshtein("inverted", "invrted", 2), 1)

    def test_cap_and_early_exit(self):
        # Distance exceeds the cap -> returns exactly max_dist + 1.
        self.assertEqual(suggest.levenshtein("abc", "xyz", 1), 2)
        self.assertEqual(suggest.levenshtein("python", "xxxxxx", 2), 3)
        # Length-difference shortcut also honours the cap.
        self.assertEqual(suggest.levenshtein("a", "abcdef", 2), 3)


class SuggestIndexTest(unittest.TestCase):
    def setUp(self):
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.conn = index.connect(self.db)
        _seed(self.conn)

    def tearDown(self):
        self.conn.close()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def test_vocab_prefix_orders_by_frequency(self):
        terms = [t for t, _ in index.vocab_prefix(self.conn, "inv", limit=5)]
        self.assertIn("inverted", terms)

    def test_prefix_completion(self):
        out = suggest.suggest(self.conn, "inv")
        self.assertIn("inverted", out)

    def test_multiword_preserves_head(self):
        out = suggest.suggest(self.conn, "search eng")
        self.assertIn("search engine", out)
        self.assertTrue(all(s.startswith("search ") for s in out))

    def test_edit_distance_fallback(self):
        # 'invrted' is a typo of 'inverted' -- no term starts with it, so only
        # the bounded edit-distance pass can recover it.
        out = suggest.suggest(self.conn, "invrted")
        self.assertIn("inverted", out)

    def test_popular_query_signal(self):
        out = suggest.suggest(self.conn, "inv",
                              popular=["inverted index tutorial"])
        self.assertIn("inverted index tutorial", out)

    def test_suggestions_are_capped(self):
        out = suggest.suggest(self.conn, "cap", limit=10)
        self.assertEqual(len(out), 10)          # 20 candidates, capped to 10

    def test_empty_query(self):
        self.assertEqual(suggest.suggest(self.conn, "   "), [])

    def test_surrogate_boundary_prefix_does_not_crash(self):
        # U+D7FF is a legal BMP char; incrementing it to form the range-scan
        # upper bound yields the lone surrogate U+D800, which is not UTF-8
        # encodable and used to crash the SQLite bind (-> /suggest 500).
        # _prefix_upper must decline (callers fall back to GLOB) and the whole
        # suggest path must stay exception-free.
        self.assertIsNone(index._prefix_upper("퟿"))
        self.assertIsNone(index._prefix_upper("hel퟿"))
        self.assertEqual(index._prefix_upper("hell"), "helm")  # normal successor
        for q in ("퟿", "hel퟿", "inverted ퟿"):
            self.assertIsInstance(index.vocab_prefix(self.conn, q), list)
            self.assertIsInstance(index.vocab_candidates(self.conn, q), list)
            self.assertIsInstance(suggest.suggest(self.conn, q), list)


class SuggestServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        conn = index.connect(cls.db)
        _seed(conn)
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

    def test_suggest_json_shape(self):
        status, body, headers = self._get("/suggest?" + urlencode({"q": "inv"}))
        self.assertEqual(status, 200)
        self.assertIn("application/x-suggestions+json",
                      headers.get("Content-Type", ""))
        data = json.loads(body)
        self.assertEqual(data[0], "inv")           # [query, [completions]]
        self.assertIsInstance(data[1], list)
        self.assertIn("inverted", data[1])

    def test_suggest_empty_query(self):
        status, body, _ = self._get("/suggest?q=")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(body), ["", []])

    def test_surrogate_query_does_not_500(self):
        # Regression: a query word ending in U+D7FF (%ED%9F%BF) used to 500.
        for frag in ("퟿", "hel퟿"):
            status, body, _ = self._get("/suggest?" + urlencode({"q": frag}))
            self.assertEqual(status, 200)
            self.assertIsInstance(json.loads(body)[1], list)

    def test_long_query_is_capped_at_edge(self):
        # The endpoint caps q independently of suggest()'s internal q[:64], so
        # the echoed query (and parse cost) is bounded even for a huge input.
        status, body, _ = self._get("/suggest?" + urlencode({"q": "z" * 5000}))
        self.assertEqual(status, 200)
        self.assertLessEqual(len(json.loads(body)[0]), server.SUGGEST_MAX_QUERY)


if __name__ == "__main__":
    unittest.main()
