//! Mainline DHT node + passive infohash harvester (BEP-5, BEP-51).
//!
//! [`DhtNode`] wires the routing table ([`crate::routing`]) to the async KRPC
//! transport ([`crate::transport`]). It answers the four standard queries
//! (`ping`, `find_node`, `get_peers`, `announce_peer`) plus `sample_infohashes`
//! (BEP-51) and, magnetico-style, *harvests* infohashes out of inbound
//! `get_peers`/`announce_peer` traffic (the passive-indexing approach) while also
//! actively walking the DHT with `find_node` to widen its routing table and
//! attract more traffic.
//!
//! Every path is loopback-testable: two [`DhtNode`]s on `127.0.0.1` exchange the
//! full query set and populate each other's routing tables with no external
//! network (see the tests).
//!
//! ## Shared state without a lock across `.await`
//!
//! The inbound handler (which the transport invokes *synchronously* from its
//! receive loop) and the outbound client methods both touch the routing table
//! and the BEP-51 sample ring. Those live behind plain [`std::sync::Mutex`]es in
//! a shared [`DhtState`]: each critical section is a handful of instructions and
//! is always released before any `.await`, so the futures stay `Send` and the
//! sync handler never blocks on an async lock.

use crate::bencode::Ben;
use crate::krpc::{Dict, KrpcError, ERR_METHOD_UNKNOWN, ERR_PROTOCOL};
use crate::routing::{
    decode_nodes, decode_peers, distance, encode_endpoint, encode_nodes, random_node_id, Node,
    NodeId, RoutingTable, DEFAULT_K, ID_BYTES,
};
use crate::transport::{KrpcNode, QueryError, QueryHandler, Stats, DEFAULT_TIMEOUT};

use std::collections::{BTreeSet, HashMap, HashSet, VecDeque};
use std::net::{Ipv4Addr, SocketAddr, SocketAddrV4};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;

/// Fan-out for the iterative `get_peers` lookup (queried per round).
pub const LOOKUP_ALPHA: usize = 3;
/// Peers retained per infohash in the served peer store.
pub const MAX_PEERS_PER_INFOHASH: usize = 128;
/// Distinct infohashes retained in the served peer store (LRU-evicted).
pub const MAX_STORED_INFOHASHES: usize = 4096;

/// BEP-51: how many infohashes we hand back per `sample_infohashes` response.
pub const SAMPLE_MAX: usize = 20;
/// BEP-51 `interval` (seconds) we advertise before a re-sample is worthwhile.
pub const SAMPLE_INTERVAL: i64 = 21_600; // 6h, the BEP-51 recommendation ceiling
/// Upper bound on the ring of recently-seen infohashes we can serve via BEP-51.
pub const RECENT_INFOHASH_CAP: usize = 2000;

/// A harvested-infohash sink: `(infohash, peer)` where `peer` is `Some` only for
/// an `announce_peer`. It must not panic (a panic unwinds the receive task).
pub type InfohashSink = Arc<dyn Fn(&NodeId, Option<SocketAddrV4>) + Send + Sync>;

/// `(peers, nodes, token)` returned by an outbound [`DhtNode::get_peers`].
pub type GetPeersOutcome = (Vec<SocketAddrV4>, Vec<Node>, Option<Vec<u8>>);
/// `(samples, nodes, num, interval)` from [`DhtNode::sample_infohashes`].
pub type SampleOutcome = (Vec<NodeId>, Vec<Node>, Option<i64>, Option<i64>);

/// Default Mainline bootstrap routers (used only when a real network exists).
pub fn default_bootstrap() -> Vec<(String, u16)> {
    vec![
        ("router.bittorrent.com".to_string(), 6881),
        ("router.utorrent.com".to_string(), 6881),
        ("dht.transmissionbt.com".to_string(), 6881),
        ("router.bitcomet.com".to_string(), 6881),
    ]
}

/// Return an ID that shares `shared` leading bytes with `target` and takes the
/// rest from `self_id`.
///
/// This is the "neighbours"/Sybil trick magnetico uses to file the node close to
/// a target in ID space so more of that target's `get_peers` traffic is routed
/// to it. Off by default; exposed for operators who want aggressive harvesting.
pub fn make_neighbor_id(target: &NodeId, self_id: &NodeId, shared: usize) -> NodeId {
    let shared = shared.min(ID_BYTES);
    let mut out = *self_id;
    out[..shared].copy_from_slice(&target[..shared]);
    out
}

