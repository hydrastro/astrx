//! Each `astrx doctor` check, on both its pass and its fail path.
//!
//! Checks are the code an operator trusts when they already do not trust the
//! box, so a check that reports PASS for the wrong reason is worse than no check
//! at all. Every case here is built against a real temp directory or a real
//! socket rather than a mock, because the things these checks catch (a
//! read-only mount, a truncated snapshot, a port held by the wrong process) do
//! not exist in a mock.

#![cfg(feature = "net")]

use std::path::{Path, PathBuf};

use astrx::doctor::checks::{
    parse_df_available_kib, parse_git_version, DataPathCheck, DiskSpaceCheck, GitBinaryCheck,
    PathKind, PortCheck, Snapshot, TorCircuitCheck, TorSocksCheck, MIN_GIT,
};
use astrx::doctor::{build_checks, parse_args, run_checks, summarize, Check, DoctorConfig, Status};

// ---------------------------------------------------------------------------
// Scaffolding
// ---------------------------------------------------------------------------

/// A temp directory removed when the test ends (including on failure, via the
/// `Drop` that runs while the panic unwinds).
struct Tmp(PathBuf);

impl Tmp {
    fn new(tag: &str) -> Self {
        let dir = std::env::temp_dir().join(format!(
            "astrx-doctor-{tag}-{}-{:?}",
            std::process::id(),
            std::thread::current().id()
        ));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).expect("temp dir");
        Tmp(dir)
    }
    fn path(&self) -> &Path {
        &self.0
    }
    fn join(&self, name: &str) -> String {
        self.0.join(name).to_string_lossy().into_owned()
    }
}

impl Drop for Tmp {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.0);
    }
}

/// A websearch index with one real document in it, so "snapshot loads" has a
/// non-zero count to report.
fn one_doc_index() -> websearch::Index {
    let mut ix = websearch::Index::new();
    ix.upsert_document(
        "https://example.com/a",
        websearch::index::DocFields {
            title: "Title",
            body: "hello world hello",
            host: "example.com",
            fetched_at: 1_700_000_000.0,
            http_status: 200,
            ..websearch::index::DocFields::default()
        },
    );
    ix.finalize();
    ix
}

/// An onioncrawler store with one queued URL, so its snapshot is not the empty
/// blob that any decoder would accept.
fn seeded_onion_store() -> onioncrawler::store::Store {
    let mut st = onioncrawler::store::Store::new();
    let canon = onioncrawler::canonical::canonicalize(
        "http://p53lf57qovyuvwsc6xnrppyply3vtqm7l6pcobkmyqsiofyeznfu5uqd.onion/",
        None,
        false,
        false,
    )
    .expect("a valid v3 onion URL");
    st.enqueue(
        &canon,
        0,
        0,
        onioncrawler::store::Caps::default(),
        1_700_000_000.0,
        false,
    );
    st
}

fn db_check(name: &str, path: String, snapshot: Snapshot) -> DataPathCheck {
    DataPathCheck {
        name: name.to_string(),
        path,
        kind: PathKind::File,
        need_write: true,
        snapshot,
        skip_reason: None,
    }
}

// ---------------------------------------------------------------------------
// Data files
// ---------------------------------------------------------------------------

#[test]
fn a_missing_snapshot_in_a_writable_directory_passes() {
    let tmp = Tmp::new("missing-db");
    let o = db_check("websearch.db", tmp.join("web.db"), Snapshot::WebSearch).run();
    // A fresh install has no snapshot yet; calling that a failure would make
    // `astrx doctor` red on every first boot and train operators to ignore it.
    assert_eq!(o.status, Status::Pass, "{o:?}");
    assert!(o.detail.contains("does not exist yet"), "{o:?}");
}

#[test]
fn a_snapshot_whose_directory_does_not_exist_fails() {
    let tmp = Tmp::new("no-dir");
    let o = db_check(
        "websearch.db",
        format!("{}/nope/web.db", tmp.path().display()),
        Snapshot::WebSearch,
    )
    .run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("does not exist either"), "{o:?}");
}

