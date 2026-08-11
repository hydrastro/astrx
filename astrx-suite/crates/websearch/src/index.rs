//! The document store + link graph — a dependency-free port of the store core of
//! the Python `websearch.index` (which is SQLite + FTS5).
//!
//! The store the crawler writes to and the search reads from: one [`Document`]
//! per indexed page (upsert by URL, returning a stable rowid), `content_hash`
//! exact-dup detection, conditional-GET validators (`etag` / `last_modified`),
//! the recrawl due-list, the `(src → dst)` link graph with incoming-link counts,
//! index statistics, and the offline ranking signals — [`Index::compute_pagerank`]
//! (internal-graph PageRank-lite → `doc.rank`) and
//! [`Index::compute_host_authority`] (cross-domain host PageRank → `host_authority`
//! + `doc.host_rank`), driven together by [`Index::finalize`]. `content_hash`
//! (SHA-256 over crawlcore), the store behaviour, and the PageRank/host-authority
//! scores are cross-checked byte-identical to Python (`tests/xcheck_index.rs`,
//! `tests/xcheck_pagerank.rs`).
//!
//! **Deferred (documented):** the FTS5 inverted-index BM25 search lives in
//! [`crate::ranking`] (a behaviourally-faithful hand-rolled Okapi stand-in, since
//! the stdlib has no FTS5); the image/video vertical search, `more_like_this`, and
//! vocabulary/typeahead await htmlparse stage 2 / the suggest module.

use crate::canonical::host_of;
use crawlcore::hash::{sha256, to_hex};
use std::collections::{BTreeMap, HashMap};

/// The content hash used for exact-duplicate detection: SHA-256 over each part
/// followed by a NUL separator (matching the Python `content_hash(*parts)`).
#[must_use]
pub fn content_hash(parts: &[&str]) -> String {
    let mut buf = Vec::new();
    for p in parts {
        buf.extend_from_slice(p.as_bytes());
        buf.push(0);
    }
    to_hex(&sha256(&buf))
}

/// One indexed document.
#[derive(Clone, Debug, PartialEq)]
pub struct Document {
    /// Stable rowid (assigned in insertion order, starting at 1).
    pub id: i64,
    /// Canonical URL (unique).
    pub url: String,
    /// Page title.
    pub title: String,
    /// Meta description.
    pub description: String,
    /// Visible body text.
    pub body: String,
    /// Host.
    pub host: String,
    /// Guessed language.
    pub lang: String,
    /// When the page was last fetched (epoch seconds).
    pub fetched_at: f64,
    /// The `content_hash` of (title, description, body).
    pub content_hash: String,
    /// The HTTP status recorded.
    pub http_status: i64,
    /// Incoming internal-link count (from [`Index::recompute_incoming`]).
    pub incoming: i64,
    /// Internal PageRank-lite (set by the ranking pass; 0 until then).
    pub rank: f64,
    /// Cross-domain host authority (set by the ranking pass; 0 until then).
    pub host_rank: f64,
    /// Conditional-GET `ETag`.
    pub etag: String,
    /// Conditional-GET `Last-Modified`.
    pub last_modified: String,
    /// Response content type.
    pub content_type: String,
    /// 64-bit near-duplicate SimHash (stored signed).
    pub simhash: i64,
}

/// Fields for [`Index::upsert_document`] (mirrors the Python keyword args).
#[derive(Clone, Debug, Default)]
pub struct DocFields<'a> {
    /// Page title.
    pub title: &'a str,
    /// Meta description.
    pub description: &'a str,
    /// Visible body text.
    pub body: &'a str,
    /// Host (empty → derived from the URL by the caller).
    pub host: &'a str,
    /// Guessed language.
    pub lang: &'a str,
    /// Fetch timestamp (epoch seconds).
    pub fetched_at: f64,
    /// Precomputed content hash; `None` → computed from title/description/body.
    pub content_hash: Option<String>,
    /// HTTP status.
    pub http_status: i64,
    /// `ETag` validator.
    pub etag: &'a str,
    /// `Last-Modified` validator.
    pub last_modified: &'a str,
    /// Content type.
    pub content_type: &'a str,
    /// Signed 64-bit SimHash.
    pub simhash: i64,
}

