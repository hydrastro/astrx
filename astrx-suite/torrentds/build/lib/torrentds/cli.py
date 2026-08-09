"""Command-line interface for torrentds.

Subcommands:
    index    run the DHT harvester (crawl + fetch metadata into the store)
    search   run the no-JS search web server + JSON API
    tracker  run the HTTP + UDP BitTorrent trackers
    stats    print store statistics
    block    add an infohash/keyword to the blocklist (and purge matches)
    backup   make a safe SQLite backup of the store to a local path
"""

from __future__ import annotations

import argparse
import asyncio
import signal
import sys
import threading
from typing import List, Optional, Tuple

from .indexer import Indexer
from .peerstore import PeerStore
from .search import make_search_server
from .store import Store
from .tracker_http import make_http_tracker
from .tracker_udp import UDPTracker


def parse_hostports(text: Optional[str]) -> List[Tuple[str, int]]:
    out: List[Tuple[str, int]] = []
    if not text:
        return out
    for item in text.split(","):
        item = item.strip()
        if not item:
            continue
        host, _, port = item.rpartition(":")
        out.append((host or "127.0.0.1", int(port)))
    return out


# --------------------------------------------------------------------------
# index
# --------------------------------------------------------------------------

def cmd_index(args) -> int:
    store = Store(args.db)
    if args.no_bootstrap:
        bootstrap: Optional[List[Tuple[str, int]]] = []
    elif args.bootstrap:
        bootstrap = parse_hostports(args.bootstrap)
    else:
        bootstrap = None  # use built-in Mainline routers
    indexer = Indexer(store, host=args.host, port=args.port, bootstrap=bootstrap,
                      fetch_concurrency=args.concurrency, num_nodes=args.nodes,
                      neighbor=args.neighbor)

    async def main() -> None:
        await indexer.start()
        print("[index] %d DHT node(s), primary %s on %s:%d (concurrency=%d, neighbor=%s)"
              % (len(indexer.nodes), indexer.node.self_id.hex()[:16], args.host,
                 indexer.port, args.concurrency, args.neighbor))
        print("[index] crawling + BEP-51 sampling + harvesting; Ctrl-C to stop")
        loop = asyncio.get_running_loop()
        stop = loop.create_future()
        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                loop.add_signal_handler(sig, lambda: stop.set_result(None))
            except NotImplementedError:  # pragma: no cover (Windows)
                pass
        runner = asyncio.ensure_future(indexer.run(
            crawl_interval=args.interval,
            max_torrents=args.max_torrents,
            max_age_seconds=(args.max_age_days * 86400 if args.max_age_days else None)))
        await stop
        print("\n[index] shutting down, saving state...")
        await indexer.stop()
        runner.cancel()

    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
    finally:
        s = store.stats()
        print("[index] discovered=%d fetched-torrents=%d pending=%d"
              % (s["discovered"], s["torrents"], s["pending"]))
        store.close()
    return 0


# --------------------------------------------------------------------------
# search
# --------------------------------------------------------------------------

def cmd_search(args) -> int:
    store = Store(args.db, spam_threshold=args.spam_threshold)
    aggregator = None
    if args.trackers:
        from .scrape import ScrapeAggregator
        specs = [s.strip() for s in args.trackers.split(",") if s.strip()]
        aggregator = ScrapeAggregator.from_specs(specs)
    server = make_search_server(store, host=args.host, port=args.port,
                                admin_token=args.admin_token or "",
                                scrape_aggregator=aggregator)
    print("[search] serving http://%s:%d  (no-JS UI, /browse, /recent, "
          "/api/search, /api/block)" % (args.host, server.server_address[1]))
    if args.admin_token:
        print("[search] POST /api/block enabled (admin token set)")
    if aggregator is not None:
        print("[search] scrape aggregation across %d tracker(s)"
              % len(aggregator.trackers))
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
        store.close()
    return 0


# --------------------------------------------------------------------------
# tracker
# --------------------------------------------------------------------------

def cmd_tracker(args) -> int:
    peer_store = PeerStore(interval=args.interval)
    store = None
    if args.db:
        # Feed the operator blocklist (infohashes) into the tracker denylist.
        store = Store(args.db)
        with store._lock:  # noqa: SLF001 - internal read of blocklist
            rows = store._conn.execute(
                "SELECT infohash FROM blocklist_infohash").fetchall()
        peer_store.set_denylist([r[0] for r in rows])
    if args.allow:
        peer_store.set_allowlist([h.strip().lower() for h in args.allow.split(",")])

    # Durable swarms: restore on start, persist periodically + on shutdown.
    if args.peers_db:
        restored = peer_store.load_from_file(args.peers_db)
        if restored:
            print("[tracker] restored %d peer(s) from %s" % (restored, args.peers_db))

    saver_stop = threading.Event()

    def _saver() -> None:
        while not saver_stop.wait(60):
            try:
                peer_store.save_to_file(args.peers_db)
            except OSError:
                pass

    http_server = make_http_tracker(peer_store, host=args.host, port=args.http_port)
    udp_tracker = UDPTracker(peer_store, host=args.host, port=args.udp_port)
    udp_tracker.start()
    http_thread = threading.Thread(target=http_server.serve_forever, daemon=True)
    http_thread.start()
    saver_thread = None
    if args.peers_db:
        saver_thread = threading.Thread(target=_saver, daemon=True)
        saver_thread.start()
    print("[tracker] HTTP  http://%s:%d/announce  /scrape"
          % (args.host, http_server.server_address[1]))
    print("[tracker] UDP   udp://%s:%d  (BEP-15, IPv6/BEP-7, stateless conn-id)"
          % (args.host, udp_tracker.port))
    try:
        signal.pause()
    except (KeyboardInterrupt, AttributeError):
        pass
    finally:
        saver_stop.set()
        http_server.shutdown()
        http_server.server_close()
        udp_tracker.stop()
        if args.peers_db:
            try:
                peer_store.save_to_file(args.peers_db)
            except OSError:
                pass
        if store is not None:
            store.close()
    return 0


