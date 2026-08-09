"""torrentds: a zero-dependency DHT torrent-metadata search engine + tracker.

Pure Python 3.11 standard library.  Indexes torrent *metadata* harvested
from the BitTorrent Mainline DHT (magnet links / info-dicts only -- never
content) and ships a standards-compliant HTTP + UDP tracker.

Public submodules:
    bencode       BEP-3 bencode codec
    routing       Kademlia node IDs, XOR distance, k-buckets
    krpc          KRPC (BEP-5) message codec + asyncio UDP transport
    dht           DHT node + passive infohash harvester
    metadata      peer wire + ut_metadata (BEP-3 / BEP-10 / BEP-9)
    store         SQLite metadata store + FTS5 search + blocklist
    search        no-JS search web server + JSON API
    peerstore     tracker swarm peer store
    tracker_http  HTTP tracker (BEP-3 / BEP-23)
    tracker_udp   UDP tracker (BEP-15)
    indexer       harvester orchestrator
    cli           command-line interface
"""

__version__ = "1.0.0"