// --- small helpers --------------------------------------------------------

/// Fill `buf` from the OS CSPRNG; on the (astronomically unlikely) failure leave
/// it zeroed so callers degrade to a deterministic-but-safe fallback.
fn rand_fill(buf: &mut [u8]) {
    if getrandom::getrandom(buf).is_err() {
        buf.iter_mut().for_each(|b| *b = 0);
    }
}

fn rand_u64() -> u64 {
    let mut b = [0u8; 8];
    rand_fill(&mut b);
    u64::from_le_bytes(b)
}

/// Extract a 20-byte id-like field (`id`/`target`/`info_hash`) from args.
fn want_id(args: &Dict, key: &[u8]) -> Option<NodeId> {
    match args.get(key) {
        Some(Ben::Bytes(b)) if b.len() == ID_BYTES => {
            let mut id = [0u8; ID_BYTES];
            id.copy_from_slice(b);
            Some(id)
        }
        _ => None,
    }
}

/// The routing table only holds IPv4 contacts (compact "nodes" is 26 bytes); a
/// v6 source is answered but not filed.
fn as_v4(addr: SocketAddr) -> Option<SocketAddrV4> {
    match addr {
        SocketAddr::V4(a) => Some(a),
        SocketAddr::V6(_) => None,
    }
}

fn proto(msg: &str) -> KrpcError {
    KrpcError {
        code: ERR_PROTOCOL,
        message: msg.to_string(),
    }
}

// --- the BEP-51 recent-infohash ring --------------------------------------

/// A bounded, LRU-evicted set of recently-seen infohashes we can sample from.
#[derive(Default)]
struct RecentRing {
    order: VecDeque<NodeId>,
    seen: HashSet<NodeId>,
}

impl RecentRing {
    fn remember(&mut self, ih: NodeId) {
        if self.seen.contains(&ih) {
            if let Some(pos) = self.order.iter().position(|x| *x == ih) {
                self.order.remove(pos); // refresh: move to the most-recent end
            }
            self.order.push_back(ih);
            return;
        }
        self.seen.insert(ih);
        self.order.push_back(ih);
        while self.order.len() > RECENT_INFOHASH_CAP {
            if let Some(old) = self.order.pop_front() {
                self.seen.remove(&old);
            }
        }
    }

    fn len(&self) -> usize {
        self.order.len()
    }

    /// A random sample of up to `count` infohashes, concatenated (BEP-51 blob).
    ///
    /// Bounded ingestion is the point: a datagram can hold thousands of hashes,
    /// so we cap what we emit and pick a random subset (partial Fisher–Yates)
    /// rather than always returning the same head of the ring.
    fn sample(&self, count: usize) -> Vec<u8> {
        let n = self.order.len();
        if n == 0 {
            return Vec::new();
        }
        let take = count.min(n);
        let keys: Vec<&NodeId> = self.order.iter().collect();
        let mut idx: Vec<usize> = (0..n).collect();
        let mut rnd = vec![0u8; take * 8];
        rand_fill(&mut rnd);
        for (i, chunk) in rnd.chunks_exact(8).enumerate() {
            let r = u64::from_le_bytes(chunk.try_into().unwrap()) as usize;
            let j = i + (r % (n - i));
            idx.swap(i, j);
        }
        let mut out = Vec::with_capacity(take * ID_BYTES);
        for &i in &idx[..take] {
            out.extend_from_slice(keys[i]);
        }
        out
    }
}

// --- the served peer store (infohash -> announced peers) -------------------

/// Peers announced to us per infohash, so we can answer `get_peers` with
/// `values`. Bounded two ways: peers-per-infohash and total infohashes (both
/// FIFO-evicted by insertion order), so a flood of announces can't grow it
/// without bound.
#[derive(Default)]
struct PeerStore {
    order: VecDeque<NodeId>, // infohash insertion order, for eviction
    map: HashMap<NodeId, Vec<SocketAddrV4>>,
}

impl PeerStore {
    fn add(&mut self, info_hash: NodeId, peer: SocketAddrV4) {
        let is_new = !self.map.contains_key(&info_hash);
        let entry = self.map.entry(info_hash).or_default();
        if !entry.contains(&peer) {
            entry.push(peer);
            if entry.len() > MAX_PEERS_PER_INFOHASH {
                entry.remove(0); // drop the oldest peer for this infohash
            }
        }
        if is_new {
            self.order.push_back(info_hash);
            while self.map.len() > MAX_STORED_INFOHASHES {
                match self.order.pop_front() {
                    Some(old) => {
                        self.map.remove(&old);
                    }
                    None => break,
                }
            }
        }
    }

