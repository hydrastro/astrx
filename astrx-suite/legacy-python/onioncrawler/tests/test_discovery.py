"""Roadmap #1 - discovery: plaintext .onion in body text + robots Sitemap:."""

import os
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from onioncrawler.config import Config
from onioncrawler.storage import Storage
from onioncrawler.crawler import Crawler
from onioncrawler.fetcher import DirectFetcher
from onioncrawler.abuse import AbuseFilter
from onioncrawler.onion import find_onion_urls
from onioncrawler.sitemap import parse_sitemap
from onioncrawler.robots import parse_robots

HOST_A = "a" * 56 + ".onion"     # seed host
HOST_B = "b" * 56 + ".onion"     # discovered only via body text
CLEARNET = "http://example.com/not-an-onion"


def _html(body):
    return ("<!doctype html><html><head><title>t</title></head><body>"
            + body + "</body></html>").encode("utf-8")


class _Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *a):
        pass

    def _host(self):
        return self.headers.get("Host", "").split(":")[0].lower()

    def _send(self, body, ctype="text/html; charset=utf-8", status=200):
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_GET(self):
        host, path = self._host(), self.path
        if host == HOST_A and path == "/robots.txt":
            self._send(f"User-agent: *\nSitemap: http://{HOST_A}/sitemap.xml\n"
                       .encode(), "text/plain")
        elif host == HOST_A and path == "/sitemap.xml":
            self._send(
                (b'<?xml version="1.0"?>'
                 b'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                 b"<url><loc>http://" + HOST_A.encode() +
                 b"/from-sitemap</loc></url></urlset>"), "application/xml")
        elif host == HOST_A and path == "/":
            # bare onion for HOST_B in BODY TEXT (not an <a href>), plus a
            # clearnet URL that must be ignored, plus one normal local link.
            self._send(_html(
                f"seedalpha content. Mirror at http://{HOST_B}/welcome here. "
                f"Ignore {CLEARNET} entirely. "
                f'<a href="/local">local</a>'))
        elif host == HOST_A and path == "/local":
            self._send(_html("deltalocal page content"))
        elif host == HOST_A and path == "/from-sitemap":
            self._send(_html("gammasitemap page content"))
        elif host == HOST_B and path == "/welcome":
            self._send(_html("betadiscovered page content"))
        else:
            self._send(_html("not found"), status=404)

    def do_HEAD(self):
        self.do_GET()


class _Server:
    def __init__(self):
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), _Handler)
        self.port = self.httpd.server_address[1]
        self.t = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    def start(self):
        self.t.start()
        return self

    def stop(self):
        self.httpd.shutdown()
        self.httpd.server_close()


def _crawl(db, port):
    cfg = Config()
    cfg.db_path = db
    cfg.fetcher = "direct"
    cfg.crawl_delay = 0.0
    cfg.crawl_delay_jitter = 0.0
    cfg.workers = 1
    cfg.recrawl_ttl = 7 * 24 * 3600.0
    st = Storage(db)
    fetcher = DirectFetcher(hostmap={HOST_A: ("127.0.0.1", port),
                                     HOST_B: ("127.0.0.1", port)})
    cr = Crawler(cfg, st, fetcher, AbuseFilter())
    cr.run([f"http://{HOST_A}/"])
    return st


