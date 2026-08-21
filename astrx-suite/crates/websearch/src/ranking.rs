//! Query parsing, scoring, snippets, and full-text search over the [`Index`].
//!
//! A port of the Python `websearch.ranking`. The **pure** pieces — the query
//! language (now [`crate::query`], re-exported here: quoted phrases,
//! `+required` / `-excluded` / optional terms, and `site:`/`-site:`/`host:`/
//! `lang:`/`filetype:`/`intitle:`/`before:`/`after:`/`date:`/`boost:`/
//! `penalize:` operators), the scoring components
//! ([`freshness`], [`proximity_bonus`], [`content_quality`]), and [`make_snippet`]
//! (query-biased, HTML-escaped, `<mark>`-highlighted) — are cross-checked
//! byte-identical to Python in `tests/xcheck_ranking.rs`.
//!
//! Ranking is *explicit*: a text-relevance base + link popularity + cross-domain
//! host authority + freshness + a proximity/phrase bonus + a content-quality
//! (anti-doorway) term + per-query host boost/penalty optics (see [`score`]).
//! The Python relevance base is SQLite FTS5's `bm25()`; here it is a hand-rolled
//! field-weighted Okapi BM25 over the in-memory index — **behaviourally faithful,
//! not bit-identical** (the stdlib has no FTS5), like the other engines' search.

use crate::index::{DocDerived, Document, Index};
use std::collections::{HashMap, HashSet};

// The query language lives in [`crate::query`]; it is re-exported here because
// `parse_query` / `Query` / `parse_date` have been part of this module's public
// surface since the port, and `tests/xcheck_ranking.rs` addresses them here.
pub use crate::query::{host_in_site, parse_date, parse_query, Query};

// ---- tunable weights (documented in the README) ---------------------------

/// bm25 field weight: title.
pub const W_TITLE: f64 = 10.0;
/// bm25 field weight: meta description.
pub const W_DESC: f64 = 4.0;
/// bm25 field weight: body text.
pub const W_BODY: f64 = 1.0;

const K_LINK: f64 = 0.30;
const K_AUTH: f64 = 0.80;
const K_PR: f64 = K_AUTH;
const K_FRESH: f64 = 0.20;
const K_PROX: f64 = 0.60;
const K_QUALITY: f64 = 0.15;
const OPTIC_BOOST: f64 = 0.80;
const OPTIC_PENALTY: f64 = 1.50;
const FRESH_HALFLIFE_DAYS: f64 = 30.0;

const CANDIDATE_CAP: usize = 400;
/// Cross-host near-duplicate Hamming threshold. Shared with the federation
/// aggregator so a fleet collapses mirrors exactly as a single node does.
pub(crate) const SIMHASH_HAMMING: u32 = 3;

// BM25 tuning for the hand-rolled relevance base.
const BM25_K1: f64 = 1.2;
const BM25_B: f64 = 0.75;

// ---- word tokenisation -----------------------------------------------------

/// Lower-case `text` and collect maximal alphanumeric runs — the Python
/// `_WORD = [^\W_]+` word tokeniser.
#[must_use]
pub fn words(text: &str) -> Vec<String> {
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

// ---- scoring components ----------------------------------------------------

/// A freshness weight in `0..1`, decaying with a 30-day half-life. `0` if unknown.
#[must_use]
pub fn freshness(fetched_at: f64, now: f64) -> f64 {
    if fetched_at == 0.0 {
        return 0.0;
    }
    let age_days = ((now - fetched_at) / 86400.0).max(0.0);
    (-age_days / FRESH_HALFLIFE_DAYS).exp()
}

/// A `0..1` proximity bonus: an exact phrase present → 1.0; otherwise reward query
/// terms appearing close together. Mirrors the Python `_proximity_bonus`.
#[must_use]
pub fn proximity_bonus(text: &str, phrases: &[Vec<String>], terms: &[String]) -> f64 {
    if text.is_empty() {
        return 0.0;
    }
    let low = text.to_lowercase();
    let mut bonus = 0.0_f64;
    for phrase in phrases {
        let needle = phrase.join(" ");
        if !needle.is_empty() && low.contains(&needle) {
            bonus = bonus.max(1.0);
        }
    }
    if bonus >= 1.0 || terms.len() < 2 {
        return bonus;
    }
    let tokens = words(&low);
    let wanted: HashSet<&String> = terms.iter().collect();
    let mut positions: HashMap<&str, Vec<usize>> = HashMap::new();
    for (i, tk) in tokens.iter().enumerate() {
        if wanted.contains(tk) {
            positions.entry(tk.as_str()).or_default().push(i);
        }
    }
    let firsts: Vec<usize> = positions.values().map(|v| v[0]).collect();
    let present = firsts.len();
    if present < 2 {
        return bonus;
    }
    let span = (firsts.iter().max().unwrap() - firsts.iter().min().unwrap()) as f64;
    let coverage = present as f64 / terms.len() as f64;
    let tightness = 1.0 / (1.0 + span / present.max(1) as f64);
    bonus.max(0.5 * coverage + 0.5 * tightness)
}

/// A `0..1` content-quality (anti-doorway) signal from body length. Mirrors the
/// Python `_content_quality`.
#[must_use]
pub fn content_quality(body: &str) -> f64 {
    let n = body.chars().count();
    if n >= 1200 {
        1.0
    } else if n <= 100 {
        0.0
    } else {
        (n - 100) as f64 / 1100.0
    }
}

// ---- snippets --------------------------------------------------------------

/// HTML-escape like Python `html.escape(s, quote=True)`.
fn html_escape(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#x27;"),
            _ => out.push(c),
        }
    }
    out
}

