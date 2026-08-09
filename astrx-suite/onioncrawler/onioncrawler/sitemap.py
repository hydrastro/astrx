"""Minimal, safe sitemap XML parser (stdlib xml.etree only).

Handles both a ``<urlset>`` (a list of page ``<loc>``s) and a
``<sitemapindex>`` (a list of child-sitemap ``<loc>``s), matching by *local*
tag name so any namespace works. The crawler drives fetching + bounded
recursion; this module just parses one document into (kind, locs).

Safety:
* Content is already byte-capped by the fetcher, so the input is bounded.
* We refuse any document containing a DOCTYPE or ENTITY declaration before
  parsing, which shuts the door on entity-expansion ("billion laughs") and
  external-entity (XXE) attacks without needing a third-party hardened parser.
"""

from __future__ import annotations

import re
import xml.etree.ElementTree as ET

_DOCTYPE_RE = re.compile(rb"<!(?:DOCTYPE|ENTITY)", re.IGNORECASE)


class SitemapDoc:
    __slots__ = ("kind", "locs")

    def __init__(self, kind: str, locs: list[str]):
        self.kind = kind          # 'urlset' | 'sitemapindex' | 'unknown'
        self.locs = locs

    def __repr__(self):
        return f"SitemapDoc(kind={self.kind!r}, locs={len(self.locs)})"


def _localname(tag: str) -> str:
    # ElementTree renders namespaced tags as '{ns}local'.
    if "}" in tag:
        return tag.rsplit("}", 1)[1].lower()
    return tag.lower()


def parse_sitemap(body: bytes, max_locs: int = 50000) -> SitemapDoc:
    """Parse sitemap *body* bytes into a SitemapDoc. Never raises: on any error
    or on a rejected (entity-bearing) document, returns an empty 'unknown' doc.
    """
    if not body:
        return SitemapDoc("unknown", [])
    if _DOCTYPE_RE.search(body):
        # Reject entity/doctype-bearing XML outright (bomb / XXE defense).
        return SitemapDoc("unknown", [])
    try:
        root = ET.fromstring(body)
    except ET.ParseError:
        return SitemapDoc("unknown", [])
    except Exception:
        return SitemapDoc("unknown", [])

    root_local = _localname(root.tag)
    kind = "sitemapindex" if root_local == "sitemapindex" else (
        "urlset" if root_local == "urlset" else "unknown")

    locs: list[str] = []
    for el in root.iter():
        if _localname(el.tag) == "loc":
            text = (el.text or "").strip()
            if text:
                locs.append(text)
                if len(locs) >= max_locs:
                    break
    return SitemapDoc(kind, locs)
