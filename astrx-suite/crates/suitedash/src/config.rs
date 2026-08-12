//! The list of services to poll, the top-level server settings, and the
//! read-only TOML config loader — a port of the Python `suitedash.config`.
//!
//! Everything has sensible defaults for the standard astrx-suite localhost
//! ports, so `suitedash` runs with no arguments. A small TOML file (CPython
//! parses it with the standard-library `tomllib`; here with the bundled
//! [`toml`] subset parser below) or CLI flags can override any of it. A service
//! entry is just `name`/`base_url` plus which paths to probe and which metric
//! keys to surface on its card.
//!
//! Loading is **bounded and validating**: rule counts, history sizes and
//! debounce windows are clamped, every `[[alert]]` gets a unique id (an
//! auto-assigned id can never collide with an explicit one — a collision would
//! merge two rules in the engine and silently drop an alert), and a malformed
//! entry is a hard [`ConfigError`] rather than a half-applied config.

use crate::pycompat;
use std::fmt;

/// Bound on the number of `[[alert]]` rules loaded from a file.
pub const MAX_RULES: usize = 256;
/// Upper clamp for a metric rule's debounce window.
pub const MAX_FOR_POLLS: i64 = 100_000;
/// Lower clamp for the per-series history ring capacity.
pub const MIN_HISTORY_CAPACITY: i64 = 2;
/// Upper clamp for the per-series history ring capacity.
pub const MAX_HISTORY_CAPACITY: i64 = 10_000;
/// Upper clamp for the number of distinct `(service, metric)` rings.
pub const MAX_HISTORY_SERIES: i64 = 100_000;
/// Upper clamp for the retained alert-transition log.
pub const MAX_ALERT_HISTORY: i64 = 10_000;

/// The comparison operators a metric alert rule may use.
pub const ALLOWED_OPS: [&str; 6] = [">", ">=", "<", "<=", "==", "!="];

/// A configuration error — a TOML parse failure or a rejected entry. Carries the
/// message CPython would have raised (`ValueError`/`TOMLDecodeError`).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ConfigError(pub String);

impl fmt::Display for ConfigError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for ConfigError {}

/// One polled service.
///
/// `health_path` is tried first for liveness; if it does not answer 2xx a set of
/// known fallbacks is tried. `metrics_path` is fetched for numbers and parsed as
/// Prometheus text *or* JSON automatically. `metrics_keys` selects which parsed
/// numbers to surface on the card (in order); empty means "auto-pick the first
/// few".
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ServiceConfig {
    /// Service name (the dashboard key and the federated `service=` label).
    pub name: String,
    /// Base URL, without a trailing slash.
    pub base_url: String,
    /// The liveness path probed first.
    pub health_path: String,
    /// The metrics path fetched for numbers.
    pub metrics_path: String,
    /// The metric keys surfaced on the card, in order.
    pub metrics_keys: Vec<String>,
    /// Optional human-friendly caption.
    pub label: String,
}

impl Default for ServiceConfig {
    fn default() -> Self {
        ServiceConfig {
            name: String::new(),
            base_url: String::new(),
            health_path: "/health".to_string(),
            metrics_path: "/metrics".to_string(),
            metrics_keys: Vec::new(),
            label: String::new(),
        }
    }
}

impl ServiceConfig {
    /// A minimal service (`/health` + `/metrics`, auto-surfaced keys).
    #[must_use]
    pub fn new(name: impl Into<String>, base_url: impl Into<String>) -> Self {
        ServiceConfig {
            name: name.into(),
            base_url: base_url.into(),
            ..ServiceConfig::default()
        }
    }

    /// A copy pointed at `base_url` (trailing slashes stripped).
    #[must_use]
    pub fn with_base(&self, base_url: &str) -> Self {
        ServiceConfig {
            base_url: pycompat::rstrip_chars(base_url, "/").to_string(),
            ..self.clone()
        }
    }
}

/// One alerting rule, evaluated once per poll sweep.
///
/// Two kinds:
///
/// * `kind = "metric"` — fires when `metric <op> threshold` holds for
///   `for_polls` consecutive sweeps (debounced) and clears when it no longer
///   holds. Only the metric keys *surfaced* on a service are visible to rules.
/// * `kind = "down"` — fires when the service's last probe was DOWN.
///
/// `service` is a service name, or `"*"` (the default) to apply the rule to
/// every polled service. `op` is one of [`ALLOWED_OPS`].
#[derive(Clone, Debug, PartialEq)]
pub struct AlertRule {
    /// Unique rule id (the engine keys per-`(service, rule id)` state on it).
    pub id: String,
    /// Target service name, or `"*"` for every polled service.
    pub service: String,
    /// `"metric"` or `"down"`.
    pub kind: String,
    /// The surfaced metric key a `metric` rule watches.
    pub metric: String,
    /// The comparison operator.
    pub op: String,
    /// The threshold compared against.
    pub threshold: f64,
    /// Consecutive breaching sweeps required before firing (debounce).
    pub for_polls: i64,
    /// Free-form severity; `critical`/`warning`/`info` sort first, in that order.
    pub severity: String,
    /// Human-readable description shown on the alert row.
    pub description: String,
}

impl Default for AlertRule {
    fn default() -> Self {
        AlertRule {
            id: String::new(),
            service: "*".to_string(),
            kind: "metric".to_string(),
            metric: String::new(),
            op: ">".to_string(),
            threshold: 0.0,
            for_polls: 1,
            severity: "warning".to_string(),
            description: String::new(),
        }
    }
}

