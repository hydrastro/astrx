"""Mainline DHT node + passive infohash harvester (BEP-5).

``DHTNode`` wires the routing table (:mod:`routing`) to the KRPC transport
(:mod:`krpc`).  It answers the four standard queries (``ping``,
``find_node``, ``get_peers``, ``announce_peer``) and, magnetico-style,
*harvests* infohashes from inbound ``get_peers``/``announce_peer`` traffic
-- the passive indexing approach -- while also actively walking the DHT
with ``find_node`` to widen its routing table and attract more traffic.

Every method is loopback-testable: two ``DHTNode`` instances on 127.0.0.1
can exchange the full query set and populate each other's routing tables
without any external network.
"""

from __future__ import annotations

import asyncio
import hashlib
import os
import random
import socket
from collections import OrderedDict
from typing import Callable, List, Optional, Tuple

from .krpc import ERR_METHOD_UNKNOWN, ERR_PROTOCOL, KRPCError, KRPCProtocol
from .routing import (
    DEFAULT_K,
    Node,
    RoutingTable,
    decode_nodes,
    decode_peers,
    encode_nodes,
    random_node_id,
)

# BEP-51 sample_infohashes: how many infohashes we return per sample response
# and the ``interval`` (seconds) we advertise before a re-sample is useful.
SAMPLE_MAX = 20
SAMPLE_INTERVAL = 21600         # 6h, the BEP-51 recommendation ceiling
RECENT_INFOHASH_CAP = 2000      # bounded ring of infohashes we can hand out

# Default Mainline bootstrap routers (used only when a network exists).
DEFAULT_BOOTSTRAP = [
    ("router.bittorrent.com", 6881),
    ("router.utorrent.com", 6881),
    ("dht.transmissionbt.com", 6881),
    ("router.bitcomet.com", 6881),
]

# Callback signature: (infohash: bytes, peer: Optional[(host, port)]).
InfohashSink = Callable[[bytes, Optional[Tuple[str, int]]], None]


def make_neighbor_id(target: bytes, self_id: bytes, shared: int = 15) -> bytes:
    """Return an ID sharing *shared* leading bytes with *target*.

    This is the "neighbours"/Sybil trick magnetico uses to place the node
    close to a target in ID space so that more ``get_peers`` traffic for
    that target is routed to us.  Off by default; exposed for operators
    who want more aggressive harvesting.
    """
    shared = max(0, min(len(self_id), shared))
    return target[:shared] + self_id[shared:]


