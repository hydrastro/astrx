//! Structured-data helpers for the media verticals + SPA recovery — the pure
//! building blocks of the Python `websearch.htmlparse`'s stage-2 harvesting.
//!
//! Two groups, both pure and cross-checked byte-identical to Python
//! (`tests/xcheck_structured.rs`):
//! - **video/media classification** — [`parse_duration`] (ISO-8601 → seconds),
//!   [`classify_player`] (a known-player `<iframe src>` → `(player, watch_url)`),
//!   and [`is_direct_media`] (a URL naming a media file);
//! - **JSON-LD / inline-state walkers** over [`crawlcore::json::Value`] —
//!   [`first_str`], [`first_url`], [`type_of`], [`iter_dicts`],
//!   [`collect_readable`], [`balanced_json`], and [`extract_state_json`].

use crawlcore::json::Value;
use crawlcore::urlparse::{host_port, urlsplit};

// ---- bounds (mirror the Python `_JSON_MAX_*` / recovery caps) ---------------

const JSON_MAX_NODES: usize = 20000;
const JSON_MAX_DEPTH: usize = 64;
const JSON_STR_KEEP: usize = 2000;
const RECOVER_BODY_MAX: usize = 8 * 1024;
const MAX_BLOB_BYTES: usize = 512 * 1024;

/// Keys whose string values are human-readable enough to recover into a body.
const READABLE_KEYS: &[&str] = &[
    "title",
    "headline",
    "description",
    "name",
    "subtitle",
    "summary",
    "caption",
    "text",
    "body",
    "articlebody",
    "content",
    "snippet",
    "abstract",
];

/// Inline global-state variable markers whose JSON payload is recovered.
const STATE_MARKERS: &[&str] = &[
    "__INITIAL_STATE__",
    "__NUXT__",
    "__APOLLO_STATE__",
    "__PRELOADED_STATE__",
];

/// Direct-media link extensions that name a video resource.
const MEDIA_EXT: &[&str] = &[".mp4", ".webm", ".ogv", ".mov", ".m3u8", ".mpd"];

/// One harvested video signal (URLs are resolved / dropped by the crawler).
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Video {
    /// A direct media URL.
    pub video_url: String,
    /// An embed (player iframe) URL.
    pub embed_url: String,
    /// A canonical watch URL.
    pub watch_url: String,
    /// A title.
    pub title: String,
    /// A thumbnail URL.
    pub thumbnail: String,
    /// The signal source (`html5` / `direct` / `youtube` / `ld-json` / …).
    pub source: String,
    /// Duration in whole seconds, if known.
    pub duration: Option<i64>,
    /// Nearby context text.
    pub context: String,
}

// ---- ISO-8601 duration -----------------------------------------------------

/// Parse an ISO-8601 duration (e.g. `PT1H2M3S`) into whole seconds, or `None`.
/// Accepts weeks/days/hours/minutes/(fractional) seconds; `P`/`PT` with no
/// component → `None`. Mirrors the Python `parse_duration` (linear, no
/// catastrophic backtracking).
#[must_use]
pub fn parse_duration(value: &str) -> Option<i64> {
    let up: Vec<char> = value.trim().to_uppercase().chars().collect();
    let n = up.len();
    if up.first() != Some(&'P') {
        return None;
    }
    let mut i = 1;
    let mut got = false;
    let mut total = 0.0_f64;

    // Read a number then an expected unit; advance only on a match. Mirrors the
    // regex groups exactly: an integer part of >=1 digit, and (only when `dot`) an
    // optional fractional part of `.` followed by >=1 digit — so `1.`, `.5`, and
    // `1.2.3` are rejected just as the Python `\d+(?:\.\d+)?` would reject them.
    let read = |chars: &[char], at: usize, unit: char, dot: bool| -> Option<(f64, usize)> {
        let mut j = at;
        while j < chars.len() && chars[j].is_ascii_digit() {
            j += 1;
        }
        if j == at {
            return None; // integer part requires at least one digit
        }
        if dot && chars.get(j) == Some(&'.') {
            let mut k = j + 1;
            while k < chars.len() && chars[k].is_ascii_digit() {
                k += 1;
            }
            if k > j + 1 {
                j = k; // consume `.` + fractional digits only if >=1 digit follows
            }
        }
        if chars.get(j) != Some(&unit) {
            return None;
        }
        let s: String = chars[at..j].iter().collect();
        s.parse::<f64>().ok().map(|v| (v, j + 1))
    };

    if let Some((w, ni)) = read(&up, i, 'W', false) {
        total += w * 604_800.0;
        i = ni;
        got = true;
    }
    if let Some((d, ni)) = read(&up, i, 'D', false) {
        total += d * 86_400.0;
        i = ni;
        got = true;
    }
    if up.get(i) == Some(&'T') {
        i += 1;
        if let Some((h, ni)) = read(&up, i, 'H', false) {
            total += h * 3600.0;
            i = ni;
            got = true;
        }
        if let Some((m, ni)) = read(&up, i, 'M', false) {
            total += m * 60.0;
            i = ni;
            got = true;
        }
        if let Some((s, ni)) = read(&up, i, 'S', true) {
            total += s;
            i = ni;
            got = true;
        }
    }
    if i != n || !got {
        return None;
    }
    Some(round_half_even(total))
}