/// 1:1 lowercase (each char → one char) so positions stay aligned with the
/// original, matching the Python `body.lower()` position use (ASCII-exact).
fn lower1(chars: &[char]) -> Vec<char> {
    chars
        .iter()
        .map(|&c| c.to_lowercase().next().unwrap_or(c))
        .collect()
}

/// Word spans `(start, end)` (char indices) of alphanumeric runs in `chars`.
fn word_spans(chars: &[char]) -> Vec<(usize, usize)> {
    let mut spans = Vec::new();
    let mut i = 0;
    let n = chars.len();
    while i < n {
        if chars[i].is_alphanumeric() {
            let start = i;
            while i < n && chars[i].is_alphanumeric() {
                i += 1;
            }
            spans.push((start, i));
        } else {
            i += 1;
        }
    }
    spans
}

fn rfind_space(chars: &[char], from: usize, to: usize) -> Option<usize> {
    let to = to.min(chars.len());
    (from..to).rev().find(|&i| chars[i] == ' ')
}

fn find_space(chars: &[char], from: usize, to: usize) -> Option<usize> {
    let to = to.min(chars.len());
    (from..to).find(|&i| chars[i] == ' ')
}

/// How much of a document body [`make_snippet`] will scan for term hits.
///
/// A snippet is `width` (280) characters wide, so a hit 64 kB into the body can
/// never be rendered anyway — but the hit scan used to run over the whole
/// uncapped body, once per result row, ten rows per page, while holding the
/// index mutex. Capping the region bounds the two `Vec<char>` allocations at
/// 256 kB each and the scan at a fixed cost, whatever the crawler stored.
const SNIPPET_SCAN_CHARS: usize = 64 * 1024;

#[cfg(test)]
thread_local! {
    /// Steps taken by [`best_window_start`] on this thread — one per hit
    /// inspected plus one per pointer move. Test-only: nothing in the shipped
    /// build counts, reads or allocates it.
    ///
    /// This is what `a_body_of_nothing_but_hits_is_not_quadratic` asserts on,
    /// because the step count is the linearity, whereas a wall-clock reading is
    /// the linearity times whatever else the machine was doing.
    static WINDOW_STEPS: std::cell::Cell<usize> = const { std::cell::Cell::new(0) };
}

/// Run `f` and report how many steps [`best_window_start`] took while it ran
/// (summed, if it ran more than once). The counter is thread-local, so a test
/// using it is unaffected by the other tests the harness runs beside it.
#[cfg(test)]
fn counting_window_steps<T>(f: impl FnOnce() -> T) -> (T, usize) {
    WINDOW_STEPS.with(|c| c.set(0));
    let out = f();
    (out, WINDOW_STEPS.with(std::cell::Cell::get))
}

