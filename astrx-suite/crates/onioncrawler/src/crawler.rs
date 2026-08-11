//! The crawl orchestration loop — the capstone that ties the engine together:
//! lease a URL → fetch it politely over the darknet fetcher → extract → index →
//! expand the frontier with the discovered links, enforcing the trap defences at
//! every step. A dependency-free port of the core of the Python
//! `onioncrawler.crawler` (`net`-gated, since it drives the async fetcher).
//!
//! The store is shared as `Arc<Mutex<Store>>`; the loop locks it in short
//! *synchronous* bursts (lease, index, enqueue) and never across the `.await` of
//! a fetch, so a `std::sync::Mutex` is correct and workers can run concurrently.
//!
//! Ported: canonicalize + darknet gate, abuse host/content blocks, the 304 /
//! error / content-type-allowlist / X-Robots-Tag / meta-robots handling, content
//! dedup via `content_hash`, link expansion with path-traps + admission caps +
//! deduped link edges, in-body `.onion` discovery, liveness (up/down) + dead-onion
//! aging, host trap-scoring, and politeness parking. Deferred (documented):
//! robots.txt fetching/caching, conditional GET, media-hash blocking, jitter, and
//! the scheduled-reseed daemon.

use std::collections::HashSet;
use std::sync::{Arc, Mutex};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use crate::abuse::AbuseFilter;
use crate::canonical::{canonicalize, CanonicalUrl};
use crate::extract::extract_html;
use crate::fetcher::Fetcher;
use crate::onion::find_onion_urls;
use crate::store::{Caps, Enqueued, HostCounter, Store, StoreOutcome};
use crate::urlparse::parse_qsl;
use crawlcore::hash::{sha1, to_hex};
use crawlcore::traps;

fn now_secs() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

/// SHA-1 hex of the normalized (`\s+`-collapsed, lowercased, trimmed) title+text,
/// or `None` when there is no content — the exact-content dedup key, byte-for-byte
/// the Python `content_hash`.
#[must_use]
pub fn content_hash(title: &str, text: &str) -> Option<String> {
    let combined = format!("{title}\n{text}");
    let mut norm = String::with_capacity(combined.len());
    let mut prev_ws = false;
    for c in combined.trim().chars() {
        if c.is_whitespace() {
            if !prev_ws {
                norm.push(' ');
            }
            prev_ws = true;
        } else {
            norm.extend(c.to_lowercase());
            prev_ws = false;
        }
    }
    let norm = norm.trim();
    if norm.is_empty() {
        return None;
    }
    Some(to_hex(&sha1(norm.as_bytes())))
}

/// Extract the `charset=` value from a full Content-Type header, if present.
fn charset_from_ctype(ctype: &str) -> Option<String> {
    let lower = ctype.to_ascii_lowercase();
    let idx = lower.find("charset=")?;
    let rest = &ctype[idx + "charset=".len()..];
    let end = rest.find(';').unwrap_or(rest.len());
    let cs = rest[..end].trim().trim_matches(['"', '\'']).to_string();
    if cs.is_empty() {
        None
    } else {
        Some(cs)
    }
}

/// Crawl policy: depth / page budgets, trap caps, politeness and the robots-ish
/// toggles. [`CrawlConfig::default`] is a sane onion-crawl baseline.
#[derive(Clone, Debug)]
pub struct CrawlConfig {
    pub max_depth: i64,
    pub max_total_pages: Option<usize>,
    pub lease_ttl: f64,
    pub allow_v2: bool,
    pub allow_i2p: bool,
    pub obey_meta_robots: bool,
    pub obey_x_robots_tag: bool,
    /// Allowed bare content-types (empty = allow all).
    pub allowed_content_types: Vec<String>,
    pub max_links_per_page: Option<usize>,
    pub dedup_content: bool,
    pub recrawl_ttl: f64,
    pub recrawl_backoff: f64,
    pub recrawl_max_interval: f64,
    pub crawl_delay: f64,
    pub liveness_fail_threshold: i64,
    pub dead_after_down_recrawls: i64,
    pub workers: usize,
    pub max_path_segments: usize,
    pub max_segment_repeats: usize,
    pub pagination_numeric_cap: i64,
    pub discover_body_onions: bool,
    pub max_text_onions_per_page: usize,
    pub max_unique_urls: Option<i64>,
    pub max_pages_per_host: Option<i64>,
    pub max_urls_per_template: Option<i64>,
    pub max_urls_per_skeleton: Option<i64>,
    pub dup_ratio_min_samples: i64,
    pub dup_ratio_threshold: f64,
    pub error_ratio_min_samples: i64,
    pub error_ratio_threshold: f64,
}