    fn get(&self, info_hash: &NodeId) -> Vec<SocketAddrV4> {
        self.map.get(info_hash).cloned().unwrap_or_default()
    }
}

// --- shared node state -----------------------------------------------------

struct DhtState {
    self_id: NodeId,
    k: usize,
    neighbor: bool,
    neighbor_shared: usize,
    token_secret: [u8; 16],
    routing: Mutex<RoutingTable>,
    recent: Mutex<RecentRing>,
    peers: Mutex<PeerStore>,
    on_infohash: Option<InfohashSink>,
    harvested: AtomicU64,
}

impl DhtState {
    fn id_dict(&self) -> Dict {
        let mut r = Dict::new();
        r.insert(b"id".to_vec(), Ben::Bytes(self.self_id.to_vec()));
        r
    }

    /// An opaque per-address token: `SHA-1(secret || ip)[..8]`. It never crosses
    /// the Python/Rust boundary (a get_peers and its matching announce are always
    /// served by the same instance), so it need not be byte-identical to Python —
    /// only self-consistent and unforgeable without the secret.
    fn make_token(&self, addr: SocketAddr) -> Vec<u8> {
        let mut data = self.token_secret.to_vec();
        match addr {
            SocketAddr::V4(a) => data.extend_from_slice(&a.ip().octets()),
            SocketAddr::V6(a) => data.extend_from_slice(&a.ip().octets()),
        }
        crate::infohash::sha1(&data)[..8].to_vec()
    }

    fn valid_token(&self, token: Option<&Ben>, addr: SocketAddr) -> bool {
        matches!(token, Some(Ben::Bytes(t)) if *t == self.make_token(addr))
    }

    fn harvest(&self, ih: &NodeId, peer: Option<SocketAddrV4>) {
        self.harvested.fetch_add(1, Ordering::Relaxed);
        self.recent.lock().unwrap().remember(*ih); // lock released at the `;`
        if let Some(sink) = &self.on_infohash {
            sink(ih, peer); // invoked with no lock held
        }
    }

    /// Learn contacts from a response we received from `addr`.
    fn absorb(&self, response: &Dict, addr: SocketAddr) {
        let mut rt = self.routing.lock().unwrap();
        if let (Some(id), Some(v4)) = (want_id(response, b"id"), as_v4(addr)) {
            rt.add_node(Node::new(id, v4));
        }
        if let Some(Ben::Bytes(raw)) = response.get(b"nodes".as_slice()) {
            for n in decode_nodes(raw) {
                rt.add_node(n);
            }
        }
    }