class DHTNode:
    def __init__(
        self,
        node_id: Optional[bytes] = None,
        host: str = "127.0.0.1",
        port: int = 0,
        bootstrap: Optional[List[Tuple[str, int]]] = None,
        on_infohash: Optional[InfohashSink] = None,
        k: int = DEFAULT_K,
        neighbor: bool = False,
        neighbor_shared: int = 15,
    ):
        self.self_id = node_id or random_node_id()
        self.host = host
        self.port = port
        self.k = k
        # Sybil/"neighbours" mode: outbound crawl queries advertise an id that
        # shares a long prefix with the *target*, so remote nodes file us near
        # that target and route more of its get_peers traffic to us.  Off by
        # default (a well-behaved DHT citizen); opt-in for aggressive harvest.
        self.neighbor = neighbor
        self.neighbor_shared = neighbor_shared
        self.routing = RoutingTable(self.self_id, k)
        self.bootstrap_nodes = list(bootstrap) if bootstrap is not None else list(DEFAULT_BOOTSTRAP)
        self.on_infohash = on_infohash
        self.protocol: Optional[KRPCProtocol] = None
        self.transport: Optional[asyncio.DatagramTransport] = None
        self._token_secret = os.urandom(16)
        self._running = False
        self._crawl_task: Optional[asyncio.Task] = None
        self.harvested = 0
        # Bounded set of recently seen infohashes we can serve via BEP-51.
        self._recent: "OrderedDict[bytes, None]" = OrderedDict()

    # -- lifecycle ----------------------------------------------------------
    async def start(self) -> None:
        loop = asyncio.get_running_loop()
        self.transport, self.protocol = await loop.create_datagram_endpoint(
            lambda: KRPCProtocol(self._on_query),
            local_addr=(self.host, self.port),
        )
        sock = self.transport.get_extra_info("socket")
        self.port = sock.getsockname()[1]
        self._running = True

    async def stop(self) -> None:
        self._running = False
        if self._crawl_task is not None:
            self._crawl_task.cancel()
            try:
                await self._crawl_task
            except asyncio.CancelledError:
                pass
            self._crawl_task = None
        if self.transport is not None:
            self.transport.close()
            self.transport = None

    # -- token helpers ------------------------------------------------------
    def _make_token(self, addr: Tuple[str, int]) -> bytes:
        return hashlib.sha1(self._token_secret + addr[0].encode("latin1")).digest()[:8]

    def _valid_token(self, token: bytes, addr: Tuple[str, int]) -> bool:
        return isinstance(token, bytes) and token == self._make_token(addr)

    # -- inbound query handler (runs synchronously in datagram_received) ----
    def _on_query(self, method: str, args: dict, addr: Tuple[str, int]) -> Optional[dict]:
        qid = args.get(b"id")
        if isinstance(qid, bytes) and len(qid) == 20:
            self.routing.add_node(Node(qid, addr[0], addr[1]))

        if method == "ping":
            return {b"id": self.self_id}

        if method == "find_node":
            target = args.get(b"target")
            if not isinstance(target, bytes) or len(target) != 20:
                raise KRPCError(ERR_PROTOCOL, "bad target")
            nodes = self.routing.find_closest(target, self.k)
            return {b"id": self.self_id, b"nodes": encode_nodes(nodes)}

        if method == "get_peers":
            info_hash = args.get(b"info_hash")
            if not isinstance(info_hash, bytes) or len(info_hash) != 20:
                raise KRPCError(ERR_PROTOCOL, "bad info_hash")
            self._harvest(info_hash, None)
            nodes = self.routing.find_closest(info_hash, self.k)
            return {
                b"id": self.self_id,
                b"token": self._make_token(addr),
                b"nodes": encode_nodes(nodes),
            }

        if method == "announce_peer":
            info_hash = args.get(b"info_hash")
            if not isinstance(info_hash, bytes) or len(info_hash) != 20:
                raise KRPCError(ERR_PROTOCOL, "bad info_hash")
            # BEP-5: an announce must carry the token from a prior get_peers
            # for this address.  Without this check anyone can inject peers.
            if not self._valid_token(args.get(b"token"), addr):
                raise KRPCError(ERR_PROTOCOL, "bad token")
            if args.get(b"implied_port"):
                port = addr[1]
            else:
                port = args.get(b"port", 0)
            self._harvest(info_hash, (addr[0], int(port) if isinstance(port, int) else 0))
            return {b"id": self.self_id}

        if method == "sample_infohashes":
            # BEP-51: hand back a random sample of the infohashes we know about
            # plus the closest nodes to the requested target, so a crawler can
            # enumerate the swarm-set far faster than passive harvesting.
            target = args.get(b"target")
            if not isinstance(target, bytes) or len(target) != 20:
                raise KRPCError(ERR_PROTOCOL, "bad target")
            nodes = self.routing.find_closest(target, self.k)
            return {
                b"id": self.self_id,
                b"interval": SAMPLE_INTERVAL,
                b"nodes": encode_nodes(nodes),
                b"num": len(self._recent),
                b"samples": self._sample_blob(),
            }

        raise KRPCError(ERR_METHOD_UNKNOWN, "unknown method %s" % method)

    def _remember_infohash(self, info_hash: bytes) -> None:
        """Record an infohash in the bounded BEP-51 sample ring (LRU-evicted)."""
        if len(info_hash) != 20:
            return
        self._recent.pop(info_hash, None)
        self._recent[info_hash] = None
        while len(self._recent) > RECENT_INFOHASH_CAP:
            self._recent.popitem(last=False)

    def _sample_blob(self, count: int = SAMPLE_MAX) -> bytes:
        if not self._recent:
            return b""
        keys = list(self._recent.keys())
        if len(keys) > count:
            keys = random.sample(keys, count)
        return b"".join(keys)

    def _harvest(self, info_hash: bytes, peer: Optional[Tuple[str, int]]) -> None:
        self.harvested += 1
        self._remember_infohash(info_hash)
        if self.on_infohash is not None:
            try:
                self.on_infohash(info_hash, peer)
            except Exception:
                pass

    def _absorb(self, response: dict, addr: Tuple[str, int]) -> None:
        rid = response.get(b"id")
        if isinstance(rid, bytes) and len(rid) == 20:
            self.routing.add_node(Node(rid, addr[0], addr[1]))
        raw = response.get(b"nodes")
        if isinstance(raw, bytes):
            for n in decode_nodes(raw):
                self.routing.add_node(n)

    # -- outbound client queries -------------------------------------------
    async def ping(self, addr: Tuple[str, int]) -> dict:
        r = await self.protocol.query("ping", {b"id": self.self_id}, addr)
        self._absorb(r, addr)
        return r

    def _source_id(self, target: Optional[bytes]) -> bytes:
        """The id we advertise in a query -- a target-neighbour when enabled."""
        if self.neighbor and isinstance(target, bytes) and len(target) == 20:
            return make_neighbor_id(target, self.self_id, self.neighbor_shared)
        return self.self_id

    async def find_node(self, target: bytes, addr: Tuple[str, int],
                        source_id: Optional[bytes] = None) -> List[Node]:
        r = await self.protocol.query(
            "find_node",
            {b"id": source_id if source_id is not None else self.self_id,
             b"target": target},
            addr,
        )
        self._absorb(r, addr)
        raw = r.get(b"nodes", b"")
        return decode_nodes(raw if isinstance(raw, bytes) else b"")

    async def sample_infohashes(self, target: bytes, addr: Tuple[str, int]):
        """BEP-51 client: returns ``(samples, nodes, num, interval)``."""
        r = await self.protocol.query(
            "sample_infohashes", {b"id": self.self_id, b"target": target}, addr)
        self._absorb(r, addr)
        blob = r.get(b"samples", b"")
        samples: List[bytes] = []
        if isinstance(blob, bytes):
            # Cap ingestion at SAMPLE_MAX per response: a UDP datagram can carry
            # ~3200 infohashes, and without a bound one hostile node could flood
            # the fetch queue with thousands of attacker-chosen infohashes.
            usable = min(len(blob) - (len(blob) % 20), SAMPLE_MAX * 20)
            for off in range(0, usable, 20):
                samples.append(blob[off:off + 20])
        raw = r.get(b"nodes")
        nodes = decode_nodes(raw) if isinstance(raw, bytes) else []
        return samples, nodes, r.get(b"num"), r.get(b"interval")

    async def get_peers(self, info_hash: bytes, addr: Tuple[str, int]):
        r = await self.protocol.query(
            "get_peers", {b"id": self.self_id, b"info_hash": info_hash}, addr
        )
        self._absorb(r, addr)
        values = r.get(b"values")
        peers = decode_peers(values) if isinstance(values, list) else []
        raw = r.get(b"nodes")
        nodes = decode_nodes(raw) if isinstance(raw, bytes) else []
        return peers, nodes, r.get(b"token")

    async def announce_peer(self, info_hash: bytes, port: int, token: bytes,
                            addr: Tuple[str, int], implied_port: int = 0) -> dict:
        args = {
            b"id": self.self_id,
            b"info_hash": info_hash,
            b"port": port,
            b"token": token,
            b"implied_port": implied_port,
        }
        r = await self.protocol.query("announce_peer", args, addr)
        self._absorb(r, addr)
        return r

    # -- crawling -----------------------------------------------------------
    async def bootstrap_once(self) -> None:
        loop = asyncio.get_running_loop()
        for host, port in self.bootstrap_nodes:
            try:
                # Resolve the (usually hostname) bootstrap router to a numeric IP
                # BEFORE querying. The KRPC layer now drops any response whose
                # source address != the query's stored destination (anti off-path
                # injection, krpc._match). A hostname destination can never equal
                # the reply's numeric source, so querying an unresolved hostname
                # would get every bootstrap reply discarded as spoofed and the
                # node would never learn a peer. Resolving up-front makes
                # dest == reply-source; it also lifts the blocking getaddrinfo out
                # of the transport's sendto path. Learned contacts are already
                # numeric, so no other query path needs this.
                infos = await loop.getaddrinfo(host, port, type=socket.SOCK_DGRAM)
                ip = infos[0][4][0]
                await self.find_node(self.self_id, (ip, port))
            except (asyncio.TimeoutError, OSError, KRPCError, IndexError):
                continue

    async def crawl_once(self, target: Optional[bytes] = None) -> int:
        """One widening step: find_node toward *target* on closest contacts."""
        target = target or random_node_id()
        contacts = self.routing.find_closest(target, self.k)
        if not contacts:
            await self.bootstrap_once()
            contacts = self.routing.find_closest(target, self.k)
        source_id = self._source_id(target)   # neighbour-of-target when enabled
        found = 0
        for node in contacts:
            try:
                found += len(await self.find_node(
                    target, (node.host, node.port), source_id=source_id))
            except (asyncio.TimeoutError, OSError, KRPCError):
                self.routing.remove_node(node.id)
        return found

    async def run_crawler(self, interval: float = 1.0) -> None:
        """Background loop that keeps walking the DHT to attract traffic."""
        await self.bootstrap_once()
        while self._running:
            try:
                await self.crawl_once()
            except Exception:
                pass
            await asyncio.sleep(interval + random.random() * interval)

    def start_crawler(self, interval: float = 1.0) -> None:
        if self._crawl_task is None:
            self._crawl_task = asyncio.ensure_future(self.run_crawler(interval))
