//! End-to-end loopback crawl (net feature): a mock site (robots.txt + linked
//! pages) is crawled through the real pipeline — frontier → SSRF-checked fetch →
//! htmlparse → index → link expansion — proving the loop drains, indexes the
//! allowed pages, obeys robots.txt, and that the SSRF gate still governs every
//! fetch (a loopback site is reachable only through the `allow_hosts` exemption).
#![cfg(feature = "net")]

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use websearch::crawler::Crawler;
use websearch::CrawlConfig;

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
        match sock.read(&mut tmp).await {
            Ok(0) | Err(_) => break,
            Ok(n) => buf.extend_from_slice(&tmp[..n]),
        }
    }
    let end = find(&buf, b"\r\n").unwrap_or(buf.len());
    String::from_utf8_lossy(&buf[..end])
        .split(' ')
        .nth(1)
        .unwrap_or("/")
        .to_string()
}

fn body_for(path: &str) -> (&'static str, &'static str, &'static str) {
    // (status, content_type, body)
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

/// A mock site that answers connections until aborted.
fn serve_site(listener: TcpListener) -> tokio::task::JoinHandle<()> {
    tokio::spawn(async move {
        loop {
            let Ok((mut sock, _)) = listener.accept().await else {
                break;
            };
            let path = read_req_path(&mut sock).await;
            let (status, ctype, body) = body_for(&path);
            let resp = format!(
                "HTTP/1.1 {status}\r\nContent-Type: {ctype}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                body.len()
            );
            let _ = sock.write_all(resp.as_bytes()).await;
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

#[tokio::test]
async fn crawl_drains_indexes_and_obeys_robots() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = serve_site(listener);

    let mut cr = Crawler::new(crawl_config(port, true));
    let seed = format!("http://127.0.0.1:{port}/");
    assert_eq!(cr.add_seeds(&[&seed]), 1);
    let stats = cr.run(Some(100)).await;
    server.abort();

    // The three allowed pages are indexed; /private is blocked by robots.
    let url = |p: &str| format!("http://127.0.0.1:{port}{p}");
    assert_eq!(cr.index().doc_count(), 3, "stats={stats:?}");
    assert!(cr.index().get_doc(&url("/")).is_some());
    assert!(cr.index().get_doc(&url("/a")).is_some());
    assert!(cr.index().get_doc(&url("/b")).is_some());
    assert!(cr.index().get_doc(&url("/private")).is_none());
    assert_eq!(stats.indexed, 3);
    assert!(stats.robots_blocked >= 1, "stats={stats:?}");
    // The link graph was recorded (home links to /a, /b, /private).
    assert!(cr.index().stats().links >= 3);
    // Titles came through htmlparse.
    assert_eq!(cr.index().get_doc(&url("/a")).unwrap().title, "Page A");
}

#[tokio::test]
async fn crawl_is_ssrf_gated() {
    // Same loopback site, but WITHOUT the allow_hosts exemption: the SSRF gate
    // refuses every fetch (127.0.0.1 is internal), so nothing is indexed.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let server = serve_site(listener);

    let mut cr = Crawler::new(crawl_config(port, false)); // block_internal stays true, no exemption
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