    /// The inbound handler: answers a query, harvesting infohashes as a side
    /// effect. Runs synchronously inside the transport's receive loop.
    fn on_query(
        &self,
        method: &[u8],
        args: &Dict,
        addr: SocketAddr,
    ) -> Result<Option<Dict>, KrpcError> {
        // File the querier (every query carries the sender's id).
        if let (Some(id), Some(v4)) = (want_id(args, b"id"), as_v4(addr)) {
            self.routing.lock().unwrap().add_node(Node::new(id, v4));
        }

        match method {
            b"ping" => Ok(Some(self.id_dict())),

            b"find_node" => {
                let target = want_id(args, b"target").ok_or_else(|| proto("bad target"))?;
                let nodes = self.routing.lock().unwrap().find_closest(&target, self.k);
                let mut r = self.id_dict();
                r.insert(b"nodes".to_vec(), Ben::Bytes(encode_nodes(&nodes)));
                Ok(Some(r))
            }

            b"get_peers" => {
                let ih = want_id(args, b"info_hash").ok_or_else(|| proto("bad info_hash"))?;
                self.harvest(&ih, None);
                let nodes = self.routing.lock().unwrap().find_closest(&ih, self.k);
                let mut r = self.id_dict();
                r.insert(b"token".to_vec(), Ben::Bytes(self.make_token(addr)));
                // BEP-5: return `values` (compact 6-byte peers) when we hold any
                // for this infohash, plus the closest nodes to keep the lookup
                // converging.
                let stored = self.peers.lock().unwrap().get(&ih);
                if !stored.is_empty() {
                    let values = stored
                        .iter()
                        .map(|p| Ben::Bytes(encode_endpoint(p).to_vec()))
                        .collect();
                    r.insert(b"values".to_vec(), Ben::List(values));
                }
                r.insert(b"nodes".to_vec(), Ben::Bytes(encode_nodes(&nodes)));
                Ok(Some(r))
            }

            b"announce_peer" => {
                let ih = want_id(args, b"info_hash").ok_or_else(|| proto("bad info_hash"))?;
                // BEP-5: the announce must echo the token from a prior get_peers
                // for this address, else anyone could inject peers.
                if !self.valid_token(args.get(b"token".as_slice()), addr) {
                    return Err(proto("bad token"));
                }
                let implied =
                    matches!(args.get(b"implied_port".as_slice()), Some(Ben::Int(n)) if *n != 0);
                let port = if implied {
                    addr.port()
                } else {
                    match args.get(b"port".as_slice()) {
                        Some(Ben::Int(p)) if (0..=65535).contains(p) => *p as u16,
                        _ => 0,
                    }
                };
                let peer = as_v4(addr).map(|v4| SocketAddrV4::new(*v4.ip(), port));
                if let Some(p) = peer {
                    self.peers.lock().unwrap().add(ih, p); // serve it back on get_peers
                }
                self.harvest(&ih, peer);
                Ok(Some(self.id_dict()))
            }

            b"sample_infohashes" => {
                let target = want_id(args, b"target").ok_or_else(|| proto("bad target"))?;
                let nodes = self.routing.lock().unwrap().find_closest(&target, self.k);
                let (num, samples) = {
                    let recent = self.recent.lock().unwrap();
                    (recent.len() as i64, recent.sample(SAMPLE_MAX))
                };
                let mut r = self.id_dict();
                r.insert(b"interval".to_vec(), Ben::Int(SAMPLE_INTERVAL));
                r.insert(b"nodes".to_vec(), Ben::Bytes(encode_nodes(&nodes)));
                r.insert(b"num".to_vec(), Ben::Int(num));
                r.insert(b"samples".to_vec(), Ben::Bytes(samples));
                Ok(Some(r))
            }

            other => Err(KrpcError {
                code: ERR_METHOD_UNKNOWN,
                message: format!("unknown method {}", String::from_utf8_lossy(other)),
            }),
        }
    }
}

// --- construction ----------------------------------------------------------

/// How to bring a [`DhtNode`] up. Fill in what you need; the rest defaults.
pub struct DhtConfig {
    /// The node's own 160-bit id (random if `None`).
    pub node_id: Option<NodeId>,
    /// Local UDP endpoint to bind (`0.0.0.0:0` picks any port).
    pub bind: SocketAddr,
    /// Sink for harvested infohashes.
    pub on_infohash: Option<InfohashSink>,
    /// Bucket size / query fan-out (Mainline uses 8).
    pub k: usize,
    /// Enable the Sybil "neighbours" trick on outbound crawl queries.
    pub neighbor: bool,
    /// Shared-prefix length for the neighbour id.
    pub neighbor_shared: usize,
    /// Per-query timeout.
    pub timeout: Duration,
}

impl Default for DhtConfig {
    fn default() -> Self {
        Self {
            node_id: None,
            bind: SocketAddr::V4(SocketAddrV4::new(Ipv4Addr::UNSPECIFIED, 0)),
            on_infohash: None,
            k: DEFAULT_K,
            neighbor: false,
            neighbor_shared: 15,
            timeout: DEFAULT_TIMEOUT,
        }
    }
}

/// A live Mainline DHT node. Cheap to [`Clone`] (two `Arc`s); dropping every
/// clone shuts the underlying transport down.
#[derive(Clone)]
pub struct DhtNode {
    state: Arc<DhtState>,
    node: Arc<KrpcNode>,
}

impl DhtNode {
    /// Bind and start serving.
    pub async fn bind(cfg: DhtConfig) -> std::io::Result<Self> {
        let self_id = cfg.node_id.unwrap_or_else(random_node_id);
        let mut token_secret = [0u8; 16];
        rand_fill(&mut token_secret);
        let state = Arc::new(DhtState {
            self_id,
            k: cfg.k,
            neighbor: cfg.neighbor,
            neighbor_shared: cfg.neighbor_shared,
            token_secret,
            routing: Mutex::new(RoutingTable::new(self_id, cfg.k)),
            recent: Mutex::new(RecentRing::default()),
            peers: Mutex::new(PeerStore::default()),
            on_infohash: cfg.on_infohash,
            harvested: AtomicU64::new(0),
        });
        let handler_state = state.clone();
        let handler: QueryHandler =
            Arc::new(move |method: &[u8], args: &Dict, addr: SocketAddr| {
                handler_state.on_query(method, args, addr)
            });
        let node = KrpcNode::bind(cfg.bind, Some(handler), cfg.timeout).await?;
        Ok(Self { state, node })
    }

