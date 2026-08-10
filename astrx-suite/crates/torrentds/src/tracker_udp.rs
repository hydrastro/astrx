//! UDP BitTorrent tracker (BEP-15) — the connect → announce/scrape handshake.
//!
//! * the magic `protocol_id = 0x41727101980` guards the connect request;
//! * the tracker issues a 64-bit `connection_id` the client must echo on
//!   announce/scrape — here it is **stateless**: a keyed hash of the source
//!   address + a time window, so a connect flood can't grow memory (there is no
//!   per-connection table). Like the DHT token it is issued and validated by the
//!   same node, so it need not match any other implementation byte-for-byte;
//! * 32-bit transaction ids are echoed on every reply;
//! * the client-supplied `ip` field is ignored — always use the packet source,
//!   so the tracker can't be used as a swarm-poisoning reflector;
//! * malformed / unauthorised requests get an `action = 3` error reply.
//!
//! The pure `encode_*`/`parse_*` codec is byte-identical to the Python reference
//! (see the cross-check) and the async server round-trips over loopback.

use crate::peerstore::{Event, Family, PeerStore};
use std::net::{IpAddr, SocketAddr};
use std::sync::{Arc, Mutex, Weak};
use tokio::net::UdpSocket;

/// BEP-15 connect magic.
pub const PROTOCOL_ID: u64 = 0x0417_2710_1980;
pub const ACTION_CONNECT: u32 = 0;
pub const ACTION_ANNOUNCE: u32 = 1;
pub const ACTION_SCRAPE: u32 = 2;
pub const ACTION_ERROR: u32 = 3;

// BEP-15 event codes.
pub const EVENT_NONE: u32 = 0;
pub const EVENT_COMPLETED: u32 = 1;
pub const EVENT_STARTED: u32 = 2;
pub const EVENT_STOPPED: u32 = 3;

// --- pure wire codec -------------------------------------------------------

/// A parsed BEP-15 announce request (the fields we act on).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AnnounceRequest {
    pub info_hash: [u8; 20],
    pub peer_id: [u8; 20],
    pub downloaded: u64,
    pub left: u64,
    pub uploaded: u64,
    pub event: u32,
    pub num_want: i32,
    pub port: u16,
}

/// `(connection_id, action, transaction_id)` — the 16-byte request header.
pub fn parse_header(data: &[u8]) -> Option<(u64, u32, u32)> {
    if data.len() < 16 {
        return None;
    }
    let conn = u64::from_be_bytes(data[0..8].try_into().unwrap());
    let action = u32::from_be_bytes(data[8..12].try_into().unwrap());
    let txn = u32::from_be_bytes(data[12..16].try_into().unwrap());
    Some((conn, action, txn))
}

/// Parse the announce body (`>20s20sQQQIIiiH` after the 16-byte header). The
/// client-supplied `ip` and `key` fields are parsed past but ignored.
pub fn parse_announce_request(data: &[u8]) -> Option<AnnounceRequest> {
    if data.len() < 98 {
        return None;
    }
    let b = &data[16..98];
    let mut info_hash = [0u8; 20];
    let mut peer_id = [0u8; 20];
    info_hash.copy_from_slice(&b[0..20]);
    peer_id.copy_from_slice(&b[20..40]);
    Some(AnnounceRequest {
        info_hash,
        peer_id,
        downloaded: u64::from_be_bytes(b[40..48].try_into().unwrap()),
        left: u64::from_be_bytes(b[48..56].try_into().unwrap()),
        uploaded: u64::from_be_bytes(b[56..64].try_into().unwrap()),
        event: u32::from_be_bytes(b[64..68].try_into().unwrap()),
        // b[68..72] = ip (ignored), b[72..76] = key (ignored)
        num_want: i32::from_be_bytes(b[76..80].try_into().unwrap()),
        port: u16::from_be_bytes(b[80..82].try_into().unwrap()),
    })
}

/// Parse up to 74 (BEP-15 cap) 20-byte infohashes from a scrape request.
pub fn parse_scrape_hashes(data: &[u8]) -> Vec<[u8; 20]> {
    let mut out = Vec::new();
    let mut off = 16;
    while off + 20 <= data.len() && out.len() < 74 {
        let mut h = [0u8; 20];
        h.copy_from_slice(&data[off..off + 20]);
        out.push(h);
        off += 20;
    }
    out
}