/// The four astrx-suite services on their standard loopback ports.
///
/// Health paths intentionally mirror each tool's *documented* (inconsistent)
/// endpoint; the tolerant prober covers the cases where reality differs. Metric
/// keys are real gauges each service exposes. Note that torrentds is pointed at
/// its JSON `/api/stats` on purpose, so the default config exercises the JSON
/// metrics parser against a real service.
#[must_use]
pub fn default_services() -> Vec<ServiceConfig> {
    let svc = |name: &str, base: &str, health: &str, metrics: &str, keys: &[&str], label: &str| {
        ServiceConfig {
            name: name.to_string(),
            base_url: base.to_string(),
            health_path: health.to_string(),
            metrics_path: metrics.to_string(),
            metrics_keys: keys.iter().map(|k| (*k).to_string()).collect(),
            label: label.to_string(),
        }
    };
    vec![
        svc(
            "gitweb",
            "http://127.0.0.1:8801",
            "/health",
            "/metrics",
            &[
                "gitweb_requests_total",
                "gitweb_requests_in_flight",
                "gitweb_uptime_seconds",
            ],
            "Read-only git web viewer",
        ),
        svc(
            "onioncrawler",
            "http://127.0.0.1:8802",
            "/healthz",
            "/metrics",
            &[
                "onioncrawler_pages",
                "onioncrawler_hosts",
                "onioncrawler_frontier_queued",
            ],
            "Onion search / crawler",
        ),
        svc(
            "websearch",
            "http://127.0.0.1:8803",
            "/stats",
            "/metrics",
            &[
                "websearch_docs",
                "websearch_hosts",
                "websearch_searches_total",
            ],
            "Clear-web search",
        ),
        svc(
            "torrentds",
            "http://127.0.0.1:8804",
            "/health",
            "/api/stats",
            &["torrents", "pending", "total_size"],
            "Torrent DHT indexer",
        ),
    ]
}

/// Top-level server + poller settings.
#[derive(Clone, Debug, PartialEq)]
pub struct Config {
    /// Bind address.
    pub host: String,
    /// Bind port.
    pub port: i64,
    /// `<meta refresh>` interval; `<= 0` disables the meta-refresh.
    pub refresh_seconds: i64,
    /// Per-service probe budget, in seconds.
    pub timeout_seconds: f64,
    /// Bounded connection/probe pool size.
    pub max_workers: i64,
    /// `> 0` caches poll results for N seconds.
    pub cache_ttl: f64,
    /// Whether the server logs each request.
    pub verbose: bool,
    /// The services to poll, in display order.
    pub services: Vec<ServiceConfig>,
    /// The alert rules, in file order.
    pub alert_rules: Vec<AlertRule>,
    /// Samples retained per `(service, metric)` sparkline.
    pub history_capacity: i64,
    /// Max distinct `(service, metric)` ring buffers.
    pub history_max_series: i64,
    /// Max alert firing/clear transitions retained.
    pub alert_history: i64,
    /// Whether to render inline-SVG sparklines on the page.
    pub sparklines: bool,
}

impl Default for Config {
    fn default() -> Self {
        Config {
            host: "127.0.0.1".to_string(),
            port: 8805,
            refresh_seconds: 15,
            timeout_seconds: 3.0,
            max_workers: 16,
            cache_ttl: 0.0,
            verbose: true,
            services: default_services(),
            alert_rules: Vec::new(),
            history_capacity: 60,
            history_max_series: 256,
            alert_history: 128,
            sparklines: true,
        }
    }
}

// --------------------------------------------------------------------------- //
// A minimal, read-only TOML parser (the `tomllib` stand-in)
// --------------------------------------------------------------------------- //

/// The read-only TOML subset the config loader needs — the `tomllib` stand-in.
///
/// Covers what a `suitedash` config can contain: comments, bare/quoted/dotted
/// keys, standard tables and arrays of tables, basic and literal strings
/// (including the multi-line forms with their escapes and line-ending backslash),
/// integers (decimal with `_` separators, plus `0x`/`0o`/`0b`), floats
/// (including `inf`/`nan`), booleans, arrays (nested, multi-line, trailing
/// comma) and inline tables.
///
/// **Documented divergence:** TOML's four date/time types are rejected with a
/// parse error instead of being decoded — `suitedash` has no date-valued
/// setting, and CPython would hand back a `datetime` that every consumer here
/// (`str()`/`int()`/`float()`) treats as an error anyway.
pub mod toml {
    use super::pycompat;
    use std::fmt;

    /// A parsed TOML value.
    #[derive(Clone, Debug, PartialEq)]
    pub enum Value {
        /// A string.
        Str(String),
        /// An integer.
        Int(i64),
        /// A float.
        Float(f64),
        /// A boolean.
        Bool(bool),
        /// An array.
        Array(Vec<Value>),
        /// A table (insertion-ordered).
        Table(Vec<(String, Value)>),
    }

    impl Value {
        /// The table's entries, if this is a table.
        #[must_use]
        pub fn as_table(&self) -> Option<&[(String, Value)]> {
            match self {
                Value::Table(t) => Some(t),
                _ => None,
            }
        }

        /// The array's elements, if this is an array.
        #[must_use]
        pub fn as_array(&self) -> Option<&[Value]> {
            match self {
                Value::Array(a) => Some(a),
                _ => None,
            }
        }

        /// The value for `key`, if this is a table holding it.
        #[must_use]
        pub fn get(&self, key: &str) -> Option<&Value> {
            self.as_table()
                .and_then(|t| t.iter().find(|(k, _)| k == key).map(|(_, v)| v))
        }

        /// Python's truthiness: empty string/container, `0`, `0.0` and `false`
        /// are falsy.
        #[must_use]
        pub fn truthy(&self) -> bool {
            match self {
                Value::Str(s) => !s.is_empty(),
                Value::Int(i) => *i != 0,
                Value::Float(f) => *f != 0.0,
                Value::Bool(b) => *b,
                Value::Array(a) => !a.is_empty(),
                Value::Table(t) => !t.is_empty(),
            }
        }

        /// Python `str(value)`.
        #[must_use]
        pub fn py_str(&self) -> String {
            match self {
                Value::Str(s) => s.clone(),
                _ => self.py_repr(),
            }
        }

