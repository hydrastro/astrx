//! Atom 1.0 rendering — any search is a feed you can subscribe to.
//!
//! `/search?q=…&format=atom` returns the current page of results as a valid
//! [RFC 4287] feed: no JavaScript, no accounts, no saved-search table. The
//! subscription IS the URL, so "watch this query" costs the server nothing
//! beyond the search it would have run anyway, and a reader can follow a query
//! the way it follows a blog.
//!
//! # Why this is a separate module
//!
//! HTML and XML have different escaping rules, and a renderer that serves both
//! from one escaper eventually serves one with the other's. The two never share
//! code here: [`xml_text`] is the only escaper in this file, and
//! [`crate::serve::esc`] never enters it. In particular XML forbids most C0
//! control characters *entirely* — they cannot be escaped, only removed — while
//! HTML happily carries them, so a crawled page holding a stray `0x03` produces
//! a well-formed page and would have produced a feed no reader can parse.
//!
//! [RFC 4287]: https://www.rfc-editor.org/rfc/rfc4287

use crate::ranking::SearchResult;

/// Escape text for an XML character-data or attribute-value context.
///
/// Beyond the five predefined entities, this DROPS the characters XML 1.0 §2.2
/// forbids outright: C0 controls other than tab / LF / CR, the surrogate range
/// (unreachable in a Rust `str`), and the two non-characters `U+FFFE`/`U+FFFF`.
/// There is no escape for them — `&#x3;` is as illegal as a raw `0x03` — so a
/// feed containing one is not "slightly wrong", it is rejected by every
/// conforming parser. Crawled titles do contain them.
#[must_use]
pub fn xml_text(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&apos;"),
            '\t' | '\n' | '\r' => out.push(c),
            c if (c as u32) < 0x20 => {}
            c if (c as u32) == 0xFFFE || (c as u32) == 0xFFFF => {}
            c => out.push(c),
        }
    }
    out
}

/// Format epoch seconds as the RFC 3339 UTC timestamp Atom requires
/// (`2024-01-31T12:00:00Z`). Non-positive input is the epoch, so `<updated>` is
/// always present and always parseable — Atom makes it mandatory.
#[must_use]
pub fn rfc3339(ts: f64) -> String {
    let secs = if ts.is_finite() && ts > 0.0 {
        ts as i64
    } else {
        0
    };
    let days = secs.div_euclid(86_400);
    let rem = secs.rem_euclid(86_400);
    let (y, m, d) = civil_from_days(days);
    let (hh, mm, ss) = (rem / 3600, (rem % 3600) / 60, rem % 60);
    format!("{y:04}-{m:02}-{d:02}T{hh:02}:{mm:02}:{ss:02}Z")
}

/// A day count since the epoch to `(y, m, d)` (Hinnant's civil_from_days).
fn civil_from_days(z: i64) -> (i64, i64, i64) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { y + 1 } else { y }, m, d)
}

/// Everything the feed renderer needs that is not a result row. Passed in rather
/// than read, so [`render`] stays pure and testable without a clock or a socket.
pub struct FeedMeta<'a> {
    /// Site name, for `<title>` and `<author>`.
    pub site_name: &'a str,
    /// Absolute base URL of this server (no trailing slash), for the links.
    pub base_url: &'a str,
    /// The query this feed is of.
    pub query: &'a str,
    /// Absolute URL of this feed (`rel="self"`).
    pub self_url: &'a str,
    /// Absolute URL of the HTML page for the same query (`rel="alternate"`).
    pub html_url: &'a str,
    /// Fallback `<updated>` (epoch seconds) when no result carries a date.
    pub now: f64,
}

