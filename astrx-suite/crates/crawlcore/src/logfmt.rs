//! Structured log lines: the `--log-format=json` half of every engine's logging.
//!
//! Every engine logs prose to stderr, which is exactly right for a person
//! watching one server and useless the moment the logs are shipped somewhere and
//! queried during an incident. This module is the other mode: **one JSON object
//! per line**, with a machine-readable timestamp, level, engine name, event
//! name, and the request fields when there is a request.
//!
//! # Escaping is the whole point
//!
//! A log line is attacker-influenced — the request path is whatever the peer
//! sent — and the suite has already been bitten once by this: a bare `\n` in a
//! request target used to survive `suitedash`'s request-line split and reach
//! `eprintln!`, forging a second, entirely attacker-written line in the request
//! log (see the regression test in `suitedash::server`). A JSON line that
//! embeds an unescaped newline, quote or control byte has the same defect with a
//! machine reading it instead of a person: the forged content parses as a real
//! event, with a timestamp and a level of the attacker's choosing.
//!
//! [`escape_into`] therefore escapes `"` and `\`, and **every** byte below
//! 0x20 — not just the ones with short forms. Non-ASCII is emitted as raw UTF-8,
//! which RFC 8259 permits and every log shipper expects; escaping it would only
//! make the lines unreadable.
//!
//! There is no JSON *writer* elsewhere in the suite ([`crate::json`] parses
//! only), and the output here is round-tripped through that parser in the tests,
//! so the two halves cannot disagree about what valid JSON is.

use std::fmt::Write as _;
use std::sync::atomic::{AtomicU8, Ordering};
use std::time::{SystemTime, UNIX_EPOCH};

/// How an engine should write its logs.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum Format {
    /// Human-readable prose on stderr. The default, byte-identical to what each
    /// engine printed before this module existed.
    #[default]
    Text,
    /// One JSON object per line.
    Json,
}

impl Format {
    /// Parse a `--log-format` value.
    ///
    /// # Errors
    /// Any value other than `text` or `json`, with a message that names both —
    /// a typo'd `--log-format=jsonl` must not silently fall back to text and
    /// leave an operator wondering why their log pipeline is empty.
    pub fn parse(s: &str) -> Result<Format, String> {
        match s {
            "text" => Ok(Format::Text),
            "json" => Ok(Format::Json),
            other => Err(format!(
                "error: --log-format expects 'text' or 'json', got {other:?}"
            )),
        }
    }

    /// `true` when JSON lines should be emitted.
    #[must_use]
    pub fn is_json(self) -> bool {
        matches!(self, Format::Json)
    }
}

/// The process-wide format, as an `AtomicU8` (`0` text, `1` json).
static FORMAT: AtomicU8 = AtomicU8::new(0);

/// Set the format for this process. Called once, from an engine's `cli::run`,
/// before any server starts.
///
/// Process-global rather than a field threaded through every `Config`: the
/// alternative is a new parameter on five servers' public constructors and every
/// `serve*` signature, in a codebase whose server behaviour is pinned by
/// byte-identity tests. The format is one operator-level choice for the whole
/// process — there is no case where two servers in one process should disagree
/// about how to encode their logs — so a single switch models it correctly.
pub fn set_format(f: Format) {
    FORMAT.store(u8::from(f.is_json()), Ordering::Relaxed);
}

/// The format this process logs in. Defaults to [`Format::Text`], so a process
/// that never calls [`set_format`] (an embedder, a test) logs exactly as it did
/// before this module existed.
#[must_use]
pub fn format() -> Format {
    if FORMAT.load(Ordering::Relaxed) == 0 {
        Format::Text
    } else {
        Format::Json
    }
}

/// Whether per-request access lines should be written at all.
static ACCESS_LOG: AtomicU8 = AtomicU8::new(0);