    // -- accessors ---------------------------------------------------------

    pub fn self_id(&self) -> NodeId {
        self.state.self_id
    }

    pub fn local_addr(&self) -> std::io::Result<SocketAddr> {
        self.node.local_addr()
    }

    /// Total infohashes harvested from inbound `get_peers`/`announce_peer`.
    pub fn harvested(&self) -> u64 {
        self.state.harvested.load(Ordering::Relaxed)
    }

    pub fn routing_len(&self) -> usize {
        self.state.routing.lock().unwrap().len()
    }

    /// A snapshot of every contact currently in the routing table.
    pub fn contacts(&self) -> Vec<Node> {
        self.state.routing.lock().unwrap().all_nodes()
    }

    /// Transport counters (tx/rx/timeouts/spoofed …).
    pub fn stats(&self) -> &Stats {
        &self.node.stats
    }

    // -- outbound client queries -------------------------------------------

    pub async fn ping(&self, addr: SocketAddr) -> Result<Dict, QueryError> {
        let r = self.node.query(b"ping", self.state.id_dict(), addr).await?;
        self.state.absorb(&r, addr);
        Ok(r)
    }

    /// The id we advertise in a query — a target-neighbour when enabled.
    fn source_id(&self, target: &NodeId) -> NodeId {
        if self.state.neighbor {
            make_neighbor_id(target, &self.state.self_id, self.state.neighbor_shared)
        } else {
            self.state.self_id
        }
    }

    pub async fn find_node(
        &self,
        target: &NodeId,
        addr: SocketAddr,
    ) -> Result<Vec<Node>, QueryError> {
        self.find_node_as(target, addr, self.state.self_id).await
    }

    async fn find_node_as(
        &self,
        target: &NodeId,
        addr: SocketAddr,
        source_id: NodeId,
    ) -> Result<Vec<Node>, QueryError> {
        let mut a = Dict::new();
        a.insert(b"id".to_vec(), Ben::Bytes(source_id.to_vec()));
        a.insert(b"target".to_vec(), Ben::Bytes(target.to_vec()));
        let r = self.node.query(b"find_node", a, addr).await?;
        self.state.absorb(&r, addr);
        let nodes = match r.get(b"nodes".as_slice()) {
            Some(Ben::Bytes(raw)) => decode_nodes(raw),
            _ => Vec::new(),
        };
        Ok(nodes)
    }

    pub async fn get_peers(
        &self,
        info_hash: &NodeId,
        addr: SocketAddr,
    ) -> Result<GetPeersOutcome, QueryError> {
        let mut a = Dict::new();
        a.insert(b"id".to_vec(), Ben::Bytes(self.state.self_id.to_vec()));
        a.insert(b"info_hash".to_vec(), Ben::Bytes(info_hash.to_vec()));
        let r = self.node.query(b"get_peers", a, addr).await?;
        self.state.absorb(&r, addr);
        let peers = match r.get(b"values".as_slice()) {
            Some(Ben::List(vals)) => decode_peers(vals.iter().filter_map(|v| match v {
                Ben::Bytes(b) => Some(b.as_slice()),
                _ => None,
            })),
            _ => Vec::new(),
        };
        let nodes = match r.get(b"nodes".as_slice()) {
            Some(Ben::Bytes(raw)) => decode_nodes(raw),
            _ => Vec::new(),
        };
        let token = match r.get(b"token".as_slice()) {
            Some(Ben::Bytes(t)) => Some(t.clone()),
            _ => None,
        };
        Ok((peers, nodes, token))
    }

    pub async fn announce_peer(
        &self,
        info_hash: &NodeId,
        port: u16,
        token: &[u8],
        addr: SocketAddr,
        implied_port: bool,
    ) -> Result<Dict, QueryError> {
        let mut a = Dict::new();
        a.insert(b"id".to_vec(), Ben::Bytes(self.state.self_id.to_vec()));
        a.insert(b"info_hash".to_vec(), Ben::Bytes(info_hash.to_vec()));
        a.insert(b"port".to_vec(), Ben::Int(i64::from(port)));
        a.insert(b"token".to_vec(), Ben::Bytes(token.to_vec()));
        a.insert(b"implied_port".to_vec(), Ben::Int(i64::from(implied_port)));
        let r = self.node.query(b"announce_peer", a, addr).await?;
        self.state.absorb(&r, addr);
        Ok(r)
    }