/// Index statistics (the scalar + top-N breakdowns for the about/stats page).
#[derive(Clone, Debug, PartialEq)]
pub struct Stats {
    /// Number of documents.
    pub docs: usize,
    /// Number of distinct hosts.
    pub hosts: usize,
    /// Number of `(src, dst)` link edges.
    pub links: usize,
    /// Oldest `fetched_at` (> 0), if any.
    pub oldest: Option<f64>,
    /// Newest `fetched_at` (> 0), if any.
    pub newest: Option<f64>,
    /// Top hosts by document count (desc; ties by host asc), up to 10.
    pub top_hosts: Vec<(String, usize)>,
    /// Top languages by document count (desc; ties by lang asc), up to 10.
    pub languages: Vec<(String, usize)>,
}

/// A dependency-free document store + link graph.
#[derive(Default)]
pub struct Index {
    docs: BTreeMap<i64, Document>,
    url_to_id: HashMap<String, i64>,
    links: HashMap<(String, String), bool>, // (src, dst) → internal
    host_authority: HashMap<String, f64>,   // cross-domain host PageRank (0..1)
    next_id: i64,
}

impl Index {
    /// A fresh, empty index.
    #[must_use]
    pub fn new() -> Self {
        Index {
            next_id: 1,
            ..Index::default()
        }
    }

    /// Insert or update the document for `url`; returns its rowid.
    pub fn upsert_document(&mut self, url: &str, f: DocFields) -> i64 {
        let chash = f
            .content_hash
            .clone()
            .unwrap_or_else(|| content_hash(&[f.title, f.description, f.body]));
        if let Some(&id) = self.url_to_id.get(url) {
            let d = self.docs.get_mut(&id).expect("indexed doc exists");
            d.title = f.title.to_string();
            d.description = f.description.to_string();
            d.body = f.body.to_string();
            d.host = f.host.to_string();
            d.lang = f.lang.to_string();
            d.fetched_at = f.fetched_at;
            d.content_hash = chash;
            d.http_status = f.http_status;
            d.etag = f.etag.to_string();
            d.last_modified = f.last_modified.to_string();
            d.content_type = f.content_type.to_string();
            d.simhash = f.simhash;
            id
        } else {
            let id = self.next_id;
            self.next_id += 1;
            self.docs.insert(
                id,
                Document {
                    id,
                    url: url.to_string(),
                    title: f.title.to_string(),
                    description: f.description.to_string(),
                    body: f.body.to_string(),
                    host: f.host.to_string(),
                    lang: f.lang.to_string(),
                    fetched_at: f.fetched_at,
                    content_hash: chash,
                    http_status: f.http_status,
                    incoming: 0,
                    rank: 0.0,
                    host_rank: 0.0,
                    etag: f.etag.to_string(),
                    last_modified: f.last_modified.to_string(),
                    content_type: f.content_type.to_string(),
                    simhash: f.simhash,
                },
            );
            self.url_to_id.insert(url.to_string(), id);
            id
        }
    }

    /// True if any document has this `content_hash`.
    #[must_use]
    pub fn hash_exists(&self, chash: &str) -> bool {
        self.docs.values().any(|d| d.content_hash == chash)
    }

    /// The URL of the lowest-id document with this `content_hash` (the crawler's
    /// exact-dup lookup; `SELECT url FROM docs WHERE content_hash=? LIMIT 1`).
    #[must_use]
    pub fn url_with_content_hash(&self, chash: &str) -> Option<String> {
        self.docs
            .values()
            .find(|d| d.content_hash == chash)
            .map(|d| d.url.clone())
    }

    /// The `(etag, last_modified)` validators for `url`, or `("", "")`.
    #[must_use]
    pub fn get_validators(&self, url: &str) -> (String, String) {
        match self.url_to_id.get(url).and_then(|id| self.docs.get(id)) {
            Some(d) => (d.etag.clone(), d.last_modified.clone()),
            None => (String::new(), String::new()),
        }
    }

    /// A conditional GET returned 304: bump `fetched_at` and refresh any re-sent
    /// validators, without touching the indexed content. No-op if `url` unknown.
    pub fn touch_revalidated(
        &mut self,
        url: &str,
        fetched_at: f64,
        etag: Option<&str>,
        last_modified: Option<&str>,
    ) {
        if let Some(&id) = self.url_to_id.get(url) {
            let d = self.docs.get_mut(&id).expect("doc exists");
            d.fetched_at = fetched_at;
            if let Some(e) = etag {
                if !e.is_empty() {
                    d.etag = e.to_string();
                }
            }
            if let Some(lm) = last_modified {
                if !lm.is_empty() {
                    d.last_modified = lm.to_string();
                }
            }
        }
    }

