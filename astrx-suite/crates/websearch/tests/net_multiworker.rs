//! Multi-worker + keep-alive loopback crawls (net feature): prove that a
//! concurrent crawl (`workers = 4`) and a pooled keep-alive crawl index the SAME
//! set of pages as the cross-checked single-worker crawl — determinism of the
//! indexed set under concurrency, and behavioural identity of the pooled
//! connector — while the SSRF gate still governs every fetch (the loopback site
//! is reachable only through the `allow_hosts` exemption). A focused pair of
//! tests drives the `Fetcher` directly to show the pool reuses one socket across
//! requests and still refuses an internal address by default.
#![cfg(feature = "net")]

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use websearch::crawler::Crawler;
use websearch::fetcher::{FetchOpts, Fetcher};
use websearch::CrawlConfig;

fn find(b: &[u8], sep: &[u8]) -> Option<usize> {
    if b.len() < sep.len() {
        return None;
    }
    (0..=b.len() - sep.len()).find(|&i| &b[i..i + sep.len()] == sep)
}

/// Read one HTTP request line's path from `sock`; `None` on a clean EOF (the
/// client closed a pooled/idle connection).
async fn read_req_path(sock: &mut TcpStream) -> Option<String> {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 1024];
    while find(&buf, b"\r\n\r\n").is_none() {
        match sock.read(&mut tmp).await {
            Ok(0) | Err(_) => break,
            Ok(n) => buf.extend_from_slice(&tmp[..n]),
        }
    }
    if buf.is_empty() {
        return None;
    }
    let end = find(&buf, b"\r\n").unwrap_or(buf.len());
    Some(
        String::from_utf8_lossy(&buf[..end])
            .split(' ')
            .nth(1)
            .unwrap_or("/")
            .to_string(),
    )
}

/// The mock site: robots.txt disallows `/private`; the home page links `/a`,
/// `/b`, and `/private`; `/a` links back to `/b`. Exactly the three reachable
/// allowed pages (`/`, `/a`, `/b`) should be indexed; `/private` is robots-blocked.
fn body_for(path: &str) -> (&'static str, &'static str, &'static str) {
    match path {
        "/robots.txt" => ("200 OK", "text/plain", "User-agent: *\nDisallow: /private\n"),
        "/" => (
            "200 OK",
            "text/html",
            "<html><head><title>Home</title></head><body><p>the home page of the site</p>\
             <a href=\"/a\">A</a> <a href=\"/b\">B</a> <a href=\"/private\">Secret</a></body></html>",
        ),
        "/a" => (
            "200 OK",
            "text/html",
            "<html><head><title>Page A</title></head><body><p>alpha content</p>\
             <a href=\"/b\">B again</a></body></html>",
        ),
        "/b" => (
            "200 OK",
            "text/html",
            "<html><head><title>Page B</title></head><body><p>beta leaf content</p></body></html>",
        ),
        "/private" => (
            "200 OK",
            "text/html",
            "<html><head><title>Secret</title></head><body>should be robots-blocked</body></html>",
        ),
        _ => ("404 Not Found", "text/plain", "nope"),
    }
}

fn response_bytes(path: &str, close: bool) -> Vec<u8> {
    let (status, ctype, body) = body_for(path);
    let conn = if close { "Connection: close\r\n" } else { "" };
    format!(
        "HTTP/1.1 {status}\r\nContent-Type: {ctype}\r\nContent-Length: {}\r\n{conn}\r\n{body}",
        body.len()
    )
    .into_bytes()
}

/// A mock site that answers each accepted connection with a single
/// `Connection: close` response (one request per connection), the same wire
/// behaviour as the cross-checked single-worker crawl test. Serves until aborted.
fn serve_close(listener: TcpListener, conns: Arc<AtomicUsize>) -> tokio::task::JoinHandle<()> {
    tokio::spawn(async move {
        loop {
            let Ok((mut sock, _)) = listener.accept().await else {
                break;
            };
            conns.fetch_add(1, Ordering::SeqCst);
            if let Some(path) = read_req_path(&mut sock).await {
                let _ = sock.write_all(&response_bytes(&path, true)).await;
            }
        }
    })
}

