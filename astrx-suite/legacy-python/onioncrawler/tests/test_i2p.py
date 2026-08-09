"""I2P support (gap-closer: Tor-only -> darknet-only) + the darknet anti-leak
proof. The onion anti-leak MUST remain intact: an onion crawl never touches i2p
or clearnet, and an i2p crawl never touches onion or clearnet -- no cross-leak.
All offline (no Tor, no I2P router, no network)."""

import os
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

from onioncrawler import i2p as i2p_mod
from onioncrawler.onion import (
    is_i2p_host, is_darknet_host, is_onion_host, i2p_kind,
    require_i2p, require_onion, NotOnionError, NotDarknetError,
)
from onioncrawler.canonical import canonicalize
from onioncrawler.config import Config
from onioncrawler.storage import Storage
from onioncrawler.crawler import Crawler
from onioncrawler.fetcher import (
    TorSocksFetcher, I2PHttpFetcher, DirectFetcher, build_fetcher,
)
from onioncrawler.abuse import AbuseFilter
from onioncrawler.submit import submit_seed


def i2p_b32(label):
    body = (label * 52)[:52]
    assert len(body) == 52
    return body + ".b32.i2p"


def onion(label):
    return (label * 60)[:56] + ".onion"


I2P_MAIN = i2p_b32("m")
ONION_X = onion("x")
CLEARNET = "http://example.com/"


# --------------------------------------------------------------------------- #
class TestI2PValidator(unittest.TestCase):
    def test_accepts_b32_and_name(self):
        self.assertTrue(is_i2p_host(I2P_MAIN))
        self.assertEqual(i2p_kind(I2P_MAIN), "b32")
        self.assertTrue(is_i2p_host("stats.i2p"))
        self.assertTrue(is_i2p_host("forum.example.i2p"))
        self.assertEqual(i2p_kind("stats.i2p"), "name")
        # case + trailing dot + port are normalized
        self.assertTrue(is_i2p_host(I2P_MAIN.upper()))
        self.assertTrue(is_i2p_host("stats.i2p:80"))
        self.assertTrue(is_i2p_host("stats.i2p."))

    def test_rejects_clearnet_ish_and_onion(self):
        for h in ["example.com", "foo.i2p.evil.com", ".i2p", "i2p", "",
                  None, "127.0.0.1", "localhost", "1.2.3.4", ONION_X]:
            self.assertFalse(is_i2p_host(h), h)

    def test_b32_length_strict_only_for_b32_kind(self):
        # a wrong-length ".b32.i2p" is not a valid base32 DESTINATION, but it is
        # still a syntactically valid .i2p *name* (ends in .i2p, never clearnet).
        self.assertEqual(i2p_kind("a" * 52 + ".b32.i2p"), "b32")
        self.assertEqual(i2p_kind("a" * 51 + ".b32.i2p"), "name")
        self.assertTrue(is_i2p_host("a" * 51 + ".b32.i2p"))

    def test_darknet_gate_gated_by_allow_i2p(self):
        # onion is always darknet; i2p only when allow_i2p
        self.assertTrue(is_darknet_host(ONION_X))
        self.assertFalse(is_darknet_host(I2P_MAIN))                 # off
        self.assertTrue(is_darknet_host(I2P_MAIN, allow_i2p=True))  # on
        # clearnet is NEVER darknet, even with i2p enabled
        self.assertFalse(is_darknet_host("example.com", allow_i2p=True))
        self.assertFalse(is_darknet_host("127.0.0.1", allow_i2p=True))

    def test_require_helpers_raise(self):
        self.assertEqual(require_i2p(I2P_MAIN + ":80"), I2P_MAIN)
        with self.assertRaises(NotDarknetError):
            require_i2p(ONION_X)          # onion is not i2p
        with self.assertRaises(NotDarknetError):
            require_i2p("example.com")
        # NotDarknetError is a NotOnionError subclass (anti-leak handlers catch it)
        self.assertTrue(issubclass(NotDarknetError, NotOnionError))
        with self.assertRaises(NotOnionError):
            require_onion(I2P_MAIN)       # crown invariant: onion gate rejects i2p