/// The start of the best snippet window: the EARLIEST hit position maximising
/// the number of hits in `[h, h + width)`.
///
/// `hits` must be ascending (it is: [`word_spans`] emits spans left to right).
/// Two pointers over that order make this O(hits); the obvious nested form —
/// `for &h in &hits { hits.iter().filter(…).count() }` — is O(hits²), which on a
/// body of `"a "` repeated measured 7 ms at 4 kB, 854 ms at 64 kB and **12.85 s
/// at 256 kB**, per result row, under the global index lock.
fn best_window_start(hits: &[usize], width: usize) -> usize {
    let mut best_pos = hits[0];
    let mut best_count = 0usize;
    // `lo`/`hi` bracket the half-open window; both only ever move forward,
    // because `h` is non-decreasing.
    let (mut lo, mut hi) = (0usize, 0usize);
    for &h in hits {
        // `h + width` on a wire-derived width would overflow; saturating keeps
        // the window "to the end of the body", which is what a huge width means.
        let end = h.saturating_add(width);
        while lo < hits.len() && hits[lo] < h {
            lo += 1;
        }
        if hi < lo {
            hi = lo;
        }
        while hi < hits.len() && hits[hi] < end {
            hi += 1;
        }
        let c = hi - lo;
        if c > best_count {
            best_count = c;
            best_pos = h;
        }
    }
    // The work the loop just did, for the linearity test — and it is a reading of
    // the loop's own state, not a tally kept alongside it: the body ran once per
    // hit, and `lo`/`hi` only ever move forward from 0, so their final values ARE
    // the number of times each advanced. At most `3 * hits.len()`, and no counter
    // in the hot path to get out of step with the code it describes.
    //
    // This line is not a tripwire on its own. A nested rewrite has no `lo`/`hi`,
    // so it deletes this line along with them and compiles fine, reporting zero
    // steps — which satisfies any pure upper bound. That is why
    // `a_body_of_nothing_but_hits_is_not_quadratic` pins the total with
    // `assert_eq!` rather than bounding it from above.
    #[cfg(test)]
    WINDOW_STEPS.with(|c| c.set(c.get() + hits.len() + lo + hi));
    best_pos
}

/// A query-biased, HTML-safe snippet with matched `terms` wrapped in `<mark>`.
/// The text is HTML-escaped first, then whole-word matches highlighted. Mirrors
/// the Python `make_snippet` (window selection quirks included), over the first
/// [`SNIPPET_SCAN_CHARS`] characters of `body`.
#[must_use]
pub fn make_snippet(body: &str, terms: &[String], width: usize) -> String {
    if body.is_empty() {
        return String::new();
    }
    let termset: HashSet<String> = terms.iter().map(|t| t.to_lowercase()).collect();
    // Only the scanned prefix is materialised: `body` is whatever the crawler
    // stored (up to `max_bytes`, 2 MB by default), and `Vec<char>` is 4 bytes a
    // character — collecting the whole of it, twice, per rendered row, is how a
    // results page came to cost hundreds of megabytes and seconds of CPU.
    let scan = SNIPPET_SCAN_CHARS.max(width.saturating_mul(2));
    let chars: Vec<char> = body.chars().take(scan).collect();
    let low = lower1(&chars);
    let spans = word_spans(&low);

    let mut start = 0usize;
    if !termset.is_empty() && !spans.is_empty() {
        let hits: Vec<usize> = spans
            .iter()
            .filter(|&&(s, e)| termset.contains(&low[s..e].iter().collect::<String>()))
            .map(|&(s, _)| s)
            .collect();
        if !hits.is_empty() {
            start = best_window_start(&hits, width).saturating_sub(width / 4);
        }
    }

    let mut end = (start + width).min(chars.len());
    if start > 0 {
        if let Some(sp) = rfind_space(&chars, start, start + 40) {
            start = sp + 1;
        }
    }
    if end < chars.len() {
        if let Some(sp) = find_space(&chars, end.saturating_sub(40), end) {
            end = sp;
        }
    }
    // Python str slicing yields "" when start > end.
    let fragment: Vec<char> = if start < end {
        chars[start..end].to_vec()
    } else {
        Vec::new()
    };
    // .strip(): trim leading/trailing whitespace.
    let fs = {
        let a = fragment.iter().position(|c| !c.is_whitespace());
        match a {
            None => Vec::new(),
            Some(a) => {
                let b = fragment.iter().rposition(|c| !c.is_whitespace()).unwrap() + 1;
                fragment[a..b].to_vec()
            }
        }
    };

    let mut out = String::new();
    if start > 0 {
        out.push_str("&hellip; ");
    }
    let flow = lower1(&fs);
    let fspans = word_spans(&fs);
    let mut pos = 0usize;
    for (s, e) in fspans {
        if s > pos {
            out.push_str(&html_escape(&fs[pos..s].iter().collect::<String>()));
        }
        let word: String = fs[s..e].iter().collect();
        let word_low: String = flow[s..e].iter().collect();
        if termset.contains(&word_low) {
            out.push_str("<mark>");
            out.push_str(&html_escape(&word));
            out.push_str("</mark>");
        } else {
            out.push_str(&html_escape(&word));
        }
        pos = e;
    }
    if pos < fs.len() {
        out.push_str(&html_escape(&fs[pos..].iter().collect::<String>()));
    }
    if end < chars.len() {
        out.push_str(" &hellip;");
    }
    out
}

// ---- full-text search ------------------------------------------------------

