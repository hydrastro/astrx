"""Harvester orchestrator: DHT crawl -> discovery queue -> metadata -> store.

``Indexer`` ties one or more :class:`~torrentds.dht.DHTNode` instances to the
SQLite :class:`~torrentds.store.Store` and the ut_metadata client:

1. inbound ``get_peers`` / ``announce_peer`` infohashes are queued in the
   store (``add_discovered``);
2. a BEP-51 sampler periodically pulls ``sample_infohashes`` off routing-table
   nodes and feeds the returned infohashes into the same queue (the biggest
   harvest lever);
3. the DHT crawler keeps walking to attract more of that traffic -- optionally
   in *neighbour* mode across several node-IDs/ports for ID-space coverage;
4. a bounded **pool** of fetch workers (an ``asyncio.Semaphore``) drains the
   queue in parallel, fetching + verifying metadata over the peer wire;
5. a maintenance loop prunes the queue, enforces retention and VACUUMs;
6. routing contacts are persisted periodically so restarts resume warm.

``fetch_and_store`` is the unit under test in the loopback metadata path;
``_fetch_dispatcher`` is the concurrency ceiling that used to be a single
serial worker.
"""

from __future__ import annotations

import asyncio
from typing import List, Optional, Set, Tuple

from .dht import DHTNode
from .metadata import MetadataError, fetch_metadata
from .routing import Node, random_node_id
from .store import Store


