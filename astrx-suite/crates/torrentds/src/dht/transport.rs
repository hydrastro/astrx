//! Async UDP transport for KRPC (BEP-5) — the live DHT endpoint.
//!
//! Layers a tokio datagram socket over the pure [`crate::krpc`] codec: it sends
//! queries and matches responses to them by transaction id, and dispatches
//! inbound queries to a handler. Two defences against off-path attackers who
//! poison routing tables / fetch queues by forging replies:
//!
//! * **Unpredictable transaction ids.** Each query draws a fresh 2-byte id from
//!   the OS CSPRNG (never an incrementing counter an attacker could anticipate).
//! * **Source-address binding.** A response/error is accepted only from the exact
//!   `(ip, port)` the query was sent to; a datagram bearing a valid pending txn
//!   from any other source is counted as `spoofed`, dropped, and the query is
//!   left pending for the genuine reply.

use crate::krpc::{
    encode_error, encode_query, encode_response, parse_message, Dict, KrpcError, KrpcMessage,
};
use std::collections::HashMap;
use std::net::SocketAddr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Weak};
use std::time::Duration;
use tokio::net::UdpSocket;
use tokio::sync::{oneshot, Mutex};

/// Default per-query timeout.
pub const DEFAULT_TIMEOUT: Duration = Duration::from_secs(5);

/// Observability counters (also drive the tests). `spoofed` counts off-path
/// response-injection attempts (valid pending txn, wrong source address).
#[derive(Debug, Default)]
pub struct Stats {
    pub tx_query: AtomicU64,
    pub rx_query: AtomicU64,
    pub rx_response: AtomicU64,
    pub rx_error: AtomicU64,
    pub timeouts: AtomicU64,
    pub dropped: AtomicU64,
    pub spoofed: AtomicU64,
}

impl Stats {
    pub fn snapshot(&self) -> [(&'static str, u64); 7] {
        [
            ("tx_query", self.tx_query.load(Ordering::Relaxed)),
            ("rx_query", self.rx_query.load(Ordering::Relaxed)),
            ("rx_response", self.rx_response.load(Ordering::Relaxed)),
            ("rx_error", self.rx_error.load(Ordering::Relaxed)),
            ("timeouts", self.timeouts.load(Ordering::Relaxed)),
            ("dropped", self.dropped.load(Ordering::Relaxed)),
            ("spoofed", self.spoofed.load(Ordering::Relaxed)),
        ]
    }
}

/// A query handler: returns `Ok(Some(response))` to reply, `Ok(None)` to stay
/// silent (passive harvesting), or `Err(KrpcError)` to reply with `y=e`.
pub type QueryHandler =
    Arc<dyn Fn(&[u8], &Dict, SocketAddr) -> Result<Option<Dict>, KrpcError> + Send + Sync>;

/// Why a [`KrpcNode::query`] did not return a response.
#[derive(Debug)]
pub enum QueryError {
    /// The remote replied with a KRPC error (`y=e`).
    Krpc(KrpcError),
    /// No reply within the timeout.
    Timeout,
    /// The node was dropped before a reply arrived.
    Cancelled,
    /// The 2-byte txn space is saturated with in-flight queries (never in practice).
    TxnExhausted,
    /// The OS CSPRNG failed.
    Rng,
}

impl std::fmt::Display for QueryError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            QueryError::Krpc(e) => write!(f, "KRPC error {}: {}", e.code, e.message),
            QueryError::Timeout => write!(f, "query timed out"),
            QueryError::Cancelled => write!(f, "node shut down"),
            QueryError::TxnExhausted => write!(f, "transaction id space exhausted"),
            QueryError::Rng => write!(f, "CSPRNG failure"),
        }
    }
}
impl std::error::Error for QueryError {}

type Reply = Result<Dict, KrpcError>;
type Pending = Mutex<HashMap<[u8; 2], (oneshot::Sender<Reply>, SocketAddr)>>;

