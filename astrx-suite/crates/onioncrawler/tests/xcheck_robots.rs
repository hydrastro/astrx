//! Cross-check: the Rust robots parser matches the Python reference
//! (`legacy-python/onioncrawler/robots.py`) — User-agent grouping (incl.
//! consecutive UA lines sharing a group and directives binding to the current
//! group), most-specific/Allow-wins matching over the ReDoS-safe glob, `$`
//! anchoring, percent-decoded paths, Crawl-delay fallback to `*`, and global
//! Sitemap directives. Expected values were emitted by driving the Python module.

use onioncrawler::robots::{parse_robots, RobotsRules};

const ROBOTS_LINES: &[&str] = &[
    "# a robots file",
    "User-agent: *",
    "Disallow: /private/",
    "Allow: /private/ok",
    "Crawl-delay: 1.5",
    "",
    "User-agent: onioncrawler",
    "User-agent: goodbot",
    "Disallow: /secret",
    "Allow: /secret/pub$",
    "Crawl-delay: 5",
    "",
    "User-agent: evil",
    "Disallow: /",
    "",
    "Sitemap: http://x.onion/sitemap.xml",
    "Sitemap: http://y.onion/sm2.xml",
    "Disallow: /*.php$",
];

#[test]
fn robots_xcheck() {
    let r = parse_robots(&ROBOTS_LINES.join("\n"));
    assert!(r.present);
    assert_eq!(
        r.sitemaps,
        vec![
            "http://x.onion/sitemap.xml".to_string(),
            "http://y.onion/sm2.xml".to_string()
        ]
    );

    // (path, agent, expected allowed)
    let allowed: &[(&str, &str, bool)] = &[
        ("/private/secret", "anybot", false),
        ("/private/ok", "anybot", true),
        ("/public", "anybot", true),
        ("/secret", "onioncrawler", false),
        ("/secret/pub", "onioncrawler", true), // $-anchored Allow, longer, wins
        ("/secret/pub/x", "onioncrawler", false), // anchor doesn't match; Disallow /secret does
        ("/anything", "evil", false),
        ("/x.php", "anybot", true), // the /*.php$ rule bound to 'evil', not '*'
        ("/x.phpx", "anybot", true),
        ("private/nolead", "anybot", false), // no leading slash → prepended
        ("/priv%61te/secret", "anybot", false), // %61 → 'a' → /private/secret
        ("/anything", "GoodBot/1.0", true),  // substring, case-insensitive match of 'goodbot'
        ("/secret", "unknownbot", true),     // falls back to '*'
    ];
    for (path, agent, want) in allowed {
        assert_eq!(
            r.allowed(path, agent),
            *want,
            "allowed({path:?}, {agent:?})"
        );
    }

    // (agent, expected crawl-delay)
    let delays: &[(&str, Option<f64>)] = &[
        ("anybot", Some(1.5)),
        ("onioncrawler", Some(5.0)),
        ("goodbot", Some(5.0)),
        ("evil", Some(1.5)), // evil has no delay → falls to '*'
        ("unknownbot", Some(1.5)),
    ];
    for (agent, want) in delays {
        assert_eq!(r.crawl_delay(agent), *want, "crawl_delay({agent:?})");
    }

    // missing robots => allow all
    let er = RobotsRules::empty();
    assert!(er.allowed("/anything", "onioncrawler"));
    assert!(!er.present);
}