class Indexer:
    def __init__(self, store: Store, host: str = "127.0.0.1", port: int = 6881,
                 bootstrap: Optional[List[Tuple[str, int]]] = None,
                 fetch_timeout: float = 15.0, fetch_concurrency: int = 20,
                 num_nodes: int = 1, neighbor: bool = False,
                 resolve_max_peers: int = 50, resolve_budget: float = 60.0):
        self.store = store
        self.host = host
        self.port = port
        self.bootstrap = bootstrap
        self.fetch_timeout = fetch_timeout
        self.fetch_concurrency = max(1, fetch_concurrency)
        self.num_nodes = max(1, num_nodes)
        self.neighbor = neighbor
        # Bounds on a single DHT-resolve fetch (a sampled infohash with no known
        # peer): a hostile contact can return a full datagram of blackhole peers
        # (~thousands), and trying each at fetch_timeout would pin one semaphore
        # permit for hours.  Cap both the peers attempted and the wall-clock.
        self.resolve_max_peers = max(1, resolve_max_peers)
        self.resolve_budget = resolve_budget
        self.nodes: List[DHTNode] = []
        self.node: Optional[DHTNode] = None   # primary node (back-compat)
        self._running = False
        self._tasks: List[asyncio.Task] = []
        self._fetch_tasks: Set[asyncio.Task] = set()
        self._inflight: Set[bytes] = set()
        self._sem: Optional[asyncio.Semaphore] = None
        self.stats = {"discovered": 0, "sampled": 0, "fetched": 0, "failed": 0}

    # -- infohash sink (called synchronously from the DHT datagram handler) --
    def _on_infohash(self, infohash: bytes, peer: Optional[Tuple[str, int]]) -> None:
        self.store.add_discovered(infohash, peer)
        self.stats["discovered"] += 1

    async def start(self) -> None:
        self._sem = asyncio.Semaphore(self.fetch_concurrency)
        self.nodes = []
        for i in range(self.num_nodes):
            # First node honours the requested port; the rest take ephemeral
            # ports so several distinct node-IDs cover more of the ID space.
            node_port = self.port if i == 0 else 0
            node = DHTNode(host=self.host, port=node_port, bootstrap=self.bootstrap,
                           on_infohash=self._on_infohash, neighbor=self.neighbor)
            await node.start()
            self.nodes.append(node)
        self.node = self.nodes[0]
        self.port = self.node.port
        # Warm the primary routing table from persisted contacts.
        for node_id, host, port in self.store.load_nodes(limit=500):
            try:
                self.node.routing.add_node(Node(node_id, host, port))
            except ValueError:
                continue
        self._running = True

    async def stop(self) -> None:
        self._running = False
        for t in list(self._fetch_tasks):
            t.cancel()
        for t in self._tasks:
            t.cancel()
        for t in list(self._fetch_tasks) + self._tasks:
            try:
                await t
            except (asyncio.CancelledError, Exception):
                pass
        self._fetch_tasks.clear()
        self._inflight.clear()
        self._tasks.clear()
        if self.nodes:
            self._persist_nodes()
            for node in self.nodes:
                await node.stop()

    def _persist_nodes(self) -> None:
        contacts = {}
        for node in self.nodes:
            for n in node.routing.all_nodes():
                contacts[n.id] = n
        if contacts:
            self.store.save_nodes(contacts.values())

    # -- metadata fetch -----------------------------------------------------
    async def fetch_and_store(self, infohash: bytes, host: str, port: int) -> bool:
        """Fetch, verify and persist metadata from one peer. Returns success."""
        try:
            # Overall wall-clock deadline for the *whole* fetch, not just each
            # read.  fetch_metadata bounds only individual reads, so a hostile
            # peer that trickles keep-alives (each resetting the per-read
            # timeout) could otherwise pin this coroutine -- and its fetch-pool
            # semaphore permit -- open forever, eventually stalling the pool.
            meta = await asyncio.wait_for(
                fetch_metadata(infohash, host, port, timeout=self.fetch_timeout),
                self.fetch_timeout)
        except (MetadataError, OSError, asyncio.TimeoutError):
            self.store.mark_attempt(infohash)
            self.stats["failed"] += 1
            return False
        self.store.store_metadata(meta)
        self.store.mark_fetched(infohash)
        self.stats["fetched"] += 1
        return True

    async def _fetch_one(self, infohash: bytes,
                         peer: Optional[Tuple[str, int]]) -> None:
        """Run a single fetch under the concurrency semaphore.

        Every failure mode is contained here: one malformed/hostile peer can
        raise anything, but it can only fail *this* task -- the dispatcher and
        the other in-flight fetches are untouched.
        """
        try:
            if peer and peer[0] and peer[1]:
                await self.fetch_and_store(infohash, peer[0], int(peer[1]))
            else:
                await self._resolve_and_fetch(infohash)
        except asyncio.CancelledError:
            raise
        except Exception:
            self.stats["failed"] += 1
            try:
                self.store.mark_attempt(infohash)
            except Exception:
                pass
        finally:
            self._inflight.discard(infohash)
            if self._sem is not None:
                self._sem.release()

    async def _fetch_dispatcher(self) -> None:
        """Schedule pending fetches, bounded by ``fetch_concurrency`` slots.

        Replaces the old single serial worker: many infohashes now fetch in
        parallel (the throughput ceiling), each with its own per-fetch timeout
        and exception containment.
        """
        assert self._sem is not None
        while self._running:
            pending = self.store.pending_infohashes(limit=self.fetch_concurrency * 4)
            scheduled = 0
            for infohash, peer in pending:
                if not self._running:
                    break
                if infohash in self._inflight:
                    continue
                await self._sem.acquire()            # backpressure
                if not self._running:
                    self._sem.release()
                    break
                self._inflight.add(infohash)
                task = asyncio.ensure_future(self._fetch_one(infohash, peer))
                self._fetch_tasks.add(task)
                task.add_done_callback(self._fetch_tasks.discard)
                scheduled += 1
            await asyncio.sleep(0.2 if scheduled else 1.0)

    async def _resolve_and_fetch(self, infohash: bytes) -> bool:
        """Use the DHT to locate peers for *infohash*, then fetch metadata.

        Bounded by ``resolve_max_peers`` (peers actually attempted) AND
        ``resolve_budget`` (overall wall-clock), so one hostile contact returning
        thousands of dead peers can never pin this fetch-pool slot for hours.
        """
        self.store.mark_attempt(infohash)
        if self.node is None:
            return False
        loop = asyncio.get_running_loop()
        deadline = loop.time() + self.resolve_budget
        attempted = 0
        contacts = self.node.routing.find_closest(infohash, self.node.k)
        for node in contacts:
            if attempted >= self.resolve_max_peers or loop.time() >= deadline:
                break
            try:
                peers, _nodes, _token = await self.node.get_peers(
                    infohash, (node.host, node.port))
            except (asyncio.TimeoutError, OSError):
                continue
            for host, port in peers:
                if attempted >= self.resolve_max_peers or loop.time() >= deadline:
                    break
                attempted += 1
                if await self.fetch_and_store(infohash, host, port):
                    return True
        return False

    # -- BEP-51 sampling ----------------------------------------------------
    async def _sample_once(self) -> int:
        """One sampling pass across the routing table; queues new infohashes."""
        if self.node is None:
            return 0
        queued = 0
        contacts = self.node.routing.find_closest(random_node_id(), self.node.k)
        for node in contacts:
            try:
                samples, _nodes, _num, _iv = await self.node.sample_infohashes(
                    random_node_id(), (node.host, node.port))
            except Exception:
                continue
            for ih in samples:
                self.stats["sampled"] += 1
                if self.store.add_discovered(ih, None):
                    queued += 1
        return queued

    async def _sampler(self, interval: float = 10.0) -> None:
        while self._running:
            await asyncio.sleep(interval)
            try:
                await self._sample_once()
            except asyncio.CancelledError:
                raise
            except Exception:
                pass

    # -- maintenance --------------------------------------------------------
    async def _maintenance(self, interval: float = 300.0,
                           max_torrents: Optional[int] = None,
                           max_age_seconds: Optional[float] = None) -> None:
        while self._running:
            await asyncio.sleep(interval)
            try:
                self.store.prune_discovered()
                if max_torrents is not None or max_age_seconds is not None:
                    self.store.enforce_retention(max_torrents, max_age_seconds)
                self.store.vacuum()
            except asyncio.CancelledError:
                raise
            except Exception:
                pass

    async def _node_saver(self, interval: float = 30.0) -> None:
        while self._running:
            await asyncio.sleep(interval)
            self._persist_nodes()

    async def run(self, crawl_interval: float = 1.0,
                  sample_interval: float = 10.0,
                  maintenance_interval: float = 300.0,
                  max_torrents: Optional[int] = None,
                  max_age_seconds: Optional[float] = None) -> None:
        """Start crawler(s) + fetch pool + sampler + maintenance + node saver."""
        if not self.nodes:
            await self.start()
        for node in self.nodes:
            node.start_crawler(crawl_interval)
        self._tasks = [
            asyncio.ensure_future(self._fetch_dispatcher()),
            asyncio.ensure_future(self._sampler(sample_interval)),
            asyncio.ensure_future(self._maintenance(
                maintenance_interval, max_torrents, max_age_seconds)),
            asyncio.ensure_future(self._node_saver()),
        ]
        try:
            await asyncio.gather(*self._tasks)
        except asyncio.CancelledError:
            pass
