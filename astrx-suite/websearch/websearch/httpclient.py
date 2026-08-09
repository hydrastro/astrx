"""A minimal but real HTTP/1.1 fetcher for the crawler.

Uses :mod:`http.client` directly so the crawler stays in control of redirects
(each hop is re-checked against robots + scope through the ``allow`` callback),
politeness and byte budgets.  Handles gzip/deflate, timeouts, a capped redirect
chain and a maximum response size.
"""

import ipaddress
import socket
import ssl
import threading
import time
import zlib
from http.client import HTTPConnection, HTTPSConnection
from urllib.parse import urlsplit

from . import canonical

DEFAULT_UA = "astrx-websearch/1.0 (+https://example.invalid/bot)"
_REDIRECT_CODES = {301, 302, 303, 307, 308}

# ---- DNS cache -------------------------------------------------------------
# A small TTL cache over getaddrinfo so a multi-worker crawl does not re-resolve
# the same host on every fetch.  IMPORTANT: only the *resolution* is cached; the
# internal-IP SSRF validation still runs on the cached addresses on EVERY call
# (see _resolve_checked), so caching cannot smuggle an internal address past the
# denylist -- and pinning to a cached, already-validated address is, if anything,
# stronger against DNS rebinding within the TTL.
DNS_TTL = 300.0
DNS_CACHE_MAX = 4096         # hard bound so a broad crawl cannot leak memory
_DNS_CACHE = {}
_DNS_LOCK = threading.Lock()


def _getaddrinfo_cached(host, port):
    key = (host, port)
    now = time.monotonic()
    with _DNS_LOCK:
        ent = _DNS_CACHE.get(key)
        if ent is not None and ent[0] > now:
            return ent[1]
    infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    with _DNS_LOCK:
        # Bound the cache: purge expired entries first, then (if still full of
        # live ones) drop it wholesale.  Eviction is always safe -- the SSRF
        # denylist re-runs on every resolve (see _resolve_checked), so a purged
        # host is simply re-resolved and re-validated, never trusted stale.
        if len(_DNS_CACHE) >= DNS_CACHE_MAX and key not in _DNS_CACHE:
            for k in [k for k, v in _DNS_CACHE.items() if v[0] <= now]:
                del _DNS_CACHE[k]
            if len(_DNS_CACHE) >= DNS_CACHE_MAX:
                _DNS_CACHE.clear()
        _DNS_CACHE[key] = (now + DNS_TTL, infos)
    return infos


def clear_dns_cache():
    """Drop all cached DNS entries (used by tests and long-running processes)."""
    with _DNS_LOCK:
        _DNS_CACHE.clear()


class _Blocked(Exception):
    """A host resolved to an internal/forbidden address (SSRF guard)."""


def _ip_is_internal(ip_str):
    """True if *ip_str* is loopback / private / link-local / reserved /
    multicast / unspecified (incl. IPv4-mapped IPv6 and the ``169.254.169.254``
    cloud-metadata address).  Unparseable -> treated as internal (fail closed)."""
    try:
        ip = ipaddress.ip_address(ip_str)
    except ValueError:
        return True
    if ip.version == 6 and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    return (
        ip.is_loopback or ip.is_private or ip.is_link_local
        or ip.is_reserved or ip.is_multicast or ip.is_unspecified
    )


def _authority_exempt(host, port, allow_hosts):
    """True if this host[:port] was explicitly allow-listed for internal use."""
    if not allow_hosts:
        return False
    h = (host or "").lower()
    forms = {h, "%s:%d" % (h, port)}
    if ":" in h:  # IPv6 literals may be stored bracketed
        forms |= {"[%s]" % h, "[%s]:%d" % (h, port)}
    return bool(forms & {x.lower() for x in allow_hosts})


