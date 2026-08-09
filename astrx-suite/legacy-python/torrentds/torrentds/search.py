"""No-JS, server-rendered search UI + JSON API over the metadata store.

Routes
------
* ``GET /``                     search form + results (query param ``q``)
* ``GET /search?q=``            same, canonical results page
* ``GET /browse``               category tiles + recently-added preview
* ``GET /browse?category=``     paginated category listing
* ``GET /recent``               most-recently-added torrents
* ``GET /t/<infohash>``         torrent detail (file list + magnet + .torrent)
* ``GET /torrent/<ih>.torrent`` rebuilt .torrent from the stored info-dict
* ``GET /api/search?q=``        JSON results + pagination metadata
* ``GET /api/torrent/<ih>``     JSON torrent detail
* ``GET /api/stats``            JSON store stats
* ``GET /feed`` / ``/rss?q=``   RSS 2.0 feed (newest, or a saved search query)
* ``POST /api/block``           token-gated blocklist admin (kind=infohash|keyword)
* ``GET /metrics``              Prometheus-text counters
* ``GET /health``              JSON liveness

Listings hide spam-flagged torrents by default (``?show_spam=1`` reveals them)
and collapse cross-infohash duplicates (a v1 and a v2/hybrid hash of the same
content show once).

Filters (size range, file count, category, recency) and ordering
(relevance/latest/size/seen) are accepted as query params on the search and
API routes.  Everything is plain server-rendered HTML with inline CSS and no
JavaScript; all torrent-controlled text is HTML-escaped.
"""

from __future__ import annotations

import hmac
import html
import json
import time
from email.utils import formatdate
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, quote
from xml.sax.saxutils import escape as xml_escape

from .metadata import build_torrent_file
from .store import CATEGORIES, Store

_UNITS = ["B", "KiB", "MiB", "GiB", "TiB", "PiB"]

# Recency window presets (label -> seconds) offered in the UI.
_SINCE_PRESETS = [("Any time", ""), ("Past hour", "3600"), ("Past day", "86400"),
                  ("Past week", "604800"), ("Past month", "2592000")]
_SIZE_PRESETS = [("Any size", ""), ("> 100 MB", "104857600"),
                 ("> 1 GB", "1073741824"), ("> 5 GB", "5368709120")]
_ORDERS = [("Relevance", "relevance"), ("Latest", "latest"),
           ("Largest", "size"), ("Most seen", "seen")]
_VALID_ORDERS = {"relevance", "latest", "size", "seen", "oldest"}


def human_size(n: int) -> str:
    n = float(n or 0)
    for unit in _UNITS:
        if n < 1024 or unit == _UNITS[-1]:
            return ("%d %s" % (int(n), unit)) if unit == "B" else ("%.1f %s" % (n, unit))
        n /= 1024
    return "%d B" % n


def _clamp_int(value, default: int, lo: int, hi: int) -> int:
    """Parse *value* as an int (default on failure), clamped to ``[lo, hi]``.

    Guards the ``limit``/``offset`` query params: a non-numeric ``?limit=abc``
    must not raise, and a giant ``?offset=`` must not overflow the SQLite
    LIMIT bind -- both previously produced a 500.
    """
    try:
        n = int(value)
    except (TypeError, ValueError):
        return default
    return max(lo, min(hi, n))


def _opt_int(params, name):
    """Return an optional int filter value (``None`` if absent/blank/bad)."""
    vals = params.get(name)
    if not vals or vals[0] == "":
        return None
    try:
        v = int(vals[0])
    except (TypeError, ValueError):
        return None
    return v if v >= 0 else None


def _filters_from_params(params) -> dict:
    """Extract validated search filters from parsed query params."""
    category = (params.get("category") or [""])[0] or None
    if category not in CATEGORIES:
        category = None
    order = (params.get("order") or ["relevance"])[0]
    if order not in _VALID_ORDERS:
        order = "relevance"
    return {
        "min_size": _opt_int(params, "min_size"),
        "max_size": _opt_int(params, "max_size"),
        "min_files": _opt_int(params, "min_files"),
        "max_files": _opt_int(params, "max_files"),
        "category": category,
        "since": _opt_int(params, "since"),
        "order": order,
    }


