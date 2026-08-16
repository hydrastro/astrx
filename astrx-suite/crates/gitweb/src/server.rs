//! The HTTP serving tier — a port of the Python `gitweb.server`.
//!
//! Following the suite's serving convention (`websearch::serve`,
//! `suitedash::server`), the routing and the whole request→response mapping are
//! **pure and socket-free**, and only the accept loop lives behind the `net`
//! feature:
//!
//! * [`Route`] is a pure `(method, target, url-prefix) -> route` decision — no
//!   filesystem, no git, no clock — so path parsing, prefix mounting and the
//!   rejection of a hostile ref/path/repo id are unit-testable on their own.
//! * [`Server::route`] maps a [`Request`] (the handful of headers the reference
//!   reads, plus an already-read POST body) to a [`Resp`]: status, header list
//!   and body. It runs git and touches the repository root, but never a socket,
//!   so every status code, content type, cache validator, security header, the
//!   error pages and the auth challenge are asserted without listening anywhere.
//! * [`Server::handle`] is [`Server::route`] wrapped in the metrics hooks
//!   ([`crate::metrics::registry`]), exactly as the reference's `_dispatch` is.
//! * `net` adds only [`serve`] / [`serve_config`]: accept, read a request head,
//!   call [`Server::handle`] on a blocking worker, write the reply.
//!
//! Every response carries the reference's security headers — [`CSP`],
//! `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
//! `Referrer-Policy: no-referrer` — and HTML responses additionally negotiate
//! `gzip`/`deflate` and carry a strong `ETag` folded with [`RENDER_VERSION`].
//!
//! # Documented divergences
//!
//! * **No `Date`/`Server` header.** CPython's `BaseHTTPRequestHandler` emits both
//!   automatically; omitting them keeps a response head a pure function of the
//!   response (the same choice `suitedash::server` documents).
//! * **Compressed bytes differ.** The `Content-Encoding` negotiation, the coded
//!   `ETag` suffix and the decoded body are identical to the reference's; the
//!   compressed byte stream is this crate's own fixed-Huffman DEFLATE (see
//!   `crate::deflate`) rather than zlib's dynamic-Huffman output.
//! * **Syntax highlighting is not ported** (there is no Pygments): the blob view
//!   always renders the escaped-plaintext fallback, which is what the reference
//!   does with its default `syntax_highlight = False`. `--highlight` is accepted
//!   by the CLI for parity and is a no-op.
//! * **A `--auth-file` that cannot be read** is a clean startup error here; in
//!   CPython the `OSError` escapes as a traceback. Both refuse to start.

use std::path::{Path, PathBuf};
use std::time::Duration;

use crate::auth::{check_basic_auth, parse_auth_spec, Credential};
use crate::deflate::{gzip_compress, zlib_compress};
use crate::gitcmd::{
    self, blame, branches, commit_count, commit_count_grep, commit_count_path, commit_meta,
    commit_patch, compare, default_branch, discover_repos, format_patch, is_binary,
    lfs_object_path, lfs_object_size, list_tree, log, log_graph, log_grep, log_path,
    parse_lfs_pointer, peek_blob, peek_file, read_blob, read_file, read_gitmodules, ref_names,
    resolve_commit, resolve_repo, search_code, stat_object, stream_archive_with, stream_blob_with,
    stream_file_with, tags, upload_pack_advertise_with, upload_pack_rpc_with, valid_path,
    FileStream, GitError, GitStream, LfsPointer, Repo, SafePath, SafeQuery, SafeRef,
};
use crate::mailarchive;
use crate::markup::{parse_patch, render_markdown, render_readme};
use crate::metrics::registry;
use crate::views::{self, BlobView, Ctx, HistoryView, Readme, RefChoices, SearchView, TreeView};
use crawlcore::hash::{sha256, to_hex};
use crawlcore::inflate::{inflate_gzip, inflate_zlib};
use crawlcore::urlparse::{parse_qsl, quote, unquote, urlsplit};

// --------------------------------------------------------------------------- //
// Constants
// --------------------------------------------------------------------------- //

/// Bumped when the HTML rendering changes so cached ETags invalidate. Folded
/// into every strong ETag alongside the object sha and path.
pub const RENDER_VERSION: &str = "gitweb-r2";

/// The Content-Security-Policy every response carries. `form-action 'self'` (not
/// `'none'`) so the no-JS search/filter GET forms can submit to this origin,
/// while submitting to any external target stays forbidden.
pub const CSP: &str = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; \
                       base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

/// Cap on a request head (request line + headers) the server will buffer.
pub const MAX_REQUEST_HEAD: usize = 64 * 1024;

/// Longest span a `?highlight=a-b` range may cover.
const MAX_HIGHLIGHT_SPAN: usize = 5000;

/// Blob extensions rendered inline as images. SVG is served with the strict
/// [`CSP`] + `nosniff`, which blocks any embedded scripting.
const IMAGE_TYPES: [(&str, &str); 8] = [
    ("png", "image/png"),
    ("jpg", "image/jpeg"),
    ("jpeg", "image/jpeg"),
    ("gif", "image/gif"),
    ("webp", "image/webp"),
    ("bmp", "image/bmp"),
    ("ico", "image/x-icon"),
    ("svg", "image/svg+xml"),
];

/// The image content type for `ext`, if it names one.
fn image_type(ext: &str) -> Option<&'static str> {
    IMAGE_TYPES.iter().find(|(k, _)| *k == ext).map(|(_, v)| *v)
}

/// Lower-cased file extension of `path` without the dot (or `""`).
fn ext_of(path: &str) -> String {
    let base = path.rsplit('/').next().unwrap_or(path);
    match base.rfind('.') {
        Some(dot) if dot > 0 => base[dot + 1..].to_lowercase(),
        _ => String::new(),
    }
}

/// Python `re.sub(r"[^A-Za-z0-9._-]+", "_", s)` — collapse every run of unsafe
/// characters to a single `_`, so a repo/ref name cannot shape a header.
fn safe_filename(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut in_run = false;
    for c in s.chars() {
        if c.is_ascii_alphanumeric() || matches!(c, '.' | '_' | '-') {
            out.push(c);
            in_run = false;
        } else if !in_run {
            out.push('_');
            in_run = true;
        }
    }
    out
}

/// `^[0-9a-f]{40}$` — a full commit sha names an immutable object.
fn is_full_sha(s: &str) -> bool {
    s.len() == 40
        && s.bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

/// Flush packet (pkt-line `0000`).
const PKT_FLUSH: &[u8] = b"0000";

/// Encode `data` as a single git pkt-line (4-hex length prefix + data).
fn pkt_line(data: &[u8]) -> Vec<u8> {
    let mut out = format!("{:04x}", data.len() + 4).into_bytes();
    out.extend_from_slice(data);
    out
}

/// Parse a `highlight` value (`"5"` or `"5-10"`) into the 1-based line numbers
/// it names.
///
/// Bounded to a sane span so a hostile `1-99999999` cannot allocate a huge set;
/// anything unparseable yields nothing.
fn parse_line_range(spec: &str) -> Vec<usize> {
    let spec = spec.trim().trim_start_matches('L');
    if spec.is_empty() {
        return Vec::new();
    }
    let digits = |s: &str| -> Option<usize> {
        if !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit()) {
            s.parse::<usize>().ok()
        } else {
            None
        }
    };
    if let Some((a, b)) = spec.split_once('-') {
        let b = b.trim_start_matches('L');
        let (Some(a), Some(b)) = (digits(a), digits(b)) else {
            return Vec::new();
        };
        let (lo, mut hi) = if a > b { (b, a) } else { (a, b) };
        if hi - lo > MAX_HIGHLIGHT_SPAN {
            hi = lo + MAX_HIGHLIGHT_SPAN;
        }
        return (lo..=hi).collect();
    }
    digits(spec).map(|n| vec![n]).unwrap_or_default()
}

// --------------------------------------------------------------------------- //
// Configuration
// --------------------------------------------------------------------------- //

/// Runtime configuration for a gitweb server.
#[derive(Clone, Debug, PartialEq)]
pub struct Config {
    /// Directory that directly contains the repositories to serve.
    pub root: PathBuf,
    /// Address to bind.
    pub host: String,
    /// TCP port.
    pub port: u16,
    /// Commits per log page.
    pub page_size: usize,
    /// Inline blob-render cap, in bytes.
    pub max_blob_bytes: u64,
    /// `/raw` streaming cap, in bytes.
    pub raw_max_bytes: u64,
    /// `/archive` streaming cap, in bytes.
    pub archive_max_bytes: usize,
    /// Cap on a README read for rendering.
    pub readme_bytes: usize,
    /// Directory of read-only per-repo `<name>.mbox` patch archives.
    pub patches_dir: String,
    /// Commits listed on the summary page.
    pub summary_commits: usize,
    /// Tree entries per page (the huge-directory guard).
    pub tree_page_size: usize,
    /// Entries in an Atom feed.
    pub feed_commits: usize,
    /// Bounded worker pool (the Slowloris / thread guard).
    pub max_workers: usize,
    /// Per-connection socket read timeout, in seconds.
    pub socket_timeout: f64,
    /// Reverse-proxy sub-path mount, e.g. `/git`.
    pub url_prefix: String,
    /// Log one structured line per request.
    pub verbose: bool,
    /// Serve `git-upload-pack` over HTTP (off ⇒ the clone endpoints 404).
    pub enable_clone: bool,
    /// Overall wall-clock budget for one `upload-pack` call, in seconds.
    pub clone_timeout: f64,
    /// POST body cap (after inflation), in bytes.
    pub clone_max_body_bytes: usize,
    /// Concurrent `upload-pack` RPCs (kept below `max_workers`).
    pub clone_max_concurrency: usize,
    /// External origin for the advertised clone URL.
    pub clone_base_url: String,
    /// `user:sha256$salt$hex` credential; when set, every request needs auth.
    pub auth: String,
    /// Path to a file whose first non-comment line is the auth spec.
    pub auth_file: String,
}

impl Default for Config {
    fn default() -> Self {
        Config {
            root: PathBuf::new(),
            host: "127.0.0.1".to_string(),
            port: 8801,
            page_size: 50,
            max_blob_bytes: 2 * 1024 * 1024,
            raw_max_bytes: 50 * 1024 * 1024,
            archive_max_bytes: 200 * 1024 * 1024,
            readme_bytes: 512 * 1024,
            patches_dir: String::new(),
            summary_commits: 10,
            tree_page_size: 500,
            feed_commits: 20,
            max_workers: 32,
            socket_timeout: 30.0,
            url_prefix: String::new(),
            verbose: true,
            enable_clone: true,
            clone_timeout: 120.0,
            clone_max_body_bytes: 25 * 1024 * 1024,
            clone_max_concurrency: 4,
            clone_base_url: String::new(),
            auth: String::new(),
            auth_file: String::new(),
        }
    }
}

impl Config {
    /// A configuration serving the repositories directly under `root`.
    #[must_use]
    pub fn new(root: impl Into<PathBuf>) -> Self {
        Config {
            root: root.into(),
            ..Config::default()
        }
    }
}

/// Normalise a reverse-proxy prefix: leading `/`, no trailing `/`.
#[must_use]
pub fn normalize_prefix(prefix: &str) -> String {
    let prefix = prefix.trim();
    if prefix.is_empty() {
        return String::new();
    }
    let with_slash = if prefix.starts_with('/') {
        prefix.to_string()
    } else {
        format!("/{prefix}")
    };
    with_slash.trim_end_matches('/').to_string()
}

// --------------------------------------------------------------------------- //
// Routing (pure)
// --------------------------------------------------------------------------- //

