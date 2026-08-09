"""In-memory swarm peer store shared by the HTTP and UDP trackers.

Peers are keyed by ``(ip, port)`` within a per-infohash swarm.  A peer that
reports ``left == 0`` is a seeder (``complete``), otherwise a leecher
(``incomplete``).  Entries expire after ``peer_ttl`` seconds of silence and
are reaped lazily on every announce/scrape.  An optional allowlist/denylist
(by hex infohash) gates which swarms the tracker will serve.
"""

from __future__ import annotations

import random
import threading
import time
from collections import OrderedDict
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Set, Tuple

from .bencode import BencodeError, decode, encode

Endpoint = Tuple[str, int]

STOPPED = "stopped"
COMPLETED = "completed"
STARTED = "started"

# Hard caps on LIVE storage.  A hostile client can announce unlimited distinct
# infohashes (and, per infohash, unlimited (ip, port) pairs since the port is
# client-supplied), so BOTH the swarm count and the peers-per-swarm are bounded
# with LRU eviction -- otherwise the in-memory swarm table grows without bound
# within one peer_ttl window.  The same caps also bound a restored snapshot, and
# an out-of-range restored port is rejected before it can raise ``struct.error``
# in the compact peer codec.
MAX_SWARMS = 1_000_000
MAX_PEERS_PER_SWARM = 10_000
# Back-compat aliases (older name for the restore-time bounds).
MAX_RESTORE_SWARMS = MAX_SWARMS
MAX_RESTORE_PEERS_PER_SWARM = MAX_PEERS_PER_SWARM


def _is_ipv6(ip: str) -> bool:
    return ":" in ip


@dataclass
class PeerEntry:
    left: int
    last_seen: float


@dataclass
class Swarm:
    # OrderedDict so a full swarm can evict its least-recently-active peer in
    # O(1) (front = oldest; a refreshed peer is moved to the back).
    peers: "OrderedDict[Endpoint, PeerEntry]" = field(default_factory=OrderedDict)
    downloaded: int = 0  # count of 'completed' events seen


