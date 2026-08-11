//! Cross-check: the Rust `Index::compute_pagerank` / `compute_host_authority`
//! (via `finalize`) reproduce the Python `index.compute_pagerank` /
//! `compute_host_authority` — the internal-graph PageRank-lite written to
//! `doc.rank` and the cross-domain host authority written to `host_authority` +
//! `doc.host_rank`. Same power-iteration (same order, iterations, tolerance), so
//! the f64 results match to full precision. Goldens from `tests/regen_goldens.py`
//! (`gen_pagerank`).

use websearch::index::{DocFields, Index};

fn approx(a: f64, b: f64) {
    assert!((a - b).abs() < 1e-9, "expected {b}, got {a}");
}

#[test]
fn pagerank_and_host_authority_match_python() {
    let mut ix = Index::new();
    let up = |ix: &mut Index, url: &str, host: &str| {
        ix.upsert_document(
            url,
            DocFields {
                title: url,
                body: "body",
                host,
                fetched_at: 100.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
    };
    up(&mut ix, "http://x/a", "x");
    up(&mut ix, "http://x/b", "x");
    up(&mut ix, "http://y/c", "y");
    up(&mut ix, "http://z/d", "z");
    ix.add_links(
        "http://x/a",
        &[
            ("http://x/b".to_string(), true),
            ("http://y/c".to_string(), false),
            ("http://z/d".to_string(), false),
        ],
    );
    ix.add_links(
        "http://x/b",
        &[
            ("http://x/a".to_string(), true),
            ("http://y/c".to_string(), false),
        ],
    );
    ix.add_links("http://y/c", &[("http://x/a".to_string(), false)]);

    ix.finalize();

    // (url, rank, host_rank, incoming)
    let cases: &[(&str, f64, f64, i64)] = &[
        ("http://x/a", 1.000000000, 1.000000000, 1),
        ("http://x/b", 1.000000000, 1.000000000, 1),
        ("http://y/c", 0.150000235, 0.846847401, 0),
        ("http://z/d", 0.150000235, 0.563513819, 0),
    ];
    for (url, rank, host_rank, incoming) in cases {
        let d = ix.get_doc(url).unwrap();
        approx(d.rank, *rank);
        approx(d.host_rank, *host_rank);
        assert_eq!(d.incoming, *incoming, "incoming for {url}");
    }

    // host_authority table
    approx(ix.host_authority("x").unwrap(), 1.000000000);
    approx(ix.host_authority("y").unwrap(), 0.846847401);
    approx(ix.host_authority("z").unwrap(), 0.563513819);
}