# Bounds on the external-scrape fold per search request.  ``aggregator.health``
# can trigger a live scrape across every configured tracker; without a ceiling a
# single cache-cold results page (up to ``limit`` rows) would issue
# ``rows * trackers`` blocking requests and pin a server thread for a long time
# (and amplify any tracker-side latency).  Cap both the number of live folds and
# the total wall-clock spent folding.
SWARM_MAX_LOOKUPS = 25
SWARM_TIME_BUDGET = 8.0   # seconds, across the whole fold for one request


def attach_swarm(results, peer_store, aggregator=None, *,
                 max_lookups: int = SWARM_MAX_LOOKUPS,
                 time_budget: float = SWARM_TIME_BUDGET):
    """Annotate each result with swarm health (seeders/leechers).

    ``peer_store`` is the tracker's in-process :class:`PeerStore` (our own
    swarm).  ``aggregator`` is an optional
    :class:`~torrentds.scrape.ScrapeAggregator` that folds *external* tracker
    scrape counts in alongside the local ones: the local counts stay in
    ``seeders``/``leechers`` while the external totals land in ``ext_*`` and a
    combined ``swarm_seeders``/``swarm_leechers`` served-health number.

    The external fold is bounded: at most ``max_lookups`` infohashes are
    health-checked and the fold stops once ``time_budget`` seconds elapse,
    regardless of how many results are on the page (the local PeerStore counts,
    which never touch the network, are still attached to every row).
    """
    if peer_store is None and aggregator is None:
        return results
    deadline = time.monotonic() + max(0.0, time_budget)
    folds = 0
    for r in results:
        try:
            ih = bytes.fromhex(r["infohash"])
        except (ValueError, KeyError):
            ih = None
        if peer_store is not None:
            try:
                c, i, d = peer_store.counts(ih) if ih else (0, 0, 0)
            except Exception:
                c = i = d = 0
            r["seeders"], r["leechers"], r["completed"] = c, i, d
        if (aggregator is not None and ih is not None
                and folds < max_lookups and time.monotonic() < deadline):
            folds += 1
            try:
                h = aggregator.health(ih)
            except Exception:
                h = None
            if h:
                r["ext_seeders"] = h["seeders"]
                r["ext_leechers"] = h["leechers"]
                r["ext_completed"] = h["completed"]
                r["ext_trackers"] = h["trackers"]
                r["swarm_seeders"] = r.get("seeders", 0) + h["seeders"]
                r["swarm_leechers"] = r.get("leechers", 0) + h["leechers"]
    return results


PAGE_CSS = """
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:900px;
margin:0 auto;padding:1.5rem;color:#1a1a1a;background:#fafafa}
a{color:#0b57d0;text-decoration:none}a:hover{text-decoration:underline}
h1{font-size:1.4rem}form{margin:1rem 0}
input[type=text]{width:70%;padding:.5rem;font-size:1rem;border:1px solid #ccc;border-radius:4px}
select{padding:.4rem;font-size:.9rem;border:1px solid #ccc;border-radius:4px;margin:.2rem .4rem .2rem 0}
button{padding:.5rem 1rem;font-size:1rem;border:0;background:#0b57d0;color:#fff;border-radius:4px;cursor:pointer}
.filters{margin:.4rem 0}
.result{background:#fff;border:1px solid #e3e3e3;border-radius:6px;padding:.8rem 1rem;margin:.6rem 0}
.result .name{font-weight:600;font-size:1.05rem}
.meta{color:#555;font-size:.85rem;margin-top:.3rem}
.meta span{margin-right:1rem}
.cat{display:inline-block;background:#eef;border-radius:3px;padding:0 .4rem;color:#456}
.facet{display:inline-block;background:#f0f0f0;border-radius:3px;padding:0 .35rem;margin-right:.25rem;color:#555;font-size:.8rem}
.meta .facet{margin-right:.25rem}
.sw{color:#137333}.sw b{color:#0b8043}.lc{color:#a50e0e}
.magnet{font-family:monospace;font-size:.8rem;word-break:break-all}
.hash{font-family:monospace;color:#888;font-size:.75rem}
.muted{color:#888}.empty{padding:2rem;text-align:center;color:#888}
.pager{margin:1rem 0}.pager a{margin-right:1rem}
footer{margin-top:2rem;color:#aaa;font-size:.8rem}
"""


