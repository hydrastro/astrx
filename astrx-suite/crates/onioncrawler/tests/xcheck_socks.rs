//! Cross-check: the Rust SOCKS5 encoders produce the exact RFC-1928/1929 byte
//! layout of the Python reference (`legacy-python/onioncrawler/socks.py`) — the
//! greeting, username/password sub-negotiation, and DOMAINNAME CONNECT request
//! (with big-endian port). Expected hex was emitted by driving the Python module.

use onioncrawler::socks::{build_connect_request, build_greeting, build_userpass_auth};

fn hex(b: &[u8]) -> String {
    b.iter().map(|x| format!("{x:02x}")).collect()
}

#[test]
fn socks_encoders_xcheck() {
    assert_eq!(hex(&build_greeting(false)), "050100");
    assert_eq!(hex(&build_greeting(true)), "05020200");
    assert_eq!(
        hex(&build_userpass_auth("user", "pass").unwrap()),
        "0104757365720470617373"
    );

    let onion = format!("{}.onion", "a".repeat(56));
    assert_eq!(
        hex(&build_connect_request(&onion, 80).unwrap()),
        "050100033e61616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161616161612e6f6e696f6e0050"
    );
    assert_eq!(
        hex(&build_connect_request("abc.onion", 8080).unwrap()),
        "05010003096162632e6f6e696f6e1f90"
    );
    assert_eq!(
        hex(&build_connect_request("stats.i2p", 443).unwrap()),
        "050100030973746174732e69327001bb"
    );
}
