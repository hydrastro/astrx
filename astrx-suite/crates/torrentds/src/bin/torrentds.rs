//! The `torrentds` command-line entrypoint — a dependency-free port of the Python
//! CLI (`legacy-python/torrentds/torrentds/cli.py`, `python3 -m torrentds`).
//!
//! Six subcommands drive the library. `index` runs the DHT harvester ([`Indexer`])
//! and periodically [`Store::snapshot`]s it to `--db`; `search` [`Store::restore`]s
//! that snapshot and runs the no-JS [`SearchServer`]; `tracker` runs the HTTP +
//! UDP BitTorrent trackers over a shared [`PeerStore`]; `stats` restores and
//! prints the store statistics; `block` adds to the blocklist and purges matches;
//! `backup` restores and writes a fresh snapshot to `--out`.
//!
//! Unlike the Python engine there is no SQLite file: the whole persistence unit
//! is a bencode snapshot blob, so `--db` is that blob's path (`Store::snapshot` /
//! `Store::restore`) and `--peers-db` is the [`PeerStore`] snapshot's path.
//!
//! The whole binary is gated behind the crate's `net` feature (see the `[[bin]]`
//! `required-features` in `Cargo.toml`), so the default `torrentds` build stays a
//! pure, zero-dependency library. Argument parsing is hand-rolled — no `clap`, no
//! third-party crate — to keep that guarantee. Exit codes match argparse: `0`
//! success (or `--help`/`--version`), `1` a runtime failure, `2` a usage error.
//!
//! # Documented divergences from the Python CLI
//!
//! * **`search --trackers`** (remote scrape aggregation across foreign trackers)
//!   is not ported: the Rust crate has no outbound scrape client. Swarm health is
//!   instead folded in from this deployment's own tracker via `--peers-db`, which
//!   reads the snapshot the `tracker` subcommand writes.
//! * **Shutdown is not signal-driven.** `tokio` is built here without its
//!   `signal` feature (it would add third-party crates to the audited dependency
//!   closure), so `index` cannot save state from a `SIGINT` handler the way the
//!   Python CLI does. It instead persists the store every `--save-interval`
//!   seconds, so terminating the process loses at most one interval of harvest.
//! * `index` prints the primary node's bound address where Python prints its
//!   hex node id (the id is internal to [`Indexer`]).
//! * `backup` refuses to overwrite an existing destination or a URI-looking one,
//!   matching the `websearch` CLI's guards.
#![forbid(unsafe_code)]

use std::io::ErrorKind;
use std::net::{SocketAddr, ToSocketAddrs};
use std::path::Path;
use std::process::ExitCode;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use torrentds::peerstore::PeerStore;
use torrentds::search::{serve_search, SearchServer};
use torrentds::store::{Stats, Store, DEFAULT_SPAM_THRESHOLD};
use torrentds::tracker_http::serve_http_tracker;
use torrentds::tracker_udp::UdpTracker;
use torrentds::{default_bootstrap, Indexer, IndexerConfig};

const PROG: &str = "torrentds";

/// BEP-15 connection-id validity window, in seconds — the Python
/// `UDPTracker(conn_ttl=120)` default.
const UDP_CONN_TTL: u64 = 120;

/// The BEP-51 sampling period and the maintenance (prune + retention) period, in
/// seconds — the Python `Indexer.run` defaults, which have no CLI flag there.
const SAMPLE_INTERVAL: f64 = 10.0;
const MAINTENANCE_INTERVAL: f64 = 300.0;

/// How often the `tracker` subcommand flushes swarms to `--peers-db` (the Python
/// `_saver` thread's 60-second period).
const PEERS_SAVE_INTERVAL: u64 = 60;

// ---------------------------------------------------------------------------
// Parsed command surface
// ---------------------------------------------------------------------------

/// A fully parsed subcommand invocation.
#[derive(Debug, PartialEq)]
enum Command {
    Index(IndexArgs),
    Search(SearchArgs),
    Tracker(TrackerArgs),
    Stats(StatsArgs),
    Block(BlockArgs),
    Backup(BackupArgs),
}

/// `index` — mirrors the Python `index` subparser (names + defaults).
#[derive(Debug, PartialEq)]
struct IndexArgs {
    db: String,
    host: String,
    port: u16,
    bootstrap: Option<String>,
    no_bootstrap: bool,
    interval: f64,
    concurrency: usize,
    nodes: usize,
    neighbor: bool,
    max_torrents: Option<usize>,
    max_age_days: Option<f64>,
    /// Rust addition: the store-autosave period, standing in for the Python
    /// CLI's save-on-SIGINT (see the module docs).
    save_interval: u64,
}

/// `search` — the no-JS search server + JSON API.
#[derive(Debug, PartialEq)]
struct SearchArgs {
    db: String,
    host: String,
    port: u16,
    admin_token: Option<String>,
    spam_threshold: Option<f64>,
    /// Rust replacement for the Python `--trackers` scrape aggregation: swarm
    /// health from this deployment's own tracker snapshot.
    peers_db: Option<String>,
    /// The self-describing base URL for RSS/Torznab links. Python derives it
    /// internally; the Rust [`SearchServer`] takes it explicitly.
    base_url: Option<String>,
}

/// `tracker` — the HTTP + UDP BitTorrent trackers.
#[derive(Debug, PartialEq)]
struct TrackerArgs {
    db: Option<String>,
    host: String,
    http_port: u16,
    udp_port: u16,
    interval: u64,
    allow: Option<String>,
    peers_db: Option<String>,
}

/// `stats` — print store statistics.
#[derive(Debug, PartialEq)]
struct StatsArgs {
    db: String,
}

/// `block` — add to the blocklist and purge matching torrents.
#[derive(Debug, PartialEq)]
struct BlockArgs {
    db: String,
    infohash: Option<String>,
    keyword: Option<String>,
}

/// `backup` — write a fresh snapshot of `--db` to `--out`.
#[derive(Debug, PartialEq)]
struct BackupArgs {
    db: String,
    out: String,
}

/// A parse outcome that is not a runnable command: either text to print on stdout
/// and exit 0 (`--help`, `--version`), or a usage error to print on stderr and
/// exit 2 — mirroring how Python's `argparse` handles the two.
#[derive(Debug, PartialEq)]
enum CliError {
    Print(String),
    Usage(String),
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let cmd = match parse_args(&argv) {
        Ok(c) => c,
        Err(CliError::Print(text)) => {
            print!("{text}");
            return ExitCode::from(0);
        }
        Err(CliError::Usage(msg)) => {
            eprintln!("{msg}");
            return ExitCode::from(2);
        }
    };
    // The three offline commands need no runtime at all.
    match &cmd {
        Command::Stats(a) => return run_stats(a),
        Command::Block(a) => return run_block(a),
        Command::Backup(a) => return run_backup(a),
        _ => {}
    }
    let rt = match tokio::runtime::Runtime::new() {
        Ok(rt) => rt,
        Err(e) => {
            eprintln!("error: failed to start async runtime: {e}");
            return ExitCode::from(1);
        }
    };
    rt.block_on(dispatch(cmd))
}

