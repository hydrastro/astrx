//! The `websearch` command-line entrypoint — a dependency-free port of the Python
//! `python3 -m websearch` CLI (`legacy-python/websearch/websearch/__main__.py`).
//!
//! Four subcommands drive the library: `crawl` seeds a [`Crawler`], runs it, and
//! [`Index::snapshot`]s the result to `--db`; `serve` [`Index::restore`]s that
//! snapshot and runs the no-JS [`SearchServer`]; `stats` restores and prints the
//! index statistics; `backup` restores and writes a fresh snapshot to `--out`.
//!
//! `crawl` normally starts from an EMPTY index and overwrites `--db` with the
//! run's result — where the Python crawls straight into a persistent SQLite file.
//! `crawl --recrawl` is the one path that restores `--db` first, because its whole
//! job is to refetch what is already indexed ([`Crawler::enqueue_recrawls`]); that
//! run extends the restored index rather than replacing it.
//!
//! `serve` builds the server with [`SearchServer::new`], i.e. WITHOUT a frontier
//! handle, so its `/about` page omits the Frontier table: `--db` is an
//! [`Index::snapshot`], and that format carries documents only — the frontier
//! lives in the crawler process and is never persisted, where the Python's
//! frontier shares the index's SQLite file and so is always readable from
//! `serve`. A server that DOES hold a live [`websearch::Frontier`] (an in-process
//! crawl + serve) renders the table via [`SearchServer::with_frontier`].
//!
//! Not ported from the Python CLI: `serve`'s `--rate`/`--burst`/`--auth`
//! (the server has neither a rate limiter nor HTTP Basic auth — see the
//! [`websearch::serve`] module docs) and the `fed-serve` subcommand.
//!
//! The whole binary is gated behind the crate's `net` feature (see the `[[bin]]`
//! `required-features` in `Cargo.toml`), so the default `websearch` build stays a
//! pure, zero-dependency library. Argument parsing is hand-rolled — no `clap`, no
//! third-party crate — to keep that guarantee.
#![forbid(unsafe_code)]

use std::collections::BTreeSet;
use std::io::ErrorKind;
use std::path::Path;
use std::process::ExitCode;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use tokio::net::TcpListener;

use websearch::crawler::PDF_TYPE;
use websearch::serve::serve;
use websearch::{canonicalize, host_of, CrawlConfig, CrawlStats, Crawler, Index, SearchServer};

const PROG: &str = "websearch";

// ---------------------------------------------------------------------------
// Parsed command surface
// ---------------------------------------------------------------------------

/// A fully parsed subcommand invocation.
#[derive(Debug, PartialEq)]
enum Command {
    Crawl(CrawlArgs),
    Serve(ServeArgs),
    Stats(StatsArgs),
    Backup(BackupArgs),
}

/// `crawl` — mirrors the Python `crawl` subparser (names + defaults).
#[derive(Debug, PartialEq)]
struct CrawlArgs {
    seeds: Vec<String>,
    seeds_file: Option<String>,
    db: String,
    scope_domain: Vec<String>,
    broad: bool,
    max_depth: i64,
    max_pages: u64,
    per_host_budget: u64,
    max_bytes: usize,
    delay: f64,
    jitter: f64,
    timeout: f64,
    user_agent: String,
    no_robots: bool,
    allow_host: Vec<String>,
    allow_internal_ips: bool,
    workers: usize,
    keep_alive: bool,
    index_pdf: bool,
    /// Also re-queue already-indexed URLs that are due for a refetch. Implies
    /// loading `--db` into the crawler first (see [`run_crawl`]) — without an
    /// existing index there is nothing to be due. Python `--recrawl`.
    recrawl: bool,
    /// Recrawl age threshold in seconds. Python `--recrawl-interval`, 7 days.
    recrawl_interval: f64,
    shard_id: Option<String>,
    shards: Option<String>,
    verbose: bool,
}

/// `serve` — the no-JS UI + JSON API server.
#[derive(Debug, PartialEq)]
struct ServeArgs {
    db: String,
    host: String,
    port: u16,
    /// The self-describing base URL (`opensearch.xml`, JSON API links). The Rust
    /// [`SearchServer`] takes this explicitly; Python derives it internally, hence
    /// the extra `--base-url` flag. Defaults to `http://<host>:<port>`.
    base_url: Option<String>,
    verbose: bool,
}

/// `stats` — print index statistics.
#[derive(Debug, PartialEq)]
struct StatsArgs {
    db: String,
}

/// `backup` — write a fresh snapshot of `--db` to `--out`.
#[derive(Debug, PartialEq)]
struct BackupArgs {
    db: String,
    out: String,
}

/// A parse outcome that is not a runnable command: either a help request (print
/// to stdout, exit 0) or a usage error (print to stderr, exit 2) — mirroring how
/// Python's `argparse` handles `--help` versus a bad argument.
#[derive(Debug, PartialEq)]
enum CliError {
    Help(String),
    Usage(String),
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    let cmd = match parse_args(&argv) {
        Ok(c) => c,
        Err(CliError::Help(text)) => {
            print!("{text}");
            return ExitCode::from(0);
        }
        Err(CliError::Usage(msg)) => {
            eprintln!("{msg}");
            return ExitCode::from(2);
        }
    };
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
        Command::Crawl(a) => run_crawl(a).await,
        Command::Serve(a) => run_serve(a).await,
        Command::Stats(a) => run_stats(&a),
        Command::Backup(a) => run_backup(&a),
    }
}

