"""No-JS, privacy-first search UI + JSON API over the FTS5 index.

Server-rendered HTML (stdlib http.server), bound to 127.0.0.1 by default.
Ranking is FTS5 bm25() with the title column weighted above the body, optionally
blended with offline host PageRank authority, and near-duplicate mirrors are
collapsed. Filters (host / last-seen date range / language) and facet counts are
exposed in both the UI and the JSON API.

Blocked and dead hosts are excluded at query time as defense-in-depth. Public
endpoints are token-bucket rate-limited; admin actions (add/purge/recrawl) are
gated by HTTP Basic auth. Everything user-supplied is HTML-escaped and the CSP
forbids scripts.

Note: /health, /healthz and /metrics are rate-exempt so a monitoring probe (and
the compose /metrics poller) always works. They expose only AGGREGATE counts
(never onion hosts, IPs, queries or seeds). They are open by default; if this
server is published beyond localhost (e.g. as an onion), set ``metrics_token``
(``--metrics-token``) to require a matching ``?token=`` / ``X-Metrics-Token`` /
``Authorization: Bearer`` on /metrics and /health -- /healthz stays a trivial,
always-open liveness probe so the container healthcheck never needs the token.
Rate limiting keys on the real TCP peer (never a spoofable header); behind an
onion service every request arrives from 127.0.0.1, so the limiter necessarily
behaves as a single shared/global bucket -- per-client fairness is impossible
over Tor because there is no per-client identity.
"""

from __future__ import annotations

import base64
import calendar
import html
import hmac
import json
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs, urlencode

from .storage import Storage
from .ratelimit import TokenBucket
from .submit import submit_many
from .onion import is_darknet_host, normalize_host
from .lang import known_languages
from . import entities as entities_mod

# Upper bound on the page number we will honour. Prevents a crafted `page`
# query param from producing an OFFSET beyond SQLite's 64-bit integer range
# (which raised OverflowError -> unhandled 500). 100k pages is far more than a
# human search UI needs.
MAX_PAGE = 100_000

PAGE_STYLE = """
body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
max-width:760px;margin:0 auto;padding:1.2rem;color:#111;background:#fafafa;line-height:1.45}
header a{text-decoration:none;color:#5b21b6}
h1{font-size:1.4rem;margin:.2rem 0 1rem}
form{margin-bottom:1rem}
.row{display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap}
input[type=text]{flex:1;min-width:12rem;padding:.55rem .7rem;font-size:1rem;border:1px solid #ccc;border-radius:6px}
input[type=date],select{padding:.4rem;border:1px solid #ccc;border-radius:6px}
.filters input[type=text]{min-width:8rem}
button{padding:.55rem 1rem;font-size:1rem;border:0;border-radius:6px;background:#5b21b6;color:#fff;cursor:pointer}
.result{margin:1rem 0;padding-bottom:.8rem;border-bottom:1px solid #eee}
.result .title{font-size:1.08rem;font-weight:600;color:#1a0dab}
.result .url{color:#0a7d33;font-size:.86rem;word-break:break-all}
.result .snip{color:#333;font-size:.95rem;margin-top:.15rem}
.result .meta{color:#888;font-size:.78rem;margin-top:.2rem}
mark{background:#fde68a;padding:0 1px}
.nav{margin-top:1.2rem;display:flex;gap:1rem}
.facets{font-size:.82rem;color:#555;margin:.4rem 0 1rem}
.facets a{color:#5b21b6;text-decoration:none;margin-right:.5rem}
.muted{color:#888;font-size:.85rem}
footer{margin-top:2rem;color:#999;font-size:.78rem}
"""


def _fmt_time(ts):
    if not ts:
        return "unknown"
    try:
        return time.strftime("%Y-%m-%d %H:%M UTC", time.gmtime(float(ts)))
    except Exception:
        return "unknown"


def _parse_date(s, end=False):
    """Parse a YYYY-MM-DD string into a UTC epoch, or None. *end* pins it to the
    last second of that day (inclusive upper bound)."""
    s = (s or "").strip()
    if not s:
        return None
    try:
        t = time.strptime(s, "%Y-%m-%d")
    except ValueError:
        return None
    epoch = calendar.timegm(t)
    return epoch + (86400 - 1) if end else epoch


