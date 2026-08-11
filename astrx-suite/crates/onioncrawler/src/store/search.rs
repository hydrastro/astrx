//! Full-text search over the stored page bodies — a dependency-free
//! reimplementation of the Python store's SQLite/FTS5 `bm25` search.
//!
//! The Python reference delegates to FTS5 (`bm25(search_index, 10.0, 1.0)` +
//! `snippet()`); the stdlib has no FTS5, so this is a hand-rolled inverted-index
//! **BM25** the way `torrentds`'s search is built — **behaviourally faithful, not
//! bit-identical**. It reproduces what matters: the **title-10× / body-1×** field
//! weighting, implicit-AND term matching with quoted **phrase** support, the
//! host / language / date **filters**, the **facets** (top hosts + languages),
//! near-duplicate **collapse** (by `cluster_id` or SimHash distance), the
//! optional **authority** blend, and the hidden-host exclusion
//! (`blocked` / `dead` never appear). Ranking is BM25 negated (lower = better),
//! matching the Python `ORDER BY rank ASC`.
//!
//! The index is rebuilt transiently per query from the stored bodies (the store
//! already retains them); a persistent incremental index is a future
//! optimisation. Tokenisation is Unicode-aware (alphanumeric runs, lowercased),
//! shared by the index and the query so matching is self-consistent.

use std::collections::{HashMap, HashSet};

use super::{Store, HIDDEN_STATES};
use crate::simhash::hamming;

const TITLE_WEIGHT: f64 = 10.0;
const BODY_WEIGHT: f64 = 1.0;
const K1: f64 = 1.2;
const B: f64 = 0.75;

/// Search parameters. [`SearchOpts::default`] mirrors the Python defaults
/// (`limit=10`, no filters, no collapse, `simhash_threshold=3`).
#[derive(Clone, Debug)]
pub struct SearchOpts {
    pub limit: usize,
    pub offset: usize,
    pub host: Option<String>,
    pub since: Option<f64>,
    pub until: Option<f64>,
    pub lang: Option<String>,
    pub authority_weight: f64,
    pub collapse: bool,
    pub simhash_threshold: u32,
}

impl Default for SearchOpts {
    fn default() -> Self {
        SearchOpts {
            limit: 10,
            offset: 0,
            host: None,
            since: None,
            until: None,
            lang: None,
            authority_weight: 0.0,
            collapse: false,
            simhash_threshold: 3,
        }
    }
}

/// One search result row.
#[derive(Clone, Debug, PartialEq)]
pub struct SearchHit {
    pub url: String,
    pub title: Option<String>,
    pub host: String,
    pub fetched_at: Option<f64>,
    pub last_seen: Option<f64>,
    pub lang: Option<String>,
    pub snippet: String,
    pub rank: f64,
}

/// A ranked page window plus the raw match total (before collapse).
#[derive(Clone, Debug, PartialEq)]
pub struct SearchResults {
    pub hits: Vec<SearchHit>,
    pub total: usize,
}

/// Facet counts for a query: the overall match total plus the top hosts and
/// languages among the matches.
#[derive(Clone, Debug, PartialEq)]
pub struct Facets {
    pub total: usize,
    pub hosts: Vec<(String, usize)>,
    pub langs: Vec<(String, usize)>,
}

/// Tokenize like the index: maximal Unicode-alphanumeric runs, lowercased.
fn tokenize(text: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    for c in text.chars() {
        if c.is_alphanumeric() {
            cur.extend(c.to_lowercase());
        } else if !cur.is_empty() {
            out.push(std::mem::take(&mut cur));
        }
    }
    if !cur.is_empty() {
        out.push(cur);
    }
    out
}

/// Parse a raw query into quoted phrases + loose terms (both tokenized). A
/// phrase is the token sequence inside a pair of double quotes; everything else
/// contributes loose terms.
fn parse_query(q: &str) -> (Vec<Vec<String>>, Vec<String>) {
    let mut phrases = Vec::new();
    let mut remainder = String::new();
    let mut in_quote = false;
    let mut buf = String::new();
    for c in q.chars() {
        if c == '"' {
            if in_quote {
                let toks = tokenize(&buf);
                if !toks.is_empty() {
                    phrases.push(toks);
                }
                buf.clear();
            }
            in_quote = !in_quote;
        } else if in_quote {
            buf.push(c);
        } else {
            remainder.push(c);
        }
    }
    // an unterminated quote's contents fall back to loose terms
    if in_quote && !buf.is_empty() {
        remainder.push(' ');
        remainder.push_str(&buf);
    }
    (phrases, tokenize(&remainder))
}

