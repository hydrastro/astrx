//! Loopback end-to-end tests for the `net` tier: real HTTP round-trips against
//! a server bound on `127.0.0.1:0`, serving the deterministic fixture repository
//! set from `tests/common/mod.rs`.
//!
//! Covered: a page renders over a socket, a raw blob streams byte-for-byte, an
//! archive streams a real gzip tarball, `HEAD` returns the `GET` headers with no
//! body, gzip content-coding decodes back to the identical document, keep-alive
//! serves two requests on one connection, HTTP Basic access control rejects and
//! accepts, and — the one that proves the whole Smart-HTTP path — a real
//! `git clone http://127.0.0.1:PORT/<repo>` succeeds and reproduces HEAD, both
//! branches and the tags, over protocol v0 *and* v2, with `git fetch` after it.
//! Everything is hermetic: no network beyond loopback, no ambient git config.
#![cfg(feature = "net")]

mod common;

use std::path::Path;
use std::process::{Command, Stdio};
use std::sync::Arc;

use gitweb::server::{serve, Config, Server};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

// --------------------------------------------------------------------------- //
// Harness
// --------------------------------------------------------------------------- //

/// A server running on loopback for the lifetime of the test.
struct Live {
    port: u16,
    handle: tokio::task::JoinHandle<std::io::Result<()>>,
}

impl Live {
    fn base(&self) -> String {
        format!("http://127.0.0.1:{}", self.port)
    }
    fn stop(self) {
        self.handle.abort();
    }
}

async fn spawn(config: Config) -> Live {
    let server = Server::new(config).expect("server");
    let listener = TcpListener::bind("127.0.0.1:0").await.expect("bind");
    let port = listener.local_addr().expect("addr").port();
    let handle = tokio::spawn(serve(listener, Arc::new(server)));
    Live { port, handle }
}

fn config(root: &Path) -> Config {
    Config {
        verbose: false,
        ..Config::new(root)
    }
}

/// One request/response round-trip on a fresh connection; returns the raw bytes.
///
/// Write and read errors are tolerated and whatever arrived is returned: a
/// server that refuses a request before reading its body may close the
/// connection under us, and losing the reply to that race would make the test
/// flaky rather than meaningful. The assertions on the returned bytes are what
/// decide the outcome.
async fn raw_request(port: u16, request: &[u8]) -> Vec<u8> {
    let mut sock = TcpStream::connect(("127.0.0.1", port))
        .await
        .expect("connect");
    let _ = sock.write_all(request).await;
    let _ = sock.flush().await;
    let mut out = Vec::new();
    let mut tmp = [0u8; 8192];
    loop {
        match tokio::time::timeout(std::time::Duration::from_secs(30), sock.read(&mut tmp)).await {
            Ok(Ok(0)) | Ok(Err(_)) | Err(_) => break,
            Ok(Ok(n)) => out.extend_from_slice(&tmp[..n]),
        }
    }
    out
}

