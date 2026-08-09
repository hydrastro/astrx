"""A minimal HTTP/1.1 client that runs over any already-connected socket.

Supports: GET, request headers, chunked + content-length bodies, connection
close bodies, gzip/deflate decompression (stdlib), and a hard cap on the
number of body bytes read (defends against tarpit / huge-response traps).

Redirects are handled one hop at a time by the caller (fetcher.py), because a
redirect to a new host needs a fresh tunnel.
"""

from __future__ import annotations

import zlib
import socket

CRLF = b"\r\n"
HEADER_END = b"\r\n\r\n"


class HttpError(Exception):
    pass


class ResponseTooLarge(HttpError):
    pass


class HttpResponse:
    __slots__ = ("status", "reason", "headers", "body", "truncated", "reusable")

    def __init__(self, status, reason, headers, body, truncated=False,
                 reusable=False):
        self.status = status
        self.reason = reason
        self.headers = headers  # dict, lowercased keys
        self.body = body        # bytes (decompressed)
        self.truncated = truncated
        # True iff this connection can be safely reused for another request
        # (HTTP/1.1, no Connection: close, whole body framed + fully drained).
        self.reusable = reusable

    def header(self, name, default=None):
        return self.headers.get(name.lower(), default)


class _SockReader:
    """Buffered reader with a global byte budget over a socket."""

    def __init__(self, sock: socket.socket, max_bytes: int):
        self.sock = sock
        self.buf = bytearray()
        self.max_bytes = max_bytes
        self.total = 0  # counts *raw* bytes pulled off the wire
        self.eof = False

    def _fill(self, hint: int = 65536):
        if self.eof:
            return 0
        chunk = self.sock.recv(hint)
        if not chunk:
            self.eof = True
            return 0
        self.total += len(chunk)
        if self.total > self.max_bytes:
            raise ResponseTooLarge(f"response exceeded {self.max_bytes} bytes")
        self.buf.extend(chunk)
        return len(chunk)

    def read_until(self, sep: bytes) -> bytes:
        while True:
            idx = self.buf.find(sep)
            if idx != -1:
                out = bytes(self.buf[: idx + len(sep)])
                del self.buf[: idx + len(sep)]
                return out
            if self._fill() == 0:
                raise HttpError("connection closed before delimiter")

    def read_n(self, n: int) -> bytes:
        while len(self.buf) < n:
            if self._fill() == 0:
                raise HttpError("connection closed before %d bytes" % n)
        out = bytes(self.buf[:n])
        del self.buf[:n]
        return out

    def read_all(self, cap: int) -> bytes:
        while not self.eof:
            if self._fill() == 0:
                break
            if len(self.buf) > cap:
                break
        return bytes(self.buf[:cap])


def _parse_status_line(line: bytes):
    try:
        parts = line.decode("iso-8859-1").rstrip("\r\n").split(" ", 2)
        version = parts[0]
        status = int(parts[1])
        reason = parts[2] if len(parts) > 2 else ""
    except Exception as e:
        raise HttpError(f"bad status line: {line!r}") from e
    if not version.upper().startswith("HTTP/"):
        raise HttpError(f"not an HTTP response: {line!r}")
    return version, status, reason


def _parse_headers(block: bytes) -> dict:
    headers: dict[str, str] = {}
    text = block.decode("iso-8859-1")
    for raw in text.split("\r\n"):
        if not raw or ":" not in raw:
            continue
        k, v = raw.split(":", 1)
        k = k.strip().lower()
        v = v.strip()
        if k in headers:
            headers[k] = headers[k] + ", " + v
        else:
            headers[k] = v
    return headers


def _inflate(body: bytes, wbits: int, max_bytes: int):
    """Incrementally inflate *body*, capping DECOMPRESSED output at *max_bytes*.

    Defeats decompression bombs: a small compressed body can no longer expand
    without bound. Returns (data, truncated); truncated=True means the stream
    was longer than max_bytes and the excess was discarded.
    """
    dobj = zlib.decompressobj(wbits)
    out = bytearray()
    limit = max_bytes + 1  # +1 so we can detect "exceeded" without a full read
    # First feed of the whole compressed input; max_length bounds the output and
    # parks any input we couldn't expand (would exceed the cap) in unconsumed_tail.
    out.extend(dobj.decompress(body, limit))
    while dobj.unconsumed_tail:
        if len(out) > max_bytes:
            return bytes(out[:max_bytes]), True
        out.extend(dobj.decompress(dobj.unconsumed_tail, limit - len(out)))
    if len(out) > max_bytes:
        return bytes(out[:max_bytes]), True
    # All input consumed; flush is bounded (nothing left to expand) but re-check.
    out.extend(dobj.flush())
    if len(out) > max_bytes:
        return bytes(out[:max_bytes]), True
    return bytes(out), False