#[test]
fn a_good_snapshot_loads_and_is_summarised() {
    let tmp = Tmp::new("good-db");
    let path = tmp.join("web.db");
    std::fs::write(&path, one_doc_index().snapshot()).unwrap();

    let o = db_check("websearch.db", path, Snapshot::WebSearch).run();
    assert_eq!(o.status, Status::Pass, "{o:?}");
    // The count is the useful part: "loads" plus "0 docs" is a different
    // incident from "loads" plus "4.2M docs".
    assert!(o.detail.contains("snapshot loads"), "{o:?}");
    assert!(o.detail.contains("1 docs"), "{o:?}");
}

#[test]
fn a_truncated_snapshot_fails_even_though_the_file_is_perfectly_readable() {
    let tmp = Tmp::new("truncated");
    let path = tmp.join("web.db");
    let blob = one_doc_index().snapshot();
    std::fs::write(&path, &blob[..blob.len() / 2]).unwrap();

    let o = db_check("websearch.db", path, Snapshot::WebSearch).run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("does NOT load"), "{o:?}");
    // The remedy has to say *why* this matters — the engine starts fine on a
    // corrupt snapshot and serves an empty index.
    assert!(
        o.remedy.as_deref().unwrap().contains("empty index"),
        "{o:?}"
    );
}

#[test]
fn every_engine_snapshot_decoder_is_wired_to_the_right_engine() {
    let tmp = Tmp::new("decoders");

    let cases: Vec<(&str, Snapshot, Vec<u8>)> = vec![
        (
            "crawl.db",
            Snapshot::OnionCrawler,
            seeded_onion_store().snapshot(),
        ),
        (
            "web.db",
            Snapshot::WebSearch,
            websearch::Index::new().snapshot(),
        ),
        (
            "torrentds.db",
            Snapshot::TorrentDs,
            torrentds::store::Store::new().snapshot(),
        ),
    ];
    for (file, snap, blob) in &cases {
        let path = tmp.join(file);
        std::fs::write(&path, blob).unwrap();
        let o = db_check(file, path, *snap).run();
        assert_eq!(o.status, Status::Pass, "{file}: {o:?}");
        assert!(o.detail.contains("snapshot loads"), "{file}: {o:?}");
    }

    // Cross-wiring is the mistake this catches: an onioncrawler blob handed to
    // the websearch decoder must fail, or `doctor` would report PASS on a node
    // whose --db-dir has the wrong engine's files in it.
    let path = tmp.join("crawl.db");
    let o = db_check("websearch.db", path, Snapshot::WebSearch).run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
}

#[test]
fn a_snapshot_that_is_actually_a_directory_fails() {
    let tmp = Tmp::new("db-is-dir");
    let path = tmp.join("web.db");
    std::fs::create_dir_all(&path).unwrap();
    let o = db_check("websearch.db", path, Snapshot::WebSearch).run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("is a directory"), "{o:?}");
}

#[test]
fn an_unreadable_snapshot_fails_rather_than_reporting_an_empty_index() {
    let tmp = Tmp::new("unreadable");
    let path = tmp.join("web.db");
    std::fs::write(&path, websearch::Index::new().snapshot()).unwrap();
    if !chmod(Path::new(&path), 0o000) {
        return; // running as root: permission bits do not apply
    }
    let o = db_check("websearch.db", path.clone(), Snapshot::WebSearch).run();
    let _ = chmod(Path::new(&path), 0o644);
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("cannot be read"), "{o:?}");
}

#[test]
fn a_snapshot_in_a_read_only_directory_fails_because_the_rename_would() {
    let tmp = Tmp::new("ro-dir");
    let dir = tmp.path().join("data");
    std::fs::create_dir_all(&dir).unwrap();
    let path = dir.join("web.db");
    std::fs::write(&path, websearch::Index::new().snapshot()).unwrap();
    if !chmod(&dir, 0o555) {
        return; // running as root
    }
    let o = db_check(
        "websearch.db",
        path.to_string_lossy().into_owned(),
        Snapshot::WebSearch,
    )
    .run();
    let _ = chmod(&dir, 0o755);
    // The file itself is writable; only the directory is not. Snapshots are
    // published by rename, so this node would crawl happily for days and never
    // save a thing.
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("published by rename"), "{o:?}");
}

