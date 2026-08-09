"""Tracker-scrape aggregation (BEP-48 HTTP scrape + BEP-15 UDP scrape).

Queries a set of *operator-configured* trackers for a swarm's seeders /
leechers / completed counts and folds them into a single combined swarm-health
number, alongside the tracker's own :class:`~torrentds.peerstore.PeerStore`
counts.  Everything is loopback-testable: the tracker list is injected, so a
test can point it at a local :func:`~torrentds.tracker_http.make_http_tracker`
or :class:`~torrentds.tracker_udp.UDPTracker` stub.

Hardening
---------
Trackers are operator-configured (not user-supplied), which bounds the SSRF
surface, but a *hostile tracker response* is still untrusted network data:

* per-request timeouts + a bounded response read (no unbounded streaming);
* a cap on the number of trackers queried and infohashes per scrape;
* every returned count is clamped to ``[0, MAX_COUNT]`` and non-ints dropped,
  so a spoofed/huge response can never corrupt the served health;
* all bencode is decoded through the bounded decoder (strict, then lenient).
"""

from __future__ import annotations

import ipaddress
import os
import socket
import struct
import time
import urllib.request as _urlreq
from dataclasses import dataclass
from typing import Dict, List, Optional, Sequence, Tuple
from urllib.parse import quote, urlsplit

from .bencode import BencodeError, decode, decode_lenient

# BEP-15 constants (mirrored from tracker_udp so this module stays standalone).
_PROTOCOL_ID = 0x41727101980
_ACTION_CONNECT = 0
_ACTION_SCRAPE = 2

MAX_COUNT = 100_000_000        # clamp any single seeder/leecher/completed value
MAX_TRACKERS = 20              # cap trackers queried per aggregation
MAX_HASHES = 64               # cap infohashes per scrape request
MAX_HTTP_BYTES = 2 * 1024 * 1024   # bounded scrape-response read

# A per-infohash triple: (complete/seeders, incomplete/leechers, downloaded).
Triple = Tuple[int, int, int]


def _clamp(v) -> int:
    if not isinstance(v, int) or isinstance(v, bool):
        return 0
    if v < 0:
        return 0
    return v if v < MAX_COUNT else MAX_COUNT


# --------------------------------------------------------------------------
# SSRF-hardened HTTP opener
# --------------------------------------------------------------------------
#
# A *hostile tracker response* (the response is untrusted even though the
# tracker URL is operator-configured) could 3xx-redirect the scrape to an
# internal address -- ``http://127.0.0.1``, cloud metadata at
# ``http://169.254.169.254``, an RFC-1918 host, or, via urllib's default
# opener, ``ftp://``/``file://``.  The stock ``urlopen`` follows those
# redirects and bundles ``FTPHandler``/``FileHandler``.  We instead build a
# private ``OpenerDirector`` that:
#
#   * carries ONLY the http/https handlers (no ftp/file/data), and
#   * re-validates every redirect hop: the target scheme must be http(s) AND
#     its resolved IP must be a public address (never loopback / private /
#     link-local / ULA / reserved / multicast).  A redirect that fails either
#     check is not followed, so the scrape simply yields no data.
#
# The *initial* operator-configured URL is not IP-filtered (an operator may
# legitimately run a tracker on the loopback / LAN and the loopback test stubs
# rely on this); only attacker-controlled redirect targets are gated.

def _ip_is_public(ip: "ipaddress._BaseAddress") -> bool:
    return not (ip.is_private or ip.is_loopback or ip.is_link_local
                or ip.is_reserved or ip.is_multicast or ip.is_unspecified)


def _host_is_public(host: Optional[str]) -> bool:
    """True only if *host* resolves and every resolved address is public."""
    if not host:
        return False
    # A bare IP literal is checked directly (no DNS).
    try:
        return _ip_is_public(ipaddress.ip_address(host))
    except ValueError:
        pass
    try:
        infos = socket.getaddrinfo(host, None)
    except (OSError, UnicodeError):
        return False
    if not infos:
        return False
    for info in infos:
        addr = info[4][0]
        try:
            if not _ip_is_public(ipaddress.ip_address(addr)):
                return False
        except ValueError:
            return False
    return True