/// Round to nearest, ties to even (Python's `round`), for non-negative input.
fn round_half_even(x: f64) -> i64 {
    let floor = x.floor();
    let frac = x - floor;
    if (frac - 0.5).abs() < f64::EPSILON {
        let fl = floor as i64;
        if fl % 2 == 0 {
            fl
        } else {
            fl + 1
        }
    } else {
        x.round() as i64
    }
}

// ---- known video players ---------------------------------------------------

fn hostname(url: &str) -> String {
    host_port(&urlsplit(url, "").netloc).0
}

/// Mirror a regex `<marker>(<pred>{min,})` `.search`: scan every occurrence of
/// `marker` in `path` and return the (maximal) run after the first occurrence
/// whose run is at least `min` chars; `None` if no occurrence qualifies. Trying
/// later occurrences reproduces the regex engine's advance-and-retry so a short
/// run at an early marker does not mask a valid one further along.
fn after(path: &str, marker: &str, min: usize, pred: impl Fn(char) -> bool) -> Option<String> {
    let mut from = 0;
    while let Some(rel) = path[from..].find(marker) {
        let idx = from + rel;
        let rest = &path[idx + marker.len()..];
        let id: String = rest.chars().take_while(|c| pred(*c)).collect();
        if id.chars().count() >= min {
            return Some(id);
        }
        from = idx + 1; // advance one char, as a regex `.search` would
    }
    None
}

fn yt_id(c: char) -> bool {
    c.is_ascii_alphanumeric() || c == '_' || c == '-'
}
fn dm_id(c: char) -> bool {
    c.is_ascii_alphanumeric()
}
fn pt_id(c: char) -> bool {
    c.is_ascii_alphanumeric() || c == '-'
}

/// Map an `<iframe src>` to `(player, watch_url)` — `(None, None)` for a src that
/// is not a recognised embed. Pure string work. Mirrors `_classify_player`.
#[must_use]
pub fn classify_player(src: &str) -> (Option<String>, Option<String>) {
    let s = urlsplit(src, "");
    let host = hostname(src);
    let path = &s.path;
    if host.is_empty() {
        return (None, None);
    }
    let yt = |id: &str| Some(format!("https://www.youtube.com/watch?v={id}"));

    if host.ends_with("youtube.com") || host.ends_with("youtube-nocookie.com") {
        return match after(path, "/embed/", 6, yt_id) {
            Some(id) => (Some("youtube".into()), yt(&id)),
            None => (Some("youtube".into()), None),
        };
    }
    if host == "youtu.be" || host.ends_with(".youtu.be") {
        let seg = path
            .trim_matches('/')
            .split('/')
            .next()
            .unwrap_or("")
            .to_string();
        let watch = if seg.is_empty() { None } else { yt(&seg) };
        return (Some("youtube".into()), watch);
    }
    if host.ends_with("player.vimeo.com") {
        let watch = after(path, "/video/", 1, |c| c.is_ascii_digit())
            .map(|id| format!("https://vimeo.com/{id}"));
        return (Some("vimeo".into()), watch);
    }
    if host.ends_with("dailymotion.com") || host == "dai.ly" || host.ends_with(".dai.ly") {
        if let Some(id) = after(path, "/video/", 1, dm_id) {
            return (
                Some("dailymotion".into()),
                Some(format!("https://www.dailymotion.com/video/{id}")),
            );
        }
        let seg = path
            .trim_matches('/')
            .split('/')
            .next()
            .unwrap_or("")
            .to_string();
        if host == "dai.ly" && !seg.is_empty() {
            return (
                Some("dailymotion".into()),
                Some(format!("https://www.dailymotion.com/video/{seg}")),
            );
        }
        return (Some("dailymotion".into()), None);
    }
    if let Some(id) = after(path, "/videos/embed/", 1, pt_id) {
        let scheme = if s.scheme.is_empty() {
            "https".to_string()
        } else {
            s.scheme.to_lowercase()
        };
        return (
            Some("peertube".into()),
            Some(format!("{scheme}://{host}/videos/watch/{id}")),
        );
    }
    if host.ends_with("odysee.com") || host.ends_with("lbry.tv") || host.ends_with("lbry.com") {
        return (Some("odysee".into()), None);
    }
    if host.ends_with("rumble.com") {
        return (Some("rumble".into()), None);
    }
    (None, None)
}