class SearchApp:
    def __init__(self, storage: Storage, config, abuse=None, log=None):
        self.storage = storage
        self.cfg = config
        self.abuse = abuse
        self.log = log or (lambda *a, **k: None)
        self.limiter = TokenBucket(
            getattr(config, "rate_limit_rps", 5.0),
            getattr(config, "rate_limit_burst", 20.0),
        )
        # serialize blocklist-file appends + reconciliation (threaded server)
        self._blocklist_lock = threading.Lock()

    # ------------------------------------------------------------ filters
    def _clean_filters(self, qs):
        host = (qs.get("host", [""])[0] or "").strip().lower()
        # A host filter must itself be a valid darknet host (.onion always; .i2p
        # only with --enable-i2p). When i2p is off this is exactly the old
        # onion-only check. Ignore an invalid filter rather than 500/empty.
        if host and not is_darknet_host(
                host, allow_v2=getattr(self.cfg, "allow_v2", False),
                allow_i2p=getattr(self.cfg, "enable_i2p", False)):
            host = ""
        host = normalize_host(host) if host else ""
        lang = (qs.get("lang", [""])[0] or "").strip().lower()
        since_s = (qs.get("since", [""])[0] or "").strip()
        until_s = (qs.get("until", [""])[0] or "").strip()
        return {
            "host": host, "lang": lang,
            "since_s": since_s, "until_s": until_s,
            "since": _parse_date(since_s), "until": _parse_date(until_s, end=True),
        }

    def _do_search(self, q, per, offset, f):
        return self.storage.search(
            q, limit=per, offset=offset,
            host=f["host"] or None, since=f["since"], until=f["until"],
            lang=f["lang"] or None,
            authority_weight=getattr(self.cfg, "authority_weight", 0.0),
            collapse=getattr(self.cfg, "collapse_duplicates", True),
            simhash_threshold=getattr(self.cfg, "simhash_threshold", 3),
        )

    # --------------------------------------------------------------- HTML
    def render_page(self, q, page, filters=None):
        per = self.cfg.results_per_page
        page = min(max(1, page), MAX_PAGE)
        offset = (page - 1) * per
        f = filters or {"host": "", "lang": "", "since_s": "", "until_s": "",
                        "since": None, "until": None}
        results, total = ([], 0)
        facets = {"hosts": [], "langs": []}
        if q:
            results, total = self._do_search(q, per, offset, f)
            facets = self.storage.search_facets(
                q, host=f["host"] or None, since=f["since"], until=f["until"],
                lang=f["lang"] or None)
        lang_opts = "".join(
            f"<option value=\"{c}\"{' selected' if f['lang']==c else ''}>{c}</option>"
            for c in ([""] + known_languages() + (["un"] if "un" not in known_languages() else [])))
        parts = [
            "<!doctype html><html lang=en><head><meta charset=utf-8>",
            "<meta name=viewport content='width=device-width,initial-scale=1'>",
            f"<title>{html.escape(q) + ' - ' if q else ''}onion search</title>",
            f"<style>{PAGE_STYLE}</style></head><body>",
            "<header><a href='/'><h1>onion search</h1></a></header>",
            "<form action='/search' method='get'>",
            "<div class=row>",
            f"<input type=text name=q value=\"{html.escape(q, quote=True)}\" "
            "placeholder='search indexed .onion pages' autofocus>",
            "<button type=submit>Search</button></div>",
            "<div class='row filters'>",
            f"<input type=text name=host value=\"{html.escape(f['host'], quote=True)}\" "
            "placeholder='host filter (x.onion)'>",
            f"<label>lang <select name=lang>{lang_opts}</select></label>",
            f"<label>from <input type=date name=since value=\"{html.escape(f['since_s'], quote=True)}\"></label>",
            f"<label>to <input type=date name=until value=\"{html.escape(f['until_s'], quote=True)}\"></label>",
            "</div></form>",
        ]
        if q:
            if total == 0:
                parts.append("<p class=muted>No results.</p>")
            else:
                lo = offset + 1
                hi = min(offset + per, total)
                parts.append(
                    f"<p class=muted>Results {lo}-{hi} of {total} match(es)"
                    + (" · near-duplicate mirrors collapsed" if getattr(
                        self.cfg, "collapse_duplicates", True) else "")
                    + "</p>")
                parts.append(self._facet_html(q, f, facets))
                for r in results:
                    title = html.escape(r["title"] or r["url"])
                    url = html.escape(r["url"])
                    snip = _safe_snippet(r["snippet"])
                    lg = html.escape(r.get("lang") or "un")
                    parts.append(
                        "<div class=result>"
                        f"<div class=title>{title}</div>"
                        f"<div class=url>{url}</div>"
                        f"<div class=snip>{snip}</div>"
                        f"<div class=meta>host: {html.escape(r['host'])} · "
                        f"lang: {lg} · last seen: {_fmt_time(r['last_seen'])}</div>"
                        "</div>"
                    )
                parts.append("<div class=nav>")
                if page > 1:
                    parts.append(
                        f"<a href='/search?{self._qs(q, page-1, f)}'>« Prev</a>")
                if offset + per < total:
                    parts.append(
                        f"<a href='/search?{self._qs(q, page+1, f)}'>Next »</a>")
                parts.append("</div>")
        else:
            n = self.storage.counter("pages_stored")
            parts.append(
                f"<p class=muted>{n} pages indexed. "
                "Enter a query above. This index serves .onion pages only.</p>")
        parts.append(
            "<footer>No JavaScript. No logging. Bound to localhost. "
            "Operator is responsible for abuse filtering.</footer>")
        parts.append("</body></html>")
        return "".join(parts)

    def _qs(self, q, page, f):
        d = {"q": q, "page": page}
        for k in ("host", "lang", "since_s", "until_s"):
            v = f.get(k)
            if v:
                d[{"since_s": "since", "until_s": "until"}.get(k, k)] = v
        return urlencode(d)

    def _facet_html(self, q, f, facets):
        bits = []
        if facets.get("hosts"):
            hs = " ".join(
                f"<a href='/search?{self._qs(q, 1, dict(f, host=h['host']))}'>"
                f"{html.escape(h['host'][:16])}… ({h['n']})</a>"
                for h in facets["hosts"][:6])
            bits.append("hosts: " + hs)
        if facets.get("langs"):
            ls = " ".join(
                f"<a href='/search?{self._qs(q, 1, dict(f, lang=l['lang']))}'>"
                f"{html.escape(l['lang'])} ({l['n']})</a>"
                for l in facets["langs"][:6])
            bits.append("langs: " + ls)
        return "<div class=facets>" + " &nbsp;·&nbsp; ".join(bits) + "</div>" if bits else ""

    # --------------------------------------------------------------- JSON
    def api(self, q, page, filters=None):
        per = self.cfg.results_per_page
        page = min(max(1, page), MAX_PAGE)
        offset = (page - 1) * per
        f = filters or {"host": "", "lang": "", "since": None, "until": None,
                        "since_s": "", "until_s": ""}
        results, total = ([], 0)
        facets = {"hosts": [], "langs": []}
        if q:
            results, total = self._do_search(q, per, offset, f)
            facets = self.storage.search_facets(
                q, host=f["host"] or None, since=f["since"], until=f["until"],
                lang=f["lang"] or None)
        return {
            "query": q,
            "page": page,
            "per_page": per,
            "total": total,
            "filters": {"host": f["host"], "lang": f["lang"],
                        "since": f["since_s"], "until": f["until_s"]},
            "facets": facets,
            "results": [
                {
                    "url": r["url"],
                    "title": r["title"],
                    "host": r["host"],
                    "lang": r.get("lang"),
                    "snippet": _strip_marks(r["snippet"]),
                    "last_seen": r["last_seen"],
                    "fetched_at": r["fetched_at"],
                }
                for r in results
            ],
        }

    # ------------------------------------------------------------ metrics
    def metrics_text(self):
        m = self.storage.metrics()
        lines = []
        for k, v in m.items():
            lines.append(f"# TYPE onioncrawler_{k} gauge")
            lines.append(f"onioncrawler_{k} {v}")
        return "\n".join(lines) + "\n"

    def health(self):
        m = self.storage.metrics()
        return {"status": "ok", "pages": m["pages"], "hosts": m["hosts"],
                "frontier_queued": m["frontier_queued"],
                "hosts_up": m["hosts_up"], "hosts_down": m["hosts_down"],
                "hosts_dead": m["hosts_dead"]}

    # -------------------------------------------------------- opensearch
    # ------------------------------------------------------- entity pivot
    def _find_rows(self, kind, value, page):
        kind = (kind or "").strip().lower()
        value = (value or "").strip()
        per = self.cfg.results_per_page
        page = min(max(1, page), MAX_PAGE)
        offset = (page - 1) * per
        if kind in entities_mod.KINDS and value and len(value) <= 256:
            return kind, value, self.storage.find_by_entity(
                kind, value, limit=per, offset=offset)
        return kind, value, []

    def render_find(self, kind, value, page=1):
        kind, value, rows = self._find_rows(kind, value, page)
        parts = [
            "<!doctype html><html lang=en><head><meta charset=utf-8>",
            f"<title>find {html.escape(kind)} - onion search</title>",
            f"<style>{PAGE_STYLE}</style></head><body>",
            "<p><a href='/'>&larr; search</a></p>",
            f"<h1>Pages carrying {html.escape(kind or 'entity')}: "
            f"<code>{html.escape(value)}</code></h1>",
        ]
        if not rows:
            parts.append("<div class=empty>No indexed onion carries this "
                         f"{html.escape(kind or 'entity')}.</div>")
        for r in rows:
            parts.append(
                "<div class=result><div class=name>"
                f"<a href=\"{html.escape(r['url'], quote=True)}\" rel=noopener>"
                f"{html.escape(r['title'] or r['url'])}</a></div>"
                f"<div class=meta>host: {html.escape(r['host'])}</div></div>")
        parts.append("</body></html>")
        return "".join(parts).encode("utf-8")

    def api_find(self, kind, value, page=1):
        kind, value, rows = self._find_rows(kind, value, page)
        return {"kind": kind, "value": value, "page": page,
                "results": [{"url": r["url"], "host": r["host"],
                             "title": r["title"]} for r in rows]}

    # ---------------------------------------------------------- statistics
    def render_stats(self):
        st = self.storage.stats()
        ents = self.storage.entity_counts()

        def row(k, v):
            return (f"<tr><td>{html.escape(str(k))}</td>"
                    f"<td class=mono>{html.escape(str(v))}</td></tr>")

        parts = [
            "<!doctype html><html lang=en><head><meta charset=utf-8>",
            "<title>index stats - onion search</title>",
            f"<style>{PAGE_STYLE}</style></head><body>",
            "<p><a href='/'>&larr; search</a></p>",
            "<h1>Index statistics</h1><table class=list><tbody>",
            row("pages indexed", st.get("pages", 0)),
            row("hosts known", st.get("hosts", 0)),
            row("hosts up", st.get("hosts_up", 0)),
            row("hosts down", st.get("hosts_down", 0)),
            row("hosts dead", st.get("hosts_dead", 0)),
            row("link edges", st.get("link_edges", 0)),
            row("duplicates skipped", st.get("duplicates", 0)),
            row("trap events", st.get("trap_events", 0)),
        ]
        for k in ("pgp", "btc", "xmr", "eth"):
            if ents.get(k):
                parts.append(row("entities: " + k, ents[k]))
        parts.append("</tbody></table>")
        blocked = st.get("trapped_hosts", []) or []
        if blocked:
            parts.append("<h2>Blocked / trapped hosts</h2><ul>")
            for b in blocked[:50]:
                parts.append(
                    f"<li class=mono>{html.escape(b.get('host', ''))} "
                    f"<span class=muted>"
                    f"{html.escape(b.get('trapped_reason') or '')}</span></li>")
            parts.append("</ul>")
        parts.append("</body></html>")
        return "".join(parts).encode("utf-8")

    def banned_md5_text(self):
        """Ahmia-format republish of our host blocklist (md5 of domain, one per
        line).  Empty when no abuse filter / no blocked hosts are configured."""
        if not self.abuse:
            return ""
        lines = self.abuse.banned_host_md5s()
        return ("\n".join(lines) + "\n") if lines else ""

    def render_cached(self, url):
        """Read-only cached text snapshot of an indexed page (for dead onions)."""
        snap = self.storage.get_page_snapshot((url or "").strip()) \
            if url else None
        parts = ["<!doctype html><html lang=en><head><meta charset=utf-8>",
                 "<title>cached - onion search</title>",
                 f"<style>{PAGE_STYLE}</style></head><body>",
                 "<p><a href='/'>&larr; search</a></p>"]
        if not snap:
            parts.append("<div class=empty>No cached copy of that URL is "
                         "indexed.</div>")
        else:
            parts.append("<h1>%s</h1>" % html.escape(snap["title"] or snap["url"]))
            parts.append(
                "<p class=muted>Cached text snapshot of <code>%s</code> — the "
                "live onion may be offline. Text only; no scripts or media.</p>"
                % html.escape(snap["url"]))
            parts.append("<pre class=cached style='white-space:pre-wrap'>%s</pre>"
                         % html.escape(snap["body"] or ""))
        parts.append("</body></html>")
        return "".join(parts).encode("utf-8")

    def opensearch_xml(self):
        """A well-formed OpenSearch descriptor so a browser can add this search
        engine. Templates are relative so a published onion / any bind address
        works without hardcoding the external host."""
        return (
            '<?xml version="1.0" encoding="UTF-8"?>\n'
            '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">\n'
            '  <ShortName>onion search</ShortName>\n'
            '  <Description>Search indexed .onion pages</Description>\n'
            '  <InputEncoding>UTF-8</InputEncoding>\n'
            '  <Url type="text/html" method="get" '
            'template="/search?q={searchTerms}"/>\n'
            '  <Url type="application/json" method="get" '
            'template="/api/search?q={searchTerms}"/>\n'
            '</OpenSearchDescription>\n'
        )

    # --------------------------------------------------------- blocklist
    def add_blocklist(self, kind, value):
        """Append a host/keyword to the right blocklist file and reconcile the
        live index against it. Returns (http_status, json_dict). Consumed by the
        AstrX blocklist editor via POST /blocklist -- keep the contract simple.
        """
        kind = (kind or "").strip().lower()
        # Strip CR/LF and other control chars BEFORE anything else: the value is
        # appended verbatim to a blocklist file, so an embedded newline could
        # inject extra blocklist lines / a '#'-comment. (A host value is further
        # constrained by normalize_host + is_darknet_host below.)
        value = "".join(c for c in (value or "")
                        if ord(c) >= 0x20 and c != "\x7f").strip()
        if kind not in ("host", "keyword") or not value:
            return 400, {"error": "kind must be host|keyword and value non-empty"}
        if kind == "host":
            h = normalize_host(value)
            if not is_darknet_host(h, allow_v2=getattr(self.cfg, "allow_v2", False),
                                   allow_i2p=getattr(self.cfg, "enable_i2p", False)):
                return 400, {"error": "value is not a valid .onion/.i2p host"}
            path = getattr(self.cfg, "blocklist_hosts_path", None)
            line = h
        else:
            path = getattr(self.cfg, "blocklist_keywords_path", None)
            line = value
        if not path:
            return 500, {"error": f"no blocklist path configured for {kind}"}
        from .abuse import load_abuse_filter
        with self._blocklist_lock:
            try:
                with open(path, "a", encoding="utf-8") as fh:
                    fh.write(line + "\n")
            except OSError as e:
                return 500, {"error": f"could not write blocklist: {e}"}
            # reload from files so in-memory == persisted, then reconcile the
            # index (blocks the host / removes keyword-hit pages immediately).
            self.abuse = load_abuse_filter(
                getattr(self.cfg, "blocklist_hosts_path", None),
                getattr(self.cfg, "blocklist_keywords_path", None),
                getattr(self.cfg, "blocklist_media_path", None),
                host_md5_path=getattr(self.cfg, "blocklist_host_md5_path", None))
            applied = self.storage.apply_abuse_blocklist(self.abuse)
        return 200, {"ok": True, "kind": kind, "value": line, "applied": applied}


