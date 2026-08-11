//! Query parsing, scoring, snippets, and full-text search over the [`Index`].
//!
//! A port of the Python `websearch.ranking`. The **pure** pieces — `parse_query`
//! (the query language: quoted phrases, `+required` / `-excluded` / optional
//! terms, and `site:`/`host:`/`lang:`/`filetype:`/`intitle:`/`before:`/`after:`/
//! `date:`/`boost:`/`penalize:` operators), the scoring components
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

use crate::index::{Document, Index};
use std::collections::{HashMap, HashSet};

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

/// Split a raw query into tokens: a `"quoted string"` (possibly with spaces) or a
/// run of non-whitespace — the Python `_TOKEN = "[^"]*"|\S+`.
fn tokenize_query(raw: &str) -> Vec<String> {
    let chars: Vec<char> = raw.chars().collect();
    let n = chars.len();
    let mut i = 0;
    let mut out = Vec::new();
    while i < n {
        if chars[i].is_whitespace() {
            i += 1;
            continue;
        }
        if chars[i] == '"' {
            // A complete "..." (quoted alternative) is tried first; it may hold
            // spaces. If there is no closing quote, fall through to the \S+ run.
            if let Some(close) = (i + 1..n).find(|&j| chars[j] == '"') {
                out.push(chars[i..=close].iter().collect());
                i = close + 1;
                continue;
            }
        }
        let start = i;
        while i < n && !chars[i].is_whitespace() {
            i += 1;
        }
        out.push(chars[start..i].iter().collect());
    }
    out
}

// ---- query -----------------------------------------------------------------

/// A parsed query.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Query {
    /// The raw input.
    pub raw: String,
    /// Plain terms (an OR group).
    pub optional: Vec<String>,
    /// `+term` (required).
    pub required: Vec<String>,
    /// `-term` (excluded).
    pub excluded: Vec<String>,
    /// Quoted phrases (each a list of words).
    pub phrases: Vec<Vec<String>>,
    /// Every positive word, for snippet highlighting (order-preserving unique).
    pub highlight: Vec<String>,
    /// `intitle:` terms.
    pub intitle: Vec<String>,
    /// `site:` / `host:` host-suffix filter.
    pub site: Option<String>,
    /// `lang:` two-letter filter.
    pub lang: Option<String>,
    /// `filetype:` extension/type filter.
    pub filetype: Option<String>,
    /// `after:` / `date:` lower bound (epoch seconds).
    pub after: Option<f64>,
    /// `before:` / `date:` upper bound (epoch seconds).
    pub before: Option<f64>,
    /// `boost:host` ranking optic.
    pub boost: Vec<String>,
    /// `penalize:host` ranking optic.
    pub penalize: Vec<String>,
}

impl Query {
    /// True if there is nothing to text-match on.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.optional.is_empty()
            && self.required.is_empty()
            && self.phrases.is_empty()
            && self.intitle.is_empty()
    }

    /// True if any structured filter is set.
    #[must_use]
    pub fn has_filter(&self) -> bool {
        self.site.is_some()
            || self.lang.is_some()
            || self.filetype.is_some()
            || self.after.is_some()
            || self.before.is_some()
    }
}

const OPERATORS: &[&str] = &[
    "site", "host", "lang", "filetype", "intitle", "before", "after", "date", "boost", "penalize",
];

/// Parse `YYYY-MM-DD` into UTC epoch seconds, or `None`.
#[must_use]
pub fn parse_date(value: &str) -> Option<f64> {
    let v = value.trim();
    let parts: Vec<&str> = v.split('-').collect();
    if parts.len() != 3 {
        return None;
    }
    let y: i64 = parts[0].parse().ok()?;
    let m: i64 = parts[1].parse().ok()?;
    let d: i64 = parts[2].parse().ok()?;
    if parts[0].len() != 4 || parts[1].len() != 2 || parts[2].len() != 2 {
        return None;
    }
    if !(1..=12).contains(&m) || !(1..=31).contains(&d) {
        return None;
    }
    Some((days_from_civil(y, m, d) * 86400) as f64)
}