#[test]
fn a_repo_root_is_checked_for_read_not_write() {
    let tmp = Tmp::new("repo-root");
    std::fs::create_dir_all(tmp.path().join("a.git")).unwrap();
    let mk = |path: String| DataPathCheck {
        name: "gitweb.repo-root".to_string(),
        path,
        kind: PathKind::Directory,
        need_write: false,
        snapshot: Snapshot::None,
        skip_reason: Some("--repo-root not given".to_string()),
    };

    let o = mk(tmp.join("")).run();
    assert_eq!(o.status, Status::Pass, "{o:?}");
    assert!(o.detail.contains("1 entr"), "{o:?}");

    // A read-only repository root is the *correct* production setup for a
    // read-only viewer; demanding write here would fail every hardened node.
    if chmod(tmp.path(), 0o555) {
        let o = mk(tmp.join("")).run();
        let _ = chmod(tmp.path(), 0o755);
        assert_eq!(o.status, Status::Pass, "{o:?}");
    }

    let o = mk(format!("{}/gone", tmp.path().display())).run();
    assert_eq!(o.status, Status::Fail, "{o:?}");

    // Unset is a skip, never a pass: nothing was checked, so nothing is claimed.
    let o = mk(String::new()).run();
    assert_eq!(o.status, Status::Skip, "{o:?}");
    assert!(o.detail.contains("--repo-root"), "{o:?}");
}

/// `chmod` via the `chmod` binary — `std::os::unix::fs::PermissionsExt` would
/// work too, but this keeps the test portable to the same set of platforms the
/// suite already shells out on. Returns false when the mode did not take (root
/// ignores the bits, and then the test has nothing to assert).
fn chmod(path: &Path, mode: u32) -> bool {
    let ok = std::process::Command::new("chmod")
        .arg(format!("{mode:o}"))
        .arg(path)
        .status()
        .map(|s| s.success())
        .unwrap_or(false);
    if !ok {
        return false;
    }
    // Root defeats the bits entirely; detect that rather than assert a
    // permission failure that will never happen.
    if mode & 0o200 == 0 && path.is_file() {
        return std::fs::OpenOptions::new().read(true).open(path).is_err();
    }
    if mode & 0o200 == 0 && path.is_dir() {
        let probe = path.join(".astrx-root-detect");
        let blocked = std::fs::File::create(&probe).is_err();
        let _ = std::fs::remove_file(&probe);
        return blocked;
    }
    true
}

// ---------------------------------------------------------------------------
// Ports
// ---------------------------------------------------------------------------

fn port_check(port: u16, expect_prefix: &str) -> PortCheck {
    PortCheck {
        name: "websearch.port".to_string(),
        host: "127.0.0.1".to_string(),
        port,
        expect_prefix: expect_prefix.to_string(),
    }
}

/// A port that was free when this returned, found by binding one and letting it
/// go.
///
/// It is free at that instant and no longer. Nothing holds it once this returns,
/// so anything on the machine that asks for an ephemeral port can be handed this
/// exact number a microsecond later. Inside this binary that is a live
/// possibility, not a hypothetical: libtest runs up to `available_parallelism()`
/// tests at a time — 2 on the 2-core CI runner — and several tests here bind
/// `127.0.0.1:0` (`spawn_metrics_server`, `spawn_silent_server`, this function),
/// so whichever test shares the runner with the caller is drawing from the same
/// pool at the same moment. It stays rare because Linux allocates out of
/// `ip_local_port_range` — 32768–60999 here — by walking the range rather than
/// by handing back what was just released, but "rare" is how a test earns a
/// once-a-month failure nobody can reproduce.
///
/// What does *not* race with it is the rest of the workspace: `cargo test` runs
/// each test binary to completion before starting the next, so no other crate's
/// sockets are open while these are.
///
/// Go through [`on_a_free_port`] wherever a squatter would change the verdict,
/// which is every caller that asserts on a particular status — a port taken
/// between the scavenge and the check turns the expected "is free" `Pass` into a
/// `Warn`, or into an "already running" `Pass`.
/// `every_check_a_default_config_builds_actually_runs` scavenges five ports
/// directly instead, because there no squatter can reach its assertion: it only
/// rejects `Fail`, and an occupied port cannot produce one. `PortCheck::run`
/// returns `Fail` solely when `bind` fails with something other than
/// `AddrInUse`; a port that is merely in use is reported as `Warn`, or as `Pass`
/// if it answers as the expected engine.
fn free_port() -> u16 {
    let l = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let p = l.local_addr().unwrap().port();
    drop(l);
    p
}

