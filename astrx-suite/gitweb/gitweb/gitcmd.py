"""Read-only git plumbing for gitweb.

Every function in this module shells out to the ``git`` binary using an
explicit *argument list* (never ``shell=True``) and only ever invokes a small
allow-list of read-only sub-commands.  All values that originate from a URL
(repo name, ref, object path) are validated by the ``valid_*`` helpers before
they are handed to git, and refs/paths are always separated from the rest of
the command line with ``--`` so a crafted value cannot be treated as an option.

Nothing in here writes to a repository.
"""

from __future__ import annotations

import atexit
import os
import re
import selectors
import signal
import subprocess
import threading
import time
from dataclasses import dataclass, field
from typing import Dict, Iterator, List, Optional, Tuple

# --------------------------------------------------------------------------- #
# Configuration constants
# --------------------------------------------------------------------------- #

GIT = "git"

#: Sub-commands we are willing to run.  Every one is read-only.  This list is
#: the last line of defence: even if a bug let a caller pass an arbitrary
#: sub-command, only these could ever execute.
ALLOWED_SUBCOMMANDS = frozenset(
    {
        "log",
        "show",
        "cat-file",
        "ls-tree",
        "rev-parse",
        "rev-list",
        "for-each-ref",
        "blame",
        "diff-tree",
        "symbolic-ref",
        "archive",
        # Read-only content/message search and patch export.  ``grep`` searches
        # a tree-ish (never the option-injectable pattern: it is always the
        # operand of ``-e``); ``format-patch`` emits a mailbox for ``git am``.
        # Both are read-only and, like every other subcommand, are fed only
        # validated args separated from the pattern/rev with ``--``.
        "grep",
        "format-patch",
        # Read-only clone/fetch transport (Git Smart HTTP).  ``upload-pack`` is
        # the *only* pack transport ever run: it serves objects out, it never
        # writes.  ``receive-pack`` (push) is deliberately absent and must never
        # be added — see the HTTP layer, which 403s any receive-pack request.
        "upload-pack",
    }
)

DEFAULT_TIMEOUT = 15  # seconds per git invocation
DEFAULT_MAX_BYTES = 12 * 1024 * 1024  # hard cap on captured stdout (bounds RAM)
MAX_STDERR_BYTES = 64 * 1024  # keep only a little stderr for error messages
FIELD_SEP = "\x1f"  # ASCII unit separator, used inside --format strings

# -- Search (code + commit message) bounds ---------------------------------- #
#: Longest search term we accept.  A term is always passed as a single argv
#: element (the operand of ``-e`` for code, ``--grep=`` for messages), so it can
#: never be option-like; this bound just keeps the request small.
MAX_QUERY_BYTES = 512
GREP_TIMEOUT = 10  # wall-clock (s) for one ``git grep`` (short: it is literal)
GREP_MAX_BYTES = 4 * 1024 * 1024  # hard cap on grep stdout (bounds RAM)
GREP_MAX_MATCHES = 1000  # total match rows rendered (parse-time cap)
GREP_MAX_COUNT_PER_FILE = 100  # ``--max-count``: matches per file git emits

# -- Patch export bounds ---------------------------------------------------- #
PATCH_TIMEOUT = 30  # wall-clock (s) for one ``git format-patch``
PATCH_MAX_BYTES = 12 * 1024 * 1024  # hard cap on the mailbox we buffer/serve

# -- Git Smart HTTP (read-only clone/fetch) bounds -------------------------- #
UPLOAD_PACK_TIMEOUT = 120  # overall wall-clock (s) for one advertise/RPC call
#: Cap on the ref advertisement captured for ``info/refs`` (bounds RAM: a repo
#: with a pathological number of refs cannot make the advertisement unbounded).
UPLOAD_PACK_ADVERTISE_MAX_BYTES = 12 * 1024 * 1024

# Unit separator byte for splitting captured output.
_FS = b"\x1f"
_NUL = b"\x00"


# --------------------------------------------------------------------------- #
# Errors
# --------------------------------------------------------------------------- #


class GitError(Exception):
    """A git command failed or produced no usable output."""


class BadRequest(Exception):
    """The request contained an invalid/hostile parameter (HTTP 400)."""


class NotFound(Exception):
    """The requested repo/ref/object does not exist (HTTP 404)."""


# --------------------------------------------------------------------------- #
# Validation of untrusted URL parameters
# --------------------------------------------------------------------------- #

_REPO_RE = re.compile(r"^[A-Za-z0-9._-]+$")
_REF_RE = re.compile(r"^[A-Za-z0-9._/+-]+$")


def valid_repo_name(name: str) -> bool:
    """A repo id is a single path component from a fixed charset.

    It must not be ``.`` / ``..`` and must not begin with ``-`` (option-like).
    """
    if not name or name in (".", ".."):
        return False
    if name.startswith("-"):
        return False
    return bool(_REPO_RE.match(name))


def valid_ref(ref: str) -> bool:
    """Validate a ref (branch/tag/sha/short-sha) supplied via the URL.

    We are deliberately stricter than ``git check-ref-format``: the charset is
    limited, a leading ``-`` (option injection) is rejected, and git's special
    sequences (``..``, ``@{``, ``:``, whitespace, control chars, ``~^?*[``) are
    all refused.  ``:`` in particular is excluded so we can safely build the
    ``<ref>:<path>`` object spec later.
    """
    if not ref or len(ref) > 256:
        return False
    if ref[0] in "-/." or ref[-1] == "/":
        return False
    if ".." in ref or "@{" in ref or ".lock" in ref:
        return False
    return bool(_REF_RE.match(ref))


def valid_path(path: str) -> bool:
    """Validate an in-repo object path supplied via the URL.

    Empty means the repository root.  We reject anything that could escape the
    tree or be mistaken for an option: leading ``/`` or ``-``, any ``..``
    component, and control characters.
    """
    if path == "":
        return True
    if len(path) > 4096:
        return False
    if path[0] in "/-":
        return False
    if "\x00" in path:
        return False
    for ch in path:
        if ord(ch) < 0x20:
            return False
    for part in path.split("/"):
        if part == "..":
            return False
    return True


def valid_query(q: str) -> bool:
    """Validate a free-text search term supplied via the URL.

    The term is only ever handed to git as the operand of ``-e`` (code search)
    or as ``--grep=<term>`` (message search) — a single argv element that git
    treats as a *literal* pattern (``--fixed-strings``), so it can neither be
    read as an option nor cause regex backtracking (ReDoS).  This check only
    bounds the length and forbids a NUL, which cannot appear in an argv element
    (``execve`` would reject it) and is the output-record separator we rely on.
    """
    if not q or len(q) > MAX_QUERY_BYTES:
        return False
    return "\x00" not in q


def object_spec(ref: str, path: str) -> str:
    """Build a ``<ref>:<path>`` (or bare ``<ref>``) git object spec.

    Callers MUST have validated ``ref`` and ``path`` first.
    """
    if path:
        return f"{ref}:{path}"
    return ref


# --------------------------------------------------------------------------- #
# Subprocess wrapper
# --------------------------------------------------------------------------- #


def _git_env(extra: Optional[dict] = None) -> dict:
    """Environment for git children: no prompts, no global/system config.

    Ignoring global/system config makes behaviour deterministic and strips any
    user aliases/pagers.  ``safe.directory`` is forced on via ``-c`` (below) so
    we can still read repos owned by another uid.

    ``GIT_PROTOCOL`` is scrubbed by default so the wire protocol version is
    deterministic; the Smart-HTTP layer passes ``extra={"GIT_PROTOCOL":
    "version=2"}`` only when the client explicitly negotiated protocol v2.
    """
    env = dict(os.environ)
    env["GIT_TERMINAL_PROMPT"] = "0"
    env["GIT_CONFIG_GLOBAL"] = os.devnull
    env["GIT_CONFIG_SYSTEM"] = os.devnull
    env.pop("GIT_DIR", None)
    env.pop("GIT_WORK_TREE", None)
    env.pop("GIT_PROTOCOL", None)
    if extra:
        env.update(extra)
    return env


