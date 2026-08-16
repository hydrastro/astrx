//! In-memory swarm peer store shared by the HTTP and UDP trackers.
//!
//! Peers are keyed by `(ip, port)` within a per-infohash swarm. A peer reporting
//! `left == 0` is a seeder (`complete`), otherwise a leecher (`incomplete`).
//! Entries expire after `peer_ttl` seconds of silence and are reaped lazily on
//! every announce/query. An optional allow/deny list (by infohash) gates which
//! swarms the tracker serves.
//!
//! Time is injected (`now`, seconds) so the store is fully deterministic and
//! unit-testable; the tracker servers pass real wall-clock seconds.
//!
//! Both the swarm count and peers-per-swarm are bounded with LRU eviction: a
//! hostile client can announce unlimited distinct infohashes (and, per infohash,
//! unlimited `(ip, port)` pairs by cycling the client-supplied port), so without
//! caps the table would grow without bound within one `peer_ttl` window.

use crate::bencode::Dict;
use crate::bencode::{decode, encode, Ben};
use std::collections::{HashMap, HashSet};
use std::net::{IpAddr, SocketAddr};

/// Hard cap on tracked swarms (LRU-evicted).
pub const MAX_SWARMS: usize = 1_000_000;
/// Hard cap on peers retained per swarm (LRU-evicted).
pub const MAX_PEERS_PER_SWARM: usize = 10_000;
/// Minimum seconds between full sweeps of every swarm (see [`PeerStore::reap`]).
///
/// One `GET /announce` calls into the store four times (announce, counts, and a
/// `get_peers` per family), and a full sweep is O(swarms): with 200 000 swarms
/// each sweep measured 4.4 ms, so an unthrottled sweep charged ~17 ms of scan to
/// every request — ~88 ms at the 1M-swarm cap, an ~11 req/s ceiling. Throttling
/// the sweep costs nothing in accuracy, because the swarm a request actually
/// touches is always reaped inline.
pub const SWEEP_INTERVAL: u64 = 30;

/// A swarm peer endpoint.
pub type Endpoint = SocketAddr;

/// Address-family filter for [`PeerStore::get_peers`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Family {
    V4,
    V6,
    Any,
}

/// A BEP-3 announce event.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Event {
    None,
    Started,
    Stopped,
    Completed,
}

/// Swarm health counts for one infohash (the BEP-48 scrape fields). Named fields,
/// not a positional triple, so the scrape-wire reorder is done explicitly by name.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct ScrapeCounts {
    /// Seeders — peers that report `left == 0`.
    pub complete: u64,
    /// Leechers — peers still downloading.
    pub incomplete: u64,
    /// Completed-download events counted for this swarm.
    pub downloaded: u64,
}

struct PeerEntry {
    left: i64,
    last_seen: u64,
    order: u64, // LRU recency (monotonic)
}

#[derive(Default)]
struct Swarm {
    peers: HashMap<Endpoint, PeerEntry>,
    downloaded: u64,
    order: u64,
}

/// The shared swarm state.
pub struct PeerStore {
    pub interval: u64,
    pub peer_ttl: u64,
    pub max_peers_per_reply: usize,
    /// Minimum seconds between full sweeps (see [`SWEEP_INTERVAL`]).
    pub sweep_interval: u64,
    max_swarms: usize,
    max_peers_per_swarm: usize,
    swarms: HashMap<[u8; 20], Swarm>,
    allow: Option<HashSet<[u8; 20]>>,
    deny: HashSet<[u8; 20]>,
    seq: u64,
    /// `now` of the last full sweep; `None` until the first one.
    last_sweep: Option<u64>,
}

impl std::fmt::Debug for PeerStore {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("PeerStore")
            .field("interval", &self.interval)
            .field("peer_ttl", &self.peer_ttl)
            .field("swarms", &self.swarms.len())
            .finish_non_exhaustive()
    }
}

fn rand_u64() -> u64 {
    let mut b = [0u8; 8];
    let _ = getrandom::getrandom(&mut b);
    u64::from_le_bytes(b)
}

fn rand_below(n: usize) -> usize {
    if n == 0 {
        0
    } else {
        (rand_u64() % n as u64) as usize
    }
}

