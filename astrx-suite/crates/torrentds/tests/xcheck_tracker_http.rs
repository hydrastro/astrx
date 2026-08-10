// Exercises `tracker_http`, which lives behind the `net` feature.
#![cfg(feature = "net")]
//! Cross-check: the Rust HTTP tracker's bencoded responses are byte-identical to
//! the Python reference (`legacy-python/torrentds/tracker_http.py`).

use std::net::SocketAddr;
use torrentds::tracker_http::{announce_response_bytes, failure_bytes, scrape_response_bytes};
use torrentds::ScrapeCounts;

fn to_hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

#[test]
fn http_tracker_responses_match_python() {
    let peer: SocketAddr = "1.2.3.4:6881".parse().unwrap();
    // announce: interval=1800, complete=5, incomplete=3, one compact peer
    assert_eq!(
        to_hex(&announce_response_bytes(1800, 5, 3, &[peer], &[], true)),
        "64383a636f6d706c65746569356531303a696e636f6d706c657465693365383a696e74657276616c69313830306531323a6d696e20696e74657276616c6939303065353a7065657273363a010203041ae165"
    );
    // scrape: infohash 0x42… -> (complete=5, downloaded=2, incomplete=3)
    let ih = [0x42u8; 20];
    assert_eq!(
        to_hex(&scrape_response_bytes(&[(
            ih,
            ScrapeCounts {
                complete: 5,
                downloaded: 2,
                incomplete: 3
            }
        )])),
        "64353a66696c65736432303a424242424242424242424242424242424242424264383a636f6d706c65746569356531303a646f776e6c6f6164656469326531303a696e636f6d706c657465693365656565"
    );
    assert_eq!(
        to_hex(&failure_bytes("invalid info_hash")),
        "6431343a6661696c75726520726561736f6e31373a696e76616c696420696e666f5f6861736865"
    );
}
