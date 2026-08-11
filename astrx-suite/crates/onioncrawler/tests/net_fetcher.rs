//! Loopback round-trips of the async HTTP client and the full fetch pipeline
//! (net feature) against a mock HTTP server — no Tor needed. Exercises
//! `perform_request` (content-length, chunked, gzip decompression) and
//! `Fetcher`/`DirectFetcher.fetch` end-to-end (canonicalize → the `&OnionHost`
//! anti-leak gate → request → response → one-hop redirect), plus that a clearnet
//! URL is refused before any socket opens.
#![cfg(feature = "net")]

use onioncrawler::fetcher::{Fetcher, Transport};
use onioncrawler::http::perform_request;
use std::collections::HashMap;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

fn v3() -> String {
    format!("{}.onion", "a".repeat(56))
}

fn find(b: &[u8], sep: &[u8]) -> Option<usize> {
    if b.len() < sep.len() {
        return None;
    }
    (0..=b.len() - sep.len()).find(|&i| &b[i..i + sep.len()] == sep)
}

/// Read a request off `sock` and return its request-line path.
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

/// Serve `count` connections, each answered by `responder(path)`.
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

#[tokio::test]
async fn perform_request_content_length() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = serve(listener, 1, |_p| {
        b"HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 5\r\n\r\nhello".to_vec()
    });
    let mut stream = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
    let resp = perform_request(&mut stream, "GET", "h.onion", "/", &[], 1_000_000)
        .await
        .unwrap();
    assert_eq!(resp.status, 200);
    assert_eq!(resp.body, b"hello");
    assert_eq!(resp.header("content-type"), Some("text/html"));
    assert!(resp.reusable); // HTTP/1.1, framed, not truncated
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
    let r1 = perform_request(&mut c1, "GET", "h.onion", "/", &[], 1_000_000)
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
    let r2 = perform_request(&mut c2, "GET", "h.onion", "/", &[], 1_000_000)
        .await
        .unwrap();
    assert_eq!(r2.body, b"hello world");
    s2.await.unwrap();
}

#[tokio::test]
async fn fetch_basic_via_direct() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = serve(listener, 1, |_p| {
        b"HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello".to_vec()
    });

    let mut map = HashMap::new();
    map.insert(v3(), ("127.0.0.1".to_string(), port));
    let f = Fetcher::direct(map);
    let res = f.fetch(&format!("http://{}/", v3())).await;
    assert!(res.ok, "error: {:?}", res.error);
    assert_eq!(res.status, 200);
    assert_eq!(res.body, b"hello");
    assert_eq!(res.content_type, "text/html");
    assert_eq!(res.final_url, format!("http://{}/", v3()));
    server.await.unwrap();
}

#[tokio::test]
async fn fetch_follows_redirect() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let host = v3();
    let dest = format!("http://{host}/final");
    let server = serve(listener, 2, move |path| {
        if path == "/final" {
            b"HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\narrived".to_vec()
        } else {
            format!("HTTP/1.1 301 Moved\r\nLocation: {dest}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").into_bytes()
        }
    });

    let mut map = HashMap::new();
    map.insert(host.clone(), ("127.0.0.1".to_string(), port));
    let f = Fetcher::direct(map);
    let res = f.fetch(&format!("http://{host}/start")).await;
    assert!(res.ok, "error: {:?}", res.error);
    assert_eq!(res.body, b"arrived");
    assert_eq!(res.final_url, format!("http://{host}/final"));
    server.await.unwrap();
}

#[tokio::test]
async fn fetch_refuses_clearnet() {
    // No server: the clearnet URL never reaches a socket (refused at canonicalize
    // / the OnionHost gate).
    let f = Fetcher::direct(HashMap::new());
    let res = f.fetch("http://example.com/").await;
    assert!(!res.ok);
    assert!(res.error.is_some());
    assert_eq!(res.status, 0);
}

#[test]
fn transport_is_constructible() {
    // Ensure the public Transport variants exist for downstream config.
    let _ = Transport::TorSocks {
        proxy_host: "127.0.0.1".to_string(),
        proxy_port: 9050,
        stream_isolation: true,
        isolation_secret: "x".to_string(),
    };
}
