//! Cross-check: the Rust `websearch::robots` reproduces the Python
//! `websearch.robots` — grouping, `Allow`/`Disallow` with `*`/`$`, longest-match
//! with `Allow` tie-break, specific-agent precedence, empty-Disallow = allow-all,
//! and `Crawl-delay`. Goldens emitted from the Python module.

use websearch::robots::parse;

#[test]
fn robots_matches_python() {
    // r1: longest-match + Allow tie-break + crawl-delay
    let r1 = parse(
        "User-agent: *\nDisallow: /private\nAllow: /private/ok\nCrawl-delay: 2.5\n",
        "mybot",
    );
    assert!(!r1.can_fetch("/private/x"));
    assert!(r1.can_fetch("/private/ok/y"));
    assert!(r1.can_fetch("/public"));
    assert_eq!(r1.crawl_delay(), Some(2.5));

    // r2: a specific agent group beats the '*' group
    let r2 = parse(
        "User-agent: mybot\nDisallow: /\nUser-agent: *\nDisallow: /x\n",
        "mybot",
    );
    assert!(!r2.can_fetch("/anything"));
    assert!(!r2.can_fetch("/x"));

    // r3: empty robots → allow all
    let r3 = parse("", "any");
    assert!(r3.can_fetch("/"));

    // r4: an empty Disallow contributes no restriction → allow all
    let r4 = parse("User-agent: *\nDisallow:\n", "any");
    assert!(r4.can_fetch("/anything"));

    // r5: `$` end-anchor
    let r5 = parse("User-agent: *\nDisallow: /*.pdf$\n", "any");
    assert!(!r5.can_fetch("/a.pdf"));
    assert!(r5.can_fetch("/a.pdf?x"));
    assert!(r5.can_fetch("/a.html"));
}
