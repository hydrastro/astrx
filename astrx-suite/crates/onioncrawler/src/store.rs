//! The onioncrawler store — the resumable frontier + page index + host graph.
//!
//! A hand-rolled, **dependency-free** in-memory store persisted as a single
//! self-describing snapshot blob (no database, no third-party crates), the same
//! approach `torrentds`'s index store takes. It ports the domain of the Python
//! `onioncrawler.storage.Storage` (a SQLite/FTS5 store) — the host state machine,
//! the leased frontier with its trap-cap admission control, the page store with
//! exact-content dedup, the entity verticals, the inter-onion link graph with
//! offline PageRank, SimHash mirror clustering, and the liveness / dead-onion
//! aging cycle — with the same return codes and invariants as the reference.
//!
//! The persistence unit is a versioned binary blob with native `i64`/`f64`
//! fields (the store's timestamps are `REAL`), round-tripped by [`Store::snapshot`]
//! / [`Store::restore`]. The deterministic analytics (PageRank authority, SimHash
//! clustering) are cross-checked byte-for-byte against the Python reference in
//! `tests/xcheck_store.rs`; the frontier / host / page semantics are covered by
//! the unit tests below.
//!
//! Full-text search over the stored bodies (the SQLite FTS5 `bm25` path) is the
//! next increment; every page's body text is retained here so it can be indexed
//! without re-crawling.

use std::collections::HashMap;

use crate::canonical::CanonicalUrl;
use crate::entities::extract as extract_entities;
use crate::lang::guess_lang;
use crate::onion::normalize_host;
use crate::simhash::{hamming, simhash64};
use crawlcore::scheduler::backoff_interval;

mod codec;
mod search;
use codec::{Reader, Writer};

pub use search::{Facets, SearchHit, SearchOpts, SearchResults};

/// Host lifecycle state. `active` hosts are the only ones the frontier will
/// lease from; `trapped` / `blocked` / `dead` are hidden and never fetched.
pub const STATE_ACTIVE: &str = "active";

/// Host states hidden from search results (defense-in-depth: `blocked` pages are
/// also never stored, `dead` hosts are demoted by aging).
pub const HIDDEN_STATES: [&str; 2] = ["blocked", "dead"];

/// One row of the `hosts` table: politeness, robots, counters, liveness and the
/// PageRank authority score for a single onion host.
#[derive(Clone, Debug, PartialEq)]
pub struct HostRow {
    pub state: String,
    pub next_allowed: f64,
    pub crawl_delay: Option<f64>,
    pub enq_count: i64,
    pub pages_count: i64,
    pub fetch_count: i64,
    pub dup_count: i64,
    pub error_count: i64,
    pub robots_body: Option<String>,
    pub robots_fetched_at: Option<f64>,
    pub robots_present: bool,
    pub sitemaps_done: bool,
    pub trapped_reason: Option<String>,
    pub first_seen: Option<f64>,
    pub last_seen: Option<f64>,
    pub consecutive_failures: i64,
    pub last_ok: Option<f64>,
    pub last_down: Option<f64>,
    pub down_recrawls: i64,
    pub up: bool,
    pub authority: f64,
}

impl HostRow {
    fn seen(now: f64) -> Self {
        HostRow {
            state: STATE_ACTIVE.to_string(),
            next_allowed: 0.0,
            crawl_delay: None,
            enq_count: 0,
            pages_count: 0,
            fetch_count: 0,
            dup_count: 0,
            error_count: 0,
            robots_body: None,
            robots_fetched_at: None,
            robots_present: false,
            sitemaps_done: false,
            trapped_reason: None,
            first_seen: Some(now),
            last_seen: Some(now),
            consecutive_failures: 0,
            last_ok: None,
            last_down: None,
            down_recrawls: 0,
            up: true,
            authority: 0.0,
        }
    }
}

/// One row of the `frontier` table: a URL awaiting (or past) a crawl, with the
/// structural trap keys and the lease bookkeeping.
#[derive(Clone, Debug, PartialEq)]
pub struct FrontierRow {
    pub id: i64,
    pub url: String,
    pub host: String,
    pub depth: i64,
    pub status: String,
    pub priority: i64,
    pub template: Option<String>,
    pub skeleton: Option<String>,
    pub enqueued_at: Option<f64>,
    pub lease_expires: f64,
    pub tries: i64,
    pub last_error: Option<String>,
}

/// One row of the `pages` table: a stored page plus the derived language and
/// SimHash fingerprint, conditional-GET validators and recrawl interval. `body`
/// is the indexed text, retained for the (forthcoming) search index and the
/// offline cached-snapshot view.
#[derive(Clone, Debug, PartialEq)]
pub struct PageRow {
    pub id: i64,
    pub url: String,
    pub host: String,
    pub title: Option<String>,
    pub body: Option<String>,
    pub content_hash: Option<String>,
    pub http_status: Option<i64>,
    pub content_type: Option<String>,
    pub bytes: Option<i64>,
    pub fetched_at: Option<f64>,
    pub last_seen: Option<f64>,
    pub etag: Option<String>,
    pub last_modified: Option<String>,
    pub recrawl_interval: Option<f64>,
    pub lang: Option<String>,
    pub simhash: Option<i64>,
    pub cluster_id: Option<i64>,
}

#[derive(Clone, Debug, PartialEq)]
struct SeenHash {
    url: String,
    host: String,
    first_seen: f64,
}

#[derive(Clone, Debug, PartialEq)]
struct UptimeRow {
    host: String,
    ts: f64,
    up: bool,
}

#[derive(Clone, Debug, PartialEq)]
struct TrapRow {
    ts: f64,
    host: String,
    url: String,
    reason: String,
}

/// Trap-cap admission budgets for an untrusted (public) enqueue. A `None` (or
/// non-positive) field means "no cap" — the trusted/forced path passes
/// [`Caps::default`] and bypasses all of them.
#[derive(Clone, Copy, Debug, Default)]
pub struct Caps {
    pub max_unique_urls: Option<i64>,
    pub max_pages_per_host: Option<i64>,
    pub max_urls_per_template: Option<i64>,
    pub max_urls_per_skeleton: Option<i64>,
}

fn cap_hit(cap: Option<i64>, count: i64) -> bool {
    matches!(cap, Some(m) if m > 0 && count >= m)
}

/// Result of an [`Store::enqueue`] / [`Store::add_seed`] admission decision.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Enqueued {
    Ok,
    DupUrl,
    UniqueBudget,
    HostDead,
    HostBudget,
    TemplateCap,
    SkeletonCap,
}

