//! Near-duplicate detection: a 64-bit SimHash over word-bigram shingles —
//! byte-identical to the Python `websearch.dedup`.
//!
//! Exact-hash dedup (`content_hash`) drops byte-identical mirrors at crawl time;
//! this catches the *fuzzy* case — mirrors and boilerplate-heavy pages that
//! differ only in a nav bar, a date, or an ad — so the ranker can collapse them
//! from a result page. The tokenizer + token hash (an explicit **FNV-1a**, not a
//! process-randomised hash) are owned here so fingerprints stay stable; the
//! column-sum / Hamming / signed-wrap bit-math lives once in `crawlcore::dedup`.

use crawlcore::dedup::{simhash_vector, WeightedHash};

// Re-export the shared distance so callers can `use dedup::hamming`, as in Python.
pub use crawlcore::dedup::{hamming, near, signed64};

const FNV_OFFSET: u64 = 0xcbf2_9ce4_8422_2325;
const FNV_PRIME: u64 = 0x0000_0100_0000_01b3;

/// 64-bit FNV-1a hash of `data` (wrapping multiply — the standard construction).
fn fnv1a(data: &[u8]) -> u64 {
    let mut h = FNV_OFFSET;
    for &b in data {
        h ^= u64::from(b);
        h = h.wrapping_mul(FNV_PRIME);
    }
    h
}

/// Tokenize like Python `re.findall(r"[^\W_]+", text.lower())`: maximal runs of
/// Unicode alphanumeric characters (word chars minus underscore), lowercased.
fn tokens(text: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    for c in text.to_lowercase().chars() {
        if c.is_alphanumeric() {
            cur.push(c);
        } else if !cur.is_empty() {
            out.push(std::mem::take(&mut cur));
        }
    }
    if !cur.is_empty() {
        out.push(cur);
    }
    out
}

/// Word bigrams — more discriminating than unigrams, so genuinely distinct short
/// pages are not collapsed while true mirrors still are. Fewer than two words
/// falls back to the unigrams themselves.
fn shingles(text: &str) -> Vec<String> {
    let words = tokens(text);
    if words.len() < 2 {
        return words;
    }
    words
        .windows(2)
        .map(|w| format!("{} {}", w[0], w[1]))
        .collect()
}

/// The 64-bit (unsigned) SimHash of `text` (0 for empty / too-short), matching
/// Python `websearch.dedup.simhash`.
#[must_use]
pub fn simhash(text: &str) -> u64 {
    if text.is_empty() {
        return 0;
    }
    let items: Vec<WeightedHash> = shingles(text)
        .iter()
        .map(|sh| WeightedHash::new(fnv1a(sh.as_bytes()), 1))
        .collect();
    simhash_vector(&items)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fnv1a_basis_and_bytes() {
        assert_eq!(fnv1a(b""), FNV_OFFSET);
        assert_eq!(fnv1a(b"a"), 12638187200555641996);
    }

    #[test]
    fn empty_is_zero_and_single_word_is_its_hash() {
        assert_eq!(simhash(""), 0);
        // one word → unigram fallback → its own token hash
        assert_eq!(simhash("a"), 12638187200555641996);
    }

    #[test]
    fn case_insensitive_and_near() {
        let a = simhash("the quick brown fox jumps over the lazy dog");
        assert_eq!(a, simhash("The Quick Brown Fox Jumps Over The Lazy Dog"));
        let b = simhash("the quick brown fox jumps over the lazy cat");
        assert!(hamming(a, b) <= 16);
    }
}
