//! Cross-check: the Rust `TokenBucket` matches the Python reference
//! (`legacy-python/onioncrawler/ratelimit.py`) — refill math, burst capacity,
//! per-key independence, and LRU eviction (an evicted key returns as a fresh full
//! bucket). Expected sequences were emitted by driving the Python module with an
//! injected clock.

use onioncrawler::ratelimit::TokenBucket;

#[test]
fn burst_then_refill_xcheck() {
    // rate=2/s, capacity=5. now values chosen to exercise burst + refill.
    let mut tb = TokenBucket::new(2.0, 5.0, 100);
    let nows = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 3.0];
    let got: Vec<bool> = nows.iter().map(|&t| tb.allow("a", 1.0, t)).collect();
    assert_eq!(got, [true, true, true, true, true, false, true, true, true]);
}

#[test]
fn lru_evict_xcheck() {
    // rate=1, cap=1, max_keys=2; all at t=0. Each key is fresh → all allowed.
    let mut tb = TokenBucket::new(1.0, 1.0, 2);
    let got: Vec<bool> = ["a", "b", "c", "a"]
        .iter()
        .map(|k| tb.allow(k, 1.0, 0.0))
        .collect();
    assert_eq!(got, [true, true, true, true]);
}

#[test]
fn lru_discriminate_xcheck() {
    // rate=0 (no refill), cap=1, max_keys=1: 'b' evicts 'a', so re-accessing 'a'
    // is a fresh bucket → allowed (would be denied without eviction).
    let mut tb = TokenBucket::new(0.0, 1.0, 1);
    let got: Vec<bool> = ["a", "b", "a"]
        .iter()
        .map(|k| tb.allow(k, 1.0, 0.0))
        .collect();
    assert_eq!(got, [true, true, true]);
}
