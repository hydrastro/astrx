//! The `suitedash` command-line entrypoint — a dependency-free port of the
//! Python console script (`legacy-python/suitedash/suitedash/cli.py`).
//!
//! Runs with no arguments (the defaults poll the four astrx-suite services on
//! their standard loopback ports). `--config` loads a TOML file; scalar flags
//! override individual settings; `--service name=url` retargets or adds a
//! service inline; `--check` polls once, prints the `/api/status` JSON to stdout
//! and exits (handy for cron / smoke tests without opening a socket), with a
//! non-zero exit when anything is DOWN so it composes in shell pipelines.
//!
//! Like the Python CLI this is flag-only — there are no subcommands. Argument
//! parsing is hand-rolled in the style of `crates/websearch/src/bin/websearch.rs`
//! (no `clap`, no third-party crate), and the whole binary is gated behind the
//! crate's `net` feature via the `[[bin]]` `required-features`, so the default
//! `suitedash` build stays a pure, zero-dependency library.
//!
//! Exit codes match argparse plus the reference's `--check` contract: `0` success
//! (or `--help`/`--version`), `1` a runtime failure or a DOWN service under
//! `--check`, `2` a usage error. **Documented divergence:** an unreadable or
//! invalid `--config` file is a clean `error: …` on stderr with exit `2`, where
//! CPython lets the `ValueError`/`OSError` escape as a traceback (exit `1`); the
//! `--version` string is this crate's version, not the retiring package's
//! `1.0.0`.
use std::process::ExitCode;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use crate::config::{apply_service_flags, load_config, Config, ConfigError};
use crate::metrics::summarize;
use crate::poller::poll_all;
use crate::render::render_status_json;
use crate::server::serve_config;
use crate::Monitor;

const PROG: &str = "suitedash";

// ---------------------------------------------------------------------------
// Parsed command surface
// ---------------------------------------------------------------------------

/// The parsed command line — every scalar is optional, so "not given" and
/// "given the default value" stay distinguishable exactly as in argparse.
#[derive(Clone, Debug, Default, PartialEq)]
struct Args {
    config: Option<String>,
    host: Option<String>,
    port: Option<i64>,
    refresh: Option<i64>,
    timeout: Option<f64>,
    max_workers: Option<i64>,
    cache_ttl: Option<f64>,
    service: Vec<String>,
    check: bool,
    quiet: bool,
}

/// A parse outcome that is not a runnable command: either text to print on
/// stdout and exit 0 (`--help`, `--version`), or a usage error to print on
/// stderr and exit 2 — mirroring how argparse handles the two.
#[derive(Clone, Debug, PartialEq)]
enum CliError {
    Print(String),
    Usage(String),
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

/// Run the `suitedash` command line and return the process exit code.
///
/// `args` is the argument list **without** `argv[0]`, so the caller decides what
/// the program was invoked as: `src/bin/suitedash.rs` passes
/// `std::env::args().skip(1)`, and the `astrx` multiplexer passes everything
/// after `astrx suitedash`. Both therefore see byte-identical parsing, help text
/// and exit codes — the point of routing them through one function rather than
/// two copies of the parser that drift apart the first time a flag is added.
pub fn run(args: impl Iterator<Item = String>) -> ExitCode {
    // `--log-format` is handled before the subcommand parser so it works on
    // every subcommand of every engine with one call site each, rather than
    // being a flag an operator has to remember which subcommands happen to
    // accept. It is removed from `argv`, so the parser below sees exactly the
    // command line it saw before this flag existed.
    let (log_format, argv) = match crawlcore::logfmt::take_format_flag(args.collect()) {
        Ok(v) => v,
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(2);
        }
    };
    crawlcore::logfmt::set_format(log_format);
    // Force the metrics registry into existence NOW, at startup, rather than
    // lazily on the first request: its `uptime_seconds` is measured from
    // construction, so leaving it to `OnceLock` made the gauge mean "seconds
    // since someone first asked for something" — a server that had been up for
    // a week but idle overnight reported an uptime of seconds, which reads
    // exactly like a crash-loop.
    let _ = crate::exporter::registry();
    let args = match parse_args(&argv) {
        Ok(a) => a,
        Err(CliError::Print(text)) => {
            print!("{text}");
            return ExitCode::from(0);
        }
        Err(CliError::Usage(msg)) => {
            eprintln!("{msg}");
            return ExitCode::from(2);
        }
    };
    let config = match config_from_args(&args) {
        Ok(cfg) => cfg,
        Err(msg) => {
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
    rt.block_on(dispatch(config, args.check))
}

async fn dispatch(config: Config, check: bool) -> ExitCode {
    if check {
        return run_check(config).await;
    }
    match serve_config(config).await {
        Ok(()) => ExitCode::from(0),
        // The bind failure carries its own address context; an accept() failure
        // is reported as-is (both stop the server).
        Err(e) => {
            eprintln!("error: {e}");
            ExitCode::from(1)
        }
    }
}

/// `--check`: one sweep, the `/api/status` JSON on stdout, exit 1 if anything is
/// DOWN. The sweep goes through a [`Monitor`] so the JSON carries alert state
/// too (rules with `for_polls = 1`, and any down-detection, evaluate at once).
async fn run_check(config: Config) -> ExitCode {
    let timeout = probe_timeout(&config);
    let results = poll_all(&config.services, timeout, 0).await;
    let monitor = Monitor::new(&config);
    let now = now_secs();
    monitor.ingest(&results, now);
    println!(
        "{}",
        render_status_json(&results, Some(&monitor.snapshot()), now)
    );
    if summarize(&results).all_up {
        ExitCode::from(0)
    } else {
        ExitCode::from(1)
    }
}

/// Epoch seconds (Python `time.time()`).
fn now_secs() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0.0, |d| d.as_secs_f64())
}