/// One of the per-repository actions the router accepts.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Action {
    /// `refs` — branches and tags.
    Refs,
    /// `releases` — the tag-derived release list.
    Releases,
    /// `releases.atom` — the release feed.
    ReleasesAtom,
    /// `patches` — the read-only mail/patch archive.
    Patches,
    /// `patches.mbox` — one thread as an mbox download.
    PatchesMbox,
    /// `log` — the paginated commit log.
    Log,
    /// `commit` — one commit and its diff.
    Commit,
    /// `tree` — a directory listing.
    Tree,
    /// `blob` — one file.
    Blob,
    /// `raw` — one file's bytes, streamed.
    Raw,
    /// `blame` — per-line authorship.
    Blame,
    /// `history` — a per-file log.
    History,
    /// `atom` — the commit feed.
    Atom,
    /// `archive` — a `tar.gz` snapshot, streamed.
    Archive,
    /// `compare` — the diff between two refs.
    Compare,
    /// `search` — code and commit-message search.
    Search,
    /// `graph` — the inline-SVG commit graph.
    Graph,
    /// `patch` — a mailbox patch (alias of `commit.patch`).
    Patch,
    /// `commit.patch` — a mailbox patch.
    CommitPatch,
    /// `opensearch.xml` — the per-repo search descriptor.
    OpenSearch,
}

impl Action {
    /// The URL token (and the metrics label) for this action.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Action::Refs => "refs",
            Action::Releases => "releases",
            Action::ReleasesAtom => "releases.atom",
            Action::Patches => "patches",
            Action::PatchesMbox => "patches.mbox",
            Action::Log => "log",
            Action::Commit => "commit",
            Action::Tree => "tree",
            Action::Blob => "blob",
            Action::Raw => "raw",
            Action::Blame => "blame",
            Action::History => "history",
            Action::Atom => "atom",
            Action::Archive => "archive",
            Action::Compare => "compare",
            Action::Search => "search",
            Action::Graph => "graph",
            Action::Patch => "patch",
            Action::CommitPatch => "commit.patch",
            Action::OpenSearch => "opensearch.xml",
        }
    }

    /// Parse a URL path segment into an action.
    #[must_use]
    pub fn parse(token: &str) -> Option<Action> {
        [
            Action::Refs,
            Action::Releases,
            Action::ReleasesAtom,
            Action::Patches,
            Action::PatchesMbox,
            Action::Log,
            Action::Commit,
            Action::Tree,
            Action::Blob,
            Action::Raw,
            Action::Blame,
            Action::History,
            Action::Atom,
            Action::Archive,
            Action::Compare,
            Action::Search,
            Action::Graph,
            Action::Patch,
            Action::CommitPatch,
            Action::OpenSearch,
        ]
        .into_iter()
        .find(|a| a.as_str() == token)
    }
}

/// The routing decision for one request — a pure function of the method, the
/// request target and the configured mount prefix.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Route {
    /// `/` — the repository list.
    Home,
    /// `/health` — liveness.
    Health,
    /// `/metrics` — the Prometheus exposition.
    Metrics,
    /// `/opensearch.xml` — the site-level search descriptor.
    OpensearchSite,
    /// `/<repo>/` — the summary page.
    Summary {
        /// The URL repository id (still unvalidated).
        repo: String,
    },
    /// `/<repo>/info/refs` — the Smart-HTTP advertisement.
    InfoRefs {
        /// The URL repository id (still unvalidated).
        repo: String,
    },
    /// `/<repo>/<action>` — a browse action.
    Action {
        /// The URL repository id (still unvalidated).
        repo: String,
        /// The action.
        action: Action,
    },
    /// `/<repo>/<unknown>` (or too many segments) — the repository is resolved
    /// first, then this 404s, exactly as the reference does.
    UnknownAction {
        /// The URL repository id (still unvalidated).
        repo: String,
    },
    /// `POST /<repo>/git-upload-pack` — the read-only pack RPC.
    UploadPack {
        /// The URL repository id (still unvalidated).
        repo: String,
    },
    /// `POST /<repo>/git-receive-pack` — push, always refused.
    ReceivePack {
        /// The URL repository id (still unvalidated).
        repo: String,
    },
    /// A `GET`/`HEAD` path that matches nothing (`404 unknown path`).
    NotFound,
    /// A `POST` path that matches nothing (`404 not found`).
    PostNotFound,
}

impl Route {
    /// The route `method` + `target` selects under the mount `prefix`.
    ///
    /// The path is split **before** percent-decoding each segment, so an encoded
    /// `%2f` can never introduce a new path separator.
    #[must_use]
    pub fn of(method: &str, target: &str, prefix: &str) -> Route {
        let split = urlsplit(target, "");
        let mut raw_path = split.path;
        let post = method == "POST";
        if !prefix.is_empty() {
            if raw_path == prefix || raw_path == format!("{prefix}/") {
                raw_path = "/".to_string();
            } else if let Some(rest) = raw_path.strip_prefix(&format!("{prefix}/")) {
                raw_path = format!("/{rest}");
            } else {
                return Route::NotFound;
            }
        }
        let segments: Vec<String> = raw_path
            .split('/')
            .filter(|s| !s.is_empty())
            .map(unquote)
            .collect();

        if post {
            if segments.len() != 2 {
                return Route::PostNotFound;
            }
            return match segments[1].as_str() {
                "git-receive-pack" => Route::ReceivePack {
                    repo: segments[0].clone(),
                },
                "git-upload-pack" => Route::UploadPack {
                    repo: segments[0].clone(),
                },
                _ => Route::PostNotFound,
            };
        }

        if segments.len() == 1 {
            match segments[0].as_str() {
                "health" => return Route::Health,
                "metrics" => return Route::Metrics,
                "opensearch.xml" => return Route::OpensearchSite,
                _ => {}
            }
        }
        let Some(repo) = segments.first().cloned() else {
            return Route::Home;
        };
        if segments.len() == 3 && segments[1] == "info" && segments[2] == "refs" {
            return Route::InfoRefs { repo };
        }
        if segments.len() == 1 {
            return Route::Summary { repo };
        }
        match Action::parse(&segments[1]) {
            Some(action) if segments.len() == 2 => Route::Action { repo, action },
            _ => Route::UnknownAction { repo },
        }
    }

    /// The metrics action label this route resolves to before any git work
    /// (`""` for the paths the reference never labels).
    #[must_use]
    pub fn action_label(&self) -> &'static str {
        match self {
            Route::Home => "home",
            Route::Health => "health",
            Route::Metrics => "metrics",
            Route::OpensearchSite => "opensearch-site",
            Route::Summary { .. } => "summary",
            Route::InfoRefs { .. } => "info-refs",
            Route::Action { action, .. } => action.as_str(),
            Route::UploadPack { .. } => "upload-pack",
            Route::ReceivePack { .. } => "receive-pack",
            Route::UnknownAction { .. } | Route::NotFound | Route::PostNotFound => "",
        }
    }
}

// --------------------------------------------------------------------------- //
// Responses
// --------------------------------------------------------------------------- //

/// A bounded byte stream backing a [`Body::Stream`] response.
///
/// Wraps `gitcmd`'s `git cat-file`/`git archive`/`upload-pack` child streams and
/// its local-file reader, truncating the total to `limit` bytes (`0` meaning "no
/// limit of its own" — the underlying stream carries its own cap). Dropping it
/// tears the child down deterministically.
pub struct ByteStream {
    source: StreamSource,
    limit: usize,
    sent: usize,
}

enum StreamSource {
    Git(GitStream),
    File(FileStream),
    Done,
}

impl ByteStream {
    /// A stream over a git child's stdout, truncated to `limit` bytes.
    #[must_use]
    pub fn git(stream: GitStream, limit: usize) -> Self {
        ByteStream {
            source: StreamSource::Git(stream),
            limit,
            sent: 0,
        }
    }

    /// A stream over a local file, truncated to `limit` bytes.
    #[must_use]
    pub fn file(stream: FileStream, limit: usize) -> Self {
        ByteStream {
            source: StreamSource::File(stream),
            limit,
            sent: 0,
        }
    }
}

impl Iterator for ByteStream {
    type Item = Vec<u8>;

    fn next(&mut self) -> Option<Vec<u8>> {
        if self.limit != 0 && self.sent >= self.limit {
            self.source = StreamSource::Done;
            return None;
        }
        let mut chunk = match &mut self.source {
            StreamSource::Git(s) => s.next()?,
            StreamSource::File(s) => s.next()?,
            StreamSource::Done => return None,
        };
        if self.limit != 0 && self.sent + chunk.len() > self.limit {
            chunk.truncate(self.limit - self.sent);
        }
        self.sent += chunk.len();
        Some(chunk)
    }
}

impl std::fmt::Debug for ByteStream {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ByteStream")
            .field("limit", &self.limit)
            .field("sent", &self.sent)
            .finish()
    }
}

/// A response body: either fully buffered, or streamed from a git child / file.
#[derive(Debug)]
pub enum Body {
    /// A fully buffered body.
    Bytes(Vec<u8>),
    /// A streamed body (raw blobs, archives, `upload-pack` results).
    Stream(ByteStream),
}

impl Body {
    /// The buffered bytes, when the body is not a stream.
    #[must_use]
    pub fn as_bytes(&self) -> Option<&[u8]> {
        match self {
            Body::Bytes(b) => Some(b),
            Body::Stream(_) => None,
        }
    }

    /// The buffered body decoded as UTF-8 (lossily), for assertions.
    #[must_use]
    pub fn text(&self) -> String {
        match self {
            Body::Bytes(b) => String::from_utf8_lossy(b).into_owned(),
            Body::Stream(_) => String::new(),
        }
    }
}

/// One HTTP reply: status, ordered header list and body.
#[derive(Debug)]
pub struct Resp {
    /// HTTP status code.
    pub status: u16,
    /// Headers, in the order the reference emits them.
    pub headers: Vec<(String, String)>,
    /// The body.
    pub body: Body,
    /// The connection must be closed after this response (no `Content-Length`,
    /// a truncated stream, or an undrained request body).
    pub close: bool,
}

impl Resp {
    fn new(status: u16, headers: Vec<(String, String)>, body: Vec<u8>) -> Resp {
        Resp {
            status,
            headers,
            body: Body::Bytes(body),
            close: false,
        }
    }

    /// The first value of `name` (case-insensitively), if present.
    #[must_use]
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers
            .iter()
            .find(|(k, _)| k.eq_ignore_ascii_case(name))
            .map(|(_, v)| v.as_str())
    }

    /// The full response head — status line, headers, terminating blank line.
    #[must_use]
    pub fn head(&self) -> String {
        let mut out = format!("HTTP/1.1 {} {}\r\n", self.status, reason(self.status));
        for (k, v) in &self.headers {
            out.push_str(&format!("{k}: {v}\r\n"));
        }
        out.push_str("\r\n");
        out
    }
}

/// The reason phrase for the statuses this server emits.
#[must_use]
pub fn reason(status: u16) -> &'static str {
    match status {
        302 => "Found",
        304 => "Not Modified",
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        500 => "Internal Server Error",
        503 => "Service Unavailable",
        _ => "OK",
    }
}

/// The security headers every non-304 response carries.
fn security_headers(content_type: &str) -> Vec<(String, String)> {
    vec![
        ("Content-Type".to_string(), content_type.to_string()),
        ("X-Content-Type-Options".to_string(), "nosniff".to_string()),
        ("X-Frame-Options".to_string(), "DENY".to_string()),
        ("Referrer-Policy".to_string(), "no-referrer".to_string()),
        ("Content-Security-Policy".to_string(), CSP.to_string()),
    ]
}

/// The headers a Smart-HTTP response carries (never content-coded).
fn git_headers(content_type: &str) -> Vec<(String, String)> {
    vec![
        ("Content-Type".to_string(), content_type.to_string()),
        ("X-Content-Type-Options".to_string(), "nosniff".to_string()),
        (
            "Cache-Control".to_string(),
            "no-cache, max-age=0, must-revalidate".to_string(),
        ),
        ("Pragma".to_string(), "no-cache".to_string()),
    ]
}

