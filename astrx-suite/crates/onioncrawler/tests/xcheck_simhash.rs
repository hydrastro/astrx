//! Cross-check: the Rust `simhash64` reproduces the Python
//! `onioncrawler.simhash.simhash64` bit-for-bit (signed 64-bit values), across
//! empty text, unigram counting, case-folding and non-ASCII input. Goldens were
//! emitted by driving the Python module; regenerate with `tests/regen_goldens.py`.

use onioncrawler::simhash::{hamming, simhash64};

/// (expected_signed_simhash, text)
const GOLDENS: &[(i64, &str)] = &[
    (0, ""),
    (4681665781835383343, "a"),
    (13723176454590477, "hello world"),
    (
        1932188759623088695,
        "The quick brown fox jumps over the lazy dog",
    ),
    (-2161954338598218878, "onion darknet market"),
    (-6361636117772159875, "Hello World\nHELLO world hello"),
    (-3874751511954139824, "café résumé naïve"),
    (788888220212982440, "aaa bbb aaa ccc aaa"),
];

#[test]
fn simhash64_matches_python() {
    for (expected, text) in GOLDENS {
        assert_eq!(simhash64(text), *expected, "simhash64({text:?})");
    }
}

#[test]
fn hamming_matches_python() {
    let a = simhash64("hello world foo bar") as u64;
    let b = simhash64("hello world foo baz") as u64;
    assert_eq!(hamming(a, b), 15);
}