impl Enqueued {
    /// The reason code string, identical to the Python reference.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Enqueued::Ok => "ok",
            Enqueued::DupUrl => "dup-url",
            Enqueued::UniqueBudget => "unique-budget",
            Enqueued::HostDead => "host-dead",
            Enqueued::HostBudget => "host-budget",
            Enqueued::TemplateCap => "template-cap",
            Enqueued::SkeletonCap => "skeleton-cap",
        }
    }
}

/// Result of [`Store::reseed_url`].
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Reseed {
    Requeued,
    HostDead,
    Enqueue(Enqueued),
}

impl Reseed {
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Reseed::Requeued => "requeued",
            Reseed::HostDead => "host-dead",
            Reseed::Enqueue(e) => e.as_str(),
        }
    }
}

/// Result of [`Store::store_page`].
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum StoreOutcome {
    Stored,
    Updated,
    Unchanged,
    Duplicate,
}

impl StoreOutcome {
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            StoreOutcome::Stored => "stored",
            StoreOutcome::Updated => "updated",
            StoreOutcome::Unchanged => "unchanged",
            StoreOutcome::Duplicate => "duplicate",
        }
    }
}

/// A leased frontier item handed to a fetch worker.
#[derive(Clone, Debug, PartialEq)]
pub struct Lease {
    pub id: i64,
    pub url: String,
    pub host: String,
    pub depth: i64,
    pub template: Option<String>,
    pub skeleton: Option<String>,
    pub tries: i64,
}

/// The onioncrawler store. Single-threaded/`&mut`-guarded (the Python store took
/// an `RLock`; here exclusive access is the borrow checker's job).
#[derive(Clone, Debug, Default)]
pub struct Store {
    meta: HashMap<String, i64>,
    hosts: HashMap<String, HostRow>,
    frontier: HashMap<i64, FrontierRow>,
    frontier_by_url: HashMap<String, i64>,
    next_frontier_id: i64,
    pages: HashMap<i64, PageRow>,
    pages_by_url: HashMap<String, i64>,
    next_page_id: i64,
    entities: HashMap<i64, Vec<(String, String)>>,
    seen_hashes: HashMap<String, SeenHash>,
    host_templates: HashMap<(String, String), i64>,
    skeletons: HashMap<String, i64>,
    link_edges: HashMap<(String, String), i64>,
    uptime: Vec<UptimeRow>,
    trap_log: Vec<TrapRow>,
}

impl Store {
    /// A fresh, empty store.
    #[must_use]
    pub fn new() -> Self {
        let mut s = Store {
            next_frontier_id: 1,
            next_page_id: 1,
            ..Default::default()
        };
        // Match the Python `_init_db`: the two rollup counters always exist.
        s.meta.insert("pages_stored".to_string(), 0);
        s.meta.insert("urls_enqueued".to_string(), 0);
        s
    }

    // ---------------------------------------------------------------- meta
    /// The value of a `meta` counter (0 if absent).
    #[must_use]
    pub fn counter(&self, key: &str) -> i64 {
        self.meta.get(key).copied().unwrap_or(0)
    }

    fn incr(&mut self, key: &str, delta: i64) {
        *self.meta.entry(key.to_string()).or_insert(0) += delta;
    }

    // --------------------------------------------------------------- hosts
    /// Create the host row if absent (stamping first/last seen).
    pub fn ensure_host(&mut self, host: &str, now: f64) {
        self.hosts
            .entry(host.to_string())
            .or_insert_with(|| HostRow::seen(now));
    }

    /// The host row, if present.
    #[must_use]
    pub fn get_host(&self, host: &str) -> Option<&HostRow> {
        self.hosts.get(host)
    }

    /// Set a host's lifecycle state. Moving a host to `trapped` / `blocked`
    /// dead-letters its still-queued frontier rows so a crawl can terminate
    /// instead of waiting on a host it will never fetch.
    pub fn set_host_state(&mut self, host: &str, state: &str, reason: Option<&str>) {
        if let Some(h) = self.hosts.get_mut(host) {
            h.state = state.to_string();
            h.trapped_reason = reason.map(str::to_string);
        }
        if state == "trapped" || state == "blocked" {
            let err = format!("host-{state}:{}", reason.unwrap_or(""));
            self.dead_letter_queued(host, &err);
        }
    }

    fn dead_letter_queued(&mut self, host: &str, err: &str) {
        for row in self.frontier.values_mut() {
            if row.host == host && row.status == "queued" {
                row.status = "error".to_string();
                row.last_error = Some(err.to_string());
            }
        }
    }

    /// Park a host until `when` (politeness).
    pub fn set_next_allowed(&mut self, host: &str, when: f64) {
        if let Some(h) = self.hosts.get_mut(host) {
            h.next_allowed = when;
        }
    }

    /// Set (or clear) a host's crawl-delay.
    pub fn set_host_crawl_delay(&mut self, host: &str, delay: Option<f64>) {
        if let Some(h) = self.hosts.get_mut(host) {
            h.crawl_delay = delay;
        }
    }

    /// Record a fetched robots.txt (body, presence, timestamp, crawl-delay).
    pub fn save_robots(
        &mut self,
        host: &str,
        body: Option<&str>,
        present: bool,
        now: f64,
        crawl_delay: Option<f64>,
    ) {
        if let Some(h) = self.hosts.get_mut(host) {
            h.robots_body = body.map(str::to_string);
            h.robots_present = present;
            h.robots_fetched_at = Some(now);
            h.crawl_delay = crawl_delay;
        }
    }

    /// Mark a host's sitemaps as processed (one-shot).
    pub fn mark_sitemaps_done(&mut self, host: &str) {
        if let Some(h) = self.hosts.get_mut(host) {
            h.sitemaps_done = true;
        }
    }

    /// Bump one of a host's rollup counters and refresh `last_seen`.
    pub fn host_counter_bump(&mut self, host: &str, field: HostCounter, delta: i64, now: f64) {
        if let Some(h) = self.hosts.get_mut(host) {
            match field {
                HostCounter::Fetch => h.fetch_count += delta,
                HostCounter::Dup => h.dup_count += delta,
                HostCounter::Error => h.error_count += delta,
                HostCounter::Pages => h.pages_count += delta,
            }
            h.last_seen = Some(now);
        }
    }

    // ------------------------------------------------------------- frontier
    /// Enqueue a seed. Revives a host previously demoted to `dead` (an operator
    /// un-age); `trapped` / `blocked` are never revived. `force` (the trusted
    /// path) bypasses the trap caps.
    pub fn add_seed(
        &mut self,
        canon: &CanonicalUrl,
        depth: i64,
        priority: i64,
        caps: Caps,
        now: f64,
        force: bool,
    ) -> Enqueued {
        self.revive_dead(&canon.host);
        self.enqueue(canon, depth, priority, caps, now, force)
    }

