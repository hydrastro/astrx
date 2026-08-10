//! Cross-check: the store's deterministic helpers are byte-identical to the
//! Python reference (`legacy-python/torrentds/store.py`). Goldens were emitted by
//! driving `store.categorize`, `store.content_signature` and `store.magnet_link`.
//! (The BM25 search *ranking* is verified behaviorally in the unit tests, not
//! here — SQLite FTS5's exact float output is an implementation detail.)

use torrentds::store::{categorize, content_signature, magnet_link};

fn f(parts: &[(&str, u64)]) -> Vec<(String, u64)> {
    parts.iter().map(|(p, l)| (p.to_string(), *l)).collect()
}

#[test]
fn categorize_matches_python() {
    assert_eq!(
        categorize("movie", &f(&[("a.mkv", 1), ("b.srt", 1)])),
        "video"
    );
    assert_eq!(
        categorize("pack", &f(&[("x.mp3", 1), ("y.flac", 1)])),
        "audio"
    );
    assert_eq!(
        categorize("mixed", &f(&[("a.mkv", 1), ("b.mp3", 1), ("c.mkv", 1)])),
        "video"
    );
    // tie (video vs audio, one each) -> first-seen wins (video)
    assert_eq!(
        categorize("tie", &f(&[("a.mkv", 1), ("b.mp3", 1)])),
        "video"
    );
    assert_eq!(categorize("readme", &f(&[])), "other");
    // the display name's own extension counts
    assert_eq!(categorize("archive.zip", &f(&[("data.bin", 1)])), "archive");
}

#[test]
fn content_signature_matches_python() {
    assert_eq!(
        content_signature(&f(&[("a.txt", 100), ("sub/b.bin", 200)]), None).as_deref(),
        Some("e17fe42548a1dfbdc06fe8df9535c4205fcd1bd11e26f41b9045473daef7e27e")
    );
    assert_eq!(
        content_signature(
            &f(&[("a.txt", 100), ("sub/b.bin", 200)]),
            Some(&[0x11u8; 32])
        )
        .as_deref(),
        Some("305403e980845968aa195ba0adf11441d367336c03c0663019b04a7eb59d0866")
    );
    // order-independent (the pairs are sorted before hashing)
    assert_eq!(
        content_signature(&f(&[("z.bin", 1), ("a.bin", 2)]), None).as_deref(),
        Some("6e9e2cfe0d53696a2726bc3980a7f145f382b9e247f063f8fdeaa90588759448")
    );
    assert_eq!(content_signature(&f(&[]), None), None);
}

#[test]
fn magnet_link_matches_python() {
    let ih = "0123456789abcdef0123456789abcdef01234567";
    let v2 = "aa".repeat(32);
    let v2b = "bb".repeat(32);
    assert_eq!(
        magnet_link(Some(ih), Some("Test Movie 2019"), None),
        "magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=Test%20Movie%202019"
    );
    assert_eq!(
        magnet_link(Some(ih), None, None),
        "magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567"
    );
    assert_eq!(
        magnet_link(None, Some("v2 only"), Some(&v2)),
        "magnet:?xt=urn:btmh:1220aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa&dn=v2%20only"
    );
    // '&' -> %26, '/' kept (safe='/'), '!' -> %21, space -> %20
    assert_eq!(
        magnet_link(Some(ih), Some("Hybrid & Special/Chars!"), Some(&v2b)),
        "magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&xt=urn:btmh:1220bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb&dn=Hybrid%20%26%20Special/Chars%21"
    );
    // '+' -> %2B (quote, not quote_plus)
    assert_eq!(
        magnet_link(Some(ih), Some("space test+plus"), None),
        "magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=space%20test%2Bplus"
    );
}