/// One search hit.
#[derive(Clone, Debug, PartialEq)]
pub struct SearchResult {
    /// Result URL.
    pub url: String,
    /// Title (falls back to the URL).
    pub title: String,
    /// Meta description.
    pub description: String,
    /// The query-biased snippet (HTML).
    pub snippet: String,
    /// Host.
    pub host: String,
    /// Fetch timestamp.
    pub fetched_at: f64,
    /// Final ranking score.
    pub score: f64,
    /// Guessed language.
    pub lang: String,
    /// 64-bit near-dup fingerprint (signed).
    pub simhash: i64,
}

/// A page of search results.
#[derive(Clone, Debug, PartialEq)]
pub struct SearchResponse {
    /// The results on this page.
    pub results: Vec<SearchResult>,
    /// Total matched candidates (capped at the re-rank window).
    pub total: usize,
    /// The parsed query.
    pub query: Query,
}

/// Search options.
#[derive(Clone, Debug)]
pub struct SearchOpts {
    /// 1-based page number.
    pub page: usize,
    /// Results per page.
    pub page_size: usize,
    /// Current time (epoch seconds) for freshness.
    pub now: f64,
    /// `"fresh"` re-orders candidates newest-first; anything else = relevance.
    pub sort: String,
    /// Restrict to downloadable-file content types / URL suffixes.
    pub only_files: bool,
}

impl Default for SearchOpts {
    fn default() -> Self {
        SearchOpts {
            page: 1,
            page_size: 10,
            now: 0.0,
            sort: "relevance".to_string(),
            only_files: false,
        }
    }
}

const FILE_CTS: &[&str] = &[
    "application/pdf",
    "application/zip",
    "application/epub+zip",
    "application/msword",
    "application/vnd.ms-excel",
    "application/vnd.ms-powerpoint",
    "application/x-tar",
    "application/gzip",
    "application/x-7z-compressed",
    "application/rtf",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
];
const FILE_EXTS: &[&str] = &[
    "pdf", "zip", "epub", "doc", "docx", "ppt", "pptx", "xls", "xlsx", "odt", "ods", "odp", "rtf",
    "tar", "gz", "tgz", "bz2", "7z", "rar", "csv", "djvu", "mobi", "azw3",
];
const FILETYPE_CT: &[(&str, &str)] = &[
    ("pdf", "application/pdf"),
    ("txt", "text/plain"),
    ("text", "text/plain"),
    ("html", "text/html"),
    ("htm", "text/html"),
    ("md", "text/markdown"),
    ("markdown", "text/markdown"),
    ("json", "application/json"),
    ("csv", "text/csv"),
    ("xml", "application/xml"),
];

/// A document paired with its memoised text derivation. The [`Document`] itself
/// is read LIVE, so `incoming` / `host_rank` / `fetched_at` reflect the latest
/// `finalize()`; only the tokenisation comes from the cache.
struct DocTokens<'a> {
    doc: &'a Document,
    d: &'a DocDerived,
}

impl DocTokens<'_> {
    fn matches(&self, q: &Query) -> bool {
        for t in &q.required {
            if !self.d.all.contains(t) {
                return false;
            }
        }
        for t in &q.excluded {
            if self.d.all.contains(t) {
                return false;
            }
        }
        if !q.optional.is_empty() && !q.optional.iter().any(|t| self.d.all.contains(t)) {
            return false;
        }
        for t in &q.intitle {
            if !self.d.title_tf.contains_key(t) {
                return false;
            }
        }
        // A phrase is a SUBSTRING test on the lower-cased field, so it needs the
        // text, not the tokens. The caseless copies are built here — once, and
        // only for a query that actually carries a phrase. Building them for
        // every document on every query is most of what made `/search?q=lorem`
        // cost 790 ms on a 200-document × 100 kB corpus.
        if !q.phrases.is_empty() {
            let title_low = self.doc.title.to_lowercase();
            let desc_low = self.doc.description.to_lowercase();
            let body_low = self.doc.body.to_lowercase();
            for phrase in &q.phrases {
                let needle = phrase.join(" ");
                if !(title_low.contains(&needle)
                    || desc_low.contains(&needle)
                    || body_low.contains(&needle))
                {
                    return false;
                }
            }
        }
        true
    }
}

/// The frequency of `term` in a field, from the memoised per-field count map.
fn tf(field: &HashMap<String, u32>, term: &str) -> f64 {
    f64::from(field.get(term).copied().unwrap_or(0))
}

