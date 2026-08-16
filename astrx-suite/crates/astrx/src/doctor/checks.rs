//! The individual diagnostics.
//!
//! Every type here is a plain struct holding the inputs it needs plus a `run()`
//! that returns an [`Outcome`]. None of them read global state, print, or exit —
//! which is what lets `tests/doctor.rs` construct each one against a temp
//! directory or a closed port and assert on both its pass and its fail path.

use std::io::{Read, Write};
use std::net::{SocketAddr, TcpListener, TcpStream, ToSocketAddrs};
use std::path::Path;
use std::process::Command;
use std::time::Duration;

use super::{Check, Outcome};

/// Wall-clock budget for one probe (identify a port, greet a SOCKS proxy).
///
/// Short on purpose: `doctor` is what an operator runs when something is already
/// broken, and a diagnostic that hangs for 30 s per dead port is a diagnostic
/// nobody waits for. A local service that cannot answer in two seconds is
/// itself the finding.
const PROBE_TIMEOUT: Duration = Duration::from_secs(2);

/// Bytes read from a probed port before giving up on identifying it. A
/// Prometheus exposition puts its first metric name well inside this; a hostile
/// or wedged service cannot make `doctor` buffer without bound.
const IDENTIFY_MAX_BYTES: usize = 8192;

// ---------------------------------------------------------------------------
// Data files and directories
// ---------------------------------------------------------------------------

/// Whether a data path is expected to be a file or a directory.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum PathKind {
    /// A single snapshot file.
    File,
    /// A directory (gitweb's repository root).
    Directory,
}

/// Which engine's snapshot decoder to try on the bytes, if any.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Snapshot {
    /// Do not attempt to decode (the path is not a snapshot).
    None,
    /// `onioncrawler::store::Store::restore`.
    OnionCrawler,
    /// `websearch::Index::restore`.
    WebSearch,
    /// `torrentds::store::Store::restore`.
    TorrentDs,
}

impl Snapshot {
    /// `Ok(summary)` when the blob decodes, `Err(())` when it does not.
    fn decode(self, blob: &[u8]) -> Result<String, ()> {
        match self {
            Snapshot::None => Ok(String::new()),
            Snapshot::OnionCrawler => onioncrawler::store::Store::restore(blob)
                .map(|s| format!("{} pages, {} hosts", s.page_count(), s.host_count()))
                .ok_or(()),
            Snapshot::WebSearch => websearch::Index::restore(blob)
                .map(|ix| {
                    let st = ix.stats();
                    format!("{} docs, {} hosts", st.docs, st.hosts)
                })
                .ok_or(()),
            Snapshot::TorrentDs => {
                torrentds::store::Store::restore(blob, torrentds::store::DEFAULT_SPAM_THRESHOLD)
                    .map(|s| {
                        let st = s.stats();
                        format!("{} torrents, {} files", st.torrents, st.files)
                    })
                    .ok_or(())
            }
        }
    }
}

/// One engine's data path: does it exist, can it be read, can it be written,
/// and (for a snapshot) does the engine's own decoder accept it?
///
/// The snapshot decode is the point of this check. Permission bits and `stat`
/// only prove the engine can *open* the file; a half-written or
/// version-mismatched blob passes every one of those and then makes the engine
/// come up with an empty index, which looks like a working service serving
/// nothing — the failure mode that costs the most time to diagnose from
/// outside.
#[derive(Clone, Debug)]
pub struct DataPathCheck {
    /// Dotted report name, e.g. `websearch.db`.
    pub name: String,
    /// The path to examine. Empty means "not configured".
    pub path: String,
    /// File or directory.
    pub kind: PathKind,
    /// Whether the engine needs to write here.
    pub need_write: bool,
    /// Which decoder to try on the contents.
    pub snapshot: Snapshot,
    /// Why an empty `path` is a skip rather than a failure.
    pub skip_reason: Option<String>,
}

impl Check for DataPathCheck {
    fn name(&self) -> String {
        self.name.clone()
    }

