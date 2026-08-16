//! The `gitweb` command-line entrypoint — a dependency-free port of the Python
//! `python3 -m gitweb` CLI (`legacy-python/gitweb/gitweb/__main__.py`).
//!
//! `--root DIR` is the one required flag: it names the directory that *directly*
//! contains the repositories to serve. Everything else tunes the read-only
//! server — page sizes, streaming caps, the reverse-proxy mount prefix, the
//! Git Smart-HTTP clone transport, and the optional HTTP Basic gate.
//! `--hash-password USER` is the credential-generation helper: it prompts, prints
//! a `USER:sha256$salt$hex` line for `--auth`/`--auth-file`, and exits without
//! starting a server.
//!
//! Argument parsing is hand-rolled in the style of
//! `crates/websearch/src/bin/websearch.rs` (no `clap`, no third-party crate), and
//! the whole binary is gated behind the crate's `net` feature via the `[[bin]]`
//! `required-features`, so the default `gitweb` build stays a pure,
//! zero-dependency library.
//!
//! Exit codes match argparse: `0` success (or `--help`/`--version`), `1` a
//! runtime failure, `2` a usage error (including a missing `--root` and a
//! mistyped password).
//!
//! # Documented divergences
//!
//! * **`--highlight` is accepted and is a no-op.** In Python it enables optional
//!   Pygments syntax highlighting, falling back to escaped plaintext when
//!   Pygments is absent — which is always the case in this zero-dependency
//!   deployment, and is also the reference's own default. The flag is kept so a
//!   deployment's command line ports across unchanged.
//! * **`--port` is validated here**, not at bind time: a value outside
//!   `0..=65535` is a usage error rather than a runtime one.
//! * **Password echo.** CPython uses `getpass`, which needs `termios`. This
//!   binary asks `stty` (argv-only, never a shell) to turn echo off and restores
//!   it afterwards; if `stty` is unavailable the password is read with echo on
//!   and a warning is printed, rather than failing.
use std::io::{BufRead, Write};
use std::process::{Command, ExitCode, Stdio};

use crate::auth::hash_password;
use crate::server::{serve_config, Config};

const PROG: &str = "gitweb";

// ---------------------------------------------------------------------------
// Parsed command surface
// ---------------------------------------------------------------------------

/// The parsed command line. Every scalar carries the reference's default, so
/// "not given" and "given the default" are indistinguishable — exactly as
/// argparse leaves them.
#[derive(Clone, Debug, PartialEq)]
struct Args {
    root: Option<String>,
    host: String,
    port: i64,
    page_size: i64,
    max_blob_mb: f64,
    raw_max_mb: f64,
    archive_max_mb: f64,
    tree_page_size: i64,
    max_workers: i64,
    socket_timeout: f64,
    url_prefix: String,
    patches_dir: String,
    highlight: bool,
    enable_clone: bool,
    clone_timeout: f64,
    clone_max_body_mb: f64,
    clone_max_concurrency: i64,
    clone_base_url: String,
    auth: String,
    auth_file: String,
    hash_password: Option<String>,
    quiet: bool,
}

impl Default for Args {
    fn default() -> Self {
        Args {
            root: None,
            host: "127.0.0.1".to_string(),
            port: 8801,
            page_size: 50,
            max_blob_mb: 2.0,
            raw_max_mb: 50.0,
            archive_max_mb: 200.0,
            tree_page_size: 500,
            max_workers: 32,
            socket_timeout: 30.0,
            url_prefix: String::new(),
            patches_dir: String::new(),
            highlight: false,
            enable_clone: true,
            clone_timeout: 120.0,
            clone_max_body_mb: 25.0,
            clone_max_concurrency: 4,
            clone_base_url: String::new(),
            auth: String::new(),
            auth_file: String::new(),
            hash_password: None,
            quiet: false,
        }
    }
}

