//! Aggregate `/metrics` exporter: federate every polled service into one
//! Prometheus text exposition, plus suitedash's own gauges — a port of the
//! Python `suitedash.exporter`.
//!
//! Prometheus can scrape suitedash alone and get the whole suite: each upstream
//! series is re-emitted with a `service="<name>"` label added. Upstream bodies
//! are parsed *defensively* — a hostile or garbled `/metrics` must never break
//! the exporter or emit invalid text:
//!
//! * Only lines matching a strict `name{labels} value [ts]` grammar are
//!   re-emitted; anything else (comments, HELP/TYPE, junk, unparseable label
//!   blocks, non-numeric values) is skipped. Upstream HELP/TYPE are dropped so
//!   two services exposing the same metric name cannot produce a duplicate-TYPE
//!   error; the federated samples are untyped and grouped by name for a clean
//!   exposition.
//! * JSON `/metrics` bodies are flattened one level and emitted as
//!   `key{service="…"} value`, with keys sanitised to valid metric names.
//! * The added `service` label value is escaped per the Prometheus text format
//!   (`\\`, `\"`, newline), so a hostile service name cannot break out of the
//!   label, and an upstream value is decoded and *re-encoded* so an invalid
//!   escape can never be smuggled through.
//! * Names in suitedash's own `suitedash_` namespace are never federated, so an
//!   upstream cannot forge our heartbeat or duplicate an authoritative gauge.
//!
//! Everything is bounded: upstream bodies are capped at fetch time and at most
//! [`MAX_FEDERATE_LINES`] series per service are federated. This module is pure
//! — a function of the poll results — and holds no state. Cross-checked
//! byte-identical to Python by `tests/xcheck_exporter.rs`.

use crate::metrics::{flatten_json, OrderedMap, Results, ServiceResult};
use crate::pycompat;

/// The exposition's content type.
pub const CONTENT_TYPE: &str = "text/plain; version=0.0.4; charset=utf-8";

/// Per-service cap on federated series (upstream bodies are also byte-capped).
pub const MAX_FEDERATE_LINES: usize = 5000;

/// suitedash owns this metric-name prefix. A federated upstream series must
/// never be emitted into it, or a hostile service could forge our heartbeat
/// (`suitedash_up`) or duplicate an authoritative per-service gauge.
const RESERVED_PREFIX: &str = "suitedash_";

fn is_reserved(name: &str) -> bool {
    name.starts_with(RESERVED_PREFIX)
}

/// Escape a string for a Prometheus label value (`\\`, `\"`, newline; CR
/// dropped).
#[must_use]
pub fn escape_label_value(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '\\' => out.push_str("\\\\"),
            '"' => out.push_str("\\\""),
            '\n' => out.push_str("\\n"),
            '\r' => {}
            c => out.push(c),
        }
    }
    out
}

/// Decode an upstream label value to its logical string.
///
/// The Prometheus text format defines exactly three escapes — `\\`, `\"` and
/// `\n`. The tolerant sample grammar also lets an *invalid* escape such as `\t`
/// through; here a backslash that does not introduce a valid escape is decoded
/// as a literal backslash. Feeding the result back through
/// [`escape_label_value`] therefore always yields a well-formed value, so a
/// hostile upstream can never smuggle an invalid escape (or a stray
/// quote/backslash) into the federated exposition.
#[must_use]
pub fn unescape_label_value(s: &str) -> String {
    if !s.contains('\\') {
        return s.to_string();
    }
    let chars: Vec<char> = s.chars().collect();
    let mut out = String::with_capacity(s.len());
    let mut i = 0usize;
    while i < chars.len() {
        let c = chars[i];
        if c == '\\' && i + 1 < chars.len() {
            match chars[i + 1] {
                '\\' => {
                    out.push('\\');
                    i += 2;
                }
                '"' => {
                    out.push('"');
                    i += 2;
                }
                'n' => {
                    out.push('\n');
                    i += 2;
                }
                // Invalid escape: keep the backslash as a literal character.
                _ => {
                    out.push('\\');
                    i += 1;
                }
            }
            continue;
        }
        out.push(c);
        i += 1;
    }
    out
}

