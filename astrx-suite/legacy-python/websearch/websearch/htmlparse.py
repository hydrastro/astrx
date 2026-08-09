"""HTML extraction built on :class:`html.parser.HTMLParser`.

Pulls out the fields an indexer cares about:

  * ``title`` and meta ``description``,
  * visible body text with ``<script>/<style>`` (and best-effort nav/header/
    footer/aside boilerplate) removed,
  * outbound links (``<a href>``, resolved lazily by the crawler),
  * ``<link rel=canonical>`` and ``<base href>``,
  * a coarse language guess and the ``meta robots`` directives,
  * harvested ``<img>`` metadata (image vertical),
  * harvested video signals -- ``<video>/<source>``, known-player ``<iframe>``,
    Open Graph / Twitter player cards, schema.org ``VideoObject`` and direct
    media ``<a href>`` (video vertical), and
  * structured-data / SPA content recovery: JSON-LD, Open Graph, ``<noscript>``
    and inline state blobs (``__NEXT_DATA__`` etc.) parsed to recover title /
    description / body when a JS-heavy page ships little static text.

Everything here is pure string + ``json`` work: it opens NO socket and fetches
NOTHING.  The crawler already downloaded the page; harvested URLs are the
browser's to load at view time, never the server's.  No third-party deps.
"""

import json
import re
from html.parser import HTMLParser
from urllib.parse import urlsplit

_WS = re.compile(r"\s+")
_WORD = re.compile(r"[^\W\d_]+", re.UNICODE)

# Elements whose text content is never indexable.
_SKIP = {"script", "style", "noscript", "template", "svg", "math"}
# Boilerplate: excluded from body text, but links inside are still followed.
_BOILER = {"nav", "header", "footer", "aside", "form"}
# Block-level elements that should introduce whitespace between text runs.
_BLOCK = {
    "p", "div", "br", "li", "tr", "td", "th", "h1", "h2", "h3", "h4", "h5",
    "h6", "section", "article", "ul", "ol", "table", "blockquote", "pre", "hr",
}

# Tiny stop-word sets for a cheap language guess.
_STOP = {
    "en": {"the", "and", "of", "to", "in", "a", "is", "that", "for", "it",
           "with", "as", "on", "are", "be", "this", "was", "by", "an"},
    "es": {"el", "la", "de", "que", "y", "en", "los", "una", "por", "con",
           "para", "es", "un", "las", "se", "no", "su", "al"},
    "fr": {"le", "la", "de", "et", "les", "des", "une", "que", "est", "pour",
           "dans", "un", "du", "au", "en", "qui", "sur", "ne"},
    "de": {"der", "die", "und", "den", "von", "zu", "das", "mit", "ist", "auf",
           "ein", "im", "nicht", "eine", "als", "auch", "es", "an"},
}


def guess_lang(text, hint=None):
    """Return a two-letter language code (defaults to ``en``)."""
    if hint:
        h = hint.strip().lower()[:2]
        if len(h) == 2 and h.isalpha():
            return h
    words = [w for w in _WORD.findall(text.lower())][:500]
    if not words:
        return "en"
    best, best_score = "en", -1
    wl = set(words) if len(words) > 200 else words
    for lang, sw in _STOP.items():
        score = sum(1 for w in words if w in sw)
        if score > best_score:
            best, best_score = lang, score
    return best


# Image harvesting bounds (metadata only -- image bytes are never fetched).
_MAX_IMAGES = 200          # per page, so a hostile page cannot flood the store
_IMG_CONTEXT = 200         # chars of preceding text kept as image context

# Video harvesting bounds (metadata only -- no media/thumbnail is ever fetched).
_MAX_VIDEOS = 200          # per page, symmetric with _MAX_IMAGES