    fn run(&self) -> Outcome {
        if self.path.is_empty() {
            let why = self
                .skip_reason
                .clone()
                .unwrap_or_else(|| "no path configured".to_string());
            return Outcome::skip(&self.name, why);
        }
        let path = Path::new(&self.path);
        let disp = &self.path;

        let meta = match std::fs::metadata(path) {
            Ok(m) => Some(m),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => None,
            Err(e) => {
                return Outcome::fail(
                    &self.name,
                    format!("{disp}: cannot stat ({e})"),
                    "check the path and the permissions on every directory leading to it",
                )
            }
        };

        match (meta, self.kind) {
            // --- Directory ---
            (Some(m), PathKind::Directory) if !m.is_dir() => Outcome::fail(
                &self.name,
                format!("{disp} exists but is not a directory"),
                "point this at the directory that directly contains the repositories",
            ),
            (None, PathKind::Directory) => Outcome::fail(
                &self.name,
                format!("{disp} does not exist"),
                "create it, or correct --repo-root",
            ),
            (Some(_), PathKind::Directory) => match std::fs::read_dir(path) {
                Err(e) => Outcome::fail(
                    &self.name,
                    format!("{disp}: cannot list directory ({e})"),
                    "grant the service account read+execute on the directory",
                ),
                Ok(entries) => {
                    let n = entries.count();
                    if self.need_write {
                        if let Err(e) = probe_writable(path) {
                            return Outcome::fail(
                                &self.name,
                                format!("{disp}: readable but NOT writable ({e})"),
                                "check the mount is rw, the quota, and the owner of the directory",
                            );
                        }
                    }
                    Outcome::pass(&self.name, format!("{disp}: readable, {n} entr(ies)"))
                }
            },

            // --- File that does not exist yet ---
            (None, PathKind::File) => {
                let parent = path.parent().filter(|p| !p.as_os_str().is_empty());
                let parent = parent.unwrap_or_else(|| Path::new("."));
                if !parent.is_dir() {
                    return Outcome::fail(
                        &self.name,
                        format!(
                            "{disp} is missing and its directory {} does not exist either",
                            parent.display()
                        ),
                        "create the data directory, or correct --db-dir",
                    );
                }
                match probe_writable(parent) {
                    Ok(()) => Outcome::pass(
                        &self.name,
                        format!(
                            "{disp} does not exist yet; {} is writable (a fresh index will be created)",
                            parent.display()
                        ),
                    ),
                    Err(e) => Outcome::fail(
                        &self.name,
                        format!(
                            "{disp} is missing and {} is not writable ({e}), so the engine cannot create it",
                            parent.display()
                        ),
                        "check the mount is rw, the quota, and the owner of the data directory",
                    ),
                }
            }

            // --- Existing file ---
            (Some(m), PathKind::File) => {
                if m.is_dir() {
                    return Outcome::fail(
                        &self.name,
                        format!("{disp} is a directory, but this engine expects a snapshot file"),
                        "correct --db-dir, or move the directory out of the way",
                    );
                }
                let blob = match std::fs::read(path) {
                    Ok(b) => b,
                    Err(e) => {
                        return Outcome::fail(
                            &self.name,
                            format!(
                                "{disp}: exists ({} bytes) but cannot be read ({e})",
                                m.len()
                            ),
                            "grant the service account read on the file",
                        )
                    }
                };
                if self.need_write {
                    // Open for write WITHOUT truncate: the point is to learn
                    // whether the engine could publish a new snapshot, not to
                    // destroy the one that is there.
                    if let Err(e) = std::fs::OpenOptions::new().write(true).open(path) {
                        return Outcome::fail(
                            &self.name,
                            format!("{disp}: readable but NOT writable ({e})"),
                            "check the file owner and that the filesystem is mounted rw",
                        );
                    }
                    // The engine publishes by rename, so the *directory* must be
                    // writable too — a writable file in a read-only directory
                    // fails only at the moment a crawl tries to commit.
                    let parent = path.parent().filter(|p| !p.as_os_str().is_empty());
                    let parent = parent.unwrap_or_else(|| Path::new("."));
                    if let Err(e) = probe_writable(parent) {
                        return Outcome::fail(
                            &self.name,
                            format!(
                                "{disp} is writable but its directory {} is not ({e}); \
                                 snapshots are published by rename, so every save will fail",
                                parent.display()
                            ),
                            "make the data directory writable by the service account",
                        );
                    }
                }
                match self.snapshot.decode(&blob) {
                    Ok(summary) if summary.is_empty() => {
                        Outcome::pass(&self.name, format!("{disp}: {} bytes, readable", m.len()))
                    }
                    Ok(summary) => Outcome::pass(
                        &self.name,
                        format!("{disp}: {} bytes, snapshot loads ({summary})", m.len()),
                    ),
                    Err(()) => Outcome::fail(
                        &self.name,
                        format!(
                            "{disp}: {} bytes, but the snapshot does NOT load (corrupt, truncated, \
                             or written by a newer format version)",
                            m.len()
                        ),
                        "restore the last good backup; starting the engine on this file serves an \
                         empty index, which looks healthy from outside",
                    ),
                }
            }
        }
    }
}