// ---------------------------------------------------------------------------
// Argument parsing (hand-rolled, dependency-free)
// ---------------------------------------------------------------------------

/// Split any `--flag=value` token into two (`--flag`, `value`) so the per-command
/// walkers only ever see `--flag [value]`. Positional args (seeds) never start
/// with `--`, so they are passed through untouched.
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

/// Top-level dispatch: pick the subcommand, then hand its arguments to the matching
/// walker. Mirrors the required-subcommand behaviour of the Python `argparse`
/// setup (no subcommand → usage error, exit 2).
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
        "serve" => parse_serve(rest).map(Command::Serve),
        "stats" => parse_stats(rest).map(Command::Stats),
        "backup" => parse_backup(rest).map(Command::Backup),
        "-h" | "--help" | "help" => Err(CliError::Help(top_help())),
        other => Err(CliError::Usage(format!(
            "error: unknown command {other:?}\n\n{}",
            top_help()
        ))),
    }
}

fn parse_crawl(toks: &[String]) -> Result<CrawlArgs, CliError> {
    let mut a = CrawlArgs {
        seeds: Vec::new(),
        seeds_file: None,
        db: "web.db".to_string(),
        scope_domain: Vec::new(),
        broad: false,
        max_depth: 6,
        max_pages: 2000,
        per_host_budget: 500,
        max_bytes: 2_000_000,
        delay: 0.5,
        jitter: 0.3,
        timeout: 10.0,
        user_agent: CrawlConfig::default().user_agent,
        no_robots: false,
        allow_host: Vec::new(),
        allow_internal_ips: false,
        workers: 1,
        keep_alive: false,
        index_pdf: false,
        recrawl: false,
        recrawl_interval: 7.0 * 86_400.0,
        shard_id: None,
        shards: None,
        verbose: false,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Help(crawl_help())),
            "--seeds" => {
                i += 1;
                a.seeds_file = Some(need(toks, i, "--seeds")?.to_string());
            }
            "--db" => {
                i += 1;
                a.db = need(toks, i, "--db")?.to_string();
            }
            "--scope-domain" => {
                i += 1;
                a.scope_domain
                    .push(need(toks, i, "--scope-domain")?.to_string());
            }
            "--broad" => a.broad = true,
            "--max-depth" => {
                i += 1;
                a.max_depth = parse_i64(need(toks, i, "--max-depth")?, "--max-depth")?;
            }
            "--max-pages" => {
                i += 1;
                a.max_pages = parse_u64(need(toks, i, "--max-pages")?, "--max-pages")?;
            }
            "--per-host-budget" => {
                i += 1;
                a.per_host_budget =
                    parse_u64(need(toks, i, "--per-host-budget")?, "--per-host-budget")?;
            }
            "--max-bytes" => {
                i += 1;
                a.max_bytes = parse_usize(need(toks, i, "--max-bytes")?, "--max-bytes")?;
            }
            "--delay" => {
                i += 1;
                a.delay = parse_f64(need(toks, i, "--delay")?, "--delay")?;
            }
            "--jitter" => {
                i += 1;
                a.jitter = parse_f64(need(toks, i, "--jitter")?, "--jitter")?;
            }
            "--timeout" => {
                i += 1;
                a.timeout = parse_f64(need(toks, i, "--timeout")?, "--timeout")?;
            }
            "--user-agent" => {
                i += 1;
                a.user_agent = need(toks, i, "--user-agent")?.to_string();
            }
            "--no-robots" => a.no_robots = true,
            "--allow-host" => {
                i += 1;
                a.allow_host
                    .push(need(toks, i, "--allow-host")?.to_string());
            }
            "--allow-internal-ips" => a.allow_internal_ips = true,
            "--workers" => {
                i += 1;
                a.workers = parse_usize(need(toks, i, "--workers")?, "--workers")?;
            }
            "--keep-alive" => a.keep_alive = true,
            "--index-pdf" => a.index_pdf = true,
            "--recrawl" => a.recrawl = true,
            "--recrawl-interval" => {
                i += 1;
                a.recrawl_interval =
                    parse_f64(need(toks, i, "--recrawl-interval")?, "--recrawl-interval")?;
            }
            "--shard-id" => {
                i += 1;
                a.shard_id = Some(need(toks, i, "--shard-id")?.to_string());
            }
            "--shards" => {
                i += 1;
                a.shards = Some(need(toks, i, "--shards")?.to_string());
            }
            "--verbose" => a.verbose = true,
            s if s.starts_with("--") => {
                return Err(CliError::Usage(format!(
                    "error: unrecognized option {s} for `crawl` (try `{PROG} crawl --help`)"
                )))
            }
            _ => a.seeds.push(toks[i].clone()),
        }
        i += 1;
    }
    Ok(a)
}

fn parse_serve(toks: &[String]) -> Result<ServeArgs, CliError> {
    let mut a = ServeArgs {
        db: "web.db".to_string(),
        host: "127.0.0.1".to_string(),
        port: 8803,
        base_url: None,
        verbose: false,
    };
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Help(serve_help())),
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
            "--base-url" => {
                i += 1;
                a.base_url = Some(need(toks, i, "--base-url")?.to_string());
            }
            "--verbose" => a.verbose = true,
            s if s.starts_with("--") => {
                return Err(CliError::Usage(format!(
                    "error: unrecognized option {s} for `serve` (try `{PROG} serve --help`)"
                )))
            }
            other => {
                return Err(CliError::Usage(format!(
                    "error: unexpected argument {other:?} for `serve`"
                )))
            }
        }
        i += 1;
    }
    Ok(a)
}