def _kill_process_group(proc: "subprocess.Popen") -> None:
    """SIGKILL the child *and its entire process group*.

    Every long-running git child is spawned with ``start_new_session=True`` so
    it leads its own process group.  ``git upload-pack`` in particular forks
    ``git pack-objects`` (the CPU/RAM-heavy step) as a child: a bare
    ``proc.kill()`` would terminate only ``upload-pack`` and orphan
    ``pack-objects`` (reparented to init, still burning CPU/RAM), defeating both
    the wall-clock timeout and the clone concurrency cap.  Killing the whole
    group reaps the entire subtree.  Falls back to a direct kill if the group id
    is unavailable (e.g. the child already exited).
    """
    if proc.poll() is not None:
        return  # already exited; nothing to signal (and the pid may be reaped)
    try:
        os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
    except (ProcessLookupError, OSError):
        try:
            proc.kill()
        except (ProcessLookupError, OSError):  # pragma: no cover - defensive
            pass


def _capture_capped(
    proc: "subprocess.Popen", max_bytes: int, timeout: int
) -> Tuple[bytes, bytes, bool, bool]:
    """Drain ``proc`` without ever buffering more than ``max_bytes`` of stdout.

    stdout and stderr are read incrementally under a single wall-clock
    ``timeout``.  The instant stdout reaches ``max_bytes`` the child is killed,
    so a command that would emit gigabytes (e.g. ``cat-file -p`` on a huge
    blob) can never stream more than the cap into memory.  ``communicate()`` is
    deliberately avoided because it buffers the *entire* output first.  Returns
    ``(stdout, stderr, capped, timed_out)``.
    """
    sel = selectors.DefaultSelector()
    tag = {}
    if proc.stdout is not None:
        sel.register(proc.stdout, selectors.EVENT_READ)
        tag[proc.stdout.fileno()] = "out"
    if proc.stderr is not None:
        sel.register(proc.stderr, selectors.EVENT_READ)
        tag[proc.stderr.fileno()] = "err"

    out = bytearray()
    err = bytearray()
    capped = False
    timed_out = False
    deadline = time.monotonic() + timeout
    try:
        while sel.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                timed_out = True
                break
            ready = sel.select(remaining)
            if not ready:
                timed_out = True
                break
            for key, _mask in ready:
                try:
                    data = os.read(key.fd, 65536)
                except (BlockingIOError, InterruptedError):  # pragma: no cover
                    continue
                if not data:  # EOF on this stream
                    sel.unregister(key.fileobj)
                    continue
                if tag.get(key.fd) == "out":
                    room = max_bytes - len(out)
                    if room > 0:
                        out += data[:room]
                    if len(out) >= max_bytes:
                        capped = True
                        break
                elif len(err) < MAX_STDERR_BYTES:
                    err += data[: MAX_STDERR_BYTES - len(err)]
            if capped:
                break
    finally:
        sel.close()
        _kill_process_group(proc)
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:  # pragma: no cover - defensive
            _kill_process_group(proc)
            proc.wait()
        for stream in (proc.stdout, proc.stderr):
            if stream is not None:
                try:
                    stream.close()
                except OSError:  # pragma: no cover - defensive
                    pass
    return bytes(out), bytes(err), capped, timed_out


def _git_base_cmd(repo_path: str) -> List[str]:
    """The invariant leading argv shared by every git invocation.

    Centralising it keeps the hardening (no pager, global/system config off,
    ``safe.directory=*``, deterministic quoting) identical across the plain
    :func:`run_git` path, the streamed ``/raw`` reader and the persistent
    ``cat-file`` batch reader.
    """
    return [
        GIT,
        "--no-pager",
        "-c",
        "safe.directory=*",
        "-c",
        "log.showSignature=false",
        "-c",
        "core.quotePath=false",
        "-C",
        repo_path,
    ]


def run_git(
    repo_path: str,
    args: List[str],
    *,
    timeout: int = DEFAULT_TIMEOUT,
    max_bytes: int = DEFAULT_MAX_BYTES,
    check: bool = False,
) -> Tuple[int, bytes, bytes]:
    """Run a read-only git command and return ``(returncode, stdout, stderr)``.

    * ``args[0]`` must be in :data:`ALLOWED_SUBCOMMANDS`.
    * Never uses a shell; ``args`` is passed verbatim as an argument vector.
    * stdout is read incrementally and **hard-capped** at ``max_bytes``: peak
      memory stays bounded even for a command that would emit far more, because
      the child is killed the moment the cap is hit.
    * The child is killed if it runs longer than ``timeout`` seconds.
    """
    if not args or args[0] not in ALLOWED_SUBCOMMANDS:
        raise BadRequest(f"refused git subcommand: {args[0] if args else '(none)'}")

    cmd = [*_git_base_cmd(repo_path), *args]

    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        stdin=subprocess.DEVNULL,
        env=_git_env(),
        # Own session/process group so a timeout/cap kill reaps the whole git
        # subtree (any helper it forks) rather than orphaning a detached child.
        start_new_session=True,
    )
    out, err, capped, timed_out = _capture_capped(proc, max_bytes, timeout)
    if timed_out:
        raise GitError(f"git {args[0]} timed out after {timeout}s")

    # When we deliberately truncated at the cap the child was killed, so its
    # exit status is meaningless; treat the (bounded) output as a success.
    returncode = 0 if capped else (proc.returncode or 0)

    if check and returncode != 0:
        raise GitError(
            f"git {args[0]} failed ({returncode}): "
            f"{err.decode('utf-8', 'replace').strip()}"
        )
    return returncode, out, err


def _text(data: bytes) -> str:
    """Decode git output as UTF-8, replacing undecodable bytes."""
    return data.decode("utf-8", "replace")


# --------------------------------------------------------------------------- #
# Persistent ``git cat-file`` batch reader
# --------------------------------------------------------------------------- #
#
# Rendering a single blob used to fork git four times: ``cat-file -t`` (type),
# ``cat-file -s`` (size), one ``cat-file -p`` to peek for binary sniffing and a
# second ``cat-file -p`` to read the body.  This collapses all of that onto two
# long-lived processes per repository — ``--batch-check`` for metadata/type
# lookups and ``--batch`` for content — reused across requests.
#
# Safety is preserved:
#   * the same hardened argv/env as every other git call (argv-only, no shell);
#   * object specs are still built only from validated refs/paths;
#   * the content reader keeps a hard output cap and early-kill: if a blob is
#     larger than the requested cap we read the cap and then kill+respawn the
#     batch process rather than draining (or buffering) gigabytes.


@dataclass
class ObjStat:
    """The identity/type/size triple ``cat-file --batch-check`` returns."""

    sha: str
    type: str  # blob | tree | commit | tag
    size: int


def _parse_batch_header(line: bytes) -> Optional[ObjStat]:
    """Parse one ``cat-file --batch``/``--batch-check`` header line.

    Existing objects yield ``<oid> <type> <size>``; a missing/ambiguous spec
    yields ``<spec> missing`` / ``<spec> ambiguous``.  Returns ``None`` for the
    latter (and for any malformed line).
    """
    if not line:
        return None
    parts = line.split()
    if len(parts) >= 2 and parts[-1] in (b"missing", b"ambiguous"):
        return None
    if len(parts) != 3:
        return None
    sha, otype, size = parts
    if not size.isdigit():
        return None
    return ObjStat(sha=sha.decode("ascii", "replace"), type=otype.decode("ascii", "replace"), size=int(size))