/// Format a finite number as a Prometheus value token (integer when integral).
#[must_use]
pub fn num(v: f64) -> String {
    if !v.is_finite() {
        return "0".to_string();
    }
    if pycompat::is_integral(v) && v.abs() < 1e15 {
        return pycompat::int_str_f64(v);
    }
    pycompat::repr_f64(v)
}

fn is_name_start(c: char) -> bool {
    c.is_ascii_alphabetic() || c == '_' || c == ':'
}

fn is_name_char(c: char) -> bool {
    c.is_ascii_alphanumeric() || c == '_' || c == ':'
}

fn is_label_start(c: char) -> bool {
    c.is_ascii_alphabetic() || c == '_'
}

fn is_label_char(c: char) -> bool {
    c.is_ascii_alphanumeric() || c == '_'
}

/// Coerce a JSON key to a valid metric name, or `None` if impossible.
#[must_use]
pub fn sanitize_metric_name(k: &str) -> Option<String> {
    let mut name: String = k
        .chars()
        .map(|c| if is_name_char(c) { c } else { '_' })
        .collect();
    if name.is_empty() {
        return None;
    }
    if !name.starts_with(is_name_start) {
        name.insert(0, '_');
    }
    Some(name)
}

fn looks_json(text: &str, ctype: &str) -> bool {
    if ctype.to_lowercase().contains("json") {
        return true;
    }
    pycompat::lstrip(text).starts_with(['{', '['])
}

/// A parsed sample line: `name`, the raw `{…}` label block (if any) and the
/// value token — the fields Python's `_SAMPLE_RE` captures.
struct Sample<'a> {
    name: &'a str,
    labels: Option<&'a str>,
    value: &'a str,
}

/// Match one whole, well-formed sample line, reproducing Python's `_SAMPLE_RE`:
/// `^(MNAME)(LABELS)?[ \t]+(\S+)(?:[ \t]+\S+)?[ \t]*$`. A non-matching (garbled)
/// line yields `None` and is skipped.
fn match_sample(line: &str) -> Option<Sample<'_>> {
    let b: Vec<char> = line.chars().collect();
    // Byte offset of each char index, so the captures can be &str slices.
    let mut offs: Vec<usize> = Vec::with_capacity(b.len() + 1);
    let mut acc = 0usize;
    for c in &b {
        offs.push(acc);
        acc += c.len_utf8();
    }
    offs.push(acc);

    let mut i = 0usize;
    if !b.first().copied().is_some_and(is_name_start) {
        return None;
    }
    while i < b.len() && is_name_char(b[i]) {
        i += 1;
    }
    let name = &line[..offs[i]];

    let labels = if b.get(i) == Some(&'{') {
        let start = i;
        i += 1;
        // \s*  (?: LABEL \s* (?: , \s* LABEL \s* )* ,? \s* )?  \}
        while b.get(i).copied().is_some_and(pycompat::is_space) {
            i += 1;
        }
        if b.get(i) != Some(&'}') {
            loop {
                // LNAME "=" LVAL
                if !b.get(i).copied().is_some_and(is_label_start) {
                    return None;
                }
                while b.get(i).copied().is_some_and(is_label_char) {
                    i += 1;
                }
                if b.get(i) != Some(&'=') {
                    return None;
                }
                i += 1;
                if b.get(i) != Some(&'"') {
                    return None;
                }
                i += 1;
                loop {
                    match b.get(i) {
                        None => return None,
                        Some('"') => {
                            i += 1;
                            break;
                        }
                        Some('\\') => {
                            // `\\.` — a backslash plus any character.
                            if i + 1 >= b.len() {
                                return None;
                            }
                            i += 2;
                        }
                        Some(_) => i += 1,
                    }
                }
                while b.get(i).copied().is_some_and(pycompat::is_space) {
                    i += 1;
                }
                if b.get(i) == Some(&',') {
                    i += 1;
                    while b.get(i).copied().is_some_and(pycompat::is_space) {
                        i += 1;
                    }
                    // A trailing comma before `}` is allowed.
                    if b.get(i) == Some(&'}') {
                        break;
                    }
                    continue;
                }
                break;
            }
        }
        if b.get(i) != Some(&'}') {
            return None;
        }
        i += 1;
        Some(&line[offs[start]..offs[i]])
    } else {
        None
    };

    // [ \t]+
    let mut sep = 0usize;
    while matches!(b.get(i), Some(' ' | '\t')) {
        i += 1;
        sep += 1;
    }
    if sep == 0 {
        return None;
    }
    // (\S+)
    let vstart = i;
    while b.get(i).copied().is_some_and(|c| !pycompat::is_space(c)) {
        i += 1;
    }
    if i == vstart {
        return None;
    }
    let value = &line[offs[vstart]..offs[i]];
    // (?:[ \t]+\S+)?
    let save = i;
    let mut sep2 = 0usize;
    while matches!(b.get(i), Some(' ' | '\t')) {
        i += 1;
        sep2 += 1;
    }
    if sep2 > 0 {
        let tstart = i;
        while b.get(i).copied().is_some_and(|c| !pycompat::is_space(c)) {
            i += 1;
        }
        if i == tstart {
            i = save; // no timestamp token; the run must be trailing [ \t]*
        }
    }
    // [ \t]*$
    while matches!(b.get(i), Some(' ' | '\t')) {
        i += 1;
    }
    if i != b.len() {
        return None;
    }
    Some(Sample {
        name,
        labels,
        value,
    })
}