/// Render one page of `results` as an Atom 1.0 feed.
///
/// * `<id>`s are STABLE: the feed's is its own canonical URL and an entry's is
///   the document URL, so a reader that has seen an entry does not re-show it
///   when the ranking shifts and it lands on a different page.
/// * `<updated>` is the newest `fetched_at` among the results, i.e. the moment
///   the feed's content last actually changed — a reader polling an unchanged
///   query sees an unchanged timestamp.
/// * The snippet is carried as `type="html"` escaped content, so its `<mark>`
///   highlighting survives in readers that render HTML and degrades to visible
///   tags nowhere.
#[must_use]
pub fn render(meta: &FeedMeta, results: &[SearchResult]) -> String {
    let newest = results.iter().map(|r| r.fetched_at).fold(0.0_f64, f64::max);
    let updated = if newest > 0.0 { newest } else { meta.now };

    let mut s = String::with_capacity(1024 + results.len() * 512);
    s.push_str("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n");
    s.push_str("<feed xmlns=\"http://www.w3.org/2005/Atom\">\n");
    s.push_str(&format!(
        "  <title>{} — {}</title>\n",
        xml_text(meta.site_name),
        xml_text(meta.query)
    ));
    s.push_str(&format!("  <id>{}</id>\n", xml_text(meta.self_url)));
    s.push_str(&format!(
        "  <updated>{}</updated>\n",
        xml_text(&rfc3339(updated))
    ));
    s.push_str(&format!(
        "  <link rel=\"self\" type=\"application/atom+xml\" href=\"{}\"/>\n",
        xml_text(meta.self_url)
    ));
    s.push_str(&format!(
        "  <link rel=\"alternate\" type=\"text/html\" href=\"{}\"/>\n",
        xml_text(meta.html_url)
    ));
    s.push_str(&format!(
        "  <subtitle>Saved search for {} on {}</subtitle>\n",
        xml_text(meta.query),
        xml_text(meta.base_url)
    ));
    s.push_str(&format!(
        "  <author><name>{}</name></author>\n",
        xml_text(meta.site_name)
    ));
    s.push_str("  <generator>astrx-websearch</generator>\n");

    for r in results {
        let title = if r.title.is_empty() { &r.url } else { &r.title };
        s.push_str("  <entry>\n");
        s.push_str(&format!("    <title>{}</title>\n", xml_text(title)));
        // The document URL is the entry's identity: stable across pages, across
        // re-ranking, and across restarts of this process.
        s.push_str(&format!("    <id>{}</id>\n", xml_text(&r.url)));
        s.push_str(&format!(
            "    <link rel=\"alternate\" type=\"text/html\" href=\"{}\"/>\n",
            xml_text(&r.url)
        ));
        s.push_str(&format!(
            "    <updated>{}</updated>\n",
            xml_text(&rfc3339(r.fetched_at))
        ));
        if !r.host.is_empty() {
            s.push_str(&format!(
                "    <author><name>{}</name></author>\n",
                xml_text(&r.host)
            ));
        }
        if !r.snippet.is_empty() {
            s.push_str(&format!(
                "    <summary type=\"html\">{}</summary>\n",
                xml_text(&r.snippet)
            ));
        }
        s.push_str("  </entry>\n");
    }

    s.push_str("</feed>\n");
    s
}

#[cfg(test)]
mod tests {
    use super::*;

    fn result(url: &str, title: &str, fetched_at: f64) -> SearchResult {
        SearchResult {
            url: url.to_string(),
            title: title.to_string(),
            description: String::new(),
            snippet: "a <mark>hit</mark> & more".to_string(),
            host: "ex.com".to_string(),
            fetched_at,
            score: 1.0,
            lang: "en".to_string(),
            simhash: 0,
        }
    }

