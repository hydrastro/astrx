"""No-JavaScript search UI + JSON API, built on :mod:`http.server`.

Routes
------
``/``                 search box (and results when ``?q=`` is present)
``/search?q=&page=``  same as ``/`` (canonical results path; ``&type=images``
                      switches to the image vertical)
``/api/search?q=&page=``  JSON results
``/images?q=``        image vertical (no-JS; thumbnails load from their ORIGINAL
                      remote URL in the browser -- the server fetches nothing)
``/similar?id=`` / ``?url=``  more-like-this (SimHash neighbours), no-JS view
``/suggest?q=``       OpenSearch Suggestions JSON ``[query, [terms...]]``
``/opensearch.xml``   OpenSearch 1.1 description document (add-as-search-engine)
``/about`` / ``/stats``   index statistics
``/style.css``        the stylesheet
``/healthz``          liveness probe

Everything is server-rendered and works with scripting disabled.  All dynamic
text is HTML-escaped; result snippets are produced pre-escaped by
:func:`ranking.make_snippet` (with only ``<mark>`` markup) and inserted as-is.
"""

import base64
import binascii
import collections
import hmac
import html
import json
import logging
import re
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlsplit

from . import index, ranking, suggest

PAGE_SIZE = 10
API_MAX_LIMIT = 200  # hard cap on /api/search?limit= (federation top-N pull)
IMAGE_LIMIT = 30       # max image results per image-search page
MLT_LIMIT = 20         # max more-like-this neighbours shown
SUGGEST_MAX_QUERY = 128  # hard cap on /suggest q at the edge (echo + parse bound)
log = logging.getLogger("websearch.server")

# Endpoints that skip auth + rate limiting (health, static, monitoring, and the
# self-describing OpenSearch descriptor -- a static document with no index data).
_OPEN_PATHS = {"/healthz", "/style.css", "/favicon.ico", "/metrics",
               "/opensearch.xml"}

# A conservative host[:port] validator for the OpenSearch descriptor's Host
# header (hostname or bracketed IPv6, optional port); anything else falls back
# to the bound socket address.
_HOST_RE = re.compile(r"^(?:\[[0-9A-Fa-f:]+\]|[A-Za-z0-9._-]+)(?::\d{1,5})?$")


class Metrics:
    """Thread-safe request/crawl counters exposed at ``/metrics``."""

    def __init__(self):
        self._c = collections.Counter()
        self._lock = threading.Lock()

    def inc(self, name, n=1):
        with self._lock:
            self._c[name] += n

    def snapshot(self):
        with self._lock:
            return dict(self._c)


class RateLimiter:
    """Per-client-IP token bucket: *rate* tokens/sec, capacity *burst*."""

    def __init__(self, rate, burst):
        self.rate = float(rate)
        self.burst = float(burst)
        self._state = {}
        self._lock = threading.Lock()

    def allow(self, key):
        now = time.monotonic()
        with self._lock:
            tokens, last = self._state.get(key, (self.burst, now))
            tokens = min(self.burst, tokens + (now - last) * self.rate)
            if tokens < 1.0:
                self._state[key] = (tokens, now)
                return False
            self._state[key] = (tokens - 1.0, now)
            return True


class PopularQueries:
    """Bounded, in-process most-frequent-query tracker (feeds /suggest).

    The query server runs on a READ-ONLY database handle by design, so popular
    queries are tracked in memory rather than written back to the index.  The
    map is capped: once it exceeds ``cap`` distinct queries it is trimmed to the
    ``cap`` most frequent, so it cannot grow without bound.
    """

    def __init__(self, cap=512):
        self._c = collections.Counter()
        self._cap = cap
        self._lock = threading.Lock()

    def record(self, q):
        q = (q or "").strip()
        if not q or len(q) > 128:
            return
        with self._lock:
            self._c[q.lower()] += 1
            if len(self._c) > self._cap:
                for key, _n in self._c.most_common()[self._cap:]:
                    del self._c[key]

    def top(self, n=50):
        with self._lock:
            return [q for q, _n in self._c.most_common(n)]


