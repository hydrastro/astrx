"""HTTP tracker announce + scrape over loopback."""

import os
import sys
import threading
import unittest
from urllib.parse import quote
from urllib.request import urlopen

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import decode
from torrentds.peerstore import PeerStore
from torrentds.routing import decode_endpoint, decode_endpoint6
from torrentds.tracker_http import make_http_tracker


def compact_to_set(blob: bytes):
    return {decode_endpoint(blob[i:i + 6]) for i in range(0, len(blob), 6)}


def compact6_to_set(blob: bytes):
    return {decode_endpoint6(blob[i:i + 18]) for i in range(0, len(blob), 18)}


class TestHTTPTracker(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.store = PeerStore(interval=1800)
        cls.server = make_http_tracker(cls.store, "127.0.0.1", 0)
        cls.port = cls.server.server_address[1]
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()

    def _get(self, path: str) -> bytes:
        with urlopen("http://127.0.0.1:%d%s" % (self.port, path), timeout=5) as r:
            return r.read()

    def _announce(self, info_hash, peer_id, port, left, event=None, compact=1):
        q = ("/announce?info_hash=%s&peer_id=%s&port=%d&uploaded=0&downloaded=0"
             "&left=%d&compact=%d" % (quote(info_hash), quote(peer_id), port,
                                      left, compact))
        if event:
            q += "&event=" + event
        return decode(self._get(q))

    def test_announce_then_second_peer_sees_first(self):
        info_hash = bytes(range(20))
        # Peer A (leecher) announces first; it should see no other peers.
        r1 = self._announce(info_hash, b"A" * 20, 6881, left=100, event="started")
        self.assertEqual(r1[b"complete"], 0)
        self.assertEqual(r1[b"incomplete"], 1)
        self.assertEqual(r1[b"peers"], b"")  # only itself, excluded
        self.assertEqual(r1[b"interval"], 1800)

        # Peer B (seeder) announces; it should now see peer A in a compact list.
        r2 = self._announce(info_hash, b"B" * 20, 6882, left=0, event="completed")
        self.assertEqual(r2[b"complete"], 1)     # B is a seeder
        self.assertEqual(r2[b"incomplete"], 1)   # A is a leecher
        peers = compact_to_set(r2[b"peers"])
        self.assertIn(("127.0.0.1", 6881), peers)
        self.assertNotIn(("127.0.0.1", 6882), peers)  # B excluded from its own reply

    def test_scrape(self):
        info_hash = bytes(range(100, 120))
        self._announce(info_hash, b"S" * 20, 7001, left=0, event="completed")
        self._announce(info_hash, b"L" * 20, 7002, left=500)
        body = decode(self._get("/scrape?info_hash=" + quote(info_hash)))
        stats = body[b"files"][info_hash]
        self.assertEqual(stats[b"complete"], 1)
        self.assertEqual(stats[b"incomplete"], 1)
        self.assertEqual(stats[b"downloaded"], 1)  # one 'completed' event

    def test_non_compact_peers(self):
        info_hash = bytes(range(50, 70))
        self._announce(info_hash, b"X" * 20, 8001, left=0)
        r = self._announce(info_hash, b"Y" * 20, 8002, left=10, compact=0)
        self.assertIsInstance(r[b"peers"], list)
        self.assertEqual(r[b"peers"][0][b"port"], 8001)

    def test_invalid_infohash(self):
        r = decode(self._get("/announce?info_hash=tooshort&port=6881&left=0"))
        self.assertIn(b"failure reason", r)

    def test_ipv6_peers_returned_in_peers6(self):
        # BEP-7: an IPv6 peer in the swarm must come back in a separate
        # ``peers6`` key (18-byte entries), not the IPv4 ``peers`` list.  The
        # v6 peer is injected directly (loopback v6 sockets are unavailable in
        # the sandbox), then a v4 client announces and reads the swarm back.
        info_hash = bytes(range(140, 160))
        self.store.announce(info_hash, "2001:db8::5", 6881, left=0)   # v6 seeder
        r = self._announce(info_hash, b"V" * 20, 6900, left=10)       # v4 leecher
        self.assertIn(b"peers6", r)
        self.assertIn(("2001:db8::5", 6881), compact6_to_set(r[b"peers6"]))
        # The v4 compact ``peers`` list must not contain the v6 peer.
        self.assertEqual(compact_to_set(r[b"peers"]), set())

    def test_stopped_removes_peer(self):
        info_hash = bytes(range(200, 220))
        self._announce(info_hash, b"Z" * 20, 9001, left=0)
        self._announce(info_hash, b"Z" * 20, 9001, left=0, event="stopped")
        body = decode(self._get("/scrape?info_hash=" + quote(info_hash)))
        stats = body[b"files"][info_hash]
        self.assertEqual(stats[b"complete"], 0)
        self.assertEqual(stats[b"incomplete"], 0)


if __name__ == "__main__":
    unittest.main()
