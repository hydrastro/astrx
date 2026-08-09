"""Command-line interface: ``crawl``, ``serve`` and ``stats``.

Examples
--------
    python3 -m websearch crawl --seeds seeds.example --db web.db --scope-domain example.com
    python3 -m websearch serve --db web.db --port 8803
    python3 -m websearch stats --db web.db
"""

import argparse
import logging
import os
import sqlite3
import sys
import time

from . import canonical, index
from .crawler import Crawler, CrawlConfig, MultiCrawler


def _setup_logging(verbose):
    if verbose:
        logging.basicConfig(
            level=logging.INFO,
            format="%(asctime)s %(name)s %(message)s")


def _read_seeds(path, extra):
    seeds = list(extra or [])
    if path:
        with open(path, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.split("#", 1)[0].strip()
                if line:
                    seeds.append(line)
    return seeds


def _parse_shards(value):
    """Comma-separated shard list -> tuple (ids for crawl, base URLs for
    fed-serve).  Empty/whitespace entries are dropped."""
    if not value:
        return ()
    return tuple(s.strip() for s in value.split(",") if s.strip())


def _build_config(args, scope):
    return CrawlConfig(
        scope_hosts=scope,
        respect_robots=not args.no_robots,
        timeout=args.timeout,
        max_bytes=args.max_bytes,
        max_depth=args.max_depth,
        per_host_budget=args.per_host_budget,
        total_budget=args.max_pages,
        base_delay=args.delay,
        jitter=args.jitter,
        user_agent=args.user_agent,
        block_internal_ips=not args.allow_internal_ips,
        allow_hosts=args.allow_host,
        index_pdf=args.index_pdf,
        keep_alive=args.keep_alive,
        workers=args.workers,
        recrawl_interval=args.recrawl_interval,
        shard_id=getattr(args, "shard_id", None),
        shards=_parse_shards(getattr(args, "shards", None)),
    )


def cmd_crawl(args):
    _setup_logging(args.verbose)
    seeds = _read_seeds(args.seeds, args.seed)
    if not seeds and not args.recrawl:
        print("error: no seeds given (use --seeds FILE or positional URLs)",
              file=sys.stderr)
        return 2

    if args.broad:
        scope = None
    elif args.scope_domain:
        scope = list(args.scope_domain)
    elif seeds:
        scope = sorted({canonical.host_of(canonical.canonicalize(s) or "")
                        for s in seeds} - {""})
    else:
        scope = None
    cfg = _build_config(args, scope)

    t0 = time.time()
    if args.workers > 1:
        driver = MultiCrawler(args.db, cfg, verbose=args.verbose)
        added = driver.add_seeds(seeds) if seeds else 0
        requeued = driver.enqueue_recrawls() if args.recrawl else 0
        print("seeded %d URL(s); recrawl-queued %d; scope=%s; workers=%d"
              % (added, requeued, scope if scope else "BROAD", args.workers))
        stats = driver.run()
    else:
        conn = index.connect(args.db)
        crawler = Crawler(conn, cfg, verbose=args.verbose)
        added = crawler.add_seeds(seeds) if seeds else 0
        requeued = crawler.enqueue_recrawls() if args.recrawl else 0
        print("seeded %d URL(s); recrawl-queued %d; scope=%s"
              % (added, requeued, scope if scope else "BROAD"))
        stats = crawler.run()
        conn.close()

    conn = index.connect(args.db)
    index.finalize(conn)
    dt = time.time() - t0
    print("crawl done in %.1fs: %s" % (dt, stats))
    st = index.stats(conn)
    print("indexed %d docs across %d host(s); frontier=%s"
          % (st["docs"], st["hosts"], st.get("frontier", {})))
    conn.close()
    return 0


def cmd_serve(args):
    from . import server
    _setup_logging(args.verbose)
    auth = None
    if args.auth:
        user, _, pw = args.auth.partition(":")
        auth = (user, pw)
    server.serve(args.db, host=args.host, port=args.port,
                 rate=args.rate, burst=args.burst, auth=auth,
                 verbose=args.verbose)
    return 0


def cmd_fed_serve(args):
    """Run the federation aggregator: one query -> all shard nodes -> merged."""
    from . import federation
    _setup_logging(args.verbose)
    auth = None
    if args.auth:
        user, _, pw = args.auth.partition(":")
        auth = (user, pw)
    shards = _parse_shards(args.shards)
    if not shards:
        print("error: no shard base URLs given (use --shards url1,url2,...)",
              file=sys.stderr)
        return 2
    federation.serve(shards, host=args.host, port=args.port,
                     timeout=args.timeout, auth=auth,
                     rate=args.rate, burst=args.burst)
    return 0


def cmd_stats(args):
    conn = index.connect(args.db, read_only=True)
    try:
        st = index.stats(conn)
    finally:
        conn.close()
    print("documents : %d" % st["docs"])
    print("hosts     : %d" % st["hosts"])
    print("link edges: %d" % st["links"])
    if st.get("newest"):
        print("fetched   : %s .. %s"
              % (time.strftime("%Y-%m-%d", time.gmtime(st["oldest"])),
                 time.strftime("%Y-%m-%d", time.gmtime(st["newest"]))))
    if st["top_hosts"]:
        print("top hosts :")
        for host, n in st["top_hosts"]:
            print("    %6d  %s" % (n, host))
    if st["languages"]:
        print("languages : "
              + ", ".join("%s=%d" % (l, n) for l, n in st["languages"]))
    if st.get("frontier"):
        print("frontier  : "
              + ", ".join("%s=%d" % kv for kv in sorted(st["frontier"].items())))
    return 0


def cmd_backup(args):
    """Safe on-line backup of the index DB (local path only, no network)."""
    if index._URI_SCHEME.match(args.out):
        print("error: destination %s looks like a URI/scheme; give a plain "
              "local filesystem path" % args.out, file=sys.stderr)
        return 2
    if os.path.exists(args.out):
        print("error: destination %s already exists (refusing to overwrite)"
              % args.out, file=sys.stderr)
        return 2
    try:
        n = index.backup(args.db, args.out)
    except (ValueError, OSError, sqlite3.Error) as exc:
        print("error: backup failed: %s" % exc, file=sys.stderr)
        return 1
    print("backup: wrote %s (%d documents)" % (args.out, n))
    return 0


def build_parser():
    p = argparse.ArgumentParser(
        prog="python3 -m websearch",
        description="Zero-dependency clearnet search engine "
                    "(crawler + FTS5 index + ranking + no-JS UI).")
    sub = p.add_subparsers(dest="cmd", required=True)

    c = sub.add_parser("crawl", help="crawl seeds into an index database")
    c.add_argument("seed", nargs="*", help="seed URL(s)")
    c.add_argument("--seeds", help="file of seed URLs (one per line)")
    c.add_argument("--db", default="web.db", help="SQLite database path")
    c.add_argument("--scope-domain", action="append",
                   help="restrict crawl to this domain (repeatable)")
    c.add_argument("--broad", action="store_true",
                   help="crawl broadly (ignore seed-host scoping)")
    c.add_argument("--max-depth", type=int, default=6)
    c.add_argument("--max-pages", type=int, default=2000,
                   help="total page budget")
    c.add_argument("--per-host-budget", type=int, default=500)
    c.add_argument("--max-bytes", type=int, default=2_000_000)
    c.add_argument("--delay", type=float, default=0.5,
                   help="base per-host politeness delay (seconds)")
    c.add_argument("--jitter", type=float, default=0.3)
    c.add_argument("--timeout", type=float, default=10.0)
    c.add_argument("--user-agent", default=CrawlConfig().user_agent)
    c.add_argument("--no-robots", action="store_true",
                   help="do not fetch/honour robots.txt (impolite)")
    c.add_argument("--allow-host", action="append", default=[],
                   help="exempt this host[:port] from the internal-IP SSRF "
                        "denylist (repeatable; for legit internal testing)")
    c.add_argument("--allow-internal-ips", action="store_true",
                   help="disable the internal-IP crawl denylist entirely "
                        "(DANGEROUS: enables SSRF to localhost/metadata)")
    c.add_argument("--workers", type=int, default=1,
                   help="parallel crawl workers (shared frontier + budget)")
    c.add_argument("--keep-alive", action="store_true",
                   help="reuse HTTP connections (still SSRF-checked per hop)")
    c.add_argument("--index-pdf", action="store_true",
                   help="index application/pdf via best-effort text extraction")
    c.add_argument("--recrawl", action="store_true",
                   help="also re-queue indexed URLs due for a recrawl")
    c.add_argument("--recrawl-interval", type=float, default=7 * 86400.0,
                   help="recrawl age threshold in seconds (default 7 days)")
    c.add_argument("--shard-id",
                   help="this node's shard id (fleet mode; enables HRW host "
                        "ownership so only this shard's hosts are crawled here)")
    c.add_argument("--shards",
                   help="comma-separated set of ALL shard ids (must match "
                        "across the fleet); empty = single-node (owns everything)")
    c.add_argument("--verbose", action="store_true")
    c.set_defaults(func=cmd_crawl)

    s = sub.add_parser("serve", help="serve the no-JS search UI + JSON API")
    s.add_argument("--db", default="web.db")
    s.add_argument("--host", default="127.0.0.1")
    s.add_argument("--port", type=int, default=8803)
    s.add_argument("--rate", type=float, default=None,
                   help="per-IP rate limit (requests/second); off if unset")
    s.add_argument("--burst", type=float, default=None,
                   help="rate-limit burst/bucket size (default = --rate)")
    s.add_argument("--auth", default=None,
                   help="require HTTP Basic auth as USER:PASS on search/API")
    s.add_argument("--verbose", action="store_true",
                   help="structured per-request logging")
    s.set_defaults(func=cmd_serve)

    f = sub.add_parser(
        "fed-serve",
        help="aggregator: federate search across sharded shard nodes")
    f.add_argument("--shards", required=True,
                   help="comma-separated shard base URLs "
                        "(e.g. http://10.0.0.1:8803,http://10.0.0.2:8803)")
    f.add_argument("--host", default="127.0.0.1")
    f.add_argument("--port", type=int, default=8809)
    f.add_argument("--timeout", type=float, default=4.0,
                   help="per-shard query deadline in seconds (default 4)")
    f.add_argument("--rate", type=float, default=None,
                   help="per-IP rate limit (requests/second); off if unset")
    f.add_argument("--burst", type=float, default=None,
                   help="rate-limit burst/bucket size (default = --rate)")
    f.add_argument("--auth", default=None,
                   help="require HTTP Basic auth as USER:PASS")
    f.add_argument("--verbose", action="store_true")
    f.set_defaults(func=cmd_fed_serve)

    t = sub.add_parser("stats", help="print index statistics")
    t.add_argument("--db", default="web.db")
    t.set_defaults(func=cmd_stats)

    bk = sub.add_parser("backup",
                        help="safe on-line backup of the index DB (VACUUM INTO)")
    bk.add_argument("--db", default="web.db", help="source index database")
    bk.add_argument("--out", required=True,
                    help="destination path (local file; must not already exist)")
    bk.set_defaults(func=cmd_backup)
    return p


def main(argv=None):
    args = build_parser().parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
