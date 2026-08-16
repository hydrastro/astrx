//! Read-only git plumbing — the argv-only, root-confined `git` exec tier.
//!
//! Every function here shells out to the `git` binary through
//! [`std::process::Command`] with an explicit **argument vector**; there is no
//! shell anywhere in this module, so a branch, path, ref or search term
//! containing `;`, `|`, `` ` ``, `$(…)` or a newline is inert data. Nothing in
//! here writes to a repository.
//!
//! # The confinement invariant
//!
//! The crown-jewel property is: *git only ever runs against a directory that a
//! validator proved is a direct child of the configured root, and every
//! URL-derived value reaching an argv slot has been through its validator.*
//! It is modelled in the types rather than left to caller discipline (the same
//! technique as `websearch::SafeIp` and `onioncrawler::OnionHost`):
//!
//! * [`RepoPath`] wraps the canonicalised repository directory and has **no
//!   public constructor**. The only way to obtain one is [`resolve_repo`] /
//!   [`discover_repos`], which canonicalise the root and the candidate and
//!   require `parent(candidate) == realpath(root)` — so `..`, an absolute
//!   path, and a symlink pointing outside the root are all rejected before any
//!   process is spawned. Every git invocation in this module takes a
//!   `&RepoPath`, so "git ran outside the root" is unrepresentable.
//! * [`RepoName`], [`SafeRef`], [`SafePath`], [`SafeQuery`] and [`RefPattern`]
//!   are validated newtypes over the Python `valid_repo_name` / `valid_ref` /
//!   `valid_path` / `valid_query` predicates (still exported as
//!   [`valid_repo_name`], [`valid_ref`], [`valid_path`], [`valid_query`]).
//!   Their `parse` constructors are the only way to build one, so a value that
//!   is option-like (`-…`, `--upload-pack=…`), traversing (`..`), NUL-bearing
//!   or control-bearing can never reach an argv slot.
//! * A ref/pathspec is always separated from the options with `--`, and every
//!   value that is not separable that way is *glued* into a single argv element
//!   (`--grep=<query>`, `--prefix=<prefix>`, `--format=<fmt>`) where git can
//!   only read it as that option's operand.
//! * [`run_git`] re-checks `args[0]` against [`ALLOWED_SUBCOMMANDS`] — the last
//!   line of defence: even a bug that let a caller pass an arbitrary
//!   sub-command could only ever run a read-only one. `receive-pack` (push) is
//!   deliberately absent and must never be added.
//! * Every child is spawned with the config/env hardening: `--no-pager`,
//!   `-c safe.directory=*`, `-c log.showSignature=false`,
//!   `-c core.quotePath=false`, `GIT_TERMINAL_PROMPT=0`, global/system config
//!   redirected to the null device, and `GIT_DIR`/`GIT_WORK_TREE`/
//!   `GIT_PROTOCOL` scrubbed — so behaviour is deterministic and no user
//!   config, alias or pager can influence it. `upload-pack` additionally pins
//!   `uploadpack.allowFilter=false` and `uploadpack.allow*SHA1InWant=false`,
//!   keeping the clone transport default-deny.
//!
//! A faithful port of the Python `gitweb.gitcmd`, with the same caps, timeouts
//! and output parsers. Non-UTF-8 git output is decoded with the replacement
//! policy ([`decode_output`], matching Python's `errors="replace"`), never
//! rejected. Where the reference raises, this returns [`GitError`].
//!
//! # Conventions
//!
//! Python's keyword arguments with defaults become two functions: `f(…)` uses
//! the reference's defaults and `f_with(…, opts)` takes them explicitly.
//!
//! # Documented divergences
//!
//! * **Process-group kill.** The reference calls `os.killpg` to reap the whole
//!   git subtree (`upload-pack` forks the CPU/RAM-heavy `pack-objects`). The
//!   Rust standard library has no safe binding for `killpg`, and this crate is
//!   `#![forbid(unsafe_code)]` with zero third-party dependencies, so
//!   [`std::os::unix::process::CommandExt::process_group`] still makes every
//!   long-running child its own group leader, teardown always SIGKILLs the
//!   leader through [`std::process::Child::kill`], and the group signal is a
//!   best-effort `kill -KILL -- -<pgid>` exec (silently skipped when `kill(1)`
//!   is unavailable).
//! * **No fork guard.** The reference guards its cached `cat-file` readers
//!   against `os.fork()`; the Rust standard library cannot fork, so the pid
//!   guard has no counterpart.
//! * **No `atexit`.** [`close_catfiles`] exists and behaves identically but has
//!   to be called by the server's shutdown path; at process exit the pipes
//!   close anyway and `git cat-file --batch` exits on stdin EOF.
//! * **`isdigit`.** The reference's numeric guards are `str.isdigit()`, which
//!   accepts Unicode digits — and then *raises* on the digit-but-not-decimal
//!   ones (`int("²")`). Here every such guard is ASCII-only, so those inputs
//!   parse as "not a number" instead of crashing. Git never emits them.
//! * **Non-UTF-8 directory names.** [`discover_repos`] decodes a directory name
//!   lossily (the reference uses surrogateescape). Such a repository is
//!   unreachable through a URL anyway, since [`valid_repo_name`] is ASCII-only.
//! * **`read_blob` is split in two.** The reference's single `read_blob` takes
//!   any string; here [`read_blob`] takes a validated [`SafePath`] (the URL
//!   path) and [`read_blob_raw_path`] takes a *repository-derived* one (a name
//!   read out of a tree listing, which git allows to contain any byte but NUL
//!   and `/`). Both funnel through [`GitCatFile::spec_ok`], so neither can
//!   desynchronise the shared batch stream — the split only makes the two
//!   trust levels visible in the signature.
//! * **The record parsers are public.** The reference keeps `_parse_*` private;
//!   here [`parse_log_records`], [`parse_graph_records`], [`parse_tree_entries`],
//!   [`parse_ref_records`], [`parse_commit_record`], [`parse_blame`],
//!   [`parse_grep_matches`], [`parse_batch_header`] and [`parse_gitmodules`] are
//!   exported so they can be exercised on captured git output without spawning
//!   a process. They are pure: none of them can start one.
//! * **Errors, not exceptions.** Where the reference raises `OSError` (git
//!   missing, a file that will not open) this returns [`GitError::Failed`], so
//!   every fallible entry point has one error type.

use std::collections::HashMap;
use std::ffi::OsStr;
use std::fmt;
use std::fs::File;
use std::io::{BufRead, BufReader, Read, Write};
use std::path::{Path, PathBuf};
use std::process::{Child, ChildStdin, ChildStdout, Command, ExitStatus, Stdio};
use std::sync::mpsc::{self, Receiver, RecvTimeoutError, SyncSender};
use std::sync::{Arc, Mutex, MutexGuard, OnceLock};
use std::thread;
use std::time::{Duration, Instant};

use crate::pycompat::{split_whitespace_maxsplit, splitlines, strip};

// --------------------------------------------------------------------------- //
// Configuration constants
// --------------------------------------------------------------------------- //

/// The git binary, looked up on `PATH` by [`std::process::Command`].
pub const GIT: &str = "git";

/// Sub-commands we are willing to run. Every one is read-only.
///
/// This list is the last line of defence: even if a bug let a caller pass an
/// arbitrary sub-command, only these could ever execute. `upload-pack` is the
/// only pack transport — it serves objects out and never writes; `receive-pack`
/// (push) is deliberately absent and must never be added.
pub const ALLOWED_SUBCOMMANDS: &[&str] = &[
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
    "grep",
    "format-patch",
    "upload-pack",
];

/// True if `sub` is one of the read-only [`ALLOWED_SUBCOMMANDS`].
#[must_use]
pub fn is_allowed_subcommand(sub: &str) -> bool {
    ALLOWED_SUBCOMMANDS.contains(&sub)
}

/// Seconds allowed for one ordinary git invocation.
pub const DEFAULT_TIMEOUT: u64 = 15;
/// Hard cap on captured stdout for one ordinary git invocation (bounds RAM).
pub const DEFAULT_MAX_BYTES: usize = 12 * 1024 * 1024;
/// Only this much stderr is kept, for error messages.
pub const MAX_STDERR_BYTES: usize = 64 * 1024;
/// ASCII unit separator, used inside `--format` strings and to split records.
pub const FIELD_SEP: char = '\u{1f}';

/// Longest search term accepted (bounds the request; the term is always a
/// single argv element, so it can never be option-like).
pub const MAX_QUERY_BYTES: usize = 512;
/// Wall-clock seconds for one `git grep` (short: the pattern is literal).
pub const GREP_TIMEOUT: u64 = 10;
/// Hard cap on `git grep` stdout (bounds RAM).
pub const GREP_MAX_BYTES: usize = 4 * 1024 * 1024;
/// Total match rows parsed from one `git grep` (parse-time cap).
pub const GREP_MAX_MATCHES: usize = 1000;
/// `--max-count`: matches per file git is allowed to emit.
pub const GREP_MAX_COUNT_PER_FILE: usize = 100;

/// Wall-clock seconds for one `git format-patch`.
pub const PATCH_TIMEOUT: u64 = 30;
/// Hard cap on the mailbox one `git format-patch` may buffer.
pub const PATCH_MAX_BYTES: usize = 12 * 1024 * 1024;

/// Overall wall-clock seconds for one `upload-pack` advertise/RPC call.
pub const UPLOAD_PACK_TIMEOUT: u64 = 120;
/// Cap on the ref advertisement captured for `info/refs` (bounds RAM: a repo
/// with a pathological number of refs cannot make the advertisement unbounded).
pub const UPLOAD_PACK_ADVERTISE_MAX_BYTES: usize = 12 * 1024 * 1024;

/// Default chunk size for the streaming readers.
pub const DEFAULT_CHUNK_SIZE: usize = 65536;
/// Default number of bytes sniffed to decide whether content is binary.
pub const DEFAULT_PEEK_BYTES: usize = 8192;
/// Default page size for the log listings.
pub const DEFAULT_LOG_LIMIT: usize = 50;
/// Cap on the `.gitmodules` blob read by [`read_gitmodules`].
pub const GITMODULES_MAX_BYTES: usize = 256 * 1024;

/// The stock `description` a fresh repository ships with; treated as empty.
const DEFAULT_DESC: &str =
    "Unnamed repository; edit this file 'description' to name the repository.";

/// Upper bound on staleness of a cached last-commit timestamp when the ref
/// store's mtime signature is unchanged.
const TS_TTL: Duration = Duration::from_secs(60);

/// The null device, as Python's `os.devnull` spells it.
const DEVNULL: &str = if cfg!(windows) { "nul" } else { "/dev/null" };

/// Size of one `read()` from a child's pipe.
const READ_CHUNK: usize = 65536;

// --------------------------------------------------------------------------- //
// Errors
// --------------------------------------------------------------------------- //

/// Why a git operation could not be served.
///
/// The three variants mirror the reference's three exception types, and map
/// onto the HTTP statuses the serving tier uses: [`GitError::Failed`] is the
/// Python `GitError` (500), [`GitError::BadRequest`] is `BadRequest` (400) and
/// [`GitError::NotFound`] is `NotFound` (404).
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum GitError {
    /// A git command failed, timed out, or produced no usable output.
    Failed(String),
    /// The request contained an invalid/hostile parameter.
    BadRequest(String),
    /// The requested repo/ref/object does not exist.
    NotFound(String),
}

impl GitError {
    /// The message, byte-identical to the reference exception's payload.
    #[must_use]
    pub fn message(&self) -> &str {
        match self {
            GitError::Failed(m) | GitError::BadRequest(m) | GitError::NotFound(m) => m,
        }
    }
}

impl fmt::Display for GitError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.message())
    }
}

impl std::error::Error for GitError {}

// --------------------------------------------------------------------------- //
// Validation of untrusted URL parameters
// --------------------------------------------------------------------------- //

/// `^[A-Za-z0-9._-]+$`
fn repo_charset(name: &str) -> bool {
    !name.is_empty()
        && name
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'.' || b == b'_' || b == b'-')
}

/// `^[A-Za-z0-9._/+-]+$`
fn ref_charset(r: &str) -> bool {
    !r.is_empty()
        && r.bytes()
            .all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'/' | b'+' | b'-'))
}

/// A repo id is a single path component from a fixed charset.
///
/// It must not be `.` / `..` and must not begin with `-` (option-like).
#[must_use]
pub fn valid_repo_name(name: &str) -> bool {
    if name.is_empty() || name == "." || name == ".." {
        return false;
    }
    if name.starts_with('-') {
        return false;
    }
    repo_charset(name)
}

/// Validate a ref (branch/tag/sha/short-sha) supplied via the URL.
///
/// Deliberately stricter than `git check-ref-format`: the charset is limited, a
/// leading `-` (option injection) is rejected, and git's special sequences
/// (`..`, `@{`, `:`, whitespace, control chars, `~^?*[`) are all refused. `:`
/// in particular is excluded so `<ref>:<path>` can be built safely later.
#[must_use]
pub fn valid_ref(r: &str) -> bool {
    if r.is_empty() || r.chars().count() > 256 {
        return false;
    }
    let first = r.chars().next().unwrap_or('\0');
    if matches!(first, '-' | '/' | '.') || r.ends_with('/') {
        return false;
    }
    if r.contains("..") || r.contains("@{") || r.contains(".lock") {
        return false;
    }
    ref_charset(r)
}

/// Validate an in-repo object path supplied via the URL.
///
/// Empty means the repository root. Anything that could escape the tree or be
/// mistaken for an option is rejected: a leading `/` or `-`, any `..`
/// component, and control characters.
#[must_use]
pub fn valid_path(path: &str) -> bool {
    if path.is_empty() {
        return true;
    }
    if path.chars().count() > 4096 {
        return false;
    }
    let first = path.chars().next().unwrap_or('\0');
    if matches!(first, '/' | '-') {
        return false;
    }
    if path.chars().any(|c| (c as u32) < 0x20) {
        return false;
    }
    !path.split('/').any(|part| part == "..")
}

/// Validate a free-text search term supplied via the URL.
///
/// The term is only ever handed to git as the operand of `-e` (code search) or
/// as `--grep=<term>` (message search) — a single argv element that git treats
/// as a *literal* pattern (`--fixed-strings`), so it can neither be read as an
/// option nor cause regex backtracking (ReDoS). This check only bounds the
/// length and forbids a NUL, which cannot appear in an argv element (`execve`
/// would reject it) and is the output-record separator we rely on.
#[must_use]
pub fn valid_query(q: &str) -> bool {
    if q.is_empty() || q.chars().count() > MAX_QUERY_BYTES {
        return false;
    }
    !q.contains('\0')
}

/// A repository id from a URL, proven to satisfy [`valid_repo_name`].
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub struct RepoName(String);

/// A ref (branch/tag/sha) from a URL, proven to satisfy [`valid_ref`].
///
/// ```
/// # use gitweb::gitcmd::SafeRef;
/// assert!(SafeRef::parse("main").is_some());
/// assert!(SafeRef::parse("--upload-pack=/tmp/evil").is_none());
/// assert!(SafeRef::parse("a;id").is_none());
/// ```
///
/// ```compile_fail
/// # use gitweb::gitcmd::SafeRef;
/// // The validator is the only way in: an option-like ref cannot be forged.
/// let forged = SafeRef("--upload-pack=/tmp/evil".to_string());
/// ```
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub struct SafeRef(String);

/// An in-repo path from a URL, proven to satisfy [`valid_path`]. May be empty,
/// meaning the repository root.
#[derive(Clone, Debug, Default, PartialEq, Eq, Hash)]
pub struct SafePath(String);

/// A free-text search term from a URL, proven to satisfy [`valid_query`].
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub struct SafeQuery(String);

/// A `for-each-ref` pattern. Server-chosen, never URL-derived; the validator
/// exists so no caller can slip an option-like pattern into the argv.
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub struct RefPattern(String);

