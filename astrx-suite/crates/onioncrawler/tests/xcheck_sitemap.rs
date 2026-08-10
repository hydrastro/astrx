//! Cross-check: the hand-rolled Rust sitemap parser matches the Python reference
//! (`legacy-python/onioncrawler/sitemap.py`, which uses `xml.etree.ElementTree`)
//! — entity decoding (`&amp;`/numeric/`&lt;`), CDATA, the `el.text` rule (text up
//! to the first child element), namespaced + prefixed + uppercase tags,
//! unknown-root-still-collects-locs, the `max_locs` cap, and the parse-error →
//! empty-`unknown` behaviour for DOCTYPE/ENTITY, undefined entities, mismatched /
//! unclosed tags, junk outside the root, and multiple roots. Every expected value
//! was emitted by driving the Python module directly.

use onioncrawler::sitemap::{parse_sitemap, SitemapKind, DEFAULT_MAX_LOCS};

fn chk(body: &[u8], max_locs: usize, kind: SitemapKind, locs: &[&str]) {
    let d = parse_sitemap(body, max_locs);
    let want: Vec<String> = locs.iter().map(|s| (*s).to_string()).collect();
    assert_eq!(d.kind, kind, "kind for {:?}", String::from_utf8_lossy(body));
    assert_eq!(d.locs, want, "locs for {:?}", String::from_utf8_lossy(body));
}

const NS: &[u8] = b"<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"><url><loc>http://a.onion/</loc></url></urlset>";

#[test]
fn sitemap_valid_xcheck() {
    use SitemapKind::{Sitemapindex, Unknown, Urlset};
    let m = DEFAULT_MAX_LOCS;

    chk(
        b"<urlset><url><loc>http://a.onion/</loc></url><url><loc>http://b.onion/x</loc></url></urlset>",
        m, Urlset, &["http://a.onion/", "http://b.onion/x"],
    );
    chk(NS, m, Urlset, &["http://a.onion/"]);
    chk(
        b"<sitemapindex><sitemap><loc>http://a.onion/sm1.xml</loc></sitemap></sitemapindex>",
        m,
        Sitemapindex,
        &["http://a.onion/sm1.xml"],
    );
    chk(
        b"<sm:urlset xmlns:sm=\"http://www.sitemaps.org/schemas/sitemap/0.9\"><sm:url><sm:loc>http://a.onion/p</sm:loc></sm:url></sm:urlset>",
        m, Urlset, &["http://a.onion/p"],
    );
    // entity decoding
    chk(
        b"<urlset><url><loc>http://a.onion/?x=1&amp;y=2</loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/?x=1&y=2"],
    );
    chk(
        b"<urlset><url><loc>http://a.onion/?a=1&#38;b=2&#x26;c=3</loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/?a=1&b=2&c=3"],
    );
    chk(
        b"<urlset><url><loc>http://a.onion/&lt;p&gt;</loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/<p>"],
    );
    chk(
        b"<urlset><url><loc><![CDATA[http://a.onion/a&b<c]]></loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/a&b<c"],
    );
    // whitespace / empty / self-closing / nested child (el.text)
    chk(
        b"<urlset><url><loc>  http://a.onion/ws  </loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/ws"],
    );
    chk(
        b"<urlset><url><loc></loc></url><url><loc>http://a.onion/ok</loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/ok"],
    );
    chk(
        b"<urlset><url><loc/></url><url><loc>http://a.onion/ok</loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/ok"],
    );
    chk(
        b"<urlset><url><loc>before<extra/>after</loc></url></urlset>",
        m,
        Urlset,
        &["before"],
    );
    // comment / PI / xml decl ignored
    chk(b"<?xml version=\"1.0\"?><!-- c --><urlset><?pi data?><url><loc>http://a.onion/c</loc></url></urlset>", m, Urlset, &["http://a.onion/c"]);
    // unknown root still collects locs
    chk(
        b"<html><body><loc>http://a.onion/nope-still-collected</loc></body></html>",
        m,
        Unknown,
        &["http://a.onion/nope-still-collected"],
    );
    chk(
        b"<urlset><url><loc rel=\"x\">http://a.onion/attr</loc></url></urlset>",
        m,
        Urlset,
        &["http://a.onion/attr"],
    );
    chk(
        b"<URLSET><URL><LOC>http://a.onion/up</LOC></URL></URLSET>",
        m,
        Urlset,
        &["http://a.onion/up"],
    );
}

#[test]
fn sitemap_rejected_xcheck() {
    use SitemapKind::Unknown;
    let m = DEFAULT_MAX_LOCS;
    for body in [
        b"".as_slice(),
        b"<!DOCTYPE urlset><urlset><url><loc>http://a.onion/</loc></url></urlset>",
        b"<!ENTITY x \"y\"><urlset><url><loc>http://a.onion/</loc></url></urlset>",
        b"<urlset><url><loc>http://a.onion/&foo;</loc></url></urlset>",
        b"<urlset><url><loc>http://a.onion/</wrong></url></urlset>",
        b"<urlset><url><loc>http://a.onion/",
        b"garbage<urlset><url><loc>http://a.onion/</loc></url></urlset>",
        b"<urlset></urlset><urlset></urlset>",
        b"just plain text, no tags",
    ] {
        chk(body, m, Unknown, &[]);
    }
}

#[test]
fn sitemap_max_locs_cap() {
    let mut body = b"<urlset>".to_vec();
    for i in 0..10 {
        body.extend_from_slice(format!("<url><loc>http://a.onion/{i}</loc></url>").as_bytes());
    }
    body.extend_from_slice(b"</urlset>");
    chk(
        &body,
        3,
        SitemapKind::Urlset,
        &["http://a.onion/0", "http://a.onion/1", "http://a.onion/2"],
    );
}
