"""onioncrawler - a resumable, trap-resistant crawler + search engine for Tor
hidden services (.onion), Python 3.11 standard library only.

Public API:
    Config, Storage, Crawler, build_fetcher, load_abuse_filter,
    is_onion_host, canonicalize
"""

# Bundled-suite import shim: when the astrx-suite ships together (run via
# `python3 -m onioncrawler`, no pip install), make the sibling `crawlcore/`
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

from .config import Config
from .storage import Storage
from .crawler import Crawler
from .fetcher import build_fetcher, TorSocksFetcher, I2PHttpFetcher, DirectFetcher
from .abuse import load_abuse_filter, AbuseFilter
from .onion import (
    is_onion_host, is_i2p_host, is_darknet_host,
    require_onion, require_i2p, require_darknet,
    NotOnionError, NotDarknetError,
)
from .canonical import canonicalize

__all__ = [
    "Config", "Storage", "Crawler", "build_fetcher", "TorSocksFetcher",
    "I2PHttpFetcher", "DirectFetcher", "load_abuse_filter", "AbuseFilter",
    "is_onion_host", "is_i2p_host", "is_darknet_host",
    "require_onion", "require_i2p", "require_darknet",
    "NotOnionError", "NotDarknetError", "canonicalize",
]

__version__ = "1.0.0"