/// A keep-alive mock site: each accepted connection serves MANY requests
/// (HTTP/1.1, `Content-Length`-framed, no `Connection: close`) until the client
/// closes it. Counts accepted connections, so connection REUSE surfaces as fewer
/// connections than requests. Serves until aborted.
fn serve_keepalive(listener: TcpListener, conns: Arc<AtomicUsize>) -> tokio::task::JoinHandle<()> {
    tokio::spawn(async move {
        loop {
            let Ok((mut sock, _)) = listener.accept().await else {
                break;
            };
            conns.fetch_add(1, Ordering::SeqCst);
            // One task per connection so several may be served at once.
            tokio::spawn(async move {
                while let Some(path) = read_req_path(&mut sock).await {
                    if sock.write_all(&response_bytes(&path, false)).await.is_err() {
                        break;
                    }
                }
            });
        }
    })
}

fn crawl_config(port: u16, allow_loopback: bool) -> CrawlConfig {
    CrawlConfig {
        allow_hosts: if allow_loopback {
            vec![format!("127.0.0.1:{port}")]
        } else {
            Vec::new()
        },
        base_delay: 0.0,
        jitter: 0.0,
        ..CrawlConfig::default()
    }
}

/// Assert `cr` indexed exactly the three allowed pages of the mock site — the
/// same set the single-worker crawl produces (see `net_crawler.rs`).
fn assert_indexed_the_allowed_set(cr: &Crawler, port: u16, stats: &websearch::CrawlStats) {
    let url = |p: &str| format!("http://127.0.0.1:{port}{p}");
    assert_eq!(cr.index().doc_count(), 3, "stats={stats:?}");
    assert!(cr.index().get_doc(&url("/")).is_some(), "/ missing");
    assert!(cr.index().get_doc(&url("/a")).is_some(), "/a missing");
    assert!(cr.index().get_doc(&url("/b")).is_some(), "/b missing");
    assert!(
        cr.index().get_doc(&url("/private")).is_none(),
        "/private must be robots-blocked"
    );
    assert_eq!(stats.indexed, 3, "stats={stats:?}");
    assert!(stats.robots_blocked >= 1, "stats={stats:?}");
    // The link graph was recorded (home links /a, /b, /private).
    assert!(cr.index().stats().links >= 3, "link graph not recorded");
    // Titles came through htmlparse, same as the single-worker crawl.
    assert_eq!(cr.index().get_doc(&url("/a")).unwrap().title, "Page A");
}

#[tokio::test]
async fn multiworker_indexes_same_set_as_single_worker() {
    // Four workers share the frontier + index + a global budget. Atomic leasing
    // keeps every reachable allowed page indexed exactly once, so the indexed set
    // is identical to the single-worker crawl despite nondeterministic fetch order.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let conns = Arc::new(AtomicUsize::new(0));
    let server = serve_close(listener, conns);

    let mut cfg = crawl_config(port, true);
    cfg.workers = 4;
    let mut cr = Crawler::new(cfg);
    let seed = format!("http://127.0.0.1:{port}/");
    assert_eq!(cr.add_seeds(&[&seed]), 1);
    let stats = cr.run(Some(100)).await;
    server.abort();

    assert_indexed_the_allowed_set(&cr, port, &stats);
}

#[tokio::test]
async fn keep_alive_indexes_same_set() {
    // A pooled keep-alive crawl over the same site indexes the same pages: the
    // pooled connector is behaviourally identical, and still SSRF-gated (the
    // loopback site is reachable only via the allow_hosts exemption).
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let conns = Arc::new(AtomicUsize::new(0));
    let server = serve_keepalive(listener, conns);

    let mut cfg = crawl_config(port, true);
    cfg.keep_alive = true;
    let mut cr = Crawler::new(cfg);
    let seed = format!("http://127.0.0.1:{port}/");
    cr.add_seeds(&[&seed]);
    let stats = cr.run(Some(100)).await;
    server.abort();

    assert_indexed_the_allowed_set(&cr, port, &stats);
}

