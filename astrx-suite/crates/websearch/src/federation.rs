//! Zero-dependency horizontal federation for websearch — a faithful port of the
//! Python `websearch.federation`.
//!
//! The clearnet crawler+index is the only part of the suite whose corpus outgrows
//! a single node, and it shards cleanly: assign every registrable HOST to exactly
//! one shard by *rendezvous* (HRW) hashing. Because a host lives on one shard and
//! only one,
//!
//!   * per-host politeness needs no cross-node coordination — each shard is the
//!     sole crawler of its hosts, so the single-node politeness clock is already
//!     fleet-correct, and
//!   * URL-seen dedup is free — the same URL can never be enqueued on two shards.
//!
//! The [pure sharding core] — [`norm_host`], [`shard_for`], [`owns`] — depends
//! only on [`crawlcore::hash::sha256`] and is cross-checked **byte-identical** to
//! the Python reference (see `tests/xcheck_federation.rs`). Behind the `net`
//! feature a stateless [`federated_search`] aggregator fans a query out to every
//! shard's JSON API in parallel and merges the answers: cross-host near-duplicate
//! mirrors are collapsed with the very same SimHash used single-node (shards
//! expose it in their JSON), each shard gets a wall-clock deadline, and the
//! response is flagged *partial* when a shard is slow or down.
//!
//! Security posture is unchanged. The aggregator only ever contacts the
//! operator-configured shard base URLs (never a user-supplied address); the
//! query is URL-encoded into a fixed base; and every shard response is size- and
//! time-bounded. The shard servers keep their own SSRF-checked crawl path.
//!
//! [pure sharding core]: norm_host

use crawlcore::hash::sha256;

// --------------------------------------------------------------------------
// Pure sharding core (only crawlcore::hash — no third-party deps, no cycles).
// --------------------------------------------------------------------------

/// Normalise a host to the sharding key: lower-case, no port, no trailing dot.
///
/// Mirrors Python `federation.norm_host`: a bracketed `[ipv6]` literal is kept
/// verbatim (its trailing `:port` dropped), a single `name:port` colon is
/// stripped, and trailing dots are removed.
#[must_use]
pub fn norm_host(host: &str) -> String {
    let h = host.trim().to_lowercase();
    if h.is_empty() {
        return String::new();
    }
    // `[ipv6](:port)?` -> keep the bracketed literal.
    if h.starts_with('[') {
        if let Some(end) = h.find(']') {
            return h[..=end].to_string();
        }
    }
    // `name:port` -> strip the port (only a single colon, i.e. not a raw IPv6).
    let h = if h.matches(':').count() == 1 {
        h.split(':').next().unwrap_or("").to_string()
    } else {
        h
    };
    h.trim_end_matches('.').to_string()
}

/// Return the shard id that owns `host` (rendezvous / HRW hashing), or `None`
/// when no shards are configured (single-node mode).
///
/// For each shard id we hash `sha256(shard_id \x00 host)` and pick the shard
/// with the greatest digest, comparing the raw 32-byte digests lexicographically
/// exactly as the Python reference compares the `bytes` objects — so the first
/// shard wins a tie. HRW gives an even split and, when a shard is added or
/// removed, reassigns only ~1/N of hosts (no global rebalance).
#[must_use]
pub fn shard_for(host: &str, shards: &[String]) -> Option<String> {
    if shards.is_empty() {
        return None;
    }
    let key = norm_host(host);
    let mut best: Option<([u8; 32], &String)> = None;
    for sid in shards {
        let mut buf = Vec::with_capacity(sid.len() + 1 + key.len());
        buf.extend_from_slice(sid.as_bytes());
        buf.push(0);
        buf.extend_from_slice(key.as_bytes());
        let digest = sha256(&buf);
        let better = match &best {
            Some((best_digest, _)) => digest > *best_digest,
            None => true,
        };
        if better {
            best = Some((digest, sid));
        }
    }
    best.map(|(_, sid)| sid.clone())
}