/// Run `check` against a freshly scavenged [`free_port`], up to three times,
/// stopping at the first outcome `settled` accepts; return the last one for the
/// caller to assert on as usual.
///
/// This does not make the scavenge race-free — nothing short of holding the port
/// open would, and holding it open is the opposite of what these two checks need
/// to see. It makes the verdict depend on an outcome observed on a port that was
/// actually unoccupied, and leaves a real regression failing on the third
/// attempt with the check's own message.
fn on_a_free_port<T>(mut check: impl FnMut(u16) -> T, settled: impl Fn(&T) -> bool) -> T {
    let mut out = check(free_port());
    for _ in 0..2 {
        if settled(&out) {
            break;
        }
        out = check(free_port());
    }
    out
}

#[test]
fn a_free_port_passes() {
    // The retry predicate is the assertion itself: any other verdict — including
    // a Pass that says "already running" — describes a port somebody bound
    // between the scavenge and the check, not a bug in the check.
    let o = on_a_free_port(
        |port| port_check(port, "websearch_").run(),
        |o| o.status == Status::Pass && o.detail.contains("is free"),
    );
    assert_eq!(o.status, Status::Pass, "{o:?}");
    assert!(o.detail.contains("is free"), "{o:?}");
}

#[test]
fn a_port_serving_the_expected_engine_passes_and_says_so() {
    let (port, _srv) = spawn_metrics_server("# HELP x\nwebsearch_docs 42\n");
    let o = port_check(port, "websearch_").run();
    assert_eq!(o.status, Status::Pass, "{o:?}");
    assert!(o.detail.contains("already running"), "{o:?}");
}

#[test]
fn a_port_held_by_a_different_engine_warns_instead_of_passing() {
    // The expensive real-world case: websearch's unit fails to bind because
    // torrentds already owns 8803, and the dashboard has shown "up" for weeks.
    let (port, _srv) = spawn_metrics_server("torrentds_torrents 7\n");
    let o = port_check(port, "websearch_").run();
    assert_eq!(o.status, Status::Warn, "{o:?}");
    assert!(o.detail.contains("torrentds"), "{o:?}");
    assert!(o.detail.contains("fail to bind"), "{o:?}");
}

#[test]
fn a_port_held_by_something_that_says_nothing_warns_with_a_way_to_find_out() {
    let (port, _srv) = spawn_silent_server();
    let o = port_check(port, "websearch_").run();
    assert_eq!(o.status, Status::Warn, "{o:?}");
    assert!(o.detail.contains("cannot tell what owns it"), "{o:?}");
    // The remedy must name the actual command, with the actual port in it.
    let remedy = o.remedy.unwrap();
    assert!(remedy.contains(&format!(":{port}")), "{remedy}");
}

/// A one-shot HTTP server that answers `GET /metrics` with `body`, on a thread
/// joined when the returned guard drops.
fn spawn_metrics_server(body: &'static str) -> (u16, ServerGuard) {
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let handle = std::thread::spawn(move || {
        use std::io::{Read, Write};
        let Ok((mut sock, _)) = listener.accept() else {
            return;
        };
        let mut buf = [0u8; 1024];
        let _ = sock.read(&mut buf);
        let resp = format!(
            "HTTP/1.0 200 OK\r\nContent-Type: text/plain\r\nContent-Length: {}\r\n\r\n{body}",
            body.len()
        );
        let _ = sock.write_all(resp.as_bytes());
    });
    (port, ServerGuard(Some(handle)))
}

/// A server that accepts and then says nothing — an nginx with the wrong
/// upstream, a wedged process, a port-forward to nowhere.
fn spawn_silent_server() -> (u16, ServerGuard) {
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let handle = std::thread::spawn(move || {
        if let Ok((sock, _)) = listener.accept() {
            std::thread::sleep(std::time::Duration::from_millis(200));
            drop(sock);
        }
    });
    (port, ServerGuard(Some(handle)))
}

struct ServerGuard(Option<std::thread::JoinHandle<()>>);

impl Drop for ServerGuard {
    fn drop(&mut self) {
        if let Some(h) = self.0.take() {
            let _ = h.join();
        }
    }
}

// ---------------------------------------------------------------------------
// Disk
// ---------------------------------------------------------------------------

