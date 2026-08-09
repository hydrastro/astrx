"""Command-line interface.

  python3 -m onioncrawler crawl  --seeds seeds.txt --db crawl.db
  python3 -m onioncrawler search --db crawl.db --port 8802
  python3 -m onioncrawler stats  --db crawl.db
"""

from __future__ import annotations

import argparse
import signal
import sys
import time

from .config import Config
from .storage import Storage
from .crawler import Crawler
from .fetcher import build_fetcher
from .abuse import load_abuse_filter
from .search import serve
from .stats import format_stats, stats_json
from .submit import submit_many
from .log import make_logger


def _read_seeds(path, extra):
    seeds = []
    if path:
        with open(path, "r", encoding="utf-8", errors="replace") as fh:
            for line in fh:
                line = line.split("#", 1)[0].strip()
                if line:
                    seeds.append(line)
    seeds.extend(extra or [])
    return seeds


def _config_from_args(args) -> Config:
    c = Config()
    c.db_path = args.db
    for name in (
        "fetcher", "tor_host", "tor_port", "tor_pool", "allow_v2", "workers",
        "crawl_delay", "max_depth", "max_pages_per_host", "max_total_pages",
        "max_pages_this_run", "media_max_bytes",
        "blocklist_hosts_path", "blocklist_keywords_path",
        "blocklist_media_path", "blocklist_host_md5_path",
        "bind_host", "bind_port", "admin_user",
        "admin_pass", "admin_token", "allow_public_submit", "rate_limit_enabled",
        "authority_weight", "metrics_token",
        "enable_i2p", "i2p_proxy_host", "i2p_proxy_port", "direct_network",
        "seed_list_path", "reseed_interval", "submission_ttl",
    ):
        if hasattr(args, name) and getattr(args, name) is not None:
            setattr(c, name, getattr(args, name))
    if getattr(args, "direct_map", None):
        c.direct_map = args.direct_map
    if getattr(args, "no_robots", False):
        c.obey_robots = False
    return c