fn shuffle<T>(v: &mut [T]) {
    for i in (1..v.len()).rev() {
        v.swap(i, rand_below(i + 1));
    }
}

/// Move `k` uniformly-random elements to the front of `v` (partial Fisher–Yates).
fn partial_sample<T>(v: &mut [T], k: usize) {
    let n = v.len();
    for i in 0..k.min(n) {
        v.swap(i, i + rand_below(n - i));
    }
}

fn family_ok(ep: &Endpoint, family: Family) -> bool {
    match family {
        Family::Any => true,
        Family::V6 => ep.is_ipv6(),
        Family::V4 => ep.is_ipv4(),
    }
}

impl PeerStore {
    /// A store with the default bounds and `peer_ttl = 2 * interval`.
    pub fn new(interval: u64) -> Self {
        Self::with_bounds(interval, interval * 2, 50, MAX_SWARMS, MAX_PEERS_PER_SWARM)
    }

    /// A store with explicit bounds: announce `interval`, `peer_ttl`, the max
    /// peers returned per reply, and the LRU caps on swarms and peers-per-swarm.
    #[must_use]
    pub fn with_bounds(
        interval: u64,
        peer_ttl: u64,
        max_peers_per_reply: usize,
        max_swarms: usize,
        max_peers_per_swarm: usize,
    ) -> Self {
        Self {
            interval,
            peer_ttl,
            max_peers_per_reply,
            sweep_interval: SWEEP_INTERVAL,
            max_swarms: max_swarms.max(1),
            max_peers_per_swarm: max_peers_per_swarm.max(1),
            swarms: HashMap::new(),
            allow: None,
            deny: HashSet::new(),
            seq: 0,
            last_sweep: None,
        }
    }

    fn next_seq(&mut self) -> u64 {
        self.seq += 1;
        self.seq
    }

    // -- policy -------------------------------------------------------------

    /// Restrict the tracker to an allowlist of infohashes (`None` = allow all).
    pub fn set_allowlist(&mut self, hashes: Option<Vec<[u8; 20]>>) {
        self.allow = hashes.map(|v| v.into_iter().collect());
    }

    /// Block a set of infohashes outright (checked before the allowlist).
    pub fn set_denylist(&mut self, hashes: Vec<[u8; 20]>) {
        self.deny = hashes.into_iter().collect();
    }

    /// Is this infohash servable under the current deny/allow policy?
    #[must_use]
    pub fn is_allowed(&self, infohash: &[u8; 20]) -> bool {
        if self.deny.contains(infohash) {
            return false;
        }
        match &self.allow {
            Some(allow) => allow.contains(infohash),
            None => true,
        }
    }

    // -- reaping ------------------------------------------------------------

    /// Full sweep: drop peers unseen for `peer_ttl`, then any swarm left with no
    /// peers.
    ///
    /// A peerless swarm goes even if it once saw `event=completed`. The old
    /// predicate kept any swarm with `downloaded > 0`, which made a fill
    /// permanent: 200 000 `GET /announce?info_hash=<random>&event=completed`
    /// requests left 200 000 immortal swarms, and every later announce then paid
    /// 4.4 ms per reap (~17 ms per HTTP request across the four reaps a single
    /// announce triggers). A scrape counter for a swarm nobody is in is not worth
    /// an unbounded, un-expirable table.
    pub fn reap(&mut self, now: u64) {
        let cutoff = now.saturating_sub(self.peer_ttl);
        self.swarms.retain(|_, sw| {
            sw.peers.retain(|_, p| p.last_seen >= cutoff);
            !sw.peers.is_empty()
        });
        self.last_sweep = Some(now);
    }

    /// Expire just one swarm's peers (O(peers in that swarm)), dropping the swarm
    /// if that empties it. This is what keeps every *answer* exact while the full
    /// sweep runs only occasionally.
    fn reap_swarm(&mut self, infohash: &[u8; 20], now: u64) {
        let cutoff = now.saturating_sub(self.peer_ttl);
        if let Some(sw) = self.swarms.get_mut(infohash) {
            sw.peers.retain(|_, p| p.last_seen >= cutoff);
            if sw.peers.is_empty() {
                self.swarms.remove(infohash);
            }
        }
    }