/// The per-service probe budget, clamped so a hostile config value cannot panic
/// [`Duration::from_secs_f64`] (the same clamp the server applies).
fn probe_timeout(config: &Config) -> Duration {
    let secs = if config.timeout_seconds.is_finite() {
        config.timeout_seconds.clamp(0.0, 86_400.0)
    } else {
        0.0
    };
    Duration::from_secs_f64(secs)
}

// ---------------------------------------------------------------------------
// Argument parsing (hand-rolled, dependency-free)
// ---------------------------------------------------------------------------

/// Split any `--flag=value` token into two (`--flag`, `value`) so the walker only
/// ever sees `--flag [value]`.
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

fn parse_f64(s: &str, flag: &str) -> Result<f64, CliError> {
    s.parse::<f64>()
        .map_err(|_| CliError::Usage(format!("error: {flag} expects a number, got {s:?}")))
}

/// Walk the command line into [`Args`].
fn parse_args(argv: &[String]) -> Result<Args, CliError> {
    let toks = normalize(argv);
    let mut a = Args::default();
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Err(CliError::Print(help())),
            // argparse's `version` action: print and exit 0. The version is this
            // crate's, not the retiring Python package's 1.0.0.
            "--version" => {
                return Err(CliError::Print(format!(
                    "{PROG} {}\n",
                    env!("CARGO_PKG_VERSION")
                )))
            }
            "--config" => {
                i += 1;
                a.config = Some(need(&toks, i, "--config")?.to_string());
            }
            "--host" => {
                i += 1;
                a.host = Some(need(&toks, i, "--host")?.to_string());
            }
            "--port" => {
                i += 1;
                a.port = Some(parse_i64(need(&toks, i, "--port")?, "--port")?);
            }
            "--refresh" => {
                i += 1;
                a.refresh = Some(parse_i64(need(&toks, i, "--refresh")?, "--refresh")?);
            }
            "--timeout" => {
                i += 1;
                a.timeout = Some(parse_f64(need(&toks, i, "--timeout")?, "--timeout")?);
            }
            "--max-workers" => {
                i += 1;
                a.max_workers = Some(parse_i64(
                    need(&toks, i, "--max-workers")?,
                    "--max-workers",
                )?);
            }
            "--cache-ttl" => {
                i += 1;
                a.cache_ttl = Some(parse_f64(need(&toks, i, "--cache-ttl")?, "--cache-ttl")?);
            }
            "--service" => {
                i += 1;
                a.service.push(need(&toks, i, "--service")?.to_string());
            }
            "--check" => a.check = true,
            "-q" | "--quiet" => a.quiet = true,
            s if s.starts_with('-') && s != "-" => {
                return Err(CliError::Usage(format!(
                    "error: unrecognized option {s} (try `{PROG} --help`)"
                )))
            }
            other => {
                return Err(CliError::Usage(format!(
                    "error: unexpected argument {other:?}"
                )))
            }
        }
        i += 1;
    }
    Ok(a)
}

// ---------------------------------------------------------------------------
// Config assembly (the port of Python `config_from_args`)
// ---------------------------------------------------------------------------

