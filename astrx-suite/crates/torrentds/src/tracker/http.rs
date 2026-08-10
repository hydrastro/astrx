//! HTTP BitTorrent tracker: `GET /announce` and `GET /scrape`.
//!
//! Implements BEP-3 (announce) with BEP-23 compact peer lists (and the legacy
//! dictionary model when `compact=0`) plus the conventional `/scrape`. The query
//! string is parsed at the byte level because `info_hash`/`peer_id` are raw
//! 20-byte values that are not valid text once percent-decoded. The
//! client-supplied address is ignored — always the TCP source — so the tracker
//! can't be used as a reflector.
//!
//! The pure query parser + response encoders are cross-checked byte-identical to
//! the Python reference; a minimal async HTTP/1.1 server round-trips over
//! loopback on a shared [`PeerStore`].

use crate::bencode::Dict;
use crate::bencode::{encode, Ben};
use crate::peerstore::{Event, Family, PeerStore, ScrapeCounts};
use std::collections::HashMap;
use std::net::{IpAddr, SocketAddr};
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

/// Parsed query parameters: name → list of raw (percent-decoded) values.
pub type Query = HashMap<String, Vec<Vec<u8>>>;

fn hex_val(b: u8) -> Option<u8> {
    match b {
        b'0'..=b'9' => Some(b - b'0'),
        b'a'..=b'f' => Some(b - b'a' + 10),
        b'A'..=b'F' => Some(b - b'A' + 10),
        _ => None,
    }
}

/// Percent-decode `%XX` escapes to raw bytes; everything else is literal (no
/// `+`→space, matching Python's `unquote_to_bytes`).
pub fn percent_decode(s: &str) -> Vec<u8> {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%' && i + 2 < b.len() {
            if let (Some(h), Some(l)) = (hex_val(b[i + 1]), hex_val(b[i + 2])) {
                out.push((h << 4) | l);
                i += 3;
                continue;
            }
        }
        out.push(b[i]);
        i += 1;
    }
    out
}

/// Parse a raw query string preserving binary values (`info_hash` etc.).
pub fn parse_query_bytes(query: &str) -> Query {
    let mut out: Query = HashMap::new();
    for pair in query.split('&') {
        if pair.is_empty() {
            continue;
        }
        let (key, value) = pair.split_once('=').unwrap_or((pair, ""));
        let name = String::from_utf8_lossy(&percent_decode(key)).into_owned();
        out.entry(name).or_default().push(percent_decode(value));
    }
    out
}

/// BEP-23 compact IPv4 peers (4-byte address + 2-byte port each).
pub fn build_compact_peers(peers: &[SocketAddr]) -> Vec<u8> {
    let mut out = Vec::new();
    for p in peers {
        if let IpAddr::V4(a) = p.ip() {
            out.extend_from_slice(&a.octets());
            out.extend_from_slice(&p.port().to_be_bytes());
        }
    }
    out
}

/// BEP-7 compact IPv6 peers (16-byte address + 2-byte port each).
pub fn build_compact_peers6(peers: &[SocketAddr]) -> Vec<u8> {
    let mut out = Vec::new();
    for p in peers {
        if let IpAddr::V6(a) = p.ip() {
            out.extend_from_slice(&a.octets());
            out.extend_from_slice(&p.port().to_be_bytes());
        }
    }
    out
}

/// Legacy dictionary peer model: `[{ip, port}, …]`.
pub fn build_dict_peers(peers: &[SocketAddr]) -> Ben {
    Ben::List(
        peers
            .iter()
            .map(|p| {
                let mut d = Dict::new();
                d.insert(b"ip".to_vec(), Ben::Bytes(p.ip().to_string().into_bytes()));
                d.insert(b"port".to_vec(), Ben::Int(i64::from(p.port())));
                Ben::Dict(d)
            })
            .collect(),
    )
}