    /// Full sweep, but at most once per `sweep_interval` seconds — see
    /// [`SWEEP_INTERVAL`] for why every announce must not drag one behind it.
    fn sweep_if_due(&mut self, now: u64) {
        match self.last_sweep {
            Some(last) if now < last.saturating_add(self.sweep_interval) => {}
            _ => self.reap(now),
        }
    }

    // -- announce -----------------------------------------------------------

    /// Record/refresh a peer. Returns `false` if the infohash is denied.
    pub fn announce(
        &mut self,
        infohash: &[u8; 20],
        endpoint: Endpoint,
        left: i64,
        event: Event,
        now: u64,
    ) -> bool {
        if !self.is_allowed(infohash) {
            return false;
        }
        // Exact for the swarm being announced to; the rest of the table is swept
        // on a timer instead of once per call (four calls per HTTP announce).
        self.reap_swarm(infohash, now);
        self.sweep_if_due(now);
        let seq = self.next_seq();
        let cap = self.max_peers_per_swarm;

        if !self.swarms.contains_key(infohash) {
            while self.swarms.len() >= self.max_swarms {
                match self
                    .swarms
                    .iter()
                    .min_by_key(|(_, s)| s.order)
                    .map(|(k, _)| *k)
                {
                    Some(oldest) => {
                        self.swarms.remove(&oldest);
                    }
                    None => break,
                }
            }
            self.swarms.insert(*infohash, Swarm::default());
        }
        let sw = self.swarms.get_mut(infohash).expect("just inserted");
        sw.order = seq; // most-recently-active swarm

        match event {
            Event::Stopped => {
                sw.peers.remove(&endpoint);
                return true;
            }
            Event::Completed => sw.downloaded += 1,
            _ => {}
        }

        if let Some(p) = sw.peers.get_mut(&endpoint) {
            p.left = left;
            p.last_seen = now;
            p.order = seq;
        } else {
            while sw.peers.len() >= cap {
                match sw
                    .peers
                    .iter()
                    .min_by_key(|(_, p)| p.order)
                    .map(|(k, _)| *k)
                {
                    Some(oldest) => {
                        sw.peers.remove(&oldest);
                    }
                    None => break,
                }
            }
            sw.peers.insert(
                endpoint,
                PeerEntry {
                    left,
                    last_seen: now,
                    order: seq,
                },
            );
        }
        true
    }

    // -- queries ------------------------------------------------------------

    /// A randomised subset of the swarm's peers (spreads load across the swarm),
    /// filtered by `family` and excluding `exclude`.
    pub fn get_peers(
        &mut self,
        infohash: &[u8; 20],
        numwant: usize,
        exclude: Option<Endpoint>,
        family: Family,
        now: u64,
    ) -> Vec<Endpoint> {
        self.reap_swarm(infohash, now);
        self.sweep_if_due(now);
        let numwant = numwant.min(self.max_peers_per_reply);
        let Some(sw) = self.swarms.get(infohash) else {
            return Vec::new();
        };
        let mut candidates: Vec<Endpoint> = sw
            .peers
            .keys()
            .copied()
            .filter(|k| Some(*k) != exclude && family_ok(k, family))
            .collect();
        if candidates.len() > numwant {
            partial_sample(&mut candidates, numwant);
            candidates.truncate(numwant);
        } else {
            shuffle(&mut candidates);
        }
        candidates
    }

    /// Swarm health for one infohash. Returning a named struct (rather than a
    /// positional `(u64, u64, u64)`) is deliberate: the scrape wire order is
    /// `complete, downloaded, incomplete`, which is *not* this struct's field
    /// order, so callers that reshuffle for the wire now do so by name — the
    /// mislabelling that a bare triple invites cannot happen.
    pub fn counts(&mut self, infohash: &[u8; 20], now: u64) -> ScrapeCounts {
        self.reap_swarm(infohash, now);
        self.sweep_if_due(now);
        match self.swarms.get(infohash) {
            None => ScrapeCounts::default(),
            Some(sw) => {
                let complete = sw.peers.values().filter(|p| p.left == 0).count() as u64;
                let incomplete = sw.peers.len() as u64 - complete;
                ScrapeCounts {
                    complete,
                    incomplete,
                    downloaded: sw.downloaded,
                }
            }
        }
    }