    pub async fn sample_infohashes(
        &self,
        target: &NodeId,
        addr: SocketAddr,
    ) -> Result<SampleOutcome, QueryError> {
        let mut a = Dict::new();
        a.insert(b"id".to_vec(), Ben::Bytes(self.state.self_id.to_vec()));
        a.insert(b"target".to_vec(), Ben::Bytes(target.to_vec()));
        let r = self.node.query(b"sample_infohashes", a, addr).await?;
        self.state.absorb(&r, addr);
        let samples = match r.get(b"samples".as_slice()) {
            Some(Ben::Bytes(blob)) => {
                // Cap ingestion at SAMPLE_MAX: without a bound one hostile node
                // could flood the fetch queue with attacker-chosen infohashes.
                let usable = (blob.len() - blob.len() % ID_BYTES).min(SAMPLE_MAX * ID_BYTES);
                blob[..usable]
                    .chunks_exact(ID_BYTES)
                    .map(|c| {
                        let mut id = [0u8; ID_BYTES];
                        id.copy_from_slice(c);
                        id
                    })
                    .collect()
            }
            _ => Vec::new(),
        };
        let nodes = match r.get(b"nodes".as_slice()) {
            Some(Ben::Bytes(raw)) => decode_nodes(raw),
            _ => Vec::new(),
        };
        let num = match r.get(b"num".as_slice()) {
            Some(Ben::Int(n)) => Some(*n),
            _ => None,
        };
        let interval = match r.get(b"interval".as_slice()) {
            Some(Ben::Int(n)) => Some(*n),
            _ => None,
        };
        Ok((samples, nodes, num, interval))
    }

    // -- iterative lookup --------------------------------------------------

    /// Iterative Kademlia `get_peers` lookup: starting from our closest known
    /// contacts, query `get_peers` toward `info_hash`, absorb the returned peers
    /// and any closer nodes, then re-query the closest contacts we haven't asked
    /// yet — until the frontier is exhausted or `max_rounds` elapse. Returns the
    /// deduplicated peers discovered, in ascending address order.
    ///
    /// Up to [`LOOKUP_ALPHA`] contacts are queried concurrently per round. This
    /// is what turns a harvested infohash into a fetchable swarm: `announce_peer`
    /// traffic seeds peer stores across the DHT, and this walk collects them.
    pub async fn lookup_peers(&self, info_hash: &NodeId, max_rounds: usize) -> Vec<SocketAddrV4> {
        let target = *info_hash;
        let mut shortlist = self
            .state
            .routing
            .lock()
            .unwrap()
            .find_closest(&target, self.state.k);
        let mut queried: HashSet<NodeId> = HashSet::new();
        let mut peers: BTreeSet<SocketAddrV4> = BTreeSet::new();

        for _ in 0..max_rounds {
            // The α closest contacts we have not yet asked.
            shortlist.sort_by_key(|n| distance(&n.id, &target));
            let batch: Vec<Node> = shortlist
                .iter()
                .filter(|n| n.id != self.state.self_id && !queried.contains(&n.id))
                .take(LOOKUP_ALPHA)
                .cloned()
                .collect();
            if batch.is_empty() {
                break;
            }
            // Query the batch concurrently (DhtNode is cheap to clone).
            let mut handles = Vec::with_capacity(batch.len());
            for n in &batch {
                queried.insert(n.id);
                let node = self.clone();
                let addr = SocketAddr::V4(n.addr);
                handles.push(tokio::spawn(
                    async move { node.get_peers(&target, addr).await },
                ));
            }
            for h in handles {
                if let Ok(Ok((vals, nodes, _token))) = h.await {
                    peers.extend(vals);
                    for nn in nodes {
                        if nn.id != self.state.self_id && !shortlist.iter().any(|x| x.id == nn.id) {
                            shortlist.push(nn);
                        }
                    }
                }
            }
            // Keep the frontier bounded to the closest handful.
            shortlist.sort_by_key(|n| distance(&n.id, &target));
            shortlist.truncate(self.state.k * 4);
        }
        peers.into_iter().collect()
    }

    // -- crawling ----------------------------------------------------------