def _resolve_checked(host, port, block_internal, allow_hosts):
    """Resolve *host*:*port*; refuse if ANY resolved address is internal.

    Returns a list of ``(family, sockaddr)`` for a *pinned* connect, so the
    socket goes to a validated address and DNS rebinding cannot swap in an
    internal IP between the check and the connect.
    """
    infos = _getaddrinfo_cached(host, port)
    if block_internal and not _authority_exempt(host, port, allow_hosts):
        for _family, _t, _p, _c, sockaddr in infos:
            if _ip_is_internal(sockaddr[0]):
                raise _Blocked(sockaddr[0])
    return [(family, sockaddr) for family, _t, _p, _c, sockaddr in infos]


class FetchResult:
    __slots__ = ("url", "final_url", "status", "headers", "body",
                 "content_type", "charset", "error", "truncated", "redirects")

    def __init__(self, url, final_url, status, headers, body, content_type,
                 charset, error=None, truncated=False, redirects=0):
        self.url = url
        self.final_url = final_url
        self.status = status
        self.headers = headers or {}
        self.body = body or b""
        self.content_type = content_type
        self.charset = charset
        self.error = error
        self.truncated = truncated
        self.redirects = redirects

    @property
    def ok(self):
        return self.error is None and self.status == 200


def _decompress(raw, enc, max_bytes):
    """Decompress *raw* with a hard output cap of ``max_bytes`` (+1 sentinel).

    Uses an *incremental* decompressor and stops as soon as the cap is reached
    (leaving the rest in ``unconsumed_tail``), so a compression bomb can never
    materialise more than ~``max_bytes`` bytes in memory.  Never one-shot
    ``gzip.decompress`` the whole stream.
    """
    limit = max_bytes + 1
    if enc == "gzip":
        wbits_opts = (16 + zlib.MAX_WBITS,)
    elif enc in ("deflate", "zlib"):
        wbits_opts = (zlib.MAX_WBITS, -zlib.MAX_WBITS)
    else:  # identity / unknown: the wire read is already capped upstream
        return raw
    for wbits in wbits_opts:
        try:
            return zlib.decompressobj(wbits).decompress(raw, limit)
        except zlib.error:
            continue
    return raw


def _parse_content_type(value):
    parts = value.split(";")
    ctype = parts[0].strip().lower()
    charset = None
    for p in parts[1:]:
        p = p.strip()
        if p.lower().startswith("charset="):
            charset = p.split("=", 1)[1].strip().strip('"').strip("'").lower()
    return ctype, charset


def _one(url, user_agent, timeout, max_bytes, accept_encoding, ctx,
         block_internal=True, allow_hosts=None, extra_headers=None):
    """Perform a single (non-redirecting) GET.  Returns a dict or an error.

    Resolves the host up front, refuses any resolution to an internal address
    (SSRF guard) unless the authority is explicitly allow-listed, and connects
    to the *validated* address so DNS rebinding cannot swap in an internal IP.
    """
    s = urlsplit(url)
    host = s.hostname
    if not host:
        return {"error": "no-host"}
    try:
        port = s.port or (443 if s.scheme == "https" else 80)
    except ValueError:
        return {"error": "bad-port"}
    try:
        addrs = _resolve_checked(host, port, block_internal, allow_hosts)
    except _Blocked as exc:
        return {"error": "blocked-internal:%s" % exc}
    except (socket.gaierror, OSError) as exc:
        return {"error": "dns:%s" % exc}

    raw_sock = None
    last_err = None
    for family, sockaddr in addrs:
        try:
            raw_sock = socket.socket(family, socket.SOCK_STREAM)
            raw_sock.settimeout(timeout)
            raw_sock.connect(sockaddr)
            break
        except OSError as exc:
            last_err = exc
            if raw_sock is not None:
                raw_sock.close()
            raw_sock = None
    if raw_sock is None:
        return {"error": "connect:%s" % last_err}

    if s.scheme == "https":
        conn = HTTPSConnection(host, port, timeout=timeout, context=ctx)
        try:
            conn.sock = ctx.wrap_socket(raw_sock, server_hostname=host)
        except Exception as exc:  # noqa: BLE001 - TLS failure -> report upward
            raw_sock.close()
            return {"error": "tls:%s" % exc}
    else:
        conn = HTTPConnection(host, port, timeout=timeout)
        conn.sock = raw_sock
    path = s.path or "/"
    if s.query:
        path += "?" + s.query
    headers = {
        "Host": s.netloc,
        "User-Agent": user_agent,
        "Accept": "text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1",
        "Accept-Encoding": accept_encoding,
        "Connection": "close",
    }
    for k, v in (extra_headers or {}).items():
        if v:                       # e.g. If-None-Match / If-Modified-Since
            headers[k] = v
    try:
        conn.request("GET", path, headers=headers)
        resp = conn.getresponse()
        status = resp.status
        hdrs = {}
        for k, v in resp.getheaders():
            hdrs[k.lower()] = v
        enc = hdrs.get("content-encoding", "").lower().strip()
        wire_cap = (max_bytes + 1) if enc in ("", "identity") else max_bytes * 20 + 1
        raw = resp.read(wire_cap)
    except Exception as exc:  # noqa: BLE001 - report any network error upward
        try:
            conn.close()
        except Exception:
            pass
        return {"error": "%s: %s" % (type(exc).__name__, exc)}
    finally:
        try:
            conn.close()
        except Exception:
            pass

    body = _decompress(raw, enc, max_bytes)
    truncated = len(body) > max_bytes
    if truncated:
        body = body[:max_bytes]
    ctype, charset = _parse_content_type(hdrs.get("content-type", ""))
    return {
        "status": status, "headers": hdrs, "body": body,
        "content_type": ctype, "charset": charset, "truncated": truncated,
    }


