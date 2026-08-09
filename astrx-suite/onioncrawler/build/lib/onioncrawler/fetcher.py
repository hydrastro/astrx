"""Pluggable fetcher interface + implementations.

TorSocksFetcher  - real deployment: SOCKS5 -> local Tor -> .onion, remote DNS.
I2PHttpFetcher   - real deployment: HTTP proxy -> local I2P router -> .i2p.
DirectFetcher    - TESTING ONLY: plain HTTP to a localhost fixture. It STILL
                   enforces the darknet validator and maps synthetic darknet
                   hosts to 127.0.0.1:<port>, so the whole crawl pipeline runs
                   for real while the transport is swapped. It must never be
                   used for a real crawl (there is no anonymity).

Every fetcher is locked to exactly ONE network via `_require_host`
(require_onion for Tor, require_i2p for I2P). That per-fetcher gate is the hard
anti-leak guarantee: an onion crawl only ever opens onion sockets and an i2p
crawl only ever opens i2p sockets -- they can never cross-leak, and neither ever
touches clearnet / localhost / an IP literal.

All share the HTTP/1.1 client and the redirect loop in BaseFetcher.
"""

from __future__ import annotations

import socket
import ssl
import threading
from urllib.parse import urljoin

from .onion import require_onion, require_i2p, NotOnionError
from .canonical import canonicalize
from . import http_client
from . import socks as socks_mod
from . import i2p as i2p_mod

USER_AGENT = "OnionCrawler/1.0 (+research crawler; abuse-filtered; contact=operator)"


class FetchResult:
    __slots__ = (
        "url", "final_url", "status", "headers", "body",
        "content_type", "ok", "error", "truncated", "too_large",
    )

    def __init__(self, url, final_url=None, status=0, headers=None, body=b"",
                 content_type="", ok=False, error=None, truncated=False,
                 too_large=False):
        self.url = url
        self.final_url = final_url or url
        self.status = status
        self.headers = headers or {}
        self.body = body
        self.content_type = content_type
        self.ok = ok
        self.error = error
        self.truncated = truncated
        # True iff the transport aborted because the body exceeded the byte cap
        # for this fetch (ResponseTooLarge). Lets the crawler re-fetch a media
        # resource at a larger media cap instead of silently skipping it.
        self.too_large = too_large

    def header(self, name, default=None):
        return self.headers.get(name.lower(), default)

    def __repr__(self):
        return f"FetchResult({self.final_url!r}, status={self.status}, ok={self.ok})"


