"""Heuristic release classifier: turn a torrent name (and file list) into
structured attribute facets -- resolution, source, video/audio codec, HDR,
year, TV season/episode, edition and release group -- plus a coarse media
*kind* (movie / tv / music / book / software / game).

This is the bitmagnet-class enrichment done in pure Python: linear regexes over
a normalised name, no dependencies, no network.  It never raises; unknown values
are simply absent from the returned dict.  The output is small and stable so it
can be stored as a compact tag string and filtered/faceted on cheaply.

Design notes:
  * We match on a *normalised* name (``. _ -`` and brackets -> spaces, folded to
    lower case) with word boundaries, so ``The.Film.2019.1080p.BluRay.x265`` and
    ``The Film (2019) [1080p] BluRay x265`` classify identically.
  * Every pattern is anchored/bounded so a hostile 10 KB name stays linear.
"""

import re
from typing import Dict, List, Optional, Sequence, Tuple

__all__ = ["classify", "tag_string", "FACET_KEYS"]

# Facet keys, in a stable display order.
FACET_KEYS = ("kind", "year", "season", "episode", "resolution", "source",
              "vcodec", "acodec", "hdr", "edition", "group", "lang")

_MAX_NAME = 4096   # names beyond this are truncated before matching (linearity)

# name normalisation: separators + bracket noise -> single spaces
_SEP = re.compile(r"[._\-\[\]\(\)\{\}+]+")
_WS = re.compile(r"\s+")


def _norm(name: str) -> str:
    s = _SEP.sub(" ", (name or "")[:_MAX_NAME].lower())
    return _WS.sub(" ", s).strip()


# ---- resolution -----------------------------------------------------------
_RES = [
    (re.compile(r"\b(?:4k|2160p|uhd)\b"), "2160p"),
    (re.compile(r"\b1440p\b"), "1440p"),
    (re.compile(r"\b1080[pi]\b"), "1080p"),
    (re.compile(r"\b720[pi]\b"), "720p"),
    (re.compile(r"\b576[pi]\b"), "576p"),
    (re.compile(r"\b480[pi]\b"), "480p"),
]

# ---- source ---------------------------------------------------------------
_SOURCE = [
    (re.compile(r"\bremux\b"), "remux"),
    (re.compile(r"\b(?:bluray|blu ray|bdrip|brrip|bd25|bd50|bdremux)\b"), "bluray"),
    (re.compile(r"\b(?:web ?dl|webdl)\b"), "web-dl"),
    (re.compile(r"\bwebrip\b"), "webrip"),
    (re.compile(r"\bweb\b"), "web"),
    (re.compile(r"\bhdtv\b"), "hdtv"),
    (re.compile(r"\bpdtv\b"), "pdtv"),
    (re.compile(r"\b(?:dvdrip|dvd5|dvd9|dvdr|dvd)\b"), "dvd"),
    (re.compile(r"\bhdrip\b"), "hdrip"),
    (re.compile(r"\b(?:cam|camrip|hdcam)\b"), "cam"),
    (re.compile(r"\b(?:ts|telesync|hdts)\b"), "telesync"),
]

# ---- video codec ----------------------------------------------------------
_VCODEC = [
    (re.compile(r"\b(?:x265|h ?265|hevc)\b"), "x265"),
    (re.compile(r"\b(?:x264|h ?264|avc)\b"), "x264"),
    (re.compile(r"\bav1\b"), "av1"),
    (re.compile(r"\b(?:xvid|divx)\b"), "xvid"),
    (re.compile(r"\bmpeg2\b"), "mpeg2"),
]

# ---- audio codec ----------------------------------------------------------
_ACODEC = [
    (re.compile(r"\b(?:truehd|true hd)\b"), "truehd"),
    (re.compile(r"\b(?:dts ?hd|dts x|dtsx|dts hd ma)\b"), "dts-hd"),
    (re.compile(r"\bdts\b"), "dts"),
    (re.compile(r"\b(?:eac3|e ac3|ddp|dd\+)\b"), "eac3"),
    (re.compile(r"\b(?:ac3|dd5 1|dd)\b"), "ac3"),
    (re.compile(r"\baac\b"), "aac"),
    (re.compile(r"\bflac\b"), "flac"),
    (re.compile(r"\bopus\b"), "opus"),
    (re.compile(r"\bmp3\b"), "mp3"),
    (re.compile(r"\batmos\b"), "atmos"),
]

# ---- HDR ------------------------------------------------------------------
_HDR = [
    (re.compile(r"\b(?:dolby vision|dovi|\bdv\b)\b"), "dolby-vision"),
    (re.compile(r"\bhdr10\+\b|\bhdr10plus\b"), "hdr10+"),
    (re.compile(r"\bhdr10\b"), "hdr10"),
    (re.compile(r"\bhdr\b"), "hdr"),
]

# ---- edition --------------------------------------------------------------
_EDITION = [
    (re.compile(r"\b(?:extended|extended cut)\b"), "extended"),
    (re.compile(r"\b(?:remaster(?:ed)?)\b"), "remastered"),
    (re.compile(r"\b(?:director'?s cut|directors cut)\b"), "directors-cut"),
    (re.compile(r"\bunrated\b"), "unrated"),
    (re.compile(r"\bimax\b"), "imax"),
    (re.compile(r"\bproper\b"), "proper"),
    (re.compile(r"\brepack\b"), "repack"),
]