    /// Resolve each bootstrap router and `find_node(self)` at it to seed the
    /// table. Hostnames are resolved to numeric IPv4 up-front: the transport
    /// drops replies whose source ≠ the query's destination, so a hostname
    /// destination could never match a numeric reply source.
    pub async fn bootstrap_once(&self, routers: &[(String, u16)]) {
        for (host, port) in routers {
            let resolved = match tokio::net::lookup_host((host.as_str(), *port)).await {
                Ok(mut it) => it.find(SocketAddr::is_ipv4),
                Err(_) => None,
            };
            if let Some(addr) = resolved {
                let _ = self.find_node(&self.state.self_id, addr).await;
            }
        }
    }

    /// One widening step: `find_node` toward `target` on our closest contacts,
    /// pruning any that fail to answer. Returns the count of contacts learned.
    pub async fn crawl_once(&self, target: Option<NodeId>) -> usize {
        let target = target.unwrap_or_else(random_node_id);
        let mut contacts = self
            .state
            .routing
            .lock()
            .unwrap()
            .find_closest(&target, self.state.k);
        if contacts.is_empty() {
            self.bootstrap_once(&default_bootstrap()).await;
            contacts = self
                .state
                .routing
                .lock()
                .unwrap()
                .find_closest(&target, self.state.k);
        }
        let source_id = self.source_id(&target);
        let mut found = 0usize;
        for node in contacts {
            match self
                .find_node_as(&target, SocketAddr::V4(node.addr), source_id)
                .await
            {
                Ok(ns) => found += ns.len(),
                Err(_) => self.state.routing.lock().unwrap().remove_node(&node.id),
            }
        }
        found
    }

