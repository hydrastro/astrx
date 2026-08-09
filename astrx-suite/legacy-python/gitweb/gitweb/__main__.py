"""Command-line entry point: ``python3 -m gitweb --root /path/to/repos``."""

from __future__ import annotations

import argparse
import sys

from . import __version__
from .server import Config, serve


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="gitweb",
        description="Read-only, no-JavaScript web git browser (stdlib only).",
    )
    parser.add_argument(
        "--root",
        required=False,
        help="Directory that directly contains the git repositories to serve.",
    )
    parser.add_argument(
        "--host",
        default="127.0.0.1",
        help="Address to bind (default: 127.0.0.1, loopback only).",
    )
    parser.add_argument(
        "--port", type=int, default=8801, help="TCP port (default: 8801)."
    )
    parser.add_argument(
        "--page-size",
        type=int,
        default=50,
        help="Commits per log page (default: 50).",
    )
    parser.add_argument(
        "--max-blob-mb",
        type=float,
        default=2.0,
        help="Max size (MiB) rendered inline in the blob view (default: 2).",
    )
    parser.add_argument(
        "--raw-max-mb",
        type=float,
        default=50.0,
        help="Max size (MiB) streamed by the /raw endpoint (default: 50).",
    )
    parser.add_argument(
        "--archive-max-mb",
        type=float,
        default=200.0,
        help="Max size (MiB) streamed by the /archive endpoint (default: 200).",
    )
    parser.add_argument(
        "--tree-page-size",
        type=int,
        default=500,
        help="Tree entries shown per page (default: 500).",
    )
    parser.add_argument(
        "--max-workers",
        type=int,
        default=32,
        help="Max concurrent connections handled at once (default: 32).",
    )
    parser.add_argument(
        "--socket-timeout",
        type=float,
        default=30.0,
        help="Per-connection socket read timeout in seconds (default: 30).",
    )
    parser.add_argument(
        "--url-prefix",
        default="",
        help="Mount under a reverse-proxy sub-path, e.g. /git (default: none).",
    )
    parser.add_argument(
        "--patches-dir",
        default="",
        help="Directory of read-only per-repo patch archives (<name>.mbox), fed "
        "by a mailing list / git send-email; renders a Sourcehut-style Patches "
        "page. Default: none (Patches page shows an empty state).",
    )
    parser.add_argument(
        "--highlight",
        action="store_true",
        help="Enable optional Pygments syntax highlighting (falls back to "
        "escaped plaintext if Pygments is not installed).",
    )
    parser.add_argument(
        "--enable-clone",
        action=argparse.BooleanOptionalAction,
        default=True,
        help="Serve read-only 'git clone'/'git fetch' over HTTP (Git Smart "
        "HTTP, upload-pack only; push is never served). Use --no-enable-clone "
        "to disable, making the clone endpoints 404 (default: enabled).",
    )
    parser.add_argument(
        "--clone-timeout",
        type=float,
        default=120.0,
        help="Overall wall-clock timeout (s) for one upload-pack call; a "
        "clone/fetch exceeding it is killed (default: 120).",
    )
    parser.add_argument(
        "--clone-max-body-mb",
        type=float,
        default=25.0,
        help="Max size (MiB) of a clone/fetch POST request body, after gzip "
        "inflation (default: 25).",
    )
    parser.add_argument(
        "--clone-max-concurrency",
        type=int,
        default=4,
        help="Max concurrent upload-pack RPCs; keep below --max-workers so "
        "clones cannot starve browsing (default: 4).",
    )
    parser.add_argument(
        "--clone-base-url",
        default="",
        help="External origin (scheme://host[:port]) shown in the 'git clone' "
        "command on the repo summary, e.g. an onion address. Defaults to the "
        "request Host header.",
    )
    parser.add_argument(
        "--auth",
        default="",
        help="Enable HTTP Basic access control for the WHOLE server "
        "(browse + clone). Value is 'user:sha256$salt$hex' (a hashed password, "
        "never plaintext). Generate one with --hash-password. Default: off.",
    )
    parser.add_argument(
        "--auth-file",
        default="",
        help="Read the 'user:sha256$salt$hex' auth spec from the first "
        "non-comment line of this file (keeps it out of the process table).",
    )
    parser.add_argument(
        "--hash-password",
        metavar="USER",
        default=None,
        help="Prompt for a password and print a 'USER:sha256$salt$hex' line to "
        "use with --auth, then exit (does not start the server).",
    )
    parser.add_argument(
        "-q", "--quiet", action="store_true", help="Suppress request logging."
    )
    parser.add_argument(
        "--version", action="version", version=f"gitweb {__version__}"
    )
    return parser


def main(argv=None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)

    # Credential-generation helper: prompt, print 'user:sha256$salt$hex', exit.
    if args.hash_password is not None:
        import getpass

        from .auth import hash_password

        pw = getpass.getpass("Password: ")
        if pw != getpass.getpass("Repeat password: "):
            print("passwords do not match", file=sys.stderr)
            return 2
        if not pw:
            print("password must not be empty", file=sys.stderr)
            return 2
        print(f"{args.hash_password}:{hash_password(pw)}")
        return 0

    if not args.root:
        parser.error("--root is required (unless --hash-password is used)")

    config = Config(
        root=args.root,
        host=args.host,
        port=args.port,
        page_size=max(1, args.page_size),
        max_blob_bytes=int(args.max_blob_mb * 1024 * 1024),
        raw_max_bytes=int(args.raw_max_mb * 1024 * 1024),
        archive_max_bytes=int(args.archive_max_mb * 1024 * 1024),
        tree_page_size=max(1, args.tree_page_size),
        max_workers=max(1, args.max_workers),
        socket_timeout=max(1.0, args.socket_timeout),
        url_prefix=args.url_prefix,
        patches_dir=args.patches_dir,
        syntax_highlight=args.highlight,
        verbose=not args.quiet,
        enable_clone=args.enable_clone,
        clone_timeout=max(1.0, args.clone_timeout),
        clone_max_body_bytes=int(args.clone_max_body_mb * 1024 * 1024),
        clone_max_concurrency=max(1, args.clone_max_concurrency),
        clone_base_url=args.clone_base_url,
        auth=args.auth,
        auth_file=args.auth_file,
    )
    serve(config)
    return 0


if __name__ == "__main__":
    sys.exit(main())