async fn dispatch(cmd: Command) -> ExitCode {
    match cmd {
        Command::Index(a) => run_index(a).await,
        Command::Search(a) => run_search(a).await,
        Command::Tracker(a) => run_tracker(a).await,
        // Handled synchronously in `main` before the runtime is built.
        Command::Stats(a) => run_stats(&a),
        Command::Block(a) => run_block(&a),
        Command::Backup(a) => run_backup(&a),
    }
}

// ---------------------------------------------------------------------------
// Argument parsing (hand-rolled, dependency-free)
// ---------------------------------------------------------------------------

/// Split any `--flag=value` token into two (`--flag`, `value`) so the per-command
/// walkers only ever see `--flag [value]`.
fn normalize(argv: &[String]) -> Vec<String> {
    let mut out = Vec::with_capacity(argv.len());
    for a in argv {
        if a.starts_with("--") {
            if let Some(eq) = a.find('=') {
                out.push(a[..eq].to_string());
                out.push(a[eq + 1..].to_string());
                continue;
            }
        }
        out.push(a.clone());
    }
    out
}

/// The value expected after `flag` (the token at `i`), or a usage error.
fn need<'a>(toks: &'a [String], i: usize, flag: &str) -> Result<&'a str, CliError> {
    toks.get(i)
        .map(String::as_str)
        .ok_or_else(|| CliError::Usage(format!("error: option {flag} requires a value")))
}

fn parse_u64(s: &str, flag: &str) -> Result<u64, CliError> {
    s.parse::<u64>().map_err(|_| {
        CliError::Usage(format!(
            "error: {flag} expects a non-negative integer, got {s:?}"
        ))
    })
}

fn parse_usize(s: &str, flag: &str) -> Result<usize, CliError> {
    s.parse::<usize>().map_err(|_| {
        CliError::Usage(format!(
            "error: {flag} expects a non-negative integer, got {s:?}"
        ))
    })
}

fn parse_u16(s: &str, flag: &str) -> Result<u16, CliError> {
    s.parse::<u16>().map_err(|_| {
        CliError::Usage(format!(
            "error: {flag} expects a port (0..=65535), got {s:?}"
        ))
    })
}

fn parse_f64(s: &str, flag: &str) -> Result<f64, CliError> {
    s.parse::<f64>()
        .map_err(|_| CliError::Usage(format!("error: {flag} expects a number, got {s:?}")))
}

/// A `--db`-style required option that was never given → argparse's
/// "the following arguments are required" usage error.
fn require(value: Option<String>, flag: &str, sub: &str) -> Result<String, CliError> {
    value.ok_or_else(|| {
        CliError::Usage(format!(
            "error: the following arguments are required: {flag} (see `{PROG} {sub} --help`)"
        ))
    })
}

fn unknown_option(opt: &str, sub: &str) -> CliError {
    CliError::Usage(format!(
        "error: unrecognized option {opt} for `{sub}` (try `{PROG} {sub} --help`)"
    ))
}

fn unexpected_arg(arg: &str, sub: &str) -> CliError {
    CliError::Usage(format!("error: unexpected argument {arg:?} for `{sub}`"))
}

/// Top-level dispatch: pick the subcommand, then hand its arguments to the
/// matching walker. Mirrors the required-subcommand behaviour of the Python
/// `argparse` setup (no subcommand → usage error, exit 2).
fn parse_args(argv: &[String]) -> Result<Command, CliError> {
    let toks = normalize(argv);
    let Some(sub) = toks.first() else {
        return Err(CliError::Usage(format!(
            "error: a subcommand is required\n\n{}",
            top_help()
        )));
    };
    let rest = &toks[1..];
    match sub.as_str() {
        "index" => parse_index(rest).map(Command::Index),
        "search" => parse_search(rest).map(Command::Search),
        "tracker" => parse_tracker(rest).map(Command::Tracker),
        "stats" => parse_stats(rest).map(Command::Stats),
        "block" => parse_block(rest).map(Command::Block),
        "backup" => parse_backup(rest).map(Command::Backup),
        "-h" | "--help" | "help" => Err(CliError::Print(top_help())),
        "--version" => Err(CliError::Print(format!(
            "{PROG} {}\n",
            env!("CARGO_PKG_VERSION")
        ))),
        other => Err(CliError::Usage(format!(
            "error: unknown command {other:?}\n\n{}",
            top_help()
        ))),
    }
}

fn parse_index(toks: &[String]) -> Result<IndexArgs, CliError> {
    let mut db: Option<String> = None;
    let mut a = IndexArgs {
        db: String::new(),
        host: "127.0.0.1".to_string(),
        port: 6881,
        bootstrap: None,
        no_bootstrap: false,
        interval: 1.0,
        concurrency: 20,
        nodes: 1,
        neighbor: false,
        max_torrents: None,
        max_age_days: None,
        save_interval: 30,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(index_help())),
            "--db" => {
                i += 1;
                db = Some(need(toks, i, "--db")?.to_string());
            }
            "--host" => {
                i += 1;
                a.host = need(toks, i, "--host")?.to_string();
            }
            "--port" => {
                i += 1;
                a.port = parse_u16(need(toks, i, "--port")?, "--port")?;
            }
            "--bootstrap" => {
                i += 1;
                a.bootstrap = Some(need(toks, i, "--bootstrap")?.to_string());
            }
            "--no-bootstrap" => a.no_bootstrap = true,
            "--interval" => {
                i += 1;
                a.interval = parse_f64(need(toks, i, "--interval")?, "--interval")?;
            }
            "--concurrency" => {
                i += 1;
                a.concurrency = parse_usize(need(toks, i, "--concurrency")?, "--concurrency")?;
            }
            "--nodes" => {
                i += 1;
                a.nodes = parse_usize(need(toks, i, "--nodes")?, "--nodes")?;
            }
            "--neighbor" => a.neighbor = true,
            "--max-torrents" => {
                i += 1;
                a.max_torrents = Some(parse_usize(
                    need(toks, i, "--max-torrents")?,
                    "--max-torrents",
                )?);
            }
            "--max-age-days" => {
                i += 1;
                a.max_age_days = Some(parse_f64(
                    need(toks, i, "--max-age-days")?,
                    "--max-age-days",
                )?);
            }
            "--save-interval" => {
                i += 1;
                a.save_interval = parse_u64(need(toks, i, "--save-interval")?, "--save-interval")?;
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "index")),
            other => return Err(unexpected_arg(other, "index")),
        }
        i += 1;
    }
    a.db = require(db, "--db", "index")?;
    Ok(a)
}