macro_rules! safe_newtype {
    ($t:ty, $check:path, $what:literal) => {
        impl $t {
            #[doc = concat!("Validate `value` as ", $what, "; `None` if it is rejected.")]
            #[must_use]
            pub fn parse(value: &str) -> Option<Self> {
                if $check(value) {
                    Some(Self(value.to_string()))
                } else {
                    None
                }
            }

            /// The validated value.
            #[must_use]
            pub fn as_str(&self) -> &str {
                &self.0
            }

            /// Consume into the owned validated string.
            #[must_use]
            pub fn into_string(self) -> String {
                self.0
            }
        }

        impl fmt::Display for $t {
            fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
                f.write_str(&self.0)
            }
        }
    };
}

safe_newtype!(RepoName, valid_repo_name, "a repository id");
safe_newtype!(SafeRef, valid_ref, "a ref");
safe_newtype!(SafePath, valid_path, "an in-repo path");
safe_newtype!(SafeQuery, valid_query, "a search term");

impl SafePath {
    /// The repository root (the empty path).
    #[must_use]
    pub fn root() -> Self {
        SafePath(String::new())
    }

    /// True for the repository root.
    #[must_use]
    pub fn is_root(&self) -> bool {
        self.0.is_empty()
    }
}

impl RefPattern {
    /// Validate a `for-each-ref` pattern: [`valid_ref`]'s charset and bound,
    /// but a trailing `/` is allowed (`refs/heads/`).
    #[must_use]
    pub fn parse(pattern: &str) -> Option<Self> {
        let body = pattern.strip_suffix('/').unwrap_or(pattern);
        if valid_ref(body) {
            Some(RefPattern(pattern.to_string()))
        } else {
            None
        }
    }

    /// `refs/heads/` — every branch.
    #[must_use]
    pub fn heads() -> Self {
        RefPattern("refs/heads/".to_string())
    }

    /// `refs/tags/` — every tag.
    #[must_use]
    pub fn tags() -> Self {
        RefPattern("refs/tags/".to_string())
    }

    /// The validated pattern.
    #[must_use]
    pub fn as_str(&self) -> &str {
        &self.0
    }
}

impl fmt::Display for RefPattern {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

/// Build a `<ref>:<path>` (or bare `<ref>`) git object spec.
///
/// Both components are validated newtypes, so the result can never contain a
/// second `:` separator, a `..`, or a leading `-`.
#[must_use]
pub fn object_spec(reference: &SafeRef, path: &SafePath) -> String {
    if path.is_root() {
        reference.0.clone()
    } else {
        format!("{}:{}", reference.0, path.0)
    }
}

// --------------------------------------------------------------------------- //
// Subprocess wrapper
// --------------------------------------------------------------------------- //

/// Environment for git children: **never interactive**, no global/system config.
///
/// Ignoring global/system config makes behaviour deterministic and strips any
/// user aliases/pagers. `safe.directory` is forced on via `-c` so we can still
/// read repos owned by another uid. `GIT_PROTOCOL` is scrubbed by default so
/// the wire protocol version is deterministic; the Smart-HTTP layer passes
/// `version=2` only when the client explicitly negotiated protocol v2.
///
/// **Non-interactivity is a hard requirement, not a convenience.** This runs
/// inside a request handler: a child that stops to ask a human for a password
/// pins a worker until the timeout, so a single unauthenticated remote could
/// stall the server. `GIT_TERMINAL_PROMPT=0` alone is *not* enough — it only
/// suppresses the TTY prompt, and git will still shell out to an **askpass
/// helper** (`GIT_ASKPASS`, `core.askPass`, `SSH_ASKPASS`) or a configured
/// **credential helper** if the environment provides one, which on a desktop
/// pops a GUI dialog. All three doors are shut here.
fn harden_env(cmd: &mut Command, extra: Option<(&str, &str)>) {
    cmd.env("GIT_TERMINAL_PROMPT", "0");
    cmd.env("GIT_CONFIG_GLOBAL", DEVNULL);
    cmd.env("GIT_CONFIG_SYSTEM", DEVNULL);
    // Belt-and-braces with GIT_CONFIG_SYSTEM: some builds honour only this.
    cmd.env("GIT_CONFIG_NOSYSTEM", "1");
    // Empty (not merely unset) disables the askpass path outright; an inherited
    // SSH_ASKPASS would otherwise be used as the fallback helper.
    cmd.env("GIT_ASKPASS", "");
    cmd.env_remove("SSH_ASKPASS");
    cmd.env_remove("SSH_ASKPASS_REQUIRE");
    cmd.env_remove("GIT_DIR");
    cmd.env_remove("GIT_WORK_TREE");
    cmd.env_remove("GIT_PROTOCOL");
    if let Some((key, value)) = extra {
        cmd.env(key, value);
    }
}

/// Make the child lead its own process group, so teardown can reap the subtree.
#[cfg(unix)]
fn own_process_group(cmd: &mut Command) {
    use std::os::unix::process::CommandExt;
    cmd.process_group(0);
}

/// No process groups on this platform; teardown kills the direct child only.
#[cfg(not(unix))]
fn own_process_group(_cmd: &mut Command) {}

/// The `(returncode, stdout, stderr)` triple of one git invocation.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct GitOutput {
    /// The child's exit status (negative `-N` for death by signal N, as
    /// Python's `Popen.returncode` reports it), or `0` when output was
    /// deliberately truncated at the cap.
    pub code: i32,
    /// Captured stdout, never longer than the invocation's `max_bytes`.
    pub stdout: Vec<u8>,
    /// Captured stderr, never longer than [`MAX_STDERR_BYTES`].
    pub stderr: Vec<u8>,
}

/// Per-invocation limits for [`run_git_with`].
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct RunOptions {
    /// Wall-clock budget for the whole invocation.
    pub timeout: Duration,
    /// Hard cap on captured stdout; the child is killed the moment it is hit.
    pub max_bytes: usize,
    /// Turn a non-zero exit into [`GitError::Failed`].
    pub check: bool,
}

impl Default for RunOptions {
    fn default() -> Self {
        RunOptions {
            timeout: Duration::from_secs(DEFAULT_TIMEOUT),
            max_bytes: DEFAULT_MAX_BYTES,
            check: false,
        }
    }
}

/// The invariant leading argv shared by every git invocation.
///
/// Centralising it keeps the hardening (no pager, global/system config off,
/// `safe.directory=*`, deterministic quoting) identical across the plain
/// [`run_git`] path, the streamed `/raw` reader and the persistent `cat-file`
/// batch reader.
fn base_cmd(repo: &RepoPath) -> Command {
    let mut cmd = Command::new(GIT);
    cmd.arg("--no-pager")
        .arg("-c")
        .arg("safe.directory=*")
        .arg("-c")
        .arg("log.showSignature=false")
        .arg("-c")
        .arg("core.quotePath=false")
        // The config half of the never-interactive rule (see `harden_env` for
        // the environment half): an empty `core.askPass` disables the askpass
        // helper, and an empty `credential.helper` RESETS the helper list, so a
        // helper configured anywhere else cannot run and block the request.
        .arg("-c")
        .arg("core.askPass=")
        .arg("-c")
        .arg("credential.helper=")
        .arg("-C")
        .arg(repo.as_os_str());
    cmd
}

/// Python's `Popen.returncode` convention: `-N` for death by signal `N`.
fn exit_code(status: ExitStatus) -> i32 {
    #[cfg(unix)]
    {
        use std::os::unix::process::ExitStatusExt;
        if let Some(sig) = status.signal() {
            return -sig;
        }
    }
    status.code().unwrap_or(0)
}

/// SIGKILL the child *and its entire process group*.
///
/// Every long-running git child leads its own process group, because
/// `git upload-pack` forks `git pack-objects` (the CPU/RAM-heavy step): killing
/// only the leader would orphan `pack-objects`, defeating both the wall-clock
/// timeout and the clone concurrency cap. The leader is always killed through
/// the standard library; the group signal is a best-effort `kill(1)` exec (see
/// the module's documented divergences).
fn kill_process_group(child: &mut Child) {
    if matches!(child.try_wait(), Ok(Some(_))) {
        return; // already exited; nothing to signal
    }
    #[cfg(unix)]
    {
        // The child was spawned with `process_group(0)`, so its pgid == its pid.
        let pgid = child.id();
        let _ = Command::new("kill")
            .arg("-KILL")
            .arg("--")
            .arg(format!("-{pgid}"))
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status();
    }
    let _ = child.kill();
}

/// `Child::wait()` with a bound, mirroring Python's `proc.wait(timeout=…)`.
fn wait_bounded(child: &mut Child, limit: Duration) -> Option<ExitStatus> {
    let deadline = Instant::now() + limit;
    loop {
        match child.try_wait() {
            Ok(Some(status)) => return Some(status),
            Ok(None) => {}
            Err(_) => return None,
        }
        if Instant::now() >= deadline {
            return None;
        }
        thread::sleep(Duration::from_millis(2));
    }
}

/// One pipe's worth of bytes, tagged with the stream it came from.
enum Chunk {
    Out(Vec<u8>),
    Err(Vec<u8>),
}

/// Pump `src` into `tx` in `chunk`-sized pieces on a detached thread.
///
/// The thread ends at EOF (guaranteed once the child is killed) or as soon as
/// the receiver is dropped, so no reader outlives the capture it serves.
fn spawn_pump<T, F>(mut src: impl Read + Send + 'static, tx: SyncSender<T>, chunk: usize, wrap: F)
where
    T: Send + 'static,
    F: Fn(Vec<u8>) -> T + Send + 'static,
{
    thread::spawn(move || {
        let mut buf = vec![0u8; chunk.max(1)];
        loop {
            match src.read(&mut buf) {
                Ok(0) => return,
                Ok(n) => {
                    if tx.send(wrap(buf[..n].to_vec())).is_err() {
                        return;
                    }
                }
                Err(ref e) if e.kind() == std::io::ErrorKind::Interrupted => {}
                Err(_) => return,
            }
        }
    });
}

/// The result of draining a child under a cap and a deadline.
struct Capture {
    out: Vec<u8>,
    err: Vec<u8>,
    capped: bool,
    timed_out: bool,
    code: i32,
}

/// Drain `child` without ever buffering more than `max_bytes` of stdout.
///
/// stdout and stderr are read incrementally under a single wall-clock
/// `timeout`. The instant stdout reaches `max_bytes` the child is killed, so a
/// command that would emit gigabytes (e.g. `cat-file -p` on a huge blob) can
/// never stream more than the cap into memory.
fn capture_capped(child: &mut Child, max_bytes: usize, timeout: Duration) -> Capture {
    let (tx, rx) = mpsc::sync_channel::<Chunk>(1);
    if let Some(stream) = child.stdout.take() {
        spawn_pump(stream, tx.clone(), READ_CHUNK, Chunk::Out);
    }
    if let Some(stream) = child.stderr.take() {
        spawn_pump(stream, tx.clone(), READ_CHUNK, Chunk::Err);
    }
    drop(tx); // so "disconnected" means "both readers hit EOF"

    let mut out: Vec<u8> = Vec::new();
    let mut err: Vec<u8> = Vec::new();
    let mut capped = false;
    let mut timed_out = false;
    let deadline = Instant::now() + timeout;
    loop {
        let remaining = deadline.saturating_duration_since(Instant::now());
        if remaining.is_zero() {
            timed_out = true;
            break;
        }
        match rx.recv_timeout(remaining) {
            Ok(Chunk::Out(data)) => {
                let room = max_bytes.saturating_sub(out.len());
                if room > 0 {
                    out.extend_from_slice(&data[..data.len().min(room)]);
                }
                if out.len() >= max_bytes {
                    capped = true;
                    break;
                }
            }
            Ok(Chunk::Err(data)) => {
                if err.len() < MAX_STDERR_BYTES {
                    let room = MAX_STDERR_BYTES - err.len();
                    err.extend_from_slice(&data[..data.len().min(room)]);
                }
            }
            Err(RecvTimeoutError::Timeout) => {
                timed_out = true;
                break;
            }
            Err(RecvTimeoutError::Disconnected) => break,
        }
    }
    drop(rx);

    if capped || timed_out {
        kill_process_group(child);
    }
    let code = match wait_bounded(child, Duration::from_secs(5)) {
        Some(status) => exit_code(status),
        None => {
            kill_process_group(child);
            child.wait().map(exit_code).unwrap_or(0)
        }
    };
    Capture {
        out,
        err,
        capped,
        timed_out,
        code,
    }
}

/// Spawn `cmd`, turning an exec failure into [`GitError::Failed`].
fn spawn(mut cmd: Command) -> Result<Child, GitError> {
    cmd.spawn()
        .map_err(|e| GitError::Failed(format!("cannot run {GIT}: {e}")))
}

/// Run a read-only git command with the reference's default limits.
///
/// # Errors
/// [`GitError::BadRequest`] if `args[0]` is not in [`ALLOWED_SUBCOMMANDS`], and
/// [`GitError::Failed`] if git cannot be spawned or exceeds the timeout.
pub fn run_git(repo: &RepoPath, args: &[&str]) -> Result<GitOutput, GitError> {
    run_git_with(repo, args, RunOptions::default())
}

/// Run a read-only git command and return `(returncode, stdout, stderr)`.
///
/// * `args[0]` must be in [`ALLOWED_SUBCOMMANDS`].
/// * Never uses a shell; `args` is passed verbatim as an argument vector.
/// * stdout is read incrementally and **hard-capped** at `opts.max_bytes`: peak
///   memory stays bounded even for a command that would emit far more, because
///   the child is killed the moment the cap is hit. When output was truncated
///   that way the child's exit status is meaningless, so the (bounded) output
///   is reported as a success.
/// * The child is killed if it runs longer than `opts.timeout`.
///
/// # Errors
/// [`GitError::BadRequest`] for a refused sub-command; [`GitError::Failed`] if
/// git cannot be spawned, if the timeout elapses, or if `opts.check` is set and
/// the command exited non-zero.
pub fn run_git_with(
    repo: &RepoPath,
    args: &[&str],
    opts: RunOptions,
) -> Result<GitOutput, GitError> {
    let Some(&sub) = args.first() else {
        return Err(GitError::BadRequest(
            "refused git subcommand: (none)".to_string(),
        ));
    };
    if !is_allowed_subcommand(sub) {
        return Err(GitError::BadRequest(format!(
            "refused git subcommand: {sub}"
        )));
    }

    let mut cmd = base_cmd(repo);
    for arg in args {
        cmd.arg(arg);
    }
    cmd.stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .stdin(Stdio::null());
    harden_env(&mut cmd, None);
    // Own process group so a timeout/cap kill reaps the whole git subtree (any
    // helper it forks) rather than orphaning a detached child.
    own_process_group(&mut cmd);

    let mut child = spawn(cmd)?;
    let cap = capture_capped(&mut child, opts.max_bytes, opts.timeout);
    if cap.timed_out {
        return Err(GitError::Failed(format!(
            "git {sub} timed out after {}s",
            opts.timeout.as_secs()
        )));
    }
    let code = if cap.capped { 0 } else { cap.code };
    if opts.check && code != 0 {
        return Err(GitError::Failed(format!(
            "git {sub} failed ({code}): {}",
            strip(&decode_output(&cap.err))
        )));
    }
    Ok(GitOutput {
        code,
        stdout: cap.out,
        stderr: cap.err,
    })
}

/// Decode git output as UTF-8, replacing undecodable bytes.
///
/// The port of the reference's `_text`: `bytes.decode("utf-8", "replace")`.
/// [`String::from_utf8_lossy`] implements the same maximal-subpart replacement
/// algorithm, so the two agree byte for byte (asserted in `xcheck_gitcmd.rs`).
#[must_use]
pub fn decode_output(data: &[u8]) -> String {
    String::from_utf8_lossy(data).into_owned()
}

/// `bytes.decode("ascii", "replace")`.
fn decode_ascii(data: &[u8]) -> String {
    data.iter()
        .map(|&b| if b < 0x80 { b as char } else { '\u{fffd}' })
        .collect()
}

/// Python's `bytes.isdigit()`/ASCII-only `str.isdigit()`: non-empty ASCII digits.
fn is_ascii_digits(s: &str) -> bool {
    !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit())
}

