"""Image vertical: <img> extraction, relative-URL resolution, no-fetch rendering.

The crown property tested here: the image vertical NEVER fetches an image
server-side.  Extraction reads only metadata already present in crawled HTML,
resolution is pure string work, and the results view emits <img src> pointing at
the ORIGINAL remote URL so the browser (not the server) loads it.
"""

import os
import tempfile
import threading
import unittest
from urllib.parse import urlencode
from urllib.request import urlopen

from websearch import htmlparse, index, server
from websearch.crawler import Crawler, CrawlConfig
try:
    from tests.common import crawl_fixture
except ImportError:  # discover -s tests (top-level = tests/)
    from common import crawl_fixture
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


SAMPLE_HTML = """<!doctype html><html lang=en><head><title>Gallery</title></head>
<body>
<p>Photos of wildlife in the park.</p>
<img src="pics/fox.png" alt="a red fox" title="fox photo">
<p>More about the red fox habitat and diet.</p>
<img src="/static/owl.jpg" alt="barn owl">
<img src="data:image/png;base64,AAAA" alt="ignored inline data uri">
<img src="http://cdn.other.test/eagle.gif" alt="&quot;&gt;<script>bad()</script>">
</body></html>"""


class ImageExtractionTest(unittest.TestCase):
    def test_extract_src_alt_title_and_context(self):
        ex = htmlparse.extract(SAMPLE_HTML)
        srcs = [im[0] for im in ex.images]
        self.assertIn("pics/fox.png", srcs)
        self.assertIn("/static/owl.jpg", srcs)
        fox = next(im for im in ex.images if im[0] == "pics/fox.png")
        self.assertEqual(fox[1], "a red fox")       # alt
        self.assertEqual(fox[2], "fox photo")       # title
        self.assertIn("wildlife", fox[3])           # surrounding context text

    def test_hostile_alt_captured_verbatim(self):
        # Extraction stores the raw alt; escaping happens only at render time.
        ex = htmlparse.extract(SAMPLE_HTML)
        evil = next(im for im in ex.images if im[0].endswith("eagle.gif"))
        self.assertIn("<script>bad()</script>", evil[1])


