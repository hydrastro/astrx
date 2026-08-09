"""(f) Search returns the expected page ranked for a query, exposes a JSON API,
and NEVER returns the blocklisted page."""

import http.client
import json
import os
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer

try:  # package-mode discovery (python3 -m unittest discover)
    from .fixtures import Fixture, ONION_MAIN, BLOCK_KEYWORD, TOKEN_PAGE_B, TOKEN_ABOUT
    from .helpers import build_crawler
except ImportError:  # top-level discovery (python3 -m unittest discover -s tests)
    from fixtures import Fixture, ONION_MAIN, BLOCK_KEYWORD, TOKEN_PAGE_B, TOKEN_ABOUT
    from helpers import build_crawler
from onioncrawler.search import SearchApp, make_handler


class TestSearch(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.fx = Fixture().start()
        cls.dir = tempfile.mkdtemp()
        cls.db = os.path.join(cls.dir, "search.db")
        cls.cfg, cls.storage, cls.crawler = build_crawler(cls.db, cls.fx, workers=1)
        cls.crawler.run([cls.fx.seed_url()])

    @classmethod
    def tearDownClass(cls):
        cls.storage.close()
        cls.fx.stop()

    def test_query_returns_expected_page_ranked(self):
        results, total = self.storage.search(TOKEN_PAGE_B, limit=10)
        self.assertGreaterEqual(total, 1)
        self.assertTrue(results[0]["url"].endswith("/page-b"),
                        f"expected page-b top, got {results[0]['url']}")

    def test_about_page_findable(self):
        results, total = self.storage.search(TOKEN_ABOUT, limit=10)
        self.assertGreaterEqual(total, 1)
        self.assertTrue(results[0]["url"].endswith("/about"))

    def test_bm25_title_weight_beats_body_frequency(self):
        # Rigorous title-weight check: 'weighttoken' appears once in one page's
        # TITLE and three times in another page's BODY. With title weighted 10x,
        # the title match must still rank first despite lower term frequency.
        import tempfile, os
        from onioncrawler.storage import Storage
        st = Storage(os.path.join(tempfile.mkdtemp(), "weight.db"))
        host = "g" * 56 + ".onion"
        st.ensure_host(host)
        st.store_page(f"http://{host}/title-hit", host, "weighttoken here",
                      "unrelated body filler", "h1", 200, "text/html", 10, 1.0)
        st.store_page(f"http://{host}/body-hit", host, "unrelated title",
                      "weighttoken weighttoken weighttoken body", "h2", 200,
                      "text/html", 10, 2.0)
        results, total = st.search("weighttoken", limit=10)
        st.close()
        self.assertEqual(total, 2)
        self.assertTrue(results[0]["url"].endswith("/title-hit"),
                        "title match should outrank higher body frequency")

    def test_snippet_is_highlighted(self):
        results, _ = self.storage.search(TOKEN_PAGE_B, limit=10)
        self.assertIn("<mark>", results[0]["snippet"].lower())

    def test_blocklisted_page_never_returned(self):
        results, total = self.storage.search(BLOCK_KEYWORD, limit=10)
        self.assertEqual(total, 0, "blocklisted content must not be searchable")
        self.assertEqual(results, [])

    def test_pagination(self):
        results, total = self.storage.search("content", limit=1, offset=0)
        if total > 1:
            page2, _ = self.storage.search("content", limit=1, offset=1)
            self.assertNotEqual(results[0]["url"], page2[0]["url"])

    def test_http_ui_and_json_api(self):
        app = SearchApp(self.storage, self.cfg)
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(app))
        port = httpd.server_address[1]
        t = threading.Thread(target=httpd.serve_forever, daemon=True)
        t.start()
        try:
            conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)

            # HTML UI returns the expected result, no <script> tags
            conn.request("GET", f"/search?q={TOKEN_PAGE_B}")
            r = conn.getresponse()
            body = r.read().decode("utf-8")
            self.assertEqual(r.status, 200)
            self.assertIn("/page-b", body)
            self.assertNotIn("<script", body.lower())

            # JSON API
            conn.request("GET", f"/api/search?q={TOKEN_PAGE_B}")
            r = conn.getresponse()
            data = json.loads(r.read().decode("utf-8"))
            self.assertGreaterEqual(data["total"], 1)
            self.assertTrue(data["results"][0]["url"].endswith("/page-b"))

            # blocklisted query -> empty via API too
            conn.request("GET", f"/api/search?q={BLOCK_KEYWORD}")
            r = conn.getresponse()
            data = json.loads(r.read().decode("utf-8"))
            self.assertEqual(data["total"], 0)
            conn.close()
        finally:
            httpd.shutdown()
            httpd.server_close()

    def test_huge_page_param_returns_200_not_500(self):
        # A crafted page value must not blow OFFSET past SQLite's int range.
        app = SearchApp(self.storage, self.cfg)
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(app))
        port = httpd.server_address[1]
        t = threading.Thread(target=httpd.serve_forever, daemon=True)
        t.start()
        try:
            conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
            conn.request("GET", "/search?q=content&page=" + ("9" * 25))
            r = conn.getresponse()
            self.assertEqual(r.status, 200)
            r.read()
            conn.request("GET", "/api/search?q=content&page=99999999999999999999")
            r = conn.getresponse()
            self.assertEqual(r.status, 200)
            r.read()
            conn.close()
        finally:
            httpd.shutdown()
            httpd.server_close()

    def test_posthoc_blocklist_hides_already_indexed_pages(self):
        # Pages are indexed while the host is allowed; the operator later adds
        # the host (and a keyword) to the blocklist. Reconciliation at search
        # startup must make those pages disappear from results.
        import os
        import tempfile
        from onioncrawler.storage import Storage
        from onioncrawler.abuse import AbuseFilter

        st = Storage(os.path.join(tempfile.mkdtemp(), "recon.db"))
        bad_host = "h" * 56 + ".onion"
        ok_host = "i" * 56 + ".onion"
        st.ensure_host(bad_host)
        st.ensure_host(ok_host)
        st.store_page(f"http://{bad_host}/evil", bad_host, "Evil",
                      "recontoken here", "c1", 200, "text/html", 10, 1.0)
        st.store_page(f"http://{ok_host}/ok", ok_host, "OK",
                      "recontoken clean", "c2", 200, "text/html", 10, 2.0)
        st.store_page(f"http://{ok_host}/kw", ok_host, "kw",
                      "contains nastykw inside body", "c3", 200, "text/html", 10, 3.0)

        # Everything searchable before reconciliation.
        _, before = st.search("recontoken")
        self.assertEqual(before, 2)
        _, kw_before = st.search("nastykw")
        self.assertEqual(kw_before, 1)

        af = AbuseFilter(hosts=[bad_host], keywords=["nastykw"])
        res = st.apply_abuse_blocklist(af)
        self.assertEqual(res["hosts_blocked"], 1)
        self.assertEqual(res["pages_removed"], 1)

        # blocked host's page hidden; only the clean host page remains
        rows, total = st.search("recontoken")
        self.assertEqual(total, 1)
        self.assertTrue(rows[0]["url"].endswith("/ok"))
        self.assertTrue(all(r["host"] != bad_host for r in rows))
        # keyword-hit page removed entirely
        _, kw_after = st.search("nastykw")
        self.assertEqual(kw_after, 0)
        st.close()


if __name__ == "__main__":
    unittest.main()