fn push(headers: &mut Vec<(String, String)>, name: &str, value: impl Into<String>) {
    headers.push((name.to_string(), value.into()));
}

/// A strong ETag body from `parts`, folded with [`RENDER_VERSION`].
///
/// The digest of the object sha, the path and the renderer version is stable
/// while all three are, so a client can revalidate a sha-immutable view instead
/// of re-downloading it.
#[must_use]
pub fn make_etag(parts: &[&str]) -> String {
    let mut buf: Vec<u8> = RENDER_VERSION.as_bytes().to_vec();
    for part in parts {
        buf.push(0);
        buf.extend_from_slice(part.as_bytes());
    }
    to_hex(&sha256(&buf)).chars().take(32).collect()
}

/// Pick a supported content coding from an `Accept-Encoding` value (or `""`).
///
/// A faithful port of the reference, including its quirk that a token is keyed
/// *without* trimming trailing whitespace before the `;` (so `gzip ;q=1` is not
/// recognised as `gzip`), and that a `;q=0` is an explicit refusal.
#[must_use]
pub fn negotiate_encoding(accept: Option<&str>) -> &'static str {
    let accept = accept.unwrap_or("").to_lowercase();
    let mut tokens: Vec<(String, f64)> = Vec::new();
    for part in accept.split(',') {
        let part = part.trim();
        let (name, params) = match part.split_once(';') {
            Some((n, p)) => (n, p),
            None => (part, ""),
        };
        let mut qvalue = 1.0f64;
        if let Some(idx) = params.find("q=") {
            qvalue = params[idx + 2..].trim().parse::<f64>().unwrap_or(1.0);
        }
        if !name.is_empty() {
            tokens.push((name.to_string(), qvalue));
        }
    }
    let get = |want: &str| -> f64 {
        tokens
            .iter()
            .rfind(|(n, _)| n == want)
            .map_or(0.0, |(_, q)| *q)
    };
    if get("gzip") > 0.0 {
        return "gzip";
    }
    if get("deflate") > 0.0 {
        return "deflate";
    }
    ""
}

/// Quote an ETag body and, per RFC, distinguish the coded entity's validator.
fn final_etag(base: &str, encoding: &str) -> String {
    if encoding.is_empty() {
        format!("\"{base}\"")
    } else {
        format!("\"{base}-{encoding}\"")
    }
}

/// True when an `If-None-Match` header matches the (already quoted) `etag`.
fn if_none_match_hit(header: Option<&str>, etag: &str) -> bool {
    let Some(header) = header.filter(|h| !h.is_empty()) else {
        return false;
    };
    if header.trim() == "*" {
        return true;
    }
    let weak = format!("W/{etag}");
    header
        .split(',')
        .map(str::trim)
        .any(|c| c == etag || c == weak)
}

// --------------------------------------------------------------------------- //
// Requests
// --------------------------------------------------------------------------- //

/// One inbound request, reduced to the handful of fields the reference reads.
///
/// The body is whatever the transport already read (and, for a coded
/// `upload-pack` request, still compressed): [`Server::route`] inflates it under
/// the configured cap so the whole POST path stays socket-free and testable.
#[derive(Clone, Debug)]
pub struct Request<'a> {
    /// `GET`, `HEAD` or `POST`.
    pub method: &'a str,
    /// The raw request target (`path?query`).
    pub target: &'a str,
    /// The `Host` header, for absolute feed/OpenSearch/clone URLs.
    pub host: Option<&'a str>,
    /// `X-Forwarded-Proto`, honoured by a reverse proxy / Tor front.
    pub forwarded_proto: Option<&'a str>,
    /// `Authorization`, for the optional HTTP Basic gate.
    pub authorization: Option<&'a str>,
    /// `Accept-Encoding`, for the HTML content-coding negotiation.
    pub accept_encoding: Option<&'a str>,
    /// `If-None-Match`, for conditional GETs.
    pub if_none_match: Option<&'a str>,
    /// `Git-Protocol`, for wire protocol v2 negotiation.
    pub git_protocol: Option<&'a str>,
    /// `Content-Encoding` of the request body.
    pub content_encoding: Option<&'a str>,
    /// The request body as read off the wire.
    pub body: &'a [u8],
}

impl<'a> Request<'a> {
    /// A bare `GET` for `target` with no headers.
    #[must_use]
    pub fn get(target: &'a str) -> Self {
        Request {
            method: "GET",
            target,
            host: None,
            forwarded_proto: None,
            authorization: None,
            accept_encoding: None,
            if_none_match: None,
            git_protocol: None,
            content_encoding: None,
            body: &[],
        }
    }

    /// A `POST` of `body` to `target`.
    #[must_use]
    pub fn post(target: &'a str, body: &'a [u8]) -> Self {
        Request {
            method: "POST",
            body,
            ..Request::get(target)
        }
    }

    /// True if the client negotiated wire protocol v2 (`Git-Protocol`).
    #[must_use]
    pub fn wants_protocol_v2(&self) -> bool {
        self.git_protocol
            .unwrap_or("")
            .split(':')
            .any(|tok| tok.trim() == "version=2")
    }

    /// Best-effort absolute origin (`scheme://host`) for feed links; `""` when
    /// no `Host` is known, in which case links fall back to relative.
    #[must_use]
    pub fn base_url(&self) -> String {
        let Some(host) = self.host.filter(|h| !h.is_empty()) else {
            return String::new();
        };
        let proto = self
            .forwarded_proto
            .unwrap_or("http")
            .split(',')
            .next()
            .unwrap_or("http")
            .trim();
        let proto = if proto == "http" || proto == "https" {
            proto
        } else {
            "http"
        };
        format!("{proto}://{host}")
    }
}

/// The result of routing one request: the reply plus the metrics action label.
#[derive(Debug)]
pub struct Routed {
    /// The reply to write.
    pub resp: Resp,
    /// The resolved action, for the metrics registry (`""` when unresolved).
    pub action: &'static str,
}

// --------------------------------------------------------------------------- //
// The server
// --------------------------------------------------------------------------- //

/// The shared, socket-free state behind every request: the configuration and
/// the resolved (optional) access-control credential.
pub struct Server {
    config: Config,
    credential: Option<Credential>,
}

impl std::fmt::Debug for Server {
    /// Deliberately omits the credential itself: only whether access control is
    /// on, never the stored verifier.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Server")
            .field("root", &self.config.root)
            .field("url_prefix", &self.config.url_prefix)
            .field("access_control", &self.credential.is_some())
            .finish()
    }
}

impl Server {
    /// Build a server from `config`, resolving the root and the credential.
    ///
    /// The root is canonicalised (and must be a directory) and the mount prefix
    /// normalised, exactly as the reference's `make_server` does. A malformed
    /// `--auth`/`--auth-file`, or one that yields no usable credential after the
    /// operator asked for access control, is refused **here** rather than
    /// silently serving with the gate open.
    ///
    /// # Errors
    /// The message the reference raises as `SystemExit`.
    pub fn new(mut config: Config) -> Result<Server, String> {
        let root_real = std::fs::canonicalize(&config.root)
            .map_err(|_| format!("root is not a directory: {}", config.root.display()))?;
        if !root_real.is_dir() {
            return Err(format!(
                "root is not a directory: {}",
                config.root.display()
            ));
        }
        config.root = root_real;
        config.url_prefix = normalize_prefix(&config.url_prefix);

        let mut spec = config.auth.clone();
        if !config.auth_file.is_empty() {
            let text = std::fs::read_to_string(&config.auth_file)
                .map_err(|e| format!("cannot read --auth-file {}: {e}", config.auth_file))?;
            for raw in text.lines() {
                let line = raw.trim();
                if !line.is_empty() && !line.starts_with('#') {
                    spec = line.to_string();
                    break;
                }
            }
        }
        let auth_requested = !config.auth.trim().is_empty() || !config.auth_file.trim().is_empty();
        let credential = parse_auth_spec(&spec)
            .map_err(|e| format!("invalid --auth/--auth-file credential: {e}"))?;
        if auth_requested && credential.is_none() {
            return Err(
                "access control was requested via --auth/--auth-file but no usable \
                        credential was found (empty or comment-only); refusing to start with \
                        auth silently disabled"
                    .to_string(),
            );
        }
        Ok(Server { config, credential })
    }

    /// The configuration being served (root canonicalised, prefix normalised).
    #[must_use]
    pub fn config(&self) -> &Config {
        &self.config
    }

    /// The resolved access-control credential, if any.
    #[must_use]
    pub fn credential(&self) -> Option<&Credential> {
        self.credential.as_ref()
    }

    /// True when access control is off, or the request carries valid credentials.
    #[must_use]
    pub fn authorized(&self, authorization: Option<&str>) -> bool {
        match &self.credential {
            None => true,
            Some(cred) => check_basic_auth(authorization, cred),
        }
    }

    /// The rendering context for this server's mount prefix.
    fn ctx(&self) -> Ctx {
        Ctx::new(&self.config.url_prefix)
    }

    /// Route one request to a reply. **Pure** with respect to the network: it
    /// runs git and reads the repository root, but never touches a socket.
    #[must_use]
    pub fn route(&self, req: &Request<'_>) -> Routed {
        // Access control (default OFF) gates *every* endpoint — browse, clone
        // and operational paths alike — before any routing or git work.
        if !self.authorized(req.authorization) {
            return Routed {
                resp: self.unauthorized(),
                action: "",
            };
        }
        let route = Route::of(req.method, req.target, &self.config.url_prefix);
        // The reference labels a request only once its handler is reached, so an
        // error raised while resolving the repository counts with no action.
        let mut action = "";
        let resp = match self.dispatch(req, &route, &mut action) {
            Ok(resp) => resp,
            // An error page is an ordinary HTML response: it negotiates a
            // content coding exactly like a successful one.
            Err(err) => self.error_response(&err, req.accept_encoding),
        };
        Routed { resp, action }
    }

    /// [`Server::route`] wrapped in the metrics hooks — what a transport calls.
    #[must_use]
    pub fn handle(&self, req: &Request<'_>) -> Routed {
        let start = std::time::Instant::now();
        registry().begin();
        let routed = self.route(req);
        registry().end(
            routed.resp.status,
            routed.action,
            start.elapsed().as_secs_f64(),
        );
        routed
    }

    /// Map a `GitError` onto the reference's status + error page.
    fn error_response(&self, err: &GitError, accept_encoding: Option<&str>) -> Resp {
        let (code, message) = match err {
            GitError::BadRequest(m) => (400, m.clone()),
            GitError::NotFound(m) => (404, m.clone()),
            GitError::Failed(m) => (500, format!("git error: {m}")),
        };
        self.html(
            code,
            &views::error_page(&self.ctx(), code, &message),
            None,
            accept_encoding,
        )
    }

    // ------------------------------------------------------------------ //
    // Response builders
    // ------------------------------------------------------------------ //

    /// 401 with a Basic challenge (covers browse *and* git clients).
    fn unauthorized(&self) -> Resp {
        let body = b"401 Unauthorized\n".to_vec();
        let mut headers = vec![(
            "WWW-Authenticate".to_string(),
            "Basic realm=\"gitweb\", charset=\"UTF-8\"".to_string(),
        )];
        headers.extend(security_headers("text/plain; charset=utf-8"));
        push(&mut headers, "Content-Length", body.len().to_string());
        let mut resp = Resp::new(401, headers, body);
        resp.close = true;
        resp
    }

