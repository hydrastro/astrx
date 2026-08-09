"""KRPC (BEP-5) message codec and asyncio UDP transport.

KRPC is the bencoded RPC used by the Mainline DHT.  Every message is a
bencoded dict with:

* ``t`` -- transaction id (opaque bytes, echoed in the reply)
* ``y`` -- message type: ``q`` query, ``r`` response, ``e`` error

Queries additionally carry ``q`` (method name) and ``a`` (argument dict);
responses carry ``r`` (return dict); errors carry ``e`` = ``[code, msg]``.

The pure ``encode_*`` / ``parse_message`` helpers are transport-free and
unit-tested directly.  ``KRPCProtocol`` layers an asyncio datagram
endpoint on top: it matches responses to outstanding queries by
transaction id and dispatches inbound queries to a supplied handler.
"""

from __future__ import annotations

import asyncio
import os
from typing import Any, Awaitable, Callable, Dict, Optional, Tuple

from .bencode import BencodeError, decode, encode

DEFAULT_TIMEOUT = 5.0

# Standard KRPC error codes (BEP-5).
ERR_GENERIC = 201
ERR_SERVER = 202
ERR_PROTOCOL = 203
ERR_METHOD_UNKNOWN = 204


# --------------------------------------------------------------------------
# Pure message codec
# --------------------------------------------------------------------------

def encode_query(txn: bytes, method: str, args: Dict[bytes | str, Any]) -> bytes:
    return encode({b"t": txn, b"y": b"q", b"q": method.encode(), b"a": args})


def encode_response(txn: bytes, response: Dict[bytes | str, Any]) -> bytes:
    return encode({b"t": txn, b"y": b"r", b"r": response})


def encode_error(txn: bytes, code: int, message: str) -> bytes:
    return encode({b"t": txn, b"y": b"e", b"e": [code, message.encode()]})


class KRPCMessage:
    """Structured view over a parsed KRPC message."""

    __slots__ = ("kind", "txn", "method", "args", "response", "error")

    def __init__(self, kind: str, txn: bytes, *, method: Optional[str] = None,
                 args: Optional[dict] = None, response: Optional[dict] = None,
                 error: Optional[tuple] = None):
        self.kind = kind          # "query" | "response" | "error"
        self.txn = txn
        self.method = method
        self.args = args
        self.response = response
        self.error = error


def parse_message(data: bytes) -> KRPCMessage:
    """Parse a datagram into a :class:`KRPCMessage`.

    Raises :class:`BencodeError` for malformed bencode and ``ValueError``
    for structurally invalid KRPC.
    """
    msg = decode(data)
    if not isinstance(msg, dict):
        raise ValueError("KRPC message must be a dict")
    txn = msg.get(b"t")
    y = msg.get(b"y")
    if not isinstance(txn, bytes):
        raise ValueError("missing transaction id")
    if y == b"q":
        method = msg.get(b"q")
        args = msg.get(b"a")
        if not isinstance(method, bytes) or not isinstance(args, dict):
            raise ValueError("malformed query")
        return KRPCMessage("query", txn, method=method.decode("latin1"), args=args)
    if y == b"r":
        response = msg.get(b"r")
        if not isinstance(response, dict):
            raise ValueError("malformed response")
        return KRPCMessage("response", txn, response=response)
    if y == b"e":
        err = msg.get(b"e")
        if not isinstance(err, list) or len(err) < 2:
            raise ValueError("malformed error")
        code = err[0]
        message = err[1].decode("utf-8", "replace") if isinstance(err[1], bytes) else str(err[1])
        return KRPCMessage("error", txn, error=(code, message))
    raise ValueError("unknown message type %r" % (y,))


class KRPCError(Exception):
    def __init__(self, code: int, message: str):
        super().__init__("KRPC error %s: %s" % (code, message))
        self.code = code
        self.message = message


# --------------------------------------------------------------------------
# asyncio transport
# --------------------------------------------------------------------------

QueryHandler = Callable[[str, dict, Tuple[str, int]], Optional[dict]]