def _magnet_anchor(magnet: str) -> str:
    return '<a class="magnet" href="%s">magnet</a>' % html.escape(magnet, quote=True)


def _select(name: str, options, current) -> str:
    cur = "" if current is None else str(current)
    out = ["<select name=%s>" % name]
    for label, value in options:
        sel = " selected" if str(value) == cur else ""
        out.append("<option value='%s'%s>%s</option>"
                   % (html.escape(str(value), quote=True), sel, html.escape(label)))
    out.append("</select>")
    return "".join(out)


def _swarm_meta(r) -> str:
    if "seeders" not in r and "ext_seeders" not in r:
        return ""
    out = ""
    if "seeders" in r:
        out += ("<span class=sw><b>%d</b> seeders</span>"
                "<span class=lc>%d leechers</span>"
                % (r["seeders"], r["leechers"]))
    if "ext_seeders" in r:
        out += ("<span class=sw>+%d/%d on %d tracker(s)</span>"
                % (r["ext_seeders"], r["ext_leechers"], r.get("ext_trackers", 0)))
    return out


def render_results(query: str, results, stats, filters, *,
                   total=None, limit=25, offset=0) -> bytes:
    f = filters
    parts = ["<!doctype html><html><head><meta charset=utf-8>",
             "<meta name=viewport content='width=device-width,initial-scale=1'>",
             "<title>torrentds search</title><style>%s</style></head><body>" % PAGE_CSS,
             "<h1>torrentds &mdash; DHT metadata search</h1>",
             "<form action='/search' method='get'>",
             "<input type=text name=q value='%s' placeholder='search torrents...' autofocus>"
             % html.escape(query, quote=True),
             "<button type=submit>Search</button>",
             "<div class=filters>",
             _select("category", [("Any type", "")] + [(c.title(), c) for c in CATEGORIES],
                     f.get("category")),
             _select("min_size", _SIZE_PRESETS, f.get("min_size")),
             _select("since", _SINCE_PRESETS, f.get("since")),
             _select("order", _ORDERS, f.get("order")),
             "</div></form>"]
    shown = total if total is not None else len(results)
    parts.append("<p class=muted>%d torrents indexed &middot; %s total &middot; "
                 "%d match</p>"
                 % (stats["torrents"], human_size(stats["total_size"]), shown))
    if query and not results:
        parts.append("<div class=empty>No results for &ldquo;%s&rdquo;.</div>"
                     % html.escape(query))
    for r in results:
        ih = html.escape(r["infohash"])
        parts.append("<div class=result>")
        parts.append("<div class=name><a href='/t/%s'>%s</a></div>"
                     % (ih, html.escape(r["name"] or "(unnamed)")))
        parts.append("<div class=meta>"
                     "<span class=cat>%s</span>%s"
                     "<span>%s</span><span>%d files</span>"
                     "<span>%d pieces</span><span>seen %d&times;</span>"
                     "%s%s</div>"
                     % (html.escape(r.get("category", "other")),
                        _facet_spans(r.get("tags")),
                        human_size(r["total_size"]), r["file_count"],
                        r["piece_count"], r["seen_count"],
                        _swarm_meta(r), _magnet_anchor(r["magnet"])))
        parts.append("<div class=hash>%s</div></div>" % ih)
    parts.append(_pager(query, filters, total, limit, offset))
    parts.append("<footer>Metadata + magnet links only. No content is stored "
                 "or served. Operators are responsible for legal compliance.</footer>")
    parts.append("</body></html>")
    return "".join(parts).encode("utf-8")