/// `int(s) if s.isdigit() else 0`, with an out-of-range value degrading to 0.
fn parse_ts(s: &str) -> i64 {
    if is_ascii_digits(s) {
        s.parse::<i64>().unwrap_or(0)
    } else {
        0
    }
}

/// `bytes.split()` — split on runs of ASCII whitespace, dropping empties.
fn split_ascii_ws(data: &[u8]) -> Vec<&[u8]> {
    data.split(|b| matches!(b, b' ' | b'\t' | b'\n' | b'\r' | 0x0b | 0x0c))
        .filter(|part| !part.is_empty())
        .collect()
}

/// Python's `str.lower()`: the context-insensitive full Unicode lowercase
/// mapping (no Greek final-sigma special casing, unlike [`str::to_lowercase`]).
fn py_lower(s: &str) -> String {
    s.chars().flat_map(char::to_lowercase).collect()
}

// --------------------------------------------------------------------------- //
// Persistent `git cat-file` batch reader
// --------------------------------------------------------------------------- //
//
// Rendering a single blob used to fork git four times: `cat-file -t` (type),
// `cat-file -s` (size), one `cat-file -p` to peek for binary sniffing and a
// second to read the body. This collapses all of that onto two long-lived
// processes per repository — `--batch-check` for metadata/type lookups and
// `--batch` for content — reused across requests.
//
// Safety is preserved: the same hardened argv/env as every other git call
// (argv-only, no shell); object specs are still built only from validated
// refs/paths; and the content reader keeps a hard output cap and early-kill, so
// a blob larger than the requested cap is never drained or buffered.

/// The identity/type/size triple `cat-file --batch-check` returns.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ObjStat {
    /// The object's own id (40 hex for sha-1 repositories).
    pub sha: String,
    /// `blob` | `tree` | `commit` | `tag`.
    pub otype: String,
    /// The object's size in bytes.
    pub size: u64,
}

/// Parse one `cat-file --batch`/`--batch-check` header line.
///
/// Existing objects yield `<oid> <type> <size>`; a missing/ambiguous spec
/// yields `<spec> missing` / `<spec> ambiguous`. Returns `None` for the latter
/// (and for any malformed line).
#[must_use]
pub fn parse_batch_header(line: &[u8]) -> Option<ObjStat> {
    if line.is_empty() {
        return None;
    }
    let parts = split_ascii_ws(line);
    if parts.len() >= 2 && matches!(parts[parts.len() - 1], b"missing" | b"ambiguous") {
        return None;
    }
    if parts.len() != 3 {
        return None;
    }
    let size = std::str::from_utf8(parts[2]).ok()?;
    if !is_ascii_digits(size) {
        return None;
    }
    Some(ObjStat {
        sha: decode_ascii(parts[0]),
        otype: decode_ascii(parts[1]),
        size: size.parse::<u64>().ok()?,
    })
}

/// What one [`GitCatFile::read`] produced.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct BlobRead {
    /// The bytes read, never more than the requested cap.
    pub data: Vec<u8>,
    /// The object's identity/type/size.
    pub stat: ObjStat,
    /// True when the object is larger than the cap (or the reader died
    /// mid-body), so `data` is a prefix.
    pub truncated: bool,
}

/// One live `git cat-file` process plus its pipes.
struct BatchProc {
    child: Child,
    stdin: ChildStdin,
    stdout: BufReader<ChildStdout>,
}

/// Which of the two batch processes a request goes to.
#[derive(Clone, Copy, PartialEq, Eq)]
enum Mode {
    Check,
    Batch,
}

#[derive(Default)]
struct CatFileInner {
    check: Option<BatchProc>,
    batch: Option<BatchProc>,
}

impl CatFileInner {
    fn slot(&mut self, mode: Mode) -> &mut Option<BatchProc> {
        match mode {
            Mode::Check => &mut self.check,
            Mode::Batch => &mut self.batch,
        }
    }
}

/// A pair of persistent `git cat-file` processes for one repository.
///
/// Thread-safe: a single lock serialises access so two request threads can
/// never interleave on the shared pipes.
pub struct GitCatFile {
    repo: RepoPath,
    inner: Mutex<CatFileInner>,
}

impl GitCatFile {
    /// A reader for `repo`; the processes are spawned lazily on first use.
    #[must_use]
    pub fn new(repo: &RepoPath) -> Self {
        GitCatFile {
            repo: repo.clone(),
            inner: Mutex::new(CatFileInner::default()),
        }
    }

    fn lock(&self) -> MutexGuard<'_, CatFileInner> {
        // A panic inside a batch request cannot corrupt the pipes into an
        // unsound state, so a poisoned lock is recovered rather than propagated.
        self.inner.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn spawn_proc(&self, mode: Mode) -> Option<BatchProc> {
        let mut cmd = base_cmd(&self.repo);
        cmd.arg("cat-file").arg(match mode {
            Mode::Check => "--batch-check",
            Mode::Batch => "--batch",
        });
        cmd.stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::null());
        harden_env(&mut cmd, None);
        // Own session so teardown group-kills this batch reader cleanly.
        own_process_group(&mut cmd);
        let mut child = cmd.spawn().ok()?;
        let stdin = child.stdin.take()?;
        // A buffered reader gives a correct `read_line`/exact read; any
        // read-ahead lands in the same object, so exact content reads stay in
        // sync with the header line.
        let stdout = BufReader::new(child.stdout.take()?);
        Some(BatchProc {
            child,
            stdin,
            stdout,
        })
    }

    /// Drop the process for `mode`, killing it and closing its pipes.
    fn drop_proc(inner: &mut CatFileInner, mode: Mode) {
        if let Some(mut proc) = inner.slot(mode).take() {
            kill_process_group(&mut proc.child);
            let _ = wait_bounded(&mut proc.child, Duration::from_secs(2));
        }
    }

    /// Tear both processes down. Idempotent.
    pub fn close(&self) {
        let mut inner = self.lock();
        Self::drop_proc(&mut inner, Mode::Check);
        Self::drop_proc(&mut inner, Mode::Batch);
    }

    /// Reject any spec containing a control character.
    ///
    /// A legitimate `<ref>:<path>` / `<ref>^{…}` spec never contains a control
    /// byte, but a *repo-derived* path (a filename in the tree) may — git
    /// allows any byte except NUL and `/` in a filename, newline included.
    /// Because a spec is written to the batch process's stdin with a trailing
    /// newline, an embedded newline would inject a second request and
    /// desynchronise the shared stream (returning the wrong blob's bytes to a
    /// later request). Refusing control characters closes that at the choke
    /// point for *every* caller, not just URL-validated ones.
    #[must_use]
    pub fn spec_ok(spec: &str) -> bool {
        !spec
            .chars()
            .any(|c| (c as u32) < 0x20 || (c as u32) == 0x7f)
    }

    /// Write one request and read its header line back.
    fn request(inner: &mut CatFileInner, mode: Mode, spec: &str) -> Option<Option<ObjStat>> {
        let proc = inner.slot(mode).as_mut()?;
        proc.stdin.write_all(spec.as_bytes()).ok()?;
        proc.stdin.write_all(b"\n").ok()?;
        proc.stdin.flush().ok()?;
        let mut header = Vec::new();
        proc.stdout.read_until(b'\n', &mut header).ok()?;
        Some(parse_batch_header(&header))
    }

    /// Ensure the process for `mode` is alive, respawning if it died.
    fn ensure(&self, inner: &mut CatFileInner, mode: Mode) -> bool {
        let alive = match inner.slot(mode) {
            Some(proc) => !matches!(proc.child.try_wait(), Ok(Some(_)) | Err(_)),
            None => false,
        };
        if !alive {
            Self::drop_proc(inner, mode);
            *inner.slot(mode) = self.spawn_proc(mode);
        }
        inner.slot(mode).is_some()
    }

    /// Return the [`ObjStat`] for `spec`, or `None` if it does not exist.
    #[must_use]
    pub fn check(&self, spec: &str) -> Option<ObjStat> {
        if !Self::spec_ok(spec) {
            return None;
        }
        let mut inner = self.lock();
        if !self.ensure(&mut inner, Mode::Check) {
            return None;
        }
        match Self::request(&mut inner, Mode::Check, spec) {
            Some(stat) => stat,
            None => {
                Self::drop_proc(&mut inner, Mode::Check);
                None
            }
        }
    }

    /// Read up to `max_bytes` of the object body.
    ///
    /// `None` when the object does not exist. When the object is larger than
    /// `max_bytes` the cap is read and the batch process is killed and
    /// respawned (early-kill; the stream would otherwise be desynchronised), so
    /// peak memory stays ~`max_bytes`.
    #[must_use]
    pub fn read(&self, spec: &str, max_bytes: usize) -> Option<BlobRead> {
        if !Self::spec_ok(spec) {
            return None;
        }
        let mut inner = self.lock();
        if !self.ensure(&mut inner, Mode::Batch) {
            return None;
        }
        let stat = match Self::request(&mut inner, Mode::Batch, spec) {
            Some(Some(stat)) => stat,
            Some(None) => return None,
            None => {
                // Any failure mid-body could leave unconsumed bytes in the pipe;
                // drop the process so the next request starts from a clean one.
                Self::drop_proc(&mut inner, Mode::Batch);
                return None;
            }
        };
        let want = usize::try_from(stat.size)
            .unwrap_or(usize::MAX)
            .min(max_bytes);
        let proc = inner.slot(Mode::Batch).as_mut()?;
        let Ok(data) = read_upto(&mut proc.stdout, want) else {
            Self::drop_proc(&mut inner, Mode::Batch);
            return None;
        };
        if data.len() < want {
            // Short read => the process died mid-body.
            Self::drop_proc(&mut inner, Mode::Batch);
            return Some(BlobRead {
                data,
                stat,
                truncated: true,
            });
        }
        if stat.size <= max_bytes as u64 {
            // Consume the single trailing LF so the stream stays aligned.
            if let Some(proc) = inner.slot(Mode::Batch).as_mut() {
                let _ = read_upto(&mut proc.stdout, 1);
            }
            return Some(BlobRead {
                data,
                stat,
                truncated: false,
            });
        }
        // Body exceeds the cap: abandon the rest, do not drain it.
        Self::drop_proc(&mut inner, Mode::Batch);
        Some(BlobRead {
            data,
            stat,
            truncated: true,
        })
    }
}

impl Drop for GitCatFile {
    fn drop(&mut self) {
        self.close();
    }
}

/// Read exactly `n` bytes, or fewer at EOF (the reference's `_read_exact`).
fn read_upto(src: &mut impl Read, n: usize) -> std::io::Result<Vec<u8>> {
    let mut buf = vec![0u8; 0];
    buf.reserve(n.min(READ_CHUNK));
    let mut chunk = vec![0u8; n.clamp(1, READ_CHUNK)];
    while buf.len() < n {
        let want = (n - buf.len()).min(chunk.len());
        match src.read(&mut chunk[..want]) {
            Ok(0) => break,
            Ok(got) => buf.extend_from_slice(&chunk[..got]),
            Err(ref e) if e.kind() == std::io::ErrorKind::Interrupted => {}
            Err(e) => return Err(e),
        }
    }
    Ok(buf)
}

/// Module-level cache of one [`GitCatFile`] per repository path.
type CatFileCache = Mutex<HashMap<PathBuf, Arc<GitCatFile>>>;

fn catfile_cache() -> &'static CatFileCache {
    static CACHE: OnceLock<CatFileCache> = OnceLock::new();
    CACHE.get_or_init(|| Mutex::new(HashMap::new()))
}

/// The cached batch reader for `repo`, created on first use.
fn catfile(repo: &RepoPath) -> Arc<GitCatFile> {
    let mut cache = catfile_cache().lock().unwrap_or_else(|e| e.into_inner());
    if let Some(existing) = cache.get(repo.as_path()) {
        return Arc::clone(existing);
    }
    let reader = Arc::new(GitCatFile::new(repo));
    cache.insert(repo.as_path().to_path_buf(), Arc::clone(&reader));
    reader
}

/// Tear down every cached batch reader.
///
/// Called by the server's shutdown path (the reference registers it with
/// `atexit`). A reader whose processes are closed simply respawns them on its
/// next use, so this is safe to call at any time.
pub fn close_catfiles() {
    let readers: Vec<Arc<GitCatFile>> = {
        let mut cache = catfile_cache().lock().unwrap_or_else(|e| e.into_inner());
        let readers = cache.values().cloned().collect();
        cache.clear();
        readers
    };
    for reader in readers {
        reader.close();
    }
}

/// Return the object identity/type/size at `reference`/`path` in one lookup.
///
/// The batch-backed replacement for a `cat-file -t` + `-s` fork pair: callers
/// get the type (for routing), the size and — crucially for ETag/permalink
/// support — the object's own sha from a single request.
#[must_use]
pub fn stat_object(repo: &Repo, reference: &SafeRef, path: &SafePath) -> Option<ObjStat> {
    catfile(&repo.path).check(&object_spec(reference, path))
}

// --------------------------------------------------------------------------- //
// Repository discovery / allow-listing
// --------------------------------------------------------------------------- //

/// A repository directory that a validator proved lives directly under the
/// configured root.
///
/// There is no public constructor: the only way to obtain one is
/// [`resolve_repo`] or [`discover_repos`], which canonicalise both the root and
/// the candidate and require the candidate's parent to be exactly the
/// canonicalised root. Because every git invocation in this module takes a
/// `&RepoPath`, "git ran against a directory outside the root" cannot be
/// expressed.
///
/// The absence of a constructor is machine-checked:
///
/// ```compile_fail
/// # use gitweb::gitcmd::RepoPath;
/// // No public constructor, and the inner path is private: a caller cannot
/// // mint a `RepoPath` for a directory the confinement check never saw.
/// let forged = RepoPath(std::path::PathBuf::from("/etc"));
/// ```
#[derive(Clone, Debug, PartialEq, Eq, Hash)]
pub struct RepoPath(PathBuf);

impl RepoPath {
    /// Confine `candidate` to `root_real` (an already-canonicalised root).
    ///
    /// Returns `None` unless the candidate canonicalises to an existing
    /// directory whose parent is exactly `root_real` — which rejects `..`
    /// traversal, an absolute path, and a symlink pointing outside the root.
    fn confine(root_real: &Path, candidate: &Path) -> Option<RepoPath> {
        let real = std::fs::canonicalize(candidate).ok()?;
        if real.parent() != Some(root_real) {
            return None;
        }
        if !real.is_dir() {
            return None;
        }
        Some(RepoPath(real))
    }

    /// The canonicalised repository directory.
    #[must_use]
    pub fn as_path(&self) -> &Path {
        &self.0
    }

    /// The canonicalised repository directory, for an argv slot.
    #[must_use]
    pub fn as_os_str(&self) -> &OsStr {
        self.0.as_os_str()
    }
}

impl fmt::Display for RepoPath {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0.to_string_lossy())
    }
}

/// A discovered repository under the configured root.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Repo {
    /// URL id == directory name.
    pub name: String,
    /// Confined absolute path used as `git -C`.
    pub path: RepoPath,
    /// True for a bare repository.
    pub bare: bool,
    /// The `description` file's contents, or empty.
    pub description: String,
    /// Unix timestamp of the tip commit, if it has one.
    pub last_commit_ts: Option<i64>,
}

fn is_bare_repo(path: &Path) -> bool {
    path.join("objects").is_dir() && path.join("refs").is_dir() && path.join("HEAD").is_file()
}

fn is_worktree_repo(path: &Path) -> bool {
    let dotgit = path.join(".git");
    dotgit.is_dir() || dotgit.is_file()
}

/// `Some(true)` if bare, `Some(false)` if a normal worktree, else `None`.
fn repo_kind(path: &Path) -> Option<bool> {
    if is_worktree_repo(path) {
        return Some(false);
    }
    if is_bare_repo(path) {
        return Some(true);
    }
    None
}