    /// Re-enqueue a curated seed root: revive a dead host, requeue a settled
    /// (`done`/`error`) existing row, refuse on an inactive host, else enqueue.
    pub fn reseed_url(
        &mut self,
        canon: &CanonicalUrl,
        caps: Caps,
        now: f64,
        force: bool,
    ) -> Reseed {
        self.revive_dead(&canon.host);
        let existing_status = self
            .frontier_by_url
            .get(&canon.url)
            .and_then(|id| self.frontier.get(id))
            .map(|r| r.status.clone());
        if existing_status.is_some() {
            let active = self
                .hosts
                .get(&canon.host)
                .map(|h| h.state == STATE_ACTIVE)
                .unwrap_or(true);
            if !active {
                return Reseed::HostDead;
            }
            if let Some(id) = self.frontier_by_url.get(&canon.url).copied() {
                if let Some(row) = self.frontier.get_mut(&id) {
                    if row.status == "done" || row.status == "error" {
                        row.status = "queued".to_string();
                        row.lease_expires = 0.0;
                    }
                }
            }
            return Reseed::Requeued;
        }
        Reseed::Enqueue(self.enqueue(canon, 0, 0, caps, now, force))
    }

    fn revive_dead(&mut self, host: &str) {
        if let Some(h) = self.hosts.get_mut(host) {
            if h.state == "dead" {
                h.state = STATE_ACTIVE.to_string();
                h.up = true;
                h.down_recrawls = 0;
                h.consecutive_failures = 0;
            }
        }
    }

    /// Try to admit `canon` to the frontier, enforcing the stateful trap caps
    /// (unless `force`). Returns the same reason codes as the Python reference.
    pub fn enqueue(
        &mut self,
        canon: &CanonicalUrl,
        depth: i64,
        priority: i64,
        caps: Caps,
        now: f64,
        force: bool,
    ) -> Enqueued {
        let url = &canon.url;
        let host = &canon.host;
        let template = canon.template_key();
        let skeleton = canon.skeleton_key();

        // already known (any status) → no duplicate work
        if self.frontier_by_url.contains_key(url) {
            return Enqueued::DupUrl;
        }
        // Global unique-URL budget, checked BEFORE creating a host row so an
        // untrusted flood of distinct hosts/paths can grow neither table.
        if !force && cap_hit(caps.max_unique_urls, self.counter("urls_enqueued")) {
            return Enqueued::UniqueBudget;
        }

        self.ensure_host(host, now);
        let (state, enq_count) = {
            let h = self.hosts.get(host).expect("ensured");
            (h.state.clone(), h.enq_count)
        };
        // An inactive host never receives a new frontier row (it could never be
        // leased and would stall termination). add_seed revives 'dead' first.
        if state == "trapped" || state == "blocked" || state == "dead" {
            return Enqueued::HostDead;
        }

        if !force {
            if cap_hit(caps.max_pages_per_host, enq_count) {
                return Enqueued::HostBudget;
            }
            let tkey = (host.clone(), template.clone());
            if cap_hit(
                caps.max_urls_per_template,
                self.host_templates.get(&tkey).copied().unwrap_or(0),
            ) {
                return Enqueued::TemplateCap;
            }
            if cap_hit(
                caps.max_urls_per_skeleton,
                self.skeletons.get(&skeleton).copied().unwrap_or(0),
            ) {
                return Enqueued::SkeletonCap;
            }
        }

        let id = self.next_frontier_id;
        self.next_frontier_id += 1;
        self.frontier.insert(
            id,
            FrontierRow {
                id,
                url: url.clone(),
                host: host.clone(),
                depth,
                status: "queued".to_string(),
                priority,
                template: Some(template.clone()),
                skeleton: Some(skeleton.clone()),
                enqueued_at: Some(now),
                lease_expires: 0.0,
                tries: 0,
                last_error: None,
            },
        );
        self.frontier_by_url.insert(url.clone(), id);
        if let Some(h) = self.hosts.get_mut(host) {
            h.enq_count += 1;
        }
        *self
            .host_templates
            .entry((host.clone(), template))
            .or_insert(0) += 1;
        *self.skeletons.entry(skeleton).or_insert(0) += 1;
        self.incr("urls_enqueued", 1);
        Enqueued::Ok
    }

    /// Return expired leases to the queue. Returns the count reclaimed.
    pub fn reclaim_expired(&mut self, now: f64) -> usize {
        let mut n = 0;
        for row in self.frontier.values_mut() {
            if row.status == "leased" && row.lease_expires < now {
                row.status = "queued".to_string();
                row.lease_expires = 0.0;
                n += 1;
            }
        }
        n
    }

    /// Graceful-shutdown reclaim: every leased row back to the queue.
    pub fn reclaim_all_leased(&mut self) -> usize {
        let mut n = 0;
        for row in self.frontier.values_mut() {
            if row.status == "leased" {
                row.status = "queued".to_string();
                row.lease_expires = 0.0;
                n += 1;
            }
        }
        n
    }

    /// Atomically lease the best eligible queued URL (reclaiming expired leases
    /// first), parking its host for the lease window. `None` if nothing is
    /// eligible. Ordering: priority ASC, depth ASC, id ASC.
    pub fn lease(&mut self, now: f64, lease_ttl: f64) -> Option<Lease> {
        self.reclaim_expired(now);
        // pick the best eligible row
        let mut best: Option<(i64, i64, i64)> = None; // (priority, depth, id)
        for row in self.frontier.values() {
            if row.status != "queued" {
                continue;
            }
            let ok_host = self
                .hosts
                .get(&row.host)
                .map(|h| h.state == STATE_ACTIVE && h.next_allowed <= now)
                .unwrap_or(false);
            if !ok_host {
                continue;
            }
            let key = (row.priority, row.depth, row.id);
            if best.map(|b| key < b).unwrap_or(true) {
                best = Some(key);
            }
        }
        let id = best?.2;
        let (url, host, depth, template, skeleton, tries) = {
            let row = self.frontier.get_mut(&id)?;
            row.status = "leased".to_string();
            row.lease_expires = now + lease_ttl;
            row.tries += 1;
            (
                row.url.clone(),
                row.host.clone(),
                row.depth,
                row.template.clone(),
                row.skeleton.clone(),
                row.tries,
            )
        };
        self.set_next_allowed(&host, now + lease_ttl);
        Some(Lease {
            id,
            url,
            host,
            depth,
            template,
            skeleton,
            tries,
        })
    }

    /// Mark a leased item done.
    pub fn mark_done(&mut self, frontier_id: i64) {
        if let Some(row) = self.frontier.get_mut(&frontier_id) {
            row.status = "done".to_string();
            row.lease_expires = 0.0;
        }
    }

    /// Mark a leased item errored (reason truncated to 500 bytes, as Python).
    pub fn mark_error(&mut self, frontier_id: i64, reason: &str) {
        if let Some(row) = self.frontier.get_mut(&frontier_id) {
            row.status = "error".to_string();
            row.last_error = Some(truncate(reason, 500));
            row.lease_expires = 0.0;
        }
    }

