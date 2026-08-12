//! Cross-check: `gitweb::auth` is byte-identical to the Python `gitweb.auth` —
//! the `sha256$salt$hex` verifier format, the constant-time verification, the
//! `--auth` spec parser (including every `ValueError` message), and HTTP Basic
//! header handling (valid, wrong, malformed base64, non-ASCII usernames).
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `gen_auth`); the header
//! handling was additionally validated against the reference on ~500 randomly
//! assembled `Authorization` values.

use gitweb::auth::{check_basic_auth, hash_password, parse_auth_spec, verify_password};

#[test]
fn hash_password_matches_python() {
    let cases: &[(&str, &str, &str)] = &[
    ("hunter2", "deadbeef", "sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45"),
    ("s3cret", "abcd1234", "sha256$abcd1234$6b1b996067ce2c8e6845f1d18328f301b580da5cc13bc116d606e073ad68f7a2"),
    ("", "00", "sha256$00$f1534392279bddbf9d43dde8701cb5be14b82f76ec6607bf8d6ad557f60f304e"),
    ("p@ss w/ spaces", "0123456789abcdef", "sha256$0123456789abcdef$36304ae4400303ba55050436e3491b1dd7404945ee03edee8c2954fd69a007db"),
    ("café—naïve", "fedcba9876543210", "sha256$fedcba9876543210$0cc94761e0a5d7f08d7ee693ef987cdf559f9bf36c4dca00940205a1ae7a242c"),
    ("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "ff", "sha256$ff$b2d456e883403cb76e93a3ac70e076678afe6ca278c54f8e1ad680d7b1a4bf8d"),
    ];
    for (pw, salt, want) in cases {
        assert_eq!(
            &hash_password(pw, salt),
            want,
            "hash_password({pw:?}, {salt:?})"
        );
    }
}

#[test]
fn verify_password_matches_python() {
    let cases: &[(&str, &str, bool)] = &[
        (
            "sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45",
            "hunter2",
            true,
        ),
        (
            "sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45",
            "wrong",
            false,
        ),
        (
            "sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45",
            "",
            false,
        ),
        ("garbage", "hunter2", false),
        ("", "", false),
        ("sha256$deadbeef$", "hunter2", false),
        (
            "sha256$$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45",
            "hunter2",
            false,
        ),
        ("md5$deadbeef$abc", "hunter2", false),
        ("sha256$deadbeef$abc$def", "hunter2", false),
        (
            "sha256$$e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            "",
            true,
        ),
    ];
    for (stored, pw, want) in cases {
        assert_eq!(
            verify_password(stored, pw),
            *want,
            "verify_password({stored:?}, {pw:?})"
        );
    }
}

#[test]
fn parse_auth_spec_matches_python() {
    // (spec, "none" | "cred" | "err", user, stored-or-error-message)
    let cases: &[(&str, &str, &str, &str)] = &[
    ("", "none", "", ""),
    ("   ", "none", "", ""),
    ("  bob:sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45  ", "cred", "bob", "sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45"),
    ("bob:sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45", "cred", "bob", "sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45"),
    ("nocolon", "err", "", "auth spec must be 'user:sha256$salt$hex'"),
    ("bob:plaintext", "err", "", "auth password must be 'sha256$salt$hex'"),
    (":sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45", "err", "", "auth spec must be 'user:sha256$salt$hex'"),
    ("bob:", "err", "", "auth spec must be 'user:sha256$salt$hex'"),
    ("bob:sha256$deadbeef", "err", "", "auth password must be 'sha256$salt$hex'"),
    ("bob:sha256$deadbeef$abc$def", "err", "", "auth password must be 'sha256$salt$hex'"),
    ("bob:md5$deadbeef$abc", "err", "", "auth password must be 'sha256$salt$hex'"),
    ("bob:sha256$$abc", "err", "", "auth password must have a non-empty salt and hash"),
    ("bob:sha256$deadbeef$", "err", "", "auth password must have a non-empty salt and hash"),
    ("b:o:b:sha256$deadbeef$cb4f906961ba8347b5e61846dd20e8a8ddf897a165e01db99479ba2a3cbd5e45", "err", "", "auth password must be 'sha256$salt$hex'"),
    ];
    for (spec, tag, user, detail) in cases {
        match parse_auth_spec(spec) {
            Ok(None) => assert_eq!(*tag, "none", "{spec:?} parsed as disabled"),
            Ok(Some(cred)) => {
                assert_eq!(*tag, "cred", "{spec:?} parsed as a credential");
                assert_eq!(&cred.user, user, "user for {spec:?}");
                assert_eq!(&cred.stored, detail, "stored for {spec:?}");
            }
            Err(e) => {
                assert_eq!(*tag, "err", "{spec:?} rejected");
                assert_eq!(e.message(), *detail, "message for {spec:?}");
                assert_eq!(e.to_string(), *detail, "Display for {spec:?}");
            }
        }
    }
}

#[test]
fn check_basic_auth_matches_python() {
    let cred = parse_auth_spec(&format!("alice:{}", hash_password("s3cret", "abcd1234")))
        .expect("valid spec")
        .expect("configured");
    let cases: &[(Option<&str>, bool)] = &[
        (None, false),
        (Some(""), false),
        (Some("Basic YWxpY2U6czNjcmV0"), true),
        (Some("basic YWxpY2U6czNjcmV0"), true),
        (Some("BASIC YWxpY2U6czNjcmV0"), true),
        (Some("Basic  YWxpY2U6czNjcmV0  "), true),
        (Some("\tBasic\tYWxpY2U6czNjcmV0"), true),
        (Some("Basic YWxpY2U6bm9wZQ=="), false),
        (Some("Basic Ym9iOnMzY3JldA=="), false),
        (Some("Basic YWxpY2U="), false),
        (Some("Basic YWxpY2U6czNjcmV0OmV4dHJh"), false),
        (Some("Basic OnMzY3JldA=="), false),
        (Some("Bearer YWxpY2U6czNjcmV0"), false),
        (Some("Basic"), false),
        (Some("Basic "), false),
        (Some("Basic !!!not-base64!!!"), false),
        (Some("Basic QQ="), false),
        (Some("Basic QQ"), false),
        (Some("Basic Q"), false),
        (Some("Basic "), false),
        (Some("Basic w7w6cHc="), false),
        (Some("Basic YWxpY2U6czNjcmV0=="), true),
        (Some("Basic //46czNjcmV0"), false),
        (Some("Basic YWxpY2U6czNjcmV0"), true),
    ];
    for (header, want) in cases {
        assert_eq!(
            check_basic_auth(*header, &cred),
            *want,
            "check_basic_auth({header:?})"
        );
    }
}