class PeerStore:
    def __init__(self, interval: int = 1800, peer_ttl: Optional[int] = None,
                 max_peers_per_reply: int = 50,
                 max_swarms: int = MAX_SWARMS,
                 max_peers_per_swarm: int = MAX_PEERS_PER_SWARM):
        self.interval = interval
        self.peer_ttl = peer_ttl if peer_ttl is not None else interval * 2
        self.max_peers_per_reply = max_peers_per_reply
        self.max_swarms = max(1, max_swarms)
        self.max_peers_per_swarm = max(1, max_peers_per_swarm)
        # OrderedDict of swarms so an over-cap store evicts the least-recently-
        # active swarm (front) in O(1); a touched swarm is moved to the back.
        self.swarms: "OrderedDict[bytes, Swarm]" = OrderedDict()
        self.allow: Optional[Set[str]] = None  # hex infohashes, or None = all
        self.deny: Set[str] = set()
        self._lock = threading.Lock()

    # -- policy -------------------------------------------------------------
    def set_allowlist(self, hexes: Optional[List[str]]) -> None:
        self.allow = {h.lower() for h in hexes} if hexes is not None else None

    def set_denylist(self, hexes: List[str]) -> None:
        self.deny = {h.lower() for h in hexes}

    def is_allowed(self, infohash: bytes) -> bool:
        h = infohash.hex()
        if h in self.deny:
            return False
        if self.allow is not None and h not in self.allow:
            return False
        return True

    # -- reaping ------------------------------------------------------------
    def _reap(self, now: Optional[float] = None) -> None:
        now = now or time.time()
        cutoff = now - self.peer_ttl
        empty = []
        for ih, sw in self.swarms.items():
            stale = [k for k, p in sw.peers.items() if p.last_seen < cutoff]
            for k in stale:
                del sw.peers[k]
            if not sw.peers and sw.downloaded == 0:
                empty.append(ih)
        for ih in empty:
            del self.swarms[ih]

    def reap(self) -> None:
        with self._lock:
            self._reap()

    # -- announce -----------------------------------------------------------
    def announce(self, infohash: bytes, ip: str, port: int, left: int,
                 event: Optional[str] = None) -> bool:
        """Record/refresh a peer.  Returns False if the infohash is denied."""
        if len(infohash) != 20 or not self.is_allowed(infohash):
            return False
        now = time.time()
        key = (ip, int(port))
        with self._lock:
            self._reap(now)
            sw = self.swarms.get(infohash)
            if sw is None:
                # Cap the number of tracked swarms: evict the least-recently-
                # active one (front of the OrderedDict) so a flood of distinct
                # infohashes cannot grow the table without bound.
                while len(self.swarms) >= self.max_swarms:
                    self.swarms.popitem(last=False)
                sw = self.swarms[infohash] = Swarm()
            else:
                self.swarms.move_to_end(infohash)   # most-recently active
            if event == STOPPED:
                sw.peers.pop(key, None)
                return True
            if event == COMPLETED:
                sw.downloaded += 1
            # Cap peers per swarm: refresh in place (move to back) or, for a new
            # peer, evict the oldest (front) when the swarm is full. Bounds the
            # per-swarm dict a hostile client can grow by cycling ports.
            if key in sw.peers:
                sw.peers[key] = PeerEntry(int(left), now)
                sw.peers.move_to_end(key)
            else:
                while len(sw.peers) >= self.max_peers_per_swarm:
                    sw.peers.popitem(last=False)
                sw.peers[key] = PeerEntry(int(left), now)
        return True

    # -- queries ------------------------------------------------------------
    def get_peers(self, infohash: bytes, numwant: int = 50,
                  exclude: Optional[Endpoint] = None,
                  family: Optional[str] = None) -> List[Endpoint]:
        """Return a *randomised* subset of the swarm's peers.

        ``family`` filters by address family (``"v4"``/``"v6"``); ``None``
        returns both.  Random selection (vs. the first-N in dict order) spreads
        connections across the swarm instead of hammering the same few peers.
        """
        with self._lock:
            self._reap()
            sw = self.swarms.get(infohash)
            if sw is None:
                return []
            numwant = max(0, min(numwant, self.max_peers_per_reply))
            candidates = [
                key for key in sw.peers
                if key != exclude and self._family_ok(key[0], family)
            ]
            if len(candidates) <= numwant:
                random.shuffle(candidates)
                return candidates
            return random.sample(candidates, numwant)

    @staticmethod
    def _family_ok(ip: str, family: Optional[str]) -> bool:
        if family is None:
            return True
        return (family == "v6") == _is_ipv6(ip)

    def counts(self, infohash: bytes) -> Tuple[int, int, int]:
        """Return (complete/seeders, incomplete/leechers, downloaded)."""
        with self._lock:
            self._reap()
            sw = self.swarms.get(infohash)
            if sw is None:
                return 0, 0, 0
            complete = sum(1 for p in sw.peers.values() if p.left == 0)
            incomplete = len(sw.peers) - complete
            return complete, incomplete, sw.downloaded

    def scrape(self, infohash: bytes) -> Dict[str, int]:
        complete, incomplete, downloaded = self.counts(infohash)
        return {"complete": complete, "incomplete": incomplete,
                "downloaded": downloaded}

    # -- durability ---------------------------------------------------------
    def snapshot(self) -> bytes:
        """Serialise all live swarms to bencoded bytes (for restart survival)."""
        with self._lock:
            self._reap()
            now = time.time()
            swarms = []
            for ih, sw in self.swarms.items():
                peers = []
                for (ip, port), entry in sw.peers.items():
                    peers.append([ip.encode("utf-8"), int(port),
                                  int(entry.left), int(now - entry.last_seen)])
                swarms.append({b"ih": ih, b"downloaded": int(sw.downloaded),
                               b"peers": peers})
            return encode({b"v": 1, b"swarms": swarms})

    def restore(self, blob: bytes) -> int:
        """Reload swarms from :meth:`snapshot` output.  Returns peers restored.

        Peers already older than ``peer_ttl`` at restore time are dropped, so a
        long downtime does not resurrect a stale swarm.
        """
        try:
            data = decode(blob)
        except BencodeError:
            return 0
        if not isinstance(data, dict):
            return 0
        now = time.time()
        restored = 0
        with self._lock:
            for sw_rec in data.get(b"swarms", []):
                if not isinstance(sw_rec, dict):
                    continue
                ih = sw_rec.get(b"ih")
                if not isinstance(ih, bytes) or len(ih) != 20:
                    continue
                sw = self.swarms.get(ih)
                if sw is None:
                    # Honour the live swarm cap on restore too, so a hostile
                    # snapshot cannot exceed the in-memory bound.
                    if len(self.swarms) >= self.max_swarms:
                        break
                    sw = self.swarms[ih] = Swarm()
                dl = sw_rec.get(b"downloaded")
                if isinstance(dl, int) and dl >= 0:
                    sw.downloaded = max(sw.downloaded, dl)
                peers_here = 0
                for peer in sw_rec.get(b"peers", []):
                    if (peers_here >= self.max_peers_per_swarm
                            or len(sw.peers) >= self.max_peers_per_swarm):
                        break
                    if not (isinstance(peer, list) and len(peer) >= 4):
                        continue
                    ip_b, port, left, age = peer[0], peer[1], peer[2], peer[3]
                    # Reject an out-of-range port: it would otherwise be handed
                    # to struct.pack(">H", port) in the compact codec and raise.
                    if (not isinstance(ip_b, bytes) or not isinstance(port, int)
                            or not (0 <= port < 65536)):
                        continue
                    age = age if isinstance(age, int) else 0
                    if age >= self.peer_ttl:
                        continue
                    last_seen = now - age
                    sw.peers[(ip_b.decode("utf-8", "replace"), int(port))] = \
                        PeerEntry(int(left) if isinstance(left, int) else 0, last_seen)
                    peers_here += 1
                    restored += 1
        return restored

    def save_to_file(self, path: str) -> None:
        with open(path, "wb") as fh:
            fh.write(self.snapshot())

    def load_from_file(self, path: str) -> int:
        try:
            with open(path, "rb") as fh:
                return self.restore(fh.read())
        except FileNotFoundError:
            return 0
