//! The pure core of the Python `suitedash.probe`: the two tolerant metric
//! parsers, the surfaced-metric selection, the [`ServiceResult`] every other
//! module consumes, and the poll-sweep roll-up.
//!
//! `/metrics` may be Prometheus text on one service and JSON on another, so
//! [`parse_metrics`] auto-detects: it parses JSON and flattens it one level, or
//! parses `name value` Prometheus lines (ignoring `#` HELP/TYPE comments). A
//! trailing timestamp is tolerated, and a labelled series is stored under both
//! its full token and its bare base name (first series wins) so a config key
//! resolves whether or not it carries labels. Non-finite values (NaN/`±Inf`) are
//! deliberately dropped, so the `/api/status` JSON stays strictly valid, and a
//! metric *name* longer than [`MAX_METRIC_NAME`] is dropped too, so an untrusted
//! body cannot mint permanent history keys of arbitrary size.
//!
//! Everything here is a function of its arguments — the SSRF-conscious HTTP
//! probing that *produces* the bodies is the `net` tier and is not in this
//! module. Cross-checked byte-identical to Python by `tests/xcheck_metrics.rs`.
//!
//! # Documented divergences (JSON dialect)
//!
//! The JSON side goes through the first-party [`crawlcore::json`] parser, which
//! is strict RFC 8259 where CPython's `json.loads` has three extensions. All
//! three make the Rust side parse *less*, never more, and every one of them
//! yields an empty metric map on both sides in the shapes a metrics endpoint
//! actually emits:
//!
//! * `NaN`/`Infinity`/`-Infinity` literals are accepted by `json.loads` and
//!   rejected here — but such a value is non-finite and would be dropped anyway.
//! * Nesting deeper than [`crawlcore::json::MAX_DEPTH`] (200) is rejected here,
//!   where CPython recurses to ~1000 before raising `RecursionError`. Only the
//!   top two levels are ever flattened, so a deep *sibling* of a shallow number
//!   is the sole difference.
//! * An integer literal too large for `i64` becomes an `f64` here; CPython keeps
//!   it exact and then `float()`s it — identical up to `1e308`, above which
//!   CPython raises `OverflowError` and this returns `inf` (dropped as
//!   non-finite).

use crate::config::ServiceConfig;
use crate::pycompat;
use crawlcore::json::Value;
use std::collections::HashMap;

/// When a service surfaces no explicit metric keys, show at most this many
/// (Python `probe.AUTO_LIMIT`).
pub const AUTO_LIMIT: usize = 6;

/// Longest metric NAME kept from a parsed body, in bytes.
///
/// A `/metrics` body is untrusted — it is whatever the polled service, or
/// whoever owns it now, chose to return — and nothing bounded the *key* length.
/// A name parsed here is promoted by [`surface`] onto the card, used by
/// [`crate::history::History::record`] as a permanent ring key, and deep-copied
/// by `Monitor::snapshot()` on **every** request to `/` and `/api/status`.
/// Measured: six lines of `("a" × 150 000)_<sweep>_<j> 1` — a 900 KB body, well
/// inside the 1 MiB [`crate::probe::MAX_BODY`] fetch cap — with the names rotated
/// each sweep produced 256 retained series holding 36.6 MiB of key strings after
/// 64 sweeps, re-copied per request.
///
/// 256 bytes is many times the longest metric name any real exposition uses
/// (Prometheus's own conventions land under 80). A labelled series whose *full*
/// token `name{label="value",…}` exceeds this is still recorded under its bare
/// base name; only the exact-token alias is dropped.
pub const MAX_METRIC_NAME: usize = 256;

/// Whether `name` is short enough to retain (see [`MAX_METRIC_NAME`]).
#[must_use]
fn name_fits(name: &str) -> bool {
    name.len() <= MAX_METRIC_NAME
}

/// An insertion-ordered `String -> V` map with Python `dict` semantics: a
/// re-inserted key keeps its original position, iteration is in insertion order,
/// and lookups are O(1).
///
/// The port uses it wherever Python's dict *order* is observable — parsed metric
/// order drives the federated exposition, the card rows and the JSON key order.
#[derive(Clone, Debug)]
pub struct OrderedMap<V> {
    items: Vec<(String, V)>,
    index: HashMap<String, usize>,
}

