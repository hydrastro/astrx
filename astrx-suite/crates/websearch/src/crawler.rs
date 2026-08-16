//! The crawl loop — leasing, politeness, robots, extraction and indexing tied
//! together. A port of the single-worker core of the Python `websearch.crawler`.
//!
//! Discipline (production-shaped): robots.txt honoured per host (fetched + cached,
//! with `Crawl-delay`), per-host politeness through the frontier, max depth +
//! per-host + total budgets + a content-type allowlist, URL canonicalization +
//! frontier dedup, trap guards (segment-repeat / query-param / path-depth caps),
//! capped redirects + gzip + timeouts (via the SSRF-checked fetch), content-hash
//! dedup, `rel=canonical` + `meta robots`, conditional GET (304 → revalidate),
//! and resumability (leases persist; `done` URLs are never refetched).
//!
//! The pure decision helpers ([`CrawlConfig`], content-type routing,
//! [`trap_ok`], [`public_resolved`]) compile without `net`; the [`Crawler`]
//! orchestration (which drives the async fetch) is behind the `net` feature.
//!
//! PDFs (`application/pdf`) are indexed via the pure [`crate::pdftext`] extractor
//! when [`PDF_TYPE`] is added to [`CrawlConfig::content_types`] (off by default).
//!
//! Federation-aware: [`enqueue_links`](Crawler) records every edge but only
//! *follows* an internal target this node owns under HRW hashing
//! ([`CrawlConfig::shard_id`] + [`CrawlConfig::shards`]; empty = single-node owns
//! everything). See [`crate::federation`].
//!
//! Concurrency: [`CrawlConfig::workers`] `> 1` spawns that many worker tasks that
//! share the frontier + index + a global page budget; atomic leasing keeps every
//! reachable allowed page indexed exactly once regardless of fetch order, so the
//! indexed set is deterministic under concurrency ([`Crawler::run`] dispatches to
//! the multi-worker path and rejoins the shared state afterwards). Each worker
//! runs the same per-URL logic as the single-worker path ([`net_impl::Core::finish_fetch`]
//! is the shared result-processing tail). With [`CrawlConfig::keep_alive`] each
//! worker routes fetches through its own pooled [`Fetcher`](crate::fetcher::Fetcher).
//!
//! Deferred (documented): per-redirect-hop robots re-checking (each hop is still
//! re-checked for scheme + scope + the SSRF internal-IP gate; robots is enforced
//! on the leased URL).

use crate::canonical::{canonicalize, host_of, max_segment_repeat, path_depth, query_param_count};
use crate::ssrf::ip_is_internal;
use std::collections::HashSet;
use std::time::Duration;

/// HTML-ish content types (parsed with the HTML extractor). The EMPTY string is
/// a member — a response with no usable `Content-Type` is treated as HTML —
/// exactly as in the Python `crawler.HTML_TYPES`.
const HTML_TYPES: &[&str] = &[
    "text/html",
    "application/xhtml+xml",
    "application/xml",
    "text/xml",
    "",
];
/// Plain-text-like types indexed verbatim (no HTML parsing).
const TEXT_TYPES: &[&str] = &[
    "text/plain",
    "text/markdown",
    "text/x-markdown",
    "text/csv",
    "text/tab-separated-values",
    "application/json",
    "text/x-rst",
];
/// The PDF media type (indexed via the pure [`crate::pdftext`] extractor).
pub const PDF_TYPE: &str = "application/pdf";

/// True for a plain-text-like content type.
#[must_use]
pub fn is_text_type(ct: &str) -> bool {
    TEXT_TYPES.contains(&ct)
}

/// True for an HTML-ish content type — including the EMPTY string, which the
/// crawler treats as HTML (a response with no `Content-Type`). Mirrors Python's
/// `ctype in crawler.HTML_TYPES`.
#[must_use]
pub fn is_html_type(ct: &str) -> bool {
    HTML_TYPES.contains(&ct)
}

/// The scheme of a URL, lower-cased.
#[must_use]
pub fn scheme_of(url: &str) -> String {
    crawlcore::urlparse::urlsplit(url, "").scheme.to_lowercase()
}

/// The request path (`path?query`, or `/`) of a URL.
#[must_use]
pub fn path_of(url: &str) -> String {
    let s = crawlcore::urlparse::urlsplit(url, "");
    let mut p = if s.path.is_empty() {
        "/".to_string()
    } else {
        s.path
    };
    if !s.query.is_empty() {
        p.push('?');
        p.push_str(&s.query);
    }
    p
}

/// True iff `host` is a **literal** IP address in an internal range. A hostname
/// returns `false` (classifying it would need DNS; the media verticals must stay
/// pure string work). Mirrors the Python `_is_internal_ip_literal`.
#[must_use]
pub fn is_internal_ip_literal(host: &str) -> bool {
    if host.is_empty() {
        return false;
    }
    if host.parse::<std::net::IpAddr>().is_err() {
        return false;
    }
    ip_is_internal(host)
}

/// Resolve `raw` against `base` and return it only if it is a PUBLIC http(s) URL.
///
/// Canonicalizes (dropping non-http(s)/unparseable to `""`) and drops a URL whose
/// host is a literal internal-range IP — so a viewer's browser is never handed an
/// internal-address resource to fetch (a client-side SSRF vector). Opens NO
/// socket. Mirrors the Python `_public_resolved`.
#[must_use]
pub fn public_resolved(raw: &str, base: &str) -> String {
    if raw.is_empty() {
        return String::new();
    }
    let Some(abs) = canonicalize(raw, Some(base)) else {
        return String::new();
    };
    if is_internal_ip_literal(&host_of(&abs)) {
        return String::new();
    }
    abs
}

