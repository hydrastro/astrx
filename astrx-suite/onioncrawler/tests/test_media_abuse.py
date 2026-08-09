"""Media-hash abuse filtering (Ahmia-grade): when the crawler downloads a media
resource whose SHA-256 is on blocklist_media.txt, the page is DROPPED and its
host flagged -- a first-class TESTED code path (not indexed, not searchable)."""

import hashlib
import os
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

from onioncrawler.abuse import AbuseFilter, load_abuse_filter
from onioncrawler.config import Config
from onioncrawler.storage import Storage
from onioncrawler.crawler import Crawler
from onioncrawler.fetcher import DirectFetcher

MAIN = ("m" * 60)[:56] + ".onion"
MEDIA = b"\x89PNG\r\n\x1a\n" + b"BLOCKED-MEDIA-PAYLOAD-bytes" * 4
MEDIA_SHA = hashlib.sha256(MEDIA).hexdigest()
TOKEN = "mediahosttoken"


class TestMediaFilterUnit(unittest.TestCase):
    def test_hook(self):
        af = AbuseFilter(media_hashes=[MEDIA_SHA.upper()])  # case-insensitive
        self.assertTrue(af.has_media_blocklist)
        self.assertEqual(af.hash_media(MEDIA), MEDIA_SHA)
        self.assertTrue(af.media_blocked(MEDIA_SHA))
        self.assertEqual(af.media_bytes_blocked(MEDIA), MEDIA_SHA)
        self.assertIsNone(af.media_bytes_blocked(b"innocent bytes"))
        # no media list -> hook is inert
        self.assertFalse(AbuseFilter().has_media_blocklist)
        self.assertIsNone(AbuseFilter().media_bytes_blocked(MEDIA))

    def test_load_from_file(self):
        d = tempfile.mkdtemp()
        mp = os.path.join(d, "media.txt")
        with open(mp, "w") as fh:
            fh.write("# media hashes\n" + MEDIA_SHA + "\n")
        af = load_abuse_filter(None, None, mp)
        self.assertTrue(af.media_blocked(MEDIA_SHA))
        self.assertEqual(af.media_hashes, [MEDIA_SHA])


def _handler(state):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _send(self, body, ctype, status=200):
            self.send_response(status)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)

        def do_GET(self):
            path = urlparse(self.path).path
            state.append(path)
            if path == "/":
                body = (f"<title>root {TOKEN}</title>"
                        f'<a href="/evil.png">pic</a> body {TOKEN} here').encode()
                return self._send(body, "text/html; charset=utf-8")
            if path == "/evil.png":
                return self._send(MEDIA, "image/png")
            return self._send(b"<title>404</title>x", "text/html", status=404)

        def do_HEAD(self):
            self.do_GET()
    return H


class _CrawlBase(unittest.TestCase):
    def _crawl(self, abuse):
        reqs = []
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), _handler(reqs))
        port = httpd.server_address[1]
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        db = os.path.join(tempfile.mkdtemp(), "media.db")
        cfg = Config()
        cfg.db_path = db
        cfg.crawl_delay = cfg.crawl_delay_jitter = 0.0
        cfg.workers = 1
        cfg.obey_robots = False
        st = Storage(db)
        fetcher = DirectFetcher(hostmap={MAIN: ("127.0.0.1", port)}, timeout=5.0)
        crawler = Crawler(cfg, st, fetcher, abuse)
        try:
            crawler.run([f"http://{MAIN}/"])
            return st, reqs
        finally:
            httpd.shutdown()
            httpd.server_close()


class TestMediaCrawl(_CrawlBase):
    def test_blocklisted_media_flags_host_and_hides_page(self):
        st, reqs = self._crawl(AbuseFilter(media_hashes=[MEDIA_SHA]))
        try:
            # the media resource WAS downloaded and hashed
            self.assertIn("/evil.png", reqs)
            # host flagged 'blocked' with the media reason
            h = dict(st.get_host(MAIN))
            self.assertEqual(h["state"], "blocked")
            self.assertEqual(h["trapped_reason"], "abuse-media")
            # the page that referenced it is NOT searchable (host hidden)
            rows, total = st.search(TOKEN)
            self.assertEqual(total, 0, "page referencing blocklisted media is searchable")
            self.assertEqual(rows, [])
            # trap log recorded the media hit
            reasons = [r["reason"] for r in
                       st.db.execute("SELECT reason FROM trap_log")]
            self.assertTrue(any(x.startswith("blocked-media:") for x in reasons))
            # the media resource itself is never indexed
            self.assertFalse(any(u.endswith("/evil.png") for u in
                                 (r["url"] for r in st.db.execute("SELECT url FROM pages"))))
        finally:
            st.close()

    def test_control_without_media_blocklist_indexes_page(self):
        # Same crawl but no media blocklist -> the root page is indexed and
        # searchable (proves the media hash is what caused the drop above).
        st, reqs = self._crawl(AbuseFilter())
        try:
            self.assertIn("/evil.png", reqs)
            self.assertEqual(dict(st.get_host(MAIN))["state"], "active")
            self.assertGreaterEqual(st.search(TOKEN)[1], 1)
        finally:
            st.close()


