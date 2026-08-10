//! BEP-33 — DHT scrape: estimate a swarm's seeders/leechers from the bloom
//! filters a node returns to a `get_peers` (with `scrape=1`) query, so torrentds
//! can report swarm health from the DHT itself — no external tracker needed,
//! which matters for a Tor-only operator.
//!
//! A BEP-33 node adds two 256-byte (2048-bit, `k=2`) bloom filters to its
//! response: `BFsd` (seeds) and `BFpe` (peers/leechers), into which every
//! announcing IP was hashed. The population is recovered from the count of
//! still-zero bits:
//!
//! ```text
//! size = ln(c / m) / (2 · ln(1 − 1/m))      // c = zero bits, m = 2048
//! ```
//!
//! Pure: SHA-1 (reused from [`crate::infohash`]) + bit math, no dependency and no
//! network. Wiring it into the live node (setting `scrape=1` and reading
//! `BFsd`/`BFpe` off real responses) is a deployment concern; the decoder here is
//! the reusable, exact-tested core.

use crate::bencode::Ben;
use crate::bencode::Dict;
use crate::infohash::sha1;
use std::net::IpAddr;

/// Filter size in bytes (BEP-33 fixes this at 256).
pub const BLOOM_BYTES: usize = 256;
/// Filter size in bits (2048).
pub const BLOOM_BITS: usize = BLOOM_BYTES * 8;

/// A fresh, all-zero filter.
pub fn new_filter() -> [u8; BLOOM_BYTES] {
    [0u8; BLOOM_BYTES]
}

/// The two BEP-33 bit indices for an IP: SHA-1 of the packed address, first two
/// 16-bit little-endian words, each taken mod 2048.
pub fn bit_indices(ip: IpAddr) -> (usize, usize) {
    let h = match ip {
        IpAddr::V4(a) => sha1(&a.octets()),
        IpAddr::V6(a) => sha1(&a.octets()),
    };
    let i1 = (h[0] as usize | ((h[1] as usize) << 8)) % BLOOM_BITS;
    let i2 = (h[2] as usize | ((h[3] as usize) << 8)) % BLOOM_BITS;
    (i1, i2)
}

/// Set an IP's two bits in `bloom`.
pub fn add_ip(bloom: &mut [u8; BLOOM_BYTES], ip: IpAddr) {
    let (i1, i2) = bit_indices(ip);
    for idx in [i1, i2] {
        bloom[idx >> 3] |= 1 << (idx & 7);
    }
}

/// Build a filter from a set of IPs.
pub fn build_filter<I: IntoIterator<Item = IpAddr>>(ips: I) -> [u8; BLOOM_BYTES] {
    let mut bf = new_filter();
    for ip in ips {
        add_ip(&mut bf, ip);
    }
    bf
}

/// Estimate the population encoded in a BEP-33 bloom filter.
///
/// Mirrors the Python reference exactly: an all-zero filter → 0, a saturated
/// filter → `m` (a huge swarm), otherwise the log-of-zero-bits estimator,
/// clamped at 0.
pub fn estimate(bloom: &[u8]) -> u64 {
    if bloom.is_empty() {
        return 0;
    }
    let m = bloom.len() * 8;
    let set_bits: u32 = bloom.iter().map(|b| b.count_ones()).sum();
    let zeros = m - set_bits as usize;
    if zeros >= m {
        return 0; // empty filter -> no peers
    }
    if zeros == 0 {
        return m as u64; // saturated -> at least m
    }
    let m_f = m as f64;
    let size = (zeros as f64 / m_f).ln() / (2.0 * (1.0 - 1.0 / m_f).ln());
    let rounded = round_half_even(size);
    if rounded < 0.0 {
        0
    } else {
        rounded as u64
    }
}

/// Round half-to-even (banker's rounding), matching Python's `round()` — the two
/// differ only at an exact `.5`, which `estimate` hits for a 1-set-bit filter
/// (`size == 0.5` exactly): Python yields 0, `f64::round` would yield 1.
fn round_half_even(x: f64) -> f64 {
    let floor = x.floor();
    let diff = x - floor;
    if diff < 0.5 {
        floor
    } else if diff > 0.5 {
        floor + 1.0
    } else if (floor as i64) % 2 == 0 {
        floor
    } else {
        floor + 1.0
    }
}

