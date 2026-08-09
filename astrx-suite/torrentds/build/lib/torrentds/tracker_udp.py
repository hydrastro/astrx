"""UDP BitTorrent tracker (BEP-15).

Implements the connect -> announce/scrape handshake:

* magic ``protocol_id = 0x41727101980`` guards the connect request;
* the tracker issues a 64-bit ``connection_id`` that the client must echo
  on announce/scrape (validated against issuing address + a short TTL);
* 32-bit transaction ids are echoed back on every reply;
* announce replies carry compact ``(ipv4, port)`` peers, scrape replies
  carry ``(seeders, completed, leechers)`` per infohash;
* malformed / unauthorised requests get an ``action=3`` error reply.

Runs a blocking recvfrom loop in a daemon thread so it is trivial to start
and stop inside a loopback test.
"""

from __future__ import annotations

import hashlib
import hmac
import os
import socket
import struct
import threading
import time
from typing import Dict, Optional, Tuple

from .peerstore import PeerStore

PROTOCOL_ID = 0x41727101980
ACTION_CONNECT = 0
ACTION_ANNOUNCE = 1
ACTION_SCRAPE = 2
ACTION_ERROR = 3

# BEP-15 event codes
EVENT_NONE = 0
EVENT_COMPLETED = 1
EVENT_STARTED = 2
EVENT_STOPPED = 3
_EVENT_NAMES = {EVENT_COMPLETED: "completed", EVENT_STARTED: "started",
                EVENT_STOPPED: "stopped", EVENT_NONE: None}