STYLE = """
:root { color-scheme: light dark; }
* { box-sizing: border-box; }
body { font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
       Helvetica, Arial, sans-serif; margin: 0; background: #fafafa;
       color: #1a1a1a; }
a { color: #1a56db; text-decoration: none; }
a:hover { text-decoration: underline; }
header { background: #fff; border-bottom: 1px solid #e5e5e5; padding: 18px 20px; }
.wrap { max-width: 760px; margin: 0 auto; }
.brand { font-weight: 700; font-size: 20px; letter-spacing: -.3px; color:#111; }
.brand span { color: #1a56db; }
form.search { display: flex; gap: 8px; margin-top: 12px; }
form.search input[type=text] { flex: 1; padding: 11px 14px; font-size: 16px;
       border: 1px solid #cbcbcb; border-radius: 8px; background:#fff; }
form.search button { padding: 11px 18px; font-size: 15px; border: 0;
       border-radius: 8px; background: #1a56db; color: #fff; cursor: pointer; }
main { padding: 20px; }
.meta { color: #666; font-size: 13px; margin: 4px 0 18px; }
.result { margin: 0 0 22px; }
.result .url { color: #0a7d33; font-size: 13px; word-break: break-all; }
.result h2 { font-size: 18px; margin: 2px 0 3px; font-weight: 600; }
.result .snippet { color: #333; font-size: 14px; }
.result .sub { color: #777; font-size: 12px; margin-top: 3px; }
mark { background: #fff2ac; color: inherit; padding: 0 1px; border-radius: 2px; }
.pager { margin: 26px 0; display: flex; gap: 14px; align-items: center; }
.pager a { padding: 7px 14px; border: 1px solid #cbcbcb; border-radius: 8px;
       background:#fff; }
.empty { color:#555; }
table.stats { border-collapse: collapse; }
table.stats td, table.stats th { text-align: left; padding: 4px 18px 4px 0; }
footer { color:#999; font-size:12px; padding: 24px 20px; }
code { background:#eee; padding:1px 5px; border-radius:4px; }
.tabs { display:flex; gap:6px; margin: 2px 0 16px; }
.tabs a.tab { padding:6px 14px; border:1px solid #cbcbcb; border-radius:8px;
       background:#fff; color:#333; font-size:14px; }
.tabs a.tab.active { background:#1a56db; border-color:#1a56db; color:#fff; }
.imggrid { display:flex; flex-wrap:wrap; gap:14px; }
figure.imgcard { margin:0; width:180px; }
figure.imgcard img.thumb { width:180px; height:135px; object-fit:cover;
       background:#eee; border:1px solid #e5e5e5; border-radius:8px; }
figure.imgcard .thumb.noimg { width:180px; height:135px; display:flex;
       align-items:center; justify-content:center; background:#eee;
       border:1px solid #e5e5e5; border-radius:8px; color:#888;
       font-size:13px; text-transform:uppercase; letter-spacing:.05em; }
figure.imgcard figcaption { font-size:12px; color:#444; margin-top:4px;
       overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
figure.imgcard .imghost { display:block; color:#0a7d33; }
@media (prefers-color-scheme: dark) {
  body { background:#161616; color:#e8e8e8; }
  header, form.search input[type=text] { background:#1f1f1f; border-color:#333; }
  .brand { color:#f2f2f2; } a { color:#7aa2f7; }
  .result .url { color:#5fbf7f; } .result .snippet { color:#cfcfcf; }
  mark { background:#5b5220; color:#fff; }
  .pager a { background:#1f1f1f; border-color:#333; }
  .tabs a.tab { background:#1f1f1f; border-color:#333; color:#cfcfcf; }
  .tabs a.tab.active { background:#1a56db; border-color:#1a56db; color:#fff; }
  figure.imgcard figcaption { color:#cfcfcf; }
  figure.imgcard .imghost { color:#5fbf7f; }
}
"""


def _page(title, body):
    return (
        "<!doctype html><html lang=en><head><meta charset=utf-8>"
        "<meta name=viewport content='width=device-width, initial-scale=1'>"
        "<title>%s</title><link rel=stylesheet href=/style.css>"
        "<link rel=search type='application/opensearchdescription+xml' "
        "title='astrx search' href=/opensearch.xml></head>"
        "<body>%s</body></html>" % (html.escape(title), body)
    )


def _opensearch_xml(base):
    """A well-formed OpenSearch 1.1 description document for *base*.

    *base* (``scheme://host[:port]``) is XML-escaped; the ``{searchTerms}``
    template macro is kept literal.  Points at the HTML results endpoint, the
    JSON API and the suggestions endpoint.
    """
    b = html.escape(base, quote=True)
    return (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">\n'
        '  <ShortName>astrx search</ShortName>\n'
        '  <Description>Zero-dependency clearnet search engine '
        '(crawler + FTS5 index + BM25).</Description>\n'
        '  <InputEncoding>UTF-8</InputEncoding>\n'
        '  <Url type="text/html" method="get" '
        'template="%s/search?q={searchTerms}"/>\n'
        '  <Url type="application/json" method="get" '
        'template="%s/api/search?q={searchTerms}"/>\n'
        '  <Url type="application/x-suggestions+json" method="get" '
        'template="%s/suggest?q={searchTerms}"/>\n'
        '</OpenSearchDescription>\n' % (b, b, b)
    )