/// Create and immediately remove a probe file in `dir`.
///
/// Permission bits are checked by *trying*, because they are not the whole
/// answer: a read-only mount, an exhausted quota and a full filesystem all leave
/// `0755 root:root` looking perfectly writable.
fn probe_writable(dir: &Path) -> Result<(), std::io::Error> {
    let probe = dir.join(format!(".astrx-doctor-probe-{}", std::process::id()));
    let result = std::fs::File::create(&probe).and_then(|mut f| f.write_all(b"astrx doctor"));
    let _ = std::fs::remove_file(&probe);
    result
}

// ---------------------------------------------------------------------------
// Ports
// ---------------------------------------------------------------------------

/// Is the port an engine is configured for free to bind, or already serving?
///
/// Both answers can be right, which is why neither is a failure on its own:
/// before `systemctl start` the port should be free, and after it should be the
/// engine. What is *always* wrong is the port being held by something that is
/// not this engine — that is the case where the unit starts, fails to bind, and
/// the dashboard shows a service that has been "up" for months on someone else's
/// process.
#[derive(Clone, Debug)]
pub struct PortCheck {
    /// Dotted report name, e.g. `websearch.port`.
    pub name: String,
    /// Host the engine binds.
    pub host: String,
    /// Port the engine binds.
    pub port: u16,
    /// Metric-name prefix that identifies the expected engine on `/metrics`,
    /// e.g. `websearch_`.
    pub expect_prefix: String,
}

impl Check for PortCheck {
    fn name(&self) -> String {
        self.name.clone()
    }

    fn run(&self) -> Outcome {
        let addr = format!("{}:{}", self.host, self.port);
        // Bind first: if it succeeds, nothing is listening. The listener is
        // dropped immediately, so this never blocks a service from starting.
        match TcpListener::bind(&addr) {
            Ok(l) => {
                drop(l);
                return Outcome::pass(&self.name, format!("{addr} is free (nothing listening)"));
            }
            Err(e) if e.kind() != std::io::ErrorKind::AddrInUse => {
                return Outcome::fail(
                    &self.name,
                    format!("{addr}: cannot bind ({e})"),
                    "check the host is an address this machine actually has, and that the port is \
                     not below 1024 without the capability to bind it",
                );
            }
            Err(_) => {}
        }
        match identify(&addr, &self.expect_prefix) {
            Identified::Expected(what) => Outcome::pass(
                &self.name,
                format!("{addr} is serving {what} (already running)"),
            ),
            Identified::Other(what) => Outcome::warn(
                &self.name,
                format!(
                    "{addr} is in use by something else: {what}. The engine will fail to bind."
                ),
                "stop whatever owns the port, or move the engine with --port",
            ),
            Identified::Unknown => Outcome::warn(
                &self.name,
                format!(
                    "{addr} is in use, but it did not answer GET /metrics — cannot tell what owns it"
                ),
                "run `ss -ltnp | grep :{port}` (or `lsof -i :{port}`) as root to name the process"
                    .replace("{port}", &self.port.to_string()),
            ),
        }
    }
}