class _GuardedRedirect(_urlreq.HTTPRedirectHandler):
    """Only follow http(s) redirects whose target resolves to a public IP."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        try:
            parts = urlsplit(newurl)
        except ValueError:
            return None
        if parts.scheme.lower() not in ("http", "https"):
            return None  # never redirect to ftp/file/etc. (SSRF)
        if not _host_is_public(parts.hostname):
            return None  # never redirect to an internal address (SSRF)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def _build_opener() -> _urlreq.OpenerDirector:
    handlers: List[object] = [_urlreq.HTTPHandler(),
                              _GuardedRedirect(),
                              _urlreq.HTTPErrorProcessor(),
                              _urlreq.HTTPDefaultErrorHandler()]
    https = getattr(_urlreq, "HTTPSHandler", None)
    if https is not None:
        handlers.insert(1, https())
    opener = _urlreq.OpenerDirector()
    for h in handlers:
        opener.add_handler(h)
    return opener


# Module-level opener: no FTP/File/Data/Unknown handlers, guarded redirects.
_OPENER = _build_opener()


@dataclass(frozen=True)
class Tracker:
    """A scrape target: ``("http", scrape_url)`` or ``("udp", host, port)``."""
    kind: str            # "http" | "udp"
    url: str = ""        # http/https scrape URL
    host: str = ""
    port: int = 0


def parse_tracker(spec: str) -> Optional[Tracker]:
    """Parse an operator tracker spec into a :class:`Tracker`.

    Accepts ``http(s)://host[:port]/announce|/scrape`` (the ``announce`` path
    segment is rewritten to ``scrape`` per convention) and
    ``udp://host:port``.  Returns ``None`` for an unsupported scheme.
    """
    spec = (spec or "").strip()
    if not spec:
        return None
    parts = urlsplit(spec)
    scheme = parts.scheme.lower()
    if scheme in ("http", "https"):
        path = parts.path or "/"
        if path.rsplit("/", 1)[-1] == "announce":
            path = path[: -len("announce")] + "scrape"
        url = "%s://%s%s" % (scheme, parts.netloc, path)
        if parts.query:
            url += "?" + parts.query
        return Tracker("http", url=url)
    if scheme == "udp":
        if parts.hostname is None or parts.port is None:
            return None
        return Tracker("udp", host=parts.hostname, port=int(parts.port))
    return None


def _parse_scrape_files(blob: bytes) -> Dict[bytes, Triple]:
    """Decode a BEP-48 scrape response ``{files: {ih: {...}}}`` defensively."""
    try:
        data = decode(blob)
    except BencodeError:
        try:
            data = decode_lenient(blob)
        except BencodeError:
            return {}
    if not isinstance(data, dict):
        return {}
    files = data.get(b"files")
    if not isinstance(files, dict):
        return {}
    out: Dict[bytes, Triple] = {}
    for ih, rec in files.items():
        if not (isinstance(ih, bytes) and len(ih) == 20 and isinstance(rec, dict)):
            continue
        out[ih] = (_clamp(rec.get(b"complete")),
                   _clamp(rec.get(b"incomplete")),
                   _clamp(rec.get(b"downloaded")))
    return out


def scrape_http(scrape_url: str, infohashes: Sequence[bytes],
                timeout: float = 5.0,
                max_hashes: int = MAX_HASHES) -> Dict[bytes, Triple]:
    """HTTP scrape (BEP-48).  Returns ``{infohash: (complete, incomplete, dl)}``."""
    hashes = [h for h in infohashes if isinstance(h, bytes) and len(h) == 20][:max_hashes]
    if not hashes:
        return {}
    sep = "&" if "?" in scrape_url else "?"
    q = "&".join("info_hash=" + quote(bytes(h), safe="") for h in hashes)
    url = scrape_url + sep + q
    # Restrict the initial request to http(s); redirects are additionally gated
    # by ``_GuardedRedirect`` (public-IP + http(s) only), and the opener carries
    # no ftp/file handlers, so a hostile redirect can never reach an internal
    # address or another scheme (SSRF).
    if not url.lower().startswith(("http://", "https://")):
        return {}
    try:
        with _OPENER.open(url, timeout=timeout) as resp:
            blob = resp.read(MAX_HTTP_BYTES + 1)
    except Exception:
        return {}
    if len(blob) > MAX_HTTP_BYTES:
        return {}  # hostile oversized response
    return _parse_scrape_files(blob)


def scrape_udp(host: str, port: int, infohashes: Sequence[bytes],
               timeout: float = 5.0,
               max_hashes: int = MAX_HASHES) -> Dict[bytes, Triple]:
    """UDP scrape (BEP-15): connect handshake then scrape up to 74 hashes."""
    hashes = [h for h in infohashes if isinstance(h, bytes) and len(h) == 20][:max_hashes]
    if not hashes:
        return {}
    fam = socket.AF_INET6 if ":" in host else socket.AF_INET
    sock = socket.socket(fam, socket.SOCK_DGRAM)
    sock.settimeout(timeout)
    try:
        # ``connect`` binds the peer so the kernel drops datagrams from any
        # other source: an off-path attacker can no longer inject a forged
        # scrape reply (source-address matching, like the KRPC/tracker paths).
        sock.connect((host, port))
        # Random transaction ids (not the wall-clock second) so a reply cannot
        # be forged from a predictable txn either.
        txn = int.from_bytes(os.urandom(4), "big")
        sock.send(struct.pack(">QII", _PROTOCOL_ID, _ACTION_CONNECT, txn))
        data = sock.recv(32)
        if len(data) < 16:
            return {}
        action, rtxn, conn_id = struct.unpack(">IIQ", data[:16])
        if action != _ACTION_CONNECT or rtxn != txn:
            return {}
        txn = int.from_bytes(os.urandom(4), "big")
        req = struct.pack(">QII", conn_id, _ACTION_SCRAPE, txn) + b"".join(hashes)
        sock.send(req)
        # 8-byte header + 12 bytes per hash; bound the read.
        data = sock.recv(8 + 12 * len(hashes) + 16)
    except (OSError, struct.error):
        return {}
    finally:
        sock.close()
    if len(data) < 8:
        return {}
    action, rtxn = struct.unpack(">II", data[:8])
    if action != _ACTION_SCRAPE or rtxn != txn:
        return {}
    out: Dict[bytes, Triple] = {}
    off = 8
    for h in hashes:
        if off + 12 > len(data):
            break
        seeders, completed, leechers = struct.unpack(">iii", data[off:off + 12])
        out[h] = (_clamp(seeders), _clamp(leechers), _clamp(completed))
        off += 12
    return out


class ScrapeAggregator:
    """Aggregate scrape counts for infohashes across several trackers.

    Combined health takes the **max** of each field across trackers (the same
    swarm mirrored on multiple trackers must not be summed / double-counted),
    each value already clamped.  Results are cached for ``cache_ttl`` seconds so
    a search request never blocks on a live scrape more than once per window.
    """

    def __init__(self, trackers: Sequence[Tracker], timeout: float = 5.0,
                 max_trackers: int = MAX_TRACKERS, max_hashes: int = MAX_HASHES,
                 cache_ttl: float = 300.0):
        self.trackers: List[Tracker] = list(trackers)[:max_trackers]
        self.timeout = timeout
        self.max_hashes = max_hashes
        self.cache_ttl = cache_ttl
        self._cache: Dict[bytes, Tuple[float, Dict[str, int]]] = {}

    @classmethod
    def from_specs(cls, specs: Sequence[str], **kw) -> "ScrapeAggregator":
        trackers = [t for t in (parse_tracker(s) for s in specs) if t is not None]
        return cls(trackers, **kw)

    def _scrape_tracker(self, tr: Tracker, hashes: Sequence[bytes]) -> Dict[bytes, Triple]:
        if tr.kind == "http":
            return scrape_http(tr.url, hashes, self.timeout, self.max_hashes)
        if tr.kind == "udp":
            return scrape_udp(tr.host, tr.port, hashes, self.timeout, self.max_hashes)
        return {}

    def scrape(self, infohashes: Sequence[bytes]) -> Dict[bytes, Dict[str, int]]:
        """Scrape all trackers and return combined per-infohash health."""
        hashes = [h for h in infohashes
                  if isinstance(h, bytes) and len(h) == 20][:self.max_hashes]
        combined: Dict[bytes, Dict[str, int]] = {
            h: {"seeders": 0, "leechers": 0, "completed": 0, "trackers": 0}
            for h in hashes
        }
        for tr in self.trackers:
            res = self._scrape_tracker(tr, hashes)
            for h, (c, i, d) in res.items():
                agg = combined.get(h)
                if agg is None:
                    continue
                agg["seeders"] = max(agg["seeders"], c)
                agg["leechers"] = max(agg["leechers"], i)
                agg["completed"] = max(agg["completed"], d)
                agg["trackers"] += 1
        return combined

    def health(self, infohash: bytes) -> Dict[str, int]:
        """Cached combined health for one infohash (scrapes on a cache miss)."""
        now = time.time()
        hit = self._cache.get(infohash)
        if hit is not None and now - hit[0] < self.cache_ttl:
            return hit[1]
        result = self.scrape([infohash]).get(
            infohash, {"seeders": 0, "leechers": 0, "completed": 0, "trackers": 0})
        self._cache[infohash] = (now, result)
        return result
