//! Cross-check: the Rust `extract_html` reproduces the Python
//! `onioncrawler.extract.extract_html` (stdlib `html.parser`) on a representative
//! corpus — title, cleaned text, followable links, and the robots-meta / base
//! outputs match exactly. Goldens were emitted by driving the Python module;
//! regenerate with `tests/regen_goldens.py`.
//!
//! (Named-entity decoding covers the common HTML set + all numeric refs; the full
//! ~2000-entry HTML5 named table is deliberately not reproduced, so the module is
//! behaviourally faithful, not bit-identical on exotic named entities.)

use onioncrawler::extract::extract_html;

struct Case {
    html: &'static [u8],
    title: &'static str,
    text: &'static str,
    links: &'static [&'static str],
    noindex: bool,
    nofollow: bool,
    base: Option<&'static str>,
}

#[test]
fn extract_html_matches_python() {
    let cases = [
        Case {
            html: b"<html><head><title>Hello &amp; Welcome</title></head><body><p>First para.</p><p>Second <a href='/x'>link</a> here.</p><script>var a=1;</script><a href='http://y.onion/z' rel='nofollow'>skip</a></body></html>",
            title: "Hello & Welcome",
            text: "First para.\nSecond link here.\nskip",
            links: &["/x"],
            noindex: false,
            nofollow: false,
            base: None,
        },
        Case {
            html: b"<html><head><meta name='robots' content='noindex, nofollow'><title>T</title></head><body>hi</body></html>",
            title: "T",
            text: "hi",
            links: &[],
            noindex: true,
            nofollow: true,
            base: None,
        },
        Case {
            html: b"<html><head><base href='http://b.onion/'><title>A&#233;B</title></head><body><div>x</div><div>y&nbsp;z</div></body></html>",
            title: "A\u{e9}B",
            text: "x\ny\u{a0}z",
            links: &[],
            noindex: false,
            nofollow: false,
            base: Some("http://b.onion/"),
        },
        Case {
            html: b"<body><style>.a{}</style><p>keep <b>this</b> text</p><noscript>drop</noscript></body>",
            title: "",
            text: "keep this text",
            links: &[],
            noindex: false,
            nofollow: false,
            base: None,
        },
        Case {
            html: b"<body><p>a<br/>b</p><a href='/l'/>after</body>",
            title: "",
            text: "a\nb\nafter",
            links: &["/l"],
            noindex: false,
            nofollow: false,
            base: None,
        },
    ];

    for (i, c) in cases.iter().enumerate() {
        let e = extract_html(c.html, None, None);
        assert_eq!(e.title, c.title, "title (case {i})");
        assert_eq!(e.text, c.text, "text (case {i})");
        assert_eq!(e.links, c.links, "links (case {i})");
        assert_eq!(e.meta_noindex, c.noindex, "noindex (case {i})");
        assert_eq!(e.meta_nofollow, c.nofollow, "nofollow (case {i})");
        assert_eq!(e.base_href.as_deref(), c.base, "base (case {i})");
    }
}