/// Python text-mode universal newlines: `\r\n` and lone `\r` become `\n`.
fn universal_newlines(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut chars = s.chars().peekable();
    while let Some(c) = chars.next() {
        if c == '\r' {
            if chars.peek() == Some(&'\n') {
                chars.next();
            }
            out.push('\n');
        } else {
            out.push(c);
        }
    }
    out
}

fn read_description(path: &Path, bare: bool) -> String {
    let desc_file = if bare {
        path.join("description")
    } else {
        path.join(".git").join("description")
    };
    let Ok(raw) = std::fs::read(&desc_file) else {
        return String::new();
    };
    let text = universal_newlines(&decode_output(&raw));
    let text = strip(&text);
    if text.is_empty() || text == DEFAULT_DESC {
        return String::new();
    }
    text.to_string()
}

/// Unix timestamp of `HEAD`'s commit, or `None` for an unborn/broken HEAD.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn last_commit_ts(repo: &RepoPath) -> Result<Option<i64>, GitError> {
    let out = run_git(repo, &["log", "-1", "--format=%ct", "HEAD"])?;
    if out.code != 0 {
        return Ok(None);
    }
    let text = decode_output(&out.stdout);
    let raw = strip(&text);
    Ok(if is_ascii_digits(raw) {
        raw.parse::<i64>().ok()
    } else {
        None
    })
}

/// A cheap fingerprint of the repo's ref store (mtimes of ref locations).
fn refs_signature(path: &Path, bare: bool) -> [i128; 4] {
    let base = if bare {
        path.to_path_buf()
    } else {
        path.join(".git")
    };
    let mut sig = [0i128; 4];
    for (slot, rel) in sig.iter_mut().zip([
        Path::new("packed-refs").to_path_buf(),
        Path::new("HEAD").to_path_buf(),
        Path::new("refs").to_path_buf(),
        Path::new("refs").join("heads"),
    ]) {
        *slot = mtime_ns(&base.join(rel));
    }
    sig
}

/// `os.stat(path).st_mtime_ns`, or 0 when it cannot be stat'd.
fn mtime_ns(path: &Path) -> i128 {
    let Ok(meta) = std::fs::metadata(path) else {
        return 0;
    };
    let Ok(modified) = meta.modified() else {
        return 0;
    };
    match modified.duration_since(std::time::UNIX_EPOCH) {
        Ok(d) => d.as_nanos() as i128,
        Err(e) => -(e.duration().as_nanos() as i128),
    }
}

type TsCache = Mutex<HashMap<PathBuf, ([i128; 4], Option<i64>, Instant)>>;

fn ts_cache() -> &'static TsCache {
    static CACHE: OnceLock<TsCache> = OnceLock::new();
    CACHE.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Memoised [`last_commit_ts`], keyed on the ref-store signature.
///
/// Returns the cached value while the ref store is unchanged and the TTL has
/// not lapsed, otherwise recomputes. This removes the N-forks-per-homepage
/// cliff and the redundant fork on every per-repo request.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn cached_last_commit_ts(repo: &RepoPath, bare: bool) -> Result<Option<i64>, GitError> {
    let now = Instant::now();
    let sig = refs_signature(repo.as_path(), bare);
    {
        let cache = ts_cache().lock().unwrap_or_else(|e| e.into_inner());
        if let Some(&(cached_sig, ts, at)) = cache.get(repo.as_path()) {
            if cached_sig == sig && now.duration_since(at) < TS_TTL {
                return Ok(ts);
            }
        }
    }
    let ts = last_commit_ts(repo)?;
    let mut cache = ts_cache().lock().unwrap_or_else(|e| e.into_inner());
    cache.insert(repo.as_path().to_path_buf(), (sig, ts, now));
    Ok(ts)
}

fn build_repo(name: String, path: RepoPath, bare: bool) -> Result<Repo, GitError> {
    let description = read_description(path.as_path(), bare);
    let last_commit_ts = cached_last_commit_ts(&path, bare)?;
    Ok(Repo {
        name,
        path,
        bare,
        description,
        last_commit_ts,
    })
}

/// Scan `root` (one level deep) for bare and normal git repositories.
///
/// An entry must resolve to a *direct* child of the canonicalised root, which
/// rejects a symlink placed under the root that points elsewhere — matching
/// [`resolve_repo`] and keeping git from ever running outside it.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out while reading a
/// repository's tip timestamp.
pub fn discover_repos(root: &Path) -> Result<Vec<Repo>, GitError> {
    let mut repos: Vec<Repo> = Vec::new();
    let Ok(root_real) = std::fs::canonicalize(root) else {
        return Ok(repos);
    };
    let Ok(entries) = std::fs::read_dir(&root_real) else {
        return Ok(repos);
    };
    let mut names: Vec<String> = entries
        .filter_map(|e| e.ok())
        .map(|e| e.file_name().to_string_lossy().into_owned())
        .collect();
    names.sort();
    for name in names {
        if name.starts_with('.') {
            continue;
        }
        let Some(path) = RepoPath::confine(&root_real, &root_real.join(&name)) else {
            continue;
        };
        let Some(bare) = repo_kind(path.as_path()) else {
            continue;
        };
        repos.push(build_repo(name, path, bare)?);
    }
    Ok(repos)
}

/// Resolve a URL repo id to a [`Repo`], enforcing the allow-list.
///
/// # Errors
/// [`GitError::BadRequest`] for a malformed name and [`GitError::NotFound`] if
/// the resolved directory is not a git repo directly under `root`.
pub fn resolve_repo(root: &Path, name: &str) -> Result<Repo, GitError> {
    let Some(repo_name) = RepoName::parse(name) else {
        return Err(GitError::BadRequest("invalid repository name".to_string()));
    };
    const NO_REPO: &str = "no such repository";
    let Ok(root_real) = std::fs::canonicalize(root) else {
        return Err(GitError::NotFound(NO_REPO.to_string()));
    };
    // Must live *directly* under the root (no traversal, no nesting).
    let Some(path) = RepoPath::confine(&root_real, &root_real.join(repo_name.as_str())) else {
        return Err(GitError::NotFound(NO_REPO.to_string()));
    };
    let Some(bare) = repo_kind(path.as_path()) else {
        return Err(GitError::NotFound(NO_REPO.to_string()));
    };
    build_repo(repo_name.into_string(), path, bare)
}

// --------------------------------------------------------------------------- //
// High-level read operations
// --------------------------------------------------------------------------- //

/// Best-effort name of the repository's default branch.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn default_branch(repo: &Repo) -> Result<String, GitError> {
    let out = run_git(&repo.path, &["symbolic-ref", "--short", "HEAD"])?;
    let text = decode_output(&out.stdout);
    let name = strip(&text);
    if out.code == 0 && !name.is_empty() {
        return Ok(name.to_string());
    }
    let out = run_git(&repo.path, &["rev-parse", "--abbrev-ref", "HEAD"])?;
    let text = decode_output(&out.stdout);
    let name = strip(&text);
    Ok(if name.is_empty() {
        "HEAD".to_string()
    } else {
        name.to_string()
    })
}

/// Resolve `reference` to a full commit sha for sha-pinned permalinks.
///
/// Uses the batch-check reader (peeling annotated tags with `^{commit}`); falls
/// back to the ref's own object id, then to the ref itself. The peel suffix is
/// added server-side, never by a caller.
#[must_use]
pub fn resolve_commit(repo: &Repo, reference: &SafeRef) -> String {
    let reader = catfile(&repo.path);
    if let Some(stat) = reader.check(&format!("{reference}^{{commit}}")) {
        return stat.sha;
    }
    match reader.check(reference.as_str()) {
        Some(stat) => stat.sha,
        None => reference.as_str().to_string(),
    }
}

/// Return `(branch_names, tag_names)` in one `for-each-ref` fork.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn ref_names(repo: &Repo) -> Result<(Vec<String>, Vec<String>), GitError> {
    let out = run_git(
        &repo.path,
        &[
            "for-each-ref",
            "--format=%(refname)",
            "refs/heads/",
            "refs/tags/",
        ],
    )?;
    let mut branches: Vec<String> = Vec::new();
    let mut tags_: Vec<String> = Vec::new();
    if out.code == 0 {
        for line in decode_output(&out.stdout).split('\n') {
            if let Some(rest) = line.strip_prefix("refs/heads/") {
                branches.push(rest.to_string());
            } else if let Some(rest) = line.strip_prefix("refs/tags/") {
                tags_.push(rest.to_string());
            }
        }
    }
    Ok((branches, tags_))
}

/// True if `reference` resolves to an object in `repo`.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn ref_exists(repo: &Repo, reference: &SafeRef) -> Result<bool, GitError> {
    let peeled = format!("{reference}^{{commit}}");
    let out = run_git(
        &repo.path,
        &["rev-parse", "--verify", "--quiet", peeled.as_str()],
    )?;
    if out.code == 0 {
        return Ok(true);
    }
    // Fall back for non-commit objects (e.g. a raw tree/blob sha).
    let out = run_git(
        &repo.path,
        &["rev-parse", "--verify", "--quiet", reference.as_str()],
    )?;
    Ok(out.code == 0)
}

/// One row in a log listing.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct CommitRow {
    /// Full commit sha (`%H`).
    pub sha: String,
    /// Abbreviated sha (`%h`).
    pub short: String,
    /// Author name (`%an`).
    pub author: String,
    /// Author email (`%ae`).
    pub email: String,
    /// Committer timestamp (`%ct`), 0 when unparseable.
    pub ts: i64,
    /// Subject line (`%s`).
    pub subject: String,
}

/// The `--format` used by every log listing.
fn log_format() -> String {
    ["%H", "%h", "%an", "%ae", "%ct", "%s"].join(&FIELD_SEP.to_string())
}

/// Split NUL-separated `-z` records, dropping empties.
fn nul_records(out: &[u8]) -> impl Iterator<Item = &[u8]> {
    out.split(|b| *b == 0).filter(|chunk| !chunk.is_empty())
}

/// Parse `git log -z --format=%H<US>%h<US>%an<US>%ae<US>%ct<US>%s` output.
#[must_use]
pub fn parse_log_records(out: &[u8]) -> Vec<CommitRow> {
    let mut rows: Vec<CommitRow> = Vec::new();
    for chunk in nul_records(out) {
        let text = decode_output(chunk);
        let fields: Vec<&str> = text.split(FIELD_SEP).collect();
        if fields.len() < 6 {
            continue;
        }
        rows.push(CommitRow {
            sha: fields[0].to_string(),
            short: fields[1].to_string(),
            author: fields[2].to_string(),
            email: fields[3].to_string(),
            ts: parse_ts(fields[4]),
            subject: fields[5].to_string(),
        });
    }
    rows
}

/// Return up to `limit` commits starting at `skip` for `reference`.
///
/// # Errors
/// [`GitError::NotFound`] if the ref does not resolve; [`GitError::Failed`] if
/// git cannot be spawned or times out.
pub fn log(
    repo: &Repo,
    reference: &SafeRef,
    skip: usize,
    limit: usize,
) -> Result<Vec<CommitRow>, GitError> {
    let fmt = format!("--format={}", log_format());
    let skip_arg = format!("--skip={skip}");
    let limit_arg = format!("-n{limit}");
    let out = run_git(
        &repo.path,
        &[
            "log",
            &skip_arg,
            &limit_arg,
            "-z",
            &fmt,
            reference.as_str(),
            "--",
        ],
    )?;
    if out.code != 0 {
        return Err(GitError::NotFound("no such ref".to_string()));
    }
    Ok(parse_log_records(&out.stdout))
}

/// Total number of commits reachable from `reference` (for pagination).
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn commit_count(repo: &Repo, reference: &SafeRef) -> Result<u64, GitError> {
    let out = run_git(
        &repo.path,
        &["rev-list", "--count", reference.as_str(), "--"],
    )?;
    let text = decode_output(&out.stdout);
    let raw = strip(&text);
    Ok(if out.code == 0 && is_ascii_digits(raw) {
        raw.parse::<u64>().unwrap_or(0)
    } else {
        0
    })
}

/// A log row plus its parent shas, for drawing the commit graph.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct GraphCommit {
    /// Full commit sha (`%H`).
    pub sha: String,
    /// Abbreviated sha (`%h`).
    pub short: String,
    /// Parent shas (`%P`, space separated).
    pub parents: Vec<String>,
    /// Author name (`%an`).
    pub author: String,
    /// Committer timestamp (`%ct`), 0 when unparseable.
    pub ts: i64,
    /// Subject line (`%s`).
    pub subject: String,
}

/// Parse `git log -z --format=%H<US>%h<US>%P<US>%an<US>%ct<US>%s` output.
#[must_use]
pub fn parse_graph_records(out: &[u8]) -> Vec<GraphCommit> {
    let mut rows: Vec<GraphCommit> = Vec::new();
    for chunk in nul_records(out) {
        let text = decode_output(chunk);
        let fields: Vec<&str> = text.split(FIELD_SEP).collect();
        if fields.len() < 6 {
            continue;
        }
        rows.push(GraphCommit {
            sha: fields[0].to_string(),
            short: fields[1].to_string(),
            parents: split_whitespace_maxsplit(fields[2], usize::MAX)
                .into_iter()
                .map(str::to_string)
                .collect(),
            author: fields[3].to_string(),
            ts: parse_ts(fields[4]),
            subject: fields[5].to_string(),
        });
    }
    rows
}

/// Like [`log`], but also captures each commit's parent shas (`%P`).
///
/// Records are NUL-separated (`-z`) and fields within a record use the
/// [`FIELD_SEP`] byte. Bounded to `limit` rows so the graph a caller draws is
/// page-sized.
///
/// # Errors
/// [`GitError::NotFound`] if the ref does not resolve; [`GitError::Failed`] if
/// git cannot be spawned or times out.
pub fn log_graph(
    repo: &Repo,
    reference: &SafeRef,
    skip: usize,
    limit: usize,
) -> Result<Vec<GraphCommit>, GitError> {
    let sep = FIELD_SEP.to_string();
    let fmt = format!(
        "--format={}",
        ["%H", "%h", "%P", "%an", "%ct", "%s"].join(&sep)
    );
    let skip_arg = format!("--skip={skip}");
    let limit_arg = format!("-n{limit}");
    let out = run_git(
        &repo.path,
        &[
            "log",
            &skip_arg,
            &limit_arg,
            "-z",
            &fmt,
            reference.as_str(),
            "--",
        ],
    )?;
    if out.code != 0 {
        return Err(GitError::NotFound("no such ref".to_string()));
    }
    Ok(parse_graph_records(&out.stdout))
}

/// Commits touching `path` on `reference` (per-file history).
///
/// The pathspec is separated from the options with `--`. `follow` enables
/// rename tracking (git only allows it for a single path).
///
/// # Errors
/// [`GitError::NotFound`] if the ref/path does not resolve; [`GitError::Failed`]
/// if git cannot be spawned or times out.
pub fn log_path(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
    skip: usize,
    limit: usize,
    follow: bool,
) -> Result<Vec<CommitRow>, GitError> {
    let fmt = format!("--format={}", log_format());
    let skip_arg = format!("--skip={skip}");
    let limit_arg = format!("-n{limit}");
    let mut args = vec!["log", &skip_arg, &limit_arg, "-z", &fmt];
    if follow {
        args.push("--follow");
    }
    args.push(reference.as_str());
    args.push("--");
    args.push(path.as_str());
    let out = run_git(&repo.path, &args)?;
    if out.code != 0 {
        return Err(GitError::NotFound("no such ref/path".to_string()));
    }
    Ok(parse_log_records(&out.stdout))
}

/// Number of commits on `reference` that touch `path` (for pagination).
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn commit_count_path(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
) -> Result<u64, GitError> {
    let out = run_git(
        &repo.path,
        &[
            "rev-list",
            "--count",
            reference.as_str(),
            "--",
            path.as_str(),
        ],
    )?;
    let text = decode_output(&out.stdout);
    let raw = strip(&text);
    Ok(if out.code == 0 && is_ascii_digits(raw) {
        raw.parse::<u64>().unwrap_or(0)
    } else {
        0
    })
}

