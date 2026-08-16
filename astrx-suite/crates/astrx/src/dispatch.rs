//! The `astrx <engine> …` argument multiplexer.
//!
//! Splitting the command line is a *pure* function ([`split`]) that never runs
//! an engine, so the pass-through contract — everything after the subcommand
//! reaches the engine byte-for-byte, in order, unmodified — is testable without
//! binding a socket or touching a database. [`run`] is the thin wrapper that
//! takes the split apart and calls the chosen engine's `cli::run`.
//!
//! The contract that matters: **only the first token is ever inspected.** No
//! flag reordering, no `--flag=value` splitting, no `--` stripping past the
//! subcommand. A documented invocation such as
//! `websearch crawl --seeds s.txt -- --not-a-flag` therefore behaves identically
//! as `astrx websearch crawl --seeds s.txt -- --not-a-flag`. Anything cleverer
//! here would silently change the meaning of commands already in unit files and
//! runbooks, which is the one thing this crate exists not to do.

use std::process::ExitCode;

/// The program name in usage and error text.
pub const PROG: &str = "astrx";

/// One dispatchable engine: the subcommand token, a one-line purpose for
/// `astrx --help`, and the loopback port its server listens on by default.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Engine {
    /// The subcommand token, e.g. `"gitweb"`.
    pub name: &'static str,
    /// What an operator uses it for, in one line.
    pub about: &'static str,
    /// The default port its HTTP server binds, for `astrx --help` and
    /// [`crate::doctor`]. `None` when the engine has no single default port.
    pub default_port: Option<u16>,
}

/// Every engine `astrx` can dispatch to, in help-listing order.
///
/// Adding an engine means adding a row here and an arm in [`run`]; both are
/// exhaustively covered by `tests/dispatch.rs`, which asserts the two agree.
pub const ENGINES: &[Engine] = &[
    Engine {
        name: "gitweb",
        about: "Read-only, no-JS git web frontend (repos, log, diff, blame, clone)",
        default_port: Some(8801),
    },
    Engine {
        name: "onioncrawler",
        about: "Darknet (.onion) crawler and no-JS search over the crawled index",
        default_port: Some(8802),
    },
    Engine {
        name: "websearch",
        about: "Clearnet crawler and search engine (BM25 + PageRank, no-JS UI + JSON API)",
        default_port: Some(8803),
    },
    Engine {
        name: "torrentds",
        about: "DHT torrent-metadata indexer, search and BitTorrent tracker",
        default_port: Some(8804),
    },
    Engine {
        name: "suitedash",
        about: "Ops/status dashboard aggregating the health and metrics of the above",
        default_port: Some(8805),
    },
];

/// What the leading tokens of the command line asked for.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Action {
    /// Dispatch to `engine`, handing it `rest` unchanged.
    Engine {
        /// The engine that owns the rest of the command line.
        engine: &'static Engine,
        /// Every token after the subcommand, in order, untouched.
        rest: Vec<String>,
    },
    /// `astrx doctor …` — the diagnostics runner, with its own flags in `rest`.
    Doctor(Vec<String>),
    /// Text for stdout and exit 0 (`--help`, `--version`).
    Print(String),
    /// A usage error for stderr and exit 2.
    Usage(String),
}