/// What answered on a port that was already in use.
enum Identified {
    /// The expected engine (its metric prefix was in the body).
    Expected(String),
    /// Something else that spoke enough HTTP to describe itself.
    Other(String),
    /// In use, but nothing intelligible came back.
    Unknown,
}

/// Ask a listening port for `/metrics` and see whose it is.
///
/// This is the identification an operator would otherwise get by running `ss` as
/// root; doing it over the socket works unprivileged and in a container where
/// `/proc` shows only this process.
fn identify(addr: &str, expect_prefix: &str) -> Identified {
    let Some(sock) = resolve(addr) else {
        return Identified::Unknown;
    };
    let Ok(mut stream) = TcpStream::connect_timeout(&sock, PROBE_TIMEOUT) else {
        return Identified::Unknown;
    };
    let _ = stream.set_read_timeout(Some(PROBE_TIMEOUT));
    let _ = stream.set_write_timeout(Some(PROBE_TIMEOUT));
    // HTTP/1.0 + `Connection: close`, so a keep-alive server closes rather than
    // holding the probe open until the read timeout.
    let req = format!("GET /metrics HTTP/1.0\r\nHost: {addr}\r\nConnection: close\r\n\r\n");
    if stream.write_all(req.as_bytes()).is_err() {
        return Identified::Unknown;
    }
    let mut buf = Vec::new();
    let mut chunk = [0u8; 1024];
    while buf.len() < IDENTIFY_MAX_BYTES {
        match stream.read(&mut chunk) {
            Ok(0) | Err(_) => break,
            Ok(n) => buf.extend_from_slice(&chunk[..n]),
        }
    }
    if buf.is_empty() {
        return Identified::Unknown;
    }
    let text = String::from_utf8_lossy(&buf);
    if text.contains(expect_prefix) {
        return Identified::Expected(format!("/metrics with {expect_prefix}* series"));
    }
    // Name the impostor as precisely as the response allows.
    for prefix in crate::dispatch::ENGINES.iter().map(|e| e.name) {
        if text.contains(&format!("{prefix}_")) {
            return Identified::Other(format!("a different astrx engine ({prefix})"));
        }
    }
    let status = text.lines().next().unwrap_or("").trim().to_string();
    let server = text
        .lines()
        .find(|l| l.to_ascii_lowercase().starts_with("server:"))
        .map(str::trim)
        .unwrap_or("");
    let desc = match (status.is_empty(), server.is_empty()) {
        (false, false) => format!("{status} / {server}"),
        (false, true) => status,
        _ => "an HTTP-ish service that did not identify itself".to_string(),
    };
    Identified::Other(desc)
}

fn resolve(addr: &str) -> Option<SocketAddr> {
    addr.to_socket_addrs().ok()?.next()
}

// ---------------------------------------------------------------------------
// Disk
// ---------------------------------------------------------------------------

/// Free space on the filesystem holding the data directory.
///
/// The suite publishes every snapshot by writing a full copy beside the old one
/// and renaming, so the space needed at commit time is the size of the *whole*
/// index, not the delta. A node that fills up does not degrade: `write_atomic`
/// fails, the crawl keeps running, and the last good snapshot silently stops
/// advancing.
#[derive(Clone, Debug)]
pub struct DiskSpaceCheck {
    /// Dotted report name.
    pub name: String,
    /// A path on the filesystem to measure.
    pub path: String,
    /// Warn below this many MiB free.
    pub min_free_mb: u64,
}

impl Check for DiskSpaceCheck {
    fn name(&self) -> String {
        self.name.clone()
    }