    /// An HTML reply, content-coded and ETagged like the reference's `send_html`.
    fn html(
        &self,
        code: u16,
        html: &str,
        etag: Option<&str>,
        accept_encoding: Option<&str>,
    ) -> Resp {
        let mut body = html.as_bytes().to_vec();
        let mut encoding = negotiate_encoding(accept_encoding);
        let coded_etag = etag.map(|e| final_etag(e, encoding));
        match encoding {
            "gzip" => body = gzip_compress(&body),
            "deflate" => body = zlib_compress(&body),
            _ => encoding = "",
        }
        let mut headers = security_headers("text/html; charset=utf-8");
        push(&mut headers, "Content-Length", body.len().to_string());
        push(&mut headers, "Vary", "Accept-Encoding");
        if !encoding.is_empty() {
            push(&mut headers, "Content-Encoding", encoding);
        }
        if let Some(tag) = coded_etag {
            push(&mut headers, "ETag", tag);
            push(&mut headers, "Cache-Control", "max-age=0, must-revalidate");
        }
        Resp::new(code, headers, body)
    }

    /// An already-encoded byte body with the standard safety headers.
    fn bytes(
        &self,
        code: u16,
        body: Vec<u8>,
        content_type: &str,
        disposition: Option<&str>,
        etag: Option<&str>,
    ) -> Resp {
        let mut headers = security_headers(content_type);
        push(&mut headers, "Content-Length", body.len().to_string());
        if let Some(d) = disposition {
            push(&mut headers, "Content-Disposition", d);
        }
        if let Some(tag) = etag {
            push(&mut headers, "ETag", tag);
            push(&mut headers, "Cache-Control", "max-age=0, must-revalidate");
        }
        Resp::new(code, headers, body)
    }

    fn redirect(&self, location: &str) -> Resp {
        let mut headers = Vec::new();
        push(&mut headers, "Location", location);
        push(&mut headers, "Content-Length", "0");
        Resp::new(302, headers, Vec::new())
    }

    /// `304 Not Modified` when the client's copy is current, else `None`.
    ///
    /// `encoding_independent` is for representations that are never content
    /// coded (the `/raw` byte stream): the ETag then carries no encoding suffix,
    /// so the check must not append the negotiated coding either — otherwise a
    /// browser advertising `gzip` could never match the suffix-less ETag the
    /// endpoint actually issues and would never get a 304.
    fn conditional_get(
        &self,
        req: &Request<'_>,
        base_etag: &str,
        encoding_independent: bool,
    ) -> Option<Resp> {
        if base_etag.is_empty() {
            return None;
        }
        let encoding = if encoding_independent {
            ""
        } else {
            negotiate_encoding(req.accept_encoding)
        };
        let etag = final_etag(base_etag, encoding);
        if !if_none_match_hit(req.if_none_match, &etag) {
            return None;
        }
        let mut headers = Vec::new();
        push(&mut headers, "ETag", etag);
        if !encoding_independent {
            push(&mut headers, "Vary", "Accept-Encoding");
        }
        push(&mut headers, "Cache-Control", "max-age=0, must-revalidate");
        push(&mut headers, "Content-Length", "0");
        Some(Resp::new(304, headers, Vec::new()))
    }

    fn git_forbidden(&self) -> Resp {
        let body = b"403 Forbidden: this git server is read-only (push disabled).\n".to_vec();
        let mut headers = git_headers("text/plain; charset=utf-8");
        push(&mut headers, "Content-Length", body.len().to_string());
        let mut resp = Resp::new(403, headers, body);
        resp.close = true;
        resp
    }

    /// `503` for a clone shed by the concurrency cap (the transport's guard).
    #[must_use]
    pub fn git_busy(&self) -> Resp {
        let body = b"503 Service Unavailable: too many concurrent clones.\n".to_vec();
        let mut headers = git_headers("text/plain; charset=utf-8");
        push(&mut headers, "Retry-After", "5");
        push(&mut headers, "Content-Length", body.len().to_string());
        let mut resp = Resp::new(503, headers, body);
        resp.close = true;
        resp
    }

    // ------------------------------------------------------------------ //
    // Dispatch
    // ------------------------------------------------------------------ //

    fn dispatch(
        &self,
        req: &Request<'_>,
        route: &Route,
        action: &mut &'static str,
    ) -> Result<Resp, GitError> {
        let params = params_of(req.target);
        *action = match route {
            // The labels the reference assigns before any repository resolution.
            Route::Home | Route::Health | Route::Metrics | Route::OpensearchSite => {
                route.action_label()
            }
            _ => "",
        };
        match route {
            Route::NotFound => Err(GitError::NotFound("unknown path".to_string())),
            Route::PostNotFound => Err(GitError::NotFound("not found".to_string())),
            Route::Health => Ok(self.bytes(
                200,
                b"ok\n".to_vec(),
                "text/plain; charset=utf-8",
                None,
                None,
            )),
            Route::Metrics => Ok(self.bytes(
                200,
                registry().render_prometheus().into_bytes(),
                "text/plain; version=0.0.4; charset=utf-8",
                None,
                None,
            )),
            Route::OpensearchSite => Ok(self.bytes(
                200,
                views::opensearch_site(&self.opensearch_base(req)).into_bytes(),
                "application/opensearchdescription+xml",
                None,
                None,
            )),
            Route::Home => self.handle_repo_list(req, &params),
            Route::ReceivePack { .. } => {
                // With clone serving disabled every RPC endpoint simply 404s, as
                // if it never existed; browsing is unaffected.
                if !self.config.enable_clone {
                    return Err(GitError::NotFound("not found".to_string()));
                }
                // Push is categorically refused, before any repository work —
                // this server is read-only and receive-pack is never run.
                *action = "receive-pack";
                Ok(self.git_forbidden())
            }
            Route::UploadPack { repo } => {
                if !self.config.enable_clone {
                    return Err(GitError::NotFound("not found".to_string()));
                }
                let repo = resolve_repo(&self.config.root, repo)?;
                *action = "upload-pack";
                self.handle_upload_pack(req, &repo)
            }
            Route::InfoRefs { repo } => {
                let repo = resolve_repo(&self.config.root, repo)?;
                *action = "info-refs";
                self.handle_info_refs(req, &repo, &params)
            }
            Route::UnknownAction { repo } => {
                resolve_repo(&self.config.root, repo)?;
                Err(GitError::NotFound("unknown path".to_string()))
            }
            Route::Summary { repo } => {
                let repo = resolve_repo(&self.config.root, repo)?;
                *action = "summary";
                self.handle_summary(req, &repo)
            }
            Route::Action { repo, action: act } => {
                let repo = resolve_repo(&self.config.root, repo)?;
                *action = act.as_str();
                self.handle_action(req, &repo, *act, &params)
            }
        }
    }

    fn handle_action(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        action: Action,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        match action {
            Action::Refs => Ok(self.html(
                200,
                &views::refs(&self.ctx(), repo, &branches(repo)?, &tags(repo)?),
                None,
                req.accept_encoding,
            )),
            Action::Releases => Ok(self.html(
                200,
                &views::releases(&self.ctx(), repo, &tags(repo)?),
                None,
                req.accept_encoding,
            )),
            Action::ReleasesAtom => Ok(self.bytes(
                200,
                views::releases_atom(&self.ctx(), repo, &tags(repo)?, &req.base_url()).into_bytes(),
                "application/atom+xml; charset=utf-8",
                None,
                None,
            )),
            Action::Patches => self.handle_patches(req, repo, params),
            Action::PatchesMbox => self.handle_patches_mbox(repo, params),
            Action::Log => self.handle_log(req, repo, params),
            Action::Commit => self.handle_commit(req, repo, params),
            Action::Tree => self.handle_tree(req, repo, params),
            Action::Blob => self.handle_blob(req, repo, params),
            Action::Raw => self.handle_raw(req, repo, params),
            Action::Blame => self.handle_blame(req, repo, params),
            Action::History => self.handle_history(req, repo, params),
            Action::Atom => self.handle_atom(req, repo, params),
            Action::Archive => self.handle_archive(repo, params),
            Action::Compare => self.handle_compare(req, repo, params),
            Action::Search => self.handle_search(req, repo, params),
            Action::Graph => self.handle_graph(req, repo, params),
            Action::Patch | Action::CommitPatch => self.handle_patch(req, repo, params),
            Action::OpenSearch => Ok(self.bytes(
                200,
                views::opensearch_repo(&repo.name, &self.opensearch_base(req)).into_bytes(),
                "application/opensearchdescription+xml",
                None,
                None,
            )),
        }
    }

    // ------------------------------------------------------------------ //
    // Parameter extraction / validation
    // ------------------------------------------------------------------ //

    fn resolve_ref(&self, repo: &Repo, params: &[(String, String)]) -> Result<SafeRef, GitError> {
        let raw = param(params, "ref").trim().to_string();
        if raw.is_empty() {
            let branch = default_branch(repo)?;
            return SafeRef::parse(&branch)
                .ok_or_else(|| GitError::NotFound("no such ref".to_string()));
        }
        SafeRef::parse(&raw).ok_or_else(|| GitError::BadRequest("invalid ref".to_string()))
    }

    fn require_path(params: &[(String, String)]) -> Result<SafePath, GitError> {
        SafePath::parse(param(params, "path"))
            .ok_or_else(|| GitError::BadRequest("invalid path".to_string()))
    }

    /// A `README*` in the given tree, rendered — `(html, name)`.
    fn readme(
        &self,
        repo: &Repo,
        reference: &SafeRef,
        path: &SafePath,
    ) -> (Option<String>, Option<String>) {
        let Ok(entries) = list_tree(repo, reference, path) else {
            return (None, None);
        };
        // Skip entries whose (repo-controlled) path isn't confinement-safe, so a
        // hostile filename can neither be fed to git nor shadow a real README
        // that sorts after it.
        let target = entries.iter().find(|e| {
            valid_path(&e.path) && e.otype == "blob" && e.name.to_lowercase().starts_with("readme")
        });
        let Some(target) = target else {
            return (None, None);
        };
        let Some(tpath) = SafePath::parse(&target.path) else {
            return (None, None);
        };
        let Ok(data) = read_blob(repo, reference, &tpath, self.config.readme_bytes) else {
            return (None, None);
        };
        if is_binary(&data) {
            return (None, None);
        }
        let lower = target.name.to_lowercase();
        let is_md = lower.ends_with(".md") || lower.ends_with(".markdown");
        let text = String::from_utf8_lossy(&data).into_owned();
        (Some(render_readme(&text, is_md)), Some(target.name.clone()))
    }

    /// Absolute `git clone` URL for `repo` (honours the prefix + base URL), or
    /// `None` when no origin is known.
    fn clone_url(&self, req: &Request<'_>, repo: &Repo) -> Option<String> {
        let configured = self.config.clone_base_url.trim().trim_end_matches('/');
        let base = if configured.is_empty() {
            req.base_url()
        } else {
            configured.to_string()
        };
        if base.is_empty() {
            return None;
        }
        Some(format!(
            "{base}{}/{}",
            self.config.url_prefix,
            quote(&repo.name, "")
        ))
    }

    /// Absolute origin + reverse-proxy prefix for an OpenSearch template.
    fn opensearch_base(&self, req: &Request<'_>) -> String {
        format!("{}{}", req.base_url(), self.config.url_prefix)
    }

    // ------------------------------------------------------------------ //
    // Handlers
    // ------------------------------------------------------------------ //