// ---- search (code + commit message) --------------------------------------- //

/// One `git grep` hit: a file path, a 1-based line number and the line.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct GrepMatch {
    /// In-repo path of the matching file.
    pub path: String,
    /// 1-based line number.
    pub lineno: usize,
    /// The matching line, verbatim.
    pub text: String,
}

/// Parse `git grep -n -z` output into matches, honouring the caps.
///
/// `-z` makes git separate the `<path>` from the line number and text with NUL
/// (only the leading `<ref>:` keeps a colon), so a path that itself contains a
/// colon still parses unambiguously. The returned flag is `true` when
/// `max_matches` clipped the list or the byte cap may have clipped the last
/// (partial) record.
#[must_use]
pub fn parse_grep_matches(
    out: &[u8],
    reference: &str,
    max_matches: usize,
) -> (Vec<GrepMatch>, bool) {
    let prefix = format!("{reference}:");
    let mut matches: Vec<GrepMatch> = Vec::new();
    let mut truncated = false;
    let mut clipped = false;
    for record in out.split(|b| *b == b'\n') {
        if record.is_empty() {
            continue;
        }
        if matches.len() >= max_matches {
            truncated = true;
            clipped = true;
            break;
        }
        let fields: Vec<&[u8]> = record.split(|b| *b == 0).collect();
        if fields.len() < 3 {
            continue;
        }
        let raw_path = decode_output(fields[0]);
        // Strip the echoed `<ref>:` prefix to recover the in-repo path.
        let path = raw_path
            .strip_prefix(&prefix)
            .unwrap_or(&raw_path)
            .to_string();
        let lineno_s = decode_output(fields[1]);
        if !is_ascii_digits(&lineno_s) {
            continue;
        }
        let text = decode_output(&fields[2..].join(&0u8));
        matches.push(GrepMatch {
            path,
            lineno: lineno_s.parse::<usize>().unwrap_or(0),
            text,
        });
    }
    if !clipped && out.len() >= GREP_MAX_BYTES {
        // The byte cap may have clipped the last (partial) record, which we
        // conservatively flag as "more".
        truncated = true;
    }
    (matches, truncated)
}

/// Literal code search over the tree at `reference`, with the default cap.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn search_code(
    repo: &Repo,
    reference: &SafeRef,
    query: &SafeQuery,
) -> Result<(Vec<GrepMatch>, bool), GitError> {
    search_code_with(repo, reference, query, GREP_MAX_MATCHES)
}

/// Literal code search over the tree at `reference`; returns `(matches, more)`.
///
/// Runs `git grep -n -I --fixed-strings -e <query> <ref> --`:
///
/// * `--fixed-strings` makes `<query>` a *literal* — never a regex — so a
///   crafted term cannot trigger catastrophic backtracking (ReDoS).
/// * `-e <query>` passes the term as the operand of `-e`, so a term that begins
///   with `-` (e.g. `-n` or `--output`) is data, never an option.
/// * `-I` skips binary files; `--max-count` caps per-file hits; the shared
///   capped/killed reader bounds total stdout ([`GREP_MAX_BYTES`]) and wall time
///   ([`GREP_TIMEOUT`]); parsing stops at `max_matches`. `more` is `true` when
///   any of those caps clipped the result.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn search_code_with(
    repo: &Repo,
    reference: &SafeRef,
    query: &SafeQuery,
    max_matches: usize,
) -> Result<(Vec<GrepMatch>, bool), GitError> {
    let max_count = format!("--max-count={GREP_MAX_COUNT_PER_FILE}");
    let out = run_git_with(
        &repo.path,
        &[
            "grep",
            "-n",
            "-I",
            "--fixed-strings",
            "-z",
            &max_count,
            "-e",
            query.as_str(),
            reference.as_str(),
            "--",
        ],
        RunOptions {
            timeout: Duration::from_secs(GREP_TIMEOUT),
            max_bytes: GREP_MAX_BYTES,
            check: false,
        },
    )?;
    // git grep exit codes: 0 = matches, 1 = no matches, >1 = a real error (e.g.
    // a non-existent ref). Only >1 means "nothing to show for a reason"; treat
    // it as an empty result rather than surfacing a 500. `run_git` already reset
    // the code to 0 if it truncated at the byte cap.
    if out.code > 1 {
        return Ok((Vec::new(), false));
    }
    Ok(parse_grep_matches(
        &out.stdout,
        reference.as_str(),
        max_matches,
    ))
}

/// Commit-message search: commits on `reference` whose message contains `query`.
///
/// `--fixed-strings` + `--grep=<query>` matches the term literally (no
/// regex/ReDoS); the term is a single argv element so it can never be an
/// option. Paginated exactly like [`log`].
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn log_grep(
    repo: &Repo,
    reference: &SafeRef,
    query: &SafeQuery,
    skip: usize,
    limit: usize,
) -> Result<Vec<CommitRow>, GitError> {
    let fmt = format!("--format={}", log_format());
    let skip_arg = format!("--skip={skip}");
    let limit_arg = format!("-n{limit}");
    let grep_arg = format!("--grep={query}");
    let out = run_git(
        &repo.path,
        &[
            "log",
            &skip_arg,
            &limit_arg,
            "-z",
            "--fixed-strings",
            &grep_arg,
            &fmt,
            reference.as_str(),
            "--",
        ],
    )?;
    if out.code != 0 {
        return Ok(Vec::new());
    }
    Ok(parse_log_records(&out.stdout))
}

/// Number of commits on `reference` whose message contains `query` (pager).
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn commit_count_grep(
    repo: &Repo,
    reference: &SafeRef,
    query: &SafeQuery,
) -> Result<u64, GitError> {
    let grep_arg = format!("--grep={query}");
    let out = run_git(
        &repo.path,
        &[
            "rev-list",
            "--count",
            "--fixed-strings",
            &grep_arg,
            reference.as_str(),
            "--",
        ],
    )?;
    let text = decode_output(&out.stdout);
    let raw = strip(&text);
    Ok(if out.code == 0 && is_ascii_digits(raw) {
        raw.parse::<u64>().unwrap_or(0)
    } else {
        0
    })
}

/// Unified diff between two commit-ish refs, via the `diff-tree` plumbing.
///
/// # Errors
/// [`GitError::NotFound`] if the refs cannot be compared; [`GitError::Failed`]
/// if git cannot be spawned or times out.
pub fn compare(repo: &Repo, base: &SafeRef, other: &SafeRef) -> Result<String, GitError> {
    let out = run_git(
        &repo.path,
        &[
            "diff-tree",
            "--patch",
            "-r",
            "-M",
            "--no-color",
            base.as_str(),
            other.as_str(),
            "--",
        ],
    )?;
    if out.code != 0 {
        return Err(GitError::NotFound("cannot compare those refs".to_string()));
    }
    Ok(decode_output(&out.stdout)
        .trim_start_matches('\n')
        .to_string())
}

/// Yield a `git archive` `tar.gz` stream for `reference` in bounded chunks.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned.
pub fn stream_archive(
    repo: &Repo,
    reference: &SafeRef,
    prefix: &str,
) -> Result<GitStream, GitError> {
    stream_archive_with(repo, reference, prefix, DEFAULT_CHUNK_SIZE, 0)
}

/// Yield a `git archive` `tar.gz` stream for `reference` in bounded chunks.
///
/// `prefix` is sanitised to a filename-safe token upstream and is glued into a
/// single `--prefix=…` argv element, so it can never be read as a separate
/// option. The child is torn down when the stream is dropped; `max_bytes`
/// (> 0) caps the bytes produced.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned.
pub fn stream_archive_with(
    repo: &Repo,
    reference: &SafeRef,
    prefix: &str,
    chunk_size: usize,
    max_bytes: usize,
) -> Result<GitStream, GitError> {
    let mut cmd = base_cmd(&repo.path);
    cmd.arg("archive")
        .arg("--format=tar.gz")
        .arg(format!("--prefix={prefix}"))
        .arg(reference.as_str())
        // Terminate options; the ref is the tree-ish, no pathspecs follow.
        .arg("--");
    cmd.stdout(Stdio::piped())
        .stderr(Stdio::null())
        .stdin(Stdio::null());
    harden_env(&mut cmd, None);
    own_process_group(&mut cmd);
    GitStream::spawn(cmd, chunk_size, max_bytes, None)
}

/// Full metadata for a single commit.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Commit {
    /// Full commit sha (`%H`).
    pub sha: String,
    /// Abbreviated sha (`%h`).
    pub short: String,
    /// Author name (`%an`).
    pub author_name: String,
    /// Author email (`%ae`).
    pub author_email: String,
    /// Author date, strict ISO 8601 (`%aI`).
    pub author_date: String,
    /// Committer name (`%cn`).
    pub committer_name: String,
    /// Committer email (`%ce`).
    pub committer_email: String,
    /// Committer date, strict ISO 8601 (`%cI`).
    pub committer_date: String,
    /// Parent shas (`%P`).
    pub parents: Vec<String>,
    /// Subject line (`%s`).
    pub subject: String,
    /// Message body (`%b`), trailing newlines removed.
    pub body: String,
    /// `git %G?`: `N`=none, `G`=good, `U`=good/unknown, `B`=bad, …
    pub signature_status: String,
    /// `git %GK`: the signing key.
    pub signing_key: String,
}

impl Commit {
    /// A cryptographically good signature (valid, or valid-but-unknown).
    #[must_use]
    pub fn signature_verified(&self) -> bool {
        self.signature_status == "G" || self.signature_status == "U"
    }

    /// True when the commit carries any signature at all.
    #[must_use]
    pub fn signature_present(&self) -> bool {
        !self.signature_status.is_empty() && self.signature_status != "N"
    }
}

/// Normalise a strict-ISO timestamp from git's `%aI`/`%cI` to ONE form.
///
/// git renders a zero UTC offset as `+00:00` up to 2.43 and as the RFC 3339 `Z`
/// designator on newer releases. Both name the same instant, but these strings
/// are a public contract — they reach the JSON API, the CMS bridge and the Atom
/// feeds, and they are what the byte-identity goldens pin — so the crate's
/// output must not shift under the operator's `git` upgrade. Everything is
/// emitted in the reference's form, `+00:00`.
///
/// This is a real failure, not a hypothetical: `commit_meta_matches_python`
/// asserted `2020-01-02T00:00:00+00:00` and got `2020-01-02T00:00:00Z` purely
/// because the machine running it had a newer git than the machine that froze
/// the golden.
#[must_use]
pub fn normalize_iso_date(date: &str) -> String {
    match date.strip_suffix('Z') {
        Some(head) => format!("{head}+00:00"),
        None => date.to_string(),
    }
}

/// Parse the 13-field `git show -s --format=…` record of [`commit_meta`].
///
/// `%b` (body) is placed before the two signature fields; neither the body nor
/// the signature fields ever contain the [`FIELD_SEP`] byte, so positional
/// splitting stays unambiguous.
///
/// Both timestamps pass through [`normalize_iso_date`], so the parsed record is
/// the same whatever version of git produced it.
#[must_use]
pub fn parse_commit_record(out: &[u8]) -> Option<Commit> {
    if out.is_empty() {
        return None;
    }
    let text = decode_output(out);
    let fields: Vec<&str> = text.split(FIELD_SEP).collect();
    if fields.len() < 13 {
        return None;
    }
    let status = strip(fields[11]);
    Some(Commit {
        sha: fields[0].to_string(),
        short: fields[1].to_string(),
        author_name: fields[2].to_string(),
        author_email: fields[3].to_string(),
        author_date: normalize_iso_date(fields[4]),
        committer_name: fields[5].to_string(),
        committer_email: fields[6].to_string(),
        committer_date: normalize_iso_date(fields[7]),
        parents: split_whitespace_maxsplit(fields[8], usize::MAX)
            .into_iter()
            .map(str::to_string)
            .collect(),
        subject: fields[9].to_string(),
        body: fields[10].trim_end_matches('\n').to_string(),
        signature_status: if status.is_empty() {
            "N".to_string()
        } else {
            status.to_string()
        },
        signing_key: strip(fields[12]).to_string(),
    })
}

/// Return metadata for the commit `rev`.
///
/// `log.showSignature=false` (the base argv) keeps any signature block out of
/// the formatted output.
///
/// # Errors
/// [`GitError::NotFound`] if the commit does not resolve; [`GitError::Failed`]
/// if git cannot be spawned or times out.
pub fn commit_meta(repo: &Repo, rev: &SafeRef) -> Result<Commit, GitError> {
    let sep = FIELD_SEP.to_string();
    let fmt = format!(
        "--format={}",
        ["%H", "%h", "%an", "%ae", "%aI", "%cn", "%ce", "%cI", "%P", "%s", "%b", "%G?", "%GK",]
            .join(&sep)
    );
    let out = run_git(&repo.path, &["show", "-s", &fmt, rev.as_str(), "--"])?;
    if out.code != 0 {
        return Err(GitError::NotFound("no such commit".to_string()));
    }
    parse_commit_record(&out.stdout).ok_or_else(|| GitError::NotFound("no such commit".to_string()))
}

/// Return the unified diff for `rev` as text (empty header).
///
/// # Errors
/// [`GitError::NotFound`] if the commit does not resolve; [`GitError::Failed`]
/// if git cannot be spawned or times out.
pub fn commit_patch(repo: &Repo, rev: &SafeRef) -> Result<String, GitError> {
    let out = run_git(
        &repo.path,
        &[
            "show",
            "--patch",
            "--no-color",
            "-M",
            "--format=",
            rev.as_str(),
            "--",
        ],
    )?;
    if out.code != 0 {
        return Err(GitError::NotFound("no such commit".to_string()));
    }
    Ok(decode_output(&out.stdout)
        .trim_start_matches('\n')
        .to_string())
}

/// Return the mailbox-format patch for a single commit, with the default caps.
///
/// # Errors
/// [`GitError::NotFound`] for an unknown rev or a commit that serialises to
/// nothing; [`GitError::Failed`] if git cannot be spawned or times out.
pub fn format_patch(repo: &Repo, rev: &SafeRef) -> Result<Vec<u8>, GitError> {
    format_patch_with(
        repo,
        rev,
        Duration::from_secs(PATCH_TIMEOUT),
        PATCH_MAX_BYTES,
    )
}

/// Return the mailbox-format patch for a single commit `rev` (for `git am`).
///
/// Runs `git format-patch -1 --stdout <rev> --`; the output opens with a
/// `From <sha> Mon Sep 17 00:00:00 2001` mbox header. Output is drained through
/// the shared capped/killed reader, so a pathological commit can exceed neither
/// `max_bytes` of RAM nor `timeout`. Returns raw bytes so the patch is served
/// verbatim. (For a merge, `format-patch -1` follows git's own convention and
/// emits the first-parent change; a merge has no single `git am`-able patch of
/// its own.)
///
/// # Errors
/// [`GitError::NotFound`] for an unknown rev or a commit that serialises to
/// nothing; [`GitError::Failed`] if git cannot be spawned or times out.
pub fn format_patch_with(
    repo: &Repo,
    rev: &SafeRef,
    timeout: Duration,
    max_bytes: usize,
) -> Result<Vec<u8>, GitError> {
    let out = run_git_with(
        &repo.path,
        &["format-patch", "-1", "--stdout", rev.as_str(), "--"],
        RunOptions {
            timeout,
            max_bytes,
            check: false,
        },
    )?;
    if out.code != 0 || out.stdout.is_empty() {
        return Err(GitError::NotFound(
            "no patch for this commit (unknown, or a merge)".to_string(),
        ));
    }
    Ok(out.stdout)
}

// ---- tree ----------------------------------------------------------------- //