/// Build the inner label list, our `service` label first.
///
/// Label *names* are de-duplicated (first wins, and our `service` is always
/// authoritative) because a repeated label name is a Prometheus parse error.
/// Each upstream *value* is decoded to its logical string and re-escaped, so an
/// invalid escape or stray quote/backslash from a hostile service can never
/// produce invalid exposition.
fn merge_labels(label_block: Option<&str>, service: &str) -> String {
    let mut parts = vec![format!("service=\"{}\"", escape_label_value(service))];
    let mut seen: Vec<String> = vec!["service".to_string()];
    if let Some(block) = label_block {
        // Strip the validated `{ }` and walk every `name="value"` pair.
        let inner: Vec<char> = block[1..block.len() - 1].chars().collect();
        let mut i = 0usize;
        while i < inner.len() {
            if !is_label_start(inner[i]) {
                i += 1;
                continue;
            }
            // A label name must not be preceded by a name character, matching
            // `finditer`'s left-to-right non-overlapping scan.
            let start = i;
            while i < inner.len() && is_label_char(inner[i]) {
                i += 1;
            }
            if inner.get(i) != Some(&'=') || inner.get(i + 1) != Some(&'"') {
                continue;
            }
            let name: String = inner[start..i].iter().collect();
            i += 2;
            let vstart = i;
            let mut value = String::new();
            let mut closed = false;
            while i < inner.len() {
                match inner[i] {
                    '"' => {
                        closed = true;
                        break;
                    }
                    '\\' if i + 1 < inner.len() => {
                        value.push('\\');
                        value.push(inner[i + 1]);
                        i += 2;
                    }
                    c => {
                        value.push(c);
                        i += 1;
                    }
                }
            }
            if !closed {
                i = vstart;
                continue;
            }
            i += 1; // closing quote
            if seen.contains(&name) {
                continue; // drop duplicate names (incl. an upstream 'service')
            }
            seen.push(name.clone());
            parts.push(format!(
                "{name}=\"{}\"",
                escape_label_value(&unescape_label_value(&value))
            ));
        }
    }
    parts.join(",")
}