/// Bencoded announce response.
pub fn announce_response_bytes(
    interval: i64,
    complete: i64,
    incomplete: i64,
    peers4: &[SocketAddr],
    peers6: &[SocketAddr],
    compact: bool,
) -> Vec<u8> {
    let mut resp = Dict::new();
    resp.insert(b"complete".to_vec(), Ben::Int(complete));
    resp.insert(b"incomplete".to_vec(), Ben::Int(incomplete));
    resp.insert(b"interval".to_vec(), Ben::Int(interval));
    resp.insert(b"min interval".to_vec(), Ben::Int((interval / 2).max(1)));
    if compact {
        resp.insert(b"peers".to_vec(), Ben::Bytes(build_compact_peers(peers4)));
        if !peers6.is_empty() {
            resp.insert(b"peers6".to_vec(), Ben::Bytes(build_compact_peers6(peers6)));
        }
    } else {
        let mut all = peers4.to_vec();
        all.extend_from_slice(peers6);
        resp.insert(b"peers".to_vec(), build_dict_peers(&all));
    }
    encode(&Ben::Dict(resp))
}

/// One scrape entry: an infohash and its [`ScrapeCounts`].
pub type ScrapeEntry = ([u8; 20], ScrapeCounts);

/// Bencoded scrape response: `{files: {ih: {complete, downloaded, incomplete}}}`.
pub fn scrape_response_bytes(entries: &[ScrapeEntry]) -> Vec<u8> {
    let mut files = Dict::new();
    for (ih, c) in entries {
        let mut e = Dict::new();
        e.insert(b"complete".to_vec(), Ben::Int(c.complete as i64));
        e.insert(b"downloaded".to_vec(), Ben::Int(c.downloaded as i64));
        e.insert(b"incomplete".to_vec(), Ben::Int(c.incomplete as i64));
        files.insert(ih.to_vec(), Ben::Dict(e));
    }
    let mut top = Dict::new();
    top.insert(b"files".to_vec(), Ben::Dict(files));
    encode(&Ben::Dict(top))
}

/// Bencoded `{failure reason: …}`.
pub fn failure_bytes(reason: &str) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(
        b"failure reason".to_vec(),
        Ben::Bytes(reason.as_bytes().to_vec()),
    );
    encode(&Ben::Dict(d))
}

fn first_value<'a>(params: &'a Query, name: &str) -> Option<&'a Vec<u8>> {
    params.get(name).and_then(|v| v.first())
}

fn param_int(params: &Query, name: &str, default: i64) -> i64 {
    match first_value(params, name) {
        Some(v) => match std::str::from_utf8(v).map(str::trim) {
            Ok(s) => match s.parse::<i64>() {
                Ok(n) => n,
                // An out-of-i64-range magnitude clamps (Python's big int keeps it),
                // so e.g. an absurd `left` still reads non-zero (a leecher), not the
                // `0` default (which would misclassify the peer as a seeder).
                Err(e) if *e.kind() == std::num::IntErrorKind::PosOverflow => i64::MAX,
                Err(e) if *e.kind() == std::num::IntErrorKind::NegOverflow => i64::MIN,
                Err(_) => default,
            },
            Err(_) => default,
        },
        None => default,
    }
}

fn event_from_bytes(v: &[u8]) -> Event {
    match v {
        b"started" => Event::Started,
        b"stopped" => Event::Stopped,
        b"completed" => Event::Completed,
        _ => Event::None,
    }
}

fn unix_secs() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

// --- request handling ------------------------------------------------------

