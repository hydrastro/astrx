//! Harvester orchestrator: DHT crawl → discovery queue → metadata → store (port
//! of `legacy-python/torrentds/indexer.py`). `net`-tier.
//!
//! Ties one or more [`DhtNode`]s to the [`Store`] and the ut_metadata client:
//!
//! 1. inbound `get_peers`/`announce_peer` infohashes are queued in the store via
//!    the node's harvest sink ([`Store::add_discovered`]);
//! 2. a BEP-51 sampler periodically pulls `sample_infohashes` off routing-table
//!    contacts and feeds the results into the same queue (the biggest lever);
//! 3. the DHT crawler keeps walking to attract more of that traffic;
//! 4. a bounded pool of fetch workers drains the queue in parallel, fetching +
//!    verifying metadata over the peer wire ([`Indexer::fetch_and_store`]);
//! 5. a maintenance loop prunes the queue and enforces retention;
//! 6. routing contacts are persisted periodically so restarts resume warm.
//!
//! Every fetch is contained: one malformed/hostile peer can only fail its own
//! task. [`Indexer::fetch_and_store`] is the unit under test in the loopback path.

use crate::metadata::fetch_metadata;
use crate::routing::{random_node_id, InfoHash, Node};
use crate::store::Store;
use crate::{DhtConfig, DhtNode, InfohashSink};
use std::collections::HashSet;
use std::net::{IpAddr, Ipv4Addr, Ipv6Addr, SocketAddr, SocketAddrV4};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tokio::sync::Semaphore;

fn now_secs() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

/// True unless `ip` is a routable public unicast address.
///
/// Peer addresses arrive as four raw bytes in a `get_peers` reply's `values`
/// (`routing::decode_endpoint` accepts any four), and the DHT contact that sends
/// them chooses what they are — nothing in the protocol ties them to a real
/// swarm. So `values = ["\x7f\x00\x00\x01\x00\x16", "\x0a\x00\x00\x05\x0c\xea",
/// "\xa9\xfe\xa9\xfe\x00\x50"]` asks the indexer to open TCP to `127.0.0.1:22`,
/// `10.0.0.5:3306` and `169.254.169.254:80` (the cloud metadata endpoint) and
/// speak BitTorrent at them. This is the list of everything that must never be
/// dialled: loopback, RFC1918 private, CGNAT, link-local, unspecified, multicast
/// and broadcast, documentation/benchmarking/reserved space, and the IPv6
/// equivalents. `Ipv4Addr::is_global` is still unstable on the 1.80 MSRV, so the
/// ranges are spelled out.
#[must_use]
pub fn is_internal_ip(ip: IpAddr) -> bool {
    match ip {
        IpAddr::V4(v4) => is_internal_v4(v4),
        IpAddr::V6(v6) => is_internal_v6(v6),
    }
}

fn is_internal_v4(ip: Ipv4Addr) -> bool {
    let [a, b, c, _] = ip.octets();
    a == 0                                          // 0.0.0.0/8 ("this network")
        || a == 10                                  // RFC1918
        || (a == 100 && (64..128).contains(&b))     // 100.64/10 CGNAT
        || a == 127                                 // loopback
        || (a == 169 && b == 254)                   // link-local + cloud metadata
        || (a == 172 && (16..32).contains(&b))      // RFC1918
        || (a == 192 && b == 0 && (c == 0 || c == 2)) // IETF assignments, TEST-NET-1
        || (a == 192 && b == 88 && c == 99)         // 6to4 relay anycast
        || (a == 192 && b == 168)                   // RFC1918
        || (a == 198 && (18..20).contains(&b))      // benchmarking
        || (a == 198 && b == 51 && c == 100)        // TEST-NET-2
        || (a == 203 && b == 0 && c == 113)         // TEST-NET-3
        || a >= 224 // multicast, reserved 240/4, and 255.255.255.255
}

