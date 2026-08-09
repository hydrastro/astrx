"""Torznab endpoint: expose the index as a Torznab/Newznab-compatible feed so
Prowlarr / Jackett and the *arr stack can use torrentds as a first-class
indexer.  Pure stdlib XML text, escape-safe (reuses the RSS XML sanitiser via a
lazy import to avoid an import cycle).

Torznab is Newznab's torrent dialect: ``?t=caps`` returns a capabilities
document; ``?t=search`` (and the ``tvsearch`` / ``movie`` / ``music`` / ``book``
aliases) return an RSS 2.0 feed whose ``<item>``s carry ``<torznab:attr>``
metadata (category, size, seeders, peers, infohash, magneturl).
"""

from email.utils import formatdate

TORZNAB_NS = "http://torznab.com/schemas/2015/feed"

# our coarse categories -> Torznab standard category ids
_CAT_MAP = {"audio": "3000", "document": "7000", "software": "4000",
            "image": "8000", "archive": "8000", "other": "8000"}
# Torznab category id -> our store category (for the ?cat= filter)
_CAT_REVERSE = {"2000": "video", "5000": "video", "3000": "audio",
                "7000": "document", "4000": "software", "8000": None}

# search-type aliases Prowlarr issues -> our handling (all map to a text search)
SEARCH_TYPES = {"search", "tvsearch", "tv-search", "movie", "movie-search",
                "music", "audio", "audio-search", "book", "book-search"}


def _x(text):
    # lazy import so torznab can `from .search import _x` without a load-time
    # cycle (search.py imports torznab only inside the request handler).
    from .search import _x as esc
    return esc(text or "")


def category_of(row):
    """Torznab category id for a result row (TV vs Movie split via the
    classifier's ``kind:tv`` tag)."""
    cat = row.get("category") or "other"
    if cat == "video":
        return "5000" if "kind:tv" in (row.get("tags") or "") else "2000"
    return _CAT_MAP.get(cat, "8000")


def store_category_for_cat(cat_param):
    """Map a Torznab ``cat=`` value to our store category filter (or None)."""
    if not cat_param:
        return None
    # a client may send a comma list; use the first recognised id
    for part in str(cat_param).split(","):
        part = part.strip()
        if part in _CAT_REVERSE:
            return _CAT_REVERSE[part]
    return None


def caps_xml():
    """The Torznab capabilities document."""
    return (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<caps><server title="torrentds"/>'
        '<limits max="100" default="25"/>'
        "<searching>"
        '<search available="yes" supportedParams="q"/>'
        '<tv-search available="yes" supportedParams="q,season,ep"/>'
        '<movie-search available="yes" supportedParams="q"/>'
        '<audio-search available="yes" supportedParams="q"/>'
        '<book-search available="yes" supportedParams="q"/>'
        "</searching><categories>"
        '<category id="2000" name="Movies"/>'
        '<category id="5000" name="TV"/>'
        '<category id="3000" name="Audio"/>'
        '<category id="4000" name="PC"/>'
        '<category id="7000" name="Books"/>'
        '<category id="8000" name="Other"/>'
        "</categories></caps>"
    ).encode("utf-8")


def search_xml(items, base_url="", offset=0, total=None):
    """RSS 2.0 + torznab attrs for a result list.

    Download links are the served ``.torrent`` (relative when *base_url* is empty
    — Prowlarr resolves it against the indexer URL); the magnet is also carried
    as a ``magneturl`` attr.  Every field is XML-escape+sanitised.
    """
    if total is None:
        total = len(items)
    parts = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<rss version="2.0" xmlns:torznab="%s"><channel>' % TORZNAB_NS,
        "<title>torrentds</title>",
        "<link>%s/</link>" % _x(base_url),
        "<description>DHT torrent-metadata index</description>",
    ]
    for r in items:
        ih = r["infohash"]
        name = _x(r.get("name") or ih)
        size = int(r.get("total_size") or 0)
        magnet = r.get("magnet") or ""
        dl = "%s/torrent/%s.torrent" % (base_url, ih)
        # prefer the combined swarm health (DHT + tracker-scrape) when present
        seeders = int(r.get("swarm_seeders", r.get("seeders")) or 0)
        leechers = int(r.get("swarm_leechers", r.get("leechers")) or 0)
        cat = category_of(r)
        pub = formatdate(r.get("last_seen") or 0, usegmt=True)
        parts.append(
            "<item>"
            "<title>%s</title>"
            '<guid isPermaLink="false">%s</guid>'
            "<pubDate>%s</pubDate>"
            "<size>%d</size>"
            "<link>%s</link>"
            '<enclosure url="%s" length="%d" type="application/x-bittorrent"/>'
            '<torznab:attr name="category" value="%s"/>'
            '<torznab:attr name="size" value="%d"/>'
            '<torznab:attr name="seeders" value="%d"/>'
            '<torznab:attr name="peers" value="%d"/>'
            '<torznab:attr name="infohash" value="%s"/>'
            '<torznab:attr name="magneturl" value="%s"/>'
            "</item>"
            % (name, _x(ih), pub, size, _x(dl), _x(dl), size,
               cat, size, seeders, seeders + leechers, _x(ih), _x(magnet))
        )
    parts.append("</channel></rss>")
    return "".join(parts).encode("utf-8")