/// Overlay the parsed flags on `base` — the pure half of [`config_from_args`]
/// (no filesystem access), so the clamps are unit-testable.
///
/// # Errors
/// [`ConfigError`] when a `--service` spec is not `name=base_url`.
fn apply_overrides(mut cfg: Config, args: &Args) -> Result<Config, ConfigError> {
    if let Some(host) = &args.host {
        cfg.host = host.clone();
    }
    if let Some(port) = args.port {
        cfg.port = port;
    }
    if let Some(refresh) = args.refresh {
        cfg.refresh_seconds = refresh;
    }
    if let Some(timeout) = args.timeout {
        cfg.timeout_seconds = timeout.max(0.1);
    }
    if let Some(workers) = args.max_workers {
        cfg.max_workers = workers.max(1);
    }
    if let Some(ttl) = args.cache_ttl {
        cfg.cache_ttl = ttl.max(0.0);
    }
    if !args.service.is_empty() {
        cfg = apply_service_flags(cfg, &args.service)?;
    }
    cfg.verbose = !args.quiet;
    Ok(cfg)
}

/// The full `config_from_args`: the optional TOML file overlaid on the defaults,
/// then the CLI overrides.
fn config_from_args(args: &Args) -> Result<Config, String> {
    let base = load_config(args.config.as_deref(), Some(Config::default()))
        .map_err(|e| format!("error: {e}"))?;
    apply_overrides(base, args).map_err(|e| format!("error: {e}"))
}

// ---------------------------------------------------------------------------
// Help text
// ---------------------------------------------------------------------------

fn help() -> String {
    format!(
        "\
usage: {PROG} [options]

Zero-dependency, no-JavaScript ops/status dashboard for the astrx-suite.

options:
  --config PATH        Path to a TOML config file (overrides defaults).
  --host HOST          Address to bind (default: 127.0.0.1, loopback only).
  --port PORT          TCP port for the dashboard (default: 8805).
  --refresh SECONDS    Auto-refresh interval in seconds; <=0 disables it (default: 15).
  --timeout SECONDS    Per-service probe timeout in seconds (default: 3.0).
  --max-workers N      Max concurrent inbound connections (default: 16).
  --cache-ttl SECONDS  Seconds to cache a poll snapshot (0 = always fresh, default).
  --service NAME=URL   Retarget or add a service, e.g. gitweb=http://127.0.0.1:8801. Repeatable.
  --check              Poll every service once, print /api/status JSON, and exit.
  -q, --quiet          Suppress request logging.
  --log-format FMT     Log as 'text' (default, human) or 'json' (one object per line).
  --version            Print the version and exit.
  -h, --help           Show this help and exit.
"
    )
}

