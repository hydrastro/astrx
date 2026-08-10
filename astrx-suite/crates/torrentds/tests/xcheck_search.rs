// Exercises `search` (net) + `build_torrent_file`.
#![cfg(feature = "net")]
//! Cross-check: the serving layer's deterministic helpers are byte-identical to
//! the Python reference (`legacy-python/torrentds/{search,metadata}.py`) —
//! `human_size` and `build_torrent_file` — plus a loopback round-trip of the async
//! HTTP server over a live `Store`.

use std::sync::{Arc, Mutex};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpStream;
use torrentds::bencode::{encode, Ben};
use torrentds::metadata::{build_torrent_file, TorrentMeta};
use torrentds::search::{human_size, rfc2822, serve_search, torznab_caps, SearchServer};
use torrentds::store::Store;

fn to_hex(b: &[u8]) -> String {
    b.iter().map(|x| format!("{x:02x}")).collect()
}

#[test]
fn human_size_matches_python() {
    // Goldens from search.human_size(n).
    assert_eq!(human_size(0), "0 B");
    assert_eq!(human_size(512), "512 B");
    assert_eq!(human_size(1024), "1.0 KiB");
    assert_eq!(human_size(1536), "1.5 KiB");
    assert_eq!(human_size(1_073_741_824), "1.0 GiB");
    assert_eq!(human_size(1_400_000_000), "1.3 GiB");
    assert_eq!(human_size(5_497_558_138_880), "5.0 TiB");
}

fn sample_info() -> Vec<u8> {
    let mut m = std::collections::BTreeMap::new();
    m.insert(b"length".to_vec(), Ben::Int(1234));
    m.insert(b"name".to_vec(), Ben::Bytes(b"test.txt".to_vec()));
    m.insert(b"piece length".to_vec(), Ben::Int(16384));
    m.insert(b"pieces".to_vec(), Ben::Bytes(vec![1u8; 20]));
    encode(&Ben::Dict(m))
}

#[test]
fn build_torrent_file_matches_python() {
    let info = sample_info();
    // info spliced verbatim; top-level keys canonical.
    assert_eq!(
        to_hex(&build_torrent_file(&info, None, &[], None)),
        "64343a696e666f64363a6c656e677468693132333465343a6e616d65383a746573742e74787431323a7069656365206c656e67746869313633383465363a70696563657332303a01010101010101010101010101010101010101016565"
    );
    let al = vec!["http://a/x".to_string(), "http://b/y".to_string()];
    assert_eq!(
        to_hex(&build_torrent_file(&info, Some("http://tr/announce"), &al, Some(1_600_000_000))),
        "64383a616e6e6f756e636531383a687474703a2f2f74722f616e6e6f756e636531333a616e6e6f756e63652d6c6973746c6c31303a687474703a2f2f612f78656c31303a687474703a2f2f622f79656531333a6372656174696f6e2064617465693136303030303030303065343a696e666f64363a6c656e677468693132333465343a6e616d65383a746573742e74787431323a7069656365206c656e67746869313633383465363a70696563657332303a01010101010101010101010101010101010101016565"
    );
}

#[test]
fn rfc2822_matches_python_formatdate() {
    assert_eq!(rfc2822(0), "Thu, 01 Jan 1970 00:00:00 GMT");
    assert_eq!(rfc2822(1_600_000_000), "Sun, 13 Sep 2020 12:26:40 GMT");
    assert_eq!(rfc2822(1_700_000_000), "Tue, 14 Nov 2023 22:13:20 GMT");
}

#[test]
fn torznab_caps_matches_python() {
    let caps = String::from_utf8(torznab_caps()).unwrap();
    assert_eq!(
        caps,
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?><caps><server title=\"torrentds\"/>\
<limits max=\"100\" default=\"25\"/><searching>\
<search available=\"yes\" supportedParams=\"q\"/>\
<tv-search available=\"yes\" supportedParams=\"q,season,ep\"/>\
<movie-search available=\"yes\" supportedParams=\"q\"/>\
<audio-search available=\"yes\" supportedParams=\"q\"/>\
<book-search available=\"yes\" supportedParams=\"q\"/></searching><categories>\
<category id=\"2000\" name=\"Movies\"/><category id=\"5000\" name=\"TV\"/>\
<category id=\"3000\" name=\"Audio\"/><category id=\"4000\" name=\"PC\"/>\
<category id=\"7000\" name=\"Books\"/><category id=\"8000\" name=\"Other\"/>\
</categories></caps>"
    );
}