BIG_MEDIA = b"\x89PNG\r\n\x1a\n" + (b"OVERSIZED-BLOCKED-MEDIA" * 140_000)  # ~3 MB
BIG_SHA = hashlib.sha256(BIG_MEDIA).hexdigest()
BIG_TOKEN = "oversizedmediatoken"


def _big_handler(state):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _send(self, body, ctype, status=200):
            self.send_response(status)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)

        def do_GET(self):
            path = urlparse(self.path).path
            state.append(path)
            if path == "/":
                return self._send(
                    (f"<title>root {BIG_TOKEN}</title>"
                     f'<a href="/big.png">pic</a> {BIG_TOKEN}').encode(),
                    "text/html; charset=utf-8")
            if path == "/big.png":
                return self._send(BIG_MEDIA, "image/png")  # > max_response_bytes
            return self._send(b"<title>404</title>x", "text/html", status=404)

        do_HEAD = do_GET
    return H


class TestOversizedMediaFilter(unittest.TestCase):
    """F2 regression: a blocklisted media served ABOVE max_response_bytes (so the
    text read cap aborts the fetch with too_large) must STILL block its host --
    the crawler re-fetches it up to the dedicated media cap and hashes it."""

    def test_oversized_blocklisted_media_still_blocks_host(self):
        reqs = []
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), _big_handler(reqs))
        port = httpd.server_address[1]
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        db = os.path.join(tempfile.mkdtemp(), "big.db")
        cfg = Config()
        cfg.db_path = db
        cfg.crawl_delay = cfg.crawl_delay_jitter = 0.0
        cfg.workers = 1
        cfg.obey_robots = False
        # the media is larger than the text cap but under the media cap
        self.assertGreater(len(BIG_MEDIA), cfg.max_response_bytes)
        self.assertLess(len(BIG_MEDIA), cfg.media_max_bytes)
        st = Storage(db)
        fetcher = DirectFetcher(hostmap={MAIN: ("127.0.0.1", port)}, timeout=5.0,
                                max_bytes=cfg.max_response_bytes)
        crawler = Crawler(cfg, st, fetcher, AbuseFilter(media_hashes=[BIG_SHA]))
        try:
            crawler.run([f"http://{MAIN}/"])
            # host flagged blocked via the media path
            h = dict(st.get_host(MAIN))
            self.assertEqual(h["state"], "blocked")
            self.assertEqual(h["trapped_reason"], "abuse-media")
            # the referencing page is no longer searchable
            self.assertEqual(st.search(BIG_TOKEN)[1], 0)
            # the media was re-fetched at the media cap to obtain the full bytes
            self.assertGreaterEqual(reqs.count("/big.png"), 2)
            reasons = [r["reason"] for r in
                       st.db.execute("SELECT reason FROM trap_log")]
            self.assertTrue(any(x.startswith("blocked-media:") for x in reasons))
        finally:
            httpd.shutdown()
            httpd.server_close()
            st.close()

    def test_oversized_clean_media_does_not_block_host(self):
        # A large NON-blocklisted media must not over-block the host.
        reqs = []
        httpd = ThreadingHTTPServer(("127.0.0.1", 0), _big_handler(reqs))
        port = httpd.server_address[1]
        threading.Thread(target=httpd.serve_forever, daemon=True).start()
        db = os.path.join(tempfile.mkdtemp(), "big2.db")
        cfg = Config()
        cfg.db_path = db
        cfg.crawl_delay = cfg.crawl_delay_jitter = 0.0
        cfg.workers = 1
        cfg.obey_robots = False
        st = Storage(db)
        fetcher = DirectFetcher(hostmap={MAIN: ("127.0.0.1", port)}, timeout=5.0,
                                max_bytes=cfg.max_response_bytes)
        other = hashlib.sha256(b"some other media").hexdigest()
        crawler = Crawler(cfg, st, fetcher, AbuseFilter(media_hashes=[other]))
        try:
            crawler.run([f"http://{MAIN}/"])
            self.assertEqual(dict(st.get_host(MAIN))["state"], "active")
            # the root page (text) is still indexed
            self.assertGreaterEqual(st.search(BIG_TOKEN)[1], 1)
        finally:
            httpd.shutdown()
            httpd.server_close()
            st.close()


if __name__ == "__main__":
    unittest.main()