class TestOnionTextScanner(unittest.TestCase):
    def test_finds_v3_in_body_text(self):
        text = (f"visit http://{HOST_A}/path?x=1 and bare {HOST_B} too; "
                f"ignore example.com and short {'a'*10}.onion")
        urls = find_onion_urls(text)
        self.assertIn(f"http://{HOST_A}/path?x=1", urls)
        self.assertTrue(any(u.startswith(f"http://{HOST_B}") for u in urls))
        self.assertFalse(any("example.com" in u for u in urls))

    def test_rejects_wrong_length_and_v2_default(self):
        self.assertEqual(find_onion_urls("x" * 55 + ".onion here"), [])
        self.assertEqual(find_onion_urls("b" * 16 + ".onion here"), [])  # v2 off
        self.assertTrue(find_onion_urls("b" * 16 + ".onion", allow_v2=True))

    def test_no_partial_slice_of_longer_blob(self):
        # a 60-char base32 blob + .onion is NOT a valid host; must not match a
        # 56-char sub-slice of it.
        self.assertEqual(find_onion_urls("z" * 60 + ".onion"), [])

    def test_limit_caps_results(self):
        blob = " ".join((ch * 56) + ".onion" for ch in "cdefg")
        self.assertEqual(len(find_onion_urls(blob, limit=2)), 2)


class TestSitemapParser(unittest.TestCase):
    def test_urlset(self):
        doc = parse_sitemap(
            b'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            b"<url><loc>http://h.onion/a</loc></url>"
            b"<url><loc>http://h.onion/b</loc></url></urlset>")
        self.assertEqual(doc.kind, "urlset")
        self.assertEqual(doc.locs, ["http://h.onion/a", "http://h.onion/b"])

    def test_sitemapindex(self):
        doc = parse_sitemap(
            b"<sitemapindex><sitemap><loc>http://h.onion/s1.xml</loc></sitemap>"
            b"</sitemapindex>")
        self.assertEqual(doc.kind, "sitemapindex")
        self.assertEqual(doc.locs, ["http://h.onion/s1.xml"])

    def test_rejects_entity_bomb(self):
        # a DOCTYPE/ENTITY-bearing document is refused before parsing.
        evil = (b'<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY a "x">]>'
                b"<urlset><url><loc>http://h.onion/&a;</loc></url></urlset>")
        doc = parse_sitemap(evil)
        self.assertEqual(doc.locs, [])

    def test_robots_captures_sitemap(self):
        rules = parse_robots("User-agent: *\nDisallow: /x\n"
                             "Sitemap: http://h.onion/sitemap.xml\n")
        self.assertEqual(rules.sitemaps, ["http://h.onion/sitemap.xml"])
        # a sitemap line must not break group/rule parsing
        self.assertFalse(rules.allowed("/x/y"))
        self.assertTrue(rules.allowed("/ok"))


class TestDiscoveryIntegration(unittest.TestCase):
    def setUp(self):
        self.srv = _Server().start()
        self.db = os.path.join(tempfile.mkdtemp(), "disc.db")

    def tearDown(self):
        self.srv.stop()

    def test_body_onion_and_sitemap_enqueued_and_crawled(self):
        st = _crawl(self.db, self.srv.port)
        try:
            pages = {r["url"] for r in st.db.execute("SELECT url FROM pages")}
            frontier = {r["url"]: r["status"] for r in
                        st.db.execute("SELECT url, status FROM frontier")}
            # #1a plaintext body onion (HOST_B) discovered, enqueued, crawled
            self.assertIn(f"http://{HOST_B}/welcome", frontier,
                          "body-text .onion was not enqueued")
            self.assertIn(f"http://{HOST_B}/welcome", pages,
                          "discovered onion page was not indexed")
            # #1b Sitemap: page enqueued + crawled
            self.assertIn(f"http://{HOST_A}/from-sitemap", frontier,
                          "Sitemap URL was not enqueued")
            self.assertIn(f"http://{HOST_A}/from-sitemap", pages)
            # the clearnet URL in the body was never enqueued (onion-only)
            self.assertFalse(any("example.com" in u for u in frontier))
            # link-graph edge A->B recorded (roadmap #7 persistence)
            edge = st.db.execute(
                "SELECT cnt FROM link_edges WHERE src_host=? AND dst_host=?",
                (HOST_A, HOST_B)).fetchone()
            self.assertIsNotNone(edge, "inter-onion link edge A->B not persisted")
        finally:
            st.close()


if __name__ == "__main__":
    unittest.main()