    /// `(queued_count, active_leased_count)` for termination decisions.
    #[must_use]
    pub fn pending_summary(&self, now: f64) -> (usize, usize) {
        let mut queued = 0;
        let mut leased = 0;
        for row in self.frontier.values() {
            if row.status == "queued" {
                queued += 1;
            } else if row.status == "leased" && row.lease_expires > now {
                leased += 1;
            }
        }
        (queued, leased)
    }

    /// Expire never-attempted (`tries==0`) queued URLs older than `ttl`.
    pub fn reap_unverified(&mut self, ttl: f64, now: f64) -> usize {
        let cutoff = now - ttl;
        let doomed: Vec<i64> = self
            .frontier
            .values()
            .filter(|r| {
                r.status == "queued" && r.tries == 0 && r.enqueued_at.is_some_and(|e| e < cutoff)
            })
            .map(|r| r.id)
            .collect();
        for id in &doomed {
            if let Some(row) = self.frontier.remove(id) {
                self.frontier_by_url.remove(&row.url);
            }
        }
        doomed.len()
    }

    /// Reset `done` rows whose page is older than `ttl` back to `queued`.
    pub fn requeue_stale(&mut self, ttl: f64, now: f64) -> usize {
        let cutoff = now - ttl;
        let stale_urls: std::collections::HashSet<&str> = self
            .pages
            .values()
            .filter(|p| p.fetched_at.is_some_and(|f| f < cutoff))
            .map(|p| p.url.as_str())
            .collect();
        let ids: Vec<i64> = self
            .frontier
            .values()
            .filter(|r| r.status == "done" && stale_urls.contains(r.url.as_str()))
            .map(|r| r.id)
            .collect();
        for id in &ids {
            if let Some(row) = self.frontier.get_mut(id) {
                row.status = "queued".to_string();
                row.lease_expires = 0.0;
            }
        }
        ids.len()
    }

    /// Recrawl scheduler: requeue every `done` row on an active host whose page
    /// is due (`fetched_at + recrawl_interval <= now`, falling back to
    /// `default_interval`).
    pub fn mark_recrawl_due(&mut self, now: f64, default_interval: f64) -> usize {
        let due_urls: std::collections::HashSet<&str> = self
            .pages
            .values()
            .filter(|p| {
                let interval = p.recrawl_interval.unwrap_or(default_interval);
                p.fetched_at.is_some_and(|f| f + interval <= now)
            })
            .map(|p| p.url.as_str())
            .collect();
        let active: std::collections::HashSet<&str> = self
            .hosts
            .iter()
            .filter(|(_, h)| h.state == STATE_ACTIVE)
            .map(|(k, _)| k.as_str())
            .collect();
        let ids: Vec<i64> = self
            .frontier
            .values()
            .filter(|r| {
                r.status == "done"
                    && active.contains(r.host.as_str())
                    && due_urls.contains(r.url.as_str())
            })
            .map(|r| r.id)
            .collect();
        for id in &ids {
            if let Some(row) = self.frontier.get_mut(id) {
                row.status = "queued".to_string();
                row.lease_expires = 0.0;
            }
        }
        ids.len()
    }

    // ---------------------------------------------------------------- pages
    fn index_entities(&mut self, pid: i64, text: &str) {
        let ents: Vec<(String, String)> = extract_entities(text)
            .into_iter()
            .map(|(k, v)| (k.as_str().to_string(), v))
            .collect();
        if ents.is_empty() {
            self.entities.remove(&pid);
        } else {
            self.entities.insert(pid, ents);
        }
    }

    /// Insert or update a page (+ entity index). Returns the outcome. Computes
    /// the language guess and the 64-bit SimHash from title+text, mirroring the
    /// Python `store_page`.
    #[allow(clippy::too_many_arguments)]
    pub fn store_page(
        &mut self,
        url: &str,
        host: &str,
        title: Option<&str>,
        text: Option<&str>,
        content_hash: Option<&str>,
        http_status: Option<i64>,
        content_type: Option<&str>,
        nbytes: Option<i64>,
        now: f64,
        dedup: bool,
        etag: Option<&str>,
        last_modified: Option<&str>,
        interval: Option<f64>,
    ) -> StoreOutcome {
        let lang = guess_lang(text.or(title).unwrap_or(""), 8);
        let shash = simhash64(&format!("{}\n{}", title.unwrap_or(""), text.unwrap_or("")));

        if let Some(&pid) = self.pages_by_url.get(url) {
            let changed = self.pages.get(&pid).and_then(|p| p.content_hash.clone())
                != content_hash.map(str::to_string);
            if let Some(p) = self.pages.get_mut(&pid) {
                p.title = title.map(str::to_string);
                p.body = text.map(str::to_string);
                p.content_hash = content_hash.map(str::to_string);
                p.http_status = http_status;
                p.content_type = content_type.map(str::to_string);
                p.bytes = nbytes;
                p.fetched_at = Some(now);
                p.last_seen = Some(now);
                p.etag = etag.map(str::to_string);
                p.last_modified = last_modified.map(str::to_string);
                p.lang = Some(lang.to_string());
                p.simhash = Some(shash);
                p.cluster_id = None;
                if interval.is_some() {
                    p.recrawl_interval = interval;
                }
            }
            self.index_entities(pid, text.unwrap_or(""));
            return if changed {
                StoreOutcome::Updated
            } else {
                StoreOutcome::Unchanged
            };
        }

        if dedup {
            if let Some(ch) = content_hash {
                if !ch.is_empty() && self.seen_hashes.contains_key(ch) {
                    if let Some(h) = self.hosts.get_mut(host) {
                        h.dup_count += 1;
                    }
                    return StoreOutcome::Duplicate;
                }
            }
        }

        let pid = self.next_page_id;
        self.next_page_id += 1;
        self.pages.insert(
            pid,
            PageRow {
                id: pid,
                url: url.to_string(),
                host: host.to_string(),
                title: title.map(str::to_string),
                body: text.map(str::to_string),
                content_hash: content_hash.map(str::to_string),
                http_status,
                content_type: content_type.map(str::to_string),
                bytes: nbytes,
                fetched_at: Some(now),
                last_seen: Some(now),
                etag: etag.map(str::to_string),
                last_modified: last_modified.map(str::to_string),
                recrawl_interval: interval,
                lang: Some(lang.to_string()),
                simhash: Some(shash),
                cluster_id: None,
            },
        );
        self.pages_by_url.insert(url.to_string(), pid);
        self.index_entities(pid, text.unwrap_or(""));
        if let Some(ch) = content_hash {
            if !ch.is_empty() {
                self.seen_hashes.entry(ch.to_string()).or_insert(SeenHash {
                    url: url.to_string(),
                    host: host.to_string(),
                    first_seen: now,
                });
            }
        }
        if let Some(h) = self.hosts.get_mut(host) {
            h.pages_count += 1;
        }
        self.incr("pages_stored", 1);
        StoreOutcome::Stored
    }