/// True iff shard `my_id` owns `host` under HRW over `shards`.
///
/// With no shard set configured (single-node mode) or no id, everything is
/// owned, so the crawler behaves exactly as before. Mirrors Python
/// `federation.owns`.
#[must_use]
pub fn owns(host: &str, my_id: Option<&str>, shards: &[String]) -> bool {
    let id = match my_id {
        Some(id) => id,
        None => return true,
    };
    if shards.is_empty() {
        return true;
    }
    shard_for(host, shards).as_deref() == Some(id)
}

// --------------------------------------------------------------------------
// Aggregator: scatter-gather a query across shard base URLs (net feature).
// --------------------------------------------------------------------------

#[cfg(feature = "net")]
pub use net_impl::{
    federated_search, normalize_bases, FederatedOpts, FederatedResponse, ShardOutcome, ShardResult,
    DEFAULT_TIMEOUT, MAX_JSON_BYTES, MAX_SHARD_LIMIT, OVER_FETCH,
};

#[cfg(feature = "net")]
mod net_impl {
    use crate::fetcher::{fetch, FetchOpts};
    use crate::ranking::SIMHASH_HAMMING;
    use crawlcore::dedup::near;
    use crawlcore::json::{parse as json_parse, Value};
    use crawlcore::urlparse::{urlencode, urlsplit, urlunsplit};
    use std::cmp::Ordering;
    use std::collections::HashMap;
    use std::time::{Duration, Instant};
    use tokio::task::JoinSet;

    /// Per-shard wall-clock deadline, in seconds (Python `DEFAULT_TIMEOUT`).
    pub const DEFAULT_TIMEOUT: f64 = 4.0;
    /// Hard cap on a shard's JSON response body (Python `MAX_JSON_BYTES`).
    pub const MAX_JSON_BYTES: usize = 4_000_000;
    /// Pull ~this * `page_size` candidates from each shard for the merge.
    pub const OVER_FETCH: usize = 3;
    /// Never ask a shard for more than it will serve (Python `MAX_SHARD_LIMIT`).
    pub const MAX_SHARD_LIMIT: usize = 200;

    /// One result row parsed from a shard's `/api/search` JSON — the shape this
    /// crate's own [`serve`](crate::serve) emits (`result_json`).
    #[derive(Clone, Debug, PartialEq)]
    pub struct ShardResult {
        /// Result URL (always non-empty; rows without one are dropped).
        pub url: String,
        /// Title.
        pub title: String,
        /// Host — the cross-host key for the near-duplicate collapse.
        pub host: String,
        /// Query-biased snippet (HTML).
        pub snippet_html: String,
        /// Final ranking score.
        pub score: f64,
        /// Fetch timestamp, if present.
        pub fetched_at: Option<f64>,
        /// Guessed language, if present.
        pub lang: Option<String>,
        /// 64-bit near-dup fingerprint (signed), carried losslessly for the merge.
        pub simhash: i64,
    }

    /// The outcome of querying one shard.
    #[derive(Clone, Copy, Debug, PartialEq, Eq)]
    pub enum ShardOutcome {
        /// The shard answered with well-formed JSON.
        Ok,
        /// The shard errored, timed out, or returned unusable JSON.
        Error,
    }

    /// Knobs for a [`federated_search`] (Python's keyword arguments).
    #[derive(Clone, Debug)]
    pub struct FederatedOpts {
        /// 1-based page number.
        pub page: usize,
        /// Results per page.
        pub page_size: usize,
        /// Per-shard wall-clock deadline.
        pub timeout: Duration,
        /// Over-fetch factor for the per-shard candidate window.
        pub over_fetch: usize,
        /// Cross-host near-duplicate Hamming threshold; negative disables the
        /// SimHash collapse (Python's `near_threshold=None` -> `SIMHASH_HAMMING`).
        pub near_threshold: i32,
    }

    impl Default for FederatedOpts {
        fn default() -> Self {
            FederatedOpts {
                page: 1,
                page_size: 10,
                timeout: Duration::from_secs_f64(DEFAULT_TIMEOUT),
                over_fetch: OVER_FETCH,
                near_threshold: SIMHASH_HAMMING as i32,
            }
        }
    }