fn parse_search(toks: &[String]) -> Result<SearchArgs, CliError> {
    let mut db: Option<String> = None;
    let mut a = SearchArgs {
        db: String::new(),
        host: "127.0.0.1".to_string(),
        port: 8804,
        admin_token: None,
        spam_threshold: None,
        peers_db: None,
        base_url: None,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(search_help())),
            "--db" => {
                i += 1;
                db = Some(need(toks, i, "--db")?.to_string());
            }
            "--host" => {
                i += 1;
                a.host = need(toks, i, "--host")?.to_string();
            }
            "--port" => {
                i += 1;
                a.port = parse_u16(need(toks, i, "--port")?, "--port")?;
            }
            "--admin-token" => {
                i += 1;
                a.admin_token = Some(need(toks, i, "--admin-token")?.to_string());
            }
            "--spam-threshold" => {
                i += 1;
                a.spam_threshold = Some(parse_f64(
                    need(toks, i, "--spam-threshold")?,
                    "--spam-threshold",
                )?);
            }
            "--peers-db" => {
                i += 1;
                a.peers_db = Some(need(toks, i, "--peers-db")?.to_string());
            }
            "--base-url" => {
                i += 1;
                a.base_url = Some(need(toks, i, "--base-url")?.to_string());
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "search")),
            other => return Err(unexpected_arg(other, "search")),
        }
        i += 1;
    }
    a.db = require(db, "--db", "search")?;
    Ok(a)
}

fn parse_tracker(toks: &[String]) -> Result<TrackerArgs, CliError> {
    let mut a = TrackerArgs {
        db: None,
        host: "127.0.0.1".to_string(),
        http_port: 8805,
        udp_port: 6969,
        interval: 1800,
        allow: None,
        peers_db: None,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(tracker_help())),
            "--db" => {
                i += 1;
                a.db = Some(need(toks, i, "--db")?.to_string());
            }
            "--host" => {
                i += 1;
                a.host = need(toks, i, "--host")?.to_string();
            }
            "--http-port" => {
                i += 1;
                a.http_port = parse_u16(need(toks, i, "--http-port")?, "--http-port")?;
            }
            "--udp-port" => {
                i += 1;
                a.udp_port = parse_u16(need(toks, i, "--udp-port")?, "--udp-port")?;
            }
            "--interval" => {
                i += 1;
                a.interval = parse_u64(need(toks, i, "--interval")?, "--interval")?;
            }
            "--allow" => {
                i += 1;
                a.allow = Some(need(toks, i, "--allow")?.to_string());
            }
            "--peers-db" => {
                i += 1;
                a.peers_db = Some(need(toks, i, "--peers-db")?.to_string());
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "tracker")),
            other => return Err(unexpected_arg(other, "tracker")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_stats(toks: &[String]) -> Result<StatsArgs, CliError> {
    let mut db: Option<String> = None;
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(stats_help())),
            "--db" => {
                i += 1;
                db = Some(need(toks, i, "--db")?.to_string());
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "stats")),
            other => return Err(unexpected_arg(other, "stats")),
        }
        i += 1;
    }
    Ok(StatsArgs {
        db: require(db, "--db", "stats")?,
    })
}

fn parse_block(toks: &[String]) -> Result<BlockArgs, CliError> {
    let mut db: Option<String> = None;
    let mut infohash: Option<String> = None;
    let mut keyword: Option<String> = None;
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(block_help())),
            "--db" => {
                i += 1;
                db = Some(need(toks, i, "--db")?.to_string());
            }
            "--infohash" => {
                i += 1;
                infohash = Some(need(toks, i, "--infohash")?.to_string());
            }
            "--keyword" => {
                i += 1;
                keyword = Some(need(toks, i, "--keyword")?.to_string());
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "block")),
            other => return Err(unexpected_arg(other, "block")),
        }
        i += 1;
    }
    Ok(BlockArgs {
        db: require(db, "--db", "block")?,
        infohash,
        keyword,
    })
}

fn parse_backup(toks: &[String]) -> Result<BackupArgs, CliError> {
    let mut db: Option<String> = None;
    let mut out: Option<String> = None;
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(backup_help())),
            "--db" => {
                i += 1;
                db = Some(need(toks, i, "--db")?.to_string());
            }
            "--out" => {
                i += 1;
                out = Some(need(toks, i, "--out")?.to_string());
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "backup")),
            other => return Err(unexpected_arg(other, "backup")),
        }
        i += 1;
    }
    Ok(BackupArgs {
        db: require(db, "--db", "backup")?,
        out: require(out, "--out", "backup")?,
    })
}

// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------

/// `host:port,host:port,…` → the parsed endpoints — the port of the Python
/// `parse_hostports` (blank entries dropped, a bare port means `127.0.0.1`).
fn parse_hostports(text: Option<&str>, flag: &str) -> Result<Vec<(String, u16)>, CliError> {
    let mut out = Vec::new();
    let Some(text) = text else { return Ok(out) };
    for item in text.split(',') {
        let item = item.trim();
        if item.is_empty() {
            continue;
        }
        let (host, port) = match item.rsplit_once(':') {
            Some((h, p)) => (h, p),
            None => ("", item),
        };
        let host = if host.is_empty() { "127.0.0.1" } else { host };
        out.push((host.to_string(), parse_u16(port, flag)?));
    }
    Ok(out)
}

/// `a,b,c` → the trimmed, lowercased, non-empty entries (the Python
/// `--allow` split).
fn split_csv(text: &str) -> Vec<String> {
    text.split(',')
        .map(|s| s.trim().to_ascii_lowercase())
        .filter(|s| !s.is_empty())
        .collect()
}

/// A 40-char hex infohash → its 20 raw bytes (`None` if it is not valid hex of
/// the right length).
fn hex20(s: &str) -> Option<[u8; 20]> {
    let s = s.trim();
    if s.len() != 40 {
        return None;
    }
    let mut out = [0u8; 20];
    let b = s.as_bytes();
    for (i, slot) in out.iter_mut().enumerate() {
        let hi = (b[2 * i] as char).to_digit(16)?;
        let lo = (b[2 * i + 1] as char).to_digit(16)?;
        *slot = (hi * 16 + lo) as u8;
    }
    Some(out)
}

/// Every valid 40-hex entry of `hexes`, raw.
fn hex20_all<I: IntoIterator<Item = String>>(hexes: I) -> Vec<[u8; 20]> {
    hexes.into_iter().filter_map(|h| hex20(&h)).collect()
}