    /// Record a page re-seen unchanged: bump `last_seen` and (if `grow_interval`
    /// is given) back the recrawl interval off multiplicatively, capped.
    pub fn touch_page(
        &mut self,
        url: &str,
        now: f64,
        grow_interval: f64,
        max_interval: f64,
        base_interval: f64,
    ) {
        let Some(&pid) = self.pages_by_url.get(url) else {
            return;
        };
        if grow_interval != 0.0 {
            let cur = self
                .pages
                .get(&pid)
                .and_then(|p| p.recrawl_interval)
                .unwrap_or(0.0);
            let nxt = backoff_interval(cur, grow_interval, max_interval, base_interval);
            if nxt != 0.0 {
                if let Some(p) = self.pages.get_mut(&pid) {
                    p.last_seen = Some(now);
                    p.recrawl_interval = Some(nxt);
                }
                return;
            }
        }
        if let Some(p) = self.pages.get_mut(&pid) {
            p.last_seen = Some(now);
        }
    }

    /// The page row for `url`, if stored.
    #[must_use]
    pub fn get_page(&self, url: &str) -> Option<&PageRow> {
        self.pages_by_url.get(url).and_then(|id| self.pages.get(id))
    }

    /// A read-only cached snapshot (title + indexed body) for `url` — useful when
    /// the live onion is offline. `None` if not indexed.
    #[must_use]
    pub fn get_page_snapshot(&self, url: &str) -> Option<PageSnapshot> {
        self.get_page(url).map(|p| PageSnapshot {
            url: p.url.clone(),
            host: p.host.clone(),
            title: p.title.clone(),
            fetched_at: p.fetched_at,
            body: p.body.clone(),
        })
    }

    // ------------------------------------------------------------- entities
    /// Pages containing entity `(kind, value)`, newest (`last_seen`) first.
    #[must_use]
    pub fn find_by_entity(
        &self,
        kind: &str,
        value: &str,
        limit: usize,
        offset: usize,
    ) -> Vec<EntityHit> {
        let mut hits: Vec<&PageRow> = self
            .entities
            .iter()
            .filter(|(_, ents)| ents.iter().any(|(k, v)| k == kind && v == value))
            .filter_map(|(pid, _)| self.pages.get(pid))
            .collect();
        // newest first; ties broken by descending id for a stable order
        hits.sort_by(|a, b| {
            b.last_seen
                .partial_cmp(&a.last_seen)
                .unwrap_or(std::cmp::Ordering::Equal)
                .then(b.id.cmp(&a.id))
        });
        hits.into_iter()
            .skip(offset)
            .take(limit)
            .map(|p| EntityHit {
                url: p.url.clone(),
                host: p.host.clone(),
                title: p.title.clone(),
                last_seen: p.last_seen,
            })
            .collect()
    }

    /// The `(kind, value)` entities on one page, ordered by kind.
    #[must_use]
    pub fn entities_for_page(&self, pid: i64) -> Vec<(String, String)> {
        let mut ents = self.entities.get(&pid).cloned().unwrap_or_default();
        ents.sort_by(|a, b| a.0.cmp(&b.0));
        ents
    }

    /// Distinct entity-value count per kind.
    #[must_use]
    pub fn entity_counts(&self) -> HashMap<String, usize> {
        let mut per: HashMap<String, std::collections::HashSet<&str>> = HashMap::new();
        for ents in self.entities.values() {
            for (k, v) in ents {
                per.entry(k.clone()).or_default().insert(v.as_str());
            }
        }
        per.into_iter().map(|(k, set)| (k, set.len())).collect()
    }

    // ------------------------------------------------------------- liveness
    /// A successful (or 304) fetch: clear the failure streak, mark up, reset
    /// dead-onion aging, revive a `dead` host. Returns `true` on a down→up flip.
    pub fn record_fetch_up(&mut self, host: &str, now: f64) -> bool {
        let Some(h) = self.hosts.get_mut(host) else {
            return false;
        };
        let was_down = !h.up;
        h.consecutive_failures = 0;
        h.last_ok = Some(now);
        h.up = true;
        h.down_recrawls = 0;
        h.last_seen = Some(now);
        if h.state == "dead" {
            h.state = STATE_ACTIVE.to_string();
        }
        if was_down {
            self.uptime.push(UptimeRow {
                host: host.to_string(),
                ts: now,
                up: true,
            });
        }
        was_down
    }

    /// A failed fetch: bump the consecutive-failure counter and, at `threshold`,
    /// flip the host down. Returns `true` on an up→down transition.
    pub fn record_fetch_down(&mut self, host: &str, now: f64, threshold: i64) -> bool {
        let Some(h) = self.hosts.get_mut(host) else {
            return false;
        };
        let cf = h.consecutive_failures + 1;
        let went_down = h.up && cf >= threshold;
        let new_up = h.up && !went_down;
        h.consecutive_failures = cf;
        h.last_down = Some(now);
        h.up = new_up;
        if went_down {
            self.uptime.push(UptimeRow {
                host: host.to_string(),
                ts: now,
                up: false,
            });
        }
        went_down
    }

    /// One dead-onion aging cycle: each still-active down host accrues a
    /// down-recrawl; those down across `>= threshold` cycles are demoted to
    /// `dead` (and their queued URLs dead-lettered). Returns the count demoted.
    pub fn age_dead_hosts(&mut self, threshold: i64, _now: f64) -> usize {
        for h in self.hosts.values_mut() {
            if !h.up && h.state == STATE_ACTIVE {
                h.down_recrawls += 1;
            }
        }
        let newly: Vec<String> = self
            .hosts
            .iter()
            .filter(|(_, h)| !h.up && h.state == STATE_ACTIVE && h.down_recrawls >= threshold)
            .map(|(k, _)| k.clone())
            .collect();
        for host in &newly {
            if let Some(h) = self.hosts.get_mut(host) {
                h.state = "dead".to_string();
                h.trapped_reason = Some("dead-onion".to_string());
            }
            self.dead_letter_queued(host, "host-dead");
        }
        newly.len()
    }

    /// The most recent uptime transitions for a host, newest first.
    #[must_use]
    pub fn uptime_history(&self, host: &str, limit: usize) -> Vec<(f64, bool)> {
        self.uptime
            .iter()
            .rev()
            .filter(|r| r.host == host)
            .take(limit)
            .map(|r| (r.ts, r.up))
            .collect()
    }