class TestI2PProxyEncoders(unittest.TestCase):
    def test_http_connect_layout(self):
        req = i2p_mod.build_http_connect("site.i2p", 443)
        first = req.split(b"\r\n", 1)[0]
        self.assertEqual(first, b"CONNECT site.i2p:443 HTTP/1.1")
        self.assertIn(b"Host: site.i2p:443", req)
        self.assertTrue(req.endswith(b"\r\n\r\n"))

    def test_connect_rejects_bad_port(self):
        with self.assertRaises(i2p_mod.I2PError):
            i2p_mod.build_http_connect("site.i2p", 0)
        with self.assertRaises(i2p_mod.I2PError):
            i2p_mod.build_http_connect("site.i2p", 70000)

    def test_absolute_form_get_target(self):
        # plain http via the HTTP proxy must use the ABSOLUTE URL request-line
        self.assertEqual(
            i2p_mod.build_proxy_get_target("http", "stats.i2p", 80, "/a?b=1"),
            "http://stats.i2p/a?b=1")
        self.assertEqual(
            i2p_mod.build_proxy_get_target("http", "stats.i2p", 8080, "/"),
            "http://stats.i2p:8080/")


class TestDarknetAntiLeak(unittest.TestCase):
    """Clearnet/localhost refused on BOTH the onion and i2p paths; neither
    network's fetcher can ever open a socket to the other network."""

    def test_onion_fetcher_refuses_clearnet_and_i2p_no_socket(self):
        f = TorSocksFetcher(proxy_host="127.0.0.1", proxy_port=1)  # bogus proxy
        for url in [CLEARNET, "http://127.0.0.1/", "http://localhost/",
                    f"http://{I2P_MAIN}/"]:
            res = f.fetch(url)
            self.assertFalse(res.ok, url)
        # _open refuses i2p/clearnet before any connect (crown invariant intact)
        with self.assertRaises(NotOnionError):
            f._open(I2P_MAIN, 80, "http")
        with self.assertRaises(NotOnionError):
            f._open("example.com", 80, "http")
        self.assertFalse(f.allow_i2p)
        self.assertEqual(f.network, "onion")

    def test_i2p_fetcher_refuses_clearnet_and_onion_no_socket(self):
        f = I2PHttpFetcher(proxy_host="127.0.0.1", proxy_port=1)  # bogus proxy
        for url in [CLEARNET, "http://127.0.0.1/", "http://localhost/",
                    f"http://{ONION_X}/"]:
            res = f.fetch(url)
            self.assertFalse(res.ok, url)
        # an ONION URL is admitted by canonicalize (darknet) but the i2p gate
        # refuses it before any socket -> reported as an anti-leak refusal.
        onion_res = f.fetch(f"http://{ONION_X}/")
        self.assertIn("refused", (onion_res.error or "").lower())
        # _open refuses onion/clearnet before any connect -> no onion socket ever
        with self.assertRaises(NotOnionError):
            f._open(ONION_X, 80, "http")
        with self.assertRaises(NotOnionError):
            f._open("example.com", 80, "http")
        self.assertTrue(f.allow_i2p)
        self.assertEqual(f.network, "i2p")

    def test_i2p_refused_when_disabled(self):
        # With i2p disabled (the default), an i2p host is refused on every path.
        self.assertIsNone(canonicalize(f"http://{I2P_MAIN}/"))           # frontier
        self.assertIsNone(canonicalize(f"http://{I2P_MAIN}/", allow_i2p=False))
        self.assertIsNotNone(canonicalize(f"http://{I2P_MAIN}/", allow_i2p=True))
        # submission refuses i2p unless allow_i2p
        st = Storage(os.path.join(tempfile.mkdtemp(), "d.db"))
        try:
            self.assertEqual(
                submit_seed(st, AbuseFilter(), f"http://{I2P_MAIN}/")["status"],
                "not-onion")
            self.assertEqual(
                submit_seed(st, AbuseFilter(), f"http://{I2P_MAIN}/",
                            allow_i2p=True)["status"], "ok")
        finally:
            st.close()
        # build_fetcher refuses to build an i2p fetcher unless enabled
        cfg = Config()
        cfg.fetcher = "i2p"
        with self.assertRaises(ValueError):
            build_fetcher(cfg)
        cfg.enable_i2p = True
        self.assertIsInstance(build_fetcher(cfg), I2PHttpFetcher)

    def test_direct_fetcher_network_lock_both_ways(self):
        onion_f = DirectFetcher(network="onion")
        i2p_f = DirectFetcher(network="i2p")
        # onion transport refuses i2p; i2p transport refuses onion
        with self.assertRaises(NotOnionError):
            onion_f._open(I2P_MAIN, 80, "http")
        with self.assertRaises(NotOnionError):
            i2p_f._open(ONION_X, 80, "http")
        self.assertFalse(onion_f.host_ok(I2P_MAIN))
        self.assertTrue(onion_f.host_ok(ONION_X))
        self.assertTrue(i2p_f.host_ok(I2P_MAIN))
        self.assertFalse(i2p_f.host_ok(ONION_X))


