"""Kademlia routing primitives for the Mainline DHT (BEP-5).

This module is transport-agnostic and fully synchronous, which makes it
directly unit-testable:

* 160-bit node IDs and XOR distance.
* ``Node`` records (id + IPv4 endpoint + freshness timestamp).
* ``KBucket`` (bounded, LRU-ish) and ``RoutingTable`` built from 160
  bit-indexed buckets -- bucket *i* holds contacts whose XOR distance from
  our own ID has its most-significant set bit at position *i*.  This is a
  standard and correct k-bucket layout; it avoids the extra machinery of
  on-the-fly bucket splitting while giving the same lookup behaviour.
* Compact "nodes" (26 bytes: 20 id + 4 IP + 2 port) and compact "peers"
  (6 bytes: 4 IP + 2 port) codecs used on the wire.
"""

from __future__ import annotations

import os
import socket
import struct
import time
from dataclasses import dataclass, field
from typing import Iterable, Iterator, List, Optional

ID_BITS = 160
ID_BYTES = 20
DEFAULT_K = 8  # nodes per bucket (Mainline uses k=8)


# --------------------------------------------------------------------------
# Node identity / distance
# --------------------------------------------------------------------------

def random_node_id() -> bytes:
    """Return a fresh random 160-bit node ID."""
    return os.urandom(ID_BYTES)


def to_int(node_id: bytes) -> int:
    return int.from_bytes(node_id, "big")


def distance(a: bytes, b: bytes) -> int:
    """XOR distance between two node IDs as an integer."""
    return to_int(a) ^ to_int(b)


def bucket_index(self_id: bytes, other_id: bytes) -> int:
    """Index of the k-bucket *other_id* belongs to relative to *self_id*.

    Returns -1 when the IDs are identical (no bucket).
    """
    d = distance(self_id, other_id)
    if d == 0:
        return -1
    return d.bit_length() - 1


@dataclass
class Node:
    id: bytes
    host: str
    port: int
    last_seen: float = field(default_factory=time.time)

    def __post_init__(self) -> None:
        if len(self.id) != ID_BYTES:
            raise ValueError("node id must be 20 bytes")

    def touch(self) -> None:
        self.last_seen = time.time()

    def compact(self) -> bytes:
        return self.id + encode_endpoint(self.host, self.port)

    def __eq__(self, other: object) -> bool:
        return isinstance(other, Node) and other.id == self.id

    def __hash__(self) -> int:
        return hash(self.id)


# --------------------------------------------------------------------------
# Compact endpoint / node / peer codecs (IPv4)
# --------------------------------------------------------------------------

def encode_endpoint(host: str, port: int) -> bytes:
    return socket.inet_aton(host) + struct.pack(">H", port)


def decode_endpoint(blob: bytes) -> tuple[str, int]:
    if len(blob) != 6:
        raise ValueError("endpoint must be 6 bytes")
    return socket.inet_ntoa(blob[:4]), struct.unpack(">H", blob[4:6])[0]


# IPv6 compact endpoint (BEP-7): 16-byte address + 2-byte port = 18 bytes.

def encode_endpoint6(host: str, port: int) -> bytes:
    return socket.inet_pton(socket.AF_INET6, host) + struct.pack(">H", port)


def decode_endpoint6(blob: bytes) -> tuple[str, int]:
    if len(blob) != 18:
        raise ValueError("ipv6 endpoint must be 18 bytes")
    return (socket.inet_ntop(socket.AF_INET6, blob[:16]),
            struct.unpack(">H", blob[16:18])[0])


def is_ipv6(host: str) -> bool:
    """True if *host* is an IPv6 literal (contains a colon)."""
    return ":" in host


def encode_nodes(nodes: Iterable[Node]) -> bytes:
    return b"".join(n.compact() for n in nodes)


def decode_nodes(blob: bytes) -> List[Node]:
    """Parse a compact "nodes" string; silently drops a ragged tail."""
    out: List[Node] = []
    for off in range(0, len(blob) - (len(blob) % 26), 26):
        chunk = blob[off : off + 26]
        node_id = chunk[:20]
        host, port = decode_endpoint(chunk[20:26])
        out.append(Node(node_id, host, port))
    return out


def encode_peers(peers: Iterable[tuple[str, int]]) -> List[bytes]:
    """Encode (host, port) tuples into a list of 6-byte compact peers."""
    return [encode_endpoint(h, p) for h, p in peers]


def decode_peers(values: Iterable[bytes]) -> List[tuple[str, int]]:
    out: List[tuple[str, int]] = []
    for v in values:
        if len(v) == 6:
            out.append(decode_endpoint(v))
    return out


# --------------------------------------------------------------------------
# K-buckets and routing table
# --------------------------------------------------------------------------

class KBucket:
    """A bounded set of nodes ordered least- to most-recently seen."""

    def __init__(self, k: int = DEFAULT_K):
        self.k = k
        self.nodes: List[Node] = []

    def __len__(self) -> int:
        return len(self.nodes)

    def __iter__(self) -> Iterator[Node]:
        return iter(self.nodes)

    def get(self, node_id: bytes) -> Optional[Node]:
        for n in self.nodes:
            if n.id == node_id:
                return n
        return None

    def add(self, node: Node) -> bool:
        """Insert/refresh *node*.

        Returns True if the node is now present (added or refreshed), False
        if the bucket was full of live contacts and the node was dropped.
        """
        existing = self.get(node.id)
        if existing is not None:
            existing.touch()
            existing.host, existing.port = node.host, node.port
            # Move to tail (most-recently seen).
            self.nodes.remove(existing)
            self.nodes.append(existing)
            return True
        if len(self.nodes) < self.k:
            self.nodes.append(node)
            return True
        return False

    def remove(self, node_id: bytes) -> None:
        n = self.get(node_id)
        if n is not None:
            self.nodes.remove(n)


class RoutingTable:
    """160 bit-indexed k-buckets rooted at *self_id*."""

    def __init__(self, self_id: bytes, k: int = DEFAULT_K):
        if len(self_id) != ID_BYTES:
            raise ValueError("self_id must be 20 bytes")
        self.self_id = self_id
        self.k = k
        self.buckets: List[KBucket] = [KBucket(k) for _ in range(ID_BITS)]

    def add_node(self, node: Node) -> bool:
        idx = bucket_index(self.self_id, node.id)
        if idx < 0:
            return False  # never store our own ID
        return self.buckets[idx].add(node)

    def remove_node(self, node_id: bytes) -> None:
        idx = bucket_index(self.self_id, node_id)
        if idx >= 0:
            self.buckets[idx].remove(node_id)

    def all_nodes(self) -> List[Node]:
        out: List[Node] = []
        for bucket in self.buckets:
            out.extend(bucket.nodes)
        return out

    def __len__(self) -> int:
        return sum(len(b) for b in self.buckets)

    def find_closest(self, target: bytes, count: int = DEFAULT_K) -> List[Node]:
        """Return up to *count* known nodes closest (XOR) to *target*."""
        nodes = self.all_nodes()
        nodes.sort(key=lambda n: distance(n.id, target))
        return nodes[:count]