    /// The merged, ranked answer of a scatter-gather across shards.
    #[derive(Clone, Debug, PartialEq)]
    pub struct FederatedResponse {
        /// The requested page slice of merged results.
        pub results: Vec<ShardResult>,
        /// Pager bound: `min(summed shard totals, merged candidate count)`.
        pub total: usize,
        /// True if any shard failed or was slow (matches may be missing).
        pub partial: bool,
        /// Per-base outcome, in the configured (normalised) base order.
        pub shards: Vec<(String, ShardOutcome)>,
        /// Number of shard base URLs queried.
        pub shard_count: usize,
        /// Number of shards that answered ok.
        pub ok_count: usize,
        /// Wall-clock seconds the fan-out took.
        pub elapsed_seconds: f64,
        /// 1-based page.
        pub page: usize,
        /// Page size.
        pub page_size: usize,
    }

    /// Validate + normalise operator-provided shard base URLs.
    ///
    /// Accepts only `http(s)://host[:port][/path]` (the trusted internal fleet
    /// endpoints); anything else is dropped. Returns a de-duplicated list with any
    /// trailing slash removed. Mirrors Python `federation.normalize_bases`.
    #[must_use]
    pub fn normalize_bases(shards: &[String]) -> Vec<String> {
        let mut out: Vec<String> = Vec::new();
        for raw in shards {
            let base = raw.trim();
            if base.is_empty() {
                continue;
            }
            let parts = urlsplit(base, "");
            if (parts.scheme != "http" && parts.scheme != "https") || parts.netloc.is_empty() {
                continue;
            }
            let clean = urlunsplit(
                &parts.scheme,
                &parts.netloc,
                parts.path.trim_end_matches('/'),
                "",
                "",
            );
            if !out.contains(&clean) {
                out.push(clean);
            }
        }
        out
    }

    /// Parse the exact `i64` a JSON value carries (an integer literal is kept
    /// losslessly; a float leaf is truncated toward zero), or `0`. Mirrors the
    /// Python `int(d.get("simhash") or 0)` coercion.
    fn as_i64_lossy(v: Option<&Value>) -> i64 {
        match v {
            Some(val) => val
                .as_i64()
                .or_else(|| val.as_f64().map(|n| n as i64))
                .unwrap_or(0),
            None => 0,
        }
    }

    /// Rebuild a [`ShardResult`] from a shard JSON row, dropping anything that is
    /// not a well-formed object with a non-empty `url` (Python `query_shard`'s
    /// `isinstance(r, dict) and r.get("url")` filter).
    fn row_from(v: &Value) -> Option<ShardResult> {
        v.as_object()?;
        let url = v.get("url").and_then(Value::as_str).unwrap_or_default();
        if url.is_empty() {
            return None;
        }
        Some(ShardResult {
            url: url.to_string(),
            title: v
                .get("title")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_string(),
            host: v
                .get("host")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_string(),
            snippet_html: v
                .get("snippet_html")
                .and_then(Value::as_str)
                .unwrap_or_default()
                .to_string(),
            score: v.get("score").and_then(Value::as_f64).unwrap_or(0.0),
            fetched_at: v.get("fetched_at").and_then(Value::as_f64),
            lang: v.get("lang").and_then(Value::as_str).map(str::to_string),
            simhash: as_i64_lossy(v.get("simhash")),
        })
    }

