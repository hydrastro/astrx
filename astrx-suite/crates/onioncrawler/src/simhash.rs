//! 64-bit SimHash for near-duplicate / mirror detection — byte-identical to the
//! Python `onioncrawler.simhash`.
//!
//! Exact content dedup uses a SHA-1 over normalized title+text (in the store);
//! this is the *fuzzy* fingerprint: two near-identical pages (a mirror, a page
//! with a rotating ad or a different footer) land a small Hamming distance apart,
//! so they can be clustered and collapsed in results. The tokenizer (`[0-9a-z]+`
//! over lowercased text) and the token hash (an 8-byte BLAKE2b digest read
//! big-endian) are owned here so the persisted fingerprints never change; the
//! column-sum / Hamming / signed-wrap bit-math lives once in `crawlcore::dedup`.

use std::collections::HashMap;

use crawlcore::blake2b::blake2b;
use crawlcore::dedup::{signed64, simhash_vector, WeightedHash};

// Re-export the shared distance so callers can `use simhash::hamming` exactly as
// the Python module re-exports it.
pub use crawlcore::dedup::hamming;

/// Stable 64-bit token hash: the big-endian reading of an 8-byte BLAKE2b digest.
/// (Python: `int.from_bytes(blake2b(token, digest_size=8).digest(), "big")`.)
/// `hash()` is salted per process and must never back a persisted fingerprint.
fn token_hash(token: &str) -> u64 {
    let d = blake2b(token.as_bytes(), 8);
    let mut b = [0u8; 8];
    b.copy_from_slice(&d[..8]);
    u64::from_be_bytes(b)
}

/// Tokenize like Python `re.findall(r"[0-9a-z]+", text.lower())`: maximal runs of
/// ASCII digits / lowercase letters over the lowercased text.
fn tokens(text: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    for c in text.to_lowercase().chars() {
        if c.is_ascii_digit() || c.is_ascii_lowercase() {
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

/// The 64-bit SimHash of `text` as a signed two's-complement integer (0 when
/// there is no tokenizable text), matching Python `simhash64`.
///
/// Standard SimHash: each token votes `+count` on the bits its hash sets and
/// `-count` on those it clears; an output bit is 1 iff its column sum is
/// positive. The signed return fits the store's signed 64-bit `simhash` field;
/// [`hamming`] masks before counting so distance math is sign-agnostic.
pub fn simhash64(text: &str) -> i64 {
    let mut counts: HashMap<String, i64> = HashMap::new();
    for t in tokens(text) {
        *counts.entry(t).or_insert(0) += 1;
    }
    if counts.is_empty() {
        return 0;
    }
    let items: Vec<WeightedHash> = counts
        .iter()
        .map(|(tok, &w)| WeightedHash::new(token_hash(tok), w))
        .collect();
    signed64(simhash_vector(&items))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn empty_is_zero() {
        assert_eq!(simhash64(""), 0);
        assert_eq!(simhash64("   \n\t"), 0);
    }

    #[test]
    fn near_duplicates_are_close() {
        // One differing token out of many → small Hamming distance.
        let a = simhash64("the quick brown fox jumps over the lazy dog") as u64;
        let b = simhash64("the quick brown fox jumps over the lazy cat") as u64;
        assert!(
            hamming(a, b) <= 12,
            "near-dup distance was {}",
            hamming(a, b)
        );
    }

    #[test]
    fn case_insensitive() {
        assert_eq!(simhash64("Hello World"), simhash64("hello world"));
    }
}