impl Default for CrawlConfig {
    fn default() -> Self {
        CrawlConfig {
            max_depth: 6,
            max_total_pages: None,
            lease_ttl: 300.0,
            allow_v2: false,
            allow_i2p: false,
            obey_meta_robots: true,
            obey_x_robots_tag: true,
            allowed_content_types: vec!["text/html".to_string(), "text/plain".to_string()],
            max_links_per_page: Some(500),
            dedup_content: true,
            recrawl_ttl: 0.0,
            recrawl_backoff: 0.0,
            recrawl_max_interval: 0.0,
            crawl_delay: 0.0,
            liveness_fail_threshold: 3,
            dead_after_down_recrawls: 5,
            workers: 1,
            max_path_segments: 24,
            max_segment_repeats: 3,
            pagination_numeric_cap: 20,
            discover_body_onions: true,
            max_text_onions_per_page: 50,
            max_unique_urls: None,
            max_pages_per_host: None,
            max_urls_per_template: None,
            max_urls_per_skeleton: None,
            dup_ratio_min_samples: 20,
            dup_ratio_threshold: 0.9,
            error_ratio_min_samples: 20,
            error_ratio_threshold: 0.9,
        }
    }
}

impl CrawlConfig {
    fn caps(&self) -> Caps {
        Caps {
            max_unique_urls: self.max_unique_urls,
            max_pages_per_host: self.max_pages_per_host,
            max_urls_per_template: self.max_urls_per_template,
            max_urls_per_skeleton: self.max_urls_per_skeleton,
        }
    }
}

/// A snapshot of the store after a crawl run.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct RunStats {
    pub pages: i64,
    pub hosts: i64,
    pub pages_stored: i64,
    pub urls_enqueued: i64,
}

/// The crawl engine. Cheap to clone (`Arc` handles + config), so `run` can fan
/// the worker loop across tasks.
#[derive(Clone)]
pub struct Crawler {
    store: Arc<Mutex<Store>>,
    fetcher: Arc<Fetcher>,
    abuse: Option<Arc<AbuseFilter>>,
    config: CrawlConfig,
}

impl Crawler {
    /// A crawler over `store` fetching via `fetcher` under `config`.
    #[must_use]
    pub fn new(store: Arc<Mutex<Store>>, fetcher: Arc<Fetcher>, config: CrawlConfig) -> Self {
        Crawler {
            store,
            fetcher,
            abuse: None,
            config,
        }
    }

    /// Attach an abuse filter (host + content blocklists enforced on the hot path).
    #[must_use]
    pub fn with_abuse(mut self, abuse: Arc<AbuseFilter>) -> Self {
        self.abuse = Some(abuse);
        self
    }

    fn host_blocked(&self, host: &str) -> bool {
        self.abuse.as_ref().is_some_and(|a| a.host_blocked(host))
    }

    fn page_blocked(&self, host: &str, title: &str, text: &str) -> Option<String> {
        self.abuse
            .as_ref()
            .and_then(|a| a.page_blocked(host, title, text))
    }