// ---------------------------------------------------------------------------
// Tests — the pure wiring: argument parsing and config assembly. No sockets.
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;

    fn argv(parts: &[&str]) -> Vec<String> {
        parts.iter().map(|s| (*s).to_string()).collect()
    }

    fn parse(parts: &[&str]) -> Args {
        parse_args(&argv(parts)).expect("parses")
    }

    #[test]
    fn no_arguments_leaves_every_setting_at_its_default() {
        assert_eq!(parse(&[]), Args::default());
        let cfg = apply_overrides(Config::default(), &Args::default()).unwrap();
        assert_eq!(cfg, Config::default()); // verbose defaults to true
    }

    #[test]
    fn every_flag_is_parsed_in_both_spellings() {
        let spaced = parse(&[
            "--config",
            "d.toml",
            "--host",
            "0.0.0.0",
            "--port",
            "9000",
            "--refresh",
            "0",
            "--timeout",
            "1.5",
            "--max-workers",
            "4",
            "--cache-ttl",
            "2.5",
            "--service",
            "a=http://x:1",
            "--service",
            "b=http://y:2",
            "--check",
            "-q",
        ]);
        assert_eq!(spaced.config.as_deref(), Some("d.toml"));
        assert_eq!(spaced.host.as_deref(), Some("0.0.0.0"));
        assert_eq!(spaced.port, Some(9000));
        assert_eq!(spaced.refresh, Some(0));
        assert_eq!(spaced.timeout, Some(1.5));
        assert_eq!(spaced.max_workers, Some(4));
        assert_eq!(spaced.cache_ttl, Some(2.5));
        assert_eq!(spaced.service, vec!["a=http://x:1", "b=http://y:2"]);
        assert!(spaced.check && spaced.quiet);

        let joined = parse(&[
            "--config=d.toml",
            "--host=0.0.0.0",
            "--port=9000",
            "--refresh=0",
            "--timeout=1.5",
            "--max-workers=4",
            "--cache-ttl=2.5",
            "--service=a=http://x:1",
            "--service=b=http://y:2",
            "--check",
            "--quiet",
        ]);
        assert_eq!(joined, spaced);
    }

    #[test]
    fn help_and_version_print_and_exit_zero() {
        for flag in ["-h", "--help"] {
            match parse_args(&argv(&[flag])) {
                Err(CliError::Print(text)) => assert!(text.starts_with("usage: suitedash")),
                other => panic!("expected help, got {other:?}"),
            }
        }
        match parse_args(&argv(&["--version"])) {
            Err(CliError::Print(text)) => {
                assert_eq!(text, format!("suitedash {}\n", env!("CARGO_PKG_VERSION")));
            }
            other => panic!("expected version, got {other:?}"),
        }
    }

    #[test]
    fn usage_errors_are_reported_not_guessed() {
        for bad in [
            vec!["--port"],             // missing value
            vec!["--port", "eight"],    // not an integer
            vec!["--timeout", "quick"], // not a number
            vec!["--nope"],             // unknown option
            vec!["-x"],                 // unknown short option
            vec!["stray"],              // unexpected positional
            vec!["--service"],          // missing value
        ] {
            assert!(
                matches!(parse_args(&argv(&bad)), Err(CliError::Usage(_))),
                "expected a usage error for {bad:?}"
            );
        }
    }

    #[test]
    fn overrides_are_clamped_like_the_python_cli() {
        let args = Args {
            timeout: Some(0.0),
            max_workers: Some(0),
            cache_ttl: Some(-2.0),
            refresh: Some(-1),
            port: Some(0),
            host: Some("0.0.0.0".to_string()),
            ..Args::default()
        };
        let cfg = apply_overrides(Config::default(), &args).unwrap();
        assert_eq!(cfg.timeout_seconds, 0.1); // max(0.1, timeout)
        assert_eq!(cfg.max_workers, 1); // max(1, max_workers)
        assert_eq!(cfg.cache_ttl, 0.0); // max(0.0, cache_ttl)
        assert_eq!(cfg.refresh_seconds, -1); // <=0 simply disables the meta-refresh
        assert_eq!(cfg.port, 0);
        assert_eq!(cfg.host, "0.0.0.0");
    }

    #[test]
    fn quiet_flips_verbose_off() {
        let loud = apply_overrides(Config::default(), &Args::default()).unwrap();
        assert!(loud.verbose);
        let quiet = apply_overrides(
            Config::default(),
            &Args {
                quiet: true,
                ..Args::default()
            },
        )
        .unwrap();
        assert!(!quiet.verbose);
    }

    #[test]
    fn service_flags_retarget_and_append() {
        let args = Args {
            service: vec![
                "gitweb=http://10.0.0.5:8801/".to_string(),
                "newsvc=http://h:9".to_string(),
            ],
            ..Args::default()
        };
        let cfg = apply_overrides(Config::default(), &args).unwrap();
        assert_eq!(cfg.services[0].base_url, "http://10.0.0.5:8801");
        assert_eq!(cfg.services.len(), 5);
        assert_eq!(cfg.services[4].name, "newsvc");

        let bad = Args {
            service: vec!["oops".to_string()],
            ..Args::default()
        };
        assert!(apply_overrides(Config::default(), &bad).is_err());
    }

    #[test]
    fn a_missing_config_file_is_a_clean_error() {
        let args = Args {
            config: Some("/nonexistent/suitedash-test.toml".to_string()),
            ..Args::default()
        };
        let err = config_from_args(&args).unwrap_err();
        assert!(err.starts_with("error: cannot read "), "{err}");
    }

    #[test]
    fn probe_timeout_is_clamped_not_panicking() {
        let cfg = |secs: f64| Config {
            timeout_seconds: secs,
            ..Config::default()
        };
        assert_eq!(probe_timeout(&cfg(3.0)), Duration::from_secs_f64(3.0));
        assert_eq!(probe_timeout(&cfg(-1.0)), Duration::ZERO);
        assert_eq!(probe_timeout(&cfg(f64::NAN)), Duration::ZERO);
        assert_eq!(probe_timeout(&cfg(1e308)), Duration::from_secs(86_400));
    }
}
