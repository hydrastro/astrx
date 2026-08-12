//! Cross-check: the Rust `suitedash::metrics` parsers reproduce the Python
//! `suitedash.probe` byte-identically — `parse_prometheus` (comments, blanks,
//! junk lines, labelled series resolving under both their full token and their
//! bare base name, `NaN`/`±Inf` rejection, a tolerated trailing timestamp,
//! CPython's `float()` spellings incl. `_` separators), `flatten_json` (one-level
//! flattening, bool/numeric-string coercion, skipped lists/nulls/deep nesting),
//! `parse_metrics` (JSON-vs-text auto-detection, both fallbacks, lossy UTF-8
//! decoding), `_num_out`'s int-vs-float split, and `_surface`'s key selection.
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `metrics`), which drives
//! the real Python module.

use suitedash::metrics::{
    flatten_json, num_out, parse_metrics, parse_prometheus, surface, MetricMap,
};

/// One parser golden: the parsed `(name, value)` pairs, in order.
type Pairs<'a> = &'a [(&'a str, f64)];
/// One `surface` golden: `(name, value-or-absent)` pairs, in order.
type OptPairs<'a> = &'a [(&'a str, Option<f64>)];

#[track_caller]
fn check(got: &MetricMap, want: &[(&str, f64)], ctx: &str) {
    let got: Vec<(&str, f64)> = got.iter().map(|(k, v)| (k, *v)).collect();
    assert_eq!(got, want.to_vec(), "{ctx}");
}

#[test]
fn parse_prometheus_matches_python() {
    let cases: &[(&str, Pairs<'_>)] = &[
        (
            r#"# HELP x_total help
# TYPE x_total counter
x_total 42
x_ratio 0.75
"#,
            &[("x_total", 42.0), ("x_ratio", 0.75)],
        ),
        (
            r#"# a comment

   
lonelytoken
# TYPE y gauge
y 3
"#,
            &[("y", 3.0)],
        ),
        (
            r#"r_total{code="200"} 40
r_total{code="500"} 2
"#,
            &[
                ("r_total", 40.0),
                ("r_total{code=\"200\"}", 40.0),
                ("r_total{code=\"500\"}", 2.0),
            ],
        ),
        (
            r#"good 1
bad NaN
inf_v +Inf
neg -Inf
"#,
            &[("good", 1.0)],
        ),
        (
            r#"z 9 1699999999000
"#,
            &[("z", 9.0)],
        ),
        (
            r#"grouped 1_000
spaced   7   
tabbed	8	
"#,
            &[("grouped", 1000.0), ("spaced", 7.0), ("tabbed", 8.0)],
        ),
        (
            r#"dup 1
dup 2
"#,
            &[("dup", 1.0)],
        ),
        (
            r#"lbl{a="1"} 5
lbl 6
"#,
            &[("lbl", 5.0), ("lbl{a=\"1\"}", 5.0)],
        ),
        (
            r#"neg_exp 1e-7
big 1e16
frac .5
"#,
            &[("neg_exp", 1e-07), ("big", 1e+16), ("frac", 0.5)],
        ),
        (r#""#, &[]),
        (
            r#"   

"#,
            &[],
        ),
        ("\u{0}\u{1} garbage {{{ \nok 1\n", &[("ok", 1.0)]),
        ("nbsp 1\nsep\u{1e}2 3\n", &[("nbsp", 1.0), ("2", 3.0)]),
        (
            r#"unicode_ws 1 2
"#,
            &[("unicode_ws", 1.0)],
        ),
        ("crlf 1\r\nvtab 2\u{b}3\n", &[("crlf", 1.0), ("vtab", 2.0)]),
    ];
    for (input, want) in cases {
        check(&parse_prometheus(input), want, &format!("{input:?}"));
    }
}

#[test]
fn flatten_json_matches_python() {
    // `None` marks a body CPython's `json.loads` rejects (the Rust parser must
    // reject it too, so `flatten_json` is never reached).
    let cases: &[(&str, Option<Pairs<'_>>)] = &[
        (
            r#"{"docs": 1000, "ok": true, "ratio": "0.5", "tags": ["a","b"], "nothing": null, "queue": {"pending": 7, "done": 300, "name": "q"}}"#,
            Some(&[
                ("docs", 1000.0),
                ("ok", 1.0),
                ("ratio", 0.5),
                ("queue_pending", 7.0),
                ("queue_done", 300.0),
            ]),
        ),
        (r#"{"a": 1, "b": 2}"#, Some(&[("a", 1.0), ("b", 2.0)])),
        (r#"[1, 2, 3]"#, Some(&[])),
        (
            r#"{"nested": {"deep": {"x": 1}}, "flat": 2}"#,
            Some(&[("flat", 2.0)]),
        ),
        (
            r#"{"neg": -3.5, "exp": 1e3, "str_bad": "abc", "bool_false": false}"#,
            Some(&[("neg", -3.5), ("exp", 1000.0), ("bool_false", 0.0)]),
        ),
        (r#"{"dup": 1, "dup": 2}"#, Some(&[("dup", 2.0)])),
        (r#"{not json"#, None),
        (
            r#"{"big": 12345678901234567890, "huge": 1e400}"#,
            Some(&[("big", 1.2345678901234567e+19)]),
        ),
        (r#"{"": 1, "a.b": 2}"#, Some(&[("", 1.0), ("a.b", 2.0)])),
    ];
    for (input, want) in cases {
        match (crawlcore::json::parse(input), want) {
            (Ok(v), Some(w)) => check(&flatten_json(&v), w, &format!("{input:?}")),
            (Err(_), None) => {}
            (Ok(_), None) => panic!("expected a parse error for {input:?}"),
            (Err(e), Some(_)) => panic!("unexpected parse error for {input:?}: {e}"),
        }
    }
}

#[test]
fn parse_metrics_matches_python() {
    let cases: &[(&[u8], &str, Pairs<'_>)] = &[
        (b"a 1\nb 2\n", r#"text/plain"#, &[("a", 1.0), ("b", 2.0)]),
        (
            b"{\"a\": 1, \"b\": 2}",
            r#"application/json"#,
            &[("a", 1.0), ("b", 2.0)],
        ),
        (b"{\"a\": 5}", r#""#, &[("a", 5.0)]),
        (b"", r#"text/plain"#, &[]),
        (b"{\"a\": 5}", r#"text/plain"#, &[("a", 5.0)]),
        (b"a 1\n", r#"application/json"#, &[("a", 1.0)]),
        (b"not json at all", r#"application/json"#, &[]),
        (
            b"\xff\xfe bad utf8 \xc3\xa9 1\nok 2\n",
            r#"text/plain"#,
            &[("ok", 2.0)],
        ),
        (b"   ", r#"text/plain"#, &[]),
    ];
    for (body, ctype, want) in cases {
        check(&parse_metrics(body, ctype), want, &format!("{ctype:?}"));
    }
}

#[test]
fn num_out_matches_python() {
    // (value, the token `json.dumps` emits for `_num_out(value)`)
    let cases: &[(Option<f64>, Option<&str>)] = &[
    (None, None),
    (Some(0.0), Some("0")),
    (Some(7.0), Some("7")),
    (Some(-7.0), Some("-7")),
    (Some(7.5), Some("7.5")),
    (Some(1204.0), Some("1204")),
    (Some(512.4), Some("512.4")),
    (Some(0.3333333333333333), Some("0.333333")),
    (Some(1e-07), Some("0.0")),
    (Some(1e+16), Some("10000000000000000")),
    (Some(1e+300), Some("1000000000000000052504760255204420248704468581108159154915854115511802457988908195786371375080447864043704443832883878176942523235360430575644792184786706982848387200926575803737830233794788090059368953234970799945081119038967640880074652742780142494579258788820056842838115669472196386865459400540160")),
    (Some(0.30000000000000004), Some("0.3")),
    (Some(-0.0), Some("0")),
    ];
    for (v, want) in cases {
        let got = num_out(*v).map(|n| n.to_json_token());
        assert_eq!(got.as_deref(), *want, "num_out({v:?})");
    }
}

#[test]
fn surface_matches_python() {
    // The Python fixture: OrderedDict b,a,c,d,e,f,g -> 2,1,3,4,5,6,7.
    let metrics: MetricMap = [
        ("b", 2.0),
        ("a", 1.0),
        ("c", 3.0),
        ("d", 4.0),
        ("e", 5.0),
        ("f", 6.0),
        ("g", 7.0),
    ]
    .iter()
    .map(|(k, v)| ((*k).to_string(), *v))
    .collect();
    let cases: &[(&[&str], OptPairs<'_>)] = &[
        (
            &[],
            &[
                ("a", Some(1.0)),
                ("b", Some(2.0)),
                ("c", Some(3.0)),
                ("d", Some(4.0)),
                ("e", Some(5.0)),
                ("f", Some(6.0)),
            ],
        ),
        (&["a", "missing"], &[("a", Some(1.0)), ("missing", None)]),
        (&["c"], &[("c", Some(3.0))]),
    ];
    for (keys, want) in cases {
        let keys: Vec<String> = keys.iter().map(|k| (*k).to_string()).collect();
        let surfaced = surface(&metrics, &keys);
        let got: Vec<(&str, Option<f64>)> = surfaced.iter().map(|(k, v)| (k, *v)).collect();
        assert_eq!(got, want.to_vec(), "surface({keys:?})");
    }
}
