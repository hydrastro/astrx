//! The crawl frontier — a dependency-free port of the Python `websearch.frontier`
//! (which is SQLite-backed).
//!
//! One entry per discovered URL (`queued` / `leased` / `done` / `error` /
//! `skipped`), a per-host politeness row (`next_time`, `crawl_delay`, a fetch
//! counter), and a small key/value `meta` store (the robots.txt cache). Leasing
//! is deterministic: the shallowest queued URL (ties broken by insertion order)
//! whose host is politeness-ready — and, if a `host_budget` is set, under budget —
//! is handed out and marked `leased` until `now + lease_seconds`. [`reclaim`]
//! returns expired leases to the queue on restart, so a resumed crawl never
//! refetches a completed URL.
//!
//! [`Frontier::reclaim`]: Frontier::reclaim
//!
//! The SQLite `ORDER BY depth, added_at` is reproduced with a monotonic insertion
//! counter (real-time `added_at` is monotonic with insertion), and every method
//! that the Python takes a `now` for takes one here — so the whole thing is
//! deterministic and cross-checked byte-identical in `tests/xcheck_frontier.rs`.

use std::collections::{BTreeMap, HashMap};

#[derive(Clone, Debug)]
struct Entry {
    host: String,
    depth: i64,
    status: String,
    lease_until: f64,
    added_at: f64,
    tries: i64,
    reason: Option<String>,
}

#[derive(Clone, Debug, Default)]
struct HostState {
    next_time: f64,
    crawl_delay: Option<f64>,
    robots_done: bool,
    fetched: u64,
}

/// A leased URL handed out by [`Frontier::lease`].
#[derive(Clone, Debug, PartialEq)]
pub struct Lease {
    /// The URL to fetch.
    pub url: String,
    /// Its host.
    pub host: String,
    /// Its crawl depth.
    pub depth: i64,
}

/// A per-host politeness row (see [`Frontier::host_row`]).
#[derive(Clone, Debug, PartialEq)]
pub struct HostRow {
    /// The host.
    pub host: String,
    /// Earliest time the host may be fetched again.
    pub next_time: f64,
    /// The robots `Crawl-delay`, if known.
    pub crawl_delay: Option<f64>,
    /// Whether robots.txt has been fetched for this host.
    pub robots_done: bool,
    /// How many URLs have been fetched from this host.
    pub fetched: u64,
}

/// A resumable, leased crawl frontier with per-host politeness.
#[derive(Default)]
pub struct Frontier {
    entries: HashMap<String, Entry>,
    seq: u64,
    hosts: HashMap<String, HostState>,
    meta: HashMap<String, String>,
}

impl Frontier {
    /// A fresh, empty frontier.
    #[must_use]
    pub fn new() -> Self {
        Frontier::default()
    }

    // ---- queueing ---------------------------------------------------------

    /// Add a URL if not already known. Returns `true` if it was newly queued.
    pub fn add(&mut self, url: &str, host: &str, depth: i64) -> bool {
        if self.entries.contains_key(url) {
            return false;
        }
        let added_at = self.seq as f64;
        self.seq += 1;
        self.entries.insert(
            url.to_string(),
            Entry {
                host: host.to_string(),
                depth,
                status: "queued".to_string(),
                lease_until: 0.0,
                added_at,
                tries: 0,
                reason: None,
            },
        );
        true
    }

    /// Add many `(url, host, depth)` triples; returns how many were newly queued.
    pub fn add_many(&mut self, triples: &[(String, String, i64)]) -> usize {
        let mut added = 0;
        for (url, host, depth) in triples {
            if self.add(url, host, *depth) {
                added += 1;
            }
        }
        added
    }

    /// True if `url` is already known to the frontier.
    #[must_use]
    pub fn seen(&self, url: &str) -> bool {
        self.entries.contains_key(url)
    }

    // ---- host politeness --------------------------------------------------

    /// Ensure a host row exists (defaults: `next_time=0`, not-done, `fetched=0`).
    pub fn ensure_host(&mut self, host: &str) {
        self.hosts.entry(host.to_string()).or_default();
    }

    /// The politeness row for `host` (created on demand).
    pub fn host_row(&mut self, host: &str) -> HostRow {
        self.ensure_host(host);
        let h = &self.hosts[host];
        HostRow {
            host: host.to_string(),
            next_time: h.next_time,
            crawl_delay: h.crawl_delay,
            robots_done: h.robots_done,
            fetched: h.fetched,
        }
    }