# --------------------------------------------------------------------------
# stats / block
# --------------------------------------------------------------------------

def cmd_stats(args) -> int:
    store = Store(args.db)
    s = store.stats()
    print("torrents indexed : %d" % s["torrents"])
    print("files indexed    : %d" % s["files"])
    print("total size       : %d bytes" % s["total_size"])
    print("infohashes seen  : %d (pending fetch: %d)" % (s["discovered"], s["pending"]))
    print("DHT contacts     : %d" % s["dht_nodes"])
    print("blocklist        : %d infohashes, %d keywords"
          % (s["blocked_infohash"], s["blocked_keyword"]))
    store.close()
    return 0


def cmd_block(args) -> int:
    store = Store(args.db)
    if args.infohash:
        store.add_block_infohash(args.infohash)
        print("blocked infohash %s" % args.infohash)
    if args.keyword:
        store.add_block_keyword(args.keyword)
        print("blocked keyword %r" % args.keyword)
    removed = store.purge_blocked()
    print("purged %d matching torrent(s) from the index" % removed)
    store.close()
    return 0


def cmd_backup(args) -> int:
    store = Store(args.db)
    try:
        info = store.backup(args.out)
        print("[backup] wrote %s (%d torrents, %d bytes)"
              % (info["path"], info["torrents"], info["bytes"]))
    finally:
        store.close()
    return 0


# --------------------------------------------------------------------------
# arg parsing
# --------------------------------------------------------------------------

def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="torrentds",
                                description="DHT torrent-metadata search engine + tracker")
    sub = p.add_subparsers(dest="command", required=True)

    pi = sub.add_parser("index", help="run the DHT harvester")
    pi.add_argument("--db", required=True)
    pi.add_argument("--host", default="127.0.0.1")
    pi.add_argument("--port", type=int, default=6881)
    pi.add_argument("--bootstrap", help="comma list host:port (default: Mainline routers)")
    pi.add_argument("--no-bootstrap", action="store_true",
                    help="do not contact any bootstrap routers")
    pi.add_argument("--interval", type=float, default=1.0, help="crawl interval seconds")
    pi.add_argument("--concurrency", type=int, default=20,
                    help="parallel metadata fetches (fetch-pool size)")
    pi.add_argument("--nodes", type=int, default=1,
                    help="number of DHT node-IDs/ports for ID-space coverage")
    pi.add_argument("--neighbor", action="store_true",
                    help="aggressive neighbour-ID harvesting (magnetico-style)")
    pi.add_argument("--max-torrents", type=int, default=None,
                    help="retention cap: keep only the N most-recent torrents")
    pi.add_argument("--max-age-days", type=float, default=None,
                    help="retention: drop torrents not seen within this many days")
    pi.set_defaults(func=cmd_index)

    ps = sub.add_parser("search", help="run the no-JS search server")
    ps.add_argument("--db", required=True)
    ps.add_argument("--host", default="127.0.0.1")
    ps.add_argument("--port", type=int, default=8804)
    ps.add_argument("--admin-token", default=None,
                    help="token for POST /api/block (unset => 403 for all)")
    ps.add_argument("--trackers", default=None,
                    help="comma list of scrape trackers (http(s)://.../announce "
                         "or udp://host:port) to aggregate swarm health from")
    ps.add_argument("--spam-threshold", type=float, default=None,
                    help="hide torrents with a spam score >= this (default tuned)")
    ps.set_defaults(func=cmd_search)

    pt = sub.add_parser("tracker", help="run the HTTP + UDP trackers")
    pt.add_argument("--db", help="optional store db; sources the blocklist denylist")
    pt.add_argument("--host", default="127.0.0.1")
    pt.add_argument("--http-port", type=int, default=8805)
    pt.add_argument("--udp-port", type=int, default=6969)
    pt.add_argument("--interval", type=int, default=1800, help="announce interval seconds")
    pt.add_argument("--allow", help="comma list of allowed infohashes (hex)")
    pt.add_argument("--peers-db", help="file to persist/restore swarms across restart")
    pt.set_defaults(func=cmd_tracker)

    pst = sub.add_parser("stats", help="print store statistics")
    pst.add_argument("--db", required=True)
    pst.set_defaults(func=cmd_stats)

    pb = sub.add_parser("block", help="add to blocklist and purge matches")
    pb.add_argument("--db", required=True)
    pb.add_argument("--infohash", help="40-char hex infohash to block")
    pb.add_argument("--keyword", help="substring to block by name")
    pb.set_defaults(func=cmd_block)

    pbk = sub.add_parser("backup", help="safe SQLite backup of the store")
    pbk.add_argument("--db", required=True)
    pbk.add_argument("--out", required=True, help="destination .db path")
    pbk.set_defaults(func=cmd_backup)
    return p


def main(argv: Optional[List[str]] = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