#[test]
fn disk_space_passes_with_a_low_threshold_and_warns_with_an_absurd_one() {
    let tmp = Tmp::new("disk");
    let mk = |min_free_mb: u64| DiskSpaceCheck {
        name: "disk.db-dir".to_string(),
        path: tmp.path().to_string_lossy().into_owned(),
        min_free_mb,
    };
    let o = mk(0).run();
    assert_eq!(o.status, Status::Pass, "{o:?}");
    assert!(o.detail.contains("MiB free"), "{o:?}");

    // No filesystem has an exabyte free, so this is the warn path without
    // needing to actually fill a disk.
    let o = mk(u64::MAX / (1024 * 1024)).run();
    assert_eq!(o.status, Status::Warn, "{o:?}");
    // A warning must not fail the run — an alert gate that flaps on disk usage
    // gets muted, and then the real failures are muted with it.
    assert!(!summarize(&[o]).1);
}

#[test]
fn disk_space_on_a_nonexistent_path_fails() {
    let o = DiskSpaceCheck {
        name: "disk.db-dir".to_string(),
        path: "/definitely/not/a/mounted/path/astrx".to_string(),
        min_free_mb: 1,
    }
    .run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
}

#[test]
fn df_parsing_survives_a_device_name_that_wraps_onto_its_own_line() {
    let normal = "Filesystem 1024-blocks    Used Available Capacity Mounted on\n\
                  /dev/sda1     41153856 8110272  30931968      21% /\n";
    assert_eq!(parse_df_available_kib(normal), Some(30_931_968));

    // `df` wraps a long device name; the numbers land on the next line. Reading
    // the 4th token of the *first* line here would silently report the wrong
    // number (or none), i.e. a disk-space check that never fires.
    let wrapped = "Filesystem 1024-blocks Used Available Capacity Mounted on\n\
                   /dev/mapper/a-very-long-volume-group-name-here\n\
                   \x2041153856 8110272 30931968 21% /data\n";
    assert_eq!(parse_df_available_kib(wrapped), Some(30_931_968));

    assert_eq!(parse_df_available_kib(""), None);
    assert_eq!(
        parse_df_available_kib("Filesystem\nnot numbers at all x\n"),
        None
    );
}

// ---------------------------------------------------------------------------
// Tor
// ---------------------------------------------------------------------------

#[test]
fn tor_checks_skip_when_no_proxy_is_configured() {
    for o in [
        TorSocksCheck {
            host: "127.0.0.1".to_string(),
            port: 0,
        }
        .run(),
        TorCircuitCheck {
            host: "127.0.0.1".to_string(),
            port: 0,
            target: "x.onion:80".to_string(),
        }
        .run(),
    ] {
        assert_eq!(o.status, Status::Skip, "{o:?}");
    }
    // Configured proxy, no probe target: the handshake is still worth doing,
    // but nothing may leave the box.
    let o = TorCircuitCheck {
        host: "127.0.0.1".to_string(),
        port: 9050,
        target: String::new(),
    }
    .run();
    assert_eq!(o.status, Status::Skip, "{o:?}");
    assert!(o.detail.contains("nothing left the box"), "{o:?}");
}

#[test]
fn a_dead_socks_port_fails() {
    // A successful connect is the tell that the port was taken between the
    // scavenge and the check: the point of the test is a port where the TCP
    // connect itself is refused.
    let o = on_a_free_port(
        |port| {
            TorSocksCheck {
                host: "127.0.0.1".to_string(),
                port,
            }
            .run()
        },
        |o| o.status == Status::Fail && o.detail.contains("nothing accepted a connection"),
    );
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("nothing accepted a connection"), "{o:?}");
}

#[test]
fn a_socks5_proxy_that_offers_no_auth_passes() {
    let (port, _srv) = spawn_socks(&[0x05, 0x00], None);
    let o = TorSocksCheck {
        host: "127.0.0.1".to_string(),
        port,
    }
    .run();
    assert_eq!(o.status, Status::Pass, "{o:?}");
}

#[test]
fn something_that_is_not_a_socks_proxy_fails_rather_than_passing_on_the_tcp_connect() {
    // An HTTP proxy on 9050 accepts the connection perfectly. Only completing
    // the handshake distinguishes it from Tor.
    let (port, _srv) = spawn_socks(b"HT", None);
    let o = TorSocksCheck {
        host: "127.0.0.1".to_string(),
        port,
    }
    .run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("not SOCKS5"), "{o:?}");
}