/// A parse outcome that is not a runnable command: text for stdout with exit 0
/// (`--help`, `--version`), or a usage error for stderr with exit 2 — mirroring
/// how argparse handles the two.
#[derive(Clone, Debug, PartialEq)]
enum CliError {
    Print(String),
    Usage(String),
}

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

/// Run the `gitweb` command line and return the process exit code.
///
/// `args` is the argument list **without** `argv[0]`, so the caller decides what
/// the program was invoked as: `src/bin/gitweb.rs` passes
/// `std::env::args().skip(1)`, and the `astrx` multiplexer passes everything
/// after `astrx gitweb`. Both therefore see byte-identical parsing, help text
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
    let _ = crate::metrics::registry();
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

    // Credential-generation helper: prompt, print 'user:sha256$salt$hex', exit.
    if let Some(user) = &args.hash_password {
        return hash_password_command(user);
    }

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
    match rt.block_on(serve_config(config)) {
        Ok(()) => ExitCode::from(0),
        Err(e) => {
            eprintln!("error: {e}");
            ExitCode::from(1)
        }
    }
}

// ---------------------------------------------------------------------------
// `--hash-password`
// ---------------------------------------------------------------------------

/// Ask `stty` to toggle terminal echo. Best effort: a missing/failing `stty`
/// simply leaves echo as it was (and the caller warns).
fn set_echo(on: bool) -> bool {
    Command::new("stty")
        .arg(if on { "echo" } else { "-echo" })
        .stdin(Stdio::inherit())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
}

/// Prompt on stderr and read one line from stdin, with echo off if possible.
fn read_password(prompt: &str, quiet_echo: bool) -> Option<String> {
    eprint!("{prompt}");
    let _ = std::io::stderr().flush();
    let mut line = String::new();
    let read = std::io::stdin().lock().read_line(&mut line).ok()?;
    if !quiet_echo {
        eprintln!();
    }
    if read == 0 && line.is_empty() {
        return None;
    }
    Some(line.trim_end_matches(['\n', '\r']).to_string())
}

