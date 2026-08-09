"""Fake / spam-torrent heuristics (GAP-CLOSER).

A cheap, deterministic scoring pass over *already-verified* metadata that flags
likely fakes and spam.  It never touches the network and never trusts a length
or a name -- it only inspects the parsed file layout + display name, so it is
trivially unit-testable with synthetic good/bad torrents.

Signals (each contributes a weight; the total is the ``spam_score``):

* **exe-in-media**   an executable (``.exe`` / ``.scr`` / ``.msi`` ...) inside a
  video/audio/image/document torrent -- the classic malware-in-a-movie trick.
* **decoy layout**   one dominant huge file plus several tiny padding/``.txt``/
  ``.url``/``.lnk`` decoys (a fake release padded to look legit).
* **piece mismatch** ``piece_count`` grossly inconsistent with
  ``ceil(total_size / piece_length)`` -- an impossible/forged size.
* **spam name**      URLs, domain tags, or promo phrases stuffed into the name
  (site-branded batch uploads).

The weights + threshold live in :class:`SpamConfig` so an operator can tune
them; :func:`score` returns ``(score, reasons)`` for transparency.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import List, Sequence, Set, Tuple

# Default flag threshold: a single strong signal (or two weak ones) trips it.
DEFAULT_THRESHOLD = 3.0

# Executables that have no business inside a media torrent.
_EXE_EXTS: Set[str] = {"exe", "scr", "bat", "cmd", "com", "msi", "pif", "vbs",
                       "js", "jar", "ps1", "hta"}
# Tiny files typically used as decoys / advertising in a fake release.
_DECOY_EXTS: Set[str] = {"txt", "url", "lnk", "nfo", "htm", "html", "website",
                         "torrent", "md", "diz"}
# Media categories in which an executable is highly suspicious.
_MEDIA_CATEGORIES: Set[str] = {"video", "audio", "image", "document"}

# Name-spam markers.
_URL_RE = re.compile(r"(?:https?://|www\.)", re.IGNORECASE)
_DOMAIN_RE = re.compile(r"\b[a-z0-9][a-z0-9-]{1,}\.(?:com|net|org|info|xyz|to|"
                        r"cc|ru|biz|site|top|club|online|download)\b",
                        re.IGNORECASE)
_PROMO_RE = re.compile(
    r"(free\s*download|watch\s*online|full\s*movie|download\s*free|"
    r"keygen|crack(?:ed)?|serial\s*key|activation\s*key|xxx\b|"
    r"visit\s+us|new\s*rip)", re.IGNORECASE)

_TINY_FILE_BYTES = 512 * 1024          # a "tiny" decoy is < 512 KiB
_DECOY_MIN_TOTAL = 50 * 1024 * 1024    # only in a torrent claiming > 50 MiB
_DOMINANT_FRACTION = 0.85              # one file is >= 85% of the total size
_DECOY_MIN_COUNT = 3                   # need at least this many decoys


@dataclass
class SpamConfig:
    threshold: float = DEFAULT_THRESHOLD
    exe_in_media: float = 4.0
    decoy_layout: float = 3.0
    piece_mismatch: float = 3.0
    url: float = 2.0
    domain: float = 2.0
    promo: float = 2.0
    # mismatch trips when piece_count differs from expected by more than this
    # multiplicative factor (guards against tiny-file / padding-file noise).
    mismatch_factor: float = 3.0
    decoy_exts: Set[str] = field(default_factory=lambda: set(_DECOY_EXTS))
    exe_exts: Set[str] = field(default_factory=lambda: set(_EXE_EXTS))


DEFAULT_CONFIG = SpamConfig()


def _ext(path: str) -> str:
    base = path.rsplit("/", 1)[-1]
    return base.rsplit(".", 1)[-1].lower() if "." in base else ""


def score(name: str, files: Sequence[Tuple[str, int]], total_size: int,
          piece_length: int, piece_count: int, category: str = "other",
          config: SpamConfig = DEFAULT_CONFIG) -> Tuple[float, List[str]]:
    """Return ``(spam_score, reasons)`` for one torrent.  Higher == spammier."""
    reasons: List[str] = []
    total = 0.0
    files = list(files or [])
    name = name or ""

    exts = [_ext(p) for p, _ in files]

    # -- exe-in-media -------------------------------------------------------
    if category in _MEDIA_CATEGORIES and any(e in config.exe_exts for e in exts):
        total += config.exe_in_media
        reasons.append("executable in %s torrent" % category)

    # -- decoy layout -------------------------------------------------------
    if len(files) >= 2 and total_size >= _DECOY_MIN_TOTAL:
        biggest = max((l for _, l in files), default=0)
        decoys = sum(1 for (p, l), e in zip(files, exts)
                     if l < _TINY_FILE_BYTES and e in config.decoy_exts)
        if (biggest >= _DOMINANT_FRACTION * total_size
                and decoys >= _DECOY_MIN_COUNT):
            total += config.decoy_layout
            reasons.append("one huge file + %d tiny decoy(s)" % decoys)

    # -- size vs piece mismatch --------------------------------------------
    if piece_length > 0 and piece_count > 0 and total_size > 0:
        expected = (total_size + piece_length - 1) // piece_length
        if expected >= 1 and abs(piece_count - expected) > 2:
            ratio = piece_count / expected
            if ratio > config.mismatch_factor or ratio < 1.0 / config.mismatch_factor:
                total += config.piece_mismatch
                reasons.append("piece_count %d vs expected ~%d"
                               % (piece_count, expected))

    # -- name spam ----------------------------------------------------------
    if _URL_RE.search(name):
        total += config.url
        reasons.append("url in name")
    if _DOMAIN_RE.search(name):
        total += config.domain
        reasons.append("domain tag in name")
    if _PROMO_RE.search(name):
        total += config.promo
        reasons.append("promotional keyword in name")

    return total, reasons


def is_spam(name: str, files: Sequence[Tuple[str, int]], total_size: int,
            piece_length: int, piece_count: int, category: str = "other",
            config: SpamConfig = DEFAULT_CONFIG) -> bool:
    s, _ = score(name, files, total_size, piece_length, piece_count, category, config)
    return s >= config.threshold
