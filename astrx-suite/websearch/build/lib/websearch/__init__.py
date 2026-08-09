"""astrx-websearch: a zero-dependency clearnet search engine.

A from-scratch crawler + inverted index (SQLite FTS5) + explicit ranking +
no-JavaScript query server. Python 3.11 standard library only.

Public modules:
    canonical   URL canonicalization, joining, trap heuristics
    robots      robots.txt parser (Allow/Disallow/Crawl-delay, longest match)
    htmlparse   HTML extraction (title, description, text, links, lang, canonical)
    httpclient  polite HTTP/1.1 fetcher (gzip/deflate, capped redirects, timeouts)
    frontier    SQLite-backed crawl frontier (lease / resume, WAL)
    index       document store + FTS5 inverted index + link graph + PageRank-lite
    ranking     query parsing, safe FTS5 MATCH build, scoring, snippets
    crawler     the crawl loop tying it all together
    server      no-JS http.server UI + JSON API
"""

# Bundled-suite import shim: when the astrx-suite ships together (run via
# `python3 -m websearch`, no pip install), make the sibling `crawlcore/`
# package importable without a .pth or PYTHONPATH. A pip-installed or
# PYTHONPATH-provided crawlcore is preferred and left untouched.
import os as _os, sys as _sys
try:
    import crawlcore as _crawlcore  # noqa: F401
except ModuleNotFoundError:
    _cc = _os.path.join(
        _os.path.dirname(_os.path.dirname(_os.path.dirname(_os.path.abspath(__file__)))),
        "crawlcore",
    )
    # append (not insert): this fallback is lowest-priority, so a user's own
    # top-level package that happens to share a name with a crawlcore submodule
    # (and any pip-installed / PYTHONPATH crawlcore) always wins over the bundle.
    if _os.path.isdir(_os.path.join(_cc, "crawlcore")) and _cc not in _sys.path:
        _sys.path.append(_cc)

__version__ = "1.0.0"