        /// Python `repr(value)` — used only where a container reaches a scalar
        /// slot (a config type error), matching `str(["a"]) == "['a']"`.
        #[must_use]
        pub fn py_repr(&self) -> String {
            match self {
                Value::Str(s) => {
                    // CPython prefers single quotes, switching to double quotes
                    // when the string contains a single quote but no double one.
                    let (q, esc_q) = if s.contains('\'') && !s.contains('"') {
                        ('"', '\0')
                    } else {
                        ('\'', '\'')
                    };
                    let mut out = String::with_capacity(s.len() + 2);
                    out.push(q);
                    for c in s.chars() {
                        match c {
                            '\\' => out.push_str("\\\\"),
                            '\n' => out.push_str("\\n"),
                            '\r' => out.push_str("\\r"),
                            '\t' => out.push_str("\\t"),
                            c if c == esc_q => {
                                out.push('\\');
                                out.push(c);
                            }
                            c if (c as u32) < 0x20 || c as u32 == 0x7f => {
                                out.push_str(&format!("\\x{:02x}", c as u32));
                            }
                            c => out.push(c),
                        }
                    }
                    out.push(q);
                    out
                }
                Value::Int(i) => i.to_string(),
                Value::Float(f) => pycompat::repr_f64(*f),
                Value::Bool(b) => if *b { "True" } else { "False" }.to_string(),
                Value::Array(a) => {
                    let parts: Vec<String> = a.iter().map(Value::py_repr).collect();
                    format!("[{}]", parts.join(", "))
                }
                Value::Table(t) => {
                    let parts: Vec<String> = t
                        .iter()
                        .map(|(k, v)| {
                            format!("{}: {}", Value::Str(k.clone()).py_repr(), v.py_repr())
                        })
                        .collect();
                    format!("{{{}}}", parts.join(", "))
                }
            }
        }
    }

    /// A TOML parse error (CPython's `tomllib.TOMLDecodeError`).
    #[derive(Clone, Debug, PartialEq, Eq)]
    pub struct TomlError(pub String);