impl<V> Default for OrderedMap<V> {
    fn default() -> Self {
        Self::new()
    }
}

impl<V: PartialEq> PartialEq for OrderedMap<V> {
    /// Order-sensitive equality (Python's `dict.__eq__` ignores order; the port
    /// compares order too, because order is part of every golden).
    fn eq(&self, other: &Self) -> bool {
        self.items == other.items
    }
}

impl<V> OrderedMap<V> {
    /// An empty map.
    #[must_use]
    pub fn new() -> Self {
        OrderedMap {
            items: Vec::new(),
            index: HashMap::new(),
        }
    }

    /// The value for `key`, or `None` — Python `d.get(key)`.
    #[must_use]
    pub fn get(&self, key: &str) -> Option<&V> {
        self.index.get(key).map(|i| &self.items[*i].1)
    }

    /// A mutable reference to the value for `key`, or `None`.
    pub fn get_mut(&mut self, key: &str) -> Option<&mut V> {
        match self.index.get(key) {
            Some(&i) => Some(&mut self.items[i].1),
            None => None,
        }
    }

    /// `true` when `key` is present.
    #[must_use]
    pub fn contains_key(&self, key: &str) -> bool {
        self.index.contains_key(key)
    }

    /// Insert or replace — Python `d[key] = value` (an existing key keeps its
    /// position).
    pub fn insert(&mut self, key: impl Into<String>, value: V) {
        let key = key.into();
        match self.index.get(&key) {
            Some(&i) => self.items[i].1 = value,
            None => {
                self.index.insert(key.clone(), self.items.len());
                self.items.push((key, value));
            }
        }
    }

    /// Insert only when absent — Python `d.setdefault(key, value)`.
    pub fn set_default(&mut self, key: impl Into<String>, value: V) {
        let key = key.into();
        if !self.index.contains_key(&key) {
            self.index.insert(key.clone(), self.items.len());
            self.items.push((key, value));
        }
    }

    /// Number of entries.
    #[must_use]
    pub fn len(&self) -> usize {
        self.items.len()
    }

    /// `true` when empty.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.items.is_empty()
    }

    /// The entries in insertion order.
    #[must_use]
    pub fn as_slice(&self) -> &[(String, V)] {
        &self.items
    }

    /// Iterate `(key, value)` in insertion order.
    pub fn iter(&self) -> impl Iterator<Item = (&str, &V)> {
        self.items.iter().map(|(k, v)| (k.as_str(), v))
    }

    /// Iterate the keys in insertion order.
    pub fn keys(&self) -> impl Iterator<Item = &str> {
        self.items.iter().map(|(k, _)| k.as_str())
    }
}

impl<'a, V> IntoIterator for &'a OrderedMap<V> {
    type Item = (&'a str, &'a V);
    type IntoIter = Box<dyn Iterator<Item = (&'a str, &'a V)> + 'a>;
    fn into_iter(self) -> Self::IntoIter {
        Box::new(self.iter())
    }
}

impl<V> FromIterator<(String, V)> for OrderedMap<V> {
    fn from_iter<T: IntoIterator<Item = (String, V)>>(iter: T) -> Self {
        let mut out = OrderedMap::new();
        for (k, v) in iter {
            out.insert(k, v);
        }
        out
    }
}

/// Parsed numeric metrics in first-seen order (Python `Dict[str, float]`).
pub type MetricMap = OrderedMap<f64>;

/// The numbers surfaced on a card: each configured key mapped to its value, or
/// `None` when the service did not expose it (Python `Dict[str, Optional[float]]`).
pub type SurfacedMetrics = OrderedMap<Option<f64>>;

/// One poll sweep's results, keyed by service name in configuration order
/// (Python `OrderedDict[str, ServiceResult]`).
pub type Results = OrderedMap<ServiceResult>;

