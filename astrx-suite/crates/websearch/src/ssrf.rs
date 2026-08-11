//! SSRF gate — the crown-jewel safety invariant for a clearnet crawler, as a type.
//!
//! A web crawler fetches arbitrary, attacker-influenced URLs; without a guard it
//! can be steered at internal addresses (localhost, RFC-1918, the cloud-metadata
//! endpoint `169.254.169.254`, …) — a Server-Side Request Forgery. The Python
//! `websearch.httpclient` resolves each host and refuses if **any** resolved
//! address is internal. Here that becomes a **type**: [`SafeIp`] wraps an
//! `IpAddr` that has passed [`ip_is_internal`], and the (forthcoming) net-tier
//! connect will take a `&SafeIp` — so dialing an unvetted / internal address is a
//! compile error. The resolver pins the connect to the vetted address, so DNS
//! rebinding cannot swap in an internal IP after the check.
//!
//! [`ip_is_internal`] is the pure, cross-checked primitive (byte-identical to the
//! Python `_ip_is_internal` over a 60+-case IPv4/IPv6 special-range corpus):
//! loopback / private / link-local / reserved / multicast / unspecified, with
//! IPv4-mapped IPv6 unwrapped, and **fail-closed** — an unparseable string is
//! treated as internal.

use std::net::{IpAddr, Ipv4Addr, Ipv6Addr};

/// IPv4 special-use blocks treated as internal (matches the Python reference).
const V4_NETS: &[([u8; 4], u32)] = &[
    ([0, 0, 0, 0], 8),       // "this network" / unspecified
    ([10, 0, 0, 0], 8),      // RFC-1918 private
    ([127, 0, 0, 0], 8),     // loopback
    ([169, 254, 0, 0], 16),  // link-local (incl. 169.254.169.254 metadata)
    ([172, 16, 0, 0], 12),   // RFC-1918 private
    ([192, 0, 0, 0], 24),    // IETF protocol assignments
    ([192, 0, 2, 0], 24),    // TEST-NET-1
    ([192, 168, 0, 0], 16),  // RFC-1918 private
    ([198, 18, 0, 0], 15),   // benchmarking
    ([198, 51, 100, 0], 24), // TEST-NET-2
    ([203, 0, 113, 0], 24),  // TEST-NET-3
    ([224, 0, 0, 0], 4),     // multicast
    ([240, 0, 0, 0], 4),     // reserved (incl. 255.255.255.255 broadcast)
];

/// IPv6 special-use blocks treated as internal (matches the Python reference).
const V6_NETS: &[(&str, u32)] = &[
    ("::1", 128),        // loopback
    ("::", 128),         // unspecified
    ("64:ff9b::", 96),   // well-known NAT64
    ("64:ff9b:1::", 48), // local-use NAT64
    ("100::", 64),       // discard-only
    ("2001::", 32),      // Teredo
    ("2001:2::", 48),    // benchmarking
    ("2001:db8::", 32),  // documentation
    ("2002::", 16),      // 6to4
    ("fc00::", 7),       // unique-local (ULA)
    ("fe80::", 10),      // link-local
    ("ff00::", 8),       // multicast
];

fn v4_in(ip: u32, net: [u8; 4], prefix: u32) -> bool {
    let n = u32::from_be_bytes(net);
    let mask = if prefix == 0 {
        0
    } else {
        u32::MAX << (32 - prefix)
    };
    (ip & mask) == (n & mask)
}

fn v4_internal(ip: Ipv4Addr) -> bool {
    let x = u32::from(ip);
    V4_NETS.iter().any(|&(net, p)| v4_in(x, net, p))
}

fn v6_in(ip: u128, net: &str, prefix: u32) -> bool {
    let n = u128::from(net.parse::<Ipv6Addr>().expect("valid const CIDR base"));
    let mask = if prefix == 0 {
        0
    } else {
        u128::MAX << (128 - prefix)
    };
    (ip & mask) == (n & mask)
}

fn v6_internal(ip: Ipv6Addr) -> bool {
    let x = u128::from(ip);
    V6_NETS.iter().any(|&(net, p)| v6_in(x, net, p))
}

