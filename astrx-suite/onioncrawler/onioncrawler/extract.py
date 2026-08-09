"""HTML extraction using stdlib html.parser.

Extracts: <title>, visible text (script/style/etc. dropped), <a href> links,
and robots meta directives (<meta name=robots content=noindex,nofollow>).
"""

from __future__ import annotations

import re
from html.parser import HTMLParser

# NB: do not skip <head> wholesale - <title> lives inside it. script/style/etc.
# inside head are skipped individually below.
_SKIP_TAGS = {"script", "style", "noscript", "template", "svg"}
_BLOCK_TAGS = {
    "p", "div", "br", "li", "ul", "ol", "tr", "table", "section", "article",
    "header", "footer", "h1", "h2", "h3", "h4", "h5", "h6", "blockquote",
    "pre", "hr", "nav", "aside",
}
_WS = re.compile(r"[ \t\r\f\v]+")
_MULTINL = re.compile(r"\n{3,}")


class _Extractor(HTMLParser):
    def __init__(self, max_links: int | None = None):
        super().__init__(convert_charrefs=True)
        self.title_parts: list[str] = []
        self._in_title = False
        self._skip_depth = 0
        self.text_parts: list[str] = []
        self.links: list[str] = []
        # hard cap on links collected (None = unlimited); bounds parse memory
        # and, downstream, the number of link-graph edges a single page can grow.
        self._max_links = max_links
        self.meta_noindex = False
        self.meta_nofollow = False
        self.base_href = None

    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        ad = dict(attrs)
        if tag in _SKIP_TAGS:
            self._skip_depth += 1
            return
        if tag == "title":
            self._in_title = True
        elif tag == "base" and ad.get("href"):
            self.base_href = ad["href"]
        elif tag == "a":
            href = ad.get("href")
            rel = (ad.get("rel") or "").lower()
            if href and "nofollow" not in rel.split() and (
                    self._max_links is None or len(self.links) < self._max_links):
                self.links.append(href)
        elif tag == "meta":
            name = (ad.get("name") or "").lower()
            if name in ("robots", "onioncrawler"):
                content = (ad.get("content") or "").lower()
                if "noindex" in content:
                    self.meta_noindex = True
                if "nofollow" in content:
                    self.meta_nofollow = True
                if "none" in content:  # 'none' == noindex,nofollow
                    self.meta_noindex = True
                    self.meta_nofollow = True
        if tag in _BLOCK_TAGS:
            self.text_parts.append("\n")

    def handle_startendtag(self, tag, attrs):
        # self-closing (e.g. <meta/>, <br/>, <a .../>)
        self.handle_starttag(tag, attrs)
        if tag.lower() not in _SKIP_TAGS:
            return
        # a self-closing skip tag shouldn't leave skip_depth incremented
        self._skip_depth = max(0, self._skip_depth - 1)

    def handle_endtag(self, tag):
        tag = tag.lower()
        if tag in _SKIP_TAGS:
            self._skip_depth = max(0, self._skip_depth - 1)
            return
        if tag == "title":
            self._in_title = False
        if tag in _BLOCK_TAGS:
            self.text_parts.append("\n")

    def handle_data(self, data):
        if self._skip_depth > 0:
            return
        if self._in_title:
            self.title_parts.append(data)
            return
        if data and not data.isspace():
            self.text_parts.append(data)
        elif data:
            self.text_parts.append(" ")


class Extracted:
    __slots__ = ("title", "text", "links", "meta_noindex", "meta_nofollow", "base_href")

    def __init__(self, title, text, links, meta_noindex, meta_nofollow, base_href):
        self.title = title
        self.text = text
        self.links = links
        self.meta_noindex = meta_noindex
        self.meta_nofollow = meta_nofollow
        self.base_href = base_href


def _clean_text(parts: list[str]) -> str:
    raw = "".join(parts)
    raw = _WS.sub(" ", raw)
    lines = [ln.strip() for ln in raw.split("\n")]
    text = "\n".join(ln for ln in lines if ln)
    return _MULTINL.sub("\n\n", text).strip()


def extract_html(html_bytes: bytes, charset_hint: str | None = None,
                 max_links: int | None = None) -> Extracted:
    """Decode + parse HTML bytes into an Extracted record. *max_links* caps how
    many <a href> links are harvested (None = unlimited)."""
    text = _decode(html_bytes, charset_hint)
    p = _Extractor(max_links=max_links)
    try:
        p.feed(text)
        p.close()
    except Exception:
        # html.parser is lenient, but guard anyway; return what we have
        pass
    title = _WS.sub(" ", "".join(p.title_parts)).strip()
    body = _clean_text(p.text_parts)
    return Extracted(
        title=title, text=body, links=p.links,
        meta_noindex=p.meta_noindex, meta_nofollow=p.meta_nofollow,
        base_href=p.base_href,
    )


def _decode(data: bytes, charset_hint: str | None) -> str:
    # 1) explicit hint from Content-Type
    for enc in _candidate_encodings(data, charset_hint):
        try:
            return data.decode(enc)
        except (LookupError, UnicodeDecodeError):
            continue
    return data.decode("utf-8", errors="replace")


def _candidate_encodings(data: bytes, charset_hint: str | None):
    seen = []
    if charset_hint:
        seen.append(charset_hint)
    m = re.search(rb'charset=["\']?\s*([a-zA-Z0-9_\-]+)', data[:2048], re.I)
    if m:
        try:
            seen.append(m.group(1).decode("ascii"))
        except Exception:
            pass
    seen += ["utf-8", "iso-8859-1"]
    out = []
    for e in seen:
        e = e.strip().lower()
        if e and e not in out:
            out.append(e)
    return out