class BaseFetcher:
    scheme_supported = ("http", "https")
    # Which darknet this fetcher is locked to: 'onion' (default) or 'i2p'.
    network = "onion"

    def __init__(self, max_bytes=2_000_000, max_redirects=5, timeout=60.0,
                 allow_v2=False, reuse_connections=False):
        self.max_bytes = max_bytes
        self.max_redirects = max_redirects
        self.timeout = timeout
        self.allow_v2 = allow_v2
        # Optional HTTP keep-alive pool: at most one idle socket per
        # (host, port, scheme). Per-host lease serialization means only one
        # worker touches a given host at a time, so the pooled socket is never
        # shared concurrently. Circuit reuse is orthogonal and comes from Tor
        # stream isolation (a stable per-host SOCKS username -> one circuit).
        self.reuse_connections = reuse_connections
        self._pool: dict[tuple, socket.socket] = {}
        self._pool_lock = threading.Lock()

    # subclasses implement the transport
    def _open(self, host: str, port: int, scheme: str) -> socket.socket:
        raise NotImplementedError

    def default_port(self, scheme: str) -> int:
        return 443 if scheme == "https" else 80

    # ---- network gate (the hard anti-leak lock) --------------------------
    def _require_host(self, host: str) -> str:
        """Raise unless *host* is on THIS fetcher's network. Onion by default
        (the crown invariant); i2p fetchers override to require_i2p."""
        return require_onion(host, allow_v2=self.allow_v2)

    @property
    def allow_i2p(self) -> bool:
        """Whether this fetcher's network is i2p (used by the crawler to admit
        .i2p into the frontier only for an i2p crawl)."""
        return self.network == "i2p"

    def host_ok(self, host: str) -> bool:
        """True iff *host* is on this fetcher's network (no raise). The crawler
        uses this to keep the frontier single-network."""
        try:
            self._require_host(host)
            return True
        except NotOnionError:
            return False

    def _request_target(self, scheme, host, port, path) -> str:
        """The request-line target. Origin-form (path) by default; the I2P http
        fetcher overrides to absolute-form when talking to its HTTP proxy."""
        return path

    # ---------------------------------------------------------- conn pool
    @staticmethod
    def _safe_close(sock):
        try:
            sock.close()
        except OSError:
            pass

    def _pool_get(self, host, port, scheme):
        if not self.reuse_connections:
            return None
        with self._pool_lock:
            return self._pool.pop((host, port, scheme), None)

    def _pool_put(self, host, port, scheme, sock):
        # keep the pool honest: only ever cache a validated on-network connection
        self._require_host(host)
        with self._pool_lock:
            old = self._pool.get((host, port, scheme))
            if old is not None and old is not sock:
                self._safe_close(old)
            self._pool[(host, port, scheme)] = sock

    def _perform(self, host, port, scheme, host_header, target, headers,
                 max_bytes=None):
        """One request/response, reusing a pooled keep-alive socket when
        possible and transparently falling back to a fresh socket if a pooled
        one is stale. *target* is the request-line target (origin-form path, or
        absolute-form for an HTTP proxy). *max_bytes* overrides the read cap for
        this request (used to hash a large media resource at the media cap)."""
        cap = max_bytes if max_bytes else self.max_bytes
        if self.reuse_connections:
            pooled = self._pool_get(host, port, scheme)
            if pooled is not None:
                try:
                    resp = http_client.perform_request(
                        pooled, "GET", host_header, target, headers, cap)
                    self._return_or_close(host, port, scheme, pooled, resp)
                    return resp
                except (OSError, http_client.HttpError):
                    self._safe_close(pooled)  # stale; open a fresh one below
        sock = self._open(host, port, scheme)
        try:
            resp = http_client.perform_request(
                sock, "GET", host_header, target, headers, cap)
        except BaseException:
            self._safe_close(sock)
            raise
        self._return_or_close(host, port, scheme, sock, resp)
        return resp

    def _return_or_close(self, host, port, scheme, sock, resp):
        if self.reuse_connections and getattr(resp, "reusable", False):
            self._pool_put(host, port, scheme, sock)
        else:
            self._safe_close(sock)

    def close(self):
        """Close any idle pooled sockets. Safe to call multiple times."""
        with self._pool_lock:
            for s in self._pool.values():
                self._safe_close(s)
            self._pool.clear()

    def fetch(self, url: str, extra_headers: dict | None = None,
              max_bytes: int | None = None) -> FetchResult:
        """Fetch *url*, following redirects one hop at a time. *extra_headers*
        (e.g. If-None-Match / If-Modified-Since for a conditional GET) are added
        to every hop; a 304 Not Modified is returned as a normal (ok=False,
        status=304) result for the caller to interpret. *max_bytes* overrides the
        read cap for this fetch (the crawler passes a larger media cap so a
        blocklisted image/video above max_response_bytes is still hashed)."""
        current = url
        netlabel = ".i2p" if self.network == "i2p" else ".onion"
        try:
            for hop in range(self.max_redirects + 1):
                cu = canonicalize(current, allow_v2=self.allow_v2,
                                  allow_i2p=self.allow_i2p)
                if cu is None:
                    return FetchResult(url, current, ok=False,
                                       error=f"not a fetchable {netlabel} URL")
                # hard anti-leak gate right before we touch a socket: this
                # fetcher's network only (onion XOR i2p; never clearnet).
                self._require_host(cu.host)
                port = cu.port or self.default_port(cu.scheme)
                path = cu.path + (("?" + cu.query) if cu.query else "")
                host_header = cu.host if port == self.default_port(cu.scheme) \
                    else f"{cu.host}:{port}"
                target = self._request_target(cu.scheme, cu.host, port, path)
                headers = {
                    "User-Agent": USER_AGENT,
                    "Accept": "text/html,text/plain;q=0.9,*/*;q=0.1",
                    "Accept-Encoding": "gzip, deflate",
                    "Connection": "keep-alive" if self.reuse_connections else "close",
                }
                if extra_headers:
                    headers.update(extra_headers)
                resp = self._perform(
                    cu.host, port, cu.scheme, host_header, target, headers,
                    max_bytes=max_bytes)

                if 300 <= resp.status < 400 and resp.header("location") \
                        and hop < self.max_redirects:
                    loc = resp.header("location")
                    current = urljoin(cu.url, loc)
                    continue

                ctype = (resp.header("content-type", "") or "").split(";")[0].strip().lower()
                return FetchResult(
                    url=url, final_url=cu.url, status=resp.status,
                    headers=resp.headers, body=resp.body, content_type=ctype,
                    ok=(200 <= resp.status < 300), error=None,
                    truncated=resp.truncated,
                )
            return FetchResult(url, current, ok=False, error="too many redirects")
        except NotOnionError as e:
            # NotDarknetError (i2p/clearnet refusal) subclasses NotOnionError, so
            # both anti-leak refusals land here as a clean non-ok result.
            return FetchResult(url, current, ok=False, error=f"non-onion refused: {e}")
        except http_client.ResponseTooLarge as e:
            # Body exceeded the read cap: surface too_large + whatever
            # content-type the response headers carried (parsed before the body
            # blew the cap) so the crawler can decide whether to re-fetch it as
            # media at the larger media cap. Never leaks bytes.
            ct = ((getattr(e, "content_type", "") or "")
                  .split(";")[0].strip().lower())
            return FetchResult(url, current,
                               status=getattr(e, "status", 0) or 0,
                               content_type=ct, ok=False, truncated=True,
                               too_large=True, error=f"ResponseTooLarge: {e}")
        except (socks_mod.SocksError, i2p_mod.I2PError,
                http_client.HttpError, OSError) as e:
            return FetchResult(url, current, ok=False, error=f"{type(e).__name__}: {e}")