    impl fmt::Display for TomlError {
        fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
            f.write_str(&self.0)
        }
    }

    struct P {
        s: Vec<char>,
        i: usize,
    }

    type PResult<T> = Result<T, TomlError>;

    fn err<T>(msg: &str) -> PResult<T> {
        Err(TomlError(msg.to_string()))
    }

    impl P {
        fn peek(&self) -> Option<char> {
            self.s.get(self.i).copied()
        }
        fn at(&self, off: usize) -> Option<char> {
            self.s.get(self.i + off).copied()
        }
        fn starts_with(&self, pat: &str) -> bool {
            pat.chars().enumerate().all(|(n, c)| self.at(n) == Some(c))
        }
        fn bump(&mut self) -> Option<char> {
            let c = self.peek();
            if c.is_some() {
                self.i += 1;
            }
            c
        }
        fn skip_inline_ws(&mut self) {
            while matches!(self.peek(), Some(' ' | '\t')) {
                self.i += 1;
            }
        }
        fn skip_comment(&mut self) {
            if self.peek() == Some('#') {
                while !matches!(self.peek(), None | Some('\n')) {
                    self.i += 1;
                }
            }
        }
        /// Skip whitespace, newlines and comments (between statements).
        fn skip_ws_and_comments(&mut self) {
            loop {
                match self.peek() {
                    Some(' ' | '\t' | '\r' | '\n') => self.i += 1,
                    Some('#') => self.skip_comment(),
                    _ => return,
                }
            }
        }
        /// Consume the rest of a line, allowing only a comment.
        fn finish_line(&mut self) -> PResult<()> {
            self.skip_inline_ws();
            self.skip_comment();
            match self.peek() {
                None => Ok(()),
                Some('\n') => {
                    self.i += 1;
                    Ok(())
                }
                Some('\r') if self.at(1) == Some('\n') => {
                    self.i += 2;
                    Ok(())
                }
                Some(c) => err(&format!("expected newline, found {c:?}")),
            }
        }
    }

    fn is_bare_key_char(c: char) -> bool {
        c.is_ascii_alphanumeric() || c == '_' || c == '-'
    }

    fn parse_key(p: &mut P) -> PResult<Vec<String>> {
        let mut parts = Vec::new();
        loop {
            p.skip_inline_ws();
            let part = match p.peek() {
                Some('"') => parse_basic_string(p)?,
                Some('\'') => parse_literal_string(p)?,
                Some(c) if is_bare_key_char(c) => {
                    let start = p.i;
                    while p.peek().is_some_and(is_bare_key_char) {
                        p.i += 1;
                    }
                    p.s[start..p.i].iter().collect()
                }
                other => return err(&format!("invalid key start {other:?}")),
            };
            parts.push(part);
            p.skip_inline_ws();
            if p.peek() == Some('.') {
                p.i += 1;
                continue;
            }
            return Ok(parts);
        }
    }

    fn hex_escape(p: &mut P, n: usize) -> PResult<char> {
        let mut v: u32 = 0;
        for _ in 0..n {
            let Some(c) = p.bump() else {
                return err("truncated unicode escape");
            };
            let Some(d) = c.to_digit(16) else {
                return err("invalid unicode escape");
            };
            v = v * 16 + d;
        }
        char::from_u32(v).map_or_else(|| err("invalid unicode scalar"), Ok)
    }

    fn parse_basic_string(p: &mut P) -> PResult<String> {
        if p.starts_with("\"\"\"") {
            p.i += 3;
            // A newline immediately after the opening delimiter is trimmed.
            if p.peek() == Some('\n') {
                p.i += 1;
            } else if p.starts_with("\r\n") {
                p.i += 2;
            }
            return parse_string_body(p, true);
        }
        p.i += 1; // opening quote
        parse_string_body(p, false)
    }

    fn parse_string_body(p: &mut P, multiline: bool) -> PResult<String> {
        let mut out = String::new();
        loop {
            let Some(c) = p.bump() else {
                return err("unterminated string");
            };
            match c {
                '"' if multiline => {
                    if p.starts_with("\"\"") {
                        p.i += 2;
                        // Up to two extra quotes belong to the content.
                        for _ in 0..2 {
                            if p.peek() != Some('"') {
                                break;
                            }
                            out.push('"');
                            p.i += 1;
                        }
                        return Ok(out);
                    }
                    out.push('"');
                }
                '"' => return Ok(out),
                '\n' | '\r' if !multiline => return err("newline in a single-line string"),
                '\\' => {
                    let Some(e) = p.bump() else {
                        return err("truncated escape");
                    };
                    match e {
                        'b' => out.push('\u{8}'),
                        't' => out.push('\t'),
                        'n' => out.push('\n'),
                        'f' => out.push('\u{c}'),
                        'r' => out.push('\r'),
                        '"' => out.push('"'),
                        '\\' => out.push('\\'),
                        'u' => out.push(hex_escape(p, 4)?),
                        'U' => out.push(hex_escape(p, 8)?),
                        ' ' | '\t' | '\n' | '\r' if multiline => {
                            // Line-ending backslash: swallow the whitespace run.
                            p.i -= 1;
                            p.skip_inline_ws();
                            if p.peek() == Some('\r') {
                                p.i += 1;
                            }
                            if p.peek() != Some('\n') {
                                return err("invalid escape sequence");
                            }
                            while matches!(p.peek(), Some(' ' | '\t' | '\r' | '\n')) {
                                p.i += 1;
                            }
                        }
                        other => return err(&format!("invalid escape {other:?}")),
                    }
                }
                c => out.push(c),
            }
        }
    }

    fn parse_literal_string(p: &mut P) -> PResult<String> {
        if p.starts_with("'''") {
            p.i += 3;
            if p.peek() == Some('\n') {
                p.i += 1;
            } else if p.starts_with("\r\n") {
                p.i += 2;
            }
            let mut out = String::new();
            loop {
                let Some(c) = p.bump() else {
                    return err("unterminated string");
                };
                if c == '\'' && p.starts_with("''") {
                    p.i += 2;
                    for _ in 0..2 {
                        if p.peek() != Some('\'') {
                            break;
                        }
                        out.push('\'');
                        p.i += 1;
                    }
                    return Ok(out);
                }
                out.push(c);
            }
        }
        p.i += 1;
        let mut out = String::new();
        loop {
            match p.bump() {
                None => return err("unterminated string"),
                Some('\'') => return Ok(out),
                Some('\n' | '\r') => return err("newline in a single-line string"),
                Some(c) => out.push(c),
            }
        }
    }

    fn parse_number_or_keyword(p: &mut P) -> PResult<Value> {
        let start = p.i;
        while let Some(c) = p.peek() {
            if c.is_ascii_alphanumeric()
                || matches!(c, '+' | '-' | '_' | '.' | ':')
                || (c == 'e' || c == 'E')
            {
                p.i += 1;
            } else {
                break;
            }
        }
        let tok: String = p.s[start..p.i].iter().collect();
        if tok.is_empty() {
            return err("expected a value");
        }
        match tok.as_str() {
            "true" => return Ok(Value::Bool(true)),
            "false" => return Ok(Value::Bool(false)),
            _ => {}
        }
        if tok.contains(':') || (tok.len() >= 10 && tok.as_bytes()[4] == b'-') {
            return err("date/time values are not supported by this config loader");
        }
        // Radix-prefixed integers.
        if let Some(rest) = tok
            .strip_prefix("0x")
            .map(|r| (r, 16))
            .or_else(|| tok.strip_prefix("0o").map(|r| (r, 8)))
            .or_else(|| tok.strip_prefix("0b").map(|r| (r, 2)))
        {
            let (digits, radix) = rest;
            let cleaned: String = digits.chars().filter(|c| *c != '_').collect();
            return i64::from_str_radix(&cleaned, radix)
                .map(Value::Int)
                .map_err(|_| TomlError(format!("invalid integer {tok:?}")));
        }
        let is_float = tok.contains('.')
            || tok.contains('e')
            || tok.contains('E')
            || tok.contains("inf")
            || tok.contains("nan");
        if is_float {
            return pycompat::py_float(&tok)
                .map(Value::Float)
                .map_or_else(|| err(&format!("invalid float {tok:?}")), Ok);
        }
        pycompat::py_int_str(&tok)
            .map(Value::Int)
            .map_or_else(|| err(&format!("invalid integer {tok:?}")), Ok)
    }

    fn parse_value(p: &mut P) -> PResult<Value> {
        p.skip_inline_ws();
        match p.peek() {
            Some('"') => Ok(Value::Str(parse_basic_string(p)?)),
            Some('\'') => Ok(Value::Str(parse_literal_string(p)?)),
            Some('[') => {
                p.i += 1;
                let mut items = Vec::new();
                loop {
                    p.skip_ws_and_comments();
                    if p.peek() == Some(']') {
                        p.i += 1;
                        return Ok(Value::Array(items));
                    }
                    items.push(parse_value(p)?);
                    p.skip_ws_and_comments();
                    match p.peek() {
                        Some(',') => p.i += 1,
                        Some(']') => {
                            p.i += 1;
                            return Ok(Value::Array(items));
                        }
                        other => return err(&format!("expected ',' or ']', found {other:?}")),
                    }
                }
            }
            Some('{') => {
                p.i += 1;
                let mut table: Vec<(String, Value)> = Vec::new();
                p.skip_inline_ws();
                if p.peek() == Some('}') {
                    p.i += 1;
                    return Ok(Value::Table(table));
                }
                loop {
                    let key = parse_key(p)?;
                    p.skip_inline_ws();
                    if p.peek() != Some('=') {
                        return err("expected '=' in an inline table");
                    }
                    p.i += 1;
                    let value = parse_value(p)?;
                    insert_path(&mut table, &key, value)?;
                    p.skip_inline_ws();
                    match p.peek() {
                        Some(',') => {
                            p.i += 1;
                            p.skip_inline_ws();
                        }
                        Some('}') => {
                            p.i += 1;
                            return Ok(Value::Table(table));
                        }
                        other => return err(&format!("expected ',' or '}}', found {other:?}")),
                    }
                }
            }
            _ => parse_number_or_keyword(p),
        }
    }

    /// Insert `value` at the dotted `path` inside `table`.
    fn insert_path(table: &mut Vec<(String, Value)>, path: &[String], value: Value) -> PResult<()> {
        let (head, rest) = path.split_first().expect("a key always has one part");
        if rest.is_empty() {
            if table.iter().any(|(k, _)| k == head) {
                return err(&format!("cannot redefine key {head:?}"));
            }
            table.push((head.clone(), value));
            return Ok(());
        }
        if let Some((_, existing)) = table.iter_mut().find(|(k, _)| k == head) {
            match existing {
                Value::Table(inner) => return insert_path(inner, rest, value),
                _ => return err(&format!("cannot extend non-table key {head:?}")),
            }
        }
        let mut inner = Vec::new();
        insert_path(&mut inner, rest, value)?;
        table.push((head.clone(), Value::Table(inner)));
        Ok(())
    }

    /// Navigate to (creating as needed) the table a `[header]` names.
    fn table_at<'a>(
        root: &'a mut Vec<(String, Value)>,
        path: &[String],
        array: bool,
    ) -> PResult<&'a mut Vec<(String, Value)>> {
        let (head, rest) = path.split_first().expect("a header always has one part");
        if !root.iter().any(|(k, _)| k == head) {
            let fresh = if rest.is_empty() && array {
                Value::Array(vec![Value::Table(Vec::new())])
            } else {
                Value::Table(Vec::new())
            };
            root.push((head.clone(), fresh));
        } else if rest.is_empty() && array {
            let slot = root
                .iter_mut()
                .find(|(k, _)| k == head)
                .map(|(_, v)| v)
                .expect("just checked");
            match slot {
                Value::Array(items) => items.push(Value::Table(Vec::new())),
                _ => return err(&format!("{head:?} is not an array of tables")),
            }
        }
        let slot = root
            .iter_mut()
            .find(|(k, _)| k == head)
            .map(|(_, v)| v)
            .expect("just inserted");
        if rest.is_empty() {
            return match slot {
                Value::Table(t) => Ok(t),
                Value::Array(items) => match items.last_mut() {
                    Some(Value::Table(t)) => Ok(t),
                    _ => err("array of tables holds a non-table"),
                },
                _ => err(&format!("cannot redefine key {head:?} as a table")),
            };
        }
        match slot {
            Value::Table(t) => table_at(t, rest, array),
            Value::Array(items) => match items.last_mut() {
                Some(Value::Table(t)) => table_at(t, rest, array),
                _ => err("array of tables holds a non-table"),
            },
            _ => err(&format!("cannot extend non-table key {head:?}")),
        }
    }

    /// Parse a whole TOML document into a root table.
    ///
    /// # Errors
    /// [`TomlError`] on malformed input (CPython raises `TOMLDecodeError`).
    pub fn parse(text: &str) -> PResult<Value> {
        // A leading BOM is skipped, as `tomllib` does.
        let text = text.strip_prefix('\u{feff}').unwrap_or(text);
        let mut p = P {
            s: text.chars().collect(),
            i: 0,
        };
        let mut root: Vec<(String, Value)> = Vec::new();
        let mut current: Vec<String> = Vec::new();
        loop {
            p.skip_ws_and_comments();
            if p.peek().is_none() {
                return Ok(Value::Table(root));
            }
            if p.peek() == Some('[') {
                let array = p.starts_with("[[");
                p.i += if array { 2 } else { 1 };
                let path = parse_key(&mut p)?;
                p.skip_inline_ws();
                let close = if array { "]]" } else { "]" };
                if !p.starts_with(close) {
                    return err("unterminated table header");
                }
                p.i += close.len();
                p.finish_line()?;
                table_at(&mut root, &path, array)?;
                current = path;
                continue;
            }
            let key = parse_key(&mut p)?;
            p.skip_inline_ws();
            if p.peek() != Some('=') {
                return err("expected '=' after a key");
            }
            p.i += 1;
            let value = parse_value(&mut p)?;
            p.finish_line()?;
            if current.is_empty() {
                insert_path(&mut root, &key, value)?;
            } else {
                let t = table_at(&mut root, &current, false)?;
                insert_path(t, &key, value)?;
            }
        }
    }
}