def _facet_spans(tags) -> str:
    """Render classifier facet tokens (``key:value``) as compact meta chips.

    Tokens are produced by :mod:`torrentds.classify` (a fixed vocabulary), but
    every value is HTML-escaped anyway.  Shows the values (1080p, bluray, x265,
    2019, ...) which are what a searcher scans for.
    """
    if not tags:
        return ""
    out = []
    for tok in str(tags).split()[:12]:
        _, _, val = tok.partition(":")
        if val:
            out.append("<span class=facet>%s</span>" % html.escape(val))
    return "".join(out)


def _qs(query, filters, limit, offset) -> str:
    pairs = [("q", query), ("limit", limit), ("offset", offset)]
    for k in ("category", "min_size", "since", "order"):
        v = filters.get(k)
        if v not in (None, "", "relevance"):
            pairs.append((k, v))
    return "&".join("%s=%s" % (k, quote(str(v))) for k, v in pairs if v not in (None, ""))


def _pager(query, filters, total, limit, offset) -> str:
    if total is None:
        return ""
    links = []
    if offset > 0:
        prev = max(0, offset - limit)
        links.append("<a href='/search?%s'>&larr; prev</a>"
                     % _qs(query, filters, limit, prev))
    if offset + limit < total:
        links.append("<a href='/search?%s'>next &rarr;</a>"
                     % _qs(query, filters, limit, offset + limit))
    return "<div class=pager>%s</div>" % "".join(links) if links else ""


def render_detail(t) -> bytes:
    ih = html.escape(t["infohash"])
    dl = ("<a href='/torrent/%s.torrent'>download .torrent</a>"
          % ih) if t.get("has_torrent") else ""
    parts = ["<!doctype html><html><head><meta charset=utf-8>",
             "<title>%s</title><style>%s</style></head><body>"
             % (html.escape(t["name"] or ih), PAGE_CSS),
             "<p><a href='/'>&larr; search</a></p>",
             "<h1>%s</h1>" % html.escape(t["name"] or "(unnamed)"),
             "<div class=meta><span class=cat>%s</span><span>%s</span>"
             "<span>%d files</span><span>%d pieces</span>"
             "<span>piece len %s</span><span>seen %d&times;</span>%s</div>"
             % (html.escape(t.get("category", "other")), human_size(t["total_size"]),
                t["file_count"], t["piece_count"], human_size(t["piece_length"]),
                t["seen_count"], _swarm_meta(t)),
             "<p class=magnet>%s &nbsp; %s</p>" % (_magnet_anchor(t["magnet"]), dl),
             "<p class=hash>infohash %s</p>" % ih,
             "<h3>Files</h3><ul>"]
    for frec in t["files"]:
        parts.append("<li>%s <span class=muted>(%s)</span></li>"
                     % (html.escape(frec["path"]), human_size(frec["length"])))
    parts.append("</ul></body></html>")
    return "".join(parts).encode("utf-8")


def _xml_clean(text: str) -> str:
    """Drop code points that are illegal in XML 1.0, so one hostile torrent
    name (or ``?q=``) cannot make the whole RSS feed non-well-formed.

    XML 1.0 permits only ``#x9 | #xA | #xD | [#x20-#xD7FF] | [#xE000-#xFFFD] |
    [#x10000-#x10FFFF]`` and additionally forbids the noncharacters
    ``U+FDD0..U+FDEF`` and ``U+xFFFE/U+xFFFF`` in every plane.  ``xml_escape``
    only handles ``& < >`` and would pass a raw ``\\x0b`` straight through.
    """
    if not text:
        return ""
    out = []
    for ch in text:
        cp = ord(ch)
        if not (cp in (0x9, 0xA, 0xD)
                or 0x20 <= cp <= 0xD7FF
                or 0xE000 <= cp <= 0xFFFD
                or 0x10000 <= cp <= 0x10FFFF):
            continue                        # C0 controls, surrogates, > U+10FFFF
        if 0xFDD0 <= cp <= 0xFDEF or (cp & 0xFFFF) in (0xFFFE, 0xFFFF):
            continue                        # noncharacters (all planes)
        out.append(ch)
    return "".join(out)


def _x(text: str) -> str:
    """XML-escape torrent/user-controlled text after stripping illegal chars."""
    return xml_escape(_xml_clean(text))