def _decompress(body: bytes, encoding: str, max_bytes: int):
    """Return (decompressed_body, truncated). The output is hard-capped at
    *max_bytes* so a compression bomb cannot exhaust memory."""
    enc = (encoding or "").lower().strip()
    if not enc or enc == "identity":
        return body, False
    try:
        if enc == "gzip" or enc == "x-gzip":
            return _inflate(body, 16 + zlib.MAX_WBITS, max_bytes)
        if enc == "deflate":
            # try raw deflate, then zlib-wrapped
            try:
                return _inflate(body, -zlib.MAX_WBITS, max_bytes)
            except zlib.error:
                return _inflate(body, zlib.MAX_WBITS, max_bytes)
    except Exception as e:
        raise HttpError(f"failed to decompress {enc!r}: {e}") from e
    # unknown encoding: return as-is rather than crash
    return body, False


def build_request(method: str, path: str, host: str, extra_headers: dict) -> bytes:
    if not path:
        path = "/"
    lines = [f"{method} {path} HTTP/1.1", f"Host: {host}"]
    for k, v in extra_headers.items():
        lines.append(f"{k}: {v}")
    lines.append("")
    lines.append("")
    return ("\r\n".join(lines)).encode("iso-8859-1")


def perform_request(
    sock: socket.socket,
    method: str,
    host: str,
    path: str,
    headers: dict,
    max_bytes: int,
) -> HttpResponse:
    """Send one request on *sock* and read one response. Caller owns the
    socket lifecycle."""
    req = build_request(method, path, host, headers)
    sock.sendall(req)

    reader = _SockReader(sock, max_bytes)
    head = reader.read_until(HEADER_END)
    # split status line from header block
    first, _, rest = head.partition(CRLF)
    version, status, reason = _parse_status_line(first)
    header_block = rest[: -len(CRLF)] if rest.endswith(HEADER_END[:-2]) else rest
    hdrs = _parse_headers(header_block.rstrip(b"\r\n"))

    # A connection can be reused only when HTTP/1.1 and the peer did not ask to
    # close, AND we framed + fully drained the body (below).
    conn = hdrs.get("connection", "").lower()
    keep_alive = version.upper() == "HTTP/1.1" and "close" not in conn

    body = b""
    truncated = False

    # Bodyless responses (no body to drain -> reusable if keep-alive)
    if method == "HEAD" or status in (204, 304) or (100 <= status < 200):
        return HttpResponse(status, reason, hdrs, b"", False, reusable=keep_alive)

    te = hdrs.get("transfer-encoding", "").lower()
    framed = False
    try:
        if "chunked" in te:
            body, truncated = _read_chunked(reader, max_bytes)
            framed = True
        elif "content-length" in hdrs:
            try:
                length = int(hdrs["content-length"])
            except ValueError:
                raise HttpError("invalid content-length")
            if length > max_bytes:
                truncated = True
                length = max_bytes
            body = reader.read_n(length)
            framed = True
        else:
            # read until close, bounded -> the peer closes, never reusable
            body = reader.read_all(max_bytes)
            truncated = reader.total > max_bytes or not reader.eof
    except ResponseTooLarge as e:
        # The body blew the read cap. Carry the already-parsed status +
        # content-type on the exception so the fetcher/crawler can tell whether
        # an oversized resource was media (and re-fetch it at the media cap)
        # without ever keeping the bytes. Header phase raises still carry none.
        e.status = status
        e.content_type = hdrs.get("content-type", "")
        raise

    body, dec_truncated = _decompress(
        body, hdrs.get("content-encoding", ""), max_bytes)
    truncated = truncated or dec_truncated
    # only reuse a fully-drained, framed, untruncated keep-alive connection
    reusable = keep_alive and framed and not truncated
    return HttpResponse(status, reason, hdrs, body, truncated, reusable=reusable)


def _read_chunked(reader: _SockReader, max_bytes: int):
    out = bytearray()
    truncated = False
    while True:
        size_line = reader.read_until(CRLF).strip()
        # chunk-ext after ';'
        size_hex = size_line.split(b";", 1)[0].strip()
        try:
            size = int(size_hex, 16)
        except ValueError:
            raise HttpError(f"bad chunk size: {size_line!r}")
        if size == 0:
            # consume trailer headers up to blank line
            reader.read_until(CRLF)
            break
        data = reader.read_n(size)
        reader.read_n(2)  # trailing CRLF
        if len(out) < max_bytes:
            out.extend(data)
            if len(out) >= max_bytes:
                truncated = True
                out = out[:max_bytes]
        else:
            truncated = True
    return bytes(out), truncated