    // ----------------------------------------------------------- link graph
    /// Persist one inter-onion link edge (`src -> dst`); self-links ignored.
    pub fn add_link_edge(&mut self, src_host: &str, dst_host: &str, delta: i64) {
        if src_host.is_empty() || dst_host.is_empty() || src_host == dst_host {
            return;
        }
        *self
            .link_edges
            .entry((src_host.to_string(), dst_host.to_string()))
            .or_insert(0) += delta;
    }

    /// Offline PageRank-lite over the host link graph. Writes a normalized
    /// (max = 1.0) authority score to every host. Returns the number scored.
    pub fn compute_authority(&mut self, iterations: usize, damping: f64) -> usize {
        let hosts: Vec<String> = self.hosts.keys().cloned().collect();
        let n = hosts.len();
        if n == 0 {
            return 0;
        }
        let hostset: std::collections::HashSet<&str> = hosts.iter().map(String::as_str).collect();
        let mut out_edges: HashMap<&str, Vec<(&str, i64)>> = HashMap::new();
        let mut outsum: HashMap<&str, i64> = HashMap::new();
        for ((s, d), c) in &self.link_edges {
            if !hostset.contains(s.as_str()) || !hostset.contains(d.as_str()) || s == d {
                continue;
            }
            out_edges.entry(s).or_default().push((d.as_str(), *c));
            *outsum.entry(s.as_str()).or_insert(0) += *c;
        }
        let nf = n as f64;
        let mut rank: HashMap<&str, f64> = hosts.iter().map(|h| (h.as_str(), 1.0 / nf)).collect();
        let base = (1.0 - damping) / nf;
        for _ in 0..iterations.max(1) {
            let dangling: f64 = hosts
                .iter()
                .filter(|h| outsum.get(h.as_str()).copied().unwrap_or(0) == 0)
                .map(|h| rank[h.as_str()])
                .sum();
            let mut newrank: HashMap<&str, f64> = hosts
                .iter()
                .map(|h| (h.as_str(), base + damping * dangling / nf))
                .collect();
            for (s, targets) in &out_edges {
                let share = damping * rank[*s] / outsum[*s] as f64;
                for (d, c) in targets {
                    *newrank.get_mut(d).expect("host in set") += share * (*c as f64);
                }
            }
            rank = newrank;
        }
        let mx = rank.values().cloned().fold(f64::NEG_INFINITY, f64::max);
        let mx = if mx <= 0.0 { 1.0 } else { mx };
        for (h, r) in &rank {
            if let Some(row) = self.hosts.get_mut(*h) {
                row.authority = r / mx;
            }
        }
        n
    }

    // ------------------------------------------------------- mirror clusters
    /// Offline near-duplicate clustering via SimHash (union-find, smallest id as
    /// root). Assigns every scanned page a `cluster_id`; returns the number of
    /// multi-page (mirror) clusters found. O(n²) over the scanned window.
    pub fn cluster_mirrors(&mut self, threshold: u32, max_pages: usize) -> usize {
        let mut rows: Vec<(i64, i64)> = self
            .pages
            .values()
            .filter(|p| p.simhash.is_some())
            .map(|p| (p.id, p.simhash.unwrap()))
            .collect();
        rows.sort_by_key(|r| r.0);
        rows.truncate(max_pages);
        let ids: Vec<i64> = rows.iter().map(|r| r.0).collect();
        let sh: Vec<i64> = rows.iter().map(|r| r.1).collect();

        let mut parent: HashMap<i64, i64> = ids.iter().map(|&i| (i, i)).collect();
        fn find(parent: &mut HashMap<i64, i64>, mut x: i64) -> i64 {
            while parent[&x] != x {
                let gp = parent[&parent[&x]];
                parent.insert(x, gp);
                x = gp;
            }
            x
        }
        let m = ids.len();
        for i in 0..m {
            if sh[i] == 0 {
                continue;
            }
            for j in (i + 1)..m {
                if sh[j] != 0 && hamming(sh[i] as u64, sh[j] as u64) <= threshold {
                    let ra = find(&mut parent, ids[i]);
                    let rb = find(&mut parent, ids[j]);
                    if ra != rb {
                        parent.insert(ra.max(rb), ra.min(rb));
                    }
                }
            }
        }
        let mut groups: HashMap<i64, Vec<i64>> = HashMap::new();
        for &i in &ids {
            let root = find(&mut parent, i);
            groups.entry(root).or_default().push(i);
        }
        for (root, members) in &groups {
            for pid in members {
                if let Some(p) = self.pages.get_mut(pid) {
                    p.cluster_id = Some(*root);
                }
            }
        }
        groups.values().filter(|g| g.len() > 1).count()
    }

    // ---------------------------------------------------------------- traps
    /// Append a trap-trigger record.
    pub fn log_trap(&mut self, host: &str, url: &str, reason: &str, now: f64) {
        self.trap_log.push(TrapRow {
            ts: now,
            host: host.to_string(),
            url: url.to_string(),
            reason: reason.to_string(),
        });
    }

    // --------------------------------------------------------------- admin
    /// Block a host and delete its indexed pages (and their entities). Returns
    /// the number of pages removed.
    pub fn purge_host(&mut self, host: &str) -> usize {
        let host = normalize_host(host);
        let ids: Vec<i64> = self
            .pages
            .values()
            .filter(|p| p.host == host)
            .map(|p| p.id)
            .collect();
        for pid in &ids {
            if let Some(p) = self.pages.remove(pid) {
                self.pages_by_url.remove(&p.url);
            }
            self.entities.remove(pid);
        }
        let row = self
            .hosts
            .entry(host.clone())
            .or_insert_with(|| HostRow::seen(0.0));
        row.state = "blocked".to_string();
        row.trapped_reason = Some("admin-purge".to_string());
        for r in self.frontier.values_mut() {
            if r.host == host && (r.status == "queued" || r.status == "leased") {
                r.status = "error".to_string();
                r.last_error = Some("host-blocked:admin-purge".to_string());
            }
        }
        ids.len()
    }

    // ---------------------------------------------------------------- stats
    /// Frontier row counts grouped by status.
    #[must_use]
    pub fn frontier_by_status(&self) -> HashMap<String, usize> {
        let mut m: HashMap<String, usize> = HashMap::new();
        for r in self.frontier.values() {
            *m.entry(r.status.clone()).or_insert(0) += 1;
        }
        m
    }

    /// Host counts grouped by state.
    #[must_use]
    pub fn hosts_by_state(&self) -> HashMap<String, usize> {
        let mut m: HashMap<String, usize> = HashMap::new();
        for h in self.hosts.values() {
            *m.entry(h.state.clone()).or_insert(0) += 1;
        }
        m
    }

