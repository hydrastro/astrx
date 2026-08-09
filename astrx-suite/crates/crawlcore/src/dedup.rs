//! Near-duplicate SimHash bit-math (only the arithmetic; each engine keeps its
//! own tokenizer + token-hash and feeds 64-bit hashes in here).
//!
//! Standard SimHash column-sum rule: each token votes `+weight` on the bits it
//! sets and `-weight` on those it clears; an output bit is 1 iff its column sum
//! is strictly positive. Feeding `(h, 3)` is identical to feeding `(h, 1)` three
//! times, so a weight-by-count caller and a per-occurrence caller agree exactly.

/// SimHash width (SQLite stores a signed 64-bit integer).
pub const DEFAULT_BITS: usize = 64;

/// One `(token hash, weight)` contribution to a SimHash.
#[derive(Debug, Clone, Copy)]
pub struct WeightedHash {
    pub hash: u64,
    pub weight: i64,
}

impl WeightedHash {
    pub fn new(hash: u64, weight: i64) -> Self {
        Self { hash, weight }
    }
}

/// Fold `(token hash, weight)` pairs into an unsigned 64-bit SimHash. Returns 0
/// for empty input — a page with no content has no fingerprint and must never be
/// treated as a mirror of another empty page.
pub fn simhash_vector(items: &[WeightedHash]) -> u64 {
    // i128 accumulator: even adversarial i64 weights over many tokens can't
    // overflow (Python's column sums are arbitrary-precision; this keeps parity
    // and can never panic in debug or wrap in release).
    let mut acc = [0i128; DEFAULT_BITS];
    let mut seen = false;
    for it in items {
        seen = true;
        let w = it.weight as i128;
        for (i, a) in acc.iter_mut().enumerate() {
            if (it.hash >> i) & 1 == 1 {
                *a += w;
            } else {
                *a -= w;
            }
        }
    }
    if !seen {
        return 0;
    }
    let mut out = 0u64;
    for (i, &a) in acc.iter().enumerate() {
        if a > 0 {
            out |= 1u64 << i;
        }
    }
    out
}

/// Reinterpret an unsigned fingerprint as signed two's-complement (same bits),
/// so it fits SQLite's signed INTEGER column. Hamming distance is unaffected.
pub fn signed64(value: u64) -> i64 {
    value as i64
}

/// Bit distance between two fingerprints (signed or unsigned bit patterns).
pub fn hamming(a: u64, b: u64) -> u32 {
    (a ^ b).count_ones()
}

/// True iff `a` and `b` are both non-zero and within `threshold` bits. A zero
/// fingerprint means "no content" and never matches.
pub fn near(a: u64, b: u64, threshold: u32) -> bool {
    a != 0 && b != 0 && hamming(a, b) <= threshold
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hamming_basic() {
        assert_eq!(hamming(0, 0), 0);
        assert_eq!(hamming(0b1011, 0b1110), 2);
        assert_eq!(hamming(0, u64::MAX), 64);
    }

    #[test]
    fn signed64_roundtrip() {
        for u in [0u64, 1, (1 << 63) - 1, 1 << 63, u64::MAX] {
            // signed and unsigned forms share bits -> Hamming-identical
            assert_eq!(hamming(signed64(u) as u64, u), 0);
        }
    }

    #[test]
    fn near_rules() {
        assert!(near(0b1111, 0b1110, 1));
        assert!(!near(0b1111, 0b1000, 1));
        assert!(!near(0, 123, 3)); // empty never matches
        assert!(!near(123, 0, 3));
    }

    #[test]
    fn extreme_weights_do_not_overflow() {
        // i128 accumulator: hostile i64::MAX weights must not panic (debug) or
        // wrap (release). bit0 set by a huge-weight token stays set.
        let out = simhash_vector(&[
            WeightedHash::new(1, i64::MAX),
            WeightedHash::new(1, i64::MAX),
            WeightedHash::new(0, 1),
        ]);
        assert_eq!(out & 1, 1);
    }

    #[test]
    fn empty_is_zero_and_weight_equals_repetition() {
        assert_eq!(simhash_vector(&[]), 0);
        let (h1, h2) = (0xDEAD_BEEF_CAFE_F00D, 0x0123_4567_89AB_CDEF);
        let weighted = simhash_vector(&[WeightedHash::new(h1, 3), WeightedHash::new(h2, 1)]);
        let expanded = simhash_vector(&[
            WeightedHash::new(h1, 1),
            WeightedHash::new(h1, 1),
            WeightedHash::new(h1, 1),
            WeightedHash::new(h2, 1),
        ]);
        assert_eq!(weighted, expanded);
    }
}