/// Turn per-request access logging on or off for this process.
///
/// Set from an engine's `--verbose`. `gitweb` and `suitedash` predate this and
/// keep their own `config.verbose` field, which already defaults to on; the
/// three engines that had no access log at all (`websearch`, `onioncrawler`,
/// `torrentds`) use this, defaulting to **off** so their stderr is byte-identical
/// to before unless an operator asks for the log.
pub fn set_access_log(on: bool) {
    ACCESS_LOG.store(u8::from(on), Ordering::Relaxed);
}

/// Whether this process writes access lines. Defaults to `false`.
#[must_use]
pub fn access_log() -> bool {
    ACCESS_LOG.load(Ordering::Relaxed) != 0
}

/// Take a `--log-format` flag out of `argv`, returning it and the rest.
///
/// Handled before the per-engine parser rather than added to each subcommand,
/// so `--log-format=json` works on every subcommand of every engine with one
/// call site each, and an operator does not have to remember which of the
/// suite's twenty-odd subcommands happen to support it.
///
/// # Errors
/// A missing or unrecognised value, as a message ready for stderr + exit 2.
pub fn take_format_flag(argv: Vec<String>) -> Result<(Format, Vec<String>), String> {
    let mut fmt = Format::Text;
    let mut rest = Vec::with_capacity(argv.len());
    let mut i = 0;
    while i < argv.len() {
        let tok = &argv[i];
        if let Some(v) = tok.strip_prefix("--log-format=") {
            fmt = Format::parse(v)?;
        } else if tok == "--log-format" {
            i += 1;
            let v = argv
                .get(i)
                .ok_or_else(|| "error: option --log-format requires a value".to_string())?;
            fmt = Format::parse(v)?;
        } else {
            rest.push(tok.clone());
        }
        i += 1;
    }
    Ok((fmt, rest))
}

/// The `--log-format` line for an engine's `--help`, indented to match the
/// suite's option blocks.
pub const HELP_LINE: &str =
    "  --log-format FMT        log as 'text' (default, human) or 'json' (one object per line)\n";

/// Severity, as the `level` field.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Level {
    /// Routine progress (an access line, a bind).
    Info,
    /// Something recoverable that an operator should see.
    Warn,
    /// A failure.
    Error,
}

impl Level {
    /// The lowercase token written to the `level` field.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Level::Info => "info",
            Level::Warn => "warn",
            Level::Error => "error",
        }
    }
}

/// Append `s` to `out` as a quoted JSON string, escaped.
///
/// Escapes `"`, `\` and every C0 control byte (U+0000..=U+001F), using the
/// two-character forms where RFC 8259 defines them and `\u00XX` otherwise. Bytes
/// at or above 0x20 that are not `"` or `\` — including all non-ASCII — are
/// copied through unchanged.
pub fn escape_into(out: &mut String, s: &str) {
    out.push('"');
    for c in s.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            // Everything else below 0x20 has no short form and MUST NOT be
            // emitted raw: a literal 0x00..0x1F inside a JSON string is a parse
            // error in a strict reader and a truncation point in a sloppy one,
            // and either way the rest of the line is attacker-controlled.
            c if (c as u32) < 0x20 => {
                let _ = write!(out, "\\u{:04x}", c as u32);
            }
            c => out.push(c),
        }
    }
    out.push('"');
}

/// [`escape_into`] into a fresh `String`.
#[must_use]
pub fn escape(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    escape_into(&mut out, s);
    out
}

/// A JSON log line under construction.
///
/// Fields are written in the order they are added, after the fixed
/// `timestamp`/`level`/`engine`/`event` header, so every line from a given call
/// site has the same shape.
#[derive(Clone, Debug)]
pub struct Line {
    buf: String,
}

impl Line {
    /// Start a line, stamping the current time.
    #[must_use]
    pub fn new(level: Level, engine: &str, event: &str) -> Self {
        Self::at(now_secs(), level, engine, event)
    }

