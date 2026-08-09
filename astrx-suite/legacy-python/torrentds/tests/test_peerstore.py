"""PeerStore: IPv6/BEP-7 family split, randomized selection, durable snapshot."""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import encode as bencode
from torrentds.peerstore import PeerStore
from torrentds.routing import decode_endpoint6, encode_endpoint6, is_ipv6
from torrentds.tracker_http import build_compact_peers, build_compact_peers6

IH = bytes(range(20))


class TestIPv6Codec(unittest.TestCase):
    def test_endpoint6_round_trip(self):
        blob = encode_endpoint6("2001:db8::1", 6881)
        self.assertEqual(len(blob), 18)
        self.assertEqual(decode_endpoint6(blob), ("2001:db8::1", 6881))

    def test_is_ipv6(self):
        self.assertTrue(is_ipv6("2001:db8::1"))
        self.assertFalse(is_ipv6("10.0.0.1"))

    def test_build_compact_peers6(self):
        blob = build_compact_peers6([("2001:db8::2", 6882)])
        self.assertEqual(len(blob), 18)
        self.assertEqual(decode_endpoint6(blob), ("2001:db8::2", 6882))
        # IPv4 endpoints are skipped by the v6 codec.
        self.assertEqual(build_compact_peers6([("10.0.0.1", 1)]), b"")


class TestFamilyFilter(unittest.TestCase):
    def test_get_peers_by_family(self):
        ps = PeerStore(interval=1800)
        ps.announce(IH, "10.0.0.1", 6881, left=0)          # v4
        ps.announce(IH, "2001:db8::1", 6881, left=0)       # v6
        v4 = ps.get_peers(IH, 50, family="v4")
        v6 = ps.get_peers(IH, 50, family="v6")
        self.assertEqual(v4, [("10.0.0.1", 6881)])
        self.assertEqual(v6, [("2001:db8::1", 6881)])
        both = ps.get_peers(IH, 50)
        self.assertEqual(set(both), {("10.0.0.1", 6881), ("2001:db8::1", 6881)})


