//! `astrx doctor` — one command that answers "what is wrong with this box?".
//!
//! # Shape
//!
//! A check is a small self-contained value implementing [`Check`]: it holds the
//! inputs it needs (a path, a port, a threshold), and `run()` returns an
//! [`Outcome`] instead of printing. That split is the whole design:
//!
//! * a check can be constructed against a temp directory and asserted on in a
//!   unit test, both for its pass path and for each way it fails — see
//!   `tests/doctor.rs`;
//! * the runner ([`run_checks`]) is the only thing that formats or exits, so a
//!   new check is one struct plus one line in [`build_checks`], and it inherits
//!   the report format, the exit-code rules and the tests' shape for free.
//!
//! # Exit codes
//!
//! `0` everything passed (warnings included), `1` at least one check FAILED,
//! `2` a usage error in `doctor`'s own flags. A WARN never fails the run: an
//! operator who wires `astrx doctor` into a health gate should not have the gate
//! flap because a disk crossed 80 %.
//!
//! Checks never mutate anything an engine owns. The one write `doctor` performs
//! is a probe file it creates and immediately deletes inside a directory it was
//! asked about, because "can the engine write here?" has no read-only answer —
//! permission bits lie about root, read-only mounts, full filesystems and full
//! quotas, all of which have taken a suite node down.

pub mod checks;

use std::process::ExitCode;

/// The verdict of one check.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Status {
    /// Working. Nothing to do.
    Pass,
    /// Working now, but heading somewhere bad (disk filling, port occupied by
    /// something that answers but is not the expected engine).
    Warn,
    /// Broken. The engine this check covers will not work correctly.
    Fail,
    /// Not applicable or not configured — nothing was tested, so nothing is
    /// claimed. A skip is never a pass in disguise.
    Skip,
}

impl Status {
    /// The fixed-width tag printed at the start of a report line.
    #[must_use]
    pub fn tag(self) -> &'static str {
        match self {
            Status::Pass => "PASS",
            Status::Warn => "WARN",
            Status::Fail => "FAIL",
            Status::Skip => "SKIP",
        }
    }
}

/// What one check found.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Outcome {
    /// Dotted identifier, e.g. `websearch.db`. Stable, so it can be grepped for
    /// in a paging alert and matched against a runbook heading.
    pub name: String,
    /// The verdict.
    pub status: Status,
    /// What was found, including the concrete path/port/number involved — never
    /// just "failed". Read at 3am by someone who did not write this code.
    pub detail: String,
    /// What to do about it, when there is a specific next step.
    pub remedy: Option<String>,
}

impl Outcome {
    /// A passing outcome.
    pub fn pass(name: impl Into<String>, detail: impl Into<String>) -> Self {
        Self::new(name, Status::Pass, detail, None)
    }
    /// A warning outcome with a suggested next step.
    pub fn warn(
        name: impl Into<String>,
        detail: impl Into<String>,
        remedy: impl Into<String>,
    ) -> Self {
        Self::new(name, Status::Warn, detail, Some(remedy.into()))
    }
    /// A failing outcome with a suggested next step.
    pub fn fail(
        name: impl Into<String>,
        detail: impl Into<String>,
        remedy: impl Into<String>,
    ) -> Self {
        Self::new(name, Status::Fail, detail, Some(remedy.into()))
    }
    /// A check that did not run, and why.
    pub fn skip(name: impl Into<String>, detail: impl Into<String>) -> Self {
        Self::new(name, Status::Skip, detail, None)
    }

    fn new(
        name: impl Into<String>,
        status: Status,
        detail: impl Into<String>,
        remedy: Option<String>,
    ) -> Self {
        Outcome {
            name: name.into(),
            status,
            detail: detail.into(),
            remedy,
        }
    }

    /// The report line, plus an indented remedy line when there is one.
    #[must_use]
    pub fn render(&self) -> String {
        let mut s = format!("{} {:<28} {}", self.status.tag(), self.name, self.detail);
        if let Some(r) = &self.remedy {
            s.push_str(&format!("\n     {:<28} -> {r}", ""));
        }
        s
    }
}

