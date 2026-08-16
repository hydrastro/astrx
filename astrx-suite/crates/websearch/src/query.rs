//! The query language — tokenising and parsing what a user types into a
//! structured [`Query`] the matcher and the filters can act on.
//!
//! Split out of [`crate::ranking`] so the language has one home: the syntax, its
//! operator table, and the tests that pin every awkward shape (unmatched quotes,
//! an operator with no value, a bare term that merely looks like one) live
//! together, and the scorer imports a parsed structure rather than owning a
//! parser.
//!
//! # The language
//!
//! | form | meaning |
//! |------|---------|
//! | `term` | optional — a document matching ANY optional term is a candidate |
//! | `+term` | required |
//! | `-term` | excluded |
//! | `"two words"` | phrase: the words in that order, adjacent |
//! | `site:example.com`, `host:` | restrict to that host and its subdomains |
//! | `-site:example.com` | exclude that host and its subdomains (repeatable) |
//! | `filetype:pdf` | restrict by content type or URL suffix |
//! | `lang:en` | restrict by detected language |
//! | `intitle:word` | the word must be in the title |
//! | `before:YYYY-MM-DD`, `after:` | crawl-date bounds |
//! | `date:A..B` | both bounds at once |
//! | `boost:host`, `penalize:host` | per-query ranking optics |
//!
//! Two rules keep the language predictable, and both are load-bearing:
//!
//! * **An unadorned query parses exactly as it always did.** `rust programming`
//!   yields the same `optional`/`highlight` lists as before this module existed —
//!   `tests/xcheck_ranking.rs` pins that against the retired Python reference.
//! * **A token is an operator only if its key is in [`OPERATORS`].** `https://x/a:b`
//!   contains a colon but `https` is not an operator, so it stays a plain term;
//!   no user input can reach a matcher as syntax.

use crate::ranking::words;
use std::collections::HashSet;

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
    /// `-site:` / `-host:` exclusions. Repeatable, unlike the positive form:
    /// "everything except these two forums" is a real request, whereas two
    /// positive `site:` filters would contradict each other.
    pub not_site: Vec<String>,
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
            || !self.not_site.is_empty()
            || self.lang.is_some()
            || self.filetype.is_some()
            || self.after.is_some()
            || self.before.is_some()
    }
}