    /// Query one shard's `/api/search`. Returns `(Some(rows), total)`, or
    /// `(None, 0)` on any error (the caller records a failed shard). Mirrors
    /// Python `federation.query_shard` + `_get_json`.
    async fn query_shard(
        base: String,
        q: String,
        limit: usize,
        timeout: Duration,
    ) -> (Option<Vec<ShardResult>>, u64) {
        let query = urlencode(&[
            ("q".to_string(), q),
            ("limit".to_string(), limit.to_string()),
        ]);
        let url = format!("{}/api/search?{}", base.trim_end_matches('/'), query);
        let opts = FetchOpts {
            user_agent: "astrx-websearch-fed/1.0".to_string(),
            timeout,
            max_bytes: MAX_JSON_BYTES,
            max_redirects: 0,
            accept_encoding: "identity".to_string(),
            // Shard base URLs are trusted operator config (typically internal
            // fleet addresses), so the SSRF gate is off here — but the response
            // stays size- (`max_bytes`) and time- (`timeout`) bounded.
            block_internal: false,
            allow_hosts: Vec::new(),
            extra_headers: vec![("Accept".to_string(), "application/json".to_string())],
        };
        let res = fetch(&url, &opts, None).await;
        if res.error.is_some() || res.status != 200 {
            return (None, 0);
        }
        let body = String::from_utf8_lossy(&res.body);
        let payload = match json_parse(&body) {
            Ok(p) => p,
            Err(_) => return (None, 0),
        };
        if payload.as_object().is_none() {
            return (None, 0);
        }
        let total = as_i64_lossy(payload.get("total"));
        let total = if total < 0 { 0 } else { total as u64 };
        let rows = match payload.get("results").and_then(Value::as_array) {
            Some(arr) => arr.iter().filter_map(row_from).collect(),
            None => Vec::new(),
        };
        (Some(rows), total)
    }

    /// Merge shard rows: exact-URL dedup (keep the highest score), then a
    /// cross-host SimHash collapse identical to the single-node
    /// `_collapse_near_dups`. Mirrors Python `federation._merge`.
    fn merge(rows: Vec<ShardResult>, near_threshold: i32) -> Vec<ShardResult> {
        // Exact-URL dedup keeping the highest score, in first-seen order (a
        // defensive no-op when hosts don't overlap, but guards a misconfigured
        // overlapping shard set).
        let mut order: Vec<String> = Vec::new();
        let mut by_url: HashMap<String, ShardResult> = HashMap::new();
        for d in rows {
            match by_url.get(&d.url) {
                Some(prev) if prev.score >= d.score => {}
                Some(_) => {
                    by_url.insert(d.url.clone(), d);
                }
                None => {
                    order.push(d.url.clone());
                    by_url.insert(d.url.clone(), d);
                }
            }
        }
        let mut items: Vec<ShardResult> = order
            .into_iter()
            .filter_map(|u| by_url.remove(&u))
            .collect();
        // Stable sort by descending score — ties keep first-seen order, exactly
        // like Python's `sorted(..., reverse=True)`.
        items.sort_by(|a, b| b.score.partial_cmp(&a.score).unwrap_or(Ordering::Equal));
        if near_threshold < 0 {
            return items;
        }
        let threshold = near_threshold as u32;
        let mut kept: Vec<ShardResult> = Vec::new();
        let mut seen: Vec<(i64, String)> = Vec::new(); // (simhash, host) already kept
        for d in items {
            let h = d.simhash;
            if h != 0 {
                let is_mirror = seen
                    .iter()
                    .any(|(kh, khost)| *khost != d.host && near(h as u64, *kh as u64, threshold));
                if is_mirror {
                    continue; // a mirror of something already shown
                }
                seen.push((h, d.host.clone()));
            }
            kept.push(d);
        }
        kept
    }