/// Parse a Prometheus/JSON scalar to a *finite* float, else `None`
/// (Python `probe._to_number`).
///
/// Prometheus permits `NaN`/`+Inf`/`-Inf`; those are deliberately rejected so
/// downstream JSON serialisation stays strictly valid.
#[must_use]
pub fn to_number(text: &str) -> Option<f64> {
    let s = pycompat::strip(text);
    if s.is_empty() {
        return None;
    }
    pycompat::py_float(s).filter(|v| v.is_finite())
}

/// Parse Prometheus text exposition `name value` lines (Python
/// `probe.parse_prometheus`).
///
/// `#` comment lines (HELP/TYPE) and blanks are ignored and a trailing timestamp
/// is tolerated. For a labelled series (`name{a="b"} 3`) the value is stored
/// under both the full token and the bare base name (first series wins for the
/// base name), so a config key like `gitweb_requests_total` resolves whether or
/// not it carries labels.
#[must_use]
pub fn parse_prometheus(text: &str) -> MetricMap {
    let mut out = MetricMap::new();
    for raw in pycompat::splitlines(text) {
        let line = pycompat::strip(raw);
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let parts = pycompat::split_whitespace(line);
        if parts.len() < 2 {
            continue;
        }
        let (name, value) = (parts[0], parts[1]);
        let Some(num) = to_number(value) else {
            continue;
        };
        let base = name.split_once('{').map_or(name, |(b, _)| b);
        // An over-long name is dropped rather than retained forever: see
        // MAX_METRIC_NAME (a 150 000-byte name is not a metric, it is a memory
        // amplifier aimed at the history rings and every snapshot copy).
        if !name_fits(base) {
            continue;
        }
        out.set_default(base, num);
        if name.contains('{') && name_fits(name) {
            out.insert(name, num);
        }
    }
    out
}

/// Flatten a JSON object *one level* into numeric `key -> float` pairs (Python
/// `probe.flatten_json`).
///
/// Top-level scalars are kept; a nested object contributes `parent_child` keys
/// for its numeric leaves. Numeric strings are coerced, booleans become 1/0.
/// Lists, `null` and deeper nesting are ignored — a status card wants a handful
/// of numbers.
#[must_use]
pub fn flatten_json(obj: &Value) -> MetricMap {
    let mut out = MetricMap::new();
    let Some(items) = obj.as_object() else {
        return out;
    };
    for (key, v) in items {
        // Same cap as the Prometheus path: a JSON body can carry a 150 KB key
        // just as easily as a text one.
        if !name_fits(key) {
            continue;
        }
        match v {
            Value::Bool(b) => out.insert(key.clone(), f64::from(u8::from(*b))),
            Value::Int(i) => out.insert(key.clone(), *i as f64),
            Value::Num(n) if n.is_finite() => out.insert(key.clone(), *n),
            Value::Str(s) => {
                if let Some(num) = to_number(s) {
                    out.insert(key.clone(), num);
                }
            }
            Value::Object(inner) => {
                for (k2, v2) in inner {
                    let sub = format!("{key}_{k2}");
                    if !name_fits(&sub) {
                        continue;
                    }
                    match v2 {
                        Value::Bool(b) => out.insert(sub, f64::from(u8::from(*b))),
                        Value::Int(i) => out.insert(sub, *i as f64),
                        Value::Num(n) if n.is_finite() => out.insert(sub, *n),
                        Value::Str(s) => {
                            if let Some(num) = to_number(s) {
                                out.insert(sub, num);
                            }
                        }
                        _ => {}
                    }
                }
            }
            _ => {}
        }
    }
    out
}

/// Parse a metrics body as JSON *or* Prometheus text, auto-detecting which
/// (Python `probe.parse_metrics`).
///
/// JSON is preferred when the content-type says so or the body opens with
/// `{`/`[`; otherwise Prometheus text is tried first. Either way both strategies
/// are attempted before giving up, so a mislabelled endpoint still parses.
#[must_use]
pub fn parse_metrics(body: &[u8], content_type: &str) -> MetricMap {
    let decoded = String::from_utf8_lossy(body);
    let text = pycompat::strip(&decoded);
    if text.is_empty() {
        return MetricMap::new();
    }
    let ctype = content_type.to_lowercase();
    let looks_json = ctype.contains("json") || text.starts_with(['{', '[']);

    if looks_json {
        if let Ok(v) = crawlcore::json::parse(text) {
            return flatten_json(&v);
        }
    }
    let prom = parse_prometheus(text);
    if !prom.is_empty() {
        return prom;
    }
    // Last resort: it may have been unadvertised JSON.
    crawlcore::json::parse(text).map_or_else(|_| MetricMap::new(), |v| flatten_json(&v))
}