fn is_internal_v6(ip: Ipv6Addr) -> bool {
    // An IPv4-mapped/compatible address is just IPv4 wearing a hat — judge the
    // embedded address, else ::ffff:127.0.0.1 walks straight past the v4 rules.
    if let Some(v4) = ip.to_ipv4_mapped().or_else(|| ip.to_ipv4()) {
        return is_internal_v4(v4);
    }
    let s = ip.segments();
    ip.is_unspecified()
        || ip.is_loopback()
        || ip.is_multicast()
        || (s[0] & 0xfe00) == 0xfc00                // fc00::/7 unique local
        || (s[0] & 0xffc0) == 0xfe80                // fe80::/10 link-local
        || (s[0] == 0x2001 && s[1] == 0x0db8)       // 2001:db8::/32 documentation
        || (s[0] == 0x0100 && s[1] == 0 && s[2] == 0 && s[3] == 0) // 100::/64 discard
}

/// Tunable knobs for the [`Indexer`].
#[derive(Clone, Debug)]
pub struct IndexerConfig {
    /// Local UDP endpoint for the primary node (`0.0.0.0:0` picks any port).
    pub bind: SocketAddr,
    /// DHT bootstrap routers (empty for a pure-loopback / test setup).
    pub bootstrap: Vec<(String, u16)>,
    /// Overall deadline for one metadata fetch.
    pub fetch_timeout: Duration,
    /// Concurrent in-flight fetches (the throughput ceiling).
    pub fetch_concurrency: usize,
    /// Number of DHT nodes to run (extra nodes cover more of the ID space).
    pub num_nodes: usize,
    /// Enable the Sybil "neighbour" trick on outbound crawl queries.
    pub neighbor: bool,
    /// Cap on peers attempted when DHT-resolving a sampled infohash.
    pub resolve_max_peers: usize,
    /// Wall-clock budget for one DHT-resolve fetch.
    pub resolve_budget: Duration,
    /// Dial peers on internal/reserved addresses (see [`is_internal_ip`]).
    ///
    /// **Off by default** — a DHT contact picks the peer addresses it hands back,
    /// so with this on it can aim the fetcher at the operator's own network
    /// (`127.0.0.1:22`, `10.0.0.5:3306`, `169.254.169.254:80`), 50 targets per
    /// harvested infohash, using connect-vs-timeout as a reachability oracle.
    /// Turn it on only for a loopback test rig or a deliberately internal swarm.
    pub allow_internal_peers: bool,
}

impl Default for IndexerConfig {
    fn default() -> Self {
        Self {
            bind: SocketAddr::V4(SocketAddrV4::new(Ipv4Addr::LOCALHOST, 0)),
            bootstrap: Vec::new(),
            fetch_timeout: Duration::from_secs(15),
            fetch_concurrency: 20,
            num_nodes: 1,
            neighbor: false,
            resolve_max_peers: 50,
            resolve_budget: Duration::from_secs(60),
            allow_internal_peers: false,
        }
    }
}

/// Live harvest counters.
#[derive(Debug, Default)]
pub struct IndexerStats {
    pub discovered: AtomicU64,
    pub sampled: AtomicU64,
    pub fetched: AtomicU64,
    pub failed: AtomicU64,
    /// Peers refused before connecting because their address was internal or
    /// reserved (see [`IndexerConfig::allow_internal_peers`]). A non-zero value
    /// means someone is feeding this node addresses inside your network.
    pub refused: AtomicU64,
}

/// The harvester. Cheap to [`Clone`] (all shared state is behind `Arc`s), so its
/// background loops each run on a clone.
#[derive(Clone)]
pub struct Indexer {
    store: Arc<Mutex<Store>>,
    cfg: IndexerConfig,
    nodes: Vec<DhtNode>,
    running: Arc<AtomicBool>,
    sem: Arc<Semaphore>,
    inflight: Arc<Mutex<HashSet<InfoHash>>>,
    stats: Arc<IndexerStats>,
    /// Handles for the background loops + crawler(s), so [`Indexer::stop`] can
    /// abort them (the crawler is an infinite loop with no `running` check).
    tasks: Arc<Mutex<Vec<tokio::task::JoinHandle<()>>>>,
}