#[tokio::test]
async fn multiworker_keep_alive_indexes_same_set() {
    // Both paths at once: four workers, each with its OWN pooled Fetcher, sharing
    // the frontier/index. Still exactly the three allowed pages.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let conns = Arc::new(AtomicUsize::new(0));
    let server = serve_keepalive(listener, conns);

    let mut cfg = crawl_config(port, true);
    cfg.workers = 4;
    cfg.keep_alive = true;
    let mut cr = Crawler::new(cfg);
    let seed = format!("http://127.0.0.1:{port}/");
    cr.add_seeds(&[&seed]);
    let stats = cr.run(Some(100)).await;
    server.abort();

    assert_indexed_the_allowed_set(&cr, port, &stats);
}

// Two worker threads, not four. Two is the smallest count that makes what this
// test exists to show happen at all: two crawl workers running at the same
// instant on different CPUs, genuinely contending for the shared frontier/index
// mutex. On one thread the four worker tasks only interleave cooperatively,
// which is what the `#[tokio::test]` cases above already cover. Going past two
// does not sharpen the property — the contention is already real at two — and on
// the 2-core CI runner there is no third CPU to run a third thread on, so the
// extra two threads bought nothing here and only took scheduling away from work
// that needed it. Not from the other test binaries: `cargo test` runs those one
// at a time, each to completion, so none of them is alive while this one is. From
// this binary's own work. libtest runs up to `available_parallelism()` tests
// concurrently — 2 on that runner — so this test is sharing the two cores with
// one of the seven `current_thread` cases around it, and with the mock server's
// tasks on its own runtime.
//
// Two does not deadlock. The workers take `std::sync::Mutex` only across
// synchronous sections — `crawler.rs` acquires and drops the guard within a
// statement, and the network fetch runs lock-free — so a worker thread is never
// parked waiting on a task that itself needs a thread to make progress. The mock
// server's accept loop and its per-connection tasks are ordinary async tasks and
// multiplex onto whichever thread is free.
#[tokio::test(flavor = "multi_thread", worker_threads = 2)]
async fn multiworker_indexes_same_set_under_real_parallelism() {
    // The determinism test on a real multi-threaded runtime: the workers genuinely
    // run in parallel and contend on the shared frontier/index mutex, so this
    // exercises atomic leasing (no URL processed twice) and shows there is no
    // deadlock under true concurrency. Outcome is still driven to completion by the
    // budget + drain, so it stays deterministic (not timing-dependent).
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let conns = Arc::new(AtomicUsize::new(0));
    let server = serve_keepalive(listener, conns);

    let mut cfg = crawl_config(port, true);
    cfg.workers = 4;
    let mut cr = Crawler::new(cfg);
    let seed = format!("http://127.0.0.1:{port}/");
    cr.add_seeds(&[&seed]);
    let stats = cr.run(Some(100)).await;
    server.abort();

    assert_indexed_the_allowed_set(&cr, port, &stats);
}

#[tokio::test]
async fn keep_alive_crawl_is_ssrf_gated() {
    // keep_alive=true but WITHOUT the allow_hosts exemption: the SSRF gate refuses
    // every fetch (127.0.0.1 is internal), so nothing is indexed — a pooled socket
    // never bypasses the gate.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let conns = Arc::new(AtomicUsize::new(0));
    let server = serve_keepalive(listener, conns);

    let mut cfg = crawl_config(port, false); // block_internal stays true, no exemption
    cfg.keep_alive = true;
    let mut cr = Crawler::new(cfg);
    let seed = format!("http://127.0.0.1:{port}/");
    cr.add_seeds(&[&seed]);
    let stats = cr.run(Some(100)).await;
    server.abort();

    assert_eq!(cr.index().doc_count(), 0);
    assert_eq!(stats.indexed, 0);
    assert!(
        stats.errors >= 1,
        "the loopback seed must error out, stats={stats:?}"
    );
}