# ---- language (a small, common set) ---------------------------------------
_LANG = [
    (re.compile(r"\bmulti\b"), "multi"),
    (re.compile(r"\bdual\b"), "dual"),
    (re.compile(r"\b(?:ita(?:lian)?)\b"), "it"),
    (re.compile(r"\b(?:fre(?:nch)?|vostfr|truefrench)\b"), "fr"),
    (re.compile(r"\b(?:ger(?:man)?)\b"), "de"),
    (re.compile(r"\b(?:spa(?:nish)?|castellano)\b"), "es"),
    (re.compile(r"\b(?:rus(?:sian)?)\b"), "ru"),
    (re.compile(r"\b(?:jap(?:anese)?|jpn)\b"), "ja"),
]

_YEAR = re.compile(r"\b(19\d{2}|20\d{2})\b")
_SXXEXX = re.compile(r"\bs(\d{1,2})[ ]?e(\d{1,3})\b")
_SEASON = re.compile(r"\b(?:season|series)[ ]?(\d{1,2})\b|\bs(\d{2})\b")
_EP_ONLY = re.compile(r"\be(\d{1,3})\b|\bepisode[ ]?(\d{1,3})\b")
_GROUP = re.compile(r"-([A-Za-z0-9]{2,20})\s*$")  # trailing -GROUP on the RAW name

# music / book / software / game hints (extension- and keyword-based fallbacks)
_MUSIC_EXT = {"mp3", "flac", "wav", "aac", "ogg", "m4a", "opus", "ape", "alac"}
_BOOK_EXT = {"epub", "mobi", "azw3", "pdf", "djvu", "cbz", "cbr"}
_SOFTWARE_EXT = {"exe", "msi", "dmg", "apk", "deb", "rpm", "pkg", "iso"}
_GAME_HINT = re.compile(r"\b(?:repack|fitgirl|dodi|codex|plaza|skidrow|goty|"
                        r"razor1911|flt|reloaded)\b")


def _first(patterns, text: str) -> Optional[str]:
    for rx, val in patterns:
        if rx.search(text):
            return val
    return None


def _dominant_ext(files: Optional[Sequence[Tuple[str, int]]]) -> str:
    """Extension of the single largest file (best 'kind' signal)."""
    if not files:
        return ""
    try:
        path, _ = max(files, key=lambda pl: int(pl[1] or 0))
    except (ValueError, TypeError):
        return ""
    return path.rsplit(".", 1)[-1].lower() if "." in path else ""


def classify(name: str,
             files: Optional[Sequence[Tuple[str, int]]] = None) -> Dict[str, object]:
    """Return a dict of attribute facets extracted from *name* (+ *files*).

    Keys are a subset of :data:`FACET_KEYS`.  ``season``/``episode`` are ints;
    ``year`` is an int; everything else is a short lower-case token.  Absent
    facets are simply omitted.
    """
    raw = (name or "")[:_MAX_NAME]
    n = _norm(raw)
    out: Dict[str, object] = {}

    res = _first(_RES, n)
    if res:
        out["resolution"] = res
    src = _first(_SOURCE, n)
    if src:
        out["source"] = src
    vc = _first(_VCODEC, n)
    if vc:
        out["vcodec"] = vc
    ac = _first(_ACODEC, n)
    if ac:
        out["acodec"] = ac
    hdr = _first(_HDR, n)
    if hdr:
        out["hdr"] = hdr
    ed = _first(_EDITION, n)
    if ed:
        out["edition"] = ed
    lang = _first(_LANG, n)
    if lang:
        out["lang"] = lang

    m = _SXXEXX.search(n)
    if m:
        out["season"] = int(m.group(1))
        out["episode"] = int(m.group(2))
    else:
        ms = _SEASON.search(n)
        if ms:
            out["season"] = int(ms.group(1) or ms.group(2))

    y = _YEAR.search(n)
    if y:
        out["year"] = int(y.group(1))

    g = _GROUP.search(raw.strip())
    if g:
        grp = g.group(1).lower()
        # avoid picking up a resolution/codec token as a "group"
        if grp not in ("x264", "x265", "h264", "h265", "1080p", "720p", "2160p"):
            out["group"] = grp

    # media kind: TV if season/episode, else use extension + name hints
    ext = _dominant_ext(files)
    if "season" in out or "episode" in out:
        out["kind"] = "tv"
    elif ext in _MUSIC_EXT:
        out["kind"] = "music"
    elif ext in _BOOK_EXT:
        out["kind"] = "book"
    elif _GAME_HINT.search(n) and (ext in _SOFTWARE_EXT or "resolution" not in out):
        out["kind"] = "game"
    elif ext in _SOFTWARE_EXT and "resolution" not in out:
        out["kind"] = "software"
    elif "resolution" in out or "source" in out or "year" in out:
        out["kind"] = "movie"
    return out


def tag_string(facets: Dict[str, object]) -> str:
    """Serialise facets to a compact, searchable ``key:value`` token string.

    e.g. ``{"resolution":"1080p","source":"web-dl","year":2019}`` ->
    ``"resolution:1080p source:web-dl year:2019"``.  Stable order (FACET_KEYS),
    so equal facet sets serialise identically.
    """
    parts: List[str] = []
    for k in FACET_KEYS:
        if k in facets and facets[k] not in (None, ""):
            parts.append("%s:%s" % (k, facets[k]))
    return " ".join(parts)
