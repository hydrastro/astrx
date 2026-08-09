"""I2P HTTP-proxy helpers for reaching .i2p eepsites via a local I2P router's
HTTP proxy (default 127.0.0.1:4444).

Two transport shapes, analogous to the SOCKS module:
  * plain http eepsite  -> send an *absolute-form* request line to the proxy
                           (``GET http://site.i2p/path HTTP/1.1``); the proxy
                           forwards it into the I2P network. No CONNECT.
  * https eepsite       -> issue an HTTP ``CONNECT site.i2p:port`` to the proxy,
                           then run TLS over the returned tunnel (origin-form).

The *encoders* here are pure functions so the exact byte layout is unit-testable
without a socket (like socks.build_connect_request). The eepsite name is sent to
the proxy verbatim -- we NEVER resolve .i2p locally, which is both how I2P works
and an anti-leak requirement (the address never becomes an IP on this host).
"""

from __future__ import annotations

import socket


class I2PError(Exception):
    pass


def build_http_connect(host: str, port: int) -> bytes:
    """Encode an HTTP CONNECT request for the I2P HTTP proxy (used for https
    eepsites). Layout: ``CONNECT host:port HTTP/1.1`` + Host + blank line."""
    if not (0 < port < 65536):
        raise I2PError(f"invalid port {port}")
    if not host or any(c in host for c in " \r\n"):
        raise I2PError(f"invalid host {host!r}")
    hp = f"{host}:{port}"
    return (
        f"CONNECT {hp} HTTP/1.1\r\n"
        f"Host: {hp}\r\n"
        f"User-Agent: OnionCrawler-I2P/1.0\r\n"
        f"Proxy-Connection: close\r\n\r\n"
    ).encode("ascii")


def build_proxy_get_target(scheme: str, host: str, port: int, path: str) -> str:
    """The request-line target sent to the HTTP proxy for a plain-http eepsite:
    the *absolute* URL (origin-form is only valid after a CONNECT tunnel)."""
    default = 443 if scheme == "https" else 80
    hostport = host if (port is None or port == default) else f"{host}:{port}"
    if not path:
        path = "/"
    return f"{scheme}://{hostport}{path}"


def read_connect_reply(sock: socket.socket, max_head: int = 8192) -> None:
    """Read the proxy's CONNECT reply headers and raise unless it is a 2xx.
    Consumes exactly up to the terminating CRLFCRLF."""
    buf = bytearray()
    while b"\r\n\r\n" not in buf:
        chunk = sock.recv(1024)
        if not chunk:
            raise I2PError("i2p proxy closed connection during CONNECT")
        buf.extend(chunk)
        if len(buf) > max_head:
            raise I2PError("i2p proxy CONNECT reply too large")
    line = bytes(buf).split(b"\r\n", 1)[0].decode("iso-8859-1")
    parts = line.split(" ", 2)
    if len(parts) < 2 or not parts[0].upper().startswith("HTTP/"):
        raise I2PError(f"bad i2p proxy CONNECT reply: {line!r}")
    try:
        code = int(parts[1])
    except ValueError as e:
        raise I2PError(f"bad i2p proxy status: {line!r}") from e
    if not (200 <= code < 300):
        raise I2PError(f"i2p proxy CONNECT failed: {line!r}")