# --------------------------------------------------------------------------- #
def _i2p_handler(state):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _host(self):
            return self.headers.get("Host", "").split(":")[0].lower()

        def _send(self, body, status=200, ctype="text/html; charset=utf-8"):
            self.send_response(status)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)

        def do_GET(self):
            path = urlparse(self.path).path
            state.append((self._host(), path))
            if path == "/":
                # links: two i2p eepsites + a clearnet + an onion (both refused)
                body = (
                    f'<a href="/eep-a">a</a> <a href="/eep-b">b</a> '
                    f'<a href="{CLEARNET}">c</a> '
                    f'<a href="http://{ONION_X}/evil">o</a> welcome'
                ).encode()
                return self._send(b"<title>i2p index</title>" + body)
            if path == "/eep-a":
                return self._send(b"<title>A</title>i2palphatoken here content")
            if path == "/eep-b":
                return self._send(b"<title>B</title>i2pbetatoken here content")
            return self._send(b"<title>404</title>nope", status=404)

        def do_HEAD(self):
            self.do_GET()
    return H


class TestI2PCrawlNoLeak(unittest.TestCase):
    """A full offline i2p crawl (DirectFetcher network='i2p') indexes eepsites
    and NEVER contacts an onion or clearnet host."""

    def setUp(self):
        self.reqs = []
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), _i2p_handler(self.reqs))
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()
        self.db = os.path.join(tempfile.mkdtemp(), "i2p.db")
        cfg = Config()
        cfg.db_path = self.db
        cfg.crawl_delay = cfg.crawl_delay_jitter = 0.0
        cfg.workers = 1
        cfg.obey_robots = False
        cfg.enable_i2p = True
        self.cfg = cfg
        self.st = Storage(self.db)
        self.fetcher = DirectFetcher(
            hostmap={I2P_MAIN: ("127.0.0.1", self.port)}, network="i2p",
            timeout=5.0)
        self.crawler = Crawler(cfg, self.st, self.fetcher, AbuseFilter())

    def tearDown(self):
        self.st.close()
        self.httpd.shutdown()
        self.httpd.server_close()

    def test_i2p_crawl_indexes_and_never_leaks(self):
        self.crawler.run([f"http://{I2P_MAIN}/"])
        hosts = {h for (h, _p) in self.reqs}
        # only the i2p host was ever contacted -- no onion, no clearnet socket
        self.assertEqual(hosts, {I2P_MAIN}, f"cross-network leak: {hosts}")
        self.assertNotIn(ONION_X, hosts)
        self.assertNotIn("example.com", hosts)
        # eepsite pages indexed + searchable
        pages = {r["url"] for r in self.st.db.execute("SELECT url FROM pages")}
        self.assertIn(f"http://{I2P_MAIN}/eep-a", pages)
        self.assertIn(f"http://{I2P_MAIN}/eep-b", pages)
        self.assertGreaterEqual(self.st.search("i2palphatoken")[1], 1)
        # the onion + clearnet links were dropped: no such host row in the DB
        db_hosts = {r["host"] for r in self.st.db.execute("SELECT host FROM hosts")}
        self.assertEqual(db_hosts, {I2P_MAIN},
                         f"off-network host entered the frontier: {db_hosts}")
        # and the onion transport gate would refuse the onion link outright
        with self.assertRaises(NotOnionError):
            self.fetcher._open(ONION_X, 80, "http")


class TestOnionCrawlNeverEnqueuesI2P(unittest.TestCase):
    """Vice-versa: an onion crawl's frontier never admits an i2p/clearnet host
    (canonicalize is .onion-only when allow_i2p is off -- the crown invariant)."""

    def test_onion_frontier_is_onion_only(self):
        # onion crawl == allow_i2p False: i2p + clearnet both canonicalize to None
        self.assertIsNone(canonicalize(f"http://{I2P_MAIN}/", allow_i2p=False))
        self.assertIsNone(canonicalize(CLEARNET, allow_i2p=False))
        self.assertIsNotNone(canonicalize(f"http://{ONION_X}/", allow_i2p=False))
        # even a DirectFetcher onion crawl refuses to open an i2p socket
        f = DirectFetcher(hostmap={}, network="onion")
        self.assertFalse(f.host_ok(I2P_MAIN))
        with self.assertRaises(NotOnionError):
            f._open(I2P_MAIN, 80, "http")


if __name__ == "__main__":
    unittest.main()