class GitCatFile:
    """A pair of persistent ``git cat-file`` processes for one repository.

    Thread-safe: a single lock serialises access so two request threads can
    never interleave on the shared pipes.  A pid guard makes the object safe
    across ``fork()`` — an inherited process is abandoned (never reused or
    killed from the child) and a fresh one is spawned on demand.
    """

    def __init__(self, repo_path: str) -> None:
        self.repo_path = repo_path
        self._lock = threading.Lock()
        self._check: Optional[subprocess.Popen] = None
        self._batch: Optional[subprocess.Popen] = None
        self._pid = os.getpid()

    # -- process lifecycle -------------------------------------------- #

    def _spawn(self, mode: str) -> subprocess.Popen:
        flag = "--batch-check" if mode == "check" else "--batch"
        cmd = [*_git_base_cmd(self.repo_path), "cat-file", flag]
        # Default buffering: the stdin BufferedWriter absorbs short pipe writes,
        # and the stdout BufferedReader gives a correct readline()/read(n).  Any
        # read-ahead lands in the same object, so exact content reads stay in
        # sync with the header line.
        return subprocess.Popen(
            cmd,
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            env=_git_env(),
            # Own session so teardown group-kills this batch reader cleanly.
            start_new_session=True,
        )

    def _guard_fork(self) -> None:
        # After a fork the child inherits our Popen objects but must not touch
        # the parent's pipes.  Close the child's *copies* of the inherited fds
        # (harmless to the parent, which keeps its own) and mark the objects
        # reaped so Popen.__del__ neither warns nor waitpid()s on a process it
        # did not spawn.  We never kill an inherited process.
        if self._pid != os.getpid():
            for proc in (self._check, self._batch):
                if proc is None:
                    continue
                for stream in (proc.stdin, proc.stdout):
                    if stream is not None:
                        try:
                            stream.close()
                        except OSError:  # pragma: no cover - defensive
                            pass
                proc.returncode = 0
            self._check = None
            self._batch = None
            self._pid = os.getpid()

    def _ensure(self, mode: str) -> subprocess.Popen:
        self._guard_fork()
        attr = "_check" if mode == "check" else "_batch"
        proc = getattr(self, attr)
        if proc is None or proc.poll() is not None:
            proc = self._spawn(mode)
            setattr(self, attr, proc)
        return proc

    def _drop(self, mode: str) -> None:
        attr = "_check" if mode == "check" else "_batch"
        proc = getattr(self, attr)
        setattr(self, attr, None)
        if proc is None:
            return
        try:
            _kill_process_group(proc)
            proc.wait(timeout=2)
        except Exception:  # pragma: no cover - defensive
            pass
        for stream in (proc.stdin, proc.stdout):
            if stream is not None:
                try:
                    stream.close()
                except OSError:  # pragma: no cover - defensive
                    pass

    def close(self) -> None:
        with self._lock:
            # Only tear down processes this pid actually owns.
            if self._pid == os.getpid():
                self._drop("check")
                self._drop("batch")
            else:  # pragma: no cover - abandoned across fork
                self._check = None
                self._batch = None

    # -- low-level exact reads ---------------------------------------- #

    @staticmethod
    def _read_exact(fileobj, n: int) -> bytes:
        buf = bytearray()
        while len(buf) < n:
            chunk = fileobj.read(n - len(buf))
            if not chunk:
                break
            buf += chunk
        return bytes(buf)

    def _request(self, mode: str, spec: str) -> Tuple[subprocess.Popen, Optional[ObjStat]]:
        proc = self._ensure(mode)
        assert proc.stdin is not None and proc.stdout is not None
        proc.stdin.write(spec.encode("utf-8") + b"\n")
        proc.stdin.flush()
        header = proc.stdout.readline()
        return proc, _parse_batch_header(header)

    # -- public API --------------------------------------------------- #

    @staticmethod
    def _spec_ok(spec: str) -> bool:
        """Reject any spec containing a control character.

        A legitimate ``<ref>:<path>`` / ``<ref>^{...}`` spec never contains a
        control byte, but a *repo-derived* path (a filename in the tree) may —
        git allows any byte except NUL and ``/`` in a filename, newline
        included.  Because a spec is written to the batch process's stdin with a
        trailing newline, an embedded newline would inject a second request and
        desynchronise the shared stream (returning the wrong blob's bytes to a
        later request).  Refusing control characters here closes that at the
        choke point for *every* caller, not just URL-validated ones.
        """
        return not any(ord(c) < 0x20 or ord(c) == 0x7F for c in spec)

    def check(self, spec: str) -> Optional[ObjStat]:
        """Return :class:`ObjStat` for ``spec`` or ``None`` if absent."""
        if not self._spec_ok(spec):
            return None
        with self._lock:
            try:
                _proc, stat = self._request("check", spec)
                return stat
            except (BrokenPipeError, OSError, ValueError):
                self._drop("check")
                return None

    def read(
        self, spec: str, max_bytes: int
    ) -> Tuple[Optional[bytes], Optional[ObjStat], bool]:
        """Read up to ``max_bytes`` of the object body.

        Returns ``(data, stat, truncated)``.  ``data`` is ``None`` when the
        object does not exist.  When the object is larger than ``max_bytes`` we
        read the cap and kill+respawn the batch process (early-kill; the stream
        would otherwise be desynchronised), so peak memory stays ~``max_bytes``.
        """
        if not self._spec_ok(spec):
            return None, None, False
        with self._lock:
            try:
                proc, stat = self._request("batch", spec)
                if stat is None:
                    return None, None, False
                assert proc.stdout is not None
                want = min(stat.size, max(0, max_bytes))
                data = self._read_exact(proc.stdout, want)
                if len(data) < want:  # short read => process died mid-body
                    self._drop("batch")
                    return data, stat, True
                if stat.size <= max_bytes:
                    # Consume the single trailing LF so the stream stays aligned.
                    self._read_exact(proc.stdout, 1)
                    return data, stat, False
                # Body exceeds the cap: abandon the rest, do not drain it.
                self._drop("batch")
                return data, stat, True
            except (BrokenPipeError, OSError, ValueError):
                # Any failure mid-body could leave unconsumed bytes in the pipe;
                # drop the process so the next request starts from a clean one.
                self._drop("batch")
                return None, None, False


# Module-level cache of one :class:`GitCatFile` per repository path.
_CATFILE_CACHE: Dict[str, GitCatFile] = {}
_CATFILE_LOCK = threading.Lock()


def _catfile(repo_path: str) -> GitCatFile:
    with _CATFILE_LOCK:
        cf = _CATFILE_CACHE.get(repo_path)
        if cf is None:
            cf = GitCatFile(repo_path)
            _CATFILE_CACHE[repo_path] = cf
        return cf


def close_catfiles() -> None:
    """Tear down every cached batch reader (called at interpreter exit)."""
    with _CATFILE_LOCK:
        readers = list(_CATFILE_CACHE.values())
        _CATFILE_CACHE.clear()
    for cf in readers:
        cf.close()


atexit.register(close_catfiles)


def stat_object(repo: "Repo", ref: str, path: str) -> Optional[ObjStat]:
    """Return the object identity/type/size at ``ref``/``path`` in one lookup.

    This is the batch-backed replacement for the old ``cat-file -t`` + ``-s``
    fork pair; callers get the type (for routing), the size and — crucially for
    ETag/permalink support — the object's own sha from a single request.
    """
    return _catfile(repo.path).check(object_spec(ref, path))


# --------------------------------------------------------------------------- #
# Repository discovery / allow-listing
# --------------------------------------------------------------------------- #

_DEFAULT_DESC = "Unnamed repository; edit this file 'description' to name the repository."


@dataclass
class Repo:
    """A discovered repository under the configured root."""

    name: str  # URL id == directory name
    path: str  # absolute realpath used as ``git -C``
    bare: bool
    description: str = ""
    last_commit_ts: Optional[int] = None


