//! A small token-bucket rate limiter, keyed by client (IP).
//!
//! Used to protect the public search/API endpoints. The clock is injected (each
//! [`TokenBucket::allow`] call takes the current monotonic time) so refill
//! behaviour is deterministically unit-testable without sleeping.
//!
//! The per-key table is bounded by `max_keys` with LRU eviction: on overflow the
//! least-recently-used key is dropped, NOT the whole table — clearing everything
//! would hand every active client a fresh full burst at once.
//!
//! Deployment note: behind a Tor onion service every request arrives from
//! `127.0.0.1`, so the limiter collapses to a single shared bucket (there is no
//! per-client identity to key on). The LRU bookkeeping is therefore O(1) in
//! practice; the `Vec`-based order below is only exercised under a clearnet
//! deployment with many distinct peers.
//!
//! Ported from the Python `ratelimit.py`; cross-checked in
//! `tests/xcheck_ratelimit.rs`. Pure (no async, no deps): the bucket math is
//! stdlib-only; a caller that shares it across tasks wraps it in a `Mutex`.

use std::collections::HashMap;

/// A per-key token bucket: `rate` tokens/sec refilling up to `capacity` burst.
#[derive(Debug, Clone)]
pub struct TokenBucket {
    rate: f64,
    capacity: f64,
    max_keys: usize,
    /// LRU order, most-recently-used at the end.
    order: Vec<String>,
    /// key -> (tokens, last-timestamp).
    buckets: HashMap<String, (f64, f64)>,
}

impl TokenBucket {
    /// A limiter of `rate` tokens/sec up to `capacity` burst, per key, with at
    /// most `max_keys` tracked keys (LRU-evicted).
    #[must_use]
    pub fn new(rate: f64, capacity: f64, max_keys: usize) -> Self {
        TokenBucket {
            rate,
            capacity,
            max_keys: max_keys.max(1),
            order: Vec::new(),
            buckets: HashMap::new(),
        }
    }

    /// Consume `cost` tokens for `key` at monotonic time `now`; return `true` if
    /// allowed, else `false`. Refills for elapsed time first, and moves `key` to
    /// the most-recently-used position.
    pub fn allow(&mut self, key: &str, cost: f64, now: f64) -> bool {
        // pop existing entry (and its LRU slot), or start a fresh full bucket
        let (mut tokens, ts) = match self.buckets.remove(key) {
            Some(v) => {
                if let Some(pos) = self.order.iter().position(|k| k == key) {
                    self.order.remove(pos);
                }
                v
            }
            None => (self.capacity, now),
        };
        tokens = self.capacity.min(tokens + (now - ts) * self.rate);
        let allowed = if tokens >= cost {
            tokens -= cost;
            true
        } else {
            false
        };
        self.buckets.insert(key.to_string(), (tokens, now));
        self.order.push(key.to_string());
        // bounded memory: evict the least-recently-used key(s), not the whole
        // table, so an overflow can't reset every active client's limit.
        while self.buckets.len() > self.max_keys {
            let lru = self.order.remove(0);
            self.buckets.remove(&lru);
        }
        allowed
    }

    /// Number of currently-tracked keys.
    #[must_use]
    pub fn tracked_keys(&self) -> usize {
        self.buckets.len()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn burst_then_refill() {
        let mut tb = TokenBucket::new(2.0, 5.0, 100);
        let nows = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0, 1.0, 3.0];
        let got: Vec<bool> = nows.iter().map(|&t| tb.allow("a", 1.0, t)).collect();
        assert_eq!(got, [true, true, true, true, true, false, true, true, true]);
    }

    #[test]
    fn lru_eviction_resets_bucket() {
        // rate=0 (no refill), cap=1, max_keys=1: 'b' evicts 'a', so re-accessing
        // 'a' finds a fresh full bucket (would be denied without eviction).
        let mut tb = TokenBucket::new(0.0, 1.0, 1);
        assert!(tb.allow("a", 1.0, 0.0));
        assert!(tb.allow("b", 1.0, 0.0));
        assert!(tb.allow("a", 1.0, 0.0));
        assert_eq!(tb.tracked_keys(), 1);
    }
}