class TorSocksFetcher(BaseFetcher):
    """Real deployment fetcher: everything goes through the Tor SOCKS proxy
    with remote DNS. Optionally isolates streams per-host (a distinct SOCKS
    username => a distinct Tor circuit)."""

    def __init__(self, proxy_host="127.0.0.1", proxy_port=9050,
                 stream_isolation=True, isolation_secret="onioncrawler",
                 verify_tls=False, proxies=None, **kw):
        super().__init__(**kw)
        self.proxy_host = proxy_host
        self.proxy_port = proxy_port
        # torfleet: a pool of Tor SOCKS endpoints. A host is pinned to one
        # endpoint (stable hash) so its circuit reuse + per-host politeness stay
        # consistent while total throughput scales with the number of daemons.
        self._proxies = self._parse_proxies(proxies, proxy_host, proxy_port)
        self.stream_isolation = stream_isolation
        self.isolation_secret = isolation_secret
        self.verify_tls = verify_tls

    @staticmethod
    def _parse_proxies(proxies, default_host, default_port):
        out = []
        for p in (proxies or []):
            if isinstance(p, (tuple, list)):
                out.append((str(p[0]), int(p[1])))
                continue
            s = str(p).strip()
            if not s:
                continue
            if ":" in s:
                h, port = s.rsplit(":", 1)
                try:
                    out.append((h, int(port)))
                except ValueError:
                    continue
            else:
                out.append((s, int(default_port)))
        return out or [(default_host, int(default_port))]

    def _pick_proxy(self, host):
        if len(self._proxies) == 1:
            return self._proxies[0]
        import hashlib
        idx = int(hashlib.sha256(
            (host or "").encode("utf-8", "replace")).hexdigest(), 16) \
            % len(self._proxies)
        return self._proxies[idx]

    @property
    def pool_size(self):
        return len(self._proxies)

    def _iso_creds(self, host):
        if not self.stream_isolation:
            return None, None
        # per-host username -> per-host circuit (Tor IsolateSOCKSAuth)
        return (f"host-{host}", self.isolation_secret)

    def _open(self, host, port, scheme):
        # never resolve .onion locally: socks5_connect sends the hostname
        require_onion(host, allow_v2=self.allow_v2)
        user, pw = self._iso_creds(host)
        phost, pport = self._pick_proxy(host)
        raw = socks_mod.socks5_connect(
            phost, pport, host, port,
            username=user, password=pw, timeout=self.timeout,
        )
        if scheme == "https":
            ctx = ssl.create_default_context()
            if not self.verify_tls:
                # onion services provide their own end-to-end authentication;
                # cert validation is commonly disabled. Operator-configurable.
                ctx.check_hostname = False
                ctx.verify_mode = ssl.CERT_NONE
            return ctx.wrap_socket(raw, server_hostname=host)
        return raw