fn federate_prometheus(service: &str, raw: &str) -> Vec<(String, String)> {
    let mut out: Vec<(String, String)> = Vec::new();
    for line in pycompat::splitlines(raw) {
        let s = pycompat::strip(line);
        if s.is_empty() || s.starts_with('#') {
            continue;
        }
        let Some(sample) = match_sample(s) else {
            continue; // garbled / unparseable label block -> skip
        };
        if is_reserved(sample.name) {
            continue; // never let an upstream forge/duplicate our own gauges
        }
        // Reject junk; NaN/Inf are canonicalised by `num`.
        let Some(fv) = pycompat::py_float(sample.value) else {
            continue;
        };
        out.push((
            sample.name.to_string(),
            format!(
                "{}{{{}}} {}",
                sample.name,
                merge_labels(sample.labels, service),
                num(fv)
            ),
        ));
        if out.len() >= MAX_FEDERATE_LINES {
            break;
        }
    }
    out
}

fn federate_json(service: &str, raw: &str) -> Vec<(String, String)> {
    // A hostile body (garbage, or nesting deep enough that CPython's json
    // raises RecursionError) must only ever yield no series.
    let Ok(parsed) = crawlcore::json::parse(raw) else {
        return Vec::new();
    };
    let flat = flatten_json(&parsed);
    let esc = escape_label_value(service);
    let mut out: Vec<(String, String)> = Vec::new();
    for (k, v) in flat.iter() {
        let Some(name) = sanitize_metric_name(k) else {
            continue;
        };
        if is_reserved(&name) {
            continue; // skip reserved suitedash_* names an upstream might forge
        }
        out.push((
            name.clone(),
            format!("{name}{{service=\"{esc}\"}} {}", num(*v)),
        ));
        if out.len() >= MAX_FEDERATE_LINES {
            break;
        }
    }
    out
}

fn federate_service(service: &str, result: &ServiceResult) -> Vec<(String, String)> {
    let raw = &result.metrics_raw;
    if pycompat::strip(raw).is_empty() {
        return Vec::new();
    }
    if looks_json(raw, &result.metrics_ctype) {
        federate_json(service, raw)
    } else {
        federate_prometheus(service, raw)
    }
}

/// Federate `results` into one Prometheus exposition.
#[must_use]
pub fn render_federated_metrics(results: &Results) -> String {
    let mut out: Vec<String> = vec![
        "# HELP suitedash_up 1 if the suitedash dashboard is running.".to_string(),
        "# TYPE suitedash_up gauge".to_string(),
        "suitedash_up 1".to_string(),
    ];

    let mut up_samples: Vec<String> = Vec::new();
    let mut dur_samples: Vec<String> = Vec::new();
    let mut cnt_samples: Vec<String> = Vec::new();
    let mut federated: Vec<(String, String)> = Vec::new();

    for (name, r) in results.iter() {
        let lbl = escape_label_value(name);
        up_samples.push(format!(
            "suitedash_service_up{{service=\"{lbl}\"}} {}",
            u8::from(r.up)
        ));
        if let Some(lat) = r.latency_ms.filter(|l| l.is_finite()) {
            dur_samples.push(format!(
                "suitedash_service_scrape_duration_seconds{{service=\"{lbl}\"}} {}",
                num(lat / 1000.0)
            ));
        }
        let fed = federate_service(name, r);
        cnt_samples.push(format!(
            "suitedash_service_metric_count{{service=\"{lbl}\"}} {}",
            fed.len()
        ));
        federated.extend(fed);
    }

    out.push(
        "# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.".to_string(),
    );
    out.push("# TYPE suitedash_service_up gauge".to_string());
    out.extend(up_samples);
    out.push(
        "# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took."
            .to_string(),
    );
    out.push("# TYPE suitedash_service_scrape_duration_seconds gauge".to_string());
    out.extend(dur_samples);
    out.push(
        "# HELP suitedash_service_metric_count Federated upstream series emitted for the service."
            .to_string(),
    );
    out.push("# TYPE suitedash_service_metric_count gauge".to_string());
    out.extend(cnt_samples);

    // Group federated upstream samples by metric name so each family is a single
    // contiguous block — a clean, tool-friendly exposition.
    let mut grouped: OrderedMap<Vec<String>> = OrderedMap::new();
    for (name, line) in federated {
        if !grouped.contains_key(&name) {
            grouped.insert(name.clone(), Vec::new());
        }
        if let Some(lines) = grouped.get_mut(&name) {
            lines.push(line);
        }
    }
    for (_, lines) in grouped.iter() {
        out.extend(lines.iter().cloned());
    }

    let mut text = out.join("\n");
    text.push('\n');
    text
}

