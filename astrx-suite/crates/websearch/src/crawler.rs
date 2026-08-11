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
//! Deferred (documented): the image/video verticals (need htmlparse stage 2),
//! PDF extraction (`pdftext`), multi-worker `MultiCrawler`, federation sharding
//! (single-node owns all hosts), and per-redirect-hop robots re-checking (each
//! hop is still re-checked for scheme + scope + the SSRF internal-IP gate; robots
//! is enforced on the leased URL).

use crate::canonical::{canonicalize, host_of, max_segment_repeat, path_depth, query_param_count};
use crate::ssrf::ip_is_internal;
use std::collections::HashSet;
use std::time::Duration;

/// HTML-ish content types (parsed with the HTML extractor). Empty is treated as
/// HTML too, handled at the call site.
const HTML_TYPES: &[&str] = &[
    "text/html",
    "application/xhtml+xml",
    "application/xml",
    "text/xml",
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
/// The PDF media type (extraction deferred to `pdftext`).
pub const PDF_TYPE: &str = "application/pdf";

/// True for a plain-text-like content type.
#[must_use]
pub fn is_text_type(ct: &str) -> bool {
    TEXT_TYPES.contains(&ct)
}

/// True for an HTML-ish content type.
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

/// Crawl configuration (single-node; the fleet/federation knobs are omitted).
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
    /// Lease duration (seconds).
    pub lease_seconds: f64,
    /// Refuse hosts resolving to internal addresses (the SSRF guard).
    pub block_internal_ips: bool,
    /// Authorities exempt from the internal-address block.
    pub allow_hosts: Vec<String>,
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
            lease_seconds: 120.0,
            block_internal_ips: true,
            allow_hosts: Vec::new(),
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
}

#[cfg(feature = "net")]
pub use net_impl::Crawler;

#[cfg(feature = "net")]
mod net_impl {
    use super::{is_text_type, path_of, scheme_of, trap_ok, CrawlConfig, CrawlStats, PDF_TYPE};
    use crate::canonical::{authority_of, canonicalize, host_of, in_scope, join};
    use crate::fetcher::{fetch, FetchOpts};
    use crate::frontier::Frontier;
    use crate::htmlparse::{self, guess_lang, Extracted};
    use crate::httpclient::decode_body;
    use crate::index::{content_hash, DocFields, Index};
    use crate::robots::{parse as parse_robots, Robots};
    use std::collections::HashMap;
    use std::time::{SystemTime, UNIX_EPOCH};