/// Crawl configuration. Defaults to single-node (empty [`shards`](CrawlConfig::shards)
/// = this node owns every host); set `shard_id` + `shards` for fleet mode.
#[derive(Clone, Debug)]
pub struct CrawlConfig {
    /// Hosts to keep in scope (`None` = crawl broadly).
    pub scope_hosts: Option<Vec<String>>,
    /// The crawler's `User-Agent`.
    pub user_agent: String,
    /// The token used to select the robots.txt group.
    pub robots_agent: String,
    /// Whether to obey robots.txt `Disallow`.
    pub respect_robots: bool,
    /// Per-connection timeout.
    pub timeout: Duration,
    /// Response body byte cap.
    pub max_bytes: usize,
    /// Maximum crawl depth.
    pub max_depth: i64,
    /// Per-host page budget (0 = unlimited).
    pub per_host_budget: u64,
    /// Total page budget for a run.
    pub total_budget: u64,
    /// Base per-host politeness delay (seconds).
    pub base_delay: f64,
    /// Extra random politeness delay (seconds; applied only with the `rand`
    /// feature — deferred here, so effectively 0 unless `base_delay` carries it).
    pub jitter: f64,
    /// Maximum redirect hops.
    pub max_redirects: u32,
    /// Allowed URL schemes.
    pub allowed_schemes: Vec<String>,
    /// Content types that are fetched + indexed.
    pub content_types: HashSet<String>,
    /// Max allowed repeats of a path segment (trap guard).
    pub segment_repeat_cap: usize,
    /// Max allowed query parameters (trap guard).
    pub query_param_cap: usize,
    /// Max allowed path depth (trap guard).
    pub max_path_depth: usize,
    /// Links harvested from ONE page (0 = unlimited, which is what the crawler
    /// effectively had). `htmlparse` deliberately extracts every `<a href>` — its
    /// output is pinned byte-identical by the goldens — so the cap belongs here,
    /// between extraction and the frontier/link-graph writes that retain memory.
    pub max_links_per_page: usize,
    /// Lease duration (seconds).
    pub lease_seconds: f64,
    /// Refuse hosts resolving to internal addresses (the SSRF guard).
    pub block_internal_ips: bool,
    /// Authorities exempt from the internal-address block.
    pub allow_hosts: Vec<String>,
    /// Route fetches through a pooled, keep-alive [`Fetcher`](crate::fetcher::Fetcher)
    /// (per worker) instead of a fresh connection per request. The SSRF gate still
    /// runs on every request and redirect hop — even when a pooled socket is
    /// reused. Default `false` (a fresh, `Connection: close` fetch per request).
    pub keep_alive: bool,
    /// Number of concurrent crawl workers sharing the frontier + index + a global
    /// page budget. Default `1` (the single-worker sequential path, unchanged).
    /// `>1` spawns that many worker tasks; leasing keeps each reachable allowed
    /// page indexed exactly once regardless of fetch order.
    pub workers: usize,
    /// Default age (seconds) after which an indexed URL is due for a refetch —
    /// the threshold [`Crawler::enqueue_recrawls`] uses when given no explicit
    /// interval. Python's `CrawlConfig.recrawl_interval`, default 7 days.
    pub recrawl_interval: f64,
    /// This node's id in the shard set (fleet mode). `None` = single-node.
    pub shard_id: Option<String>,
    /// All shard ids for HRW routing; empty = single-node (this node owns every
    /// host). See [`crate::federation`].
    pub shards: Vec<String>,
}

impl Default for CrawlConfig {
    fn default() -> Self {
        let mut content_types: HashSet<String> = ["text/html", "application/xhtml+xml"]
            .iter()
            .map(|s| (*s).to_string())
            .collect();
        for t in TEXT_TYPES {
            content_types.insert((*t).to_string());
        }
        CrawlConfig {
            scope_hosts: None,
            user_agent: crate::httpclient::DEFAULT_UA.to_string(),
            robots_agent: "astrx-websearch".to_string(),
            respect_robots: true,
            timeout: Duration::from_secs(10),
            max_bytes: 2_000_000,
            max_depth: 6,
            per_host_budget: 500,
            total_budget: 2000,
            base_delay: 0.5,
            jitter: 0.3,
            max_redirects: 5,
            allowed_schemes: vec!["http".to_string(), "https".to_string()],
            content_types,
            segment_repeat_cap: 3,
            query_param_cap: 3,
            max_path_depth: 24,
            max_links_per_page: 1000,
            lease_seconds: 120.0,
            block_internal_ips: true,
            allow_hosts: Vec::new(),
            keep_alive: false,
            workers: 1,
            recrawl_interval: 7.0 * 86_400.0,
            shard_id: None,
            shards: Vec::new(),
        }
    }
}

impl CrawlConfig {
    #[cfg(feature = "net")]
    fn scheme_allowed(&self, url: &str) -> bool {
        self.allowed_schemes.contains(&scheme_of(url))
    }
}

/// True if `url` clears the structural trap guards (segment-repeat / query-param
/// / path-depth caps). Mirrors the Python `_trap_ok`.
#[must_use]
pub fn trap_ok(url: &str, cfg: &CrawlConfig) -> bool {
    max_segment_repeat(url) <= cfg.segment_repeat_cap
        && query_param_count(url) <= cfg.query_param_cap
        && path_depth(url) <= cfg.max_path_depth
}

/// Aggregate outcome counts for a crawl run.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct CrawlStats {
    /// Pages fetched.
    pub fetched: u64,
    /// Documents indexed.
    pub indexed: u64,
    /// URLs skipped (budget / robots / content-type / …).
    pub skipped: u64,
    /// Fetch errors.
    pub errors: u64,
    /// URLs blocked by robots.
    pub robots_blocked: u64,
    /// Exact-duplicate pages dropped.
    pub dups: u64,
    /// Unchanged (304) revalidations.
    pub unchanged: u64,
    /// Links discarded by the per-page cap ([`CrawlConfig::max_links_per_page`]
    /// or [`crate::index::MAX_EDGES_PER_CALL`]). Reported rather than dropped
    /// silently: a non-zero count is how an operator learns a host is serving
    /// link bombs, and how they know the crawl's coverage of that page is partial.
    pub links_dropped: u64,
}

#[cfg(feature = "net")]
pub use net_impl::Crawler;

#[cfg(feature = "net")]
mod net_impl {
    use super::{
        is_text_type, path_of, public_resolved, scheme_of, trap_ok, CrawlConfig, CrawlStats,
        PDF_TYPE,
    };
    use crate::canonical::{authority_of, canonicalize, host_of, in_scope, join};
    use crate::fetcher::{fetch, FetchOpts, Fetcher};
    use crate::frontier::Frontier;
    use crate::htmlparse::{self, guess_lang, Extracted};
    use crate::httpclient::{decode_body, FetchResult};
    use crate::index::{content_hash, DocFields, Index};
    use crate::pdftext;
    use crate::robots::{parse as parse_robots, Robots};
    use std::collections::HashMap;
    use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
    use std::sync::{Arc, Mutex};
    use std::time::{Duration, SystemTime, UNIX_EPOCH};
    use tokio::task::JoinSet;

