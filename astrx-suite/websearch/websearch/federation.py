"""Zero-dependency horizontal federation for websearch.

The clearnet crawler+index is the only part of the suite whose corpus outgrows a
single node, and it shards cleanly: assign every registrable HOST to exactly one
shard by *rendezvous* (HRW) hashing.  Because a host lives on one shard and only
one,

  * per-host politeness needs no cross-node coordination -- each shard is the
    sole crawler of its hosts, so the existing single-node politeness clock is
    already fleet-correct, and
  * URL-seen dedup is free -- the same URL can never be enqueued on two shards.

A stateless *aggregator* fans a query out to every shard's JSON API in parallel
and merges the answers: cross-host near-duplicate mirrors are collapsed with the
very same SimHash used single-node (shards now expose it in their JSON), each
shard gets a wall-clock deadline, and the response is flagged *partial* when a
shard is slow or down.  Everything here is Python 3.11 standard library.

Security posture is unchanged.  The aggregator only ever contacts the
operator-configured shard base URLs (never a user-supplied address); the user's
query is URL-encoded into a fixed base; and every shard response is size- and
time-bounded.  The shard servers keep their own SSRF-checked crawl path.

Two independent namespaces:
  * the crawler is told ``--shard-id X --shards a,b,c`` (opaque shard *ids* used
    only for HRW routing), and
  * the aggregator is told the shard *base URLs* to query.
Operators keep the id set identical across crawlers; the aggregator needs only
the URLs.
"""

import concurrent.futures
import json
import time
import urllib.parse
import urllib.request

import hashlib


# --------------------------------------------------------------------------
# Pure sharding core (only hashlib -- import-cheap, no cycles).
# --------------------------------------------------------------------------

def norm_host(host):
    """Normalise a host to the sharding key: lower-case, no port, no trailing dot."""
    h = (host or "").strip().lower()
    if not h:
        return ""
    if h.startswith("["):                      # [ipv6](:port)? -> keep the literal
        end = h.find("]")
        if end != -1:
            return h[: end + 1]
    if h.count(":") == 1:                       # name:port -> strip the port
        h = h.split(":", 1)[0]
    return h.rstrip(".")


def shard_for(host, shards):
    """Return the shard id that owns *host* (rendezvous / HRW hashing).

    For each shard id we hash ``sha256(shard_id \\x00 host)`` and pick the shard
    with the greatest digest.  HRW gives an even split and, when a shard is added
    or removed, reassigns only ~1/N of hosts -- no global rebalance.
    """
    if not shards:
        return None
    key = norm_host(host)
    best = None
    best_id = None
    for sid in shards:
        d = hashlib.sha256(
            (str(sid) + "\x00" + key).encode("utf-8", "replace")).digest()
        if best is None or d > best:
            best = d
            best_id = sid
    return best_id


def owns(host, my_id, shards):
    """True iff shard *my_id* owns *host* under HRW over *shards*.

    With no shard set configured (single-node mode) everything is owned, so the
    crawler behaves exactly as before.
    """
    if not shards or my_id is None:
        return True
    return shard_for(host, shards) == my_id


# --------------------------------------------------------------------------
# Aggregator: scatter-gather a query across shard base URLs.
# --------------------------------------------------------------------------

DEFAULT_TIMEOUT = 4.0
MAX_JSON_BYTES = 4_000_000
OVER_FETCH = 3            # pull ~this * page_size from each shard for the merge
MAX_SHARD_LIMIT = 200     # never ask a shard for more than it will serve


def normalize_bases(shards):
    """Validate + normalise operator-provided shard base URLs.

    Accepts only ``http(s)://host[:port][/path]`` (the trusted internal fleet
    endpoints); anything else is dropped.  Returns a de-duplicated list with any
    trailing slash removed.
    """
    out = []
    seen = set()
    for raw in shards or ():
        base = (raw or "").strip()
        if not base:
            continue
        parts = urllib.parse.urlsplit(base)
        if parts.scheme not in ("http", "https") or not parts.netloc:
            continue
        clean = urllib.parse.urlunsplit(
            (parts.scheme, parts.netloc, parts.path.rstrip("/"), "", ""))
        if clean not in seen:
            seen.add(clean)
            out.append(clean)
    return out


