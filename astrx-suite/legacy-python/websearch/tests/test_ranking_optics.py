"""Anti-SEO content-quality signal + ranking optics (boost:/penalize: hosts)."""
import os
import tempfile
import unittest

from websearch import index, ranking, dedup


def _seed(docs):
    path = os.path.join(tempfile.mkdtemp(), "t.db")
    conn = index.connect(path)
    for url, host, title, body in docs:
        index.upsert_document(conn, url, title, "", body, host=host,
                              fetched_at=1000,
                              simhash=dedup.signed64(dedup.simhash(body)))
    conn.commit()
    return conn


class TestQuality(unittest.TestCase):
    def test_content_quality_scale(self):
        self.assertEqual(ranking._content_quality({"body": ""}), 0.0)
        self.assertEqual(ranking._content_quality({"body": "x" * 50}), 0.0)
        self.assertEqual(ranking._content_quality({"body": "x" * 1500}), 1.0)
        mid = ranking._content_quality({"body": "x" * 600})
        self.assertTrue(0.0 < mid < 1.0)

    def test_quality_in_signals(self):
        conn = _seed([("http://a/1", "a", "Alpha", "alpha " * 400)])
        res, _, _, _ = ranking.search(conn, "alpha")
        self.assertIn("quality", res[0].signals)
        self.assertGreater(res[0].signals["quality"], 0.0)


class TestOptics(unittest.TestCase):
    def test_parse_boost_penalize(self):
        q = ranking.parse_query("hello boost:good.com penalize:bad.com")
        self.assertIn("good.com", q.boost)
        self.assertIn("bad.com", q.penalize)
        self.assertNotIn("boost", q.optional)     # operator consumed, not a term
        self.assertIn("hello", q.optional)

    def test_boost_lifts_host(self):
        conn = _seed([("http://a/1", "a.com", "Alpha", "topic aaa bbb ccc " * 20),
                      ("http://b/1", "b.com", "Beta", "topic eee fff ggg " * 20)])
        boosted = [r.url for r in ranking.search(conn, "topic boost:b.com")[0]]
        self.assertEqual(boosted[0], "http://b/1")

    def test_penalize_lowers_host(self):
        conn = _seed([("http://a/1", "a.com", "Alpha", "subject aaa bbb " * 20),
                      ("http://s/1", "spam.com", "Spam", "subject eee fff " * 20)])
        res = [r.url for r in
               ranking.search(conn, "subject penalize:spam.com")[0]]
        self.assertEqual(res[-1], "http://s/1")    # spam pushed to the bottom


if __name__ == "__main__":
    unittest.main()
