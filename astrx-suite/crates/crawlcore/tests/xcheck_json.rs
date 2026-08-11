//! Cross-check: `crawlcore::json::parse` reproduces Python `json.loads` on the
//! structure the crawlers' structured-data recovery walks — the ordered list of
//! string leaves and of object keys (which exercises string escaping/unicode,
//! object key order, duplicate-key-keeps-last, `@graph`/array traversal, and
//! numbers-are-not-strings), sidestepping int/float formatting differences.
//! Goldens emitted by driving CPython `json.loads` (see `tests/regen_goldens.py`).

use crawlcore::json::{parse, Value};

fn collect_strings(v: &Value, out: &mut Vec<String>) {
    match v {
        Value::Str(s) => out.push(s.clone()),
        Value::Array(a) => a.iter().for_each(|x| collect_strings(x, out)),
        Value::Object(pairs) => pairs.iter().for_each(|(_, val)| collect_strings(val, out)),
        _ => {}
    }
}

fn collect_keys(v: &Value, out: &mut Vec<String>) {
    match v {
        Value::Array(a) => a.iter().for_each(|x| collect_keys(x, out)),
        Value::Object(pairs) => pairs.iter().for_each(|(k, val)| {
            out.push(k.clone());
            collect_keys(val, out);
        }),
        _ => {}
    }
}

fn strs(v: &Value) -> Vec<String> {
    let mut o = Vec::new();
    collect_strings(v, &mut o);
    o
}
fn keys(v: &Value) -> Vec<String> {
    let mut o = Vec::new();
    collect_keys(v, &mut o);
    o
}

#[test]
fn json_traversal_matches_python() {
    // (input, string-leaves, keys) — goldens from CPython json.loads.
    struct Case {
        input: &'static str,
        strs: &'static [&'static str],
        keys: &'static [&'static str],
    }
    let cases = [
        Case {
            input: r#"{"@type": "VideoObject", "name": "Cats", "desc": "funny", "tags": ["a", "b"]}"#,
            strs: &["VideoObject", "Cats", "funny", "a", "b"],
            keys: &["@type", "name", "desc", "tags"],
        },
        Case {
            input: r#"{"a": {"b": {"c": "deep"}}, "list": [1, "two", true, null, {"k": "v"}]}"#,
            strs: &["deep", "two", "v"],
            keys: &["a", "b", "c", "list", "k"],
        },
        Case {
            input: "[\"caf\\u00e9\", \"emoji \\ud83d\\ude00\", \"tab\\tend\"]",
            strs: &["café", "emoji 😀", "tab\tend"],
            keys: &[],
        },
        Case {
            input: r#"{"dup": "first", "dup": "last", "n": 42, "f": 3.14}"#,
            strs: &["last"],
            keys: &["dup", "n", "f"],
        },
        Case {
            input: r#"{"@graph": [{"headline": "H1"}, {"headline": "H2"}]}"#,
            strs: &["H1", "H2"],
            keys: &["@graph", "headline", "headline"],
        },
    ];
    for c in &cases {
        let v = parse(c.input).unwrap_or_else(|e| panic!("parse {:?}: {e}", c.input));
        assert_eq!(strs(&v), c.strs, "string leaves for {:?}", c.input);
        assert_eq!(keys(&v), c.keys, "keys for {:?}", c.input);
    }
}