# Structured-data / SPA-recovery bounds.  These make the JSON-LD / state-blob
# path provably bounded and linear on hostile input: a page can embed huge or
# deeply nested JSON, thousands of scripts, or a giant state blob without being
# able to blow memory or CPU here.
_MAX_BLOB_BYTES = 512 * 1024      # hard per-blob byte cap (each ld+json/state)
_MAX_CAPTURE_TOTAL = 2 * 1024 * 1024  # total bytes ever captured, whole page
_MAX_LD_BLOBS = 32                # max JSON-LD scripts parsed
_MAX_STATE_BLOBS = 16             # max state blobs parsed
_MAX_SCRIPT_SCANS = 40            # max inline scripts scanned for state markers
_MAX_NOSCRIPT_BYTES = 64 * 1024   # cap on recovered <noscript> text
_JSON_MAX_NODES = 20000           # cap on JSON nodes visited per structure
_JSON_MAX_DEPTH = 64              # cap on JSON nesting walked
_JSON_STR_KEEP = 2000             # cap on any single recovered string leaf
_RECOVER_BODY_MAX = 8 * 1024      # cap on total recovered body text appended
_THIN_BODY = 200                  # static body shorter than this -> recover
_TITLE_MAX = 300                  # cap on a recovered title
_DESC_MAX = 500                   # cap on a recovered description

# Direct-media link extensions that name a video resource.
_MEDIA_EXT = (".mp4", ".webm", ".ogv", ".mov", ".m3u8", ".mpd")
# Inline global-state variable markers whose JSON payload we recover.
_STATE_MARKERS = ("__INITIAL_STATE__", "__NUXT__", "__APOLLO_STATE__",
                  "__PRELOADED_STATE__")
# JSON keys whose string values are human-readable enough to index.
_READABLE_KEYS = {
    "title", "headline", "description", "name", "subtitle", "summary",
    "caption", "text", "body", "articlebody", "content", "snippet", "abstract",
}
# Open Graph / Twitter meta keys we retain (a fixed allowlist, so a hostile page
# emitting thousands of distinct og:* keys cannot grow these dicts unbounded).
_OG_KEYS = {
    "og:title", "og:description", "og:site_name", "og:type", "og:url",
    "og:image", "og:image:url", "og:image:secure_url",
    "og:video", "og:video:url", "og:video:secure_url", "og:video:type",
}
_TWITTER_KEYS = {
    "twitter:card", "twitter:title", "twitter:description", "twitter:image",
    "twitter:player", "twitter:player:stream",
}


# ---- ISO-8601 duration -----------------------------------------------------

_DUR_RE = re.compile(
    r"^P(?:(\d+)W)?(?:(\d+)D)?"
    r"(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$", re.IGNORECASE)


def parse_duration(value):
    """ISO-8601 duration (e.g. ``PT1H2M3S``) -> whole seconds, or ``None``.

    Accepts weeks/days/hours/minutes/(fractional) seconds; returns ``None`` for
    anything it cannot parse or that carries no component (``P``/``PT``).  The
    regex is linear (no nested quantifier over a shared class), so it cannot
    backtrack pathologically on a hostile string.
    """
    if not value or not isinstance(value, str):
        return None
    m = _DUR_RE.match(value.strip())
    if not m or not any(m.groups()):
        return None
    weeks, days, hours, mins, secs = m.groups()
    total = 0.0
    if weeks:
        total += int(weeks) * 604800
    if days:
        total += int(days) * 86400
    if hours:
        total += int(hours) * 3600
    if mins:
        total += int(mins) * 60
    if secs:
        total += float(secs)
    return int(round(total))


# ---- known video players ---------------------------------------------------

_YT_EMBED = re.compile(r"/embed/([A-Za-z0-9_-]{6,})")
_VIMEO_ID = re.compile(r"/video/(\d+)")
_DM_ID = re.compile(r"/(?:embed/)?video/([A-Za-z0-9]+)")
_PEERTUBE = re.compile(r"/videos/embed/([0-9A-Za-z-]+)")