/// `>IIQ` connect response.
pub fn encode_connect_response(txn: u32, connection_id: u64) -> Vec<u8> {
    let mut o = Vec::with_capacity(16);
    o.extend_from_slice(&ACTION_CONNECT.to_be_bytes());
    o.extend_from_slice(&txn.to_be_bytes());
    o.extend_from_slice(&connection_id.to_be_bytes());
    o
}

/// `>II` + message error response.
pub fn encode_error(txn: u32, message: &str) -> Vec<u8> {
    let mut o = Vec::with_capacity(8 + message.len());
    o.extend_from_slice(&ACTION_ERROR.to_be_bytes());
    o.extend_from_slice(&txn.to_be_bytes());
    o.extend_from_slice(message.as_bytes());
    o
}

/// `>IIiii` announce header (`interval, leechers, seeders`) + compact peers of
/// the connection's own address family.
pub fn encode_announce_response(
    txn: u32,
    interval: i32,
    leechers: i32,
    seeders: i32,
    peers: &[SocketAddr],
) -> Vec<u8> {
    let mut o = Vec::with_capacity(20 + peers.len() * 6);
    o.extend_from_slice(&ACTION_ANNOUNCE.to_be_bytes());
    o.extend_from_slice(&txn.to_be_bytes());
    o.extend_from_slice(&interval.to_be_bytes());
    o.extend_from_slice(&leechers.to_be_bytes());
    o.extend_from_slice(&seeders.to_be_bytes());
    for p in peers {
        match p.ip() {
            IpAddr::V4(a) => o.extend_from_slice(&a.octets()),
            IpAddr::V6(a) => o.extend_from_slice(&a.octets()),
        }
        o.extend_from_slice(&p.port().to_be_bytes());
    }
    o
}

/// `>II` + per-hash `>iii` (`complete, downloaded, incomplete`) scrape response.
pub fn encode_scrape_response(txn: u32, entries: &[(i32, i32, i32)]) -> Vec<u8> {
    let mut o = Vec::with_capacity(8 + entries.len() * 12);
    o.extend_from_slice(&ACTION_SCRAPE.to_be_bytes());
    o.extend_from_slice(&txn.to_be_bytes());
    for (complete, downloaded, incomplete) in entries {
        o.extend_from_slice(&complete.to_be_bytes());
        o.extend_from_slice(&downloaded.to_be_bytes());
        o.extend_from_slice(&incomplete.to_be_bytes());
    }
    o
}

fn event_from_code(code: u32) -> Event {
    match code {
        EVENT_COMPLETED => Event::Completed,
        EVENT_STARTED => Event::Started,
        EVENT_STOPPED => Event::Stopped,
        _ => Event::None,
    }
}

fn unix_secs() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

// --- the async server ------------------------------------------------------

/// A BEP-15 UDP tracker bound to a socket, serving a shared [`PeerStore`].
pub struct UdpTracker {
    socket: Arc<UdpSocket>,
    store: Arc<Mutex<PeerStore>>,
    secret: [u8; 32],
    window: u64,
}

impl UdpTracker {
    /// Bind and start serving. `conn_ttl` is the connection-id validity window
    /// (seconds). Dropping the returned `Arc` stops the receive loop.
    pub async fn bind(
        addr: SocketAddr,
        store: Arc<Mutex<PeerStore>>,
        conn_ttl: u64,
    ) -> std::io::Result<Arc<Self>> {
        let socket = Arc::new(UdpSocket::bind(addr).await?);
        let mut secret = [0u8; 32];
        let _ = getrandom::getrandom(&mut secret);
        let tracker = Arc::new(Self {
            socket: socket.clone(),
            store,
            secret,
            window: conn_ttl.max(1),
        });
        tokio::spawn(recv_loop(Arc::downgrade(&tracker), socket));
        Ok(tracker)
    }

    pub fn local_addr(&self) -> std::io::Result<SocketAddr> {
        self.socket.local_addr()
    }

    /// The stateless connection id for `addr` in a given time `window`.
    fn connection_id(&self, addr: SocketAddr, window: u64) -> u64 {
        let mut data = self.secret.to_vec();
        data.extend_from_slice(format!("{}|{}", addr.ip(), addr.port()).as_bytes());
        data.extend_from_slice(&window.to_be_bytes());
        let h = crate::infohash::sha1(&data);
        u64::from_be_bytes(h[..8].try_into().unwrap())
    }

