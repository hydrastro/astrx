//! Cross-check: the dependency-free Rust `onion` gate produces the exact same
//! results as the Python reference (`legacy-python/onioncrawler/onion.py`) over a
//! corpus that exercises the tricky paths — trailing-dot / port / userinfo /
//! IPv6-bracket normalization, v3 vs v2, the base32 alphabet (no `0 1 8 9`), the
//! i2p b32/name forms, and the in-text scanner's look-behind, port clamp,
//! path-stop set and non-overlapping dedup. Every expected value below was
//! emitted by driving the Python module directly.

use onioncrawler::onion::{
    find_onion_urls, i2p_kind, is_darknet_host, is_i2p_host, is_onion_host, normalize_host,
    onion_version, I2pKind,
};

const V3: &str = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"; // 56
const V3B: &str = "abcdefghijklmnopqrstuvwxyz234567aaaaaaaaaaaaaaaaaaaaaaaa"; // 32+24 = 56
const V2: &str = "bbbbbbbbbbbbbbbb"; // 16
const I2PB32: &str = "cccccccccccccccccccccccccccccccccccccccccccccccccccc"; // 52

#[test]
fn normalize_host_xcheck() {
    let cases: &[(String, &str)] = &[
        ("Example.ONION.".into(), "example.onion"),
        (
            format!("{V3}.onion"),
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.onion",
        ),
        (
            format!("{V3}.onion:8080"),
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.onion",
        ),
        (
            format!("user@{V3}.onion"),
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.onion",
        ),
        (
            format!("[{V3}.onion]:80"),
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.onion",
        ),
        (
            format!("{V3}.onion..."),
            "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.onion",
        ),
        ("  Foo.Onion  ".into(), "foo.onion"),
        (String::new(), ""),
        ("HTTP://x".into(), "http"),
        ("a.b.i2p.".into(), "a.b.i2p"),
        (
            format!("{I2PB32}.B32.I2P"),
            "cccccccccccccccccccccccccccccccccccccccccccccccccccc.b32.i2p",
        ),
    ];
    for (input, expected) in cases {
        assert_eq!(
            normalize_host(input),
            *expected,
            "normalize_host({input:?})"
        );
    }
}

#[test]
fn is_onion_host_v2_off_xcheck() {
    let t: &[(String, bool)] = &[
        (format!("{V3}.onion"), true),
        (format!("{V3B}.onion"), true),
        (format!("{V2}.onion"), false),
        (format!("{V3}.onion."), true),
        (format!("{V3}.onion:9050"), true),
        ("notonion.com".into(), false),
        (format!("{V3}0.onion"), false),
        (format!("{}.onion", &V3[..55]), false), // 55 chars
        (String::new(), false),
        (format!("{I2PB32}.b32.i2p"), false),
        ("stats.i2p".into(), false),
    ];
    for (h, want) in t {
        assert_eq!(
            is_onion_host(h, false),
            *want,
            "is_onion_host({h:?}, v2=off)"
        );
    }
}

#[test]
fn is_onion_host_v2_on_xcheck() {
    assert!(is_onion_host(&format!("{V2}.onion"), true));
    assert!(is_onion_host(&format!("{V3}.onion"), true));
    // contains '1', which is not in the base32 alphabet
    assert!(!is_onion_host("z1z1z1z1z1z1z1z1.onion", true));
}

#[test]
fn onion_version_xcheck() {
    assert_eq!(onion_version(&format!("{V3}.onion")), Some(3));
    assert_eq!(onion_version(&format!("{V2}.onion")), Some(2));
    assert_eq!(onion_version("bad.onion"), None);
    assert_eq!(onion_version(&format!("{V3}.ONION")), Some(3));
}

#[test]
fn i2p_xcheck() {
    let t: &[(String, bool, Option<I2pKind>)] = &[
        (format!("{I2PB32}.b32.i2p"), true, Some(I2pKind::B32)),
        ("stats.i2p".into(), true, Some(I2pKind::Name)),
        ("a.b.i2p".into(), true, Some(I2pKind::Name)),
        ("i2p".into(), false, None),
        (".i2p".into(), false, None),
        ("foo.i2p.evil.com".into(), false, None),
        (format!("{V3}.onion"), false, None),
        ("xn--foo.i2p".into(), true, Some(I2pKind::Name)),
        ("-bad.i2p".into(), false, None),
        ("bad-.i2p".into(), false, None),
        (format!("{I2PB32}.B32.I2P"), true, Some(I2pKind::B32)),
    ];
    for (h, want_is, want_kind) in t {
        assert_eq!(is_i2p_host(h), *want_is, "is_i2p_host({h:?})");
        assert_eq!(i2p_kind(h), *want_kind, "i2p_kind({h:?})");
    }
}

#[test]
fn is_darknet_host_xcheck() {
    // (host, allow_v2, allow_i2p, expected)
    let t: &[(String, bool, bool, bool)] = &[
        (format!("{V3}.onion"), false, false, true),
        (format!("{V2}.onion"), false, false, false),
        (format!("{V2}.onion"), true, false, true),
        ("stats.i2p".into(), false, false, false),
        ("stats.i2p".into(), false, true, true),
        ("evil.com".into(), false, true, false),
    ];
    for (h, v2, i2p, want) in t {
        assert_eq!(
            is_darknet_host(h, *v2, *i2p),
            *want,
            "is_darknet_host({h:?}, {v2}, {i2p})"
        );
    }
}

#[test]
fn find_onion_urls_xcheck() {
    let onion3 = format!("http://{V3}.onion");
    let cases: Vec<(bool, String, Vec<String>)> = vec![
        (
            false,
            format!("visit http://{V3}.onion/path and {V2}.onion too"),
            vec![format!("{onion3}/path")],
        ),
        (
            true,
            format!("visit http://{V3}.onion/path and {V2}.onion too"),
            vec![format!("{onion3}/path"), format!("http://{V2}.onion/")],
        ),
        (
            false,
            format!("bare {V3}.onion here"),
            vec![format!("{onion3}/")],
        ),
        (
            false,
            format!("HTTPS://{V3}.ONION:8080/A/b?x=1 mixed case"),
            vec![format!("https://{V3}.onion:8080/A/b?x=1")],
        ),
        (false, format!("x{V3}.onion adjacency blocked"), vec![]),
        (
            false,
            format!("({V3}.onion) parens then stop"),
            vec![format!("{onion3}/")],
        ),
        (
            false,
            format!("{V3}.onion:123456/over five digits"),
            vec![format!("{onion3}:12345/")],
        ),
        (
            false,
            format!("dup {V3}.onion and {V3}.onion again"),
            vec![format!("{onion3}/")],
        ),
        (
            false,
            format!("{}.onion too-long blob", "d".repeat(72)),
            vec![],
        ),
        (
            false,
            "no onions here at all, just text with words".into(),
            vec![],
        ),
        (
            false,
            format!("path stops at quote {V3}.onion/a\"b"),
            vec![format!("{onion3}/a")],
        ),
        (
            false,
            format!("i2p {I2PB32}.b32.i2p not scanned by find_onion"),
            vec![],
        ),
    ];
    for (v2, text, want) in cases {
        assert_eq!(
            find_onion_urls(&text, v2, 100, "http"),
            want,
            "find_onion_urls({text:?}, v2={v2})"
        );
    }
}
