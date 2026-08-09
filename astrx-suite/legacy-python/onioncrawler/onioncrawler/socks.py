"""Hand-rolled SOCKS5 (RFC 1928) CONNECT with optional username/password
auth (RFC 1929), in pure socket code.

Design notes
------------
* The *encoding* is factored into pure functions (build_greeting,
  build_userpass_auth, build_connect_request) so the exact RFC1928 byte layout
  can be unit-tested without a socket.
* Hostnames are sent as ATYP=0x03 (DOMAINNAME) so the SOCKS proxy (Tor) does
  the DNS resolution.  We NEVER resolve .onion locally - that is both the only
  way .onion works and a hard anti-leak requirement.
* username/password can be used for Tor stream isolation (a distinct
  user/pass => a distinct circuit), e.g. one circuit per host.
"""

from __future__ import annotations

import socket
import struct

VER = 0x05
RSV = 0x00

# auth methods
M_NOAUTH = 0x00
M_USERPASS = 0x02
M_NONE_ACCEPTABLE = 0xFF

# commands
CMD_CONNECT = 0x01

# address types
ATYP_IPV4 = 0x01
ATYP_DOMAIN = 0x03
ATYP_IPV6 = 0x04

# reply codes (RFC 1928 sec 6)
REPLY_TEXT = {
    0x00: "succeeded",
    0x01: "general SOCKS server failure",
    0x02: "connection not allowed by ruleset",
    0x03: "network unreachable",
    0x04: "host unreachable",
    0x05: "connection refused",
    0x06: "TTL expired",
    0x07: "command not supported",
    0x08: "address type not supported",
}


class SocksError(Exception):
    pass


# --------------------------------------------------------------------------
# Pure encoders (unit-tested against the RFC layout)
# --------------------------------------------------------------------------
def build_greeting(use_userpass: bool) -> bytes:
    """Client greeting: VER, NMETHODS, METHODS..."""
    methods = [M_NOAUTH]
    if use_userpass:
        methods = [M_USERPASS, M_NOAUTH]
    return bytes([VER, len(methods)]) + bytes(methods)


def build_userpass_auth(username: str, password: str) -> bytes:
    """RFC 1929 sub-negotiation: VER(1), ULEN, UNAME, PLEN, PASSWD."""
    u = username.encode("utf-8")
    p = password.encode("utf-8")
    if len(u) > 255 or len(p) > 255:
        raise SocksError("socks username/password too long (max 255 bytes)")
    return bytes([0x01, len(u)]) + u + bytes([len(p)]) + p


def build_connect_request(host: str, port: int) -> bytes:
    """CONNECT request with a DOMAINNAME target (remote resolution).

    Layout: VER, CMD, RSV, ATYP=0x03, LEN, HOST..., PORT(2, big-endian)
    """
    hb = host.encode("idna") if _needs_idna(host) else host.encode("ascii")
    if len(hb) > 255:
        raise SocksError("socks hostname too long (max 255 bytes)")
    if not (0 < port < 65536):
        raise SocksError(f"invalid port {port}")
    return (
        bytes([VER, CMD_CONNECT, RSV, ATYP_DOMAIN, len(hb)])
        + hb
        + struct.pack("!H", port)
    )


def _needs_idna(host: str) -> bool:
    try:
        host.encode("ascii")
        return False
    except UnicodeEncodeError:
        return True


# --------------------------------------------------------------------------
# Socket I/O
# --------------------------------------------------------------------------
def _recv_exact(sock: socket.socket, n: int) -> bytes:
    buf = bytearray()
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise SocksError("socks proxy closed connection early")
        buf.extend(chunk)
    return bytes(buf)


def _read_bind_address(sock: socket.socket) -> None:
    """Consume the BND.ADDR/BND.PORT tail of a reply (value unused)."""
    atyp = _recv_exact(sock, 1)[0]
    if atyp == ATYP_IPV4:
        _recv_exact(sock, 4)
    elif atyp == ATYP_IPV6:
        _recv_exact(sock, 16)
    elif atyp == ATYP_DOMAIN:
        ln = _recv_exact(sock, 1)[0]
        _recv_exact(sock, ln)
    else:
        raise SocksError(f"unknown ATYP in reply: {atyp}")
    _recv_exact(sock, 2)  # port


def socks5_connect(
    proxy_host: str,
    proxy_port: int,
    dest_host: str,
    dest_port: int,
    username: str | None = None,
    password: str | None = None,
    timeout: float = 60.0,
) -> socket.socket:
    """Open a TCP socket to *proxy* and perform a SOCKS5 CONNECT to
    (*dest_host*, *dest_port*) with remote name resolution. Returns the
    connected socket (now a transparent tunnel) or raises SocksError.
    """
    use_userpass = bool(username) and password is not None
    sock = socket.create_connection((proxy_host, proxy_port), timeout=timeout)
    try:
        sock.settimeout(timeout)
        # 1) greeting
        sock.sendall(build_greeting(use_userpass))
        resp = _recv_exact(sock, 2)
        if resp[0] != VER:
            raise SocksError(f"bad SOCKS version in method reply: {resp[0]}")
        method = resp[1]
        if method == M_NONE_ACCEPTABLE:
            raise SocksError("no acceptable SOCKS auth method")
        # 2) auth
        if method == M_USERPASS:
            if not use_userpass:
                raise SocksError("proxy demands user/pass but none provided")
            sock.sendall(build_userpass_auth(username, password or ""))
            ar = _recv_exact(sock, 2)
            if ar[1] != 0x00:
                raise SocksError("SOCKS username/password auth failed")
        elif method != M_NOAUTH:
            raise SocksError(f"unsupported SOCKS method selected: {method}")
        # 3) connect
        sock.sendall(build_connect_request(dest_host, dest_port))
        rep = _recv_exact(sock, 3)  # VER, REP, RSV
        if rep[0] != VER:
            raise SocksError(f"bad SOCKS version in connect reply: {rep[0]}")
        if rep[1] != 0x00:
            raise SocksError(
                "SOCKS connect failed: "
                + REPLY_TEXT.get(rep[1], f"code {rep[1]}")
            )
        _read_bind_address(sock)
        return sock
    except Exception:
        try:
            sock.close()
        finally:
            pass
        raise
