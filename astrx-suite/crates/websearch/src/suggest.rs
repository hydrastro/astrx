//! Query autocomplete: prefix completion + a bounded edit-distance fallback — a
//! dependency-free port of the Python `websearch.suggest`.
//!
//! Two signals feed the `/suggest` endpoint (see [`crate::serve`]):
//!
//!   * **prefix completion** of the final query word from the indexed term
//!     dictionary ([`Index::vocab_prefix`]) and from recently-popular queries
//!     (the `popular` argument), and
//!   * an edit-distance **"did you mean"** fallback over a *bounded* sample of
//!     terms ([`Index::vocab_candidates`]), used only when the prefix pass is
//!     thin.
//!
//! Everything is bounded so a long or adversarial query cannot burn CPU: the
//! query is length-capped, the fuzzy pass scans only a capped candidate set and
//! only for fragments of a minimum length, each [`levenshtein`] call early-exits
//! once it provably exceeds the cap, and the suggestion list itself is capped.
//!
//! # Fidelity notes
//!
//! * **Diacritics.** The term dictionary is tokenised with
//!   [`crate::ranking::words`] (alphanumeric-run split + lowercase), the same FTS5
//!   `unicode61` stand-in the BM25 search uses. It reproduces SQLite on the
//!   ASCII/diacritic-free subset but does NOT fold diacritics (`unicode61
//!   remove_diacritics 2`) — the identical "behaviourally faithful" standard as
//!   the BM25 path. [`levenshtein`] itself is fully Unicode-correct (it compares
//!   by code point).
//! * **PopularQueries / step (0).** [`suggest`] ports the popular-query pass
//!   faithfully, but the `/suggest` endpoint intentionally passes an EMPTY
//!   `popular` slice — the in-process recently-popular tracker (`PopularQueries`)
//!   is not implemented — so step (0) is exercised only by direct callers/tests.

use crate::index::{Index, FUZZY_SCAN_CAP};
use std::collections::HashSet;

/// Maximum suggestions returned (Python `MAX_SUGGESTIONS`).
pub const MAX_SUGGESTIONS: usize = 10;
/// The query is truncated to this many code points before lowercasing (Python
/// `MAX_QUERY_LEN`).
pub const MAX_QUERY_LEN: usize = 64;
/// The fuzzy pass ignores final fragments shorter than this (Python
/// `FUZZY_MIN_LEN`).
pub const FUZZY_MIN_LEN: usize = 3;

/// Levenshtein edit distance between `a` and `b`, capped at `max_dist`.
///
/// Compares by CODE POINT (not byte), and returns `max_dist + 1` as soon as the
/// distance provably exceeds `max_dist` — via a length-difference shortcut and a
/// per-row minimum early exit — so callers can rely on the bound rather than
/// paying full `O(len(a) * len(b))` for obviously-distant pairs. An exact port of
/// the Python `levenshtein`.
#[must_use]
pub fn levenshtein(a: &str, b: &str, max_dist: usize) -> usize {
    let a: Vec<char> = a.chars().collect();
    let b: Vec<char> = b.chars().collect();
    let la = a.len();
    let lb = b.len();
    if la.abs_diff(lb) > max_dist {
        return max_dist + 1;
    }
    if a == b {
        return 0;
    }
    let mut prev: Vec<usize> = (0..=lb).collect();
    for (i, ca) in a.iter().enumerate() {
        let i1 = i + 1;
        let mut cur: Vec<usize> = Vec::with_capacity(lb + 1);
        cur.push(i1);
        let mut row_best = i1;
        for (j, cb) in b.iter().enumerate() {
            let cost = usize::from(ca != cb);
            let v = (prev[j + 1] + 1).min(cur[j] + 1).min(prev[j] + cost);
            cur.push(v);
            if v < row_best {
                row_best = v;
            }
        }
        if row_best > max_dist {
            return max_dist + 1;
        }
        prev = cur;
    }
    prev[lb]
}

/// Strip, case-insensitively de-duplicate, and append `s` to `out` — the Python
/// `_add` closure: no-op on an empty string, on a case-insensitive duplicate, or
/// once `out` has reached `limit`.
fn add(out: &mut Vec<String>, seen: &mut HashSet<String>, limit: usize, s: &str) {
    let s = s.trim();
    if s.is_empty() {
        return;
    }
    let key = s.to_lowercase();
    if out.len() < limit && !seen.contains(&key) {
        seen.insert(key);
        out.push(s.to_string());
    }
}