def _vertical_tabs(q, active):
    """Web / News / Images / Videos / Files switcher, preserving the query."""
    qs = ("?" + urlencode({"q": q})) if q else ""

    def tab(href, label, key):
        cls = "tab active" if key == active else "tab"
        return ("<a class='%s' href='%s'>%s</a>"
                % (cls, html.escape(href + qs, quote=True), label))

    def vtab(label, key):   # /search with a ?type= param (news / files verticals)
        p = {"type": key}
        if q:
            p["q"] = q
        href = "/search?" + urlencode(p)
        cls = "tab active" if key == active else "tab"
        return ("<a class='%s' href='%s'>%s</a>"
                % (cls, html.escape(href, quote=True), label))

    return ("<div class=tabs>%s%s%s%s%s</div>"
            % (tab("/search", "Web", "web"),
               vtab("News", "news"),
               tab("/images", "Images", "images"),
               tab("/videos", "Videos", "videos"),
               vtab("Files", "files")))


def _fmt_duration(secs):
    """Whole seconds -> ``H:MM:SS`` / ``M:SS`` (empty string if unknown)."""
    if secs is None:
        return ""
    try:
        s = int(secs)
    except (TypeError, ValueError):
        return ""
    if s < 0:
        return ""
    h, rem = divmod(s, 3600)
    m, sec = divmod(rem, 60)
    if h:
        return "%d:%02d:%02d" % (h, m, sec)
    return "%d:%02d" % (m, sec)


def _header(q=""):
    return (
        "<header><div class=wrap>"
        "<a class=brand href='/'>astrx<span>search</span></a>"
        "<form class=search method=get action='/search'>"
        "<input type=text name=q value='%s' placeholder='Search the crawl…' "
        "autofocus autocomplete=off>"
        "<button type=submit>Search</button></form>"
        "</div></header>" % html.escape(q, quote=True)
    )


def _fmt_date(ts):
    if not ts:
        return ""
    try:
        return time.strftime("%Y-%m-%d", time.gmtime(ts))
    except Exception:
        return ""


def _active_filters(parsed):
    """Human-readable summary of the operators applied to a query (or '')."""
    if parsed is None:
        return ""
    bits = []
    if parsed.site:
        bits.append("site:" + parsed.site)
    if parsed.lang:
        bits.append("lang:" + parsed.lang)
    if parsed.filetype:
        bits.append("filetype:" + parsed.filetype)
    if parsed.intitle:
        bits.append("intitle:" + " ".join(parsed.intitle))
    if parsed.after is not None:
        bits.append("after:" + _fmt_date(parsed.after))
    if parsed.before is not None:
        bits.append("before:" + _fmt_date(parsed.before))
    if not bits:
        return ""
    return ("<div class=meta>Filters: %s</div>"
            % html.escape(" · ".join(bits)))


def _result_row(r, similar=True):
    """Render one result row (shared by web results and more-like-this).

    ``r`` is a :class:`ranking.SearchResult`.  ``r.snippet`` is already-escaped
    HTML (only ``<mark>`` markup); every other field is escaped here.
    """
    parts = ["<div class=result>"]
    parts.append("<div class=url>%s</div>" % html.escape(r.url))
    parts.append("<h2><a href='%s'>%s</a></h2>"
                 % (html.escape(r.url, quote=True),
                    html.escape(r.title or r.url)))
    if r.snippet:
        parts.append("<div class=snippet>%s</div>" % r.snippet)
    sub = html.escape(r.host or "")
    d = _fmt_date(r.fetched_at)
    if d:
        sub += " &middot; " + d
    if r.lang:
        sub += " &middot; " + html.escape(r.lang)
    if similar:
        sub += (" &middot; <a href='/similar?%s'>similar</a>"
                % urlencode({"url": r.url}))
    parts.append("<div class=sub>%s</div>" % sub)
    parts.append("</div>")
    return "".join(parts)