/// Days since the Unix epoch for a proleptic-Gregorian date (Hinnant's algorithm).
fn days_from_civil(y: i64, m: i64, d: i64) -> i64 {
    let y = if m <= 2 { y - 1 } else { y };
    let era = if y >= 0 { y } else { y - 399 } / 400;
    let yoe = y - era * 400; // [0, 399]
    let doy = (153 * (if m > 2 { m - 3 } else { m + 9 }) + 2) / 5 + d - 1; // [0, 365]
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy; // [0, 146096]
    era * 146097 + doe - 719468
}

fn apply_operator(q: &mut Query, key: &str, value: &str) {
    let key = key.to_lowercase();
    let value = value.trim();
    if value.is_empty() {
        return;
    }
    match key.as_str() {
        "site" | "host" => {
            q.site = Some(
                value
                    .to_lowercase()
                    .trim_matches('/')
                    .trim_start_matches('.')
                    .to_string(),
            );
        }
        "lang" => {
            if let Some(w) = words(value).into_iter().next() {
                q.lang = Some(w.chars().take(8).collect());
            }
        }
        "filetype" => {
            if let Some(ft) = words(value).into_iter().next() {
                q.filetype = Some(ft);
            }
        }
        "intitle" => {
            for w in words(value) {
                q.intitle.push(w.clone());
                q.highlight.push(w);
            }
        }
        "before" => {
            if let Some(ts) = parse_date(value) {
                q.before = Some(ts);
            }
        }
        "after" => {
            if let Some(ts) = parse_date(value) {
                q.after = Some(ts);
            }
        }
        "date" => {
            let (lo, hi) = match value.split_once("..") {
                Some((a, b)) => (a, Some(b)),
                None => (value, None),
            };
            if let Some(a) = parse_date(lo) {
                q.after = Some(a);
            }
            if let Some(h) = hi {
                if !h.is_empty() {
                    if let Some(b) = parse_date(h) {
                        q.before = Some(b + 86400.0);
                    }
                }
            }
        }
        "boost" | "penalize" => {
            let h = value
                .to_lowercase()
                .trim_matches('/')
                .trim_start_matches('.')
                .to_string();
            if !h.is_empty() {
                if key == "boost" {
                    q.boost.push(h);
                } else {
                    q.penalize.push(h);
                }
            }
        }
        _ => {}
    }
}

fn split_operator(tok: &str) -> Option<(&str, &str)> {
    let (key, value) = tok.split_once(':')?;
    if value.is_empty() {
        return None;
    }
    if OPERATORS.contains(&key.to_lowercase().as_str()) {
        Some((key, value))
    } else {
        None
    }
}