    /// Background crawl loop: keep walking the DHT to attract traffic. Consumes a
    /// (cheap) clone; spawn it with [`tokio::spawn`] and abort the handle — or
    /// drop all node clones — to stop. Never returns on its own.
    pub async fn run_crawler(self, interval: Duration, bootstrap: Vec<(String, u16)>) {
        self.bootstrap_once(&bootstrap).await;
        loop {
            let _ = self.crawl_once(None).await;
            let span = interval.as_millis() as u64;
            let jitter = Duration::from_millis(rand_u64() % (span + 1));
            tokio::time::sleep(interval + jitter).await;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn loopback() -> SocketAddr {
        "127.0.0.1:0".parse().unwrap()
    }

    fn cfg() -> DhtConfig {
        DhtConfig {
            bind: loopback(),
            ..Default::default()
        }
    }

    #[test]
    fn make_neighbor_id_shares_prefix() {
        let target = [0xAAu8; ID_BYTES];
        let me = [0x55u8; ID_BYTES];
        let n = make_neighbor_id(&target, &me, 15);
        assert_eq!(&n[..15], &target[..15]);
        assert_eq!(&n[15..], &me[15..]);
        assert_eq!(make_neighbor_id(&target, &me, 999), target); // clamp high
        assert_eq!(make_neighbor_id(&target, &me, 0), me); // 0 -> unchanged
    }

    #[tokio::test]
    async fn two_nodes_ping_learn_each_other() {
        let a = DhtNode::bind(cfg()).await.unwrap();
        let b = DhtNode::bind(cfg()).await.unwrap();
        let r = a.ping(b.local_addr().unwrap()).await.unwrap();
        assert_eq!(
            r.get(b"id".as_slice()),
            Some(&Ben::Bytes(b.self_id().to_vec()))
        );
        // A learned B (from the response); B learned A (from the query args).
        assert_eq!(a.routing_len(), 1);
        assert_eq!(b.routing_len(), 1);
    }

    #[tokio::test]
    async fn find_node_returns_known_contacts() {
        let a = DhtNode::bind(cfg()).await.unwrap();
        let b = DhtNode::bind(cfg()).await.unwrap();
        let c = DhtNode::bind(cfg()).await.unwrap();
        let b_addr = b.local_addr().unwrap();
        // B learns A and C by being pinged.
        a.ping(b_addr).await.unwrap();
        c.ping(b_addr).await.unwrap();
        assert_eq!(b.routing_len(), 2);
        // A asks B for nodes near C's id; B returns C, and A absorbs it.
        let nodes = a.find_node(&c.self_id(), b_addr).await.unwrap();
        assert!(nodes.iter().any(|n| n.id == c.self_id()));
        assert!(a.contacts().iter().any(|n| n.id == c.self_id()));
    }

    #[tokio::test]
    async fn get_peers_then_announce_with_token() {
        let log = Arc::new(Mutex::new(Vec::<(NodeId, Option<SocketAddrV4>)>::new()));
        let log2 = log.clone();
        let sink: InfohashSink =
            Arc::new(move |ih: &NodeId, peer| log2.lock().unwrap().push((*ih, peer)));
        let b = DhtNode::bind(DhtConfig {
            on_infohash: Some(sink),
            bind: loopback(),
            ..Default::default()
        })
        .await
        .unwrap();
        let a = DhtNode::bind(cfg()).await.unwrap();
        let b_addr = b.local_addr().unwrap();
        let ih = [0x42u8; ID_BYTES];

        let (_peers, _nodes, token) = a.get_peers(&ih, b_addr).await.unwrap();
        let token = token.expect("get_peers hands back a token");
        assert_eq!(b.harvested(), 1);

        // Good token: accepted, harvested again with our endpoint as the peer.
        a.announce_peer(&ih, 6881, &token, b_addr, false)
            .await
            .unwrap();
        assert_eq!(b.harvested(), 2);

        // Wrong token: rejected with a protocol error; no harvest.
        let bad = a.announce_peer(&ih, 6881, b"badtoken", b_addr, false).await;
        assert!(matches!(bad, Err(QueryError::Krpc(ref e)) if e.code == ERR_PROTOCOL));
        assert_eq!(b.harvested(), 2);

        let seen = log.lock().unwrap();
        assert_eq!(seen.len(), 2);
        assert_eq!(seen[0], (ih, None)); // get_peers: infohash, no peer
        assert_eq!(seen[1].0, ih);
        assert_eq!(seen[1].1.unwrap().port(), 6881); // announce: peer port
    }

    #[tokio::test]
    async fn sample_infohashes_returns_recent() {
        let b = DhtNode::bind(cfg()).await.unwrap();
        let a = DhtNode::bind(cfg()).await.unwrap();
        let b_addr = b.local_addr().unwrap();
        let (ih1, ih2) = ([1u8; ID_BYTES], [2u8; ID_BYTES]);
        a.get_peers(&ih1, b_addr).await.unwrap();
        a.get_peers(&ih2, b_addr).await.unwrap();

        let (samples, _nodes, num, interval) =
            a.sample_infohashes(&[0u8; ID_BYTES], b_addr).await.unwrap();
        assert_eq!(interval, Some(SAMPLE_INTERVAL));
        assert_eq!(num, Some(2));
        assert!(samples.contains(&ih1));
        assert!(samples.contains(&ih2));
    }

    #[tokio::test]
    async fn crawl_once_widens_routing() {
        let a = DhtNode::bind(cfg()).await.unwrap();
        let b = DhtNode::bind(cfg()).await.unwrap();
        let c = DhtNode::bind(cfg()).await.unwrap();
        let b_addr = b.local_addr().unwrap();
        a.ping(b_addr).await.unwrap(); // A knows B
        c.ping(b_addr).await.unwrap(); // B knows C (and A)
        let before = a.routing_len();
        // A crawls toward C: asks its closest contact (B), which returns C.
        a.crawl_once(Some(c.self_id())).await;
        assert!(a.routing_len() > before);
        assert!(a.contacts().iter().any(|n| n.id == c.self_id()));
    }

    #[tokio::test]
    async fn iterative_lookup_finds_announced_peer() {
        let a = DhtNode::bind(cfg()).await.unwrap();
        let b = DhtNode::bind(cfg()).await.unwrap();
        let c = DhtNode::bind(cfg()).await.unwrap();
        let x = DhtNode::bind(cfg()).await.unwrap();
        let b_addr = b.local_addr().unwrap();
        let c_addr = c.local_addr().unwrap();

        // Topology: A knows B; B knows A and C. A does NOT know C yet.
        a.ping(b_addr).await.unwrap();
        c.ping(b_addr).await.unwrap();

        // X announces a peer for the infohash to C (get a token, then announce).
        let ih = [0x55u8; ID_BYTES];
        let (_p, _n, token) = x.get_peers(&ih, c_addr).await.unwrap();
        x.announce_peer(&ih, 6881, &token.unwrap(), c_addr, false)
            .await
            .unwrap();

        // Starting from B, A must hop to C and collect the announced peer.
        let peers = a.lookup_peers(&ih, 4).await;
        assert!(
            peers.iter().any(|p| p.port() == 6881),
            "expected the announced peer, got {peers:?}"
        );
        // and A learned C in the process.
        assert!(a.contacts().iter().any(|n| n.id == c.self_id()));
    }
}