fn parse_stats(toks: &[String]) -> Result<StatsArgs, CliError> {
    let mut db = "web.db".to_string();
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Help(stats_help())),
            "--db" => {
                i += 1;
                db = need(toks, i, "--db")?.to_string();
            }
            s if s.starts_with("--") => {
                return Err(CliError::Usage(format!(
                    "error: unrecognized option {s} for `stats`"
                )))
            }
            other => {
                return Err(CliError::Usage(format!(
                    "error: unexpected argument {other:?} for `stats`"
                )))
            }
        }
        i += 1;
    }
    Ok(StatsArgs { db })
}

fn parse_backup(toks: &[String]) -> Result<BackupArgs, CliError> {
    let mut db = "web.db".to_string();
    let mut out: Option<String> = None;
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Help(backup_help())),
            "--db" => {
                i += 1;
                db = need(toks, i, "--db")?.to_string();
            }
            "--out" => {
                i += 1;
                out = Some(need(toks, i, "--out")?.to_string());
            }
            s if s.starts_with("--") => {
                return Err(CliError::Usage(format!(
                    "error: unrecognized option {s} for `backup`"
                )))
            }
            other => {
                return Err(CliError::Usage(format!(
                    "error: unexpected argument {other:?} for `backup`"
                )))
            }
        }
        i += 1;
    }
    let out =
        out.ok_or_else(|| CliError::Usage("error: backup requires --out DEST".to_string()))?;
    Ok(BackupArgs { db, out })
}

// ---------------------------------------------------------------------------
// Pure helpers (ported from the Python `_read_seeds` / `_parse_shards` /
// `_build_config` / `cmd_crawl` scope logic)
// ---------------------------------------------------------------------------

/// Comma-separated shard list → the non-empty, trimmed entries. Mirrors the Python
/// `_parse_shards` (empty/whitespace entries are dropped).
fn parse_shards(value: Option<&str>) -> Vec<String> {
    match value {
        None => Vec::new(),
        Some(v) => v
            .split(',')
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .map(String::from)
            .collect(),
    }
}

/// Parse the seed-file contents (comment-stripped, trimmed, blank lines dropped),
/// appended after the positional `extra` seeds — the pure core of `_read_seeds`.
fn seed_lines(contents: &str, extra: &[String]) -> Vec<String> {
    let mut seeds: Vec<String> = extra.to_vec();
    for line in contents.lines() {
        let line = line.split('#').next().unwrap_or("").trim();
        if !line.is_empty() {
            seeds.push(line.to_string());
        }
    }
    seeds
}

/// The full `_read_seeds`: positional `extra` seeds plus, if `path` is given, the
/// seed URLs from that file.
fn read_seeds(path: Option<&str>, extra: &[String]) -> Result<Vec<String>, String> {
    match path {
        None => Ok(extra.to_vec()),
        Some(p) => {
            let contents = std::fs::read_to_string(p)
                .map_err(|e| format!("error: cannot read seeds file {p}: {e}"))?;
            Ok(seed_lines(&contents, extra))
        }
    }
}

/// The crawl scope (`cmd_crawl`): `--broad` → no scope; else `--scope-domain`s;
/// else the sorted, de-duplicated hosts of the seed URLs; else no scope.
fn compute_scope(a: &CrawlArgs, seeds: &[String]) -> Option<Vec<String>> {
    if a.broad {
        None
    } else if !a.scope_domain.is_empty() {
        Some(a.scope_domain.clone())
    } else if !seeds.is_empty() {
        let mut hosts: BTreeSet<String> = BTreeSet::new();
        for s in seeds {
            let canon = canonicalize(s, None).unwrap_or_default();
            let h = host_of(&canon);
            if !h.is_empty() {
                hosts.insert(h);
            }
        }
        Some(hosts.into_iter().collect())
    } else {
        None
    }
}

/// Build a [`CrawlConfig`] from the parsed flags + computed scope — the port of
/// the Python `_build_config`.
fn build_config(a: &CrawlArgs, scope: Option<Vec<String>>) -> CrawlConfig {
    let mut cfg = CrawlConfig {
        scope_hosts: scope,
        respect_robots: !a.no_robots,
        timeout: Duration::from_secs_f64(a.timeout.max(0.0)),
        max_bytes: a.max_bytes,
        max_depth: a.max_depth,
        per_host_budget: a.per_host_budget,
        total_budget: a.max_pages,
        base_delay: a.delay,
        jitter: a.jitter,
        user_agent: a.user_agent.clone(),
        block_internal_ips: !a.allow_internal_ips,
        allow_hosts: a.allow_host.clone(),
        keep_alive: a.keep_alive,
        workers: a.workers,
        recrawl_interval: a.recrawl_interval,
        shard_id: a.shard_id.clone(),
        shards: parse_shards(a.shards.as_deref()),
        ..CrawlConfig::default()
    };
    // `--index-pdf` enables the best-effort PDF text vertical by adding the PDF
    // content type to the indexable set (the Rust analogue of Python's
    // `index_pdf=True`).
    if a.index_pdf {
        cfg.content_types.insert(PDF_TYPE.to_string());
    }
    cfg
}