use toml::Value as Toml;

/// Python `str(v)` for a config value, honouring `x or ""` (falsy → empty).
fn str_or_empty(v: Option<&Toml>) -> String {
    match v {
        Some(v) if v.truthy() => v.py_str(),
        _ => String::new(),
    }
}

/// Python `int(v)`.
fn py_int(v: &Toml, what: &str) -> Result<i64, ConfigError> {
    match v {
        Toml::Int(i) => Ok(*i),
        Toml::Bool(b) => Ok(i64::from(*b)),
        Toml::Float(f) => {
            if f.is_finite() {
                Ok(f.trunc() as i64)
            } else {
                Err(ConfigError(format!(
                    "cannot convert {} float to integer",
                    if f.is_nan() { "NaN" } else { "infinity" }
                )))
            }
        }
        Toml::Str(s) => pycompat::py_int_str(s).ok_or_else(|| {
            ConfigError(format!(
                "invalid literal for int() with base 10: {}",
                Toml::Str(s.clone()).py_repr()
            ))
        }),
        _ => Err(ConfigError(format!("{what} must be an integer"))),
    }
}

/// Python `float(v)`.
fn py_float_val(v: &Toml, what: &str) -> Result<f64, ConfigError> {
    match v {
        Toml::Int(i) => Ok(*i as f64),
        Toml::Bool(b) => Ok(f64::from(u8::from(*b))),
        Toml::Float(f) => Ok(*f),
        Toml::Str(s) => pycompat::py_float(s).ok_or_else(|| {
            ConfigError(format!(
                "could not convert string to float: {}",
                Toml::Str(s.clone()).py_repr()
            ))
        }),
        _ => Err(ConfigError(format!("{what} must be a number"))),
    }
}

fn clamp(v: i64, lo: i64, hi: i64) -> i64 {
    v.max(lo).min(hi)
}

fn as_service(entry: &Toml) -> Result<ServiceConfig, ConfigError> {
    let name = pycompat::strip(&str_or_empty(entry.get("name"))).to_string();
    let base = pycompat::rstrip_chars(pycompat::strip(&str_or_empty(entry.get("base_url"))), "/")
        .to_string();
    if name.is_empty() || base.is_empty() {
        return Err(ConfigError(
            "each [[service]] needs a name and a base_url".to_string(),
        ));
    }
    let keys_val = entry.get("metrics_keys");
    let keys: Vec<String> = match keys_val {
        None => Vec::new(),
        Some(v) if !v.truthy() => Vec::new(),
        Some(Toml::Array(items)) => items.iter().map(Toml::py_str).collect(),
        Some(_) => {
            return Err(ConfigError(format!(
                "metrics_keys must be a list for service {}",
                Toml::Str(name).py_repr()
            )))
        }
    };
    Ok(ServiceConfig {
        name,
        base_url: base,
        health_path: entry
            .get("health_path")
            .map_or_else(|| "/health".to_string(), Toml::py_str),
        metrics_path: entry
            .get("metrics_path")
            .map_or_else(|| "/metrics".to_string(), Toml::py_str),
        metrics_keys: keys,
        label: entry.get("label").map(Toml::py_str).unwrap_or_default(),
    })
}