def _render_results(q, results, total, elapsed, page, parsed=None,
                    active="web"):
    parts = ["<main><div class=wrap>"]
    parts.append(_vertical_tabs(q, active))
    _pg = {"q": q}
    if active in ("news", "files"):
        _pg["type"] = active
    parts.append(_active_filters(parsed))
    parts.append(
        "<div class=meta>About %d result%s (%.3f seconds)</div>"
        % (total, "" if total == 1 else "s", elapsed)
    )
    if not results:
        parts.append(
            "<p class=empty>No pages matched <strong>%s</strong>. Try fewer or "
            "different terms.</p>" % html.escape(q)
        )
    for r in results:
        parts.append(_result_row(r))

    # pagination
    last = max(1, (total + PAGE_SIZE - 1) // PAGE_SIZE)
    if last > 1:
        parts.append("<div class=pager>")
        if page > 1:
            parts.append("<a href='/search?%s'>&larr; Prev</a>"
                         % urlencode(dict(_pg, page=page - 1)))
        parts.append("<span>Page %d of %d</span>" % (page, last))
        if page < last:
            parts.append("<a href='/search?%s'>Next &rarr;</a>"
                         % urlencode(dict(_pg, page=page + 1)))
        parts.append("</div>")

    parts.append("<footer><a href='/about'>About &amp; stats</a> &middot; "
                 "<a href='/api/search?%s'>JSON API</a></footer>"
                 % urlencode({"q": q}))
    parts.append("</div></main>")
    return "".join(parts)


def _render_home():
    return (
        "<main><div class=wrap>"
        "<p class=meta>A from-scratch crawler + FTS5 inverted index + explicit "
        "ranking. Enter a query above. Supports <code>\"exact phrase\"</code>, "
        "<code>+required</code>, <code>-excluded</code> terms and the "
        "<code>site:</code>, <code>lang:</code>, <code>filetype:</code>, "
        "<code>intitle:</code>, <code>before:</code>/<code>after:</code> "
        "operators.</p>"
        "<footer><a href='/about'>About &amp; stats</a></footer>"
        "</div></main>"
    )


def _render_about(st):
    def rows(pairs):
        return "".join("<tr><td>%s</td><td>%s</td></tr>"
                       % (html.escape(str(k)), html.escape(str(v)))
                       for k, v in pairs)
    body = ["<main><div class=wrap><h1>Index statistics</h1>"]
    body.append("<table class=stats>")
    body.append("<tr><td>Documents indexed</td><td>%d</td></tr>" % st["docs"])
    body.append("<tr><td>Distinct hosts</td><td>%d</td></tr>" % st["hosts"])
    body.append("<tr><td>Link edges</td><td>%d</td></tr>" % st["links"])
    if st.get("newest"):
        body.append("<tr><td>Newest fetch</td><td>%s</td></tr>"
                    % _fmt_date(st["newest"]))
        body.append("<tr><td>Oldest fetch</td><td>%s</td></tr>"
                    % _fmt_date(st["oldest"]))
    body.append("</table>")
    if st["top_hosts"]:
        body.append("<h2>Top hosts</h2><table class=stats>"
                    + rows(st["top_hosts"]) + "</table>")
    if st["languages"]:
        body.append("<h2>Languages</h2><table class=stats>"
                    + rows(st["languages"]) + "</table>")
    if st.get("frontier"):
        body.append("<h2>Frontier</h2><table class=stats>"
                    + rows(sorted(st["frontier"].items())) + "</table>")
    body.append("<footer><a href='/'>&larr; Back to search</a></footer>")
    body.append("</div></main>")
    return "".join(body)


def _render_images(q, images):
    """No-JS image results.

    Each thumbnail is a plain ``<img src>`` pointing at the ORIGINAL remote URL:
    the browser loads it directly -- the server never fetches the image, so this
    view adds no server-side network I/O and no SSRF surface.  ``referrerpolicy``
    and ``rel=noreferrer`` keep the query from leaking to the image origin.  Every
    field is HTML-escaped.
    """
    parts = ["<main><div class=wrap>", _vertical_tabs(q, "images")]
    if not q:
        parts.append("<p class=meta>Enter a query to search images harvested "
                     "from crawled pages (no image is fetched by the server).</p>")
    elif not images:
        parts.append("<p class=empty>No images matched <strong>%s</strong>.</p>"
                     % html.escape(q))
    else:
        parts.append("<div class=meta>%d image result%s</div>"
                     % (len(images), "" if len(images) == 1 else "s"))
        parts.append("<div class=imggrid>")
        for im in images:
            src = html.escape(im["src"], quote=True)
            alt = html.escape(im["alt"] or "", quote=True)
            page = html.escape(im["page_url"], quote=True)
            cap = html.escape(im["alt"] or im["title"] or im["src"])
            host = html.escape(im["host"] or "")
            parts.append(
                "<figure class=imgcard>"
                "<a href='%s' rel='noreferrer nofollow'>"
                "<img class=thumb loading=lazy referrerpolicy=no-referrer "
                "src='%s' alt='%s'></a>"
                "<figcaption>%s<span class=imghost>%s</span></figcaption>"
                "</figure>" % (page, src, alt, cap, host))
        parts.append("</div>")
    parts.append("<footer><a href='/'>&larr; Back to search</a></footer>")
    parts.append("</div></main>")
    return "".join(parts)


def _render_videos(q, videos):
    """No-JS video results.

    Each card links to the source PAGE and, when a thumbnail was harvested,
    shows a plain ``<img src>`` pointing at the ORIGINAL remote thumbnail URL:
    the browser loads it directly -- the server never fetches the thumbnail or
    the video, so this view adds no server-side network I/O and no SSRF surface.
    ``referrerpolicy``/``rel=noreferrer`` keep the query from leaking.  Every
    field is HTML-escaped.
    """
    parts = ["<main><div class=wrap>", _vertical_tabs(q, "videos")]
    if not q:
        parts.append("<p class=meta>Enter a query to search videos harvested "
                     "from crawled pages (no video or thumbnail is fetched by "
                     "the server).</p>")
    elif not videos:
        parts.append("<p class=empty>No videos matched <strong>%s</strong>.</p>"
                     % html.escape(q))
    else:
        parts.append("<div class=meta>%d video result%s</div>"
                     % (len(videos), "" if len(videos) == 1 else "s"))
        parts.append("<div class=imggrid>")
        for v in videos:
            page = html.escape(v["page_url"], quote=True)
            cap = html.escape(v["title"] or v.get("watch_url")
                              or v.get("embed_url") or v.get("video_url")
                              or "video")
            host = html.escape(v["host"] or "")
            thumb = v.get("thumbnail_url") or ""
            if thumb:
                media = ("<img class=thumb loading=lazy "
                         "referrerpolicy=no-referrer src='%s' alt=''>"
                         % html.escape(thumb, quote=True))
            else:
                media = "<div class='thumb noimg'>video</div>"
            bits = []
            if v.get("source"):
                bits.append(html.escape(v["source"]))
            dur = _fmt_duration(v.get("duration"))
            if dur:
                bits.append(html.escape(dur))
            sub = (" &middot; ".join([host] + bits) if host
                   else " &middot; ".join(bits))
            parts.append(
                "<figure class=imgcard>"
                "<a href='%s' rel='noreferrer nofollow'>%s</a>"
                "<figcaption>%s<span class=imghost>%s</span></figcaption>"
                "</figure>" % (page, media, cap, sub))
        parts.append("</div>")
    parts.append("<footer><a href='/'>&larr; Back to search</a></footer>")
    parts.append("</div></main>")
    return "".join(parts)


def _render_similar(src, rows):
    """No-JS more-like-this view, reusing the standard result-row template."""
    parts = ["<main><div class=wrap>"]
    if src is None:
        parts.append("<p class=empty>Unknown document.</p>")
    else:
        parts.append("<div class=meta>Documents similar to "
                     "<a href='%s'>%s</a></div>"
                     % (html.escape(src["url"], quote=True),
                        html.escape(src["title"] or src["url"])))
        if not rows:
            parts.append("<p class=empty>No similar documents found.</p>")
        for r in rows:
            parts.append(_result_row(r, similar=False))
    parts.append("<footer><a href='/'>&larr; Back to search</a></footer>")
    parts.append("</div></main>")
    return "".join(parts)


def _similar_rows(results):
    """Adapt more-like-this DB rows into render-ready SearchResult objects."""
    out = []
    for r in results:
        desc = r["description"] or ""
        snippet = html.escape(desc[:280]) if desc else ""
        out.append(ranking.SearchResult(
            url=r["url"], title=r["title"] or r["url"], description=desc,
            snippet=snippet, host=r["host"], fetched_at=r["fetched_at"],
            score=0.0, signals={}, lang=r["lang"]))
    return out


class Handler(BaseHTTPRequestHandler):
    server_version = "astrx-websearch/1.0"
    protocol_version = "HTTP/1.1"
    db_path = None       # set by make_server
    rate_limiter = None  # RateLimiter or None
    auth = None          # (username, password) or None
    metrics = None       # Metrics
    popular = None       # PopularQueries or None
    verbose = False

    def log_message(self, *a):  # keep the test output clean
        pass

    def _send(self, code, body, ctype="text/html; charset=utf-8", extra=None):
        self._status = code
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

    def _conn(self):
        return index.connect(self.db_path, read_only=True)

    def _query_params(self):
        qs = urlsplit(self.path).query
        p = parse_qs(qs, keep_blank_values=True)
        q = (p.get("q", [""])[0] or "").strip()
        try:
            page = max(1, int(p.get("page", ["1"])[0]))
        except (ValueError, TypeError):
            page = 1
        return q, page

    def _str_param(self, name, default=""):
        p = parse_qs(urlsplit(self.path).query, keep_blank_values=True)
        vals = p.get(name)
        if not vals:
            return default
        return (vals[0] or default)

    def _external_base(self):
        """``scheme://host[:port]`` for self-describing URLs (OpenSearch).

        Prefers the (validated) ``Host`` request header so the descriptor names
        the address the client actually reached -- important behind a Tor hidden
        service or reverse proxy -- and falls back to the bound socket address.
        The scheme is plain ``http`` (TLS/Tor is terminated upstream).
        """
        host = self.headers.get("Host", "") or ""
        if not _HOST_RE.match(host):
            addr = self.server.server_address
            host = "%s:%d" % (addr[0], addr[1])
        return "http://%s" % host

    def do_HEAD(self):
        self.do_GET()

    # ---- auth --------------------------------------------------------------
    def _authorized(self):
        """Constant-time HTTP Basic auth check against the configured creds."""
        hdr = self.headers.get("Authorization", "")
        if not hdr.startswith("Basic "):
            return False
        try:
            raw = base64.b64decode(hdr[6:].strip(), validate=True)
            user, _, pw = raw.decode("utf-8", "replace").partition(":")
        except (binascii.Error, ValueError):
            return False
        want_user, want_pw = self.auth
        ok_user = hmac.compare_digest(user, want_user)
        ok_pw = hmac.compare_digest(pw, want_pw)
        return ok_user and ok_pw

    def _send_401(self):
        self._send(401, _page("Authentication required",
                   "<p>Authentication required.</p>"),
                   extra={"WWW-Authenticate": 'Basic realm="astrx"'})

    # ---- request pipeline --------------------------------------------------
    def do_GET(self):
        started = time.perf_counter()
        path = urlsplit(self.path).path
        client = self.client_address[0] if self.client_address else "-"
        self._status = 200
        if self.metrics is not None:
            self.metrics.inc("requests_total")
        try:
            protected = path not in _OPEN_PATHS
            if (self.rate_limiter is not None and protected
                    and not self.rate_limiter.allow(client)):
                if self.metrics is not None:
                    self.metrics.inc("rate_limited_total")
                self._send(429, _page("Too Many Requests",
                           "<p>Rate limit exceeded. Slow down.</p>"),
                           extra={"Retry-After": "1"})
            elif (self.auth is not None and protected
                    and not self._authorized()):
                if self.metrics is not None:
                    self.metrics.inc("unauthorized_total")
                self._send_401()
            else:
                self._route(path)
        except BrokenPipeError:  # pragma: no cover
            pass
        except Exception as exc:  # never 500 silently in a way that hides cause
            if self.metrics is not None:
                self.metrics.inc("errors_total")
            self._send(500, _page("Error", "<pre>%s</pre>"
                       % html.escape(repr(exc))))
        finally:
            if self.verbose:
                log.info("request client=%s method=%s path=%s status=%s "
                         "elapsed_ms=%.1f", client, self.command, path,
                         getattr(self, "_status", "?"),
                         (time.perf_counter() - started) * 1000.0)

    def _route(self, path):
        if path == "/style.css":
            return self._send(200, STYLE, "text/css; charset=utf-8")
        if path == "/healthz":
            return self._send(200, "ok", "text/plain; charset=utf-8")
        if path == "/metrics":
            return self._metrics()
        if path == "/favicon.ico":
            return self._send(204, b"")
        if path == "/opensearch.xml":
            return self._opensearch()
        if path == "/suggest":
            return self._suggest()
        if path == "/images":
            return self._images()
        if path == "/videos":
            return self._videos()
        if path in ("/similar", "/mlt"):
            return self._similar()
        if path == "/api/search":
            return self._api_search()
        if path == "/api/videos":
            return self._api_videos()
        if path in ("/about", "/stats"):
            return self._about()
        if path in ("/", "/search"):
            return self._search()
        return self._send(404, _page("Not found",
                          _header() + "<main><div class=wrap>"
                          "<p>Not found.</p></div></main>"))

    def _metrics(self):
        counters = self.metrics.snapshot() if self.metrics is not None else {}
        try:
            conn = self._conn()
            try:
                counters["docs"] = conn.execute(
                    "SELECT COUNT(*) FROM docs").fetchone()[0]
                counters["hosts"] = conn.execute(
                    "SELECT COUNT(DISTINCT host) FROM docs").fetchone()[0]
            finally:
                conn.close()
        except Exception:
            pass
        lines = ["# astrx-websearch metrics"]
        for name in sorted(counters):
            lines.append("websearch_%s %s" % (name, counters[name]))
        self._send(200, "\n".join(lines) + "\n", "text/plain; charset=utf-8")

    def _search(self):
        vertical = self._str_param("type")
        if vertical == "images":
            return self._images()
        if vertical == "videos":
            return self._videos()
        # news = freshness-ordered; files = downloadable-document filter.
        if vertical == "news":
            sort, only_files, active = "fresh", False, "news"
        elif vertical == "files":
            sort, only_files, active = "relevance", True, "files"
        else:
            sort, only_files, active = "relevance", False, "web"
        q, page = self._query_params()
        if self.metrics is not None:
            self.metrics.inc("searches_total")
        if q and self.popular is not None:
            self.popular.record(q)
        if not q:
            return self._send(200, _page("astrx search",
                              _header() + _render_home()))
        conn = self._conn()
        try:
            results, total, elapsed, parsed = ranking.search(
                conn, q, page=page, page_size=PAGE_SIZE, sort=sort,
                only_files=only_files)
        finally:
            conn.close()
        title = "%s - astrx search" % q
        html_body = _header(q) + _render_results(
            q, results, total, elapsed, page, parsed, active=active)
        self._send(200, _page(title, html_body))

    def _api_search(self):
        q, page = self._query_params()
        if self.metrics is not None:
            self.metrics.inc("api_searches_total")
        if q and self.popular is not None:
            self.popular.record(q)
        # ``limit`` (optional, capped) returns the top-N from the front in one
        # request -- used by the federation aggregator to pull a shard's best
        # candidates for a global merge without paging.  Absent -> normal paging.
        page_size = PAGE_SIZE
        # ``page_size`` (optional, capped) lets a paging client pick how many
        # results per page -- distinct from ``limit`` below, which forces page 1
        # (federation top-N).  Paging is preserved.
        try:
            reqps = int(self._str_param("page_size", "") or 0)
        except (ValueError, TypeError):
            reqps = 0
        if reqps > 0:
            page_size = min(API_MAX_LIMIT, reqps)
        try:
            lim = int(self._str_param("limit", "") or 0)
        except (ValueError, TypeError):
            lim = 0
        if lim > 0:
            page_size = min(API_MAX_LIMIT, lim)
            page = 1
        # Vertical parity with the HTML UI: type=news (fresh) / type=files.
        vtype = self._str_param("type")
        sort = "fresh" if vtype == "news" else "relevance"
        # Explicit ``sort`` override (relevance|fresh) so a client can offer an
        # "order by" control independent of the vertical.  Unknown -> ignored.
        reqsort = self._str_param("sort", "")
        if reqsort in ("relevance", "fresh"):
            sort = reqsort
        only_files = (vtype == "files")
        conn = self._conn()
        try:
            results, total, elapsed, parsed = ranking.search(
                conn, q, page=page, page_size=page_size, sort=sort,
                only_files=only_files)
        finally:
            conn.close()
        payload = {
            "query": q,
            "parsed": {
                "optional": parsed.optional, "required": parsed.required,
                "excluded": parsed.excluded, "phrases": parsed.phrases,
                "intitle": parsed.intitle, "site": parsed.site,
                "lang": parsed.lang, "filetype": parsed.filetype,
                "after": parsed.after, "before": parsed.before,
            },
            "page": page,
            "page_size": page_size,
            "total": total,
            "elapsed_seconds": round(elapsed, 6),
            "results": [r.as_dict() for r in results],
        }
        self._send(200, json.dumps(payload, ensure_ascii=False),
                   "application/json; charset=utf-8")

    def _about(self):
        conn = self._conn()
        try:
            st = index.stats(conn)
        finally:
            conn.close()
        self._send(200, _page("astrx search - stats",
                   _header() + _render_about(st)))

    def _opensearch(self):
        if self.metrics is not None:
            self.metrics.inc("opensearch_total")
        self._send(200, _opensearch_xml(self._external_base()),
                   "application/opensearchdescription+xml; charset=utf-8")

    def _suggest(self):
        # Cap q at the edge so the echoed query and the parse cost are bounded,
        # rather than relying solely on suggest.suggest()'s internal q[:64].
        q = self._str_param("q").strip()[:SUGGEST_MAX_QUERY]
        if self.metrics is not None:
            self.metrics.inc("suggests_total")
        terms = []
        if q:
            popular = self.popular.top() if self.popular is not None else None
            conn = self._conn()
            try:
                terms = suggest.suggest(conn, q, popular=popular)
            finally:
                conn.close()
        # OpenSearch Suggestions JSON: [query, [completions...]].
        self._send(200, json.dumps([q, terms], ensure_ascii=False),
                   "application/x-suggestions+json; charset=utf-8")

    def _images(self):
        q, _ = self._query_params()
        if self.metrics is not None:
            self.metrics.inc("image_searches_total")
        images = []
        if q:
            conn = self._conn()
            try:
                images = index.image_search(conn, q, limit=IMAGE_LIMIT)
            finally:
                conn.close()
        title = ("%s - images - astrx search" % q) if q else "astrx images"
        self._send(200, _page(title, _header(q) + _render_images(q, images)))

    def _videos(self):
        q, _ = self._query_params()
        if self.metrics is not None:
            self.metrics.inc("video_searches_total")
        videos = []
        if q:
            conn = self._conn()
            try:
                videos = index.video_search(conn, q, limit=IMAGE_LIMIT)
            finally:
                conn.close()
        title = ("%s - videos - astrx search" % q) if q else "astrx videos"
        self._send(200, _page(title, _header(q) + _render_videos(q, videos)))

    def _api_videos(self):
        q, _ = self._query_params()
        if self.metrics is not None:
            self.metrics.inc("api_video_searches_total")
        videos = []
        if q:
            conn = self._conn()
            try:
                videos = index.video_search(conn, q, limit=IMAGE_LIMIT)
            finally:
                conn.close()
        payload = {"query": q, "count": len(videos), "results": videos}
        self._send(200, json.dumps(payload, ensure_ascii=False),
                   "application/json; charset=utf-8")

    def _similar(self):
        doc_id = None
        url = None
        idp = self._str_param("id")
        if idp:
            try:
                doc_id = int(idp)
            except (TypeError, ValueError):
                doc_id = None
        else:
            url = self._str_param("url") or None
        if self.metrics is not None:
            self.metrics.inc("similar_total")
        src, rows = None, []
        if doc_id is not None or url is not None:
            conn = self._conn()
            try:
                src, results = index.more_like_this(
                    conn, doc_id=doc_id, url=url, limit=MLT_LIMIT)
                rows = _similar_rows(results)
            finally:
                conn.close()
        title = "Similar pages - astrx search"
        self._send(200, _page(title, _header() + _render_similar(src, rows)))


def make_server(db_path, host="127.0.0.1", port=8803, rate=None, burst=None,
                auth=None, verbose=False):
    """Build (but do not start) a :class:`ThreadingHTTPServer`.

    ``rate``/``burst`` enable a per-IP token-bucket rate limiter (tokens/sec and
    bucket size).  ``auth`` is an optional ``(username, password)`` pair that
    gates the search/API/stats endpoints with HTTP Basic auth (``/healthz``,
    ``/style.css`` and ``/metrics`` stay open).  ``verbose`` turns on structured
    per-request logging.
    """
    limiter = None
    if rate is not None:
        limiter = RateLimiter(rate, burst if burst is not None else rate)
    attrs = {
        "db_path": db_path,
        "rate_limiter": limiter,
        "auth": tuple(auth) if auth else None,
        "metrics": Metrics(),
        "popular": PopularQueries(),
        "verbose": verbose,
    }
    handler = type("BoundHandler", (Handler,), attrs)
    return ThreadingHTTPServer((host, port), handler)


def serve(db_path, host="127.0.0.1", port=8803, rate=None, burst=None,
          auth=None, verbose=False):  # pragma: no cover - CLI path
    httpd = make_server(db_path, host, port, rate=rate, burst=burst,
                        auth=auth, verbose=verbose)
    print("astrx-websearch serving http://%s:%d/  (db=%s)  Ctrl-C to stop"
          % (host, port, db_path))
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.server_close()
