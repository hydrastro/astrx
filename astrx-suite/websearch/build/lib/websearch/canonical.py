"""URL canonicalization, joining and cheap crawler-trap heuristics.

Canonicalization goal: two URLs that name the same resource should map to the
same string, so the frontier can dedup them.  We:

  * lower-case scheme and host,
  * drop default ports (80/443) and any userinfo / fragment,
  * resolve ``.`` / ``..`` path segments (RFC 3986 section 5.2.4),
  * collapse duplicate slashes,
  * sort query parameters for a stable key.

Everything here is pure-stdlib and side-effect free.
"""

import re
from urllib.parse import (
    urlsplit, urlunsplit, urljoin, parse_qsl, urlencode,
)

from crawlcore import traps as _traps

DEFAULT_PORTS = {"http": "80", "https": "443"}
_MULTISLASH = re.compile(r"/{2,}")


def _bracket(host):
    """Wrap an IPv6 literal in ``[]`` so it round-trips through ``urlsplit``."""
    if ":" in host and not host.startswith("["):
        return "[%s]" % host
    return host


def is_http_url(url):
    """True for absolute http/https URLs with a host."""
    try:
        s = urlsplit(url)
    except ValueError:
        return False
    return s.scheme in ("http", "https") and bool(s.hostname)


def host_of(url):
    """Lower-cased host of *url* (empty string if none)."""
    try:
        return (urlsplit(url).hostname or "").lower()
    except ValueError:
        return ""


def authority_of(url):
    """Lower-cased origin authority ``host[:port]`` with default ports dropped.

    This is the correct per-origin key for the frontier, politeness and
    robots.txt (which are scheme+host+port specific), whereas :func:`host_of`
    gives the bare hostname used for domain scoping and display.
    """
    try:
        s = urlsplit(url)
    except ValueError:
        return ""
    host = (s.hostname or "").lower()
    if not host:
        return ""
    scheme = s.scheme.lower()
    try:
        port = s.port
    except ValueError:
        return _bracket(host)
    disp = _bracket(host)
    if port is not None and str(port) != DEFAULT_PORTS.get(scheme):
        return "%s:%d" % (disp, port)
    return disp


def _remove_dot_segments(path):
    """RFC 3986 section 5.2.4 dot-segment removal."""
    out = []
    inp = path
    while inp:
        if inp.startswith("../"):
            inp = inp[3:]
        elif inp.startswith("./"):
            inp = inp[2:]
        elif inp.startswith("/./"):
            inp = "/" + inp[3:]
        elif inp == "/.":
            inp = "/"
        elif inp.startswith("/../"):
            inp = "/" + inp[4:]
            if out:
                out.pop()
        elif inp == "/..":
            inp = "/"
            if out:
                out.pop()
        elif inp in (".", ".."):
            inp = ""
        else:
            if inp.startswith("/"):
                idx = inp.find("/", 1)
            else:
                idx = inp.find("/")
            if idx == -1:
                out.append(inp)
                inp = ""
            else:
                out.append(inp[:idx])
                inp = inp[idx:]
    return "".join(out)


def canonicalize(url, base=None):
    """Return the canonical form of *url* (optionally resolved against *base*).

    Returns ``None`` for non-http(s) URLs or unparseable input.
    """
    if url is None:
        return None
    url = url.strip()
    if not url:
        return None
    if base:
        try:
            url = urljoin(base, url)
        except ValueError:
            return None
    try:
        s = urlsplit(url)
    except ValueError:
        return None

    scheme = s.scheme.lower()
    if scheme not in ("http", "https"):
        return None
    host = (s.hostname or "").lower()
    if not host:
        return None

    netloc = _bracket(host)
    port = None
    try:
        port = s.port
    except ValueError:
        return None
    if port is not None and str(port) != DEFAULT_PORTS.get(scheme):
        netloc = "%s:%d" % (_bracket(host), port)

    path = _remove_dot_segments(s.path or "/")
    path = _MULTISLASH.sub("/", path)
    if not path.startswith("/"):
        path = "/" + path

    # Normalise the query: re-encode and sort for a stable dedup key.
    pairs = parse_qsl(s.query, keep_blank_values=True)
    pairs.sort()
    query = urlencode(pairs)

    return urlunsplit((scheme, netloc, path, query, ""))


def join(base, href):
    """Resolve *href* against *base* and canonicalize; ``None`` if unusable."""
    return canonicalize(href, base=base)


def in_scope(url, scope_hosts):
    """Scope test.

    ``scope_hosts`` is either ``None`` (crawl broadly) or an iterable of host
    suffixes.  A URL is in scope when its host equals or is a sub-domain of one
    of the suffixes.
    """
    if scope_hosts is None:
        return True
    h = host_of(url)
    if not h:
        return False
    for d in scope_hosts:
        d = d.lower().lstrip(".")
        if h == d or h.endswith("." + d):
            return True
    return False


# ---- cheap trap heuristics -------------------------------------------------
# The structural predicates themselves are shared (crawlcore.traps); these thin
# URL-string wrappers parse the path/query and delegate, so the trap logic has
# one tested home across both crawlers.

def path_segments(url):
    return _traps.path_segments(urlsplit(url).path)


def path_depth(url):
    return _traps.depth(urlsplit(url).path)


def max_segment_repeat(url):
    """Largest number of times any single path segment repeats.

    ``/a/b/a/a`` -> 3 (the segment ``a``).  Detects ``/x/x/x/...`` style traps.
    """
    return _traps.segment_repeat_max(urlsplit(url).path)


def query_param_count(url):
    return _traps.query_param_count(urlsplit(url).query)