    /// Enqueue seed URLs (canonicalized + darknet-gated). Returns how many were
    /// newly admitted.
    pub fn add_seeds<I: IntoIterator<Item = String>>(&self, seeds: I) -> usize {
        let now = now_secs();
        let mut added = 0;
        let mut s = self.store.lock().expect("store lock");
        for raw in seeds {
            if let Some(cu) = canonicalize(&raw, None, self.config.allow_v2, self.config.allow_i2p)
            {
                s.ensure_host(&cu.host, now);
                if s.add_seed(&cu, 0, 0, Caps::default(), now, true) == Enqueued::Ok {
                    added += 1;
                }
            }
        }
        added
    }

    /// Run the crawl until the frontier drains (or a page cap is hit), then
    /// return the store snapshot. Startup reclaims dead leases, ages dead onions,
    /// and requeues due recrawls; shutdown returns in-flight leases to the queue.
    pub async fn run(&self) -> RunStats {
        {
            let now = now_secs();
            let mut s = self.store.lock().expect("store lock");
            s.reclaim_expired(now);
            s.age_dead_hosts(self.config.dead_after_down_recrawls, now);
            if self.config.recrawl_ttl > 0.0 {
                s.mark_recrawl_due(now, self.config.recrawl_ttl);
            }
        }

        let workers = self.config.workers.max(1);
        if workers == 1 {
            self.worker().await;
        } else {
            let mut handles = Vec::with_capacity(workers);
            for _ in 0..workers {
                let me = self.clone();
                handles.push(tokio::spawn(async move { me.worker().await }));
            }
            for h in handles {
                let _ = h.await;
            }
        }

        let mut s = self.store.lock().expect("store lock");
        s.reclaim_all_leased();
        let m = s.metrics();
        let g = |k: &str| *m.get(k).unwrap_or(&0);
        RunStats {
            pages: g("pages"),
            hosts: g("hosts"),
            pages_stored: g("pages_stored"),
            urls_enqueued: g("urls_enqueued"),
        }
    }

    async fn worker(&self) {
        loop {
            let lease = {
                let mut s = self.store.lock().expect("store lock");
                if let Some(cap) = self.config.max_total_pages {
                    if s.counter("pages_stored") >= cap as i64 {
                        break;
                    }
                }
                s.lease(now_secs(), self.config.lease_ttl)
            };
            let Some(lease) = lease else {
                let (queued, leased_active) = {
                    let s = self.store.lock().expect("store lock");
                    s.pending_summary(now_secs())
                };
                if queued == 0 && leased_active == 0 {
                    break; // frontier drained → done
                }
                // another worker holds a lease that may refill the frontier
                tokio::time::sleep(Duration::from_millis(10)).await;
                continue;
            };
            self.process(&lease).await;
        }
    }

