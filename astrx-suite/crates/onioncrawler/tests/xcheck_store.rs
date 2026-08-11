//! Cross-check: the store's deterministic analytics reproduce the Python
//! `onioncrawler.storage.Storage` reference. The SimHash fingerprints and the
//! union-find `cluster_id` assignment must match *exactly* (both are integer /
//! bit-exact); the PageRank-lite authority scores match within a tight f64
//! tolerance (floating-point summation order is not part of the spec). Goldens
//! were emitted by driving the Python `Storage` (SQLite/FTS5) — see
//! `tests/regen_goldens.py`.

use onioncrawler::store::{Caps, Store};

fn ensure(s: &mut Store, host: &str) {
    s.ensure_host(host, 1.0);
    let _ = Caps::default();
}

#[test]
fn compute_authority_matches_python() {
    let mut s = Store::new();
    for h in ["hub.onion", "a.onion", "b.onion", "c.onion", "d.onion"] {
        ensure(&mut s, h);
    }
    s.add_link_edge("a.onion", "hub.onion", 1);
    s.add_link_edge("b.onion", "hub.onion", 2);
    s.add_link_edge("c.onion", "hub.onion", 1);
    s.add_link_edge("d.onion", "hub.onion", 1);
    s.add_link_edge("a.onion", "b.onion", 1);
    s.add_link_edge("hub.onion", "a.onion", 1);
    assert_eq!(s.compute_authority(25, 0.85), 5);

    // (host, expected authority) from the Python reference.
    let want = [
        ("a.onion", 0.926_487_010_276_328_2),
        ("b.onion", 0.470_248_539_465_585_65),
        ("c.onion", 0.076_491_560_098_146_17),
        ("d.onion", 0.076_491_560_098_146_17),
        ("hub.onion", 1.0),
    ];
    for (host, exp) in want {
        let got = s.get_host(host).unwrap().authority;
        assert!(
            (got - exp).abs() < 1e-9,
            "authority({host}) = {got}, want {exp}"
        );
    }
}

#[test]
fn cluster_mirrors_matches_python() {
    let mut s = Store::new();
    ensure(&mut s, "x.onion");
    let pages = [
        ("http://x.onion/1", "the quick brown fox jumps over the lazy dog in the meadow every single morning today", 6_402_011_095_582_929_462_i64, 1_i64),
        ("http://x.onion/2", "the quick brown fox jumps over the lazy dog in the field every single morning today", 1_934_440_127_788_249_654, 1),
        ("http://x.onion/3", "completely different content discussing finance markets equities and interest rates policy", -6_658_559_406_888_252_869, 3),
        ("http://x.onion/4", "the quick brown fox jumps over the lazy dog in the meadow every single evening today", 6_402_011_095_578_866_238, 1),
    ];
    for (url, text, _, _) in pages {
        s.store_page(
            url,
            "x.onion",
            None,
            Some(text),
            Some("h"),
            Some(200),
            Some("text/html"),
            None,
            100.0,
            false,
            None,
            None,
            None,
        );
    }
    assert_eq!(s.cluster_mirrors(5, 1000), 1);
    for (url, _, want_simhash, want_cluster) in pages {
        let p = s.get_page(url).unwrap();
        assert_eq!(p.simhash, Some(want_simhash), "simhash({url})");
        assert_eq!(p.cluster_id, Some(want_cluster), "cluster_id({url})");
    }
}