def fetch(url, user_agent=DEFAULT_UA, timeout=10.0, max_bytes=2_000_000,
          max_redirects=5, allow=None, accept_encoding="gzip, deflate",
          verify_tls=True, block_internal=True, allow_hosts=None,
          extra_headers=None):
    """Fetch *url*, following up to *max_redirects* redirects.

    ``allow(url)`` (if given) is consulted for the initial URL and every
    redirect target; a target that fails the check stops the chain and the 3xx
    response is returned as-is (so the caller can record it as skipped).

    ``extra_headers`` (e.g. ``If-None-Match`` / ``If-Modified-Since`` for a
    conditional GET) are sent on the *initial* request only; a ``304 Not
    Modified`` is returned to the caller as-is (status 304, empty body).

    When ``block_internal`` is true (the default) the initial connect *and every
    redirect hop* refuse hosts that resolve to internal addresses, unless the
    host[:port] is present in ``allow_hosts``.
    """
    ctx = None
    if verify_tls:
        ctx = ssl.create_default_context()
    else:  # pragma: no cover - not used in the offline test suite
        ctx = ssl._create_unverified_context()

    seen = set()
    current = url
    redirects = 0
    while True:
        if allow is not None and not allow(current):
            return FetchResult(url, current, 0, {}, b"", None, None,
                               error="blocked", redirects=redirects)
        res = _one(current, user_agent, timeout, max_bytes, accept_encoding, ctx,
                   block_internal=block_internal, allow_hosts=allow_hosts,
                   extra_headers=extra_headers if redirects == 0 else None)
        if "error" in res:
            return FetchResult(url, current, 0, {}, b"", None, None,
                               error=res["error"], redirects=redirects)
        status = res["status"]
        if status in _REDIRECT_CODES and redirects < max_redirects:
            loc = res["headers"].get("location")
            target = canonical.canonicalize(loc, base=current) if loc else None
            if not target or target in seen:
                # Broken or looping redirect -> hand back what we have.
                return FetchResult(url, current, status, res["headers"],
                                   res["body"], res["content_type"],
                                   res["charset"], redirects=redirects)
            seen.add(target)
            current = target
            redirects += 1
            continue
        return FetchResult(url, current, status, res["headers"], res["body"],
                           res["content_type"], res["charset"],
                           truncated=res["truncated"], redirects=redirects)