/// One entry of a tree listing.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct TreeEntry {
    /// The octal file mode, e.g. `100644`.
    pub mode: String,
    /// `blob` | `tree` | `commit` (a submodule gitlink).
    pub otype: String,
    /// The entry's object id.
    pub sha: String,
    /// Blob size in bytes; `None` for a tree/gitlink (git prints `-`).
    pub size: Option<u64>,
    /// The entry's basename.
    pub name: String,
    /// The entry's full path from the repository root.
    pub path: String,
}

/// Parse `git ls-tree --long -z` output for the tree at `path`.
///
/// Directories sort first, then files, each alphabetically (case-folded).
#[must_use]
pub fn parse_tree_entries(out: &[u8], path: &str) -> Vec<TreeEntry> {
    let mut entries: Vec<TreeEntry> = Vec::new();
    for chunk in nul_records(out) {
        let text = decode_output(chunk);
        let Some((meta, name)) = text.split_once('\t') else {
            continue;
        };
        let parts = split_whitespace_maxsplit(meta, usize::MAX);
        if parts.len() < 4 {
            continue;
        }
        let size = parts[3];
        entries.push(TreeEntry {
            mode: parts[0].to_string(),
            otype: parts[1].to_string(),
            sha: parts[2].to_string(),
            size: if is_ascii_digits(size) {
                size.parse::<u64>().ok()
            } else {
                None
            },
            name: name.to_string(),
            path: if path.is_empty() {
                name.to_string()
            } else {
                format!("{path}/{name}")
            },
        });
    }
    // Directories first, then files, each alphabetically.
    entries.sort_by(|a, b| {
        (a.otype != "tree", py_lower(&a.name)).cmp(&(b.otype != "tree", py_lower(&b.name)))
    });
    entries
}

/// List the immediate children of the tree at `reference`/`path`.
///
/// # Errors
/// [`GitError::NotFound`] if the tree does not resolve; [`GitError::Failed`] if
/// git cannot be spawned or times out.
pub fn list_tree(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
) -> Result<Vec<TreeEntry>, GitError> {
    let spec = object_spec(reference, path);
    let out = run_git(&repo.path, &["ls-tree", "--long", "-z", &spec, "--"])?;
    if out.code != 0 {
        return Err(GitError::NotFound("no such tree".to_string()));
    }
    Ok(parse_tree_entries(&out.stdout, path.as_str()))
}

// ---- blob ----------------------------------------------------------------- //

/// Return the git object type at `reference`/`path`, or `None` if absent.
#[must_use]
pub fn object_type(repo: &Repo, reference: &SafeRef, path: &SafePath) -> Option<String> {
    stat_object(repo, reference, path).map(|stat| stat.otype)
}

/// Size in bytes of the object at `reference`/`path` (0 if absent).
#[must_use]
pub fn blob_size(repo: &Repo, reference: &SafeRef, path: &SafePath) -> u64 {
    stat_object(repo, reference, path).map_or(0, |stat| stat.size)
}

/// Read up to `max_bytes` of a blob's content into memory.
///
/// Routed through the persistent `cat-file --batch` reader; a blob larger than
/// `max_bytes` is capped (and the batch process respawned) so memory stays
/// bounded.
///
/// # Errors
/// [`GitError::NotFound`] if the blob does not exist (which includes a
/// repo-derived path carrying a control character — see [`GitCatFile::spec_ok`]).
pub fn read_blob(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
    max_bytes: usize,
) -> Result<Vec<u8>, GitError> {
    match catfile(&repo.path).read(&object_spec(reference, path), max_bytes) {
        Some(read) => Ok(read.data),
        None => Err(GitError::NotFound("no such blob".to_string())),
    }
}

/// Read a blob by a *repo-derived* (not URL-validated) path.
///
/// Tree listings can contain any byte but NUL and `/`, so this takes a plain
/// `&str`; the spec still goes through [`GitCatFile::spec_ok`], which refuses
/// any control character and so cannot desynchronise the shared batch stream.
///
/// # Errors
/// [`GitError::NotFound`] if the blob does not exist or the path is refused.
pub fn read_blob_raw_path(
    repo: &Repo,
    reference: &SafeRef,
    path: &str,
    max_bytes: usize,
) -> Result<Vec<u8>, GitError> {
    let spec = if path.is_empty() {
        reference.as_str().to_string()
    } else {
        format!("{reference}:{path}")
    };
    match catfile(&repo.path).read(&spec, max_bytes) {
        Some(read) => Ok(read.data),
        None => Err(GitError::NotFound("no such blob".to_string())),
    }
}

/// Parse `.gitmodules` INI text into `(submodule_path, url)` pairs.
///
/// Insertion-ordered like the reference's `dict`: a repeated `path` keeps its
/// first position and takes the last value.
#[must_use]
pub fn parse_gitmodules(text: &str) -> Vec<(String, String)> {
    let mut result: Vec<(String, String)> = Vec::new();
    let mut cur_path: Option<String> = None;
    let mut cur_url: Option<String> = None;

    fn flush(
        result: &mut Vec<(String, String)>,
        cur_path: &Option<String>,
        cur_url: &Option<String>,
    ) {
        let Some(path) = cur_path else { return };
        if path.is_empty() {
            return;
        }
        let url = cur_url.clone().unwrap_or_default();
        match result.iter_mut().find(|(key, _)| key == path) {
            Some(slot) => slot.1 = url,
            None => result.push((path.clone(), url)),
        }
    }

    for raw in text.split('\n') {
        let line = strip(raw);
        if line.starts_with('[') && line.ends_with(']') {
            flush(&mut result, &cur_path, &cur_url);
            cur_path = None;
            cur_url = None;
            continue;
        }
        let Some((key, value)) = line.split_once('=') else {
            continue;
        };
        let key = py_lower(strip(key));
        let value = strip(value);
        if key == "path" {
            cur_path = Some(value.to_string());
        } else if key == "url" {
            cur_url = Some(value.to_string());
        }
    }
    flush(&mut result, &cur_path, &cur_url);
    result
}

/// Return the `(path, url)` submodule map for `reference` (or empty).
#[must_use]
pub fn read_gitmodules(repo: &Repo, reference: &SafeRef) -> Vec<(String, String)> {
    let spec = format!("{reference}:.gitmodules");
    match catfile(&repo.path).read(&spec, GITMODULES_MAX_BYTES) {
        Some(read) if !read.data.is_empty() && read.stat.otype == "blob" => {
            parse_gitmodules(&decode_output(&read.data))
        }
        _ => Vec::new(),
    }
}

/// A parsed git-lfs pointer (the small text file that stands in for a blob).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct LfsPointer {
    /// The 64-hex sha-256 object id.
    pub oid: String,
    /// The real object's size in bytes.
    pub size: u64,
}

/// `^[0-9a-f]{64}$`
fn lfs_oid_ok(oid: &str) -> bool {
    oid.len() == 64
        && oid
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

/// Detect a git-lfs pointer file and return its oid/size, else `None`.
///
/// A pointer is a tiny UTF-8 file beginning with the LFS spec version line and
/// carrying `oid sha256:<hex>` and `size <n>` fields. The oid is validated as
/// 64 lowercase hex so it can never be turned into a traversal path.
#[must_use]
pub fn parse_lfs_pointer(data: &[u8]) -> Option<LfsPointer> {
    if data.is_empty() || data.len() > 1024 {
        return None;
    }
    let text = std::str::from_utf8(data).ok()?;
    if !text.starts_with("version https://git-lfs.github.com/spec/") {
        return None;
    }
    let mut oid = String::new();
    let mut size: i128 = -1;
    for line in splitlines(text) {
        if let Some(rest) = line.strip_prefix("oid sha256:") {
            oid = strip(rest).to_string();
        } else if let Some(rest) = line.strip_prefix("size ") {
            let raw = strip(rest);
            if is_ascii_digits(raw) {
                size = raw.parse::<i128>().unwrap_or(-1);
            }
        }
    }
    if oid.is_empty() || size < 0 || !lfs_oid_ok(&oid) {
        return None;
    }
    Some(LfsPointer {
        oid,
        size: u64::try_from(size).ok()?,
    })
}

/// Return the local path of an LFS object, or `None` if not stored locally.
///
/// Git-LFS lays objects out at `lfs/objects/<oid[:2]>/<oid[2:4]>/<oid>` under
/// the git dir — `<repo>/lfs/…` for a bare repo and `<repo>/.git/lfs/…` for a
/// worktree. The oid is validated as 64 lowercase hex (no `/` or `..`), and
/// each candidate is canonicalised and confined under the repo so a symlinked
/// `lfs` tree cannot point the read outside the repository. This never contacts
/// a remote LFS server — a missing object simply returns `None` and the caller
/// keeps showing the pointer.
#[must_use]
pub fn lfs_object_path(repo: &Repo, oid: &str) -> Option<PathBuf> {
    if !lfs_oid_ok(oid) {
        return None;
    }
    let rel = Path::new("lfs")
        .join("objects")
        .join(&oid[0..2])
        .join(&oid[2..4])
        .join(oid);
    let repo_real = std::fs::canonicalize(repo.path.as_path()).ok()?;
    for base in [repo_real.clone(), repo_real.join(".git")] {
        let Ok(real) = std::fs::canonicalize(base.join(&rel)) else {
            continue;
        };
        // Confinement: must resolve to somewhere under the repository.
        if real != repo_real && !real.starts_with(&repo_real) {
            continue;
        }
        if real.is_file() {
            return Some(real);
        }
    }
    None
}

/// Size in bytes of a local file (0 if it cannot be stat'd).
#[must_use]
pub fn lfs_object_size(path: &Path) -> u64 {
    std::fs::metadata(path).map(|m| m.len()).unwrap_or(0)
}

/// Read up to `max_bytes` bytes of a local (confined) file.
#[must_use]
pub fn read_file(path: &Path, max_bytes: usize) -> Vec<u8> {
    if max_bytes == 0 {
        return Vec::new();
    }
    let Ok(file) = File::open(path) else {
        return Vec::new();
    };
    let mut buf = Vec::new();
    match file.take(max_bytes as u64).read_to_end(&mut buf) {
        Ok(_) => buf,
        Err(_) => Vec::new(),
    }
}

/// Return at most [`DEFAULT_PEEK_BYTES`] of a local file, for binary sniffing.
#[must_use]
pub fn peek_file(path: &Path) -> Vec<u8> {
    read_file(path, DEFAULT_PEEK_BYTES)
}

/// Return at most `n` bytes of a local file, for binary sniffing.
#[must_use]
pub fn peek_file_with(path: &Path, n: usize) -> Vec<u8> {
    read_file(path, n)
}

/// Yield a local file's bytes in [`DEFAULT_CHUNK_SIZE`] chunks.
///
/// # Errors
/// [`GitError::Failed`] if the file cannot be opened.
pub fn stream_file(path: &Path) -> Result<FileStream, GitError> {
    stream_file_with(path, DEFAULT_CHUNK_SIZE, 0)
}

/// Yield a local file's bytes in bounded chunks (for serving an LFS object).
///
/// No subprocess is involved — this is a plain filesystem read of a path the
/// caller has already confined via [`lfs_object_path`]. `max_bytes` (> 0) caps
/// the bytes produced; dropping the stream closes the file.
///
/// # Errors
/// [`GitError::Failed`] if the file cannot be opened.
pub fn stream_file_with(
    path: &Path,
    chunk_size: usize,
    max_bytes: usize,
) -> Result<FileStream, GitError> {
    let file = File::open(path)
        .map_err(|e| GitError::Failed(format!("cannot open {}: {e}", path.display())))?;
    Ok(FileStream {
        file,
        chunk_size: chunk_size.max(1),
        max_bytes,
        sent: 0,
        done: false,
    })
}

/// Yield a blob's bytes in [`DEFAULT_CHUNK_SIZE`] chunks (for `/raw`).
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned.
pub fn stream_blob(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
) -> Result<GitStream, GitError> {
    stream_blob_with(repo, reference, path, DEFAULT_CHUNK_SIZE, 0)
}

/// Yield a blob's bytes in chunks (for the `/raw` endpoint).
///
/// The git child is torn down when the stream is dropped or exhausted. If
/// `max_bytes` > 0 the stream stops after that many bytes.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned.
pub fn stream_blob_with(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
    chunk_size: usize,
    max_bytes: usize,
) -> Result<GitStream, GitError> {
    let spec = object_spec(reference, path);
    let mut cmd = Command::new(GIT);
    cmd.arg("--no-pager")
        .arg("-c")
        .arg("safe.directory=*")
        .arg("-C")
        .arg(repo.path.as_os_str())
        .arg("cat-file")
        .arg("-p")
        // Terminate options so a validated spec can never be read as one.
        .arg("--")
        .arg(&spec);
    cmd.stdout(Stdio::piped())
        .stderr(Stdio::null())
        .stdin(Stdio::null());
    harden_env(&mut cmd, None);
    own_process_group(&mut cmd);
    GitStream::spawn(cmd, chunk_size, max_bytes, None)
}

/// Return at most [`DEFAULT_PEEK_BYTES`] of a blob, for binary sniffing.
#[must_use]
pub fn peek_blob(repo: &Repo, reference: &SafeRef, path: &SafePath) -> Vec<u8> {
    peek_blob_with(repo, reference, path, DEFAULT_PEEK_BYTES)
}

/// Return at most `n` bytes of a blob, for binary sniffing.
///
/// Backed by the persistent `cat-file --batch` reader with a hard `n`-byte cap:
/// peeking at a multi-gigabyte blob still costs ~`n` bytes because the reader
/// stops (and respawns git) the moment the cap is reached.
#[must_use]
pub fn peek_blob_with(repo: &Repo, reference: &SafeRef, path: &SafePath, n: usize) -> Vec<u8> {
    if n == 0 {
        return Vec::new();
    }
    match catfile(&repo.path).read(&object_spec(reference, path), n) {
        Some(read) => {
            let mut data = read.data;
            data.truncate(n);
            data
        }
        None => Vec::new(),
    }
}

/// Heuristic: a NUL byte in the first 8 KiB means binary.
#[must_use]
pub fn is_binary(data: &[u8]) -> bool {
    data[..data.len().min(DEFAULT_PEEK_BYTES)].contains(&0)
}

// ---- refs ----------------------------------------------------------------- //

/// Whether a [`RefRow`] describes a branch or a tag.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RefKind {
    /// A `refs/heads/…` ref.
    Branch,
    /// A `refs/tags/…` ref.
    Tag,
}

impl RefKind {
    /// The reference's string spelling of this kind.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            RefKind::Branch => "branch",
            RefKind::Tag => "tag",
        }
    }
}

/// One branch or tag, with the metadata of the object it points at.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RefRow {
    /// The short ref name (`%(refname:short)`).
    pub name: String,
    /// Branch or tag.
    pub kind: RefKind,
    /// The abbreviated target sha (`%(objectname:short)`).
    pub target: String,
    /// The tag message / commit subject (`%(contents:subject)`).
    pub subject: String,
    /// Creator date as a unix timestamp, 0 when unparseable.
    pub ts: i64,
    /// The author name (`%(authorname)`).
    pub author: String,
}

/// Parse `for-each-ref` output whose fields are [`FIELD_SEP`]-separated.
#[must_use]
pub fn parse_ref_records(out: &[u8], kind: RefKind) -> Vec<RefRow> {
    let mut rows: Vec<RefRow> = Vec::new();
    for line in decode_output(out).split('\n') {
        if line.is_empty() {
            continue;
        }
        let fields: Vec<&str> = line.split(FIELD_SEP).collect();
        if fields.len() < 5 {
            continue;
        }
        rows.push(RefRow {
            name: fields[0].to_string(),
            kind,
            target: fields[1].to_string(),
            subject: fields[2].to_string(),
            ts: parse_ts(fields[3]),
            author: fields[4].to_string(),
        });
    }
    rows
}