/// Field-weighted Okapi BM25 relevance (positive; larger = better) — the
/// hand-rolled stand-in for FTS5 `-bm25()`.
#[allow(clippy::too_many_arguments)]
fn bm25(
    dt: &DocTokens,
    terms: &[String],
    df: &HashMap<&str, usize>,
    n: usize,
    avg: (f64, f64, f64),
) -> f64 {
    let mut score = 0.0;
    for t in terms {
        let d = *df.get(t.as_str()).unwrap_or(&0);
        if d == 0 {
            continue;
        }
        let idf = (1.0 + (n as f64 - d as f64 + 0.5) / (d as f64 + 0.5)).ln();
        for (weight, fcounts, flen, avglen) in [
            (W_TITLE, &dt.d.title_tf, dt.d.title_len, avg.0),
            (W_DESC, &dt.d.desc_tf, dt.d.desc_len, avg.1),
            (W_BODY, &dt.d.body_tf, dt.d.body_len, avg.2),
        ] {
            let f = tf(fcounts, t);
            if f == 0.0 {
                continue;
            }
            let len = flen as f64;
            let denom = f + BM25_K1 * (1.0 - BM25_B + BM25_B * len / avglen.max(1.0));
            score += weight * idf * (f * (BM25_K1 + 1.0)) / denom;
        }
    }
    score
}

fn passes_filters(doc: &Document, q: &Query, only_files: bool) -> bool {
    if let Some(site) = &q.site {
        if !host_in_site(&doc.host, site) {
            return false;
        }
    }
    // `-site:` excludes the host and everything under it, so blocking a forum
    // blocks its subdomains too — the same scope rule as the positive form.
    if q.not_site.iter().any(|s| host_in_site(&doc.host, s)) {
        return false;
    }
    if let Some(lang) = &q.lang {
        if doc.lang != *lang {
            return false;
        }
    }
    if let Some(ft) = &q.filetype {
        let url_low = doc.url.to_lowercase();
        let suffix_ok = url_low.ends_with(&format!(".{ft}"));
        let ct_ok = FILETYPE_CT
            .iter()
            .find(|(k, _)| k == ft)
            .is_some_and(|(_, ct)| doc.content_type == *ct);
        if !(ct_ok || suffix_ok) {
            return false;
        }
    }
    if let Some(after) = q.after {
        if doc.fetched_at < after {
            return false;
        }
    }
    if let Some(before) = q.before {
        if doc.fetched_at >= before {
            return false;
        }
    }
    if only_files {
        let url_low = doc.url.to_lowercase();
        let ct_ok = FILE_CTS.contains(&doc.content_type.as_str());
        let ext_ok = FILE_EXTS
            .iter()
            .any(|e| url_low.ends_with(&format!(".{e}")));
        if !(ct_ok || ext_ok) {
            return false;
        }
    }
    true
}

/// The explicit ranking score for a matched doc (relevance + link + authority +
/// freshness + proximity + quality + optic). Mirrors the Python `score`.
fn score_doc(dt: &DocTokens, q: &Query, now: f64, relevance: f64) -> f64 {
    let doc = dt.doc;
    let link = K_LINK * (1.0 + doc.incoming.max(0) as f64).ln();
    let authority = K_AUTH * doc.host_rank;
    let fresh = K_FRESH * freshness(doc.fetched_at, now);
    let prox_text = format!("{} . {}", doc.title, doc.body);
    let prox = K_PROX * proximity_bonus(&prox_text, &q.phrases, &q.highlight);
    let quality = K_QUALITY * content_quality(&doc.body);
    let mut optic = 0.0;
    if !q.boost.is_empty() || !q.penalize.is_empty() {
        let host = doc.host.to_lowercase();
        if q.boost.contains(&host) {
            optic += OPTIC_BOOST;
        }
        if q.penalize.contains(&host) {
            optic -= OPTIC_PENALTY;
        }
    }
    let _ = K_PR; // informational pagerank weight (exposed but not summed)
    relevance + link + authority + fresh + prox + quality + optic
}

fn near(a: i64, b: i64, threshold: u32) -> bool {
    // simhashes are stored signed (via `signed64`); the reverse is a bit cast.
    crate::dedup::near(a as u64, b as u64, threshold)
}