/// One diagnostic. Implementors hold their own inputs and touch nothing global,
/// so a test can point one at a temp directory and assert on both outcomes.
pub trait Check {
    /// The stable dotted name this check reports under.
    fn name(&self) -> String;
    /// Perform the check. Must not panic: a check that panics takes the whole
    /// report down and hides every other finding, which is the opposite of what
    /// an operator needs from a diagnostic tool.
    fn run(&self) -> Outcome;
}

/// Run every check in order and return the outcomes.
///
/// Order is the order given: engine-by-engine, so a report reads top to bottom
/// as "gitweb is fine, websearch is fine, torrentds cannot write its database".
#[must_use]
pub fn run_checks(checks: &[Box<dyn Check>]) -> Vec<Outcome> {
    checks.iter().map(|c| c.run()).collect()
}

/// The summary line, and whether the run failed.
#[must_use]
pub fn summarize(outcomes: &[Outcome]) -> (String, bool) {
    let count = |s: Status| outcomes.iter().filter(|o| o.status == s).count();
    let (pass, warn, fail, skip) = (
        count(Status::Pass),
        count(Status::Warn),
        count(Status::Fail),
        count(Status::Skip),
    );
    let line = format!("{pass} passed, {warn} warning(s), {fail} failed, {skip} skipped");
    (line, fail > 0)
}

// ---------------------------------------------------------------------------
// Command line
// ---------------------------------------------------------------------------

