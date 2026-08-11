//! Loopback round-trips of the async HTTP client and the SSRF-checked fetch
//! pipeline (net feature) against a mock HTTP server — no external network.
//! Exercises `perform_request` (content-length, chunked, gzip) and
//! `fetcher::fetch` end-to-end (resolve → SSRF gate → pinned connect → request →
//! response → one-hop redirect), and — the crown jewel — that a loopback
//! (internal) address is REFUSED by default and only reachable through the
//! explicit `allow_hosts` exemption.
#![cfg(feature = "net")]

use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use websearch::fetcher::{fetch, FetchOpts};
use websearch::httpclient::perform_request;

fn find(b: &[u8], sep: &[u8]) -> Option<usize> {
    if b.len() < sep.len() {
        return None;
    }
    (0..=b.len() - sep.len()).find(|&i| &b[i..i + sep.len()] == sep)
}

async fn read_req_path(sock: &mut TcpStream) -> String {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 1024];
    while find(&buf, b"\r\n\r\n").is_none() {
        let n = sock.read(&mut tmp).await.unwrap();
        if n == 0 {
            break;
        }
        buf.extend_from_slice(&tmp[..n]);
    }
    let end = find(&buf, b"\r\n").unwrap_or(buf.len());
    String::from_utf8_lossy(&buf[..end])
        .split(' ')
        .nth(1)
        .unwrap_or("/")
        .to_string()
}

fn serve<F>(listener: TcpListener, count: usize, responder: F) -> tokio::task::JoinHandle<()>
where
    F: Fn(&str) -> Vec<u8> + Send + 'static,
{
    tokio::spawn(async move {
        for _ in 0..count {
            let (mut sock, _) = listener.accept().await.unwrap();
            let path = read_req_path(&mut sock).await;
            sock.write_all(&responder(&path)).await.unwrap();
        }
    })
}

fn unhex(s: &str) -> Vec<u8> {
    (0..s.len())
        .step_by(2)
        .map(|i| u8::from_str_radix(&s[i..i + 2], 16).unwrap())
        .collect()
}

/// FetchOpts that exempt a single loopback authority (so the mock server, which
/// is inherently internal, is reachable while the SSRF guard stays on).
fn opts_allow(port: u16) -> FetchOpts {
    FetchOpts {
        allow_hosts: vec![format!("127.0.0.1:{port}")],
        timeout: Duration::from_secs(5),
        ..FetchOpts::default()
    }
}

#[tokio::test]
async fn perform_request_content_length() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = serve(listener, 1, |_p| {
        b"HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 5\r\n\r\nhello".to_vec()
    });
    let mut stream = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
    let resp = perform_request(&mut stream, "GET", "h", "/", &[], 1_000_000)
        .await
        .unwrap();
    assert_eq!(resp.status, 200);
    assert_eq!(resp.body, b"hello");
    assert_eq!(resp.header("content-type"), Some("text/html"));
    assert!(resp.reusable);
    server.await.unwrap();
}

#[tokio::test]
async fn perform_request_chunked_and_gzip() {
    // chunked
    let l1 = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let p1 = l1.local_addr().unwrap().port();
    let s1 = serve(l1, 1, |_p| {
        b"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n"
            .to_vec()
    });
    let mut c1 = TcpStream::connect(("127.0.0.1", p1)).await.unwrap();
    let r1 = perform_request(&mut c1, "GET", "h", "/", &[], 1_000_000)
        .await
        .unwrap();
    assert_eq!(r1.body, b"Wikipedia");
    s1.await.unwrap();

    // gzip Content-Encoding (body = gzip("hello world"))
    let gz = unhex("1f8b0800000000000203cb48cdc9c95728cf2fca49010085114a0d0b000000");
    let l2 = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let p2 = l2.local_addr().unwrap().port();
    let s2 = serve(l2, 1, move |_p| {
        let mut r = format!(
            "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: {}\r\n\r\n",
            gz.len()
        )
        .into_bytes();
        r.extend_from_slice(&gz);
        r
    });
    let mut c2 = TcpStream::connect(("127.0.0.1", p2)).await.unwrap();
    let r2 = perform_request(&mut c2, "GET", "h", "/", &[], 1_000_000)
        .await
        .unwrap();
    assert_eq!(r2.body, b"hello world");
    s2.await.unwrap();
}

#[tokio::test]
async fn fetch_basic_via_exemption() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = serve(listener, 1, |_p| {
        b"HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello".to_vec()
    });
    let url = format!("http://127.0.0.1:{port}/");
    let res = fetch(&url, &opts_allow(port), None).await;
    assert!(res.ok(), "error: {:?}", res.error);
    assert_eq!(res.status, 200);
    assert_eq!(res.body, b"hello");
    assert_eq!(res.content_type, "text/html");
    assert_eq!(res.charset.as_deref(), Some("utf-8"));
    server.await.unwrap();
}

#[tokio::test]
async fn fetch_follows_redirect() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let dest = format!("http://127.0.0.1:{port}/final");
    let server = serve(listener, 2, move |path| {
        if path == "/final" {
            b"HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\narrived".to_vec()
        } else {
            format!("HTTP/1.1 301 Moved\r\nLocation: {dest}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").into_bytes()
        }
    });
    let url = format!("http://127.0.0.1:{port}/start");
    let res = fetch(&url, &opts_allow(port), None).await;
    assert!(res.ok(), "error: {:?}", res.error);
    assert_eq!(res.body, b"arrived");
    assert_eq!(res.final_url, format!("http://127.0.0.1:{port}/final"));
    assert_eq!(res.redirects, 1);
    server.await.unwrap();
}

#[tokio::test]
async fn fetch_refuses_internal_by_default() {
    // A loopback (internal) address with the guard ON and NO exemption: the SSRF
    // gate refuses it before any byte is sent. No server is even started.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    drop(listener); // nothing listens; the gate must refuse before connect anyway
    let url = format!("http://127.0.0.1:{port}/");
    let res = fetch(&url, &FetchOpts::default(), None).await;
    assert!(!res.ok());
    assert_eq!(res.status, 0);
    assert_eq!(
        res.error.as_deref(),
        Some("blocked-internal:127.0.0.1"),
        "the loopback address must be refused by the SSRF gate"
    );
}

#[tokio::test]
async fn fetch_honours_allow_callback() {
    // The allow(url) predicate refuses the URL before any resolve/connect.
    let deny = |_u: &str| false;
    let res = fetch(
        "http://127.0.0.1:1/",
        &FetchOpts::default(),
        Some(&deny as &(dyn Fn(&str) -> bool + Sync)),
    )
    .await;
    assert!(!res.ok());
    assert_eq!(res.error.as_deref(), Some("blocked"));
}