def _is_bare_repo(path: str) -> bool:
    return (
        os.path.isdir(os.path.join(path, "objects"))
        and os.path.isdir(os.path.join(path, "refs"))
        and os.path.isfile(os.path.join(path, "HEAD"))
    )


def _is_worktree_repo(path: str) -> bool:
    dotgit = os.path.join(path, ".git")
    return os.path.isdir(dotgit) or os.path.isfile(dotgit)


def _repo_kind(path: str) -> Optional[bool]:
    """Return ``True`` if bare, ``False`` if a normal worktree, else ``None``."""
    if _is_worktree_repo(path):
        return False
    if _is_bare_repo(path):
        return True
    return None


def _read_description(path: str, bare: bool) -> str:
    desc_file = (
        os.path.join(path, "description")
        if bare
        else os.path.join(path, ".git", "description")
    )
    try:
        with open(desc_file, "r", encoding="utf-8", errors="replace") as fh:
            text = fh.read().strip()
    except OSError:
        return ""
    if not text or text == _DEFAULT_DESC:
        return ""
    return text


def _last_commit_ts(path: str) -> Optional[int]:
    rc, out, _ = run_git(path, ["log", "-1", "--format=%ct", "HEAD"])
    if rc != 0:
        return None
    raw = _text(out).strip()
    return int(raw) if raw.isdigit() else None


# Cache of last-commit timestamps.  The homepage previously forked ``git log``
# once per repository on every load; here each result is memoised per repo and
# invalidated when the ref store changes (mtime signature) or a short TTL lapses.
_TS_CACHE: Dict[str, Tuple[tuple, Optional[int], float]] = {}
_TS_LOCK = threading.Lock()
_TS_TTL = 60.0  # seconds; upper bound on staleness when refs mtime is unchanged


def _refs_signature(path: str, bare: bool) -> tuple:
    """A cheap fingerprint of the repo's ref store (mtimes of ref locations)."""
    base = path if bare else os.path.join(path, ".git")
    sig: List[int] = []
    for rel in ("packed-refs", "HEAD", "refs", os.path.join("refs", "heads")):
        try:
            sig.append(os.stat(os.path.join(base, rel)).st_mtime_ns)
        except OSError:
            sig.append(0)
    return tuple(sig)


def cached_last_commit_ts(path: str, bare: bool) -> Optional[int]:
    """Memoised :func:`_last_commit_ts`, keyed on the ref-store signature.

    Returns the cached value while the ref store is unchanged and the TTL has
    not lapsed, otherwise recomputes.  This removes the N-forks-per-homepage
    cliff and the redundant fork on every per-repo request.
    """
    now = time.monotonic()
    sig = _refs_signature(path, bare)
    with _TS_LOCK:
        ent = _TS_CACHE.get(path)
        if ent is not None and ent[0] == sig and (now - ent[2]) < _TS_TTL:
            return ent[1]
    ts = _last_commit_ts(path)
    with _TS_LOCK:
        _TS_CACHE[path] = (sig, ts, now)
    return ts


def discover_repos(root: str) -> List[Repo]:
    """Scan ``root`` (one level deep) for bare and normal git repositories."""
    repos: List[Repo] = []
    root_real = os.path.realpath(root)
    try:
        names = sorted(os.listdir(root_real))
    except OSError:
        return repos
    for name in names:
        if name.startswith("."):
            continue
        real = os.path.realpath(os.path.join(root_real, name))
        # Confinement: the entry must resolve to a *direct* child of the root.
        # This rejects a symlink placed under the root that points elsewhere,
        # matching resolve_repo and preventing us from running git outside it.
        if os.path.dirname(real) != root_real:
            continue
        if not os.path.isdir(real):
            continue
        bare = _repo_kind(real)
        if bare is None:
            continue
        repos.append(
            Repo(
                name=name,
                path=real,
                bare=bare,
                description=_read_description(real, bare),
                last_commit_ts=cached_last_commit_ts(real, bare),
            )
        )
    return repos


def resolve_repo(root: str, name: str) -> Repo:
    """Resolve a URL repo id to a :class:`Repo`, enforcing the allow-list.

    Raises :class:`BadRequest` for a malformed name and :class:`NotFound` if
    the resolved directory is not a git repo directly under ``root``.
    """
    if not valid_repo_name(name):
        raise BadRequest("invalid repository name")

    root_real = os.path.realpath(root)
    candidate = os.path.realpath(os.path.join(root_real, name))

    # Must live *directly* under the root (no traversal, no nesting).
    if os.path.dirname(candidate) != root_real:
        raise NotFound("no such repository")
    if not os.path.isdir(candidate):
        raise NotFound("no such repository")

    bare = _repo_kind(candidate)
    if bare is None:
        raise NotFound("no such repository")

    return Repo(
        name=name,
        path=candidate,
        bare=bare,
        description=_read_description(candidate, bare),
        last_commit_ts=cached_last_commit_ts(candidate, bare),
    )


# --------------------------------------------------------------------------- #
# High-level read operations
# --------------------------------------------------------------------------- #


def default_branch(repo: Repo) -> str:
    """Best-effort name of the repository's default branch."""
    rc, out, _ = run_git(repo.path, ["symbolic-ref", "--short", "HEAD"])
    name = _text(out).strip()
    if rc == 0 and name:
        return name
    rc, out, _ = run_git(repo.path, ["rev-parse", "--abbrev-ref", "HEAD"])
    name = _text(out).strip()
    return name or "HEAD"


def resolve_commit(repo: Repo, ref: str) -> str:
    """Resolve ``ref`` to a full commit sha for sha-pinned permalinks.

    Uses the batch-check reader (peeling annotated tags with ``^{commit}``);
    falls back to the ref's own object id, then to ``ref`` itself.  ``ref`` is
    caller-validated, and the peel suffix is added server-side.
    """
    st = _catfile(repo.path).check(f"{ref}^{{commit}}")
    if st is not None:
        return st.sha
    st = _catfile(repo.path).check(ref)
    return st.sha if st is not None else ref


def ref_names(repo: Repo) -> Tuple[List[str], List[str]]:
    """Return ``(branch_names, tag_names)`` in one ``for-each-ref`` fork."""
    rc, out, _ = run_git(
        repo.path,
        ["for-each-ref", "--format=%(refname)", "refs/heads/", "refs/tags/"],
    )
    branches: List[str] = []
    tags_: List[str] = []
    if rc == 0:
        for line in _text(out).split("\n"):
            if line.startswith("refs/heads/"):
                branches.append(line[len("refs/heads/") :])
            elif line.startswith("refs/tags/"):
                tags_.append(line[len("refs/tags/") :])
    return branches, tags_


def ref_exists(repo: Repo, ref: str) -> bool:
    """True if ``ref`` resolves to an object in ``repo``."""
    rc, _out, _err = run_git(repo.path, ["rev-parse", "--verify", "--quiet", f"{ref}^{{commit}}"])
    if rc == 0:
        return True
    # Fall back for non-commit objects (e.g. a raw tree/blob sha).
    rc, _out, _err = run_git(repo.path, ["rev-parse", "--verify", "--quiet", ref])
    return rc == 0


@dataclass
class CommitRow:
    """One row in a log listing."""

    sha: str
    short: str
    author: str
    email: str
    ts: int
    subject: str


def _parse_log_records(out: bytes) -> List[CommitRow]:
    rows: List[CommitRow] = []
    for chunk in out.split(_NUL):
        if not chunk:
            continue
        fields = _text(chunk).split(FIELD_SEP)
        if len(fields) < 6:
            continue
        sha, short, author, email, ts, subject = fields[:6]
        rows.append(
            CommitRow(
                sha=sha,
                short=short,
                author=author,
                email=email,
                ts=int(ts) if ts.isdigit() else 0,
                subject=subject,
            )
        )
    return rows