/// Where `doctor` should look. Defaults match each engine's own CLI defaults, so
/// running `astrx doctor` in the directory an engine runs in checks the files
/// that engine actually uses.
#[derive(Clone, Debug, PartialEq)]
pub struct DoctorConfig {
    /// Directory holding the engine snapshots (`--db-dir`).
    pub db_dir: String,
    /// gitweb's repository root (`--repo-root`); empty means "not configured".
    pub repo_root: String,
    /// Host the engines bind (`--host`).
    pub host: String,
    /// Free space below this many MiB is a warning (`--min-free-mb`).
    pub min_free_mb: u64,
    /// Tor SOCKS5 host (`--tor-host`).
    pub tor_host: String,
    /// Tor SOCKS5 port (`--tor-port`); `0` disables the Tor checks.
    pub tor_port: u16,
    /// `host:port` to reach through the proxy to prove a circuit builds
    /// (`--tor-probe`); empty skips the circuit check.
    pub tor_probe: String,
    /// Per-engine port overrides, indexed like [`crate::dispatch::ENGINES`].
    pub ports: Vec<(&'static str, u16)>,
}

impl Default for DoctorConfig {
    fn default() -> Self {
        DoctorConfig {
            db_dir: ".".to_string(),
            repo_root: String::new(),
            host: "127.0.0.1".to_string(),
            // A suite node that drops below 1 GiB is roughly one crawl sweep
            // from a truncated snapshot publish: `write_atomic` needs room for
            // the *whole* new blob beside the old one before it renames.
            min_free_mb: 1024,
            tor_host: "127.0.0.1".to_string(),
            // Off unless asked for. Most suite nodes run no Tor at all (gitweb,
            // websearch and torrentds never touch it), and a doctor that FAILs
            // by default on those boxes is a doctor whose red lines get ignored
            // — which is how a real failure gets missed.
            tor_port: 0,
            // Off by default: a circuit probe leaves the box, and `doctor` must
            // be safe to run on a node that is deliberately not allowed to.
            tor_probe: String::new(),
            ports: crate::dispatch::ENGINES
                .iter()
                .filter_map(|e| e.default_port.map(|p| (e.name, p)))
                .collect(),
        }
    }
}

/// `astrx doctor --help`.
#[must_use]
pub fn help() -> String {
    let cfg = DoctorConfig::default();
    format!(
        "usage: astrx doctor [options]\n\n\
         Check what an astrx node needs to work: data files, ports, disk, Tor and git.\n\
         Exits 0 when nothing FAILED (warnings do not fail the run), 1 otherwise.\n\n\
         Options:\n  \
         --db-dir DIR        Directory holding the engine snapshots (default: {})\n  \
         --repo-root DIR     gitweb repository root (default: unset, gitweb checks skipped)\n  \
         --host HOST         Host the engines bind (default: {})\n  \
         --port ENGINE=PORT  Override one engine's port; repeatable\n  \
         --min-free-mb MB    Warn below this much free space (default: {})\n  \
         --tor-host HOST     Tor SOCKS5 host (default: {})\n  \
         --tor-port PORT     Tor SOCKS5 port; 0 skips the Tor checks (default: {},\n  \
         {:20} i.e. skipped — pass --tor-port 9050 on an onion node)\n  \
         --tor-probe H:P     Also prove a circuit builds by connecting to H:P through\n  \
         {:20} the proxy (default: unset, no traffic leaves the box)\n  \
         -h, --help          Show this message\n",
        cfg.db_dir, cfg.host, cfg.min_free_mb, cfg.tor_host, cfg.tor_port, "", ""
    )
}

/// Parse `doctor`'s own flags. `Err` is a usage message for stderr + exit 2.
///
/// # Errors
/// An unknown flag, a missing value, or an unparseable number/port spec.
pub fn parse_args(argv: &[String]) -> Result<Option<DoctorConfig>, String> {
    // Same `--flag=value` normalisation the engines use, so `--db-dir=/data` and
    // `--db-dir /data` are the same command everywhere in the suite.
    let mut toks: Vec<String> = Vec::with_capacity(argv.len());
    for a in argv {
        match a.split_once('=') {
            Some((flag, val)) if flag.starts_with("--") => {
                toks.push(flag.to_string());
                toks.push(val.to_string());
            }
            _ => toks.push(a.clone()),
        }
    }
    let need = |i: usize, flag: &str| -> Result<String, String> {
        toks.get(i)
            .cloned()
            .ok_or_else(|| format!("error: option {flag} requires a value"))
    };
    let mut cfg = DoctorConfig::default();
    let mut i = 0;
    while i < toks.len() {
        match toks[i].as_str() {
            "-h" | "--help" => return Ok(None),
            "--db-dir" => {
                i += 1;
                cfg.db_dir = need(i, "--db-dir")?;
            }
            "--repo-root" => {
                i += 1;
                cfg.repo_root = need(i, "--repo-root")?;
            }
            "--host" => {
                i += 1;
                cfg.host = need(i, "--host")?;
            }
            "--tor-host" => {
                i += 1;
                cfg.tor_host = need(i, "--tor-host")?;
            }
            "--tor-probe" => {
                i += 1;
                cfg.tor_probe = need(i, "--tor-probe")?;
            }
            "--tor-port" => {
                i += 1;
                let v = need(i, "--tor-port")?;
                cfg.tor_port = v
                    .parse()
                    .map_err(|_| format!("error: --tor-port expects 0..=65535, got {v:?}"))?;
            }
            "--min-free-mb" => {
                i += 1;
                let v = need(i, "--min-free-mb")?;
                cfg.min_free_mb = v
                    .parse()
                    .map_err(|_| format!("error: --min-free-mb expects a number, got {v:?}"))?;
            }
            "--port" => {
                i += 1;
                let v = need(i, "--port")?;
                let (name, port) = v.split_once('=').ok_or_else(|| {
                    format!("error: --port expects ENGINE=PORT (e.g. websearch=8803), got {v:?}")
                })?;
                let port: u16 = port
                    .parse()
                    .map_err(|_| format!("error: --port expects 0..=65535, got {port:?}"))?;
                let known = crate::dispatch::ENGINES
                    .iter()
                    .find(|e| e.name == name)
                    .ok_or_else(|| format!("error: --port names an unknown engine {name:?}"))?;
                match cfg.ports.iter_mut().find(|(n, _)| *n == known.name) {
                    Some(slot) => slot.1 = port,
                    None => cfg.ports.push((known.name, port)),
                }
            }
            other => {
                return Err(format!(
                    "error: unknown option {other:?} (try `astrx doctor --help`)"
                ))
            }
        }
        i += 1;
    }
    Ok(Some(cfg))
}

/// Every check a [`DoctorConfig`] implies, in report order.
///
/// The list is the whole extension point: a new diagnostic is a `Check` impl in
/// [`checks`] plus one `push` here.
#[must_use]
pub fn build_checks(cfg: &DoctorConfig) -> Vec<Box<dyn Check>> {
    use checks::{
        DataPathCheck, DiskSpaceCheck, GitBinaryCheck, PathKind, PortCheck, Snapshot,
        TorCircuitCheck, TorSocksCheck,
    };
    let db = |name: &str| -> String {
        let dir = cfg.db_dir.trim_end_matches('/');
        if dir.is_empty() {
            name.to_string()
        } else {
            format!("{dir}/{name}")
        }
    };
    let mut checks: Vec<Box<dyn Check>> = Vec::new();

    // --- gitweb: no snapshot of its own; it serves repositories read-only. ---
    checks.push(Box::new(GitBinaryCheck::new()));
    checks.push(Box::new(DataPathCheck {
        name: "gitweb.repo-root".to_string(),
        path: cfg.repo_root.clone(),
        kind: PathKind::Directory,
        // gitweb never writes to the repository root — it runs read-only git
        // plumbing — so demanding write access here would fail every correctly
        // locked-down deployment.
        need_write: false,
        snapshot: Snapshot::None,
        skip_reason: Some("--repo-root not given".to_string()),
    }));

    // --- The three engines with an on-disk snapshot. ---
    for (engine, file, snap) in [
        ("onioncrawler", "crawl.db", Snapshot::OnionCrawler),
        ("websearch", "web.db", Snapshot::WebSearch),
        ("torrentds", "torrentds.db", Snapshot::TorrentDs),
    ] {
        checks.push(Box::new(DataPathCheck {
            name: format!("{engine}.db"),
            path: db(file),
            kind: PathKind::File,
            need_write: true,
            snapshot: snap,
            skip_reason: None,
        }));
    }

    // --- Ports. ---
    for (engine, port) in &cfg.ports {
        checks.push(Box::new(PortCheck {
            name: format!("{engine}.port"),
            host: cfg.host.clone(),
            port: *port,
            expect_prefix: format!("{engine}_"),
        }));
    }

    // --- Disk, Tor. ---
    checks.push(Box::new(DiskSpaceCheck {
        name: "disk.db-dir".to_string(),
        path: cfg.db_dir.clone(),
        min_free_mb: cfg.min_free_mb,
    }));
    checks.push(Box::new(TorSocksCheck {
        host: cfg.tor_host.clone(),
        port: cfg.tor_port,
    }));
    checks.push(Box::new(TorCircuitCheck {
        host: cfg.tor_host.clone(),
        port: cfg.tor_port,
        target: cfg.tor_probe.clone(),
    }));
    checks
}

/// `astrx doctor` end to end: parse, build, run, print, exit.
#[must_use]
pub fn run(argv: &[String]) -> ExitCode {
    let cfg = match parse_args(argv) {
        Ok(Some(cfg)) => cfg,
        Ok(None) => {
            print!("{}", help());
            return ExitCode::from(0);
        }
        Err(msg) => {
            eprintln!("{msg}");
            return ExitCode::from(2);
        }
    };
    let outcomes = run_checks(&build_checks(&cfg));
    for o in &outcomes {
        println!("{}", o.render());
    }
    let (summary, failed) = summarize(&outcomes);
    println!("\n{summary}");
    if failed {
        // Named explicitly: an operator who sees only the tail of a log needs to
        // know the exit code meant "a check failed", not "doctor crashed".
        println!("astrx doctor: FAILED — see the FAIL line(s) above");
        return ExitCode::from(1);
    }
    ExitCode::from(0)
}
