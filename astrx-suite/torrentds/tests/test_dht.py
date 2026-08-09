"""KRPC message codec tests + a two-node loopback DHT exchange."""

import hashlib
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import decode
from torrentds.dht import SAMPLE_MAX, DHTNode, make_neighbor_id
from torrentds.routing import Node
from torrentds.krpc import (
    KRPCError,
    encode_error,
    encode_query,
    encode_response,
    parse_message,
)
from torrentds.routing import random_node_id


class TestKRPCCodec(unittest.TestCase):
    def test_query_round_trip(self):
        raw = encode_query(b"aa", "ping", {b"id": b"x" * 20})
        msg = parse_message(raw)
        self.assertEqual(msg.kind, "query")
        self.assertEqual(msg.txn, b"aa")
        self.assertEqual(msg.method, "ping")
        self.assertEqual(msg.args[b"id"], b"x" * 20)
        # Confirm exact wire bytes for a known ping query (BEP-5 example shape).
        self.assertEqual(decode(raw),
                         {b"t": b"aa", b"y": b"q", b"q": b"ping",
                          b"a": {b"id": b"x" * 20}})

    def test_response_round_trip(self):
        raw = encode_response(b"aa", {b"id": b"y" * 20})
        msg = parse_message(raw)
        self.assertEqual(msg.kind, "response")
        self.assertEqual(msg.response[b"id"], b"y" * 20)

    def test_error_round_trip(self):
        raw = encode_error(b"aa", 201, "A Generic Error Occurred")
        msg = parse_message(raw)
        self.assertEqual(msg.kind, "error")
        self.assertEqual(msg.error[0], 201)
        self.assertEqual(msg.error[1], "A Generic Error Occurred")

    def test_malformed_rejected(self):
        with self.assertRaises(Exception):
            parse_message(b"not bencode")
        with self.assertRaises(ValueError):
            parse_message(encode_response(b"t", {}).replace(b"1:y1:r", b"1:y1:z"))

    def test_neighbor_id_shares_prefix(self):
        target = random_node_id()
        me = random_node_id()
        neighbor = make_neighbor_id(target, me, shared=15)
        self.assertEqual(neighbor[:15], target[:15])
        self.assertEqual(neighbor[15:], me[15:])