    fn now_secs() -> f64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_secs_f64())
            .unwrap_or(0.0)
    }

    /// The crawl engine over a [`Frontier`] + an [`Index`], driving the
    /// SSRF-checked fetch.
    pub struct Crawler {
        /// The crawl configuration.
        pub cfg: CrawlConfig,
        fr: Frontier,
        ix: Index,
        robots: HashMap<String, Robots>,
        stats: CrawlStats,
        pages_fetched: u64,
    }

    impl Crawler {
        /// A crawler with the given config over a fresh frontier + index.
        #[must_use]
        pub fn new(cfg: CrawlConfig) -> Self {
            Crawler {
                cfg,
                fr: Frontier::new(),
                ix: Index::new(),
                robots: HashMap::new(),
                stats: CrawlStats::default(),
                pages_fetched: 0,
            }
        }

        /// The document index (to read results after a crawl).
        #[must_use]
        pub fn index(&self) -> &Index {
            &self.ix
        }

        /// The frontier (to inspect queue state).
        #[must_use]
        pub fn frontier(&self) -> &Frontier {
            &self.fr
        }

        /// The run statistics.
        #[must_use]
        pub fn stats(&self) -> &CrawlStats {
            &self.stats
        }

        /// Seed the frontier; returns how many URLs were newly queued.
        pub fn add_seeds(&mut self, seeds: &[&str]) -> usize {
            let mut added = 0;
            for s in seeds {
                let Some(u) = canonicalize(s, None) else {
                    continue;
                };
                if self.cfg.scheme_allowed(&u) && self.fr.add(&u, &authority_of(&u), 0) {
                    added += 1;
                }
            }
            added
        }

        /// Run the crawl loop until the page budget is spent or the frontier
        /// drains. Returns the run statistics.
        pub async fn run(&mut self, max_pages: Option<u64>) -> CrawlStats {
            let budget = max_pages.unwrap_or(self.cfg.total_budget);
            self.fr.reclaim(now_secs());
            while self.pages_fetched < budget {
                let now = now_secs();
                self.fr.reclaim(now);
                let hb = self.host_budget();
                let leased = self.fr.lease(now, self.cfg.lease_seconds, hb);
                match leased {
                    Some(l) => self.process(&l.url, l.depth).await,
                    None => {
                        if !self.fr.has_queued() {
                            break;
                        }
                        match self.fr.next_ready_time(hb) {
                            Some(t) if t > now => {
                                let wait = (t - now).clamp(0.0, 5.0);
                                tokio::time::sleep(std::time::Duration::from_secs_f64(wait)).await;
                            }
                            _ => break, // queued remain but nothing leasable
                        }
                    }
                }
            }
            self.stats.clone()
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
            let robots_url = format!("{scheme}://{authority}/robots.txt");
            let opts = FetchOpts {
                user_agent: self.cfg.user_agent.clone(),
                timeout: self.cfg.timeout,
                max_bytes: 262_144,
                max_redirects: 3,
                block_internal: self.cfg.block_internal_ips,
                allow_hosts: self.cfg.allow_hosts.clone(),
                ..FetchOpts::default()
            };
            // robots fetch gate: scheme + scope only (NOT robots, which would
            // recurse); the internal-IP denylist is enforced by the fetch itself.
            let schemes = self.cfg.allowed_schemes.clone();
            let scope = self.cfg.scope_hosts.clone();
            let allow = move |u: &str| -> bool {
                schemes.contains(&scheme_of(u)) && in_scope(u, scope.as_deref())
            };
            let res = fetch(&robots_url, &opts, Some(&allow)).await;
            let text = if res.error.is_none() && res.status == 200 {
                decode_body(&res.body, res.charset.as_deref())
            } else {
                String::new() // 4xx/5xx/error → empty → allow all
            };
            let rob = parse_robots(&text, &self.cfg.robots_agent);
            self.fr.set_crawl_delay(authority, rob.crawl_delay());
            self.robots.insert(authority.to_string(), rob);
        }

        async fn process(&mut self, url: &str, depth: i64) {
            let host = authority_of(url);
            let scheme = scheme_of(url);

            // Per-host budget.
            let hrow = self.fr.host_row(&host);
            if self.cfg.per_host_budget > 0 && hrow.fetched >= self.cfg.per_host_budget {
                self.fr.complete(url, "skipped", Some("host-budget"));
                self.stats.skipped += 1;
                return;
            }

            // Robots (always fetched for Crawl-delay; enforced iff respect_robots).
            self.ensure_robots(&host, &scheme).await;
            let (allowed, cdelay) = match self.robots.get(&host) {
                Some(r) => (r.can_fetch(&path_of(url)), r.crawl_delay()),
                None => (true, None),
            };
            if self.cfg.respect_robots && !allowed {
                self.fr.complete(url, "skipped", Some("robots"));
                self.stats.robots_blocked += 1;
                return;
            }
            let delay = cdelay.map_or(self.cfg.base_delay, |cd| self.cfg.base_delay.max(cd));
            self.fr.reserve_host(&host, now_secs() + delay);

            // Conditional GET from stored validators.
            let (etag, last_mod) = self.ix.get_validators(url);
            let mut extra: Vec<(String, String)> = Vec::new();
            if !etag.is_empty() {
                extra.push(("If-None-Match".to_string(), etag));
            }
            if !last_mod.is_empty() {
                extra.push(("If-Modified-Since".to_string(), last_mod));
            }

            self.pages_fetched += 1;
            let opts = FetchOpts {
                user_agent: self.cfg.user_agent.clone(),
                timeout: self.cfg.timeout,
                max_bytes: self.cfg.max_bytes,
                max_redirects: self.cfg.max_redirects,
                block_internal: self.cfg.block_internal_ips,
                allow_hosts: self.cfg.allow_hosts.clone(),
                extra_headers: extra,
                ..FetchOpts::default()
            };
            let schemes = self.cfg.allowed_schemes.clone();
            let scope = self.cfg.scope_hosts.clone();
            let allow = |u: &str| -> bool {
                schemes.contains(&scheme_of(u)) && in_scope(u, scope.as_deref())
            };
            let res = fetch(url, &opts, Some(&allow)).await;
            self.fr.note_fetch(&host, now_secs() + delay);
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
            if !ctype.is_empty() && !self.cfg.content_types.contains(&ctype) {
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

            // PDF extraction is deferred (pdftext); such a page is recorded done.
            if ctype == PDF_TYPE {
                self.fr.complete(url, "done", Some("pdf-deferred"));
                return;
            }
            let body_text = decode_body(&res.body, res.charset.as_deref());
            let ex = if is_text_type(&ctype) {
                let mut e = Extracted {
                    text: body_text.trim().to_string(),
                    ..Extracted::default()
                };
                e.lang = Some(guess_lang(&e.text, None));
                e
            } else {
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
                    && schemes.contains(&scheme_of(&cn))
                    && in_scope(&cn, scope.as_deref())
                {
                    let follow = !ex.nofollow();
                    self.enqueue_links(&final_url, &base, &ex, depth, follow);
                    self.fr.add(&cn, &authority_of(&cn), depth);
                    self.fr.complete(url, "done", Some("canonical"));
                    return;
                }
            }

            let follow = !ex.nofollow();
            self.enqueue_links(&final_url, &base, &ex, depth, follow);

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
            self.ix.upsert_document(
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
            self.stats.indexed += 1;
            self.fr.complete(url, "done", None);
        }

        fn enqueue_links(
            &mut self,
            src_url: &str,
            base: &str,
            ex: &Extracted,
            depth: i64,
            follow: bool,
        ) {
            let mut edges: Vec<(String, bool)> = Vec::new();
            let mut seen: HashMap<String, ()> = HashMap::new();
            for href in &ex.links {
                let Some(tgt) = canonicalize(href, Some(base)) else {
                    continue;
                };
                if !self.cfg.scheme_allowed(&tgt) || seen.contains_key(&tgt) {
                    continue;
                }
                seen.insert(tgt.clone(), ());
                let internal = in_scope(&tgt, self.cfg.scope_hosts.as_deref());
                edges.push((tgt.clone(), internal));
                if follow && internal && depth < self.cfg.max_depth && trap_ok(&tgt, &self.cfg) {
                    self.fr.add(&tgt, &authority_of(&tgt), depth + 1);
                }
            }
            if !edges.is_empty() {
                self.ix.add_links(src_url, &edges);
            }
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
    }
}