/// True if `href`'s path ends in a direct video media extension.
#[must_use]
pub fn is_direct_media(href: &str) -> bool {
    let p = urlsplit(href, "").path.to_lowercase();
    MEDIA_EXT.iter().any(|e| p.ends_with(e))
}

// ---- JSON-LD / state helpers (over crawlcore::json) ------------------------

/// Python truthiness of a JSON value (empty string/array/object, 0, false, null
/// are falsy).
#[must_use]
pub fn truthy(v: &Value) -> bool {
    match v {
        Value::Null => false,
        Value::Bool(b) => *b,
        Value::Num(n) => *n != 0.0,
        Value::Int(i) => *i != 0,
        Value::Str(s) => !s.is_empty(),
        Value::Array(a) => !a.is_empty(),
        Value::Object(o) => !o.is_empty(),
    }
}

/// The first non-empty string in `v` (a bare string, or the first string in a
/// list). Mirrors `_first_str`.
#[must_use]
pub fn first_str(v: &Value) -> String {
    match v {
        Value::Str(s) => s.trim().to_string(),
        Value::Array(a) => {
            for x in a {
                if let Value::Str(s) = x {
                    if !s.trim().is_empty() {
                        return s.trim().to_string();
                    }
                }
            }
            String::new()
        }
        _ => String::new(),
    }
}

/// The first URL-ish string in `v` (string, list, or `{"url"|"@id"|"contentUrl"}`
/// object). Mirrors `_first_url`.
#[must_use]
pub fn first_url(v: &Value) -> String {
    match v {
        Value::Str(s) => s.trim().to_string(),
        Value::Array(a) => {
            for x in a {
                let u = first_url(x);
                if !u.is_empty() {
                    return u;
                }
            }
            String::new()
        }
        Value::Object(_) => {
            for key in ["url", "@id", "contentUrl"] {
                if let Some(val) = v.get(key) {
                    if truthy(val) {
                        return first_str(val);
                    }
                }
            }
            String::new()
        }
        _ => String::new(),
    }
}

/// The schema.org `@type` of a node as lower-case strings. Mirrors `_type_of`.
#[must_use]
pub fn type_of(node: &Value) -> Vec<String> {
    match node.get("@type") {
        Some(Value::Str(s)) => vec![s.to_lowercase()],
        Some(Value::Array(a)) => a
            .iter()
            .filter_map(|x| x.as_str().map(str::to_lowercase))
            .collect(),
        _ => Vec::new(),
    }
}

/// Collect dict nodes from a parsed JSON value (follows lists + `@graph`),
/// bounded to `JSON_MAX_NODES`. Order matches the Python `_iter_json_dicts`
/// (LIFO stack). Mirrors `_iter_json_dicts`.
#[must_use]
pub fn iter_dicts(parsed: &Value) -> Vec<&Value> {
    let mut out = Vec::new();
    let mut stack: Vec<&Value> = vec![parsed];
    while let Some(node) = stack.pop() {
        if out.len() >= JSON_MAX_NODES {
            break;
        }
        match node {
            Value::Object(_) => {
                out.push(node);
                if let Some(Value::Array(g)) = node.get("@graph") {
                    for x in g {
                        if matches!(x, Value::Object(_) | Value::Array(_)) {
                            stack.push(x);
                        }
                    }
                }
            }
            Value::Array(a) => {
                for x in a {
                    if matches!(x, Value::Object(_) | Value::Array(_)) {
                        stack.push(x);
                    }
                }
            }
            _ => {}
        }
    }
    out
}

/// Collect human-readable string leaves (by key) from a state blob, bounded by
/// node count, depth, and total length. Mirrors `_collect_readable`.
#[must_use]
pub fn collect_readable(parsed: &Value) -> Vec<String> {
    let mut out = Vec::new();
    let mut total = 0usize;
    let mut nodes = 0usize;
    let mut stack: Vec<(&Value, usize)> = vec![(parsed, 0)];
    while let Some((node, depth)) = stack.pop() {
        if nodes >= JSON_MAX_NODES || total >= RECOVER_BODY_MAX {
            break;
        }
        nodes += 1;
        if depth > JSON_MAX_DEPTH {
            continue;
        }
        match node {
            Value::Object(pairs) => {
                for (k, v) in pairs {
                    match v {
                        Value::Str(s)
                            if READABLE_KEYS.contains(&k.to_lowercase().as_str())
                                && !s.trim().is_empty() =>
                        {
                            let t = s.trim();
                            let kept: String = t.chars().take(JSON_STR_KEEP).collect();
                            total += kept.chars().count();
                            out.push(kept);
                        }
                        Value::Object(_) | Value::Array(_) => stack.push((v, depth + 1)),
                        _ => {}
                    }
                }
            }
            Value::Array(a) => {
                for v in a {
                    if matches!(v, Value::Object(_) | Value::Array(_)) {
                        stack.push((v, depth + 1));
                    }
                }
            }
            _ => {}
        }
    }
    out
}