/// `^[A-Za-z][A-Za-z0-9+.\-]*:` — the Python `index._URI_SCHEME` guard, so a
/// `backup --out` that looks like a URI/scheme is refused (a plain path is wanted).
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

/// Wall-clock now as epoch seconds — the `now` the recrawl due-list is measured
/// against (`Index::due_for_recrawl` takes it explicitly so it stays testable).
fn epoch_secs() -> f64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

/// `fetched_at` epoch seconds → `YYYY-MM-DD` (UTC), for the `stats` fetch range.
fn fmt_date(ts: f64) -> String {
    if ts <= 0.0 {
        return String::new();
    }
    let (y, m, d) = civil_from_days((ts as i64).div_euclid(86400));
    format!("{y:04}-{m:02}-{d:02}")
}

/// A day count since the Unix epoch → `(year, month, day)` (Howard Hinnant's
/// `civil_from_days`), matching `serve::civil_from_days`.
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

/// A one-line rendering of a [`CrawlStats`] for the crawl summary.
fn fmt_stats(s: &CrawlStats) -> String {
    format!(
        "fetched={} indexed={} skipped={} errors={} robots_blocked={} dups={} unchanged={}",
        s.fetched, s.indexed, s.skipped, s.errors, s.robots_blocked, s.dups, s.unchanged
    )
}

/// Load an index from a snapshot file: a missing file yields a fresh empty index
/// (so `serve`/`stats` on a never-crawled db still work); a present-but-corrupt
/// file is a hard error.
fn read_index(db: &str) -> Result<Index, String> {
    match std::fs::read(db) {
        Ok(bytes) => Index::restore(&bytes).ok_or_else(|| {
            format!("error: {db} is not a valid websearch snapshot (corrupt or truncated)")
        }),
        Err(e) if e.kind() == ErrorKind::NotFound => Ok(Index::new()),
        Err(e) => Err(format!("error: cannot read {db}: {e}")),
    }
}

// ---------------------------------------------------------------------------
// Command wiring (ports of `cmd_crawl` / `cmd_serve` / `cmd_stats` / `cmd_backup`)
// ---------------------------------------------------------------------------