    /// Documents whose `fetched_at + interval` has passed → `[(url, host), …]`,
    /// ordered by `fetched_at` (ties by rowid). Only rows with `fetched_at > 0`.
    #[must_use]
    pub fn due_for_recrawl(&self, interval: f64, now: f64) -> Vec<(String, String)> {
        let cutoff = now - interval;
        let mut due: Vec<&Document> = self
            .docs
            .values()
            .filter(|d| d.fetched_at > 0.0 && d.fetched_at <= cutoff)
            .collect();
        due.sort_by(|a, b| {
            a.fetched_at
                .partial_cmp(&b.fetched_at)
                .unwrap_or(std::cmp::Ordering::Equal)
                .then(a.id.cmp(&b.id))
        });
        due.into_iter()
            .map(|d| (d.url.clone(), d.host.clone()))
            .collect()
    }

    /// Record outbound links: `edges` is `(dst, internal)`. Duplicate `(src, dst)`
    /// pairs are ignored (first write wins), matching `INSERT OR IGNORE`.
    pub fn add_links(&mut self, src: &str, edges: &[(String, bool)]) {
        for (dst, internal) in edges {
            self.links
                .entry((src.to_string(), dst.clone()))
                .or_insert(*internal);
        }
    }

    /// Refresh every document's `incoming` count from the internal link graph
    /// (`links.dst == docs.url AND internal`).
    pub fn recompute_incoming(&mut self) {
        let mut counts: HashMap<&str, i64> = HashMap::new();
        for ((_, dst), internal) in &self.links {
            if *internal {
                *counts.entry(dst.as_str()).or_insert(0) += 1;
            }
        }
        // Snapshot to avoid borrowing docs immutably + mutably at once.
        let updates: Vec<(i64, i64)> = self
            .docs
            .values()
            .map(|d| (d.id, *counts.get(d.url.as_str()).unwrap_or(&0)))
            .collect();
        for (id, c) in updates {
            self.docs.get_mut(&id).expect("doc exists").incoming = c;
        }
    }

    /// The document for `url`, if indexed.
    #[must_use]
    pub fn get_doc(&self, url: &str) -> Option<&Document> {
        self.url_to_id.get(url).and_then(|id| self.docs.get(id))
    }

    /// Number of indexed documents.
    #[must_use]
    pub fn doc_count(&self) -> usize {
        self.docs.len()
    }

    /// All documents, in ascending rowid (insertion) order — the corpus a search
    /// pass iterates over.
    pub fn all_docs(&self) -> impl Iterator<Item = &Document> {
        self.docs.values()
    }

    /// Index statistics for the about/stats page.
    #[must_use]
    pub fn stats(&self) -> Stats {
        let docs = self.docs.len();
        let mut host_counts: BTreeMap<String, usize> = BTreeMap::new();
        let mut lang_counts: BTreeMap<String, usize> = BTreeMap::new();
        let mut oldest: Option<f64> = None;
        let mut newest: Option<f64> = None;
        for d in self.docs.values() {
            *host_counts.entry(d.host.clone()).or_insert(0) += 1;
            if !d.lang.is_empty() {
                *lang_counts.entry(d.lang.clone()).or_insert(0) += 1;
            }
            if d.fetched_at > 0.0 {
                oldest = Some(oldest.map_or(d.fetched_at, |m: f64| m.min(d.fetched_at)));
                newest = Some(newest.map_or(d.fetched_at, |m: f64| m.max(d.fetched_at)));
            }
        }
        Stats {
            docs,
            hosts: host_counts.len(),
            links: self.links.len(),
            oldest,
            newest,
            top_hosts: top_n(&host_counts, 10),
            languages: top_n(&lang_counts, 10),
        }
    }

    /// Cross-domain host authority for `host` (0..1), if computed.
    #[must_use]
    pub fn host_authority(&self, host: &str) -> Option<f64> {
        self.host_authority.get(host).copied()
    }

