"""Server tests: HTML search page, JSON API, stats page and XSS-safety."""

import json
import os
import tempfile
import threading
import unittest
from urllib.request import urlopen
from urllib.parse import urlencode

from websearch import index, server
try:
    from tests.common import crawl_fixture
except ImportError:  # discover -s tests (top-level = tests/)
    from common import crawl_fixture
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


class ServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(cls.db)
        conn, _ = crawl_fixture(cls.site, cls.db)
        # Inject a doc with hostile content to prove snippets are escaped.
        index.upsert_document(
            conn, cls.site.url("/xss"), "XSS Probe Page", "desc",
            "xsspayloadmarker <script>alert('pwned')</script> trailing text",
            lang="en")
        index.finalize(conn)
        conn.close()

        cls.httpd = server.make_server(cls.db, host="127.0.0.1", port=0)
        cls.port = cls.httpd.server_address[1]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever,
                                      kwargs={"poll_interval": 0.05},
                                      daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()
        cls.thread.join(timeout=3)
        cls.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _get(self, path):
        url = "http://127.0.0.1:%d%s" % (self.port, path)
        with urlopen(url, timeout=5) as r:
            return r.status, r.read().decode("utf-8"), r.headers

    def test_home_page(self):
        status, body, _ = self._get("/")
        self.assertEqual(status, 200)
        self.assertIn("<form", body)
        self.assertIn("name=q", body)

    def test_search_page_has_results(self):
        status, body, headers = self._get(
            "/search?" + urlencode({"q": "inverted index"}))
        self.assertEqual(status, 200)
        self.assertIn("text/html", headers.get("Content-Type", ""))
        self.assertIn("/search-engines", body)
        self.assertIn("<mark>", body)          # query terms highlighted
        self.assertIn("result", body)

    def test_json_api(self):
        status, body, headers = self._get(
            "/api/search?" + urlencode({"q": "python programming"}))
        self.assertEqual(status, 200)
        self.assertIn("application/json", headers.get("Content-Type", ""))
        data = json.loads(body)
        self.assertGreater(data["total"], 0)
        self.assertIsInstance(data["results"], list)
        self.assertIn("elapsed_seconds", data)
        first = data["results"][0]
        for key in ("url", "title", "host", "snippet_html", "score"):
            self.assertIn(key, first)

    def test_about_page(self):
        status, body, _ = self._get("/about")
        self.assertEqual(status, 200)
        self.assertIn("Documents indexed", body)
        self.assertIn("Top hosts", body)

    def test_pagination_param_safe(self):
        status, _, _ = self._get(
            "/search?" + urlencode({"q": "programming", "page": "999"}))
        self.assertEqual(status, 200)
        status, _, _ = self._get(
            "/search?" + urlencode({"q": "programming", "page": "notanumber"}))
        self.assertEqual(status, 200)

    def test_snippet_xss_escaped(self):
        status, body, _ = self._get(
            "/search?" + urlencode({"q": "xsspayloadmarker"}))
        self.assertEqual(status, 200)
        self.assertIn("xsspayloadmarker", body)
        self.assertNotIn("<script>alert('pwned')</script>", body)
        self.assertIn("&lt;script&gt;", body)

    def test_query_echo_escaped(self):
        status, body, _ = self._get(
            "/search?" + urlencode({"q": "<script>alert(1)</script>"}))
        self.assertEqual(status, 200)
        self.assertNotIn("<script>alert(1)</script>", body)


if __name__ == "__main__":
    unittest.main()
