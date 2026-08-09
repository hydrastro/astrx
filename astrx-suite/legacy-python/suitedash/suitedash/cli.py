"""Command-line entry point for the ``suitedash`` console script.

Runs with no arguments (defaults poll the four astrx-suite services on their
standard loopback ports).  ``--config`` loads a TOML file; scalar flags override
individual settings; ``--service name=url`` retargets or adds a service inline;
``--check`` polls once, prints the ``/api/status`` JSON to stdout and exits
(handy for cron / smoke tests without opening a socket).
"""

from __future__ import annotations

import argparse
import sys

from . import __version__
from .config import Config, apply_service_flags, load_config
from .monitor import Monitor
from .poller import poll_all, summarize
from .render import render_status_json
from .server import serve


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="suitedash",
        description="Zero-dependency, no-JavaScript ops/status dashboard for the astrx-suite.",
    )
    p.add_argument("--config", help="Path to a TOML config file (overrides defaults).")
    p.add_argument("--host", help="Address to bind (default: 127.0.0.1, loopback only).")
    p.add_argument("--port", type=int, help="TCP port for the dashboard (default: 8805).")
    p.add_argument(
        "--refresh",
        type=int,
        help="Auto-refresh interval in seconds; <=0 disables it (default: 15).",
    )
    p.add_argument(
        "--timeout",
        type=float,
        help="Per-service probe timeout in seconds (default: 3.0).",
    )
    p.add_argument(
        "--max-workers",
        type=int,
        help="Max concurrent inbound connections (default: 16).",
    )
    p.add_argument(
        "--cache-ttl",
        type=float,
        help="Seconds to cache a poll snapshot (0 = always fresh, default).",
    )
    p.add_argument(
        "--service",
        action="append",
        default=[],
        metavar="NAME=URL",
        help="Retarget or add a service, e.g. gitweb=http://127.0.0.1:8801. Repeatable.",
    )
    p.add_argument(
        "--check",
        action="store_true",
        help="Poll every service once, print /api/status JSON, and exit.",
    )
    p.add_argument("-q", "--quiet", action="store_true", help="Suppress request logging.")
    p.add_argument("--version", action="version", version="suitedash %s" % __version__)
    return p


def config_from_args(args) -> Config:
    cfg = load_config(args.config, base=Config())
    if args.host is not None:
        cfg.host = args.host
    if args.port is not None:
        cfg.port = args.port
    if args.refresh is not None:
        cfg.refresh_seconds = args.refresh
    if args.timeout is not None:
        cfg.timeout_seconds = max(0.1, args.timeout)
    if args.max_workers is not None:
        cfg.max_workers = max(1, args.max_workers)
    if args.cache_ttl is not None:
        cfg.cache_ttl = max(0.0, args.cache_ttl)
    if args.service:
        apply_service_flags(cfg, args.service)
    cfg.verbose = not args.quiet
    return cfg


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    cfg = config_from_args(args)

    if args.check:
        results = poll_all(cfg.services, cfg.timeout_seconds)
        # One sweep through the monitor so --check JSON carries alert state too
        # (rules with for_polls=1, and any down-detection, evaluate immediately).
        monitor = Monitor(cfg)
        monitor.ingest(results)
        print(render_status_json(results, monitor.snapshot()).decode("utf-8"))
        # Non-zero exit if anything is down, so it composes in shell pipelines.
        return 0 if summarize(results)["all_up"] else 1

    serve(cfg)
    return 0


if __name__ == "__main__":
    sys.exit(main())