/// List the refs matching `pattern`, newest first.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn for_each_ref(
    repo: &Repo,
    pattern: &RefPattern,
    kind: RefKind,
) -> Result<Vec<RefRow>, GitError> {
    let sep = FIELD_SEP.to_string();
    let fmt = format!(
        "--format={}",
        [
            "%(refname:short)",
            "%(objectname:short)",
            "%(contents:subject)",
            "%(creatordate:unix)",
            "%(authorname)",
        ]
        .join(&sep)
    );
    let out = run_git(
        &repo.path,
        &[
            "for-each-ref",
            "--sort=-creatordate",
            &fmt,
            pattern.as_str(),
        ],
    )?;
    if out.code != 0 {
        return Ok(Vec::new());
    }
    Ok(parse_ref_records(&out.stdout, kind))
}

/// Every branch, newest first.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn branches(repo: &Repo) -> Result<Vec<RefRow>, GitError> {
    for_each_ref(repo, &RefPattern::heads(), RefKind::Branch)
}

/// Every tag, newest first.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned or times out.
pub fn tags(repo: &Repo) -> Result<Vec<RefRow>, GitError> {
    for_each_ref(repo, &RefPattern::tags(), RefKind::Tag)
}

// ---- blame ---------------------------------------------------------------- //

/// One line of a blame listing.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct BlameLine {
    /// The first 8 characters of the commit sha.
    pub short: String,
    /// The commit's author name.
    pub author: String,
    /// The 1-based line number in the final file.
    pub lineno: usize,
    /// The line's content, with the porcelain tab prefix removed.
    pub content: String,
}

/// `^([0-9a-f]{40}) (\d+) (\d+)(?: (\d+))?$` — returns `(sha, final_lineno)`.
fn match_blame_header(line: &str) -> Option<(&str, usize)> {
    let bytes = line.as_bytes();
    if bytes.len() < 40 {
        return None;
    }
    // Check on *bytes* first: the scan treats every line as a candidate header,
    // including arbitrary UTF-8 file content, and slicing at 40 would panic
    // mid-character. 40 ASCII hex digits make the split safe.
    if !bytes[..40]
        .iter()
        .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(b))
    {
        return None;
    }
    let (sha, rest) = line.split_at(40);
    let mut groups = rest.split(' ');
    // `rest` starts with the separating space, so the first split element is "".
    if groups.next() != Some("") {
        return None;
    }
    let orig = groups.next()?;
    let final_lineno = groups.next()?;
    if !is_ascii_digits(orig) || !is_ascii_digits(final_lineno) {
        return None;
    }
    if let Some(count) = groups.next() {
        if !is_ascii_digits(count) || groups.next().is_some() {
            return None;
        }
    }
    Some((sha, final_lineno.parse::<usize>().unwrap_or(0)))
}

/// Parse `git blame --porcelain` output into per-line records.
#[must_use]
pub fn parse_blame(out: &[u8]) -> Vec<BlameLine> {
    let text = decode_output(out);
    let lines: Vec<&str> = text.split('\n').collect();
    let mut authors: HashMap<String, String> = HashMap::new();
    let mut result: Vec<BlameLine> = Vec::new();
    let mut i = 0usize;
    let n = lines.len();
    while i < n {
        let header = lines[i];
        i += 1;
        let Some((sha, final_lineno)) = match_blame_header(header) else {
            continue;
        };
        let mut author: Option<String> = authors.get(sha).cloned();
        // Consume the optional metadata block up to the tab-prefixed content.
        while i < n && !lines[i].starts_with('\t') {
            let meta = lines[i];
            i += 1;
            if let Some(rest) = meta.strip_prefix("author ") {
                author = Some(rest.to_string());
            }
        }
        let mut content = String::new();
        if i < n && lines[i].starts_with('\t') {
            content = lines[i][1..].to_string();
            i += 1;
        }
        if let Some(name) = author.clone() {
            authors.entry(sha.to_string()).or_insert(name);
        }
        result.push(BlameLine {
            short: sha[..8].to_string(),
            author: authors
                .get(sha)
                .cloned()
                .unwrap_or_else(|| author.unwrap_or_default()),
            lineno: final_lineno,
            content,
        });
    }
    result
}

/// Blame `path` at `reference`.
///
/// # Errors
/// [`GitError::NotFound`] if the path cannot be blamed; [`GitError::Failed`] if
/// git cannot be spawned or times out.
pub fn blame(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
) -> Result<Vec<BlameLine>, GitError> {
    let out = run_git(
        &repo.path,
        &[
            "blame",
            "--porcelain",
            reference.as_str(),
            "--",
            path.as_str(),
        ],
    )?;
    if out.code != 0 {
        return Err(GitError::NotFound("cannot blame path".to_string()));
    }
    Ok(parse_blame(&out.stdout))
}

// --------------------------------------------------------------------------- //
// Git Smart HTTP (read-only clone / fetch) transport
// --------------------------------------------------------------------------- //
//
// The clone/fetch endpoints run *only* `git upload-pack` — the read side of the
// pack protocol. Every invocation:
//   * uses the argv-only, hardened form (no shell; global/system config off);
//   * pins the repo as an explicit positional path, after `--` so it can never
//     be read as an option (though a server-resolved realpath is never
//     option-like anyway);
//   * pins config that keeps the transport read-only and *default-deny*:
//       - `uploadpack.allowFilter=false`        -> no partial-clone filters;
//       - `uploadpack.allow*SHA1InWant=false`   -> a client can only fetch
//         objects reachable from an advertised ref, never an arbitrary
//         unreferenced object it happens to know the sha of.
//
// `receive-pack` (push) is never constructed here.

/// `-c` hardening shared by every `upload-pack` invocation.
const UPLOAD_PACK_CONFIG: &[&str] = &[
    "-c",
    "safe.directory=*",
    "-c",
    "uploadpack.allowFilter=false",
    "-c",
    "uploadpack.allowAnySHA1InWant=false",
    "-c",
    "uploadpack.allowReachableSHA1InWant=false",
    "-c",
    "uploadpack.allowTipSHA1InWant=false",
];

fn upload_pack_cmd(repo: &RepoPath, advertise: bool, protocol_v2: bool) -> Command {
    let mut cmd = Command::new(GIT);
    for arg in UPLOAD_PACK_CONFIG {
        cmd.arg(arg);
    }
    cmd.arg("upload-pack").arg("--stateless-rpc");
    if advertise {
        cmd.arg("--advertise-refs");
    }
    cmd.arg("--").arg(repo.as_os_str());
    harden_env(
        &mut cmd,
        if protocol_v2 {
            Some(("GIT_PROTOCOL", "version=2"))
        } else {
            None
        },
    );
    // Own process group so a timeout kill reaps the whole subtree.
    own_process_group(&mut cmd);
    cmd
}

/// Return the ref advertisement for `GET /<repo>/info/refs`, default caps.
///
/// # Errors
/// [`GitError::Failed`] on a timeout, an exec failure, or an empty
/// advertisement.
pub fn upload_pack_advertise(repo: &Repo, protocol_v2: bool) -> Result<Vec<u8>, GitError> {
    upload_pack_advertise_with(
        repo,
        protocol_v2,
        Duration::from_secs(UPLOAD_PACK_TIMEOUT),
        UPLOAD_PACK_ADVERTISE_MAX_BYTES,
    )
}

/// Return the ref advertisement for `GET /<repo>/info/refs`.
///
/// Runs `git upload-pack --stateless-rpc --advertise-refs <repo>` and returns
/// its raw stdout (the pkt-line advertisement). The output is drained through
/// the shared capped/killed reader, so a runaway or pathological repo can
/// neither exceed `max_bytes` of memory nor `timeout` of wall time. The HTTP
/// layer prepends the `# service=git-upload-pack` banner (protocol v0/v1 only —
/// v2 has no banner).
///
/// # Errors
/// [`GitError::Failed`] on a timeout, an exec failure, or an empty
/// advertisement.
pub fn upload_pack_advertise_with(
    repo: &Repo,
    protocol_v2: bool,
    timeout: Duration,
    max_bytes: usize,
) -> Result<Vec<u8>, GitError> {
    let mut cmd = upload_pack_cmd(&repo.path, true, protocol_v2);
    cmd.stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .stdin(Stdio::null());
    let mut child = spawn(cmd)?;
    let cap = capture_capped(&mut child, max_bytes, timeout);
    if cap.timed_out {
        return Err(GitError::Failed(
            "git upload-pack (advertise) timed out".to_string(),
        ));
    }
    if cap.out.is_empty() {
        let err = decode_output(&cap.err);
        return Err(GitError::Failed(format!(
            "git upload-pack produced no advertisement: {}",
            strip(&err)
        )));
    }
    Ok(cap.out)
}

/// Stream the `POST /<repo>/git-upload-pack` result, with the default caps.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned.
pub fn upload_pack_rpc(
    repo: &Repo,
    payload: Vec<u8>,
    protocol_v2: bool,
) -> Result<GitStream, GitError> {
    upload_pack_rpc_with(
        repo,
        payload,
        protocol_v2,
        Duration::from_secs(UPLOAD_PACK_TIMEOUT),
        DEFAULT_CHUNK_SIZE,
    )
}

/// Stream the `POST /<repo>/git-upload-pack` result.
///
/// Runs `git upload-pack --stateless-rpc <repo>`, feeds the (already read,
/// size-bounded and — if the client gzipped it — inflated) request `payload` to
/// git's stdin on a writer thread, and yields git's stdout in `chunk_size`
/// pieces. Yielding straight to the socket means a pack of *any* size streams
/// with only a chunk resident (packs are never buffered in RAM).
///
/// An overall wall-clock `timeout` bounds pack generation: a hostile
/// want/deepen set that would make git churn is killed on the deadline.
/// Dropping the stream (client disconnect, or the deadline) tears the child
/// down.
///
/// # Errors
/// [`GitError::Failed`] if git cannot be spawned.
pub fn upload_pack_rpc_with(
    repo: &Repo,
    payload: Vec<u8>,
    protocol_v2: bool,
    timeout: Duration,
    chunk_size: usize,
) -> Result<GitStream, GitError> {
    let mut cmd = upload_pack_cmd(&repo.path, false, protocol_v2);
    cmd.stdout(Stdio::piped())
        .stderr(Stdio::null())
        .stdin(Stdio::piped());
    let mut stream = GitStream::spawn(cmd, chunk_size, 0, Some(timeout))?;
    // Feed stdin on a separate thread: git may start emitting the pack (filling
    // its stdout pipe) before it has consumed all of stdin, so writing the whole
    // request inline could deadlock. `payload` is already size-capped, so the
    // thread cannot buffer an unbounded amount.
    if let Some(mut stdin) = stream.stdin.take() {
        thread::spawn(move || {
            if !payload.is_empty() {
                let _ = stdin.write_all(&payload);
            }
            let _ = stdin.flush();
            drop(stdin);
        });
    }
    Ok(stream)
}

// --------------------------------------------------------------------------- //
// Bounded byte streams
// --------------------------------------------------------------------------- //

/// A child-backed byte stream: `git archive`, `/raw` blobs and `upload-pack`.
///
/// Iterating yields chunks of at most the configured size. The child is killed
/// (process group and all) and reaped when the stream is exhausted, hits its
/// byte cap or deadline, or is dropped — the port of the reference generator's
/// `finally` block.
pub struct GitStream {
    child: Option<Child>,
    rx: Option<Receiver<Vec<u8>>>,
    stdin: Option<ChildStdin>,
    deadline: Option<Instant>,
    max_bytes: usize,
    sent: usize,
    done: bool,
}

impl GitStream {
    /// Spawn `cmd` (already fully configured: stdio, env hardening, group) and
    /// start pumping its stdout.
    fn spawn(
        cmd: Command,
        chunk_size: usize,
        max_bytes: usize,
        timeout: Option<Duration>,
    ) -> Result<GitStream, GitError> {
        let mut child = spawn(cmd)?;
        let stdin = child.stdin.take();
        let (tx, rx) = mpsc::sync_channel::<Vec<u8>>(1);
        if let Some(stream) = child.stdout.take() {
            spawn_pump(stream, tx, chunk_size.max(1), |data| data);
        }
        Ok(GitStream {
            child: Some(child),
            rx: Some(rx),
            stdin,
            deadline: timeout.map(|t| Instant::now() + t),
            max_bytes,
            sent: 0,
            done: false,
        })
    }

    /// Kill the child and release the pipes. Idempotent.
    fn teardown(&mut self) {
        self.done = true;
        self.rx = None;
        self.stdin = None;
        if let Some(mut child) = self.child.take() {
            kill_process_group(&mut child);
            let _ = child.wait();
        }
    }
}

impl Iterator for GitStream {
    type Item = Vec<u8>;

    fn next(&mut self) -> Option<Vec<u8>> {
        if self.done {
            return None;
        }
        let received = {
            let Some(rx) = self.rx.as_ref() else {
                self.teardown();
                return None;
            };
            match self.deadline {
                Some(deadline) => {
                    let remaining = deadline.saturating_duration_since(Instant::now());
                    if remaining.is_zero() {
                        None
                    } else {
                        rx.recv_timeout(remaining).ok()
                    }
                }
                None => rx.recv().ok(),
            }
        };
        let Some(data) = received else {
            self.teardown();
            return None;
        };
        if self.max_bytes != 0 && self.sent + data.len() > self.max_bytes {
            let keep = self.max_bytes - self.sent;
            self.teardown();
            return Some(data[..keep].to_vec());
        }
        self.sent += data.len();
        Some(data)
    }
}

impl Drop for GitStream {
    fn drop(&mut self) {
        self.teardown();
    }
}

/// A local-file byte stream (for serving an LFS object).
pub struct FileStream {
    file: File,
    chunk_size: usize,
    max_bytes: usize,
    sent: usize,
    done: bool,
}

impl Iterator for FileStream {
    type Item = Vec<u8>;