/// Run a full-text search over `index`. Returns a page of ranked results plus the
/// total matched (capped at the re-rank window). Mirrors the Python `search`.
#[must_use]
pub fn search(index: &Index, raw_query: &str, opts: &SearchOpts) -> SearchResponse {
    let q = parse_query(raw_query);
    let has_text = !q.is_empty();
    let any_filter = q.has_filter() || opts.only_files;
    if !has_text && !any_filter {
        return SearchResponse {
            results: Vec::new(),
            total: 0,
            query: q,
        };
    }

    // The tokenisation is memoised on the index and invalidated by text writes,
    // so a query pays for it only after a crawl has changed something. The `Arc`
    // is held for the whole search, which is what keeps `&'a DocDerived` valid.
    let derived = index.derived();
    let toks: Vec<DocTokens> = index
        .all_docs()
        .map(|doc| DocTokens {
            doc,
            d: derived
                .docs
                .get(&doc.id)
                .expect("derived cache covers every document"),
        })
        .collect();
    let n = toks.len();

    // Corpus stats for BM25 over the highlight terms.
    let mut df: HashMap<&str, usize> = HashMap::new();
    let (mut sum_t, mut sum_d, mut sum_b) = (0.0, 0.0, 0.0);
    for dt in &toks {
        sum_t += dt.d.title_len as f64;
        sum_d += dt.d.desc_len as f64;
        sum_b += dt.d.body_len as f64;
        for t in &q.highlight {
            if dt.d.all.contains(t) {
                *df.entry(t.as_str()).or_insert(0) += 1;
            }
        }
    }
    let avg = if n == 0 {
        (1.0, 1.0, 1.0)
    } else {
        (sum_t / n as f64, sum_d / n as f64, sum_b / n as f64)
    };

    // Candidate selection + scoring.
    let mut scored: Vec<(f64, &DocTokens)> = Vec::new();
    for dt in &toks {
        if !passes_filters(dt.doc, &q, opts.only_files) {
            continue;
        }
        if has_text && !dt.matches(&q) {
            continue;
        }
        let relevance = if has_text {
            bm25(dt, &q.highlight, &df, n, avg)
        } else {
            0.0
        };
        scored.push((relevance, dt));
    }
    let total_matched = scored.len();

    // Order: text search by score desc; pure-filter browse by authority then recency.
    if has_text {
        let mut ranked: Vec<(f64, &DocTokens)> = scored
            .into_iter()
            .map(|(rel, dt)| (score_doc(dt, &q, opts.now, rel), dt))
            .collect();
        ranked.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap_or(std::cmp::Ordering::Equal));
        ranked.truncate(CANDIDATE_CAP);
        scored = ranked;
    } else {
        scored.sort_by(|a, b| {
            b.1.doc
                .host_rank
                .partial_cmp(&a.1.doc.host_rank)
                .unwrap_or(std::cmp::Ordering::Equal)
                .then(
                    b.1.doc
                        .fetched_at
                        .partial_cmp(&a.1.doc.fetched_at)
                        .unwrap_or(std::cmp::Ordering::Equal),
                )
        });
        scored.truncate(CANDIDATE_CAP);
    }

    if opts.sort == "fresh" {
        scored.sort_by(|a, b| {
            b.1.doc
                .fetched_at
                .partial_cmp(&a.1.doc.fetched_at)
                .unwrap_or(std::cmp::Ordering::Equal)
        });
    }

    // Fuzzy cross-host near-dup collapse.
    let mut kept: Vec<(f64, &DocTokens)> = Vec::new();
    let mut seen: Vec<(i64, String)> = Vec::new();
    for (s, dt) in scored {
        let h = dt.doc.simhash;
        let host = dt.doc.host.clone();
        if h != 0 {
            if seen
                .iter()
                .any(|(kh, khost)| *khost != host && near(h, *kh, SIMHASH_HAMMING))
            {
                continue;
            }
            seen.push((h, host));
        }
        kept.push((s, dt));
    }

    let total = total_matched.min(kept.len());
    let lo = (opts.page.saturating_sub(1)) * opts.page_size;
    let hi = lo + opts.page_size;
    let results = kept
        .iter()
        .skip(lo)
        .take(hi.saturating_sub(lo))
        .map(|(s, dt)| {
            let doc = dt.doc;
            let snippet_src = if doc.body.is_empty() {
                &doc.description
            } else {
                &doc.body
            };
            SearchResult {
                url: doc.url.clone(),
                title: if doc.title.is_empty() {
                    doc.url.clone()
                } else {
                    doc.title.clone()
                },
                description: doc.description.clone(),
                snippet: make_snippet(snippet_src, &q.highlight, 280),
                host: doc.host.clone(),
                fetched_at: doc.fetched_at,
                score: *s,
                lang: doc.lang.clone(),
                simhash: doc.simhash,
            }
        })
        .collect();

    SearchResponse {
        results,
        total,
        query: q,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::index::{DocFields, Index};

    fn doc(ix: &mut Index, url: &str, title: &str, body: &str, host: &str, fa: f64) {
        ix.upsert_document(
            url,
            DocFields {
                title,
                body,
                host,
                lang: "en",
                fetched_at: fa,
                http_status: 200,
                ..DocFields::default()
            },
        );
    }

    #[test]
    fn parse_basic() {
        let q = parse_query("+rust -java \"web crawler\" site:example.com");
        assert_eq!(q.required, vec!["rust"]);
        assert_eq!(q.excluded, vec!["java"]);
        assert_eq!(
            q.phrases,
            vec![vec!["web".to_string(), "crawler".to_string()]]
        );
        assert_eq!(q.site.as_deref(), Some("example.com"));
        assert_eq!(q.highlight, vec!["rust", "web", "crawler"]);
    }

    #[test]
    fn title_weight_ranks_first() {
        let mut ix = Index::new();
        doc(
            &mut ix,
            "http://a/1",
            "Rust guide",
            "some body text about things",
            "a",
            100.0,
        );
        doc(
            &mut ix,
            "http://a/2",
            "Other",
            "a long body that mentions rust once here",
            "a",
            100.0,
        );
        let resp = search(&ix, "rust", &SearchOpts::default());
        assert_eq!(resp.total, 2);
        assert_eq!(resp.results[0].url, "http://a/1"); // title match wins
    }

    #[test]
    fn phrase_and_exclude_and_filter() {
        let mut ix = Index::new();
        doc(
            &mut ix,
            "http://a/1",
            "T1",
            "the web crawler is fast",
            "a",
            100.0,
        );
        doc(
            &mut ix,
            "http://a/2",
            "T2",
            "web and crawler apart here",
            "a",
            100.0,
        );
        doc(
            &mut ix,
            "http://b/3",
            "T3",
            "the web crawler is fast",
            "b",
            100.0,
        );
        // phrase must be adjacent
        let r = search(&ix, "\"web crawler\"", &SearchOpts::default());
        let urls: Vec<&str> = r.results.iter().map(|x| x.url.as_str()).collect();
        assert!(urls.contains(&"http://a/1") && urls.contains(&"http://b/3"));
        assert!(!urls.contains(&"http://a/2"));
        // site filter narrows to host a
        let r2 = search(&ix, "web site:a", &SearchOpts::default());
        assert!(r2.results.iter().all(|x| x.host == "a"));
    }

    #[test]
    fn snippet_escapes_and_marks() {
        let s = make_snippet(
            "<script>alert(1)</script> the target word here",
            &["target".to_string()],
            280,
        );
        assert!(s.contains("&lt;script&gt;"));
        assert!(s.contains("<mark>target</mark>"));
        assert!(!s.contains("<script>"));
    }
}