#[cfg(test)]
mod tests {
    use super::*;

    fn up(name: &str, raw: &str, ctype: &str, latency: Option<f64>) -> ServiceResult {
        let mut r = ServiceResult::new(name, "http://x", true);
        r.latency_ms = latency;
        r.metrics_raw = raw.to_string();
        r.metrics_ctype = ctype.to_string();
        r
    }

    fn one(name: &str, r: ServiceResult) -> Results {
        let mut results = Results::new();
        results.insert(name, r);
        results
    }

    /// Every emitted sample line must be well-formed Prometheus text.
    fn assert_valid(text: &str) {
        for line in text.lines() {
            if line.is_empty() || line.starts_with('#') {
                continue;
            }
            assert!(
                match_sample(line).is_some(),
                "invalid exposition line: {line:?}"
            );
        }
    }

    #[test]
    fn relabels_prometheus_samples_with_service_label() {
        let raw = "# HELP http_reqs total\n# TYPE http_reqs counter\nhttp_reqs 5\nhttp_reqs{code=\"200\"} 4\n";
        let out =
            render_federated_metrics(&one("alpha", up("alpha", raw, "text/plain", Some(5.0))));
        assert!(out.contains("http_reqs{service=\"alpha\"} 5"));
        assert!(out.contains("http_reqs{service=\"alpha\",code=\"200\"} 4"));
        assert!(!out.contains("# TYPE http_reqs"));
        assert_valid(&out);
    }

    #[test]
    fn skips_garbage_and_non_numeric_lines() {
        let raw = "good_metric 1\nthis is not prometheus at all\nbad_value abc\nunterminated{label=\"x 2\nanother_good 2\n";
        let out = render_federated_metrics(&one("s", up("s", raw, "text/plain", Some(5.0))));
        assert!(out.contains("good_metric{service=\"s\"} 1"));
        assert!(out.contains("another_good{service=\"s\"} 2"));
        assert!(!out.contains("not prometheus"));
        assert!(!out.contains("abc"));
        assert!(!out.contains("unterminated"));
        assert!(out.contains("suitedash_service_metric_count{service=\"s\"} 2"));
        assert_valid(&out);
    }

    /// A hostile upstream body must never panic the exporter or the parsers, and
    /// whatever survives must still be valid exposition. Deterministic soup drawn
    /// from the grammar's own metacharacters (an LCG, so no dependency and no
    /// flake).
    #[test]
    fn hostile_bodies_never_panic_and_stay_valid() {
        let alphabet: Vec<char> = "ab_:{}\"\\=, \t\n\r#019.eE+-NnIif\u{1c}\u{85}\u{a0}é\u{1f600}"
            .chars()
            .collect();
        let mut seed: u64 = 0x2545_f491_4f6c_dd1d;
        let mut next = || {
            seed = seed.wrapping_mul(6_364_136_223_846_793_005).wrapping_add(1);
            (seed >> 33) as usize
        };
        for _ in 0..400 {
            let len = next() % 120;
            let body: String = (0..len)
                .map(|_| alphabet[next() % alphabet.len()])
                .collect();
            let ctype = if next() % 2 == 0 {
                "text/plain"
            } else {
                "application/json"
            };
            let out = render_federated_metrics(&one("s", up("s", &body, ctype, Some(1.0))));
            assert_valid(&out);
            // The two parsers see the same soup.
            let _ = crate::metrics::parse_metrics(body.as_bytes(), ctype);
            let _ = crate::metrics::parse_prometheus(&body);
        }
    }