/// Removes an infohash from the `inflight` set on drop — so it is cleared even if
/// the fetch task panics (a plain post-`await` statement would be skipped on
/// unwind, stranding the hash forever and growing the set without bound).
struct InflightGuard {
    set: Arc<Mutex<HashSet<InfoHash>>>,
    ih: InfoHash,
}

impl Drop for InflightGuard {
    fn drop(&mut self) {
        self.set
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner)
            .remove(&self.ih);
    }
}

impl std::fmt::Debug for Indexer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Indexer")
            .field("nodes", &self.nodes.len())
            .field("running", &self.running.load(Ordering::Relaxed))
            .finish_non_exhaustive()
    }
}

impl Indexer {
    /// A harvester over `store` with the given config. Call [`Indexer::start`]
    /// to bind the DHT node(s) before running.
    #[must_use]
    pub fn new(store: Arc<Mutex<Store>>, mut cfg: IndexerConfig) -> Self {
        cfg.fetch_concurrency = cfg.fetch_concurrency.max(1);
        cfg.num_nodes = cfg.num_nodes.max(1);
        cfg.resolve_max_peers = cfg.resolve_max_peers.max(1);
        let concurrency = cfg.fetch_concurrency;
        Self {
            store,
            cfg,
            nodes: Vec::new(),
            running: Arc::new(AtomicBool::new(false)),
            sem: Arc::new(Semaphore::new(concurrency)),
            inflight: Arc::new(Mutex::new(HashSet::new())),
            stats: Arc::new(IndexerStats::default()),
            tasks: Arc::new(Mutex::new(Vec::new())),
        }
    }

    /// The live harvest counters.
    #[must_use]
    pub fn stats(&self) -> &Arc<IndexerStats> {
        &self.stats
    }

    /// The primary node's bound address (after [`Indexer::start`]).
    pub fn local_addr(&self) -> Option<SocketAddr> {
        self.nodes.first().and_then(|n| n.local_addr().ok())
    }

    /// Bind the DHT node(s), wire the harvest sink to the store, and warm the
    /// primary routing table from persisted contacts.
    pub async fn start(&mut self) -> std::io::Result<()> {
        let store = self.store.clone();
        let stats = self.stats.clone();
        let sink: InfohashSink = Arc::new(move |ih: &InfoHash, peer: Option<SocketAddrV4>| {
            let peer = peer.map(|a| (a.ip().to_string(), a.port()));
            // This runs synchronously inside the DHT receive task; a panic would
            // unwind it and silently stop that node's inbound harvest. `add_discovered`
            // itself can't panic, and the lock is taken poison-tolerantly so a panic
            // elsewhere while holding the store lock can't cascade to kill harvesting.
            store
                .lock()
                .unwrap_or_else(std::sync::PoisonError::into_inner)
                .add_discovered(ih, peer, now_secs());
            stats.discovered.fetch_add(1, Ordering::Relaxed);
        });

        let mut nodes = Vec::with_capacity(self.cfg.num_nodes.max(1));
        for i in 0..self.cfg.num_nodes.max(1) {
            let bind = if i == 0 {
                self.cfg.bind
            } else {
                SocketAddr::V4(SocketAddrV4::new(Ipv4Addr::LOCALHOST, 0))
            };
            let node = DhtNode::bind(DhtConfig {
                bind,
                on_infohash: Some(sink.clone()),
                neighbor: self.cfg.neighbor,
                ..DhtConfig::default()
            })
            .await?;
            nodes.push(node);
        }
        // Warm the primary routing table from persisted contacts.
        let saved = self.store.lock().unwrap().load_nodes(500);
        for (id, host, port) in saved {
            if let Ok(ip) = host.parse::<Ipv4Addr>() {
                nodes[0].add_contact(Node::new(id, SocketAddrV4::new(ip, port)));
            }
        }
        self.nodes = nodes;
        self.running.store(true, Ordering::Relaxed);
        Ok(())
    }