#[test]
fn a_proxy_demanding_authentication_fails_with_the_reason() {
    let (port, _srv) = spawn_socks(&[0x05, 0xFF], None);
    let o = TorSocksCheck {
        host: "127.0.0.1".to_string(),
        port,
    }
    .run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("no-auth"), "{o:?}");
}

#[test]
fn a_circuit_probe_passes_on_reply_0_and_fails_on_a_refusal() {
    let (port, _srv) = spawn_socks(&[0x05, 0x00], Some([0x05, 0x00, 0x00, 0x01]));
    let o = TorCircuitCheck {
        host: "127.0.0.1".to_string(),
        port,
        target: "example.onion:80".to_string(),
    }
    .run();
    assert_eq!(o.status, Status::Pass, "{o:?}");

    // 0x04 = host unreachable: the shape of a Tor that answers the handshake
    // but has not bootstrapped, which reads as "the internet is down" unless
    // this check is separate from the handshake check.
    let (port, _srv) = spawn_socks(&[0x05, 0x00], Some([0x05, 0x04, 0x00, 0x01]));
    let o = TorCircuitCheck {
        host: "127.0.0.1".to_string(),
        port,
        target: "example.onion:80".to_string(),
    }
    .run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.remedy.as_deref().unwrap().contains("bootstrap"), "{o:?}");
}

#[test]
fn a_malformed_tor_probe_target_is_a_failure_not_a_panic() {
    let o = TorCircuitCheck {
        host: "127.0.0.1".to_string(),
        port: 9050,
        target: "no-port-here".to_string(),
    }
    .run();
    assert_eq!(o.status, Status::Fail, "{o:?}");
    assert!(o.detail.contains("HOST:PORT"), "{o:?}");
}

/// A fake SOCKS5 server: replies `greeting` to the method negotiation, then
/// `connect` (if given) to the CONNECT request.
fn spawn_socks(greeting: &'static [u8], connect: Option<[u8; 4]>) -> (u16, ServerGuard) {
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    let handle = std::thread::spawn(move || {
        use std::io::{Read, Write};
        let Ok((mut sock, _)) = listener.accept() else {
            return;
        };
        let mut buf = [0u8; 512];
        let _ = sock.read(&mut buf);
        if sock.write_all(greeting).is_err() {
            return;
        }
        if let Some(reply) = connect {
            let _ = sock.read(&mut buf);
            // VER REP RSV ATYP + a 4-byte IPv4 BND.ADDR + 2-byte BND.PORT.
            let mut out = reply.to_vec();
            out.extend_from_slice(&[0, 0, 0, 0, 0, 0]);
            let _ = sock.write_all(&out);
        }
    });
    (port, ServerGuard(Some(handle)))
}

// ---------------------------------------------------------------------------
// git
// ---------------------------------------------------------------------------

#[test]
fn the_git_check_agrees_with_the_git_on_this_box() {
    let o = GitBinaryCheck::new().run();
    let installed = std::process::Command::new("git").arg("--version").output();
    match installed {
        Ok(out) if out.status.success() => {
            let banner = String::from_utf8_lossy(&out.stdout);
            let ver = parse_git_version(banner.trim()).expect("a real git banner must parse");
            let expected = if ver < MIN_GIT {
                Status::Fail
            } else {
                Status::Pass
            };
            assert_eq!(o.status, expected, "{o:?}");
        }
        // No git: the check must FAIL rather than quietly pass, because gitweb
        // cannot serve a single page without it.
        _ => assert_eq!(o.status, Status::Fail, "{o:?}"),
    }
}

#[test]
fn git_version_parsing_handles_the_banners_real_gits_print() {
    assert_eq!(parse_git_version("git version 2.43.0"), Some((2, 43)));
    assert_eq!(
        parse_git_version("git version 2.39.3 (Apple Git-145)"),
        Some((2, 39))
    );
    assert_eq!(
        parse_git_version("git version 2.22.0.windows.1"),
        Some((2, 22))
    );
    assert_eq!(parse_git_version("git version 2.20.1"), Some((2, 20)));
    // The floor itself: 2.21 is out, 2.22 is in. `git grep --max-count` landed
    // in 2.22, and below it every code-search request 500s while every other
    // gitweb view keeps working.
    assert!(parse_git_version("git version 2.21.9").unwrap() < MIN_GIT);
    assert!(parse_git_version("git version 2.22.0").unwrap() >= MIN_GIT);

    assert_eq!(parse_git_version("git version banana"), None);
    assert_eq!(parse_git_version(""), None);
}