    /// Hosts demoted out of `active` by the trap defences, as
    /// `(host, trapped_reason)` sorted by host — the per-host detail behind the
    /// `hosts_trapped` / `hosts_blocked` gauges, for the operator `stats` report.
    #[must_use]
    pub fn trapped_hosts(&self) -> Vec<(String, String)> {
        let mut out: Vec<(String, String)> = self
            .hosts
            .iter()
            .filter(|(_, h)| h.trapped_reason.is_some())
            .map(|(name, h)| (name.clone(), h.trapped_reason.clone().unwrap_or_default()))
            .collect();
        out.sort();
        out
    }

    /// A flat map of numeric gauges (for `/metrics` + `/health`).
    #[must_use]
    pub fn metrics(&self) -> HashMap<&'static str, i64> {
        let fs = self.frontier_by_status();
        let hs = self.hosts_by_state();
        let g = |m: &HashMap<String, usize>, k: &str| *m.get(k).unwrap_or(&0) as i64;
        let dup: i64 = self.hosts.values().map(|h| h.dup_count).sum();
        let err: i64 = self.hosts.values().map(|h| h.error_count).sum();
        let up = self.hosts.values().filter(|h| h.up).count() as i64;
        let down = self.hosts.values().filter(|h| !h.up).count() as i64;
        HashMap::from([
            ("frontier_queued", g(&fs, "queued")),
            ("frontier_leased", g(&fs, "leased")),
            ("frontier_done", g(&fs, "done")),
            ("frontier_error", g(&fs, "error")),
            ("pages", self.pages.len() as i64),
            ("pages_stored", self.counter("pages_stored")),
            ("urls_enqueued", self.counter("urls_enqueued")),
            ("hosts", self.hosts.len() as i64),
            ("hosts_active", g(&hs, "active")),
            ("hosts_trapped", g(&hs, "trapped")),
            ("hosts_blocked", g(&hs, "blocked")),
            ("hosts_dead", g(&hs, "dead")),
            ("hosts_up", up),
            ("hosts_down", down),
            ("duplicates", dup),
            ("errors", err),
            ("trap_events", self.trap_log.len() as i64),
            ("link_edges", self.link_edges.len() as i64),
        ])
    }

    /// Total page count.
    #[must_use]
    pub fn page_count(&self) -> usize {
        self.pages.len()
    }

    /// Total host count.
    #[must_use]
    pub fn host_count(&self) -> usize {
        self.hosts.len()
    }
}

/// Snapshot format version. Bump on any breaking change to the field layout.
const SNAPSHOT_VERSION: u8 = 1;

impl Store {
    /// Serialize the entire store to a self-describing binary blob — the whole
    /// persistence unit (no database file). Collections are emitted in a stable
    /// key order so the blob is reproducible for a given logical state.
    #[must_use]
    pub fn snapshot(&self) -> Vec<u8> {
        let mut w = Writer::new();
        w.u8(SNAPSHOT_VERSION);

        let mut meta: Vec<(&String, &i64)> = self.meta.iter().collect();
        meta.sort_by(|a, b| a.0.cmp(b.0));
        w.len(meta.len());
        for (k, v) in meta {
            w.str(k);
            w.i64(*v);
        }

        w.i64(self.next_frontier_id);
        w.i64(self.next_page_id);

        let mut hosts: Vec<(&String, &HostRow)> = self.hosts.iter().collect();
        hosts.sort_by(|a, b| a.0.cmp(b.0));
        w.len(hosts.len());
        for (host, h) in hosts {
            w.str(host);
            w.str(&h.state);
            w.f64(h.next_allowed);
            w.opt_f64(h.crawl_delay);
            w.i64(h.enq_count);
            w.i64(h.pages_count);
            w.i64(h.fetch_count);
            w.i64(h.dup_count);
            w.i64(h.error_count);
            w.opt_str(&h.robots_body);
            w.opt_f64(h.robots_fetched_at);
            w.bool(h.robots_present);
            w.bool(h.sitemaps_done);
            w.opt_str(&h.trapped_reason);
            w.opt_f64(h.first_seen);
            w.opt_f64(h.last_seen);
            w.i64(h.consecutive_failures);
            w.opt_f64(h.last_ok);
            w.opt_f64(h.last_down);
            w.i64(h.down_recrawls);
            w.bool(h.up);
            w.f64(h.authority);
        }

        let mut frontier: Vec<&FrontierRow> = self.frontier.values().collect();
        frontier.sort_by_key(|r| r.id);
        w.len(frontier.len());
        for r in frontier {
            w.i64(r.id);
            w.str(&r.url);
            w.str(&r.host);
            w.i64(r.depth);
            w.str(&r.status);
            w.i64(r.priority);
            w.opt_str(&r.template);
            w.opt_str(&r.skeleton);
            w.opt_f64(r.enqueued_at);
            w.f64(r.lease_expires);
            w.i64(r.tries);
            w.opt_str(&r.last_error);
        }

        let mut pages: Vec<&PageRow> = self.pages.values().collect();
        pages.sort_by_key(|p| p.id);
        w.len(pages.len());
        for p in pages {
            w.i64(p.id);
            w.str(&p.url);
            w.str(&p.host);
            w.opt_str(&p.title);
            w.opt_str(&p.body);
            w.opt_str(&p.content_hash);
            w.opt_i64(p.http_status);
            w.opt_str(&p.content_type);
            w.opt_i64(p.bytes);
            w.opt_f64(p.fetched_at);
            w.opt_f64(p.last_seen);
            w.opt_str(&p.etag);
            w.opt_str(&p.last_modified);
            w.opt_f64(p.recrawl_interval);
            w.opt_str(&p.lang);
            w.opt_i64(p.simhash);
            w.opt_i64(p.cluster_id);
        }

        let mut entities: Vec<(&i64, &Vec<(String, String)>)> = self.entities.iter().collect();
        entities.sort_by_key(|e| *e.0);
        w.len(entities.len());
        for (pid, ents) in entities {
            w.i64(*pid);
            w.len(ents.len());
            for (k, v) in ents {
                w.str(k);
                w.str(v);
            }
        }

        let mut seen: Vec<(&String, &SeenHash)> = self.seen_hashes.iter().collect();
        seen.sort_by(|a, b| a.0.cmp(b.0));
        w.len(seen.len());
        for (hash, s) in seen {
            w.str(hash);
            w.str(&s.url);
            w.str(&s.host);
            w.f64(s.first_seen);
        }

        let mut tmpl: Vec<(&(String, String), &i64)> = self.host_templates.iter().collect();
        tmpl.sort_by(|a, b| a.0.cmp(b.0));
        w.len(tmpl.len());
        for ((host, template), cnt) in tmpl {
            w.str(host);
            w.str(template);
            w.i64(*cnt);
        }

        let mut skel: Vec<(&String, &i64)> = self.skeletons.iter().collect();
        skel.sort_by(|a, b| a.0.cmp(b.0));
        w.len(skel.len());
        for (skeleton, cnt) in skel {
            w.str(skeleton);
            w.i64(*cnt);
        }

        let mut edges: Vec<(&(String, String), &i64)> = self.link_edges.iter().collect();
        edges.sort_by(|a, b| a.0.cmp(b.0));
        w.len(edges.len());
        for ((src, dst), cnt) in edges {
            w.str(src);
            w.str(dst);
            w.i64(*cnt);
        }

        w.len(self.uptime.len());
        for u in &self.uptime {
            w.str(&u.host);
            w.f64(u.ts);
            w.bool(u.up);
        }

        w.len(self.trap_log.len());
        for t in &self.trap_log {
            w.f64(t.ts);
            w.str(&t.host);
            w.str(&t.url);
            w.str(&t.reason);
        }

        w.into_bytes()
    }