/// Select the numbers to show: the configured `keys` (`None` when the service
/// did not expose one), or, when none are configured, the first [`AUTO_LIMIT`]
/// metrics sorted by name (Python `probe._surface`).
#[must_use]
pub fn surface(metrics: &MetricMap, keys: &[String]) -> SurfacedMetrics {
    let mut out = SurfacedMetrics::new();
    if keys.is_empty() {
        // Auto-picked names come from the parsed (untrusted) body, so they are
        // re-checked here too: `surface` is public, and an embedder can hand it a
        // `MetricMap` it built itself rather than one the capped parsers produced.
        // Configured keys are the operator's own and are never filtered.
        let mut names: Vec<&str> = metrics.keys().filter(|k| name_fits(k)).collect();
        names.sort_unstable();
        for k in names.into_iter().take(AUTO_LIMIT) {
            out.insert(k, metrics.get(k).copied());
        }
    } else {
        for k in keys {
            out.insert(k.clone(), metrics.get(k).copied());
        }
    }
    out
}

/// A metric rendered for output — Python's `probe._num_out` returns an `int`
/// for an integral value and a 6-decimal-rounded `float` otherwise, and the two
/// serialise differently (`7` vs `7.5`).
#[derive(Clone, Copy, Debug, PartialEq)]
pub enum NumOut {
    /// An integral value: Python `int(f)`, printed exactly at any magnitude.
    Int(f64),
    /// A non-integral value, already `round(f, 6)`-ed.
    Float(f64),
}

impl NumOut {
    /// The JSON token Python's `json.dumps` would emit for this value.
    #[must_use]
    pub fn to_json_token(self) -> String {
        match self {
            NumOut::Int(f) => pycompat::int_str_f64(f),
            NumOut::Float(f) => pycompat::repr_f64(f),
        }
    }
}

/// Render a metric for output: `None`, an integer when integral, else the value
/// rounded to 6 decimals (Python `probe._num_out`).
#[must_use]
pub fn num_out(v: Option<f64>) -> Option<NumOut> {
    let f = v?;
    if pycompat::is_integral(f) {
        Some(NumOut::Int(f))
    } else {
        Some(NumOut::Float(pycompat::round_ndigits(f, 6)))
    }
}

/// The outcome of probing one service (one refresh) — Python
/// `probe.ServiceResult`.
#[derive(Clone, Debug, PartialEq)]
pub struct ServiceResult {
    /// Service name (the key it is stored under).
    pub name: String,
    /// The service's base URL, as configured.
    pub base_url: String,
    /// Whether the last liveness probe answered 2xx.
    pub up: bool,
    /// Round-trip time of the successful health probe, in milliseconds.
    pub latency_ms: Option<f64>,
    /// The numbers surfaced on the card, in configured order.
    pub metrics: SurfacedMetrics,
    /// When the probe ran (epoch seconds).
    pub checked_at: f64,
    /// Why the service is DOWN, when it is.
    pub error: Option<String>,
    /// Which health path answered.
    pub health_path: Option<String>,
    /// Optional human-friendly caption.
    pub label: String,
    /// Raw (capped) upstream metrics text, retained only so the aggregate
    /// `/metrics` exporter can re-emit relabelled series. Never serialised into
    /// the `/api/status` JSON.
    pub metrics_raw: String,
    /// The upstream metrics body's content-type (drives JSON auto-detection).
    pub metrics_ctype: String,
}

impl ServiceResult {
    /// A bare result for `name`/`base_url` with everything else defaulted —
    /// the Rust stand-in for the Python dataclass's keyword defaults (whose
    /// `checked_at` default is `time.time()`; here the clock is explicit).
    #[must_use]
    pub fn new(name: impl Into<String>, base_url: impl Into<String>, up: bool) -> Self {
        ServiceResult {
            name: name.into(),
            base_url: base_url.into(),
            up,
            latency_ms: None,
            metrics: SurfacedMetrics::new(),
            checked_at: 0.0,
            error: None,
            health_path: None,
            label: String::new(),
            metrics_raw: String::new(),
            metrics_ctype: String::new(),
        }
    }

