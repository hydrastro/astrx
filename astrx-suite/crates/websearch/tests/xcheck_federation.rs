//! Cross-check: the Rust `websearch::federation` pure sharding core reproduces
//! the Python `websearch.federation` **byte-identically** — `norm_host`, and the
//! rendezvous / HRW `shard_for` + `owns` (including the add-a-shard rebalance and
//! the empty-shards single-node case). The digest byte-construction
//! (`sha256(shard_id \x00 host)`) and the greatest-digest tie-break must match
//! exactly. Goldens emitted by driving the real Python module (see
//! `tests/regen_goldens.py::gen_federation`).

use websearch::federation::{norm_host, owns, shard_for};

fn shards(list: &[&str]) -> Vec<String> {
    list.iter().map(|s| (*s).to_string()).collect()
}

#[test]
fn norm_host_matches_python() {
    // (input, expected) — case, trailing dot, port, IPv6-in-brackets, empty.
    let cases: &[(&str, &str)] = &[
        ("Example.COM", "example.com"),
        ("example.com.", "example.com"),
        ("EXAMPLE.com:8080", "example.com"),
        ("host", "host"),
        ("", ""),
        ("  Foo.Bar.  ", "foo.bar"),
        ("[2001:db8::1]", "[2001:db8::1]"),
        ("[2001:db8::1]:443", "[2001:db8::1]"),
        ("[::1]", "[::1]"),
        ("a:b:c", "a:b:c"),
        ("localhost:80", "localhost"),
        ("trailing...", "trailing"),
        ("192.168.0.1:9000", "192.168.0.1"),
        ("UPPER.CASE.EXAMPLE.ORG.", "upper.case.example.org"),
    ];
    for (input, expected) in cases {
        assert_eq!(&norm_host(input), expected, "norm_host({input:?})");
    }
}

/// The host corpus each `shard_for` table below is keyed on, in order.
const HOSTS: &[&str] = &[
    "example.com",
    "example.org",
    "a.example.com",
    "news.bbc.co.uk",
    "wikipedia.org",
    "rust-lang.org",
    "python.org",
    "localhost",
    "sub.domain.test",
    "EXAMPLE.COM:443",
];

#[test]
fn shard_for_matches_python() {
    // One row per shard set: expected[i] is the owner of HOSTS[i].
    let tables: &[(&[&str], &[Option<&str>])] = &[
        (&[], &[None; 10]),
        (&["s0"], &[Some("s0"); 10]),
        (
            &["s0", "s1", "s2"],
            &[
                Some("s0"),
                Some("s2"),
                Some("s1"),
                Some("s0"),
                Some("s1"),
                Some("s2"),
                Some("s0"),
                Some("s2"),
                Some("s0"),
                Some("s0"),
            ],
        ),
        (
            // Add s3: HRW reassigns only ~1/N of hosts. a.example.com moves
            // s1 -> s3; every other host keeps its owner.
            &["s0", "s1", "s2", "s3"],
            &[
                Some("s0"),
                Some("s2"),
                Some("s3"),
                Some("s0"),
                Some("s1"),
                Some("s2"),
                Some("s0"),
                Some("s2"),
                Some("s0"),
                Some("s0"),
            ],
        ),
        (
            &["alpha", "beta", "gamma"],
            &[
                Some("alpha"),
                Some("gamma"),
                Some("beta"),
                Some("alpha"),
                Some("alpha"),
                Some("beta"),
                Some("beta"),
                Some("gamma"),
                Some("gamma"),
                Some("alpha"),
            ],
        ),
        (
            &["node-1", "node-2"],
            &[
                Some("node-2"),
                Some("node-2"),
                Some("node-1"),
                Some("node-2"),
                Some("node-2"),
                Some("node-2"),
                Some("node-2"),
                Some("node-2"),
                Some("node-2"),
                Some("node-2"),
            ],
        ),
    ];
    for (set, expected) in tables {
        let sv = shards(set);
        for (host, want) in HOSTS.iter().zip(expected.iter()) {
            assert_eq!(
                shard_for(host, &sv).as_deref(),
                *want,
                "shard_for({host:?}, {set:?})"
            );
        }
    }
    // EXAMPLE.COM:443 normalises to example.com, so it routes identically.
    let sv = shards(&["s0", "s1", "s2"]);
    assert_eq!(
        shard_for("EXAMPLE.COM:443", &sv),
        shard_for("example.com", &sv)
    );
}

#[test]
fn owns_matches_python() {
    // (host, my_id, shards, expected)
    let cases: &[(&str, Option<&str>, &[&str], bool)] = &[
        ("example.com", None, &[], true),
        ("example.com", None, &["s0", "s1", "s2"], true),
        ("example.com", Some("s0"), &[], true),
        ("example.com", Some("s0"), &["s0", "s1", "s2"], true),
        ("example.com", Some("s1"), &["s0", "s1", "s2"], false),
        ("example.com", Some("s2"), &["s0", "s1", "s2"], false),
        ("example.org", Some("s0"), &["s0", "s1", "s2"], false),
        ("example.org", Some("s1"), &["s0", "s1", "s2"], false),
        ("example.org", Some("s2"), &["s0", "s1", "s2"], true),
        (
            "wikipedia.org",
            Some("s0"),
            &["s0", "s1", "s2", "s3"],
            false,
        ),
        (
            "wikipedia.org",
            Some("s3"),
            &["s0", "s1", "s2", "s3"],
            false,
        ),
        ("example.com", Some("nonmember"), &["s0", "s1", "s2"], false),
    ];
    for (host, my_id, set, want) in cases {
        let sv = shards(set);
        assert_eq!(
            owns(host, *my_id, &sv),
            *want,
            "owns({host:?}, {my_id:?}, {set:?})"
        );
    }
}
