"""News (freshness-ordered) and Files (downloadable-doc) verticals — query-time."""
import os
import tempfile
import unittest

from websearch import index, ranking, dedup


def _seed(docs):
    path = os.path.join(tempfile.mkdtemp(), "t.db")
    conn = index.connect(path)
    for url, host, title, body, ct, fetched in docs:
        index.upsert_document(conn, url, title, "", body, host=host,
                              fetched_at=fetched, content_type=ct,
                              simhash=dedup.signed64(dedup.simhash(body)))
    conn.commit()
    return conn


class TestVerticals(unittest.TestCase):
    def test_files_filter_keeps_only_downloadables(self):
        conn = _seed([
            ("http://a/doc.pdf", "a", "Report alpha", "alpha report content",
             "application/pdf", 1000),
            ("http://a/page", "a", "Alpha page", "alpha content on a page",
             "text/html", 1000),
            ("http://a/data.zip", "a", "Alpha archive", "alpha zip bundle",
             "application/zip", 1000),
        ])
        res, _, _, _ = ranking.search(conn, "alpha", only_files=True)
        urls = {r.url for r in res}
        self.assertIn("http://a/doc.pdf", urls)
        self.assertIn("http://a/data.zip", urls)
        self.assertNotIn("http://a/page", urls)      # HTML excluded

    def test_files_by_url_suffix(self):
        conn = _seed([
            ("http://a/report.docx", "a", "Quarterly", "quarterly figures",
             "", 1000),                                # no content-type, suffix only
            ("http://a/index", "a", "Quarterly notes", "quarterly notes html",
             "text/html", 1000),
        ])
        urls = {r.url for r in ranking.search(conn, "quarterly", only_files=True)[0]}
        self.assertEqual(urls, {"http://a/report.docx"})

    def test_news_sorts_newest_first(self):
        conn = _seed([
            ("http://a/old", "a", "News old", "breaking news story report",
             "text/html", 1000),
            ("http://a/new", "a", "News new", "breaking news update report",
             "text/html", 2000),
        ])
        res, _, _, _ = ranking.search(conn, "news", sort="fresh")
        self.assertEqual(res[0].url, "http://a/new")   # freshest first

    def test_default_relevance_unaffected(self):
        conn = _seed([("http://a/1", "a", "Zebra", "zebra facts here",
                       "text/html", 1000)])
        res, _, _, _ = ranking.search(conn, "zebra")
        self.assertTrue(res and res[0].url == "http://a/1")


if __name__ == "__main__":
    unittest.main()
