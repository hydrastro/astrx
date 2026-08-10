//! Cross-check: the Rust `AbuseFilter` matches the Python reference
//! (`legacy-python/onioncrawler/abuse.py`) — host blocklist + Ahmia
//! `md5(domain)` bans, keyword matching with the `(?<![0-9a-z])…(?![0-9a-z])`
//! boundary (case-insensitive, `_` acts as a boundary), and SHA-256 media
//! hashing. Every expected value was emitted by driving the Python module.

use onioncrawler::abuse::AbuseFilter;

fn onion(c: char) -> String {
    format!("{}.onion", c.to_string().repeat(56))
}

#[test]
fn abuse_xcheck() {
    let a = onion('a');
    let b = onion('b');
    let c = onion('c');

    assert_eq!(
        AbuseFilter::host_md5(&a),
        "2b0ef34da922697515f37ee789254436"
    );

    let f = AbuseFilter::new(
        &[a.clone(), format!("{b}:9050")],
        &["scam".into(), "bad phrase".into(), "xxx".into()],
        &["ABC123".into(), "deadbeef".into()],
        &[AbuseFilter::host_md5(&c)],
    );

    // host blocklist (explicit + normalized + md5-ban)
    assert!(f.host_blocked(&a));
    assert!(f.host_blocked(&format!("{a}:80")));
    assert!(f.host_blocked(&b));
    assert!(f.host_blocked(&c));
    assert!(!f.host_blocked("clearnet.com"));

    assert_eq!(
        f.banned_host_md5s(),
        vec![
            "2b0ef34da922697515f37ee789254436".to_string(),
            "fe085cefe884c7c2aef73659833256f1".to_string()
        ]
    );
    assert_eq!(
        f.keywords(),
        [
            "scam".to_string(),
            "bad phrase".to_string(),
            "xxx".to_string()
        ]
    );
    assert_eq!(
        f.media_hashes(),
        vec!["abc123".to_string(), "deadbeef".to_string()]
    );

    // keyword matching
    assert_eq!(
        f.content_hit(&["This is a SCAM offer"]),
        Some("scam".into())
    );
    assert_eq!(f.content_hit(&["nothing here"]), None);
    assert_eq!(
        f.content_hit(&["a Bad Phrase indeed"]),
        Some("bad phrase".into())
    );
    assert_eq!(f.content_hit(&["scamper"]), None);
    assert_eq!(f.content_hit(&["x_scam_y"]), Some("scam".into()));
    assert_eq!(f.content_hit(&["title xxx", "body"]), Some("xxx".into()));
    assert_eq!(f.content_hit(&["", ""]), None);

    // page_blocked (host check precedes keyword check)
    assert_eq!(
        f.page_blocked(&a, "t", "b"),
        Some(format!("blocked-host:{a}"))
    );
    assert_eq!(
        f.page_blocked(&c, "hello", "world"),
        Some(format!("blocked-host:{c}"))
    );
    assert_eq!(
        f.page_blocked(&b, "clean", "SCAM here"),
        Some(format!("blocked-host:{b}"))
    );
    assert_eq!(f.page_blocked("ok.onion", "clean", "clean"), None);

    // media hashing
    assert_eq!(
        AbuseFilter::hash_media(b"abc"),
        "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
    );
    assert_eq!(
        AbuseFilter::hash_media(b""),
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    );

    let sha = AbuseFilter::hash_media(b"blockme");
    let f2 = AbuseFilter::new(&[], &[], &[sha], &[]);
    assert_eq!(
        f2.media_bytes_blocked(b"blockme"),
        Some("fcff13363d92d3d5243b570bcb6009730f0f9933c45f7957171851fad43829dd".into())
    );
    assert_eq!(f2.media_bytes_blocked(b"other"), None);
    assert!(f.media_blocked("ABC123"));
    assert!(!f.media_blocked("nope"));
    assert!(!f.media_blocked(""));
    assert!(f.has_media_blocklist());
}