/// Return the balanced `{...}` / `[...]` substring at/after `start` (the opener
/// must be within 100 chars), scanning at most `MAX_BLOB_BYTES`, tracking string
/// literals so braces inside strings are ignored. Mirrors `_balanced_json`.
#[must_use]
pub fn balanced_json(text: &str, start: usize) -> Option<String> {
    let chars: Vec<char> = text.chars().collect();
    let n = chars.len();
    let mut i = start;
    let limit = n.min(start + 100);
    while i < limit && chars.get(i) != Some(&'{') && chars.get(i) != Some(&'[') {
        i += 1;
    }
    let open = *chars.get(i)?;
    if open != '{' && open != '[' {
        return None;
    }
    let close = if open == '{' { '}' } else { ']' };
    let end = n.min(i + MAX_BLOB_BYTES);
    let mut depth = 0i64;
    let mut in_str = false;
    let mut esc = false;
    let mut j = i;
    while j < end {
        let c = chars[j];
        if in_str {
            if esc {
                esc = false;
            } else if c == '\\' {
                esc = true;
            } else if c == '"' {
                in_str = false;
            }
        } else if c == '"' {
            in_str = true;
        } else if c == open {
            depth += 1;
        } else if c == close {
            depth -= 1;
            if depth == 0 {
                return Some(chars[i..=j].iter().collect());
            }
        }
        j += 1;
    }
    None
}

/// Find a known inline-state marker in `text` and return its JSON payload string.
/// Mirrors `_extract_state_json`.
#[must_use]
pub fn extract_state_json(text: &str) -> Option<String> {
    let chars: Vec<char> = text.chars().collect();
    for marker in STATE_MARKERS {
        if let Some(i) = text.find(marker) {
            // char index of the marker start (text.find gives a byte index)
            let byte_i = i;
            let ci = text[..byte_i].chars().count();
            let j = ci + marker.chars().count();
            // '=' within 40 chars after the marker → start just past it.
            let eq = chars[j..(j + 41).min(chars.len())]
                .iter()
                .position(|c| *c == '=')
                .map(|p| j + p);
            let start = match eq {
                Some(k) if k <= j + 40 => k + 1,
                _ => j,
            };
            if let Some(obj) = balanced_json(text, start) {
                return Some(obj);
            }
        }
    }
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn durations() {
        assert_eq!(parse_duration("PT1H2M3S"), Some(3723));
        assert_eq!(parse_duration("PT1M30S"), Some(90));
        assert_eq!(parse_duration("P1DT2H"), Some(93600));
        assert_eq!(parse_duration("PT0S"), Some(0));
        assert_eq!(parse_duration("P"), None);
        assert_eq!(parse_duration("PT"), None);
        assert_eq!(parse_duration("garbage"), None);
    }

    #[test]
    fn players() {
        assert_eq!(
            classify_player("https://www.youtube.com/embed/dQw4w9WgXcQ"),
            (
                Some("youtube".into()),
                Some("https://www.youtube.com/watch?v=dQw4w9WgXcQ".into())
            )
        );
        assert_eq!(
            classify_player("https://player.vimeo.com/video/12345"),
            (Some("vimeo".into()), Some("https://vimeo.com/12345".into()))
        );
        assert_eq!(classify_player("https://example.com/x"), (None, None));
        assert!(is_direct_media("http://a/clip.MP4"));
        assert!(!is_direct_media("http://a/page.html"));
    }

    #[test]
    fn json_walk() {
        let v = crawlcore::json::parse(
            r#"{"@type": "VideoObject", "name": "Cats", "nested": {"description": "d"}, "url": "u"}"#,
        )
        .unwrap();
        assert_eq!(type_of(&v), vec!["videoobject"]);
        assert_eq!(first_url(&v), "u");
        // iter_dicts follows only lists + `@graph`, not arbitrary nested values:
        assert_eq!(iter_dicts(&v).len(), 1);
        let g = crawlcore::json::parse(r#"{"@graph": [{"@type": "A"}, {"@type": "B"}]}"#).unwrap();
        assert_eq!(iter_dicts(&g).len(), 3); // wrapper + A + B
                                             // collect_readable DOES recurse into nested dict values:
        let mut readable = collect_readable(&v);
        readable.sort();
        assert_eq!(readable, vec!["Cats".to_string(), "d".to_string()]);
    }

    #[test]
    fn state_extraction() {
        let s = "var x = 1; window.__NUXT__ = {\"a\": {\"title\": \"Hi\"}}; more();";
        let blob = extract_state_json(s).unwrap();
        assert_eq!(blob, "{\"a\": {\"title\": \"Hi\"}}");
    }
}