/// True if `needle` appears as a consecutive run in `haystack`.
fn contains_run(haystack: &[String], needle: &[String]) -> bool {
    if needle.is_empty() || needle.len() > haystack.len() {
        return false;
    }
    let last = haystack.len() - needle.len();
    (0..=last).any(|i| &haystack[i..i + needle.len()] == needle)
}

/// A doc tokenized into its two fields, kept for the duration of one query.
struct Doc<'a> {
    page: &'a super::PageRow,
    title: Vec<String>,
    body: Vec<String>,
}

impl Store {
    /// Ranked full-text search. Returns the page window plus the raw match total
    /// (before collapse). An empty / punctuation-only query yields no hits.
    #[must_use]
    pub fn search(&self, query: &str, opts: &SearchOpts) -> SearchResults {
        let (phrases, loose) = parse_query(query);
        // every query token (phrase tokens + loose terms), unique, for matching
        // + BM25; a non-empty query that tokenizes to nothing matches nothing.
        let mut terms: Vec<String> = loose.clone();
        for ph in &phrases {
            terms.extend(ph.iter().cloned());
        }
        if terms.is_empty() {
            return SearchResults {
                hits: Vec::new(),
                total: 0,
            };
        }
        let mut seen = HashSet::new();
        terms.retain(|t| seen.insert(t.clone()));

        // The searchable corpus: pages not on a hidden-state host.
        let docs: Vec<Doc> = self
            .pages
            .values()
            .filter(|p| !self.host_hidden(&p.host))
            .map(|p| Doc {
                page: p,
                title: tokenize(p.title.as_deref().unwrap_or("")),
                body: tokenize(p.body.as_deref().unwrap_or("")),
            })
            .collect();
        let n = docs.len();
        if n == 0 {
            return SearchResults {
                hits: Vec::new(),
                total: 0,
            };
        }

        // Corpus stats for BM25: per-term document frequency + per-field avg len.
        let df: HashMap<&str, f64> = terms
            .iter()
            .map(|t| {
                let c = docs
                    .iter()
                    .filter(|d| d.title.iter().any(|x| x == t) || d.body.iter().any(|x| x == t))
                    .count() as f64;
                (t.as_str(), c)
            })
            .collect();
        let avg_title =
            (docs.iter().map(|d| d.title.len()).sum::<usize>() as f64 / n as f64).max(1.0);
        let avg_body =
            (docs.iter().map(|d| d.body.len()).sum::<usize>() as f64 / n as f64).max(1.0);

        // Candidates: pass the filters AND contain every term, with phrases
        // appearing consecutively in the title or the body.
        let mut scored: Vec<(f64, &Doc)> = docs
            .iter()
            .filter(|d| self.passes_filters(d.page, opts))
            .filter(|d| {
                terms
                    .iter()
                    .all(|t| d.title.iter().any(|x| x == t) || d.body.iter().any(|x| x == t))
                    && phrases
                        .iter()
                        .all(|ph| contains_run(&d.title, ph) || contains_run(&d.body, ph))
            })
            .map(|d| {
                (
                    self.bm25(d, &terms, &df, n as f64, avg_title, avg_body, opts),
                    d,
                )
            })
            .collect();
        let total = scored.len();

        // Rank ascending (lower BM25 = better); tie-break newest, then id.
        scored.sort_by(|a, b| {
            a.0.partial_cmp(&b.0)
                .unwrap_or(std::cmp::Ordering::Equal)
                .then_with(|| {
                    b.1.page
                        .last_seen
                        .partial_cmp(&a.1.page.last_seen)
                        .unwrap_or(std::cmp::Ordering::Equal)
                })
                .then_with(|| b.1.page.id.cmp(&a.1.page.id))
        });

        let window: Vec<(f64, &Doc)> = if opts.collapse {
            self.collapse(scored, opts)
        } else {
            scored
                .into_iter()
                .skip(opts.offset)
                .take(opts.limit)
                .collect()
        };

        let hits = window
            .into_iter()
            .map(|(rank, d)| SearchHit {
                url: d.page.url.clone(),
                title: d.page.title.clone(),
                host: d.page.host.clone(),
                fetched_at: d.page.fetched_at,
                last_seen: d.page.last_seen,
                lang: d.page.lang.clone(),
                snippet: snippet(&d.body, &terms),
                rank,
            })
            .collect();
        SearchResults { hits, total }
    }