/// Given a decoded `get_peers` response dict, return `(seeders, leechers)` from
/// `BFsd`/`BFpe`, or `None` for a filter the node did not include.
pub fn estimate_from_response(resp: &Dict) -> (Option<u64>, Option<u64>) {
    let read = |key: &[u8]| match resp.get(key) {
        Some(Ben::Bytes(b)) => Some(estimate(b)),
        _ => None,
    };
    (read(b"BFsd"), read(b"BFpe"))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::net::Ipv4Addr;

    fn hexs(b: &[u8]) -> String {
        b.iter().map(|x| format!("{x:02x}")).collect()
    }

    fn ip(s: &str) -> IpAddr {
        s.parse().unwrap()
    }

    // Synthetic IP set used by the Python scale golden:
    // "10.{(i>>16)&255}.{(i>>8)&255}.{i&255}" for i in 0..n.
    fn synthetic(n: u32) -> Vec<IpAddr> {
        (0..n)
            .map(|i| {
                IpAddr::V4(Ipv4Addr::new(
                    10,
                    ((i >> 16) & 255) as u8,
                    ((i >> 8) & 255) as u8,
                    (i & 255) as u8,
                ))
            })
            .collect()
    }

    #[test]
    fn bit_indices_match_python() {
        assert_eq!(bit_indices(ip("1.2.3.4")), (530, 2010));
        assert_eq!(bit_indices(ip("10.0.0.7")), (839, 2000));
        assert_eq!(bit_indices(ip("2001:db8::1")), (1239, 1191));
    }

    #[test]
    fn filter_and_estimate_match_python_golden() {
        // Same five IPs the Python reference filtered; byte-identical result.
        const GOLDEN5: &str = "00000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000004000000000000000000000000000000000000000000000000000000000000000000000000008000000000000000000400000200000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000020000000000000000000000000000000000000000000000000000000800000000000000000000000000000080000000000000000000000000000080000000000000000000000000000000000000000000010400000000";
        let ips = [
            "1.2.3.4",
            "10.0.0.7",
            "8.8.8.8",
            "192.168.1.1",
            "203.0.113.5",
        ]
        .map(ip)
        .to_vec();
        let bf = build_filter(ips);
        assert_eq!(hexs(&bf), GOLDEN5);
        assert_eq!(estimate(&bf), 5);
    }

    #[test]
    fn estimate_scales_like_python() {
        // Golden estimates from the Python reference for the synthetic sets.
        for (n, expected) in [(1u32, 1u64), (10, 10), (100, 100), (500, 488), (1000, 1001)] {
            let bf = build_filter(synthetic(n));
            assert_eq!(estimate(&bf), expected, "n={n}");
        }
    }

    #[test]
    fn empty_and_saturated() {
        assert_eq!(estimate(&[0u8; BLOOM_BYTES]), 0);
        assert_eq!(estimate(&[0xFFu8; BLOOM_BYTES]), BLOOM_BITS as u64); // 2048
        assert_eq!(estimate(&[]), 0);
        // Exactly one set bit -> size == 0.5 exactly; Python's round() gives 0
        // (round-half-to-even), which our round_half_even matches (f64::round → 1).
        let mut one = [0u8; BLOOM_BYTES];
        one[0] = 1;
        assert_eq!(estimate(&one), 0);
    }

    #[test]
    fn from_response_reads_both_filters() {
        let bf = build_filter(synthetic(50));
        let mut resp = Dict::new();
        resp.insert(b"BFsd".to_vec(), Ben::Bytes(bf.to_vec()));
        resp.insert(b"BFpe".to_vec(), Ben::Bytes(new_filter().to_vec()));
        let (sd, pe) = estimate_from_response(&resp);
        assert_eq!(sd, Some(estimate(&bf)));
        assert_eq!(pe, Some(0)); // empty peers filter
                                 // absent filters -> None
        assert_eq!(estimate_from_response(&Dict::new()), (None, None));
    }
}