def _get_json(url, timeout):
    req = urllib.request.Request(
        url, headers={"User-Agent": "astrx-websearch-fed/1.0",
                      "Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:  # nosec - config URL
        raw = resp.read(MAX_JSON_BYTES + 1)
    if len(raw) > MAX_JSON_BYTES:
        raw = raw[:MAX_JSON_BYTES]
    return json.loads(raw.decode("utf-8", "replace"))


def query_shard(base, q, limit, timeout):
    """Query one shard's ``/api/search``.  Returns ``(results, total)`` or
    ``(None, 0)`` on any error (the caller records it as a failed shard)."""
    url = base.rstrip("/") + "/api/search?" + urllib.parse.urlencode(
        {"q": q, "limit": limit})
    try:
        payload = _get_json(url, timeout)
    except Exception:
        return None, 0
    if not isinstance(payload, dict):
        return None, 0
    results = payload.get("results")
    if not isinstance(results, list):
        results = []
    try:
        total = int(payload.get("total") or 0)
    except (TypeError, ValueError):
        total = 0
    # keep only well-formed dict rows
    results = [r for r in results if isinstance(r, dict) and r.get("url")]
    return results, total


def _merge(shard_results, near_threshold):
    """Merge shard result rows: exact-URL dedup, then cross-host SimHash collapse.

    Ordered by descending score.  Exact-URL dedup is a defensive no-op in
    practice (a host lives on one shard, so its URLs cannot appear twice) but
    guards against a misconfigured overlapping shard set.  The SimHash pass is
    the real work: it drops a mirror on host B when a higher-scoring copy on host
    A is already kept -- identical to the single-node ``_collapse_near_dups``.
    """
    from . import dedup
    by_url = {}
    for d in shard_results:
        u = d.get("url")
        if not u:
            continue
        prev = by_url.get(u)
        if prev is None or (d.get("score") or 0.0) > (prev.get("score") or 0.0):
            by_url[u] = d
    items = sorted(by_url.values(),
                   key=lambda d: d.get("score") or 0.0, reverse=True)
    if near_threshold is None or near_threshold < 0:
        return items
    kept = []
    seen = []                      # list of (simhash_int, host) already kept
    for d in items:
        try:
            h = int(d.get("simhash") or 0)
        except (TypeError, ValueError):
            h = 0
        host = d.get("host") or ""
        if h:
            if any(host != kh_host and dedup.near(h, kh, near_threshold)
                   for kh, kh_host in seen):
                continue           # a mirror of something already shown
            seen.append((h, host))
        kept.append(d)
    return kept


def federated_search(shards, q, page=1, page_size=10, timeout=DEFAULT_TIMEOUT,
                     over_fetch=OVER_FETCH, near_threshold=None):
    """Scatter-gather *q* across shard base URLs and merge.

    Returns a dict: ``results`` (the requested page slice), ``total`` (a pager
    bound), ``partial`` (a shard failed or was slow), ``shards`` (per-base
    ok/error), ``shard_count``/``ok_count`` and ``elapsed_seconds``.
    """
    from . import ranking
    if near_threshold is None:
        near_threshold = ranking.SIMHASH_HAMMING
    started = time.perf_counter()
    bases = normalize_bases(shards)
    # Enough candidates from each shard to fill the requested page after merge.
    limit = min(MAX_SHARD_LIMIT,
                max(page_size, page * page_size + page_size * (over_fetch - 1)))
    limit = max(page_size, limit)

    all_results = []
    sum_total = 0
    status = {}
    if q and bases:
        with concurrent.futures.ThreadPoolExecutor(
                max_workers=min(len(bases), 16)) as pool:
            futs = {pool.submit(query_shard, base, q, limit, timeout): base
                    for base in bases}
            for fut in concurrent.futures.as_completed(futs):
                base = futs[fut]
                try:
                    res, total = fut.result()
                except Exception:
                    res, total = None, 0
                if res is None:
                    status[base] = "error"
                    continue
                status[base] = "ok"
                all_results.extend(res)
                sum_total += total

    merged = _merge(all_results, near_threshold)
    # Never advertise (or page past) more than we can actually serve from the
    # merged candidate window -- mirrors ranking.search's own total clamp.
    total = min(sum_total, len(merged))
    lo = max(0, (page - 1) * page_size)
    hi = lo + page_size
    partial = any(v != "ok" for v in status.values()) or (bool(bases) and not status)
    return {
        "results": merged[lo:hi],
        "total": total,
        "partial": partial,
        "shards": status,
        "shard_count": len(bases),
        "ok_count": sum(1 for v in status.values() if v == "ok"),
        "elapsed_seconds": time.perf_counter() - started,
        "page": page,
        "page_size": page_size,
    }


# --------------------------------------------------------------------------
# Aggregator HTTP front-end (no-JS; reuses the shard server's renderer).
# --------------------------------------------------------------------------

def _result_obj(d):
    """Rebuild a ``ranking.SearchResult`` from a shard JSON row so the shard
    server's ``_result_row`` renders it byte-for-byte like a local result."""
    from . import ranking
    return ranking.SearchResult(
        url=d.get("url"), title=d.get("title") or d.get("url"),
        description=None, snippet=d.get("snippet_html") or "",
        host=d.get("host"), fetched_at=d.get("fetched_at"),
        score=d.get("score") or 0.0, signals=d.get("signals") or {},
        lang=d.get("lang"), simhash=int(d.get("simhash") or 0),
    )


def _render(q, fed):
    import html as _html
    from urllib.parse import urlencode
    from . import server
    page = fed["page"]
    page_size = fed["page_size"]
    total = fed["total"]
    parts = ["<main><div class=wrap>"]
    if fed["partial"]:
        parts.append(
            "<div class=meta>Partial results: %d of %d shard(s) responded; "
            "some matches may be missing.</div>"
            % (fed["ok_count"], fed["shard_count"]))
    parts.append(
        "<div class=meta>About %d result%s (%.3f seconds) across %d shard(s)</div>"
        % (total, "" if total == 1 else "s",
           fed["elapsed_seconds"], fed["shard_count"]))
    if not fed["results"]:
        parts.append("<p class=empty>No pages matched <strong>%s</strong>. Try "
                     "fewer or different terms.</p>" % _html.escape(q))
    for d in fed["results"]:
        parts.append(server._result_row(_result_obj(d), similar=False))
    last = max(1, (total + page_size - 1) // page_size)
    if last > 1:
        parts.append("<div class=pager>")
        if page > 1:
            parts.append("<a href='/search?%s'>&larr; Prev</a>"
                         % urlencode({"q": q, "page": page - 1}))
        parts.append("<span>Page %d of %d</span>" % (page, last))
        if page < last:
            parts.append("<a href='/search?%s'>Next &rarr;</a>"
                         % urlencode({"q": q, "page": page + 1}))
        parts.append("</div>")
    parts.append("<footer><a href='/api/search?%s'>JSON API</a></footer>"
                 % urlencode({"q": q}))
    parts.append("</div></main>")
    return "".join(parts)


def _home():
    return ("<main><div class=wrap><p class=meta>Federated search across "
            "sharded astrx-websearch nodes. Enter a query above.</p>"
            "<footer><a href='/api/search'>JSON API</a></footer></div></main>")


def make_handler(bases, timeout, page_size, auth, rate_limiter):
    from http.server import BaseHTTPRequestHandler
    from urllib.parse import urlsplit, parse_qs
    import base64
    import binascii
    import hmac
    import html as _html
    from . import server

    _OPEN = {"/healthz", "/style.css", "/favicon.ico", "/opensearch.xml",
             "/metrics"}

    class FedHandler(BaseHTTPRequestHandler):
        server_version = "astrx-websearch-fed/1.0"
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):   # keep test output clean
            pass

        def _send(self, code, body, ctype="text/html; charset=utf-8", extra=None):
            if isinstance(body, str):
                body = body.encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("X-Content-Type-Options", "nosniff")
            for k, v in (extra or {}).items():
                self.send_header(k, v)
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)

        def do_HEAD(self):
            self.do_GET()

        def _params(self):
            p = parse_qs(urlsplit(self.path).query, keep_blank_values=True)
            q = (p.get("q", [""])[0] or "").strip()
            try:
                page = max(1, int(p.get("page", ["1"])[0]))
            except (ValueError, TypeError):
                page = 1
            return q, page

        def _authorized(self):
            hdr = self.headers.get("Authorization", "")
            if not hdr.startswith("Basic "):
                return False
            try:
                raw = base64.b64decode(hdr[6:].strip(), validate=True)
                user, _, pw = raw.decode("utf-8", "replace").partition(":")
            except (binascii.Error, ValueError):
                return False
            return (hmac.compare_digest(user, auth[0])
                    and hmac.compare_digest(pw, auth[1]))

        def do_GET(self):
            path = urlsplit(self.path).path
            client = self.client_address[0] if self.client_address else "-"
            try:
                protected = path not in _OPEN
                if (rate_limiter is not None and protected
                        and not rate_limiter.allow(client)):
                    return self._send(
                        429, server._page("Too Many Requests",
                                          "<p>Rate limit exceeded.</p>"),
                        extra={"Retry-After": "1"})
                if auth is not None and protected and not self._authorized():
                    return self._send(
                        401, server._page("Authentication required",
                                          "<p>Authentication required.</p>"),
                        extra={"WWW-Authenticate": 'Basic realm="astrx"'})
                self._route(path)
            except BrokenPipeError:   # pragma: no cover
                pass
            except Exception as exc:  # never leak a silent 500
                self._send(500, server._page("Error", "<pre>%s</pre>"
                           % _html.escape(repr(exc))))

        def _route(self, path):
            if path == "/style.css":
                return self._send(200, server.STYLE, "text/css; charset=utf-8")
            if path == "/healthz":
                return self._send(200, "ok", "text/plain; charset=utf-8")
            if path == "/favicon.ico":
                return self._send(204, b"")
            if path == "/metrics":
                return self._metrics()
            if path == "/opensearch.xml":
                return self._send(
                    200, server._opensearch_xml(self._base()),
                    "application/opensearchdescription+xml; charset=utf-8")
            if path == "/api/search":
                return self._api()
            if path in ("/", "/search"):
                return self._html()
            return self._send(404, server._page(
                "Not found", server._header() + "<main><div class=wrap>"
                "<p>Not found.</p></div></main>"))

        def _base(self):
            host = self.headers.get("Host", "") or ""
            if not server._HOST_RE.match(host):
                a = self.server.server_address
                host = "%s:%d" % (a[0], a[1])
            return "http://%s" % host

        def _metrics(self):
            lines = ["# astrx-websearch-fed metrics",
                     "websearch_fed_shards %d" % len(bases)]
            self._send(200, "\n".join(lines) + "\n",
                       "text/plain; charset=utf-8")

        def _html(self):
            q, page = self._params()
            if not q:
                return self._send(200, server._page(
                    "astrx search", server._header() + _home()))
            fed = federated_search(bases, q, page=page, page_size=page_size,
                                   timeout=timeout)
            body = server._header(q) + _render(q, fed)
            self._send(200, server._page("%s - astrx search" % q, body))

        def _api(self):
            q, page = self._params()
            fed = federated_search(bases, q, page=page, page_size=page_size,
                                   timeout=timeout)
            payload = {
                "query": q, "page": page, "page_size": page_size,
                "total": fed["total"], "partial": fed["partial"],
                "shards": fed["shard_count"], "shards_ok": fed["ok_count"],
                "elapsed_seconds": round(fed["elapsed_seconds"], 6),
                "results": fed["results"],
            }
            self._send(200, json.dumps(payload, ensure_ascii=False),
                       "application/json; charset=utf-8")

    return FedHandler


def make_server(shards, host="127.0.0.1", port=8809, timeout=DEFAULT_TIMEOUT,
                page_size=10, auth=None, rate=None, burst=None):
    """Build (but do not start) the aggregator :class:`ThreadingHTTPServer`."""
    from http.server import ThreadingHTTPServer
    from . import server
    bases = normalize_bases(shards)
    rl = None
    if rate is not None:
        rl = server.RateLimiter(rate, burst if burst is not None else rate)
    handler = make_handler(bases, timeout, page_size,
                           tuple(auth) if auth else None, rl)
    return ThreadingHTTPServer((host, port), handler)


def serve(shards, host="127.0.0.1", port=8809, timeout=DEFAULT_TIMEOUT,
          page_size=10, auth=None, rate=None, burst=None):  # pragma: no cover
    httpd = make_server(shards, host, port, timeout=timeout, page_size=page_size,
                        auth=auth, rate=rate, burst=burst)
    bases = normalize_bases(shards)
    print("astrx-websearch aggregator on http://%s:%d/  (%d shard(s))  Ctrl-C to stop"
          % (host, port, len(bases)))
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()