/// Split a command line into "what astrx should do" and "what to pass on".
///
/// `argv` excludes `argv[0]`. Only the leading token is interpreted:
///
/// * `-h` / `--help` / `help` → the engine list.
/// * `-V` / `--version` → the suite version.
/// * `--` → the *next* token is the subcommand; the `--` itself is consumed,
///   which is what lets an operator write `astrx -- doctor --json` and be sure
///   the `doctor` is read as a subcommand and not as a value.
/// * any other token starting with `-` → a usage error naming it, because
///   silently forwarding an unknown astrx-level flag to an engine produces that
///   engine's error message about a flag the operator never aimed at it.
/// * anything else → the subcommand; **all** remaining tokens are `rest`.
#[must_use]
pub fn split(argv: &[String]) -> Action {
    let mut i = 0;
    // At most one leading `--`: the token after it is the subcommand, whatever
    // it looks like. A second `--` belongs to the engine and is passed through.
    if argv.first().map(String::as_str) == Some("--") {
        i = 1;
    } else {
        match argv.first().map(String::as_str) {
            None => return Action::Usage(format!("{}\nerror: a subcommand is required", usage())),
            Some("-h" | "--help" | "help") => return Action::Print(help()),
            Some("-V" | "--version") => {
                return Action::Print(format!("{PROG} {}\n", env!("CARGO_PKG_VERSION")))
            }
            Some(tok) if tok.starts_with('-') => {
                return Action::Usage(format!(
                    "{}\nerror: unknown option {tok} (astrx takes no options of its own; \
                     flags after the subcommand go to the engine)",
                    usage()
                ))
            }
            Some(_) => {}
        }
    }

    let Some(sub) = argv.get(i) else {
        return Action::Usage(format!(
            "{}\nerror: a subcommand is required after `--`",
            usage()
        ));
    };
    let rest: Vec<String> = argv[i + 1..].to_vec();

    if sub == "doctor" {
        return Action::Doctor(rest);
    }
    match ENGINES.iter().find(|e| e.name == sub) {
        Some(engine) => Action::Engine { engine, rest },
        None => Action::Usage(format!(
            "{}\nerror: unknown subcommand {sub:?} (expected one of: {}, doctor)",
            usage(),
            ENGINES
                .iter()
                .map(|e| e.name)
                .collect::<Vec<_>>()
                .join(", ")
        )),
    }
}

/// The one-line usage banner that precedes every error.
#[must_use]
pub fn usage() -> String {
    format!("usage: {PROG} <engine|doctor> [args...]")
}

/// `astrx --help`: what each engine is for, and how to reach its own help.
#[must_use]
pub fn help() -> String {
    let width = ENGINES.iter().map(|e| e.name.len()).max().unwrap_or(0);
    let mut s = format!(
        "{}\n\nOne binary for the whole astrx suite. Everything after the engine name is\n\
         passed to that engine unchanged, so any documented invocation works verbatim\n\
         with `astrx ` in front of it.\n\nEngines:\n",
        usage()
    );
    for e in ENGINES {
        s.push_str(&format!("  {:width$}  {}\n", e.name, e.about));
        if let Some(port) = e.default_port {
            s.push_str(&format!(
                "  {:width$}  (serves on 127.0.0.1:{port} by default)\n",
                "",
            ));
        }
    }
    s.push_str(&format!(
        "\nDiagnostics:\n  {:width$}  Check data files, ports, disk, Tor and git; \
         exit non-zero on failure\n",
        "doctor"
    ));
    s.push_str(&format!(
        "\nOptions:\n  -h, --help     Show this message\n  \
         -V, --version  Show the suite version\n\nExamples:\n  \
         {PROG} gitweb --root /repos --port 8801\n  \
         {PROG} websearch crawl --db /data/web.db --seeds /data/seeds.txt\n  \
         {PROG} torrentds search --db /data/torrentds.db --port 8804\n  \
         {PROG} doctor --repo-root /repos --db-dir /data\n\n\
         Per-engine help:\n  {PROG} <engine> --help\n"
    ));
    s
}

/// Run one `astrx` command line and return the process exit code.
///
/// `argv` excludes `argv[0]`.
#[must_use]
pub fn run(argv: &[String]) -> ExitCode {
    match split(argv) {
        Action::Print(text) => {
            print!("{text}");
            ExitCode::from(0)
        }
        Action::Usage(msg) => {
            eprintln!("{msg}");
            ExitCode::from(2)
        }
        Action::Doctor(rest) => crate::doctor::run(&rest),
        Action::Engine { engine, rest } => {
            let args = rest.into_iter();
            // Each arm calls the engine's own `cli::run`, which is the exact
            // function its standalone binary calls. No parsing happens here.
            match engine.name {
                "gitweb" => gitweb::cli::run(args),
                "onioncrawler" => onioncrawler::cli::run(args),
                "websearch" => websearch::cli::run(args),
                "torrentds" => torrentds::cli::run(args),
                "suitedash" => suitedash::cli::run(args),
                // Unreachable while ENGINES and this match agree;
                // `tests/dispatch.rs::every_engine_row_dispatches` proves they do
                // on every build, so a new row without an arm fails CI rather
                // than shipping an engine that exits 2 for no visible reason.
                other => {
                    eprintln!(
                        "error: engine {other:?} is listed in `astrx --help` but not wired to a \
                         run() — this is an astrx bug, not a configuration problem"
                    );
                    ExitCode::from(70)
                }
            }
        }
    }
}