def _classify_player(src):
    """Map an ``<iframe src>`` to ``(player, watch_url_or_None)``.

    Pure string work (``urlsplit`` + regex); returns ``(None, None)`` for a src
    that is not a recognised embed.  The canonical watch URL is derived only
    when unambiguous from the embed path.
    """
    try:
        s = urlsplit(src)
    except ValueError:
        return None, None
    host = (s.hostname or "").lower()
    path = s.path or ""
    if not host:
        return None, None
    if host.endswith("youtube.com") or host.endswith("youtube-nocookie.com"):
        m = _YT_EMBED.search(path)
        if m:
            return "youtube", "https://www.youtube.com/watch?v=" + m.group(1)
        return "youtube", None
    if host == "youtu.be" or host.endswith(".youtu.be"):
        vid = path.strip("/").split("/")[0] if path.strip("/") else ""
        return "youtube", ("https://www.youtube.com/watch?v=" + vid
                           if vid else None)
    if host.endswith("player.vimeo.com"):
        m = _VIMEO_ID.search(path)
        return "vimeo", ("https://vimeo.com/" + m.group(1) if m else None)
    if (host.endswith("dailymotion.com") or host == "dai.ly"
            or host.endswith(".dai.ly")):
        m = _DM_ID.search(path)
        if m:
            return ("dailymotion",
                    "https://www.dailymotion.com/video/" + m.group(1))
        seg = path.strip("/").split("/")[0] if path.strip("/") else ""
        if host in ("dai.ly",) and seg:
            return "dailymotion", "https://www.dailymotion.com/video/" + seg
        return "dailymotion", None
    m = _PEERTUBE.search(path)          # PeerTube: any self-hosted instance
    if m:
        scheme = (s.scheme or "https").lower()
        return "peertube", "%s://%s/videos/watch/%s" % (scheme, host,
                                                         m.group(1))
    if (host.endswith("odysee.com") or host.endswith("lbry.tv")
            or host.endswith("lbry.com")):
        return "odysee", None
    if host.endswith("rumble.com"):
        return "rumble", None
    return None, None


def _is_direct_media(href):
    """True if *href*'s path ends in a direct video media extension."""
    try:
        p = urlsplit(href).path.lower()
    except ValueError:
        return False
    return p.endswith(_MEDIA_EXT)


# ---- JSON helpers (bounded, never eval) ------------------------------------

def _balanced_json(text, start):
    """Return the balanced ``{...}``/``[...]`` substring at/after *start*.

    Scans at most :data:`_MAX_BLOB_BYTES` characters, tracking string literals
    so braces inside strings are ignored.  Returns ``None`` if no opener is
    found near *start* or the structure does not close within the scan bound.
    """
    n = len(text)
    i = start
    limit = min(n, start + 100)     # the opener must be close to the marker
    while i < limit and text[i] not in "{[":
        i += 1
    if i >= n or text[i] not in "{[":
        return None
    open_ch = text[i]
    close_ch = "}" if open_ch == "{" else "]"
    depth = 0
    in_str = False
    esc = False
    end = min(n, i + _MAX_BLOB_BYTES)
    j = i
    while j < end:
        c = text[j]
        if in_str:
            if esc:
                esc = False
            elif c == "\\":
                esc = True
            elif c == '"':
                in_str = False
        elif c == '"':
            in_str = True
        elif c == open_ch:
            depth += 1
        elif c == close_ch:
            depth -= 1
            if depth == 0:
                return text[i:j + 1]
        j += 1
    return None


def _extract_state_json(text):
    """Find a known state marker in *text* and return its JSON payload string."""
    for marker in _STATE_MARKERS:
        i = text.find(marker)
        if i == -1:
            continue
        j = i + len(marker)
        k = text.find("=", j)
        start = k + 1 if 0 <= k <= j + 40 else j
        obj = _balanced_json(text, start)
        if obj:
            return obj
    return None


def _first_str(v):
    """First non-empty string in *v* (accepts a bare string or a list)."""
    if isinstance(v, str):
        return v.strip()
    if isinstance(v, list):
        for x in v:
            if isinstance(x, str) and x.strip():
                return x.strip()
    return ""


def _first_url(v):
    """First URL-ish string in *v* (string, list, or ``{"url": ...}`` object)."""
    if isinstance(v, str):
        return v.strip()
    if isinstance(v, list):
        for x in v:
            u = _first_url(x)
            if u:
                return u
        return ""
    if isinstance(v, dict):
        return _first_str(v.get("url") or v.get("@id") or v.get("contentUrl"))
    return ""


def _type_of(node):
    """schema.org ``@type`` of a node as a list of lower-case strings."""
    t = node.get("@type")
    if isinstance(t, str):
        return [t.lower()]
    if isinstance(t, list):
        return [x.lower() for x in t if isinstance(x, str)]
    return []