class TestRandomizedSelection(unittest.TestCase):
    def test_selection_is_random_subset(self):
        ps = PeerStore(interval=1800, max_peers_per_reply=100)
        for i in range(60):
            ps.announce(IH, "10.%d.%d.1" % (i // 256, i % 256), 6881, left=0)
        draws = [tuple(ps.get_peers(IH, 10)) for _ in range(6)]
        for d in draws:
            self.assertEqual(len(d), 10)
            self.assertEqual(len(set(d)), 10)          # distinct within a draw
        union = set().union(*[set(d) for d in draws])
        # Random selection reaches beyond a single fixed first-N slice.
        self.assertGreater(len(union), 10)

    def test_excludes_and_caps(self):
        ps = PeerStore(interval=1800, max_peers_per_reply=5)
        for i in range(20):
            ps.announce(IH, "10.0.0.%d" % i, 6881, left=0)
        got = ps.get_peers(IH, 50, exclude=("10.0.0.0", 6881))
        self.assertLessEqual(len(got), 5)              # capped
        self.assertNotIn(("10.0.0.0", 6881), got)      # excluded


class TestDurableSnapshot(unittest.TestCase):
    def test_snapshot_restore_roundtrip(self):
        ps = PeerStore(interval=1800)
        ps.announce(IH, "1.2.3.4", 6881, left=0)       # seeder
        ps.announce(IH, "5.6.7.8", 6882, left=500)     # leecher
        ps.announce(IH, "9.9.9.9", 6883, left=1, event="completed")
        blob = ps.snapshot()

        ps2 = PeerStore(interval=1800)
        restored = ps2.restore(blob)
        self.assertEqual(restored, 3)
        complete, incomplete, downloaded = ps2.counts(IH)
        self.assertEqual(complete, 1)                  # one left==0 seeder
        self.assertEqual(incomplete, 2)                # two leechers
        self.assertEqual(downloaded, 1)                # 'completed' event survived

    def test_restore_drops_expired(self):
        # Snapshot with a long TTL (so the peer is kept + serialised with a
        # large age), then restore into a store with a SHORT TTL: the age in
        # the blob exceeds it, so the stale peer is dropped on restore.
        ps = PeerStore(interval=1800, peer_ttl=100000)
        ps.announce(IH, "1.2.3.4", 6881, left=0)
        with ps._lock:
            for entry in ps.swarms[IH].peers.values():
                entry.last_seen -= 5000        # 5000s old at snapshot time
        blob = ps.snapshot()
        ps2 = PeerStore(interval=1800, peer_ttl=1000)   # 5000 > 1000 -> expired
        ps2.restore(blob)
        self.assertEqual(ps2.counts(IH), (0, 0, 0))

    def test_restore_rejects_out_of_range_port(self):
        # A crafted/corrupt snapshot with an out-of-range port must be dropped
        # on restore, not stored -- otherwise it later reaches
        # struct.pack(">H", port) in the compact codec and raises struct.error.
        blob = bencode({b"v": 1, b"swarms": [
            {b"ih": IH, b"downloaded": 0, b"peers": [
                [b"1.2.3.4", 6881, 0, 0],       # valid
                [b"5.6.7.8", 999999, 0, 0],     # illegal port -> rejected
                [b"9.9.9.9", -1, 0, 0],         # negative port -> rejected
            ]},
        ]})
        ps = PeerStore(interval=1800)
        self.assertEqual(ps.restore(blob), 1)          # only the valid peer
        peers = ps.get_peers(IH, 50)
        self.assertEqual(peers, [("1.2.3.4", 6881)])
        # Serving the swarm must not raise (pre-fix a bad port -> struct.error).
        self.assertIsInstance(build_compact_peers(peers), bytes)

    def test_compact_codec_skips_out_of_range_port(self):
        # Defense in depth: the compact codecs skip a bad port instead of
        # raising struct.error (which is NOT an OSError).
        self.assertEqual(build_compact_peers([("1.2.3.4", 999999)]), b"")
        self.assertEqual(build_compact_peers6([("2001:db8::1", 999999)]), b"")
        # A valid neighbour in the same batch is still encoded.
        self.assertEqual(len(build_compact_peers([("1.2.3.4", 999999),
                                                  ("1.2.3.4", 6881)])), 6)

    def test_file_roundtrip(self):
        import tempfile
        ps = PeerStore(interval=1800)
        ps.announce(IH, "1.2.3.4", 6881, left=0)
        fd, path = tempfile.mkstemp()
        os.close(fd)
        try:
            ps.save_to_file(path)
            ps2 = PeerStore(interval=1800)
            ps2.load_from_file(path)
            self.assertEqual(ps2.counts(IH)[0], 1)
        finally:
            os.unlink(path)
        # Missing file restores nothing, without raising.
        self.assertEqual(PeerStore().load_from_file("/nonexistent/xyz"), 0)


class TestLiveCaps(unittest.TestCase):
    """A hostile client cannot grow the swarm table without bound (unbounded
    infohashes / ports).  Both caps evict LRU rather than growing forever."""

    def test_swarm_count_bounded(self):
        ps = PeerStore(interval=1800, max_swarms=10)
        for i in range(1000):                      # 1000 distinct infohashes
            ih = i.to_bytes(20, "big")
            ps.announce(ih, "10.0.0.1", 6881, left=0)
        self.assertLessEqual(len(ps.swarms), 10)
        # The most-recently-announced swarm survived (LRU keeps the freshest).
        newest = (999).to_bytes(20, "big")
        self.assertIn(newest, ps.swarms)

    def test_peers_per_swarm_bounded(self):
        ps = PeerStore(interval=1800, max_peers_per_swarm=50,
                       max_peers_per_reply=10_000)
        # One infohash, thousands of distinct client-supplied ports.
        for port in range(2000, 2000 + 5000):
            ps.announce(IH, "10.0.0.1", port, left=0)
        self.assertLessEqual(len(ps.swarms[IH].peers), 50)
        complete, incomplete, _ = ps.counts(IH)
        self.assertLessEqual(complete + incomplete, 50)
        # Serving still works and returns only tracked (recent) peers.
        got = ps.get_peers(IH, 10_000)
        self.assertLessEqual(len(got), 50)

    def test_refresh_does_not_evict(self):
        ps = PeerStore(interval=1800, max_peers_per_swarm=3)
        for p in (1, 2, 3):
            ps.announce(IH, "10.0.0.1", p, left=0)
        # Re-announcing an existing peer refreshes it (no new slot, no eviction).
        ps.announce(IH, "10.0.0.1", 2, left=0)
        self.assertEqual(len(ps.swarms[IH].peers), 3)
        self.assertIn(("10.0.0.1", 2), ps.swarms[IH].peers)
        # A genuinely new peer evicts the oldest (port 1, then 3 after the
        # refresh moved 2 to the back).
        ps.announce(IH, "10.0.0.1", 4, left=0)
        self.assertEqual(len(ps.swarms[IH].peers), 3)
        self.assertNotIn(("10.0.0.1", 1), ps.swarms[IH].peers)
        self.assertIn(("10.0.0.1", 4), ps.swarms[IH].peers)

    def test_normal_announce_get_unaffected(self):
        ps = PeerStore(interval=1800)               # default (large) caps
        ps.announce(IH, "10.0.0.1", 6881, left=0)
        ps.announce(IH, "10.0.0.2", 6882, left=5)
        self.assertEqual(ps.counts(IH)[:2], (1, 1))  # 1 seeder, 1 leecher
        self.assertEqual(set(ps.get_peers(IH, 50)),
                         {("10.0.0.1", 6881), ("10.0.0.2", 6882)})


if __name__ == "__main__":
    unittest.main()