    async fn process(&self, lease: &crate::store::Lease) {
        let fid = lease.id;
        let url = &lease.url;
        let host = &lease.host;
        let depth = lease.depth;

        let Some(cu) = canonicalize(url, None, self.config.allow_v2, self.config.allow_i2p) else {
            self.store
                .lock()
                .expect("lock")
                .mark_error(fid, "uncanonicalizable");
            return;
        };

        if self.host_blocked(host) {
            let mut s = self.store.lock().expect("lock");
            s.set_host_state(host, "blocked", Some("abuse-host"));
            s.mark_error(fid, "blocked-host");
            s.log_trap(host, url, "blocked-host", now_secs());
            return;
        }

        // fetch (no lock held across the await)
        let result = self.fetcher.fetch(&cu.url).await;
        {
            let mut s = self.store.lock().expect("lock");
            s.host_counter_bump(host, HostCounter::Fetch, 1, now_secs());
        }

        // 304 Not Modified: unchanged, bump last-seen + back off, no re-index.
        if result.status == 304 {
            let mut s = self.store.lock().expect("lock");
            s.touch_page(
                &cu.url,
                now_secs(),
                self.config.recrawl_backoff,
                self.config.recrawl_max_interval,
                self.config.recrawl_ttl,
            );
            s.record_fetch_up(host, now_secs());
            s.mark_done(fid);
            self.park(&mut s, host);
            return;
        }

        if !result.ok {
            {
                let mut s = self.store.lock().expect("lock");
                s.host_counter_bump(host, HostCounter::Error, 1, now_secs());
                // an HTTP response (even 4xx/5xx) means the onion is up; only a
                // transport failure (status 0) counts toward the dead-onion path.
                if result.status >= 100 {
                    s.record_fetch_up(host, now_secs());
                } else {
                    s.record_fetch_down(host, now_secs(), self.config.liveness_fail_threshold);
                }
                let err = result
                    .error
                    .clone()
                    .unwrap_or_else(|| format!("http-{}", result.status));
                s.mark_error(fid, &err);
            }
            self.score_host(host);
            let mut s = self.store.lock().expect("lock");
            self.park(&mut s, host);
            return;
        }

        // a real 2xx: the host is alive
        {
            let mut s = self.store.lock().expect("lock");
            s.record_fetch_up(host, now_secs());
        }

        let ctype = result.content_type.clone();
        if !ctype.is_empty()
            && !self.config.allowed_content_types.is_empty()
            && !self.config.allowed_content_types.contains(&ctype)
        {
            let mut s = self.store.lock().expect("lock");
            s.mark_done(fid);
            s.log_trap(host, url, &format!("ctype:{ctype}"), now_secs());
            self.park(&mut s, host);
            return;
        }

        let mut noindex = false;
        let mut nofollow = false;
        if self.config.obey_x_robots_tag {
            let xr = result
                .headers
                .get("x-robots-tag")
                .unwrap_or("")
                .to_lowercase();
            noindex = xr.contains("noindex") || xr.contains("none");
            nofollow = xr.contains("nofollow") || xr.contains("none");
        }

        let charset = charset_from_ctype(result.headers.get("content-type").unwrap_or(""));
        let ext = extract_html(
            &result.body,
            charset.as_deref(),
            self.config.max_links_per_page,
        );
        if self.config.obey_meta_robots {
            noindex = noindex || ext.meta_noindex;
            nofollow = nofollow || ext.meta_nofollow;
        }

        if let Some(reason) = self.page_blocked(host, &ext.title, &ext.text) {
            let mut s = self.store.lock().expect("lock");
            s.mark_done(fid);
            s.log_trap(host, url, &reason, now_secs());
            self.park(&mut s, host);
            return;
        }

        if !noindex {
            let chash = content_hash(&ext.title, &ext.text);
            let outcome = {
                let mut s = self.store.lock().expect("lock");
                s.store_page(
                    &cu.url,
                    host,
                    Some(&ext.title),
                    Some(&ext.text),
                    chash.as_deref(),
                    Some(i64::from(result.status)),
                    Some(&ctype),
                    Some(result.body.len() as i64),
                    now_secs(),
                    self.config.dedup_content,
                    result.headers.get("etag"),
                    result.headers.get("last-modified"),
                    Some(self.config.recrawl_ttl),
                )
            };
            let mut s = self.store.lock().expect("lock");
            match outcome {
                StoreOutcome::Duplicate => s.log_trap(host, url, "dup-content", now_secs()),
                StoreOutcome::Unchanged => s.touch_page(
                    &cu.url,
                    now_secs(),
                    self.config.recrawl_backoff,
                    self.config.recrawl_max_interval,
                    self.config.recrawl_ttl,
                ),
                _ => {}
            }
        } else {
            self.store
                .lock()
                .expect("lock")
                .log_trap(host, url, "noindex", now_secs());
        }

        if !nofollow {
            let mut links = ext.links.clone();
            if self.config.discover_body_onions && !self.config.allow_i2p {
                links.extend(find_onion_urls(
                    &ext.text,
                    self.config.allow_v2,
                    self.config.max_text_onions_per_page,
                    "http",
                ));
            }
            self.enqueue_links(&cu, &links, depth);
        }

        {
            let mut s = self.store.lock().expect("lock");
            s.mark_done(fid);
        }
        self.score_host(host);
        let mut s = self.store.lock().expect("lock");
        self.park(&mut s, host);
    }