class UDPTracker:
    def __init__(self, peer_store: PeerStore, host: str = "127.0.0.1",
                 port: int = 6969, conn_ttl: int = 120,
                 max_conns: int = 65536):
        self.peer_store = peer_store
        self.host = host
        self.port = port
        self.conn_ttl = conn_ttl
        self.max_conns = max_conns          # accepted for API compat (unused)
        self.sock: Optional[socket.socket] = None
        self._thread: Optional[threading.Thread] = None
        self._running = False
        # Connection ids are now STATELESS (keyed HMAC of source addr + time
        # window), so no per-connection table is kept -- a connect flood can no
        # longer grow memory.  ``_conns`` remains as an always-empty vestige for
        # API/back-compat.
        self._conns: Dict[int, Tuple[Tuple[str, int], float]] = {}
        self._secret = os.urandom(32)
        self._window = max(1, conn_ttl)     # seconds per HMAC time window
        self._family = "v4"

    # -- lifecycle ----------------------------------------------------------
    def start(self) -> None:
        # Bind AF_INET6 for an IPv6 host literal, AF_INET otherwise; the peer
        # codec in the announce reply follows the socket's family.
        if ":" in self.host:
            self.sock = socket.socket(socket.AF_INET6, socket.SOCK_DGRAM)
            self._family = "v6"
        else:
            self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self._family = "v4"
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind((self.host, self.port))
        self.port = self.sock.getsockname()[1]
        self.sock.settimeout(0.5)
        self._running = True
        self._thread = threading.Thread(target=self._serve, daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._running = False
        if self._thread is not None:
            self._thread.join(timeout=2)
            self._thread = None
        if self.sock is not None:
            self.sock.close()
            self.sock = None

    # -- main loop ----------------------------------------------------------
    def _serve(self) -> None:
        while self._running:
            try:
                data, addr = self.sock.recvfrom(4096)
            except socket.timeout:
                continue
            except OSError:
                break
            try:
                reply = self._handle(data, addr)
            except Exception:
                reply = None
            if reply is not None:
                try:
                    self.sock.sendto(reply, addr)
                except OSError:
                    pass

    # -- connection-id bookkeeping (stateless, keyed HMAC) ------------------
    @staticmethod
    def _addr_key(addr) -> bytes:
        return ("%s|%d" % (addr[0], addr[1])).encode("utf-8")

    def _cid_bytes(self, addr, window: int) -> bytes:
        mac = hmac.new(self._secret,
                       self._addr_key(addr) + struct.pack(">q", window),
                       hashlib.sha256).digest()
        return mac[:8]

    def _cid(self, addr, window: int) -> int:
        return int.from_bytes(self._cid_bytes(addr, window), "big")

    def _new_connection_id(self, addr) -> int:
        # Deterministic function of (source addr, current time window, secret).
        # No state stored -- ``_conns`` stays empty.
        return self._cid(addr, int(time.time()) // self._window)

    def _valid_connection(self, cid: int, addr) -> bool:
        # Accept the current or previous window so an id stays valid for at
        # least one ``conn_ttl`` after issue (and at most two).  Compare the
        # packed MACs with a constant-time equality (hmac.compare_digest) rather
        # than integer ``==`` to avoid leaking timing on the connection-id.
        if not (0 <= cid < (1 << 64)):
            return False
        got = struct.pack(">Q", cid)
        window = int(time.time()) // self._window
        return (hmac.compare_digest(got, self._cid_bytes(addr, window)) or
                hmac.compare_digest(got, self._cid_bytes(addr, window - 1)))

    # -- dispatch -----------------------------------------------------------
    def _handle(self, data: bytes, addr) -> Optional[bytes]:
        if len(data) < 16:
            return None
        connection_id, action, txn = struct.unpack(">QII", data[:16])
        if action == ACTION_CONNECT:
            if connection_id != PROTOCOL_ID:
                return None
            cid = self._new_connection_id(addr)
            return struct.pack(">IIQ", ACTION_CONNECT, txn, cid)
        if action == ACTION_ANNOUNCE:
            if not self._valid_connection(connection_id, addr):
                return self._error(txn, "connection id mismatch")
            return self._announce(data, txn, addr)
        if action == ACTION_SCRAPE:
            if not self._valid_connection(connection_id, addr):
                return self._error(txn, "connection id mismatch")
            return self._scrape(data, txn)
        return self._error(txn, "unknown action")

    def _error(self, txn: int, message: str) -> bytes:
        return struct.pack(">II", ACTION_ERROR, txn) + message.encode()

    # -- announce -----------------------------------------------------------
    def _announce(self, data: bytes, txn: int, addr) -> bytes:
        if len(data) < 98:
            return self._error(txn, "short announce")
        (info_hash, peer_id, downloaded, left, uploaded, event, ip_int,
         key, num_want, port) = struct.unpack(">20s20sQQQIIiiH", data[16:98])
        # Ignore the client-supplied ip field: honoring it would let a client
        # inject an arbitrary victim IP into the swarm (tracker-as-DDoS-
        # reflector / swarm poisoning).  Always use the packet source address,
        # matching the HTTP tracker.
        ip = addr[0]
        event_name = _EVENT_NAMES.get(event, None)
        if not self.peer_store.announce(info_hash, ip, port, left, event_name):
            return self._error(txn, "info_hash not allowed")
        want = 50 if num_want < 0 else min(num_want, self.peer_store.max_peers_per_reply)
        complete, incomplete, _ = self.peer_store.counts(info_hash)
        # BEP-15 carries peers of the connection's own address family; select
        # matching-family peers and use the matching compact codec.
        peers = self.peer_store.get_peers(info_hash, want, exclude=(ip, port),
                                          family=self._family)
        body = struct.pack(">IIiii", ACTION_ANNOUNCE, txn,
                           self.peer_store.interval, incomplete, complete)
        v6 = self._family == "v6"
        for pip, pport in peers:
            try:
                if v6:
                    body += socket.inet_pton(socket.AF_INET6, pip) + struct.pack(">H", pport)
                else:
                    body += socket.inet_aton(pip) + struct.pack(">H", pport)
            except (OSError, struct.error):
                continue  # skip a bad address / out-of-range port
        return body

    # -- scrape -------------------------------------------------------------
    def _scrape(self, data: bytes, txn: int) -> bytes:
        body = struct.pack(">II", ACTION_SCRAPE, txn)
        offset = 16
        count = 0
        while offset + 20 <= len(data) and count < 74:  # BEP-15: up to 74 hashes
            info_hash = data[offset:offset + 20]
            offset += 20
            count += 1
            complete, incomplete, downloaded = self.peer_store.counts(info_hash)
            body += struct.pack(">iii", complete, downloaded, incomplete)
        return body