    /// Alias for [`counts`](Self::counts).
    pub fn scrape(&mut self, infohash: &[u8; 20], now: u64) -> ScrapeCounts {
        self.counts(infohash, now)
    }

    /// The number of swarms (distinct infohashes) currently tracked.
    #[must_use]
    pub fn swarm_count(&self) -> usize {
        self.swarms.len()
    }

    // -- durability (bencode snapshot / restore, no external store) ----------

    /// Serialise all live swarms to bencoded bytes for restart survival. Swarms
    /// and peers are emitted in a deterministic (sorted) order.
    pub fn snapshot(&mut self, now: u64) -> Vec<u8> {
        self.reap(now);
        let mut ihs: Vec<&[u8; 20]> = self.swarms.keys().collect();
        ihs.sort_unstable();
        let mut swarms = Vec::with_capacity(ihs.len());
        for ih in ihs {
            let sw = &self.swarms[ih];
            let mut eps: Vec<&Endpoint> = sw.peers.keys().collect();
            eps.sort_unstable();
            let peers = eps
                .into_iter()
                .map(|ep| {
                    let e = &sw.peers[ep];
                    Ben::List(vec![
                        Ben::Bytes(ep.ip().to_string().into_bytes()),
                        Ben::Int(i64::from(ep.port())),
                        Ben::Int(e.left),
                        Ben::Int(now.saturating_sub(e.last_seen) as i64),
                    ])
                })
                .collect();
            let mut rec = Dict::new();
            rec.insert(b"downloaded".to_vec(), Ben::Int(sw.downloaded as i64));
            rec.insert(b"ih".to_vec(), Ben::Bytes(ih.to_vec()));
            rec.insert(b"peers".to_vec(), Ben::List(peers));
            swarms.push(Ben::Dict(rec));
        }
        let mut top = Dict::new();
        top.insert(b"swarms".to_vec(), Ben::List(swarms));
        top.insert(b"v".to_vec(), Ben::Int(1));
        encode(&Ben::Dict(top))
    }