def _iter_json_dicts(parsed, cap=_JSON_MAX_NODES):
    """Yield dict nodes from a parsed JSON value (follows lists + ``@graph``).

    Bounded to *cap* dict nodes so a huge flat array cannot cause unbounded
    work; deeply nested input is already rejected by ``json.loads`` (recursion
    limit) upstream.
    """
    stack = [parsed]
    seen = 0
    while stack and seen < cap:
        node = stack.pop()
        if isinstance(node, dict):
            seen += 1
            yield node
            g = node.get("@graph")
            if isinstance(g, list):
                for x in g:
                    if isinstance(x, (dict, list)):
                        stack.append(x)
        elif isinstance(node, list):
            for x in node:
                if isinstance(x, (dict, list)):
                    stack.append(x)


def _collect_readable(parsed, out):
    """Collect human-readable string leaves (by key) from a state blob.

    Bounded by node count, nesting depth and total recovered length, so a
    hostile state blob cannot drive unbounded CPU/memory here.
    """
    stack = [(parsed, 0)]
    nodes = 0
    total = 0
    while stack and nodes < _JSON_MAX_NODES and total < _RECOVER_BODY_MAX:
        node, depth = stack.pop()
        nodes += 1
        if depth > _JSON_MAX_DEPTH:
            continue
        if isinstance(node, dict):
            for k, v in node.items():
                if isinstance(v, str):
                    if (isinstance(k, str) and k.lower() in _READABLE_KEYS
                            and v.strip()):
                        s = v.strip()[:_JSON_STR_KEEP]
                        out.append(s)
                        total += len(s)
                elif isinstance(v, (dict, list)):
                    stack.append((v, depth + 1))
        elif isinstance(node, list):
            for v in node:
                if isinstance(v, (dict, list)):
                    stack.append((v, depth + 1))
    return out


class Extracted:
    __slots__ = ("title", "description", "text", "links", "canonical",
                 "base_href", "lang", "meta_robots", "images", "videos",
                 "og", "twitter", "ldjson_blobs", "state_blobs",
                 "noscript_parts")

    def __init__(self):
        self.title = ""
        self.description = ""
        self.text = ""
        self.links = []          # raw href strings, in document order
        self.canonical = None
        self.base_href = None
        self.lang = None
        self.meta_robots = ""
        # (raw_src, alt, title, context) tuples; src is resolved by the crawler.
        self.images = []
        # list of dicts {video_url, embed_url, watch_url, title, thumbnail,
        # source, duration, context}; URLs are resolved/dropped by the crawler.
        self.videos = []
        self.og = {}             # retained Open Graph properties
        self.twitter = {}        # retained Twitter-card properties
        self.ldjson_blobs = []   # raw application/ld+json strings (bounded)
        self.state_blobs = []    # raw inline state JSON strings (bounded)
        self.noscript_parts = []  # recovered <noscript> text chunks (bounded)

    @property
    def noindex(self):
        return "noindex" in self.meta_robots

    @property
    def nofollow(self):
        return "nofollow" in self.meta_robots