fn a_torrent() -> TorrentMeta {
    let info = sample_info();
    let ih = torrentds::sha1(&info);
    TorrentMeta {
        info_hash: ih,
        name: "Ubuntu 22.04 Desktop".to_string(),
        total_size: 1234,
        piece_length: 16384,
        piece_count: 1,
        files: vec![("test.txt".to_string(), 1234)],
        info_bytes: Some(info),
        info_hash_v2: None,
        version: "v1",
        content_id: None,
    }
}

async fn http_get(addr: std::net::SocketAddr, path: &str) -> (String, Vec<u8>) {
    let mut s = TcpStream::connect(addr).await.unwrap();
    s.write_all(format!("GET {path} HTTP/1.1\r\nHost: t\r\n\r\n").as_bytes())
        .await
        .unwrap();
    let mut resp = Vec::new();
    s.read_to_end(&mut resp).await.unwrap();
    let sep = resp.windows(4).position(|w| w == b"\r\n\r\n").unwrap();
    let head = String::from_utf8_lossy(&resp[..sep]).into_owned();
    (head, resp[sep + 4..].to_vec())
}

#[tokio::test]
async fn search_server_round_trip() {
    let mut store = Store::new();
    let meta = a_torrent();
    let ih_hex: String = torrentds::sha1(&sample_info())
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect();
    store.store_metadata(&meta, 1000);
    let server = SearchServer::new(Arc::new(Mutex::new(store)), None, "");
    let (addr, handle) = serve_search(server, "127.0.0.1:0".parse().unwrap())
        .await
        .unwrap();
    let _keep = handle;

    // health
    let (head, body) = http_get(addr, "/health").await;
    assert!(head.starts_with("HTTP/1.1 200"));
    assert!(String::from_utf8_lossy(&body).contains("\"torrents\":1"));

    // stats
    let (_h, body) = http_get(addr, "/api/stats").await;
    assert!(String::from_utf8_lossy(&body).contains("\"torrents\":1"));

    // search JSON finds it by prefix
    let (_h, body) = http_get(addr, "/api/search?q=ubuntu").await;
    let text = String::from_utf8_lossy(&body);
    assert!(text.contains("\"total\":1"), "api/search body: {text}");
    assert!(text.contains("Ubuntu 22.04 Desktop"));

    // HTML detail
    let (head, body) = http_get(addr, &format!("/t/{ih_hex}")).await;
    assert!(head.starts_with("HTTP/1.1 200"));
    assert!(String::from_utf8_lossy(&body).contains("Ubuntu 22.04 Desktop"));

    // rebuilt .torrent hashes back to the infohash
    let (head, body) = http_get(addr, &format!("/torrent/{ih_hex}.torrent")).await;
    assert!(head.contains("application/x-bittorrent"));
    let Ben::Dict(d) = torrentds::decode(&body).unwrap() else {
        panic!("torrent is a dict")
    };
    let Some(Ben::Dict(info)) = d.get(b"info".as_slice()) else {
        panic!("has info")
    };
    assert_eq!(
        torrentds::sha1(&encode(&Ben::Dict(info.clone()))),
        meta.info_hash
    );

    // torznab caps + search
    let (head, body) = http_get(addr, "/torznab/api?t=caps").await;
    assert!(head.contains("application/xml"));
    assert!(String::from_utf8_lossy(&body).contains("<caps>"));
    let (_h, body) = http_get(addr, "/torznab/api?t=search&q=ubuntu").await;
    let tz = String::from_utf8_lossy(&body);
    assert!(tz.contains("xmlns:torznab"));
    assert!(tz.contains("Ubuntu 22.04 Desktop"));
    assert!(tz.contains("<torznab:attr name=\"category\""));
    assert!(tz.contains("<torznab:attr name=\"infohash\""));

    // unknown torrent -> 404
    let (head, _b) = http_get(addr, "/t/deadbeef").await;
    assert!(head.starts_with("HTTP/1.1 404"));
}
