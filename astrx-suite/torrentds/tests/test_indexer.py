"""End-to-end harvester path over loopback: DHT harvest + metadata fetch."""

import asyncio
import hashlib
import os
import sys
import tempfile
import time
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds import metadata as M
from torrentds.bencode import encode
from torrentds.dht import DHTNode
from torrentds.indexer import Indexer
from torrentds.metadata import serve_metadata
from torrentds.routing import Node
from torrentds.store import Store


async def _trickle_keepalive_peer(reader, writer):
    """A hostile peer: completes the handshake, advertises metadata, then
    trickles keep-alives forever without ever sending a metadata piece.  Each
    keep-alive resets the client's per-read timeout, so only an *overall*
    fetch deadline can free the client's coroutine (and its pool slot)."""
    try:
        hs = await reader.readexactly(M.HANDSHAKE_LEN)
        _res, ih, _pid = M.parse_handshake(hs)
        writer.write(M.build_handshake(ih, M._random_peer_id(), extensions=True))
        await writer.drain()
        while True:
            mid, payload = await M.read_message(reader)
            if mid == M.EXT_MSG_ID and payload and payload[0] == 0:
                break
        writer.write(M.build_ext_handshake(metadata_size=32768, ut_metadata_id=2))
        await writer.drain()
        while True:
            writer.write((0).to_bytes(4, "big"))   # length-0 = keep-alive
            await writer.drain()
            await asyncio.sleep(0.2)
    except (asyncio.IncompleteReadError, ConnectionError, OSError, M.MetadataError):
        pass
    finally:
        try:
            writer.close()
        except Exception:
            pass


def make_info(name, n_hash_pieces=1500):
    return {
        b"name": name.encode(),
        b"piece length": 262144,
        b"pieces": os.urandom(20 * n_hash_pieces),
        b"length": 700 * 1024 * 1024,
    }