def log(repo: Repo, ref: str, skip: int = 0, limit: int = 50) -> List[CommitRow]:
    """Return up to ``limit`` commits starting at ``skip`` for ``ref``."""
    fmt = FIELD_SEP.join(["%H", "%h", "%an", "%ae", "%ct", "%s"])
    rc, out, err = run_git(
        repo.path,
        ["log", f"--skip={skip}", f"-n{limit}", "-z", f"--format={fmt}", ref, "--"],
    )
    if rc != 0:
        raise NotFound("no such ref")
    return _parse_log_records(out)


def commit_count(repo: Repo, ref: str) -> int:
    """Total number of commits reachable from ``ref`` (for pagination)."""
    rc, out, _ = run_git(repo.path, ["rev-list", "--count", ref, "--"])
    raw = _text(out).strip()
    return int(raw) if rc == 0 and raw.isdigit() else 0


@dataclass
class GraphCommit:
    """A log row plus its parent shas, for drawing the commit graph."""

    sha: str
    short: str
    parents: List[str]
    author: str
    ts: int
    subject: str


def log_graph(repo: Repo, ref: str, skip: int = 0, limit: int = 50) -> List[GraphCommit]:
    """Like :func:`log`, but also captures each commit's parent shas (``%P``).

    Records are NUL-separated (``-z``) and fields within a record use the
    FIELD_SEP byte; the parents field is the space-separated ``%P`` list.
    Bounded to ``limit`` rows so the graph a caller draws is page-sized.
    """
    fmt = FIELD_SEP.join(["%H", "%h", "%P", "%an", "%ct", "%s"])
    rc, out, _ = run_git(
        repo.path,
        ["log", f"--skip={skip}", f"-n{limit}", "-z", f"--format={fmt}", ref, "--"],
    )
    if rc != 0:
        raise NotFound("no such ref")
    rows: List[GraphCommit] = []
    for chunk in out.split(_NUL):
        if not chunk:
            continue
        fields = _text(chunk).split(FIELD_SEP)
        if len(fields) < 6:
            continue
        sha, short, parents_s, author, ts, subject = fields[:6]
        rows.append(
            GraphCommit(
                sha=sha,
                short=short,
                parents=parents_s.split(),
                author=author,
                ts=int(ts) if ts.isdigit() else 0,
                subject=subject,
            )
        )
    return rows


def log_path(
    repo: Repo,
    ref: str,
    path: str,
    skip: int = 0,
    limit: int = 50,
    follow: bool = False,
) -> List[CommitRow]:
    """Commits touching ``path`` on ``ref`` (per-file history).

    ``ref`` and ``path`` must already be validated by the caller; the pathspec
    is separated from options with ``--``.  ``follow`` enables rename tracking
    (git only allows it for a single path).
    """
    fmt = FIELD_SEP.join(["%H", "%h", "%an", "%ae", "%ct", "%s"])
    args = ["log", f"--skip={skip}", f"-n{limit}", "-z", f"--format={fmt}"]
    if follow:
        args.append("--follow")
    args += [ref, "--", path]
    rc, out, _ = run_git(repo.path, args)
    if rc != 0:
        raise NotFound("no such ref/path")
    return _parse_log_records(out)


def commit_count_path(repo: Repo, ref: str, path: str) -> int:
    """Number of commits on ``ref`` that touch ``path`` (for pagination)."""
    rc, out, _ = run_git(repo.path, ["rev-list", "--count", ref, "--", path])
    raw = _text(out).strip()
    return int(raw) if rc == 0 and raw.isdigit() else 0


# ---- search (code + commit message) -------------------------------------- #


@dataclass
class GrepMatch:
    """One ``git grep`` hit: a file path, a 1-based line number and the line."""

    path: str
    lineno: int
    text: str


def search_code(
    repo: Repo,
    ref: str,
    query: str,
    max_matches: int = GREP_MAX_MATCHES,
) -> Tuple[List[GrepMatch], bool]:
    """Literal code search over the tree at ``ref``; returns ``(matches, more)``.

    Runs ``git grep -n -I --fixed-strings -e <query> <ref> --``:

    * ``--fixed-strings`` makes ``<query>`` a *literal* — never a regex — so a
      crafted term cannot trigger catastrophic backtracking (ReDoS).
    * ``-e <query>`` passes the term as the operand of ``-e``, so a term that
      begins with ``-`` (e.g. ``-n`` or ``--output``) is data, never an option.
    * ``-I`` skips binary files; ``--max-count`` caps per-file hits; the shared
      capped/killed reader bounds total stdout (:data:`GREP_MAX_BYTES`) and wall
      time (:data:`GREP_TIMEOUT`); parsing stops at ``max_matches``.  ``more`` is
      ``True`` when any of those caps clipped the result.

    ``ref`` is caller-validated and ``query`` must satisfy :func:`valid_query`.
    ``-z`` makes git separate the ``<path>`` from the line number and text with
    NUL (only the leading ``<ref>:`` keeps a colon), so a path that itself
    contains a colon still parses unambiguously.
    """
    args = [
        "grep",
        "-n",
        "-I",
        "--fixed-strings",
        "-z",
        f"--max-count={GREP_MAX_COUNT_PER_FILE}",
        "-e",
        query,
        ref,
        "--",
    ]
    rc, out, _ = run_git(
        repo.path, args, timeout=GREP_TIMEOUT, max_bytes=GREP_MAX_BYTES
    )
    # git grep exit codes: 0 = matches, 1 = no matches, >1 = real error (e.g. a
    # non-existent ref).  Only >1 means "nothing to show for a reason"; treat it
    # as an empty result rather than surfacing a 500.  ``run_git`` already reset
    # the code to 0 if it truncated at the byte cap.
    if rc > 1:
        return [], False
    prefix = ref + ":"
    matches: List[GrepMatch] = []
    truncated = False
    for record in out.split(b"\n"):
        if not record:
            continue
        if len(matches) >= max_matches:
            truncated = True
            break
        fields = record.split(_NUL)
        if len(fields) < 3:
            continue
        raw_path = _text(fields[0])
        # Strip the echoed ``<ref>:`` prefix to recover the in-repo path.
        path = raw_path[len(prefix):] if raw_path.startswith(prefix) else raw_path
        lineno_s = _text(fields[1])
        if not lineno_s.isdigit():
            continue
        text = _text(_NUL.join(fields[2:]))
        matches.append(GrepMatch(path=path, lineno=int(lineno_s), text=text))
    else:
        # Loop finished without break: the byte cap may still have clipped the
        # last (partial) record, which we conservatively flag as "more".
        if len(out) >= GREP_MAX_BYTES:
            truncated = True
    return matches, truncated


def log_grep(
    repo: Repo, ref: str, query: str, skip: int = 0, limit: int = 50
) -> List[CommitRow]:
    """Commit-message search: commits on ``ref`` whose message contains ``query``.

    ``--fixed-strings`` + ``--grep=<query>`` matches the term literally (no
    regex/ReDoS); the term is a single argv element so it can never be an
    option.  Paginated exactly like :func:`log`.
    """
    fmt = FIELD_SEP.join(["%H", "%h", "%an", "%ae", "%ct", "%s"])
    args = [
        "log",
        f"--skip={skip}",
        f"-n{limit}",
        "-z",
        "--fixed-strings",
        f"--grep={query}",
        f"--format={fmt}",
        ref,
        "--",
    ]
    rc, out, _ = run_git(repo.path, args)
    if rc != 0:
        return []
    return _parse_log_records(out)


def commit_count_grep(repo: Repo, ref: str, query: str) -> int:
    """Number of commits on ``ref`` whose message contains ``query`` (pager)."""
    rc, out, _ = run_git(
        repo.path,
        ["rev-list", "--count", "--fixed-strings", f"--grep={query}", ref, "--"],
    )
    raw = _text(out).strip()
    return int(raw) if rc == 0 and raw.isdigit() else 0


