//! Cross-check: the Rust `websearch::canonical` reproduces the Python
//! `websearch.canonical` exactly — `canonicalize` (default-port drop, userinfo /
//! fragment strip, RFC-3986 dot-segments, multi-slash collapse, query sort, IPv6
//! brackets, base resolution, non-http rejection), plus `host_of` /
//! `authority_of` / `is_http_url` / `in_scope`. Goldens emitted from the Python
//! module; regenerate with `tests/regen_goldens.py`.

use websearch::canonical::{authority_of, canonicalize, host_of, in_scope, is_http_url};

/// (input, base, expected canonical | None)
const CANON: &[(&str, Option<&str>, Option<&str>)] = &[
    (
        "http://Example.COM/a/./b/../c",
        None,
        Some("http://example.com/a/c"),
    ),
    (
        "HTTP://example.com:80/path",
        None,
        Some("http://example.com/path"),
    ),
    (
        "https://example.com:443/",
        None,
        Some("https://example.com/"),
    ),
    (
        "http://example.com:8080/x?b=2&a=1",
        None,
        Some("http://example.com:8080/x?a=1&b=2"),
    ),
    (
        "http://example.com/a//b///c",
        None,
        Some("http://example.com/a/b/c"),
    ),
    ("http://example.com", None, Some("http://example.com/")),
    (
        "//example.com/x",
        Some("http://base.com/"),
        Some("http://example.com/x"),
    ),
    (
        "/rel/path",
        Some("http://example.com/a/b"),
        Some("http://example.com/rel/path"),
    ),
    (
        "../up",
        Some("http://example.com/a/b/c"),
        Some("http://example.com/a/up"),
    ),
    (
        "http://user:pass@example.com/x",
        None,
        Some("http://example.com/x"),
    ),
    (
        "http://[2001:db8::1]:8080/x",
        None,
        Some("http://[2001:db8::1]:8080/x"),
    ),
    ("ftp://example.com/x", None, None),
    ("not a url", None, None),
    (
        "http://example.com/p?z=1&a=2&a=1",
        None,
        Some("http://example.com/p?a=1&a=2&z=1"),
    ),
    (
        "http://example.com/%7euser/",
        None,
        Some("http://example.com/%7euser/"),
    ),
];

#[test]
fn canonicalize_matches_python() {
    for (input, base, expected) in CANON {
        assert_eq!(
            canonicalize(input, *base).as_deref(),
            *expected,
            "canonicalize({input:?}, base={base:?})"
        );
    }
}

/// (url, host, authority, is_http)
const PARTS: &[(&str, &str, &str, bool)] = &[
    (
        "http://User@Example.com:8080/x",
        "example.com",
        "example.com:8080",
        true,
    ),
    ("https://example.com/", "example.com", "example.com", true),
    (
        "http://[2001:db8::1]:99/",
        "2001:db8::1",
        "[2001:db8::1]:99",
        true,
    ),
    ("ftp://x/", "x", "x", false),
    ("http://example.com:80/", "example.com", "example.com", true),
];

#[test]
fn host_authority_ishttp_match_python() {
    for (url, host, auth, http) in PARTS {
        assert_eq!(host_of(url), *host, "host_of({url:?})");
        assert_eq!(authority_of(url), *auth, "authority_of({url:?})");
        assert_eq!(is_http_url(url), *http, "is_http_url({url:?})");
    }
}

#[test]
fn in_scope_matches_python() {
    let scope = ["example.com".to_string()];
    assert!(in_scope("http://a.example.com/x", Some(&scope)));
    assert!(!in_scope("http://evil.com/x", Some(&scope)));
    assert!(in_scope("http://x/y", None));
}