    fn run(&self) -> Outcome {
        let path = if self.path.is_empty() {
            "."
        } else {
            &self.path
        };
        // `df -P` is POSIX-specified output (one line per filesystem, fixed
        // column order); `-k` pins the block size to 1 KiB so the numbers do not
        // change under a `BLOCKSIZE`/`DF_BLOCK_SIZE` in the operator's
        // environment. argv-only, never a shell, like every other subprocess in
        // this suite.
        let out = match Command::new("df").args(["-P", "-k", path]).output() {
            Ok(o) => o,
            Err(e) => {
                return Outcome::skip(
                    &self.name,
                    format!("cannot measure free space on {path}: df is unavailable ({e})"),
                )
            }
        };
        if !out.status.success() {
            return Outcome::fail(
                &self.name,
                format!(
                    "df failed for {path}: {}",
                    String::from_utf8_lossy(&out.stderr).trim()
                ),
                "check the path exists and is on a mounted filesystem",
            );
        }
        let text = String::from_utf8_lossy(&out.stdout);
        let Some(free_kib) = parse_df_available_kib(&text) else {
            return Outcome::skip(&self.name, format!("could not parse df output for {path}"));
        };
        let free_mb = free_kib / 1024;
        if free_mb < self.min_free_mb {
            return Outcome::warn(
                &self.name,
                format!(
                    "{path}: {free_mb} MiB free, below the {} MiB threshold",
                    self.min_free_mb
                ),
                "free space or grow the volume before the next snapshot publish — a failed publish \
                 leaves the index frozen at its last good state with no other symptom",
            );
        }
        Outcome::pass(&self.name, format!("{path}: {free_mb} MiB free"))
    }
}

/// The `Available` (4th) column of `df -P -k`'s data row, in KiB.
///
/// Split as its own function because `df`'s first column can wrap onto its own
/// line for a long device name, and getting that wrong silently reports the
/// wrong filesystem's free space.
#[must_use]
pub fn parse_df_available_kib(text: &str) -> Option<u64> {
    let mut fields: Vec<&str> = Vec::new();
    for line in text.lines().skip(1) {
        fields.extend(line.split_whitespace());
        // A wrapped device name leaves fewer than the 6 POSIX columns on the
        // first line; keep accumulating until the row is complete.
        if fields.len() >= 6 {
            break;
        }
    }
    fields.get(3)?.parse().ok()
}

// ---------------------------------------------------------------------------
// Tor
// ---------------------------------------------------------------------------

/// Does a SOCKS5 proxy answer on the configured port?
///
/// A TCP connect alone is not enough — plenty of things accept a connection on
/// 9050 — so this completes the SOCKS5 method negotiation. Getting `05 00` back
/// proves the far end really is a SOCKS5 proxy that will take no-auth requests,
/// which is exactly what onioncrawler's fetcher needs.
#[derive(Clone, Debug)]
pub struct TorSocksCheck {
    /// Proxy host.
    pub host: String,
    /// Proxy port; `0` disables the check.
    pub port: u16,
}

impl Check for TorSocksCheck {
    fn name(&self) -> String {
        "tor.socks".to_string()
    }

    fn run(&self) -> Outcome {
        if self.port == 0 {
            return Outcome::skip(
                self.name(),
                "no SOCKS proxy configured (pass --tor-port 9050 to check Tor on an onion node)",
            );
        }
        let addr = format!("{}:{}", self.host, self.port);
        match socks_greet(&addr) {
            Ok(()) => Outcome::pass(
                self.name(),
                format!("{addr}: SOCKS5 proxy answered the no-auth handshake"),
            ),
            Err(e) => Outcome::fail(
                self.name(),
                format!("{addr}: {e}"),
                "start Tor (`systemctl status tor`) or correct --tor-host/--tor-port; without it \
                 onioncrawler cannot fetch anything and every host goes 'down'",
            ),
        }
    }
}

/// Prove a circuit actually builds, by asking the proxy to CONNECT somewhere.
///
/// Distinct from [`TorSocksCheck`] because the two fail for unrelated reasons: a
/// Tor that is running but has not bootstrapped answers the handshake perfectly
/// and then refuses every CONNECT, which reads as "Tor is fine, the whole
/// internet is down" unless the two are reported separately.
#[derive(Clone, Debug)]
pub struct TorCircuitCheck {
    /// Proxy host.
    pub host: String,
    /// Proxy port; `0` disables the check.
    pub port: u16,
    /// `host:port` to reach through the proxy; empty disables the check.
    pub target: String,
}