class I2PHttpFetcher(BaseFetcher):
    """Real deployment fetcher for .i2p eepsites via a local I2P router's HTTP
    proxy (default 127.0.0.1:4444). Plain-http eepsites use an absolute-form GET
    through the proxy; https eepsites use an HTTP CONNECT tunnel then TLS.

    Locked to the i2p network by require_i2p, so it can NEVER open an onion or
    clearnet socket -- symmetric to TorSocksFetcher's require_onion lock."""

    network = "i2p"

    def __init__(self, proxy_host="127.0.0.1", proxy_port=4444,
                 verify_tls=False, **kw):
        super().__init__(**kw)
        self.proxy_host = proxy_host
        self.proxy_port = proxy_port
        self.verify_tls = verify_tls

    def _require_host(self, host):
        return require_i2p(host)

    def _request_target(self, scheme, host, port, path):
        # plain http through an HTTP proxy needs the absolute URL in the request
        # line; an https CONNECT tunnel is origin-form like any direct socket.
        if scheme == "https":
            return path
        return i2p_mod.build_proxy_get_target(scheme, host, port, path)

    def _open(self, host, port, scheme):
        # never resolve .i2p locally: the eepsite name is handed to the proxy.
        require_i2p(host)
        if scheme == "https":
            raw = socket.create_connection(
                (self.proxy_host, self.proxy_port), timeout=self.timeout)
            try:
                raw.settimeout(self.timeout)
                raw.sendall(i2p_mod.build_http_connect(host, port))
                i2p_mod.read_connect_reply(raw)
                ctx = ssl.create_default_context()
                if not self.verify_tls:
                    ctx.check_hostname = False
                    ctx.verify_mode = ssl.CERT_NONE
                return ctx.wrap_socket(raw, server_hostname=host)
            except BaseException:
                self._safe_close(raw)
                raise
        # plain http: connect to the proxy; the absolute-form request line
        # (from _request_target) tells the proxy which eepsite to reach.
        return socket.create_connection(
            (self.proxy_host, self.proxy_port), timeout=self.timeout)


class DirectFetcher(BaseFetcher):
    """TESTING ONLY. Plain HTTP to a localhost fixture. NO anonymity.

    Maps synthetic darknet hostnames to (ip, port) on 127.0.0.1 via *hostmap*.
    The darknet validator still runs (require_onion for network='onion',
    require_i2p for network='i2p'), so canonicalization / network-lock logic is
    exercised exactly as in production. *network* also selects which darknet the
    offline crawl simulates, so cross-leak tests run without Tor or I2P.
    """

    def __init__(self, hostmap=None, network="onion", **kw):
        super().__init__(**kw)
        self.network = network
        # {darknet_host: (ip, port)}
        self.hostmap = {}
        for h, addr in dict(hostmap or {}).items():
            self.add_host(h, addr[0], addr[1])

    def _require_host(self, host):
        if self.network == "i2p":
            return require_i2p(host)
        return require_onion(host, allow_v2=self.allow_v2)

    def add_host(self, darknet_host, ip, port):
        self.hostmap[self._require_host(darknet_host)] = (ip, port)

    def _open(self, host, port, scheme):
        self._require_host(host)  # keep the pipeline honest (network-locked)
        if scheme != "http":
            raise http_client.HttpError("DirectFetcher supports http only (test transport)")
        if host not in self.hostmap:
            raise socks_mod.SocksError(f"DirectFetcher has no mapping for {host}")
        ip, real_port = self.hostmap[host]
        return socket.create_connection((ip, real_port), timeout=self.timeout)


def build_fetcher(config) -> BaseFetcher:
    """Construct a fetcher from a Config object."""
    common = dict(
        max_bytes=config.max_response_bytes,
        max_redirects=config.max_redirects,
        timeout=config.fetch_timeout,
        allow_v2=config.allow_v2,
        reuse_connections=getattr(config, "reuse_connections", False),
    )
    if config.fetcher == "tor":
        extras = [p.strip() for p in
                  str(getattr(config, "tor_pool", "") or "").split(",") if p.strip()]
        # torfleet: the base --tor-port is always in the pool; --tor-pool ADDS
        # daemons to it (so setting a pool never silently drops the base one).
        proxies = ([f"{config.tor_host}:{config.tor_port}"] + extras) if extras \
            else None
        return TorSocksFetcher(
            proxy_host=config.tor_host, proxy_port=config.tor_port,
            proxies=proxies,
            stream_isolation=config.stream_isolation,
            verify_tls=config.verify_tls, **common,
        )
    if config.fetcher == "i2p":
        # I2P is a gap-closer behind an explicit opt-in; refuse to build the
        # fetcher unless the operator turned it on.
        if not getattr(config, "enable_i2p", False):
            raise ValueError("i2p fetcher requires enable_i2p (--enable-i2p)")
        return I2PHttpFetcher(
            proxy_host=getattr(config, "i2p_proxy_host", "127.0.0.1"),
            proxy_port=getattr(config, "i2p_proxy_port", 4444),
            verify_tls=config.verify_tls, **common,
        )
    if config.fetcher == "direct":
        network = "i2p" if getattr(config, "enable_i2p", False) and \
            getattr(config, "direct_network", "onion") == "i2p" else "onion"
        f = DirectFetcher(network=network, **common)
        for entry in config.direct_map:
            hostpart, addr = entry.split("=", 1)
            ip, p = addr.rsplit(":", 1)
            f.add_host(hostpart, ip, int(p))
        return f
    raise ValueError(f"unknown fetcher {config.fetcher!r}")