fn handle_announce(params: &Query, src: SocketAddr, store: &Mutex<PeerStore>, now: u64) -> Vec<u8> {
    let info_hash = match first_value(params, "info_hash") {
        Some(v) if v.len() == 20 => {
            let mut a = [0u8; 20];
            a.copy_from_slice(v);
            a
        }
        _ => return failure_bytes("invalid info_hash"),
    };
    let port = param_int(params, "port", 0);
    if !(0 < port && port < 65536) {
        return failure_bytes("invalid port");
    }
    let left = param_int(params, "left", 0);
    let event = event_from_bytes(first_value(params, "event").map_or(&[][..], |v| v.as_slice()));
    let compact = param_int(params, "compact", 1) != 0;
    let numwant = param_int(params, "numwant", 50).max(0) as usize;
    let endpoint = SocketAddr::new(src.ip(), port as u16);

    let mut st = store.lock().unwrap();
    if !st.announce(&info_hash, endpoint, left, event, now) {
        return failure_bytes("info_hash not allowed by tracker policy");
    }
    let interval = st.interval as i64;
    let c = st.counts(&info_hash, now);
    let peers4 = st.get_peers(&info_hash, numwant, Some(endpoint), Family::V4, now);
    let peers6 = st.get_peers(&info_hash, numwant, Some(endpoint), Family::V6, now);
    drop(st);
    announce_response_bytes(
        interval,
        c.complete as i64,
        c.incomplete as i64,
        &peers4,
        &peers6,
        compact,
    )
}

fn handle_scrape(params: &Query, store: &Mutex<PeerStore>, now: u64) -> Vec<u8> {
    let hashes: Vec<[u8; 20]> = params
        .get("info_hash")
        .map(|vs| {
            vs.iter()
                .filter(|v| v.len() == 20)
                .map(|v| {
                    let mut a = [0u8; 20];
                    a.copy_from_slice(v);
                    a
                })
                .collect()
        })
        .unwrap_or_default();
    if hashes.is_empty() {
        return failure_bytes("scrape requires at least one info_hash");
    }
    let mut st = store.lock().unwrap();
    let entries: Vec<ScrapeEntry> = hashes.iter().map(|h| (*h, st.counts(h, now))).collect();
    drop(st);
    scrape_response_bytes(&entries)
}

/// Route a request target to its response body.
pub fn route(target: &str, src: SocketAddr, store: &Mutex<PeerStore>, now: u64) -> Vec<u8> {
    let (path, query) = target.split_once('?').unwrap_or((target, ""));
    let params = parse_query_bytes(query);
    match path.trim_end_matches('/') {
        "/announce" => handle_announce(&params, src, store, now),
        "/scrape" => handle_scrape(&params, store, now),
        "" => b"torrentds tracker: GET /announce and GET /scrape\n".to_vec(),
        _ => failure_bytes("not found"),
    }
}

async fn handle_conn(
    mut stream: TcpStream,
    src: SocketAddr,
    store: Arc<Mutex<PeerStore>>,
) -> std::io::Result<()> {
    // Read the request head (until CRLFCRLF), bounded in size AND time — a client
    // that connects and never sends the terminator (slowloris) must not park a
    // task/fd forever.
    let mut buf = Vec::new();
    let mut tmp = [0u8; 1024];
    let read_head = async {
        loop {
            let n = stream.read(&mut tmp).await?;
            if n == 0 {
                break;
            }
            buf.extend_from_slice(&tmp[..n]);
            if buf.windows(4).any(|w| w == b"\r\n\r\n") || buf.len() > 8192 {
                break;
            }
        }
        Ok::<(), std::io::Error>(())
    };
    match tokio::time::timeout(Duration::from_secs(15), read_head).await {
        Ok(Ok(())) => {}
        _ => return Ok(()), // read error or slow client -> drop the connection
    }
    let head = String::from_utf8_lossy(&buf);
    let target = head
        .lines()
        .next()
        .and_then(|line| line.split_whitespace().nth(1))
        .unwrap_or("/");
    let body = route(target, src, &store, unix_secs());
    let header = format!(
        "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
        body.len()
    );
    stream.write_all(header.as_bytes()).await?;
    stream.write_all(&body).await?;
    Ok(())
}