/// Whether `host` is `suffix` or a subdomain of it — the host-scope rule both
/// `site:` and `-site:` use.
///
/// Suffix matching is on LABEL boundaries: `site:example.com` covers
/// `www.example.com` but not `notexample.com`, which a plain `ends_with` would
/// wrongly include.
#[must_use]
pub fn host_in_site(host: &str, suffix: &str) -> bool {
    host == suffix || host.ends_with(&format!(".{suffix}"))
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

/// Normalise an operator's host value: lower-case, no wrapping slashes, no
/// leading dot (so `site:.Example.com/` and `site:example.com` are one filter).
fn host_value(value: &str) -> String {
    value
        .to_lowercase()
        .trim_matches('/')
        .trim_start_matches('.')
        .to_string()
}

/// Apply one operator. `negated` is set for the `-op:value` form.
fn apply_operator(q: &mut Query, key: &str, value: &str, negated: bool) {
    let key = key.to_lowercase();
    let value = value.trim();
    if value.is_empty() {
        return;
    }
    if negated {
        // Only the host filter has a meaningful negation today. Any other
        // negated operator is dropped rather than exploded into `excluded`
        // words: `-lang:en` used to add "lang" and "en" as excluded TERMS, which
        // silently threw away every document containing the word "lang".
        if key == "site" || key == "host" {
            let h = host_value(value);
            if !h.is_empty() && !q.not_site.contains(&h) {
                q.not_site.push(h);
            }
        }
        return;
    }
    match key.as_str() {
        "site" | "host" => {
            q.site = Some(host_value(value));
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
            let h = host_value(value);
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

/// Split `key:value` when `key` names an operator, honouring a leading `-`.
///
/// Returns `None` for a token that merely CONTAINS a colon — `https://x/a:b`
/// splits to `("https", "//x/a:b")`, and `https` is not an operator, so the whole
/// token stays a plain search term. Also `None` when the value is empty
/// (`site:`), which leaves `site:` itself to be tokenised as the word "site"
/// rather than installing a filter on nothing.
fn split_operator(tok: &str) -> Option<(&str, &str, bool)> {
    let (negated, body) = match tok.strip_prefix('-') {
        Some(rest) => (true, rest),
        None => (false, tok),
    };
    let (key, value) = body.split_once(':')?;
    if value.is_empty() {
        return None;
    }
    if OPERATORS.contains(&key.to_lowercase().as_str()) {
        Some((key, value, negated))
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
        if let Some((key, value, negated)) = split_operator(&tok) {
            apply_operator(&mut q, key, value, negated);
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

#[cfg(test)]
mod tests {
    use super::*;

    fn sv(v: &[&str]) -> Vec<String> {
        v.iter().map(|s| (*s).to_string()).collect()
    }

    /// The baseline the goldens pin: a query with no syntax in it parses to the
    /// same lists it always has. Everything else in this module is additive.
    #[test]
    fn a_bare_query_is_just_optional_terms() {
        let q = parse_query("rust programming");
        assert_eq!(q.optional, sv(&["rust", "programming"]));
        assert_eq!(q.highlight, sv(&["rust", "programming"]));
        assert!(q.required.is_empty() && q.excluded.is_empty() && q.phrases.is_empty());
        assert!(!q.has_filter());
        assert_eq!(q.raw, "rust programming");
    }

    #[test]
    fn signs_and_phrases() {
        let q = parse_query("+rust -java \"web crawler\"");
        assert_eq!(q.required, sv(&["rust"]));
        assert_eq!(q.excluded, sv(&["java"]));
        assert_eq!(q.phrases, vec![sv(&["web", "crawler"])]);
        // Excluded words are NOT highlighted; required and phrase words are.
        assert_eq!(q.highlight, sv(&["rust", "web", "crawler"]));
    }

    /// Quoting edge cases. A one-word quote is a REQUIRED term, not a phrase (a
    /// one-word "phrase" has no adjacency to enforce); an empty quote adds
    /// nothing; punctuation inside a quote is dropped by the word tokeniser.
    #[test]
    fn quoting_shapes() {
        assert_eq!(parse_query("\"alpha\"").required, sv(&["alpha"]));
        assert!(parse_query("\"alpha\"").phrases.is_empty());

        let empty = parse_query("\"\"");
        assert!(empty.required.is_empty() && empty.phrases.is_empty());

        let punct = parse_query("\"hello, world!\"");
        assert_eq!(punct.phrases, vec![sv(&["hello", "world"])]);

        // Two phrases in one query, each kept whole and in order.
        let two = parse_query("\"web crawler\" \"search engine\"");
        assert_eq!(
            two.phrases,
            vec![sv(&["web", "crawler"]), sv(&["search", "engine"])]
        );
    }

    /// A quote inside a quote is not nesting — the first closing quote ends the
    /// token, and the remainder is tokenised as ordinary text. This is the shape
    /// a user produces by pasting a sentence that already contains quotes.
    #[test]
    fn quotes_do_not_nest() {
        let q = parse_query("\"a \"b\" c\"");
        // The first `"…"` run is `"a "` — one word, so a required term. What is
        // left (`b"` and `c"`) has no opening quote and tokenises as plain words.
        assert_eq!(q.required, sv(&["a"]));
        assert_eq!(q.optional, sv(&["b", "c"]));
        assert!(
            q.phrases.is_empty(),
            "a stray inner quote invented a phrase"
        );
    }

    /// An UNMATCHED quote must not swallow the rest of the query or be dropped:
    /// the token falls back to the plain non-whitespace run, so the words still
    /// search.
    #[test]
    fn an_unmatched_quote_degrades_to_plain_terms() {
        let q = parse_query("\"unclosed phrase here");
        assert_eq!(q.optional, sv(&["unclosed", "phrase", "here"]));
        assert!(q.phrases.is_empty());

        // …including when the stray quote is at the end.
        let q2 = parse_query("alpha beta\"");
        assert_eq!(q2.optional, sv(&["alpha", "beta"]));

        // …and a lone quote character is simply no words at all.
        assert!(parse_query("\"").is_empty());
    }

    #[test]
    fn host_filters_positive_and_negative() {
        let q = parse_query("cats site:example.com");
        assert_eq!(q.site.as_deref(), Some("example.com"));
        assert_eq!(q.optional, sv(&["cats"]));

        // Normalisation: case, a trailing slash and a leading dot all collapse.
        assert_eq!(
            parse_query("host:.EXAMPLE.com/").site.as_deref(),
            Some("example.com")
        );

        // `-site:` is repeatable and de-duplicated; it does not become excluded
        // WORDS, which is what the parser used to do with it.
        let n = parse_query("cats -site:spam.example -site:ads.example -site:spam.example");
        assert_eq!(n.not_site, sv(&["spam.example", "ads.example"]));
        assert!(
            n.excluded.is_empty(),
            "'-site:x' leaked into excluded terms"
        );
        assert_eq!(n.optional, sv(&["cats"]));
        assert!(n.has_filter());

        // Subdomains are in scope for both directions; a lookalike host is not.
        assert!(host_in_site("www.example.com", "example.com"));
        assert!(host_in_site("example.com", "example.com"));
        assert!(!host_in_site("notexample.com", "example.com"));
        assert!(!host_in_site("example.com.evil.net", "example.com"));
    }

    #[test]
    fn filetype_lang_intitle_and_dates() {
        let q = parse_query("intitle:Rust filetype:PDF lang:EN before:2020-01-01 after:2019-01-01");
        assert_eq!(q.intitle, sv(&["rust"]));
        assert_eq!(q.filetype.as_deref(), Some("pdf"));
        assert_eq!(q.lang.as_deref(), Some("en"));
        assert_eq!(q.before, Some(1_577_836_800.0));
        assert_eq!(q.after, Some(1_546_300_800.0));

        // `date:A..B` sets both bounds, with the upper one inclusive of that day.
        let r = parse_query("date:2020-01-01..2020-01-02");
        assert_eq!(r.after, Some(1_577_836_800.0));
        assert_eq!(r.before, Some(1_577_923_200.0 + 86_400.0));

        // An unparseable date leaves the bound unset rather than filtering to
        // nothing (or to everything since 1970).
        let bad = parse_query("before:notadate after:2020-13-45");
        assert_eq!(bad.before, None);
        assert_eq!(bad.after, None);
    }

    /// An operator with NO value is not an operator. `site:` installs no filter;
    /// the token is tokenised as the ordinary word "site", so the query still
    /// searches for something instead of silently matching everything.
    #[test]
    fn an_operator_without_a_value_is_a_word() {
        for raw in ["site:", "before:", "filetype:", "-site:"] {
            let q = parse_query(raw);
            assert!(!q.has_filter(), "{raw} installed a filter from nothing");
        }
        // The valueless token survives as an ordinary word, keeping its sign.
        assert_eq!(parse_query("site:").optional, sv(&["site"]));
        assert!(!parse_query("site:").is_empty());
        assert_eq!(parse_query("-site:").excluded, sv(&["site"]));
        // A bare colon, or a colon-only token, is harmless.
        assert!(parse_query(":").is_empty());
        assert!(parse_query("::").is_empty());
    }

    /// A term that merely LOOKS like an operator stays a term. This is the case
    /// that makes "split on the first colon" unsafe on its own: every URL a user
    /// pastes contains one.
    #[test]
    fn a_term_that_looks_like_an_operator_is_not_one() {
        let q = parse_query("https://x/a:b");
        assert!(!q.has_filter());
        assert_eq!(q.optional, sv(&["https", "x", "a", "b"]));

        // An unknown key is likewise just text, colon and all.
        let u = parse_query("author:knuth");
        assert!(!u.has_filter());
        assert_eq!(u.optional, sv(&["author", "knuth"]));

        // …and a real operator's name used as a plain word is a plain word.
        let w = parse_query("site of the year");
        assert!(w.site.is_none());
        assert_eq!(w.optional, sv(&["site", "of", "the", "year"]));
    }

    /// Negation is defined for the host filter only. Any other negated operator
    /// is dropped, NOT exploded into excluded words — `-lang:en` used to exclude
    /// every document containing the word "lang".
    #[test]
    fn negating_an_operator_without_a_negation_drops_it() {
        let q = parse_query("cats -lang:en");
        assert!(q.lang.is_none());
        assert!(q.excluded.is_empty(), "-lang:en became excluded terms");
        assert_eq!(q.optional, sv(&["cats"]));
    }

    #[test]
    fn optics_are_collected_in_order() {
        let q = parse_query("boost:good.com penalize:bad.com boost:also.good");
        assert_eq!(q.boost, sv(&["good.com", "also.good"]));
        assert_eq!(q.penalize, sv(&["bad.com"]));
    }

    /// Operators are case-insensitive on the key as well as the value, and the
    /// whole language survives being crammed together with odd whitespace.
    #[test]
    fn keys_are_case_insensitive_and_whitespace_is_irrelevant() {
        let q = parse_query("  SITE:Example.COM\t+Rust\n\"Web Crawler\"   -Java  ");
        assert_eq!(q.site.as_deref(), Some("example.com"));
        assert_eq!(q.required, sv(&["rust"]));
        assert_eq!(q.excluded, sv(&["java"]));
        assert_eq!(q.phrases, vec![sv(&["web", "crawler"])]);
    }

    /// Highlight terms are order-preserving and de-duplicated, and never include
    /// an excluded word (the snippet must not mark what the user ruled out).
    #[test]
    fn highlight_is_deduplicated_and_positive_only() {
        let q = parse_query("rust rust +rust \"rust lang\" -rust");
        assert_eq!(q.highlight, sv(&["rust", "lang"]));
        assert_eq!(q.excluded, sv(&["rust"]));
    }

    /// An empty or whitespace-only query yields an empty parse with no filters —
    /// the shape the server checks before deciding to render the home page.
    #[test]
    fn an_empty_query_is_empty() {
        for raw in ["", "   ", "\t\n"] {
            let q = parse_query(raw);
            assert!(q.is_empty() && !q.has_filter());
            assert_eq!(q.raw, raw);
        }
    }
}
