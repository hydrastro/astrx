//! Cross-check: the Rust `websearch::ranking` pure functions reproduce the Python
//! `websearch.ranking` — `parse_query` (the query language + operators),
//! `parse_date`, the scoring components (`_freshness` / `_proximity_bonus` /
//! `_content_quality`), and `make_snippet` (window selection + HTML escaping +
//! `<mark>` highlighting). Goldens from `tests/regen_goldens.py` (`gen_ranking`).
//! The full BM25 search ranking is behavioural (FTS5 has no stdlib equivalent)
//! and is unit-tested in the module instead.

use websearch::ranking::{
    content_quality, freshness, make_snippet, parse_date, parse_query, proximity_bonus,
};

fn approx(a: f64, b: f64) {
    assert!((a - b).abs() < 1e-6, "expected {b}, got {a}");
}

fn sv(v: &[&str]) -> Vec<String> {
    v.iter().map(|s| (*s).to_string()).collect()
}

#[test]
fn parse_query_matches_python() {
    let q = parse_query("rust programming");
    assert_eq!(q.optional, sv(&["rust", "programming"]));
    assert_eq!(q.highlight, sv(&["rust", "programming"]));

    let q = parse_query("+rust -java \"web crawler\"");
    assert_eq!(q.required, sv(&["rust"]));
    assert_eq!(q.excluded, sv(&["java"]));
    assert_eq!(q.phrases, vec![sv(&["web", "crawler"])]);
    assert_eq!(q.highlight, sv(&["rust", "web", "crawler"]));

    let q = parse_query("site:example.com lang:en foo");
    assert_eq!(q.optional, sv(&["foo"]));
    assert_eq!(q.site.as_deref(), Some("example.com"));
    assert_eq!(q.lang.as_deref(), Some("en"));

    let q = parse_query("intitle:Rust before:2020-01-01 boost:good.com");
    assert_eq!(q.intitle, sv(&["rust"]));
    assert_eq!(q.highlight, sv(&["rust"]));
    assert_eq!(q.before, Some(1_577_836_800.0));
    assert_eq!(q.boost, sv(&["good.com"]));

    let q = parse_query("\"a\" host:X.COM/ filetype:PDF");
    assert_eq!(q.required, sv(&["a"])); // 1-word quote → required
    assert_eq!(q.site.as_deref(), Some("x.com"));
    assert_eq!(q.filetype.as_deref(), Some("pdf"));
}

#[test]
fn parse_date_matches_python() {
    assert_eq!(parse_date("2020-01-01"), Some(1_577_836_800.0));
    assert_eq!(parse_date("2021-06-15"), Some(1_623_715_200.0));
    assert_eq!(parse_date("bad"), None);
}

#[test]
fn freshness_matches_python() {
    approx(freshness(0.0, 1000.0), 0.0);
    approx(freshness(1000.0, 1000.0), 1.0);
    approx(freshness(1000.0, 1000.0 + 30.0 * 86400.0), 0.367879);
    approx(freshness(1000.0, 1000.0 + 60.0 * 86400.0), 0.135335);
}

#[test]
fn content_quality_matches_python() {
    approx(content_quality(&"x".repeat(0)), 0.0);
    approx(content_quality(&"x".repeat(50)), 0.0);
    approx(content_quality(&"x".repeat(100)), 0.0);
    approx(content_quality(&"x".repeat(101)), 0.000909);
    approx(content_quality(&"x".repeat(600)), 0.454545);
    approx(content_quality(&"x".repeat(1200)), 1.0);
    approx(content_quality(&"x".repeat(2000)), 1.0);
}

#[test]
fn proximity_matches_python() {
    approx(
        proximity_bonus(
            "the web crawler is here",
            &[sv(&["web", "crawler"])],
            &sv(&["web", "crawler"]),
        ),
        1.0,
    );
    approx(
        proximity_bonus(
            "web then some words then crawler",
            &[],
            &sv(&["web", "crawler"]),
        ),
        0.642857,
    );
    approx(
        proximity_bonus("nothing relevant", &[], &sv(&["web", "crawler"])),
        0.0,
    );
}

#[test]
fn snippet_matches_python() {
    let body = "The quick brown fox jumps over the lazy dog. ".repeat(5);
    assert_eq!(make_snippet(&body, &sv(&["fox"]), 60), "&hellip;  &hellip;");
    assert_eq!(
        make_snippet(
            "<script>alert(1)</script> safe word here",
            &sv(&["word"]),
            80
        ),
        "&hellip; here"
    );
    assert_eq!(make_snippet("", &sv(&["x"]), 280), "");
}