/// Up to `limit` autocomplete suggestions for query `q`.
///
/// `popular` is a slice of recently-popular query strings (most-frequent first);
/// suggestions are de-duplicated case-insensitively and preserve the earlier
/// (typed) words while completing the final one. An exact port of the Python
/// `suggest` — steps (0) popular, (a) prefix completion, (b) edit-distance
/// fallback (only when the prefix pass is thin and the fragment is long enough).
#[must_use]
pub fn suggest(index: &Index, q: &str, popular: &[String], limit: usize) -> Vec<String> {
    let q = q.trim();
    if q.is_empty() {
        return Vec::new();
    }
    // low = first MAX_QUERY_LEN code points, then lowercased (Python q[:64].lower()).
    let low: String = q
        .chars()
        .take(MAX_QUERY_LEN)
        .collect::<String>()
        .to_lowercase();
    let words: Vec<&str> = low.split_whitespace().collect();
    if words.is_empty() {
        return Vec::new();
    }
    let last = words[words.len() - 1];
    let head = words[..words.len() - 1].join(" ");
    let prefix_head = if head.is_empty() {
        String::new()
    } else {
        format!("{head} ")
    };

    let mut out: Vec<String> = Vec::new();
    let mut seen: HashSet<String> = HashSet::new();

    // (0) recently-popular queries that extend exactly what was typed.
    for pq in popular {
        let pl = pq.trim().to_lowercase();
        if !pl.is_empty() && pl != low && pl.starts_with(&low) {
            add(&mut out, &mut seen, limit, pq);
        }
        if out.len() >= limit {
            out.truncate(limit);
            return out;
        }
    }

    // (a) prefix completion of the final word from the indexed term dictionary.
    for (term, _cnt) in index.vocab_prefix(last, limit.saturating_mul(2)) {
        if term.as_str() != last {
            add(&mut out, &mut seen, limit, &format!("{prefix_head}{term}"));
        }
        if out.len() >= limit {
            out.truncate(limit);
            return out;
        }
    }

    // (b) edit-distance "did you mean" fallback -- only when the prefix pass was
    //     thin, and only over the bounded candidate sample.
    let last_len = last.chars().count();
    if out.len() < std::cmp::max(3, limit / 2) && last_len >= FUZZY_MIN_LEN {
        let max_dist = if last_len <= 4 { 1 } else { 2 };
        // (dist ASC, -doc ASC == doc DESC, term ASC) — the Python tuple sort.
        let mut cands: Vec<(usize, i64, String)> = Vec::new();
        for (term, cnt) in index.vocab_candidates(last, FUZZY_SCAN_CAP) {
            if term.starts_with(last) || term.as_str() == last {
                continue; // prefix pass already covers these
            }
            let d = levenshtein(last, &term, max_dist);
            if d <= max_dist {
                cands.push((d, -(i64::from(cnt)), term));
            }
        }
        cands.sort();
        for (_d, _nc, term) in cands {
            add(&mut out, &mut seen, limit, &format!("{prefix_head}{term}"));
            if out.len() >= limit {
                break;
            }
        }
    }
    out.truncate(limit);
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::index::{DocFields, Index};

    fn ix_with(terms: &[(&str, &str, &str)]) -> Index {
        let mut ix = Index::new();
        for (i, (t, d, b)) in terms.iter().enumerate() {
            ix.upsert_document(
                &format!("http://h/{i}"),
                DocFields {
                    title: t,
                    description: d,
                    body: b,
                    host: "h",
                    fetched_at: 100.0,
                    http_status: 200,
                    ..DocFields::default()
                },
            );
        }
        ix
    }

    #[test]
    fn levenshtein_bounds_and_codepoints() {
        assert_eq!(levenshtein("", "", 1), 0);
        assert_eq!(levenshtein("abc", "abc", 2), 0);
        assert_eq!(levenshtein("kitten", "sitting", 2), 3); // early exit -> max+1
        assert_eq!(levenshtein("kitten", "sitting", 3), 3);
        assert_eq!(levenshtein("abcdef", "abc", 2), 3); // length-diff shortcut
        assert_eq!(levenshtein("café", "cafe", 1), 1); // é is ONE code point
        assert_eq!(levenshtein("naïve", "naive", 2), 1);
    }

    #[test]
    fn suggest_prefix_and_fuzzy() {
        let ix = ix_with(&[
            ("rust programming", "", "rust programs"),
            ("programming", "", "programs"),
            ("hello", "", "hello world"),
        ]);
        // step (a): prefix completion, doc-DESC then term-ASC, `last` term skipped.
        assert_eq!(
            suggest(&ix, "prog", &[], 10),
            vec!["programming", "programs"]
        );
        // step (b): pure typo resolved by the fuzzy pass.
        assert_eq!(suggest(&ix, "helo", &[], 10), vec!["hello"]);
        // empty / whitespace -> [].
        assert!(suggest(&ix, "   ", &[], 10).is_empty());
    }

    #[test]
    fn suggest_popular_and_dedup() {
        let ix = ix_with(&[("rust programming", "", "rust")]);
        // step (0) keeps the popular query's original case, and the vocab
        // completion that collides case-insensitively is de-duplicated away.
        let out = suggest(&ix, "rust pro", &["Rust Programming".to_string()], 10);
        assert_eq!(out[0], "Rust Programming");
        assert!(!out.iter().any(|s| s == "rust programming"));
    }
}
