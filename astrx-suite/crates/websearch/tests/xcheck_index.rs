//! Cross-check: the Rust `websearch::index` store core reproduces the Python
//! `websearch.index` (SQLite) — `content_hash` (SHA-256 over crawlcore),
//! `upsert_document` rowids + update-in-place, `get_validators` /
//! `touch_revalidated`, `due_for_recrawl` ordering, the `(src→dst)` link graph +
//! `recompute_incoming`, and `stats`. Goldens emitted by `tests/regen_goldens.py`
//! (the `gen_index` section). The FTS5/BM25 search + verticals + PageRank are the
//! ranking increment (not yet ported).

use websearch::index::{content_hash, DocFields, Index};

#[test]
fn content_hash_matches_python() {
    // (parts, expected hex) — SHA-256 over each part + a NUL separator.
    let cases: &[(&[&str], &str)] = &[
        (
            &["a", "b", "c"],
            "13e228567e8249fce53337f25d7970de3bd68ab2653424c7b8f9fd05e33caedf",
        ),
        (
            &["T2", "", ""],
            "5da78e563e083d6de66b9d220634adf34e185127cc0a339d47e75f9bc78e1a33",
        ),
        (
            &["Hello", "World", "Body text here"],
            "29f6d9417006cca8835f516d1c56c877d72cce0b8e846f230f3ce07604de50e6",
        ),
        (
            &["", "", ""],
            "709e80c88487a2411e1ee4dfb9f22a861492d20c4765150c0c794abd70f8147c",
        ),
        (
            &["café", "é", ""],
            "8f88d8c1c74e46a35ac970ddbdbf844d916a30949820473c13dd8e04ca7ca18e",
        ),
    ];
    for (parts, want) in cases {
        assert_eq!(&content_hash(parts), want, "content_hash({parts:?})");
    }
}

#[test]
fn store_matches_python() {
    let mut ix = Index::new();

    let a1 = ix.upsert_document(
        "http://x/a",
        DocFields {
            title: "A",
            body: "alpha body",
            host: "x",
            lang: "en",
            fetched_at: 100.0,
            etag: "\"v1\"",
            http_status: 200,
            ..DocFields::default()
        },
    );
    assert_eq!(a1, 1); // id1
    assert_eq!(
        ix.upsert_document(
            "http://x/b",
            DocFields {
                title: "B",
                body: "beta body",
                host: "x",
                lang: "en",
                fetched_at: 200.0,
                http_status: 200,
                ..DocFields::default()
            }
        ),
        2
    ); // id2
    assert_eq!(
        ix.upsert_document(
            "http://y/c",
            DocFields {
                title: "C",
                body: "gamma body",
                host: "y",
                lang: "fr",
                fetched_at: 300.0,
                http_status: 200,
                ..DocFields::default()
            }
        ),
        3
    ); // id3
       // re-upsert a: same id, etag cleared (passed empty), fetched_at updated
    assert_eq!(
        ix.upsert_document(
            "http://x/a",
            DocFields {
                title: "A2",
                body: "alpha body",
                host: "x",
                lang: "en",
                fetched_at: 150.0,
                http_status: 200,
                ..DocFields::default()
            }
        ),
        1
    ); // id1_re
    assert_eq!(
        ix.get_validators("http://x/a"),
        (String::new(), String::new())
    ); // valid_a
    ix.touch_revalidated("http://x/a", 500.0, Some("\"v2\""), None);
    assert_eq!(
        ix.get_validators("http://x/a"),
        ("\"v2\"".to_string(), String::new())
    ); // valid_a2
    assert_eq!(
        ix.get_validators("http://nope"),
        (String::new(), String::new())
    ); // valid_missing

    // due at now=1000, interval=100 → cutoff 900; order by fetched_at asc:
    // b(200), c(300), a(500)
    assert_eq!(
        ix.due_for_recrawl(100.0, 1000.0),
        vec![
            ("http://x/b".to_string(), "x".to_string()),
            ("http://y/c".to_string(), "y".to_string()),
            ("http://x/a".to_string(), "x".to_string()),
        ]
    );

    ix.add_links(
        "http://x/a",
        &[
            ("http://x/b".to_string(), true),
            ("http://y/c".to_string(), false),
        ],
    );
    ix.add_links("http://x/a", &[("http://x/b".to_string(), true)]); // dup ignored
    ix.add_links("http://y/c", &[("http://x/b".to_string(), true)]);
    ix.recompute_incoming();
    assert_eq!(ix.get_doc("http://x/b").unwrap().incoming, 2); // incoming_b
    assert_eq!(ix.get_doc("http://x/a").unwrap().incoming, 0); // incoming_a

    let s = ix.stats();
    assert_eq!(s.docs, 3);
    assert_eq!(s.hosts, 2);
    assert_eq!(s.links, 3);
    assert_eq!(s.oldest, Some(200.0));
    assert_eq!(s.newest, Some(500.0));
    assert_eq!(
        s.top_hosts,
        vec![("x".to_string(), 2), ("y".to_string(), 1)]
    );
    assert_eq!(
        s.languages,
        vec![("en".to_string(), 2), ("fr".to_string(), 1)]
    );
}