    /// A connection id is valid for its issuing window or the previous one (so it
    /// stays usable for at least one `conn_ttl` after issue).
    fn valid_connection(&self, cid: u64, addr: SocketAddr, now: u64) -> bool {
        let w = now / self.window;
        cid == self.connection_id(addr, w) || (w > 0 && cid == self.connection_id(addr, w - 1))
    }

    /// Handle one datagram, returning the reply (if any). Pure w.r.t. the socket
    /// so it is directly testable; `now` is seconds.
    pub fn handle(&self, data: &[u8], src: SocketAddr, now: u64) -> Option<Vec<u8>> {
        let (conn, action, txn) = parse_header(data)?;
        match action {
            ACTION_CONNECT => {
                if conn != PROTOCOL_ID {
                    return None;
                }
                Some(encode_connect_response(
                    txn,
                    self.connection_id(src, now / self.window),
                ))
            }
            ACTION_ANNOUNCE => {
                if !self.valid_connection(conn, src, now) {
                    return Some(encode_error(txn, "connection id mismatch"));
                }
                let Some(req) = parse_announce_request(data) else {
                    return Some(encode_error(txn, "short announce"));
                };
                let endpoint = SocketAddr::new(src.ip(), req.port);
                let mut store = self.store.lock().unwrap();
                if !store.announce(
                    &req.info_hash,
                    endpoint,
                    req.left as i64,
                    event_from_code(req.event),
                    now,
                ) {
                    return Some(encode_error(txn, "info_hash not allowed"));
                }
                let interval = store.interval as i32;
                let want = if req.num_want < 0 {
                    50
                } else {
                    (req.num_want as usize).min(store.max_peers_per_reply)
                };
                let (seeders, leechers, _dl) = store.counts(&req.info_hash, now);
                let family = if src.is_ipv6() {
                    Family::V6
                } else {
                    Family::V4
                };
                let peers = store.get_peers(&req.info_hash, want, Some(endpoint), family, now);
                drop(store);
                Some(encode_announce_response(
                    txn,
                    interval,
                    leechers as i32,
                    seeders as i32,
                    &peers,
                ))
            }
            ACTION_SCRAPE => {
                if !self.valid_connection(conn, src, now) {
                    return Some(encode_error(txn, "connection id mismatch"));
                }
                let hashes = parse_scrape_hashes(data);
                let mut store = self.store.lock().unwrap();
                let entries: Vec<(i32, i32, i32)> = hashes
                    .iter()
                    .map(|h| {
                        let (complete, incomplete, downloaded) = store.counts(h, now);
                        // Saturate so a huge `downloaded` counter can't wrap to a
                        // negative i32 on the wire (Python raises struct.error and
                        // drops the reply; a saturated max is the safer analogue).
                        let clamp = |v: u64| v.min(i32::MAX as u64) as i32;
                        (clamp(complete), clamp(downloaded), clamp(incomplete))
                    })
                    .collect();
                drop(store);
                Some(encode_scrape_response(txn, &entries))
            }
            _ => Some(encode_error(txn, "unknown action")),
        }
    }
}

