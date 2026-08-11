//! Cross-check: the Rust `websearch::dedup` reproduces the Python
//! `websearch.dedup` — FNV-1a token hashes and the shingled 64-bit SimHash match
//! bit-for-bit (empty, single-word unigram fallback, case-folding, non-ASCII).
//! Goldens were emitted by driving the Python module.

use websearch::dedup::{hamming, simhash};

/// (text, expected_unsigned_simhash)
const GOLDENS: &[(&str, u64)] = &[
    ("", 0),
    ("a", 12638187200555641996),
    (
        "the quick brown fox jumps over the lazy dog",
        613633053257931328,
    ),
    (
        "The Quick Brown Fox Jumps Over The Lazy Dog",
        613633053257931328,
    ),
    ("hello world foo bar baz", 3967993170956390539),
    ("café résumé señor niño", 15839188587703067270),
];

#[test]
fn simhash_matches_python() {
    for (text, expected) in GOLDENS {
        assert_eq!(simhash(text), *expected, "simhash({text:?})");
    }
}

#[test]
fn hamming_matches_python() {
    let a = simhash("the quick brown fox jumps over the lazy dog");
    let b = simhash("the quick brown fox jumps over the lazy cat");
    assert_eq!(hamming(a, b), 11);
}
