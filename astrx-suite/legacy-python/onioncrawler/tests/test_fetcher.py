"""Anti-leak: fetchers must refuse every non-.onion host and never open a
socket to one. These run fully offline (no Tor, no network)."""

import gzip
import unittest

from onioncrawler import http_client
from onioncrawler.fetcher import TorSocksFetcher, DirectFetcher
from onioncrawler.onion import NotOnionError

GOOD = "f" * 56 + ".onion"


class _FakeSock:
    """Feeds a canned HTTP response to http_client.perform_request offline."""

    def __init__(self, data):
        self.data = data
        self.i = 0

    def sendall(self, b):
        pass

    def recv(self, n):
        chunk = self.data[self.i:self.i + n]
        self.i += len(chunk)
        return chunk

    def close(self):
        pass


def _wire(comp, encoding="gzip"):
    return (b"HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n"
            b"Content-Encoding: %s\r\nContent-Length: %d\r\n\r\n"
            % (encoding.encode(), len(comp))) + comp


class TestDecompressionBomb(unittest.TestCase):
    def test_gzip_bomb_is_capped_not_ooming(self):
        # 8 MiB of zeros compresses to a few KB -> slips under any read cap, but
        # would expand to 8 MiB if decompression were unbounded.
        max_bytes = 200_000
        comp = gzip.compress(b"\x00" * (8 * 1024 * 1024), 9)
        self.assertLess(len(comp), max_bytes, "compressed body slips under read cap")
        resp = http_client.perform_request(
            _FakeSock(_wire(comp, "gzip")), "GET", "h.onion", "/", {}, max_bytes)
        # decompressed output is hard-bounded at max_bytes, not 8 MiB
        self.assertEqual(len(resp.body), max_bytes)
        self.assertTrue(resp.truncated)

    def test_deflate_bomb_is_capped(self):
        import zlib
        max_bytes = 200_000
        comp = zlib.compress(b"\x00" * (8 * 1024 * 1024), 9)
        resp = http_client.perform_request(
            _FakeSock(_wire(comp, "deflate")), "GET", "h.onion", "/", {}, max_bytes)
        self.assertEqual(len(resp.body), max_bytes)
        self.assertTrue(resp.truncated)

    def test_normal_gzip_roundtrips(self):
        body = b"<html><title>t</title>hello onion world</html>"
        resp = http_client.perform_request(
            _FakeSock(_wire(gzip.compress(body), "gzip")), "GET", "h.onion", "/",
            {}, 2_000_000)
        self.assertEqual(resp.body, body)
        self.assertFalse(resp.truncated)

    def test_oversized_uncompressed_body_is_bounded(self):
        # An oversized *uncompressed* body must not be read unbounded: the raw
        # read budget caps it (fail-closed -> ResponseTooLarge), never OOM.
        body = b"A" * 5000
        wire = (b"HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
                b"Content-Length: 5000\r\n\r\n") + body
        with self.assertRaises(http_client.ResponseTooLarge):
            http_client.perform_request(
                _FakeSock(wire), "GET", "h.onion", "/", {}, max_bytes=1000)


class TestFetcherAntiLeak(unittest.TestCase):
    def test_tor_fetch_refuses_clearnet_without_connecting(self):
        # No Tor is running; if this tried to open a socket it would raise a
        # connection error. Instead canonicalization rejects it up front.
        f = TorSocksFetcher(proxy_host="127.0.0.1", proxy_port=1)  # bogus proxy
        for url in ["http://example.com/", "https://not-an-onion.example/",
                    "http://127.0.0.1/", "http://sub.onion.evil.com/"]:
            res = f.fetch(url)
            self.assertFalse(res.ok)
            self.assertIn("onion", (res.error or "").lower())

    def test_tor_open_raises_on_non_onion(self):
        f = TorSocksFetcher(proxy_host="127.0.0.1", proxy_port=1)
        with self.assertRaises(NotOnionError):
            f._open("example.com", 80, "http")

    def test_direct_open_raises_on_non_onion(self):
        f = DirectFetcher(hostmap={})
        with self.assertRaises(NotOnionError):
            f._open("example.com", 80, "http")

    def test_direct_fetch_unmapped_onion_fails_cleanly(self):
        f = DirectFetcher(hostmap={})
        res = f.fetch(f"http://{GOOD}/")
        self.assertFalse(res.ok)
        self.assertIsNotNone(res.error)

    def test_v2_refused_by_default(self):
        v2 = "a" * 16 + ".onion"
        f = TorSocksFetcher(proxy_host="127.0.0.1", proxy_port=1)
        res = f.fetch(f"http://{v2}/")
        self.assertFalse(res.ok)


if __name__ == "__main__":
    unittest.main()