/// Build one [`AlertRule`] from an `[[alert]]` table (validated).
///
/// `rid` is the already-resolved, guaranteed-unique rule id (see
/// `resolve_rule_ids`); the engine keys per-`(service, rule id)` state on it, so
/// a collision would silently merge two rules into one.
fn as_rule(entry: &Toml, rid: &str) -> Result<AlertRule, ConfigError> {
    let rid_repr = Toml::Str(rid.to_string()).py_repr();
    let kind_raw = entry.get("kind").map_or_else(
        || "metric".to_string(),
        |v| pycompat::strip(&v.py_str()).to_lowercase(),
    );
    let kind = if kind_raw.is_empty() {
        "metric".to_string()
    } else {
        kind_raw
    };
    if kind != "metric" && kind != "down" {
        return Err(ConfigError(format!(
            "alert {rid_repr}: kind must be 'metric' or 'down'"
        )));
    }
    let service_raw = entry.get("service").map_or_else(
        || "*".to_string(),
        |v| pycompat::strip(&v.py_str()).to_string(),
    );
    let service = if service_raw.is_empty() {
        "*".to_string()
    } else {
        service_raw
    };
    let metric = entry
        .get("metric")
        .map_or_else(String::new, |v| pycompat::strip(&v.py_str()).to_string());
    let op = entry.get("op").map_or_else(
        || ">".to_string(),
        |v| pycompat::strip(&v.py_str()).to_string(),
    );
    if kind == "metric" {
        if metric.is_empty() {
            return Err(ConfigError(format!(
                "alert {rid_repr}: a metric rule needs a metric"
            )));
        }
        if !ALLOWED_OPS.contains(&op.as_str()) {
            return Err(ConfigError(format!(
                "alert {rid_repr}: op must be one of {}",
                ALLOWED_OPS.join(", ")
            )));
        }
    }
    let threshold = match entry.get("threshold") {
        None => 0.0,
        Some(v) => py_float_val(v, "threshold")
            .map_err(|_| ConfigError(format!("alert {rid_repr}: threshold must be a number")))?,
    };
    if !threshold.is_finite() {
        return Err(ConfigError(format!(
            "alert {rid_repr}: threshold must be finite"
        )));
    }
    let for_raw = entry.get("for").or_else(|| entry.get("for_polls"));
    let for_polls = match for_raw {
        None => 1,
        Some(v) => py_int(v, "for")
            .map_err(|_| ConfigError(format!("alert {rid_repr}: for must be an integer")))?,
    };
    let for_polls = clamp(for_polls, 1, MAX_FOR_POLLS);
    let severity_raw = entry.get("severity").map_or_else(
        || "warning".to_string(),
        |v| pycompat::strip(&v.py_str()).to_lowercase(),
    );
    let severity = if severity_raw.is_empty() {
        "warning".to_string()
    } else {
        severity_raw
    };
    Ok(AlertRule {
        id: rid.to_string(),
        service,
        kind,
        metric,
        op,
        threshold,
        for_polls,
        severity,
        description: entry
            .get("description")
            .map(Toml::py_str)
            .unwrap_or_default(),
    })
}

/// Assign a unique id to every `[[alert]]` entry.
///
/// Explicit ids must be unique (a duplicate is a hard error). Entries without an
/// id get `rule-<n>` chosen from a set that already contains every explicit id
/// AND every id assigned so far, so an auto-id can never collide with an
/// explicit one — a collision would make the engine merge two rules into one and
/// silently drop an alert.
fn resolve_rule_ids(entries: &[Toml]) -> Result<Vec<String>, ConfigError> {
    let mut explicit: Vec<String> = Vec::new();
    for e in entries {
        let rid = pycompat::strip(&str_or_empty(e.get("id"))).to_string();
        if rid.is_empty() {
            continue;
        }
        if explicit.contains(&rid) {
            return Err(ConfigError(format!(
                "alert {}: duplicate alert id",
                Toml::Str(rid).py_repr()
            )));
        }
        explicit.push(rid);
    }
    let mut used: Vec<String> = explicit.clone();
    let mut ids = Vec::with_capacity(entries.len());
    for (i, e) in entries.iter().enumerate() {
        let mut rid = pycompat::strip(&str_or_empty(e.get("id"))).to_string();
        if rid.is_empty() {
            let mut n = i + 1;
            rid = format!("rule-{n}");
            while used.contains(&rid) {
                n += 1;
                rid = format!("rule-{n}");
            }
            used.push(rid.clone());
        }
        ids.push(rid);
    }
    Ok(ids)
}

/// Validate ids for uniqueness, then build every rule.
fn build_rules(entries: &[Toml]) -> Result<Vec<AlertRule>, ConfigError> {
    let ids = resolve_rule_ids(entries)?;
    entries
        .iter()
        .zip(ids.iter())
        .map(|(e, rid)| as_rule(e, rid))
        .collect()
}