    /// A DOWN result for `cfg` (Python `ServiceResult.down`); `checked_at` is
    /// passed in rather than read from the clock, keeping the constructor pure.
    #[must_use]
    pub fn down(cfg: &ServiceConfig, error: &str, checked_at: f64) -> Self {
        ServiceResult {
            name: cfg.name.clone(),
            base_url: cfg.base_url.clone(),
            up: false,
            latency_ms: None,
            metrics: SurfacedMetrics::new(),
            checked_at,
            error: Some(error.to_string()),
            health_path: None,
            label: cfg.label.clone(),
            metrics_raw: String::new(),
            metrics_ctype: String::new(),
        }
    }
}

/// Overall roll-up of a poll sweep (Python `poller.summarize`).
///
/// Lives here, with the rest of the pure result core, because both renderers
/// consume it and the concurrent poller that produces the results is the `net`
/// tier.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Summary {
    /// Number of services polled.
    pub total: usize,
    /// How many answered UP.
    pub up: usize,
    /// How many are DOWN.
    pub down: usize,
    /// `true` when every service is UP.
    pub all_up: bool,
}

/// Total / up / down counts and an `all_up` flag for a sweep.
#[must_use]
pub fn summarize(results: &Results) -> Summary {
    let total = results.len();
    let up = results.iter().filter(|(_, r)| r.up).count();
    Summary {
        total,
        up,
        down: total - up,
        all_up: up == total,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn m(pairs: &[(&str, f64)]) -> MetricMap {
        pairs
            .iter()
            .map(|(k, v)| ((*k).to_string(), *v))
            .collect::<MetricMap>()
    }

    #[test]
    fn parses_name_value_lines() {
        let text = "# HELP x_total help\n# TYPE x_total counter\nx_total 42\nx_ratio 0.75\n";
        let out = parse_prometheus(text);
        assert_eq!(out.get("x_total"), Some(&42.0));
        assert_eq!(out.get("x_ratio"), Some(&0.75));
    }

    #[test]
    fn ignores_comments_blanks_and_bad_lines() {
        let out = parse_prometheus("# a comment\n\n   \nlonelytoken\n# TYPE y gauge\ny 3\n");
        assert_eq!(out, m(&[("y", 3.0)]));
    }

    #[test]
    fn labeled_series_resolve_by_base_name() {
        let out = parse_prometheus("r_total{code=\"200\"} 40\nr_total{code=\"500\"} 2\n");
        assert_eq!(out.get("r_total"), Some(&40.0));
        assert_eq!(out.get("r_total{code=\"200\"}"), Some(&40.0));
        assert_eq!(out.get("r_total{code=\"500\"}"), Some(&2.0));
    }

    #[test]
    fn non_finite_values_are_dropped() {
        let out = parse_prometheus("good 1\nbad NaN\ninf_v +Inf\nneg -Inf\n");
        assert_eq!(out, m(&[("good", 1.0)]));
    }

    #[test]
    fn trailing_timestamp_tolerated() {
        assert_eq!(parse_prometheus("z 9 1699999999000\n").get("z"), Some(&9.0));
    }

    #[test]
    fn flatten_one_level() {
        let obj = crawlcore::json::parse(
            r#"{"docs": 1000, "ok": true, "ratio": "0.5", "tags": ["a","b"],
                "nothing": null, "queue": {"pending": 7, "done": 300, "name": "q"}}"#,
        )
        .unwrap();
        let out = flatten_json(&obj);
        assert_eq!(out.get("docs"), Some(&1000.0));
        assert_eq!(out.get("ok"), Some(&1.0));
        assert_eq!(out.get("ratio"), Some(&0.5));
        assert_eq!(out.get("queue_pending"), Some(&7.0));
        assert_eq!(out.get("queue_done"), Some(&300.0));
        assert!(!out.contains_key("tags"));
        assert!(!out.contains_key("nothing"));
        assert!(!out.contains_key("queue_name"));
    }

    #[test]
    fn non_dict_returns_empty() {
        let obj = crawlcore::json::parse("[1, 2, 3]").unwrap();
        assert!(flatten_json(&obj).is_empty());
    }

    #[test]
    fn autodetect() {
        assert_eq!(
            parse_metrics(b"a 1\nb 2\n", "text/plain"),
            m(&[("a", 1.0), ("b", 2.0)])
        );
        assert_eq!(
            parse_metrics(br#"{"a": 1, "b": 2}"#, "application/json"),
            m(&[("a", 1.0), ("b", 2.0)])
        );
        assert_eq!(parse_metrics(br#"{"a": 5}"#, ""), m(&[("a", 5.0)]));
        assert!(parse_metrics(b"", "text/plain").is_empty());
    }

    /// The measured attack: six lines whose NAME is 150 000 bytes (a 900 KB
    /// body, inside the 1 MiB fetch cap), rotated every sweep. Each name used to
    /// become a permanent history ring key and was deep-copied into every
    /// snapshot — 36.6 MiB of key strings after 64 sweeps.
    #[test]
    fn hostile_metric_names_are_capped_by_both_parsers() {
        let huge = "a".repeat(150_000);
        let text = format!("{huge}_1 1\n{huge}_2 2\nshort_total 3\n");
        let prom = parse_prometheus(&text);
        assert_eq!(prom, m(&[("short_total", 3.0)]));

        // A labelled series keeps its short base name even when the full token is
        // over the cap; only the exact-token alias is dropped.
        let labelled = parse_prometheus(&format!("r_total{{pad=\"{huge}\"}} 7\n"));
        assert_eq!(labelled, m(&[("r_total", 7.0)]));

        let json = format!(r#"{{"{huge}": 1, "ok": 2, "nested": {{"{huge}": 3, "k": 4}}}}"#);
        let flat = parse_metrics(json.as_bytes(), "application/json");
        assert_eq!(flat, m(&[("ok", 2.0), ("nested_k", 4.0)]));

        for out in [&prom, &labelled, &flat] {
            assert!(out.keys().all(|k| k.len() <= MAX_METRIC_NAME));
        }
    }

    #[test]
    fn surface_selects_configured_keys_then_falls_back_to_sorted() {
        let metrics = m(&[("b", 2.0), ("a", 1.0), ("c", 3.0)]);
        let keys = vec!["a".to_string(), "missing".to_string()];
        let s = surface(&metrics, &keys);
        assert_eq!(s.get("a"), Some(&Some(1.0)));
        assert_eq!(s.get("missing"), Some(&None));
        let auto = surface(&metrics, &[]);
        assert_eq!(auto.keys().collect::<Vec<_>>(), vec!["a", "b", "c"]);
    }

    #[test]
    fn num_out_splits_int_and_float() {
        assert_eq!(num_out(None), None);
        assert_eq!(num_out(Some(7.0)), Some(NumOut::Int(7.0)));
        assert_eq!(num_out(Some(7.5)), Some(NumOut::Float(7.5)));
        assert_eq!(
            num_out(Some(0.3333333333333333)).map(NumOut::to_json_token),
            Some("0.333333".to_string())
        );
    }

    #[test]
    fn ordered_map_keeps_python_dict_semantics() {
        let mut mm = MetricMap::new();
        mm.insert("b", 1.0);
        mm.insert("a", 2.0);
        mm.insert("b", 3.0); // replace in place, position kept
        mm.set_default("a", 9.0); // no-op
        mm.set_default("c", 4.0);
        assert_eq!(
            mm.iter().collect::<Vec<_>>(),
            vec![("b", &3.0), ("a", &2.0), ("c", &4.0)]
        );
    }

    #[test]
    fn summarize_counts() {
        let mut results = Results::new();
        results.insert("a", ServiceResult::new("a", "x", true));
        results.insert("b", ServiceResult::new("b", "x", false));
        assert_eq!(
            summarize(&results),
            Summary {
                total: 2,
                up: 1,
                down: 1,
                all_up: false
            }
        );
    }
}