    /// PageRank-lite over the internal link graph, written to `doc.rank`
    /// (normalised so the max is 1). No edges → every rank is 0. Mirrors the
    /// Python `compute_pagerank`.
    pub fn compute_pagerank(&mut self, damping: f64, iterations: usize, tol: f64) {
        let urls: Vec<String> = self.docs.values().map(|d| d.url.clone()).collect();
        let n = urls.len();
        if n == 0 {
            return;
        }
        let idx: HashMap<&str, usize> = urls
            .iter()
            .enumerate()
            .map(|(i, u)| (u.as_str(), i))
            .collect();
        let mut out: Vec<Vec<usize>> = vec![Vec::new(); n];
        let mut have_edges = false;
        let mut keys: Vec<&(String, String)> = self
            .links
            .iter()
            .filter(|(_, v)| **v)
            .map(|(k, _)| k)
            .collect();
        keys.sort();
        for (src, dst) in keys {
            if let (Some(&si), Some(&di)) = (idx.get(src.as_str()), idx.get(dst.as_str())) {
                if si != di {
                    out[si].push(di);
                    have_edges = true;
                }
            }
        }
        if !have_edges {
            for d in self.docs.values_mut() {
                d.rank = 0.0;
            }
            return;
        }
        let nf = n as f64;
        let mut pr = vec![1.0 / nf; n];
        let base = (1.0 - damping) / nf;
        for _ in 0..iterations {
            let mut new = vec![base; n];
            let mut dangling = 0.0;
            for i in 0..n {
                if out[i].is_empty() {
                    dangling += damping * pr[i] / nf;
                } else {
                    let share = damping * pr[i] / out[i].len() as f64;
                    for &j in &out[i] {
                        new[j] += share;
                    }
                }
            }
            if dangling != 0.0 {
                for v in &mut new {
                    *v += dangling;
                }
            }
            let delta: f64 = (0..n).map(|i| (new[i] - pr[i]).abs()).sum();
            pr = new;
            if delta < tol {
                break;
            }
        }
        let top = normalizer(&pr);
        let ranks: Vec<(i64, f64)> = self
            .docs
            .values()
            .map(|d| (d.id, pr[idx[d.url.as_str()]] / top))
            .collect();
        for (id, r) in ranks {
            self.docs.get_mut(&id).expect("doc").rank = r;
        }
    }

    /// Cross-domain host-level PageRank — the authority signal — over the graph of
    /// hosts linked by cross-domain edges (weight = distinct source pages). Written
    /// to `host_authority` and denormalised onto `doc.host_rank` (max = 1). No
    /// cross-domain edges → every `host_rank` is 0. Mirrors the Python
    /// `compute_host_authority`.
    pub fn compute_host_authority(&mut self, damping: f64, iterations: usize, tol: f64) {
        let mut adj: HashMap<String, HashMap<String, f64>> = HashMap::new();
        let mut hosts: std::collections::BTreeSet<String> = std::collections::BTreeSet::new();
        let mut keys: Vec<&(String, String)> = self.links.keys().collect();
        keys.sort();
        for (src, dst) in keys {
            let sh = host_of(src);
            let dh = host_of(dst);
            if sh.is_empty() || dh.is_empty() || sh == dh {
                continue;
            }
            hosts.insert(sh.clone());
            hosts.insert(dh.clone());
            *adj.entry(sh).or_default().entry(dh).or_insert(0.0) += 1.0;
        }
        for d in self.docs.values() {
            if !d.host.is_empty() {
                hosts.insert(d.host.clone());
            }
        }
        self.host_authority.clear();
        if hosts.is_empty() {
            for d in self.docs.values_mut() {
                d.host_rank = 0.0;
            }
            return;
        }
        let nodes: Vec<String> = hosts.into_iter().collect();
        let idx: HashMap<&str, usize> = nodes
            .iter()
            .enumerate()
            .map(|(i, h)| (h.as_str(), i))
            .collect();
        let n = nodes.len();
        let mut out: Vec<Vec<(usize, f64)>> = vec![Vec::new(); n];
        let mut out_w = vec![0.0f64; n];
        let mut have_edges = false;
        for (i, h) in nodes.iter().enumerate() {
            if let Some(targets) = adj.get(h) {
                let mut tks: Vec<&String> = targets.keys().collect();
                tks.sort();
                for dh in tks {
                    let w = targets[dh];
                    out[i].push((idx[dh.as_str()], w));
                    out_w[i] += w;
                    have_edges = true;
                }
            }
        }
        if !have_edges {
            for d in self.docs.values_mut() {
                d.host_rank = 0.0;
            }
            return;
        }
        let nf = n as f64;
        let mut pr = vec![1.0 / nf; n];
        let base = (1.0 - damping) / nf;
        for _ in 0..iterations {
            let mut new = vec![base; n];
            let mut dangling = 0.0;
            for i in 0..n {
                if out_w[i] > 0.0 {
                    let factor = damping * pr[i] / out_w[i];
                    for &(j, w) in &out[i] {
                        new[j] += factor * w;
                    }
                } else {
                    dangling += damping * pr[i] / nf;
                }
            }
            if dangling != 0.0 {
                for v in &mut new {
                    *v += dangling;
                }
            }
            let delta: f64 = (0..n).map(|i| (new[i] - pr[i]).abs()).sum();
            pr = new;
            if delta < tol {
                break;
            }
        }
        let top = normalizer(&pr);
        for (i, h) in nodes.iter().enumerate() {
            self.host_authority.insert(h.clone(), pr[i] / top);
        }
        let updates: Vec<(i64, f64)> = self
            .docs
            .values()
            .map(|d| {
                (
                    d.id,
                    self.host_authority.get(&d.host).copied().unwrap_or(0.0),
                )
            })
            .collect();
        for (id, hr) in updates {
            self.docs.get_mut(&id).expect("doc").host_rank = hr;
        }
    }