    /// Scatter-gather `q` across shard base URLs and merge.
    ///
    /// Returns the requested page slice, a pager `total`, a `partial` flag (a
    /// shard failed or was slow), the per-base status, and timing. Mirrors Python
    /// `federation.federated_search`.
    pub async fn federated_search(
        shards: &[String],
        q: &str,
        opts: &FederatedOpts,
    ) -> FederatedResponse {
        let started = Instant::now();
        let bases = normalize_bases(shards);

        // Enough candidates from each shard to fill the requested page post-merge.
        let want = opts.page.saturating_mul(opts.page_size).saturating_add(
            opts.page_size
                .saturating_mul(opts.over_fetch.saturating_sub(1)),
        );
        let limit = opts
            .page_size
            .max(want.min(MAX_SHARD_LIMIT).max(opts.page_size));

        let mut all_results: Vec<ShardResult> = Vec::new();
        let mut sum_total: u64 = 0;
        let mut status: Vec<Option<ShardOutcome>> = vec![None; bases.len()];

        if !q.is_empty() && !bases.is_empty() {
            let mut set: JoinSet<(usize, Option<Vec<ShardResult>>, u64)> = JoinSet::new();
            for (i, base) in bases.iter().enumerate() {
                let base = base.clone();
                let q = q.to_string();
                let timeout = opts.timeout;
                set.spawn(async move {
                    // A slow shard is bounded by the same wall-clock deadline the
                    // fetch uses, so the whole gather never exceeds it.
                    match tokio::time::timeout(timeout, query_shard(base, q, limit, timeout)).await
                    {
                        Ok((res, total)) => (i, res, total),
                        Err(_) => (i, None, 0),
                    }
                });
            }
            while let Some(joined) = set.join_next().await {
                if let Ok((i, res, total)) = joined {
                    match res {
                        Some(rows) => {
                            status[i] = Some(ShardOutcome::Ok);
                            all_results.extend(rows);
                            sum_total = sum_total.saturating_add(total);
                        }
                        None => status[i] = Some(ShardOutcome::Error),
                    }
                }
            }
            // A task that panicked leaves its slot unset; Python records every
            // queried base, so treat the gap as a failed shard.
            for slot in &mut status {
                if slot.is_none() {
                    *slot = Some(ShardOutcome::Error);
                }
            }
        }

        let merged = merge(all_results, opts.near_threshold);
        // Never advertise (or page past) more than the merged window can serve.
        let total = merged.len().min(sum_total as usize);
        let lo = opts.page.saturating_sub(1).saturating_mul(opts.page_size);
        let hi = lo.saturating_add(opts.page_size);
        let results = if lo >= merged.len() {
            Vec::new()
        } else {
            merged[lo..hi.min(merged.len())].to_vec()
        };

        let ok_count = status
            .iter()
            .filter(|s| **s == Some(ShardOutcome::Ok))
            .count();
        let any_error = status.contains(&Some(ShardOutcome::Error));
        let any_status = status.iter().any(Option::is_some);
        let partial = any_error || (!bases.is_empty() && !any_status);
        let shard_status: Vec<(String, ShardOutcome)> = bases
            .iter()
            .zip(status.iter())
            .filter_map(|(b, s)| s.map(|st| (b.clone(), st)))
            .collect();

        FederatedResponse {
            results,
            total,
            partial,
            shards: shard_status,
            shard_count: bases.len(),
            ok_count,
            elapsed_seconds: started.elapsed().as_secs_f64(),
            page: opts.page,
            page_size: opts.page_size,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn norm_host_basics() {
        assert_eq!(norm_host("Example.COM"), "example.com");
        assert_eq!(norm_host("example.com."), "example.com");
        assert_eq!(norm_host("EXAMPLE.com:8080"), "example.com");
        assert_eq!(norm_host(""), "");
        assert_eq!(norm_host("[2001:db8::1]:443"), "[2001:db8::1]");
        assert_eq!(norm_host("a:b:c"), "a:b:c"); // two colons -> not a port
    }

    #[test]
    fn empty_shards_is_single_node() {
        assert_eq!(shard_for("example.com", &[]), None);
        assert!(owns("example.com", None, &[]));
        assert!(owns("example.com", Some("s0"), &[]));
        // None id -> owns everything even with a shard set configured.
        assert!(owns("example.com", None, &["s0".into(), "s1".into()]));
    }

    #[test]
    fn hrw_partitions_and_is_stable_per_host() {
        let shards: Vec<String> = vec!["s0".into(), "s1".into(), "s2".into()];
        let owner = shard_for("example.com", &shards).unwrap();
        // Exactly one shard owns it, and `owns` agrees with `shard_for`.
        let owners: Vec<bool> = shards
            .iter()
            .map(|s| owns("example.com", Some(s), &shards))
            .collect();
        assert_eq!(owners.iter().filter(|b| **b).count(), 1);
        assert!(owns("example.com", Some(&owner), &shards));
        // Port/case only change the key via norm_host, so routing is identical.
        assert_eq!(
            shard_for("EXAMPLE.COM:443", &shards),
            shard_for("example.com", &shards)
        );
    }
}