// ---------------------------------------------------------------------------
// Runner, flags
// ---------------------------------------------------------------------------

#[test]
fn the_runner_exits_non_zero_only_when_something_failed() {
    use astrx::doctor::Outcome;
    let ok = vec![
        Outcome::pass("a", "fine"),
        Outcome::warn("b", "filling up", "add disk"),
        Outcome::skip("c", "not configured"),
    ];
    let (line, failed) = summarize(&ok);
    assert!(!failed, "{line}");
    assert_eq!(line, "1 passed, 1 warning(s), 0 failed, 1 skipped");

    let mut bad = ok;
    bad.push(Outcome::fail("d", "broken", "fix it"));
    assert!(summarize(&bad).1);
}

#[test]
fn a_rendered_outcome_carries_the_name_the_detail_and_the_remedy() {
    use astrx::doctor::Outcome;
    let text = Outcome::fail(
        "websearch.db",
        "/data/web.db does NOT load",
        "restore a backup",
    )
    .render();
    assert!(text.starts_with("FAIL "), "{text}");
    assert!(text.contains("websearch.db"), "{text}");
    assert!(text.contains("/data/web.db"), "{text}");
    assert!(text.contains("-> restore a backup"), "{text}");
}

#[test]
fn every_check_a_default_config_builds_actually_runs() {
    let tmp = Tmp::new("full-run");
    let cfg = DoctorConfig {
        db_dir: tmp.path().to_string_lossy().into_owned(),
        repo_root: tmp.path().to_string_lossy().into_owned(),
        // Ports nothing is listening on, so the run does not depend on what else
        // is running on the build machine.
        ports: vec![
            ("gitweb", free_port()),
            ("onioncrawler", free_port()),
            ("websearch", free_port()),
            ("torrentds", free_port()),
            ("suitedash", free_port()),
        ],
        min_free_mb: 0,
        ..DoctorConfig::default()
    };
    let checks = build_checks(&cfg);
    let outcomes = run_checks(&checks);
    assert_eq!(outcomes.len(), checks.len());
    // Names are unique and stable — they are what an alert greps for.
    let mut names: Vec<&str> = outcomes.iter().map(|o| o.name.as_str()).collect();
    names.sort_unstable();
    let before = names.len();
    names.dedup();
    assert_eq!(before, names.len(), "duplicate check names: {names:?}");
    for want in [
        "gitweb.git",
        "gitweb.repo-root",
        "onioncrawler.db",
        "websearch.db",
        "torrentds.db",
        "websearch.port",
        "disk.db-dir",
        "tor.socks",
        "tor.circuit",
    ] {
        assert!(names.contains(&want), "{want} missing from {names:?}");
    }
    // Nothing failed on a healthy temp directory with free ports, apart from a
    // git that may genuinely be missing on a build box.
    for o in &outcomes {
        assert!(
            o.status != Status::Fail || o.name == "gitweb.git",
            "unexpected failure: {o:?}"
        );
    }
}

#[test]
fn doctor_flags_parse_the_way_the_engine_clis_do() {
    let p = |args: &[&str]| parse_args(&args.iter().map(|s| (*s).to_string()).collect::<Vec<_>>());

    // `--flag=value` and `--flag value` must be the same command, because half
    // the suite's documented invocations use one and half the other.
    let a = p(&["--db-dir=/data", "--min-free-mb=64"]).unwrap().unwrap();
    let b = p(&["--db-dir", "/data", "--min-free-mb", "64"])
        .unwrap()
        .unwrap();
    assert_eq!(a, b);
    assert_eq!(a.db_dir, "/data");
    assert_eq!(a.min_free_mb, 64);

    let c = p(&["--port", "websearch=9999"]).unwrap().unwrap();
    assert_eq!(
        c.ports.iter().find(|(n, _)| *n == "websearch").unwrap().1,
        9999
    );

    assert!(p(&["-h"]).unwrap().is_none());
    assert!(p(&["--help"]).unwrap().is_none());

    for bad in [
        vec!["--db-dir"],
        vec!["--min-free-mb", "lots"],
        vec!["--port", "websearch"],
        vec!["--port", "nosuchengine=1"],
        vec!["--tor-port", "99999"],
        vec!["--nope"],
    ] {
        assert!(p(&bad).is_err(), "{bad:?} should be a usage error");
    }
}
