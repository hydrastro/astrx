"""HTTP layer: a tiny router over :class:`http.server.ThreadingHTTPServer`.

The handler is intentionally thin.  It parses and validates the request,
delegates all git work to :mod:`gitweb.gitcmd`, and all HTML to
:mod:`gitweb.views`.  It never interpolates untrusted values into a shell and
never emits an unescaped value into HTML.
"""

from __future__ import annotations

import gzip
import hashlib
import math
import os
import re
import threading
import time
import zlib
from dataclasses import dataclass
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Optional
from urllib.parse import parse_qs, quote, unquote, urlsplit

from . import auth, gitcmd, metrics, views
from .gitcmd import BadRequest, GitError, NotFound
from .markup import parse_patch, render_markdown, render_readme

_ACTIONS = frozenset(
    {
        "refs",
        "releases",
        "releases.atom",
        "patches",
        "patches.mbox",
        "log",
        "commit",
        "tree",
        "blob",
        "raw",
        "blame",
        "history",
        "atom",
        "archive",
        "compare",
        "search",
        "graph",
        "patch",
        "commit.patch",
        "opensearch.xml",
    }
)
_FILENAME_RE = re.compile(r"[^A-Za-z0-9._-]+")
_FULL_SHA_RE = re.compile(r"^[0-9a-f]{40}$")

#: Bump when the HTML rendering changes so cached ETags invalidate.  Folded
#: into every strong ETag alongside the object sha and path.
RENDER_VERSION = "gitweb-r2"

#: Reserved top-level paths that are handled before repository resolution.
_RESERVED = frozenset({"health", "metrics", "opensearch.xml"})

#: Blob extensions rendered inline as images (see :meth:`handle_blob`).  SVG is
#: served with our strict CSP + nosniff, which blocks any embedded scripting.
_IMAGE_TYPES = {
    "png": "image/png",
    "jpg": "image/jpeg",
    "jpeg": "image/jpeg",
    "gif": "image/gif",
    "webp": "image/webp",
    "bmp": "image/bmp",
    "ico": "image/x-icon",
    "svg": "image/svg+xml",
}


def _ext(path: str) -> str:
    """Lower-cased file extension of ``path`` without the dot (or "")."""
    base = path.rsplit("/", 1)[-1]
    dot = base.rfind(".")
    return base[dot + 1 :].lower() if dot > 0 else ""


# --------------------------------------------------------------------------- #
# Git Smart HTTP helpers
# --------------------------------------------------------------------------- #

#: Flush packet (pkt-line "0000").
_PKT_FLUSH = b"0000"


def _pkt_line(data: bytes) -> bytes:
    """Encode ``data`` as a single git pkt-line (4-hex length prefix + data)."""
    return b"%04x" % (len(data) + 4) + data


def _inflate_capped(data: bytes, max_bytes: int) -> bytes:
    """Inflate a gzip/zlib request body, refusing to exceed ``max_bytes``.

    Git may send an ``upload-pack`` request ``Content-Encoding: gzip``.  We
    decompress with an explicit output cap so a small hostile body cannot
    inflate into an unbounded allocation (zip bomb); the raw input is already
    bounded by the caller's body cap.
    """
    dec = zlib.decompressobj(47)  # 47 = auto-detect gzip/zlib header
    out = bytearray()
    buf = data
    try:
        while True:
            piece = dec.decompress(buf, max(1, max_bytes + 1 - len(out)))
            out += piece
            if len(out) > max_bytes:
                raise BadRequest("request body too large")
            buf = dec.unconsumed_tail
            if not buf:
                break
        out += dec.flush()
    except zlib.error as exc:
        raise BadRequest(f"malformed compressed request body: {exc}")
    if len(out) > max_bytes:
        raise BadRequest("request body too large")
    return bytes(out)


def _parse_line_range(spec: str):
    """Parse a ``highlight`` value (``"5"`` or ``"5-10"``) into a set of ints.

    Bounded to a sane span so a hostile ``1-99999999`` cannot allocate a huge
    set; anything unparseable yields an empty set.
    """
    spec = (spec or "").strip().lstrip("L")
    if not spec:
        return set()
    if "-" in spec:
        a, _, b = spec.partition("-")
        b = b.lstrip("L")
        if a.isdigit() and b.isdigit():
            lo, hi = int(a), int(b)
            if lo > hi:
                lo, hi = hi, lo
            if hi - lo > 5000:
                hi = lo + 5000
            return set(range(lo, hi + 1))
        return set()
    return {int(spec)} if spec.isdigit() else set()


@dataclass
class Config:
    """Runtime configuration for a gitweb server."""

    root: str
    host: str = "127.0.0.1"
    port: int = 8801
    page_size: int = 50
    max_blob_bytes: int = 2 * 1024 * 1024  # inline render cap
    raw_max_bytes: int = 50 * 1024 * 1024  # /raw streaming cap
    archive_max_bytes: int = 200 * 1024 * 1024  # /archive streaming cap
    readme_bytes: int = 512 * 1024
    patches_dir: str = ""  # dir of per-repo <name>.mbox patch archives (read-only)
    summary_commits: int = 10
    tree_page_size: int = 500  # entries per tree page (huge-dir guard)
    feed_commits: int = 20  # entries in an Atom feed
    max_workers: int = 32  # bounded worker pool (Slowloris/thread guard)
    socket_timeout: float = 30.0  # per-connection socket read timeout (s)
    url_prefix: str = ""  # reverse-proxy sub-path mount, e.g. "/git"
    syntax_highlight: bool = False  # opt-in Pygments; default is the fallback
    verbose: bool = True

    # -- Git Smart HTTP (read-only clone/fetch) ------------------------------ #
    enable_clone: bool = True  # serve git-upload-pack over HTTP (off => 404)
    clone_timeout: float = 120.0  # overall wall-clock per upload-pack call (s)
    clone_max_body_bytes: int = 25 * 1024 * 1024  # POST body cap (inflated)
    clone_max_concurrency: int = 4  # concurrent upload-pack RPCs (< max_workers)
    clone_base_url: str = ""  # external origin for the shown clone URL

    # -- Optional HTTP Basic access control (default OFF) -------------------- #
    #: ``user:sha256$salt$hex`` credential; when set (directly or via
    #: ``auth_file``), every request needs matching HTTP Basic auth.
    auth: str = ""
    auth_file: str = ""  # path to a file whose first line is the auth spec