def render_rss(items, base_url: str = "", query: str = "") -> bytes:
    """RSS 2.0 feed of the newest torrents (optionally for a saved query).

    When *query* is non-empty the feed is titled/linked for that search, so a
    user can subscribe to ``/rss?q=<query>`` as a saved search.  All torrent-
    and user-controlled text is run through :func:`_xml_clean` before escaping
    so a hostile name/query cannot break feed well-formedness.
    """
    if query:
        title = "torrentds — search: %s" % _x(query)
        link = "%s/search?q=%s" % (_x(base_url), quote(query))
        desc = "Newest torrents matching %s" % _x(query)
    else:
        title = "torrentds — newest torrents"
        link = "%s/" % _x(base_url)
        desc = "Newest metadata harvested from the DHT"
    parts = ['<?xml version="1.0" encoding="UTF-8"?>',
             '<rss version="2.0"><channel>',
             "<title>%s</title>" % title,
             "<link>%s</link>" % link,
             "<description>%s</description>" % desc]
    for r in items:
        ih = r["infohash"]
        title = _x(r["name"] or ih)
        magnet = _x(r["magnet"])
        desc = _x("%s, %d files" % (human_size(r["total_size"]), r["file_count"]))
        pub = formatdate(r["last_seen"], usegmt=True)
        parts.append("<item><title>%s</title>"
                     "<link>%s/t/%s</link>"
                     "<guid isPermaLink=\"false\">%s</guid>"
                     "<enclosure url=\"%s\" type=\"application/x-bittorrent\"/>"
                     "<description>%s</description>"
                     "<pubDate>%s</pubDate></item>"
                     % (title, _x(base_url), _x(ih),
                        _x(ih), magnet, desc, pub))
    parts.append("</channel></rss>")
    return "".join(parts).encode("utf-8")


def render_browse(cat_counts, recent, stats) -> bytes:
    """No-JS browse landing: category tiles + a recently-added preview."""
    parts = ["<!doctype html><html><head><meta charset=utf-8>",
             "<meta name=viewport content='width=device-width,initial-scale=1'>",
             "<title>torrentds browse</title><style>%s</style></head><body>" % PAGE_CSS,
             "<h1>torrentds &mdash; browse</h1>",
             "<p><a href='/'>&larr; search</a> &middot; "
             "<a href='/recent'>recently added</a> &middot; "
             "<a href='/rss'>RSS</a></p>",
             "<p class=muted>%d torrents indexed &middot; %s total</p>"
             % (stats["torrents"], human_size(stats["total_size"])),
             "<h3>Categories</h3><div class=filters>"]
    for cat, n in cat_counts.items():
        parts.append("<a class=cat href='/browse?category=%s'>%s (%d)</a> "
                     % (quote(cat), html.escape(cat.title()), n))
    parts.append("</div><h3>Recently added</h3>")
    if not recent:
        parts.append("<div class=empty>Nothing indexed yet.</div>")
    for r in recent:
        ih = html.escape(r["infohash"])
        parts.append("<div class=result>"
                     "<div class=name><a href='/t/%s'>%s</a></div>"
                     "<div class=meta><span class=cat>%s</span>"
                     "<span>%s</span><span>%d files</span>%s</div></div>"
                     % (ih, html.escape(r["name"] or "(unnamed)"),
                        html.escape(r.get("category", "other")),
                        human_size(r["total_size"]), r["file_count"],
                        _magnet_anchor(r["magnet"])))
    parts.append("<footer>Metadata + magnet links only. No content is stored "
                 "or served.</footer></body></html>")
    return "".join(parts).encode("utf-8")