    /// Reload swarms from [`snapshot`](Self::snapshot) output. Peers already older
    /// than `peer_ttl` are dropped. Returns the number of peers restored.
    pub fn restore(&mut self, blob: &[u8], now: u64) -> usize {
        let Ok(Ben::Dict(data)) = decode(blob) else {
            return 0;
        };
        let cap = self.max_peers_per_swarm;
        let max_swarms = self.max_swarms;
        let ttl = self.peer_ttl as i64;
        let mut order = self.seq;
        let mut restored = 0;

        if let Some(Ben::List(recs)) = data.get(b"swarms".as_slice()) {
            for rec in recs {
                let Ben::Dict(sr) = rec else { continue };
                let ih = match sr.get(b"ih".as_slice()) {
                    Some(Ben::Bytes(b)) if b.len() == 20 => {
                        let mut a = [0u8; 20];
                        a.copy_from_slice(b);
                        a
                    }
                    _ => continue,
                };
                if !self.swarms.contains_key(&ih) {
                    if self.swarms.len() >= max_swarms {
                        break;
                    }
                    self.swarms.insert(ih, Swarm::default());
                }
                let sw = self.swarms.get_mut(&ih).expect("present");
                // Give each restored swarm an increasing recency, else they all tie
                // at 0 and post-restore LRU eviction picks an arbitrary victim.
                order += 1;
                sw.order = order;
                if let Some(Ben::Int(dl)) = sr.get(b"downloaded".as_slice()) {
                    if *dl >= 0 {
                        sw.downloaded = sw.downloaded.max(*dl as u64);
                    }
                }
                if let Some(Ben::List(peers)) = sr.get(b"peers".as_slice()) {
                    for peer in peers {
                        if sw.peers.len() >= cap {
                            break;
                        }
                        let Ben::List(f) = peer else { continue };
                        if f.len() < 4 {
                            continue;
                        }
                        let ip_b = match &f[0] {
                            Ben::Bytes(b) => b,
                            _ => continue,
                        };
                        let port = match &f[1] {
                            Ben::Int(p) if (0..=65535).contains(p) => *p as u16,
                            _ => continue,
                        };
                        let left = match &f[2] {
                            Ben::Int(l) => *l,
                            _ => 0,
                        };
                        let age = match &f[3] {
                            Ben::Int(a) => *a,
                            _ => 0,
                        };
                        if age >= ttl {
                            continue;
                        }
                        let Ok(ip) = String::from_utf8_lossy(ip_b).parse::<IpAddr>() else {
                            continue;
                        };
                        order += 1;
                        sw.peers.insert(
                            SocketAddr::new(ip, port),
                            PeerEntry {
                                left,
                                last_seen: now.saturating_sub(age.max(0) as u64),
                                order,
                            },
                        );
                        restored += 1;
                    }
                }
            }
        }
        self.seq = order;
        restored
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ep(s: &str) -> Endpoint {
        s.parse().unwrap()
    }
    fn sc(complete: u64, incomplete: u64, downloaded: u64) -> ScrapeCounts {
        ScrapeCounts {
            complete,
            incomplete,
            downloaded,
        }
    }
    const IH: [u8; 20] = [0xAB; 20];
    const IH2: [u8; 20] = [0xCD; 20];

    #[test]
    fn announce_counts_seeders_and_leechers() {
        let mut ps = PeerStore::new(1800);
        assert!(ps.announce(&IH, ep("1.2.3.4:6881"), 0, Event::Started, 100)); // seeder
        assert!(ps.announce(&IH, ep("5.6.7.8:6881"), 500, Event::Started, 100)); // leecher
        assert_eq!(ps.counts(&IH, 100), sc(1, 1, 0));
        // completed bumps downloaded and makes it a seeder
        assert!(ps.announce(&IH, ep("5.6.7.8:6881"), 0, Event::Completed, 101));
        assert_eq!(ps.counts(&IH, 101), sc(2, 0, 1));
        // stopped removes the peer
        assert!(ps.announce(&IH, ep("1.2.3.4:6881"), 0, Event::Stopped, 102));
        assert_eq!(ps.counts(&IH, 102), sc(1, 0, 1));
    }

    #[test]
    fn get_peers_excludes_and_filters_family() {
        let mut ps = PeerStore::new(1800);
        ps.announce(&IH, ep("1.2.3.4:1"), 0, Event::Started, 0);
        ps.announce(&IH, ep("5.6.7.8:2"), 0, Event::Started, 0);
        ps.announce(&IH, ep("[2001:db8::1]:3"), 0, Event::Started, 0);
        // exclude self, v4 only -> the other v4 peer
        let v4 = ps.get_peers(&IH, 50, Some(ep("1.2.3.4:1")), Family::V4, 0);
        assert_eq!(v4, vec![ep("5.6.7.8:2")]);
        // v6 only
        let v6 = ps.get_peers(&IH, 50, None, Family::V6, 0);
        assert_eq!(v6, vec![ep("[2001:db8::1]:3")]);
        // any, capped at numwant
        assert_eq!(ps.get_peers(&IH, 2, None, Family::Any, 0).len(), 2);
        assert_eq!(ps.get_peers(&IH, 50, None, Family::Any, 0).len(), 3);
    }

    #[test]
    fn peers_expire_after_ttl() {
        let mut ps = PeerStore::new(100); // ttl = 200
        ps.announce(&IH, ep("1.2.3.4:1"), 0, Event::Started, 0);
        assert_eq!(ps.counts(&IH, 199), sc(1, 0, 0)); // still alive
        assert_eq!(ps.counts(&IH, 201), sc(0, 0, 0)); // reaped
        assert_eq!(ps.swarm_count(), 0); // empty swarm dropped
    }

    #[test]
    fn allow_and_deny_policy() {
        let mut ps = PeerStore::new(1800);
        ps.set_denylist(vec![IH]);
        assert!(!ps.announce(&IH, ep("1.2.3.4:1"), 0, Event::Started, 0));
        ps.set_denylist(vec![]);
        ps.set_allowlist(Some(vec![IH2]));
        assert!(!ps.announce(&IH, ep("1.2.3.4:1"), 0, Event::Started, 0)); // not allowed
        assert!(ps.announce(&IH2, ep("1.2.3.4:1"), 0, Event::Started, 0)); // allowed
    }

    #[test]
    fn per_swarm_peer_cap_evicts_oldest() {
        let mut ps = PeerStore::with_bounds(1800, 3600, 50, 100, 2);
        ps.announce(&IH, ep("1.1.1.1:1"), 0, Event::Started, 0);
        ps.announce(&IH, ep("2.2.2.2:2"), 0, Event::Started, 1);
        ps.announce(&IH, ep("3.3.3.3:3"), 0, Event::Started, 2); // evicts 1.1.1.1
        let peers = ps.get_peers(&IH, 50, None, Family::Any, 2);
        assert_eq!(peers.len(), 2);
        assert!(!peers.contains(&ep("1.1.1.1:1")));
    }

    /// Regression: a swarm with no peers left must expire even if it once counted
    /// a `completed`. The old retain predicate kept every swarm with
    /// `downloaded > 0` forever, so 200 000 `GET /announce?info_hash=<random 20B>
    /// &port=6881&event=completed` requests bought 200 000 permanent swarms — and
    /// every later announce then paid to scan them (4.4 ms per reap, four reaps
    /// per HTTP announce).
    #[test]
    fn peerless_swarm_expires_even_after_completed() {
        let mut ps = PeerStore::with_bounds(5, 10, 50, 100, 100); // peer_ttl = 10
        assert!(ps.announce(&IH, ep("1.2.3.4:6881"), 0, Event::Completed, 0));
        assert_eq!(ps.counts(&IH, 0), sc(1, 0, 1));
        // The peer goes silent; past the TTL the swarm itself must go too.
        assert_eq!(ps.counts(&IH, 20), sc(0, 0, 0));
        assert_eq!(ps.swarm_count(), 0, "an emptied swarm outlived its peers");
    }

    /// Regression: the full sweep must be amortised, not run on every call. One
    /// HTTP announce reaches the store four times (announce, counts, get_peers ×2)
    /// and a sweep is O(swarms) — 4.4 ms at 200 000 swarms, ~88 ms per request at
    /// the 1M cap (~11 req/s). The swarm a call actually touches is still exact.
    #[test]
    fn full_sweep_is_amortised_but_still_happens() {
        let mut ps = PeerStore::with_bounds(5, 10, 50, 100, 100); // peer_ttl = 10
        let ih_b = [0x01u8; 20];
        let ih_c = [0x02u8; 20];
        for ih in [&IH, &ih_b, &ih_c] {
            ps.announce(ih, ep("1.2.3.4:6881"), 0, Event::Started, 0);
        }
        assert_eq!(ps.swarm_count(), 3);
        // t=20: every peer is past the TTL, but the last sweep was at t=0 and
        // `sweep_interval` is 30 — so only the swarm actually asked about is
        // touched. Its answer is exact regardless.
        assert_eq!(ps.counts(&IH, 20), sc(0, 0, 0));
        assert_eq!(
            ps.swarm_count(),
            2,
            "every call is still dragging a full sweep behind it"
        );
        // Past `sweep_interval`, the sweep does run and clears the stragglers.
        assert_eq!(ps.counts(&IH, 40), sc(0, 0, 0));
        assert_eq!(ps.swarm_count(), 0);
    }

    #[test]
    fn snapshot_restore_round_trip() {
        let mut ps = PeerStore::new(1800);
        ps.announce(&IH, ep("1.2.3.4:6881"), 0, Event::Started, 1000);
        ps.announce(&IH, ep("5.6.7.8:51413"), 999, Event::Started, 1000);
        ps.announce(&IH2, ep("[2001:db8::9]:6969"), 0, Event::Completed, 1000);
        let blob = ps.snapshot(1000);

        let mut restored = PeerStore::new(1800);
        let n = restored.restore(&blob, 1000);
        assert_eq!(n, 3);
        assert_eq!(restored.counts(&IH, 1000), ps.counts(&IH, 1000));
        assert_eq!(restored.counts(&IH2, 1000), sc(1, 0, 1)); // downloaded preserved
                                                              // deterministic snapshot: same bytes twice
        assert_eq!(ps.snapshot(1000), restored.snapshot(1000));
    }
}