/// Overlay a TOML document (already read into memory) on `base`, or on the
/// defaults — the pure half of [`load_config`].
///
/// Top-level keys (`host`, `port`, `refresh_seconds`, `timeout_seconds`,
/// `max_workers`, `cache_ttl`, the history/alert bounds and `sparklines`)
/// override the matching field. A `[[service]]` array-of-tables, if present,
/// *replaces* the whole service list so the file is the single source of truth
/// for what to poll.
///
/// # Errors
/// [`ConfigError`] when the document is not valid TOML or an entry is invalid.
pub fn parse_config(text: &str, base: Option<Config>) -> Result<Config, ConfigError> {
    let mut cfg = base.unwrap_or_default();
    let data = toml::parse(text).map_err(|e| ConfigError(e.0))?;

    if let Some(v) = data.get("host") {
        cfg.host = v.py_str();
    }
    if let Some(v) = data.get("port") {
        cfg.port = py_int(v, "port")?;
    }
    if let Some(v) = data.get("refresh_seconds") {
        cfg.refresh_seconds = py_int(v, "refresh_seconds")?;
    }
    if let Some(v) = data.get("timeout_seconds") {
        cfg.timeout_seconds = py_float_val(v, "timeout_seconds")?;
    }
    if let Some(v) = data.get("max_workers") {
        cfg.max_workers = py_int(v, "max_workers")?.max(1);
    }
    if let Some(v) = data.get("cache_ttl") {
        cfg.cache_ttl = py_float_val(v, "cache_ttl")?.max(0.0);
    }
    if let Some(v) = data.get("history_capacity") {
        cfg.history_capacity = clamp(
            py_int(v, "history_capacity")?,
            MIN_HISTORY_CAPACITY,
            MAX_HISTORY_CAPACITY,
        );
    }
    if let Some(v) = data.get("history_max_series") {
        cfg.history_max_series = clamp(py_int(v, "history_max_series")?, 1, MAX_HISTORY_SERIES);
    }
    if let Some(v) = data.get("alert_history") {
        cfg.alert_history = clamp(py_int(v, "alert_history")?, 1, MAX_ALERT_HISTORY);
    }
    if let Some(v) = data.get("sparklines") {
        cfg.sparklines = v.truthy();
    }

    if let Some(svc) = data.get("service").filter(|v| v.truthy()) {
        let Some(items) = svc.as_array() else {
            return Err(ConfigError(
                "[[service]] must be an array of tables".to_string(),
            ));
        };
        cfg.services = items.iter().map(as_service).collect::<Result<_, _>>()?;
    }

    if let Some(alerts) = data.get("alert").filter(|v| v.truthy()) {
        let Some(items) = alerts.as_array() else {
            return Err(ConfigError(
                "[[alert]] must be an array of tables".to_string(),
            ));
        };
        // Bounded: never load more than MAX_RULES rules from a file. Ids are
        // resolved to be unique so no two rules can silently collapse in the engine.
        let bounded = &items[..items.len().min(MAX_RULES)];
        cfg.alert_rules = build_rules(bounded)?;
    }
    Ok(cfg)
}

/// Return a [`Config`], overlaying an optional TOML file on defaults.
///
/// `path` of `None` (Python's `load_config(None)`) returns the defaults
/// untouched, without touching the filesystem.
///
/// # Errors
/// [`ConfigError`] when the file cannot be read, is not valid TOML, or holds an
/// invalid entry.
pub fn load_config(path: Option<&str>, base: Option<Config>) -> Result<Config, ConfigError> {
    let cfg = base.unwrap_or_default();
    let Some(path) = path.filter(|p| !p.is_empty()) else {
        return Ok(cfg);
    };
    let text = std::fs::read_to_string(path)
        .map_err(|e| ConfigError(format!("cannot read {path}: {e}")))?;
    parse_config(&text, Some(cfg))
}