class ImageResolveStoreTest(unittest.TestCase):
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

    def _store_sample(self, page="http://ex.test/gallery/index.html"):
        ex = htmlparse.extract(SAMPLE_HTML)
        doc_id = index.upsert_document(
            self.conn, page, "Gallery", "", "wildlife body", host="ex.test")
        cr = Crawler(self.conn, CrawlConfig(scope_hosts=["ex.test"]))
        cr._index_images(doc_id, page, page, ex)     # base = page URL; NO fetch
        self.conn.commit()
        return doc_id

    def test_relative_resolution_and_scheme_filtering(self):
        self._store_sample()
        srcs = [r[0] for r in self.conn.execute("SELECT src FROM images")]
        # Relative srcs resolved against the page's directory / root.
        self.assertIn("http://ex.test/gallery/pics/fox.png", srcs)
        self.assertIn("http://ex.test/static/owl.jpg", srcs)
        # Absolute cross-host src kept as-is.
        self.assertIn("http://cdn.other.test/eagle.gif", srcs)
        # data: URI dropped (not http/https) -- and never fetched.
        self.assertFalse(any(s.startswith("data:") for s in srcs))

    def test_internal_ip_srcs_dropped_without_opening_socket(self):
        # <img> thumbnails whose host is an internal-range IP LITERAL are dropped
        # at index time (a client-side SSRF / internal-port-scan vector), while
        # hostnames and public IPs are kept.  Classification must open NO socket
        # (no DNS, no connect) -- proven with a dial-out tripwire.
        import socket
        html = (
            '<html><body><p>ctx pixel photo</p>'
            '<img src="http://169.254.169.254/meta" alt="cloud pixel">'
            '<img src="http://127.0.0.1:8803/a" alt="loopback pixel">'
            '<img src="http://[::1]/a" alt="v6loop pixel">'
            '<img src="http://192.168.0.1/r.png" alt="lan pixel">'
            '<img src="http://10.1.2.3/x" alt="ten pixel">'
            '<img src="http://cdn.public.test/ok.png" alt="public pixel">'
            '<img src="/rel/kitten.png" alt="relative pixel">'
            '</body></html>')
        ex = htmlparse.extract(html)
        page = "http://ex.test/p"
        doc_id = index.upsert_document(self.conn, page, "P", "", "b",
                                       host="ex.test")
        cr = Crawler(self.conn, CrawlConfig(scope_hosts=["ex.test"]))

        def _boom(*a, **k):
            raise AssertionError("image indexing must not open a socket")
        saved = (socket.getaddrinfo, socket.create_connection,
                 socket.socket.connect)
        socket.getaddrinfo = _boom
        socket.create_connection = _boom
        socket.socket.connect = _boom
        try:
            cr._index_images(doc_id, page, page, ex)      # base = page; NO fetch
        finally:
            (socket.getaddrinfo, socket.create_connection,
             socket.socket.connect) = saved
        self.conn.commit()

        srcs = [r[0] for r in self.conn.execute("SELECT src FROM images")]
        for bad in ("169.254.169.254", "127.0.0.1", "[::1]", "192.168.0.1",
                    "10.1.2.3"):
            self.assertFalse(any(bad in s for s in srcs),
                             "internal src leaked: %s" % bad)
        self.assertIn("http://cdn.public.test/ok.png", srcs)   # public kept
        self.assertIn("http://ex.test/rel/kitten.png", srcs)   # relative kept

    def test_image_search_matches_and_shape(self):
        self._store_sample()
        hits = index.image_search(self.conn, "fox")
        self.assertTrue(any(h["src"].endswith("fox.png") for h in hits))
        for h in hits:
            self.assertEqual(set(h), {"src", "alt", "title", "page_url", "host"})

    def test_recrawl_replaces_images_and_syncs_fts(self):
        page = "http://ex.test/p"
        doc_id = index.upsert_document(self.conn, page, "P", "", "b",
                                       host="ex.test")
        index.replace_images(self.conn, doc_id, page, "ex.test",
                             [("http://ex.test/a.png", "alphamark", "", "ctx")])
        index.replace_images(self.conn, doc_id, page, "ex.test",
                             [("http://ex.test/b.png", "betamark", "", "ctx")])
        self.conn.commit()
        rows = [r[0] for r in self.conn.execute(
            "SELECT src FROM images WHERE doc_id=?", (doc_id,))]
        self.assertEqual(rows, ["http://ex.test/b.png"])   # old row replaced

        def match(term):
            return self.conn.execute(
                "SELECT COUNT(*) FROM images_fts WHERE images_fts MATCH ?",
                (term,)).fetchone()[0]
        self.assertEqual(match("betamark"), 1)             # new alt indexed
        self.assertEqual(match("alphamark"), 0)            # old alt purged


class ImageServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(cls.db)
        conn, _ = crawl_fixture(cls.site, cls.db)
        # Inject an image whose thumbnail points at a LOOPBACK fixture URL the
        # crawl never visited, with a hostile alt.  If the server ever fetched
        # the image, the fixture's hit counter for this path would tick.
        cls.img_url = cls.site.url("/pixel-should-not-be-fetched.png")
        row = conn.execute("SELECT id, url FROM docs LIMIT 1").fetchone()
        index.replace_images(
            conn, row["id"], row["url"], "127.0.0.1",
            [(cls.img_url, '"><script>alert(9)</script>', "wildfox",
              "a wild fox in surrounding context")])
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
        cls.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _get(self, path):
        with urlopen("http://127.0.0.1:%d%s" % (self.port, path), timeout=5) as r:
            return r.status, r.read().decode("utf-8"), r.headers

    def test_image_results_render_remote_src_without_fetching(self):
        status, body, _ = self._get("/images?" + urlencode({"q": "wildfox"}))
        self.assertEqual(status, 200)
        self.assertIn("<img", body)
        self.assertIn(self.img_url, body)          # remote src rendered as-is
        # Server did NOT fetch the image (browser would): fixture untouched.
        self.assertEqual(
            self.site.hits.get("/pixel-should-not-be-fetched.png", 0), 0)

    def test_hostile_alt_is_escaped(self):
        _, body, _ = self._get("/images?" + urlencode({"q": "wildfox"}))
        self.assertNotIn("<script>alert(9)</script>", body)
        self.assertIn("&lt;script&gt;", body)

    def test_search_type_images_routes_to_vertical(self):
        _, body, _ = self._get(
            "/search?" + urlencode({"type": "images", "q": "wildfox"}))
        self.assertIn("<img", body)
        self.assertIn(self.img_url, body)

    def test_results_offer_images_tab(self):
        _, body, _ = self._get("/search?" + urlencode({"q": "inverted index"}))
        self.assertIn("/images", body)
        self.assertIn(">Images<", body)


if __name__ == "__main__":
    unittest.main()