#[cfg(test)]
mod audit_regression {
    use super::*;

    /// The window search exactly as it was written before the fix: for every hit,
    /// count every hit inside `[h, h + width)` and keep the first maximum. Kept
    /// here as the differential oracle — the goldens pin the chosen snippet, so
    /// the replacement must agree with this on every input, not merely be faster.
    fn best_window_start_quadratic(hits: &[usize], width: usize) -> usize {
        let mut best_pos = hits[0];
        let mut best_count = 0usize;
        for &h in hits {
            let (lo, hi) = (h, h + width);
            let c = hits.iter().filter(|&&x| lo <= x && x < hi).count();
            if c > best_count {
                best_count = c;
                best_pos = h;
            }
        }
        best_pos
    }

    /// A deterministic xorshift64* — no `rand` crate, and a failure reproduces.
    struct Rng(u64);
    impl Rng {
        fn next(&mut self) -> u64 {
            self.0 ^= self.0 << 13;
            self.0 ^= self.0 >> 7;
            self.0 ^= self.0 << 17;
            self.0
        }
        fn below(&mut self, n: usize) -> usize {
            (self.next() % (n as u64).max(1)) as usize
        }
    }

    /// AUDIT REGRESSION (HIGH). The linear two-pointer window must choose the
    /// SAME start as the quadratic loop it replaced, over many hit shapes:
    /// clustered, sparse, single, all-identical-gap, and widths from 0 to wider
    /// than the whole hit range.
    #[test]
    fn the_linear_window_picks_the_same_start_as_the_quadratic_one() {
        let mut rng = Rng(0x2545_F491_4F6C_DD1D);
        for case in 0..2000u32 {
            let n = 1 + rng.below(40);
            let spread = 1 + rng.below(4) * 30; // dense clusters .. sparse hits
            let mut hits = Vec::with_capacity(n);
            let mut pos = rng.below(50);
            for _ in 0..n {
                hits.push(pos);
                pos += 1 + rng.below(spread);
            }
            for width in [0usize, 1, 2, 7, 30, 60, 280, 1000, 100_000] {
                let fast = best_window_start(&hits, width);
                let slow = best_window_start_quadratic(&hits, width);
                assert_eq!(
                    fast, slow,
                    "case {case} width {width} disagreed on hits {hits:?}"
                );
            }
        }
    }