    fn now_secs() -> f64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_secs_f64())
            .unwrap_or(0.0)
    }

    /// The last path segment of `url` (`urlsplit(url).path.rsplit("/", 1)[-1]`),
    /// used as a PDF's title fallback when it carries no `/Title`.
    fn url_filename(url: &str) -> String {
        let path = crawlcore::urlparse::urlsplit(url, "").path;
        path.rsplit('/').next().unwrap_or("").to_string()
    }

    /// The shared crawl state — the frontier (work queue), the document index,
    /// and the run statistics. In single-worker mode the [`Crawler`] owns one
    /// directly; in multi-worker mode it is moved behind an `Arc<Mutex<Core>>` so N
    /// workers share it, and moved back into the crawler when they join. Grouping
    /// the three lets the per-URL result tail ([`Core::finish_fetch`]) run under a
    /// single lock, so a multi-worker crawl's indexed result is the same set as a
    /// single-worker crawl (each leased URL is processed atomically, exactly once).
    #[derive(Default)]
    pub(super) struct Core {
        fr: Frontier,
        ix: Index,
        stats: CrawlStats,
    }

    /// The crawl engine over a [`Frontier`] + an [`Index`], driving the
    /// SSRF-checked fetch.
    pub struct Crawler {
        /// The crawl configuration.
        pub cfg: CrawlConfig,
        core: Core,
        robots: HashMap<String, Robots>,
        pages_fetched: u64,
        /// The per-worker pooled connector, present iff `cfg.keep_alive`. Fetches
        /// route through it (reusing sockets) instead of the free `fetch`.
        fetcher: Option<Fetcher>,
    }

    impl Crawler {
        /// A crawler with the given config over a fresh frontier + index.
        #[must_use]
        pub fn new(cfg: CrawlConfig) -> Self {
            let fetcher = cfg.keep_alive.then(|| Fetcher::new(true));
            Crawler {
                cfg,
                core: Core::default(),
                robots: HashMap::new(),
                pages_fetched: 0,
                fetcher,
            }
        }

        /// The document index (to read results after a crawl).
        #[must_use]
        pub fn index(&self) -> &Index {
            &self.core.ix
        }

        /// The document index, mutably — so a driver can [`Index::finalize`] the
        /// ranking signals and then [`Index::snapshot`] it after a crawl (what the
        /// `websearch crawl` CLI does, mirroring the Python `index.finalize`).
        #[must_use]
        pub fn index_mut(&mut self) -> &mut Index {
            &mut self.core.ix
        }

        /// The frontier (to inspect queue state).
        #[must_use]
        pub fn frontier(&self) -> &Frontier {
            &self.core.fr
        }

        /// The run statistics.
        #[must_use]
        pub fn stats(&self) -> &CrawlStats {
            &self.core.stats
        }

        /// Seed the frontier; returns how many URLs were newly queued.
        pub fn add_seeds(&mut self, seeds: &[&str]) -> usize {
            let mut added = 0;
            for s in seeds {
                let Some(u) = canonicalize(s, None) else {
                    continue;
                };
                // Seeds are federation-gated too, so a shard only crawls hosts it
                // owns (single-node default: `owns` is always true).
                if self.cfg.scheme_allowed(&u)
                    && crate::federation::owns(
                        &host_of(&u),
                        self.cfg.shard_id.as_deref(),
                        &self.cfg.shards,
                    )
                    && self.core.fr.add(&u, &authority_of(&u), 0)
                {
                    added += 1;
                }
            }
            added
        }

        /// Re-queue every indexed URL that is due for a recrawl; returns how many
        /// were queued.
        ///
        /// "Due" means `fetched_at + interval <= now` ([`Index::due_for_recrawl`]).
        /// Each due URL goes back into the frontier as `queued` at depth 0 with
        /// reason `recrawl`, and its host's spent politeness budget is cleared so
        /// the refetch is not blocked by it. The refetch itself is an ordinary
        /// [`run`](Self::run) pass — a conditional GET from the stored validators,
        /// through the same SSRF-checked connector as any other fetch. `interval`
        /// of `None` uses [`CrawlConfig::recrawl_interval`].
        ///
        /// This reads the crawler's OWN index, so a driver that wants to refresh a
        /// previously persisted crawl must load that snapshot into
        /// [`index_mut`](Self::index_mut) first (a fresh [`Crawler::new`] has an
        /// empty index and therefore nothing due). Mirrors the Python
        /// `Crawler.enqueue_recrawls`, which gets the same effect for free by
        /// opening the persistent SQLite database.
        pub fn enqueue_recrawls(&mut self, interval: Option<f64>, now: f64) -> usize {
            let interval = interval.unwrap_or(self.cfg.recrawl_interval);
            let due = self.core.ix.due_for_recrawl(interval, now);
            for (url, _host) in &due {
                // The Python re-derives the authority from the URL rather than
                // trusting the stored `docs.host`; so do we.
                self.core.fr.requeue_for_recrawl(url, &authority_of(url));
            }
            due.len()
        }

        /// Run the crawl loop until the page budget is spent or the frontier
        /// drains. Returns the run statistics.
        ///
        /// With `cfg.workers > 1` this dispatches to the multi-worker path
        /// ([`run_multi`](Self::run_multi)); otherwise it takes the single-worker
        /// sequential loop below (unchanged: `workers == 1` is byte-for-byte the
        /// original behaviour).
        pub async fn run(&mut self, max_pages: Option<u64>) -> CrawlStats {
            if self.cfg.workers > 1 {
                return self.run_multi(max_pages).await;
            }
            let budget = max_pages.unwrap_or(self.cfg.total_budget);
            self.core.fr.reclaim(now_secs());
            while self.pages_fetched < budget {
                let now = now_secs();
                self.core.fr.reclaim(now);
                let hb = self.host_budget();
                let leased = self.core.fr.lease(now, self.cfg.lease_seconds, hb);
                match leased {
                    Some(l) => self.process(&l.url, l.depth).await,
                    None => {
                        if !self.core.fr.has_queued() {
                            break;
                        }
                        match self.core.fr.next_ready_time(hb) {
                            Some(t) if t > now => {
                                let wait = (t - now).clamp(0.0, 5.0);
                                tokio::time::sleep(std::time::Duration::from_secs_f64(wait)).await;
                            }
                            _ => break, // queued remain but nothing leasable
                        }
                    }
                }
            }
            // Release any pooled keep-alive sockets (mirrors Python `run` -> close).
            if let Some(f) = self.fetcher.as_mut() {
                f.close();
            }
            self.core.stats.clone()
        }

        /// Pages this crawler has taken through its per-URL processing so far — the
        /// counter [`Crawler::run`]'s budget is measured against.
        ///
        /// Only the single-worker path maintains it (the multi-worker path runs a
        /// shared page budget per call instead), so a driver that wants a
        /// mode-independent measure of progress should read
        /// [`CrawlStats::fetched`].
        #[must_use]
        pub fn pages_fetched(&self) -> u64 {
            self.pages_fetched
        }

        /// Crawl at most `pages` MORE pages, then return.
        ///
        /// # Why this exists
        ///
        /// [`Crawler::run`] runs to exhaustion and its caller then saves. That is
        /// fine when saving costs a corpus rewrite and you therefore only do it
        /// once — and it is exactly what makes a `SIGKILL` mid-run lose the whole
        /// run. A segmented store's commits are cheap, so the driver wants to
        /// crawl a little, commit, and repeat; this is the "a little" that makes
        /// the crash window a number an operator sets rather than the run length.
        ///
        /// It exists as a method because the two run modes take their budget
        /// differently and a caller should not have to know: [`Crawler::run`]
        /// measures its `max_pages` against the cumulative `pages_fetched`, while
        /// the multi-worker path allocates a fresh page budget per call. Both
        /// mean "stop after `pages` more" once the argument is computed right, and
        /// getting it wrong in the caller means either one slice and then silence,
        /// or an unbounded crawl.
        ///
        /// Returns the run-to-date statistics, exactly as [`Crawler::run`] does;
        /// they accumulate across slices, so the final slice's return value is the
        /// whole run's.
        pub async fn run_slice(&mut self, pages: u64) -> CrawlStats {
            // Compute the budget, THEN make exactly one call. Two `self.run(…)`
            // calls in two branches of an `async fn` give the generated state
            // machine room for two copies of `run`'s future, and `run`'s future is
            // enormous in a debug build (it holds the whole fetch/parse/index
            // pipeline). That doubling was measured overflowing a test thread's
            // stack before this was one call.
            let budget = if self.cfg.workers > 1 {
                // The multi-worker path allocates a fresh page budget per call,
                // so its argument is already "this many more".
                pages
            } else {
                // `run` measures its argument against the cumulative counter.
                self.pages_fetched.saturating_add(pages)
            };
            self.run(Some(budget)).await
        }

        /// Multi-worker crawl: move the shared state behind an `Arc<Mutex<Core>>`,
        /// spawn `cfg.workers` worker tasks that share it plus a global page
        /// [`Budget`], join them, then move the state back so [`index`](Self::index)
        /// et al. still work. Aggregated stats accumulate directly into the shared
        /// `Core` (one counter set, bumped under the same lock as the tail), so the
        /// returned totals equal the sum a single-worker run would produce over the
        /// same reachable set.
        async fn run_multi(&mut self, max_pages: Option<u64>) -> CrawlStats {
            let total = max_pages.unwrap_or(self.cfg.total_budget) as i64;
            let workers = self.cfg.workers.max(1);
            // Take the seeded state out of `self` and share it.
            let shared = Arc::new(Mutex::new(std::mem::take(&mut self.core)));
            let budget = Arc::new(Budget::new(total));
            let stop = Arc::new(AtomicBool::new(false));
            {
                lock_core(&shared).fr.reclaim(now_secs());
            }

            let mut set: JoinSet<()> = JoinSet::new();
            for _ in 0..workers {
                let shared = Arc::clone(&shared);
                let budget = Arc::clone(&budget);
                let stop = Arc::clone(&stop);
                let cfg = self.cfg.clone();
                set.spawn(async move {
                    worker_loop(&shared, &budget, &stop, &cfg).await;
                });
            }
            while set.join_next().await.is_some() {}

            // All workers joined: move the shared state back into `self` (so
            // `index`/`frontier`/`stats` keep working) by taking it out from under
            // the lock — no `Arc::try_unwrap` gymnastics, and poison-safe.
            self.core = std::mem::take(&mut *lock_core(&shared));
            self.core.stats.clone()
        }

        fn host_budget(&self) -> Option<u64> {
            if self.cfg.per_host_budget == 0 {
                None
            } else {
                Some(self.cfg.per_host_budget)
            }
        }

        /// Ensure robots.txt for `authority` is fetched + cached (once per host).
        async fn ensure_robots(&mut self, authority: &str, scheme: &str) {
            if self.robots.contains_key(authority) {
                return;
            }
            let rob = fetch_robots(&self.cfg, &mut self.fetcher, authority, scheme).await;
            self.core.fr.set_crawl_delay(authority, rob.crawl_delay());
            self.robots.insert(authority.to_string(), rob);
        }

        async fn process(&mut self, url: &str, depth: i64) {
            let host = authority_of(url);
            let scheme = scheme_of(url);

            // Per-host budget.
            let hrow = self.core.fr.host_row(&host);
            if self.cfg.per_host_budget > 0 && hrow.fetched >= self.cfg.per_host_budget {
                self.core.fr.complete(url, "skipped", Some("host-budget"));
                self.core.stats.skipped += 1;
                return;
            }

            // Robots (always fetched for Crawl-delay; enforced iff respect_robots).
            self.ensure_robots(&host, &scheme).await;
            let (allowed, cdelay) = match self.robots.get(&host) {
                Some(r) => (r.can_fetch(&path_of(url)), r.crawl_delay()),
                None => (true, None),
            };
            if self.cfg.respect_robots && !allowed {
                self.core.fr.complete(url, "skipped", Some("robots"));
                self.core.stats.robots_blocked += 1;
                return;
            }
            let delay = cdelay.map_or(self.cfg.base_delay, |cd| self.cfg.base_delay.max(cd));
            self.core.fr.reserve_host(&host, now_secs() + delay);

            // Conditional GET from stored validators.
            let (etag, last_mod) = self.core.ix.get_validators(url);
            let extra = conditional_headers(&etag, &last_mod);

            self.pages_fetched += 1;
            let opts = fetch_opts(&self.cfg, extra);
            let schemes = self.cfg.allowed_schemes.clone();
            let scope = self.cfg.scope_hosts.clone();
            let allow = |u: &str| -> bool {
                schemes.contains(&scheme_of(u)) && in_scope(u, scope.as_deref())
            };
            let res = route_fetch(&mut self.fetcher, url, &opts, Some(&allow)).await;
            // The post-fetch result tail is shared verbatim with the multi-worker
            // path (`process_shared`), so both index the same set for a given page.
            self.core
                .finish_fetch(&self.cfg, url, depth, &host, delay, res);
        }
    }

    impl Core {
        /// The shared post-fetch tail: record the fetch, then route the result
        /// (error / 304 / non-200 / content-type / extract → canonical → dedup →
        /// index → link expansion) and mark the frontier entry complete. Pure of
        /// network I/O and `await` — the multi-worker path runs it under one lock,
        /// so a leased URL's whole result is applied atomically (each reachable
        /// allowed page is indexed exactly once, regardless of fetch order).
        fn finish_fetch(
            &mut self,
            cfg: &CrawlConfig,
            url: &str,
            depth: i64,
            host: &str,
            delay: f64,
            res: FetchResult,
        ) {
            self.fr.note_fetch(host, now_secs() + delay);
            self.stats.fetched += 1;

            if let Some(err) = res.error.clone() {
                self.fr.complete(url, "error", Some(&err));
                self.stats.errors += 1;
                return;
            }
            if res.status == 304 {
                let et = res.headers.get("etag").map(str::to_string);
                let lm = res.headers.get("last-modified").map(str::to_string);
                self.ix
                    .touch_revalidated(url, now_secs(), et.as_deref(), lm.as_deref());
                self.stats.unchanged += 1;
                self.fr.complete(url, "done", Some("unchanged-304"));
                return;
            }
            if res.status != 200 {
                self.fr
                    .complete(url, "done", Some(&format!("status-{}", res.status)));
                return;
            }
            let ctype = res.content_type.clone();
            if !ctype.is_empty() && !cfg.content_types.contains(&ctype) {
                self.fr
                    .complete(url, "done", Some(&format!("ctype-{ctype}")));
                return;
            }
            let final_url = if res.final_url.is_empty() {
                url.to_string()
            } else {
                res.final_url.clone()
            };
            let new_etag = res.headers.get("etag").unwrap_or("").to_string();
            let new_last_mod = res.headers.get("last-modified").unwrap_or("").to_string();

            // PDF: pure, dependency-free text extraction (pdftext). Mirrors the
            // Python crawler — recover text; skip (don't fake) a scanned/encrypted
            // PDF that yields none; otherwise index it as a normal document, with a
            // title from the PDF `/Title`, else the URL filename, else the URL.
            let ex = if ctype == PDF_TYPE {
                let text = pdftext::extract_text(&res.body, pdftext::DEFAULT_MAX_CHARS);
                if text.is_empty() {
                    self.fr.complete(url, "done", Some("pdf-no-text"));
                    return;
                }
                let mut title = pdftext::extract_title(&res.body);
                if title.is_empty() {
                    title = url_filename(&final_url);
                }
                if title.is_empty() {
                    title = final_url.clone();
                }
                let mut e = Extracted {
                    text,
                    title,
                    ..Extracted::default()
                };
                e.lang = Some(guess_lang(&e.text, None));
                e
            } else if is_text_type(&ctype) {
                let body_text = decode_body(&res.body, res.charset.as_deref());
                let mut e = Extracted {
                    text: body_text.trim().to_string(),
                    ..Extracted::default()
                };
                e.lang = Some(guess_lang(&e.text, None));
                e
            } else {
                let body_text = decode_body(&res.body, res.charset.as_deref());
                htmlparse::extract(&body_text)
            };

            let mut base = final_url.clone();
            if let Some(bh) = &ex.base_href {
                if let Some(j) = join(&final_url, bh) {
                    base = j;
                }
            }

            // rel=canonical: enqueue the canonical target and record this alias done.
            let canon = ex
                .canonical
                .as_ref()
                .and_then(|c| canonicalize(c, Some(&base)));
            if let Some(cn) = canon {
                if cn != final_url
                    && cn != url
                    && cfg.allowed_schemes.contains(&scheme_of(&cn))
                    && in_scope(&cn, cfg.scope_hosts.as_deref())
                {
                    let follow = !ex.nofollow();
                    self.enqueue_links(cfg, &final_url, &base, &ex, depth, follow);
                    // Only enqueue the canonical target if this shard owns it (the
                    // alias is still marked done either way), matching Python.
                    if crate::federation::owns(&host_of(&cn), cfg.shard_id.as_deref(), &cfg.shards)
                    {
                        self.fr.add(&cn, &authority_of(&cn), depth);
                    }
                    self.fr.complete(url, "done", Some("canonical"));
                    return;
                }
            }

            let follow = !ex.nofollow();
            self.enqueue_links(cfg, &final_url, &base, &ex, depth, follow);

            if ex.noindex() {
                self.fr.complete(url, "done", Some("noindex"));
                return;
            }

            let chash = content_hash(&[&ex.title, &ex.description, &ex.text]);
            if let Some(existing) = self.ix.url_with_content_hash(&chash) {
                if existing != final_url {
                    self.fr
                        .complete(url, "done", Some(&format!("dup-of:{existing}")));
                    self.stats.dups += 1;
                    return;
                }
            }

            let sim = crawlcore::dedup::signed64(crate::dedup::simhash(&ex.text));
            let host_field = host_of(&final_url);
            let doc_id = self.ix.upsert_document(
                &final_url,
                DocFields {
                    title: &ex.title,
                    description: &ex.description,
                    body: &ex.text,
                    host: &host_field,
                    lang: ex.lang.as_deref().unwrap_or(""),
                    fetched_at: now_secs(),
                    content_hash: Some(chash),
                    http_status: 200,
                    etag: &new_etag,
                    last_modified: &new_last_mod,
                    content_type: &ctype,
                    simhash: sim,
                },
            );
            // Store the harvested media verticals (metadata only — NO network I/O
            // and NO SSRF surface: URLs are resolved against the page base with the
            // pure canonicalizer and any internal-IP-literal host is dropped, so the
            // viewer's browser is never handed an internal-address thumbnail/embed).
            self.index_images(doc_id, &final_url, &base, &ex);
            self.index_videos(doc_id, &final_url, &base, &ex);
            self.stats.indexed += 1;
            self.fr.complete(url, "done", None);
        }

        /// Resolve + store `<img>` metadata for `doc_id` (mirrors the Python
        /// `_index_images`). Pure string work: `public_resolved` canonicalizes
        /// against `base` and drops a non-http(s) / internal-IP-literal src.
        fn index_images(&mut self, doc_id: i64, page_url: &str, base: &str, ex: &Extracted) {
            if ex.images.is_empty() {
                return;
            }
            let mut resolved: Vec<crate::htmlparse::Image> = Vec::new();
            for im in &ex.images {
                let abs_src = public_resolved(&im.src, base);
                if abs_src.is_empty() {
                    continue;
                }
                resolved.push(crate::htmlparse::Image {
                    src: abs_src,
                    alt: im.alt.clone(),
                    title: im.title.clone(),
                    context: im.context.clone(),
                });
            }
            if !resolved.is_empty() {
                self.ix
                    .replace_images(doc_id, page_url, &host_of(page_url), &resolved);
            }
        }

        /// Resolve + store harvested video metadata for `doc_id` (mirrors the
        /// Python `_index_videos`): each candidate URL is `public_resolved`, a
        /// video with no remaining linkable URL is skipped, and the same video
        /// surfaced by several signals is collapsed by `(video, embed, watch)`.
        fn index_videos(&mut self, doc_id: i64, page_url: &str, base: &str, ex: &Extracted) {
            if ex.videos.is_empty() {
                return;
            }
            let mut resolved: Vec<crate::structured::Video> = Vec::new();
            let mut seen: std::collections::HashSet<(String, String, String)> =
                std::collections::HashSet::new();
            for v in &ex.videos {
                let video_url = public_resolved(&v.video_url, base);
                let embed_url = public_resolved(&v.embed_url, base);
                let watch_url = public_resolved(&v.watch_url, base);
                let thumbnail = public_resolved(&v.thumbnail, base);
                if video_url.is_empty() && embed_url.is_empty() && watch_url.is_empty() {
                    continue;
                }
                let key = (video_url.clone(), embed_url.clone(), watch_url.clone());
                if !seen.insert(key) {
                    continue;
                }
                resolved.push(crate::structured::Video {
                    video_url,
                    embed_url,
                    watch_url,
                    title: v.title.clone(),
                    thumbnail,
                    source: v.source.clone(),
                    duration: v.duration,
                    context: v.context.clone(),
                });
            }
            if !resolved.is_empty() {
                self.ix
                    .replace_videos(doc_id, page_url, &host_of(page_url), &resolved);
            }
        }

        fn enqueue_links(
            &mut self,
            cfg: &CrawlConfig,
            src_url: &str,
            base: &str,
            ex: &Extracted,
            depth: i64,
            follow: bool,
        ) {
            // The cap covers ACCEPTED links (canonical, in-scheme, not already
            // seen on this page); the loop stops the moment it is spent, so a
            // page of 100 000 `<a href=/N>` costs neither the canonicalisation of
            // the tail nor the 100 000 frontier rows and graph edges it used to
            // produce (+13 MB RSS for one page; ~6.5 GB over a 500-page host).
            // (`crawlcore::budget::Budget` in full: this module has its own
            // `Budget`, the atomic *page* budget shared by the workers.)
            let mut budget = crawlcore::budget::Budget::new(if cfg.max_links_per_page == 0 {
                usize::MAX
            } else {
                cfg.max_links_per_page
            });
            let mut edges: Vec<(String, bool)> = Vec::new();
            let mut seen: HashMap<String, ()> = HashMap::new();
            let mut dropped = 0u64;
            for (i, href) in ex.links.iter().enumerate() {
                if budget.is_exhausted() {
                    dropped = (ex.links.len() - i) as u64;
                    break;
                }
                let Some(tgt) = canonicalize(href, Some(base)) else {
                    continue;
                };
                if !cfg.scheme_allowed(&tgt) || seen.contains_key(&tgt) {
                    continue;
                }
                budget.take(1);
                seen.insert(tgt.clone(), ());
                let internal = in_scope(&tgt, cfg.scope_hosts.as_deref());
                edges.push((tgt.clone(), internal));
                // The edge is always recorded; only the *follow/enqueue* is
                // federation-gated. In fleet mode a shard enqueues only the hosts
                // it owns under HRW, so each host is crawled by exactly one shard
                // (single-node default: empty `shards` -> owns everything).
                if follow
                    && internal
                    && depth < cfg.max_depth
                    && trap_ok(&tgt, cfg)
                    && crate::federation::owns(&host_of(&tgt), cfg.shard_id.as_deref(), &cfg.shards)
                {
                    self.fr.add(&tgt, &authority_of(&tgt), depth + 1);
                }
            }
            if !edges.is_empty() {
                dropped += self.ix.add_links(src_url, &edges) as u64;
            }
            self.stats.links_dropped += dropped;
        }
    }

    // ---- shared fetch helpers (single- and multi-worker) ------------------

    /// The conditional-GET headers (`If-None-Match` / `If-Modified-Since`) for the
    /// stored validators; empty validators are omitted.
    fn conditional_headers(etag: &str, last_mod: &str) -> Vec<(String, String)> {
        let mut extra: Vec<(String, String)> = Vec::new();
        if !etag.is_empty() {
            extra.push(("If-None-Match".to_string(), etag.to_string()));
        }
        if !last_mod.is_empty() {
            extra.push(("If-Modified-Since".to_string(), last_mod.to_string()));
        }
        extra
    }

    /// The [`FetchOpts`] for a page fetch, from the crawl config + conditional
    /// headers (shared by the single- and multi-worker paths).
    fn fetch_opts(cfg: &CrawlConfig, extra_headers: Vec<(String, String)>) -> FetchOpts {
        FetchOpts {
            user_agent: cfg.user_agent.clone(),
            timeout: cfg.timeout,
            max_bytes: cfg.max_bytes,
            max_redirects: cfg.max_redirects,
            block_internal: cfg.block_internal_ips,
            allow_hosts: cfg.allow_hosts.clone(),
            extra_headers,
            ..FetchOpts::default()
        }
    }

    /// Route a fetch through the per-worker pooled [`Fetcher`] when present
    /// (keep-alive), else the free [`fetch`] — same SSRF-gated semantics either
    /// way. Mirrors the Python `Crawler._fetch`.
    async fn route_fetch(
        fetcher: &mut Option<Fetcher>,
        url: &str,
        opts: &FetchOpts,
        allow: Option<&(dyn Fn(&str) -> bool + Sync)>,
    ) -> FetchResult {
        match fetcher {
            Some(f) => f.fetch(url, opts, allow).await,
            None => fetch(url, opts, allow).await,
        }
    }

    /// Fetch + parse `authority`'s robots.txt (or empty → allow-all on any
    /// non-200 / error), routing through the pooled connector when present. The
    /// fetch gate is scheme + scope only (NOT robots, which would recurse); the
    /// internal-IP denylist is enforced by the fetch itself. Mirrors the Python
    /// `Crawler._robots_for` fetch half.
    async fn fetch_robots(
        cfg: &CrawlConfig,
        fetcher: &mut Option<Fetcher>,
        authority: &str,
        scheme: &str,
    ) -> Robots {
        let robots_url = format!("{scheme}://{authority}/robots.txt");
        let opts = FetchOpts {
            user_agent: cfg.user_agent.clone(),
            timeout: cfg.timeout,
            max_bytes: 262_144,
            max_redirects: 3,
            block_internal: cfg.block_internal_ips,
            allow_hosts: cfg.allow_hosts.clone(),
            ..FetchOpts::default()
        };
        let schemes = cfg.allowed_schemes.clone();
        let scope = cfg.scope_hosts.clone();
        let allow = move |u: &str| -> bool {
            schemes.contains(&scheme_of(u)) && in_scope(u, scope.as_deref())
        };
        let res = route_fetch(fetcher, &robots_url, &opts, Some(&allow)).await;
        let text = if res.error.is_none() && res.status == 200 {
            decode_body(&res.body, res.charset.as_deref())
        } else {
            String::new() // 4xx/5xx/error → empty → allow all
        };
        parse_robots(&text, &cfg.robots_agent)
    }

    // ---- multi-worker driver ----------------------------------------------

    /// A thread-safe global page budget shared by all workers of a crawl (the
    /// analogue of the Python `_Budget`): [`take`](Self::take) atomically claims a
    /// page slot iff one remains, and [`give_back`](Self::give_back) returns an
    /// unused slot. The sum of successful `take`s over a run never exceeds the
    /// initial total, so the workers collectively fetch at most `total` pages.
    struct Budget(AtomicI64);

    impl Budget {
        fn new(total: i64) -> Self {
            Budget(AtomicI64::new(total))
        }

        /// Claim one page slot; `true` iff one was available.
        fn take(&self) -> bool {
            // Speculatively decrement; if we went past zero, undo and report empty.
            if self.0.fetch_sub(1, Ordering::SeqCst) > 0 {
                true
            } else {
                self.0.fetch_add(1, Ordering::SeqCst);
                false
            }
        }

        /// Return a slot claimed by [`take`](Self::take) but not spent (no work was
        /// leasable), so it can be reused by this or another worker.
        fn give_back(&self) {
            self.0.fetch_add(1, Ordering::SeqCst);
        }
    }

    /// Lock the shared [`Core`], recovering the guard even if a worker panicked
    /// while holding it (a poisoned lock still yields the state — one bad URL must
    /// not wedge the whole crawl).
    fn lock_core(m: &Mutex<Core>) -> std::sync::MutexGuard<'_, Core> {
        m.lock().unwrap_or_else(std::sync::PoisonError::into_inner)
    }

    /// One worker of a multi-worker crawl (the analogue of the Python
    /// `Crawler.run_worker`). Coordinates purely through the shared frontier
    /// (atomic leases) and the shared page `budget`; a worker stops only when the
    /// budget is spent, `stop` is set, or there is provably no more work — no
    /// leasable queued URL *and* no peer still holds a lease that could enqueue
    /// more (`fr.has_leased`). Has its own robots cache + pooled [`Fetcher`], so a
    /// pooled socket is never shared across workers.
    async fn worker_loop(
        shared: &Mutex<Core>,
        budget: &Budget,
        stop: &AtomicBool,
        cfg: &CrawlConfig,
    ) {
        let mut robots: HashMap<String, Robots> = HashMap::new();
        let mut fetcher: Option<Fetcher> = cfg.keep_alive.then(|| Fetcher::new(true));
        let hb = if cfg.per_host_budget == 0 {
            None
        } else {
            Some(cfg.per_host_budget)
        };
        {
            lock_core(shared).fr.reclaim(now_secs());
        }
        loop {
            if stop.load(Ordering::SeqCst) {
                break;
            }
            if !budget.take() {
                break;
            }
            let now = now_secs();
            // Reclaim + lease under ONE lock so the lease is atomic (no two workers
            // ever process the same URL).
            let leased = {
                let mut c = lock_core(shared);
                c.fr.reclaim(now);
                c.fr.lease(now, cfg.lease_seconds, hb)
            };
            match leased {
                Some(l) => {
                    process_shared(shared, cfg, &mut robots, &mut fetcher, &l.url, l.depth).await;
                }
                None => {
                    budget.give_back(); // slot unused: nothing was leasable
                    let (has_queued, next_ready, has_leased) = {
                        let c = lock_core(shared);
                        (
                            c.fr.has_queued(),
                            c.fr.next_ready_time(hb),
                            c.fr.has_leased(),
                        )
                    };
                    // Queued URLs remain but their host is on a politeness cooldown:
                    // wait briefly and retry.
                    if has_queued {
                        if let Some(t) = next_ready {
                            if t > now {
                                let wait = (t - now).clamp(0.0, 0.25);
                                tokio::time::sleep(Duration::from_secs_f64(wait)).await;
                                continue;
                            }
                        }
                    }
                    // A peer still holds a lease and may enqueue more — stay alive.
                    if has_leased {
                        tokio::time::sleep(Duration::from_millis(20)).await;
                        continue;
                    }
                    break; // provably drained
                }
            }
        }
        if let Some(f) = fetcher.as_mut() {
            f.close();
        }
    }

    /// A worker's per-URL processing: the same lease→robots→conditional-GET→fetch
    /// pipeline as the single-worker [`Crawler::process`], but each frontier/index
    /// access takes the shared lock and the network fetch runs lock-free. The
    /// post-fetch result tail runs under one lock via the shared
    /// [`Core::finish_fetch`], so it applies atomically.
    async fn process_shared(
        shared: &Mutex<Core>,
        cfg: &CrawlConfig,
        robots: &mut HashMap<String, Robots>,
        fetcher: &mut Option<Fetcher>,
        url: &str,
        depth: i64,
    ) {
        let host = authority_of(url);
        let scheme = scheme_of(url);

        // Per-host budget.
        let hrow = lock_core(shared).fr.host_row(&host);
        if cfg.per_host_budget > 0 && hrow.fetched >= cfg.per_host_budget {
            let mut c = lock_core(shared);
            c.fr.complete(url, "skipped", Some("host-budget"));
            c.stats.skipped += 1;
            return;
        }

        // Robots (per-worker cache, like the Python worker's own `self.robots`).
        if !robots.contains_key(&host) {
            let rob = fetch_robots(cfg, fetcher, &host, &scheme).await;
            lock_core(shared)
                .fr
                .set_crawl_delay(&host, rob.crawl_delay());
            robots.insert(host.clone(), rob);
        }
        let (allowed, cdelay) = match robots.get(&host) {
            Some(r) => (r.can_fetch(&path_of(url)), r.crawl_delay()),
            None => (true, None),
        };
        if cfg.respect_robots && !allowed {
            let mut c = lock_core(shared);
            c.fr.complete(url, "skipped", Some("robots"));
            c.stats.robots_blocked += 1;
            return;
        }
        let delay = cdelay.map_or(cfg.base_delay, |cd| cfg.base_delay.max(cd));
        lock_core(shared).fr.reserve_host(&host, now_secs() + delay);

        // Conditional GET from stored validators.
        let (etag, last_mod) = lock_core(shared).ix.get_validators(url);
        let extra = conditional_headers(&etag, &last_mod);

        let opts = fetch_opts(cfg, extra);
        let schemes = cfg.allowed_schemes.clone();
        let scope = cfg.scope_hosts.clone();
        let allow = move |u: &str| -> bool {
            schemes.contains(&scheme_of(u)) && in_scope(u, scope.as_deref())
        };
        // The network fetch holds NO lock — this is where workers overlap.
        let res = route_fetch(fetcher, url, &opts, Some(&allow)).await;
        // Apply the whole result atomically under one lock (shared tail).
        lock_core(shared).finish_fetch(cfg, url, depth, &host, delay, res);
    }

    #[cfg(test)]
    mod audit_regression {
        use super::*;

        fn page_of_links(n: usize) -> Extracted {
            Extracted {
                links: (0..n).map(|i| format!("/{i}")).collect(),
                ..Extracted::default()
            }
        }

        /// AUDIT REGRESSION (MEDIUM). `enqueue_links` recorded EVERY extracted
        /// edge and queued every in-scope one, whatever the page's size: a single
        /// 1.9 MB page of `<a href=/N>` produced 100 000 frontier rows and 100 000
        /// graph edges for +13 MB of RSS — and with the default `per_host_budget`
        /// of 500 pages, ~6.5 GB from one host. Nothing about scope, depth, the
        /// trap guards or federation bounds this, because the edge is recorded
        /// before any of them are consulted.
        #[test]
        fn a_page_of_a_hundred_thousand_links_is_capped_and_counted() {
            let cfg = CrawlConfig {
                scope_hosts: Some(vec!["ex.example".to_string()]),
                max_links_per_page: 1000,
                ..CrawlConfig::default()
            };
            let mut core = Core::default();
            core.enqueue_links(
                &cfg,
                "http://ex.example/p",
                "http://ex.example/p",
                &page_of_links(100_000),
                0,
                true,
            );

            assert_eq!(
                core.ix.stats().links,
                1000,
                "the link graph took every edge on the page"
            );
            assert!(
                core.fr.counts().get("queued").copied().unwrap_or(0) <= 1000,
                "the frontier queued more than the per-page cap"
            );
            assert_eq!(
                core.stats.links_dropped, 99_000,
                "the discarded links were not counted"
            );
        }

        /// An ordinary page is untouched by the cap: every link is kept, nothing
        /// is reported dropped, and the edges are the same ones as before.
        #[test]
        fn an_ordinary_page_is_unaffected() {
            let cfg = CrawlConfig {
                scope_hosts: Some(vec!["ex.example".to_string()]),
                ..CrawlConfig::default()
            };
            let mut core = Core::default();
            core.enqueue_links(
                &cfg,
                "http://ex.example/p",
                "http://ex.example/p",
                &page_of_links(120),
                0,
                true,
            );
            assert_eq!(core.ix.stats().links, 120);
            assert_eq!(core.stats.links_dropped, 0);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn content_type_routing() {
        assert!(is_html_type("text/html"));
        assert!(is_text_type("application/json"));
        assert!(!is_text_type("text/html"));
        assert_eq!(scheme_of("HTTP://x/Path?a=1"), "http");
        assert_eq!(path_of("http://x/p/q?a=1"), "/p/q?a=1");
        assert_eq!(path_of("http://x"), "/");
    }

    /// The predicates are the Python sets, member for member — including the
    /// EMPTY content type, which is an `HTML_TYPES` member there and so must be
    /// one here (a missing `Content-Type` is HTML). Pinned against the Python
    /// `crawler.HTML_TYPES` / `TEXT_TYPES` literals; the matching dispatch pin
    /// (a real no-`Content-Type` response parsed as HTML) is
    /// `tests/net_crawler.rs::missing_content_type_is_crawled_as_html`.
    #[test]
    fn type_predicates_match_python_sets() {
        for ct in [
            "text/html",
            "application/xhtml+xml",
            "application/xml",
            "text/xml",
            "", // the member the Rust used to be missing
        ] {
            assert!(is_html_type(ct), "HTML_TYPES should contain {ct:?}");
            assert!(!is_text_type(ct), "TEXT_TYPES should not contain {ct:?}");
        }
        for ct in [
            "text/plain",
            "text/markdown",
            "text/x-markdown",
            "text/csv",
            "text/tab-separated-values",
            "application/json",
            "text/x-rst",
        ] {
            assert!(is_text_type(ct), "TEXT_TYPES should contain {ct:?}");
            assert!(!is_html_type(ct), "HTML_TYPES should not contain {ct:?}");
        }
        assert!(!is_html_type(PDF_TYPE));
        assert!(!is_text_type(PDF_TYPE));
        assert!(!is_html_type("text/html; charset=utf-8")); // params are stripped upstream
    }

    #[test]
    fn internal_ip_media_guard() {
        // literal internal IP → dropped; public host → kept; hostname → kept.
        assert!(is_internal_ip_literal("127.0.0.1"));
        assert!(is_internal_ip_literal("169.254.169.254"));
        assert!(!is_internal_ip_literal("example.com"));
        assert!(!is_internal_ip_literal("8.8.8.8"));
        assert_eq!(
            public_resolved("//127.0.0.1/x", "http://a/"),
            "" // internal-IP host dropped
        );
        assert_eq!(
            public_resolved("/p", "http://example.com/a/b"),
            "http://example.com/p"
        );
        assert_eq!(public_resolved("mailto:x@y", "http://a/"), ""); // non-http dropped
    }

    #[test]
    fn trap_guard() {
        let cfg = CrawlConfig::default();
        assert!(trap_ok("http://x/a/b/c", &cfg));
        // 4 repeats of "a" exceeds the default segment_repeat_cap of 3
        assert!(!trap_ok("http://x/a/a/a/a", &cfg));
        // too many query params
        assert!(!trap_ok("http://x/p?a=1&b=2&c=3&d=4", &cfg));
    }

    #[test]
    fn default_config_content_types() {
        let cfg = CrawlConfig::default();
        assert!(cfg.content_types.contains("text/html"));
        assert!(cfg.content_types.contains("application/json"));
        assert!(!cfg.content_types.contains(PDF_TYPE));
        // Python `CrawlConfig(recrawl_interval=7 * 86400.0)`.
        assert_eq!(cfg.recrawl_interval, 7.0 * 86_400.0);
    }

    /// The recrawl scheduler: every indexed URL whose `fetched_at + interval` has
    /// passed goes back into the frontier as `queued`, with its host's spent
    /// politeness budget cleared; nothing else moves.
    ///
    /// The expected counts were taken from the real Python
    /// `Crawler.enqueue_recrawls` driven over these same four documents (it
    /// returns 2, then 3 at `interval=1.0`, and leaves `hosts` for `a.example` at
    /// `next_time=0, fetched=0`). The host-row half of that is asserted in
    /// `frontier::tests::requeue_for_recrawl_resets_entry_and_host`, which can
    /// take the frontier mutably.
    #[cfg(feature = "net")]
    #[test]
    fn enqueue_recrawls_requeues_only_due_docs() {
        use crate::index::DocFields;

        let mut cr = Crawler::new(CrawlConfig {
            recrawl_interval: 100.0,
            ..CrawlConfig::default()
        });
        for (url, host, fetched_at) in [
            ("http://a.example/old", "a.example", 1_000.0), // due at now=2000
            ("http://a.example/older", "a.example", 500.0), // due
            ("http://b.example/fresh", "b.example", 1_990.0), // not due
            ("http://b.example/never", "b.example", 0.0),   // never fetched
        ] {
            cr.index_mut().upsert_document(
                url,
                DocFields {
                    title: "t",
                    body: "b",
                    host,
                    fetched_at,
                    http_status: 200,
                    ..DocFields::default()
                },
            );
        }

        assert_eq!(cr.enqueue_recrawls(None, 2_000.0), 2);
        assert!(cr.frontier().seen("http://a.example/old"));
        assert!(cr.frontier().seen("http://a.example/older"));
        assert!(!cr.frontier().seen("http://b.example/fresh"));
        assert!(!cr.frontier().seen("http://b.example/never"));
        assert_eq!(cr.frontier().counts().get("queued"), Some(&2));

        // An explicit interval overrides the config's: at 1s everything fetched
        // is due (the never-fetched doc still is not).
        assert_eq!(cr.enqueue_recrawls(Some(1.0), 2_000.0), 3);
        assert_eq!(cr.frontier().counts().get("queued"), Some(&3));
        assert!(!cr.frontier().seen("http://b.example/never"));

        // An empty index has nothing due — the reason the `--recrawl` CLI path
        // restores its snapshot before calling this.
        let mut fresh = Crawler::new(CrawlConfig::default());
        assert_eq!(fresh.enqueue_recrawls(None, 2_000.0), 0);
    }
}
