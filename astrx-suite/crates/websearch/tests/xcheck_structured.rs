//! Cross-check: the Rust `structured` helpers reproduce the Python
//! `websearch.htmlparse` stage-2 building blocks byte-identically —
//! `parse_duration` (ISO-8601 → seconds, banker's rounding, malformed-fraction
//! rejection), `_classify_player` (known-player iframe → watch URL),
//! `_is_direct_media`, and the JSON-LD / inline-state walkers (`_first_str`,
//! `_first_url`, `_type_of`, `_iter_json_dicts`, `_collect_readable`,
//! `_balanced_json`, `_extract_state_json`). Every golden below was emitted by
//! driving the real Python module; regenerate with `tests/regen_goldens.py`
//! (section `gen_structured`).

use crawlcore::json::parse as jparse;
use websearch::structured::{
    balanced_json, classify_player, collect_readable, extract_state_json, first_str, first_url,
    is_direct_media, iter_dicts, parse_duration, type_of,
};

/// `(iframe src, (expected player, expected watch URL))`.
type PlayerCase = (&'static str, (Option<&'static str>, Option<&'static str>));

#[test]
fn parse_duration_matches_python() {
    let cases: &[(&str, Option<i64>)] = &[
        ("PT1H2M3S", Some(3723)),
        ("PT1M30S", Some(90)),
        ("P1DT2H", Some(93600)),
        ("PT0S", Some(0)),
        ("P1W", Some(604800)),
        ("P2DT3H4M5S", Some(183845)),
        ("PT1.5S", Some(2)),
        ("PT0.5S", Some(0)),
        ("PT2.5S", Some(2)),
        ("PT1.4S", Some(1)),
        ("PT1.6S", Some(2)),
        ("pt1h", Some(3600)),
        ("PT1.S", None),
        ("PT.5S", None),
        ("PT1.2.3S", None),
        ("P", None),
        ("PT", None),
        ("", None),
        ("garbage", None),
        ("P1Y", None),
        ("  PT1H  ", Some(3600)),
        ("P1WT1H", Some(608400)),
        ("P1D", Some(86400)),
        ("PT10M", Some(600)),
        ("PT1H0M0S", Some(3600)),
        ("P0W", Some(0)),
        ("PT90M", Some(5400)),
    ];
    for (inp, exp) in cases {
        assert_eq!(parse_duration(inp), *exp, "parse_duration({inp:?})");
    }
}

#[test]
fn classify_player_matches_python() {
    let cases: &[PlayerCase] = &[
        (
            "https://www.youtube.com/embed/dQw4w9WgXcQ",
            (
                Some("youtube"),
                Some("https://www.youtube.com/watch?v=dQw4w9WgXcQ"),
            ),
        ),
        (
            "https://www.youtube.com/embed/short",
            (Some("youtube"), None),
        ),
        (
            "https://www.youtube-nocookie.com/embed/abcdef1234",
            (
                Some("youtube"),
                Some("https://www.youtube.com/watch?v=abcdef1234"),
            ),
        ),
        (
            "https://youtu.be/dQw4w9WgXcQ",
            (
                Some("youtube"),
                Some("https://www.youtube.com/watch?v=dQw4w9WgXcQ"),
            ),
        ),
        ("https://youtu.be/", (Some("youtube"), None)),
        (
            "https://player.vimeo.com/video/12345",
            (Some("vimeo"), Some("https://vimeo.com/12345")),
        ),
        ("https://player.vimeo.com/video/abc", (Some("vimeo"), None)),
        (
            "https://www.dailymotion.com/embed/video/x7tgad0",
            (
                Some("dailymotion"),
                Some("https://www.dailymotion.com/video/x7tgad0"),
            ),
        ),
        (
            "https://www.dailymotion.com/video/x7tgad0",
            (
                Some("dailymotion"),
                Some("https://www.dailymotion.com/video/x7tgad0"),
            ),
        ),
        (
            "https://dai.ly/x7tgad0",
            (
                Some("dailymotion"),
                Some("https://www.dailymotion.com/video/x7tgad0"),
            ),
        ),
        (
            "https://peertube.example.org/videos/embed/abc-123",
            (
                Some("peertube"),
                Some("https://peertube.example.org/videos/watch/abc-123"),
            ),
        ),
        ("https://odysee.com/@x:1/y:2", (Some("odysee"), None)),
        ("https://rumble.com/embed/v123", (Some("rumble"), None)),
        ("https://example.com/x", (None, None)),
        ("https://vimeo.com/12345", (None, None)),
        (
            "//youtube.com/embed/abcdef",
            (
                Some("youtube"),
                Some("https://www.youtube.com/watch?v=abcdef"),
            ),
        ),
        (
            "https://WWW.YOUTUBE.COM/embed/UPPER123",
            (
                Some("youtube"),
                Some("https://www.youtube.com/watch?v=UPPER123"),
            ),
        ),
    ];
    for (src, (ep, ew)) in cases {
        let (p, w) = classify_player(src);
        assert_eq!(p.as_deref(), *ep, "classify_player({src:?}).0");
        assert_eq!(w.as_deref(), *ew, "classify_player({src:?}).1");
    }
}

#[test]
fn is_direct_media_matches_python() {
    let cases: &[(&str, bool)] = &[
        ("http://a/clip.mp4", true),
        ("http://a/clip.MP4", true),
        ("http://a/v.webm", true),
        ("http://a/v.m3u8", true),
        ("http://a/v.mpd", true),
        ("http://a/v.ogv", true),
        ("http://a/v.mov", true),
        ("http://a/page.html", false),
        ("http://a/noext", false),
        ("http://a/clip.mp4?x=1", true),
    ];
    for (inp, exp) in cases {
        assert_eq!(is_direct_media(inp), *exp, "is_direct_media({inp:?})");
    }
}

#[test]
fn first_str_matches_python() {
    let cases: &[(&str, &str)] = &[
        ("\"hello\"", "hello"),
        ("\"  hi  \"", "hi"),
        ("[\"\", \"  x \", \"y\"]", "x"),
        ("[1, 2, \"z\"]", "z"),
        ("42", ""),
        ("{\"a\":1}", ""),
        ("[]", ""),
        ("[\"   \", \"\"]", ""),
    ];
    for (j, exp) in cases {
        let v = jparse(j).unwrap();
        assert_eq!(first_str(&v), *exp, "first_str({j:?})");
    }
}

#[test]
fn first_url_matches_python() {
    let cases: &[(&str, &str)] = &[
        ("\"http://x\"", "http://x"),
        ("{\"url\": \"http://u\"}", "http://u"),
        ("{\"@id\": \"http://id\"}", "http://id"),
        ("{\"contentUrl\": \"http://c\"}", "http://c"),
        ("{\"url\": \"\", \"@id\": \"http://id\"}", "http://id"),
        (
            "{\"url\": \"http://u\", \"@id\": \"http://id\"}",
            "http://u",
        ),
        ("[\"http://a\", \"http://b\"]", "http://a"),
        ("[{\"url\":\"http://x\"}]", "http://x"),
        ("{}", ""),
        ("{\"url\": [\"http://l1\", \"http://l2\"]}", "http://l1"),
        ("42", ""),
    ];
    for (j, exp) in cases {
        let v = jparse(j).unwrap();
        assert_eq!(first_url(&v), *exp, "first_url({j:?})");
    }
}

#[test]
fn type_of_matches_python() {
    let cases: &[(&str, &[&str])] = &[
        ("{\"@type\": \"VideoObject\"}", &["videoobject"]),
        (
            "{\"@type\": [\"Article\", \"NewsArticle\"]}",
            &["article", "newsarticle"],
        ),
        (
            "{\"@type\": [\"Thing\", 42, \"Other\"]}",
            &["thing", "other"],
        ),
        ("{\"no_type\": 1}", &[]),
        ("{\"@type\": 42}", &[]),
    ];
    for (j, exp) in cases {
        let v = jparse(j).unwrap();
        let got = type_of(&v);
        let want: Vec<String> = exp.iter().map(|s| (*s).to_string()).collect();
        assert_eq!(got, want, "type_of({j:?})");
    }
}

#[test]
fn iter_dicts_matches_python() {
    // Each dict node is projected through `type_of` (order + count preserved).
    let cases: &[(&str, &[&[&str]])] = &[
        ("{\"@type\":\"A\"}", &[&["a"]]),
        (
            "{\"@graph\":[{\"@type\":\"B\"},{\"@type\":\"C\"}]}",
            &[&[], &["c"], &["b"]],
        ),
        ("[{\"@type\":\"X\"}, {\"@type\":\"Y\"}]", &[&["y"], &["x"]]),
        (
            "{\"@type\":\"Root\", \"nested\":{\"@type\":\"Deep\"}}",
            &[&["root"]],
        ),
        (
            "{\"@type\":\"R\", \"@graph\":[{\"@type\":\"G1\"}, {\"nested\":{\"@type\":\"NG\"}}]}",
            &[&["r"], &[], &["g1"]],
        ),
    ];
    for (j, exp) in cases {
        let v = jparse(j).unwrap();
        let got: Vec<Vec<String>> = iter_dicts(&v).iter().map(|d| type_of(d)).collect();
        let want: Vec<Vec<String>> = exp
            .iter()
            .map(|row| row.iter().map(|s| (*s).to_string()).collect())
            .collect();
        assert_eq!(got, want, "iter_dicts({j:?})");
    }
}

#[test]
fn collect_readable_matches_python() {
    let cases: &[(&str, &[&str])] = &[
        (
            "{\"name\":\"Cats\", \"nested\":{\"description\":\"d\"}, \"url\":\"u\"}",
            &["Cats", "d"],
        ),
        (
            "{\"title\":\"T\", \"headline\":\"H\", \"body\":\"B\"}",
            &["T", "H", "B"],
        ),
        (
            "{\"Title\":\"Cap\", \"DESCRIPTION\":\"UP\"}",
            &["Cap", "UP"],
        ),
        ("{\"name\":\"  spaced  \"}", &["spaced"]),
        ("{\"name\":\"\"}", &[]),
        ("{\"name\":\"   \"}", &[]),
        ("{\"other\":\"x\"}", &[]),
        (
            "{\"items\":[{\"name\":\"A\"},{\"name\":\"B\"}]}",
            &["B", "A"],
        ),
    ];
    for (j, exp) in cases {
        let v = jparse(j).unwrap();
        let got = collect_readable(&v);
        let want: Vec<String> = exp.iter().map(|s| (*s).to_string()).collect();
        assert_eq!(got, want, "collect_readable({j:?})");
    }
}

#[test]
fn balanced_json_matches_python() {
    let cases: &[(&str, usize, Option<&str>)] = &[
        ("{\"a\":1}", 0, Some("{\"a\":1}")),
        ("prefix {\"a\":1} suffix", 7, Some("{\"a\":1}")),
        ("xx {\"a\": {\"b\":2}} yy", 3, Some("{\"a\": {\"b\":2}}")),
        ("[1, 2, [3]]", 0, Some("[1, 2, [3]]")),
        ("no opener here", 0, None),
        ("{\"s\": \"has } brace\"}", 0, Some("{\"s\": \"has } brace\"}")),
        ("  {unclosed", 0, None),
        ("{\"e\": \"esc \\\" quote\"}", 0, Some("{\"e\": \"esc \\\" quote\"}")),
        ("     {\"a\":1}", 0, Some("{\"a\":1}")),
        (
            "                                                                                                                                                      {}",
            0,
            None,
        ),
    ];
    for (text, start, exp) in cases {
        assert_eq!(
            balanced_json(text, *start).as_deref(),
            *exp,
            "balanced_json({text:?}, {start})"
        );
    }
}

#[test]
fn extract_state_json_matches_python() {
    let cases: &[(&str, Option<&str>)] = &[
        (
            "var x=1; window.__NUXT__ = {\"a\":{\"title\":\"Hi\"}}; more();",
            Some("{\"a\":{\"title\":\"Hi\"}}"),
        ),
        ("window.__INITIAL_STATE__={\"k\":1}", Some("{\"k\":1}")),
        (
            "__APOLLO_STATE__ = {\"x\":[1,2,3]}",
            Some("{\"x\":[1,2,3]}"),
        ),
        ("__PRELOADED_STATE__ : {\"y\":true}", Some("{\"y\":true}")),
        ("no marker here {\"a\":1}", None),
        ("__NUXT__ = not json", None),
        ("__NUXT__   =   {\"a\":1}", Some("{\"a\":1}")),
        (
            "a __NUXT__={\"n\":1} b __INITIAL_STATE__={\"i\":2}",
            Some("{\"i\":2}"),
        ),
    ];
    for (text, exp) in cases {
        assert_eq!(
            extract_state_json(text).as_deref(),
            *exp,
            "extract_state_json({text:?})"
        );
    }
}