def compare(repo: Repo, base: str, other: str) -> str:
    """Unified diff between two commit-ish refs, via the ``diff-tree`` plumbing.

    Both refs must already be validated; they are passed as an argument vector
    and (being validated) cannot be option-like.
    """
    rc, out, _ = run_git(
        repo.path,
        ["diff-tree", "--patch", "-r", "-M", "--no-color", base, other, "--"],
    )
    if rc != 0:
        raise NotFound("cannot compare those refs")
    return _text(out).lstrip("\n")


def stream_archive(
    repo: Repo,
    ref: str,
    prefix: str,
    chunk_size: int = 65536,
    max_bytes: int = 0,
) -> Iterator[bytes]:
    """Yield a ``git archive`` ``tar.gz`` stream for ``ref`` in bounded chunks.

    ``ref`` is validated by the caller and ``prefix`` is sanitised to a
    filename-safe token upstream; both are passed as argv (never a shell).  The
    child is torn down when the generator closes; ``max_bytes`` (> 0) caps the
    bytes produced.
    """
    cmd = [
        *_git_base_cmd(repo.path),
        "archive",
        "--format=tar.gz",
        f"--prefix={prefix}",
        ref,
        "--",  # terminate options; ref is the tree-ish, no pathspecs follow
    ]
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        stdin=subprocess.DEVNULL,
        env=_git_env(),
        # Own session/process group: teardown group-kills any child (git archive
        # has no wall-clock timeout, so its teardown must reap the whole subtree).
        start_new_session=True,
    )
    sent = 0
    try:
        assert proc.stdout is not None
        while True:
            data = proc.stdout.read(chunk_size)
            if not data:
                break
            if max_bytes and sent + len(data) > max_bytes:
                yield data[: max_bytes - sent]
                break
            sent += len(data)
            yield data
    finally:
        if proc.stdout is not None:
            proc.stdout.close()
        _kill_process_group(proc)
        proc.wait()


@dataclass
class Commit:
    """Full metadata for a single commit."""

    sha: str
    short: str
    author_name: str
    author_email: str
    author_date: str
    committer_name: str
    committer_email: str
    committer_date: str
    parents: List[str]
    subject: str
    body: str
    signature_status: str = "N"  # git %G? : N=none, G=good, U=good/unknown, B=bad, ...
    signing_key: str = ""  # git %GK

    @property
    def signature_verified(self) -> bool:
        """A cryptographically good signature (valid, or valid-but-unknown)."""
        return self.signature_status in ("G", "U")

    @property
    def signature_present(self) -> bool:
        return self.signature_status not in ("", "N")


def commit_meta(repo: Repo, rev: str) -> Commit:
    """Return metadata for the commit ``rev`` (a validated ref/sha)."""
    # ``%b`` (body) is placed before the two signature fields; neither the body
    # nor the signature fields ever contain the FIELD_SEP byte, so positional
    # splitting stays unambiguous.  ``log.showSignature=false`` (base cmd) keeps
    # any signature block out of the formatted output.
    fmt = FIELD_SEP.join(
        [
            "%H", "%h", "%an", "%ae", "%aI", "%cn", "%ce", "%cI",
            "%P", "%s", "%b", "%G?", "%GK",
        ]
    )
    rc, out, _ = run_git(repo.path, ["show", "-s", f"--format={fmt}", rev, "--"])
    if rc != 0 or not out:
        raise NotFound("no such commit")
    fields = _text(out).split(FIELD_SEP)
    if len(fields) < 13:
        raise NotFound("no such commit")
    parents = fields[8].split()
    return Commit(
        sha=fields[0],
        short=fields[1],
        author_name=fields[2],
        author_email=fields[3],
        author_date=fields[4],
        committer_name=fields[5],
        committer_email=fields[6],
        committer_date=fields[7],
        parents=parents,
        subject=fields[9],
        body=fields[10].rstrip("\n"),
        signature_status=fields[11].strip() or "N",
        signing_key=fields[12].strip(),
    )


def commit_patch(repo: Repo, rev: str) -> str:
    """Return the unified diff for ``rev`` as text (empty header)."""
    rc, out, _ = run_git(
        repo.path,
        ["show", "--patch", "--no-color", "-M", "--format=", rev, "--"],
    )
    if rc != 0:
        raise NotFound("no such commit")
    return _text(out).lstrip("\n")


def format_patch(
    repo: Repo,
    rev: str,
    *,
    timeout: int = PATCH_TIMEOUT,
    max_bytes: int = PATCH_MAX_BYTES,
) -> bytes:
    """Return the mailbox-format patch for a single commit ``rev`` (for ``git am``).

    Runs ``git format-patch -1 --stdout <rev> --``; the output opens with a
    ``From <sha> Mon Sep 17 00:00:00 2001`` mbox header.  ``rev`` is
    caller-validated and separated from options with ``--``.  Output is drained
    through the shared capped/killed reader, so a pathological commit can exceed
    neither ``max_bytes`` of RAM nor ``timeout`` seconds.  Returns raw bytes so
    the patch is served verbatim.  An unknown rev (git error) or a commit that
    serialises to nothing is reported as :class:`NotFound`.  (For a merge,
    ``format-patch -1`` follows git's own convention and emits the first-parent
    change; a merge has no single ``git am``-able patch of its own.)
    """
    rc, out, err = run_git(
        repo.path,
        ["format-patch", "-1", "--stdout", rev, "--"],
        timeout=timeout,
        max_bytes=max_bytes,
    )
    if rc != 0 or not out:
        raise NotFound("no patch for this commit (unknown, or a merge)")
    return out


# ---- tree ---------------------------------------------------------------- #


@dataclass
class TreeEntry:
    mode: str
    type: str  # blob | tree | commit
    sha: str
    size: Optional[int]
    name: str  # basename
    path: str  # full path from repo root


def list_tree(repo: Repo, ref: str, path: str) -> List[TreeEntry]:
    """List the immediate children of the tree at ``ref``/``path``."""
    spec = object_spec(ref, path)
    rc, out, _ = run_git(repo.path, ["ls-tree", "--long", "-z", spec, "--"])
    if rc != 0:
        raise NotFound("no such tree")
    entries: List[TreeEntry] = []
    for chunk in out.split(_NUL):
        if not chunk:
            continue
        text = _text(chunk)
        try:
            meta, name = text.split("\t", 1)
        except ValueError:
            continue
        parts = meta.split()
        if len(parts) < 4:
            continue
        mode, otype, sha, size = parts[0], parts[1], parts[2], parts[3]
        entries.append(
            TreeEntry(
                mode=mode,
                type=otype,
                sha=sha,
                size=int(size) if size.isdigit() else None,
                name=name,
                path=f"{path}/{name}" if path else name,
            )
        )
    # Directories first, then files, each alphabetically.
    entries.sort(key=lambda e: (e.type != "tree", e.name.lower()))
    return entries


# ---- blob ---------------------------------------------------------------- #


def object_type(repo: Repo, ref: str, path: str) -> Optional[str]:
    """Return the git object type at ``ref``/``path`` or ``None`` if absent."""
    stat = stat_object(repo, ref, path)
    return stat.type if stat else None


def blob_size(repo: Repo, ref: str, path: str) -> int:
    stat = stat_object(repo, ref, path)
    return stat.size if stat else 0


def read_blob(repo: Repo, ref: str, path: str, max_bytes: int) -> bytes:
    """Read up to ``max_bytes`` of a blob's content into memory.

    Routed through the persistent ``cat-file --batch`` reader; a blob larger
    than ``max_bytes`` is capped (and the batch process respawned) so memory
    stays bounded exactly as the old streamed reader guaranteed.
    """
    data, _stat, _trunc = _catfile(repo.path).read(object_spec(ref, path), max_bytes)
    if data is None:
        raise NotFound("no such blob")
    return data