    fn meta<'a>(q: &'a str, self_url: &'a str, html_url: &'a str) -> FeedMeta<'a> {
        FeedMeta {
            site_name: "astrx search",
            base_url: "http://s.example",
            query: q,
            self_url,
            html_url,
            now: 1_700_000_000.0,
        }
    }

    #[test]
    fn timestamps_are_rfc3339_utc() {
        assert_eq!(rfc3339(0.0), "1970-01-01T00:00:00Z");
        assert_eq!(rfc3339(1_700_000_000.0), "2023-11-14T22:13:20Z");
        assert_eq!(rfc3339(1_577_836_800.0), "2020-01-01T00:00:00Z");
        // Nonsense in, still a parseable timestamp out — Atom requires one.
        assert_eq!(rfc3339(-5.0), "1970-01-01T00:00:00Z");
        assert_eq!(rfc3339(f64::NAN), "1970-01-01T00:00:00Z");
    }

    /// The five predefined entities, plus the control characters XML forbids
    /// outright. A crawled title with a `0x03` in it must not reach a reader.
    #[test]
    fn escaping_covers_entities_and_illegal_controls() {
        assert_eq!(
            xml_text("a<b>&\"c\"'d'"),
            "a&lt;b&gt;&amp;&quot;c&quot;&apos;d&apos;"
        );
        assert_eq!(xml_text("a\u{3}b\u{1f}c"), "abc");
        assert_eq!(xml_text("keep\tthese\r\nones"), "keep\tthese\r\nones");
        assert_eq!(xml_text("no\u{fffe}\u{ffff}chars"), "nochars");
        assert_eq!(xml_text("héllo ☃"), "héllo ☃");
    }

    #[test]
    fn a_feed_has_stable_ids_and_the_newest_updated() {
        let rows = vec![
            result("http://ex.com/a", "A", 1_600_000_000.0),
            result("http://ex.com/b", "B", 1_650_000_000.0),
        ];
        let m = meta(
            "rust",
            "http://s.example/search?q=rust&format=atom",
            "http://s.example/search?q=rust",
        );
        let feed = render(&m, &rows);

        assert!(feed.starts_with("<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<feed"));
        assert!(feed.contains("<id>http://s.example/search?q=rust&amp;format=atom</id>"));
        // The newest document's date, not the wall clock.
        assert!(feed.contains("<updated>2022-04-15T05:20:00Z</updated>"));
        assert!(feed.contains("<id>http://ex.com/a</id>"));
        assert!(feed.contains("<id>http://ex.com/b</id>"));
        assert_eq!(feed.matches("<entry>").count(), 2);
        // Rendering twice yields the same bytes: nothing here reads a clock.
        assert_eq!(feed, render(&m, &rows));
    }

    /// An empty result set is still a valid feed — a reader subscribing to a
    /// query with no hits yet must not get a parse error.
    #[test]
    fn an_empty_feed_is_still_valid() {
        let m = meta(
            "nothing",
            "http://s.example/search?q=nothing&format=atom",
            "http://s.example/search?q=nothing",
        );
        let feed = render(&m, &[]);
        assert!(feed.contains("<updated>2023-11-14T22:13:20Z</updated>"));
        assert!(!feed.contains("<entry>"));
        assert!(feed.ends_with("</feed>\n"));
    }

    /// Hostile text in a title, a query and a URL cannot break out of the
    /// document — the whole point of keeping this escaper away from the HTML one.
    #[test]
    fn hostile_text_cannot_break_the_document() {
        let rows = vec![result(
            "http://ex.com/?a=1&b=2",
            "</title><script>alert(1)</script>",
            1_600_000_000.0,
        )];
        let m = meta(
            "</feed>& \u{2}bad",
            "http://s.example/search?q=%3C&format=atom",
            "http://s.example/search?q=%3C",
        );
        let feed = render(&m, &rows);
        assert!(!feed.contains("<script>"));
        assert!(!feed.contains("</title><"));
        assert!(!feed.contains('\u{2}'));
        assert!(feed.contains("http://ex.com/?a=1&amp;b=2"));
        // Exactly one opening and one closing feed element survive.
        assert_eq!(feed.matches("<feed ").count(), 1);
        assert_eq!(feed.matches("</feed>").count(), 1);
    }
}