    /// Set a host's robots `Crawl-delay` (marks robots done).
    pub fn set_crawl_delay(&mut self, host: &str, delay: Option<f64>) {
        let h = self.hosts.entry(host.to_string()).or_default();
        h.crawl_delay = delay;
        h.robots_done = true;
    }

    /// Mark that robots.txt has been fetched for `host`.
    pub fn mark_robots_done(&mut self, host: &str) {
        self.hosts.entry(host.to_string()).or_default().robots_done = true;
    }

    /// Record a fetch of `host`: hold it until `next_time` and bump the counter.
    pub fn note_fetch(&mut self, host: &str, next_time: f64) {
        let h = self.hosts.entry(host.to_string()).or_default();
        h.next_time = next_time;
        h.fetched += 1;
    }

    /// Reserve `host` until `next_time` **without** bumping the fetch counter.
    pub fn reserve_host(&mut self, host: &str, next_time: f64) {
        self.hosts.entry(host.to_string()).or_default().next_time = next_time;
    }

    // ---- leasing / completion --------------------------------------------

    /// Return expired leases (`lease_until < now`) to the queue.
    pub fn reclaim(&mut self, now: f64) {
        for e in self.entries.values_mut() {
            if e.status == "leased" && e.lease_until < now {
                e.status = "queued".to_string();
            }
        }
    }

    /// Atomically lease the next fetchable URL: the shallowest queued URL (ties
    /// broken by insertion order) whose host is politeness-ready (`next_time <=
    /// now`) and, if `host_budget` is set, under budget. A queued URL whose host
    /// has no politeness row yet is leasable too (the row is created on demand),
    /// mirroring the Python `LEFT JOIN` fallback. Returns `None` if nothing is
    /// currently leasable.
    pub fn lease(
        &mut self,
        now: f64,
        lease_seconds: f64,
        host_budget: Option<u64>,
    ) -> Option<Lease> {
        // Main path: hosts present in the politeness table, ready + under budget.
        let mut best: Option<(String, i64, f64)> = None;
        for (url, e) in &self.entries {
            if e.status != "queued" {
                continue;
            }
            if let Some(h) = self.hosts.get(&e.host) {
                let ready = h.next_time <= now;
                let under = host_budget.map_or(true, |b| h.fetched < b);
                if ready && under {
                    consider(&mut best, url, e.depth, e.added_at);
                }
            }
        }
        // Fallback: queued URLs whose host is not yet in the politeness table.
        if best.is_none() {
            let mut miss: Option<(String, i64, f64)> = None;
            for (url, e) in &self.entries {
                if e.status == "queued" && !self.hosts.contains_key(&e.host) {
                    consider(&mut miss, url, e.depth, e.added_at);
                }
            }
            if let Some((url, _, _)) = &miss {
                let host = self.entries[url].host.clone();
                self.hosts.entry(host).or_default();
                best = miss;
            }
        }
        let (url, _, _) = best?;
        let e = self.entries.get_mut(&url).expect("leased url exists");
        e.status = "leased".to_string();
        e.lease_until = now + lease_seconds;
        e.tries += 1;
        Some(Lease {
            url,
            host: e.host.clone(),
            depth: e.depth,
        })
    }

    /// Mark `url` complete with a terminal `status` (`done` / `error` / `skipped`)
    /// and an optional `reason`. A no-op if the URL is unknown.
    pub fn complete(&mut self, url: &str, status: &str, reason: Option<&str>) {
        if let Some(e) = self.entries.get_mut(url) {
            e.status = status.to_string();
            e.reason = reason.map(str::to_string);
        }
    }

    // ---- introspection ----------------------------------------------------

    /// The earliest `next_time` among queued URLs whose host has a politeness row
    /// (optionally under `host_budget`), or `None` if there are none. Mirrors the
    /// Python `INNER JOIN` (a queued URL with no host row does not count here).
    #[must_use]
    pub fn next_ready_time(&self, host_budget: Option<u64>) -> Option<f64> {
        let mut min: Option<f64> = None;
        for e in self.entries.values() {
            if e.status != "queued" {
                continue;
            }
            if let Some(h) = self.hosts.get(&e.host) {
                if host_budget.map_or(true, |b| h.fetched < b) {
                    min = Some(min.map_or(h.next_time, |m: f64| m.min(h.next_time)));
                }
            }
        }
        min
    }