/// Parse a user query into a structured [`Query`]. All free text is reduced to
/// word tokens (no user input can reach a matcher as an operator).
#[must_use]
pub fn parse_query(raw: &str) -> Query {
    let mut q = Query {
        raw: raw.to_string(),
        ..Query::default()
    };
    for tok in tokenize_query(raw) {
        if tok.starts_with('"') && tok.ends_with('"') && tok.chars().count() >= 2 {
            let ws = words(&tok);
            if ws.len() >= 2 {
                q.highlight.extend(ws.iter().cloned());
                q.phrases.push(ws);
            } else if let Some(w) = ws.into_iter().next() {
                q.required.push(w.clone());
                q.highlight.push(w);
            }
            continue;
        }
        if let Some((key, value)) = split_operator(&tok) {
            apply_operator(&mut q, key, value);
            continue;
        }
        let mut t = tok.as_str();
        let sign = t.chars().next().filter(|c| *c == '+' || *c == '-');
        if sign.is_some() {
            t = &t[1..];
        }
        let ws = words(t);
        if ws.is_empty() {
            continue;
        }
        match sign {
            Some('-') => q.excluded.extend(ws),
            Some('+') => {
                q.highlight.extend(ws.iter().cloned());
                q.required.extend(ws);
            }
            _ => {
                q.highlight.extend(ws.iter().cloned());
                q.optional.extend(ws);
            }
        }
    }
    // de-duplicate highlight while preserving order
    let mut seen = HashSet::new();
    q.highlight.retain(|w| seen.insert(w.clone()));
    q
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

/// A query-biased, HTML-safe snippet with matched `terms` wrapped in `<mark>`.
/// The text is HTML-escaped first, then whole-word matches highlighted. Mirrors
/// the Python `make_snippet` (window selection quirks included).
#[must_use]
pub fn make_snippet(body: &str, terms: &[String], width: usize) -> String {
    if body.is_empty() {
        return String::new();
    }
    let termset: HashSet<String> = terms.iter().map(|t| t.to_lowercase()).collect();
    let chars: Vec<char> = body.chars().collect();
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
            let mut best_pos = hits[0];
            let mut best_count = 0usize;
            for &h in &hits {
                let (lo, hi) = (h, h + width);
                let c = hits.iter().filter(|&&x| lo <= x && x < hi).count();
                if c > best_count {
                    best_count = c;
                    best_pos = h;
                }
            }
            start = best_pos.saturating_sub(width / 4);
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

struct DocTokens<'a> {
    doc: &'a Document,
    title_words: Vec<String>,
    desc_words: Vec<String>,
    body_words: Vec<String>,
    all: HashSet<String>,
    title_set: HashSet<String>,
    title_low: String,
    desc_low: String,
    body_low: String,
}

impl<'a> DocTokens<'a> {
    fn new(doc: &'a Document) -> Self {
        let title_words = words(&doc.title);
        let desc_words = words(&doc.description);
        let body_words = words(&doc.body);
        let mut all: HashSet<String> = HashSet::new();
        all.extend(title_words.iter().cloned());
        all.extend(desc_words.iter().cloned());
        all.extend(body_words.iter().cloned());
        let title_set: HashSet<String> = title_words.iter().cloned().collect();
        DocTokens {
            doc,
            title_words,
            desc_words,
            body_words,
            all,
            title_set,
            title_low: doc.title.to_lowercase(),
            desc_low: doc.description.to_lowercase(),
            body_low: doc.body.to_lowercase(),
        }
    }

    fn matches(&self, q: &Query) -> bool {
        for t in &q.required {
            if !self.all.contains(t) {
                return false;
            }
        }
        for t in &q.excluded {
            if self.all.contains(t) {
                return false;
            }
        }
        if !q.optional.is_empty() && !q.optional.iter().any(|t| self.all.contains(t)) {
            return false;
        }
        for t in &q.intitle {
            if !self.title_set.contains(t) {
                return false;
            }
        }
        for phrase in &q.phrases {
            let needle = phrase.join(" ");
            if !(self.title_low.contains(&needle)
                || self.desc_low.contains(&needle)
                || self.body_low.contains(&needle))
            {
                return false;
            }
        }
        true
    }
}

fn tf(words: &[String], term: &str) -> f64 {
    words.iter().filter(|w| w.as_str() == term).count() as f64
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
        for (weight, fwords, avglen) in [
            (W_TITLE, &dt.title_words, avg.0),
            (W_DESC, &dt.desc_words, avg.1),
            (W_BODY, &dt.body_words, avg.2),
        ] {
            let f = tf(fwords, t);
            if f == 0.0 {
                continue;
            }
            let len = fwords.len() as f64;
            let denom = f + BM25_K1 * (1.0 - BM25_B + BM25_B * len / avglen.max(1.0));
            score += weight * idf * (f * (BM25_K1 + 1.0)) / denom;
        }
    }
    score
}

fn passes_filters(doc: &Document, q: &Query, only_files: bool) -> bool {
    if let Some(site) = &q.site {
        if !(doc.host == *site || doc.host.ends_with(&format!(".{site}"))) {
            return false;
        }
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

    let toks: Vec<DocTokens> = index.all_docs().map(DocTokens::new).collect();
    let n = toks.len();

    // Corpus stats for BM25 over the highlight terms.
    let mut df: HashMap<&str, usize> = HashMap::new();
    let (mut sum_t, mut sum_d, mut sum_b) = (0.0, 0.0, 0.0);
    for dt in &toks {
        sum_t += dt.title_words.len() as f64;
        sum_d += dt.desc_words.len() as f64;
        sum_b += dt.body_words.len() as f64;
        for t in &q.highlight {
            if dt.all.contains(t) {
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