class _Parser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.out = Extracted()
        self._skip = 0
        self._boiler = 0
        self._in_title = False
        self._title_parts = []
        self._text_parts = []
        self._recent = ""          # rolling tail of body text, for image context
        self._html_lang = None
        # video state
        self._in_video = 0
        self._video_poster = ""
        # structured-data capture state
        self._cap_kind = None      # None | ldjson | state_json | script_scan
        self._cap_tag = None       # element whose end closes the capture
        self._cap_parts = []
        self._cap_len = 0
        self._cap_total = 0        # total bytes captured across the whole page
        self._script_scans = 0
        self._noscript_len = 0

    # start / start-end -----------------------------------------------------
    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        self._close(tag)

    def handle_starttag(self, tag, attrs):
        a = dict((k.lower(), (v or "")) for k, v in attrs)
        if tag == "html" and "lang" in a:
            self._html_lang = a["lang"]
        elif tag == "base" and a.get("href"):
            self.out.base_href = a["href"]
        elif tag == "title":
            self._in_title = True
        elif tag == "a":
            href = a.get("href")
            if href:
                self.out.links.append(href)
                if _is_direct_media(href):
                    self._add_video(video_url=href.strip(), source="direct",
                                    context=self._recent_ctx())
        elif tag == "img":
            # Metadata only: the crawler already fetched this page; the image
            # bytes are never downloaded.  src is resolved against the base by
            # the crawler; here we just capture the raw attributes + nearby text.
            src = (a.get("src") or a.get("data-src") or "").strip()
            if src and len(self.out.images) < _MAX_IMAGES:
                self.out.images.append((
                    src, (a.get("alt") or "").strip(),
                    (a.get("title") or "").strip(),
                    _WS.sub(" ", self._recent).strip()))
        elif tag == "video":
            self._in_video += 1
            self._video_poster = (a.get("poster") or "").strip()
            src = (a.get("src") or "").strip()
            if src:
                self._add_video(video_url=src, thumbnail=self._video_poster,
                                source="html5", context=self._recent_ctx())
        elif tag == "source":
            if self._in_video > 0:
                src = (a.get("src") or "").strip()
                if src:
                    self._add_video(video_url=src,
                                    thumbnail=self._video_poster,
                                    source="html5", context=self._recent_ctx())
        elif tag == "iframe":
            src = (a.get("src") or "").strip()
            if src:
                player, watch = _classify_player(src)
                if player:
                    self._add_video(embed_url=src, watch_url=watch or "",
                                    source=player, context=self._recent_ctx())
        elif tag == "script":
            self._begin_script_capture(a)
        elif tag == "noscript":
            self._begin_capture("noscript", "noscript")
        elif tag == "link":
            rel = a.get("rel", "").lower()
            if "canonical" in rel.split() and a.get("href"):
                self.out.canonical = a["href"]
        elif tag == "meta":
            name = a.get("name", "").lower()
            prop = a.get("property", "").lower()
            content = a.get("content", "")
            if name == "description" and content:
                if not self.out.description:
                    self.out.description = content.strip()
            elif name == "robots" and content:
                self.out.meta_robots = content.lower()
            elif name in ("content-language", "language") and content:
                if not self._html_lang:
                    self._html_lang = content
            elif a.get("http-equiv", "").lower() == "content-language":
                if not self._html_lang and content:
                    self._html_lang = content
            # Open Graph + Twitter cards (property=og:* / name=twitter:*), kept
            # to a fixed allowlist so the dicts stay bounded.
            if content:
                key = prop or name
                if key in _OG_KEYS:
                    self.out.og.setdefault(key, content.strip())
                elif key in _TWITTER_KEYS:
                    self.out.twitter.setdefault(key, content.strip())

        if tag in _SKIP:
            self._skip += 1
        if tag in _BOILER:
            self._boiler += 1
        if tag in _BLOCK:
            self._text_parts.append(" ")

    def handle_starttag_void(self, tag, attrs):  # pragma: no cover
        pass

    def handle_endtag(self, tag):
        self._close(tag)

    def _close(self, tag):
        if self._cap_kind is not None and tag == self._cap_tag:
            self._finish_capture()
        if tag == "title":
            self._in_title = False
        if tag == "video" and self._in_video:
            self._in_video -= 1
            if self._in_video == 0:
                self._video_poster = ""
        if tag in _SKIP and self._skip:
            self._skip -= 1
        if tag in _BOILER and self._boiler:
            self._boiler -= 1
        if tag in _BLOCK:
            self._text_parts.append(" ")

    def handle_data(self, data):
        if self._in_title:
            self._title_parts.append(data)
            return
        if self._cap_kind is not None:
            # Capturing a script/noscript blob: route text to the capture buffer
            # (bounded) and never into the body.
            if (self._cap_len < _MAX_BLOB_BYTES
                    and self._cap_total < _MAX_CAPTURE_TOTAL):
                room = min(_MAX_BLOB_BYTES - self._cap_len,
                           _MAX_CAPTURE_TOTAL - self._cap_total)
                chunk = data[:room]
                self._cap_parts.append(chunk)
                self._cap_len += len(chunk)
                self._cap_total += len(chunk)
            return
        if self._skip:
            return
        # Keep a bounded rolling tail of recent text so <img> context capture is
        # O(text) overall, not O(text^2) on image-heavy pages.
        self._recent = (self._recent + data)[-_IMG_CONTEXT:]
        if self._boiler:
            return
        self._text_parts.append(data)

    # ---- video harvesting -------------------------------------------------
    def _recent_ctx(self):
        return _WS.sub(" ", self._recent).strip()

    def _add_video(self, **kw):
        """Append one raw video signal (URLs resolved/dropped by the crawler)."""
        if len(self.out.videos) >= _MAX_VIDEOS:
            return
        self.out.videos.append({
            "video_url": kw.get("video_url") or "",
            "embed_url": kw.get("embed_url") or "",
            "watch_url": kw.get("watch_url") or "",
            "title": kw.get("title") or "",
            "thumbnail": kw.get("thumbnail") or "",
            "source": kw.get("source") or "",
            "duration": kw.get("duration"),
            "context": kw.get("context") or "",
        })

    # ---- structured-data capture ------------------------------------------
    def _begin_script_capture(self, a):
        if self._cap_kind is not None or a.get("src"):
            return                 # external scripts carry no inline payload
        typ = (a.get("type") or "").strip().lower()
        if typ == "application/ld+json":
            self._begin_capture("ldjson", "script")
        elif typ == "application/json" or a.get("id", "") == "__NEXT_DATA__":
            self._begin_capture("state_json", "script")
        elif typ in ("", "text/javascript", "application/javascript", "module",
                     "text/ecmascript", "application/ecmascript"):
            if self._script_scans < _MAX_SCRIPT_SCANS:
                self._begin_capture("script_scan", "script")

    def _begin_capture(self, kind, tag):
        if self._cap_kind is not None or self._cap_total >= _MAX_CAPTURE_TOTAL:
            return
        self._cap_kind = kind
        self._cap_tag = tag
        self._cap_parts = []
        self._cap_len = 0

    def _finish_capture(self):
        buf = "".join(self._cap_parts)
        kind = self._cap_kind
        self._cap_kind = None
        self._cap_tag = None
        self._cap_parts = []
        self._cap_len = 0
        if kind == "ldjson":
            if buf.strip() and len(self.out.ldjson_blobs) < _MAX_LD_BLOBS:
                self.out.ldjson_blobs.append(buf)
        elif kind == "state_json":
            if buf.strip() and len(self.out.state_blobs) < _MAX_STATE_BLOBS:
                self.out.state_blobs.append(buf)
        elif kind == "script_scan":
            self._script_scans += 1
            js = _extract_state_json(buf)
            if js and len(self.out.state_blobs) < _MAX_STATE_BLOBS:
                self.out.state_blobs.append(js)
        elif kind == "noscript":
            if buf.strip() and self._noscript_len < _MAX_NOSCRIPT_BYTES:
                take = buf[:_MAX_NOSCRIPT_BYTES - self._noscript_len]
                self.out.noscript_parts.append(take)
                self._noscript_len += len(take)

    # ---- structured-data recovery -----------------------------------------
    def _add_video_from_ldjson(self, node):
        name = _first_str(node.get("name"))
        embed = _first_str(node.get("embedUrl"))
        content = _first_str(node.get("contentUrl"))
        thumb = _first_url(node.get("thumbnailUrl"))
        dur = node.get("duration")
        if isinstance(dur, bool):
            duration = None
        elif isinstance(dur, (int, float)):
            duration = int(dur)
        elif isinstance(dur, str):
            duration = parse_duration(dur)
        else:
            duration = None
        if name or embed or content or thumb:
            self._add_video(video_url=content, embed_url=embed, title=name,
                            thumbnail=thumb, source="ld-json",
                            duration=duration, context=name)

    def _add_image_from_ldjson(self, node):
        src = _first_url(node.get("contentUrl") or node.get("url"))
        if src and len(self.out.images) < _MAX_IMAGES:
            alt = _first_str(node.get("caption") or node.get("name")
                             or node.get("description"))
            self.out.images.append((src, alt, "", ""))

    def _add_video_from_meta(self, o):
        og = o.og
        ogv = (_first_str(og.get("og:video:secure_url"))
               or _first_str(og.get("og:video:url"))
               or _first_str(og.get("og:video")))
        if ogv:
            self._add_video(
                video_url=ogv, title=_first_str(og.get("og:title")),
                thumbnail=(_first_str(og.get("og:image"))
                           or _first_str(og.get("og:image:url"))
                           or _first_str(og.get("og:image:secure_url"))),
                source="opengraph", context=_first_str(og.get("og:title")))
        tw = o.twitter
        tp = _first_str(tw.get("twitter:player"))
        ts = _first_str(tw.get("twitter:player:stream"))
        if tp or ts:
            self._add_video(
                embed_url=tp, video_url=ts,
                title=(_first_str(tw.get("twitter:title"))
                       or _first_str(og.get("og:title"))),
                thumbnail=(_first_str(tw.get("twitter:image"))
                           or _first_str(og.get("og:image"))),
                source="twitter",
                context=(_first_str(tw.get("twitter:title"))
                         or _first_str(og.get("og:title"))))

    def _recover(self, o):
        """Recover title/description/body from structured data (no fetch).

        JSON-LD ``VideoObject``/``ImageObject`` are routed to the video/image
        verticals; readable text from JSON-LD, Open Graph, Twitter cards,
        ``<noscript>`` and inline state blobs backfills a thin static body so a
        JS-heavy/SPA page still becomes searchable.  All parsing is bounded.
        """
        rec_title = ""
        rec_desc = ""
        body_parts = []
        # 1. JSON-LD blobs
        for blob in o.ldjson_blobs:
            try:
                parsed = json.loads(blob)
            except Exception:
                continue           # malformed JSON-LD -> skip, never crash
            for node in _iter_json_dicts(parsed):
                types = _type_of(node)
                if not rec_title:
                    rec_title = _first_str(node.get("name")
                                           or node.get("headline"))
                if not rec_desc:
                    rec_desc = _first_str(node.get("description"))
                for k in ("articleBody", "text"):
                    bv = node.get(k)
                    if isinstance(bv, str) and bv.strip():
                        body_parts.append(bv.strip())
                if "videoobject" in types:
                    self._add_video_from_ldjson(node)
                if "imageobject" in types:
                    self._add_image_from_ldjson(node)
        # 2. Open Graph / Twitter player cards -> video vertical
        self._add_video_from_meta(o)
        # 3. og/twitter title + description fallbacks
        if not rec_title:
            rec_title = (_first_str(o.og.get("og:title"))
                         or _first_str(o.twitter.get("twitter:title")))
        if not rec_desc:
            rec_desc = (_first_str(o.og.get("og:description"))
                        or _first_str(o.twitter.get("twitter:description")))
        # 4. <noscript> text
        if o.noscript_parts:
            body_parts.append(" ".join(o.noscript_parts))
        # 5. inline state blobs
        for blob in o.state_blobs:
            try:
                parsed = json.loads(blob)
            except Exception:
                continue
            strings = []
            _collect_readable(parsed, strings)
            if strings:
                body_parts.append(" ".join(strings))
        # ---- backfill thin fields ----
        if not o.title and rec_title:
            o.title = _WS.sub(" ", rec_title).strip()[:_TITLE_MAX]
        if not o.description and rec_desc:
            o.description = _WS.sub(" ", rec_desc).strip()[:_DESC_MAX]
        if len(o.text) < _THIN_BODY:
            if rec_desc:
                body_parts.insert(0, rec_desc)
            if rec_title:
                body_parts.insert(0, rec_title)
            recovered = _WS.sub(" ", " ".join(body_parts)).strip()
            recovered = recovered[:_RECOVER_BODY_MAX]
            if recovered:
                o.text = ((o.text + " " + recovered).strip()
                          if o.text else recovered)

    # finalisation ----------------------------------------------------------
    def finish(self):
        if self._cap_kind is not None:      # flush an unclosed capture at EOF
            self._finish_capture()
        o = self.out
        o.title = _WS.sub(" ", "".join(self._title_parts)).strip()
        o.text = _WS.sub(" ", "".join(self._text_parts)).strip()
        if o.description:
            o.description = _WS.sub(" ", o.description).strip()
        self._recover(o)
        o.videos = o.videos[:_MAX_VIDEOS]
        o.lang = guess_lang(o.text or o.title, hint=self._html_lang)
        return o


def extract(html_text):
    """Parse *html_text* (a ``str``) into an :class:`Extracted`."""
    p = _Parser()
    try:
        p.feed(html_text)
    except Exception:
        # html.parser is tolerant, but never let a parse error kill a crawl.
        pass
    finally:
        try:
            p.close()
        except Exception:
            pass
    return p.finish()