class TestIndexerLoopback(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    async def test_harvest_queues_infohash(self):
        # An indexer's DHT node harvests infohashes from inbound get_peers.
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[])
        await indexer.start()
        sender = DHTNode(host="127.0.0.1", port=0, bootstrap=[])
        await sender.start()
        try:
            info_hash = hashlib.sha1(b"harvest-me").digest()
            await sender.get_peers(info_hash, ("127.0.0.1", indexer.port))
            pending = self.store.pending_infohashes()
            self.assertIn(info_hash, {ih for ih, _ in pending})
            self.assertEqual(self.store.stats()["discovered"], 1)
        finally:
            await sender.stop()
            await indexer.stop()

    async def test_fetch_and_store_from_peer(self):
        # The metadata fetch path stores a verified, searchable torrent.
        info = make_info("Indexed Loopback Release")
        metadata = encode(info)
        info_hash = hashlib.sha1(metadata).digest()
        self.store.add_discovered(info_hash, peer=None)

        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[])
        await indexer.start()
        server, host, port = await serve_metadata(metadata)
        try:
            ok = await indexer.fetch_and_store(info_hash, host, port)
            self.assertTrue(ok)
            t = self.store.get_torrent(info_hash.hex())
            self.assertIsNotNone(t)
            self.assertEqual(t["name"], "Indexed Loopback Release")
            self.assertEqual(len(self.store.search("indexed loopback")), 1)
            # The verified raw info-dict is persisted for .torrent rebuilds.
            self.assertEqual(self.store.get_info_bytes(info_hash.hex()), metadata)
            self.assertTrue(t["has_torrent"])
        finally:
            server.close()
            await server.wait_closed()
            await indexer.stop()

    async def test_concurrent_fetch_pool_runs_in_parallel(self):
        # The fetch pool must run many infohashes concurrently (bounded by the
        # semaphore), not one-at-a-time like the old serial worker.
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[],
                          fetch_concurrency=5)
        await indexer.start()
        try:
            for i in range(12):
                self.store.add_discovered(hashlib.sha1(b"cc-%d" % i).digest(),
                                          peer=("127.0.0.1", 1000 + i))
            current = 0
            max_seen = 0
            lock = asyncio.Lock()

            async def fake_fetch(infohash, host, port):
                nonlocal current, max_seen
                async with lock:
                    current += 1
                    max_seen = max(max_seen, current)
                await asyncio.sleep(0.1)
                async with lock:
                    current -= 1
                self.store.mark_fetched(infohash)
                indexer.stats["fetched"] += 1
                return True

            indexer.fetch_and_store = fake_fetch
            indexer._running = True
            disp = asyncio.ensure_future(indexer._fetch_dispatcher())
            await asyncio.sleep(0.4)
            indexer._running = False
            await asyncio.wait_for(disp, timeout=5)
            # Everything got fetched, concurrency exceeded 1, and stayed capped.
            self.assertEqual(indexer.stats["fetched"], 12)
            self.assertGreater(max_seen, 1)
            self.assertLessEqual(max_seen, 5)
        finally:
            await indexer.stop()

    async def test_dispatcher_survives_one_raising_fetch(self):
        # Containment at the pool level: a fetch that raises must fail only its
        # own task, never halt the dispatcher or the other fetches.
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[],
                          fetch_concurrency=4)
        await indexer.start()
        try:
            bad = hashlib.sha1(b"bad").digest()
            good = hashlib.sha1(b"good").digest()
            self.store.add_discovered(bad, peer=("127.0.0.1", 2001))
            self.store.add_discovered(good, peer=("127.0.0.1", 2002))
            done = set()

            async def flaky(infohash, host, port):
                if infohash == bad:
                    raise RuntimeError("hostile peer")
                done.add(infohash)
                self.store.mark_fetched(infohash)
                return True

            indexer.fetch_and_store = flaky
            indexer._running = True
            disp = asyncio.ensure_future(indexer._fetch_dispatcher())
            await asyncio.sleep(0.4)
            indexer._running = False
            await asyncio.wait_for(disp, timeout=5)
            self.assertIn(good, done)               # good one completed
            self.assertGreaterEqual(indexer.stats["failed"], 1)  # bad one contained
        finally:
            await indexer.stop()

    async def test_sampler_feeds_queue(self):
        # BEP-51 sampler drains infohashes off a routing-table node into the
        # discovery queue.
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[])
        await indexer.start()
        peer = DHTNode(host="127.0.0.1", port=0, bootstrap=[])
        await peer.start()
        try:
            ihs = [hashlib.sha1(b"samp-%d" % i).digest() for i in range(5)]
            for ih in ihs:
                peer._remember_infohash(ih)
            indexer.node.routing.add_node(
                Node(peer.self_id, "127.0.0.1", peer.port))
            queued = await indexer._sample_once()
            self.assertGreaterEqual(queued, 1)
            self.assertGreater(indexer.stats["sampled"], 0)
            pending = {ih for ih, _ in self.store.pending_infohashes()}
            self.assertTrue(set(ihs) & pending)
        finally:
            await peer.stop()
            await indexer.stop()

    async def test_multiple_dht_nodes_distinct_ids_and_ports(self):
        # ID-space coverage: several node-IDs/ports in one process, all
        # harvesting into the same store.
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[],
                          num_nodes=3)
        await indexer.start()
        sender = DHTNode(host="127.0.0.1", port=0, bootstrap=[])
        await sender.start()
        try:
            self.assertEqual(len(indexer.nodes), 3)
            self.assertEqual(len({n.self_id for n in indexer.nodes}), 3)
            self.assertEqual(len({n.port for n in indexer.nodes}), 3)
            # An infohash sent to the THIRD node still lands in the shared store.
            ih = hashlib.sha1(b"multi-node").digest()
            await sender.get_peers(ih, ("127.0.0.1", indexer.nodes[2].port))
            self.assertIn(ih, {x for x, _ in self.store.pending_infohashes()})
        finally:
            await sender.stop()
            await indexer.stop()

    async def test_trickle_peer_does_not_stall_fetch_slot(self):
        # Regression: a keep-alive-trickle peer must NOT pin the fetch coroutine
        # (and its semaphore permit) open forever.  The overall wall-clock
        # deadline frees it near fetch_timeout, returning False -- so the pool
        # keeps making progress instead of stalling on hostile peers.
        server = await asyncio.start_server(_trickle_keepalive_peer, "127.0.0.1", 0)
        host, port = server.sockets[0].getsockname()[:2]
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[],
                          fetch_timeout=1.0)
        await indexer.start()
        info_hash = hashlib.sha1(b"trickle").digest()
        try:
            start = time.monotonic()
            ok = await indexer.fetch_and_store(info_hash, host, port)
            elapsed = time.monotonic() - start
            self.assertFalse(ok)                      # gave up, did not hang
            self.assertLess(elapsed, 5.0)             # bounded ~fetch_timeout
            self.assertGreaterEqual(elapsed, 0.9)     # overall deadline fired
            self.assertEqual(indexer.stats["failed"], 1)
        finally:
            server.close()
            await server.wait_closed()
            await indexer.stop()

    async def test_junk_but_hashmatching_metadata_does_not_kill_worker(self):
        # A malicious peer generates its own torrent, so it can serve bytes
        # that are NOT valid bencode yet still hash to the announced infohash.
        # fetch_and_store must return False (not raise), so the fetch worker
        # survives.  Regression for the "one bad peer halts all indexing" bug.
        junk = b"not-bencode-metadata-but-hash-matches"
        info_hash = hashlib.sha1(junk).digest()
        self.store.add_discovered(info_hash, peer=None)

        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[])
        await indexer.start()
        server, host, port = await serve_metadata(junk)  # serves junk as metadata
        try:
            ok = await indexer.fetch_and_store(info_hash, host, port)
            self.assertFalse(ok)                       # handled, not raised
            self.assertEqual(indexer.stats["failed"], 1)
            self.assertIsNone(self.store.get_torrent(info_hash.hex()))
        finally:
            server.close()
            await server.wait_closed()
            await indexer.stop()

    async def test_resolve_and_fetch_caps_peers_attempted(self):
        # A hostile DHT contact returns thousands of (blackhole) peers for a
        # sampled infohash. _resolve_and_fetch must attempt at most
        # resolve_max_peers of them, not the whole list.
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[],
                          resolve_max_peers=5, resolve_budget=60.0)
        peers = [("10.0.%d.%d" % (i // 256, i % 256), 7000 + i)
                 for i in range(3000)]
        indexer.node = _FakeResolveNode(peers)
        attempts = 0

        async def dead_fetch(infohash, host, port):
            nonlocal attempts
            attempts += 1
            return False                                   # every peer is dead

        indexer.fetch_and_store = dead_fetch
        result = await indexer._resolve_and_fetch(hashlib.sha1(b"cap").digest())
        self.assertFalse(result)
        self.assertLessEqual(attempts, 5)                  # peer cap held

    async def test_resolve_and_fetch_respects_wallclock_budget(self):
        # With a huge peer cap but a small time budget, a resolve of slow/dead
        # peers must return near the budget, not try them all for hours.
        # Peer cap set high so the *time* budget is the binding constraint; the
        # list is far larger than the ~10 attempts the budget allows.
        indexer = Indexer(self.store, host="127.0.0.1", port=0, bootstrap=[],
                          resolve_max_peers=10_000, resolve_budget=0.2)
        peers = [("10.0.%d.%d" % (i // 256, i % 256), 7000 + i)
                 for i in range(10_000)]
        indexer.node = _FakeResolveNode(peers)
        attempts = 0

        async def slow_dead_fetch(infohash, host, port):
            nonlocal attempts
            attempts += 1
            await asyncio.sleep(0.02)                      # 20 ms per dead peer
            return False

        indexer.fetch_and_store = slow_dead_fetch
        start = time.monotonic()
        result = await asyncio.wait_for(
            indexer._resolve_and_fetch(hashlib.sha1(b"budget").digest()),
            timeout=5)
        elapsed = time.monotonic() - start
        self.assertFalse(result)
        self.assertLess(elapsed, 2.0)                      # bounded by the budget
        self.assertLess(attempts, 100)                     # ~10, nowhere near 10k


class _FakeRouting:
    def __init__(self, contacts):
        self._contacts = contacts

    def find_closest(self, target, k):
        return self._contacts[:k]


class _FakeResolveNode:
    """Minimal stand-in for a DHTNode whose single contact returns *peers*."""

    def __init__(self, peers):
        self.k = 8
        self.routing = _FakeRouting([Node(os.urandom(20), "127.0.0.1", 6881)])
        self._peers = peers

    async def get_peers(self, infohash, addr):
        return list(self._peers), [], None


if __name__ == "__main__":
    unittest.main()
