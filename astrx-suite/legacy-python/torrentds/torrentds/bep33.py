"""BEP-33 — DHT scrape: estimate a swarm's seeders/leechers from the bloom
filters a node returns to a ``get_peers`` query, so torrentds can report swarm
health from the DHT itself (no external tracker needed — important for a
Tor-only operator).

A BEP-33 node answers ``get_peers`` (with ``scrape=1``) by adding two 256-byte
bloom filters to its response: ``BFsd`` (seeds) and ``BFpe`` (peers/leechers).
Each is a 2048-bit filter, ``k=2``, into which every announcing IP was hashed.
The population is recovered from the count of still-zero bits:

    size = ln(c / m) / (2 · ln(1 − 1/m))      # c = zero bits, m = 2048

This module is pure stdlib and exact-tested (build a filter from N IPs → the
estimate lands near N).  Wiring it into the live DHT (setting ``scrape=1`` and
reading ``BFsd``/``BFpe`` off real responses) is a deployment concern; the
decoder here is the reusable core.
"""

import hashlib
import ipaddress
import math

BLOOM_BYTES = 256
BLOOM_BITS = BLOOM_BYTES * 8   # 2048


def new_filter():
    return bytearray(BLOOM_BYTES)


def _indices(ip):
    """The two BEP-33 bit indices for an IP (SHA-1 of the packed address,
    first two 16-bit little-endian words, each mod 2048)."""
    packed = ipaddress.ip_address(ip).packed
    h = hashlib.sha1(packed).digest()
    i1 = (h[0] | (h[1] << 8)) % BLOOM_BITS
    i2 = (h[2] | (h[3] << 8)) % BLOOM_BITS
    return i1, i2


def add_ip(bloom, ip):
    """Set an IP's two bits in *bloom* (a 256-byte bytearray)."""
    for idx in _indices(ip):
        bloom[idx >> 3] |= (1 << (idx & 7))


def build_filter(ips):
    """Build a BEP-33 bloom filter from an iterable of IP strings."""
    bf = new_filter()
    for ip in ips:
        try:
            add_ip(bf, ip)
        except ValueError:
            continue          # skip a malformed address
    return bytes(bf)


def estimate(bloom):
    """Estimate the population of a BEP-33 bloom filter (bytes/bytearray)."""
    if not bloom:
        return 0
    m = len(bloom) * 8
    set_bits = 0
    for b in bloom:
        set_bits += bin(b).count("1")
    zeros = m - set_bits
    if zeros >= m:
        return 0                      # empty filter -> no peers
    if zeros <= 0:
        return m                      # saturated -> at least m (huge swarm)
    size = math.log(zeros / m) / (2.0 * math.log(1.0 - 1.0 / m))
    return max(0, int(round(size)))


def estimate_from_response(resp):
    """Given a decoded ``get_peers`` response dict (bencode keys as bytes),
    return ``(seeders, leechers)`` from ``BFsd``/``BFpe``, or ``(None, None)``
    when the node did not answer with BEP-33 filters."""
    if not isinstance(resp, dict):
        return None, None
    sd = resp.get(b"BFsd") or resp.get("BFsd")
    pe = resp.get(b"BFpe") or resp.get("BFpe")
    if sd is None and pe is None:
        return None, None
    seeders = estimate(sd) if sd is not None else None
    leechers = estimate(pe) if pe is not None else None
    return seeders, leechers
