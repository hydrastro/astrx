//! Cross-check: the Rust `ip_is_internal` reproduces the Python
//! `websearch.httpclient._ip_is_internal` exactly over a broad corpus of IPv4 /
//! IPv6 special-use ranges (loopback, RFC-1918, link-local, CGNAT boundary,
//! test-nets, benchmarking, multicast, reserved, NAT64, Teredo, 6to4, ULA,
//! documentation, unspecified, IPv4-mapped, and unparseable). Goldens were
//! emitted by driving the Python module; regenerate with `tests/regen_goldens.py`.

use websearch::ssrf::ip_is_internal;

/// (address, is_internal) — the Python reference verdict.
const GOLDENS: &[(&str, bool)] = &[
    ("127.0.0.1", true),
    ("10.0.0.1", true),
    ("192.168.1.1", true),
    ("172.16.5.4", true),
    ("172.31.255.255", true),
    ("172.32.0.1", false),
    ("172.15.255.255", false),
    ("172.16.0.0", true),
    ("169.254.169.254", true),
    ("169.254.0.1", true),
    ("100.64.0.1", false),
    ("100.63.255.255", false),
    ("100.64.0.0", false),
    ("100.127.255.255", false),
    ("100.128.0.0", false),
    ("100.128.0.1", false),
    ("8.8.8.8", false),
    ("1.1.1.1", false),
    ("93.184.216.34", false),
    ("11.0.0.1", false),
    ("0.0.0.0", true),
    ("255.255.255.255", true),
    ("224.0.0.1", true),
    ("239.255.255.255", true),
    ("233.252.0.1", true),
    ("240.0.0.1", true),
    ("203.0.113.5", true),
    ("203.0.114.0", false),
    ("198.51.100.9", true),
    ("198.51.101.0", false),
    ("192.0.2.7", true),
    ("192.0.0.5", true),
    ("192.0.0.171", true),
    ("192.0.0.255", true),
    ("192.1.0.1", false),
    ("192.88.99.1", false),
    ("198.18.0.1", true),
    ("198.19.255.255", true),
    ("198.20.0.1", false),
    ("::1", true),
    ("::", true),
    ("fe80::1", true),
    ("fc00::1", true),
    ("fd12:3456::1", true),
    ("fec0::1", false),
    ("2001:4860:4860::8888", false),
    ("2606:4700::1111", false),
    ("::ffff:127.0.0.1", true),
    ("::ffff:8.8.8.8", false),
    ("::ffff:169.254.169.254", true),
    ("::ffff:10.1.2.3", true),
    ("::ffff:1.2.3.4", false),
    ("ff02::1", true),
    ("2001:db8::1", true),
    ("2002::1", true),
    ("64:ff9b::1", true),
    ("100::1", true),
    ("2001::1", true),
    ("2001:20::1", false),
    ("not-an-ip", true),
    ("", true),
    ("999.1.1.1", true),
];

#[test]
fn ip_is_internal_matches_python() {
    for (addr, expected) in GOLDENS {
        assert_eq!(ip_is_internal(addr), *expected, "ip_is_internal({addr:?})");
    }
}
