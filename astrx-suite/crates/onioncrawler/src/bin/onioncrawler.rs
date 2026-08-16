//! The `onioncrawler` command-line entrypoint — a dependency-free port of the
//! Python CLI (`legacy-python/onioncrawler/onioncrawler/__main__.py`,
//! `python3 -m onioncrawler`).
//!
//! Nine subcommands drive the library. `crawl` seeds a [`Crawler`] over the
//! darknet [`Fetcher`], runs it, and [`Store::snapshot`]s the result to `--db`;
//! `search` [`Store::restore`]s that snapshot and serves the no-JS
//! [`SearchServer`]; `submit` and `reseed` admit seed URLs through the darknet
//! gate + abuse filter; `stats`, `authority`, `cluster`, `recrawl` and `backup`
//! are offline maintenance over the same snapshot.
//!
//! Unlike the Python engine there is no SQLite file: the whole persistence unit
//! is a versioned binary snapshot blob, so `--db` is that blob's path
//! ([`Store::snapshot`] / [`Store::restore`]) and every mutating subcommand
//! writes it back when it finishes.
//!
//! The whole binary is gated behind the crate's `net` feature (see the `[[bin]]`
//! `required-features` in `Cargo.toml`), so the default `onioncrawler` build
//! stays a pure, zero-dependency library. Argument parsing is hand-rolled — no
//! `clap`, no third-party crate — to keep that guarantee. Exit codes match
//! argparse: `0` success (or `--help`/`--version`), `1` a runtime failure, `2` a
//! usage error (including `submit`/`reseed` with no URLs, as in Python).
//!
//! # Documented divergences from the Python CLI
//!
//! * **`--fetcher i2p`** is not accepted: the Rust [`Fetcher`] has a Tor SOCKS5
//!   transport and a loopback test transport only (the `i2p` module currently
//!   provides the proxy *encoders*, not a transport). `--enable-i2p` still admits
//!   `.i2p` hosts to the frontier / submissions, as in Python.
//! * **`crawl --tor-pool`** (torfleet: spreading a crawl across N Tor daemons),
//!   **`--media-max-bytes`** (media-hash re-fetch) and **`--max-pages-this-run`**
//!   are not ported — the Rust fetcher takes a single proxy, and the crawl loop
//!   has neither the media re-fetch nor a per-run page counter (only the
//!   cumulative `max_total_pages`).
//! * **`crawl --seed-list` / `--reseed-interval`** are not ported: the scheduled
//!   re-seed daemon is not in the Rust crawl loop. Run `onioncrawler reseed
//!   --seed-list FILE` before (or beside) a crawl instead.
//! * **`search --admin-user` / `--admin-pass`** (HTTP Basic auth) are not ported:
//!   the Rust serve layer authenticates write endpoints with a `Bearer` token
//!   (`--admin-token`) only. **`--metrics-token`**, **`--no-rate-limit`** and
//!   **`--authority-weight`** are likewise not ported — the Rust server has no
//!   metrics gate or rate limiter, and blends host authority per request via the
//!   `?authority=` query parameter rather than a server-wide default.
//! * **Shutdown is not signal-driven.** `tokio` is built here without its
//!   `signal` feature (it would add crates to the audited dependency closure), so
//!   there is no SIGINT handler; `crawl` is a batch job that persists when the
//!   frontier drains, and `search` is read-only.
//! * `backup`'s default destination timestamp is UTC (Python uses local time),
//!   and an existing destination is refused rather than overwritten.
#![forbid(unsafe_code)]

use std::collections::{HashMap, HashSet};
use std::io::ErrorKind;
use std::net::{SocketAddr, ToSocketAddrs};
use std::path::Path;
use std::process::ExitCode;
use std::sync::{Arc, Mutex};
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use onioncrawler::abuse::{load_abuse_filter, AbuseFilter};
use onioncrawler::canonical::canonicalize;
use onioncrawler::crawler::{CrawlConfig, Crawler};
use onioncrawler::fetcher::Fetcher;
use onioncrawler::serve::{json_str, serve, RateLimits, SearchServer, ServeConfig};
use onioncrawler::store::{Caps, Enqueued, Reseed, Store};
use onioncrawler::submit::submit_many;

const PROG: &str = "onioncrawler";

// The five knobs the Python CLI declares with `default=None` and therefore
// inherits from its `Config` dataclass. They are repeated here (rather than
// taken from `CrawlConfig::default()`) so a command line ported verbatim from
// the Python deployment keeps the same budgets.
const DEFAULT_WORKERS: usize = 4;
const DEFAULT_CRAWL_DELAY: f64 = 3.0;
const DEFAULT_MAX_DEPTH: i64 = 8;
const DEFAULT_MAX_PAGES_PER_HOST: i64 = 500;
const DEFAULT_MAX_TOTAL_PAGES: usize = 10_000;
/// Python `Config.recrawl_ttl` — the per-page recrawl interval the `recrawl`
/// subcommand falls back to (7 days).
const DEFAULT_RECRAWL_TTL: f64 = 7.0 * 24.0 * 3600.0;
/// Scan window for the O(n²) SimHash clustering pass (`cluster --max-pages`).
const DEFAULT_CLUSTER_MAX_PAGES: usize = 20_000;

// ---------------------------------------------------------------------------
// Parsed command surface
// ---------------------------------------------------------------------------

/// A fully parsed subcommand invocation.
#[derive(Debug, PartialEq)]
enum Command {
    Crawl(CrawlArgs),
    Search(SearchArgs),
    Stats(StatsArgs),
    Submit(SubmitArgs),
    Reseed(ReseedArgs),
    Backup(BackupArgs),
    Authority(AuthorityArgs),
    Cluster(ClusterArgs),
    Recrawl(RecrawlArgs),
}

/// The `--blocklist-*` paths shared by `crawl`, `search`, `submit` and `reseed`.
/// An empty path means "no list" (the Python `--blocklist-host-md5` default).
#[derive(Clone, Debug, PartialEq)]
struct Blocklists {
    hosts: String,
    keywords: String,
    media: String,
    host_md5: String,
}

impl Default for Blocklists {
    fn default() -> Self {
        Blocklists {
            hosts: "blocklist_hosts.txt".to_string(),
            keywords: "blocklist_keywords.txt".to_string(),
            media: "blocklist_media.txt".to_string(),
            host_md5: String::new(),
        }
    }
}

/// `crawl` — mirrors the Python `crawl` subparser.
#[derive(Debug, PartialEq)]
struct CrawlArgs {
    db: String,
    seeds: Option<String>,
    seed: Vec<String>,
    fetcher: String,
    tor_host: String,
    tor_port: u16,
    direct_map: Vec<String>,
    submission_ttl: f64,
    enable_i2p: bool,
    allow_v2: bool,
    workers: usize,
    crawl_delay: f64,
    max_depth: i64,
    max_pages_per_host: i64,
    max_total_pages: usize,
    no_robots: bool,
    blocklists: Blocklists,
    verbose: bool,
}

/// `search` — the no-JS search UI + JSON API.
#[derive(Debug, PartialEq)]
struct SearchArgs {
    db: String,
    host: String,
    port: u16,
    blocklists: Blocklists,
    enable_i2p: bool,
    admin_token: Option<String>,
    allow_public_submit: bool,
    /// The OpenSearch descriptor's base URL. Python derives it internally; the
    /// Rust [`SearchServer`] takes it explicitly.
    base_url: Option<String>,
    /// Sustained GETs/s and burst per client (`ServeConfig::rate_limits`).
    read_rate: f64,
    read_burst: f64,
    /// Sustained POSTs/s and burst per client. A write also costs a full
    /// snapshot fsync, so this is the tighter of the two.
    write_rate: f64,
    write_burst: f64,
}

/// `stats` — frontier / pages / host statistics.
#[derive(Debug, PartialEq)]
struct StatsArgs {
    db: String,
    json: bool,
}

/// `submit` — validate + enqueue seed darknet URL(s).
#[derive(Debug, PartialEq)]
struct SubmitArgs {
    db: String,
    urls: Vec<String>,
    file: Option<String>,
    allow_v2: bool,
    enable_i2p: bool,
    blocklists: Blocklists,
}

/// `reseed` (alias `seeds`) — import a curated seed list, re-enqueue the roots.
#[derive(Debug, PartialEq)]
struct ReseedArgs {
    db: String,
    seed_list: Option<String>,
    seed: Vec<String>,
    allow_v2: bool,
    enable_i2p: bool,
    blocklists: Blocklists,
}

/// `backup` — write a standalone snapshot copy.
#[derive(Debug, PartialEq)]
struct BackupArgs {
    db: String,
    out: Option<String>,
}

/// `authority` — offline PageRank-lite host authority.
#[derive(Debug, PartialEq)]
struct AuthorityArgs {
    db: String,
    iterations: usize,
    damping: f64,
}

/// `cluster` — cluster near-duplicate mirror pages.
#[derive(Debug, PartialEq)]
struct ClusterArgs {
    db: String,
    threshold: u32,
    max_pages: usize,
}

/// `recrawl` — mark due pages for recrawl now.
#[derive(Debug, PartialEq)]
struct RecrawlArgs {
    db: String,
    recrawl_ttl: f64,
}

/// A parse outcome that is not a runnable command: either text to print on
/// stdout and exit 0 (`--help`, `--version`), or a usage error to print on
/// stderr and exit 2 — mirroring how Python's `argparse` handles the two.
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
    // Only `crawl` and `search` touch the network; everything else is offline
    // maintenance that needs no async runtime.
    let (crawl_or_search, offline) = match cmd {
        Command::Crawl(_) | Command::Search(_) => (Some(cmd), None),
        other => (None, Some(other)),
    };
    if let Some(cmd) = offline {
        return run_offline(cmd);
    }
    let rt = match tokio::runtime::Runtime::new() {
        Ok(rt) => rt,
        Err(e) => {
            eprintln!("error: failed to start async runtime: {e}");
            return ExitCode::from(1);
        }
    };
    match crawl_or_search {
        Some(Command::Crawl(a)) => rt.block_on(run_crawl(a)),
        Some(Command::Search(a)) => rt.block_on(run_search(a)),
        _ => unreachable!("only crawl/search reach the runtime"),
    }
}

