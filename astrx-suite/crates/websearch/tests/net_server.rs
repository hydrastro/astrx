//! Loopback round-trip of the no-JS search server (net feature): start `serve`,
//! make a real HTTP GET to `/api/search`, and assert the JSON response.
#![cfg(feature = "net")]

use std::sync::{Arc, Mutex};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use websearch::index::{DocFields, Index};
use websearch::serve::serve;
use websearch::SearchServer;

#[tokio::test]
async fn api_search_over_a_socket() {
    let mut ix = Index::new();
    ix.upsert_document(
        "http://a/rust",
        DocFields {
            title: "Rust guide",
            body: "learning rust programming today",
            host: "a",
            lang: "en",
            fetched_at: 1_700_000_000.0,
            http_status: 200,
            ..DocFields::default()
        },
    );
    let server = Arc::new(SearchServer::new(
        Arc::new(Mutex::new(ix)),
        "http://127.0.0.1",
    ));

    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let handle = tokio::spawn(serve(listener, server));

    let mut sock = TcpStream::connect(("127.0.0.1", port)).await.unwrap();
    sock.write_all(b"GET /api/search?q=rust HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n")
        .await
        .unwrap();
    let mut resp = Vec::new();
    sock.read_to_end(&mut resp).await.unwrap();
    let text = String::from_utf8_lossy(&resp);

    assert!(text.starts_with("HTTP/1.1 200 OK"), "resp: {text}");
    assert!(text.contains("application/json"));
    let body = text.split("\r\n\r\n").nth(1).unwrap_or("");
    assert!(body.contains("\"url\":\"http://a/rust\""), "body: {body}");
    assert!(body.contains("\"total\":1"));

    handle.abort();
}
