"""URL canonicalization, dedup keys, and structural keys for trap detection.

Produces, from any (possibly relative) URL:
  * canonical URL      - the stable identity used for dedup / frontier PK
  * template key       - host + path + sorted query *keys* (values dropped)
                         used to detect query-explosion / calendar bombs
  * skeleton key       - host + path with numeric/hex segments collapsed to '#'
                         a global backstop for "same shape, different ids"

Only http/https darknet URLs survive; everything else returns None. By default
that means .onion only (the crown invariant); passing allow_i2p=True (an i2p
crawl / an --enable-i2p submission) also admits .i2p hosts. Clearnet never
survives.
"""

from __future__ import annotations

import posixpath
import re
from urllib.parse import (
    urlsplit,
    urlunsplit,
    urljoin,
    parse_qsl,
    urlencode,
    quote,
    unquote,
)

from .onion import normalize_host, is_darknet_host

# Query params that never identify content: drop them entirely.
TRACKING_PARAMS = frozenset(
    {
        "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content",
        "utm_id", "utm_reader", "utm_name", "utm_social", "utm_social-type",
        "gclid", "gclsrc", "dclid", "fbclid", "yclid", "msclkid", "mc_cid",
        "mc_eid", "igshid", "ref", "referrer", "referer", "source",
        "sessionid", "session_id", "sid", "phpsessid", "jsessionid",
        "aspsessionid", "cfid", "cftoken", "s", "spm", "scm", "share",
        "_ga", "_gl", "trk", "cmpid", "campaign",
    }
)

DEFAULT_PORTS = {"http": 80, "https": 443}

_NUMERICISH = re.compile(r"^[0-9]+$")
_HEXISH = re.compile(r"^[0-9a-f]{8,}$")
_DATEISH = re.compile(r"^\d{4}(-\d{1,2}(-\d{1,2})?)?$")


def _normalize_path(path: str) -> str:
    """Percent-decode safe chars, collapse //, resolve . and .. ."""
    if not path:
        return "/"
    # Resolve dot segments without touching the leading slash semantics.
    # posixpath.normpath collapses trailing slash, so remember it.
    had_trailing = path.endswith("/") and path != "/"
    # unquote then re-quote to normalize case of percent-escapes, but keep
    # the path characters that are legal unencoded.
    path = unquote(path)
    norm = posixpath.normpath(path)
    if norm == ".":
        norm = "/"
    if not norm.startswith("/"):
        norm = "/" + norm
    if had_trailing and not norm.endswith("/"):
        norm += "/"
    # re-quote, preserving '/' and a conservative safe set
    return quote(norm, safe="/-._~!$&'()*+,;=:@")


def _clean_query(query: str) -> str:
    if not query:
        return ""
    pairs = parse_qsl(query, keep_blank_values=True)
    kept = [
        (k, v)
        for (k, v) in pairs
        if k.lower() not in TRACKING_PARAMS
    ]
    # sort for a stable canonical form (dedup: ?a=1&b=2 == ?b=2&a=1)
    kept.sort()
    return urlencode(kept, doseq=True)


def canonicalize(url: str, base: str | None = None, allow_v2: bool = False,
                 allow_i2p: bool = False):
    """Return a CanonicalUrl or None if the URL is not a usable darknet URL.

    *base* is the page the link was found on (for resolving relative URLs).
    *allow_i2p* additionally admits .i2p hosts (default off => .onion only).
    """
    try:
        if base:
            url = urljoin(base, url)
        sp = urlsplit(url)
    except Exception:
        return None

    scheme = sp.scheme.lower()
    if scheme not in ("http", "https"):
        return None

    host = normalize_host(sp.hostname or "")
    if not is_darknet_host(host, allow_v2=allow_v2, allow_i2p=allow_i2p):
        return None

    port = sp.port
    if port is not None and DEFAULT_PORTS.get(scheme) == port:
        port = None
    netloc = host if port is None else f"{host}:{port}"

    path = _normalize_path(sp.path)
    query = _clean_query(sp.query)
    # fragment always dropped
    canonical = urlunsplit((scheme, netloc, path, query, ""))
    return CanonicalUrl(canonical, scheme, host, port, path, query)


class CanonicalUrl:
    __slots__ = ("url", "scheme", "host", "port", "path", "query")

    def __init__(self, url, scheme, host, port, path, query):
        self.url = url
        self.scheme = scheme
        self.host = host
        self.port = port
        self.path = path
        self.query = query

    # ---- structural keys used by trap detection -------------------------
    def query_keys(self):
        if not self.query:
            return ()
        return tuple(sorted({k for (k, _) in parse_qsl(self.query, keep_blank_values=True)}))

    def template_key(self) -> str:
        """host + path + sorted query KEYS (values stripped).

        All of  /cal?year=2020&month=1  and  /cal?year=2021&month=2  collapse
        to the same template, so we can cap how many we enqueue.
        """
        qk = ",".join(self.query_keys())
        return f"{self.host}{self.path}?{qk}" if qk else f"{self.host}{self.path}"

    def skeleton_key(self) -> str:
        """host + path with numeric/hex/date-ish segments replaced by '#'.

        Global backstop for id-parameterized page farms:
        /post/12345 and /post/67890 share one skeleton.
        """
        segs = []
        for seg in self.path.split("/"):
            if not seg:
                segs.append(seg)
                continue
            low = seg.lower()
            if _NUMERICISH.match(low) or _HEXISH.match(low) or _DATEISH.match(low):
                segs.append("#")
            else:
                segs.append(low)
        sk = "/".join(segs)
        qk = ",".join(self.query_keys())
        return f"{self.host}{sk}?{qk}" if qk else f"{self.host}{sk}"

    def __repr__(self):
        return f"CanonicalUrl({self.url!r})"
