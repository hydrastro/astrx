"""HTTP BitTorrent tracker: GET /announce and GET /scrape.

Implements BEP-3 (announce) with BEP-23 compact peer lists (and the
legacy dictionary model when ``compact=0``) plus the conventional
``/scrape`` convention.  The query string is parsed at the byte level
because ``info_hash`` and ``peer_id`` are raw 20-byte values that are not
valid text once percent-decoded.
"""

from __future__ import annotations

import socket
import struct
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Dict, List, Optional
from urllib.parse import unquote_to_bytes

from .bencode import encode
from .peerstore import PeerStore


def parse_query_bytes(query: str) -> Dict[str, List[bytes]]:
    """Parse a raw query string preserving binary values (info_hash etc.)."""
    out: Dict[str, List[bytes]] = {}
    if not query:
        return out
    for pair in query.split("&"):
        if not pair:
            continue
        if "=" in pair:
            key, _, value = pair.partition("=")
        else:
            key, value = pair, ""
        name = unquote_to_bytes(key).decode("latin1")
        out.setdefault(name, []).append(unquote_to_bytes(value))
    return out


def _int(params: Dict[str, List[bytes]], name: str, default: int = 0) -> int:
    vals = params.get(name)
    if not vals:
        return default
    try:
        return int(vals[0])
    except ValueError:
        return default


def build_compact_peers(peers) -> bytes:
    out = bytearray()
    for ip, port in peers:
        try:
            out += socket.inet_aton(ip) + struct.pack(">H", port)
        except (OSError, struct.error):
            continue  # skip non-IPv4 endpoints / out-of-range ports
    return bytes(out)


def build_compact_peers6(peers) -> bytes:
    """BEP-7 compact IPv6 peer list: 16-byte address + 2-byte port each."""
    out = bytearray()
    for ip, port in peers:
        try:
            out += socket.inet_pton(socket.AF_INET6, ip) + struct.pack(">H", port)
        except (OSError, struct.error):
            continue  # skip non-IPv6 endpoints / out-of-range ports
    return bytes(out)


def build_dict_peers(peers) -> list:
    return [{b"ip": ip.encode(), b"port": port} for ip, port in peers]


class TrackerHandler(BaseHTTPRequestHandler):
    server_version = "torrentds-tracker/1.0"
    protocol_version = "HTTP/1.1"

    # Injected by the server factory.
    peer_store: PeerStore = None  # type: ignore[assignment]

    def log_message(self, *args):  # silence default stderr logging
        pass

    def _send(self, body: bytes, status: int = 200) -> None:
        self.send_response(status)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _failure(self, reason: str) -> None:
        self._send(encode({b"failure reason": reason.encode()}))

    def do_GET(self) -> None:
        path, _, query = self.path.partition("?")
        params = parse_query_bytes(query)
        if path.rstrip("/") in ("/announce",):
            self.handle_announce(params)
        elif path.rstrip("/") in ("/scrape",):
            self.handle_scrape(params)
        elif path in ("/", ""):
            self._send(b"torrentds tracker: GET /announce and GET /scrape\n")
        else:
            self._send(encode({b"failure reason": b"not found"}), status=404)

    def handle_announce(self, params: Dict[str, List[bytes]]) -> None:
        info_hash = (params.get("info_hash") or [b""])[0]
        if len(info_hash) != 20:
            return self._failure("invalid info_hash")
        port = _int(params, "port", 0)
        if not (0 < port < 65536):
            return self._failure("invalid port")
        left = _int(params, "left", 0)
        event_raw = (params.get("event") or [b""])[0]
        event = event_raw.decode("latin1") or None
        compact = _int(params, "compact", 1)
        numwant = _int(params, "numwant", 50)
        ip = self.client_address[0]

        if not self.peer_store.announce(info_hash, ip, port, left, event):
            return self._failure("info_hash not allowed by tracker policy")

        complete, incomplete, _ = self.peer_store.counts(info_hash)
        # BEP-7: split the swarm by address family -- IPv4 peers go in ``peers``
        # (or a dict list), IPv6 peers in ``peers6``.
        peers4 = self.peer_store.get_peers(info_hash, numwant, exclude=(ip, port),
                                           family="v4")
        peers6 = self.peer_store.get_peers(info_hash, numwant, exclude=(ip, port),
                                           family="v6")
        resp = {
            b"interval": self.peer_store.interval,
            b"min interval": max(1, self.peer_store.interval // 2),
            b"complete": complete,
            b"incomplete": incomplete,
        }
        if compact:
            resp[b"peers"] = build_compact_peers(peers4)
            if peers6:
                resp[b"peers6"] = build_compact_peers6(peers6)
        else:
            resp[b"peers"] = build_dict_peers(peers4 + peers6)
        self._send(encode(resp))

    def handle_scrape(self, params: Dict[str, List[bytes]]) -> None:
        hashes = [h for h in params.get("info_hash", []) if len(h) == 20]
        if not hashes:
            # Scraping the whole tracker is discouraged; require an info_hash.
            return self._failure("scrape requires at least one info_hash")
        files = {}
        for ih in hashes:
            s = self.peer_store.scrape(ih)
            files[ih] = {
                b"complete": s["complete"],
                b"downloaded": s["downloaded"],
                b"incomplete": s["incomplete"],
            }
        self._send(encode({b"files": files}))


def make_http_tracker(peer_store: PeerStore, host: str = "127.0.0.1",
                      port: int = 8805) -> ThreadingHTTPServer:
    handler = type("BoundTrackerHandler", (TrackerHandler,),
                   {"peer_store": peer_store})
    return ThreadingHTTPServer((host, port), handler)