async fn run_crawl(a: CrawlArgs) -> ExitCode {
    let seeds = match read_seeds(a.seeds_file.as_deref(), &a.seeds) {
        Ok(s) => s,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    // Python: `if not seeds and not args.recrawl`. A `--recrawl` pass needs no
    // seeds — its work comes from the URLs already in `--db`.
    if seeds.is_empty() && !a.recrawl {
        eprintln!("error: no seeds given (use --seeds FILE or positional URLs)");
        return ExitCode::from(2);
    }

    let scope = compute_scope(&a, &seeds);
    let label = match &scope {
        None => "BROAD".to_string(),
        Some(v) => format!("[{}]", v.join(", ")),
    };
    let cfg = build_config(&a, scope);
    if a.verbose {
        eprintln!("crawl config: {cfg:?}");
    }

    let mut crawler = Crawler::new(cfg);
    // The Python crawls straight into the persistent SQLite database, so its
    // recrawl due-list is simply "what is already indexed". Here the index is a
    // snapshot file, so `--recrawl` has to load it into the crawler before asking
    // what is due — and the run then extends that index instead of replacing it.
    if a.recrawl {
        match read_index(&a.db) {
            Ok(ix) => *crawler.index_mut() = ix,
            Err(msg) => {
                eprintln!("{msg}");
                return ExitCode::from(1);
            }
        }
    }
    let seed_refs: Vec<&str> = seeds.iter().map(String::as_str).collect();
    let added = if seeds.is_empty() {
        0
    } else {
        crawler.add_seeds(&seed_refs)
    };
    let requeued = if a.recrawl {
        crawler.enqueue_recrawls(None, epoch_secs())
    } else {
        0
    };
    if a.workers > 1 {
        println!(
            "seeded {added} URL(s); recrawl-queued {requeued}; scope={label}; workers={}",
            a.workers
        );
    } else {
        println!("seeded {added} URL(s); recrawl-queued {requeued}; scope={label}");
    }

    let t0 = Instant::now();
    let stats = crawler.run(None).await;
    // Finalise the ranking signals (incoming counts, PageRank, host authority),
    // then persist the whole index — mirrors the Python `index.finalize` + commit.
    crawler.index_mut().finalize();
    let blob = crawler.index().snapshot();
    if let Err(e) = std::fs::write(&a.db, &blob) {
        eprintln!("error: cannot write index to {}: {e}", a.db);
        return ExitCode::from(1);
    }
    let dt = t0.elapsed().as_secs_f64();
    println!("crawl done in {dt:.1}s: {}", fmt_stats(&stats));
    let st = crawler.index().stats();
    println!(
        "indexed {} docs across {} host(s) -> {}",
        st.docs, st.hosts, a.db
    );
    ExitCode::from(0)
}

async fn run_serve(a: ServeArgs) -> ExitCode {
    if !Path::new(&a.db).exists() {
        eprintln!("note: {} does not exist; serving an empty index", a.db);
    }
    let index = match read_index(&a.db) {
        Ok(ix) => ix,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let doc_count = index.doc_count();
    let base = a
        .base_url
        .clone()
        .unwrap_or_else(|| format!("http://{}:{}", a.host, a.port));
    // `new`, not `with_frontier`: there is no frontier to pass. `--db` is an
    // index snapshot and that format stores documents only, so `/about` here
    // omits the Frontier table (see the module docs).
    let server = Arc::new(SearchServer::new(
        Arc::new(Mutex::new(index)),
        base.as_str(),
    ));
    let addr = format!("{}:{}", a.host, a.port);
    let listener = match TcpListener::bind(&addr).await {
        Ok(l) => l,
        Err(e) => {
            eprintln!("error: cannot bind {addr}: {e}");
            return ExitCode::from(1);
        }
    };
    println!(
        "serving {doc_count} docs on http://{}:{}  (db={}, base-url={base})",
        a.host, a.port, a.db
    );
    if a.verbose {
        eprintln!("serve: bound to {addr}");
    }
    match serve(listener, server).await {
        Ok(()) => ExitCode::from(0),
        Err(e) => {
            eprintln!("error: server stopped: {e}");
            ExitCode::from(1)
        }
    }
}

fn run_stats(a: &StatsArgs) -> ExitCode {
    if !Path::new(&a.db).exists() {
        eprintln!("note: {} does not exist; reporting an empty index", a.db);
    }
    let index = match read_index(&a.db) {
        Ok(ix) => ix,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let st = index.stats();
    println!("documents : {}", st.docs);
    println!("hosts     : {}", st.hosts);
    println!("link edges: {}", st.links);
    if let Some(newest) = st.newest {
        let oldest = st.oldest.unwrap_or(newest);
        println!("fetched   : {} .. {}", fmt_date(oldest), fmt_date(newest));
    }
    if !st.top_hosts.is_empty() {
        println!("top hosts :");
        for (host, n) in &st.top_hosts {
            println!("    {n:>6}  {host}");
        }
    }
    if !st.languages.is_empty() {
        let langs: Vec<String> = st
            .languages
            .iter()
            .map(|(l, n)| format!("{l}={n}"))
            .collect();
        println!("languages : {}", langs.join(", "));
    }
    ExitCode::from(0)
}

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
    let index = match read_index(&a.db) {
        Ok(ix) => ix,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(1);
        }
    };
    let blob = index.snapshot();
    if let Err(e) = std::fs::write(&a.out, &blob) {
        eprintln!("error: backup failed: {e}");
        return ExitCode::from(1);
    }
    println!("backup: wrote {} ({} documents)", a.out, index.doc_count());
    ExitCode::from(0)
}

// ---------------------------------------------------------------------------
// Help text
// ---------------------------------------------------------------------------

fn top_help() -> String {
    format!(
        "\
{PROG} — zero-dependency clearnet search engine (crawler + index + ranking + no-JS UI).

usage: {PROG} <command> [options]

commands:
  crawl    crawl seeds into an index snapshot database
  serve    serve the no-JS search UI + JSON API
  stats    print index statistics
  backup   write a fresh snapshot of the index database

Run `{PROG} <command> --help` for per-command options.
"
    )
}

fn crawl_help() -> String {
    format!(
        "\
usage: {PROG} crawl [SEED_URL ...] [options]

crawl seeds into an index snapshot database.

options:
  --seeds FILE            file of seed URLs (one per line; '#' comments)
  --db PATH               snapshot database path (default: web.db)
  --scope-domain DOMAIN   restrict the crawl to this domain (repeatable)
  --broad                 crawl broadly (ignore seed-host scoping)
  --max-depth N           maximum crawl depth (default: 6)
  --max-pages N           total page budget (default: 2000)
  --per-host-budget N     per-host page budget (default: 500)
  --max-bytes N           response body byte cap (default: 2000000)
  --delay SECONDS         base per-host politeness delay (default: 0.5)
  --jitter SECONDS        extra random politeness delay (default: 0.3)
  --timeout SECONDS       per-connection timeout (default: 10.0)
  --user-agent UA         crawler User-Agent
  --no-robots             do not fetch/honour robots.txt (impolite)
  --allow-host HOST       exempt host[:port] from the internal-IP SSRF denylist (repeatable)
  --allow-internal-ips    disable the internal-IP crawl denylist entirely (DANGEROUS)
  --workers N             parallel crawl workers (default: 1)
  --keep-alive            reuse HTTP connections (still SSRF-checked per hop)
  --index-pdf             index application/pdf via best-effort text extraction
  --recrawl               also re-queue indexed URLs due for a recrawl (loads
                          --db first, so the run refreshes that index in place;
                          may be used with no seeds at all)
  --recrawl-interval SEC  recrawl age threshold in seconds (default 604800 = 7 days)
  --shard-id ID           this node's shard id (fleet mode)
  --shards IDS            comma-separated set of ALL shard ids
  --verbose               log the resolved crawl config to stderr
"
    )
}

fn serve_help() -> String {
    format!(
        "\
usage: {PROG} serve [options]

serve the no-JS search UI + JSON API over the restored index.

options:
  --db PATH        snapshot database path (default: web.db)
  --host HOST      bind address (default: 127.0.0.1)
  --port PORT      bind port (default: 8803)
  --base-url URL   self-describing base URL (default: http://<host>:<port>)
  --verbose        log the bound address to stderr
"
    )
}

fn stats_help() -> String {
    format!(
        "\
usage: {PROG} stats [options]

print index statistics.

options:
  --db PATH   snapshot database path (default: web.db)
"
    )
}

fn backup_help() -> String {
    format!(
        "\
usage: {PROG} backup --out DEST [options]

write a fresh snapshot of the index database to a new local file.

options:
  --db PATH    source snapshot database (default: web.db)
  --out DEST   destination path (local file; must not already exist) [required]
"
    )
}

// ---------------------------------------------------------------------------
// Tests — the pure wiring: arg parsing, config building, seed/shard helpers,
// and the persistence round-trip through a file. No sockets, no network.
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;
    use websearch::index::DocFields;

    fn argv(parts: &[&str]) -> Vec<String> {
        parts.iter().map(|s| (*s).to_string()).collect()
    }

    fn crawl_of(parts: &[&str]) -> CrawlArgs {
        match parse_args(&argv(parts)) {
            Ok(Command::Crawl(a)) => a,
            other => panic!("expected crawl, got {other:?}"),
        }
    }

    #[test]
    fn crawl_defaults_match_python() {
        let a = crawl_of(&["crawl", "http://example.com"]);
        assert_eq!(a.seeds, vec!["http://example.com".to_string()]);
        assert_eq!(a.seeds_file, None);
        assert_eq!(a.db, "web.db");
        assert!(a.scope_domain.is_empty());
        assert!(!a.broad);
        assert_eq!(a.max_depth, 6);
        assert_eq!(a.max_pages, 2000);
        assert_eq!(a.per_host_budget, 500);
        assert_eq!(a.max_bytes, 2_000_000);
        assert_eq!(a.delay, 0.5);
        assert_eq!(a.jitter, 0.3);
        assert_eq!(a.timeout, 10.0);
        assert_eq!(a.user_agent, CrawlConfig::default().user_agent);
        assert!(!a.no_robots);
        assert!(a.allow_host.is_empty());
        assert!(!a.allow_internal_ips);
        assert_eq!(a.workers, 1);
        assert!(!a.keep_alive);
        assert!(!a.index_pdf);
        assert!(!a.recrawl);
        assert_eq!(a.recrawl_interval, 7.0 * 86_400.0); // Python `7 * 86400.0`
        assert_eq!(a.shard_id, None);
        assert_eq!(a.shards, None);
    }

    #[test]
    fn crawl_parses_all_flags_and_repeatables() {
        let a = crawl_of(&[
            "crawl",
            "http://a.example/",
            "http://b.example/",
            "--db",
            "out.db",
            "--scope-domain",
            "a.example",
            "--scope-domain",
            "b.example",
            "--max-depth",
            "3",
            "--max-pages",
            "50",
            "--per-host-budget",
            "10",
            "--max-bytes",
            "1234",
            "--delay",
            "0.1",
            "--jitter",
            "0.2",
            "--timeout",
            "4.5",
            "--user-agent",
            "my-bot/1.0",
            "--no-robots",
            "--allow-host",
            "10.0.0.1:8080",
            "--allow-internal-ips",
            "--workers",
            "4",
            "--keep-alive",
            "--index-pdf",
            "--recrawl",
            "--recrawl-interval",
            "3600",
            "--shard-id",
            "s1",
            "--shards",
            "s1,s2,s3",
            "--verbose",
        ]);
        assert_eq!(a.seeds, vec!["http://a.example/", "http://b.example/"]);
        assert_eq!(a.db, "out.db");
        assert_eq!(a.scope_domain, vec!["a.example", "b.example"]);
        assert_eq!(a.max_depth, 3);
        assert_eq!(a.max_pages, 50);
        assert_eq!(a.per_host_budget, 10);
        assert_eq!(a.max_bytes, 1234);
        assert_eq!(a.delay, 0.1);
        assert_eq!(a.jitter, 0.2);
        assert_eq!(a.timeout, 4.5);
        assert_eq!(a.user_agent, "my-bot/1.0");
        assert!(a.no_robots);
        assert_eq!(a.allow_host, vec!["10.0.0.1:8080"]);
        assert!(a.allow_internal_ips);
        assert_eq!(a.workers, 4);
        assert!(a.keep_alive);
        assert!(a.index_pdf);
        assert!(a.recrawl);
        assert_eq!(a.recrawl_interval, 3600.0);
        assert_eq!(a.shard_id, Some("s1".to_string()));
        assert_eq!(a.shards, Some("s1,s2,s3".to_string()));
        assert!(a.verbose);
    }

    /// `--recrawl` / `--recrawl-interval` reach the library: the flag parses in
    /// both forms, the interval lands on the [`CrawlConfig`] the crawl runs with,
    /// and the crawl help documents them. Python `__main__.py:234-237`.
    #[test]
    fn recrawl_flags_reach_the_config() {
        let a = crawl_of(&[
            "crawl",
            "--recrawl",
            "--recrawl-interval=86400",
            "http://x/",
        ]);
        assert!(a.recrawl);
        assert_eq!(a.recrawl_interval, 86_400.0);
        let cfg = build_config(&a, None);
        assert_eq!(cfg.recrawl_interval, 86_400.0);
        // The default flows through untouched when the flag is absent.
        let d = build_config(&crawl_of(&["crawl", "http://x/"]), None);
        assert_eq!(d.recrawl_interval, CrawlConfig::default().recrawl_interval);
        // …and both flags are documented.
        let help = crawl_help();
        assert!(help.contains("--recrawl "), "{help}");
        assert!(help.contains("--recrawl-interval SEC"), "{help}");
        // A bad interval is a usage error, like every other numeric flag.
        assert!(matches!(
            parse_args(&argv(&["crawl", "--recrawl-interval", "soon"])),
            Err(CliError::Usage(_))
        ));
    }

    /// `crawl --recrawl` loads `--db` into the crawler before asking what is due,
    /// so the re-queue has something to work from. Without that load a fresh
    /// crawler's index is empty and NOTHING is ever due — the reason this path
    /// restores the snapshot where a plain `crawl` does not.
    #[test]
    fn recrawl_requeues_from_the_restored_snapshot() {
        let dir = std::env::temp_dir().join(format!("websearch-recrawl-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let db = dir.join("web.db");

        let mut seeded = Index::new();
        seeded.upsert_document(
            "http://a.example/old",
            DocFields {
                title: "t",
                body: "b",
                host: "a.example",
                fetched_at: 1_000.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
        std::fs::write(&db, seeded.snapshot()).unwrap();

        let a = crawl_of(&["crawl", "--recrawl", "--recrawl-interval=100"]);
        let mut cr = Crawler::new(build_config(&a, None));
        // Without the restore: nothing is due, because nothing is indexed.
        assert_eq!(cr.enqueue_recrawls(None, 2_000.0), 0);
        // With it (what `run_crawl` does for `--recrawl`): the stored doc is due.
        *cr.index_mut() = read_index(db.to_str().unwrap()).unwrap();
        assert_eq!(cr.enqueue_recrawls(None, 2_000.0), 1);
        assert!(cr.frontier().seen("http://a.example/old"));

        std::fs::remove_dir_all(&dir).ok();
    }

    /// `--recrawl` makes seeds optional (Python `if not seeds and not
    /// args.recrawl`), so `crawl --recrawl` with no URLs at all is a valid
    /// invocation rather than the "no seeds given" usage error.
    #[test]
    fn recrawl_parses_without_seeds() {
        let a = crawl_of(&["crawl", "--recrawl"]);
        assert!(a.seeds.is_empty());
        assert!(a.recrawl);
        // No seeds and no --recrawl is still an error at run time; the scope of a
        // seedless run is BROAD, as in the Python.
        assert_eq!(compute_scope(&a, &[]), None);
    }

    #[test]
    fn crawl_accepts_equals_form() {
        let a = crawl_of(&["crawl", "--db=web2.db", "--max-pages=7", "http://x/"]);
        assert_eq!(a.db, "web2.db");
        assert_eq!(a.max_pages, 7);
        assert_eq!(a.seeds, vec!["http://x/"]);
    }

    #[test]
    fn serve_and_stats_and_backup_parse() {
        let s = match parse_args(&argv(&[
            "serve",
            "--db",
            "w.db",
            "--host",
            "0.0.0.0",
            "--port",
            "9001",
            "--base-url",
            "http://pub.example",
        ])) {
            Ok(Command::Serve(s)) => s,
            other => panic!("expected serve, got {other:?}"),
        };
        assert_eq!(s.db, "w.db");
        assert_eq!(s.host, "0.0.0.0");
        assert_eq!(s.port, 9001);
        assert_eq!(s.base_url, Some("http://pub.example".to_string()));

        let st = match parse_args(&argv(&["stats", "--db", "s.db"])) {
            Ok(Command::Stats(s)) => s,
            other => panic!("expected stats, got {other:?}"),
        };
        assert_eq!(st.db, "s.db");

        let bk = match parse_args(&argv(&["backup", "--db", "src.db", "--out", "dst.db"])) {
            Ok(Command::Backup(b)) => b,
            other => panic!("expected backup, got {other:?}"),
        };
        assert_eq!(bk.db, "src.db");
        assert_eq!(bk.out, "dst.db");
    }

    #[test]
    fn serve_defaults() {
        let s = match parse_args(&argv(&["serve"])) {
            Ok(Command::Serve(s)) => s,
            other => panic!("expected serve, got {other:?}"),
        };
        assert_eq!(s.db, "web.db");
        assert_eq!(s.host, "127.0.0.1");
        assert_eq!(s.port, 8803);
        assert_eq!(s.base_url, None);
    }

    #[test]
    fn parse_errors_and_help() {
        // no subcommand -> usage error
        assert!(matches!(parse_args(&argv(&[])), Err(CliError::Usage(_))));
        // unknown command -> usage error
        assert!(matches!(
            parse_args(&argv(&["frobnicate"])),
            Err(CliError::Usage(_))
        ));
        // top-level help
        assert!(matches!(
            parse_args(&argv(&["--help"])),
            Err(CliError::Help(_))
        ));
        // per-command help
        assert!(matches!(
            parse_args(&argv(&["crawl", "--help"])),
            Err(CliError::Help(_))
        ));
        // unknown option
        assert!(matches!(
            parse_args(&argv(&["crawl", "--nope"])),
            Err(CliError::Usage(_))
        ));
        // missing value
        assert!(matches!(
            parse_args(&argv(&["crawl", "--db"])),
            Err(CliError::Usage(_))
        ));
        // bad number
        assert!(matches!(
            parse_args(&argv(&["crawl", "--max-pages", "lots"])),
            Err(CliError::Usage(_))
        ));
        // backup requires --out
        assert!(matches!(
            parse_args(&argv(&["backup", "--db", "x"])),
            Err(CliError::Usage(_))
        ));
        // stats rejects stray positionals
        assert!(matches!(
            parse_args(&argv(&["stats", "junk"])),
            Err(CliError::Usage(_))
        ));
    }

    #[test]
    fn parse_shards_drops_blanks() {
        assert!(parse_shards(None).is_empty());
        assert!(parse_shards(Some("")).is_empty());
        assert!(parse_shards(Some("  , ,")).is_empty());
        assert_eq!(
            parse_shards(Some(" a , b ,,c ")),
            vec!["a".to_string(), "b".to_string(), "c".to_string()]
        );
    }

    #[test]
    fn seed_lines_strips_comments_and_orders_extra_first() {
        let contents = "  http://one/  \n# a comment\nhttp://two/ # inline\n\n   \n";
        let extra = vec!["http://zero/".to_string()];
        assert_eq!(
            seed_lines(contents, &extra),
            vec!["http://zero/", "http://one/", "http://two/"]
        );
    }

    #[test]
    fn read_seeds_from_a_file_is_hermetic() {
        let dir = std::env::temp_dir();
        let path = dir.join(format!(
            "websearch_seeds_{}_{}.txt",
            std::process::id(),
            line!()
        ));
        std::fs::write(&path, "http://f1/\n#skip\nhttp://f2/\n").unwrap();
        let got = read_seeds(Some(path.to_str().unwrap()), &["http://pos/".to_string()]).unwrap();
        std::fs::remove_file(&path).ok();
        assert_eq!(got, vec!["http://pos/", "http://f1/", "http://f2/"]);
        // No path -> just the positional seeds.
        assert_eq!(
            read_seeds(None, &["http://pos/".to_string()]).unwrap(),
            vec!["http://pos/"]
        );
    }

    #[test]
    fn compute_scope_matches_python_rules() {
        // --broad wins -> no scope.
        let a = crawl_of(&["crawl", "http://a.example/x", "--broad"]);
        assert_eq!(compute_scope(&a, &a.seeds), None);
        // explicit scope-domains.
        let a = crawl_of(&["crawl", "http://a.example/x", "--scope-domain", "z.example"]);
        assert_eq!(
            compute_scope(&a, &a.seeds),
            Some(vec!["z.example".to_string()])
        );
        // derived from seed hosts, sorted + de-duplicated.
        let a = crawl_of(&[
            "crawl",
            "http://b.example/1",
            "http://a.example/2",
            "http://b.example/3",
        ]);
        assert_eq!(
            compute_scope(&a, &a.seeds),
            Some(vec!["a.example".to_string(), "b.example".to_string()])
        );
    }

    #[test]
    fn build_config_maps_flags() {
        let a = crawl_of(&[
            "crawl",
            "http://x/",
            "--no-robots",
            "--allow-internal-ips",
            "--index-pdf",
            "--keep-alive",
            "--workers",
            "3",
            "--timeout",
            "2.5",
            "--max-bytes",
            "99",
            "--shards",
            "s1, s2",
        ]);
        let cfg = build_config(&a, Some(vec!["x".to_string()]));
        assert_eq!(cfg.scope_hosts, Some(vec!["x".to_string()]));
        assert!(!cfg.respect_robots);
        assert!(!cfg.block_internal_ips);
        assert!(cfg.keep_alive);
        assert_eq!(cfg.workers, 3);
        assert_eq!(cfg.timeout, Duration::from_secs_f64(2.5));
        assert_eq!(cfg.max_bytes, 99);
        assert_eq!(cfg.shards, vec!["s1".to_string(), "s2".to_string()]);
        assert!(cfg.content_types.contains(PDF_TYPE));
        // A plain crawl leaves PDF out and robots on.
        let b = crawl_of(&["crawl", "http://x/"]);
        let cfg2 = build_config(&b, None);
        assert!(cfg2.respect_robots);
        assert!(cfg2.block_internal_ips);
        assert!(!cfg2.content_types.contains(PDF_TYPE));
    }

    #[test]
    fn looks_like_uri_matches_scheme_prefix() {
        assert!(looks_like_uri("file:backup.db"));
        assert!(looks_like_uri("http://host/x"));
        assert!(looks_like_uri("a+b.c-d:whatever"));
        assert!(!looks_like_uri("backup.db"));
        assert!(!looks_like_uri("/abs/path/backup.db"));
        assert!(!looks_like_uri("./rel.db"));
        assert!(!looks_like_uri(""));
    }

    #[test]
    fn read_index_roundtrips_through_a_file() {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://a/one",
            DocFields {
                title: "One",
                body: "hello world",
                host: "a",
                lang: "en",
                fetched_at: 1_700_000_000.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
        ix.finalize();
        let path = std::env::temp_dir().join(format!(
            "websearch_snap_{}_{}.db",
            std::process::id(),
            line!()
        ));
        std::fs::write(&path, ix.snapshot()).unwrap();
        let db = path.to_str().unwrap();

        let restored = read_index(db).unwrap();
        assert_eq!(restored.doc_count(), 1);
        assert_eq!(restored.stats(), ix.stats());
        assert!(restored.get_doc("http://a/one").is_some());

        // A corrupt file is a hard error; a missing file is an empty index.
        std::fs::write(&path, b"not a snapshot").unwrap();
        assert!(read_index(db).is_err());
        std::fs::remove_file(&path).ok();
        assert_eq!(read_index(db).unwrap().doc_count(), 0);
    }

    #[test]
    fn fmt_helpers_render() {
        assert_eq!(fmt_date(1_700_000_000.0), "2023-11-14");
        assert_eq!(fmt_date(0.0), "");
        let s = CrawlStats {
            fetched: 5,
            indexed: 4,
            ..CrawlStats::default()
        };
        assert!(fmt_stats(&s).contains("fetched=5"));
        assert!(fmt_stats(&s).contains("indexed=4"));
    }
}