impl Check for TorCircuitCheck {
    fn name(&self) -> String {
        "tor.circuit".to_string()
    }

    fn run(&self) -> Outcome {
        if self.port == 0 {
            return Outcome::skip(
                self.name(),
                "no SOCKS proxy configured (pass --tor-port 9050 to check Tor on an onion node)",
            );
        }
        if self.target.is_empty() {
            return Outcome::skip(
                self.name(),
                "--tor-probe not given: no circuit test attempted (nothing left the box)",
            );
        }
        let Some((thost, tport)) = split_host_port(&self.target) else {
            return Outcome::fail(
                self.name(),
                format!("--tor-probe {:?} is not HOST:PORT", self.target),
                "use e.g. --tor-probe example.onion:80",
            );
        };
        let addr = format!("{}:{}", self.host, self.port);
        match socks_connect(&addr, &thost, tport) {
            Ok(()) => Outcome::pass(
                self.name(),
                format!("circuit to {thost}:{tport} established through {addr}"),
            ),
            Err(e) => Outcome::fail(
                self.name(),
                format!("cannot reach {thost}:{tport} through {addr}: {e}"),
                "check Tor has finished bootstrapping (`Bootstrapped 100%` in its log); a proxy \
                 that answers the handshake but refuses CONNECT is a Tor that is still starting",
            ),
        }
    }
}

fn split_host_port(s: &str) -> Option<(String, u16)> {
    let (h, p) = s.rsplit_once(':')?;
    if h.is_empty() {
        return None;
    }
    Some((h.to_string(), p.parse().ok()?))
}

/// SOCKS5 method negotiation against `addr`.
fn socks_greet(addr: &str) -> Result<(), String> {
    let mut stream = open(addr)?;
    greet(&mut stream)?;
    Ok(())
}

/// Full SOCKS5 negotiation plus a CONNECT to `host:port`.
fn socks_connect(addr: &str, host: &str, port: u16) -> Result<(), String> {
    let mut stream = open(addr)?;
    greet(&mut stream)?;
    let req = onioncrawler::socks::build_connect_request(host, port).map_err(|e| e.0)?;
    stream
        .write_all(&req)
        .map_err(|e| format!("cannot send the CONNECT request ({e})"))?;
    // VER REP RSV ATYP — the reply code is byte 1 and is all this check needs.
    let mut reply = [0u8; 4];
    stream
        .read_exact(&mut reply)
        .map_err(|e| format!("no CONNECT reply from the proxy ({e})"))?;
    if reply[0] != onioncrawler::socks::VER {
        return Err(format!(
            "the proxy replied with SOCKS version {:#04x}, not 5",
            reply[0]
        ));
    }
    if reply[1] != 0x00 {
        return Err(format!(
            "the proxy refused the connection: {} ({:#04x})",
            onioncrawler::socks::reply_text(reply[1]),
            reply[1]
        ));
    }
    Ok(())
}

fn open(addr: &str) -> Result<TcpStream, String> {
    let sock = resolve(addr).ok_or_else(|| format!("cannot resolve {addr}"))?;
    let stream = TcpStream::connect_timeout(&sock, PROBE_TIMEOUT)
        .map_err(|e| format!("nothing accepted a connection ({e})"))?;
    let _ = stream.set_read_timeout(Some(PROBE_TIMEOUT));
    let _ = stream.set_write_timeout(Some(PROBE_TIMEOUT));
    Ok(stream)
}