    /// Facet counts for a query: total matches + the top `top` hosts and
    /// languages among them.
    #[must_use]
    pub fn search_facets(
        &self,
        query: &str,
        host: Option<&str>,
        since: Option<f64>,
        until: Option<f64>,
        lang: Option<&str>,
        top: usize,
    ) -> Facets {
        // Reuse `search` with a wide window to enumerate every match once.
        let opts = SearchOpts {
            limit: usize::MAX,
            offset: 0,
            host: host.map(str::to_string),
            since,
            until,
            lang: lang.map(str::to_string),
            authority_weight: 0.0,
            collapse: false,
            simhash_threshold: 3,
        };
        let res = self.search(query, &opts);
        let mut host_counts: HashMap<String, usize> = HashMap::new();
        let mut lang_counts: HashMap<String, usize> = HashMap::new();
        for h in &res.hits {
            *host_counts.entry(h.host.clone()).or_insert(0) += 1;
            *lang_counts
                .entry(h.lang.clone().unwrap_or_else(|| "un".to_string()))
                .or_insert(0) += 1;
        }
        Facets {
            total: res.total,
            hosts: top_n(host_counts, top),
            langs: top_n(lang_counts, top),
        }
    }

    fn host_hidden(&self, host: &str) -> bool {
        self.get_host(host)
            .map(|h| HIDDEN_STATES.contains(&h.state.as_str()))
            .unwrap_or(false)
    }

    fn passes_filters(&self, p: &super::PageRow, opts: &SearchOpts) -> bool {
        if let Some(h) = &opts.host {
            if &p.host != h {
                return false;
            }
        }
        if let Some(s) = opts.since {
            if p.last_seen.map_or(true, |ls| ls < s) {
                return false;
            }
        }
        if let Some(u) = opts.until {
            if p.last_seen.map_or(true, |ls| ls > u) {
                return false;
            }
        }
        if let Some(l) = &opts.lang {
            if p.lang.as_deref() != Some(l.as_str()) {
                return false;
            }
        }
        true
    }

    #[allow(clippy::too_many_arguments)]
    fn bm25(
        &self,
        d: &Doc,
        terms: &[String],
        df: &HashMap<&str, f64>,
        n: f64,
        avg_title: f64,
        avg_body: f64,
        opts: &SearchOpts,
    ) -> f64 {
        let dl_title = d.title.len().max(1) as f64;
        let dl_body = d.body.len().max(1) as f64;
        let mut bm = 0.0;
        for t in terms {
            let dfi = *df.get(t.as_str()).unwrap_or(&0.0);
            let idf = ((n - dfi + 0.5) / (dfi + 0.5)).ln();
            let tf_t = d.title.iter().filter(|x| *x == t).count() as f64;
            let tf_b = d.body.iter().filter(|x| *x == t).count() as f64;
            // Per-field BM25 saturation, then the field-weighted sum — the same
            // shape as FTS5 `bm25(idx, 10.0, 1.0)`.
            let comp_t = TITLE_WEIGHT * (tf_t * (K1 + 1.0))
                / (tf_t + K1 * (1.0 - B + B * dl_title / avg_title));
            let comp_b = BODY_WEIGHT * (tf_b * (K1 + 1.0))
                / (tf_b + K1 * (1.0 - B + B * dl_body / avg_body));
            bm += idf * (comp_t + comp_b);
        }
        // FTS5 rank is the NEGATED bm25 (lower = better); blend host authority.
        let mut rank = -bm;
        if opts.authority_weight != 0.0 {
            let authority = self
                .get_host(&d.page.host)
                .map(|h| h.authority)
                .unwrap_or(0.0);
            rank -= opts.authority_weight * authority;
        }
        rank
    }

    /// Collapse near-duplicates in rank order, keeping the best representative of
    /// each cluster, then apply offset/limit — mirroring the Python collapse path.
    fn collapse<'a>(
        &self,
        scored: Vec<(f64, &'a Doc<'a>)>,
        opts: &SearchOpts,
    ) -> Vec<(f64, &'a Doc<'a>)> {
        let cap = (opts.offset + opts.limit.saturating_mul(4) + 20).min(1000);
        let mut kept: Vec<(f64, &Doc)> = Vec::new();
        let mut kept_sig: Vec<(Option<i64>, Option<i64>)> = Vec::new();
        for (rank, d) in scored.into_iter().take(cap) {
            let cid = d.page.cluster_id;
            let sh = d.page.simhash;
            let dup = kept_sig.iter().any(|(kc, ksh)| {
                (cid.is_some() && *kc == cid)
                    || match (sh, ksh) {
                        (Some(a), Some(b)) if a != 0 && *b != 0 => {
                            hamming(a as u64, *b as u64) <= opts.simhash_threshold
                        }
                        _ => false,
                    }
            });
            if dup {
                continue;
            }
            kept_sig.push((cid, sh));
            kept.push((rank, d));
        }
        kept.into_iter()
            .skip(opts.offset)
            .take(opts.limit)
            .collect()
    }
}