def _safe_snippet(snip):
    if not snip:
        return ""
    esc = html.escape(snip)
    return esc.replace("&lt;mark&gt;", "<mark>").replace("&lt;/mark&gt;", "</mark>")


def _strip_marks(snip):
    if not snip:
        return ""
    return snip.replace("<mark>", "").replace("</mark>", "")


def make_handler(app: SearchApp):
    class Handler(BaseHTTPRequestHandler):
        server_version = "OnionSearch/1.0"

        def log_message(self, *a):  # privacy: no request logging
            pass

        # -- helpers ----------------------------------------------------
        def _client(self):
            try:
                return self.client_address[0]
            except Exception:
                return "?"

        def _send(self, code, body, ctype="text/html; charset=utf-8", headers=None):
            data = body.encode("utf-8") if isinstance(body, str) else body
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(data)))
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Referrer-Policy", "no-referrer")
            self.send_header(
                "Content-Security-Policy",
                "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'")
            for k, v in (headers or {}).items():
                self.send_header(k, v)
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(data)

        def _json(self, code, obj):
            self._send(code, json.dumps(obj, ensure_ascii=False),
                       "application/json; charset=utf-8")

        def _rate_ok(self):
            cfg = app.cfg
            if not getattr(cfg, "rate_limit_enabled", True):
                return True
            return app.limiter.allow(self._client())

        def _metrics_authorized(self, u):
            """Gate for /metrics + /health. Open (True) unless the operator set a
            ``metrics_token``, in which case a matching ``?token=`` /
            ``X-Metrics-Token`` header / ``Authorization: Bearer`` is required
            (constant-time compare). /healthz is never routed through here."""
            token = getattr(app.cfg, "metrics_token", "") or ""
            if not token:
                return True  # gate disabled (default): open for pollers
            provided = (parse_qs(u.query).get("token", [""])[0] or "")
            if not provided:
                provided = self.headers.get("X-Metrics-Token", "") or ""
            if not provided:
                auth = self.headers.get("Authorization", "") or ""
                if auth.startswith("Bearer "):
                    provided = auth[7:]
            return hmac.compare_digest(provided.encode("utf-8"),
                                       token.encode("utf-8"))

        def _check_basic_auth(self):
            cfg = app.cfg
            hdr = self.headers.get("Authorization", "")
            if not hdr.startswith("Basic "):
                return False
            try:
                raw = base64.b64decode(hdr[6:]).decode("utf-8", "replace")
                user, _, pw = raw.partition(":")
                # Compare on BYTES: hmac.compare_digest raises TypeError on a str
                # containing non-ASCII, which a crafted Authorization header could
                # trigger (an unauthenticated 500 / dropped connection) and which
                # would also lock out a non-ASCII admin password. Bytes always
                # compare in constant time; any error -> clean auth failure (401).
                return (hmac.compare_digest(user.encode("utf-8"),
                                            cfg.admin_user.encode("utf-8")) and
                        hmac.compare_digest(pw.encode("utf-8"),
                                            cfg.admin_pass.encode("utf-8")))
            except Exception:
                return False

        def _admin_gate(self):
            """None = admin disabled (no creds); True = authed; False = bad creds."""
            cfg = app.cfg
            if not (cfg.admin_user and cfg.admin_pass):
                return None
            return self._check_basic_auth()

        def _read_form(self):
            try:
                n = int(self.headers.get("Content-Length", "0"))
            except ValueError:
                n = 0
            n = max(0, min(n, 1_000_000))  # cap admin bodies at 1 MB
            body = self.rfile.read(n).decode("utf-8", "replace") if n else ""
            return parse_qs(body)

        # -- routing ----------------------------------------------------
        def do_HEAD(self):
            self.do_GET()

        def do_GET(self):
            u = urlparse(self.path)
            # /healthz: trivial, always-open liveness probe (container
            # healthcheck). It emits no counters and never needs a token.
            if u.path == "/healthz":
                return self._send(200, "ok", "text/plain")
            # /health (JSON) and /metrics (Prometheus text) are rate-exempt so a
            # monitor always works, and emit ONLY aggregate counters. When an
            # operator sets a metrics token they are gated; by default (no token)
            # they stay open so the compose /metrics poller keeps working.
            if u.path in ("/health", "/metrics"):
                if not self._metrics_authorized(u):
                    return self._send(401, "unauthorized", "text/plain",
                                      {"WWW-Authenticate": 'Bearer realm="metrics"'})
                if u.path == "/health":
                    return self._json(200, app.health())
                return self._send(200, app.metrics_text(),
                                  "text/plain; version=0.0.4; charset=utf-8")
            if not self._rate_ok():
                return self._send(429, "rate limited", "text/plain",
                                  {"Retry-After": "1"})
            qs = parse_qs(u.query)
            q = (qs.get("q", [""])[0] or "").strip()
            try:
                page = int(qs.get("page", ["1"])[0])
            except ValueError:
                page = 1
            filters = app._clean_filters(qs)
            if u.path in ("/", "/search"):
                self._send(200, app.render_page(q, page, filters))
            elif u.path == "/api/search":
                self._json(200, app.api(q, page, filters))
            elif u.path == "/find":
                kind = (qs.get("kind", [""])[0] or "")
                val = (qs.get("value", [""])[0] or "")
                self._send(200, app.render_find(kind, val, page))
            elif u.path == "/api/find":
                kind = (qs.get("kind", [""])[0] or "")
                val = (qs.get("value", [""])[0] or "")
                self._json(200, app.api_find(kind, val, page))
            elif u.path == "/stats":
                self._send(200, app.render_stats())
            elif u.path == "/cached":
                self._send(200, app.render_cached(
                    (qs.get("url", [""])[0] or "")))
            elif u.path == "/blocklist/banned.md5":
                self._send(200, app.banned_md5_text(),
                           "text/plain; charset=utf-8")
            elif u.path == "/robots.txt":
                self._send(200, "User-agent: *\nDisallow: /\n", "text/plain")
            elif u.path == "/opensearch.xml":
                self._send(200, app.opensearch_xml(),
                           "application/opensearchdescription+xml; charset=utf-8")
            else:
                self._send(404, "<h1>404</h1>")

        def _blocklist_token_ok(self, form):
            """Gate for POST /blocklist. None => disabled (admin_token unset =>
            403); True/False => authed / bad token. Constant-time compare over
            X-Admin-Token header, `token` form field, or Authorization: Bearer."""
            token = getattr(app.cfg, "admin_token", "") or ""
            if not token:
                return None
            provided = self.headers.get("X-Admin-Token", "") or ""
            if not provided:
                provided = (form.get("token") or [""])[0] or ""
            if not provided:
                auth = self.headers.get("Authorization", "") or ""
                if auth.startswith("Bearer "):
                    provided = auth[7:]
            return hmac.compare_digest(provided.encode("utf-8"),
                                       token.encode("utf-8"))

        def _do_blocklist(self):
            # read the (bounded) form once; the token may be a form field
            form = self._read_form()
            qs = parse_qs(urlparse(self.path).query)
            gate = self._blocklist_token_ok(form)
            if gate is None:
                return self._json(403, {"error": "blocklist admin disabled: set --admin-token"})
            if not gate:
                return self._json(403, {"error": "invalid admin token"})
            kind = (form.get("kind") or qs.get("kind") or [""])[0]
            value = (form.get("value") or qs.get("value") or [""])[0]
            code, body = app.add_blocklist(kind, value)
            app.log("admin_blocklist", client=self._client(),
                    kind=kind, ok=(code == 200))
            return self._json(code, body)

        def do_POST(self):
            u = urlparse(self.path)
            if not self._rate_ok():
                return self._send(429, "rate limited", "text/plain",
                                  {"Retry-After": "1"})
            # POST /blocklist: token-gated (admin_token) AstrX blocklist editor.
            if u.path == "/blocklist":
                return self._do_blocklist()
            if u.path not in ("/add", "/purge", "/recrawl"):
                return self._send(404, "<h1>404</h1>")

            # /add may be public if explicitly enabled; everything else is admin.
            public_add = (u.path == "/add" and
                          getattr(app.cfg, "allow_public_submit", False))
            if not public_add:
                gate = self._admin_gate()
                if gate is None:
                    return self._json(403, {"error": "admin disabled: set admin_user/admin_pass"})
                if not gate:
                    return self._send(401, json.dumps({"error": "auth required"}),
                                      "application/json; charset=utf-8",
                                      {"WWW-Authenticate": "Basic realm=onioncrawler-admin"})

            form = self._read_form()
            qs = parse_qs(u.query)

            def _param(name):
                return (form.get(name) or qs.get(name) or [])

            if u.path == "/add":
                urls = list(_param("url"))
                for blob in _param("urls"):
                    urls.extend(blob.splitlines())
                if not urls:
                    return self._json(400, {"error": "no url(s) provided"})
                # Untrusted (public) submissions honour the frontier trap caps and
                # a per-request URL count cap; authed/operator submissions are
                # trusted seeds (caps=None -> forced enqueue, no per-call limit).
                if public_add:
                    caps = {
                        "max_unique_urls": getattr(app.cfg, "max_unique_urls", 0),
                        "max_pages_per_host": getattr(app.cfg, "max_pages_per_host", 0),
                        "max_urls_per_template": getattr(app.cfg, "max_urls_per_template", 0),
                        "max_urls_per_skeleton": getattr(app.cfg, "max_urls_per_skeleton", 0),
                    }
                    max_urls = getattr(app.cfg, "max_public_add_urls", 100)
                else:
                    caps = None
                    max_urls = None
                res = submit_many(app.storage, app.abuse, urls,
                                  allow_v2=getattr(app.cfg, "allow_v2", False),
                                  caps=caps, max_urls=max_urls,
                                  allow_i2p=getattr(app.cfg, "enable_i2p", False))
                app.log("admin_add", client=self._client(),
                        ok=res["ok"], dup=res["dup"], blocked=res["blocked"])
                return self._json(200, res)

            if u.path == "/purge":
                hosts = _param("host")
                if not hosts:
                    return self._json(400, {"error": "no host provided"})
                out = [app.storage.purge_host(h) for h in hosts]
                app.log("admin_purge", client=self._client(),
                        hosts=[o["host"][:16] for o in out])
                return self._json(200, {"purged": out})

            if u.path == "/recrawl":
                n = app.storage.mark_recrawl_due(
                    default_interval=getattr(app.cfg, "recrawl_ttl", 0.0))
                app.log("admin_recrawl", client=self._client(), due=n)
                return self._json(200, {"recrawl_due": n})

    return Handler


def serve(config, storage=None, abuse=None, log=None):
    st = storage or Storage(config.db_path)
    # Reconcile the index against the current abuse blocklist so that hosts /
    # keywords added AFTER indexing are immediately removed from results.
    if abuse is None:
        try:
            from .abuse import load_abuse_filter
            abuse = load_abuse_filter(
                getattr(config, "blocklist_hosts_path", None),
                getattr(config, "blocklist_keywords_path", None),
                getattr(config, "blocklist_media_path", None),
                host_md5_path=getattr(config, "blocklist_host_md5_path", None),
            )
        except Exception:
            abuse = None
    if abuse is not None and (abuse.hosts or abuse.keywords):
        st.apply_abuse_blocklist(abuse)
    app = SearchApp(st, config, abuse=abuse, log=log)
    handler = make_handler(app)
    httpd = ThreadingHTTPServer((config.bind_host, config.bind_port), handler)
    return httpd, st