    /// [`Line::new`] with an explicit epoch-seconds timestamp — the constructor
    /// the tests use, so a golden line does not depend on the wall clock.
    #[must_use]
    pub fn at(epoch_secs: f64, level: Level, engine: &str, event: &str) -> Self {
        let mut buf = String::with_capacity(192);
        buf.push_str("{\"timestamp\":");
        escape_into(&mut buf, &rfc3339_utc(epoch_secs));
        buf.push_str(",\"level\":\"");
        buf.push_str(level.as_str());
        buf.push_str("\",\"engine\":");
        escape_into(&mut buf, engine);
        buf.push_str(",\"event\":");
        escape_into(&mut buf, event);
        Line { buf }
    }

    /// Add a string field. Both key and value are escaped.
    #[must_use]
    pub fn str(mut self, key: &str, value: &str) -> Self {
        self.buf.push(',');
        escape_into(&mut self.buf, key);
        self.buf.push(':');
        escape_into(&mut self.buf, value);
        self
    }

    /// Add a string field only when `value` is non-empty, so an absent peer or
    /// action does not become `""` in every line.
    #[must_use]
    pub fn str_opt(self, key: &str, value: &str) -> Self {
        if value.is_empty() {
            self
        } else {
            self.str(key, value)
        }
    }

    /// Add an integer field.
    #[must_use]
    pub fn int(mut self, key: &str, value: i64) -> Self {
        self.buf.push(',');
        escape_into(&mut self.buf, key);
        let _ = write!(self.buf, ":{value}");
        self
    }

    /// Add a number field, rounded to `decimals` places.
    ///
    /// A non-finite value is written as `null`: JSON has no `NaN`/`Infinity`
    /// literal, and emitting one produces a line that a strict consumer rejects
    /// wholesale — losing the event that was interesting enough to have a broken
    /// number in it.
    #[must_use]
    pub fn num(mut self, key: &str, value: f64, decimals: usize) -> Self {
        self.buf.push(',');
        escape_into(&mut self.buf, key);
        if value.is_finite() {
            let _ = write!(self.buf, ":{value:.decimals$}");
        } else {
            self.buf.push_str(":null");
        }
        self
    }

    /// Add a boolean field.
    #[must_use]
    pub fn bool(mut self, key: &str, value: bool) -> Self {
        self.buf.push(',');
        escape_into(&mut self.buf, key);
        let _ = write!(self.buf, ":{value}");
        self
    }

    /// Close the object. The result contains no newline — the caller's
    /// `eprintln!`/`println!` supplies exactly one, so a line is a line.
    #[must_use]
    pub fn finish(mut self) -> String {
        self.buf.push('}');
        self.buf
    }
}

/// The request fields shared by every engine's access log.
#[derive(Clone, Copy, Debug, Default)]
pub struct Request<'a> {
    /// HTTP method as received.
    pub method: &'a str,
    /// Request target as received — attacker-controlled, never pre-sanitised.
    pub path: &'a str,
    /// Response status.
    pub status: u16,
    /// Handling time in milliseconds.
    pub duration_ms: f64,
    /// Peer address, or empty when the server does not track one.
    pub peer: &'a str,
    /// Resolved route/action, or empty.
    pub action: &'a str,
}

/// One access-log line in JSON, with the engine's name and the request fields.
///
/// The field names (`method`, `path`, `status`, `duration_ms`, `peer`) are the
/// same for all five engines on purpose: one query answers "show me every 5xx in
/// the suite in the last hour", which is the question that gets asked first.
#[must_use]
pub fn request_line(engine: &str, req: &Request<'_>) -> String {
    request_line_at(now_secs(), engine, req)
}

/// [`request_line`] with an explicit timestamp (for tests).
#[must_use]
pub fn request_line_at(epoch_secs: f64, engine: &str, req: &Request<'_>) -> String {
    let level = if req.status >= 500 {
        Level::Error
    } else {
        Level::Info
    };
    Line::at(epoch_secs, level, engine, "request")
        .str("method", req.method)
        .str("path", req.path)
        .int("status", i64::from(req.status))
        .num("duration_ms", req.duration_ms, 1)
        .str_opt("peer", req.peer)
        .str_opt("action", req.action)
        .finish()
}