/// Build a `<mark>`-highlighted snippet around the first body match (≈14-token
/// window), matching the Python `snippet(...,'<mark>','</mark>','…',14)` intent.
fn snippet(body: &[String], terms: &[String]) -> String {
    if body.is_empty() {
        return String::new();
    }
    let want: HashSet<&str> = terms.iter().map(String::as_str).collect();
    let first = body.iter().position(|t| want.contains(t.as_str()));
    let center = first.unwrap_or(0);
    let start = center.saturating_sub(7);
    let end = (start + 14).min(body.len());
    let parts: Vec<String> = body[start..end]
        .iter()
        .map(|t| {
            if want.contains(t.as_str()) {
                format!("<mark>{t}</mark>")
            } else {
                t.clone()
            }
        })
        .collect();
    let mut s = String::new();
    if start > 0 {
        s.push('…');
    }
    s.push_str(&parts.join(" "));
    if end < body.len() {
        s.push('…');
    }
    s
}

fn top_n(counts: HashMap<String, usize>, top: usize) -> Vec<(String, usize)> {
    let mut v: Vec<(String, usize)> = counts.into_iter().collect();
    // count DESC, then key ASC for a stable, deterministic order
    v.sort_by(|a, b| b.1.cmp(&a.1).then_with(|| a.0.cmp(&b.0)));
    v.truncate(top);
    v
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store::Store;

    /// Store a page on `host` (ensuring the host exists first).
    fn sp(s: &mut Store, url: &str, host: &str, title: &str, body: &str) {
        s.ensure_host(host, 1.0);
        s.store_page(
            url,
            host,
            Some(title),
            Some(body),
            Some(url),
            Some(200),
            Some("text/html"),
            None,
            1.0,
            false,
            None,
            None,
            None,
        );
    }

    fn urls(r: &SearchResults) -> Vec<&str> {
        r.hits.iter().map(|h| h.url.as_str()).collect()
    }

    #[test]
    fn and_semantics_and_ranking() {
        let mut s = Store::new();
        sp(&mut s, "u1", "h.onion", "alpha", "widget in the body here");
        sp(&mut s, "u2", "h.onion", "widget", "alpha beta gamma delta");
        // filler docs so idf(widget) stays positive (df=2 of 5)
        sp(
            &mut s,
            "u3",
            "h.onion",
            "zeta",
            "nothing relevant here at all",
        );
        sp(
            &mut s,
            "u4",
            "h.onion",
            "eta",
            "unrelated words fill the corpus",
        );
        sp(
            &mut s,
            "u5",
            "h.onion",
            "theta",
            "more filler text for stats",
        );

        let r = s.search("widget", &SearchOpts::default());
        assert_eq!(r.total, 2);
        // u2 has "widget" in the TITLE (10× weight) → ranks first
        assert_eq!(urls(&r)[0], "u2");

        // AND: both terms must be present
        let r = s.search("widget alpha", &SearchOpts::default());
        let got: Vec<&str> = urls(&r);
        assert!(got.contains(&"u1")); // has widget(body)+alpha(title)
        assert!(got.contains(&"u2")); // has widget(title)+alpha(body)
        assert_eq!(r.total, 2);

        // a term present nowhere → no matches
        assert_eq!(s.search("nonexistentterm", &SearchOpts::default()).total, 0);
    }

    #[test]
    fn phrase_requires_adjacency() {
        let mut s = Store::new();
        sp(&mut s, "adj", "h.onion", "t", "the quick brown fox runs");
        sp(&mut s, "split", "h.onion", "t", "the quick red brown fox");
        let r = s.search("\"quick brown\"", &SearchOpts::default());
        assert_eq!(urls(&r), vec!["adj"]);
    }

    #[test]
    fn empty_and_punctuation_match_nothing() {
        let mut s = Store::new();
        sp(&mut s, "u1", "h.onion", "hello", "world");
        assert_eq!(s.search("", &SearchOpts::default()).total, 0);
        assert_eq!(s.search("   ", &SearchOpts::default()).total, 0);
        assert_eq!(s.search("!!! ---", &SearchOpts::default()).total, 0);
    }

    #[test]
    fn filters_by_host_and_lang_and_date() {
        let mut s = Store::new();
        sp(&mut s, "a", "a.onion", "t", "widget alpha");
        sp(&mut s, "b", "b.onion", "t", "widget beta");
        // host filter
        let r = s.search(
            "widget",
            &SearchOpts {
                host: Some("a.onion".to_string()),
                ..SearchOpts::default()
            },
        );
        assert_eq!(urls(&r), vec!["a"]);
        // lang filter (force a lang on one page)
        if let Some(p) = s.pages.get_mut(&1) {
            p.lang = Some("en".to_string());
        }
        let r = s.search(
            "widget",
            &SearchOpts {
                lang: Some("en".to_string()),
                ..SearchOpts::default()
            },
        );
        assert_eq!(urls(&r), vec!["a"]);
        // since filter (last_seen is 1.0 for both) → since=2.0 excludes all
        let r = s.search(
            "widget",
            &SearchOpts {
                since: Some(2.0),
                ..SearchOpts::default()
            },
        );
        assert_eq!(r.total, 0);
    }

    #[test]
    fn hidden_host_states_excluded() {
        let mut s = Store::new();
        sp(&mut s, "ok", "good.onion", "t", "widget alpha");
        sp(&mut s, "bad", "bad.onion", "t", "widget beta");
        s.set_host_state("bad.onion", "blocked", Some("abuse"));
        let r = s.search("widget", &SearchOpts::default());
        assert_eq!(urls(&r), vec!["ok"]);
    }

    #[test]
    fn collapse_drops_near_duplicates() {
        let mut s = Store::new();
        let base = "the quick brown fox jumps over the lazy dog in the meadow every morning today";
        sp(&mut s, "p1", "h.onion", "t", base);
        sp(
            &mut s,
            "p2",
            "h.onion",
            "t",
            &base.replace("meadow", "field"),
        );
        // both contain "quick"; without collapse → 2 hits, with collapse → 1
        let plain = s.search("quick", &SearchOpts::default());
        assert_eq!(plain.total, 2);
        let collapsed = s.search(
            "quick",
            &SearchOpts {
                collapse: true,
                simhash_threshold: 8,
                ..SearchOpts::default()
            },
        );
        assert_eq!(collapsed.hits.len(), 1);
        assert_eq!(collapsed.total, 2); // total is the pre-collapse count
    }

    #[test]
    fn facets_count_hosts_and_langs() {
        let mut s = Store::new();
        sp(&mut s, "a", "a.onion", "t", "widget one");
        sp(&mut s, "b", "a.onion", "t", "widget two");
        sp(&mut s, "c", "b.onion", "t", "widget three");
        let f = s.search_facets("widget", None, None, None, None, 10);
        assert_eq!(f.total, 3);
        assert_eq!(f.hosts[0], ("a.onion".to_string(), 2)); // most matches first
    }

    #[test]
    fn snippet_highlights_match_and_limit_offset() {
        let mut s = Store::new();
        sp(
            &mut s,
            "u1",
            "h.onion",
            "t",
            "lorem ipsum widget dolor sit amet consectetur",
        );
        let r = s.search("widget", &SearchOpts::default());
        assert!(r.hits[0].snippet.contains("<mark>widget</mark>"));
        // limit/offset windowing
        for i in 0..5 {
            sp(
                &mut s,
                &format!("p{i}"),
                "h.onion",
                "t",
                "widget filler content number",
            );
        }
        let page1 = s.search(
            "widget",
            &SearchOpts {
                limit: 2,
                offset: 0,
                ..SearchOpts::default()
            },
        );
        let page2 = s.search(
            "widget",
            &SearchOpts {
                limit: 2,
                offset: 2,
                ..SearchOpts::default()
            },
        );
        assert_eq!(page1.hits.len(), 2);
        assert_eq!(page2.hits.len(), 2);
        assert!(page1.total >= 6);
        // windows are disjoint
        let u1: Vec<&str> = urls(&page1);
        let u2: Vec<&str> = urls(&page2);
        assert!(u1.iter().all(|x| !u2.contains(x)));
    }

    #[test]
    fn authority_weight_breaks_ties() {
        let mut s = Store::new();
        sp(&mut s, "lo", "low.onion", "t", "widget alpha beta");
        sp(&mut s, "hi", "high.onion", "t", "widget alpha beta");
        // identical content ⇒ identical bm25; authority should lift "high.onion"
        s.ensure_host("high.onion", 1.0);
        if let Some(h) = s.hosts.get_mut("high.onion") {
            h.authority = 1.0;
        }
        let r = s.search(
            "widget",
            &SearchOpts {
                authority_weight: 5.0,
                ..SearchOpts::default()
            },
        );
        assert_eq!(urls(&r)[0], "hi");
    }
}