/// `^[A-Za-z][A-Za-z0-9+.\-]*:` — so a `backup --out` that looks like a
/// URI/scheme is refused (a plain local path is wanted).
fn looks_like_uri(s: &str) -> bool {
    let mut chars = s.chars();
    match chars.next() {
        Some(c) if c.is_ascii_alphabetic() => {}
        _ => return false,
    }
    for c in chars {
        if c == ':' {
            return true;
        }
        if !(c.is_ascii_alphanumeric() || c == '+' || c == '.' || c == '-') {
            return false;
        }
    }
    false
}

/// Resolve `host:port` to a bind address (a hostname is resolved through the
/// system resolver, as Python's `bind` does).
fn bind_addr(host: &str, port: u16) -> Result<SocketAddr, String> {
    (host, port)
        .to_socket_addrs()
        .map_err(|e| format!("error: cannot resolve {host}:{port}: {e}"))?
        .next()
        .ok_or_else(|| format!("error: {host}:{port} resolved to no address"))
}

/// Load a store from a snapshot file: a missing file yields a fresh empty store
/// (so `stats`/`search` on a never-indexed db still work); a present-but-corrupt
/// file is a hard error.
fn read_store(db: &str, spam_threshold: f64) -> Result<Store, String> {
    match std::fs::read(db) {
        Ok(bytes) => Store::restore(&bytes, spam_threshold).ok_or_else(|| {
            format!("error: {db} is not a valid torrentds snapshot (corrupt or truncated)")
        }),
        Err(e) if e.kind() == ErrorKind::NotFound => Ok(Store::with_spam_threshold(spam_threshold)),
        Err(e) => Err(format!("error: cannot read {db}: {e}")),
    }
}

/// Persist a store snapshot to `db`.
fn write_store(store: &Store, db: &str) -> Result<usize, String> {
    let blob = store.snapshot();
    std::fs::write(db, &blob).map_err(|e| format!("error: cannot write store to {db}: {e}"))?;
    Ok(blob.len())
}

/// Epoch seconds.
fn now_secs() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

/// A non-negative, finite seconds value as a [`Duration`] (a hostile flag value
/// must not panic `Duration::from_secs_f64`).
fn secs(v: f64) -> Duration {
    Duration::from_secs_f64(if v.is_finite() {
        v.clamp(0.0, 86_400.0)
    } else {
        0.0
    })
}

/// The Python `cmd_stats` report over a [`Stats`], as printable lines.
fn stats_lines(s: &Stats, dht_nodes: usize) -> Vec<String> {
    vec![
        format!("torrents indexed : {}", s.torrents),
        format!("files indexed    : {}", s.files),
        format!("total size       : {} bytes", s.total_size),
        format!(
            "infohashes seen  : {} (pending fetch: {})",
            s.discovered, s.pending
        ),
        format!("DHT contacts     : {dht_nodes}"),
        format!(
            "blocklist        : {} infohashes, {} keywords",
            s.blocked_infohash, s.blocked_keyword
        ),
    ]
}

// ---------------------------------------------------------------------------
// Command wiring
// ---------------------------------------------------------------------------