    #[test]
    fn emits_suitedash_own_gauges() {
        let mut results = Results::new();
        results.insert("alpha", up("alpha", "x 1\n", "text/plain", Some(12.0)));
        results.insert("beta", ServiceResult::new("beta", "x", false));
        let out = render_federated_metrics(&results);
        assert!(out.contains("suitedash_up 1"));
        assert!(out.contains("suitedash_service_up{service=\"alpha\"} 1"));
        assert!(out.contains("suitedash_service_up{service=\"beta\"} 0"));
        assert!(out.contains("suitedash_service_scrape_duration_seconds{service=\"alpha\"} 0.012"));
        assert_valid(&out);
    }

    #[test]
    fn json_upstream_is_federated_and_names_sanitized() {
        let raw = r#"{"docs": 1000, "a.b": 2, "ok": true, "tags": ["x"]}"#;
        let out =
            render_federated_metrics(&one("js", up("js", raw, "application/json", Some(1.0))));
        assert!(out.contains("docs{service=\"js\"} 1000"));
        assert!(out.contains("a_b{service=\"js\"} 2"));
        assert!(!out.contains("tags"));
        assert_valid(&out);
    }

    #[test]
    fn hostile_service_name_is_escaped_in_the_label() {
        let hostile = "ev\"il\\\nx";
        let out =
            render_federated_metrics(&one(hostile, up(hostile, "m 1\n", "text/plain", Some(1.0))));
        assert!(out.contains("service=\"ev\\\"il\\\\\\nx\""));
        assert!(!out.contains("service=\"ev\"il"));
        assert_valid(&out);
    }

    #[test]
    fn hostile_body_never_breaks_the_exporter() {
        let raw = "\u{0}\u{1} garbage {{{ \n".repeat(50) + "evil{a=\"}\"} 3\n";
        let out = render_federated_metrics(&one("s", up("s", &raw, "text/plain", Some(1.0))));
        assert!(out.contains("suitedash_up 1"));
        assert!(out.contains("evil{service=\"s\",a=\"}\"} 3"));
        assert_valid(&out);
    }