/// One request rendered as a **text** access line, in the shape `gitweb` and
/// `suitedash` already use.
///
/// The path is passed through [`safe_token`] first. A request target is
/// attacker-controlled, and this suite has already shipped the bug where a bare
/// `\n` in one forged a second, entirely attacker-written line in the request
/// log; the same input must not be able to do it through this function.
#[must_use]
pub fn request_text(req: &Request<'_>) -> String {
    let action = if req.action.is_empty() {
        "-"
    } else {
        req.action
    };
    let peer = if req.peer.is_empty() { "-" } else { req.peer };
    format!(
        "method={} path=\"{}\" status={} action={action} dur_ms={:.1} client={peer}",
        safe_token(req.method),
        safe_token(req.path),
        req.status,
        req.duration_ms
    )
}

/// Replace anything in `s` that could break a single-line text log — every C0
/// control byte, DEL, and the double quote that delimits the field — with `?`.
///
/// Deliberately lossy and deliberately not an escape: a text log is read by a
/// person, and `path="/a?b"` staying one line matters more than being able to
/// reconstruct the exact bytes. The JSON format is the one that round-trips.
#[must_use]
pub fn safe_token(s: &str) -> String {
    s.chars()
        .map(|c| {
            if (c as u32) < 0x20 || c == '\u{7f}' || c == '"' {
                '?'
            } else {
                c
            }
        })
        .collect()
}

/// Emit one access line on stderr in this process's configured format, or
/// nothing when access logging is off.
///
/// The single call site for the three engines (`websearch`, `onioncrawler`,
/// `torrentds`) that had no access log at all before. `gitweb` and `suitedash`
/// keep their own text line, whose exact bytes are part of their ported
/// behaviour, and call [`request_line`] directly for the JSON side.
pub fn access(engine: &str, req: &Request<'_>) {
    if !access_log() {
        return;
    }
    if format().is_json() {
        eprintln!("{}", request_line(engine, req));
    } else {
        eprintln!("{}", request_text(req));
    }
}

/// Epoch seconds now.
fn now_secs() -> f64 {
    match SystemTime::now().duration_since(UNIX_EPOCH) {
        Ok(d) => d.as_secs_f64(),
        Err(e) => -e.duration().as_secs_f64(),
    }
}

/// Epoch seconds → `YYYY-MM-DDTHH:MM:SS.mmmZ` (UTC).
///
/// UTC and millisecond precision, unconditionally. A log shipper correlating
/// five engines across three machines cannot do it if each stamps local time
/// with a different offset, and second precision is too coarse to order the
/// requests inside one incident.
#[must_use]
pub fn rfc3339_utc(epoch_secs: f64) -> String {
    let millis_total = (epoch_secs * 1000.0).floor();
    // A clock before 1970, or a NaN from a broken source, must not panic a log
    // call — the logger runs on the request path.
    if !millis_total.is_finite() {
        return "1970-01-01T00:00:00.000Z".to_string();
    }
    let millis_total = millis_total as i64;
    let (secs, millis) = (millis_total.div_euclid(1000), millis_total.rem_euclid(1000));
    let days = secs.div_euclid(86_400);
    let tod = secs.rem_euclid(86_400);
    let (y, m, d) = civil_from_days(days);
    format!(
        "{y:04}-{m:02}-{d:02}T{:02}:{:02}:{:02}.{millis:03}Z",
        tod / 3600,
        (tod % 3600) / 60,
        tod % 60
    )
}