class GitwebHandler(BaseHTTPRequestHandler):
    """Handle GET requests for the git browser."""

    protocol_version = "HTTP/1.1"
    server_version = "gitweb/1.0"

    #: Set per-connection in :meth:`setup` from ``config.socket_timeout`` so a
    #: slow client (Slowloris) is disconnected instead of pinning a worker.
    timeout = 30.0

    # ------------------------------------------------------------------ #
    # Framework glue
    # ------------------------------------------------------------------ #

    @property
    def config(self) -> Config:
        return self.server.config  # type: ignore[attr-defined]

    def setup(self):
        # Apply the configured socket read timeout before the base class wires
        # up the connection streams (StreamRequestHandler.setup reads it).
        try:
            self.timeout = self.config.socket_timeout
        except Exception:  # pragma: no cover - defensive
            pass
        super().setup()

    def log_message(self, fmt, *args):  # noqa: D401 - quiet unless verbose
        if self.config.verbose:
            super().log_message(fmt, *args)

    def do_GET(self):
        self._dispatch()

    def do_HEAD(self):
        # Same routing as GET; body writes are suppressed by the response
        # helpers when ``self.command == "HEAD"``, so status/headers match GET.
        self._dispatch()

    def do_POST(self):
        # POST is only ever the Git Smart HTTP RPC (git-upload-pack); routing
        # rejects everything else.  Shares the dispatch wrapper for metrics and
        # uniform error handling.
        self._dispatch()

    def _dispatch(self):
        self._status = 0
        self._action = ""
        start = time.monotonic()
        metrics.REGISTRY.begin()
        try:
            # Access control (default OFF) gates *every* endpoint — browse,
            # clone and operational paths alike — before any routing/git work.
            if not self._authorized():
                self._send_unauthorized()
            else:
                self.route()
        except BadRequest as exc:
            self.send_html(400, views.error_page(400, str(exc)))
        except NotFound as exc:
            self.send_html(404, views.error_page(404, str(exc)))
        except GitError as exc:
            self.send_html(500, views.error_page(500, f"git error: {exc}"))
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception:  # pragma: no cover - defensive catch-all
            if self.config.verbose:
                import traceback

                traceback.print_exc()
            try:
                self.send_html(500, views.error_page(500, "internal server error"))
            except Exception:
                pass
        finally:
            elapsed = time.monotonic() - start
            status = getattr(self, "_status", 0) or 0
            metrics.REGISTRY.end(status, getattr(self, "_action", ""), elapsed)
            if self.config.verbose:
                self._log_structured(status, elapsed)

    def _log_structured(self, status: int, elapsed: float):
        """One structured, timed access line per request."""
        try:
            client = self.client_address[0]
        except Exception:  # pragma: no cover - defensive
            client = "-"
        self.log_message(
            'method=%s path="%s" status=%d action=%s dur_ms=%.1f client=%s',
            self.command,
            self.path,
            status,
            getattr(self, "_action", "") or "-",
            elapsed * 1000.0,
            client,
        )

    # ------------------------------------------------------------------ #
    # Access control (optional HTTP Basic auth)
    # ------------------------------------------------------------------ #

    def _authorized(self) -> bool:
        """True when access control is off, or the request carries valid creds."""
        cred = getattr(self.server, "auth_cred", None)
        if cred is None:
            return True
        return auth.check_basic_auth(self.headers.get("Authorization"), cred)

    def _send_unauthorized(self):
        """401 with a Basic challenge (covers browse *and* git clients)."""
        self.close_connection = True
        body = b"401 Unauthorized\n"
        self._status = 401
        self.send_response(401)
        self.send_header("WWW-Authenticate", 'Basic realm="gitweb", charset="UTF-8"')
        self._security_headers("text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    # ------------------------------------------------------------------ #
    # Response helpers
    # ------------------------------------------------------------------ #

    def _security_headers(self, content_type: str):
        self.send_header("Content-Type", content_type)
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header(
            "Content-Security-Policy",
            "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; "
            # 'self' (not 'none') so the no-JS search/filter GET forms can submit
            # to this origin; still forbids submitting to any external target.
            "base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        )

    # -- conditional GET + content negotiation ------------------------- #

    def make_etag(self, *parts: str) -> str:
        """Strong ETag from ``parts`` folded with :data:`RENDER_VERSION`.

        The digest of ``sha + path + RENDER_VERSION`` is stable while the
        underlying object and the renderer are unchanged, so a Tor client can
        revalidate a sha-immutable view instead of re-downloading it.
        """
        h = hashlib.sha256()
        h.update(RENDER_VERSION.encode("utf-8"))
        for part in parts:
            h.update(b"\x00")
            h.update(part.encode("utf-8", "replace"))
        return h.hexdigest()[:32]

    def _negotiate_encoding(self) -> str:
        """Pick a supported content coding from ``Accept-Encoding`` (or "")."""
        accept = (self.headers.get("Accept-Encoding") or "").lower()
        # A token followed by ``;q=0`` is an explicit refusal.
        tokens = {}
        for part in accept.split(","):
            name, _, params = part.strip().partition(";")
            q = 1.0
            if "q=" in params:
                try:
                    q = float(params.split("q=", 1)[1])
                except ValueError:
                    q = 1.0
            if name:
                tokens[name] = q
        if tokens.get("gzip", 0) > 0:
            return "gzip"
        if tokens.get("deflate", 0) > 0:
            return "deflate"
        return ""

    def _final_etag(self, base: str, encoding: str) -> str:
        """Quote and (per RFC) distinguish the compressed entity's ETag."""
        suffix = f"-{encoding}" if encoding else ""
        return f'"{base}{suffix}"'

    def _if_none_match_hit(self, etag: str) -> bool:
        header = self.headers.get("If-None-Match")
        if not header:
            return False
        if header.strip() == "*":
            return True
        candidates = {c.strip() for c in header.split(",")}
        # Tolerate a weak-validator prefix on the client's stored value.
        return etag in candidates or f"W/{etag}" in candidates

    def conditional_get(
        self, base_etag: Optional[str], *, encoding_independent: bool = False
    ) -> bool:
        """Send ``304`` and return ``True`` when the client's copy is current.

        Handlers call this *before* doing expensive work; :meth:`send_html`
        performs the same check as a fallback for handlers that pass an ETag.

        ``encoding_independent`` is for representations that are *never* content-
        coded (the ``/raw`` byte stream): the ETag then carries no encoding
        suffix, so the check must not append the negotiated coding either —
        otherwise a browser advertising ``gzip`` could never match the suffix-
        less ETag the endpoint actually issues and would never get a ``304``.
        """
        if not base_etag:
            return False
        encoding = "" if encoding_independent else self._negotiate_encoding()
        etag = self._final_etag(base_etag, encoding)
        if self._if_none_match_hit(etag):
            self._status = 304
            self.send_response(304)
            self.send_header("ETag", etag)
            if not encoding_independent:
                self.send_header("Vary", "Accept-Encoding")
            self.send_header("Cache-Control", "max-age=0, must-revalidate")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return True
        return False

    def send_html(self, code: int, html: str, *, etag: Optional[str] = None):
        body = html.encode("utf-8", "replace")
        encoding = self._negotiate_encoding()
        final_etag = self._final_etag(etag, encoding) if etag else None
        if final_etag and code == 200 and self._if_none_match_hit(final_etag):
            return self.conditional_get(etag)
        if encoding == "gzip":
            body = gzip.compress(body, compresslevel=6, mtime=0)
        elif encoding == "deflate":
            body = zlib.compress(body, 6)
        else:
            encoding = ""
        self._status = code
        self.send_response(code)
        self._security_headers("text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Vary", "Accept-Encoding")
        if encoding:
            self.send_header("Content-Encoding", encoding)
        if final_etag:
            self.send_header("ETag", final_etag)
            self.send_header("Cache-Control", "max-age=0, must-revalidate")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def send_bytes(
        self,
        code: int,
        body: bytes,
        content_type: str,
        *,
        disposition: Optional[str] = None,
        etag: Optional[str] = None,
    ):
        """Send an already-encoded byte body with the standard safety headers.

        ``etag`` (an already-quoted validator) is emitted for immutable
        representations so a client can revalidate; the caller is expected to
        have already answered a matching ``If-None-Match`` with a 304.
        """
        self._status = code
        self.send_response(code)
        self._security_headers(content_type)
        self.send_header("Content-Length", str(len(body)))
        if disposition:
            self.send_header("Content-Disposition", disposition)
        if etag:
            self.send_header("ETag", etag)
            self.send_header("Cache-Control", "max-age=0, must-revalidate")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def send_redirect(self, location: str):
        self._status = 302
        self.send_response(302)
        self.send_header("Location", location)
        self.send_header("Content-Length", "0")
        self.end_headers()

    # ------------------------------------------------------------------ #
    # Routing
    # ------------------------------------------------------------------ #

    def route(self):
        split = urlsplit(self.path)
        prefix = self.config.url_prefix
        # Make every generated URL carry the reverse-proxy sub-path for this
        # request (thread-local, so concurrent requests never cross-talk).
        views.push_url_prefix(prefix)

        raw_path = split.path
        if prefix:
            if raw_path in (prefix, prefix + "/"):
                raw_path = "/"
            elif raw_path.startswith(prefix + "/"):
                raw_path = raw_path[len(prefix) :]
            else:
                raise NotFound("unknown path")

        # Split the *raw* path, then unquote each segment.  This prevents an
        # encoded "%2f" from being treated as a new path separator.
        segments = [unquote(s) for s in raw_path.split("/") if s != ""]
        query = parse_qs(split.query, keep_blank_values=True)

        # POST is exclusively the Git Smart HTTP RPC; route it separately.
        if self.command == "POST":
            return self._route_post(segments)

        # Reserved operational endpoints, resolved before repositories.
        if len(segments) == 1 and segments[0] in _RESERVED:
            if segments[0] == "health":
                self._action = "health"
                return self.handle_health()
            if segments[0] == "opensearch.xml":
                self._action = "opensearch-site"
                return self.handle_opensearch_site()
            self._action = "metrics"
            return self.handle_metrics()

        if not segments:
            self._action = "home"
            return self.handle_repo_list(query)

        repo_name = segments[0]
        repo = gitcmd.resolve_repo(self.config.root, repo_name)

        # Git Smart HTTP: GET /<repo>/info/refs?service=git-upload-pack .  Must
        # be matched before the browse-action dispatch (which caps at 2 path
        # segments and would otherwise 404 the 3-segment info/refs path).
        if len(segments) == 3 and segments[1] == "info" and segments[2] == "refs":
            self._action = "info-refs"
            return self.handle_info_refs(repo, query)

        if len(segments) == 1:
            self._action = "summary"
            return self.handle_summary(repo)

        action = segments[1]
        if action not in _ACTIONS or len(segments) > 2:
            raise NotFound("unknown path")
        self._action = action

        if action == "refs":
            return self.handle_refs(repo)
        if action == "releases":
            return self.handle_releases(repo)
        if action == "releases.atom":
            return self.handle_releases_atom(repo)
        if action == "patches":
            return self.handle_patches(repo, query)
        if action == "patches.mbox":
            return self.handle_patches_mbox(repo, query)
        if action == "log":
            return self.handle_log(repo, query)
        if action == "commit":
            return self.handle_commit(repo, query)
        if action == "tree":
            return self.handle_tree(repo, query)
        if action == "blob":
            return self.handle_blob(repo, query)
        if action == "raw":
            return self.handle_raw(repo, query)
        if action == "blame":
            return self.handle_blame(repo, query)
        if action == "history":
            return self.handle_history(repo, query)
        if action == "atom":
            return self.handle_atom(repo, query)
        if action == "archive":
            return self.handle_archive(repo, query)
        if action == "compare":
            return self.handle_compare(repo, query)
        if action == "search":
            return self.handle_search(repo, query)
        if action == "graph":
            return self.handle_graph(repo, query)
        if action in ("patch", "commit.patch"):
            return self.handle_patch(repo, query)
        if action == "opensearch.xml":
            return self.handle_opensearch_repo(repo)
        raise NotFound("unknown path")  # pragma: no cover

    def _route_post(self, segments):
        """Route a POST — only the Git Smart HTTP RPC endpoints are valid.

        Close the connection after any POST: on an error/refusal we do not drain
        the (possibly large) request body, so keep-alive would desync the next
        request on the socket.  A clone uses a fresh connection anyway.
        """
        self.close_connection = True

        # When clone serving is disabled every RPC endpoint simply 404s, exactly
        # as if it never existed; browsing is unaffected.
        if not self.config.enable_clone:
            raise NotFound("not found")

        if len(segments) != 2:
            raise NotFound("not found")
        repo_name, endpoint = segments

        # Push is categorically refused, before any repo work — this server is
        # read-only and receive-pack is never run.
        if endpoint == "git-receive-pack":
            self._action = "receive-pack"
            return self._send_git_forbidden()
        if endpoint != "git-upload-pack":
            raise NotFound("not found")

        repo = gitcmd.resolve_repo(self.config.root, repo_name)
        self._action = "upload-pack"
        return self.handle_upload_pack(repo)

    # ------------------------------------------------------------------ #
    # Git Smart HTTP (read-only clone / fetch)
    # ------------------------------------------------------------------ #

    def _wants_protocol_v2(self) -> bool:
        """True if the client negotiated wire protocol v2 (``Git-Protocol``)."""
        header = self.headers.get("Git-Protocol", "") or ""
        return any(tok.strip() == "version=2" for tok in header.split(":"))

    def _git_headers(self, content_type: str):
        """Common headers for a Smart-HTTP response (never content-coded)."""
        self.send_header("Content-Type", content_type)
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Cache-Control", "no-cache, max-age=0, must-revalidate")
        self.send_header("Pragma", "no-cache")

    def _send_git_forbidden(self):
        self.close_connection = True
        body = b"403 Forbidden: this git server is read-only (push disabled).\n"
        self._status = 403
        self.send_response(403)
        self._git_headers("text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def _send_git_busy(self):
        self.close_connection = True
        body = b"503 Service Unavailable: too many concurrent clones.\n"
        self._status = 503
        self.send_response(503)
        self._git_headers("text/plain; charset=utf-8")
        self.send_header("Retry-After", "5")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def handle_info_refs(self, repo, query):
        if not self.config.enable_clone:
            raise NotFound("not found")
        service = self._q(query, "service")
        if service == "git-receive-pack":
            # Advertise for push -> refused (read-only server).
            return self._send_git_forbidden()
        if service != "git-upload-pack":
            # No service (dumb protocol) or an unknown one: unsupported.
            raise NotFound("not found")

        protocol_v2 = self._wants_protocol_v2()
        adv = gitcmd.upload_pack_advertise(
            repo, protocol_v2=protocol_v2, timeout=int(self.config.clone_timeout)
        )
        # The "# service=" banner precedes the advertisement in protocol v0/v1
        # only; protocol v2 sends the capability advertisement with no banner.
        if protocol_v2:
            body = adv
        else:
            body = _pkt_line(b"# service=git-upload-pack\n") + _PKT_FLUSH + adv

        self._status = 200
        self.send_response(200)
        self._git_headers("application/x-git-upload-pack-advertisement")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def handle_upload_pack(self, repo):
        if not self.config.enable_clone:  # pragma: no cover - guarded upstream
            raise NotFound("not found")
        protocol_v2 = self._wants_protocol_v2()

        # Acquire the clone-concurrency slot *before* reading the (potentially
        # clone_max_body_bytes, ~25 MiB) request body into RAM.  Otherwise peak
        # buffered body would scale with the whole worker pool
        # (max_workers x cap, ~800 MiB) instead of the much smaller clone budget
        # (clone_max_concurrency x cap, ~100 MiB).  A slow uploader now holds a
        # slot, but the per-connection socket timeout bounds how long it can.
        slots = getattr(self.server, "clone_slots", None)
        acquired = True
        if slots is not None:
            acquired = slots.acquire(blocking=False)
            if not acquired:
                return self._send_git_busy()
        try:
            # Read + (if needed) inflate the request body under the body cap.
            # May raise BadRequest -> a 400 is sent before any 200 headers (the
            # slot is released by the finally below).
            payload = self._read_git_request_body()

            self._status = 200
            self.send_response(200)
            self._git_headers("application/x-git-upload-pack-result")
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command == "HEAD":  # pragma: no cover - defensive
                return
            stream = gitcmd.upload_pack_rpc(
                repo,
                payload,
                protocol_v2=protocol_v2,
                timeout=int(self.config.clone_timeout),
            )
            try:
                for chunk in stream:
                    if chunk:
                        self.wfile.write(chunk)
            finally:
                stream.close()  # deterministic child teardown
        finally:
            if slots is not None and acquired:
                slots.release()

    def _read_git_request_body(self) -> bytes:
        """Read the POST body under the clone body cap, inflating gzip bodies.

        Rejects an over-large body with ``BadRequest`` (400) *before* any
        response is sent.  Supports Content-Length and chunked framing, and a
        ``Content-Encoding: gzip`` (or deflate) entity, each hard-capped.
        """
        cap = int(self.config.clone_max_body_bytes)
        te = (self.headers.get("Transfer-Encoding") or "").lower()
        if "chunked" in te:
            raw = self._read_chunked_body(cap)
        else:
            length_hdr = self.headers.get("Content-Length")
            if length_hdr is None:
                raw = b""
            else:
                try:
                    n = int(length_hdr)
                except ValueError:
                    raise BadRequest("bad Content-Length")
                if n < 0:
                    raise BadRequest("bad Content-Length")
                if n > cap:
                    raise BadRequest("request body too large")
                raw = self._read_exact_body(n)
        ce = (self.headers.get("Content-Encoding") or "").lower()
        if "gzip" in ce or "deflate" in ce:
            raw = _inflate_capped(raw, cap)
        return raw

    def _read_exact_body(self, n: int) -> bytes:
        buf = bytearray()
        while len(buf) < n:
            chunk = self.rfile.read(min(65536, n - len(buf)))
            if not chunk:
                break
            buf += chunk
        return bytes(buf)

    def _read_chunked_body(self, cap: int) -> bytes:
        """Read an HTTP/1.1 chunked request body, capped at ``cap`` bytes."""
        out = bytearray()
        while True:
            size_line = self.rfile.readline(64)
            if not size_line:
                break
            size_field = size_line.split(b";", 1)[0].strip()
            try:
                size = int(size_field, 16)
            except ValueError:
                raise BadRequest("bad chunk size")
            if size == 0:
                # Consume any trailer lines up to the terminating blank line.
                while True:
                    line = self.rfile.readline(1024)
                    if line in (b"\r\n", b"\n", b""):
                        break
                break
            if len(out) + size > cap:
                raise BadRequest("request body too large")
            chunk = self._read_exact_body(size)
            if len(chunk) < size:
                break
            out += chunk
            self.rfile.readline(4)  # trailing CRLF after the chunk data
        return bytes(out)

    def _clone_url(self, repo) -> Optional[str]:
        """Absolute ``git clone`` URL for ``repo`` (honours prefix + base URL).

        Uses the configured external base URL when set (e.g. the onion origin),
        else the request ``Host``.  Returns ``None`` when no origin is known.
        """
        base = (self.config.clone_base_url or "").strip().rstrip("/")
        if not base:
            base = self._base_url()
        if not base:
            return None
        prefix = self.config.url_prefix or ""
        return f"{base}{prefix}/{quote(repo.name, safe='')}"

    # ------------------------------------------------------------------ #
    # Parameter extraction / validation
    # ------------------------------------------------------------------ #

    def _q(self, query, key, default=""):
        values = query.get(key)
        return values[0] if values else default

    def _resolve_ref(self, repo, query):
        ref = self._q(query, "ref").strip()
        if not ref:
            return gitcmd.default_branch(repo)
        if not gitcmd.valid_ref(ref):
            raise BadRequest("invalid ref")
        return ref

    def _require_path(self, query):
        path = self._q(query, "path")
        if not gitcmd.valid_path(path):
            raise BadRequest("invalid path")
        return path

    def _page(self, query) -> int:
        """Parse a 1-based ``page`` query parameter (defaulting to 1)."""
        try:
            return max(1, int(self._q(query, "page", "1")))
        except ValueError:
            return 1

    # ------------------------------------------------------------------ #
    # README helper
    # ------------------------------------------------------------------ #

    def _readme(self, repo, ref, path):
        """Return ``(html, name)`` for a README in the given tree, or None."""
        try:
            entries = gitcmd.list_tree(repo, ref, path)
        except (NotFound, GitError):
            return None, None
        target = None
        for entry in entries:
            # Skip entries whose (repo-controlled) path isn't confinement-safe,
            # so a hostile filename can neither be fed to git nor shadow a real
            # README that sorts after it.
            if not gitcmd.valid_path(entry.path):
                continue
            if entry.type == "blob" and entry.name.lower().startswith("readme"):
                target = entry
                break
        if target is None:
            return None, None
        try:
            data = gitcmd.read_blob(repo, ref, target.path, self.config.readme_bytes)
        except (NotFound, GitError):
            return None, None
        if gitcmd.is_binary(data):
            return None, None
        is_md = target.name.lower().endswith((".md", ".markdown"))
        text = data.decode("utf-8", "replace")
        return render_readme(text, is_md), target.name

    # ------------------------------------------------------------------ #
    # Handlers
    # ------------------------------------------------------------------ #

    def handle_health(self):
        self.send_bytes(200, b"ok\n", "text/plain; charset=utf-8")

    def handle_metrics(self):
        body = metrics.REGISTRY.render_prometheus().encode("utf-8")
        self.send_bytes(200, body, "text/plain; version=0.0.4; charset=utf-8")

    def handle_repo_list(self, query=None):
        repos = gitcmd.discover_repos(self.config.root)
        q = self._q(query or {}, "q").strip()
        if q:
            needle = q.lower()
            repos = [
                r
                for r in repos
                if needle in r.name.lower() or needle in (r.description or "").lower()
            ]
        self.send_html(200, views.repo_list(repos, q))

    def handle_summary(self, repo):
        branch = gitcmd.default_branch(repo)
        try:
            commits = gitcmd.log(repo, branch, 0, self.config.summary_commits)
        except NotFound:
            commits = []
        readme_html, readme_name = self._readme(repo, branch, "")
        clone_url = self._clone_url(repo) if self.config.enable_clone else None
        self.send_html(
            200,
            views.summary(
                repo, branch, commits, readme_html, readme_name, clone_url=clone_url
            ),
        )

    def handle_refs(self, repo):
        self.send_html(
            200, views.refs(repo, gitcmd.branches(repo), gitcmd.tags(repo))
        )

    def handle_releases(self, repo):
        self.send_html(200, views.releases(repo, gitcmd.tags(repo)))

    def handle_releases_atom(self, repo):
        body = views.releases_atom(
            repo, gitcmd.tags(repo), self._base_url()).encode("utf-8")
        self.send_bytes(200, body, "application/atom+xml; charset=utf-8")

    def _patch_archive_path(self, repo):
        d = self.config.patches_dir
        if not d:
            return None
        safe = _FILENAME_RE.sub("_", repo.name)
        return os.path.join(d, safe + ".mbox")

    def handle_patches(self, repo, query):
        from . import mailarchive
        path = self._patch_archive_path(repo)
        msgs = mailarchive.read_archive(path) if path else []
        threads = mailarchive.group_threads(msgs)

        def u(action, **params):
            return views.u_action(repo.name, action, **params)

        tid = self._q(query, "thread", "")
        if tid:
            thr = next((t for t in threads if t["id"] == tid), None)
            if thr is None:
                raise NotFound("no such thread")
            body = mailarchive.render_thread(repo.name, thr, u)
            title = "%s: %s" % (repo.name, thr["subject"])
        else:
            body = mailarchive.render_list(
                repo.name, threads, u, configured=bool(path))
            title = "%s: patches" % repo.name
        body = "<style>%s</style>%s" % (mailarchive.PATCH_CSS, body)
        self.send_html(200, views.page(
            title, body, repo_name=repo.name, active_tab="patches",
            repo_desc=repo.description))

    def handle_patches_mbox(self, repo, query):
        from . import mailarchive
        path = self._patch_archive_path(repo)
        msgs = mailarchive.read_archive(path) if path else []
        threads = mailarchive.group_threads(msgs)
        tid = self._q(query, "thread", "")
        thr = next((t for t in threads if t["id"] == tid), None)
        if thr is None:
            raise NotFound("no such thread")
        data = mailarchive.thread_mbox(thr)
        self.send_bytes(200, data, "application/mbox",
                        disposition='attachment; filename="%s.mbox"' % thr["id"])

    def handle_log(self, repo, query):
        ref = self._resolve_ref(repo, query)
        try:
            page_num = int(self._q(query, "page", "1"))
        except ValueError:
            page_num = 1
        total = gitcmd.commit_count(repo, ref)
        page_size = self.config.page_size
        total_pages = max(1, math.ceil(total / page_size)) if total else 1
        page_num = max(1, min(page_num, total_pages))
        skip = (page_num - 1) * page_size
        try:
            rows = gitcmd.log(repo, ref, skip, page_size)
        except NotFound:
            # Empty / unborn-HEAD repo (or a ref with no commits): render an
            # empty log page rather than a 404, mirroring handle_summary.
            rows = []
        self.send_html(200, views.log_page(repo, ref, rows, page_num, total_pages))

    def handle_commit(self, repo, query):
        rev = self._q(query, "id").strip()
        if not gitcmd.valid_ref(rev):
            raise BadRequest("invalid commit id")
        # A full 40-hex sha names an immutable commit: we can answer a
        # conditional GET (and skip the diff parse) before touching git.
        etag = None
        if _FULL_SHA_RE.match(rev):
            etag = self.make_etag(rev, "commit")
            if self.conditional_get(etag):
                return
        commit = gitcmd.commit_meta(repo, rev)
        files = parse_patch(gitcmd.commit_patch(repo, rev))
        self.send_html(200, views.commit_page(repo, commit, files), etag=etag)

    def handle_tree(self, repo, query):
        ref = self._resolve_ref(repo, query)
        path = self._require_path(query)
        if path:
            st = gitcmd.stat_object(repo, ref, path)
            if st is None:
                raise NotFound("no such path")
            if st.type == "blob":
                return self.send_redirect(
                    views.u_action(repo.name, "blob", ref=ref, path=path)
                )
            content_sha = st.sha
        else:
            st = gitcmd.stat_object(repo, ref, "")
            if st is None:
                raise NotFound("no such ref")
            content_sha = st.sha

        # Ref folded in for the same reason as the blob view (ref-specific
        # chrome), and the requested page so a 304 cannot serve the wrong page;
        # the tree/commit sha still invalidates on any content change.
        req_page = self._page(query)
        etag = self.make_etag(content_sha, path, ref, str(req_page), "tree")
        if self.conditional_get(etag):
            return

        entries = gitcmd.list_tree(repo, ref, path)
        # Hard entry cap via pagination (mirror the log pager) so a directory
        # with tens of thousands of entries cannot blow up the response.
        total = len(entries)
        page_size = self.config.tree_page_size
        total_pages = max(1, math.ceil(total / page_size)) if total else 1
        page_num = min(req_page, total_pages)
        start = (page_num - 1) * page_size
        page_entries = entries[start : start + page_size]

        readme_html, readme_name = (None, None)
        if page_num == 1:
            readme_html, readme_name = self._readme(repo, ref, path)
        branches, tags = gitcmd.ref_names(repo)
        commit_sha = gitcmd.resolve_commit(repo, ref)
        submodules = {}
        if any(e.type == "commit" for e in page_entries):
            submodules = gitcmd.read_gitmodules(repo, ref)
        self.send_html(
            200,
            views.tree_page(
                repo,
                ref,
                path,
                page_entries,
                readme_html,
                readme_name,
                page_num=page_num,
                total_pages=total_pages,
                total_entries=total,
                branches=branches,
                tags=tags,
                commit_sha=commit_sha,
                submodules=submodules,
            ),
            etag=etag,
        )

    def handle_blob(self, repo, query):
        ref = self._resolve_ref(repo, query)
        path = self._require_path(query)
        if not path:
            raise BadRequest("missing path")
        st = gitcmd.stat_object(repo, ref, path)
        if st is None:
            raise NotFound("no such file")
        if st.type == "tree":
            return self.send_redirect(
                views.u_action(repo.name, "tree", ref=ref, path=path)
            )
        if st.type != "blob":
            raise NotFound("not a file")

        size = st.size
        hl_raw = self._q(query, "highlight")
        display_raw = self._q(query, "display")
        # Fold in the ref (page renders ref-specific chrome) and the variant
        # selectors (highlight/display) so a 304 can never serve the wrong
        # rendered variant of the same blob.
        etag = self.make_etag(st.sha, path, ref, hl_raw, display_raw, "blob")
        if self.conditional_get(etag):
            return

        branches, tags = gitcmd.ref_names(repo)
        commit_sha = gitcmd.resolve_commit(repo, ref)
        highlight = _parse_line_range(hl_raw)

        # Git LFS detection (pointers are tiny, so a peek parses one).  When the
        # pointed object is in local storage we render the REAL content; when it
        # is not, we fall back to showing the pointer with a note.
        peek = gitcmd.peek_blob(repo, ref, path, 8192)
        lfs_ptr = gitcmd.parse_lfs_pointer(peek)
        lfs_local = gitcmd.lfs_object_path(repo, lfs_ptr.oid) if lfs_ptr else None

        is_image = _ext(path) in _IMAGE_TYPES

        # An image renders inline via <img src=raw> (CSP allows img-src 'self')
        # when it is an ordinary blob, or an LFS pointer whose object is present
        # locally (/raw then serves the real image bytes).
        if is_image and (lfs_ptr is None or lfs_local is not None):
            img_size = gitcmd.lfs_object_size(lfs_local) if lfs_local else size
            self.send_html(
                200,
                views.blob_page(
                    repo, ref, path, size=img_size, text=None, binary=True,
                    too_large=False, is_image=True, branches=branches,
                    tags=tags, commit_sha=commit_sha,
                    lfs_served=lfs_ptr if lfs_local else None,
                ),
                etag=etag,
            )
            return

        # A non-image LFS pointer whose object is present locally: render the
        # real content (read from local storage, capped like any blob).
        if lfs_ptr is not None and lfs_local is not None:
            obj_size = gitcmd.lfs_object_size(lfs_local)
            binary = gitcmd.is_binary(gitcmd.peek_file(lfs_local, 8192))
            too_large = obj_size > self.config.max_blob_bytes
            text = None
            rendered_md = None
            if not binary and not too_large:
                data = gitcmd.read_file(lfs_local, self.config.max_blob_bytes)
                text = data.decode("utf-8", "replace")
                if _ext(path) in ("md", "markdown"):
                    try:
                        rendered_md = render_markdown(text)
                    except Exception:  # pragma: no cover - fall back to source
                        rendered_md = None
            self.send_html(
                200,
                views.blob_page(
                    repo, ref, path, size=obj_size, text=text, binary=binary,
                    too_large=too_large, syntax=self.config.syntax_highlight,
                    highlight=highlight, branches=branches, tags=tags,
                    commit_sha=commit_sha, lfs_served=lfs_ptr,
                    rendered_md=rendered_md, show_source=display_raw == "source",
                ),
                etag=etag,
            )
            return

        # An LFS pointer whose object is NOT stored locally: show the pointer.
        if lfs_ptr is not None:
            self.send_html(
                200,
                views.blob_page(
                    repo, ref, path, size=size, text=None, binary=False,
                    too_large=False, branches=branches, tags=tags,
                    commit_sha=commit_sha, lfs=lfs_ptr,
                ),
                etag=etag,
            )
            return

        # Ordinary (non-LFS) blob.
        binary = gitcmd.is_binary(peek)
        too_large = size > self.config.max_blob_bytes
        text = None
        rendered_md = None
        if not binary and not too_large:
            data = gitcmd.read_blob(repo, ref, path, self.config.max_blob_bytes)
            text = data.decode("utf-8", "replace")
            if _ext(path) in ("md", "markdown"):
                try:
                    rendered_md = render_markdown(text)
                except Exception:  # pragma: no cover - fall back to source
                    rendered_md = None
        show_source = display_raw == "source"
        self.send_html(
            200,
            views.blob_page(
                repo, ref, path, size=size, text=text, binary=binary,
                too_large=too_large, syntax=self.config.syntax_highlight,
                highlight=highlight, branches=branches, tags=tags,
                commit_sha=commit_sha, lfs=None, rendered_md=rendered_md,
                show_source=show_source,
            ),
            etag=etag,
        )

    def _raw_content_type(self, path, binary):
        """Pick ``(content_type, disposition)`` for a /raw byte stream."""
        filename = _FILENAME_RE.sub("_", os.path.basename(path)) or "file"
        image_type = _IMAGE_TYPES.get(_ext(path))
        if image_type:
            # Inline as an image (the blob view references it via <img>).
            # nosniff + our strict CSP keep even SVG safe (scripts blocked).
            return image_type, f'inline; filename="{filename}"'
        if binary:
            return "application/octet-stream", f'attachment; filename="{filename}"'
        return "text/plain; charset=utf-8", f'inline; filename="{filename}"'

    def _send_download(self, ctype, disposition, etag_base, size, stream_factory):
        """Stream ``size`` bytes (capped at ``raw_max_bytes``) with safe headers.

        ``stream_factory(max_bytes)`` returns a closeable byte iterator (a git
        ``cat-file`` stream or a local-file stream); it is torn down
        deterministically in the ``finally``.  The response is never content-
        coded, so the ETag carries no encoding suffix.
        """
        cap = self.config.raw_max_bytes
        length = min(size, cap)
        self.close_connection = True  # avoid keep-alive desync on truncation
        self._status = 200
        self.send_response(200)
        self._security_headers(ctype)
        self.send_header("Content-Length", str(length))
        self.send_header("Content-Disposition", disposition)
        self.send_header("ETag", self._final_etag(etag_base, ""))
        self.send_header("Connection", "close")
        self.end_headers()
        if self.command == "HEAD":
            return
        max_stream = 0 if size <= cap else cap
        written = 0
        stream = stream_factory(max_stream)
        try:
            for chunk in stream:
                if written + len(chunk) > length:
                    chunk = chunk[: length - written]
                if chunk:
                    self.wfile.write(chunk)
                    written += len(chunk)
                if written >= length:
                    break
        finally:
            stream.close()  # runs the generator's cleanup deterministically

    def handle_raw(self, repo, query):
        ref = self._resolve_ref(repo, query)
        path = self._require_path(query)
        if not path:
            raise BadRequest("missing path")
        st = gitcmd.stat_object(repo, ref, path)
        if st is None or st.type != "blob":
            raise NotFound("no such file")

        size = st.size
        # The raw stream is never content-coded, so its ETag carries no encoding
        # suffix; revalidate encoding-independently or a gzip-advertising browser
        # would never see a 304.
        etag = self.make_etag(st.sha, path, "raw")
        if self.conditional_get(etag, encoding_independent=True):
            return
        peek = gitcmd.peek_blob(repo, ref, path, 8192)

        # Git LFS: when the blob is a pointer whose object is in *local* storage,
        # serve the real object bytes (streamed + capped) — never a remote fetch.
        lfs = gitcmd.parse_lfs_pointer(peek)
        if lfs is not None:
            local = gitcmd.lfs_object_path(repo, lfs.oid)
            if local is not None:
                obj_size = gitcmd.lfs_object_size(local)
                obj_binary = gitcmd.is_binary(gitcmd.peek_file(local, 8192))
                ctype, disposition = self._raw_content_type(path, obj_binary)
                return self._send_download(
                    ctype, disposition, etag, obj_size,
                    lambda mb: gitcmd.stream_file(local, max_bytes=mb),
                )

        binary = gitcmd.is_binary(peek)
        ctype, disposition = self._raw_content_type(path, binary)
        self._send_download(
            ctype, disposition, etag, size,
            lambda mb: gitcmd.stream_blob(repo, ref, path, max_bytes=mb),
        )

    def handle_blame(self, repo, query):
        ref = self._resolve_ref(repo, query)
        path = self._require_path(query)
        if not path:
            raise BadRequest("missing path")
        if gitcmd.object_type(repo, ref, path) != "blob":
            raise NotFound("not a file")
        lines = gitcmd.blame(repo, ref, path)
        self.send_html(200, views.blame_page(repo, ref, path, lines))

    def handle_history(self, repo, query):
        ref = self._resolve_ref(repo, query)
        path = self._require_path(query)
        if not path:
            raise BadRequest("missing path")
        follow = self._q(query, "follow") in ("1", "true", "yes", "on")
        total = gitcmd.commit_count_path(repo, ref, path)
        page_size = self.config.page_size
        total_pages = max(1, math.ceil(total / page_size)) if total else 1
        page_num = min(self._page(query), total_pages)
        skip = (page_num - 1) * page_size
        try:
            rows = gitcmd.log_path(repo, ref, path, skip, page_size, follow)
        except NotFound:
            rows = []
        self.send_html(
            200,
            views.history_page(repo, ref, path, rows, page_num, total_pages, follow),
        )

    def handle_atom(self, repo, query):
        ref = self._resolve_ref(repo, query)
        try:
            rows = gitcmd.log(repo, ref, 0, self.config.feed_commits)
        except NotFound:
            rows = []
        body = views.atom_feed(repo, ref, rows, self._base_url()).encode(
            "utf-8", "replace"
        )
        self.send_bytes(200, body, "application/atom+xml; charset=utf-8")

    def handle_compare(self, repo, query):
        base = self._q(query, "from").strip()
        other = self._q(query, "to").strip()
        if not base or not other:
            raise BadRequest("compare needs 'from' and 'to' refs")
        if not gitcmd.valid_ref(base) or not gitcmd.valid_ref(other):
            raise BadRequest("invalid ref")
        patch = gitcmd.compare(repo, base, other)
        files = parse_patch(patch)
        self.send_html(200, views.compare_page(repo, base, other, files))

    def handle_search(self, repo, query):
        q = self._q(query, "q")
        typ = self._q(query, "type", "code")
        if typ not in ("code", "log"):
            typ = "code"
        ref = self._resolve_ref(repo, query)
        invalid = False
        code_matches = None
        code_truncated = False
        log_rows = None
        page_num = 1
        total_pages = 1
        if q:
            if not gitcmd.valid_query(q):
                invalid = True
            elif typ == "log":
                total = gitcmd.commit_count_grep(repo, ref, q)
                page_size = self.config.page_size
                total_pages = max(1, math.ceil(total / page_size)) if total else 1
                page_num = max(1, min(self._page(query), total_pages))
                skip = (page_num - 1) * page_size
                log_rows = gitcmd.log_grep(repo, ref, q, skip, page_size)
            else:
                code_matches, code_truncated = gitcmd.search_code(repo, ref, q)
        self.send_html(
            200,
            views.search_page(
                repo, q, typ, ref,
                code_matches=code_matches, code_truncated=code_truncated,
                log_rows=log_rows, page_num=page_num, total_pages=total_pages,
                invalid=invalid,
            ),
        )

    def handle_graph(self, repo, query):
        ref = self._resolve_ref(repo, query)
        total = gitcmd.commit_count(repo, ref)
        page_size = self.config.page_size
        total_pages = max(1, math.ceil(total / page_size)) if total else 1
        page_num = max(1, min(self._page(query), total_pages))
        skip = (page_num - 1) * page_size
        try:
            rows = gitcmd.log_graph(repo, ref, skip, page_size)
        except NotFound:
            rows = []
        self.send_html(200, views.graph_page(repo, ref, rows, page_num, total_pages))

    def handle_patch(self, repo, query):
        rev = self._q(query, "id").strip()
        if not gitcmd.valid_ref(rev):
            raise BadRequest("invalid commit id")
        # A full 40-hex sha names an immutable patch: answer a conditional GET
        # (never content-coded, so revalidate encoding-independently).
        etag = None
        if _FULL_SHA_RE.match(rev):
            etag = self.make_etag(rev, "patch")
            if self.conditional_get(etag, encoding_independent=True):
                return
        data = gitcmd.format_patch(repo, rev)
        safe = _FILENAME_RE.sub("_", f"{repo.name}-{rev[:12]}") or "patch"
        self.send_bytes(
            200,
            data,
            "text/plain; charset=utf-8",
            disposition=f'attachment; filename="{safe}.patch"',
            etag=self._final_etag(etag, "") if etag else None,
        )

    def handle_opensearch_repo(self, repo):
        body = views.opensearch_repo(repo.name, self._opensearch_base()).encode(
            "utf-8", "replace"
        )
        self.send_bytes(200, body, "application/opensearchdescription+xml")

    def handle_opensearch_site(self):
        body = views.opensearch_site(self._opensearch_base()).encode(
            "utf-8", "replace"
        )
        self.send_bytes(200, body, "application/opensearchdescription+xml")

    def _opensearch_base(self) -> str:
        """Absolute origin + reverse-proxy prefix for an OpenSearch template.

        Prefers the request ``Host`` (via :meth:`_base_url`); falls back to a
        prefix-relative base when no host is known.  The template the browser
        stores works either way, and always carries ``--url-prefix``.
        """
        return f"{self._base_url()}{self.config.url_prefix or ''}"

    def handle_archive(self, repo, query):
        ref = self._resolve_ref(repo, query)
        if gitcmd.stat_object(repo, ref, "") is None:
            raise NotFound("no such ref")
        safe_ref = _FILENAME_RE.sub("_", ref) or "archive"
        prefix = f"{repo.name}-{safe_ref}/"
        filename = f"{repo.name}-{safe_ref}.tar.gz"
        cap = self.config.archive_max_bytes

        self.close_connection = True  # length is unknown; read to EOF
        self._status = 200
        self.send_response(200)
        self._security_headers("application/gzip")
        self.send_header("Content-Disposition", f'attachment; filename="{filename}"')
        self.send_header("Connection", "close")
        self.end_headers()
        if self.command == "HEAD":
            return
        written = 0
        stream = gitcmd.stream_archive(repo, ref, prefix, max_bytes=cap)
        try:
            for chunk in stream:
                if not chunk:
                    continue
                self.wfile.write(chunk)
                written += len(chunk)
                if cap and written >= cap:
                    break
        finally:
            stream.close()

    # ------------------------------------------------------------------ #
    # Misc helpers
    # ------------------------------------------------------------------ #

    def _base_url(self) -> str:
        """Best-effort absolute origin (scheme://host) for feed links.

        Honours ``X-Forwarded-Proto`` (reverse proxy / Tor front) and the
        ``Host`` header; returns "" when no host is known, in which case the
        feed falls back to relative links.
        """
        host = self.headers.get("Host")
        if not host:
            return ""
        proto = (self.headers.get("X-Forwarded-Proto") or "http").split(",")[0].strip()
        if proto not in ("http", "https"):
            proto = "http"
        return f"{proto}://{host}"


class BoundedThreadingHTTPServer(ThreadingHTTPServer):
    """A threading server that caps the number of in-flight connections.

    An unauthenticated onion service is exposed to Slowloris-style thread
    exhaustion: each slow client pins a worker thread.  A
    :class:`~threading.BoundedSemaphore` limits concurrent handler threads;
    connections beyond the limit are closed immediately (and counted) instead
    of spawning unbounded threads.  Combined with the per-connection socket
    timeout set in :meth:`GitwebHandler.setup`, a slow or idle client can
    neither pin a worker indefinitely nor exhaust the pool.
    """

    daemon_threads = True
    allow_reuse_address = True

    def __init__(
        self,
        server_address,
        handler_cls,
        *,
        max_workers: int = 32,
        clone_max_concurrency: int = 4,
    ):
        super().__init__(server_address, handler_cls)
        self.max_workers = max(1, int(max_workers))
        self._slots = threading.BoundedSemaphore(self.max_workers)
        # A separate, smaller budget for concurrent git-upload-pack RPCs.  Kept
        # below ``max_workers`` so long-running clones cannot monopolise the
        # whole worker pool and starve interactive browsing.
        self.clone_max_concurrency = max(1, int(clone_max_concurrency))
        self.clone_slots = threading.BoundedSemaphore(self.clone_max_concurrency)

    def process_request(self, request, client_address):
        if not self._slots.acquire(blocking=False):
            metrics.REGISTRY.reject()
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:  # pragma: no cover - defensive
            self._slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self._slots.release()


def _normalize_prefix(prefix: str) -> str:
    prefix = (prefix or "").strip()
    if not prefix:
        return ""
    if not prefix.startswith("/"):
        prefix = "/" + prefix
    return prefix.rstrip("/")


def make_server(config: Config) -> ThreadingHTTPServer:
    """Create (but do not start) a configured bounded threading server."""
    root_real = os.path.realpath(config.root)
    if not os.path.isdir(root_real):
        raise SystemExit(f"root is not a directory: {config.root}")
    config.root = root_real
    config.url_prefix = _normalize_prefix(config.url_prefix)

    # Resolve the optional Basic-auth credential once, up front, so a malformed
    # spec fails at startup (not per request) and no plaintext is kept around.
    spec = config.auth
    if config.auth_file:
        with open(config.auth_file, "r", encoding="utf-8") as fh:
            for raw in fh:
                line = raw.strip()
                if line and not line.startswith("#"):
                    spec = line
                    break
    # Whether the operator *asked* for access control at all.
    auth_requested = bool((config.auth or "").strip() or (config.auth_file or "").strip())
    try:
        auth_cred = auth.parse_auth_spec(spec)
    except ValueError as exc:
        raise SystemExit(f"invalid --auth/--auth-file credential: {exc}")
    # If auth was requested but no usable credential resulted (e.g. an empty or
    # comment-only --auth-file), refuse to start rather than silently serving
    # with access control disabled.
    if auth_requested and auth_cred is None:
        raise SystemExit(
            "access control was requested via --auth/--auth-file but no usable "
            "credential was found (empty or comment-only); refusing to start "
            "with auth silently disabled"
        )

    httpd = BoundedThreadingHTTPServer(
        (config.host, config.port),
        GitwebHandler,
        max_workers=config.max_workers,
        clone_max_concurrency=config.clone_max_concurrency,
    )
    httpd.config = config  # type: ignore[attr-defined]
    httpd.auth_cred = auth_cred  # type: ignore[attr-defined]
    return httpd


def serve(config: Config) -> None:
    """Create and run the server until interrupted."""
    httpd = make_server(config)
    host, port = httpd.server_address[0], httpd.server_address[1]
    if config.verbose:
        print(f"gitweb serving repos in {config.root} at http://{host}:{port}/")
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        httpd.shutdown()
        httpd.server_close()