    /// May we open a TCP connection to `host`? Every dialled address comes from a
    /// remote DHT contact, so this is the only thing standing between the fetcher
    /// and the operator's internal network (see [`is_internal_ip`]).
    fn dialable(&self, host: &str) -> bool {
        if self.cfg.allow_internal_peers {
            return true;
        }
        match host.parse::<IpAddr>() {
            Ok(ip) => !is_internal_ip(ip),
            // Peers reach us as compact 6-byte endpoints, so a host that is not a
            // literal address never comes from the DHT; and a name we would have to
            // resolve cannot be vetted here anyway (what it resolves to can change
            // between the check and the connect). Refuse it.
            Err(_) => false,
        }
    }

    /// Fetch, verify and persist metadata from one peer. Returns success. On any
    /// failure the infohash's attempt counter is bumped (so it is retried, then
    /// eventually pruned). This is the unit under test in the loopback path.
    ///
    /// Refuses internal/reserved peer addresses before connecting unless
    /// [`IndexerConfig::allow_internal_peers`] is set.
    pub async fn fetch_and_store(&self, infohash: InfoHash, host: &str, port: u16) -> bool {
        if !self.dialable(host) {
            // Counted, not silently dropped: a rising `refused` is a DHT contact
            // aiming this node at 127.0.0.1 / 10.0.0.0 / 169.254.169.254.
            self.stats.refused.fetch_add(1, Ordering::Relaxed);
            self.store.lock().unwrap().mark_attempt(&infohash);
            return false;
        }
        match fetch_metadata(&infohash, host, port, self.cfg.fetch_timeout, None, None).await {
            Ok(meta) => {
                let mut store = self.store.lock().unwrap();
                store.store_metadata(&meta, now_secs());
                store.mark_fetched(&infohash);
                self.stats.fetched.fetch_add(1, Ordering::Relaxed);
                true
            }
            Err(_) => {
                self.store.lock().unwrap().mark_attempt(&infohash);
                self.stats.failed.fetch_add(1, Ordering::Relaxed);
                false
            }
        }
    }

    /// Use the DHT to locate peers for a harvested infohash (no known peer), then
    /// fetch. Bounded by `resolve_max_peers` and `resolve_budget` so one hostile
    /// contact returning thousands of dead peers can't pin a pool slot for hours.
    pub async fn resolve_and_fetch(&self, infohash: InfoHash) -> bool {
        self.store.lock().unwrap().mark_attempt(&infohash);
        let Some(node) = self.nodes.first() else {
            return false;
        };
        let max = self.cfg.resolve_max_peers;
        let outcome = tokio::time::timeout(self.cfg.resolve_budget, async {
            let peers = node.lookup_peers(&infohash, 3).await;
            for p in peers.into_iter().take(max) {
                if self
                    .fetch_and_store(infohash, &p.ip().to_string(), p.port())
                    .await
                {
                    return true;
                }
            }
            false
        })
        .await;
        outcome.unwrap_or(false)
    }

    /// One BEP-51 sampling pass across the routing table; queues new infohashes.
    /// Returns the number newly added to the discovery queue.
    pub async fn sample_once(&self) -> usize {
        let Some(node) = self.nodes.first() else {
            return 0;
        };
        let mut queued = 0;
        let contacts = node.closest_contacts(&random_node_id(), node.k());
        for c in contacts {
            let target = random_node_id();
            let Ok(out) = node
                .sample_infohashes(&target, SocketAddr::V4(c.addr))
                .await
            else {
                continue;
            };
            for ih in out.samples {
                self.stats.sampled.fetch_add(1, Ordering::Relaxed);
                if self
                    .store
                    .lock()
                    .unwrap()
                    .add_discovered(&ih, None, now_secs())
                {
                    queued += 1;
                }
            }
        }
        queued
    }