    fn next(&mut self) -> Option<Vec<u8>> {
        if self.done {
            return None;
        }
        let mut buf = vec![0u8; self.chunk_size];
        let got = loop {
            match self.file.read(&mut buf) {
                Ok(n) => break n,
                Err(ref e) if e.kind() == std::io::ErrorKind::Interrupted => {}
                Err(_) => break 0,
            }
        };
        if got == 0 {
            self.done = true;
            return None;
        }
        buf.truncate(got);
        if self.max_bytes != 0 && self.sent + got > self.max_bytes {
            let keep = self.max_bytes - self.sent;
            buf.truncate(keep);
            self.done = true;
            return Some(buf);
        }
        self.sent += got;
        Some(buf)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Join fields with the unit separator, as every `--format` here does.
    fn rec(fields: &[&str]) -> String {
        fields.join(&FIELD_SEP.to_string())
    }

    // -- helpers ---------------------------------------------------------- //

    #[test]
    fn ascii_helpers_match_python() {
        assert_eq!(decode_ascii(b"abc"), "abc");
        assert_eq!(decode_ascii(b"a\xffb"), "a\u{fffd}b");
        assert_eq!(decode_ascii(b""), "");

        assert!(is_ascii_digits("0"));
        assert!(is_ascii_digits("1234567890"));
        assert!(!is_ascii_digits(""));
        assert!(!is_ascii_digits("-1"));
        assert!(!is_ascii_digits("1.0"));
        assert!(!is_ascii_digits(" 1"));
        // Documented divergence: a Unicode digit is "not a number" here, where
        // CPython's `str.isdigit()` accepts it (and `int()` may then raise).
        assert!(!is_ascii_digits("٣"));

        assert_eq!(split_ascii_ws(b"a  b\tc\nd"), [b"a", b"b", b"c", b"d"]);
        assert!(split_ascii_ws(b"   ").is_empty());
        assert_eq!(split_ascii_ws(b" abc \n"), [b"abc"]);

        // Python's `str.lower()` has no final-sigma special case (unlike
        // `str::to_lowercase`), which the tree sort depends on.
        assert_eq!(py_lower("ΑΣ"), "ασ");
        assert_eq!(py_lower("İ"), "i\u{307}");
        assert_eq!(py_lower("ABC"), "abc");

        assert_eq!(parse_ts("1700000000"), 1_700_000_000);
        assert_eq!(parse_ts(""), 0);
        assert_eq!(parse_ts("nope"), 0);
        assert_eq!(parse_ts(&"9".repeat(30)), 0);

        assert_eq!(universal_newlines("a\r\nb\rc\nd"), "a\nb\nc\nd");
        assert_eq!(universal_newlines("plain"), "plain");
        assert_eq!(universal_newlines("\r"), "\n");
    }

    // -- validators ------------------------------------------------------- //

    #[test]
    fn newtypes_only_wrap_validated_values() {
        assert_eq!(
            RepoName::parse("myrepo")
                .map(|n| n.into_string())
                .as_deref(),
            Some("myrepo")
        );
        assert!(RepoName::parse("..").is_none());
        assert!(SafeRef::parse("refs/heads/").is_none());
        assert!(RefPattern::parse("refs/heads/").is_some());
        assert!(RefPattern::parse("-x").is_none());
        assert_eq!(SafePath::root().as_str(), "");
        assert!(SafePath::root().is_root());
        assert!(!SafePath::parse("a").expect("valid").is_root());
        assert_eq!(SafeQuery::parse("a b").expect("valid").to_string(), "a b");
        // The 256 bound counts code points, as Python's `len(str)` does.
        assert!(valid_ref(&"a".repeat(256)));
        assert!(!valid_ref(&"a".repeat(257)));
        // A non-ASCII ref is out of the charset regardless of its length.
        assert!(!valid_ref("é"));
        // …but a path's 4096 bound really is measured in code points.
        assert!(valid_path(&"é".repeat(4096)));
        assert!(!valid_path(&"é".repeat(4097)));
    }

    #[test]
    fn object_spec_needs_both_components_validated() {
        let reference = SafeRef::parse("main").expect("valid");
        assert_eq!(object_spec(&reference, &SafePath::root()), "main");
        assert_eq!(
            object_spec(&reference, &SafePath::parse("a/b.txt").expect("valid")),
            "main:a/b.txt"
        );
        // `:` is refused in a ref precisely so the spec stays unambiguous.
        assert!(SafeRef::parse("main:evil").is_none());
    }

    // -- record parsers, fed captured git output -------------------------- //

    #[test]
    fn parse_log_records_skips_malformed_records() {
        let mut out = Vec::new();
        out.extend_from_slice(rec(&["sha1", "s1", "Ann", "a@e", "100", "subject one"]).as_bytes());
        out.push(0);
        out.push(0); // an empty record is skipped
        out.extend_from_slice(rec(&["sha2", "s2", "Bob", "b@e", "notanumber", "two"]).as_bytes());
        out.push(0);
        out.extend_from_slice(rec(&["sha3", "s3", "too", "few"]).as_bytes()); // < 6 fields
        out.push(0);
        // Extra fields are ignored (a subject containing the separator cannot
        // happen: git strips control characters from `%s`).
        out.extend_from_slice(rec(&["sha4", "s4", "C", "c@e", "7", "four", "extra"]).as_bytes());

        let rows = parse_log_records(&out);
        assert_eq!(rows.len(), 3);
        assert_eq!(rows[0].sha, "sha1");
        assert_eq!(rows[0].ts, 100);
        assert_eq!(rows[0].subject, "subject one");
        assert_eq!(rows[1].ts, 0, "a non-numeric timestamp degrades to 0");
        assert_eq!(rows[2].subject, "four");
        assert!(parse_log_records(b"").is_empty());
    }

    #[test]
    fn parse_graph_records_splits_parents_on_whitespace_runs() {
        let out = rec(&["sha", "s", "p1  p2\tp3", "Ann", "5", "merge"]);
        let rows = parse_graph_records(out.as_bytes());
        assert_eq!(rows[0].parents, ["p1", "p2", "p3"]);
        let root = rec(&["sha", "s", "", "Ann", "5", "root"]);
        assert!(parse_graph_records(root.as_bytes())[0].parents.is_empty());
    }

    #[test]
    fn parse_tree_entries_sorts_directories_first_case_insensitively() {
        let mut out = Vec::new();
        for line in [
            "100644 blob aaa      10\tZebra.txt",
            "040000 tree bbb       -\tzdir",
            "100644 blob ccc       6\tapple.txt",
            "160000 commit ddd     -\tvendor",
            "100755 blob eee      18\trun.sh",
            "malformed-without-a-tab",
            "100644 blob\tshort.txt",
        ] {
            out.extend_from_slice(line.as_bytes());
            out.push(0);
        }
        let entries = parse_tree_entries(&out, "sub");
        let names: Vec<&str> = entries.iter().map(|e| e.name.as_str()).collect();
        assert_eq!(
            names,
            ["zdir", "apple.txt", "run.sh", "vendor", "Zebra.txt"]
        );
        assert_eq!(entries[0].size, None, "a tree's `-` size is None");
        assert_eq!(entries[1].size, Some(6));
        assert_eq!(entries[1].path, "sub/apple.txt");
        assert_eq!(entries[2].mode, "100755");
        assert_eq!(entries[3].otype, "commit");
        // The repository root keeps the bare name as the path.
        let entries = parse_tree_entries(&out, "");
        assert_eq!(entries[1].path, "apple.txt");
    }

    #[test]
    fn parse_ref_records_needs_five_fields() {
        let out = format!(
            "{}\n{}\n\n{}\n",
            rec(&["main", "abc1234", "subject", "1700000000", "Ann"]),
            rec(&["short", "abc", "only three"]),
            rec(&["v1.0", "def5678", "tag msg", "notanumber", ""]),
        );
        let rows = parse_ref_records(out.as_bytes(), RefKind::Tag);
        assert_eq!(rows.len(), 2);
        assert_eq!(rows[0].name, "main");
        assert_eq!(rows[0].kind, RefKind::Tag);
        assert_eq!(rows[0].ts, 1_700_000_000);
        assert_eq!(rows[1].ts, 0);
        assert_eq!(rows[1].author, "");
    }

    #[test]
    fn parse_commit_record_handles_the_optional_fields() {
        let full = rec(&[
            "sha",
            "sh",
            "An",
            "a@e",
            "2020-01-01T00:00:00+00:00",
            "Cn",
            "c@e",
            "2020-01-02T00:00:00+00:00",
            "p1 p2",
            "subject",
            "body line\n\n",
            "  G  ",
            " KEY ",
        ]);
        let commit = parse_commit_record(full.as_bytes()).expect("parses");
        assert_eq!(commit.parents, ["p1", "p2"]);
        assert_eq!(commit.body, "body line", "trailing newlines are stripped");
        assert_eq!(commit.signature_status, "G");
        assert_eq!(commit.signing_key, "KEY");
        assert!(commit.signature_verified());
        assert!(commit.signature_present());

        let unsigned = rec(&[
            "sha", "sh", "An", "a@e", "d", "Cn", "c@e", "d", "", "subject", "", "", "",
        ]);
        let commit = parse_commit_record(unsigned.as_bytes()).expect("parses");
        assert!(commit.parents.is_empty());
        assert_eq!(commit.signature_status, "N", "an empty %G? defaults to N");
        assert!(!commit.signature_verified());
        assert!(!commit.signature_present());

        assert!(parse_commit_record(b"").is_none());
        assert!(parse_commit_record(rec(&["a"; 12]).as_bytes()).is_none());
        assert!(parse_commit_record(rec(&["a"; 13]).as_bytes()).is_some());
    }

    #[test]
    fn parse_blame_reuses_the_author_and_never_panics_on_content() {
        // Captured `git blame --porcelain` shape: the author appears once per
        // commit and is reused by every later line of the same commit.
        let out = concat!(
            "1111111111111111111111111111111111111111 1 1 2\n",
            "author Alice\n",
            "author-mail <a@e>\n",
            "summary first\n",
            "filename f.txt\n",
            "\tline one\n",
            "1111111111111111111111111111111111111111 2 2\n",
            "\tline two\n",
            "2222222222222222222222222222222222222222 1 3 1\n",
            "author Bob\n",
            "filename f.txt\n",
            "\t\tindented content\n",
        );
        let lines = parse_blame(out.as_bytes());
        assert_eq!(lines.len(), 3);
        assert_eq!(lines[0].short, "11111111");
        assert_eq!(lines[0].author, "Alice");
        assert_eq!(lines[0].lineno, 1);
        assert_eq!(lines[0].content, "line one");
        assert_eq!(lines[1].author, "Alice", "the author is remembered per sha");
        assert_eq!(lines[1].lineno, 2);
        assert_eq!(lines[2].author, "Bob");
        assert_eq!(lines[2].content, "\tindented content");

        // Regression: every line is tested as a header, including file content.
        // A multibyte character at byte 40 must not panic the header scan.
        let hostile = format!("\t{}\n{}\n", "é".repeat(40), "ありがとう".repeat(20));
        assert!(parse_blame(hostile.as_bytes()).is_empty());
        // A 40-hex prefix that is *not* a header (wrong field shapes) is skipped.
        for bad in [
            "1111111111111111111111111111111111111111\n",
            "1111111111111111111111111111111111111111 x 1\n",
            "1111111111111111111111111111111111111111 1\n",
            "1111111111111111111111111111111111111111 1 2 3 4\n",
            "111111111111111111111111111111111111111g 1 2\n",
            "1111111111111111111111111111111111111111  1 2\n",
        ] {
            assert!(parse_blame(bad.as_bytes()).is_empty(), "{bad:?}");
        }
        assert!(parse_blame(b"").is_empty());
    }

    #[test]
    fn parse_grep_matches_handles_colon_paths_and_caps() {
        let mut out = Vec::new();
        for (path, lineno, text) in [
            ("main:src/main.py", "4", "hit one"),
            ("main:weird dir/a:b.txt", "2", "hit two"),
            ("no-prefix.txt", "9", "hit three"),
        ] {
            out.extend_from_slice(path.as_bytes());
            out.push(0);
            out.extend_from_slice(lineno.as_bytes());
            out.push(0);
            out.extend_from_slice(text.as_bytes());
            out.push(b'\n');
        }
        // A record whose "line number" is not numeric is skipped, as is one with
        // fewer than three NUL fields.
        out.extend_from_slice(b"main:x\0notanumber\0text\n");
        out.extend_from_slice(b"main:y\0only-two\n");

        let (matches, more) = parse_grep_matches(&out, "main", GREP_MAX_MATCHES);
        assert!(!more);
        assert_eq!(matches.len(), 3);
        assert_eq!(matches[0].path, "src/main.py");
        assert_eq!(
            matches[1].path, "weird dir/a:b.txt",
            "a colon in the path survives"
        );
        assert_eq!(matches[1].lineno, 2);
        assert_eq!(matches[2].path, "no-prefix.txt");

        // A line that itself contains a NUL is rejoined verbatim.
        let (matches, _) = parse_grep_matches(b"main:f\x001\0a\0b\n", "main", 10);
        assert_eq!(matches[0].text, "a\u{0}b");

        // The parse-time cap flags "more".
        let (matches, more) = parse_grep_matches(&out, "main", 2);
        assert_eq!(matches.len(), 2);
        assert!(more);

        // So does hitting the byte cap without breaking out of the scan.
        let big = vec![b'\n'; GREP_MAX_BYTES];
        let (matches, more) = parse_grep_matches(&big, "main", GREP_MAX_MATCHES);
        assert!(matches.is_empty());
        assert!(more);
    }

    #[test]
    fn parse_gitmodules_keeps_the_first_position_and_the_last_value() {
        let pairs = parse_gitmodules(
            "[a]\npath = one\nurl = u1\n[b]\npath = two\nurl = u2\n[c]\npath = one\nurl = u3\n",
        );
        assert_eq!(
            pairs,
            vec![
                ("one".to_string(), "u3".to_string()),
                ("two".to_string(), "u2".to_string()),
            ]
        );
        assert!(parse_gitmodules("").is_empty());
        assert!(parse_gitmodules("[only-a-section]\n").is_empty());
    }

    #[test]
    fn lfs_oids_are_strictly_64_lowercase_hex() {
        assert!(lfs_oid_ok(&"a".repeat(64)));
        assert!(lfs_oid_ok(&"0123456789abcdef".repeat(4)));
        assert!(!lfs_oid_ok(&"A".repeat(64)));
        assert!(!lfs_oid_ok(&"a".repeat(63)));
        assert!(!lfs_oid_ok(&"a".repeat(65)));
        assert!(!lfs_oid_ok("../../../../etc/passwd"));
        assert!(!lfs_oid_ok(""));
        assert!(!lfs_oid_ok(&"g".repeat(64)));
    }

    #[test]
    fn read_upto_stops_at_eof() {
        let data = b"0123456789";
        assert_eq!(read_upto(&mut &data[..], 4).expect("read"), b"0123");
        assert_eq!(read_upto(&mut &data[..], 100).expect("read"), data);
        assert!(read_upto(&mut &data[..], 0).expect("read").is_empty());
    }

    #[test]
    fn run_git_refuses_a_subcommand_outside_the_allow_list() {
        // No process is spawned: the check happens before anything is built.
        // (`RepoPath` cannot be forged, so this uses the one place that can mint
        // one — a repository resolved from a root — via the exec tests. Here we
        // only assert the allow-list itself, which is pure.)
        assert!(is_allowed_subcommand("log"));
        assert!(is_allowed_subcommand("upload-pack"));
        assert!(!is_allowed_subcommand("receive-pack"));
        assert!(!is_allowed_subcommand("LOG"));
        assert!(!is_allowed_subcommand(""));
        assert_eq!(ALLOWED_SUBCOMMANDS.len(), 14);
    }

    #[test]
    fn is_binary_only_looks_at_the_first_8_kib() {
        let mut data = vec![b'a'; DEFAULT_PEEK_BYTES];
        data.push(0);
        assert!(
            !is_binary(&data),
            "a NUL past the sniff window is invisible"
        );
        data[0] = 0;
        assert!(is_binary(&data));
        assert!(!is_binary(b""));
    }
}

#[cfg(test)]
mod git_version_regression {
    use super::*;

    /// git renders a zero UTC offset as `+00:00` up to 2.43 and as the RFC 3339
    /// `Z` on newer releases, so `commit_meta`'s output — a public contract that
    /// reaches the JSON API, the CMS bridge and the Atom feeds — used to change
    /// under the operator's git upgrade. `commit_meta_matches_python` failed for
    /// exactly this reason on a newer host: expected `2020-01-02T00:00:00+00:00`,
    /// got `2020-01-02T00:00:00Z`.
    #[test]
    fn a_utc_commit_date_is_the_same_string_on_every_git_version() {
        assert_eq!(
            normalize_iso_date("2020-01-02T00:00:00Z"),
            "2020-01-02T00:00:00+00:00"
        );
        assert_eq!(
            normalize_iso_date("2020-01-02T00:00:00+00:00"),
            "2020-01-02T00:00:00+00:00"
        );
        // A real non-UTC offset is untouched, and so is anything unexpected.
        assert_eq!(
            normalize_iso_date("2020-01-02T00:00:00+01:00"),
            "2020-01-02T00:00:00+01:00"
        );
        assert_eq!(
            normalize_iso_date("2020-01-02T00:00:00-05:30"),
            "2020-01-02T00:00:00-05:30"
        );
        assert_eq!(normalize_iso_date(""), "");
    }

    /// The normalisation must run on BOTH dates of a parsed record, whichever
    /// form the local git emitted.
    #[test]
    fn both_commit_dates_are_normalised_whatever_git_emitted() {
        let rec = [
            "sha",
            "sh",
            "An",
            "a@e",
            "2020-01-01T00:00:00Z", // author, new-git form
            "Cn",
            "c@e",
            "2020-01-02T00:00:00Z", // committer, new-git form
            "",
            "subject",
            "",
            "N",
            "",
        ]
        .join(&FIELD_SEP.to_string());
        let c = parse_commit_record(rec.as_bytes()).expect("parses");
        assert_eq!(c.author_date, "2020-01-01T00:00:00+00:00");
        assert_eq!(c.committer_date, "2020-01-02T00:00:00+00:00");
    }
}