    /// Rebuild a store from a [`Store::snapshot`] blob. Returns `None` if the
    /// blob is truncated, malformed, or a version this build does not understand.
    #[must_use]
    pub fn restore(blob: &[u8]) -> Option<Store> {
        let mut r = Reader::new(blob);
        if r.u8()? != SNAPSHOT_VERSION {
            return None;
        }
        let mut s = Store {
            next_frontier_id: 1,
            next_page_id: 1,
            ..Default::default()
        };

        let n = r.len()?;
        for _ in 0..n {
            let k = r.str()?;
            let v = r.i64()?;
            s.meta.insert(k, v);
        }

        s.next_frontier_id = r.i64()?;
        s.next_page_id = r.i64()?;

        let n = r.len()?;
        for _ in 0..n {
            let host = r.str()?;
            let h = HostRow {
                state: r.str()?,
                next_allowed: r.f64()?,
                crawl_delay: r.opt_f64()?,
                enq_count: r.i64()?,
                pages_count: r.i64()?,
                fetch_count: r.i64()?,
                dup_count: r.i64()?,
                error_count: r.i64()?,
                robots_body: r.opt_str()?,
                robots_fetched_at: r.opt_f64()?,
                robots_present: r.bool()?,
                sitemaps_done: r.bool()?,
                trapped_reason: r.opt_str()?,
                first_seen: r.opt_f64()?,
                last_seen: r.opt_f64()?,
                consecutive_failures: r.i64()?,
                last_ok: r.opt_f64()?,
                last_down: r.opt_f64()?,
                down_recrawls: r.i64()?,
                up: r.bool()?,
                authority: r.f64()?,
            };
            s.hosts.insert(host, h);
        }

        let n = r.len()?;
        for _ in 0..n {
            let row = FrontierRow {
                id: r.i64()?,
                url: r.str()?,
                host: r.str()?,
                depth: r.i64()?,
                status: r.str()?,
                priority: r.i64()?,
                template: r.opt_str()?,
                skeleton: r.opt_str()?,
                enqueued_at: r.opt_f64()?,
                lease_expires: r.f64()?,
                tries: r.i64()?,
                last_error: r.opt_str()?,
            };
            s.frontier_by_url.insert(row.url.clone(), row.id);
            s.frontier.insert(row.id, row);
        }

        let n = r.len()?;
        for _ in 0..n {
            let p = PageRow {
                id: r.i64()?,
                url: r.str()?,
                host: r.str()?,
                title: r.opt_str()?,
                body: r.opt_str()?,
                content_hash: r.opt_str()?,
                http_status: r.opt_i64()?,
                content_type: r.opt_str()?,
                bytes: r.opt_i64()?,
                fetched_at: r.opt_f64()?,
                last_seen: r.opt_f64()?,
                etag: r.opt_str()?,
                last_modified: r.opt_str()?,
                recrawl_interval: r.opt_f64()?,
                lang: r.opt_str()?,
                simhash: r.opt_i64()?,
                cluster_id: r.opt_i64()?,
            };
            s.pages_by_url.insert(p.url.clone(), p.id);
            s.pages.insert(p.id, p);
        }

        let n = r.len()?;
        for _ in 0..n {
            let pid = r.i64()?;
            let m = r.len()?;
            let mut ents = Vec::with_capacity(m);
            for _ in 0..m {
                let k = r.str()?;
                let v = r.str()?;
                ents.push((k, v));
            }
            s.entities.insert(pid, ents);
        }

        let n = r.len()?;
        for _ in 0..n {
            let hash = r.str()?;
            let sh = SeenHash {
                url: r.str()?,
                host: r.str()?,
                first_seen: r.f64()?,
            };
            s.seen_hashes.insert(hash, sh);
        }

        let n = r.len()?;
        for _ in 0..n {
            let host = r.str()?;
            let template = r.str()?;
            let cnt = r.i64()?;
            s.host_templates.insert((host, template), cnt);
        }

        let n = r.len()?;
        for _ in 0..n {
            let skeleton = r.str()?;
            let cnt = r.i64()?;
            s.skeletons.insert(skeleton, cnt);
        }

        let n = r.len()?;
        for _ in 0..n {
            let src = r.str()?;
            let dst = r.str()?;
            let cnt = r.i64()?;
            s.link_edges.insert((src, dst), cnt);
        }

        let n = r.len()?;
        for _ in 0..n {
            s.uptime.push(UptimeRow {
                host: r.str()?,
                ts: r.f64()?,
                up: r.bool()?,
            });
        }

        let n = r.len()?;
        for _ in 0..n {
            s.trap_log.push(TrapRow {
                ts: r.f64()?,
                host: r.str()?,
                url: r.str()?,
                reason: r.str()?,
            });
        }

        Some(s)
    }
}

/// Which host rollup counter [`Store::host_counter_bump`] adjusts.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum HostCounter {
    Fetch,
    Dup,
    Error,
    Pages,
}

/// A cached page view (title + body) returned by [`Store::get_page_snapshot`].
#[derive(Clone, Debug, PartialEq)]
pub struct PageSnapshot {
    pub url: String,
    pub host: String,
    pub title: Option<String>,
    pub fetched_at: Option<f64>,
    pub body: Option<String>,
}

/// A page hit from [`Store::find_by_entity`].
#[derive(Clone, Debug, PartialEq)]
pub struct EntityHit {
    pub url: String,
    pub host: String,
    pub title: Option<String>,
    pub last_seen: Option<f64>,
}

/// Truncate a string to at most `max` bytes without splitting a UTF-8 boundary.
fn truncate(s: &str, max: usize) -> String {
    if s.len() <= max {
        return s.to_string();
    }
    let mut end = max;
    while end > 0 && !s.is_char_boundary(end) {
        end -= 1;
    }
    s[..end].to_string()
}

#[cfg(test)]
mod tests;