    /// Persist every node's routing contacts to the store (warm-restart state).
    pub fn persist_nodes(&self) {
        let mut contacts: Vec<(InfoHash, String, u16)> = Vec::new();
        for node in &self.nodes {
            for n in node.contacts() {
                contacts.push((n.id, n.addr.ip().to_string(), n.addr.port()));
            }
        }
        if !contacts.is_empty() {
            self.store.lock().unwrap().save_nodes(&contacts);
        }
    }

    async fn fetch_one(&self, infohash: InfoHash, peer: Option<(String, u16)>) {
        match peer {
            Some((host, port)) if !host.is_empty() && port != 0 => {
                self.fetch_and_store(infohash, &host, port).await;
            }
            _ => {
                self.resolve_and_fetch(infohash).await;
            }
        }
    }

    /// Schedule pending fetches, bounded by `fetch_concurrency` slots. Each fetch
    /// runs on its own task with its own timeout + exception containment.
    async fn fetch_dispatcher(self) {
        while self.running.load(Ordering::Relaxed) {
            let pending = self
                .store
                .lock()
                .unwrap()
                .pending_infohashes(self.cfg.fetch_concurrency * 4, 5);
            let mut scheduled = 0;
            for (ih, peer) in pending {
                if !self.running.load(Ordering::Relaxed) {
                    break;
                }
                if self.inflight.lock().unwrap().contains(&ih) {
                    continue;
                }
                let Ok(permit) = self.sem.clone().acquire_owned().await else {
                    break;
                };
                if !self.running.load(Ordering::Relaxed) {
                    break;
                }
                self.inflight.lock().unwrap().insert(ih);
                let me = self.clone();
                let guard = InflightGuard {
                    set: self.inflight.clone(),
                    ih,
                };
                tokio::spawn(async move {
                    // `_guard` clears `inflight` and `_permit` releases the slot on
                    // completion OR panic (both drop during unwind).
                    let _guard = guard;
                    let _permit = permit;
                    me.fetch_one(ih, peer).await;
                });
                scheduled += 1;
            }
            let idle = if scheduled > 0 {
                Duration::from_millis(200)
            } else {
                Duration::from_secs(1)
            };
            tokio::time::sleep(idle).await;
        }
    }

    async fn sampler(self, interval: Duration) {
        while self.running.load(Ordering::Relaxed) {
            tokio::time::sleep(interval).await;
            if self.running.load(Ordering::Relaxed) {
                self.sample_once().await;
            }
        }
    }

    async fn maintenance(
        self,
        interval: Duration,
        max_torrents: Option<usize>,
        max_age: Option<u64>,
    ) {
        while self.running.load(Ordering::Relaxed) {
            tokio::time::sleep(interval).await;
            let mut store = self.store.lock().unwrap();
            store.prune_discovered(5, now_secs());
            if max_torrents.is_some() || max_age.is_some() {
                store.enforce_retention(max_torrents, max_age, now_secs());
            }
        }
    }

    async fn node_saver(self, interval: Duration) {
        while self.running.load(Ordering::Relaxed) {
            tokio::time::sleep(interval).await;
            self.persist_nodes();
        }
    }

    /// Start the crawler(s) + fetch pool + sampler + maintenance + node saver.
    /// Spawns background tasks and returns immediately; call [`Indexer::stop`] to
    /// wind them down. Binds the node(s) first if [`Indexer::start`] wasn't called.
    pub async fn run(
        &mut self,
        crawl_interval: Duration,
        sample_interval: Duration,
        maintenance_interval: Duration,
        max_torrents: Option<usize>,
        max_age: Option<u64>,
    ) -> std::io::Result<()> {
        if self.nodes.is_empty() {
            self.start().await?;
        }
        let mut handles = Vec::new();
        for node in &self.nodes {
            let n = node.clone();
            let boot = self.cfg.bootstrap.clone();
            // The crawler is an infinite loop with no `running` check, so its
            // handle MUST be kept and aborted in `stop()` — otherwise it (and the
            // node it clones) would keep crawling and harvesting after shutdown.
            handles.push(tokio::spawn(async move {
                n.run_crawler(crawl_interval, boot).await
            }));
        }
        handles.push(tokio::spawn(self.clone().fetch_dispatcher()));
        handles.push(tokio::spawn(self.clone().sampler(sample_interval)));
        handles.push(tokio::spawn(self.clone().maintenance(
            maintenance_interval,
            max_torrents,
            max_age,
        )));
        handles.push(tokio::spawn(
            self.clone().node_saver(Duration::from_secs(30)),
        ));
        self.tasks.lock().unwrap().extend(handles);
        Ok(())
    }