    #[test]
    fn invalid_upstream_escape_is_reencoded() {
        let out = render_federated_metrics(&one(
            "evil",
            up("evil", "m{tag=\"a\\tb\"} 1\n", "text/plain", Some(1.0)),
        ));
        assert!(out.contains(r#"m{service="evil",tag="a\\tb"} 1"#));
        assert!(!out.contains(r#"tag="a\tb""#));
    }

    #[test]
    fn legit_escapes_round_trip() {
        let out = render_federated_metrics(&one(
            "s",
            up(
                "s",
                "m{a=\"x\\\"y\",b=\"c\\\\d\",c=\"e\\nf\"} 1\n",
                "text/plain",
                Some(1.0),
            ),
        ));
        assert!(out.contains(r#"a="x\"y""#));
        assert!(out.contains(r#"b="c\\d""#));
        assert!(out.contains(r#"c="e\nf""#));
    }

    #[test]
    fn duplicate_and_spoofed_labels_are_dropped() {
        let out = render_federated_metrics(&one(
            "s",
            up("s", "m{a=\"1\",a=\"2\"} 1\n", "text/plain", Some(1.0)),
        ));
        assert!(out.contains("m{service=\"s\",a=\"1\"} 1"));
        assert!(!out.contains("a=\"2\""));

        let out = render_federated_metrics(&one(
            "real",
            up(
                "real",
                "m{service=\"spoof\",k=\"v\"} 1\n",
                "text/plain",
                Some(1.0),
            ),
        ));
        assert!(out.contains("m{service=\"real\",k=\"v\"} 1"));
        assert!(!out.contains("spoof"));
    }

    #[test]
    fn upstream_cannot_forge_reserved_series() {
        let raw = "suitedash_up 0\nsuitedash_service_up 0\nlegit 7\n";
        let out = render_federated_metrics(&one("evil", up("evil", raw, "text/plain", Some(1.0))));
        assert!(out.contains("suitedash_up 1"));
        assert!(out.contains("suitedash_service_up{service=\"evil\"} 1"));
        assert!(!out.contains("suitedash_service_up{service=\"evil\"} 0"));
        assert!(!out.contains("suitedash_up{service=\"evil\"}"));
        assert!(out.contains("legit{service=\"evil\"} 7"));
        assert!(out.contains("suitedash_service_metric_count{service=\"evil\"} 1"));

        let out = render_federated_metrics(&one(
            "evil",
            up(
                "evil",
                r#"{"suitedash_up": 0, "ok": 5}"#,
                "application/json",
                Some(1.0),
            ),
        ));
        assert!(out.contains("suitedash_up 1"));
        assert!(!out.contains("suitedash_up{service=\"evil\"}"));
        assert!(out.contains("ok{service=\"evil\"} 5"));
    }

    #[test]
    fn deeply_nested_json_body_yields_no_series() {
        let deep = "[".repeat(4000);
        assert!(federate_json("evil", &deep).is_empty());
        let out = render_federated_metrics(&one(
            "evil",
            up("evil", &deep, "application/json", Some(1.0)),
        ));
        assert!(out.contains("suitedash_service_metric_count{service=\"evil\"} 0"));
        assert_valid(&out);
    }

    #[test]
    fn federated_values_are_canonicalized() {
        let out = render_federated_metrics(&one(
            "s",
            up(
                "s",
                "grouped 1_000\nplain 3.0\nnanv NaN\n",
                "text/plain",
                Some(1.0),
            ),
        ));
        assert!(out.contains("grouped{service=\"s\"} 1000"));
        assert!(!out.contains("1_000"));
        assert!(out.contains("plain{service=\"s\"} 3"));
        assert!(out.contains("nanv{service=\"s\"} 0"));
        assert_valid(&out);
    }

    #[test]
    fn down_service_still_valid_with_no_upstream_series() {
        let out = render_federated_metrics(&one("d", ServiceResult::new("d", "x", false)));
        assert!(out.contains("suitedash_up 1"));
        assert!(out.contains("suitedash_service_up{service=\"d\"} 0"));
        assert_valid(&out);
    }

    #[test]
    fn output_reparses_as_prometheus() {
        let out = render_federated_metrics(&one(
            "w",
            up("w", "widgets_total 7\n", "text/plain", Some(1.0)),
        ));
        let m = crate::metrics::parse_prometheus(&out);
        assert_eq!(m.get("suitedash_up"), Some(&1.0));
        assert_eq!(m.get("widgets_total"), Some(&7.0));
    }

    #[test]
    fn sample_grammar_matches_the_python_regex() {
        assert!(match_sample("a 1").is_some());
        assert!(match_sample("a{} 1").is_some());
        assert!(match_sample("a{b=\"c\"} 1 123").is_some());
        assert!(match_sample("a{b=\"c\",} 1").is_some());
        assert!(match_sample("a{ b=\"c\" , d=\"e\" } 1").is_some());
        assert!(match_sample("a{b=\"\\\"\"} 1").is_some());
        assert!(match_sample("1a 1").is_none());
        assert!(match_sample("a").is_none());
        assert!(match_sample("a 1 2 3").is_none());
        assert!(match_sample("a{b=c} 1").is_none());
        assert!(match_sample("a{b=\"c} 1").is_none());
        assert!(match_sample("a{,} 1").is_none());
    }
}