    /// Expand the frontier with the discovered links, enforcing depth, path
    /// traps, the admission caps (tightened for pagination bombs), and deduped
    /// inter-onion link edges. Runs under a single store lock (no `.await`).
    fn enqueue_links(&self, parent: &CanonicalUrl, links: &[String], depth: i64) {
        if depth + 1 > self.config.max_depth {
            return;
        }
        let now = now_secs();
        let base_caps = self.config.caps();
        let mut seen_edges: HashSet<(String, String)> = HashSet::new();
        let mut s = self.store.lock().expect("lock");
        for href in links {
            let Some(child) = canonicalize(
                href,
                Some(&parent.url),
                self.config.allow_v2,
                self.config.allow_i2p,
            ) else {
                continue; // non-darknet / unusable → dropped
            };
            if self.host_blocked(&child.host) {
                s.log_trap(&child.host, &child.url, "blocked-host-link", now);
                continue;
            }
            if traps::is_path_trap(
                &child.path,
                self.config.max_path_segments,
                self.config.max_segment_repeats,
            ) {
                s.log_trap(&child.host, &child.url, "path-trap", now);
                continue;
            }
            // calendar / pagination bomb → tighter template cap
            let mut child_caps = base_caps;
            let qpairs = parse_qsl(&child.query, true);
            let refs: Vec<(&str, &str)> = qpairs
                .iter()
                .map(|(k, v)| (k.as_str(), v.as_str()))
                .collect();
            if traps::looks_like_pagination(&refs) {
                let cap = match self.config.max_urls_per_template {
                    Some(m) => m.min(self.config.pagination_numeric_cap),
                    None => self.config.pagination_numeric_cap,
                };
                child_caps.max_urls_per_template = Some(cap);
            }
            let reason = s.enqueue(&child, depth + 1, 0, child_caps, now, false);
            if reason != Enqueued::Ok && reason != Enqueued::DupUrl {
                s.log_trap(&child.host, &child.url, reason.as_str(), now);
                continue;
            }
            // one edge per (parent,child) host pair per page
            if child.host != parent.host {
                let key = (parent.host.clone(), child.host.clone());
                if seen_edges.insert(key) {
                    s.add_link_edge(&parent.host, &child.host, 1);
                }
            }
        }
    }

    /// Host trap-scoring: demote a host to `trapped` once it blows the page
    /// budget, or its duplicate / error ratios cross the configured thresholds.
    fn score_host(&self, host: &str) {
        let mut s = self.store.lock().expect("lock");
        let Some(h) = s.get_host(host) else { return };
        if h.state != "active" {
            return;
        }
        let (pages, dup, err, fetch) = (h.pages_count, h.dup_count, h.error_count, h.fetch_count);
        if let Some(budget) = self.config.max_pages_per_host {
            if budget > 0 && pages >= budget {
                s.set_host_state(host, "trapped", Some("page-budget-exceeded"));
                s.log_trap(host, "", "trapped:page-budget", now_secs());
                return;
            }
        }
        let seen = dup + pages;
        if seen >= self.config.dup_ratio_min_samples
            && dup as f64 / seen.max(1) as f64 >= self.config.dup_ratio_threshold
        {
            s.set_host_state(host, "trapped", Some("duplicate-ratio"));
            s.log_trap(host, "", "trapped:dup-ratio", now_secs());
            return;
        }
        if fetch >= self.config.error_ratio_min_samples
            && err as f64 / fetch.max(1) as f64 >= self.config.error_ratio_threshold
        {
            s.set_host_state(host, "trapped", Some("error-ratio"));
            s.log_trap(host, "", "trapped:error-ratio", now_secs());
        }
    }

    /// Park a host for its crawl delay (politeness). The store's lease already
    /// serializes per host; this adds the configured inter-fetch delay.
    fn park(&self, s: &mut Store, host: &str) {
        let delay = s
            .get_host(host)
            .and_then(|h| h.crawl_delay)
            .unwrap_or(self.config.crawl_delay);
        s.set_next_allowed(host, now_secs() + delay);
    }
}

#[cfg(test)]
mod tests;