/// Dispatch the subcommands that need no async runtime.
fn run_offline(cmd: Command) -> ExitCode {
    match cmd {
        Command::Stats(a) => run_stats(&a),
        Command::Submit(a) => run_submit(&a),
        Command::Reseed(a) => run_reseed(&a),
        Command::Backup(a) => run_backup(&a),
        Command::Authority(a) => run_authority(&a),
        Command::Cluster(a) => run_cluster(&a),
        Command::Recrawl(a) => run_recrawl(&a),
        Command::Crawl(_) | Command::Search(_) => {
            unreachable!("crawl/search are dispatched on the runtime")
        }
    }
}

// ---------------------------------------------------------------------------
// Argument parsing (hand-rolled, dependency-free)
// ---------------------------------------------------------------------------

/// Split any `--flag=value` token into two (`--flag`, `value`) so the per-command
/// walkers only ever see `--flag [value]`. Positional args (submit URLs) never
/// start with `--`, so they pass through untouched.
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

fn parse_i64(s: &str, flag: &str) -> Result<i64, CliError> {
    s.parse::<i64>()
        .map_err(|_| CliError::Usage(format!("error: {flag} expects an integer, got {s:?}")))
}

fn parse_usize(s: &str, flag: &str) -> Result<usize, CliError> {
    s.parse::<usize>().map_err(|_| {
        CliError::Usage(format!(
            "error: {flag} expects a non-negative integer, got {s:?}"
        ))
    })
}