/// Start a loopback HTTP tracker on a shared [`PeerStore`]. Returns the bound
/// address and the accept-loop handle (abort it to stop).
pub async fn serve_http_tracker(
    store: Arc<Mutex<PeerStore>>,
    addr: SocketAddr,
) -> std::io::Result<(SocketAddr, tokio::task::JoinHandle<()>)> {
    let listener = TcpListener::bind(addr).await?;
    let bound = listener.local_addr()?;
    let handle = tokio::spawn(async move {
        loop {
            match listener.accept().await {
                Ok((stream, peer)) => {
                    let store = store.clone();
                    tokio::spawn(async move {
                        let _ = handle_conn(stream, peer, store).await;
                    });
                }
                // A transient accept error (e.g. fd exhaustion) must not kill the
                // listener; back off briefly and keep serving.
                Err(_) => tokio::time::sleep(Duration::from_millis(5)).await,
            }
        }
    });
    Ok((bound, handle))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bencode::decode;

    fn ep(s: &str) -> SocketAddr {
        s.parse().unwrap()
    }

    fn pct(bytes: &[u8]) -> String {
        bytes.iter().map(|b| format!("%{b:02x}")).collect()
    }

    #[test]
    fn parse_query_decodes_binary_info_hash() {
        let ih = [0xABu8; 20];
        let q = format!("info_hash={}&port=6881&compact=1", pct(&ih));
        let params = parse_query_bytes(&q);
        assert_eq!(first_value(&params, "info_hash").unwrap().as_slice(), &ih);
        assert_eq!(param_int(&params, "port", 0), 6881);
        assert_eq!(param_int(&params, "compact", 9), 1);
    }

    #[tokio::test]
    async fn http_announce_returns_a_peer() {
        let store = Arc::new(Mutex::new(PeerStore::new(1800)));
        let ih = [0x42u8; 20];
        store
            .lock()
            .unwrap()
            .announce(&ih, ep("9.8.7.6:6881"), 0, Event::Started, unix_secs());
        let (addr, handle) = serve_http_tracker(store, "127.0.0.1:0".parse().unwrap())
            .await
            .unwrap();
        let _keep = handle;

        let mut stream = TcpStream::connect(addr).await.unwrap();
        let req = format!(
            "GET /announce?info_hash={}&port=6882&left=0&compact=1&event=started HTTP/1.1\r\nHost: t\r\n\r\n",
            pct(&ih)
        );
        stream.write_all(req.as_bytes()).await.unwrap();
        let mut resp = Vec::new();
        stream.read_to_end(&mut resp).await.unwrap();

        // split head/body at CRLFCRLF
        let sep = resp.windows(4).position(|w| w == b"\r\n\r\n").unwrap();
        let body = &resp[sep + 4..];
        let Ben::Dict(d) = decode(body).unwrap() else {
            panic!("bencode dict")
        };
        assert_eq!(d.get(b"complete".as_slice()), Some(&Ben::Int(2))); // two seeders
                                                                       // compact peers: the one peer that isn't us -> 9.8.7.6:6881 = 6 bytes
        let Some(Ben::Bytes(peers)) = d.get(b"peers".as_slice()) else {
            panic!("peers")
        };
        assert_eq!(peers.as_slice(), &[9, 8, 7, 6, 0x1a, 0xe1]);
    }

    #[tokio::test]
    async fn http_announce_rejects_bad_info_hash() {
        let store = Arc::new(Mutex::new(PeerStore::new(1800)));
        let (addr, handle) = serve_http_tracker(store, "127.0.0.1:0".parse().unwrap())
            .await
            .unwrap();
        let _keep = handle;
        let mut stream = TcpStream::connect(addr).await.unwrap();
        stream
            .write_all(b"GET /announce?info_hash=tooshort&port=1 HTTP/1.1\r\n\r\n")
            .await
            .unwrap();
        let mut resp = Vec::new();
        stream.read_to_end(&mut resp).await.unwrap();
        let sep = resp.windows(4).position(|w| w == b"\r\n\r\n").unwrap();
        let Ben::Dict(d) = decode(&resp[sep + 4..]).unwrap() else {
            panic!()
        };
        assert_eq!(
            d.get(b"failure reason".as_slice()),
            Some(&Ben::Bytes(b"invalid info_hash".to_vec()))
        );
    }
}