def _parse_gitmodules(text: str) -> dict:
    """Parse ``.gitmodules`` INI text into a ``{submodule_path: url}`` map."""
    result: dict = {}
    cur_path: Optional[str] = None
    cur_url: Optional[str] = None

    def flush():
        if cur_path:
            result[cur_path] = cur_url or ""

    for raw in text.split("\n"):
        line = raw.strip()
        if line.startswith("[") and line.endswith("]"):
            flush()
            cur_path = None
            cur_url = None
            continue
        if "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip().lower()
        value = value.strip()
        if key == "path":
            cur_path = value
        elif key == "url":
            cur_url = value
    flush()
    return result


def read_gitmodules(repo: Repo, ref: str) -> dict:
    """Return the ``{path: url}`` submodule map for ``ref`` (or ``{}``)."""
    data, stat, _ = _catfile(repo.path).read(f"{ref}:.gitmodules", 256 * 1024)
    if not data or stat is None or stat.type != "blob":
        return {}
    return _parse_gitmodules(_text(data))


@dataclass
class LFSPointer:
    """A parsed git-lfs pointer (the small text file that stands in for a blob)."""

    oid: str
    size: int


_LFS_OID_RE = re.compile(r"^[0-9a-f]{64}$")


def parse_lfs_pointer(data: bytes) -> Optional[LFSPointer]:
    """Detect a git-lfs pointer file and return its oid/size, else ``None``.

    A pointer is a tiny UTF-8 file beginning with the LFS spec version line and
    carrying ``oid sha256:<hex>`` and ``size <n>`` fields.  The oid is validated
    as 64 lowercase hex so it can never be turned into a traversal path.
    """
    if not data or len(data) > 1024:
        return None
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError:
        return None
    if not text.startswith("version https://git-lfs.github.com/spec/"):
        return None
    oid = ""
    size = -1
    for line in text.splitlines():
        if line.startswith("oid sha256:"):
            oid = line[len("oid sha256:") :].strip()
        elif line.startswith("size "):
            raw = line[len("size ") :].strip()
            if raw.isdigit():
                size = int(raw)
    if not oid or size < 0 or not _LFS_OID_RE.match(oid):
        return None
    return LFSPointer(oid=oid, size=size)


def lfs_object_path(repo: Repo, oid: str) -> Optional[str]:
    """Return the local path of an LFS object, or ``None`` if not stored locally.

    Git-LFS lays objects out at ``lfs/objects/<oid[:2]>/<oid[2:4]>/<oid>`` under
    the git dir — ``<repo>/lfs/...`` for a bare repo and ``<repo>/.git/lfs/...``
    for a worktree.  The oid is validated as 64 lowercase hex (no ``/`` or
    ``..``), and each candidate is ``realpath``-confined under the repo so a
    symlinked ``lfs`` tree cannot point the read outside the repository.  This
    never contacts a remote LFS server — a missing object simply returns
    ``None`` and the caller keeps showing the pointer.
    """
    if not oid or not _LFS_OID_RE.match(oid):
        return None
    rel = os.path.join("lfs", "objects", oid[0:2], oid[2:4], oid)
    repo_real = os.path.realpath(repo.path)
    for base in (repo_real, os.path.join(repo_real, ".git")):
        real = os.path.realpath(os.path.join(base, rel))
        if real != repo_real and not real.startswith(repo_real + os.sep):
            continue  # confinement: must resolve to somewhere under the repo
        if os.path.isfile(real):
            return real
    return None


def lfs_object_size(path: str) -> int:
    """Size in bytes of a local file (0 if it cannot be stat'd)."""
    try:
        return os.path.getsize(path)
    except OSError:
        return 0


def read_file(path: str, max_bytes: int) -> bytes:
    """Read up to ``max_bytes`` bytes of a local (confined) file."""
    if max_bytes <= 0:
        return b""
    try:
        with open(path, "rb") as fh:
            return fh.read(max_bytes)
    except OSError:
        return b""


def peek_file(path: str, n: int = 8192) -> bytes:
    """Return at most ``n`` bytes of a local file, for binary sniffing."""
    return read_file(path, n)


def stream_file(
    path: str, chunk_size: int = 65536, max_bytes: int = 0
) -> Iterator[bytes]:
    """Yield a local file's bytes in bounded chunks (for serving an LFS object).

    No subprocess is involved — this is a plain filesystem read of a path the
    caller has already confined via :func:`lfs_object_path`.  ``max_bytes`` (> 0)
    caps the bytes produced; closing the generator closes the file.
    """
    sent = 0
    with open(path, "rb") as fh:
        while True:
            data = fh.read(chunk_size)
            if not data:
                break
            if max_bytes and sent + len(data) > max_bytes:
                yield data[: max_bytes - sent]
                break
            sent += len(data)
            yield data


def stream_blob(
    repo: Repo, ref: str, path: str, chunk_size: int = 65536, max_bytes: int = 0
) -> Iterator[bytes]:
    """Yield a blob's bytes in chunks (for the /raw endpoint).

    The git child is torn down when the generator is closed or exhausted.  If
    ``max_bytes`` > 0 the stream stops after that many bytes.
    """
    spec = object_spec(ref, path)
    cmd = [
        GIT,
        "--no-pager",
        "-c",
        "safe.directory=*",
        "-C",
        repo.path,
        "cat-file",
        "-p",
        "--",  # terminate options so a validated spec can never be read as one
        spec,
    ]
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        stdin=subprocess.DEVNULL,
        env=_git_env(),
        # Own session/process group so teardown group-kills the whole subtree.
        start_new_session=True,
    )
    sent = 0
    try:
        assert proc.stdout is not None
        while True:
            data = proc.stdout.read(chunk_size)
            if not data:
                break
            if max_bytes and sent + len(data) > max_bytes:
                yield data[: max_bytes - sent]
                break
            sent += len(data)
            yield data
    finally:
        if proc.stdout is not None:
            proc.stdout.close()
        _kill_process_group(proc)
        proc.wait()


def peek_blob(repo: Repo, ref: str, path: str, n: int = 8192) -> bytes:
    """Return at most ``n`` bytes of a blob, for binary sniffing.

    Backed by the persistent ``cat-file --batch`` reader with a hard ``n``-byte
    cap: peeking at a multi-gigabyte blob still costs ~``n`` bytes because the
    reader stops (and respawns git) the moment the cap is reached.
    """
    if n <= 0:
        return b""
    data, _stat, _trunc = _catfile(repo.path).read(object_spec(ref, path), n)
    if not data:
        return b""
    return data[:n]


def is_binary(data: bytes) -> bool:
    """Heuristic: a NUL byte in the first 8 KiB means binary."""
    return b"\x00" in data[:8192]


# ---- refs ---------------------------------------------------------------- #


@dataclass
class RefRow:
    name: str
    kind: str  # "branch" | "tag"
    target: str  # short sha
    subject: str
    ts: int
    author: str


def _parse_ref_records(out: bytes, kind: str) -> List[RefRow]:
    rows: List[RefRow] = []
    for line in _text(out).split("\n"):
        if not line:
            continue
        fields = line.split(FIELD_SEP)
        if len(fields) < 5:
            continue
        name, target, subject, ts, author = fields[:5]
        rows.append(
            RefRow(
                name=name,
                kind=kind,
                target=target,
                subject=subject,
                ts=int(ts) if ts.isdigit() else 0,
                author=author,
            )
        )
    return rows


def for_each_ref(repo: Repo, pattern: str, kind: str) -> List[RefRow]:
    fmt = FIELD_SEP.join(
        [
            "%(refname:short)",
            "%(objectname:short)",
            "%(contents:subject)",
            "%(creatordate:unix)",
            "%(authorname)",
        ]
    )
    rc, out, _ = run_git(
        repo.path,
        ["for-each-ref", "--sort=-creatordate", f"--format={fmt}", pattern],
    )
    if rc != 0:
        return []
    return _parse_ref_records(out, kind)