    /// Post-crawl finalise: recompute incoming counts, page PageRank (30 iters),
    /// and cross-domain host authority (50 iters), with the Python defaults.
    pub fn finalize(&mut self) {
        self.recompute_incoming();
        self.compute_pagerank(0.85, 30, 1e-6);
        self.compute_host_authority(0.85, 50, 1e-6);
    }
}

/// The PageRank normaliser: the max score, or `1.0` if it is zero
/// (Python `max(pr) or 1.0`).
fn normalizer(pr: &[f64]) -> f64 {
    let m = pr.iter().copied().fold(f64::MIN, f64::max);
    if m == 0.0 {
        1.0
    } else {
        m
    }
}

/// The top `n` `(key, count)` by count desc, ties by key asc (deterministic).
fn top_n(counts: &BTreeMap<String, usize>, n: usize) -> Vec<(String, usize)> {
    let mut v: Vec<(String, usize)> = counts.iter().map(|(k, c)| (k.clone(), *c)).collect();
    v.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
    v.truncate(n);
    v
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fields(fetched_at: f64) -> DocFields<'static> {
        DocFields {
            fetched_at,
            http_status: 200,
            ..DocFields::default()
        }
    }

    #[test]
    fn content_hash_matches_shape() {
        // Empty single part → sha256("\0"); deterministic + non-empty hex.
        let h = content_hash(&["a", "b", "c"]);
        assert_eq!(h.len(), 64);
        assert_ne!(content_hash(&["a", "b"]), content_hash(&["ab", ""]));
    }

    #[test]
    fn upsert_and_dedup() {
        let mut ix = Index::new();
        let id1 = ix.upsert_document(
            "http://x/1",
            DocFields {
                title: "T",
                body: "hello world body",
                host: "x",
                ..fields(100.0)
            },
        );
        assert_eq!(id1, 1);
        // re-upsert same url → same id, updated fields
        let id1b = ix.upsert_document(
            "http://x/1",
            DocFields {
                title: "T2",
                ..fields(200.0)
            },
        );
        assert_eq!(id1b, 1);
        assert_eq!(ix.get_doc("http://x/1").unwrap().title, "T2");
        // a second doc with the SAME content hash is found by the dedup lookup
        let ch = content_hash(&["T2", "", ""]);
        assert_eq!(ix.url_with_content_hash(&ch).as_deref(), Some("http://x/1"));
    }

    #[test]
    fn validators_and_recrawl() {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://x/1",
            DocFields {
                etag: "\"v1\"",
                fetched_at: 100.0,
                host: "x",
                http_status: 200,
                ..DocFields::default()
            },
        );
        assert_eq!(ix.get_validators("http://x/1").0, "\"v1\"");
        ix.touch_revalidated("http://x/1", 500.0, Some("\"v2\""), None);
        assert_eq!(ix.get_validators("http://x/1").0, "\"v2\"");
        assert_eq!(ix.get_doc("http://x/1").unwrap().fetched_at, 500.0);
        // due at now=1000, interval=100 → cutoff 900; fetched_at 500 <= 900 → due
        assert_eq!(
            ix.due_for_recrawl(100.0, 1000.0),
            vec![("http://x/1".to_string(), "x".to_string())]
        );
        // not due yet (cutoff 400 < 500)
        assert!(ix.due_for_recrawl(100.0, 500.0).is_empty());
    }

    #[test]
    fn links_and_incoming() {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://x/a",
            DocFields {
                host: "x",
                ..fields(1.0)
            },
        );
        ix.upsert_document(
            "http://x/b",
            DocFields {
                host: "x",
                ..fields(2.0)
            },
        );
        ix.add_links(
            "http://x/a",
            &[
                ("http://x/b".to_string(), true),
                ("http://ext/z".to_string(), false),
            ],
        );
        ix.add_links("http://x/a", &[("http://x/b".to_string(), true)]); // dup ignored
        ix.recompute_incoming();
        assert_eq!(ix.get_doc("http://x/b").unwrap().incoming, 1);
        assert_eq!(ix.get_doc("http://x/a").unwrap().incoming, 0);
        assert_eq!(ix.stats().links, 2);
    }
}