class TestDHTLoopback(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.harvested = []
        self.node_a = DHTNode(host="127.0.0.1", port=0, bootstrap=[])
        self.node_b = DHTNode(
            host="127.0.0.1", port=0, bootstrap=[],
            on_infohash=lambda ih, peer: self.harvested.append((ih, peer)),
        )
        self.node_c = DHTNode(host="127.0.0.1", port=0, bootstrap=[])
        await self.node_a.start()
        await self.node_b.start()
        await self.node_c.start()
        self.addr_b = ("127.0.0.1", self.node_b.port)
        self.addr_c = ("127.0.0.1", self.node_c.port)

    async def asyncTearDown(self):
        await self.node_a.stop()
        await self.node_b.stop()
        await self.node_c.stop()

    async def test_ping_updates_both_tables(self):
        r = await self.node_a.ping(self.addr_b)
        self.assertEqual(r[b"id"], self.node_b.self_id)
        # A learned B from the response; B learned A from the query.
        self.assertIn(self.node_b.self_id, {n.id for n in self.node_a.routing.all_nodes()})
        self.assertIn(self.node_a.self_id, {n.id for n in self.node_b.routing.all_nodes()})

    async def test_find_node_returns_known_contact(self):
        # Make B aware of C (C pings B), then ask B to find C.
        await self.node_c.ping(self.addr_b)
        found = await self.node_a.find_node(self.node_c.self_id, self.addr_b)
        found_ids = {n.id for n in found}
        self.assertIn(self.node_c.self_id, found_ids)
        # A now has C in its own routing table.
        self.assertIn(self.node_c.self_id,
                      {n.id for n in self.node_a.routing.all_nodes()})

    async def test_get_peers_harvests_infohash(self):
        info_hash = random_node_id()  # 20 bytes
        peers, nodes, token = await self.node_a.get_peers(info_hash, self.addr_b)
        self.assertIsInstance(token, bytes)
        self.assertEqual(len(token), 8)
        self.assertIn((info_hash, None), self.harvested)

    async def test_announce_peer_harvests_infohash_and_peer(self):
        info_hash = random_node_id()
        _, _, token = await self.node_a.get_peers(info_hash, self.addr_b)
        r = await self.node_a.announce_peer(info_hash, 6881, token, self.addr_b)
        self.assertEqual(r[b"id"], self.node_b.self_id)
        # B recorded the announcing peer's infohash with an endpoint.
        announces = [h for h in self.harvested if h[1] is not None]
        self.assertTrue(announces)
        self.assertEqual(announces[-1][0], info_hash)

    async def test_crawl_widens_table(self):
        # Seed A with B as a known contact, and B with C.
        await self.node_a.ping(self.addr_b)
        await self.node_c.ping(self.addr_b)
        before = len(self.node_a.routing)
        await self.node_a.crawl_once(target=self.node_c.self_id)
        self.assertGreaterEqual(len(self.node_a.routing), before)
        self.assertIn(self.node_c.self_id,
                      {n.id for n in self.node_a.routing.all_nodes()})

    async def test_bad_query_returns_error(self):
        # find_node with a bad target must yield a KRPC protocol error.
        with self.assertRaises(KRPCError):
            await self.node_a.protocol.query(
                "find_node", {b"id": self.node_a.self_id, b"target": b"short"},
                self.addr_b)

    async def test_sample_infohashes_bep51(self):
        # B harvests a handful of infohashes (via inbound get_peers), then A
        # enumerates them with a BEP-51 sample_infohashes query.
        ihs = [hashlib.sha1(b"bep51-%d" % i).digest() for i in range(6)]
        for ih in ihs:
            await self.node_a.get_peers(ih, self.addr_b)   # B remembers each
        samples, nodes, num, interval = await self.node_a.sample_infohashes(
            self.node_a.self_id, self.addr_b)
        self.assertEqual(num, 6)
        self.assertGreaterEqual(len(samples), 1)
        self.assertTrue(set(samples).issubset(set(ihs)))
        self.assertEqual(interval and interval > 0, True)

    async def test_sample_infohashes_bad_target_errors(self):
        with self.assertRaises(KRPCError):
            await self.node_a.protocol.query(
                "sample_infohashes",
                {b"id": self.node_a.self_id, b"target": b"short"}, self.addr_b)

    async def test_sample_response_flood_is_capped(self):
        # Regression: a hostile node returning a huge ``samples`` blob (here
        # 10k infohashes, far more than fits a real datagram) must not flood the
        # fetch queue -- the client caps ingestion at SAMPLE_MAX per response.
        flood = bytes(range(20)) * 10000        # 10k * 20 bytes of "infohashes"

        async def fake_query(method, args, addr, timeout=None):
            return {b"id": self.node_a.self_id, b"samples": flood,
                    b"nodes": b"", b"num": 10000, b"interval": 1}

        self.node_a.protocol.query = fake_query
        samples, nodes, num, interval = await self.node_a.sample_infohashes(
            self.node_a.self_id, self.addr_b)
        self.assertEqual(len(samples), SAMPLE_MAX)     # capped, not 10000
        self.assertTrue(all(len(s) == 20 for s in samples))

    async def test_neighbor_id_attracts_traffic_via_crawl(self):
        # With neighbour mode on, A's crawl toward a target advertises an id
        # sharing the target's prefix, so B files A *near the target* -- the
        # magnetico trick that pulls that region's get_peers traffic to us.
        target = bytes([0xC0, 0xFF, 0xEE]) + os.urandom(17)
        agg = DHTNode(host="127.0.0.1", port=0, bootstrap=[],
                      neighbor=True, neighbor_shared=15)
        await agg.start()
        try:
            agg.routing.add_node(Node(self.node_b.self_id, "127.0.0.1", self.node_b.port))
            await agg.crawl_once(target=target)
            near = [n.id for n in self.node_b.routing.all_nodes()
                    if n.id[:15] == target[:15]]
            self.assertTrue(near, "neighbour id sharing target prefix not filed by peer")
        finally:
            await agg.stop()

    async def test_announce_with_bad_token_rejected(self):
        # BEP-5: announce_peer without a valid token (from a prior get_peers)
        # must be rejected -- otherwise anyone can inject peers.
        info_hash = random_node_id()
        with self.assertRaises(KRPCError):
            await self.node_a.protocol.query(
                "announce_peer",
                {b"id": self.node_a.self_id, b"info_hash": info_hash,
                 b"port": 6881, b"token": b"bogustok", b"implied_port": 0},
                self.addr_b)
        # And the harvest sink must not have recorded the forged announce.
        self.assertNotIn((info_hash, ("127.0.0.1", 6881)), self.harvested)

    async def test_transaction_ids_are_random_not_sequential(self):
        # Hardening: txns must be cryptographically-random 2-byte ids, not a
        # predictable incrementing counter an off-path attacker could guess.
        proto = self.node_a.protocol
        txns = [proto._next_txn() for _ in range(256)]
        self.assertTrue(all(isinstance(t, bytes) and len(t) == 2 for t in txns))
        # Random 2-byte draws are overwhelmingly distinct (birthday paradox
        # over a 65536 space gives <1 expected collision in 256 draws).
        self.assertGreaterEqual(len(set(txns)), 240)
        ints = [int.from_bytes(t, "big") for t in txns]
        # The old counter produced a strictly ascending, +1-per-step run;
        # random ids must be neither sorted nor uniformly unit-delta.
        self.assertNotEqual(ints, sorted(ints))
        self.assertNotEqual({b - a for a, b in zip(ints, ints[1:])}, {1})

    async def test_response_from_wrong_source_is_dropped(self):
        # Hardening: a response bearing a VALID pending txn but arriving from a
        # source other than the query's destination is an off-path injection
        # and must be dropped -- the pending query stays open for the genuine
        # reply.  The correct-source response then resolves it.
        proto = self.node_a.protocol
        dest = ("127.0.0.1", 40404)          # where the query was "sent"
        attacker = ("127.0.0.1", 55555)      # forged source, right txn
        txn = b"zz"
        fut = proto.loop.create_future()
        proto._pending[txn] = (fut, dest)
        spoofed_before = proto.stats["spoofed"]

        forged = encode_response(txn, {b"id": b"\x11" * 20, b"nodes": b""})
        proto.datagram_received(forged, attacker)
        # Dropped: future unresolved, query still pending, counters bumped.
        self.assertFalse(fut.done())
        self.assertIn(txn, proto._pending)
        self.assertEqual(proto.stats["spoofed"], spoofed_before + 1)

        genuine = encode_response(txn, {b"id": b"\x22" * 20, b"nodes": b""})
        proto.datagram_received(genuine, dest)
        # Correct source resolves the pending query and clears it.
        self.assertTrue(fut.done())
        response, addr = fut.result()
        self.assertEqual(response[b"id"], b"\x22" * 20)
        self.assertEqual(addr, dest)
        self.assertNotIn(txn, proto._pending)

    async def test_bep51_oversized_samples_blob_is_capped(self):
        # Regression: a hostile BEP-51 reply with an oversized (and misaligned)
        # ``samples`` blob must be capped at SAMPLE_MAX whole infohashes so it
        # cannot flood the fetch queue with attacker-chosen infohashes.
        flood = os.urandom(20 * 5000 + 7)     # 5000 ihs worth + ragged tail

        async def fake_query(method, args, addr, timeout=None):
            return {b"id": self.node_a.self_id, b"samples": flood,
                    b"nodes": b"", b"num": 5000, b"interval": 1}

        self.node_a.protocol.query = fake_query
        samples, _, _, _ = await self.node_a.sample_infohashes(
            self.node_a.self_id, self.addr_b)
        self.assertEqual(len(samples), SAMPLE_MAX)
        self.assertTrue(all(len(s) == 20 for s in samples))


if __name__ == "__main__":
    unittest.main()
