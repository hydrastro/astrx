"""Browse / recently-added / per-query RSS views + POST /api/block admin."""

import hashlib
import json
import os
import sys
import threading
import unittest
import urllib.error
import urllib.request
from urllib.parse import urlencode
from xml.etree import ElementTree

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.metadata import TorrentMeta
from torrentds.search import make_search_server
from torrentds.store import Store

ADMIN_TOKEN = "s3cr3t-admin-token"


def make_meta(name, files):
    total = sum(l for _, l in files)
    ih = hashlib.sha1(name.encode()).digest()
    return TorrentMeta(info_hash=ih, name=name, total_size=total,
                       piece_length=262144, piece_count=max(1, total // 262144),
                       files=files)


class _ServerCase(unittest.TestCase):
    admin_token = ""

    def setUp(self):
        import tempfile
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)
        self._seed()
        self.server = make_search_server(self.store, "127.0.0.1", 0,
                                         admin_token=self.admin_token)
        self.port = self.server.server_address[1]
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.store.close()
        os.unlink(self.path)

    def _seed(self):
        pass

    def _get(self, path):
        with urllib.request.urlopen(
                "http://127.0.0.1:%d%s" % (self.port, path), timeout=5) as r:
            return r.read().decode("utf-8")

    def _post(self, path, data=None, headers=None):
        body = urlencode(data).encode() if data else b""
        req = urllib.request.Request(
            "http://127.0.0.1:%d%s" % (self.port, path),
            data=body, headers=headers or {}, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=5) as r:
                return r.status, json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode("utf-8"))


class TestBrowseAndRss(_ServerCase):
    HOSTILE = "<script>alert(1)</script> & Co"

    def _seed(self):
        self.store.store_metadata(make_meta("Big Movie 2024", [("film.mkv", 4_000_000_000)]))
        self.store.store_metadata(make_meta("Cool Song", [("track.mp3", 5_000_000)]))
        self.store.store_metadata(make_meta(self.HOSTILE, [("clip.mkv", 700_000_000)]))

    def test_browse_landing_lists_categories(self):
        html = self._get("/browse")
        self.assertIn("Categories", html)
        self.assertIn("Video", html)
        self.assertIn("Audio", html)
        self.assertIn("/browse?category=video", html)

    def test_browse_escapes_hostile_name(self):
        html = self._get("/browse")
        self.assertNotIn("<script>alert(1)</script>", html)
        self.assertIn("&lt;script&gt;", html)

    def test_category_listing(self):
        html = self._get("/browse?category=audio")
        self.assertIn("Cool Song", html)
        self.assertNotIn("Big Movie 2024", html)

    def test_recent_view(self):
        html = self._get("/recent")
        self.assertIn("Big Movie 2024", html)
        self.assertNotIn("<script", html.lower())

    def test_per_query_rss(self):
        body = self._get("/rss?q=movie").encode("utf-8")
        root = ElementTree.fromstring(body)
        self.assertEqual(root.tag, "rss")
        titles = [t.text or "" for t in root.iter("title")]
        self.assertTrue(any("search: movie" in t for t in titles))
        self.assertTrue(any("Big Movie 2024" in t for t in titles))

    def test_rss_escapes_hostile_name(self):
        body = self._get("/rss?q=script").encode("utf-8")
        ElementTree.fromstring(body)   # must be well-formed despite the name
        self.assertNotIn(b"<script>alert(1)</script>", body)


class TestRssControlChars(_ServerCase):
    """M3: XML-1.0-illegal chars in a name/query must not break the feed."""

    # backspace, vtab, unit-separator, a BMP noncharacter -- all illegal in XML.
    BAD = "Movie\x08\x0b\x1f Rel\ufffe ease"

    def _seed(self):
        self.store.store_metadata(make_meta(self.BAD, [("clip.mkv", 10)]))
        self.store.store_metadata(make_meta("Clean Name", [("ok.mkv", 10)]))

    def test_feed_wellformed_despite_control_chars_in_name(self):
        body = self._get("/rss").encode("utf-8")
        root = ElementTree.fromstring(body)   # must not raise
        self.assertEqual(root.tag, "rss")
        # the sanitised title is present (control chars stripped, text kept)
        titles = " ".join(t.text or "" for t in root.iter("title"))
        self.assertIn("Movie", titles)
        self.assertNotIn("\x0b", titles)

    def test_feed_wellformed_despite_control_chars_in_query(self):
        body = self._get("/rss?" + urlencode({"q": "q\x0b\x1fx"})).encode("utf-8")
        ElementTree.fromstring(body)          # must not raise


class TestBlockDisabled(_ServerCase):
    admin_token = ""   # unset => every block request is 403

    def _seed(self):
        self.store.store_metadata(make_meta("Block Me", [("x.bin", 100)]))

    def test_block_403_without_token(self):
        code, body = self._post("/api/block",
                                {"kind": "keyword", "value": "block"})
        self.assertEqual(code, 403)
        self.assertIn("disabled", body["error"])
        # Nothing was purged.
        self.assertEqual(len(self.store.search("block")), 1)


class TestBlockEnabled(_ServerCase):
    admin_token = ADMIN_TOKEN

    def _seed(self):
        self.store.store_metadata(make_meta("Bad Keyword Thing", [("a.bin", 10)]))
        self.ih_meta = make_meta("Hash Target", [("b.bin", 20)])
        self.store.store_metadata(self.ih_meta)

    def test_bad_token_403(self):
        code, body = self._post("/api/block",
                                {"kind": "keyword", "value": "bad",
                                 "token": "wrong-token-value"})
        self.assertEqual(code, 403)
        self.assertIn("invalid", body["error"])
        self.assertEqual(len(self.store.search("bad")), 1)  # untouched

    def test_block_keyword_via_form_token(self):
        code, body = self._post("/api/block",
                                {"kind": "keyword", "value": "keyword",
                                 "token": ADMIN_TOKEN})
        self.assertEqual(code, 200)
        self.assertTrue(body["ok"])
        self.assertEqual(body["kind"], "keyword")
        self.assertGreaterEqual(body["purged"], 1)
        self.assertEqual(len(self.store.search("keyword")), 0)

    def test_block_infohash_via_header_token(self):
        ih = self.ih_meta.info_hash.hex()
        code, body = self._post(
            "/api/block", {"kind": "infohash", "value": ih},
            headers={"X-Admin-Token": ADMIN_TOKEN})
        self.assertEqual(code, 200)
        self.assertEqual(body["value"], ih)
        self.assertGreaterEqual(body["purged"], 1)
        self.assertIsNone(self.store.get_torrent(ih))

    def test_block_via_bearer_token(self):
        code, body = self._post(
            "/api/block", {"kind": "keyword", "value": "target"},
            headers={"Authorization": "Bearer " + ADMIN_TOKEN})
        self.assertEqual(code, 200)
        self.assertTrue(body["ok"])

    def test_invalid_kind_rejected(self):
        code, body = self._post("/api/block",
                                {"kind": "host", "value": "x", "token": ADMIN_TOKEN})
        self.assertEqual(code, 400)
        self.assertIn("kind must be", body["error"])

    def test_bad_infohash_rejected(self):
        code, body = self._post("/api/block",
                                {"kind": "infohash", "value": "nothex",
                                 "token": ADMIN_TOKEN})
        self.assertEqual(code, 400)


if __name__ == "__main__":
    unittest.main()
