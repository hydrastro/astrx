"""Tracker-scrape aggregation (BEP-48 HTTP + BEP-15 UDP) over loopback."""

import os
import socket
import struct
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import encode
from torrentds.peerstore import PeerStore
from torrentds.scrape import (
    MAX_COUNT,
    ScrapeAggregator,
    Tracker,
    _host_is_public,
    parse_tracker,
    scrape_http,
    scrape_udp,
)
from torrentds.search import attach_swarm
from torrentds.tracker_http import make_http_tracker
from torrentds.tracker_udp import UDPTracker

IH = bytes(range(20))


class _HostileHandler(BaseHTTPRequestHandler):
    """A malicious scrape endpoint used to prove defensive parsing."""

    def log_message(self, *a):
        pass

    def _send(self, body):
        self.send_response(200)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        path = self.path.partition("?")[0]
        if path == "/huge":
            self._send(encode({b"files": {IH: {
                b"complete": 10 ** 18, b"incomplete": -5, b"downloaded": 3}}}))
        elif path == "/junk":
            self._send(b"not-bencode-at-all \x00\xff")
        elif path == "/big":
            self._send(b"d5:filesd" + b"x" * (3 * 1024 * 1024) + b"ee")
        else:
            self._send(b"de")


class TestScrapeLoopback(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.ps = PeerStore(interval=1800)
        cls.ps.announce(IH, "1.2.3.4", 6881, left=0, event="completed")  # seeder
        cls.ps.announce(IH, "5.6.7.8", 6882, left=500)                   # leecher
        cls.http = make_http_tracker(cls.ps, "127.0.0.1", 0)
        cls.http_port = cls.http.server_address[1]
        cls.http_thread = threading.Thread(target=cls.http.serve_forever, daemon=True)
        cls.http_thread.start()
        cls.udp = UDPTracker(cls.ps, "127.0.0.1", 0)
        cls.udp.start()

    @classmethod
    def tearDownClass(cls):
        cls.http.shutdown()
        cls.http.server_close()
        cls.udp.stop()

    def test_http_scrape(self):
        url = "http://127.0.0.1:%d/scrape" % self.http_port
        res = scrape_http(url, [IH], timeout=5.0)
        self.assertIn(IH, res)
        complete, incomplete, downloaded = res[IH]
        self.assertEqual((complete, incomplete, downloaded), (1, 1, 1))

    def test_udp_scrape(self):
        res = scrape_udp("127.0.0.1", self.udp.port, [IH], timeout=5.0)
        self.assertIn(IH, res)
        self.assertEqual(res[IH], (1, 1, 1))

    def test_aggregate_across_trackers(self):
        trackers = [Tracker("http", url="http://127.0.0.1:%d/scrape" % self.http_port),
                    Tracker("udp", host="127.0.0.1", port=self.udp.port)]
        agg = ScrapeAggregator(trackers, timeout=5.0)
        combined = agg.scrape([IH])
        h = combined[IH]
        # Same swarm mirrored on both trackers -> max, not summed.
        self.assertEqual(h["seeders"], 1)
        self.assertEqual(h["leechers"], 1)
        self.assertEqual(h["trackers"], 2)

    def test_attach_swarm_folds_external_health(self):
        agg = ScrapeAggregator(
            [Tracker("http", url="http://127.0.0.1:%d/scrape" % self.http_port)],
            timeout=5.0)
        rows = [{"infohash": IH.hex()}]
        attach_swarm(rows, None, agg)
        self.assertEqual(rows[0]["ext_seeders"], 1)
        self.assertEqual(rows[0]["ext_leechers"], 1)
        self.assertEqual(rows[0]["ext_trackers"], 1)


class TestDefensiveParsing(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.srv = ThreadingHTTPServer(("127.0.0.1", 0), _HostileHandler)
        cls.port = cls.srv.server_address[1]
        cls.thread = threading.Thread(target=cls.srv.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()
        cls.srv.server_close()

    def _url(self, path):
        return "http://127.0.0.1:%d%s" % (self.port, path)

    def test_huge_and_negative_values_clamped(self):
        res = scrape_http(self._url("/huge"), [IH], timeout=5.0)
        complete, incomplete, downloaded = res[IH]
        self.assertEqual(complete, MAX_COUNT)   # 10**18 clamped to cap
        self.assertEqual(incomplete, 0)         # negative clamped to 0
        self.assertEqual(downloaded, 3)

    def test_junk_response_yields_nothing(self):
        self.assertEqual(scrape_http(self._url("/junk"), [IH], timeout=5.0), {})

    def test_oversized_response_rejected(self):
        # A 3 MiB body exceeds the bounded read and is dropped, not buffered.
        self.assertEqual(scrape_http(self._url("/big"), [IH], timeout=5.0), {})

    def test_bad_infohash_ignored(self):
        # Non-20-byte "hashes" are never sent / requested.
        self.assertEqual(scrape_http(self._url("/huge"), [b"short"], timeout=5.0), {})


class TestScrapeSsrf(unittest.TestCase):
    """H1: a hostile tracker redirect must not reach an internal address."""

    def test_host_is_public_rejects_internal(self):
        for h in ["127.0.0.1", "10.0.0.1", "192.168.1.1", "169.254.169.254",
                  "0.0.0.0", "::1", "fc00::1", "fe80::1"]:
            self.assertFalse(_host_is_public(h), h)

    def test_host_is_public_allows_global_literals(self):
        self.assertTrue(_host_is_public("8.8.8.8"))
        self.assertTrue(_host_is_public("2001:4860:4860::8888"))

    def test_redirect_to_internal_not_followed(self):
        # An "internal" target that records whether the client ever reaches it.
        hit = {"n": 0}
        parent = self

        class Target(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                hit["n"] += 1
                body = encode({b"files": {IH: {b"complete": 9,
                                               b"incomplete": 0,
                                               b"downloaded": 0}}})
                self.send_response(200)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        target = ThreadingHTTPServer(("127.0.0.1", 0), Target)
        tport = target.server_address[1]
        threading.Thread(target=target.serve_forever, daemon=True).start()
        location = "http://127.0.0.1:%d/scrape" % tport

        class Redir(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                self.send_response(302)
                self.send_header("Location", location)
                self.send_header("Content-Length", "0")
                self.end_headers()

        redir = ThreadingHTTPServer(("127.0.0.1", 0), Redir)
        rport = redir.server_address[1]
        threading.Thread(target=redir.serve_forever, daemon=True).start()
        try:
            res = scrape_http("http://127.0.0.1:%d/scrape" % rport, [IH], timeout=3.0)
        finally:
            target.shutdown(); target.server_close()
            redir.shutdown(); redir.server_close()
        # The redirect to loopback was refused: no data, internal never reached.
        parent.assertEqual(res, {})
        parent.assertEqual(hit["n"], 0)

    def test_redirect_to_ftp_not_followed(self):
        # A raw TCP listener detects any connection attempt (ftp SSRF probe).
        conn = {"hit": False}
        raw = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        raw.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        raw.bind(("127.0.0.1", 0)); raw.listen(1)
        fport = raw.getsockname()[1]

        def accept():
            try:
                raw.settimeout(2)
                raw.accept()
                conn["hit"] = True
            except OSError:
                pass

        threading.Thread(target=accept, daemon=True).start()
        location = "ftp://127.0.0.1:%d/x" % fport

        class Redir(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                self.send_response(302)
                self.send_header("Location", location)
                self.send_header("Content-Length", "0")
                self.end_headers()

        redir = ThreadingHTTPServer(("127.0.0.1", 0), Redir)
        rport = redir.server_address[1]
        threading.Thread(target=redir.serve_forever, daemon=True).start()
        try:
            res = scrape_http("http://127.0.0.1:%d/scrape" % rport, [IH], timeout=2.0)
        finally:
            redir.shutdown(); redir.server_close()
            raw.close()
        self.assertEqual(res, {})
        self.assertFalse(conn["hit"])   # ftp handler dropped; no TCP connect


class TestScrapeUdpAntiSpoof(unittest.TestCase):
    """M2: a forged UDP reply from a different source must be rejected."""

    def test_forged_reply_from_other_source_rejected(self):
        tracker = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        tracker.bind(("127.0.0.1", 0))
        tport = tracker.getsockname()[1]
        spoofer = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        spoofer.bind(("127.0.0.1", 0))
        fake_seeders = 424242

        def server():
            try:
                data, client = tracker.recvfrom(4096)
                _pid, _act, txn = struct.unpack(">QII", data[:16])
                # Reply from the SPOOFER socket (a different source port).
                spoofer.sendto(struct.pack(">IIQ", 0, txn, 0x1122334455667788),
                               client)
                data2, client2 = tracker.recvfrom(4096)
                _conn, _act2, txn2 = struct.unpack(">QII", data2[:16])
                spoofer.sendto(struct.pack(">II", 2, txn2)
                               + struct.pack(">iii", fake_seeders, 1, 1), client2)
            except OSError:
                pass

        threading.Thread(target=server, daemon=True).start()
        try:
            res = scrape_udp("127.0.0.1", tport, [IH], timeout=2.0)
        finally:
            tracker.close(); spoofer.close()
        # connect() binds the peer, so the kernel drops the spoofed-source reply.
        self.assertEqual(res, {})


class TestSwarmFoldBounded(unittest.TestCase):
    """M4: the external health fold is bounded regardless of result count."""

    def _slow_agg(self, hits):
        class Slow(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_GET(self):
                hits["n"] += 1
                body = encode({b"files": {}})
                self.send_response(200)
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        srv = ThreadingHTTPServer(("127.0.0.1", 0), Slow)
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        agg = ScrapeAggregator(
            [Tracker("http", url="http://127.0.0.1:%d/scrape" % srv.server_address[1])],
            timeout=3.0)
        return srv, agg

    def test_fold_capped_by_max_lookups(self):
        hits = {"n": 0}
        srv, agg = self._slow_agg(hits)
        rows = [{"infohash": "%040x" % i} for i in range(100)]
        try:
            attach_swarm(rows, None, agg, max_lookups=5, time_budget=100.0)
        finally:
            srv.shutdown(); srv.server_close()
        # 100 results, but only 5 live scrapes (1 tracker request each).
        self.assertEqual(hits["n"], 5)

    def test_zero_time_budget_stops_fold(self):
        hits = {"n": 0}
        srv, agg = self._slow_agg(hits)
        rows = [{"infohash": "%040x" % i} for i in range(20)]
        try:
            attach_swarm(rows, None, agg, max_lookups=100, time_budget=0.0)
        finally:
            srv.shutdown(); srv.server_close()
        self.assertEqual(hits["n"], 0)   # deadline already passed => no folds


class TestParseTracker(unittest.TestCase):
    def test_http_announce_becomes_scrape(self):
        tr = parse_tracker("http://tr.example.org:8080/announce")
        self.assertEqual(tr.kind, "http")
        self.assertEqual(tr.url, "http://tr.example.org:8080/scrape")

    def test_udp_spec(self):
        tr = parse_tracker("udp://tr.example.org:6969")
        self.assertEqual((tr.kind, tr.host, tr.port), ("udp", "tr.example.org", 6969))

    def test_unsupported_scheme(self):
        self.assertIsNone(parse_tracker("ftp://tr.example.org/announce"))

    def test_from_specs_filters_bad(self):
        agg = ScrapeAggregator.from_specs(
            ["udp://a:1", "bogus", "http://b/announce"])
        self.assertEqual(len(agg.trackers), 2)


if __name__ == "__main__":
    unittest.main()
