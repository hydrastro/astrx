// Exercises `peerstore`, which lives behind the `rand` feature.
#![cfg(feature = "rand")]
//! Cross-check: the Rust `PeerStore` restores a snapshot produced by the Python
//! reference (`legacy-python/torrentds/peerstore.py`) — proving the bencode
//! snapshot format is interoperable (a running Python tracker's state can be
//! migrated into the Rust one). The blob was emitted by the Python `snapshot()`
//! with the clock pinned so peer ages are 0.

use torrentds::peerstore::{PeerStore, ScrapeCounts};

fn sc(complete: u64, incomplete: u64, downloaded: u64) -> ScrapeCounts {
    ScrapeCounts {
        complete,
        incomplete,
        downloaded,
    }
}

fn unhex(s: &str) -> Vec<u8> {
    (0..s.len())
        .step_by(2)
        .map(|i| u8::from_str_radix(&s[i..i + 2], 16).unwrap())
        .collect()
}

#[test]
fn restores_python_snapshot() {
    // Python: announce (1.2.3.4:6881 seeder), (5.6.7.8:51413 leecher, left=999)
    // to infohash 0xAB…; (9.9.9.9:6969 completed) to 0xCD… ; then snapshot().
    let blob = unhex("64363a737761726d736c6431303a646f776e6c6f61646564693065323a696832303aabababababababababababababababababababab353a70656572736c6c373a312e322e332e34693638383165693065693065656c373a352e362e372e386935313431336569393939656930656565656431303a646f776e6c6f61646564693165323a696832303acdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd353a70656572736c6c373a392e392e392e3969363936396569306569306565656565313a7669316565");

    let mut ps = PeerStore::new(1800);
    let restored = ps.restore(&blob, 1000);
    assert_eq!(restored, 3);
    assert_eq!(ps.counts(&[0xABu8; 20], 1000), sc(1, 1, 0)); // 1 seeder, 1 leecher
    assert_eq!(ps.counts(&[0xCDu8; 20], 1000), sc(1, 0, 1)); // 1 seeder, downloaded=1
}