    /// The same equivalence at the level the goldens actually pin: the rendered
    /// snippet string, over random bodies and term sets.
    #[test]
    fn the_rendered_snippet_is_unchanged_for_normal_bodies() {
        let mut rng = Rng(0x9E37_79B9_7F4A_7C15);
        let vocab = ["alpha", "beta", "gamma", "delta", "eps", "zeta", "x", "yy"];
        for _ in 0..400 {
            let words: Vec<&str> = (0..1 + rng.below(300))
                .map(|_| vocab[rng.below(vocab.len())])
                .collect();
            let body = words.join(" ");
            let terms: Vec<String> = (0..1 + rng.below(3))
                .map(|_| vocab[rng.below(vocab.len())].to_string())
                .collect();
            let width = [40usize, 60, 280][rng.below(3)];

            // Reproduce the pre-fix pipeline: full body, quadratic window.
            let termset: HashSet<String> = terms.iter().map(|t| t.to_lowercase()).collect();
            let chars: Vec<char> = body.chars().collect();
            let low = lower1(&chars);
            let spans = word_spans(&low);
            let hits: Vec<usize> = spans
                .iter()
                .filter(|&&(s, e)| termset.contains(&low[s..e].iter().collect::<String>()))
                .map(|&(s, _)| s)
                .collect();
            let expected_start = if hits.is_empty() {
                0
            } else {
                best_window_start_quadratic(&hits, width).saturating_sub(width / 4)
            };
            let got_start = if hits.is_empty() {
                0
            } else {
                best_window_start(&hits, width).saturating_sub(width / 4)
            };
            assert_eq!(expected_start, got_start, "body {body:?} terms {terms:?}");
            // …and the function as a whole still renders something anchored there.
            let s = make_snippet(&body, &terms, width);
            assert_eq!(s.starts_with("&hellip; "), expected_start > 0);
        }
    }

    /// AUDIT REGRESSION (HIGH). 256 kB of `"a "` with `a` as the term is 128k hit
    /// positions; the quadratic window took **12.85 s** on it, ten times per
    /// results page, holding the index mutex throughout. The linear window plus
    /// the scan cap put it in single-digit milliseconds.
    ///
    /// What is asserted is the step count, not the clock. The bound here was
    /// `dt < 300 ms`, a number taken from release timings — CI builds tests
    /// unoptimised, where the same call costs 10-50× more — and under contention
    /// on two cores it measured 313 to 424 ms over fifteen runs, failing all
    /// fifteen. Steps do not depend on the machine: over the
    /// [`SNIPPET_SCAN_CHARS`] the snippet actually scans there are 32 768 hits,
    /// the two-pointer walk takes exactly 98 303 steps, and the nested form it
    /// replaced takes 32 768² ≈ 1.1e9 comparisons. Four orders of magnitude is a
    /// gap no scheduler can close in either direction.
    ///
    /// The count is pinned exactly rather than bounded from above, because an
    /// upper bound is satisfied by zero and zero is what the regression produces:
    /// [`best_window_start`]'s step tally is a reading of `lo`/`hi`, and the
    /// nested rewrite has no `lo`/`hi`, so restoring it deletes the tally with
    /// them. That mutation compiles, emits no clippy warning under `-D warnings`,
    /// and passed `steps <= 4 * hits` — a wall-clock bound would not have been a
    /// reliable backstop either. Against `assert_eq!` it reports 0 and fails.
    ///
    /// This pins the shape of the window search, not the cost of `make_snippet`
    /// as a whole; `the_rendered_snippet_is_unchanged_for_normal_bodies` above is
    /// what pins the answer it produces.
    #[test]
    fn a_body_of_nothing_but_hits_is_not_quadratic() {
        let body = "a ".repeat(128 * 1024);
        let terms = vec!["a".to_string()];
        // One hit every two characters, over the scanned prefix of the body.
        let hits = SNIPPET_SCAN_CHARS / 2;
        assert_eq!(hits, 32_768, "the input the step count below is taken over");
        let (s, steps) = counting_window_steps(|| make_snippet(&body, &terms, 280));
        assert!(s.contains("<mark>a</mark>"));
        // 98 303 = 32 768 loop bodies + `lo`'s final 32 767 (it halts on the last
        // hit, never past it) + `hi`'s final 32 768 (the last window runs off the
        // end of the body). Three per hit is the two-pointer walk's ceiling and
        // this input all but reaches it; the nested form would be ~32 768², and a
        // walk that stopped reporting its pointers would be 0.
        assert_eq!(
            steps, 98_303,
            "the window search took {steps} steps over {hits} hits, not 98 303: \
             above that it is quadratic again, below it the counted pointers are \
             no longer the ones doing the work"
        );
    }
}
