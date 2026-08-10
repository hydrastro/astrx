// Exercises `tracker_udp`, which lives behind the `net` feature.
#![cfg(feature = "net")]
//! Cross-check: the Rust BEP-15 UDP tracker wire codec is byte-identical to the
//! Python reference's `struct.pack` layouts (`legacy-python/torrentds/tracker_udp.py`).

use std::net::SocketAddr;
use torrentds::tracker_udp::{
    encode_announce_response, encode_connect_response, encode_error, encode_scrape_response,
};
use torrentds::ScrapeCounts;

fn to_hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

#[test]
fn udp_tracker_wire_matches_python() {
    let txn = 0x1122_3344u32;

    assert_eq!(
        to_hex(&encode_connect_response(txn, 0x1122_3344_5566_7788)),
        "00000000112233441122334455667788"
    );
    assert_eq!(
        to_hex(&encode_error(txn, "connection id mismatch")),
        "0000000311223344636f6e6e656374696f6e206964206d69736d61746368"
    );
    // announce: interval=1800, leechers=3, seeders=5, peer 1.2.3.4:6881
    let peer: SocketAddr = "1.2.3.4:6881".parse().unwrap();
    assert_eq!(
        to_hex(&encode_announce_response(txn, 1800, 3, 5, &[peer])),
        "0000000111223344000007080000000300000005010203041ae1"
    );
    // scrape: (complete=5, downloaded=2, incomplete=3)
    assert_eq!(
        to_hex(&encode_scrape_response(
            txn,
            &[ScrapeCounts {
                complete: 5,
                downloaded: 2,
                incomplete: 3
            }]
        )),
        "0000000211223344000000050000000200000003"
    );
}