    fn handle_repo_list(
        &self,
        req: &Request<'_>,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let mut repos = discover_repos(&self.config.root)?;
        let q = param(params, "q").trim().to_string();
        if !q.is_empty() {
            let needle = q.to_lowercase();
            repos.retain(|r| {
                r.name.to_lowercase().contains(&needle)
                    || r.description.to_lowercase().contains(&needle)
            });
        }
        Ok(self.html(
            200,
            &views::repo_list(&self.ctx(), &repos, &q),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_summary(&self, req: &Request<'_>, repo: &Repo) -> Result<Resp, GitError> {
        let branch = default_branch(repo)?;
        let commits = match SafeRef::parse(&branch) {
            Some(reference) => match log(repo, &reference, 0, self.config.summary_commits) {
                Ok(rows) => rows,
                Err(GitError::NotFound(_)) => Vec::new(),
                Err(e) => return Err(e),
            },
            None => Vec::new(),
        };
        let (html, name) = match SafeRef::parse(&branch) {
            Some(reference) => self.readme(repo, &reference, &SafePath::root()),
            None => (None, None),
        };
        let clone_url = if self.config.enable_clone {
            self.clone_url(req, repo)
        } else {
            None
        };
        Ok(self.html(
            200,
            &views::summary(
                &self.ctx(),
                repo,
                &branch,
                &commits,
                &Readme {
                    html: html.as_deref(),
                    name: name.as_deref(),
                },
                clone_url.as_deref(),
            ),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_log(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let total = commit_count(repo, &reference)?;
        let (page_num, total_pages) = paginate(total, self.config.page_size, page_param(params));
        let skip = (page_num - 1) * self.config.page_size;
        // An empty / unborn-HEAD repo (or a ref with no commits) renders an
        // empty log page rather than a 404, mirroring the summary.
        let rows = match log(repo, &reference, skip, self.config.page_size) {
            Ok(rows) => rows,
            Err(GitError::NotFound(_)) => Vec::new(),
            Err(e) => return Err(e),
        };
        Ok(self.html(
            200,
            &views::log_page(
                &self.ctx(),
                repo,
                reference.as_str(),
                &rows,
                page_num,
                total_pages,
            ),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_commit(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let rev_raw = param(params, "id").trim().to_string();
        let rev = SafeRef::parse(&rev_raw)
            .ok_or_else(|| GitError::BadRequest("invalid commit id".to_string()))?;
        // A full 40-hex sha names an immutable commit: answer a conditional GET
        // (and skip the diff parse) before touching git.
        let mut etag = None;
        if is_full_sha(&rev_raw) {
            let tag = make_etag(&[&rev_raw, "commit"]);
            if let Some(resp) = self.conditional_get(req, &tag, false) {
                return Ok(resp);
            }
            etag = Some(tag);
        }
        let commit = commit_meta(repo, &rev)?;
        let files = parse_patch(&commit_patch(repo, &rev)?);
        Ok(self.html(
            200,
            &views::commit_page(&self.ctx(), repo, &commit, &files),
            etag.as_deref(),
            req.accept_encoding,
        ))
    }

    fn handle_tree(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let path = Self::require_path(params)?;
        let content_sha = if path.is_root() {
            stat_object(repo, &reference, &path)
                .ok_or_else(|| GitError::NotFound("no such ref".to_string()))?
                .sha
        } else {
            let st = stat_object(repo, &reference, &path)
                .ok_or_else(|| GitError::NotFound("no such path".to_string()))?;
            if st.otype == "blob" {
                return Ok(self.redirect(&self.ctx().action(
                    &repo.name,
                    "blob",
                    &[("ref", reference.as_str()), ("path", path.as_str())],
                )));
            }
            st.sha
        };

        // Ref folded in for the same reason as the blob view (ref-specific
        // chrome), and the requested page so a 304 cannot serve the wrong page;
        // the tree/commit sha still invalidates on any content change.
        let req_page = page_param(params);
        let etag = make_etag(&[
            &content_sha,
            path.as_str(),
            reference.as_str(),
            &req_page.to_string(),
            "tree",
        ]);
        if let Some(resp) = self.conditional_get(req, &etag, false) {
            return Ok(resp);
        }

        let entries = list_tree(repo, &reference, &path)?;
        // Hard entry cap via pagination (mirroring the log pager) so a directory
        // with tens of thousands of entries cannot blow up the response.
        let total = entries.len();
        let page_size = self.config.tree_page_size.max(1);
        let total_pages = if total == 0 {
            1
        } else {
            total.div_ceil(page_size).max(1)
        };
        let page_num = req_page.min(total_pages);
        let start = (page_num - 1) * page_size;
        let page_entries = &entries[start.min(total)..(start + page_size).min(total)];

        let (readme_html, readme_name) = if page_num == 1 {
            self.readme(repo, &reference, &path)
        } else {
            (None, None)
        };
        let (branch_names, tag_names) = ref_names(repo)?;
        let commit_sha = resolve_commit(repo, &reference);
        let submodules = if page_entries.iter().any(|e| e.otype == "commit") {
            read_gitmodules(repo, &reference)
        } else {
            Vec::new()
        };
        Ok(self.html(
            200,
            &views::tree_page(
                &self.ctx(),
                repo,
                reference.as_str(),
                path.as_str(),
                page_entries,
                &Readme {
                    html: readme_html.as_deref(),
                    name: readme_name.as_deref(),
                },
                &TreeView {
                    page_num,
                    total_pages,
                    total_entries: Some(total),
                    refs: RefChoices {
                        branches: &branch_names,
                        tags: &tag_names,
                        commit_sha: &commit_sha,
                    },
                    submodules: &submodules,
                },
            ),
            Some(&etag),
            req.accept_encoding,
        ))
    }

    fn handle_blob(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let path = Self::require_path(params)?;
        if path.is_root() {
            return Err(GitError::BadRequest("missing path".to_string()));
        }
        let st = stat_object(repo, &reference, &path)
            .ok_or_else(|| GitError::NotFound("no such file".to_string()))?;
        if st.otype == "tree" {
            return Ok(self.redirect(&self.ctx().action(
                &repo.name,
                "tree",
                &[("ref", reference.as_str()), ("path", path.as_str())],
            )));
        }
        if st.otype != "blob" {
            return Err(GitError::NotFound("not a file".to_string()));
        }

        let size = st.size;
        let hl_raw = param(params, "highlight").to_string();
        let display_raw = param(params, "display").to_string();
        // Fold in the ref (the page renders ref-specific chrome) and the variant
        // selectors so a 304 can never serve the wrong rendered variant.
        let etag = make_etag(&[
            &st.sha,
            path.as_str(),
            reference.as_str(),
            &hl_raw,
            &display_raw,
            "blob",
        ]);
        if let Some(resp) = self.conditional_get(req, &etag, false) {
            return Ok(resp);
        }

        let (branch_names, tag_names) = ref_names(repo)?;
        let commit_sha = resolve_commit(repo, &reference);
        let highlight = parse_line_range(&hl_raw);
        let choices = RefChoices {
            branches: &branch_names,
            tags: &tag_names,
            commit_sha: &commit_sha,
        };

        // Git LFS detection (pointers are tiny, so a peek parses one). When the
        // pointed object is in local storage the REAL content is rendered; when
        // it is not, the pointer is shown with a note.
        let peek = peek_blob(repo, &reference, &path);
        let lfs_ptr: Option<LfsPointer> = parse_lfs_pointer(&peek);
        let lfs_local = lfs_ptr.as_ref().and_then(|p| lfs_object_path(repo, &p.oid));
        let is_image = image_type(&ext_of(path.as_str())).is_some();

        // An image renders inline via <img src=raw> (CSP allows img-src 'self')
        // when it is an ordinary blob, or an LFS pointer whose object is present
        // locally (/raw then serves the real image bytes).
        if is_image && (lfs_ptr.is_none() || lfs_local.is_some()) {
            let img_size = match &lfs_local {
                Some(local) => lfs_object_size(local),
                None => size,
            };
            let served = if lfs_local.is_some() {
                lfs_ptr.as_ref()
            } else {
                None
            };
            return Ok(self.html(
                200,
                &views::blob_page(
                    &self.ctx(),
                    repo,
                    reference.as_str(),
                    path.as_str(),
                    &BlobView {
                        size: img_size,
                        binary: true,
                        is_image: true,
                        refs: choices,
                        lfs_served: served,
                        ..BlobView::default()
                    },
                ),
                Some(&etag),
                req.accept_encoding,
            ));
        }

        let md_ext = matches!(ext_of(path.as_str()).as_str(), "md" | "markdown");
        let show_source = display_raw == "source";

        // A non-image LFS pointer whose object is present locally: render the
        // real content (read from local storage, capped like any blob).
        if let (Some(ptr), Some(local)) = (lfs_ptr.as_ref(), lfs_local.as_ref()) {
            let obj_size = lfs_object_size(local);
            let binary = is_binary(&peek_file(local));
            let too_large = obj_size > self.config.max_blob_bytes;
            let mut text = None;
            let mut rendered_md = None;
            if !binary && !too_large {
                let data = read_file(local, usize_cap(self.config.max_blob_bytes));
                let decoded = String::from_utf8_lossy(&data).into_owned();
                if md_ext {
                    rendered_md = Some(render_markdown(&decoded));
                }
                text = Some(decoded);
            }
            return Ok(self.html(
                200,
                &views::blob_page(
                    &self.ctx(),
                    repo,
                    reference.as_str(),
                    path.as_str(),
                    &BlobView {
                        size: obj_size,
                        text: text.as_deref(),
                        binary,
                        too_large,
                        highlight: &highlight,
                        refs: choices,
                        lfs_served: Some(ptr),
                        rendered_md: rendered_md.as_deref(),
                        show_source,
                        ..BlobView::default()
                    },
                ),
                Some(&etag),
                req.accept_encoding,
            ));
        }

        // An LFS pointer whose object is NOT stored locally: show the pointer.
        if let Some(ptr) = lfs_ptr.as_ref() {
            return Ok(self.html(
                200,
                &views::blob_page(
                    &self.ctx(),
                    repo,
                    reference.as_str(),
                    path.as_str(),
                    &BlobView {
                        size,
                        refs: choices,
                        lfs: Some(ptr),
                        ..BlobView::default()
                    },
                ),
                Some(&etag),
                req.accept_encoding,
            ));
        }

        // Ordinary (non-LFS) blob.
        let binary = is_binary(&peek);
        let too_large = size > self.config.max_blob_bytes;
        let mut text = None;
        let mut rendered_md = None;
        if !binary && !too_large {
            let data = read_blob(
                repo,
                &reference,
                &path,
                usize_cap(self.config.max_blob_bytes),
            )?;
            let decoded = String::from_utf8_lossy(&data).into_owned();
            if md_ext {
                rendered_md = Some(render_markdown(&decoded));
            }
            text = Some(decoded);
        }
        Ok(self.html(
            200,
            &views::blob_page(
                &self.ctx(),
                repo,
                reference.as_str(),
                path.as_str(),
                &BlobView {
                    size,
                    text: text.as_deref(),
                    binary,
                    too_large,
                    highlight: &highlight,
                    refs: choices,
                    rendered_md: rendered_md.as_deref(),
                    show_source,
                    ..BlobView::default()
                },
            ),
            Some(&etag),
            req.accept_encoding,
        ))
    }

    /// Pick `(content_type, disposition)` for a `/raw` byte stream.
    fn raw_content_type(path: &str, binary: bool) -> (String, String) {
        let base = path.rsplit('/').next().unwrap_or(path);
        let mut filename = safe_filename(base);
        if filename.is_empty() {
            filename = "file".to_string();
        }
        // Inline as an image (the blob view references it via <img>); nosniff +
        // the strict CSP keep even SVG safe (scripts are blocked).
        if let Some(ctype) = image_type(&ext_of(path)) {
            return (
                ctype.to_string(),
                format!("inline; filename=\"{filename}\""),
            );
        }
        if binary {
            return (
                "application/octet-stream".to_string(),
                format!("attachment; filename=\"{filename}\""),
            );
        }
        (
            "text/plain; charset=utf-8".to_string(),
            format!("inline; filename=\"{filename}\""),
        )
    }

    /// Stream `size` bytes (capped at `raw_max_bytes`) with safe headers.
    fn download(
        &self,
        ctype: &str,
        disposition: &str,
        etag_base: &str,
        size: u64,
        make: impl FnOnce(usize) -> Result<ByteStream, GitError>,
    ) -> Result<Resp, GitError> {
        let cap = self.config.raw_max_bytes;
        let length = size.min(cap);
        let max_stream = if size <= cap { 0 } else { usize_cap(cap) };
        let mut headers = security_headers(ctype);
        push(&mut headers, "Content-Length", length.to_string());
        push(&mut headers, "Content-Disposition", disposition);
        push(&mut headers, "ETag", final_etag(etag_base, ""));
        push(&mut headers, "Connection", "close");
        let stream = make(max_stream)?;
        Ok(Resp {
            status: 200,
            headers,
            body: Body::Stream(ByteStream {
                limit: usize_cap(length),
                ..stream
            }),
            // Avoid a keep-alive desync if the stream ends short of the length.
            close: true,
        })
    }

    fn handle_raw(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let path = Self::require_path(params)?;
        if path.is_root() {
            return Err(GitError::BadRequest("missing path".to_string()));
        }
        let st = stat_object(repo, &reference, &path)
            .filter(|st| st.otype == "blob")
            .ok_or_else(|| GitError::NotFound("no such file".to_string()))?;

        // The raw stream is never content-coded, so its ETag carries no encoding
        // suffix; revalidate encoding-independently or a gzip-advertising browser
        // would never see a 304.
        let etag = make_etag(&[&st.sha, path.as_str(), "raw"]);
        if let Some(resp) = self.conditional_get(req, &etag, true) {
            return Ok(resp);
        }
        let peek = peek_blob(repo, &reference, &path);

        // Git LFS: when the blob is a pointer whose object is in *local* storage,
        // serve the real object bytes (streamed + capped) — never a remote fetch.
        if let Some(local) = parse_lfs_pointer(&peek).and_then(|p| lfs_object_path(repo, &p.oid)) {
            let obj_size = lfs_object_size(&local);
            let obj_binary = is_binary(&peek_file(&local));
            let (ctype, disposition) = Self::raw_content_type(path.as_str(), obj_binary);
            return self.download(&ctype, &disposition, &etag, obj_size, |max| {
                stream_file_with(&local, gitcmd::DEFAULT_CHUNK_SIZE, max)
                    .map(|s| ByteStream::file(s, 0))
            });
        }

        let (ctype, disposition) = Self::raw_content_type(path.as_str(), is_binary(&peek));
        self.download(&ctype, &disposition, &etag, st.size, |max| {
            stream_blob_with(repo, &reference, &path, gitcmd::DEFAULT_CHUNK_SIZE, max)
                .map(|s| ByteStream::git(s, 0))
        })
    }

    fn handle_blame(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let path = Self::require_path(params)?;
        if path.is_root() {
            return Err(GitError::BadRequest("missing path".to_string()));
        }
        if gitcmd::object_type(repo, &reference, &path).as_deref() != Some("blob") {
            return Err(GitError::NotFound("not a file".to_string()));
        }
        let lines = blame(repo, &reference, &path)?;
        Ok(self.html(
            200,
            &views::blame_page(&self.ctx(), repo, reference.as_str(), path.as_str(), &lines),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_history(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let path = Self::require_path(params)?;
        if path.is_root() {
            return Err(GitError::BadRequest("missing path".to_string()));
        }
        let follow = matches!(param(params, "follow"), "1" | "true" | "yes" | "on");
        let total = commit_count_path(repo, &reference, &path)?;
        let page_size = self.config.page_size.max(1);
        let total_pages = if total == 0 {
            1
        } else {
            (total as usize).div_ceil(page_size).max(1)
        };
        let page_num = page_param(params).min(total_pages);
        let skip = (page_num - 1) * page_size;
        let rows = match log_path(repo, &reference, &path, skip, page_size, follow) {
            Ok(rows) => rows,
            Err(GitError::NotFound(_)) => Vec::new(),
            Err(e) => return Err(e),
        };
        Ok(self.html(
            200,
            &views::history_page(
                &self.ctx(),
                repo,
                reference.as_str(),
                path.as_str(),
                &rows,
                &HistoryView {
                    page_num,
                    total_pages,
                    follow,
                },
            ),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_atom(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let rows = match log(repo, &reference, 0, self.config.feed_commits) {
            Ok(rows) => rows,
            Err(GitError::NotFound(_)) => Vec::new(),
            Err(e) => return Err(e),
        };
        let body = views::atom_feed(
            &self.ctx(),
            repo,
            reference.as_str(),
            &rows,
            &req.base_url(),
        );
        Ok(self.bytes(
            200,
            body.into_bytes(),
            "application/atom+xml; charset=utf-8",
            None,
            None,
        ))
    }

    fn handle_compare(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let base = param(params, "from").trim().to_string();
        let other = param(params, "to").trim().to_string();
        if base.is_empty() || other.is_empty() {
            return Err(GitError::BadRequest(
                "compare needs 'from' and 'to' refs".to_string(),
            ));
        }
        let (Some(base_ref), Some(other_ref)) = (SafeRef::parse(&base), SafeRef::parse(&other))
        else {
            return Err(GitError::BadRequest("invalid ref".to_string()));
        };
        let files = parse_patch(&compare(repo, &base_ref, &other_ref)?);
        Ok(self.html(
            200,
            &views::compare_page(&self.ctx(), repo, &base, &other, &files),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_search(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let q = param(params, "q").to_string();
        let typ = match param_or(params, "type", "code") {
            "log" => "log",
            _ => "code",
        };
        let reference = self.resolve_ref(repo, params)?;
        let mut invalid = false;
        let mut code_matches = None;
        let mut code_truncated = false;
        let mut log_rows = None;
        let mut page_num = 1usize;
        let mut total_pages = 1usize;
        if !q.is_empty() {
            match SafeQuery::parse(&q) {
                None => invalid = true,
                Some(query) if typ == "log" => {
                    let total = commit_count_grep(repo, &reference, &query)?;
                    let (p, tp) = paginate(total, self.config.page_size, page_param(params));
                    page_num = p;
                    total_pages = tp;
                    let skip = (page_num - 1) * self.config.page_size;
                    log_rows = Some(log_grep(
                        repo,
                        &reference,
                        &query,
                        skip,
                        self.config.page_size,
                    )?);
                }
                Some(query) => {
                    let (matches, more) = search_code(repo, &reference, &query)?;
                    code_matches = Some(matches);
                    code_truncated = more;
                }
            }
        }
        Ok(self.html(
            200,
            &views::search_page(
                &self.ctx(),
                repo,
                &q,
                typ,
                reference.as_str(),
                &SearchView {
                    code_matches: code_matches.as_deref(),
                    code_truncated,
                    log_rows: log_rows.as_deref(),
                    page_num,
                    total_pages,
                    invalid,
                },
            ),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_graph(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        let total = commit_count(repo, &reference)?;
        let (page_num, total_pages) = paginate(total, self.config.page_size, page_param(params));
        let skip = (page_num - 1) * self.config.page_size;
        let rows = match log_graph(repo, &reference, skip, self.config.page_size) {
            Ok(rows) => rows,
            Err(GitError::NotFound(_)) => Vec::new(),
            Err(e) => return Err(e),
        };
        Ok(self.html(
            200,
            &views::graph_page(
                &self.ctx(),
                repo,
                reference.as_str(),
                &rows,
                page_num,
                total_pages,
            ),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_patch(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let rev_raw = param(params, "id").trim().to_string();
        let rev = SafeRef::parse(&rev_raw)
            .ok_or_else(|| GitError::BadRequest("invalid commit id".to_string()))?;
        // A full 40-hex sha names an immutable patch: answer a conditional GET
        // (never content-coded, so revalidate encoding-independently).
        let mut etag = None;
        if is_full_sha(&rev_raw) {
            let tag = make_etag(&[&rev_raw, "patch"]);
            if let Some(resp) = self.conditional_get(req, &tag, true) {
                return Ok(resp);
            }
            etag = Some(tag);
        }
        let data = format_patch(repo, &rev)?;
        let short: String = rev_raw.chars().take(12).collect();
        let mut safe = safe_filename(&format!("{}-{short}", repo.name));
        if safe.is_empty() {
            safe = "patch".to_string();
        }
        Ok(self.bytes(
            200,
            data,
            "text/plain; charset=utf-8",
            Some(&format!("attachment; filename=\"{safe}.patch\"")),
            etag.as_deref().map(|t| final_etag(t, "")).as_deref(),
        ))
    }

    fn handle_archive(&self, repo: &Repo, params: &[(String, String)]) -> Result<Resp, GitError> {
        let reference = self.resolve_ref(repo, params)?;
        if stat_object(repo, &reference, &SafePath::root()).is_none() {
            return Err(GitError::NotFound("no such ref".to_string()));
        }
        let mut safe_ref = safe_filename(reference.as_str());
        if safe_ref.is_empty() {
            safe_ref = "archive".to_string();
        }
        let prefix = format!("{}-{safe_ref}/", repo.name);
        let filename = format!("{}-{safe_ref}.tar.gz", repo.name);
        let cap = self.config.archive_max_bytes;

        let mut headers = security_headers("application/gzip");
        push(
            &mut headers,
            "Content-Disposition",
            format!("attachment; filename=\"{filename}\""),
        );
        push(&mut headers, "Connection", "close");
        let stream =
            stream_archive_with(repo, &reference, &prefix, gitcmd::DEFAULT_CHUNK_SIZE, cap)?;
        Ok(Resp {
            status: 200,
            headers,
            body: Body::Stream(ByteStream::git(stream, cap)),
            // The length is unknown, so the client reads to EOF.
            close: true,
        })
    }

    // -- the read-only patch/mail archive ------------------------------- //

    fn patch_archive_path(&self, repo: &Repo) -> Option<PathBuf> {
        if self.config.patches_dir.is_empty() {
            return None;
        }
        Some(
            Path::new(&self.config.patches_dir).join(format!("{}.mbox", safe_filename(&repo.name))),
        )
    }

    fn handle_patches(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let path = self.patch_archive_path(repo);
        let msgs = match &path {
            Some(p) => mailarchive::read_archive(p, mailarchive::MAX_MESSAGES),
            None => Vec::new(),
        };
        let threads = mailarchive::group_threads(msgs);
        let ctx = self.ctx();
        let u = |action: &str, p: &[(&str, &str)]| ctx.action(&repo.name, action, p);
        let tid = param(params, "thread");
        let (body, title) = if tid.is_empty() {
            (
                mailarchive::render_list(&repo.name, &threads, u, path.is_some()),
                format!("{}: patches", repo.name),
            )
        } else {
            let thread = threads
                .iter()
                .find(|t| t.id == tid)
                .ok_or_else(|| GitError::NotFound("no such thread".to_string()))?;
            (
                mailarchive::render_thread(&repo.name, thread, u),
                format!("{}: {}", repo.name, thread.subject),
            )
        };
        let body = format!("<style>{}</style>{body}", mailarchive::PATCH_CSS);
        Ok(self.html(
            200,
            &views::page(
                &ctx,
                &title,
                &body,
                Some(&repo.name),
                "patches",
                &repo.description,
            ),
            None,
            req.accept_encoding,
        ))
    }

    fn handle_patches_mbox(
        &self,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        let path = self.patch_archive_path(repo);
        let msgs = match &path {
            Some(p) => mailarchive::read_archive(p, mailarchive::MAX_MESSAGES),
            None => Vec::new(),
        };
        let threads = mailarchive::group_threads(msgs);
        let tid = param(params, "thread");
        let thread = threads
            .iter()
            .find(|t| t.id == tid)
            .ok_or_else(|| GitError::NotFound("no such thread".to_string()))?;
        Ok(self.bytes(
            200,
            mailarchive::thread_mbox(thread),
            "application/mbox",
            Some(&format!("attachment; filename=\"{}.mbox\"", thread.id)),
            None,
        ))
    }

    // -- Git Smart HTTP (read-only clone / fetch) ------------------------ //

    fn handle_info_refs(
        &self,
        req: &Request<'_>,
        repo: &Repo,
        params: &[(String, String)],
    ) -> Result<Resp, GitError> {
        if !self.config.enable_clone {
            return Err(GitError::NotFound("not found".to_string()));
        }
        match param(params, "service") {
            // Advertise for push → refused (this server is read-only).
            "git-receive-pack" => return Ok(self.git_forbidden()),
            // No service (the dumb protocol) or an unknown one: unsupported.
            "git-upload-pack" => {}
            _ => return Err(GitError::NotFound("not found".to_string())),
        }
        let protocol_v2 = req.wants_protocol_v2();
        let adv = upload_pack_advertise_with(
            repo,
            protocol_v2,
            Duration::from_secs(self.config.clone_timeout.max(0.0) as u64),
            gitcmd::UPLOAD_PACK_ADVERTISE_MAX_BYTES,
        )?;
        // The "# service=" banner precedes the advertisement in protocol v0/v1
        // only; protocol v2 sends the capability advertisement with no banner.
        let body = if protocol_v2 {
            adv
        } else {
            let mut out = pkt_line(b"# service=git-upload-pack\n");
            out.extend_from_slice(PKT_FLUSH);
            out.extend_from_slice(&adv);
            out
        };
        let mut headers = git_headers("application/x-git-upload-pack-advertisement");
        push(&mut headers, "Content-Length", body.len().to_string());
        Ok(Resp::new(200, headers, body))
    }

    fn handle_upload_pack(&self, req: &Request<'_>, repo: &Repo) -> Result<Resp, GitError> {
        let payload = self.decode_git_body(req)?;
        let mut headers = git_headers("application/x-git-upload-pack-result");
        push(&mut headers, "Connection", "close");
        let stream = upload_pack_rpc_with(
            repo,
            payload,
            req.wants_protocol_v2(),
            Duration::from_secs(self.config.clone_timeout.max(0.0) as u64),
            gitcmd::DEFAULT_CHUNK_SIZE,
        )?;
        Ok(Resp {
            status: 200,
            headers,
            body: Body::Stream(ByteStream::git(stream, 0)),
            close: true,
        })
    }

    /// Inflate a (possibly gzip/deflate-coded) `upload-pack` request body under
    /// the configured cap.
    ///
    /// git may send the request `Content-Encoding: gzip`; decompression has an
    /// explicit output cap so a small hostile body cannot inflate into an
    /// unbounded allocation (a zip bomb). The raw input is already bounded by
    /// the transport's body cap.
    ///
    /// # Errors
    /// [`GitError::BadRequest`] for an over-large or malformed body.
    pub fn decode_git_body(&self, req: &Request<'_>) -> Result<Vec<u8>, GitError> {
        let cap = self.config.clone_max_body_bytes;
        if req.body.len() > cap {
            return Err(GitError::BadRequest("request body too large".to_string()));
        }
        let coding = req.content_encoding.unwrap_or("").to_lowercase();
        if !coding.contains("gzip") && !coding.contains("deflate") {
            return Ok(req.body.to_vec());
        }
        let gzip = req.body.starts_with(&[0x1f, 0x8b]);
        let result = if gzip {
            inflate_gzip(req.body, cap)
        } else {
            inflate_zlib(req.body, cap)
        };
        match result {
            Ok((_, true)) => Err(GitError::BadRequest("request body too large".to_string())),
            Ok((out, false)) if out.len() > cap => {
                Err(GitError::BadRequest("request body too large".to_string()))
            }
            Ok((out, false)) => Ok(out),
            Err(e) => Err(GitError::BadRequest(format!(
                "malformed compressed request body: {e}"
            ))),
        }
    }
}

// --------------------------------------------------------------------------- //
// Small pure helpers
// --------------------------------------------------------------------------- //

/// The query parameters of a request target, in order.
fn params_of(target: &str) -> Vec<(String, String)> {
    parse_qsl(&urlsplit(target, "").query, true)
}

/// The first value of `key` (`""` when absent) — the reference's `_q`.
fn param<'a>(params: &'a [(String, String)], key: &str) -> &'a str {
    params
        .iter()
        .find(|(k, _)| k == key)
        .map_or("", |(_, v)| v.as_str())
}

/// The first value of `key`, or `default` when absent.
fn param_or<'a>(params: &'a [(String, String)], key: &str, default: &'a str) -> &'a str {
    params
        .iter()
        .find(|(k, _)| k == key)
        .map_or(default, |(_, v)| v.as_str())
}

/// A 1-based `page` query parameter (defaulting to 1, never below it).
///
/// Python's `int()` has no width limit, so a caller can name page
/// `10**30` and the reference clamps it to the last page. An integer literal
/// that overflows here therefore saturates rather than silently meaning "page
/// 1"; anything that is not an integer at all falls back to 1, as `int()`
/// raising `ValueError` does.
fn page_param(params: &[(String, String)]) -> usize {
    let raw = param(params, "page");
    let raw = if raw.is_empty() { "1" } else { raw };
    let text = raw.trim();
    if let Ok(n) = text.parse::<i128>() {
        return if n >= 1 {
            usize::try_from(n).unwrap_or(usize::MAX)
        } else {
            1
        };
    }
    let body = text.strip_prefix('+').unwrap_or(text);
    let (negative, digits) = match body.strip_prefix('-') {
        Some(d) => (true, d),
        None => (false, body),
    };
    if !digits.is_empty() && digits.bytes().all(|b| b.is_ascii_digit()) && !negative {
        return usize::MAX;
    }
    1
}

/// `(page_num, total_pages)` for `total` items at `page_size` per page.
fn paginate(total: u64, page_size: usize, requested: usize) -> (usize, usize) {
    let page_size = page_size.max(1);
    let total_pages = if total == 0 {
        1
    } else {
        usize::try_from(total.div_ceil(page_size as u64))
            .unwrap_or(usize::MAX)
            .max(1)
    };
    (requested.clamp(1, total_pages), total_pages)
}

/// Clamp a byte budget to `usize` on a 32-bit target.
fn usize_cap(n: u64) -> usize {
    usize::try_from(n).unwrap_or(usize::MAX)
}

// --------------------------------------------------------------------------- //
// The `net` tier: the accept loop
// --------------------------------------------------------------------------- //

#[cfg(feature = "net")]
pub use net_impl::{serve, serve_config};

#[cfg(feature = "net")]
mod net_impl {
    use super::{Body, Config, Request, Resp, Route, Server, MAX_REQUEST_HEAD};
    use crate::gitcmd::GitError;
    use crate::metrics::registry;
    use std::sync::Arc;
    use std::time::Duration;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::{TcpListener, TcpStream};
    use tokio::sync::{mpsc, Semaphore};

    /// Ceiling on the concurrent-connection bound, so an absurd `max_workers`
    /// cannot overflow the permit pool's own limit.
    const MAX_CONNECTIONS: usize = 65_536;
    /// Requests served on one keep-alive connection before it is closed.
    const MAX_KEEPALIVE_REQUESTS: usize = 128;
    /// After refusing a POST its body is never read — but closing a socket that
    /// still has unread data queued makes the kernel send an RST, which would
    /// destroy the `400`/`503` just written. Discarding a bounded amount first
    /// (streamed, never buffered) means the client actually sees the refusal.
    const REFUSED_BODY_DRAIN: usize = 64 * 1024;
    /// How long to spend on that courtesy drain.
    const REFUSED_BODY_DRAIN_TIMEOUT: Duration = Duration::from_millis(250);
    /// TOTAL time allowed to receive one request head, independent of the
    /// per-read `socket_timeout`. A per-read timer alone is not a Slowloris
    /// guard: it resets on every byte, so a client sending one byte every
    /// `socket_timeout - ε` holds its connection slot for as long as it likes,
    /// and `max_workers` (default 32) such clients shut everyone else out.
    const HEAD_DEADLINE: Duration = Duration::from_secs(30);

    /// One request, owned so it can cross onto a blocking worker.
    #[derive(Default)]
    struct Owned {
        method: String,
        target: String,
        version: String,
        host: Option<String>,
        forwarded_proto: Option<String>,
        authorization: Option<String>,
        accept_encoding: Option<String>,
        if_none_match: Option<String>,
        git_protocol: Option<String>,
        content_encoding: Option<String>,
        connection: Option<String>,
        content_length: Option<String>,
        transfer_encoding: Option<String>,
        body: Vec<u8>,
    }

    impl Owned {
        fn as_request(&self) -> Request<'_> {
            Request {
                method: &self.method,
                target: &self.target,
                host: self.host.as_deref(),
                forwarded_proto: self.forwarded_proto.as_deref(),
                authorization: self.authorization.as_deref(),
                accept_encoding: self.accept_encoding.as_deref(),
                if_none_match: self.if_none_match.as_deref(),
                git_protocol: self.git_protocol.as_deref(),
                content_encoding: self.content_encoding.as_deref(),
                body: &self.body,
            }
        }

        /// Whether the client asked to keep the connection open.
        fn wants_keep_alive(&self) -> bool {
            let token = self.connection.as_deref().unwrap_or("").to_lowercase();
            if token.split(',').any(|t| t.trim() == "close") {
                return false;
            }
            if self.version == "HTTP/1.0" {
                return token.split(',').any(|t| t.trim() == "keep-alive");
            }
            true
        }
    }

    fn find(hay: &[u8], needle: &[u8]) -> Option<usize> {
        if needle.is_empty() || hay.len() < needle.len() {
            return None;
        }
        (0..=hay.len() - needle.len()).find(|&i| &hay[i..i + needle.len()] == needle)
    }

    /// A buffered reader over one connection, so a pipelined request's bytes are
    /// never lost between reads.
    struct Conn {
        sock: TcpStream,
        buf: Vec<u8>,
        timeout: Duration,
    }

    impl Conn {
        async fn fill(&mut self) -> bool {
            let mut tmp = [0u8; 8192];
            match tokio::time::timeout(self.timeout, self.sock.read(&mut tmp)).await {
                Ok(Ok(0)) | Ok(Err(_)) | Err(_) => false,
                Ok(Ok(n)) => {
                    self.buf.extend_from_slice(&tmp[..n]);
                    true
                }
            }
        }

        /// Discard a bounded amount of a request body we are refusing to read.
        async fn drain_refused(&mut self) {
            let mut discarded = self.buf.len();
            self.buf.clear();
            let mut tmp = [0u8; 8192];
            while discarded < REFUSED_BODY_DRAIN {
                match tokio::time::timeout(REFUSED_BODY_DRAIN_TIMEOUT, self.sock.read(&mut tmp))
                    .await
                {
                    Ok(Ok(0)) | Ok(Err(_)) | Err(_) => return,
                    Ok(Ok(n)) => discarded += n,
                }
            }
        }

        /// Read one request head (up to and including the blank line).
        ///
        /// Bounded by a TOTAL deadline, not just the per-read `socket_timeout` —
        /// see [`HEAD_DEADLINE`]. The connection cap bounds how many sockets can
        /// be open; this is what bounds how long one of them may hold its slot.
        async fn read_head(&mut self) -> Option<String> {
            let deadline = tokio::time::Instant::now() + HEAD_DEADLINE;
            loop {
                if let Some(end) = find(&self.buf, b"\r\n\r\n") {
                    let head = String::from_utf8_lossy(&self.buf[..end]).into_owned();
                    self.buf.drain(..end + 4);
                    return Some(head);
                }
                if self.buf.len() > MAX_REQUEST_HEAD {
                    return None;
                }
                if tokio::time::Instant::now() >= deadline {
                    return None;
                }
                if !self.fill_by(deadline).await {
                    return None;
                }
            }
        }

        /// `fill`, but never past `deadline` — so a slow trickle cannot outlive
        /// the whole-request budget by resetting a per-read timer.
        async fn fill_by(&mut self, deadline: tokio::time::Instant) -> bool {
            let mut tmp = [0u8; 8192];
            let cut = deadline.min(tokio::time::Instant::now() + self.timeout);
            match tokio::time::timeout_at(cut, self.sock.read(&mut tmp)).await {
                Ok(Ok(0)) | Ok(Err(_)) | Err(_) => false,
                Ok(Ok(n)) => {
                    self.buf.extend_from_slice(&tmp[..n]);
                    true
                }
            }
        }

        async fn read_exact_body(&mut self, n: usize) -> Vec<u8> {
            while self.buf.len() < n {
                if !self.fill().await {
                    break;
                }
            }
            let take = n.min(self.buf.len());
            self.buf.drain(..take).collect()
        }

        async fn read_line(&mut self) -> Option<Vec<u8>> {
            loop {
                if let Some(end) = find(&self.buf, b"\r\n") {
                    let line: Vec<u8> = self.buf.drain(..end + 2).collect();
                    return Some(line[..end].to_vec());
                }
                if self.buf.len() > MAX_REQUEST_HEAD {
                    return None;
                }
                if !self.fill().await {
                    return None;
                }
            }
        }

        /// Read an HTTP/1.1 chunked request body, capped at `cap` bytes.
        async fn read_chunked_body(&mut self, cap: usize) -> Result<Vec<u8>, GitError> {
            let mut out: Vec<u8> = Vec::new();
            loop {
                let Some(line) = self.read_line().await else {
                    break;
                };
                let field = line.split(|b| *b == b';').next().unwrap_or(&[]);
                let text = String::from_utf8_lossy(field).trim().to_string();
                let Ok(size) = usize::from_str_radix(&text, 16) else {
                    return Err(GitError::BadRequest("bad chunk size".to_string()));
                };
                if size == 0 {
                    // Consume any trailer lines up to the terminating blank line.
                    while let Some(line) = self.read_line().await {
                        if line.is_empty() {
                            break;
                        }
                    }
                    break;
                }
                // NB: `out.len() + size > cap` would OVERFLOW. `size` is the
                // peer's hex chunk header and can be usize::MAX, whose sum wraps
                // to a small value that passes the check — and read_exact_body is
                // then handed an unbounded length, buffering until the socket
                // idles. Compare against the room that is left instead.
                if size > cap.saturating_sub(out.len()) {
                    return Err(GitError::BadRequest("request body too large".to_string()));
                }
                let chunk = self.read_exact_body(size).await;
                if chunk.len() < size {
                    break;
                }
                out.extend_from_slice(&chunk);
                let _ = self.read_line().await; // trailing CRLF after the data
            }
            Ok(out)
        }
    }

    /// Parse a request head into its line + the headers the server reads.
    fn parse_head(head: &str) -> Owned {
        let mut lines = head.split("\r\n");
        let line = lines.next().unwrap_or("");
        let mut parts = line.split(' ').filter(|p| !p.is_empty());
        let mut owned = Owned {
            method: parts.next().unwrap_or("GET").to_string(),
            target: parts.next().unwrap_or("/").to_string(),
            version: parts.next().unwrap_or("HTTP/1.1").to_string(),
            ..Owned::default()
        };
        for raw in lines {
            let Some((name, value)) = raw.split_once(':') else {
                continue;
            };
            let value = value.trim().to_string();
            match name.trim().to_ascii_lowercase().as_str() {
                "host" => owned.host = Some(value),
                "x-forwarded-proto" => owned.forwarded_proto = Some(value),
                "authorization" => owned.authorization = Some(value),
                "accept-encoding" => owned.accept_encoding = Some(value),
                "if-none-match" => owned.if_none_match = Some(value),
                "git-protocol" => owned.git_protocol = Some(value),
                "content-encoding" => owned.content_encoding = Some(value),
                "connection" => owned.connection = Some(value),
                "content-length" => owned.content_length = Some(value),
                "transfer-encoding" => owned.transfer_encoding = Some(value),
                _ => {}
            }
        }
        owned
    }

    /// Read the POST body under the clone body cap (Content-Length or chunked).
    async fn read_body(conn: &mut Conn, owned: &Owned, cap: usize) -> Result<Vec<u8>, GitError> {
        let te = owned
            .transfer_encoding
            .as_deref()
            .unwrap_or("")
            .to_lowercase();
        if te.contains("chunked") {
            return conn.read_chunked_body(cap).await;
        }
        let Some(raw) = owned.content_length.as_deref() else {
            return Ok(Vec::new());
        };
        let Ok(n) = raw.trim().parse::<i64>() else {
            return Err(GitError::BadRequest("bad Content-Length".to_string()));
        };
        if n < 0 {
            return Err(GitError::BadRequest("bad Content-Length".to_string()));
        }
        let n = usize::try_from(n).unwrap_or(usize::MAX);
        if n > cap {
            return Err(GitError::BadRequest("request body too large".to_string()));
        }
        Ok(conn.read_exact_body(n).await)
    }

    /// Write a reply, suppressing the body for a `HEAD` request.
    async fn write_resp(sock: &mut TcpStream, resp: Resp, head_only: bool) -> std::io::Result<()> {
        sock.write_all(resp.head().as_bytes()).await?;
        if head_only {
            return sock.flush().await;
        }
        match resp.body {
            Body::Bytes(bytes) => {
                if !bytes.is_empty() {
                    sock.write_all(&bytes).await?;
                }
            }
            Body::Stream(stream) => {
                // Pull the (blocking) child/file stream on a blocking worker and
                // forward its chunks, so the async runtime is never stalled.
                let (tx, mut rx) = mpsc::channel::<Vec<u8>>(4);
                tokio::task::spawn_blocking(move || {
                    for chunk in stream {
                        if chunk.is_empty() {
                            continue;
                        }
                        if tx.blocking_send(chunk).is_err() {
                            break;
                        }
                    }
                });
                while let Some(chunk) = rx.recv().await {
                    sock.write_all(&chunk).await?;
                }
            }
        }
        sock.flush().await
    }

    /// Serve one connection: read requests, route them, write the replies.
    async fn handle_conn(
        sock: TcpStream,
        server: &Arc<Server>,
        clone_slots: &Arc<Semaphore>,
        peer: &str,
    ) {
        let timeout = Duration::from_secs_f64(server.config.socket_timeout.clamp(0.001, 86_400.0));
        let mut conn = Conn {
            sock,
            buf: Vec::new(),
            timeout,
        };
        for _ in 0..MAX_KEEPALIVE_REQUESTS {
            let Some(head) = conn.read_head().await else {
                return;
            };
            let mut owned = parse_head(&head);
            let head_only = owned.method == "HEAD";
            let is_post = owned.method == "POST";

            // Acquire the clone-concurrency slot *before* reading the (up to
            // `clone_max_body_bytes`) request body into RAM: otherwise peak
            // buffered body would scale with the whole worker pool instead of
            // the much smaller clone budget.
            let mut permit = None;
            let mut body_error = None;
            if is_post {
                let route = Route::of("POST", &owned.target, &server.config.url_prefix);
                if matches!(route, Route::UploadPack { .. }) && server.config.enable_clone {
                    match Arc::clone(clone_slots).try_acquire_owned() {
                        Ok(p) => permit = Some(p),
                        Err(_) => {
                            let resp = server.git_busy();
                            let _ = write_resp(&mut conn.sock, resp, head_only).await;
                            conn.drain_refused().await;
                            return;
                        }
                    }
                }
                match read_body(&mut conn, &owned, server.config.clone_max_body_bytes).await {
                    Ok(body) => owned.body = body,
                    Err(e) => body_error = Some(e),
                }
            }

            // Only a POST body is ever read; a GET/HEAD that declares one would
            // leave its bytes on the socket and desync the next keep-alive
            // request, so such a connection is answered and then closed.
            let undrained_body =
                !is_post && (owned.content_length.is_some() || owned.transfer_encoding.is_some());
            let keep_alive = owned.wants_keep_alive() && !is_post && !undrained_body;
            let (method, target) = (owned.method.clone(), owned.target.clone());
            let started = std::time::Instant::now();
            let srv = Arc::clone(server);
            let body_error_seen = body_error.is_some();
            let routed = match body_error {
                Some(err) => {
                    // The body was refused before any routing: counted like the
                    // reference's `_dispatch` does, with no resolved action.
                    registry().begin();
                    let resp = srv.error_response(&err, owned.accept_encoding.as_deref());
                    registry().end(resp.status, "", 0.0);
                    super::Routed { resp, action: "" }
                }
                None => {
                    // git work blocks; keep it off the async runtime's threads.
                    let Ok(routed) =
                        tokio::task::spawn_blocking(move || srv.handle(&owned.as_request())).await
                    else {
                        return;
                    };
                    routed
                }
            };
            if server.config.verbose {
                // One structured, timed access line per request.
                let action = if routed.action.is_empty() {
                    "-"
                } else {
                    routed.action
                };
                let dur_ms = started.elapsed().as_secs_f64() * 1000.0;
                if crawlcore::logfmt::format().is_json() {
                    eprintln!(
                        "{}",
                        crawlcore::logfmt::request_line(
                            "gitweb",
                            &crawlcore::logfmt::Request {
                                method: &method,
                                path: &target,
                                status: routed.resp.status,
                                duration_ms: dur_ms,
                                peer,
                                action: routed.action,
                            }
                        )
                    );
                } else {
                    // Byte-identical to what this server has always printed.
                    eprintln!(
                        "method={method} path=\"{target}\" status={} action={action} \
                         dur_ms={dur_ms:.1} client={peer}",
                        routed.resp.status,
                    );
                }
            }
            let close = routed.resp.close || !keep_alive;
            if write_resp(&mut conn.sock, routed.resp, head_only)
                .await
                .is_err()
            {
                return;
            }
            drop(permit);
            if body_error_seen {
                conn.drain_refused().await;
            }
            if close {
                return;
            }
        }
    }

    /// Accept and serve connections until the listener errors.
    ///
    /// At most `config.max_workers` connections are handled at once (clamped to
    /// `MAX_CONNECTIONS`); a connection arriving with every slot taken is closed
    /// immediately and counted, rather than queued — the reference's
    /// `BoundedThreadingHTTPServer` Slowloris guard.
    ///
    /// # Errors
    /// Propagates a fatal `accept()` error.
    pub async fn serve(listener: TcpListener, server: Arc<Server>) -> std::io::Result<()> {
        let max_conns = server.config.max_workers.clamp(1, MAX_CONNECTIONS);
        let slots = Arc::new(Semaphore::new(max_conns));
        let clone_slots = Arc::new(Semaphore::new(
            server
                .config
                .clone_max_concurrency
                .clamp(1, MAX_CONNECTIONS),
        ));
        loop {
            let (sock, peer) = listener.accept().await?;
            let Ok(permit) = Arc::clone(&slots).try_acquire_owned() else {
                registry().reject();
                drop(sock); // over the connection bound: refuse, never queue
                continue;
            };
            let server = Arc::clone(&server);
            let clone_slots = Arc::clone(&clone_slots);
            tokio::spawn(async move {
                let _permit = permit;
                handle_conn(sock, &server, &clone_slots, &peer.to_string()).await;
            });
        }
    }

    /// Bind `config.host:config.port` and serve until the listener errors.
    ///
    /// # Errors
    /// A bad configuration, a bind failure, or a fatal `accept()` error.
    pub async fn serve_config(config: Config) -> std::io::Result<()> {
        let verbose = config.verbose;
        let addr = format!("{}:{}", config.host, config.port);
        let server = Server::new(config)
            .map_err(|e| std::io::Error::new(std::io::ErrorKind::InvalidInput, e))?;
        let listener = TcpListener::bind(&addr)
            .await
            .map_err(|e| std::io::Error::new(e.kind(), format!("cannot bind {addr}: {e}")))?;
        let bound = listener.local_addr()?;
        if verbose {
            println!(
                "gitweb serving repos in {} at http://{}:{}/",
                server.config.root.display(),
                bound.ip(),
                bound.port()
            );
        }
        serve(listener, Arc::new(server)).await
    }
}