class Fetcher:
    """A keep-alive-capable fetcher with the *same* SSRF guarantees as :func:`fetch`.

    Maintains a per-instance pool of idle keep-alive connections keyed by
    ``(scheme, host, port)``.  A pooled connection is only reused after its
    pinned address is re-checked against the internal-IP denylist, and every
    fresh connect (and every redirect hop) still goes through
    :func:`_resolve_checked`.  Not shared across threads -- give each crawl
    worker its own :class:`Fetcher`.
    """

    def __init__(self, verify_tls=True, keep_alive=True):
        if verify_tls:
            self._ctx = ssl.create_default_context()
        else:  # pragma: no cover - not used offline
            self._ctx = ssl._create_unverified_context()
        self.keep_alive = keep_alive
        self._pool = {}          # key -> (connection, pinned_ip)
        self.opened = 0          # new sockets opened (observability/tests)
        self.reused = 0          # pooled connections reused

    def close(self):
        for conn, _ip in self._pool.values():
            try:
                conn.close()
            except Exception:
                pass
        self._pool.clear()

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()

    def _acquire(self, key, s, host, port, timeout, block_internal, allow_hosts):
        """Obtain a connection for *key*, returning ``(conn, pinned, reused, err)``.

        Reuses a pooled keep-alive connection ONLY after re-checking its pinned
        address against the internal-IP denylist; otherwise opens a fresh socket
        through :func:`_resolve_checked` (so every new connect is SSRF-validated
        and pinned to the checked address).  ``err`` is a non-``None`` error dict
        when no connection could be obtained.
        """
        pooled = self._pool.pop(key, None)
        if pooled is not None:
            pconn, pip = pooled
            exempt = _authority_exempt(host, port, allow_hosts)
            if block_internal and not exempt and _ip_is_internal(pip):
                try:
                    pconn.close()          # policy changed under us -> drop it
                except Exception:
                    pass
            else:
                self.reused += 1
                return pconn, pip, True, None

        try:
            addrs = _resolve_checked(host, port, block_internal, allow_hosts)
        except _Blocked as exc:
            return None, None, False, {"error": "blocked-internal:%s" % exc}
        except (socket.gaierror, OSError) as exc:
            return None, None, False, {"error": "dns:%s" % exc}
        raw_sock = None
        last_err = None
        pinned = None
        for family, sockaddr in addrs:
            try:
                raw_sock = socket.socket(family, socket.SOCK_STREAM)
                raw_sock.settimeout(timeout)
                raw_sock.connect(sockaddr)
                pinned = sockaddr[0]
                break
            except OSError as exc:
                last_err = exc
                if raw_sock is not None:
                    raw_sock.close()
                raw_sock = None
        if raw_sock is None:
            return None, None, False, {"error": "connect:%s" % last_err}
        self.opened += 1
        if s.scheme == "https":
            conn = HTTPSConnection(host, port, timeout=timeout, context=self._ctx)
            try:
                conn.sock = self._ctx.wrap_socket(raw_sock, server_hostname=host)
            except Exception as exc:
                raw_sock.close()
                return None, None, False, {"error": "tls:%s" % exc}
        else:
            conn = HTTPConnection(host, port, timeout=timeout)
            conn.sock = raw_sock
        return conn, pinned, False, None

    def _one(self, url, user_agent, timeout, max_bytes, accept_encoding,
             block_internal, allow_hosts, extra_headers):
        s = urlsplit(url)
        host = s.hostname
        if not host:
            return {"error": "no-host"}
        try:
            port = s.port or (443 if s.scheme == "https" else 80)
        except ValueError:
            return {"error": "bad-port"}
        key = (s.scheme, host, port)

        path = s.path or "/"
        if s.query:
            path += "?" + s.query
        headers = {
            "Host": s.netloc,
            "User-Agent": user_agent,
            "Accept": "text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1",
            "Accept-Encoding": accept_encoding,
            "Connection": "keep-alive" if self.keep_alive else "close",
        }
        for k, v in (extra_headers or {}).items():
            if v:
                headers[k] = v

        # At most two attempts: a POOLED connection can be closed by the peer
        # while idle, so an idempotent GET is retried ONCE on a fresh, re-resolved
        # (and re-validated) connection.  The retry still goes through _acquire ->
        # _resolve_checked, so the SSRF denylist holds on the second try too.
        last_exc = None
        for attempt in (0, 1):
            conn, pinned, reused, err = self._acquire(
                key, s, host, port, timeout, block_internal, allow_hosts)
            if err is not None:
                return err
            try:
                conn.request("GET", path, headers=headers)
                resp = conn.getresponse()
                status = resp.status
                hdrs = {k.lower(): v for k, v in resp.getheaders()}
                enc = hdrs.get("content-encoding", "").lower().strip()
                wire_cap = (max_bytes + 1) if enc in ("", "identity") \
                    else max_bytes * 20 + 1
                raw = resp.read(wire_cap)
            except Exception as exc:
                try:
                    conn.close()
                except Exception:
                    pass
                # Only a stale POOLED connection is worth retrying; a fresh
                # connection that fails is a real error (and never retried twice).
                if reused and attempt == 0:
                    last_exc = exc
                    continue
                return {"error": "%s: %s" % (type(exc).__name__, exc)}

            body = _decompress(raw, enc, max_bytes)
            truncated = len(body) > max_bytes
            if truncated:
                body = body[:max_bytes]
            ctype, charset = _parse_content_type(hdrs.get("content-type", ""))

            # A connection is reusable only if the whole body was consumed and the
            # peer did not signal close; otherwise there is unread data on the wire.
            conn_hdr = hdrs.get("connection", "").lower()
            fully_read = len(raw) < wire_cap
            if (self.keep_alive and "close" not in conn_hdr and fully_read
                    and pinned is not None):
                self._pool[key] = (conn, pinned)
            else:
                try:
                    conn.close()
                except Exception:
                    pass
            return {"status": status, "headers": hdrs, "body": body,
                    "content_type": ctype, "charset": charset,
                    "truncated": truncated}
        # Both the pooled reuse and the fresh retry raised a connection error.
        return {"error": "%s: %s" % (type(last_exc).__name__, last_exc)}

    def fetch(self, url, user_agent=DEFAULT_UA, timeout=10.0,
              max_bytes=2_000_000, max_redirects=5, allow=None,
              accept_encoding="gzip, deflate", block_internal=True,
              allow_hosts=None, extra_headers=None):
        """Keep-alive fetch with the redirect/SSRF semantics of :func:`fetch`."""
        seen = set()
        current = url
        redirects = 0
        while True:
            if allow is not None and not allow(current):
                return FetchResult(url, current, 0, {}, b"", None, None,
                                   error="blocked", redirects=redirects)
            res = self._one(current, user_agent, timeout, max_bytes,
                            accept_encoding, block_internal, allow_hosts,
                            extra_headers if redirects == 0 else None)
            if "error" in res:
                return FetchResult(url, current, 0, {}, b"", None, None,
                                   error=res["error"], redirects=redirects)
            status = res["status"]
            if status in _REDIRECT_CODES and redirects < max_redirects:
                loc = res["headers"].get("location")
                target = canonical.canonicalize(loc, base=current) if loc else None
                if not target or target in seen:
                    return FetchResult(url, current, status, res["headers"],
                                       res["body"], res["content_type"],
                                       res["charset"], redirects=redirects)
                seen.add(target)
                current = target
                redirects += 1
                continue
            return FetchResult(url, current, status, res["headers"], res["body"],
                               res["content_type"], res["charset"],
                               truncated=res["truncated"], redirects=redirects)


def decode_body(body, charset=None):
    """Decode *body* bytes to ``str``, sniffing a meta charset if needed."""
    if charset:
        try:
            return body.decode(charset, errors="replace")
        except (LookupError, ValueError):
            pass
    head = body[:2048].lower()
    for marker in (b"charset=", b"charset ="):
        i = head.find(marker)
        if i != -1:
            frag = head[i + len(marker): i + len(marker) + 40]
            cs = bytes(
                c for c in frag if c not in b' "\';>'
            ).split(b"/")[-1].decode("ascii", "ignore")
            if cs:
                try:
                    return body.decode(cs, errors="replace")
                except (LookupError, ValueError):
                    break
    try:
        return body.decode("utf-8")
    except UnicodeDecodeError:
        return body.decode("latin-1", errors="replace")