fn greet(stream: &mut TcpStream) -> Result<(), String> {
    stream
        .write_all(&onioncrawler::socks::build_greeting(false))
        .map_err(|e| format!("cannot send the SOCKS5 greeting ({e})"))?;
    let mut reply = [0u8; 2];
    stream.read_exact(&mut reply).map_err(|e| {
        format!(
            "connected, but nothing answered the SOCKS5 greeting ({e}) \
                              — is this really a SOCKS proxy?"
        )
    })?;
    if reply[0] != onioncrawler::socks::VER {
        return Err(format!(
            "answered with version {:#04x}, not SOCKS5 — something else owns this port",
            reply[0]
        ));
    }
    if reply[1] == onioncrawler::socks::M_NONE_ACCEPTABLE {
        return Err(
            "the proxy rejected no-auth; onioncrawler's fetcher only speaks no-auth".to_string(),
        );
    }
    if reply[1] != onioncrawler::socks::M_NOAUTH {
        return Err(format!(
            "the proxy demands auth method {:#04x}; onioncrawler's fetcher only speaks no-auth",
            reply[1]
        ));
    }
    Ok(())
}

// ---------------------------------------------------------------------------
// git
// ---------------------------------------------------------------------------

/// The oldest git gitweb works on, as `(major, minor)`.
///
/// Set by `git grep --max-count`, which `gitcmd::search_code_with` passes on
/// every code search and which git only learned in 2.22. On an older git the
/// repository, log, diff and blame views all work and only `/grep` fails, so
/// this is exactly the kind of breakage that gets reported months later as
/// "search has never worked on that box".
pub const MIN_GIT: (u32, u32) = (2, 22);

/// Is `git` on `PATH`, and new enough?
#[derive(Clone, Copy, Debug, Default)]
pub struct GitBinaryCheck;

impl GitBinaryCheck {
    /// A check against the `git` gitweb itself would exec.
    #[must_use]
    pub fn new() -> Self {
        GitBinaryCheck
    }
}

impl Check for GitBinaryCheck {
    fn name(&self) -> String {
        "gitweb.git".to_string()
    }

    fn run(&self) -> Outcome {
        // The same binary name gitweb execs, resolved the same way (PATH lookup
        // by `std::process::Command`), so this cannot pass while gitweb fails.
        let out =
            match Command::new(gitweb::gitcmd::GIT).arg("--version").output() {
                Ok(o) => o,
                Err(e) => return Outcome::fail(
                    self.name(),
                    format!("cannot run `{}` ({e})", gitweb::gitcmd::GIT),
                    "install git and make sure it is on the PATH of the service account, not just \
                     your login shell",
                ),
            };
        if !out.status.success() {
            return Outcome::fail(
                self.name(),
                format!(
                    "`git --version` exited {}: {}",
                    out.status,
                    String::from_utf8_lossy(&out.stderr).trim()
                ),
                "the git on PATH is broken; reinstall it",
            );
        }
        let text = String::from_utf8_lossy(&out.stdout);
        let banner = text.trim();
        let Some(ver) = parse_git_version(banner) else {
            return Outcome::warn(
                self.name(),
                format!("git responded, but its version is unreadable: {banner:?}"),
                "check `git --version` by hand; gitweb needs at least \
                 git 2.22 for `git grep --max-count`",
            );
        };
        if ver < MIN_GIT {
            return Outcome::fail(
                self.name(),
                format!(
                    "git {}.{} is older than the {}.{} gitweb needs",
                    ver.0, ver.1, MIN_GIT.0, MIN_GIT.1
                ),
                "upgrade git; below 2.22 every code search 500s because `git grep --max-count` \
                 does not exist, while every other view keeps working",
            );
        }
        Outcome::pass(
            self.name(),
            format!("{banner} (>= {}.{})", MIN_GIT.0, MIN_GIT.1),
        )
    }
}

/// `(major, minor)` from a `git version 2.43.0` banner.
///
/// Tolerant of vendor suffixes (`git version 2.39.3 (Apple Git-145)`), because a
/// distribution that decorates the banner should not make the check unreadable.
#[must_use]
pub fn parse_git_version(banner: &str) -> Option<(u32, u32)> {
    let rest = banner
        .split_whitespace()
        .find(|t| t.chars().next().is_some_and(|c| c.is_ascii_digit()) && t.contains('.'))?;
    let mut parts = rest.split('.');
    let major: u32 = parts.next()?.parse().ok()?;
    let minor_tok = parts.next()?;
    let digits: String = minor_tok.chars().take_while(char::is_ascii_digit).collect();
    Some((major, digits.parse().ok()?))
}
