//! Crawler tests: `content_hash` goldens (byte-identical to Python) + end-to-end
//! loopback crawls that drive the real orchestration loop over a mock HTTP server
//! through the `Direct` fetcher (no Tor) — a single-host crawl draining the
//! frontier, a cross-host crawl recording a link edge, and the depth cap.

use super::*;
use std::collections::HashMap;
use tokio::net::TcpListener;

const HOST1: &str = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.onion"; // 56
const HOST2: &str = "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.onion";

#[test]
fn content_hash_matches_python() {
    assert_eq!(
        content_hash("Hello World", "Some body text here.").as_deref(),
        Some("22de5eaf715370b14f3e31e6035957714d79a92c")
    );
    assert_eq!(content_hash("", ""), None);
    assert_eq!(
        content_hash("  A\tB\n", "C  D").as_deref(),
        Some("30a47d966e96ef820533b160cd25bc61ce71ec7c")
    );
}

#[test]
fn charset_parse() {
    assert_eq!(
        charset_from_ctype("text/html; charset=UTF-8").as_deref(),
        Some("UTF-8")
    );
    assert_eq!(charset_from_ctype("text/html").as_deref(), None);
}

fn find(hay: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || hay.len() < needle.len() {
        return None;
    }
    (0..=hay.len() - needle.len()).find(|&i| &hay[i..i + needle.len()] == needle)
}

/// A minimal HTTP/1.1 mock server: reads the request path + Host header, routes
/// through `responder(host, path) -> Option<html>` (None ⇒ 404), replies with
/// `Connection: close`. Serves connections sequentially (the crawler under test
/// fetches one URL at a time).
async fn run_server(listener: TcpListener, responder: fn(&str, &str) -> Option<String>) {
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    loop {
        let Ok((mut stream, _)) = listener.accept().await else {
            break;
        };
        let mut buf = Vec::new();
        let mut tmp = [0u8; 1024];
        loop {
            if find(&buf, b"\r\n\r\n").is_some() {
                break;
            }
            match stream.read(&mut tmp).await {
                Ok(0) | Err(_) => break,
                Ok(n) => buf.extend_from_slice(&tmp[..n]),
            }
        }
        let head = String::from_utf8_lossy(&buf);
        let path = head
            .lines()
            .next()
            .and_then(|l| l.split_whitespace().nth(1))
            .unwrap_or("/")
            .to_string();
        let host = head
            .lines()
            .find(|l| l.to_ascii_lowercase().starts_with("host:"))
            .and_then(|l| l.split_once(':').map(|(_, v)| v.trim()))
            .unwrap_or("")
            .split(':')
            .next()
            .unwrap_or("")
            .to_string();
        let (status, body) = match responder(&host, &path) {
            Some(b) => (200, b),
            None => (404, "<h1>404</h1>".to_string()),
        };
        let resp = format!(
            "HTTP/1.1 {status} OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
            body.len()
        );
        let _ = stream.write_all(resp.as_bytes()).await;
        let _ = stream.flush().await;
    }
}

fn direct_crawler(port: u16, hosts: &[&str], config: CrawlConfig) -> (Crawler, Arc<Mutex<Store>>) {
    let mut map = HashMap::new();
    for h in hosts {
        map.insert((*h).to_string(), ("127.0.0.1".to_string(), port));
    }
    let store = Arc::new(Mutex::new(Store::new()));
    let fetcher = Arc::new(Fetcher::direct(map));
    (Crawler::new(store.clone(), fetcher, config), store)
}

fn cfg() -> CrawlConfig {
    CrawlConfig {
        discover_body_onions: false, // focus on <a> links
        ..CrawlConfig::default()
    }
}

fn one_host(_host: &str, path: &str) -> Option<String> {
    match path {
        "/" => Some("<html><body><a href=\"/a\">A</a> <a href=\"/b\">B</a></body></html>".into()),
        "/a" => Some("<html><body><p>page a content</p></body></html>".into()),
        "/b" => Some("<html><body><p>page b content</p></body></html>".into()),
        _ => None,
    }
}

#[tokio::test]
async fn single_host_crawl_drains_and_stores() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(run_server(listener, one_host));

    let (crawler, store) = direct_crawler(port, &[HOST1], cfg());
    assert_eq!(crawler.add_seeds([format!("http://{HOST1}/")]), 1);
    let stats = crawler.run().await;

    assert_eq!(stats.pages, 3, "should store /, /a, /b");
    let s = store.lock().unwrap();
    assert!(s.get_page(&format!("http://{HOST1}/a")).is_some());
    assert!(s.get_page(&format!("http://{HOST1}/b")).is_some());
    // frontier fully drained
    assert_eq!(s.pending_summary(1e12), (0, 0));
    let m = s.metrics();
    assert_eq!(m["frontier_done"], 3);
}

fn two_hosts(host: &str, path: &str) -> Option<String> {
    let h1 = HOST1;
    if host == h1 && path == "/" {
        Some(format!(
            "<html><body><a href=\"http://{HOST2}/\">to host2</a></body></html>"
        ))
    } else if path == "/" {
        Some("<html><body><p>host two landing</p></body></html>".into())
    } else {
        None
    }
}

#[tokio::test]
async fn cross_host_crawl_records_link_edge() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(run_server(listener, two_hosts));

    let (crawler, store) = direct_crawler(port, &[HOST1, HOST2], cfg());
    crawler.add_seeds([format!("http://{HOST1}/")]);
    crawler.run().await;

    let s = store.lock().unwrap();
    // both hosts crawled
    assert!(s.get_page(&format!("http://{HOST1}/")).is_some());
    assert!(s.get_page(&format!("http://{HOST2}/")).is_some());
    // exactly one inter-onion link edge (host1 → host2)
    assert_eq!(s.metrics()["link_edges"], 1);
}

fn chain(_host: &str, path: &str) -> Option<String> {
    match path {
        "/" => Some("<html><body><a href=\"/a\">A</a></body></html>".into()),
        "/a" => Some("<html><body><a href=\"/b\">B</a></body></html>".into()),
        "/b" => Some("<html><body>end</body></html>".into()),
        _ => None,
    }
}

#[tokio::test]
async fn depth_cap_stops_expansion() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(run_server(listener, chain));

    let config = CrawlConfig {
        max_depth: 1,
        ..cfg()
    };
    let (crawler, store) = direct_crawler(port, &[HOST1], config);
    crawler.add_seeds([format!("http://{HOST1}/")]);
    crawler.run().await;

    let s = store.lock().unwrap();
    // "/" (depth 0) and "/a" (depth 1) crawled; "/b" (depth 2) never enqueued
    assert!(s.get_page(&format!("http://{HOST1}/a")).is_some());
    assert!(s.get_page(&format!("http://{HOST1}/b")).is_none());
    assert_eq!(s.page_count(), 2);
}