    /// Signal every background loop to stop, abort the crawler(s), and persist the
    /// routing tables. The `running`-gated loops exit on their own; the crawler
    /// (no `running` check) is aborted here so harvesting actually stops.
    pub fn stop(&self) {
        self.running.store(false, Ordering::Relaxed);
        for h in self.tasks.lock().unwrap().drain(..) {
            h.abort();
        }
        self.persist_nodes();
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bencode::{encode, Ben};
    use crate::infohash::sha1;
    use crate::metadata::serve_metadata;

    fn store() -> Arc<Mutex<Store>> {
        Arc::new(Mutex::new(Store::new()))
    }

    /// The loopback test rig dials 127.0.0.1, which the SSRF gate refuses by
    /// default — these tests opt in explicitly.
    fn loopback_cfg() -> IndexerConfig {
        IndexerConfig {
            allow_internal_peers: true,
            ..IndexerConfig::default()
        }
    }

    fn hex(b: &[u8]) -> String {
        b.iter().map(|x| format!("{x:02x}")).collect()
    }

    fn make_info(name: &str) -> Vec<u8> {
        let mut m = std::collections::BTreeMap::new();
        m.insert(b"length".to_vec(), Ben::Int(700 * 1024 * 1024));
        m.insert(b"name".to_vec(), Ben::Bytes(name.as_bytes().to_vec()));
        m.insert(b"piece length".to_vec(), Ben::Int(262_144));
        m.insert(b"pieces".to_vec(), Ben::Bytes(vec![0xABu8; 20 * 3]));
        encode(&Ben::Dict(m))
    }

    #[tokio::test]
    async fn harvest_queues_infohash() {
        let st = store();
        let mut indexer = Indexer::new(st.clone(), IndexerConfig::default());
        indexer.start().await.unwrap();
        let target = indexer.local_addr().unwrap();

        // A separate node sends a get_peers to the indexer's node.
        let sender = DhtNode::bind(DhtConfig::default()).await.unwrap();
        let ih = sha1(b"harvest-me");
        let _ = sender.get_peers(&ih, target).await;

        let pending = st.lock().unwrap().pending_infohashes(50, 5);
        assert!(pending.iter().any(|(h, _)| *h == ih), "infohash harvested");
        assert_eq!(indexer.stats().discovered.load(Ordering::Relaxed), 1);
        indexer.stop();
    }

    #[tokio::test]
    async fn fetch_and_store_from_peer() {
        let metadata = make_info("Indexed Loopback Release");
        let ih = sha1(&metadata);
        let st = store();
        st.lock().unwrap().add_discovered(&ih, None, 1000);

        let mut indexer = Indexer::new(st.clone(), loopback_cfg());
        indexer.start().await.unwrap();
        let (addr, handle) = serve_metadata(metadata.clone(), false).await.unwrap();

        let ok = indexer
            .fetch_and_store(ih, &addr.ip().to_string(), addr.port())
            .await;
        assert!(ok, "fetch_and_store succeeds");

        let store = st.lock().unwrap();
        let rec = store.get(&hex(&ih)).expect("stored");
        assert_eq!(rec.name, "Indexed Loopback Release");
        assert_eq!(store.info_bytes(&hex(&ih)), Some(metadata.as_slice()));
        assert_eq!(indexer.stats().fetched.load(Ordering::Relaxed), 1);
        drop(store);
        handle.abort();
        indexer.stop();
    }

    #[tokio::test]
    async fn fetch_and_store_marks_attempt_on_failure() {
        let st = store();
        let ih = [0x11u8; 20];
        st.lock().unwrap().add_discovered(&ih, None, 1000);
        let mut indexer = Indexer::new(st.clone(), loopback_cfg());
        indexer.cfg.fetch_timeout = Duration::from_millis(200);
        indexer.start().await.unwrap();
        // Nothing listening → the fetch fails fast and the attempt is recorded.
        let ok = indexer.fetch_and_store(ih, "127.0.0.1", 1).await;
        assert!(!ok);
        assert_eq!(indexer.stats().failed.load(Ordering::Relaxed), 1);
        indexer.stop();
    }

    /// Regression (SSRF / internal port scan): peer addresses come from a remote
    /// DHT contact's `values` list, which `decode_endpoint` accepts as any four
    /// bytes, so `fetch_and_store` must refuse internal/reserved addresses before
    /// connecting. Before the fix a `get_peers` answer of `127.0.0.1:22`,
    /// `10.0.0.5:3306`, `169.254.169.254:80` got a real 68-byte BitTorrent
    /// handshake delivered to each — here, to a loopback-only listener.
    #[tokio::test]
    async fn refuses_internal_peer_addresses() {
        // The exact addresses from the repro's `values` list, plus a public one.
        for ip in [
            "127.0.0.1",
            "10.0.0.5",
            "169.254.169.254",
            "192.168.1.1",
            "172.16.0.1",
            "100.64.0.1",
            "0.0.0.0",
            "255.255.255.255",
            "224.0.0.1",
            "::1",
            "::ffff:127.0.0.1",
            "fd00::1",
            "fe80::1",
        ] {
            assert!(
                is_internal_ip(ip.parse().unwrap()),
                "{ip} must never be dialled"
            );
        }
        for ip in ["8.8.8.8", "1.2.3.4", "2606:4700::1111"] {
            assert!(!is_internal_ip(ip.parse().unwrap()), "{ip} is public");
        }

        // A *working* loopback peer: the only reason the fetch can fail is the gate.
        let metadata = make_info("Internal Target");
        let ih = sha1(&metadata);
        let st = store();
        st.lock().unwrap().add_discovered(&ih, None, 1000);
        let (addr, handle) = serve_metadata(metadata.clone(), false).await.unwrap();

        let mut indexer = Indexer::new(st.clone(), IndexerConfig::default());
        indexer.start().await.unwrap();
        let ok = indexer
            .fetch_and_store(ih, &addr.ip().to_string(), addr.port())
            .await;
        assert!(!ok, "a loopback peer must be refused by default");
        assert_eq!(indexer.stats().refused.load(Ordering::Relaxed), 1);
        assert_eq!(indexer.stats().fetched.load(Ordering::Relaxed), 0);
        assert!(st.lock().unwrap().get(&hex(&ih)).is_none());
        // A hostname cannot be vetted (it can rebind after the check) → refused.
        assert!(!indexer.fetch_and_store(ih, "localhost", addr.port()).await);
        assert_eq!(indexer.stats().refused.load(Ordering::Relaxed), 2);
        indexer.stop();

        // Opting in dials the very same peer successfully — so the refusal above
        // was the address gate, not a broken test rig.
        let mut opted_in = Indexer::new(st.clone(), loopback_cfg());
        opted_in.start().await.unwrap();
        assert!(
            opted_in
                .fetch_and_store(ih, &addr.ip().to_string(), addr.port())
                .await
        );
        assert_eq!(opted_in.stats().refused.load(Ordering::Relaxed), 0);
        assert!(st.lock().unwrap().get(&hex(&ih)).is_some());
        handle.abort();
        opted_in.stop();
    }
}