fn hash_password_command(user: &str) -> ExitCode {
    let echo_off = set_echo(false);
    if !echo_off {
        eprintln!("warning: could not disable terminal echo (no usable stty); the password will be visible");
    }
    let first = read_password("Password: ", !echo_off);
    let second = read_password("Repeat password: ", !echo_off);
    if echo_off {
        set_echo(true);
    }
    let (Some(first), Some(second)) = (first, second) else {
        eprintln!("error: could not read the password");
        return ExitCode::from(2);
    };
    if first != second {
        eprintln!("passwords do not match");
        return ExitCode::from(2);
    }
    if first.is_empty() {
        eprintln!("password must not be empty");
        return ExitCode::from(2);
    }
    let mut salt = [0u8; 16];
    if let Err(e) = getrandom::getrandom(&mut salt) {
        eprintln!("error: no entropy source for the salt: {e}");
        return ExitCode::from(1);
    }
    let salt_hex: String = salt.iter().map(|b| format!("{b:02x}")).collect();
    println!("{user}:{}", hash_password(&first, &salt_hex));
    ExitCode::from(0)
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
            "--version" => {
                return Err(CliError::Print(format!(
                    "{PROG} {}\n",
                    env!("CARGO_PKG_VERSION")
                )))
            }
            "--root" => {
                i += 1;
                a.root = Some(need(&toks, i, "--root")?.to_string());
            }
            "--host" => {
                i += 1;
                a.host = need(&toks, i, "--host")?.to_string();
            }
            "--port" => {
                i += 1;
                a.port = parse_i64(need(&toks, i, "--port")?, "--port")?;
            }
            "--page-size" => {
                i += 1;
                a.page_size = parse_i64(need(&toks, i, "--page-size")?, "--page-size")?;
            }
            "--max-blob-mb" => {
                i += 1;
                a.max_blob_mb = parse_f64(need(&toks, i, "--max-blob-mb")?, "--max-blob-mb")?;
            }
            "--raw-max-mb" => {
                i += 1;
                a.raw_max_mb = parse_f64(need(&toks, i, "--raw-max-mb")?, "--raw-max-mb")?;
            }
            "--archive-max-mb" => {
                i += 1;
                a.archive_max_mb =
                    parse_f64(need(&toks, i, "--archive-max-mb")?, "--archive-max-mb")?;
            }
            "--tree-page-size" => {
                i += 1;
                a.tree_page_size =
                    parse_i64(need(&toks, i, "--tree-page-size")?, "--tree-page-size")?;
            }
            "--max-workers" => {
                i += 1;
                a.max_workers = parse_i64(need(&toks, i, "--max-workers")?, "--max-workers")?;
            }
            "--socket-timeout" => {
                i += 1;
                a.socket_timeout =
                    parse_f64(need(&toks, i, "--socket-timeout")?, "--socket-timeout")?;
            }
            "--url-prefix" => {
                i += 1;
                a.url_prefix = need(&toks, i, "--url-prefix")?.to_string();
            }
            "--patches-dir" => {
                i += 1;
                a.patches_dir = need(&toks, i, "--patches-dir")?.to_string();
            }
            "--highlight" => a.highlight = true,
            "--enable-clone" => a.enable_clone = true,
            "--no-enable-clone" => a.enable_clone = false,
            "--clone-timeout" => {
                i += 1;
                a.clone_timeout = parse_f64(need(&toks, i, "--clone-timeout")?, "--clone-timeout")?;
            }
            "--clone-max-body-mb" => {
                i += 1;
                a.clone_max_body_mb = parse_f64(
                    need(&toks, i, "--clone-max-body-mb")?,
                    "--clone-max-body-mb",
                )?;
            }
            "--clone-max-concurrency" => {
                i += 1;
                a.clone_max_concurrency = parse_i64(
                    need(&toks, i, "--clone-max-concurrency")?,
                    "--clone-max-concurrency",
                )?;
            }
            "--clone-base-url" => {
                i += 1;
                a.clone_base_url = need(&toks, i, "--clone-base-url")?.to_string();
            }
            "--auth" => {
                i += 1;
                a.auth = need(&toks, i, "--auth")?.to_string();
            }
            "--auth-file" => {
                i += 1;
                a.auth_file = need(&toks, i, "--auth-file")?.to_string();
            }
            "--hash-password" => {
                i += 1;
                a.hash_password = Some(need(&toks, i, "--hash-password")?.to_string());
            }
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
// Config assembly (the port of Python `main`'s Config(...) construction)
// ---------------------------------------------------------------------------

/// Megabytes → bytes, with the reference's `int(x * 1024 * 1024)` truncation and
/// a floor of 0 so a negative flag cannot wrap.
fn mib(value: f64) -> u64 {
    let bytes = value * 1024.0 * 1024.0;
    if bytes.is_finite() && bytes > 0.0 {
        bytes as u64
    } else {
        0
    }
}

/// Build the server configuration from the parsed flags, applying the same
/// clamps the reference does.
fn config_from_args(args: &Args) -> Result<Config, String> {
    let Some(root) = args.root.clone() else {
        return Err(format!(
            "error: --root is required (unless --hash-password is used) (try `{PROG} --help`)"
        ));
    };
    let port = u16::try_from(args.port).map_err(|_| {
        format!(
            "error: --port must be between 0 and 65535, got {}",
            args.port
        )
    })?;
    Ok(Config {
        root: root.into(),
        host: args.host.clone(),
        port,
        page_size: args.page_size.max(1) as usize,
        max_blob_bytes: mib(args.max_blob_mb),
        raw_max_bytes: mib(args.raw_max_mb),
        archive_max_bytes: usize::try_from(mib(args.archive_max_mb)).unwrap_or(usize::MAX),
        tree_page_size: args.tree_page_size.max(1) as usize,
        max_workers: args.max_workers.max(1) as usize,
        socket_timeout: args.socket_timeout.max(1.0),
        url_prefix: args.url_prefix.clone(),
        patches_dir: args.patches_dir.clone(),
        verbose: !args.quiet,
        enable_clone: args.enable_clone,
        clone_timeout: args.clone_timeout.max(1.0),
        clone_max_body_bytes: usize::try_from(mib(args.clone_max_body_mb)).unwrap_or(usize::MAX),
        clone_max_concurrency: args.clone_max_concurrency.max(1) as usize,
        clone_base_url: args.clone_base_url.clone(),
        auth: args.auth.clone(),
        auth_file: args.auth_file.clone(),
        ..Config::default()
    })
}

// ---------------------------------------------------------------------------
// Help text
// ---------------------------------------------------------------------------

fn help() -> String {
    format!(
        "\
usage: {PROG} --root DIR [options]

Read-only, no-JavaScript web git browser (zero dependencies).

options:
  --root DIR                 Directory that directly contains the git repositories to serve.
  --host HOST                Address to bind (default: 127.0.0.1, loopback only).
  --port PORT                TCP port (default: 8801).
  --page-size N              Commits per log page (default: 50).
  --max-blob-mb MB           Max size (MiB) rendered inline in the blob view (default: 2).
  --raw-max-mb MB            Max size (MiB) streamed by the /raw endpoint (default: 50).
  --archive-max-mb MB        Max size (MiB) streamed by the /archive endpoint (default: 200).
  --tree-page-size N         Tree entries shown per page (default: 500).
  --max-workers N            Max concurrent connections handled at once (default: 32).
  --socket-timeout SECONDS   Per-connection socket read timeout (default: 30).
  --url-prefix PATH          Mount under a reverse-proxy sub-path, e.g. /git (default: none).
  --patches-dir DIR          Directory of read-only per-repo patch archives (<name>.mbox),
                             fed by a mailing list / git send-email; renders a Sourcehut-style
                             Patches page. Default: none (the page shows an empty state).
  --highlight                Accepted for CLI parity; a no-op (syntax highlighting is not
                             ported, so the escaped-plaintext fallback is always used).
  --enable-clone             Serve read-only 'git clone'/'git fetch' over HTTP (Git Smart HTTP,
  --no-enable-clone          upload-pack only; push is never served). Default: enabled.
  --clone-timeout SECONDS    Wall-clock timeout for one upload-pack call (default: 120).
  --clone-max-body-mb MB     Max size (MiB) of a clone/fetch POST body, after gzip inflation
                             (default: 25).
  --clone-max-concurrency N  Max concurrent upload-pack RPCs; keep below --max-workers so
                             clones cannot starve browsing (default: 4).
  --clone-base-url URL       External origin (scheme://host[:port]) shown in the 'git clone'
                             command on the repo summary, e.g. an onion address. Defaults to
                             the request Host header.
  --auth SPEC                Enable HTTP Basic access control for the WHOLE server
                             (browse + clone). SPEC is 'user:sha256$salt$hex' (a hashed
                             password, never plaintext). Generate one with --hash-password.
  --auth-file PATH           Read the auth spec from the first non-comment line of this file
                             (keeps it out of the process table).
  --hash-password USER       Prompt for a password, print 'USER:sha256$salt$hex' and exit
                             (does not start the server).
  -q, --quiet                Suppress request logging.
  --log-format FMT           Log as 'text' (default, human) or 'json' (one object per line).
  --version                  Print the version and exit.
  -h, --help                 Show this help and exit.
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
    fn defaults_match_the_python_cli() {
        let a = parse(&["--root", "/srv/git"]);
        assert_eq!(a.root.as_deref(), Some("/srv/git"));
        assert_eq!(
            a,
            Args {
                root: Some("/srv/git".to_string()),
                ..Args::default()
            }
        );
        let cfg = config_from_args(&a).expect("config");
        assert_eq!(cfg.host, "127.0.0.1");
        assert_eq!(cfg.port, 8801);
        assert_eq!(cfg.page_size, 50);
        assert_eq!(cfg.max_blob_bytes, 2 * 1024 * 1024);
        assert_eq!(cfg.raw_max_bytes, 50 * 1024 * 1024);
        assert_eq!(cfg.archive_max_bytes, 200 * 1024 * 1024);
        assert_eq!(cfg.tree_page_size, 500);
        assert_eq!(cfg.max_workers, 32);
        assert_eq!(cfg.socket_timeout, 30.0);
        assert_eq!(cfg.clone_timeout, 120.0);
        assert_eq!(cfg.clone_max_body_bytes, 25 * 1024 * 1024);
        assert_eq!(cfg.clone_max_concurrency, 4);
        assert!(cfg.enable_clone);
        assert!(cfg.verbose);
        assert_eq!(cfg.summary_commits, 10);
        assert_eq!(cfg.feed_commits, 20);
        assert_eq!(cfg.readme_bytes, 512 * 1024);
    }

    #[test]
    fn every_flag_is_parsed_in_both_spellings() {
        let spaced = parse(&[
            "--root",
            "/r",
            "--host",
            "0.0.0.0",
            "--port",
            "9000",
            "--page-size",
            "7",
            "--max-blob-mb",
            "1.5",
            "--raw-max-mb",
            "3",
            "--archive-max-mb",
            "4",
            "--tree-page-size",
            "9",
            "--max-workers",
            "11",
            "--socket-timeout",
            "2.5",
            "--url-prefix",
            "/git",
            "--patches-dir",
            "/p",
            "--highlight",
            "--no-enable-clone",
            "--clone-timeout",
            "13",
            "--clone-max-body-mb",
            "0.5",
            "--clone-max-concurrency",
            "2",
            "--clone-base-url",
            "http://x.onion",
            "--auth",
            "u:sha256$a$b",
            "--auth-file",
            "/f",
            "-q",
        ]);
        let equals = parse(&[
            "--root=/r",
            "--host=0.0.0.0",
            "--port=9000",
            "--page-size=7",
            "--max-blob-mb=1.5",
            "--raw-max-mb=3",
            "--archive-max-mb=4",
            "--tree-page-size=9",
            "--max-workers=11",
            "--socket-timeout=2.5",
            "--url-prefix=/git",
            "--patches-dir=/p",
            "--highlight",
            "--no-enable-clone",
            "--clone-timeout=13",
            "--clone-max-body-mb=0.5",
            "--clone-max-concurrency=2",
            "--clone-base-url=http://x.onion",
            "--auth=u:sha256$a$b",
            "--auth-file=/f",
            "--quiet",
        ]);
        assert_eq!(spaced, equals);
        let cfg = config_from_args(&spaced).expect("config");
        assert_eq!(cfg.host, "0.0.0.0");
        assert_eq!(cfg.port, 9000);
        assert_eq!(cfg.page_size, 7);
        assert_eq!(cfg.max_blob_bytes, 1024 * 1024 + 512 * 1024);
        assert_eq!(cfg.tree_page_size, 9);
        assert_eq!(cfg.url_prefix, "/git");
        assert_eq!(cfg.patches_dir, "/p");
        assert!(!cfg.enable_clone);
        assert_eq!(cfg.clone_max_body_bytes, 512 * 1024);
        assert_eq!(cfg.clone_max_concurrency, 2);
        assert_eq!(cfg.clone_base_url, "http://x.onion");
        assert_eq!(cfg.auth, "u:sha256$a$b");
        assert_eq!(cfg.auth_file, "/f");
        assert!(!cfg.verbose);
    }

    #[test]
    fn clamps_match_the_reference() {
        let a = parse(&[
            "--root",
            "/r",
            "--page-size",
            "0",
            "--tree-page-size",
            "-5",
            "--max-workers",
            "0",
            "--socket-timeout",
            "0.1",
            "--clone-timeout",
            "0",
            "--clone-max-concurrency",
            "0",
        ]);
        let cfg = config_from_args(&a).expect("config");
        assert_eq!(cfg.page_size, 1);
        assert_eq!(cfg.tree_page_size, 1);
        assert_eq!(cfg.max_workers, 1);
        assert_eq!(cfg.socket_timeout, 1.0);
        assert_eq!(cfg.clone_timeout, 1.0);
        assert_eq!(cfg.clone_max_concurrency, 1);
        // A negative size flag floors at 0 rather than wrapping.
        let neg = parse(&["--root", "/r", "--max-blob-mb", "-4"]);
        assert_eq!(config_from_args(&neg).expect("config").max_blob_bytes, 0);
    }

    #[test]
    fn enable_clone_is_a_boolean_optional_pair() {
        assert!(parse(&["--root", "/r"]).enable_clone);
        assert!(!parse(&["--root", "/r", "--no-enable-clone"]).enable_clone);
        assert!(parse(&["--root", "/r", "--no-enable-clone", "--enable-clone"]).enable_clone);
    }

    #[test]
    fn root_is_required_unless_hashing_a_password() {
        let err = config_from_args(&parse(&[])).expect_err("must require --root");
        assert!(err.contains("--root is required"));
        let hashing = parse(&["--hash-password", "carol"]);
        assert_eq!(hashing.hash_password.as_deref(), Some("carol"));
        assert!(hashing.root.is_none());
    }

    #[test]
    fn usage_and_help_errors() {
        assert!(matches!(
            parse_args(&argv(&["--help"])),
            Err(CliError::Print(t)) if t.starts_with("usage: gitweb")
        ));
        assert!(matches!(
            parse_args(&argv(&["--version"])),
            Err(CliError::Print(t)) if t.starts_with("gitweb ")
        ));
        assert!(matches!(
            parse_args(&argv(&["--nope"])),
            Err(CliError::Usage(m)) if m.contains("unrecognized option --nope")
        ));
        assert!(matches!(
            parse_args(&argv(&["extra"])),
            Err(CliError::Usage(m)) if m.contains("unexpected argument")
        ));
        assert!(matches!(
            parse_args(&argv(&["--root"])),
            Err(CliError::Usage(m)) if m.contains("--root requires a value")
        ));
        assert!(matches!(
            parse_args(&argv(&["--port", "x"])),
            Err(CliError::Usage(m)) if m.contains("--port expects an integer")
        ));
        assert!(matches!(
            parse_args(&argv(&["--max-blob-mb", "x"])),
            Err(CliError::Usage(m)) if m.contains("--max-blob-mb expects a number")
        ));
        let bad_port = config_from_args(&parse(&["--root", "/r", "--port", "70000"]));
        assert!(bad_port
            .expect_err("out of range")
            .contains("--port must be"));
    }

    #[test]
    fn help_lists_every_flag_the_parser_accepts() {
        let text = help();
        for flag in [
            "--root",
            "--host",
            "--port",
            "--page-size",
            "--max-blob-mb",
            "--raw-max-mb",
            "--archive-max-mb",
            "--tree-page-size",
            "--max-workers",
            "--socket-timeout",
            "--url-prefix",
            "--patches-dir",
            "--highlight",
            "--enable-clone",
            "--no-enable-clone",
            "--clone-timeout",
            "--clone-max-body-mb",
            "--clone-max-concurrency",
            "--clone-base-url",
            "--auth",
            "--auth-file",
            "--hash-password",
            "--quiet",
            "--version",
            "--help",
        ] {
            assert!(text.contains(flag), "help omits {flag}");
        }
    }
}