async fn recv_loop(tracker: Weak<UdpTracker>, socket: Arc<UdpSocket>) {
    let mut buf = vec![0u8; 4096];
    loop {
        let (len, src) = match socket.recv_from(&mut buf).await {
            Ok(x) => x,
            Err(_) => continue,
        };
        match tracker.upgrade() {
            Some(t) => {
                if let Some(reply) = t.handle(&buf[..len], src, unix_secs()) {
                    let _ = socket.send_to(&reply, src).await;
                }
            }
            None => break,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ep(s: &str) -> SocketAddr {
        s.parse().unwrap()
    }

    async fn tracker_with_peer() -> (Arc<UdpTracker>, SocketAddr, [u8; 20]) {
        let store = Arc::new(Mutex::new(PeerStore::new(1800)));
        let ih = [0x42u8; 20];
        store
            .lock()
            .unwrap()
            .announce(&ih, ep("9.8.7.6:6881"), 0, Event::Started, unix_secs());
        let tracker = UdpTracker::bind("127.0.0.1:0".parse().unwrap(), store, 120)
            .await
            .unwrap();
        let addr = tracker.local_addr().unwrap();
        (tracker, addr, ih)
    }

    fn connect_req(txn: u32) -> Vec<u8> {
        let mut r = Vec::new();
        r.extend_from_slice(&PROTOCOL_ID.to_be_bytes());
        r.extend_from_slice(&ACTION_CONNECT.to_be_bytes());
        r.extend_from_slice(&txn.to_be_bytes());
        r
    }

    #[allow(clippy::too_many_arguments)]
    fn announce_req(
        conn: u64,
        txn: u32,
        ih: &[u8; 20],
        left: u64,
        event: u32,
        port: u16,
    ) -> Vec<u8> {
        let mut r = Vec::new();
        r.extend_from_slice(&conn.to_be_bytes());
        r.extend_from_slice(&ACTION_ANNOUNCE.to_be_bytes());
        r.extend_from_slice(&txn.to_be_bytes());
        r.extend_from_slice(ih);
        r.extend_from_slice(&[0x01; 20]); // peer_id
        r.extend_from_slice(&0u64.to_be_bytes()); // downloaded
        r.extend_from_slice(&left.to_be_bytes()); // left
        r.extend_from_slice(&0u64.to_be_bytes()); // uploaded
        r.extend_from_slice(&event.to_be_bytes()); // event
        r.extend_from_slice(&0u32.to_be_bytes()); // ip (ignored)
        r.extend_from_slice(&0u32.to_be_bytes()); // key
        r.extend_from_slice(&(-1i32).to_be_bytes()); // num_want
        r.extend_from_slice(&port.to_be_bytes()); // port
        r
    }

    #[tokio::test]
    async fn connect_then_announce_returns_a_peer() {
        let (tracker, taddr, ih) = tracker_with_peer().await;
        let _keep = tracker;
        let client = UdpSocket::bind("127.0.0.1:0").await.unwrap();
        let txn = 0x1122_3344;
        let mut buf = [0u8; 2048];

        client.send_to(&connect_req(txn), taddr).await.unwrap();
        let (n, _) = client.recv_from(&mut buf).await.unwrap();
        assert_eq!(n, 16);
        assert_eq!(
            u32::from_be_bytes(buf[0..4].try_into().unwrap()),
            ACTION_CONNECT
        );
        assert_eq!(u32::from_be_bytes(buf[4..8].try_into().unwrap()), txn);
        let conn_id = u64::from_be_bytes(buf[8..16].try_into().unwrap());

        client
            .send_to(
                &announce_req(conn_id, txn, &ih, 0, EVENT_STARTED, 6882),
                taddr,
            )
            .await
            .unwrap();
        let (n, _) = client.recv_from(&mut buf).await.unwrap();
        assert_eq!(
            u32::from_be_bytes(buf[0..4].try_into().unwrap()),
            ACTION_ANNOUNCE
        );
        // two seeders now (pre-existing + us); the reply carries the one peer
        // that isn't us: 9.8.7.6:6881.
        assert_eq!(i32::from_be_bytes(buf[16..20].try_into().unwrap()), 2); // seeders
        assert_eq!(n, 26); // 20 header + 6 compact peer
        assert_eq!(
            std::net::Ipv4Addr::new(buf[20], buf[21], buf[22], buf[23]),
            std::net::Ipv4Addr::new(9, 8, 7, 6)
        );
        assert_eq!(u16::from_be_bytes(buf[24..26].try_into().unwrap()), 6881);
    }

    #[tokio::test]
    async fn announce_with_bad_connection_id_errors() {
        let (tracker, taddr, ih) = tracker_with_peer().await;
        let _keep = tracker;
        let client = UdpSocket::bind("127.0.0.1:0").await.unwrap();
        client
            .send_to(
                &announce_req(0xdead_beef, 7, &ih, 0, EVENT_STARTED, 6882),
                taddr,
            )
            .await
            .unwrap();
        let mut buf = [0u8; 512];
        let (n, _) = client.recv_from(&mut buf).await.unwrap();
        assert_eq!(
            u32::from_be_bytes(buf[0..4].try_into().unwrap()),
            ACTION_ERROR
        );
        assert_eq!(u32::from_be_bytes(buf[4..8].try_into().unwrap()), 7); // txn echoed
        assert_eq!(&buf[8..n], b"connection id mismatch");
    }
}