fn parse_u32(s: &str, flag: &str) -> Result<u32, CliError> {
    s.parse::<u32>().map_err(|_| {
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

fn unknown_option(opt: &str, sub: &str) -> CliError {
    CliError::Usage(format!(
        "error: unrecognized option {opt} for `{sub}` (try `{PROG} {sub} --help`)"
    ))
}

fn unexpected_arg(arg: &str, sub: &str) -> CliError {
    CliError::Usage(format!("error: unexpected argument {arg:?} for `{sub}`"))
}

/// Consume a shared `--blocklist-*` flag at `toks[*i]`, if that is what it is.
/// `allow_md5` mirrors Python, where only `crawl` and `search` take the Ahmia
/// `md5(domain)` banlist.
fn take_blocklist(
    toks: &[String],
    i: &mut usize,
    b: &mut Blocklists,
    allow_md5: bool,
) -> Result<bool, CliError> {
    match toks[*i].as_str() {
        "--blocklist-hosts" => {
            *i += 1;
            b.hosts = need(toks, *i, "--blocklist-hosts")?.to_string();
        }
        "--blocklist-keywords" => {
            *i += 1;
            b.keywords = need(toks, *i, "--blocklist-keywords")?.to_string();
        }
        "--blocklist-media" => {
            *i += 1;
            b.media = need(toks, *i, "--blocklist-media")?.to_string();
        }
        "--blocklist-host-md5" if allow_md5 => {
            *i += 1;
            b.host_md5 = need(toks, *i, "--blocklist-host-md5")?.to_string();
        }
        _ => return Ok(false),
    }
    Ok(true)
}

/// Top-level dispatch: pick the subcommand, then hand its arguments to the
/// matching walker. Mirrors argparse's required-subcommand behaviour (no
/// subcommand → usage error, exit 2).
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
        "crawl" => parse_crawl(rest).map(Command::Crawl),
        "search" => parse_search(rest).map(Command::Search),
        "stats" => parse_stats(rest).map(Command::Stats),
        "submit" => parse_submit(rest).map(Command::Submit),
        // `seeds` is the Python alias for `reseed`.
        "reseed" | "seeds" => parse_reseed(rest).map(Command::Reseed),
        "backup" => parse_backup(rest).map(Command::Backup),
        "authority" => parse_authority(rest).map(Command::Authority),
        "cluster" => parse_cluster(rest).map(Command::Cluster),
        "recrawl" => parse_recrawl(rest).map(Command::Recrawl),
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

fn parse_crawl(toks: &[String]) -> Result<CrawlArgs, CliError> {
    let mut a = CrawlArgs {
        db: "crawl.db".to_string(),
        seeds: None,
        seed: Vec::new(),
        fetcher: "tor".to_string(),
        tor_host: "127.0.0.1".to_string(),
        tor_port: 9050,
        direct_map: Vec::new(),
        submission_ttl: 0.0,
        enable_i2p: false,
        allow_v2: false,
        workers: DEFAULT_WORKERS,
        crawl_delay: DEFAULT_CRAWL_DELAY,
        max_depth: DEFAULT_MAX_DEPTH,
        max_pages_per_host: DEFAULT_MAX_PAGES_PER_HOST,
        max_total_pages: DEFAULT_MAX_TOTAL_PAGES,
        no_robots: false,
        blocklists: Blocklists::default(),
        verbose: false,
    };
    let mut i = 0;
    while i < toks.len() {
        if take_blocklist(toks, &mut i, &mut a.blocklists, true)? {
            i += 1;
            continue;
        }
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(crawl_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--seeds" => {
                i += 1;
                a.seeds = Some(need(toks, i, "--seeds")?.to_string());
            }
            "--seed" => {
                i += 1;
                a.seed.push(need(toks, i, "--seed")?.to_string());
            }
            "--fetcher" => {
                i += 1;
                let v = need(toks, i, "--fetcher")?;
                if v == "i2p" {
                    return Err(CliError::Usage(
                        "error: --fetcher i2p is not ported: the Rust fetcher has a Tor SOCKS5 \
                         and a loopback test transport only (use --enable-i2p to admit .i2p \
                         hosts to the frontier)"
                            .to_string(),
                    ));
                }
                if v != "tor" && v != "direct" {
                    return Err(CliError::Usage(format!(
                        "error: --fetcher expects one of tor, direct; got {v:?}"
                    )));
                }
                a.fetcher = v.to_string();
            }
            "--tor-host" => {
                i += 1;
                a.tor_host = need(toks, i, "--tor-host")?.to_string();
            }
            "--tor-port" => {
                i += 1;
                a.tor_port = parse_u16(need(toks, i, "--tor-port")?, "--tor-port")?;
            }
            "--direct-map" => {
                i += 1;
                a.direct_map
                    .push(need(toks, i, "--direct-map")?.to_string());
            }
            "--submission-ttl" => {
                i += 1;
                a.submission_ttl =
                    parse_f64(need(toks, i, "--submission-ttl")?, "--submission-ttl")?;
            }
            "--enable-i2p" => a.enable_i2p = true,
            "--allow-v2" => a.allow_v2 = true,
            "--workers" => {
                i += 1;
                a.workers = parse_usize(need(toks, i, "--workers")?, "--workers")?;
            }
            "--crawl-delay" => {
                i += 1;
                a.crawl_delay = parse_f64(need(toks, i, "--crawl-delay")?, "--crawl-delay")?;
            }
            "--max-depth" => {
                i += 1;
                a.max_depth = parse_i64(need(toks, i, "--max-depth")?, "--max-depth")?;
            }
            "--max-pages-per-host" => {
                i += 1;
                a.max_pages_per_host = parse_i64(
                    need(toks, i, "--max-pages-per-host")?,
                    "--max-pages-per-host",
                )?;
            }
            "--max-total-pages" => {
                i += 1;
                a.max_total_pages =
                    parse_usize(need(toks, i, "--max-total-pages")?, "--max-total-pages")?;
            }
            "--no-robots" => a.no_robots = true,
            "-v" | "--verbose" => a.verbose = true,
            s if s.starts_with('-') => return Err(unknown_option(s, "crawl")),
            other => return Err(unexpected_arg(other, "crawl")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_search(toks: &[String]) -> Result<SearchArgs, CliError> {
    let mut a = SearchArgs {
        db: "crawl.db".to_string(),
        host: "127.0.0.1".to_string(),
        port: 8802,
        blocklists: Blocklists::default(),
        enable_i2p: false,
        admin_token: None,
        allow_public_submit: false,
        base_url: None,
        read_rate: RateLimits::default().read_rate,
        read_burst: RateLimits::default().read_burst,
        write_rate: RateLimits::default().write_rate,
        write_burst: RateLimits::default().write_burst,
    };
    let mut i = 0;
    while i < toks.len() {
        if take_blocklist(toks, &mut i, &mut a.blocklists, true)? {
            i += 1;
            continue;
        }
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(search_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--host" => {
                i += 1;
                a.host = need(toks, i, "--host")?.to_string();
            }
            "--port" => {
                i += 1;
                a.port = parse_u16(need(toks, i, "--port")?, "--port")?;
            }
            "--enable-i2p" => a.enable_i2p = true,
            "--admin-token" => {
                i += 1;
                a.admin_token = Some(need(toks, i, "--admin-token")?.to_string());
            }
            "--allow-public-submit" => a.allow_public_submit = true,
            "--base-url" => {
                i += 1;
                a.base_url = Some(need(toks, i, "--base-url")?.to_string());
            }
            "--read-rate" => {
                i += 1;
                a.read_rate = parse_f64(need(toks, i, "--read-rate")?, "--read-rate")?;
            }
            "--read-burst" => {
                i += 1;
                a.read_burst = parse_f64(need(toks, i, "--read-burst")?, "--read-burst")?;
            }
            "--write-rate" => {
                i += 1;
                a.write_rate = parse_f64(need(toks, i, "--write-rate")?, "--write-rate")?;
            }
            "--write-burst" => {
                i += 1;
                a.write_burst = parse_f64(need(toks, i, "--write-burst")?, "--write-burst")?;
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "search")),
            other => return Err(unexpected_arg(other, "search")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_stats(toks: &[String]) -> Result<StatsArgs, CliError> {
    let mut a = StatsArgs {
        db: "crawl.db".to_string(),
        json: false,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(stats_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--json" => a.json = true,
            s if s.starts_with('-') => return Err(unknown_option(s, "stats")),
            other => return Err(unexpected_arg(other, "stats")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_submit(toks: &[String]) -> Result<SubmitArgs, CliError> {
    let mut a = SubmitArgs {
        db: "crawl.db".to_string(),
        urls: Vec::new(),
        file: None,
        allow_v2: false,
        enable_i2p: false,
        blocklists: Blocklists::default(),
    };
    let mut i = 0;
    while i < toks.len() {
        if take_blocklist(toks, &mut i, &mut a.blocklists, false)? {
            i += 1;
            continue;
        }
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(submit_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--file" => {
                i += 1;
                a.file = Some(need(toks, i, "--file")?.to_string());
            }
            "--allow-v2" => a.allow_v2 = true,
            "--enable-i2p" => a.enable_i2p = true,
            s if s.starts_with("--") => return Err(unknown_option(s, "submit")),
            _ => a.urls.push(toks[i].clone()),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_reseed(toks: &[String]) -> Result<ReseedArgs, CliError> {
    let mut a = ReseedArgs {
        db: "crawl.db".to_string(),
        seed_list: None,
        seed: Vec::new(),
        allow_v2: false,
        enable_i2p: false,
        blocklists: Blocklists::default(),
    };
    let mut i = 0;
    while i < toks.len() {
        if take_blocklist(toks, &mut i, &mut a.blocklists, false)? {
            i += 1;
            continue;
        }
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(reseed_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--seed-list" => {
                i += 1;
                a.seed_list = Some(need(toks, i, "--seed-list")?.to_string());
            }
            "--seed" => {
                i += 1;
                a.seed.push(need(toks, i, "--seed")?.to_string());
            }
            "--allow-v2" => a.allow_v2 = true,
            "--enable-i2p" => a.enable_i2p = true,
            s if s.starts_with('-') => return Err(unknown_option(s, "reseed")),
            other => return Err(unexpected_arg(other, "reseed")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_backup(toks: &[String]) -> Result<BackupArgs, CliError> {
    let mut a = BackupArgs {
        db: "crawl.db".to_string(),
        out: None,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(backup_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--out" => {
                i += 1;
                a.out = Some(need(toks, i, "--out")?.to_string());
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "backup")),
            other => return Err(unexpected_arg(other, "backup")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_authority(toks: &[String]) -> Result<AuthorityArgs, CliError> {
    let mut a = AuthorityArgs {
        db: "crawl.db".to_string(),
        iterations: 20,
        damping: 0.85,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(authority_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--iterations" => {
                i += 1;
                a.iterations = parse_usize(need(toks, i, "--iterations")?, "--iterations")?;
            }
            "--damping" => {
                i += 1;
                a.damping = parse_f64(need(toks, i, "--damping")?, "--damping")?;
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "authority")),
            other => return Err(unexpected_arg(other, "authority")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_cluster(toks: &[String]) -> Result<ClusterArgs, CliError> {
    let mut a = ClusterArgs {
        db: "crawl.db".to_string(),
        threshold: 3,
        max_pages: DEFAULT_CLUSTER_MAX_PAGES,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(cluster_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--threshold" => {
                i += 1;
                a.threshold = parse_u32(need(toks, i, "--threshold")?, "--threshold")?;
            }
            "--max-pages" => {
                i += 1;
                a.max_pages = parse_usize(need(toks, i, "--max-pages")?, "--max-pages")?;
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "cluster")),
            other => return Err(unexpected_arg(other, "cluster")),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_recrawl(toks: &[String]) -> Result<RecrawlArgs, CliError> {
    let mut a = RecrawlArgs {
        db: "crawl.db".to_string(),
        recrawl_ttl: DEFAULT_RECRAWL_TTL,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(recrawl_help())),
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--recrawl-ttl" => {
                i += 1;
                a.recrawl_ttl = parse_f64(need(toks, i, "--recrawl-ttl")?, "--recrawl-ttl")?;
            }
            s if s.starts_with('-') => return Err(unknown_option(s, "recrawl")),
            other => return Err(unexpected_arg(other, "recrawl")),
        }
        i += 1;
    }
    Ok(a)
}

// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------

/// Epoch seconds (Python `time.time()`).
fn now_secs() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

// Bounds so a huge or malicious seed file cannot exhaust memory / CPU — the
// Python `seedlist._MAX_SEED_LINE_BYTES` / `_MAX_SEEDS` / `_MAX_SEED_LINES`.
// The file is streamed line by line (never slurped): each read is length-capped
// (so a gigabyte with no newline cannot be buffered as one line), the number of
// accepted roots is capped, and the total lines scanned is capped (so an
// all-junk file cannot spin forever).
/// Longest single seed line we will buffer — Python's `readline(size)` argument.
const MAX_SEED_LINE_BYTES: usize = 4096;
/// Most seeds we will accept from one file.
const MAX_SEEDS: usize = 100_000;
/// Most lines we will scan looking for those seeds.
const MAX_SEED_LINES: usize = 5_000_000;

/// One `readline(cap)`: read at most `cap` bytes into `out`, stopping after the
/// first `\n` (which is kept, as Python does). Returns the number of bytes read;
/// zero means EOF. A line longer than `cap` is delivered in `cap`-sized pieces,
/// exactly like the reference — each piece then simply fails validation.
fn read_capped_line<R: std::io::BufRead>(
    r: &mut R,
    cap: usize,
    out: &mut Vec<u8>,
) -> std::io::Result<usize> {
    out.clear();
    while out.len() < cap {
        let available = match r.fill_buf() {
            Ok(b) => b,
            Err(ref e) if e.kind() == ErrorKind::Interrupted => continue,
            Err(e) => return Err(e),
        };
        if available.is_empty() {
            break; // EOF
        }
        let room = cap - out.len();
        match available.iter().position(|&b| b == b'\n') {
            Some(i) if i < room => {
                out.extend_from_slice(&available[..=i]);
                r.consume(i + 1);
                break;
            }
            _ => {
                let n = room.min(available.len());
                out.extend_from_slice(&available[..n]);
                r.consume(n);
            }
        }
    }
    Ok(out.len())
}

/// Stream the comment-stripped, trimmed, non-blank lines of `reader` under the
/// line-length and lines-scanned bounds, handing each to `accept` — the read
/// loop of the Python `seedlist.load_seed_list`. `accept` returns `false` to
/// stop early, which is how the accepted-seed cap is applied.
fn for_each_seed_line<R: std::io::BufRead>(
    mut reader: R,
    max_lines: usize,
    max_line_bytes: usize,
    mut accept: impl FnMut(&str) -> bool,
) {
    let mut raw = Vec::with_capacity(max_line_bytes.min(4096));
    let mut scanned = 0usize;
    while scanned < max_lines {
        match read_capped_line(&mut reader, max_line_bytes, &mut raw) {
            Ok(0) | Err(_) => break,
            Ok(_) => {}
        }
        scanned += 1;
        let line = String::from_utf8_lossy(&raw);
        let line = line.split('#').next().unwrap_or("").trim();
        if !line.is_empty() && !accept(line) {
            break;
        }
    }
}

/// The comment-stripped, trimmed, non-blank seed lines of a stream, bounded on
/// every axis (line length, lines scanned, seeds accepted).
fn seed_lines_from<R: std::io::BufRead>(
    reader: R,
    max_seeds: usize,
    max_lines: usize,
    max_line_bytes: usize,
) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    if max_seeds == 0 {
        return out;
    }
    for_each_seed_line(reader, max_lines, max_line_bytes, |line| {
        out.push(line.to_string());
        out.len() < max_seeds
    });
    out
}

/// The full `_read_seeds`: the seed URLs from `path` (if given) plus `extra`.
/// The file is streamed under the seed-list bounds, never slurped.
fn read_seeds(path: Option<&str>, extra: &[String]) -> Result<Vec<String>, String> {
    match path {
        None => Ok(extra.to_vec()),
        Some(p) => {
            let fh = std::fs::File::open(p)
                .map_err(|e| format!("error: cannot read seed file {p}: {e}"))?;
            let mut seeds = seed_lines_from(
                std::io::BufReader::new(fh),
                MAX_SEEDS,
                MAX_SEED_LINES,
                MAX_SEED_LINE_BYTES,
            );
            seeds.extend(extra.iter().cloned());
            Ok(seeds)
        }
    }
}

/// The Python `seedlist.load_seed_list`: the curated seed file read under the
/// same bounds as [`read_seeds`], then canonicalized (darknet-only, so no
/// clearnet line can leak) and deduped by canonical URL, order-preserving. A
/// line that is not a valid darknet URL is dropped silently.
fn load_seed_list(path: &str, allow_v2: bool, allow_i2p: bool) -> Result<Vec<String>, String> {
    let fh = std::fs::File::open(path)
        .map_err(|e| format!("error: cannot read seed file {path}: {e}"))?;
    let mut out: Vec<String> = Vec::new();
    let mut seen: HashSet<String> = HashSet::new();
    for_each_seed_line(
        std::io::BufReader::new(fh),
        MAX_SEED_LINES,
        MAX_SEED_LINE_BYTES,
        |line| {
            if let Some(cu) = canon_seed(line, allow_v2, allow_i2p) {
                if seen.insert(cu.clone()) {
                    out.push(cu);
                }
            }
            out.len() < MAX_SEEDS
        },
    );
    Ok(out)
}

/// The Python `seedlist._canon`: canonicalize a seed line, defaulting the scheme
/// for a bare host. Returns the canonical URL string.
fn canon_seed(line: &str, allow_v2: bool, allow_i2p: bool) -> Option<String> {
    let s = line.trim();
    if s.is_empty() || s.starts_with('#') {
        return None;
    }
    canonicalize(s, None, allow_v2, allow_i2p)
        .or_else(|| {
            if s.contains("://") {
                None
            } else {
                canonicalize(&format!("http://{s}"), None, allow_v2, allow_i2p)
            }
        })
        .map(|cu| cu.url)
}

/// `host.onion=127.0.0.1:8080` → `(host, (ip, port))` for the loopback test
/// transport (the Python `direct_map` entries).
fn parse_direct_map(entries: &[String]) -> Result<HashMap<String, (String, u16)>, String> {
    let mut out = HashMap::new();
    for e in entries {
        let (host, addr) = e
            .split_once('=')
            .ok_or_else(|| format!("error: --direct-map expects HOST=IP:PORT, got {e:?}"))?;
        let (ip, port) = addr
            .rsplit_once(':')
            .ok_or_else(|| format!("error: --direct-map expects HOST=IP:PORT, got {e:?}"))?;
        let port: u16 = port
            .trim()
            .parse()
            .map_err(|_| format!("error: --direct-map port must be 0..=65535, got {e:?}"))?;
        out.insert(host.trim().to_string(), (ip.trim().to_string(), port));
    }
    Ok(out)
}

/// An [`AbuseFilter`] from the configured paths (an empty path = no list).
fn build_abuse(b: &Blocklists) -> AbuseFilter {
    let opt = |s: &str| {
        if s.is_empty() {
            None
        } else {
            Some(s.to_string())
        }
    };
    load_abuse_filter(
        opt(&b.hosts).as_deref(),
        opt(&b.keywords).as_deref(),
        opt(&b.media).as_deref(),
        opt(&b.host_md5).as_deref(),
    )
}

/// True when no blocklist at all is configured — the Python `cmd_crawl` warning
/// condition (operators of a public .onion index MUST filter abuse).
fn abuse_is_empty(a: &AbuseFilter) -> bool {
    a.hosts().is_empty() && a.keywords().is_empty() && a.media_hashes().is_empty()
}

/// Build the [`CrawlConfig`] from the parsed flags — the port of the Python
/// `_config_from_args` for the knobs the Rust crawl loop honours. Everything not
/// exposed as a flag keeps the library's `CrawlConfig::default()` baseline.
fn build_config(a: &CrawlArgs) -> CrawlConfig {
    CrawlConfig {
        max_depth: a.max_depth,
        // 0 (or less) means "unbounded", matching the Python cap semantics.
        max_total_pages: (a.max_total_pages > 0).then_some(a.max_total_pages),
        max_pages_per_host: (a.max_pages_per_host > 0).then_some(a.max_pages_per_host),
        allow_v2: a.allow_v2,
        allow_i2p: a.enable_i2p,
        obey_robots: !a.no_robots,
        crawl_delay: a.crawl_delay.max(0.0),
        workers: a.workers.max(1),
        ..CrawlConfig::default()
    }
}

/// Build the darknet fetcher for the selected transport.
fn build_fetcher(a: &CrawlArgs) -> Result<Fetcher, String> {
    let mut f = if a.fetcher == "direct" {
        let map = parse_direct_map(&a.direct_map)?;
        if map.is_empty() {
            return Err(
                "error: --fetcher direct needs at least one --direct-map HOST=IP:PORT".to_string(),
            );
        }
        Fetcher::direct(map)
    } else {
        Fetcher::tor(&a.tor_host, a.tor_port)
    };
    f.allow_v2 = a.allow_v2;
    Ok(f)
}

/// Resolve `host:port` to a bind address (a hostname goes through the system
/// resolver, as Python's `bind` does).
fn bind_addr(host: &str, port: u16) -> Result<SocketAddr, String> {
    (host, port)
        .to_socket_addrs()
        .map_err(|e| format!("error: cannot resolve {host}:{port}: {e}"))?
        .next()
        .ok_or_else(|| format!("error: {host}:{port} resolved to no address"))
}

/// Load a store from a snapshot file: a missing file yields a fresh empty store
/// (so a first `crawl`/`submit` just creates it); a present-but-corrupt file is
/// a hard error.
fn read_store(db: &str) -> Result<Store, String> {
    match std::fs::read(db) {
        Ok(bytes) => Store::restore(&bytes).ok_or_else(|| {
            format!("error: {db} is not a valid onioncrawler snapshot (corrupt, truncated, or a newer format version)")
        }),
        Err(e) if e.kind() == ErrorKind::NotFound => Ok(Store::new()),
        Err(e) => Err(format!("error: cannot read {db}: {e}")),
    }
}

/// Persist a store snapshot to `db`.
fn write_store(store: &Store, db: &str) -> Result<usize, String> {
    let blob = store.snapshot();
    // Published by rename, not truncate-then-write: a crash or a full disk
    // partway through `fs::write` left a truncated blob, `Store::restore`
    // correctly refused it, and the previous good index was already gone.
    crawlcore::atomicfile::write_atomic(db, &blob)
        .map_err(|e| format!("error: cannot write store to {db}: {e}"))?;
    Ok(blob.len())
}

/// A day count since the Unix epoch → `(year, month, day)` (Howard Hinnant's
/// `civil_from_days`).
fn civil_from_days(z: i64) -> (i64, i64, i64) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { y + 1 } else { y }, m, d)
}

/// Epoch seconds → `YYYYmmdd-HHMMSS` (UTC) — the default `backup --out` suffix
/// (Python formats the same shape in local time).
fn timestamp(ts: f64) -> String {
    let secs = ts.max(0.0) as i64;
    let (y, mo, d) = civil_from_days(secs.div_euclid(86_400));
    let tod = secs.rem_euclid(86_400);
    format!(
        "{y:04}{mo:02}{d:02}-{:02}{:02}{:02}",
        tod / 3600,
        (tod % 3600) / 60,
        tod % 60
    )
}

/// The Python `format_stats` report over a store.
fn stats_report(store: &Store) -> String {
    let m = store.metrics();
    let g = |k: &str| *m.get(k).unwrap_or(&0);
    let hs = store.hosts_by_state();
    let mut states: Vec<(&String, &usize)> = hs.iter().collect();
    states.sort();
    let states = states
        .iter()
        .map(|(k, v)| format!("{k}: {v}"))
        .collect::<Vec<_>>()
        .join(", ");
    let fs = store.frontier_by_status();
    let mut lines = vec![
        "== onioncrawler stats ==".to_string(),
        format!("pages indexed      : {}", g("pages")),
        format!("pages stored (ctr) : {}", g("pages_stored")),
        format!("urls enqueued      : {}", g("urls_enqueued")),
        format!("duplicates skipped : {}", g("duplicates")),
        format!("fetch errors       : {}", g("errors")),
        format!("hosts              : {}  {{{states}}}", g("hosts")),
        "frontier:".to_string(),
    ];
    for status in ["queued", "leased", "done", "error"] {
        lines.push(format!(
            "  {status:8}: {}",
            fs.get(status).copied().unwrap_or(0)
        ));
    }
    let trapped = store.trapped_hosts();
    if !trapped.is_empty() {
        lines.push("trapped/blocked hosts:".to_string());
        for (host, reason) in &trapped {
            let short: String = host.chars().take(24).collect();
            lines.push(format!("  {short}… : {reason}"));
        }
    }
    lines.join("\n")
}

/// The Python `stats_json` payload (stable key order, hand-rolled JSON).
fn stats_json(store: &Store) -> String {
    let m = store.metrics();
    let mut keys: Vec<&&'static str> = m.keys().collect();
    keys.sort();
    let mut out = String::from("{\n");
    for k in keys {
        out.push_str(&format!("  \"{}\": {},\n", json_str(k), m[*k]));
    }
    let obj = |map: &HashMap<String, usize>| {
        let mut entries: Vec<(&String, &usize)> = map.iter().collect();
        entries.sort();
        entries
            .iter()
            .map(|(k, v)| format!("\"{}\": {v}", json_str(k)))
            .collect::<Vec<_>>()
            .join(", ")
    };
    out.push_str(&format!(
        "  \"frontier_by_status\": {{{}}},\n",
        obj(&store.frontier_by_status())
    ));
    out.push_str(&format!(
        "  \"hosts_by_state\": {{{}}},\n",
        obj(&store.hosts_by_state())
    ));
    let trapped = store
        .trapped_hosts()
        .iter()
        .map(|(h, r)| {
            format!(
                "{{\"host\": \"{}\", \"trapped_reason\": \"{}\"}}",
                json_str(h),
                json_str(r)
            )
        })
        .collect::<Vec<_>>()
        .join(", ");
    out.push_str(&format!("  \"trapped_hosts\": [{trapped}]\n}}"));
    out
}

// ---------------------------------------------------------------------------
// Command wiring
// ---------------------------------------------------------------------------

/// `crawl` — the port of the Python `cmd_crawl`.
async fn run_crawl(a: CrawlArgs) -> ExitCode {
    let mut store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    if a.submission_ttl > 0.0 {
        let reaped = store.reap_unverified(a.submission_ttl, now_secs());
        if reaped > 0 {
            println!("[crawl] expired {reaped} unverified queued seed(s) past TTL");
        }
    }
    let abuse = build_abuse(&a.blocklists);
    if abuse_is_empty(&abuse) {
        eprintln!(
            "WARNING: abuse blocklists are empty. Operators of any legitimate \
             .onion index MUST configure abuse filtering (see README)."
        );
    }
    let fetcher = match build_fetcher(&a) {
        Ok(f) => f,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(2);
        }
    };
    let seeds = match read_seeds(a.seeds.as_deref(), &a.seed) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let cfg = build_config(&a);
    if a.verbose {
        eprintln!("[crawl] config: {cfg:?}");
        eprintln!("[crawl] fetcher: {} seeds: {}", a.fetcher, seeds.len());
    }

    let store = Arc::new(Mutex::new(store));
    let crawler = Crawler::new(store.clone(), Arc::new(fetcher), cfg).with_abuse(Arc::new(abuse));
    let added = crawler.add_seeds(seeds);
    println!("[crawl] seeded {added} url(s); db={}", a.db);

    let t0 = Instant::now();
    let stats = crawler.run().await;
    let dt = t0.elapsed().as_secs_f64();

    let guard = store
        .lock()
        .unwrap_or_else(std::sync::PoisonError::into_inner);
    if let Err(msg) = write_store(&guard, &a.db) {
        eprintln!("{msg}");
        return ExitCode::from(1);
    }
    println!(
        "[crawl] finished in {dt:.1}s: {} pages, {} urls enqueued, {} trapped/blocked host(s)",
        stats.pages,
        stats.urls_enqueued,
        guard.trapped_hosts().len()
    );
    ExitCode::from(0)
}

/// `search` — the port of the Python `cmd_search`.
async fn run_search(a: SearchArgs) -> ExitCode {
    if !Path::new(&a.db).exists() {
        eprintln!("note: {} does not exist; serving an empty index", a.db);
    }
    let store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let pages = store.counter("pages_stored");
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
    let server = SearchServer::new(Arc::new(Mutex::new(store)), base.as_str())
        .with_config(ServeConfig {
            admin_token: admin_token.clone(),
            allow_public_submit: a.allow_public_submit,
            allow_i2p: a.enable_i2p,
            // The write endpoints commit here. Without it `/purge` would edit
            // only this process's memory and the next `search --db` would serve
            // every purged page again, un-blocked.
            store_path: Some(a.db.clone()),
            rate_limits: RateLimits {
                read_rate: a.read_rate,
                read_burst: a.read_burst,
                write_rate: a.write_rate,
                write_burst: a.write_burst,
                ..RateLimits::default()
            },
            ..ServeConfig::default()
        })
        .with_abuse(Arc::new(build_abuse(&a.blocklists)));

    let listener = match tokio::net::TcpListener::bind(addr).await {
        Ok(l) => l,
        Err(e) => {
            eprintln!("error: cannot bind {addr}: {e}");
            return ExitCode::from(1);
        }
    };
    println!(
        "[search] serving {pages} pages at {base}/ (admin auth: {}; public submit: {}; \
Ctrl-C to stop)",
        if admin_token.is_empty() { "off" } else { "on" },
        if a.allow_public_submit { "on" } else { "off" },
    );
    match serve(listener, server).await {
        Ok(()) => ExitCode::from(0),
        Err(e) => {
            eprintln!("error: server stopped: {e}");
            ExitCode::from(1)
        }
    }
}

/// `stats` — the port of the Python `cmd_stats`.
fn run_stats(a: &StatsArgs) -> ExitCode {
    if !Path::new(&a.db).exists() {
        eprintln!("note: {} does not exist; reporting an empty index", a.db);
    }
    let store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    println!(
        "{}",
        if a.json {
            stats_json(&store)
        } else {
            stats_report(&store)
        }
    );
    ExitCode::from(0)
}

/// `submit` — the port of the Python `cmd_submit`.
fn run_submit(a: &SubmitArgs) -> ExitCode {
    let mut urls = a.urls.clone();
    match read_seeds(a.file.as_deref(), &[]) {
        Ok(from_file) => urls.extend(from_file),
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    }
    if urls.is_empty() {
        eprintln!("submit: provide URL(s) or --file");
        return ExitCode::from(2);
    }
    let mut store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let abuse = build_abuse(&a.blocklists);
    let res = submit_many(
        &mut store,
        Some(&abuse),
        urls,
        a.allow_v2,
        None,
        None,
        a.enable_i2p,
        now_secs(),
    );
    if let Err(msg) = write_store(&store, &a.db) {
        eprintln!("{msg}");
        return ExitCode::from(1);
    }
    println!(
        "[submit] ok={} dup={} blocked={} not-onion={}",
        res.ok, res.dup, res.blocked, res.not_onion
    );
    ExitCode::from(0)
}

/// `reseed` — the port of the Python `cmd_reseed` (`seedlist.reseed`): revive +
/// re-enqueue curated roots, bypassing the trap caps (`force`), refusing hosts
/// on the abuse blocklist.
fn run_reseed(a: &ReseedArgs) -> ExitCode {
    // The curated file goes through `load_seed_list` (bounded streaming read +
    // canonical dedup, dropping anything that is not a darknet URL); the
    // repeatable `--seed` flags are appended verbatim, as in the Python
    // `cmd_reseed`.
    let mut seeds = match a.seed_list.as_deref() {
        None => Vec::new(),
        Some(p) => match load_seed_list(p, a.allow_v2, a.enable_i2p) {
            Ok(s) => s,
            Err(msg) => {
                eprintln!("{msg}");
                return ExitCode::from(1);
            }
        },
    };
    seeds.extend(a.seed.iter().cloned());
    if seeds.is_empty() {
        eprintln!("reseed: provide --seed-list FILE and/or --seed URL");
        return ExitCode::from(2);
    }
    let mut store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let abuse = build_abuse(&a.blocklists);
    let now = now_secs();
    let (mut reseeded, mut added, mut blocked, mut capped, mut not_onion) = (0, 0, 0, 0, 0);
    for raw in seeds {
        let Some(cu) = canon_seed(&raw, a.allow_v2, a.enable_i2p)
            .and_then(|u| canonicalize(&u, None, a.allow_v2, a.enable_i2p))
        else {
            not_onion += 1;
            continue;
        };
        if abuse.host_blocked(&cu.host) {
            blocked += 1;
            continue;
        }
        store.ensure_host(&cu.host, now);
        match store.reseed_url(&cu, Caps::default(), now, true) {
            Reseed::Requeued => reseeded += 1,
            Reseed::Enqueue(Enqueued::Ok) => added += 1,
            Reseed::Enqueue(_) | Reseed::HostDead => capped += 1,
        }
    }
    if let Err(msg) = write_store(&store, &a.db) {
        eprintln!("{msg}");
        return ExitCode::from(1);
    }
    println!(
        "[reseed] reseeded={reseeded} added={added} blocked={blocked} capped={capped} \
not-onion={not_onion}"
    );
    ExitCode::from(0)
}

/// `backup` — the port of the Python `cmd_backup` (`VACUUM INTO` → a standalone
/// snapshot copy).
fn run_backup(a: &BackupArgs) -> ExitCode {
    let dest = a.out.clone().unwrap_or_else(|| {
        format!(
            "{}.backup-{}.db",
            a.db.trim_end_matches('/'),
            timestamp(now_secs())
        )
    });
    if Path::new(&dest).exists() {
        eprintln!("error: destination {dest} already exists (refusing to overwrite)");
        return ExitCode::from(2);
    }
    let store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    match write_store(&store, &dest) {
        Ok(bytes) => {
            println!(
                "[backup] wrote {dest} ({bytes} bytes, {} pages)",
                store.page_count()
            );
            ExitCode::from(0)
        }
        Err(msg) => {
            eprintln!("{msg}");
            ExitCode::from(1)
        }
    }
}

/// `authority` — the port of the Python `cmd_authority`.
fn run_authority(a: &AuthorityArgs) -> ExitCode {
    let mut store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let n = store.compute_authority(a.iterations, a.damping);
    if let Err(msg) = write_store(&store, &a.db) {
        eprintln!("{msg}");
        return ExitCode::from(1);
    }
    println!("[authority] scored {n} host(s) via PageRank-lite");
    ExitCode::from(0)
}

/// `cluster` — the port of the Python `cmd_cluster`.
fn run_cluster(a: &ClusterArgs) -> ExitCode {
    let mut store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let n = store.cluster_mirrors(a.threshold, a.max_pages);
    if let Err(msg) = write_store(&store, &a.db) {
        eprintln!("{msg}");
        return ExitCode::from(1);
    }
    println!("[cluster] {n} mirror cluster(s) found");
    ExitCode::from(0)
}

/// `recrawl` — the port of the Python `cmd_recrawl`.
fn run_recrawl(a: &RecrawlArgs) -> ExitCode {
    let mut store = match read_store(&a.db) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let n = store.mark_recrawl_due(now_secs(), a.recrawl_ttl);
    if let Err(msg) = write_store(&store, &a.db) {
        eprintln!("{msg}");
        return ExitCode::from(1);
    }
    println!("[recrawl] marked {n} page(s) due for recrawl");
    ExitCode::from(0)
}

// ---------------------------------------------------------------------------
// Help text
// ---------------------------------------------------------------------------

fn top_help() -> String {
    format!(
        "\
{PROG} — darknet (.onion/.i2p) crawler, index and no-JS search.

usage: {PROG} <command> [options]

commands:
  crawl       crawl darknet seeds (resumable)
  search      serve the no-JS search UI + JSON API
  stats       show frontier/pages/host stats
  submit      validate + enqueue seed darknet URL(s)
  reseed      import a curated seed list and re-enqueue the roots (alias: seeds)
  backup      write a standalone snapshot copy of the index
  authority   compute offline PageRank host authority
  cluster     cluster near-duplicate mirror pages
  recrawl     mark due pages for recrawl now

Every command takes `--db PATH` (default: crawl.db) — the snapshot file that is
this engine's whole persistence unit.

Run `{PROG} <command> --help` for per-command options.
"
    )
}

fn crawl_help() -> String {
    format!(
        "\
usage: {PROG} crawl [options]

crawl darknet seeds into the index snapshot (resumable: the frontier, host state
and pages are restored from --db and written back when the run finishes).

options:
  --db PATH                  snapshot database path (default: crawl.db)
  --seeds FILE               file of seed URLs (one per line; '#' comments)
  --seed URL                 a seed URL (repeatable)
  --fetcher {{tor,direct}}     transport (default: tor; `direct` is TEST ONLY)
  --tor-host HOST            Tor SOCKS5 host (default: 127.0.0.1)
  --tor-port PORT            Tor SOCKS5 port (default: 9050)
  --direct-map HOST=IP:PORT  TEST ONLY loopback mapping (repeatable)
  --submission-ttl SECONDS   expire never-crawled queued seeds older than this
                             at the start of the run; 0=off (default: 0)
  --enable-i2p               admit .i2p hosts (darknet-only; off by default)
  --allow-v2                 admit deprecated v2 .onion hosts
  --workers N                crawl workers (default: {DEFAULT_WORKERS})
  --crawl-delay SECONDS      base per-host politeness delay (default: {DEFAULT_CRAWL_DELAY})
  --max-depth N              maximum crawl depth (default: {DEFAULT_MAX_DEPTH})
  --max-pages-per-host N     per-host page budget, 0=unlimited (default: {DEFAULT_MAX_PAGES_PER_HOST})
  --max-total-pages N        whole-index page cap, 0=unlimited (default: {DEFAULT_MAX_TOTAL_PAGES})
  --no-robots                do not fetch/honour robots.txt (impolite)
  --blocklist-hosts FILE     host blocklist (default: blocklist_hosts.txt)
  --blocklist-keywords FILE  keyword blocklist (default: blocklist_keywords.txt)
  --blocklist-media FILE     hex sha256 media blocklist (default: blocklist_media.txt)
  --blocklist-host-md5 FILE  Ahmia md5(domain) banlist (default: none)
  -v, --verbose              log the resolved crawl config to stderr
"
    )
}

fn search_help() -> String {
    format!(
        "\
usage: {PROG} search [options]

serve the no-JS search UI + JSON API over the restored index.

options:
  --db PATH                  snapshot database path (default: crawl.db)
  --host HOST                bind address (default: 127.0.0.1)
  --port PORT                bind port (default: 8802)
  --enable-i2p               accept .i2p host filters/submissions
  --admin-token TOKEN        Bearer token gating POST /add, /purge, /recrawl
                             (unset => the write endpoints answer 403)
  --allow-public-submit      allow POST /add without auth (off by default)
  --base-url URL             OpenSearch base URL (default: http://<host>:<port>)
  --read-rate N              GETs/s per client (default: 20)
  --read-burst N             GET burst per client (default: 60)
  --write-rate N             POSTs/s per client; each write fsyncs the whole
                             snapshot, so keep it low (default: 1)
  --write-burst N            POST burst per client (default: 10)
  --blocklist-hosts FILE     host blocklist (default: blocklist_hosts.txt)
  --blocklist-keywords FILE  keyword blocklist (default: blocklist_keywords.txt)
  --blocklist-media FILE     media blocklist (default: blocklist_media.txt)
  --blocklist-host-md5 FILE  Ahmia md5(domain) banlist (default: none)
"
    )
}

fn stats_help() -> String {
    format!(
        "\
usage: {PROG} stats [options]

show frontier / pages / host statistics.

options:
  --db PATH   snapshot database path (default: crawl.db)
  --json      emit the statistics as JSON
"
    )
}

fn submit_help() -> String {
    format!(
        "\
usage: {PROG} submit [URL ...] [options]

validate one or more darknet URLs through the onion gate + abuse filter and
enqueue them as seeds.

options:
  --db PATH                  snapshot database path (default: crawl.db)
  --file FILE                file of URLs to bulk-import (one per line)
  --allow-v2                 admit deprecated v2 .onion hosts
  --enable-i2p               also accept .i2p submissions
  --blocklist-hosts FILE     host blocklist (default: blocklist_hosts.txt)
  --blocklist-keywords FILE  keyword blocklist (default: blocklist_keywords.txt)
  --blocklist-media FILE     media blocklist (default: blocklist_media.txt)

exit codes: 0 ok, 2 when no URL was given.
"
    )
}

fn reseed_help() -> String {
    format!(
        "\
usage: {PROG} reseed [options]        (alias: {PROG} seeds)

import a curated seed list and re-enqueue the roots: revives hosts previously
aged out as dead, requeues settled rows, and bypasses the trap caps.

options:
  --db PATH                  snapshot database path (default: crawl.db)
  --seed-list FILE           curated seed file (one .onion/.i2p per line)
  --seed URL                 a seed URL (repeatable)
  --allow-v2                 admit deprecated v2 .onion hosts
  --enable-i2p               also accept .i2p seeds
  --blocklist-hosts FILE     host blocklist (default: blocklist_hosts.txt)
  --blocklist-keywords FILE  keyword blocklist (default: blocklist_keywords.txt)
  --blocklist-media FILE     media blocklist (default: blocklist_media.txt)

exit codes: 0 ok, 2 when no seed was given.
"
    )
}

fn backup_help() -> String {
    format!(
        "\
usage: {PROG} backup [options]

write a standalone snapshot copy of the index.

options:
  --db PATH    source snapshot database (default: crawl.db)
  --out DEST   destination path (default: <db>.backup-<UTC timestamp>.db;
               must not already exist)
"
    )
}

fn authority_help() -> String {
    format!(
        "\
usage: {PROG} authority [options]

compute offline PageRank-lite host authority over the host link graph.

options:
  --db PATH        snapshot database path (default: crawl.db)
  --iterations N   power-iteration count (default: 20)
  --damping FLOAT  damping factor (default: 0.85)
"
    )
}

fn cluster_help() -> String {
    format!(
        "\
usage: {PROG} cluster [options]

cluster near-duplicate mirror pages by SimHash Hamming distance.

options:
  --db PATH        snapshot database path (default: crawl.db)
  --threshold N    max SimHash Hamming distance for a mirror (default: 3)
  --max-pages N    scan window for the O(n^2) pass (default: {DEFAULT_CLUSTER_MAX_PAGES})
"
    )
}

fn recrawl_help() -> String {
    format!(
        "\
usage: {PROG} recrawl [options]

requeue every done page on an active host whose recrawl interval has elapsed.

options:
  --db PATH             snapshot database path (default: crawl.db)
  --recrawl-ttl SECONDS fallback per-page recrawl interval
                        (default: {DEFAULT_RECRAWL_TTL} — 7 days)
"
    )
}

// ---------------------------------------------------------------------------
// Tests — the pure wiring: arg parsing, the seed/direct-map/config helpers, the
// stats renderers, and the offline commands over real snapshot files. No Tor, no
// sockets, no network.
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;

    fn argv(parts: &[&str]) -> Vec<String> {
        parts.iter().map(|s| (*s).to_string()).collect()
    }

    fn tmp(tag: &str, line: u32) -> std::path::PathBuf {
        std::env::temp_dir().join(format!(
            "onioncrawler_cli_{tag}_{}_{line}",
            std::process::id()
        ))
    }

    fn onion(c: char) -> String {
        format!("http://{}.onion/", c.to_string().repeat(56))
    }

    // -- parsing ------------------------------------------------------------

    #[test]
    fn crawl_defaults_match_python() {
        let a = match parse_args(&argv(&["crawl"])) {
            Ok(Command::Crawl(a)) => a,
            other => panic!("expected crawl, got {other:?}"),
        };
        assert_eq!(a.db, "crawl.db");
        assert_eq!(a.seeds, None);
        assert!(a.seed.is_empty());
        assert_eq!(a.fetcher, "tor");
        assert_eq!(a.tor_host, "127.0.0.1");
        assert_eq!(a.tor_port, 9050);
        assert_eq!(a.submission_ttl, 0.0);
        assert!(!a.enable_i2p);
        assert!(!a.allow_v2);
        assert_eq!(a.workers, 4);
        assert_eq!(a.crawl_delay, 3.0);
        assert_eq!(a.max_depth, 8);
        assert_eq!(a.max_pages_per_host, 500);
        assert_eq!(a.max_total_pages, 10_000);
        assert!(!a.no_robots);
        assert!(!a.verbose);
        assert_eq!(a.blocklists, Blocklists::default());
        assert_eq!(a.blocklists.hosts, "blocklist_hosts.txt");
        assert_eq!(a.blocklists.keywords, "blocklist_keywords.txt");
        assert_eq!(a.blocklists.media, "blocklist_media.txt");
        assert_eq!(a.blocklists.host_md5, "");
    }

    #[test]
    fn crawl_parses_all_flags_and_repeatables() {
        let a = match parse_args(&argv(&[
            "crawl",
            "--db",
            "c.db",
            "--seeds",
            "seeds.txt",
            "--seed",
            "http://a.onion/",
            "--seed",
            "http://b.onion/",
            "--fetcher",
            "direct",
            "--tor-host",
            "10.0.0.9",
            "--tor-port",
            "9150",
            "--direct-map",
            "a.onion=127.0.0.1:8080",
            "--submission-ttl",
            "86400",
            "--enable-i2p",
            "--allow-v2",
            "--workers",
            "8",
            "--crawl-delay",
            "0.5",
            "--max-depth",
            "3",
            "--max-pages-per-host",
            "50",
            "--max-total-pages",
            "99",
            "--no-robots",
            "--blocklist-hosts",
            "h.txt",
            "--blocklist-keywords",
            "k.txt",
            "--blocklist-media",
            "m.txt",
            "--blocklist-host-md5",
            "md5.txt",
            "-v",
        ])) {
            Ok(Command::Crawl(a)) => a,
            other => panic!("expected crawl, got {other:?}"),
        };
        assert_eq!(a.db, "c.db");
        assert_eq!(a.seeds.as_deref(), Some("seeds.txt"));
        assert_eq!(a.seed, vec!["http://a.onion/", "http://b.onion/"]);
        assert_eq!(a.fetcher, "direct");
        assert_eq!(a.tor_host, "10.0.0.9");
        assert_eq!(a.tor_port, 9150);
        assert_eq!(a.direct_map, vec!["a.onion=127.0.0.1:8080"]);
        assert_eq!(a.submission_ttl, 86_400.0);
        assert!(a.enable_i2p);
        assert!(a.allow_v2);
        assert_eq!(a.workers, 8);
        assert_eq!(a.crawl_delay, 0.5);
        assert_eq!(a.max_depth, 3);
        assert_eq!(a.max_pages_per_host, 50);
        assert_eq!(a.max_total_pages, 99);
        assert!(a.no_robots);
        assert!(a.verbose);
        assert_eq!(a.blocklists.hosts, "h.txt");
        assert_eq!(a.blocklists.keywords, "k.txt");
        assert_eq!(a.blocklists.media, "m.txt");
        assert_eq!(a.blocklists.host_md5, "md5.txt");
    }

    #[test]
    fn crawl_rejects_the_unported_i2p_fetcher() {
        assert!(matches!(
            parse_args(&argv(&["crawl", "--fetcher", "i2p"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["crawl", "--fetcher=nope"])),
            Err(CliError::Usage(_))
        ));
        // ... but the two supported transports parse.
        assert!(parse_args(&argv(&["crawl", "--fetcher=tor"])).is_ok());
        assert!(parse_args(&argv(&["crawl", "--fetcher=direct"])).is_ok());
    }

    #[test]
    fn search_defaults_and_flags() {
        let d = match parse_args(&argv(&["search"])) {
            Ok(Command::Search(a)) => a,
            other => panic!("expected search, got {other:?}"),
        };
        assert_eq!(d.db, "crawl.db");
        assert_eq!(d.host, "127.0.0.1");
        assert_eq!(d.port, 8802);
        assert!(!d.enable_i2p);
        assert_eq!(d.admin_token, None);
        assert!(!d.allow_public_submit);
        assert_eq!(d.base_url, None);
        // the served rate limits default to `RateLimits::default()`
        assert_eq!(d.read_rate, RateLimits::default().read_rate);
        assert_eq!(d.write_rate, RateLimits::default().write_rate);

        let a = match parse_args(&argv(&[
            "search",
            "--db=s.db",
            "--host=0.0.0.0",
            "--port=9002",
            "--enable-i2p",
            "--admin-token=tok",
            "--allow-public-submit",
            "--base-url=http://pub.example",
            "--blocklist-hosts=h.txt",
            "--read-rate=3.5",
            "--read-burst=7",
            "--write-rate=0.25",
            "--write-burst=2",
        ])) {
            Ok(Command::Search(a)) => a,
            other => panic!("expected search, got {other:?}"),
        };
        assert_eq!(a.read_rate, 3.5);
        assert_eq!(a.read_burst, 7.0);
        assert_eq!(a.write_rate, 0.25);
        assert_eq!(a.write_burst, 2.0);
        assert_eq!(a.db, "s.db");
        assert_eq!(a.host, "0.0.0.0");
        assert_eq!(a.port, 9002);
        assert!(a.enable_i2p);
        assert_eq!(a.admin_token.as_deref(), Some("tok"));
        assert!(a.allow_public_submit);
        assert_eq!(a.base_url.as_deref(), Some("http://pub.example"));
        assert_eq!(a.blocklists.hosts, "h.txt");
    }

    #[test]
    fn stats_submit_reseed_parse() {
        let s = match parse_args(&argv(&["stats", "--json"])) {
            Ok(Command::Stats(a)) => a,
            other => panic!("expected stats, got {other:?}"),
        };
        assert_eq!(s.db, "crawl.db");
        assert!(s.json);

        let sub = match parse_args(&argv(&[
            "submit",
            "http://a.onion/",
            "http://b.onion/",
            "--file",
            "urls.txt",
            "--allow-v2",
            "--enable-i2p",
            "--blocklist-media",
            "m.txt",
        ])) {
            Ok(Command::Submit(a)) => a,
            other => panic!("expected submit, got {other:?}"),
        };
        assert_eq!(sub.urls, vec!["http://a.onion/", "http://b.onion/"]);
        assert_eq!(sub.file.as_deref(), Some("urls.txt"));
        assert!(sub.allow_v2);
        assert!(sub.enable_i2p);
        assert_eq!(sub.blocklists.media, "m.txt");
        // `submit` does not take the Ahmia md5 banlist (matching Python).
        assert!(matches!(
            parse_args(&argv(&["submit", "--blocklist-host-md5", "x"])),
            Err(CliError::Usage(_))
        ));

        // `seeds` is the alias for `reseed`.
        for name in ["reseed", "seeds"] {
            let r = match parse_args(&argv(&[
                name,
                "--seed-list",
                "curated.txt",
                "--seed",
                "http://a.onion/",
                "--allow-v2",
            ])) {
                Ok(Command::Reseed(a)) => a,
                other => panic!("expected reseed, got {other:?}"),
            };
            assert_eq!(r.seed_list.as_deref(), Some("curated.txt"));
            assert_eq!(r.seed, vec!["http://a.onion/"]);
            assert!(r.allow_v2);
        }
    }

    #[test]
    fn maintenance_commands_parse() {
        let b = match parse_args(&argv(&["backup", "--db", "c.db", "--out", "copy.db"])) {
            Ok(Command::Backup(a)) => a,
            other => panic!("expected backup, got {other:?}"),
        };
        assert_eq!(b.db, "c.db");
        assert_eq!(b.out.as_deref(), Some("copy.db"));
        assert_eq!(
            match parse_args(&argv(&["backup"])) {
                Ok(Command::Backup(a)) => a.out,
                other => panic!("expected backup, got {other:?}"),
            },
            None
        );

        let au = match parse_args(&argv(&[
            "authority",
            "--iterations",
            "5",
            "--damping",
            "0.5",
        ])) {
            Ok(Command::Authority(a)) => a,
            other => panic!("expected authority, got {other:?}"),
        };
        assert_eq!(au.iterations, 5);
        assert_eq!(au.damping, 0.5);
        let au = match parse_args(&argv(&["authority"])) {
            Ok(Command::Authority(a)) => a,
            other => panic!("expected authority, got {other:?}"),
        };
        assert_eq!(au.iterations, 20);
        assert_eq!(au.damping, 0.85);

        let cl = match parse_args(&argv(&["cluster", "--threshold", "6", "--max-pages", "10"])) {
            Ok(Command::Cluster(a)) => a,
            other => panic!("expected cluster, got {other:?}"),
        };
        assert_eq!(cl.threshold, 6);
        assert_eq!(cl.max_pages, 10);
        assert_eq!(
            match parse_args(&argv(&["cluster"])) {
                Ok(Command::Cluster(a)) => (a.threshold, a.max_pages),
                other => panic!("expected cluster, got {other:?}"),
            },
            (3, DEFAULT_CLUSTER_MAX_PAGES)
        );

        let rc = match parse_args(&argv(&["recrawl", "--recrawl-ttl", "60"])) {
            Ok(Command::Recrawl(a)) => a,
            other => panic!("expected recrawl, got {other:?}"),
        };
        assert_eq!(rc.recrawl_ttl, 60.0);
        assert_eq!(
            match parse_args(&argv(&["recrawl"])) {
                Ok(Command::Recrawl(a)) => a.recrawl_ttl,
                other => panic!("expected recrawl, got {other:?}"),
            },
            DEFAULT_RECRAWL_TTL
        );
    }

    #[test]
    fn parse_errors_and_help_and_version() {
        assert!(matches!(parse_args(&argv(&[])), Err(CliError::Usage(_))));
        assert!(matches!(
            parse_args(&argv(&["frobnicate"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["--help"])),
            Err(CliError::Print(_))
        ));
        for sub in [
            "crawl",
            "search",
            "stats",
            "submit",
            "reseed",
            "seeds",
            "backup",
            "authority",
            "cluster",
            "recrawl",
        ] {
            assert!(
                matches!(parse_args(&argv(&[sub, "--help"])), Err(CliError::Print(_))),
                "{sub} --help"
            );
        }
        match parse_args(&argv(&["--version"])) {
            Err(CliError::Print(text)) => {
                assert_eq!(
                    text,
                    format!("onioncrawler {}\n", env!("CARGO_PKG_VERSION"))
                );
            }
            other => panic!("expected version, got {other:?}"),
        }
        // unknown option / missing value / bad number / stray positional
        assert!(matches!(
            parse_args(&argv(&["crawl", "--nope"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["crawl", "--db"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["crawl", "--workers", "many"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["stats", "junk"])),
            Err(CliError::Usage(_))
        ));
        assert!(matches!(
            parse_args(&argv(&["cluster", "--threshold", "-1"])),
            Err(CliError::Usage(_))
        ));
    }

    // -- helpers ------------------------------------------------------------

    #[test]
    fn seed_lines_strips_comments_and_blanks() {
        let seeds =
            |c: &str| seed_lines_from(c.as_bytes(), MAX_SEEDS, MAX_SEED_LINES, MAX_SEED_LINE_BYTES);
        let contents = "  http://one.onion/  \n# a comment\nhttp://two.onion/ # inline\n\n  \n";
        assert_eq!(
            seeds(contents),
            vec!["http://one.onion/", "http://two.onion/"]
        );
        // a file with no trailing newline still yields its last line
        assert_eq!(seeds("http://one.onion/"), vec!["http://one.onion/"]);
    }

    // -- the bounded seed reader (Python `seedlist.load_seed_list`) ----------

    #[test]
    fn seed_reader_caps_the_line_length() {
        // a 10k line with no newline is delivered in 4096-byte pieces rather
        // than buffered whole, so a gigabyte-with-no-newline cannot OOM us
        let junk = "x".repeat(10_000);
        let got = seed_lines_from(junk.as_bytes(), 100, 100, 4096);
        assert_eq!(got.len(), 3);
        assert_eq!(got[0].len(), 4096);
        assert_eq!(got[1].len(), 4096);
        assert_eq!(got[2].len(), 10_000 - 8192);
        // and every piece fails canonicalization, so nothing junk is accepted
        for piece in &got {
            assert!(canon_seed(piece, false, false).is_none());
        }
    }

    #[test]
    fn seed_reader_caps_lines_scanned_and_seeds_accepted() {
        let blanks_then_seeds = format!("{}http://one.onion/\n", "\n".repeat(10));
        // only the first 5 lines are scanned → the seed after them is never seen
        assert!(seed_lines_from(blanks_then_seeds.as_bytes(), 100, 5, 4096).is_empty());
        assert_eq!(
            seed_lines_from(blanks_then_seeds.as_bytes(), 100, 100, 4096).len(),
            1
        );
        // and the accepted count is capped independently of the lines scanned
        let many: String = (0..50).map(|i| format!("http://h{i}.onion/\n")).collect();
        assert_eq!(seed_lines_from(many.as_bytes(), 7, 1000, 4096).len(), 7);
        // the shipped bounds are the reference's
        assert_eq!(MAX_SEED_LINE_BYTES, 4096);
        assert_eq!(MAX_SEEDS, 100_000);
        assert_eq!(MAX_SEED_LINES, 5_000_000);
    }

    #[test]
    fn load_seed_list_canonicalizes_dedups_and_drops_clearnet() {
        let a = onion('a');
        let b = onion('b');
        let path = tmp("seedlist", line!());
        std::fs::write(
            &path,
            format!(
                "{a}\n# comment\n{a}\n  {a}?utm_source=x  \n{}\nhttp://example.com/\n\
not-a-url\n{b}\n",
                // a bare host defaults to http:// like the reference `_canon`
                b.trim_start_matches("http://").trim_end_matches('/'),
            ),
        )
        .unwrap();
        let got = load_seed_list(path.to_str().unwrap(), false, false).unwrap();
        std::fs::remove_file(&path).ok();
        // deduped by canonical URL (the tracking param is stripped), order
        // preserving, and neither the clearnet line nor the junk line survives
        assert_eq!(got, vec![a, b]);
        assert!(load_seed_list("/nonexistent/seeds.txt", false, false).is_err());
    }

    #[test]
    fn read_seeds_from_a_file_is_hermetic() {
        let path = tmp("seeds", line!());
        std::fs::write(&path, "http://f1.onion/\n#skip\nhttp://f2.onion/\n").unwrap();
        let got = read_seeds(
            Some(path.to_str().unwrap()),
            &["http://pos.onion/".to_string()],
        )
        .unwrap();
        std::fs::remove_file(&path).ok();
        assert_eq!(
            got,
            vec!["http://f1.onion/", "http://f2.onion/", "http://pos.onion/"]
        );
        assert_eq!(
            read_seeds(None, &["http://pos.onion/".to_string()]).unwrap(),
            vec!["http://pos.onion/"]
        );
        assert!(read_seeds(Some("/nonexistent/seeds.txt"), &[]).is_err());
    }

    #[test]
    fn direct_map_parses_and_rejects_junk() {
        let m = parse_direct_map(&[
            "a.onion=127.0.0.1:8080".to_string(),
            " b.onion = 10.0.0.2:99 ".to_string(),
        ])
        .unwrap();
        assert_eq!(m["a.onion"], ("127.0.0.1".to_string(), 8080));
        assert_eq!(m["b.onion"], ("10.0.0.2".to_string(), 99));
        assert!(parse_direct_map(&["nope".to_string()]).is_err());
        assert!(parse_direct_map(&["a.onion=127.0.0.1".to_string()]).is_err());
        assert!(parse_direct_map(&["a.onion=127.0.0.1:99999".to_string()]).is_err());
    }

    #[test]
    fn build_config_maps_flags() {
        let a = match parse_args(&argv(&[
            "crawl",
            "--no-robots",
            "--allow-v2",
            "--enable-i2p",
            "--workers",
            "3",
            "--crawl-delay",
            "0.25",
            "--max-depth",
            "2",
            "--max-pages-per-host",
            "7",
            "--max-total-pages",
            "11",
        ])) {
            Ok(Command::Crawl(a)) => a,
            other => panic!("expected crawl, got {other:?}"),
        };
        let cfg = build_config(&a);
        assert!(!cfg.obey_robots);
        assert!(cfg.allow_v2);
        assert!(cfg.allow_i2p);
        assert_eq!(cfg.workers, 3);
        assert_eq!(cfg.crawl_delay, 0.25);
        assert_eq!(cfg.max_depth, 2);
        assert_eq!(cfg.max_pages_per_host, Some(7));
        assert_eq!(cfg.max_total_pages, Some(11));

        // Defaults: robots on, the Python Config budgets, and 0 = unlimited.
        let d = match parse_args(&argv(&["crawl", "--max-total-pages=0"])) {
            Ok(Command::Crawl(a)) => build_config(&a),
            other => panic!("expected crawl, got {other:?}"),
        };
        assert!(d.obey_robots);
        assert!(!d.allow_v2);
        assert_eq!(d.workers, DEFAULT_WORKERS);
        assert_eq!(d.max_depth, DEFAULT_MAX_DEPTH);
        assert_eq!(d.max_pages_per_host, Some(DEFAULT_MAX_PAGES_PER_HOST));
        assert_eq!(d.max_total_pages, None);
    }

    #[test]
    fn build_fetcher_needs_a_direct_map_and_carries_allow_v2() {
        let tor = match parse_args(&argv(&["crawl", "--allow-v2"])) {
            Ok(Command::Crawl(a)) => build_fetcher(&a).unwrap(),
            other => panic!("expected crawl, got {other:?}"),
        };
        assert!(tor.allow_v2);
        let direct = match parse_args(&argv(&[
            "crawl",
            "--fetcher",
            "direct",
            "--direct-map",
            "a.onion=127.0.0.1:1",
        ])) {
            Ok(Command::Crawl(a)) => build_fetcher(&a),
            other => panic!("expected crawl, got {other:?}"),
        };
        assert!(direct.is_ok());
        let missing = match parse_args(&argv(&["crawl", "--fetcher", "direct"])) {
            Ok(Command::Crawl(a)) => build_fetcher(&a),
            other => panic!("expected crawl, got {other:?}"),
        };
        assert!(missing.is_err(), "direct transport needs a mapping");
    }

    #[test]
    fn abuse_filter_emptiness_and_paths() {
        let path = tmp("blocklist", line!());
        std::fs::write(&path, "# comment\nbadhost.onion\n").unwrap();
        let empty = build_abuse(&Blocklists {
            hosts: String::new(),
            keywords: String::new(),
            media: String::new(),
            host_md5: String::new(),
        });
        assert!(abuse_is_empty(&empty));
        let loaded = build_abuse(&Blocklists {
            hosts: path.to_str().unwrap().to_string(),
            ..Blocklists::default()
        });
        std::fs::remove_file(&path).ok();
        assert!(!abuse_is_empty(&loaded));
        assert!(loaded.host_blocked("badhost.onion"));
    }

    #[test]
    fn timestamp_formats_utc() {
        assert_eq!(timestamp(0.0), "19700101-000000");
        assert_eq!(timestamp(1_700_000_000.0), "20231114-221320");
    }

    // -- offline commands over real snapshot files --------------------------

    /// A store with one queued seed, for the offline command tests.
    fn seeded_store(url: &str) -> Store {
        let mut s = Store::new();
        let now = 1_700_000_000.0;
        let cu = canonicalize(url, None, false, false).expect("darknet url");
        s.ensure_host(&cu.host, now);
        s.add_seed(&cu, 0, 0, Caps::default(), now, true);
        s
    }

    #[test]
    fn read_store_roundtrips_and_rejects_corruption() {
        let path = tmp("roundtrip", line!());
        let db = path.to_str().unwrap().to_string();
        assert_eq!(read_store(&db).unwrap().page_count(), 0);

        let store = seeded_store(&onion('a'));
        write_store(&store, &db).unwrap();
        let back = read_store(&db).unwrap();
        assert_eq!(back.host_count(), 1);
        assert_eq!(
            back.counter("urls_enqueued"),
            store.counter("urls_enqueued")
        );

        std::fs::write(&path, b"not a snapshot").unwrap();
        assert!(read_store(&db).is_err());
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn stats_renders_text_and_json_over_a_real_store() {
        let store = seeded_store(&onion('b'));
        let text = stats_report(&store);
        assert!(text.starts_with("== onioncrawler stats =="));
        assert!(text.contains("pages indexed      : 0"));
        assert!(text.contains("urls enqueued      : 1"));
        assert!(text.contains("frontier:"));
        assert!(text.contains("  queued  : 1"));

        let json = stats_json(&store);
        assert!(json.starts_with('{') && json.ends_with('}'));
        assert!(json.contains("\"urls_enqueued\": 1"));
        assert!(json.contains("\"frontier_by_status\": {\"queued\": 1}"));
        assert!(json.contains("\"hosts_by_state\": {\"active\": 1}"));
        assert!(json.contains("\"trapped_hosts\": []"));
    }

    #[test]
    fn stats_command_runs_over_missing_and_populated_dbs() {
        let path = tmp("stats", line!());
        let db = path.to_str().unwrap().to_string();
        let ok = format!("{:?}", ExitCode::from(0));
        assert_eq!(
            format!(
                "{:?}",
                run_stats(&StatsArgs {
                    db: db.clone(),
                    json: false
                })
            ),
            ok
        );
        write_store(&seeded_store(&onion('c')), &db).unwrap();
        assert_eq!(
            format!(
                "{:?}",
                run_stats(&StatsArgs {
                    db: db.clone(),
                    json: true
                })
            ),
            ok
        );
        std::fs::write(&path, b"garbage").unwrap();
        assert_eq!(
            format!(
                "{:?}",
                run_stats(&StatsArgs {
                    db: db.clone(),
                    json: false
                })
            ),
            format!("{:?}", ExitCode::from(1))
        );
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn submit_command_enqueues_and_persists() {
        let path = tmp("submit", line!());
        let db = path.to_str().unwrap().to_string();
        let args = SubmitArgs {
            db: db.clone(),
            urls: vec![onion('d'), "http://clearnet.example/".to_string()],
            file: None,
            allow_v2: false,
            enable_i2p: false,
            // No blocklists on disk => an empty (permissive) filter.
            blocklists: Blocklists {
                hosts: String::new(),
                keywords: String::new(),
                media: String::new(),
                host_md5: String::new(),
            },
        };
        assert_eq!(
            format!("{:?}", run_submit(&args)),
            format!("{:?}", ExitCode::from(0))
        );
        let store = read_store(&db).unwrap();
        assert_eq!(store.counter("urls_enqueued"), 1, "clearnet url refused");
        assert_eq!(store.host_count(), 1);

        // No URL at all is argparse-style usage error 2.
        let none = SubmitArgs {
            urls: Vec::new(),
            ..args
        };
        assert_eq!(
            format!("{:?}", run_submit(&none)),
            format!("{:?}", ExitCode::from(2))
        );
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn reseed_command_requeues_settled_roots() {
        let path = tmp("reseed", line!());
        let db = path.to_str().unwrap().to_string();
        let url = onion('e');
        write_store(&seeded_store(&url), &db).unwrap();

        let args = ReseedArgs {
            db: db.clone(),
            seed_list: None,
            seed: vec![url.clone(), "http://clearnet.example/".to_string()],
            allow_v2: false,
            enable_i2p: false,
            blocklists: Blocklists {
                hosts: String::new(),
                keywords: String::new(),
                media: String::new(),
                host_md5: String::new(),
            },
        };
        assert_eq!(
            format!("{:?}", run_reseed(&args)),
            format!("{:?}", ExitCode::from(0))
        );
        // The already-known root is requeued (not double-counted), and the store
        // still holds exactly one host.
        let store = read_store(&db).unwrap();
        assert_eq!(store.host_count(), 1);
        assert_eq!(store.frontier_by_status().get("queued"), Some(&1));

        let none = ReseedArgs {
            seed: Vec::new(),
            ..args
        };
        assert_eq!(
            format!("{:?}", run_reseed(&none)),
            format!("{:?}", ExitCode::from(2))
        );
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn maintenance_commands_run_over_a_snapshot() {
        let path = tmp("maint", line!());
        let db = path.to_str().unwrap().to_string();
        write_store(&seeded_store(&onion('f')), &db).unwrap();
        let ok = format!("{:?}", ExitCode::from(0));

        assert_eq!(
            format!(
                "{:?}",
                run_authority(&AuthorityArgs {
                    db: db.clone(),
                    iterations: 3,
                    damping: 0.85
                })
            ),
            ok
        );
        assert_eq!(
            format!(
                "{:?}",
                run_cluster(&ClusterArgs {
                    db: db.clone(),
                    threshold: 3,
                    max_pages: 100
                })
            ),
            ok
        );
        assert_eq!(
            format!(
                "{:?}",
                run_recrawl(&RecrawlArgs {
                    db: db.clone(),
                    recrawl_ttl: 1.0
                })
            ),
            ok
        );

        // backup: writes a fresh snapshot, then refuses to overwrite it.
        let out = tmp("maint_backup", line!());
        let out_s = out.to_str().unwrap().to_string();
        assert_eq!(
            format!(
                "{:?}",
                run_backup(&BackupArgs {
                    db: db.clone(),
                    out: Some(out_s.clone())
                })
            ),
            ok
        );
        assert_eq!(read_store(&out_s).unwrap().host_count(), 1);
        assert_eq!(
            format!(
                "{:?}",
                run_backup(&BackupArgs {
                    db: db.clone(),
                    out: Some(out_s)
                })
            ),
            format!("{:?}", ExitCode::from(2))
        );
        std::fs::remove_file(&out).ok();
        std::fs::remove_file(&path).ok();
    }
}