#[tokio::test]
async fn keep_alive_reuses_connections() {
    // Keep-alive reuses one pooled socket for many same-host requests, so it opens
    // strictly fewer connections than a fresh-per-request crawl over the same site.
    // Deterministic (single worker, sequential fetches) — no sleeps, no flakiness.
    let l1 = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let p1 = l1.local_addr().unwrap().port();
    let c1 = Arc::new(AtomicUsize::new(0));
    let s1 = serve_keepalive(l1, Arc::clone(&c1));
    let mut cfg1 = crawl_config(p1, true);
    cfg1.keep_alive = true;
    let mut cr1 = Crawler::new(cfg1);
    cr1.add_seeds(&[&format!("http://127.0.0.1:{p1}/")]);
    cr1.run(Some(100)).await;
    s1.abort();
    let keep_alive_conns = c1.load(Ordering::SeqCst);

    let l2 = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let p2 = l2.local_addr().unwrap().port();
    let c2 = Arc::new(AtomicUsize::new(0));
    let s2 = serve_keepalive(l2, Arc::clone(&c2));
    // keep_alive defaults to false → Connection: close → one connection per fetch.
    let mut cr2 = Crawler::new(crawl_config(p2, true));
    cr2.add_seeds(&[&format!("http://127.0.0.1:{p2}/")]);
    cr2.run(Some(100)).await;
    s2.abort();
    let closed_conns = c2.load(Ordering::SeqCst);

    assert!(
        keep_alive_conns >= 1,
        "keep-alive opened at least one connection"
    );
    assert!(
        keep_alive_conns < closed_conns,
        "keep-alive should reuse connections: keep_alive={keep_alive_conns} vs closed={closed_conns}"
    );
}

#[tokio::test]
async fn fetcher_pools_and_reuses_socket() {
    // Drive the Fetcher directly: two fetches of the same authority open ONE socket
    // and reuse it, and the server sees a single connection.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let conns = Arc::new(AtomicUsize::new(0));
    let server = serve_keepalive(listener, Arc::clone(&conns));

    let opts = FetchOpts {
        allow_hosts: vec![format!("127.0.0.1:{port}")],
        timeout: Duration::from_secs(5),
        ..FetchOpts::default()
    };
    let mut f = Fetcher::new(true);
    let url = format!("http://127.0.0.1:{port}/");

    let r1 = f.fetch(&url, &opts, None).await;
    assert!(r1.ok(), "first fetch: {:?}", r1.error);
    assert_eq!(f.opened(), 1);
    assert_eq!(f.reused(), 0);

    let r2 = f.fetch(&url, &opts, None).await;
    assert!(r2.ok(), "second fetch: {:?}", r2.error);
    assert_eq!(r2.body, r1.body, "reused connection returns the same body");
    assert_eq!(f.opened(), 1, "no new socket opened on the second fetch");
    assert_eq!(f.reused(), 1, "the pooled socket was reused");
    assert_eq!(conns.load(Ordering::SeqCst), 1, "server saw one connection");

    f.close();
    server.abort();
}

#[tokio::test]
async fn fetcher_refuses_internal_by_default() {
    // The pooled fetcher refuses a loopback address with the guard on and no
    // exemption, before any socket is opened — the SSRF gate governs the pool too.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    drop(listener); // nothing listens; the gate must refuse before connect anyway
    let mut f = Fetcher::new(true);
    let url = format!("http://127.0.0.1:{port}/");
    let res = f.fetch(&url, &FetchOpts::default(), None).await;
    assert!(!res.ok());
    assert_eq!(res.error.as_deref(), Some("blocked-internal:127.0.0.1"));
    assert_eq!(f.opened(), 0, "no socket opened");
    assert_eq!(f.reused(), 0, "nothing pooled to reuse");
}