    /// A map of `status` → count over all frontier entries.
    #[must_use]
    pub fn counts(&self) -> BTreeMap<String, usize> {
        let mut m: BTreeMap<String, usize> = BTreeMap::new();
        for e in self.entries.values() {
            *m.entry(e.status.clone()).or_insert(0) += 1;
        }
        m
    }

    /// True if any URL is still queued.
    #[must_use]
    pub fn has_queued(&self) -> bool {
        self.entries.values().any(|e| e.status == "queued")
    }

    /// How many URLs are `done` or `error`.
    #[must_use]
    pub fn total_done(&self) -> u64 {
        self.entries
            .values()
            .filter(|e| e.status == "done" || e.status == "error")
            .count() as u64
    }

    // ---- robots cache -----------------------------------------------------

    /// Read a value from the small `meta` store (e.g. a cached robots.txt).
    #[must_use]
    pub fn cache_get(&self, key: &str) -> Option<String> {
        self.meta.get(key).cloned()
    }

    /// Write a value into the small `meta` store.
    pub fn cache_set(&mut self, key: &str, value: &str) {
        self.meta.insert(key.to_string(), value.to_string());
    }
}

/// Keep the best `(url, depth, added_at)` by `(depth, added_at)` ascending.
fn consider(best: &mut Option<(String, i64, f64)>, url: &str, depth: i64, added_at: f64) {
    let better = match best {
        None => true,
        Some((_, bd, ba)) => depth < *bd || (depth == *bd && added_at < *ba),
    };
    if better {
        *best = Some((url.to_string(), depth, added_at));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn lease_order_depth_then_insertion() {
        let mut f = Frontier::new();
        assert!(f.add("http://a/1", "a", 0));
        assert!(f.add("http://a/2", "a", 1));
        assert!(f.add("http://b/1", "b", 0));
        assert!(!f.add("http://a/1", "a", 0)); // dup

        // First lease: depth 0, earliest added → a/1 (host created via fallback).
        let l1 = f.lease(1000.0, 120.0, None).unwrap();
        assert_eq!(l1.url, "http://a/1");
        f.note_fetch("a", 1100.0); // hold host a

        // a is held; b has no host row → fallback → b/1.
        let l2 = f.lease(1000.0, 120.0, None).unwrap();
        assert_eq!(l2.url, "http://b/1");
        f.note_fetch("b", 1100.0);

        // Both hosts held → nothing leasable now.
        assert!(f.lease(1000.0, 120.0, None).is_none());
        assert_eq!(f.next_ready_time(None), Some(1100.0));

        // Later: a ready → a/2 (depth 1).
        let l3 = f.lease(1200.0, 120.0, None).unwrap();
        assert_eq!(l3.url, "http://a/2");
    }

    #[test]
    fn reclaim_and_complete() {
        let mut f = Frontier::new();
        f.add("http://a/1", "a", 0);
        let l = f.lease(10.0, 5.0, None).unwrap(); // lease_until = 15
        assert_eq!(l.url, "http://a/1");
        assert!(!f.has_queued());
        f.reclaim(20.0); // 15 < 20 → back to queued
        assert!(f.has_queued());
        let l2 = f.lease(30.0, 5.0, None).unwrap();
        f.complete(&l2.url, "done", None);
        assert_eq!(f.total_done(), 1);
        assert_eq!(f.counts().get("done"), Some(&1));
    }

    #[test]
    fn host_budget_gates_leasing() {
        let mut f = Frontier::new();
        f.add("http://a/1", "a", 0);
        f.add("http://a/2", "a", 0);
        f.ensure_host("a");
        f.note_fetch("a", 0.0); // fetched = 1, ready (next_time 0)
                                // budget 1: a.fetched (1) not < 1 → not leasable.
        assert!(f.lease(100.0, 5.0, Some(1)).is_none());
        // budget 2: leasable.
        assert!(f.lease(100.0, 5.0, Some(2)).is_some());
    }

    #[test]
    fn robots_cache() {
        let mut f = Frontier::new();
        assert_eq!(f.cache_get("robots:a"), None);
        f.cache_set("robots:a", "User-agent: *");
        assert_eq!(f.cache_get("robots:a").as_deref(), Some("User-agent: *"));
    }
}