/// `index` — the port of the Python `cmd_index`.
async fn run_index(a: IndexArgs) -> ExitCode {
    let store = match read_store(&a.db, DEFAULT_SPAM_THRESHOLD) {
        Ok(s) => Arc::new(Mutex::new(s)),
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let bind = match bind_addr(&a.host, a.port) {
        Ok(addr) => addr,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let bootstrap = if a.no_bootstrap {
        Vec::new()
    } else {
        match a.bootstrap.as_deref() {
            Some(spec) => match parse_hostports(Some(spec), "--bootstrap") {
                Ok(v) => v,
                Err(CliError::Usage(msg) | CliError::Print(msg)) => {
                    eprintln!("{msg}");
                    return ExitCode::from(2);
                }
            },
            None => default_bootstrap(),
        }
    };

    let mut indexer = Indexer::new(
        store.clone(),
        IndexerConfig {
            bind,
            bootstrap,
            fetch_concurrency: a.concurrency,
            num_nodes: a.nodes,
            neighbor: a.neighbor,
            ..IndexerConfig::default()
        },
    );
    if let Err(e) = indexer.start().await {
        eprintln!("error: cannot bind DHT node on {bind}: {e}");
        return ExitCode::from(1);
    }
    let bound = indexer
        .local_addr()
        .map_or_else(|| bind.to_string(), |a| a.to_string());
    println!(
        "[index] {} DHT node(s), primary on {bound} (concurrency={}, neighbor={})",
        a.nodes.max(1),
        a.concurrency.max(1),
        a.neighbor
    );
    println!("[index] crawling + BEP-51 sampling + harvesting; Ctrl-C to stop");

    let max_age = a.max_age_days.map(|d| (d * 86_400.0).max(0.0) as u64);
    if let Err(e) = indexer
        .run(
            secs(a.interval),
            secs(SAMPLE_INTERVAL),
            secs(MAINTENANCE_INTERVAL),
            a.max_torrents,
            max_age,
        )
        .await
    {
        eprintln!("error: harvester failed to start: {e}");
        return ExitCode::from(1);
    }

    // No SIGINT handler is available (see the module docs), so durability comes
    // from persisting the store every `--save-interval` seconds instead.
    let period = Duration::from_secs(a.save_interval.max(1));
    loop {
        tokio::time::sleep(period).await;
        indexer.persist_nodes();
        let (blob_len, s) = {
            let st = store
                .lock()
                .unwrap_or_else(std::sync::PoisonError::into_inner);
            match write_store(&st, &a.db) {
                Ok(n) => (n, st.stats()),
                Err(msg) => {
                    eprintln!("{msg}");
                    indexer.stop();
                    return ExitCode::from(1);
                }
            }
        };
        println!(
            "[index] discovered={} fetched-torrents={} pending={} (saved {blob_len} bytes to {})",
            s.discovered, s.torrents, s.pending, a.db
        );
    }
}

/// `search` — the port of the Python `cmd_search`.
async fn run_search(a: SearchArgs) -> ExitCode {
    if !Path::new(&a.db).exists() {
        eprintln!("note: {} does not exist; serving an empty store", a.db);
    }
    let threshold = a.spam_threshold.unwrap_or(DEFAULT_SPAM_THRESHOLD);
    let store = match read_store(&a.db, threshold) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let torrents = store.len();

    // Swarm health from this deployment's own tracker snapshot (the Rust
    // stand-in for Python's remote `--trackers` scrape aggregation).
    let peer_store = match a.peers_db.as_deref() {
        None => None,
        Some(path) => match std::fs::read(path) {
            Ok(blob) => {
                let mut ps = PeerStore::new(1800);
                let n = ps.restore(&blob, now_secs());
                println!("[search] swarm health from {path} ({n} peer(s))");
                Some(Arc::new(Mutex::new(ps)))
            }
            Err(e) => {
                eprintln!("error: cannot read peers db {path}: {e}");
                return ExitCode::from(1);
            }
        },
    };

    let addr = match bind_addr(&a.host, a.port) {
        Ok(addr) => addr,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let base = a
        .base_url
        .clone()
        .unwrap_or_else(|| format!("http://{}:{}", a.host, a.port));
    let admin_token = a.admin_token.clone().unwrap_or_default();
    let server = SearchServer::new(
        Arc::new(Mutex::new(store)),
        peer_store,
        admin_token.as_str(),
    )
    .with_base_url(base.as_str());

    let (bound, handle) = match serve_search(server, addr).await {
        Ok(v) => v,
        Err(e) => {
            eprintln!("error: cannot bind {addr}: {e}");
            return ExitCode::from(1);
        }
    };
    println!(
        "[search] serving {torrents} torrents on http://{bound}  \
(no-JS UI, /browse, /recent, /api/search, /api/block; base-url={base})"
    );
    if !admin_token.is_empty() {
        println!("[search] POST /api/block enabled (admin token set)");
    }
    match handle.await {
        Ok(()) => ExitCode::from(0),
        Err(e) => {
            eprintln!("error: server stopped: {e}");
            ExitCode::from(1)
        }
    }
}

/// `tracker` — the port of the Python `cmd_tracker`.
async fn run_tracker(a: TrackerArgs) -> ExitCode {
    let mut peer_store = PeerStore::new(a.interval);

    // The operator blocklist (infohashes) feeds the tracker denylist.
    if let Some(db) = a.db.as_deref() {
        match read_store(db, DEFAULT_SPAM_THRESHOLD) {
            Ok(store) => {
                let deny = hex20_all(store.blocked_infohashes());
                println!("[tracker] denylist: {} blocked infohash(es)", deny.len());
                peer_store.set_denylist(deny);
            }
            Err(msg) => {
                eprintln!("{msg}");
                return ExitCode::from(1);
            }
        }
    }
    if let Some(allow) = a.allow.as_deref() {
        let allow = hex20_all(split_csv(allow));
        println!("[tracker] allowlist: {} infohash(es)", allow.len());
        peer_store.set_allowlist(Some(allow));
    }
    // Durable swarms: restore on start, persist periodically.
    if let Some(path) = a.peers_db.as_deref() {
        match std::fs::read(path) {
            Ok(blob) => {
                let restored = peer_store.restore(&blob, now_secs());
                if restored > 0 {
                    println!("[tracker] restored {restored} peer(s) from {path}");
                }
            }
            Err(e) if e.kind() == ErrorKind::NotFound => {}
            Err(e) => {
                eprintln!("error: cannot read peers db {path}: {e}");
                return ExitCode::from(1);
            }
        }
    }

    let peer_store = Arc::new(Mutex::new(peer_store));
    let http_addr = match bind_addr(&a.host, a.http_port) {
        Ok(addr) => addr,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let udp_addr = match bind_addr(&a.host, a.udp_port) {
        Ok(addr) => addr,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let (http_bound, http_handle) = match serve_http_tracker(peer_store.clone(), http_addr).await {
        Ok(v) => v,
        Err(e) => {
            eprintln!("error: cannot bind HTTP tracker on {http_addr}: {e}");
            return ExitCode::from(1);
        }
    };
    let udp = match UdpTracker::bind(udp_addr, peer_store.clone(), UDP_CONN_TTL).await {
        Ok(t) => t,
        Err(e) => {
            eprintln!("error: cannot bind UDP tracker on {udp_addr}: {e}");
            http_handle.abort();
            return ExitCode::from(1);
        }
    };
    let udp_bound = udp.local_addr().unwrap_or(udp_addr);
    println!("[tracker] HTTP  http://{http_bound}/announce  /scrape");
    println!("[tracker] UDP   udp://{udp_bound}  (BEP-15, IPv6/BEP-7, stateless conn-id)");

    // The Python `_saver` thread, as a task: flush swarms every 60s. With no
    // peers db there is nothing to save, so just park on the accept loop.
    let Some(path) = a.peers_db.clone() else {
        return match http_handle.await {
            Ok(()) => ExitCode::from(0),
            Err(e) => {
                eprintln!("error: tracker stopped: {e}");
                ExitCode::from(1)
            }
        };
    };
    let period = Duration::from_secs(PEERS_SAVE_INTERVAL);
    loop {
        tokio::time::sleep(period).await;
        let blob = peer_store
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner)
            .snapshot(now_secs());
        if let Err(e) = std::fs::write(&path, &blob) {
            // Matches Python's `except OSError: pass` — a transient write failure
            // must not take the trackers down.
            eprintln!("warning: cannot write peers db {path}: {e}");
        }
    }
}

/// `stats` — the port of the Python `cmd_stats`.
fn run_stats(a: &StatsArgs) -> ExitCode {
    if !Path::new(&a.db).exists() {
        eprintln!("note: {} does not exist; reporting an empty store", a.db);
    }
    let store = match read_store(&a.db, DEFAULT_SPAM_THRESHOLD) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    for line in stats_lines(&store.stats(), store.load_nodes(usize::MAX).len()) {
        println!("{line}");
    }
    ExitCode::from(0)
}

/// `block` — the port of the Python `cmd_block`.
fn run_block(a: &BlockArgs) -> ExitCode {
    let mut store = match read_store(&a.db, DEFAULT_SPAM_THRESHOLD) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    if let Some(ih) = &a.infohash {
        store.add_block_infohash(ih);
        println!("blocked infohash {ih}");
    }
    if let Some(kw) = &a.keyword {
        store.add_block_keyword(kw);
        println!("blocked keyword {kw:?}");
    }
    let removed = store.purge_blocked();
    println!("purged {removed} matching torrent(s) from the index");
    if let Err(msg) = write_store(&store, &a.db) {
        eprintln!("{msg}");
        return ExitCode::from(1);
    }
    ExitCode::from(0)
}

/// `backup` — the port of the Python `cmd_backup`.
fn run_backup(a: &BackupArgs) -> ExitCode {
    if looks_like_uri(&a.out) {
        eprintln!(
            "error: destination {} looks like a URI/scheme; give a plain local filesystem path",
            a.out
        );
        return ExitCode::from(2);
    }
    if Path::new(&a.out).exists() {
        eprintln!(
            "error: destination {} already exists (refusing to overwrite)",
            a.out
        );
        return ExitCode::from(2);
    }
    if !Path::new(&a.db).exists() {
        eprintln!("error: backup failed: source {} does not exist", a.db);
        return ExitCode::from(1);
    }
    let store = match read_store(&a.db, DEFAULT_SPAM_THRESHOLD) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let bytes = match write_store(&store, &a.out) {
        Ok(n) => n,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    println!(
        "[backup] wrote {} ({} torrents, {bytes} bytes)",
        a.out,
        store.len()
    );
    ExitCode::from(0)
}

// ---------------------------------------------------------------------------
// Help text
// ---------------------------------------------------------------------------

fn top_help() -> String {
    format!(
        "\
{PROG} — DHT torrent-metadata search engine + tracker.

usage: {PROG} <command> [options]

commands:
  index     run the DHT harvester (crawl + fetch metadata into the store)
  search    run the no-JS search web server + JSON API
  tracker   run the HTTP + UDP BitTorrent trackers
  stats     print store statistics
  block     add an infohash/keyword to the blocklist (and purge matches)
  backup    write a fresh snapshot of the store to a local path

Run `{PROG} <command> --help` for per-command options.
"
    )
}

fn index_help() -> String {
    format!(
        "\
usage: {PROG} index --db PATH [options]

run the DHT harvester: crawl, sample (BEP-51) and fetch metadata into the store.

options:
  --db PATH             store snapshot path (created if absent) [required]
  --host HOST           DHT bind address (default: 127.0.0.1)
  --port PORT           DHT bind port (default: 6881)
  --bootstrap LIST      comma list host:port (default: Mainline routers)
  --no-bootstrap        do not contact any bootstrap routers
  --interval SECONDS    crawl interval (default: 1.0)
  --concurrency N       parallel metadata fetches (default: 20)
  --nodes N             DHT node-IDs/ports for ID-space coverage (default: 1)
  --neighbor            aggressive neighbour-ID harvesting (magnetico-style)
  --max-torrents N      retention cap: keep only the N most-recent torrents
  --max-age-days DAYS   retention: drop torrents not seen within this many days
  --save-interval SECS  store autosave period (default: 30); no SIGINT handler
                        is available, so this is how state survives shutdown
"
    )
}

fn search_help() -> String {
    format!(
        "\
usage: {PROG} search --db PATH [options]

serve the no-JS search UI + JSON API over the restored store.

options:
  --db PATH               store snapshot path [required]
  --host HOST             bind address (default: 127.0.0.1)
  --port PORT             bind port (default: 8804)
  --admin-token TOKEN     token for POST /api/block (unset => 403 for all)
  --spam-threshold FLOAT  hide torrents with a spam score >= this (default tuned)
  --peers-db PATH         fold swarm health in from a `tracker --peers-db`
                          snapshot (replaces Python's remote --trackers scrape)
  --base-url URL          self-describing base URL for RSS/Torznab links
                          (default: http://<host>:<port>)
"
    )
}

fn tracker_help() -> String {
    format!(
        "\
usage: {PROG} tracker [options]

run the HTTP (BEP-3/23) + UDP (BEP-15) BitTorrent trackers.

options:
  --db PATH          optional store snapshot; sources the blocklist denylist
  --host HOST        bind address (default: 127.0.0.1)
  --http-port PORT   HTTP tracker port (default: 8805)
  --udp-port PORT    UDP tracker port (default: 6969)
  --interval SECS    announce interval (default: 1800)
  --allow LIST       comma list of allowed infohashes (hex)
  --peers-db PATH    file to persist/restore swarms across restart
"
    )
}

fn stats_help() -> String {
    format!(
        "\
usage: {PROG} stats --db PATH

print store statistics.

options:
  --db PATH   store snapshot path [required]
"
    )
}

fn block_help() -> String {
    format!(
        "\
usage: {PROG} block --db PATH [--infohash HEX] [--keyword TEXT]

add to the blocklist, purge already-indexed matches, and save the store.

options:
  --db PATH         store snapshot path [required]
  --infohash HEX    40-char hex infohash to block
  --keyword TEXT    substring to block by name
"
    )
}

fn backup_help() -> String {
    format!(
        "\
usage: {PROG} backup --db PATH --out DEST

write a fresh snapshot of the store to a new local file.

options:
  --db PATH    source store snapshot [required]
  --out DEST   destination path (local file; must not already exist) [required]
"
    )
}

// ---------------------------------------------------------------------------
// Tests — the pure wiring: arg parsing, the hostport/hex/csv helpers, and the
// offline commands (stats / block / backup) over real snapshot files. No DHT,
// no sockets, no network.
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;
    use torrentds::bencode::{encode, Ben, Dict};
    use torrentds::infohash::sha1;
    use torrentds::metadata::parse_info;

    fn argv(parts: &[&str]) -> Vec<String> {
        parts.iter().map(|s| (*s).to_string()).collect()
    }

    fn tmp(tag: &str, line: u32) -> std::path::PathBuf {
        std::env::temp_dir().join(format!("torrentds_cli_{tag}_{}_{line}", std::process::id()))
    }

    /// A store holding one real torrent, for the offline command tests.
    fn store_with_one(name: &str) -> Store {
        let mut info = Dict::new();
        info.insert(b"length".to_vec(), Ben::Int(700 * 1024 * 1024));
        info.insert(b"name".to_vec(), Ben::Bytes(name.as_bytes().to_vec()));
        info.insert(b"piece length".to_vec(), Ben::Int(262_144));
        info.insert(b"pieces".to_vec(), Ben::Bytes(vec![0xABu8; 20 * 3]));
        let info_bytes = encode(&Ben::Dict(info.clone()));
        let meta = parse_info(&info, None, Some(info_bytes)).expect("info parses");
        let mut store = Store::new();
        store.store_metadata(&meta, 1_700_000_000);
        store.add_discovered(&sha1(b"pending-one"), None, 1_700_000_000);
        store
    }

    // -- parsing ------------------------------------------------------------

    #[test]
    fn index_defaults_match_python() {
        let a = match parse_args(&argv(&["index", "--db", "t.db"])) {
            Ok(Command::Index(a)) => a,
            other => panic!("expected index, got {other:?}"),
        };
        assert_eq!(a.db, "t.db");
        assert_eq!(a.host, "127.0.0.1");
        assert_eq!(a.port, 6881);
        assert_eq!(a.bootstrap, None);
        assert!(!a.no_bootstrap);
        assert_eq!(a.interval, 1.0);
        assert_eq!(a.concurrency, 20);
        assert_eq!(a.nodes, 1);
        assert!(!a.neighbor);
        assert_eq!(a.max_torrents, None);
        assert_eq!(a.max_age_days, None);
        assert_eq!(a.save_interval, 30);
    }

    #[test]
    fn index_parses_all_flags() {
        let a = match parse_args(&argv(&[
            "index",
            "--db",
            "t.db",
            "--host",
            "0.0.0.0",
            "--port",
            "7000",
            "--bootstrap",
            "1.2.3.4:6881,5.6.7.8:6882",
            "--no-bootstrap",
            "--interval",
            "2.5",
            "--concurrency",
            "40",
            "--nodes",
            "3",
            "--neighbor",
            "--max-torrents",
            "1000",
            "--max-age-days",
            "30",
            "--save-interval",
            "5",
        ])) {
            Ok(Command::Index(a)) => a,
            other => panic!("expected index, got {other:?}"),
        };
        assert_eq!(a.host, "0.0.0.0");
        assert_eq!(a.port, 7000);
        assert_eq!(a.bootstrap.as_deref(), Some("1.2.3.4:6881,5.6.7.8:6882"));
        assert!(a.no_bootstrap);
        assert_eq!(a.interval, 2.5);
        assert_eq!(a.concurrency, 40);
        assert_eq!(a.nodes, 3);
        assert!(a.neighbor);
        assert_eq!(a.max_torrents, Some(1000));
        assert_eq!(a.max_age_days, Some(30.0));
        assert_eq!(a.save_interval, 5);
    }

    #[test]
    fn search_defaults_and_flags() {
        let d = match parse_args(&argv(&["search", "--db", "t.db"])) {
            Ok(Command::Search(a)) => a,
            other => panic!("expected search, got {other:?}"),
        };
        assert_eq!(d.host, "127.0.0.1");
        assert_eq!(d.port, 8804);
        assert_eq!(d.admin_token, None);
        assert_eq!(d.spam_threshold, None);
        assert_eq!(d.peers_db, None);
        assert_eq!(d.base_url, None);

        let a = match parse_args(&argv(&[
            "search",
            "--db=t.db",
            "--host=0.0.0.0",
            "--port=9000",
            "--admin-token=s3cret",
            "--spam-threshold=0.75",
            "--peers-db=peers.db",
            "--base-url=http://pub.example",
        ])) {
            Ok(Command::Search(a)) => a,
            other => panic!("expected search, got {other:?}"),
        };
        assert_eq!(a.db, "t.db");
        assert_eq!(a.host, "0.0.0.0");
        assert_eq!(a.port, 9000);
        assert_eq!(a.admin_token.as_deref(), Some("s3cret"));
        assert_eq!(a.spam_threshold, Some(0.75));
        assert_eq!(a.peers_db.as_deref(), Some("peers.db"));
        assert_eq!(a.base_url.as_deref(), Some("http://pub.example"));
    }

    #[test]
    fn tracker_defaults_and_flags() {
        let d = match parse_args(&argv(&["tracker"])) {
            Ok(Command::Tracker(a)) => a,
            other => panic!("expected tracker, got {other:?}"),
        };
        assert_eq!(d.db, None);
        assert_eq!(d.host, "127.0.0.1");
        assert_eq!(d.http_port, 8805);
        assert_eq!(d.udp_port, 6969);
        assert_eq!(d.interval, 1800);
        assert_eq!(d.allow, None);
        assert_eq!(d.peers_db, None);

        let a = match parse_args(&argv(&[
            "tracker",
            "--db",
            "t.db",
            "--host",
            "0.0.0.0",
            "--http-port",
            "1234",
            "--udp-port",
            "5678",
            "--interval",
            "900",
            "--allow",
            "AA,BB",
            "--peers-db",
            "peers.db",
        ])) {
            Ok(Command::Tracker(a)) => a,
            other => panic!("expected tracker, got {other:?}"),
        };
        assert_eq!(a.db.as_deref(), Some("t.db"));
        assert_eq!(a.http_port, 1234);
        assert_eq!(a.udp_port, 5678);
        assert_eq!(a.interval, 900);
        assert_eq!(a.allow.as_deref(), Some("AA,BB"));
        assert_eq!(a.peers_db.as_deref(), Some("peers.db"));
    }

    #[test]
    fn stats_block_backup_parse() {
        let st = match parse_args(&argv(&["stats", "--db", "s.db"])) {
            Ok(Command::Stats(a)) => a,
            other => panic!("expected stats, got {other:?}"),
        };
        assert_eq!(st.db, "s.db");

        let b = match parse_args(&argv(&[
            "block",
            "--db",
            "s.db",
            "--infohash",
            "aa",
            "--keyword",
            "spam pack",
        ])) {
            Ok(Command::Block(a)) => a,
            other => panic!("expected block, got {other:?}"),
        };
        assert_eq!(b.infohash.as_deref(), Some("aa"));
        assert_eq!(b.keyword.as_deref(), Some("spam pack"));

        let bk = match parse_args(&argv(&["backup", "--db", "src.db", "--out", "dst.db"])) {
            Ok(Command::Backup(a)) => a,
            other => panic!("expected backup, got {other:?}"),
        };
        assert_eq!(bk.db, "src.db");
        assert_eq!(bk.out, "dst.db");
    }

    #[test]
    fn parse_errors_and_help_and_version() {
        // no subcommand / unknown command -> usage error
        assert!(matches!(parse_args(&argv(&[])), Err(CliError::Usage(_))));
        assert!(matches!(
            parse_args(&argv(&["frobnicate"])),
            Err(CliError::Usage(_))
        ));
        // top-level + per-command help
        assert!(matches!(
            parse_args(&argv(&["--help"])),
            Err(CliError::Print(_))
        ));
        for sub in ["index", "search", "tracker", "stats", "block", "backup"] {
            assert!(
                matches!(parse_args(&argv(&[sub, "--help"])), Err(CliError::Print(_))),
                "{sub} --help"
            );
        }
        // --version prints this crate's version
        match parse_args(&argv(&["--version"])) {
            Err(CliError::Print(text)) => {
                assert_eq!(text, format!("torrentds {}\n", env!("CARGO_PKG_VERSION")));
            }
            other => panic!("expected version, got {other:?}"),
        }
        // required --db
        for sub in ["index", "search", "stats", "block"] {
            assert!(
                matches!(parse_args(&argv(&[sub])), Err(CliError::Usage(_))),
                "{sub} without --db"
            );
        }
        // backup requires both
        assert!(matches!(
            parse_args(&argv(&["backup", "--db", "x"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["backup", "--out", "x"])),
            Err(CliError::Usage(_))
        ));
        // unknown option, missing value, bad number, stray positional
        assert!(matches!(
            parse_args(&argv(&["index", "--nope"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["index", "--db"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["index", "--db", "x", "--port", "lots"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["stats", "--db", "x", "junk"])),
            Err(CliError::Usage(_))
        ));
    }

    // -- helpers ------------------------------------------------------------

    #[test]
    fn parse_hostports_matches_python() {
        assert!(parse_hostports(None, "--bootstrap").unwrap().is_empty());
        assert!(parse_hostports(Some(" , ,"), "--bootstrap")
            .unwrap()
            .is_empty());
        assert_eq!(
            parse_hostports(Some("a.example:6881, b.example:6882 ,6883"), "--bootstrap").unwrap(),
            vec![
                ("a.example".to_string(), 6881),
                ("b.example".to_string(), 6882),
                ("127.0.0.1".to_string(), 6883),
            ]
        );
        assert!(parse_hostports(Some("a.example:nope"), "--bootstrap").is_err());
    }

    #[test]
    fn hex_and_csv_helpers() {
        assert_eq!(
            split_csv(" AA , ,bB "),
            vec!["aa".to_string(), "bb".to_string()]
        );
        let ih = "0123456789abcdef0123456789ABCDEF01234567";
        let raw = hex20(ih).expect("valid hex");
        assert_eq!(raw[0], 0x01);
        assert_eq!(raw[19], 0x67);
        assert_eq!(hex20("tooshort"), None);
        assert_eq!(hex20(&"z".repeat(40)), None);
        // only the well-formed entries survive
        assert_eq!(hex20_all(vec![ih.to_string(), "junk".to_string()]).len(), 1);
    }

    #[test]
    fn looks_like_uri_matches_scheme_prefix() {
        assert!(looks_like_uri("file:backup.db"));
        assert!(looks_like_uri("http://host/x"));
        assert!(!looks_like_uri("backup.db"));
        assert!(!looks_like_uri("/abs/path/backup.db"));
        assert!(!looks_like_uri(""));
    }

    #[test]
    fn secs_clamps_hostile_values() {
        assert_eq!(secs(1.5), Duration::from_millis(1500));
        assert_eq!(secs(-1.0), Duration::from_secs(0));
        assert_eq!(secs(f64::NAN), Duration::from_secs(0));
        assert_eq!(secs(f64::INFINITY), Duration::from_secs(0));
    }

    #[test]
    fn stats_lines_render_the_python_report() {
        let s = store_with_one("Report Me").stats();
        let lines = stats_lines(&s, 7);
        assert_eq!(lines[0], "torrents indexed : 1");
        assert_eq!(lines[1], "files indexed    : 1");
        assert!(lines[2].starts_with("total size       : "));
        assert_eq!(lines[3], "infohashes seen  : 1 (pending fetch: 1)");
        assert_eq!(lines[4], "DHT contacts     : 7");
        assert_eq!(lines[5], "blocklist        : 0 infohashes, 0 keywords");
    }

    // -- offline commands over real snapshot files --------------------------

    #[test]
    fn read_store_roundtrips_and_rejects_corruption() {
        let path = tmp("roundtrip", line!());
        let db = path.to_str().unwrap().to_string();
        // A missing file is an empty store (so `stats` on a fresh db works).
        assert_eq!(read_store(&db, DEFAULT_SPAM_THRESHOLD).unwrap().len(), 0);

        let store = store_with_one("Roundtrip Release");
        write_store(&store, &db).unwrap();
        let back = read_store(&db, DEFAULT_SPAM_THRESHOLD).unwrap();
        assert_eq!(back.len(), 1);
        assert_eq!(back.stats(), store.stats());

        std::fs::write(&path, b"not a snapshot").unwrap();
        assert!(read_store(&db, DEFAULT_SPAM_THRESHOLD).is_err());
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn stats_command_runs_over_an_empty_and_a_populated_store() {
        let path = tmp("stats", line!());
        let db = path.to_str().unwrap().to_string();
        // Missing db: a note on stderr, still exit 0.
        assert_eq!(
            format!("{:?}", run_stats(&StatsArgs { db: db.clone() })),
            format!("{:?}", ExitCode::from(0))
        );
        write_store(&store_with_one("Stats Release"), &db).unwrap();
        assert_eq!(
            format!("{:?}", run_stats(&StatsArgs { db: db.clone() })),
            format!("{:?}", ExitCode::from(0))
        );
        // A corrupt db is a runtime error.
        std::fs::write(&path, b"garbage").unwrap();
        assert_eq!(
            format!("{:?}", run_stats(&StatsArgs { db })),
            format!("{:?}", ExitCode::from(1))
        );
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn block_command_blocks_purges_and_persists() {
        let path = tmp("block", line!());
        let db = path.to_str().unwrap().to_string();
        write_store(&store_with_one("Blocked Keyword Release"), &db).unwrap();

        let code = run_block(&BlockArgs {
            db: db.clone(),
            infohash: Some("AA".repeat(20)),
            keyword: Some("keyword".to_string()),
        });
        assert_eq!(format!("{code:?}"), format!("{:?}", ExitCode::from(0)));

        // The purge removed the matching torrent and the blocklist persisted.
        let back = read_store(&db, DEFAULT_SPAM_THRESHOLD).unwrap();
        assert_eq!(back.len(), 0, "keyword-matching torrent purged");
        let s = back.stats();
        assert_eq!(s.blocked_infohash, 1);
        assert_eq!(s.blocked_keyword, 1);
        assert_eq!(back.blocked_infohashes(), vec!["aa".repeat(20)]);
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn backup_command_writes_a_fresh_snapshot_and_guards_the_destination() {
        let src = tmp("backup_src", line!());
        let dst = tmp("backup_dst", line!());
        let (src_s, dst_s) = (
            src.to_str().unwrap().to_string(),
            dst.to_str().unwrap().to_string(),
        );
        write_store(&store_with_one("Backup Release"), &src_s).unwrap();

        let code = run_backup(&BackupArgs {
            db: src_s.clone(),
            out: dst_s.clone(),
        });
        assert_eq!(format!("{code:?}"), format!("{:?}", ExitCode::from(0)));
        assert_eq!(read_store(&dst_s, DEFAULT_SPAM_THRESHOLD).unwrap().len(), 1);

        // Refuses to overwrite, and refuses a URI-looking destination.
        let code = run_backup(&BackupArgs {
            db: src_s.clone(),
            out: dst_s.clone(),
        });
        assert_eq!(format!("{code:?}"), format!("{:?}", ExitCode::from(2)));
        let code = run_backup(&BackupArgs {
            db: src_s.clone(),
            out: "file:elsewhere.db".to_string(),
        });
        assert_eq!(format!("{code:?}"), format!("{:?}", ExitCode::from(2)));

        std::fs::remove_file(&src).ok();
        std::fs::remove_file(&dst).ok();
        // A missing source is a runtime error.
        let code = run_backup(&BackupArgs {
            db: src_s,
            out: dst_s,
        });
        assert_eq!(format!("{code:?}"), format!("{:?}", ExitCode::from(1)));
    }
}