class SearchHandler(BaseHTTPRequestHandler):
    server_version = "torrentds-search/1.0"
    protocol_version = "HTTP/1.1"
    store: Store = None  # type: ignore[assignment]
    peer_store = None
    metrics_provider = None
    scrape_aggregator = None
    admin_token = ""            # unset => POST /api/block returns 403 for all
    _start_time = time.time()
    MAX_BODY = 1_000_000        # cap admin POST bodies at 1 MB

    def log_message(self, *args):
        pass

    def _send(self, body: bytes, ctype: str = "text/html; charset=utf-8",
              status: int = 200, extra_headers=None) -> None:
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        for k, v in (extra_headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _json(self, obj, status: int = 200) -> None:
        self._send(json.dumps(obj, indent=2).encode("utf-8"),
                   "application/json", status)

    def do_GET(self) -> None:
        path, _, query = self.path.partition("?")
        params = parse_qs(query)
        q = (params.get("q") or [""])[0]
        limit = _clamp_int((params.get("limit") or [""])[0], default=25, lo=1, hi=100)
        offset = _clamp_int((params.get("offset") or [""])[0],
                            default=0, lo=0, hi=1_000_000)
        filters = _filters_from_params(params)
        # Spam is hidden by default; ?show_spam=1 reveals flagged items.
        show_spam = (params.get("show_spam") or [""])[0] in ("1", "true", "yes", "on")

        if path in ("/", "/search"):
            results = self.store.search(q, limit=limit, offset=offset,
                                        include_spam=show_spam, collapse=True, **filters)
            attach_swarm(results, self.peer_store, self.scrape_aggregator)
            total = self.store.count(q, include_spam=show_spam,
                                     **{k: filters[k] for k in filters if k != "order"})
            self._send(render_results(q, results, self.store.stats(), filters,
                                      total=total, limit=limit, offset=offset))
        elif path == "/api/search":
            results = self.store.search(q, limit=limit, offset=offset,
                                        include_spam=show_spam, collapse=True, **filters)
            attach_swarm(results, self.peer_store, self.scrape_aggregator)
            total = self.store.count(q, include_spam=show_spam,
                                     **{k: filters[k] for k in filters if k != "order"})
            self._json({"query": q, "count": len(results), "total": total,
                        "limit": limit, "offset": offset,
                        "has_more": offset + len(results) < total,
                        "next_offset": (offset + limit) if offset + limit < total else None,
                        "filters": {k: filters[k] for k in filters},
                        "results": [self._api_row(r) for r in results]})
        elif path == "/browse":
            self._handle_browse(params, filters, limit, offset, show_spam)
        elif path == "/recent":
            filters["order"] = "latest"
            results = self.store.search("", limit=limit, offset=offset,
                                        include_spam=show_spam, collapse=True,
                                        **{k: filters[k] for k in filters})
            attach_swarm(results, self.peer_store, self.scrape_aggregator)
            total = self.store.count("", include_spam=show_spam,
                                     **{k: filters[k] for k in filters if k != "order"})
            self._send(render_results("", results, self.store.stats(), filters,
                                      total=total, limit=limit, offset=offset))
        elif path == "/api/stats":
            self._json(self.store.stats())
        elif path.startswith("/api/torrent/"):
            ih = path[len("/api/torrent/"):].strip("/").lower()
            t = self.store.get_torrent(ih)
            if t is None:
                self._json({"error": "not found"}, status=404)
            else:
                attach_swarm([t], self.peer_store, self.scrape_aggregator)
                self._json(self._api_detail(t))
        elif path.startswith("/torrent/") and path.endswith(".torrent"):
            self._serve_torrent(path[len("/torrent/"):-len(".torrent")])
        elif path in ("/feed", "/rss", "/feed.xml"):
            # Per-query RSS: /rss?q=<query> is a subscribable saved search.
            items = self.store.search(q, limit=limit, offset=0, order="latest",
                                      include_spam=show_spam, collapse=True,
                                      category=filters["category"])
            self._send(render_rss(items, query=q),
                       "application/rss+xml; charset=utf-8")
        elif path in ("/torznab/api", "/torznab"):
            # Torznab/Newznab indexer feed for Prowlarr/Jackett/*arr.
            from . import torznab
            t = (params.get("t") or ["search"])[0].lower()
            if t == "caps" or t not in torznab.SEARCH_TYPES:
                self._send(torznab.caps_xml(), "application/xml; charset=utf-8")
            else:
                store_cat = torznab.store_category_for_cat(
                    (params.get("cat") or [""])[0])
                tzf = dict(filters)
                if store_cat:
                    tzf["category"] = store_cat
                results = self.store.search(q, limit=limit, offset=offset,
                                            include_spam=False, collapse=True, **tzf)
                attach_swarm(results, self.peer_store, self.scrape_aggregator)
                total = self.store.count(
                    q, include_spam=False,
                    **{k: tzf[k] for k in tzf if k != "order"})
                self._send(torznab.search_xml(results, total=total),
                           "application/rss+xml; charset=utf-8")
        elif path == "/metrics":
            self._send(self._metrics_text(), "text/plain; version=0.0.4; charset=utf-8")
        elif path == "/health":
            s = self.store.stats()
            self._json({"status": "ok", "torrents": s["torrents"],
                        "pending": s["pending"],
                        "uptime_seconds": round(time.time() - self._start_time, 1)})
        elif path.startswith("/t/"):
            ih = path[3:].strip("/").lower()
            t = self.store.get_torrent(ih)
            if t is None:
                self._send(b"<h1>404 not found</h1>", status=404)
            else:
                attach_swarm([t], self.peer_store)
                self._send(render_detail(t))
        else:
            self._send(b"<h1>404 not found</h1>", status=404)

    def _handle_browse(self, params, filters, limit, offset, show_spam) -> None:
        category = filters.get("category")
        if not category:
            cat_counts = self.store.category_counts(include_spam=show_spam)
            recent = self.store.search("", limit=15, offset=0, order="latest",
                                       include_spam=show_spam, collapse=True)
            attach_swarm(recent, self.peer_store, self.scrape_aggregator)
            self._send(render_browse(cat_counts, recent, self.store.stats()))
            return
        bf = dict(filters)
        bf["order"] = "latest"
        results = self.store.search("", limit=limit, offset=offset,
                                    include_spam=show_spam, collapse=True, **bf)
        attach_swarm(results, self.peer_store, self.scrape_aggregator)
        total = self.store.count("", include_spam=show_spam,
                                 **{k: bf[k] for k in bf if k != "order"})
        self._send(render_results("", results, self.store.stats(), bf,
                                  total=total, limit=limit, offset=offset))

    # -- POST /api/block: token-gated blocklist admin (AstrX editor) --------
    def _read_form(self) -> dict:
        try:
            n = int(self.headers.get("Content-Length", "0"))
        except (TypeError, ValueError):
            n = 0
        n = max(0, min(n, self.MAX_BODY))     # bound admin bodies
        body = self.rfile.read(n).decode("utf-8", "replace") if n else ""
        return parse_qs(body)

    def _block_token_ok(self, form):
        """None => admin disabled (no --admin-token); True/False => authed/bad.

        Constant-time compare over the ``token`` form field, ``X-Admin-Token``
        header, or ``Authorization: Bearer`` (mirrors onioncrawler)."""
        token = self.admin_token or ""
        if not token:
            return None
        provided = self.headers.get("X-Admin-Token", "") or ""
        if not provided:
            provided = (form.get("token") or [""])[0] or ""
        if not provided:
            auth = self.headers.get("Authorization", "") or ""
            if auth.startswith("Bearer "):
                provided = auth[7:]
        return hmac.compare_digest(provided.encode("utf-8"), token.encode("utf-8"))

    def _do_block(self, query: str) -> None:
        form = self._read_form()
        qs = parse_qs(query)
        gate = self._block_token_ok(form)
        if gate is None:
            return self._json(
                {"error": "blocklist admin disabled: set --admin-token"}, status=403)
        if not gate:
            return self._json({"error": "invalid admin token"}, status=403)
        kind = (form.get("kind") or qs.get("kind") or [""])[0]
        value = (form.get("value") or qs.get("value") or [""])[0]
        code, body = self.store.add_blocklist(kind, value)
        self._json(body, status=code)

    def do_POST(self) -> None:
        path, _, query = self.path.partition("?")
        if path == "/api/block":
            return self._do_block(query)
        self._json({"error": "not found"}, status=404)

    def _serve_torrent(self, infohash_hex: str) -> None:
        ih = infohash_hex.strip("/").lower()
        info = self.store.get_info_bytes(ih)
        if info is None:
            self._send(b"<h1>404 not found</h1>", status=404)
            return
        torrent = build_torrent_file(info)
        t = self.store.get_torrent(ih)
        name = (t["name"] if t else ih) or ih
        # HTTP header values are encoded latin-1 by http.server, so restrict the
        # filename to ASCII alnum + a few safe punctuation chars; ``str.isalnum``
        # is Unicode-aware and would otherwise pass CJK letters that raise
        # UnicodeEncodeError in send_header (a 500 / dropped connection).
        safe = "".join(ch for ch in name
                       if (ch.isascii() and ch.isalnum()) or ch in "-_. ")[:80] or ih
        self._send(torrent, "application/x-bittorrent", extra_headers={
            "Content-Disposition": 'attachment; filename="%s.torrent"' % safe})

    def _metrics_text(self) -> bytes:
        lines = []
        for k, v in self.store.stats().items():
            lines.append("torrentds_%s %s" % (k, v))
        if self.peer_store is not None:
            with self.peer_store._lock:  # noqa: SLF001
                swarms = len(self.peer_store.swarms)
                peers = sum(len(s.peers) for s in self.peer_store.swarms.values())
            lines.append("torrentds_tracker_swarms %d" % swarms)
            lines.append("torrentds_tracker_peers %d" % peers)
        if self.metrics_provider is not None:
            try:
                for k, v in self.metrics_provider().items():
                    if isinstance(v, (int, float)):
                        lines.append("torrentds_%s %s" % (k, v))
            except Exception:
                pass
        return ("\n".join(lines) + "\n").encode("utf-8")

    @staticmethod
    def _api_row(r: dict) -> dict:
        row = {
            "infohash": r["infohash"],
            "name": r["name"],
            "total_size": r["total_size"],
            "file_count": r["file_count"],
            "piece_count": r["piece_count"],
            "seen_count": r["seen_count"],
            "category": r.get("category", "other"),
            "version": r.get("version", "v1"),
            "magnet": r["magnet"],
        }
        if r.get("infohash_v2"):
            row["infohash_v2"] = r["infohash_v2"]
        if "seeders" in r:
            row["seeders"] = r["seeders"]
            row["leechers"] = r["leechers"]
            row["completed"] = r["completed"]
        for k in ("ext_seeders", "ext_leechers", "ext_completed", "ext_trackers",
                  "swarm_seeders", "swarm_leechers"):
            if k in r:
                row[k] = r[k]
        if r.get("dup_count", 1) and r.get("dup_count", 1) > 1:
            row["dup_count"] = r["dup_count"]
            row["alt_infohashes"] = r.get("alt_infohashes", [])
        return row

    @classmethod
    def _api_detail(cls, t: dict) -> dict:
        row = cls._api_row(t)
        row["piece_length"] = t["piece_length"]
        row["first_seen"] = t["first_seen"]
        row["last_seen"] = t["last_seen"]
        row["has_torrent"] = bool(t.get("has_torrent"))
        row["torrent"] = ("/torrent/%s.torrent" % t["infohash"]
                          if t.get("has_torrent") else None)
        row["files"] = [dict(f) for f in t["files"]]
        row["alt_infohashes"] = t.get("alt_infohashes", [])
        return row


def make_search_server(store: Store, host: str = "127.0.0.1",
                       port: int = 8804, peer_store=None,
                       metrics_provider=None, admin_token: str = "",
                       scrape_aggregator=None) -> ThreadingHTTPServer:
    attrs = {"store": store, "peer_store": peer_store, "_start_time": time.time(),
             "admin_token": admin_token or "",
             "scrape_aggregator": scrape_aggregator}
    if metrics_provider is not None:
        # staticmethod so a plain callable stored as a class attribute is not
        # rebound as an (arg-taking) method when accessed via the instance.
        attrs["metrics_provider"] = staticmethod(metrics_provider)
    handler = type("BoundSearchHandler", (SearchHandler,), attrs)
    return ThreadingHTTPServer((host, port), handler)
