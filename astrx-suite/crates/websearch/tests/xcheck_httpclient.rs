//! Cross-check: the Rust `websearch::httpclient` pure helpers reproduce the
//! Python `websearch.httpclient` — `_parse_content_type`, `_authority_exempt`,
//! `_decompress` (over `crawlcore::inflate`), the `_ip_is_internal` /
//! `_resolve_checked` SSRF gate decision, and `decode_body`'s sniffing for the
//! encodings the stdlib reproduces natively. Goldens emitted by
//! `tests/regen_goldens.py` (the `gen_httpclient` section).

use std::net::IpAddr;
use websearch::httpclient::{
    authority_exempt, decode_body, decompress, parse_content_type, vet_addrs, GateError,
};

fn hex(s: &str) -> Vec<u8> {
    (0..s.len())
        .step_by(2)
        .map(|i| u8::from_str_radix(&s[i..i + 2], 16).unwrap())
        .collect()
}

#[test]
fn content_type_matches_python() {
    // (input, media_type, charset)
    let cases: &[(&str, &str, Option<&str>)] = &[
        ("text/HTML; charset=UTF-8", "text/html", Some("utf-8")),
        ("application/json", "application/json", None),
        (
            "text/plain; charset=\"ISO-8859-1\"",
            "text/plain",
            Some("iso-8859-1"),
        ),
        ("TEXT/Plain ; Charset='utf-8'", "text/plain", Some("utf-8")),
        (
            "image/png; boundary=x; charset=us-ascii",
            "image/png",
            Some("us-ascii"),
        ),
        ("", "", None),
    ];
    for (input, ct, cs) in cases {
        let (gct, gcs) = parse_content_type(input);
        assert_eq!(gct, *ct, "media type for {input:?}");
        assert_eq!(gcs.as_deref(), *cs, "charset for {input:?}");
    }
}

#[test]
fn authority_exempt_matches_python() {
    let allow = vec![
        "intranet:8080".to_string(),
        "[::1]".to_string(),
        "Example.COM".to_string(),
    ];
    assert!(authority_exempt("intranet", 8080, &allow));
    assert!(!authority_exempt("intranet", 80, &allow));
    assert!(authority_exempt("::1", 443, &allow));
    assert!(authority_exempt("example.com", 80, &allow));
    assert!(!authority_exempt("other", 80, &allow));
    assert!(!authority_exempt("intranet", 8080, &[]));
}

#[test]
fn ssrf_gate_matches_python() {
    let ip = |s: &str| s.parse::<IpAddr>().unwrap();

    // gate:['8.8.8.8'],True,False -> ok:1
    assert_eq!(vet_addrs(&[ip("8.8.8.8")], true, false).unwrap().len(), 1);
    // gate:['8.8.8.8','127.0.0.1'],True,False -> blocked:127.0.0.1
    assert_eq!(
        vet_addrs(&[ip("8.8.8.8"), ip("127.0.0.1")], true, false),
        Err(GateError::Blocked("127.0.0.1".to_string()))
    );
    // gate:['127.0.0.1'],True,True -> ok:1 (exempt)
    let exempt = vet_addrs(&[ip("127.0.0.1")], true, true).unwrap();
    assert_eq!(exempt.len(), 1);
    assert_eq!(exempt[0].addr(), ip("127.0.0.1"));
    // gate:['10.0.0.1','192.168.1.1'],False,False -> ok:2 (guard off)
    assert_eq!(
        vet_addrs(&[ip("10.0.0.1"), ip("192.168.1.1")], false, false)
            .unwrap()
            .len(),
        2
    );
    // gate:['1.1.1.1','2606:4700::1111'],True,False -> ok:2
    assert_eq!(
        vet_addrs(&[ip("1.1.1.1"), ip("2606:4700::1111")], true, false)
            .unwrap()
            .len(),
        2
    );
    // gate:['93.184.216.34','169.254.169.254'],True,False -> blocked:169.254.169.254
    assert_eq!(
        vet_addrs(&[ip("93.184.216.34"), ip("169.254.169.254")], true, false),
        Err(GateError::Blocked("169.254.169.254".to_string()))
    );
}

#[test]
fn decompress_matches_python() {
    let plain = b"the quick brown fox".repeat(4);
    // (encoding, hex of the compressed/identity blob) — each must decompress to `plain`.
    let cases: &[(&str, &str)] = &[
        ("gzip", "1f8b08000000000002032bc94855282ccd4cce56482aca2fcf5348cbaf282157080056dbc3144c000000"),
        ("deflate", "78da2bc94855282ccd4cce56482aca2fcf5348cbaf2821570800532a1ccd"),
        ("deflate", "2bc94855282ccd4cce56482aca2fcf5348cbaf2821570800"),
        ("zlib", "78da2bc94855282ccd4cce56482aca2fcf5348cbaf2821570800532a1ccd"),
        ("identity", "74686520717569636b2062726f776e20666f7874686520717569636b2062726f776e20666f7874686520717569636b2062726f776e20666f7874686520717569636b2062726f776e20666f78"),
    ];
    for (enc, blob) in cases {
        assert_eq!(
            decompress(&hex(blob), enc, 1_000_000),
            plain,
            "decompress {enc}"
        );
    }
}

#[test]
fn decode_body_matches_python() {
    // (body hex, charset, expected text) — the encodings the stdlib reproduces.
    assert_eq!(decode_body(&hex("636166c3a9"), Some("utf-8")), "café");
    assert_eq!(decode_body(&hex("636166e9"), Some("latin-1")), "café");
    assert_eq!(
        decode_body(&hex("3c6d65746120636861727365743d7574662d383ec3a9"), None),
        "<meta charset=utf-8>é"
    );
    assert_eq!(
        decode_body(&hex("706c61696e2061736369692074657874"), None),
        "plain ascii text"
    );
}