class KRPCProtocol(asyncio.DatagramProtocol):
    """asyncio datagram endpoint speaking KRPC.

    *query_handler(method, args, addr)* is invoked for every inbound query
    and must return the response dict (``r``) or raise :class:`KRPCError`.
    Returning ``None`` suppresses any reply (used by passive harvesting so
    we can craft custom responses).
    """

    def __init__(self, query_handler: Optional[QueryHandler] = None,
                 timeout: float = DEFAULT_TIMEOUT):
        self.query_handler = query_handler
        self.timeout = timeout
        self.transport: Optional[asyncio.DatagramTransport] = None
        # Pending queries keyed by transaction id.  Each entry pairs the
        # awaiting future with the (ip, port) the query was sent to, so an
        # inbound response can be validated against its query's destination.
        self._pending: Dict[bytes, Tuple[asyncio.Future, Tuple[str, int]]] = {}
        self.loop = asyncio.get_running_loop()
        # Simple counters for observability / tests.  ``spoofed`` counts
        # datagrams whose txn matched a pending query but whose source address
        # did not -- i.e. off-path response-injection attempts.
        self.stats = {"tx_query": 0, "rx_query": 0, "rx_response": 0,
                      "rx_error": 0, "timeouts": 0, "dropped": 0, "spoofed": 0}

    # -- asyncio callbacks --------------------------------------------------
    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.transport = transport  # type: ignore[assignment]

    def datagram_received(self, data: bytes, addr: Tuple[str, int]) -> None:
        try:
            msg = parse_message(data)
        except (BencodeError, ValueError):
            self.stats["dropped"] += 1
            return
        if msg.kind == "query":
            self.stats["rx_query"] += 1
            self._handle_query(msg, addr)
        elif msg.kind == "response":
            self.stats["rx_response"] += 1
            fut = self._match(msg.txn, addr)
            if fut is not None and not fut.done():
                fut.set_result((msg.response, addr))
        elif msg.kind == "error":
            self.stats["rx_error"] += 1
            fut = self._match(msg.txn, addr)
            if fut is not None and not fut.done():
                fut.set_exception(KRPCError(*msg.error))

    def _match(self, txn: bytes, addr: Tuple[str, int]) -> Optional[asyncio.Future]:
        """Resolve *txn* to its pending future, enforcing source-address match.

        A response/error is accepted only from the exact (ip, port) the query
        was sent to.  A datagram bearing a valid pending txn but arriving from
        any other source is an off-path injection attempt -- an attacker who
        guessed the (now random) txn trying to forge a ``find_node`` /
        ``get_peers`` / ``sample_infohashes`` reply to poison our routing table
        or fetch queue.  Such a datagram is dropped and the query is left
        pending for the genuine reply.  Returns the popped future on a match,
        else ``None``.
        """
        entry = self._pending.get(txn)
        if entry is None:
            return None
        fut, dest = entry
        if addr != dest:
            self.stats["dropped"] += 1
            self.stats["spoofed"] += 1
            return None
        self._pending.pop(txn, None)
        return fut

    def error_received(self, exc: Exception) -> None:  # pragma: no cover
        # ICMP port-unreachable etc. Non-fatal for a DHT node.
        pass

    # -- query dispatch -----------------------------------------------------
    def _handle_query(self, msg: KRPCMessage, addr: Tuple[str, int]) -> None:
        if self.query_handler is None:
            return
        try:
            response = self.query_handler(msg.method, msg.args, addr)
        except KRPCError as exc:
            self.send_raw(encode_error(msg.txn, exc.code, exc.message), addr)
            return
        except Exception:
            self.send_raw(encode_error(msg.txn, ERR_SERVER, "internal error"), addr)
            return
        if response is not None:
            self.send_raw(encode_response(msg.txn, response), addr)

    # -- outbound -----------------------------------------------------------
    def _next_txn(self) -> bytes:
        """A fresh, unpredictable 2-byte transaction id.

        Transaction ids must be hard to guess: a predictable (incrementing)
        counter lets an off-path attacker anticipate the txn and forge a
        plausible response before the genuine one arrives.  We draw a
        cryptographically-random 2-byte id and regenerate on the small chance
        of colliding with an already-pending query (bounded attempts -- the
        16-bit space dwarfs the realistic in-flight set).
        """
        for _ in range(16):
            txn = os.urandom(2)
            if txn not in self._pending:
                return txn
        # Pathologically full pending map (never reached in practice): signal
        # backpressure rather than clobber an outstanding query's txn.
        raise RuntimeError("KRPC transaction id space exhausted")

    def send_raw(self, data: bytes, addr: Tuple[str, int]) -> None:
        if self.transport is not None:
            self.transport.sendto(data, addr)

    async def query(self, method: str, args: Dict[bytes | str, Any],
                    addr: Tuple[str, int],
                    timeout: Optional[float] = None) -> dict:
        """Send a query and await the response dict (``r``)."""
        txn = self._next_txn()
        fut: asyncio.Future = self.loop.create_future()
        # Record the destination alongside the future so datagram_received can
        # reject a response spoofed from any other source (injection defence).
        self._pending[txn] = (fut, addr)
        self.stats["tx_query"] += 1
        self.send_raw(encode_query(txn, method, args), addr)
        try:
            response, _ = await asyncio.wait_for(fut, timeout or self.timeout)
            return response
        except asyncio.TimeoutError:
            self.stats["timeouts"] += 1
            self._pending.pop(txn, None)
            raise
        finally:
            self._pending.pop(txn, None)