def branches(repo: Repo) -> List[RefRow]:
    return for_each_ref(repo, "refs/heads/", "branch")


def tags(repo: Repo) -> List[RefRow]:
    return for_each_ref(repo, "refs/tags/", "tag")


# ---- blame --------------------------------------------------------------- #


@dataclass
class BlameLine:
    short: str
    author: str
    lineno: int
    content: str


_BLAME_HEADER_RE = re.compile(r"^([0-9a-f]{40}) (\d+) (\d+)(?: (\d+))?$")


def blame(repo: Repo, ref: str, path: str) -> List[BlameLine]:
    """Parse ``git blame --porcelain`` into per-line records."""
    rc, out, _ = run_git(repo.path, ["blame", "--porcelain", ref, "--", path])
    if rc != 0:
        raise NotFound("cannot blame path")

    lines = _text(out).split("\n")
    authors: dict = {}
    result: List[BlameLine] = []
    i = 0
    n = len(lines)
    while i < n:
        header = lines[i]
        i += 1
        m = _BLAME_HEADER_RE.match(header)
        if not m:
            continue
        sha = m.group(1)
        final_lineno = int(m.group(3))
        author = authors.get(sha)
        # Consume the optional metadata block up to the tab-prefixed content.
        while i < n and not lines[i].startswith("\t"):
            meta = lines[i]
            i += 1
            if meta.startswith("author "):
                author = meta[len("author ") :]
        content = ""
        if i < n and lines[i].startswith("\t"):
            content = lines[i][1:]
            i += 1
        if author is not None:
            authors.setdefault(sha, author)
        result.append(
            BlameLine(
                short=sha[:8],
                author=authors.get(sha, author or ""),
                lineno=final_lineno,
                content=content,
            )
        )
    return result


# --------------------------------------------------------------------------- #
# Git Smart HTTP (read-only clone / fetch) transport
# --------------------------------------------------------------------------- #
#
# The clone/fetch endpoints run *only* ``git upload-pack`` — the read side of
# the pack protocol.  Every invocation:
#   * uses the argv-only, hardened form (no shell; global/system config off);
#   * pins the repo as an explicit positional path, after ``--`` so it can
#     never be read as an option (though a server-resolved realpath is never
#     option-like anyway);
#   * pins config that keeps the transport read-only and *default-deny*:
#       - ``uploadpack.allowFilter=false``  -> no partial-clone object filters;
#       - ``uploadpack.allow*SHA1InWant=false`` -> a client can only fetch
#         objects reachable from an advertised ref, never an arbitrary
#         unreferenced object it happens to know the sha of.
#
# ``receive-pack`` (push) is never constructed here.

#: ``-c`` hardening shared by every ``upload-pack`` invocation.
_UPLOAD_PACK_CONFIG = [
    "-c", "safe.directory=*",
    "-c", "uploadpack.allowFilter=false",
    "-c", "uploadpack.allowAnySHA1InWant=false",
    "-c", "uploadpack.allowReachableSHA1InWant=false",
    "-c", "uploadpack.allowTipSHA1InWant=false",
]


def _upload_pack_cmd(repo_path: str, *, advertise: bool) -> List[str]:
    cmd = [GIT, *_UPLOAD_PACK_CONFIG, "upload-pack", "--stateless-rpc"]
    if advertise:
        cmd.append("--advertise-refs")
    cmd += ["--", repo_path]
    return cmd


def _protocol_env(protocol_v2: bool) -> Optional[dict]:
    return {"GIT_PROTOCOL": "version=2"} if protocol_v2 else None


def upload_pack_advertise(
    repo: Repo,
    *,
    protocol_v2: bool = False,
    timeout: int = UPLOAD_PACK_TIMEOUT,
    max_bytes: int = UPLOAD_PACK_ADVERTISE_MAX_BYTES,
) -> bytes:
    """Return the ref advertisement for ``GET /<repo>/info/refs``.

    Runs ``git upload-pack --stateless-rpc --advertise-refs <repo>`` and returns
    its raw stdout (the pkt-line advertisement).  The output is drained through
    the shared capped/killed reader, so a runaway or pathological repo can
    neither exceed ``max_bytes`` of memory nor ``timeout`` seconds of wall time.
    The HTTP layer prepends the ``# service=git-upload-pack`` banner (protocol
    v0/v1 only — v2 has no banner).
    """
    cmd = _upload_pack_cmd(repo.path, advertise=True)
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        stdin=subprocess.DEVNULL,
        env=_git_env(_protocol_env(protocol_v2)),
        # Own session/process group so a timeout kill reaps the whole subtree.
        start_new_session=True,
    )
    out, err, _capped, timed_out = _capture_capped(proc, max_bytes, timeout)
    if timed_out:
        raise GitError("git upload-pack (advertise) timed out")
    if not out:
        raise GitError(
            "git upload-pack produced no advertisement: "
            f"{err.decode('utf-8', 'replace').strip()}"
        )
    return out


def upload_pack_rpc(
    repo: Repo,
    payload: bytes,
    *,
    protocol_v2: bool = False,
    timeout: int = UPLOAD_PACK_TIMEOUT,
    chunk_size: int = 65536,
) -> Iterator[bytes]:
    """Stream the ``POST /<repo>/git-upload-pack`` result.

    Runs ``git upload-pack --stateless-rpc <repo>``, feeds the (already read,
    size-bounded and — if the client gzipped it — inflated) request ``payload``
    to git's stdin on a writer thread, and yields git's stdout in ``chunk_size``
    pieces.  Yielding straight to the socket means a pack of *any* size streams
    with only a chunk resident (packs are never buffered in RAM).

    An overall wall-clock ``timeout`` bounds pack generation: a hostile want /
    deepen set that would make git churn is killed on the deadline.  Closing the
    generator (client disconnect, or the deadline) tears the child down.
    """
    cmd = _upload_pack_cmd(repo.path, advertise=False)
    proc = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        stdin=subprocess.PIPE,
        env=_git_env(_protocol_env(protocol_v2)),
        # Own session/process group.  ``upload-pack`` forks ``pack-objects`` (the
        # heavy step); a bare kill on timeout/abort would orphan it to keep
        # running.  Teardown group-kills so the whole subtree dies.
        start_new_session=True,
    )

    # Feed stdin on a separate thread: git may start emitting the pack (filling
    # its stdout pipe) before it has consumed all of stdin, so writing the whole
    # request inline could deadlock.  ``payload`` is already size-capped, so the
    # thread cannot buffer an unbounded amount.
    def _feed() -> None:
        try:
            if payload:
                proc.stdin.write(payload)
            proc.stdin.close()
        except (BrokenPipeError, OSError, ValueError):  # pragma: no cover - client aborted
            # ValueError covers the teardown race: the main thread may close
            # proc.stdin concurrently, so a write/close here can hit a closed
            # BufferedWriter ("I/O operation on closed file") — swallow it.
            pass

    writer = threading.Thread(target=_feed, daemon=True)
    writer.start()

    deadline = time.monotonic() + timeout
    sel = selectors.DefaultSelector()
    assert proc.stdout is not None
    sel.register(proc.stdout, selectors.EVENT_READ)
    fd = proc.stdout.fileno()
    try:
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                break  # wall-clock timeout -> child killed in finally
            if not sel.select(remaining):
                break  # timed out waiting for output
            try:
                data = os.read(fd, chunk_size)
            except (BlockingIOError, InterruptedError):  # pragma: no cover
                continue
            if not data:
                break  # clean EOF
            yield data
    finally:
        sel.close()
        for stream in (proc.stdin, proc.stdout):
            if stream is not None:
                try:
                    stream.close()
                except OSError:  # pragma: no cover - defensive
                    pass
        _kill_process_group(proc)
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:  # pragma: no cover - defensive
            _kill_process_group(proc)
            proc.wait()
        writer.join(timeout=1)