/// Classify a parsed address, unwrapping an IPv4-mapped IPv6 address first.
fn classify(ip: IpAddr) -> bool {
    let ip = match ip {
        IpAddr::V6(v6) => v6
            .to_ipv4_mapped()
            .map(IpAddr::V4)
            .unwrap_or(IpAddr::V6(v6)),
        v4 => v4,
    };
    match ip {
        IpAddr::V4(v4) => v4_internal(v4),
        IpAddr::V6(v6) => v6_internal(v6),
    }
}

/// True if `s` names an internal / unsafe address. **Fail-closed**: an
/// unparseable string is treated as internal (byte-identical to Python
/// `_ip_is_internal`).
#[must_use]
pub fn ip_is_internal(s: &str) -> bool {
    match s.parse::<IpAddr>() {
        Ok(ip) => classify(ip),
        Err(_) => true,
    }
}

/// An IP address vetted as **external** — safe to connect to. Constructible only
/// through [`SafeIp::from_ip`] / [`SafeIp::vet`], so a socket API that takes a
/// `&SafeIp` can never be pointed at an internal address: the SSRF invariant as a
/// type. The net-tier resolver vets every resolved address and pins the connect
/// to it (DNS-rebinding safe).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct SafeIp(IpAddr);

impl SafeIp {
    /// Vet an already-parsed address; `None` if it is internal.
    #[must_use]
    pub fn from_ip(ip: IpAddr) -> Option<SafeIp> {
        if classify(ip) {
            None
        } else {
            Some(SafeIp(ip))
        }
    }

    /// Parse + vet an address string; `None` if unparseable or internal.
    #[must_use]
    pub fn vet(s: &str) -> Option<SafeIp> {
        SafeIp::from_ip(s.parse().ok()?)
    }

    /// The vetted address (safe to connect to).
    #[must_use]
    pub fn addr(&self) -> IpAddr {
        self.0
    }

    /// Escape hatch: mint a `SafeIp` for an **explicitly operator-allow-listed**
    /// internal host (the `allow_hosts` config), bypassing the internal check.
    ///
    /// `pub(crate)` on purpose — only this crate's own resolver
    /// ([`crate::httpclient`]) can produce a `SafeIp` over an internal address,
    /// and only after [`crate::httpclient::authority_exempt`] has matched the
    /// authority against the operator's allow-list. Callers outside the crate can
    /// still *only* obtain a strictly-external `SafeIp` via
    /// [`SafeIp::from_ip`] / [`SafeIp::vet`], so the SSRF type-gate holds by
    /// default and the exemption is a narrow, audit-visible bypass.
    #[must_use]
    pub(crate) fn exempt(ip: IpAddr) -> SafeIp {
        SafeIp(ip)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn blocks_the_obvious_internals() {
        for s in [
            "127.0.0.1",
            "10.0.0.1",
            "192.168.1.1",
            "169.254.169.254",
            "::1",
            "fc00::1",
            "::ffff:127.0.0.1",
            "not-an-ip",
            "",
        ] {
            assert!(ip_is_internal(s), "{s} should be internal");
            assert!(SafeIp::vet(s).is_none(), "{s} must not vet");
        }
    }

    #[test]
    fn allows_public() {
        for s in ["8.8.8.8", "1.1.1.1", "93.184.216.34", "2606:4700::1111"] {
            assert!(!ip_is_internal(s), "{s} should be external");
            assert_eq!(SafeIp::vet(s).unwrap().addr(), s.parse::<IpAddr>().unwrap());
        }
    }

    #[test]
    fn cidr_boundaries() {
        assert!(ip_is_internal("172.31.255.255")); // in 172.16/12
        assert!(!ip_is_internal("172.32.0.1")); // out
        assert!(!ip_is_internal("100.64.0.1")); // CGNAT is external here
        assert!(ip_is_internal("2002::1")); // 6to4
        assert!(!ip_is_internal("2001:20::1")); // not Teredo/2001::/32
    }
}