/// A live KRPC node bound to a UDP socket.
pub struct KrpcNode {
    socket: Arc<UdpSocket>,
    pending: Pending,
    handler: Option<QueryHandler>,
    timeout: Duration,
    pub stats: Stats,
}

impl std::fmt::Debug for KrpcNode {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("KrpcNode")
            .field("local_addr", &self.socket.local_addr().ok())
            .field("has_handler", &self.handler.is_some())
            .finish_non_exhaustive()
    }
}

impl KrpcNode {
    /// Bind to `addr` and start serving. `handler` (if any) answers inbound
    /// queries. The receive loop holds only a `Weak` reference, so dropping the
    /// returned `Arc` lets the node shut down.
    pub async fn bind(
        addr: SocketAddr,
        handler: Option<QueryHandler>,
        timeout: Duration,
    ) -> std::io::Result<Arc<Self>> {
        let socket = Arc::new(UdpSocket::bind(addr).await?);
        let node = Arc::new(Self {
            socket: socket.clone(),
            pending: Mutex::new(HashMap::new()),
            handler,
            timeout,
            stats: Stats::default(),
        });
        let weak = Arc::downgrade(&node);
        tokio::spawn(recv_loop(weak, socket));
        Ok(node)
    }

    pub fn local_addr(&self) -> std::io::Result<SocketAddr> {
        self.socket.local_addr()
    }

    /// Send a query to `addr` and await the response dict (`r`).
    pub async fn query(
        &self,
        method: &[u8],
        args: Dict,
        addr: SocketAddr,
    ) -> Result<Dict, QueryError> {
        let (tx, rx) = oneshot::channel();
        let txn = {
            // Reserve a fresh, unpredictable txn atomically under the lock so two
            // concurrent queries can't collide.
            let mut pend = self.pending.lock().await;
            let mut chosen = None;
            for _ in 0..16 {
                let mut t = [0u8; 2];
                getrandom::getrandom(&mut t).map_err(|_| QueryError::Rng)?;
                if !pend.contains_key(&t) {
                    chosen = Some(t);
                    break;
                }
            }
            let t = chosen.ok_or(QueryError::TxnExhausted)?;
            pend.insert(t, (tx, addr));
            t
        };
        self.stats.tx_query.fetch_add(1, Ordering::Relaxed);
        let _ = self
            .socket
            .send_to(&encode_query(&txn, method, args), addr)
            .await;

        match tokio::time::timeout(self.timeout, rx).await {
            Ok(Ok(Ok(response))) => Ok(response),
            Ok(Ok(Err(krpc))) => Err(QueryError::Krpc(krpc)),
            Ok(Err(_)) => Err(QueryError::Cancelled), // sender dropped
            Err(_) => {
                self.stats.timeouts.fetch_add(1, Ordering::Relaxed);
                self.pending.lock().await.remove(&txn);
                Err(QueryError::Timeout)
            }
        }
    }

    async fn handle_datagram(&self, data: &[u8], addr: SocketAddr) {
        let msg = match parse_message(data) {
            Ok(m) => m,
            Err(_) => {
                self.stats.dropped.fetch_add(1, Ordering::Relaxed);
                return;
            }
        };
        match msg {
            KrpcMessage::Query { txn, method, args } => {
                self.stats.rx_query.fetch_add(1, Ordering::Relaxed);
                if let Some(handler) = &self.handler {
                    let reply = match handler(&method, &args, addr) {
                        Ok(Some(resp)) => Some(encode_response(&txn, resp)),
                        Ok(None) => None,
                        Err(e) => Some(encode_error(&txn, e.code, &e.message)),
                    };
                    if let Some(bytes) = reply {
                        let _ = self.socket.send_to(&bytes, addr).await;
                    }
                }
            }
            KrpcMessage::Response { txn, response } => {
                self.stats.rx_response.fetch_add(1, Ordering::Relaxed);
                if let Some(tx) = self.match_pending(&txn, addr).await {
                    let _ = tx.send(Ok(response));
                }
            }
            KrpcMessage::Error { txn, code, message } => {
                self.stats.rx_error.fetch_add(1, Ordering::Relaxed);
                if let Some(tx) = self.match_pending(&txn, addr).await {
                    let _ = tx.send(Err(KrpcError { code, message }));
                }
            }
        }
    }