/// Days since the Unix epoch → `(year, month, day)` — Howard Hinnant's
/// `civil_from_days`, the same algorithm `onioncrawler`'s CLI already uses for
/// its date formatting.
fn civil_from_days(z: i64) -> (i64, i64, i64) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { y + 1 } else { y }, m, d)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::json::{parse, Value};

    /// The string value stored at `key` in a rendered line, via the crate's own
    /// parser — so the test asserts the line is *parseable* JSON, not merely
    /// that it looks right.
    fn field(line: &str, key: &str) -> Value {
        let Ok(Value::Object(obj)) = parse(line) else {
            panic!("not a JSON object: {line}");
        };
        obj.iter()
            .find(|(k, _)| k == key)
            .map(|(_, v)| v.clone())
            .unwrap_or_else(|| panic!("no field {key} in {line}"))
    }

    fn s(line: &str, key: &str) -> String {
        match field(line, key) {
            Value::Str(v) => v,
            other => panic!("field {key} is {other:?}"),
        }
    }

    #[test]
    fn a_path_containing_a_newline_cannot_forge_a_second_log_line() {
        // The exact attack the suite already fixed once for the prose logs: a
        // bare newline in the target. In JSON it must become `\n` inside the
        // string, leaving exactly one line on the wire.
        let req = Request {
            method: "GET",
            path: "/x\n{\"timestamp\":\"1970-01-01T00:00:00.000Z\",\"level\":\"info\",\"event\":\"forged\"}",
            status: 404,
            duration_ms: 1.25,
            peer: "10.0.0.1:5555",
            action: "search",
        };
        let line = request_line_at(1_700_000_000.0, "websearch", &req);
        assert_eq!(line.lines().count(), 1, "{line}");
        assert!(!line.contains('\n'));
        // And it round-trips: the forged text is *data*, not structure.
        assert_eq!(s(&line, "path"), req.path);
        assert_eq!(s(&line, "event"), "request");
    }

    #[test]
    fn quotes_backslashes_and_control_characters_all_round_trip() {
        let nasty = "a\"b\\c\nd\re\tf\u{0}g\u{1}h\u{1f}i\u{7f}j";
        let line = Line::at(0.0, Level::Info, "gitweb", "t")
            .str("v", nasty)
            .finish();
        assert_eq!(s(&line, "v"), nasty);
        // The mandatory escapes are present in the *wire* form, not just after
        // parsing: no raw control byte survives into the line.
        assert!(!line.chars().any(|c| (c as u32) < 0x20), "{line:?}");
        assert!(line.contains("\\u0000"), "{line}");
        assert!(line.contains("\\u001f"), "{line}");
        assert!(line.contains("\\\""), "{line}");
        assert!(line.contains("\\\\"), "{line}");
    }

    #[test]
    fn non_ascii_is_kept_as_utf8_rather_than_escaped() {
        // Escaping non-ASCII is legal but makes every log line with a real URL
        // in it unreadable; RFC 8259 allows raw UTF-8 and every shipper handles
        // it.
        let path = "/søk?q=café+日本語+🕵&x=Ω";
        let line = Line::at(0.0, Level::Info, "websearch", "request")
            .str("path", path)
            .finish();
        assert!(line.contains("café"), "{line}");
        assert!(line.contains("日本語"), "{line}");
        assert_eq!(s(&line, "path"), path);
    }

    #[test]
    fn a_hostile_key_is_escaped_too() {
        // Keys go through the same escaper as values: a caller that ever builds
        // a key from request data must not be able to break the object.
        let line = Line::at(0.0, Level::Info, "e", "t")
            .str("a\":1,\"b", "v")
            .finish();
        assert_eq!(s(&line, "a\":1,\"b"), "v");
    }

    #[test]
    fn an_engine_or_event_name_is_escaped_as_well() {
        let line = Line::at(0.0, Level::Info, "en\"gine", "ev\nent").finish();
        assert_eq!(s(&line, "engine"), "en\"gine");
        assert_eq!(s(&line, "event"), "ev\nent");
    }

    #[test]
    fn the_fixed_header_fields_are_always_present_and_in_order() {
        let line = Line::at(1_700_000_000.5, Level::Warn, "torrentds", "bind").finish();
        assert!(
            line.starts_with(
                "{\"timestamp\":\"2023-11-14T22:13:20.500Z\",\"level\":\"warn\",\
                 \"engine\":\"torrentds\",\"event\":\"bind\""
            ),
            "{line}"
        );
    }

    #[test]
    fn a_5xx_is_logged_at_error_level_so_it_can_be_alerted_on() {
        let mk = |status| {
            let req = Request {
                method: "GET",
                path: "/",
                status,
                duration_ms: 0.0,
                peer: "1.2.3.4:1",
                action: "",
            };
            s(&request_line_at(0.0, "e", &req), "level")
        };
        assert_eq!(mk(200), "info");
        assert_eq!(mk(404), "info");
        assert_eq!(mk(500), "error");
        assert_eq!(mk(503), "error");
    }

    #[test]
    fn empty_optional_fields_are_omitted_not_blank() {
        let req = Request {
            method: "GET",
            path: "/",
            status: 200,
            duration_ms: 0.0,
            peer: "",
            action: "",
        };
        let line = request_line_at(0.0, "e", &req);
        assert!(!line.contains("\"peer\""), "{line}");
        assert!(!line.contains("\"action\""), "{line}");
    }

    #[test]
    fn a_non_finite_number_becomes_null_rather_than_invalid_json() {
        for bad in [f64::NAN, f64::INFINITY, f64::NEG_INFINITY] {
            let line = Line::at(0.0, Level::Info, "e", "t")
                .num("d", bad, 3)
                .finish();
            assert_eq!(field(&line, "d"), Value::Null, "{line}");
        }
        // Finite values round through `{:.N}`, which rounds halfway cases to
        // even (1.25 -> 1.2) — the same rule the engines' existing `{:.1}` text
        // access lines already use, so the two formats never disagree.
        let line = Line::at(0.0, Level::Info, "e", "t")
            .num("d", 1.25, 1)
            .finish();
        assert_eq!(field(&line, "d"), Value::Num(1.2));
        let line = Line::at(0.0, Level::Info, "e", "t")
            .num("d", 1.26, 1)
            .finish();
        assert_eq!(field(&line, "d"), Value::Num(1.3));
    }

    #[test]
    fn timestamps_are_utc_and_millisecond_precise() {
        assert_eq!(rfc3339_utc(0.0), "1970-01-01T00:00:00.000Z");
        assert_eq!(rfc3339_utc(1_700_000_000.0), "2023-11-14T22:13:20.000Z");
        assert_eq!(rfc3339_utc(1_700_000_000.123), "2023-11-14T22:13:20.123Z");
        // A leap-day date, and a pre-epoch time (a machine with a wrong clock
        // must still produce a parseable line rather than panicking).
        assert_eq!(rfc3339_utc(1_709_164_800.0), "2024-02-29T00:00:00.000Z");
        assert_eq!(rfc3339_utc(-1.0), "1969-12-31T23:59:59.000Z");
        assert_eq!(rfc3339_utc(f64::NAN), "1970-01-01T00:00:00.000Z");
    }

    #[test]
    fn the_format_flag_rejects_anything_it_does_not_implement() {
        assert_eq!(Format::parse("text"), Ok(Format::Text));
        assert_eq!(Format::parse("json"), Ok(Format::Json));
        assert!(Format::default() == Format::Text);
        for bad in ["jsonl", "JSON", "logfmt", "", "text "] {
            let err = Format::parse(bad).unwrap_err();
            assert!(err.contains("'text' or 'json'"), "{err}");
        }
    }

    #[test]
    fn the_text_access_line_cannot_be_broken_by_a_hostile_path_either() {
        // The text format is read by a person and cannot escape, so it
        // substitutes rather than encodes — but it must still be exactly one
        // line, and the quote that delimits `path="…"` must not appear inside it.
        let req = Request {
            method: "GET",
            path: "/a\nmethod=GET path=\"/forged\" status=200",
            status: 200,
            duration_ms: 2.0,
            peer: "10.0.0.1:9",
            action: "search",
        };
        let line = request_text(&req);
        assert_eq!(line.lines().count(), 1, "{line}");
        assert_eq!(line.matches('"').count(), 2, "{line}");
        assert!(
            line.contains("path=\"/a?method=GET path=?/forged?"),
            "{line}"
        );
    }

    #[test]
    fn the_text_access_line_keeps_the_shape_gitweb_established() {
        let req = Request {
            method: "GET",
            path: "/repo/log",
            status: 200,
            duration_ms: 12.34,
            peer: "127.0.0.1:5555",
            action: "log",
        };
        assert_eq!(
            request_text(&req),
            "method=GET path=\"/repo/log\" status=200 action=log dur_ms=12.3 client=127.0.0.1:5555"
        );
        // Missing peer/action render as `-`, not as an empty gap that shifts
        // every following field for anyone parsing the line with `awk`.
        let bare = Request {
            method: "GET",
            path: "/",
            status: 404,
            duration_ms: 0.0,
            peer: "",
            action: "",
        };
        assert_eq!(
            request_text(&bare),
            "method=GET path=\"/\" status=404 action=- dur_ms=0.0 client=-"
        );
    }

    #[test]
    fn safe_token_replaces_only_what_would_break_a_line() {
        assert_eq!(safe_token("/a/b?c=d&e=f"), "/a/b?c=d&e=f");
        assert_eq!(safe_token("/café/日本"), "/café/日本");
        assert_eq!(safe_token("a\nb\rc\td"), "a?b?c?d");
        assert_eq!(safe_token("a\u{0}b\u{1f}c\u{7f}d"), "a?b?c?d");
        assert_eq!(safe_token("a\"b"), "a?b");
    }

    #[test]
    fn the_format_flag_is_taken_out_of_argv_in_both_spellings() {
        let v = |a: &[&str]| a.iter().map(|s| (*s).to_string()).collect::<Vec<_>>();

        let (f, rest) =
            take_format_flag(v(&["serve", "--log-format=json", "--port", "1"])).unwrap();
        assert_eq!(f, Format::Json);
        assert_eq!(rest, v(&["serve", "--port", "1"]));

        let (f, rest) =
            take_format_flag(v(&["serve", "--log-format", "json", "--port", "1"])).unwrap();
        assert_eq!(f, Format::Json);
        assert_eq!(rest, v(&["serve", "--port", "1"]));

        // Absent: text, and argv is untouched — every existing invocation keeps
        // reaching the engine's parser exactly as it did.
        let args = v(&["crawl", "--db", "web.db", "https://a.example/"]);
        let (f, rest) = take_format_flag(args.clone()).unwrap();
        assert_eq!(f, Format::Text);
        assert_eq!(rest, args);

        // A value that looks like a flag is still consumed as the value, so the
        // error names --log-format rather than the next flag.
        assert!(take_format_flag(v(&["--log-format", "--port"])).is_err());
        assert!(take_format_flag(v(&["--log-format"])).is_err());
        assert!(take_format_flag(v(&["--log-format=yaml"])).is_err());
    }

    #[test]
    fn a_value_that_merely_contains_the_flag_name_is_not_stripped() {
        let v = |a: &[&str]| a.iter().map(|s| (*s).to_string()).collect::<Vec<_>>();
        // A seed URL or a query containing the string must survive intact.
        let args = v(&["crawl", "https://e.example/?x=--log-format=json"]);
        let (f, rest) = take_format_flag(args.clone()).unwrap();
        assert_eq!(f, Format::Text);
        assert_eq!(rest, args);
    }

    #[test]
    fn every_field_type_lands_in_a_parseable_object() {
        let line = Line::at(0.0, Level::Error, "e", "t")
            .str("s", "v")
            .int("i", -7)
            .num("f", 2.5, 2)
            .bool("b", true)
            .finish();
        assert_eq!(field(&line, "i"), Value::Int(-7));
        assert_eq!(field(&line, "f"), Value::Num(2.5));
        assert_eq!(field(&line, "b"), Value::Bool(true));
        assert_eq!(s(&line, "s"), "v");
    }
}