/// Read from `sock` until `needle` appears (or the deadline passes).
async fn read_until(sock: &mut TcpStream, needle: &str) -> String {
    let mut out = Vec::new();
    let mut tmp = [0u8; 8192];
    while !String::from_utf8_lossy(&out).contains(needle) {
        match tokio::time::timeout(std::time::Duration::from_secs(30), sock.read(&mut tmp)).await {
            Ok(Ok(0)) | Ok(Err(_)) | Err(_) => break,
            Ok(Ok(n)) => out.extend_from_slice(&tmp[..n]),
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// Split a raw response into `(head, body)`.
fn split(resp: &[u8]) -> (String, Vec<u8>) {
    let sep = b"\r\n\r\n";
    let at = (0..resp.len().saturating_sub(3))
        .find(|&i| &resp[i..i + 4] == sep)
        .unwrap_or(resp.len());
    (
        String::from_utf8_lossy(&resp[..at]).into_owned(),
        resp.get(at + 4..).unwrap_or(&[]).to_vec(),
    )
}

async fn get(port: u16, path: &str) -> (String, Vec<u8>) {
    let req = format!("GET {path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    split(&raw_request(port, req.as_bytes()).await)
}

async fn get_with(port: u16, path: &str, headers: &str) -> (String, Vec<u8>) {
    let req =
        format!("GET {path} HTTP/1.1\r\nHost: 127.0.0.1\r\n{headers}Connection: close\r\n\r\n");
    split(&raw_request(port, req.as_bytes()).await)
}

/// The hermetic environment every `git` child in this test runs under.
fn git_env(cmd: &mut Command) -> &mut Command {
    cmd.env("HOME", "/nonexistent")
        .env("GIT_CONFIG_GLOBAL", "/dev/null")
        .env("GIT_CONFIG_SYSTEM", "/dev/null")
        .env("GIT_TERMINAL_PROMPT", "0")
        .env("GIT_AUTHOR_NAME", "Test Author")
        .env("GIT_AUTHOR_EMAIL", "author@example.com")
        .env("GIT_COMMITTER_NAME", "Test Author")
        .env("GIT_COMMITTER_EMAIL", "author@example.com")
        .env_remove("http_proxy")
        .env_remove("https_proxy")
        .env_remove("HTTP_PROXY")
        .env_remove("HTTPS_PROXY")
        .env_remove("ALL_PROXY")
        .env_remove("all_proxy")
        .stdin(Stdio::null())
}

fn git_capture(cwd: &Path, args: &[&str]) -> String {
    let out = git_env(Command::new("git").args(args).current_dir(cwd))
        .output()
        .expect("run git");
    assert!(
        out.status.success(),
        "git {args:?} failed: {}",
        String::from_utf8_lossy(&out.stderr)
    );
    String::from_utf8_lossy(&out.stdout).trim().to_string()
}

/// Run `git clone` (optionally pinning the wire protocol version).
fn git_clone(url: &str, dst: &Path, version: Option<u8>) -> std::process::Output {
    let mut args: Vec<String> = Vec::new();
    if let Some(v) = version {
        args.push("-c".to_string());
        args.push(format!("protocol.version={v}"));
    }
    // Never route the loopback clone through an ambient http proxy.
    args.push("-c".to_string());
    args.push("http.proxy=".to_string());
    args.push("clone".to_string());
    args.push(url.to_string());
    args.push(dst.display().to_string());
    git_env(Command::new("git").args(&args))
        .output()
        .expect("run git clone")
}

fn temp_dir(tag: &str) -> std::path::PathBuf {
    use std::sync::atomic::{AtomicU64, Ordering};
    static SEQ: AtomicU64 = AtomicU64::new(0);
    let nanos = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map_or(0, |d| d.as_nanos());
    let n = SEQ.fetch_add(1, Ordering::Relaxed);
    let dir = std::env::temp_dir().join(format!(
        "gitweb-net-{tag}-{}-{nanos}-{n}",
        std::process::id()
    ));
    std::fs::create_dir_all(&dir).expect("mkdir");
    dir
}

/// A scratch directory removed when dropped.
struct Scratch(std::path::PathBuf);

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.0);
    }
}

// --------------------------------------------------------------------------- //
// Browsing over a socket
// --------------------------------------------------------------------------- //

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn pages_blobs_and_archives_round_trip_over_http() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build();
    let live = spawn(config(&fx.root)).await;

    // A page renders, with the security headers, over a real socket.
    let (head, body) = get(live.port, "/xrepo/").await;
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n"), "{head}");
    assert!(head.contains("Content-Type: text/html; charset=utf-8"));
    assert!(head.contains("X-Content-Type-Options: nosniff"));
    assert!(head.contains("Content-Security-Policy: default-src 'none'"));
    let text = String::from_utf8_lossy(&body);
    assert!(text.starts_with("<!doctype html>"), "{text}");
    assert!(text.contains("Default branch"));
    assert!(text.contains("git clone http://127.0.0.1"));
    assert!(!text.contains("<script"));

    // A raw blob streams byte-for-byte.
    let (head, body) = get(live.port, "/xrepo/raw?ref=main&path=apple.txt").await;
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n"));
    assert!(head.contains("Content-Type: text/plain; charset=utf-8"));
    assert!(head.contains("Content-Disposition: inline; filename=\"apple.txt\""));
    assert_eq!(body, b"apple\n");

    // ...including a binary one.
    let (head, body) = get(live.port, "/xrepo/raw?ref=main&path=assets/logo.bin").await;
    assert!(head.contains("Content-Type: application/octet-stream"));
    assert_eq!(body, common::BINARY);

    // The archive streams a real gzip tarball containing the prefixed tree.
    let (head, body) = get(live.port, "/xrepo/archive?ref=main").await;
    assert!(head.contains("Content-Type: application/gzip"));
    assert!(head.contains("filename=\"xrepo-main.tar.gz\""));
    assert_eq!(&body[..2], &[0x1f, 0x8b], "not gzip");
    let (tar, _) = crawlcore::inflate::inflate_gzip(&body, 64 << 20).expect("gunzip");
    assert!(String::from_utf8_lossy(&tar).contains("xrepo-main/README.md"));

    // A patch downloads as a mailbox.
    let (head, body) = get(live.port, &format!("/xrepo/commit.patch?id={}", fx.shas[1])).await;
    assert!(head.contains("Content-Disposition: attachment;"));
    assert!(String::from_utf8_lossy(&body).starts_with("From "));

    // Errors render the HTML error page with the right status.
    let (head, body) = get(live.port, "/nope/").await;
    assert!(head.starts_with("HTTP/1.1 404 Not Found\r\n"), "{head}");
    assert!(String::from_utf8_lossy(&body).contains("Error 404"));
    let (head, _) = get(live.port, "/xrepo/log?ref=--evil").await;
    assert!(head.starts_with("HTTP/1.1 400 Bad Request\r\n"), "{head}");

    live.stop();
    drop(fx);
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn head_gzip_and_keep_alive_behave() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build();
    let live = spawn(config(&fx.root)).await;

    // HEAD: identical headers, no body.
    let plain = get(live.port, "/xrepo/refs").await;
    let req = "HEAD /xrepo/refs HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n";
    let (head, body) = split(&raw_request(live.port, req.as_bytes()).await);
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n"));
    assert_eq!(head, plain.0, "HEAD headers must equal GET's");
    assert!(body.is_empty(), "HEAD must have no body");

    // gzip: the coded body decodes back to the identical document.
    let (head, coded) = get_with(live.port, "/xrepo/refs", "Accept-Encoding: gzip\r\n").await;
    assert!(head.contains("Content-Encoding: gzip"));
    assert!(head.contains("Vary: Accept-Encoding"));
    assert!(coded.len() < plain.1.len(), "gzip did not compress");
    let (back, _) = crawlcore::inflate::inflate_gzip(&coded, 64 << 20).expect("gunzip");
    assert_eq!(back, plain.1);

    // A conditional GET revalidates to 304 with no body.
    let (head, _) = get(live.port, "/xrepo/blob?ref=main&path=apple.txt").await;
    let etag = head
        .lines()
        .find_map(|l| l.strip_prefix("ETag: "))
        .expect("etag")
        .trim()
        .to_string();
    let (head, body) = get_with(
        live.port,
        "/xrepo/blob?ref=main&path=apple.txt",
        &format!("If-None-Match: {etag}\r\n"),
    )
    .await;
    assert!(head.starts_with("HTTP/1.1 304 Not Modified\r\n"), "{head}");
    assert!(body.is_empty());

    // Keep-alive: two requests on one connection.
    let mut sock = TcpStream::connect(("127.0.0.1", live.port))
        .await
        .expect("connect");
    sock.write_all(b"GET /health HTTP/1.1\r\nHost: x\r\n\r\n")
        .await
        .expect("write");
    let first = read_until(&mut sock, "ok\n").await;
    assert!(
        first.contains("ok\n"),
        "first request was not served: {first}"
    );
    sock.write_all(b"GET /health HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
        .await
        .expect("write 2");
    let second = read_until(&mut sock, "ok\n").await;
    assert!(
        second.contains("ok\n"),
        "second keep-alive request was not served: {second}"
    );

    live.stop();
    drop(fx);
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn access_control_rejects_and_accepts_over_http() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build();
    let spec = format!(
        "alice:{}",
        gitweb::auth::hash_password("s3cret", "abcd1234")
    );
    let live = spawn(Config {
        auth: spec,
        ..config(&fx.root)
    })
    .await;

    let (head, _) = get(live.port, "/").await;
    assert!(head.starts_with("HTTP/1.1 401 Unauthorized\r\n"), "{head}");
    assert!(head.contains("WWW-Authenticate: Basic realm=\"gitweb\""));

    // YWxpY2U6czNjcmV0 == "alice:s3cret"
    let (head, body) = get_with(
        live.port,
        "/xrepo/",
        "Authorization: Basic YWxpY2U6czNjcmV0\r\n",
    )
    .await;
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n"), "{head}");
    assert!(String::from_utf8_lossy(&body).contains("Default branch"));

    // YWxpY2U6bm9wZQ== == "alice:nope"
    let (head, _) = get_with(
        live.port,
        "/xrepo/",
        "Authorization: Basic YWxpY2U6bm9wZQ==\r\n",
    )
    .await;
    assert!(head.starts_with("HTTP/1.1 401 Unauthorized\r\n"), "{head}");

    // An unauthenticated clone fails; an authenticated one succeeds.
    let scratch = Scratch(temp_dir("auth-clone"));
    let anon = git_clone(
        &format!("{}/xrepo", live.base()),
        &scratch.0.join("anon"),
        None,
    );
    assert!(!anon.status.success(), "an unauthenticated clone succeeded");
    let authed = git_clone(
        &format!("http://alice:s3cret@127.0.0.1:{}/xrepo", live.port),
        &scratch.0.join("authed"),
        None,
    );
    assert!(
        authed.status.success(),
        "authenticated clone failed: {}",
        String::from_utf8_lossy(&authed.stderr)
    );
    assert_eq!(
        git_capture(&scratch.0.join("authed"), &["rev-parse", "HEAD"]),
        fx.shas[5]
    );

    live.stop();
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Git Smart HTTP: a real `git clone` over the served HTTP
// --------------------------------------------------------------------------- //

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn git_clone_and_fetch_work_over_the_served_http() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build();
    let live = spawn(config(&fx.root)).await;
    let scratch = Scratch(temp_dir("clone"));

    // The advertisement is well-formed pkt-line, with the service banner.
    let (head, body) = get(live.port, "/xrepo/info/refs?service=git-upload-pack").await;
    assert!(head.contains("Content-Type: application/x-git-upload-pack-advertisement"));
    assert!(head.contains("Cache-Control: no-cache"));
    assert!(
        body.starts_with(b"001e# service=git-upload-pack\n0000"),
        "{head}"
    );

    // Protocol v0/v1: a real clone reproduces HEAD, both branches and the tags.
    let dst = scratch.0.join("v1");
    let res = git_clone(&format!("{}/xrepo", live.base()), &dst, None);
    assert!(
        res.status.success(),
        "git clone failed: {}",
        String::from_utf8_lossy(&res.stderr)
    );
    assert_eq!(git_capture(&dst, &["rev-parse", "HEAD"]), fx.shas[5]);
    let remotes = git_capture(&dst, &["branch", "-r"]);
    assert!(remotes.contains("origin/main"), "{remotes}");
    assert!(remotes.contains("origin/feature"), "{remotes}");
    let tags = git_capture(&dst, &["tag"]);
    assert!(tags.contains("v1.0") && tags.contains("v2.0"), "{tags}");
    assert!(git_capture(&dst, &["log", "--oneline"]).contains("Add README and sources"));

    // A subsequent fetch against the same endpoint also works.
    let fetched = git_env(
        Command::new("git")
            .args(["-c", "http.proxy=", "fetch", "--all"])
            .current_dir(&dst),
    )
    .output()
    .expect("run git fetch");
    assert!(
        fetched.status.success(),
        "git fetch failed: {}",
        String::from_utf8_lossy(&fetched.stderr)
    );

    // Protocol v2: no service banner, and the clone still works.
    let (_head, body) = get_with(
        live.port,
        "/xrepo/info/refs?service=git-upload-pack",
        "Git-Protocol: version=2\r\n",
    )
    .await;
    assert!(!body.starts_with(b"001e# service="));
    assert!(String::from_utf8_lossy(&body[..32]).contains("version 2"));
    let dst2 = scratch.0.join("v2");
    let res = git_clone(&format!("{}/xrepo", live.base()), &dst2, Some(2));
    assert!(
        res.status.success(),
        "protocol v2 clone failed: {}",
        String::from_utf8_lossy(&res.stderr)
    );
    assert_eq!(git_capture(&dst2, &["rev-parse", "HEAD"]), fx.shas[5]);

    // Cloning the bare mirror works too.
    let dst3 = scratch.0.join("bare");
    let res = git_clone(&format!("{}/bare.git", live.base()), &dst3, None);
    assert!(
        res.status.success(),
        "bare clone failed: {}",
        String::from_utf8_lossy(&res.stderr)
    );
    assert_eq!(git_capture(&dst3, &["rev-parse", "HEAD"]), fx.shas[5]);

    // Push is refused, and an unknown repository 404s.
    let req = b"POST /xrepo/git-receive-pack HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\n\
                Connection: close\r\n\r\n0000";
    let (head, _) = split(&raw_request(live.port, req).await);
    assert!(head.starts_with("HTTP/1.1 403 Forbidden\r\n"), "{head}");
    let (head, _) = get(live.port, "/nope/info/refs?service=git-upload-pack").await;
    assert!(head.starts_with("HTTP/1.1 404 Not Found\r\n"), "{head}");

    live.stop();
    drop(fx);
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn a_clone_against_a_clone_disabled_server_fails_while_browsing_works() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build();
    let live = spawn(Config {
        enable_clone: false,
        ..config(&fx.root)
    })
    .await;

    let (head, _) = get(live.port, "/xrepo/info/refs?service=git-upload-pack").await;
    assert!(head.starts_with("HTTP/1.1 404 Not Found\r\n"), "{head}");
    let scratch = Scratch(temp_dir("noclone"));
    let res = git_clone(
        &format!("{}/xrepo", live.base()),
        &scratch.0.join("r"),
        None,
    );
    assert!(!res.status.success(), "clone succeeded with clone disabled");

    // Browsing is unaffected, and no clone command is advertised.
    let (head, body) = get(live.port, "/xrepo/").await;
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n"));
    assert!(!String::from_utf8_lossy(&body).contains("git clone"));

    live.stop();
    drop(fx);
}

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn a_chunked_and_an_over_large_post_body_are_handled() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build();
    let live = spawn(Config {
        clone_max_body_bytes: 16,
        ..config(&fx.root)
    })
    .await;

    // A chunked flush-only request is framed correctly and served.
    let req = b"POST /xrepo/git-upload-pack HTTP/1.1\r\nHost: x\r\n\
                Transfer-Encoding: chunked\r\nConnection: close\r\n\r\n4\r\n0000\r\n0\r\n\r\n";
    let (head, _) = split(&raw_request(live.port, req).await);
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n"), "{head}");
    assert!(head.contains("Content-Type: application/x-git-upload-pack-result"));

    // An over-large declared body is refused before any pack work.
    let big = "X".repeat(1024);
    let req = format!(
        "POST /xrepo/git-upload-pack HTTP/1.1\r\nHost: x\r\nContent-Length: {}\r\n\
         Connection: close\r\n\r\n{big}",
        big.len()
    );
    let (head, _) = split(&raw_request(live.port, req.as_bytes()).await);
    assert!(head.starts_with("HTTP/1.1 400 Bad Request\r\n"), "{head}");

    live.stop();
    drop(fx);
}