/// Override a service's `base_url` from `name=base_url` CLI specs.
///
/// Unknown names are appended as minimal services (health `/health`, metrics
/// `/metrics`, auto-surfaced keys). Handy for quick one-off checks without
/// writing a config file.
///
/// # Errors
/// [`ConfigError`] when a spec is not `name=base_url`.
pub fn apply_service_flags(mut cfg: Config, specs: &[String]) -> Result<Config, ConfigError> {
    for spec in specs {
        let (name, sep, base) = match spec.split_once('=') {
            Some((n, b)) => (n, true, b),
            None => (spec.as_str(), false, ""),
        };
        let name = pycompat::strip(name);
        let base = pycompat::rstrip_chars(pycompat::strip(base), "/");
        if !sep || name.is_empty() || base.is_empty() {
            return Err(ConfigError(format!(
                "--service expects name=base_url, got {}",
                Toml::Str(spec.clone()).py_repr()
            )));
        }
        match cfg.services.iter().position(|s| s.name == name) {
            Some(i) => cfg.services[i] = cfg.services[i].with_base(base),
            None => cfg.services.push(ServiceConfig::new(name, base)),
        }
    }
    Ok(cfg)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn load(text: &str) -> Result<Config, ConfigError> {
        parse_config(text, None)
    }

    #[test]
    fn defaults_are_the_four_suite_services() {
        let cfg = Config::default();
        assert_eq!(cfg.port, 8805);
        assert_eq!(cfg.services.len(), 4);
        assert_eq!(cfg.services[3].metrics_path, "/api/stats");
        assert!(load_config(None, None).unwrap().services.len() == 4);
    }

    #[test]
    fn parses_metric_and_down_rules() {
        let cfg = load(concat!(
            "[[alert]]\nid=\"busy\"\nservice=\"gitweb\"\nmetric=\"m\"\nop=\">=\"\n",
            "threshold=100\nfor=3\nseverity=\"warning\"\n\n",
            "[[alert]]\nid=\"down\"\nkind=\"down\"\nservice=\"*\"\n"
        ))
        .unwrap();
        assert_eq!(cfg.alert_rules.len(), 2);
        let r0 = &cfg.alert_rules[0];
        assert_eq!(
            (r0.id.as_str(), r0.op.as_str(), r0.threshold, r0.for_polls),
            ("busy", ">=", 100.0, 3)
        );
        assert_eq!(cfg.alert_rules[1].kind, "down");
    }

    #[test]
    fn rejects_bad_operator() {
        assert!(load("[[alert]]\nid=\"x\"\nmetric=\"m\"\nop=\"=~\"\nthreshold=1\n").is_err());
    }

    #[test]
    fn rejects_metric_rule_without_metric() {
        assert!(load("[[alert]]\nid=\"x\"\nop=\">\"\nthreshold=1\n").is_err());
    }

    #[test]
    fn for_polls_is_clamped_to_at_least_one() {
        let cfg =
            load("[[alert]]\nid=\"x\"\nmetric=\"m\"\nop=\">\"\nthreshold=1\nfor=0\n").unwrap();
        assert_eq!(cfg.alert_rules[0].for_polls, 1);
    }

    #[test]
    fn rule_count_is_bounded() {
        let mut text = String::new();
        for i in 0..MAX_RULES + 25 {
            text.push_str(&format!(
                "[[alert]]\nid=\"r{i}\"\nmetric=\"m\"\nop=\">\"\nthreshold=1\n\n"
            ));
        }
        assert_eq!(load(&text).unwrap().alert_rules.len(), MAX_RULES);
    }

    #[test]
    fn duplicate_explicit_rule_id_is_rejected() {
        assert!(load(
            "[[alert]]\nid=\"dup\"\nkind=\"down\"\n\n[[alert]]\nid=\"dup\"\nkind=\"down\"\n"
        )
        .is_err());
    }

    #[test]
    fn auto_id_does_not_collide_with_explicit_id() {
        let cfg = load(concat!(
            "[[alert]]\nid=\"rule-2\"\nservice=\"svc\"\nmetric=\"cpu\"\nop=\">\"\nthreshold=90\n\n",
            "[[alert]]\nservice=\"svc\"\nmetric=\"mem\"\nop=\">\"\nthreshold=10\n"
        ))
        .unwrap();
        assert_eq!(cfg.alert_rules.len(), 2);
        assert_ne!(cfg.alert_rules[0].id, cfg.alert_rules[1].id);
        assert_eq!(cfg.alert_rules[1].id, "rule-3");
    }

    #[test]
    fn top_level_settings_override_and_clamp() {
        let cfg = load(concat!(
            "host = \"0.0.0.0\"\nport = 9000\nrefresh_seconds = 0\ntimeout_seconds = 1.5\n",
            "max_workers = 0\ncache_ttl = -2.0\nhistory_capacity = 1\n",
            "history_max_series = 999999999\nalert_history = 0\nsparklines = false\n"
        ))
        .unwrap();
        assert_eq!(cfg.host, "0.0.0.0");
        assert_eq!(cfg.port, 9000);
        assert_eq!(cfg.refresh_seconds, 0);
        assert_eq!(cfg.timeout_seconds, 1.5);
        assert_eq!(cfg.max_workers, 1);
        assert_eq!(cfg.cache_ttl, 0.0);
        assert_eq!(cfg.history_capacity, MIN_HISTORY_CAPACITY);
        assert_eq!(cfg.history_max_series, MAX_HISTORY_SERIES);
        assert_eq!(cfg.alert_history, 1);
        assert!(!cfg.sparklines);
    }

    #[test]
    fn service_array_replaces_the_defaults() {
        let cfg = load(concat!(
            "[[service]]\nname = \"only\"\nbase_url = \"http://x:1/\"\n",
            "metrics_keys = [\"a\", \"b\"]\n"
        ))
        .unwrap();
        assert_eq!(cfg.services.len(), 1);
        assert_eq!(cfg.services[0].base_url, "http://x:1");
        assert_eq!(cfg.services[0].health_path, "/health");
        assert_eq!(cfg.services[0].metrics_keys, vec!["a", "b"]);
    }

    #[test]
    fn service_needs_a_name_and_base_url() {
        assert!(load("[[service]]\nname = \"x\"\n").is_err());
        assert!(load("[[service]]\nbase_url = \"http://x\"\n").is_err());
        assert!(
            load("[[service]]\nname = \"x\"\nbase_url = \"http://x\"\nmetrics_keys = 5\n").is_err()
        );
    }

    #[test]
    fn apply_service_flags_overrides_and_appends() {
        let cfg = apply_service_flags(
            Config::default(),
            &[
                "gitweb=http://10.0.0.5:8801/".to_string(),
                "newsvc=http://h:9/".to_string(),
            ],
        )
        .unwrap();
        assert_eq!(cfg.services[0].base_url, "http://10.0.0.5:8801");
        assert_eq!(cfg.services[0].label, "Read-only git web viewer");
        assert_eq!(cfg.services.len(), 5);
        assert_eq!(cfg.services[4].name, "newsvc");
        assert_eq!(cfg.services[4].health_path, "/health");
        assert!(apply_service_flags(Config::default(), &["oops".to_string()]).is_err());
    }

    #[test]
    fn toml_subset_parses_the_shipped_example_shapes() {
        let doc = toml::parse(concat!(
            "# comment\nhost = \"127.0.0.1\"  # trailing\nport = 8805\n",
            "timeout_seconds = 3.0\nsparklines = true\nempty = []\n",
            "multi = [\n  \"a\",\n  \"b\",  # note\n]\n",
            "inline = { x = 1, y = \"z\" }\n[a.b]\nc = 'lit\\n'\n",
            "[[t]]\nk = 1\n[[t]]\nk = 2\n"
        ))
        .unwrap();
        assert_eq!(doc.get("port"), Some(&Toml::Int(8805)));
        assert_eq!(doc.get("timeout_seconds"), Some(&Toml::Float(3.0)));
        assert_eq!(doc.get("sparklines"), Some(&Toml::Bool(true)));
        assert_eq!(doc.get("empty"), Some(&Toml::Array(vec![])));
        assert_eq!(
            doc.get("multi").and_then(Toml::as_array).map(<[Toml]>::len),
            Some(2)
        );
        assert_eq!(
            doc.get("inline").and_then(|v| v.get("y")),
            Some(&Toml::Str("z".to_string()))
        );
        assert_eq!(
            doc.get("a")
                .and_then(|v| v.get("b"))
                .and_then(|v| v.get("c")),
            Some(&Toml::Str("lit\\n".to_string()))
        );
        let t = doc.get("t").and_then(Toml::as_array).unwrap();
        assert_eq!(t.len(), 2);
        assert_eq!(t[1].get("k"), Some(&Toml::Int(2)));
    }

    #[test]
    fn toml_rejects_malformed_documents() {
        assert!(toml::parse("port = ").is_err());
        assert!(toml::parse("port 8805\n").is_err());
        assert!(toml::parse("a = \"unterminated\n").is_err());
        assert!(toml::parse("[unclosed\n").is_err());
        assert!(toml::parse("a = 1\na = 2\n").is_err());
        assert!(toml::parse("when = 1979-05-27T07:32:00Z\n").is_err());
    }

    /// A malformed config must be a clean [`ConfigError`], never a panic or a
    /// hang. Deterministic soup drawn from TOML's own metacharacters (an LCG, so
    /// no dependency and no flake).
    #[test]
    fn hostile_documents_never_panic() {
        let alphabet: Vec<char> = "ab[]{}\"'=.,# \t\n\r019+-_eE\\:élinf".chars().collect();
        let mut seed: u64 = 0x9e37_79b9_7f4a_7c15;
        let mut next = || {
            seed = seed.wrapping_mul(6_364_136_223_846_793_005).wrapping_add(1);
            (seed >> 33) as usize
        };
        for _ in 0..500 {
            let len = next() % 90;
            let text: String = (0..len)
                .map(|_| alphabet[next() % alphabet.len()])
                .collect();
            let _ = parse_config(&text, None);
        }
    }
}