    /// Resolve `txn` to its pending sender, enforcing the source-address match.
    async fn match_pending(&self, txn: &[u8], addr: SocketAddr) -> Option<oneshot::Sender<Reply>> {
        // Our own txns are always exactly 2 bytes; anything else can't match.
        let key: [u8; 2] = txn.try_into().ok()?;
        let mut pend = self.pending.lock().await;
        let dest = pend.get(&key)?.1;
        if dest != addr {
            // Off-path injection: valid pending txn, wrong source. Drop it and
            // leave the query pending for the genuine reply.
            self.stats.dropped.fetch_add(1, Ordering::Relaxed);
            self.stats.spoofed.fetch_add(1, Ordering::Relaxed);
            return None;
        }
        pend.remove(&key).map(|(tx, _)| tx)
    }
}

async fn recv_loop(node: Weak<KrpcNode>, socket: Arc<UdpSocket>) {
    let mut buf = vec![0u8; 65_536];
    loop {
        let (len, addr) = match socket.recv_from(&mut buf).await {
            Ok(x) => x,
            Err(_) => continue, // ICMP port-unreachable etc.; non-fatal
        };
        match node.upgrade() {
            Some(node) => node.handle_datagram(&buf[..len], addr).await,
            None => break, // node dropped -> shut the loop down
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bencode::Ben;

    fn loopback() -> SocketAddr {
        "127.0.0.1:0".parse().unwrap()
    }

    fn ok_handler() -> QueryHandler {
        Arc::new(|method: &[u8], _args: &Dict, _addr: SocketAddr| {
            assert_eq!(method, b"ping");
            let mut r = Dict::new();
            r.insert(b"id".to_vec(), Ben::Bytes(vec![0x11; 20]));
            Ok(Some(r))
        })
    }

    #[tokio::test]
    async fn ping_round_trip() {
        let server = KrpcNode::bind(loopback(), Some(ok_handler()), DEFAULT_TIMEOUT)
            .await
            .unwrap();
        let client = KrpcNode::bind(loopback(), None, DEFAULT_TIMEOUT)
            .await
            .unwrap();
        let mut args = Dict::new();
        args.insert(b"id".to_vec(), Ben::Bytes(vec![0x22; 20]));
        let resp = client
            .query(b"ping", args, server.local_addr().unwrap())
            .await
            .unwrap();
        assert_eq!(
            resp.get(b"id".as_slice()),
            Some(&Ben::Bytes(vec![0x11; 20]))
        );
        assert_eq!(client.stats.tx_query.load(Ordering::Relaxed), 1);
        assert_eq!(server.stats.rx_query.load(Ordering::Relaxed), 1);
    }

    #[tokio::test]
    async fn timeout_when_no_server() {
        let client = KrpcNode::bind(loopback(), None, Duration::from_millis(150))
            .await
            .unwrap();
        // 127.0.0.1:1 — nothing listening; must time out, not hang or panic.
        let dead: SocketAddr = "127.0.0.1:1".parse().unwrap();
        let r = client.query(b"ping", Dict::new(), dead).await;
        assert!(matches!(r, Err(QueryError::Timeout)));
        assert_eq!(client.stats.timeouts.load(Ordering::Relaxed), 1);
    }

    #[tokio::test]
    async fn spoofed_response_is_rejected() {
        use crate::krpc::{parse_message, KrpcMessage};

        // 500 ms — and this one value sets both the observation window and what
        // the test costs. `match_pending` counts a forged datagram only while its
        // txn is still in the pending map (it resolves `pend.get(&key)?`, bumps
        // both counters and returns without removing the entry), and the only
        // thing that ever removes that entry is this query's own timeout path.
        // So the query deadline IS the window the rejection has to be seen in,
        // and the assertion at the bottom — that the genuine query ends in
        // `Err(QueryError::Timeout)` — cannot resolve until that same deadline
        // elapses. The test cannot be cheaper than one timeout.
        //
        // It was briefly 3 s, on the theory that a loaded 2-core runner could
        // leave the `current_thread` `recv_loop` unscheduled for half a second
        // and drop the forged reply into an empty map. Measured, it does not
        // come close: with the whole `--lib` binary running under a 16-way CPU
        // load, the datagram was counted 2.12–2.18 ms after the attacker's
        // `send_to` across 8 runs, so 500 ms is a ~230× margin. The 3 s bought no
        // safety that was reachable and charged its whole self to `q.await`
        // below, which made this one test most of the `--lib` binary: measured on
        // an idle box, that binary runs 3.02–3.06 s with a 3 s timeout here and
        // 0.93–0.96 s with 500 ms.
        let client = KrpcNode::bind(loopback(), None, Duration::from_millis(500))
            .await
            .unwrap();
        // A raw socket that receives the query (so it learns the real txn) but
        // never replies from its own address.
        let server = UdpSocket::bind(loopback()).await.unwrap();
        let server_addr = server.local_addr().unwrap();
        // A separate socket: the off-path attacker (a different source address).
        let attacker = UdpSocket::bind(loopback()).await.unwrap();
        let client_addr = client.local_addr().unwrap();

        let c = client.clone();
        let q = tokio::spawn(async move { c.query(b"ping", Dict::new(), server_addr).await });

        // Server reads the query and extracts the unpredictable txn.
        let mut buf = vec![0u8; 1500];
        let (len, _) = server.recv_from(&mut buf).await.unwrap();
        let txn = match parse_message(&buf[..len]).unwrap() {
            KrpcMessage::Query { txn, .. } => txn,
            other => panic!("expected query, got {other:?}"),
        };

        // Attacker forges a response with the CORRECT txn but the WRONG source.
        let mut r = Dict::new();
        r.insert(b"id".to_vec(), Ben::Bytes(vec![0x99; 20]));
        attacker
            .send_to(&encode_response(&txn, r), client_addr)
            .await
            .unwrap();

        // Wait for the rejection itself rather than for the query to finish. The
        // counter reaching 1 IS the event under test, and polling for it takes
        // the scheduler out of the assertion: the datagram is rejected whenever
        // the `recv_loop` next runs, which on a busy machine is later than the
        // send but is still a rejection.
        //
        // The deadline is the query's own 500 ms, which is the longest one that
        // can mean anything: the moment the query times out it takes the pending
        // entry with it, and from then on `match_pending` cannot count anything,
        // so a poll loop that outlived the query would spin against counters
        // frozen at 0 and report the failure late. Against the 2.12–2.18 ms this
        // actually takes under load, 500 ms is slack enough that reaching the
        // deadline means spoof detection is broken, not that the machine was
        // slow.
        let deadline = tokio::time::Instant::now() + Duration::from_millis(500);
        while client.stats.spoofed.load(Ordering::Relaxed) != 1 {
            assert!(
                tokio::time::Instant::now() < deadline,
                "the forged response was never counted as spoofed"
            );
            tokio::time::sleep(Duration::from_millis(5)).await;
        }
        // Rejected on the source-address check, and counted once as both. Both
        // reads land ~2 ms in, while the txn is still pending — nothing removes
        // it until the query times out at 500 ms, which is `q.await` below.
        assert_eq!(client.stats.spoofed.load(Ordering::Relaxed), 1);
        assert_eq!(client.stats.dropped.load(Ordering::Relaxed), 1);
        // …and the query it forged a reply to is still pending, so it times out:
        // the genuine server never replied from its own address.
        let result = q.await.unwrap();
        assert!(matches!(result, Err(QueryError::Timeout)));
    }
}