def cmd_crawl(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    if getattr(cfg, "submission_ttl", 0):
        reaped = st.reap_unverified(cfg.submission_ttl)
        if reaped:
            print("[crawl] expired %d unverified queued seed(s) past TTL" % reaped)
    fetcher = build_fetcher(cfg)
    abuse = load_abuse_filter(cfg.blocklist_hosts_path, cfg.blocklist_keywords_path,
                              cfg.blocklist_media_path,
                              host_md5_path=getattr(cfg, "blocklist_host_md5_path", None))
    if not abuse.hosts and not abuse.keywords and not abuse.media:
        sys.stderr.write(
            "WARNING: abuse blocklists are empty. Operators of any legitimate "
            ".onion index MUST configure abuse filtering (see README).\n")
    log = (lambda *a: print("[crawl]", *a, flush=True)) if args.verbose else (lambda *a: None)
    crawler = Crawler(cfg, st, fetcher, abuse, log=log)

    def _stop(signum, frame):
        print(f"\n[crawl] signal {signum}: finishing in-flight work and saving state...",
              flush=True)
        crawler.stop.set()

    signal.signal(signal.SIGINT, _stop)
    signal.signal(signal.SIGTERM, _stop)

    seeds = _read_seeds(args.seeds, args.seed)
    t0 = time.time()
    stats = crawler.run(seeds)
    st.close()
    dt = time.time() - t0
    print(f"[crawl] finished in {dt:.1f}s: "
          f"{stats['pages']} pages, {stats['urls_enqueued']} urls enqueued, "
          f"{len(stats['trapped_hosts'])} trapped/blocked host(s)")
    return 0


def cmd_search(args):
    cfg = _config_from_args(args)
    log = make_logger(enabled=True, component="onionsearch")
    httpd, st = serve(cfg, log=log)
    url = f"http://{cfg.bind_host}:{cfg.bind_port}/"
    admin = "on" if (cfg.admin_user and cfg.admin_pass) else "off"
    print(f"[search] serving {st.counter('pages_stored')} pages at {url} "
          f"(admin auth: {admin}; Ctrl-C to stop)", flush=True)
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.shutdown()
        st.close()
    return 0


def cmd_submit(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    abuse = load_abuse_filter(cfg.blocklist_hosts_path, cfg.blocklist_keywords_path,
                              cfg.blocklist_media_path,
                              host_md5_path=getattr(cfg, "blocklist_host_md5_path", None))
    urls = list(args.url or [])
    if args.file:
        urls += _read_seeds(args.file, None)
    if not urls:
        sys.stderr.write("submit: provide URL(s) or --file\n")
        st.close()
        return 2
    res = submit_many(st, abuse, urls, allow_v2=cfg.allow_v2,
                      allow_i2p=cfg.enable_i2p)
    st.close()
    print(f"[submit] ok={res['ok']} dup={res['dup']} "
          f"blocked={res['blocked']} not-onion={res['not-onion']}")
    return 0


def cmd_reseed(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    abuse = load_abuse_filter(cfg.blocklist_hosts_path, cfg.blocklist_keywords_path,
                              cfg.blocklist_media_path,
                              host_md5_path=getattr(cfg, "blocklist_host_md5_path", None))
    from . import seedlist
    seeds = []
    if getattr(args, "seed_list", None):
        seeds = seedlist.load_seed_list(
            args.seed_list, allow_v2=cfg.allow_v2, allow_i2p=cfg.enable_i2p)
    seeds += list(args.seed or [])
    if not seeds:
        sys.stderr.write("reseed: provide --seed-list FILE and/or --seed URL\n")
        st.close()
        return 2
    res = seedlist.reseed(st, abuse, seeds, allow_v2=cfg.allow_v2,
                          allow_i2p=cfg.enable_i2p, caps=None, force=True)
    st.close()
    print(f"[reseed] reseeded={res['reseeded']} added={res['added']} "
          f"blocked={res['blocked']} capped={res['capped']} "
          f"not-onion={res['not-onion']}")
    return 0


def cmd_backup(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    dest = args.out or (cfg.db_path.rstrip("/") +
                        f".backup-{time.strftime('%Y%m%d-%H%M%S')}.db")
    path = st.backup_to(dest)
    st.close()
    print(f"[backup] wrote {path}")
    return 0


def cmd_authority(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    n = st.compute_authority(iterations=args.iterations, damping=args.damping)
    st.close()
    print(f"[authority] scored {n} host(s) via PageRank-lite")
    return 0


def cmd_cluster(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    n = st.cluster_mirrors(threshold=args.threshold)
    st.close()
    print(f"[cluster] {n} mirror cluster(s) found")
    return 0


def cmd_recrawl(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    n = st.mark_recrawl_due(default_interval=cfg.recrawl_ttl)
    st.close()
    print(f"[recrawl] marked {n} page(s) due for recrawl")
    return 0


def cmd_stats(args):
    cfg = _config_from_args(args)
    st = Storage(cfg.db_path)
    print(stats_json(st) if args.json else format_stats(st))
    st.close()
    return 0


def build_parser():
    p = argparse.ArgumentParser(prog="onioncrawler", description=__doc__)
    sub = p.add_subparsers(dest="command", required=True)

    def common(sp):
        sp.add_argument("--db", default="crawl.db", help="SQLite DB path")

    pc = sub.add_parser("crawl", help="crawl .onion seeds (resumable)")
    common(pc)
    pc.add_argument("--seeds", help="file of seed URLs (one per line)")
    pc.add_argument("--seed", action="append", help="a seed URL (repeatable)")
    pc.add_argument("--seed-list", dest="seed_list_path",
                    help="curated known-onions seed file for scheduled re-seed")
    pc.add_argument("--reseed-interval", dest="reseed_interval", type=float,
                    default=None,
                    help="seconds between re-enqueuing the curated seed list "
                         "(0/off by default; keeps rediscovering roots)")
    pc.add_argument("--fetcher", choices=["tor", "i2p", "direct"], default="tor")
    pc.add_argument("--tor-host", dest="tor_host", default="127.0.0.1")
    pc.add_argument("--tor-port", dest="tor_port", type=int, default=9050)
    pc.add_argument("--tor-pool", dest="tor_pool", default="",
                    help="torfleet: comma-separated extra Tor SOCKS endpoints "
                         "(host:port,...) to spread crawling across N daemons")
    pc.add_argument("--submission-ttl", dest="submission_ttl", type=float,
                    default=0.0,
                    help="expire never-crawled queued seeds older than N seconds "
                         "at the start of each crawl (public-submission TTL); 0=off")
    pc.add_argument("--enable-i2p", dest="enable_i2p", action="store_true",
                    default=None, help="allow .i2p crawling (darknet-only; off by default)")
    pc.add_argument("--i2p-proxy-host", dest="i2p_proxy_host", default="127.0.0.1")
    pc.add_argument("--i2p-proxy-port", dest="i2p_proxy_port", type=int, default=4444)
    pc.add_argument("--direct-map", dest="direct_map", action="append",
                    help="TEST ONLY host.onion=127.0.0.1:PORT (repeatable)")
    pc.add_argument("--allow-v2", dest="allow_v2", action="store_true", default=None)
    pc.add_argument("--workers", type=int)
    pc.add_argument("--crawl-delay", dest="crawl_delay", type=float)
    pc.add_argument("--max-depth", dest="max_depth", type=int)
    pc.add_argument("--max-pages-per-host", dest="max_pages_per_host", type=int)
    pc.add_argument("--max-total-pages", dest="max_total_pages", type=int)
    pc.add_argument("--max-pages-this-run", dest="max_pages_this_run", type=int)
    pc.add_argument("--no-robots", dest="no_robots", action="store_true")
    pc.add_argument("--blocklist-hosts", dest="blocklist_hosts_path",
                    default="blocklist_hosts.txt")
    pc.add_argument("--blocklist-keywords", dest="blocklist_keywords_path",
                    default="blocklist_keywords.txt")
    pc.add_argument("--blocklist-media", dest="blocklist_media_path",
                    default="blocklist_media.txt",
                    help="file of hex sha256 media hashes to drop (Ahmia-grade)")
    pc.add_argument("--blocklist-host-md5", dest="blocklist_host_md5_path",
                    default="",
                    help="file of md5(onion_domain) hex digests to block "
                         "(subscribe to Ahmia's published banned-domain list)")
    pc.add_argument("--media-max-bytes", dest="media_max_bytes", type=int,
                    default=None,
                    help="read cap for hashing a media resource against the "
                         "media blocklist (default 12MB; must exceed "
                         "--max-response-bytes to catch large known-bad media)")
    pc.add_argument("-v", "--verbose", action="store_true")
    pc.set_defaults(func=cmd_crawl)

    ps = sub.add_parser("search", help="serve the no-JS search UI")
    common(ps)
    ps.add_argument("--host", dest="bind_host", default="127.0.0.1")
    ps.add_argument("--port", dest="bind_port", type=int, default=8802)
    # so the search server can reconcile already-indexed pages against the same
    # abuse blocklist the crawler uses (host/keyword added post-indexing).
    ps.add_argument("--blocklist-hosts", dest="blocklist_hosts_path",
                    default="blocklist_hosts.txt")
    ps.add_argument("--blocklist-keywords", dest="blocklist_keywords_path",
                    default="blocklist_keywords.txt")
    ps.add_argument("--blocklist-media", dest="blocklist_media_path",
                    default="blocklist_media.txt")
    ps.add_argument("--blocklist-host-md5", dest="blocklist_host_md5_path",
                    default="",
                    help="Ahmia md5(domain) banlist file; also republished at "
                         "/blocklist/banned.md5")
    ps.add_argument("--enable-i2p", dest="enable_i2p", action="store_true",
                    default=None, help="accept .i2p host filters/submissions")
    ps.add_argument("--admin-user", dest="admin_user", default=None,
                    help="enable admin actions (add/purge/recrawl) with this user")
    ps.add_argument("--admin-pass", dest="admin_pass", default=None,
                    help="admin password (Basic auth)")
    ps.add_argument("--admin-token", dest="admin_token", default=None,
                    help="bearer token gating POST /blocklist (AstrX blocklist "
                         "editor); unset => /blocklist returns 403")
    ps.add_argument("--allow-public-submit", dest="allow_public_submit",
                    action="store_true", default=None,
                    help="allow POST /add without auth (off by default)")
    ps.add_argument("--metrics-token", dest="metrics_token", default=None,
                    help="require this token (?token= / X-Metrics-Token / Bearer) "
                         "on /metrics and /health; off by default (/healthz stays open)")
    ps.add_argument("--no-rate-limit", dest="rate_limit_enabled",
                    action="store_false", default=None)
    ps.add_argument("--authority-weight", dest="authority_weight", type=float,
                    default=None, help="blend host PageRank into ranking")
    ps.set_defaults(func=cmd_search)

    pt = sub.add_parser("stats", help="show frontier/pages/host stats")
    common(pt)
    pt.add_argument("--json", action="store_true")
    pt.set_defaults(func=cmd_stats)

    pb = sub.add_parser("submit", help="validate + enqueue seed darknet URL(s)")
    common(pb)
    pb.add_argument("url", nargs="*", help="one or more .onion/.i2p URLs")
    pb.add_argument("--file", help="file of URLs to bulk-import (one per line)")
    pb.add_argument("--allow-v2", dest="allow_v2", action="store_true", default=None)
    pb.add_argument("--enable-i2p", dest="enable_i2p", action="store_true",
                    default=None, help="also accept .i2p submissions")
    pb.add_argument("--blocklist-hosts", dest="blocklist_hosts_path",
                    default="blocklist_hosts.txt")
    pb.add_argument("--blocklist-keywords", dest="blocklist_keywords_path",
                    default="blocklist_keywords.txt")
    pb.add_argument("--blocklist-media", dest="blocklist_media_path",
                    default="blocklist_media.txt")
    pb.set_defaults(func=cmd_submit)

    # reseed (alias: seeds) - import a curated seed list + re-enqueue the roots
    prs = sub.add_parser("reseed", aliases=["seeds"],
                         help="import a curated seed list and re-enqueue the roots")
    common(prs)
    prs.add_argument("--seed-list", dest="seed_list",
                     help="curated seed file (one .onion/.i2p per line)")
    prs.add_argument("--seed", action="append", help="a seed URL (repeatable)")
    prs.add_argument("--allow-v2", dest="allow_v2", action="store_true", default=None)
    prs.add_argument("--enable-i2p", dest="enable_i2p", action="store_true",
                     default=None, help="also accept .i2p seeds")
    prs.add_argument("--blocklist-hosts", dest="blocklist_hosts_path",
                     default="blocklist_hosts.txt")
    prs.add_argument("--blocklist-keywords", dest="blocklist_keywords_path",
                     default="blocklist_keywords.txt")
    prs.add_argument("--blocklist-media", dest="blocklist_media_path",
                     default="blocklist_media.txt")
    prs.set_defaults(func=cmd_reseed)

    # backup - VACUUM INTO a standalone timestamped DB copy
    pbk = sub.add_parser("backup", help="write a standalone DB backup (VACUUM INTO)")
    common(pbk)
    pbk.add_argument("--out", help="destination path (default: <db>.backup-<ts>.db)")
    pbk.set_defaults(func=cmd_backup)

    pa = sub.add_parser("authority", help="compute offline PageRank host authority")
    common(pa)
    pa.add_argument("--iterations", type=int, default=20)
    pa.add_argument("--damping", type=float, default=0.85)
    pa.set_defaults(func=cmd_authority)

    pcl = sub.add_parser("cluster", help="cluster near-duplicate mirror pages")
    common(pcl)
    pcl.add_argument("--threshold", type=int, default=3,
                     help="max SimHash Hamming distance for a mirror")
    pcl.set_defaults(func=cmd_cluster)

    prc = sub.add_parser("recrawl", help="mark due pages for recrawl now")
    common(prc)
    prc.set_defaults(func=cmd_recrawl)
    return p


def main(argv=None):
    args = build_parser().parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
