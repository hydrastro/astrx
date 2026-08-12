//! HTML-safe rendering helpers: escaping, dates, minimal Markdown, diff parsing.
//!
//! The guiding rule for anything in this module is *escape first*. Every
//! function that turns untrusted repository content into HTML runs it through
//! [`esc`] before applying any structure, so a value can never inject markup.
//! The Markdown renderer only re-introduces a fixed, safe subset of tags onto
//! already-escaped text.
//!
//! A faithful port of the Python `gitweb.markup`, cross-checked byte-identical
//! in `tests/xcheck_markup.rs`. The Python module leans on `re`; every pattern
//! is reproduced here as a hand-rolled linear scan (no regex crate, matching the
//! rest of the suite), including the greedy/lazy and backtracking semantics that
//! decide whether a construct matches at all. Because Python's `re` counts
//! *code points*, every bounded span (`{1,512}`) and the
//! [`MAX_MARKDOWN_BYTES`] cap (which, despite its name, is `len(str)` in Python)
//! are measured in `char`s here too.
//!
//! # Documented divergences
//!
//! * **Ordered-list markers**: Python's `\d` matches every Unicode decimal digit
//!   (general category `Nd`); the stdlib exposes no `Nd` table, so an ordered
//!   list marker here must use ASCII digits. Every other `\d`/`\w`/`\s` class is
//!   reproduced exactly (see [`crate::pycompat`]).
//! * **`highlight_source` is not ported.** In Python it is an optional Pygments
//!   hook that returns `None` — the escaped-plaintext fallback — whenever
//!   Pygments is absent, which is always the case in the zero-dependency
//!   deployment this crate targets. There is no Rust equivalent to add, so the
//!   blob view simply renders the (identical) fallback.
//! * **An indented ATX heading no longer hangs.** `render_markdown("  # x")`
//!   *never returns* in the Python reference: an ATX heading is only recognised
//!   at column 0, but the paragraph terminator `^\s*(#{1,6}\s|…)` fires on the
//!   indented form, so the paragraph loop consumes no line, `i` is never
//!   advanced, and the `while i < n` loop spins forever emitting `<p></p>`. On
//!   a network-facing renderer that is a one-request denial of service, so it
//!   is fixed here: when the paragraph scan consumes nothing, the offending
//!   line is taken as a one-line paragraph and `i` advances. The guard is inert
//!   for every input on which the Python reference terminates — the paragraph
//!   scan can only come up empty on exactly that hang — so byte-identity is
//!   unaffected. (Only an *indented* `#{1,6}` + whitespace triggers it;
//!   `  #x`, `  ####### x`, indented lists/quotes/fences and `  # x` followed
//!   by a setext underline all terminate in Python and are matched exactly.)

use crate::pycompat::{
    expandtabs, gmtime, html_escape, is_space, is_word, lstrip, rstrip, rstrip_chars, strip,
};

/// HTML-escape `value` (quotes included) for safe placement anywhere.
#[must_use]
pub fn esc(value: &str) -> String {
    html_escape(value)
}

/// True for a C0 control character that is *illegal* in XML 1.0 even as a
/// numeric reference (everything below U+0020 except TAB/LF/CR), plus the two
/// noncharacters U+FFFE/U+FFFF that the XML `Char` production also forbids.
fn xml_invalid(c: char) -> bool {
    matches!(c, '\u{0}'..='\u{8}' | '\u{b}' | '\u{c}' | '\u{e}'..='\u{1f}' | '\u{fffe}' | '\u{ffff}')
}

/// Escape `value` for XML text/attributes, dropping XML-illegal controls.
///
/// Identical to [`esc`] but first replaces the characters XML 1.0 forbids with
/// U+FFFD, so repository-derived text (which git preserves verbatim) can never
/// break Atom feed well-formedness. XML-*legal* noncharacters (e.g. U+FDD0) are
/// preserved.
#[must_use]
pub fn xml_escape(value: &str) -> String {
    let cleaned: String = value
        .chars()
        .map(|c| if xml_invalid(c) { '\u{fffd}' } else { c })
        .collect();
    esc(&cleaned)
}

// --------------------------------------------------------------------------- //
// Dates
// --------------------------------------------------------------------------- //

/// Seconds since the unix epoch, as `time.time()` reports them.
fn now_seconds() -> f64 {
    use std::time::{SystemTime, UNIX_EPOCH};
    match SystemTime::now().duration_since(UNIX_EPOCH) {
        Ok(d) => d.as_secs_f64(),
        Err(e) => -e.duration().as_secs_f64(),
    }
}

/// Human `"3 days ago"` style string from a unix timestamp.
///
/// `now` defaults to the current time. Following the Python reference's falsy
/// test (`if not ts`), both `None` **and** `Some(0)` render as `"unknown"`.
#[must_use]
pub fn relative_date(ts: Option<i64>, now: Option<f64>) -> String {
    let ts = match ts {
        Some(t) if t != 0 => t,
        _ => return "unknown".to_string(),
    };
    let now = now.unwrap_or_else(now_seconds);
    let mut delta = now - ts as f64;
    if delta < 0.0 {
        delta = 0.0;
    }
    const UNITS: [(i64, &str); 6] = [
        (31_536_000, "year"),
        (2_592_000, "month"),
        (604_800, "week"),
        (86_400, "day"),
        (3600, "hour"),
        (60, "minute"),
    ];
    for (secs, label) in UNITS {
        if delta >= secs as f64 {
            let n = py_floordiv(delta, secs as f64) as i64;
            return format!("{n} {label}{} ago", if n != 1 { "s" } else { "" });
        }
    }
    "just now".to_string()
}

/// CPython's `float.__floordiv__` for two non-negative finite operands:
/// `floor((v - fmod(v, w)) / w)` with the `> 0.5` correction from
/// `float_divmod`, which is *not* always `floor(v / w)`.
fn py_floordiv(v: f64, w: f64) -> f64 {
    let m = v % w;
    let div = (v - m) / w;
    if div == 0.0 {
        return 0.0;
    }
    let floordiv = div.floor();
    if div - floordiv > 0.5 {
        floordiv + 1.0
    } else {
        floordiv
    }
}

/// `YYYY-MM-DD HH:MM UTC` from a unix timestamp (empty for `None`/`0`).
#[must_use]
pub fn iso_date(ts: Option<i64>) -> String {
    let ts = match ts {
        Some(t) if t != 0 => t,
        _ => return String::new(),
    };
    let t = gmtime(ts);
    format!(
        "{}-{:02}-{:02} {:02}:{:02} UTC",
        t.year, t.mon, t.day, t.hour, t.min
    )
}

/// RFC 3339 / Atom timestamp (UTC) from a unix timestamp; `None`/`0` render as
/// the epoch.
#[must_use]
pub fn atom_date(ts: Option<i64>) -> String {
    let t = gmtime(ts.unwrap_or(0));
    format!(
        "{}-{:02}-{:02}T{:02}:{:02}:{:02}Z",
        t.year, t.mon, t.day, t.hour, t.min, t.sec
    )
}

// --------------------------------------------------------------------------- //
// Minimal, safe Markdown
// --------------------------------------------------------------------------- //

/// Upper bound (in code points, as Python's `len(str)` measures) on a document
/// handed to [`render_markdown`]. Above this we fall back to an escaped `<pre>`
/// (no inline parsing) so a hostile blob or README can never drive the renderer
/// into a large amount of work.
pub const MAX_MARKDOWN_BYTES: usize = 256 * 1024;

/// Longest span an inline construct (link/image label or URL, code span, angle
/// autolink) may cover. Every such body is bounded so a *failed* match attempt
/// costs O(N) rather than O(remaining). A construct longer than this simply
/// degrades to literal text (rare, safe).
const MD_MAX_SPAN: usize = 512;

/// Resolved reference-link definitions: `id` → already-escaped URL, in the
/// insertion order Python's `dict` keeps (first definition wins).
type Refs = Vec<(String, String)>;

fn refs_get<'a>(refs: &'a Refs, id: &str) -> Option<&'a str> {
    refs.iter().find(|(k, _)| k == id).map(|(_, v)| v.as_str())
}

fn refs_setdefault(refs: &mut Refs, id: String, url: String) {
    if !refs.iter().any(|(k, _)| *k == id) {
        refs.push((id, url));
    }
}

/// Return `url` if its scheme is safe, else `None`.
///
/// `url` is already HTML-escaped. http/https/mailto, root-relative and fragment
/// links, and scheme-less relative links are allowed; everything else (notably
/// `javascript:`) is rejected.
fn safe_url(url: &str) -> Option<String> {
    let probe = strip(url);
    if probe.is_empty() {
        return None;
    }
    // A `\0` here is one of our placeholder sentinels that got captured *as a
    // URL* — e.g. an image or code span nested in a link's `(...)`. It must
    // never become an `href`/`src`, because on restore its content (which
    // contains literal `"`) would break out of the attribute.
    if url.contains('\0') {
        return None;
    }
    let lower = probe.to_lowercase();
    if lower.starts_with("javascript:") || lower.starts_with("data:") {
        return None;
    }
    // `^(https?:|mailto:|/|#|[^:]*$)` (case-insensitive).
    let ok = lower.starts_with("http:")
        || lower.starts_with("https:")
        || lower.starts_with("mailto:")
        || probe.starts_with('/')
        || probe.starts_with('#')
        || !probe.contains(':');
    if ok {
        Some(url.to_string())
    } else {
        None
    }
}

fn stash(placeholders: &mut Vec<String>, html: String) -> String {
    placeholders.push(html);
    format!("\0{}\0", placeholders.len() - 1)
}

/// `` `code` `` — `` `([^`]{1,512})` ``.
fn sub_inline_code(text: &str, ph: &mut Vec<String>) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if c[i] == '`' {
            let mut j = i + 1;
            while j < c.len() && c[j] != '`' {
                j += 1;
            }
            let body = j - i - 1;
            if j < c.len() && (1..=MD_MAX_SPAN).contains(&body) {
                let inner: String = c[i + 1..j].iter().collect();
                out.push_str(&stash(ph, format!("<code>{inner}</code>")));
                i = j + 1;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// Match `[label](url)` starting at the `[` at `open`; `min_label` is 0 for an
/// image (`![…]`) and 1 for a link. Returns `(label, url, end)` where `end` is
/// the index just past the closing `)`.
fn match_bracket_paren(
    c: &[char],
    open: usize,
    min_label: usize,
) -> Option<(String, String, usize)> {
    let mut j = open + 1;
    while j < c.len() && c[j] != ']' {
        j += 1;
    }
    let label_len = j - open - 1;
    if j >= c.len() || label_len < min_label || label_len > MD_MAX_SPAN {
        return None;
    }
    if j + 1 >= c.len() || c[j + 1] != '(' {
        return None;
    }
    let ustart = j + 2;
    let mut k = ustart;
    while k < c.len() && c[k] != ')' && !is_space(c[k]) {
        k += 1;
    }
    let url_len = k - ustart;
    if k >= c.len() || c[k] != ')' || !(1..=MD_MAX_SPAN).contains(&url_len) {
        return None;
    }
    Some((
        c[open + 1..j].iter().collect(),
        c[ustart..k].iter().collect(),
        k + 1,
    ))
}

/// `![alt](url)` — the image pass.
fn sub_image(text: &str, ph: &mut Vec<String>) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if c[i] == '!' && i + 1 < c.len() && c[i + 1] == '[' {
            if let Some((alt, url, end)) = match_bracket_paren(&c, i + 1, 0) {
                match safe_url(&url) {
                    Some(safe) => {
                        out.push_str(&stash(ph, format!("<img src=\"{safe}\" alt=\"{alt}\">")));
                    }
                    None => out.extend(c[i..end].iter()),
                }
                i = end;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// `[text](url)` — the inline-link pass.
fn sub_link(text: &str, ph: &mut Vec<String>) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if c[i] == '[' {
            if let Some((label, url, end)) = match_bracket_paren(&c, i, 1) {
                match safe_url(&url) {
                    Some(safe) => out.push_str(&stash(
                        ph,
                        format!("<a href=\"{safe}\" rel=\"nofollow noopener\">{label}</a>"),
                    )),
                    None => out.extend(c[i..end].iter()),
                }
                i = end;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// `[text][id]` / `[text][]` — the reference-link pass.
fn sub_ref(text: &str, ph: &mut Vec<String>, refs: &Refs) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if c[i] == '[' {
            if let Some((label, id, end)) = match_double_bracket(&c, i) {
                let stripped = strip(&id);
                let rid = if stripped.is_empty() {
                    label.to_lowercase()
                } else {
                    stripped.to_lowercase()
                };
                let resolved = refs_get(refs, &rid).and_then(safe_url);
                match resolved {
                    Some(safe) => out.push_str(&stash(
                        ph,
                        format!("<a href=\"{safe}\" rel=\"nofollow noopener\">{label}</a>"),
                    )),
                    None => out.extend(c[i..end].iter()),
                }
                i = end;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// `\[([^\]]{1,512})\]\[([^\]]{0,512})\]` starting at the `[` at `open`.
fn match_double_bracket(c: &[char], open: usize) -> Option<(String, String, usize)> {
    let mut j = open + 1;
    while j < c.len() && c[j] != ']' {
        j += 1;
    }
    let label_len = j - open - 1;
    if j >= c.len() || !(1..=MD_MAX_SPAN).contains(&label_len) {
        return None;
    }
    if j + 1 >= c.len() || c[j + 1] != '[' {
        return None;
    }
    let istart = j + 2;
    let mut k = istart;
    while k < c.len() && c[k] != ']' {
        k += 1;
    }
    let id_len = k - istart;
    if k >= c.len() || id_len > MD_MAX_SPAN {
        return None;
    }
    Some((
        c[open + 1..j].iter().collect(),
        c[istart..k].iter().collect(),
        k + 1,
    ))
}

/// Match `https?://` at `p`, returning the index just past `://` (the greedy
/// `s?` is tried first, exactly as `re` does).
fn match_scheme(c: &[char], p: usize) -> Option<usize> {
    let starts = |at: usize, lit: &str| -> bool {
        let l: Vec<char> = lit.chars().collect();
        at + l.len() <= c.len() && c[at..at + l.len()] == l[..]
    };
    if starts(p, "https://") {
        Some(p + 8)
    } else if starts(p, "http://") {
        Some(p + 7)
    } else {
        None
    }
}

/// `&lt;(https?://[^\s<>]{1,512}?)&gt;` — the angle autolink pass (the brackets
/// are already escaped by the time inline runs).
fn sub_angle(text: &str, ph: &mut Vec<String>) -> String {
    let c: Vec<char> = text.chars().collect();
    let lt: Vec<char> = "&lt;".chars().collect();
    let gt: Vec<char> = "&gt;".chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if i + lt.len() <= c.len() && c[i..i + lt.len()] == lt[..] {
            if let Some(body_start) = match_scheme(&c, i + lt.len()) {
                // Lazy `{1,512}?`: grow the body one char at a time, checking
                // for the closing `&gt;` after each.
                let mut end = None;
                for k in 1..=MD_MAX_SPAN {
                    let ch = match c.get(body_start + k - 1) {
                        Some(&ch) => ch,
                        None => break,
                    };
                    if is_space(ch) || ch == '<' || ch == '>' {
                        break;
                    }
                    let after = body_start + k;
                    if after + gt.len() <= c.len() && c[after..after + gt.len()] == gt[..] {
                        end = Some(after);
                        break;
                    }
                }
                if let Some(after) = end {
                    let url: String = c[i + lt.len()..after].iter().collect();
                    let full_end = after + gt.len();
                    if url.contains('\0') {
                        out.extend(c[i..full_end].iter());
                    } else {
                        out.push_str(&stash(
                            ph,
                            format!("<a href=\"{url}\" rel=\"nofollow noopener\">{url}</a>"),
                        ));
                    }
                    i = full_end;
                    continue;
                }
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// `https?://[^\s<>()\[\]]+` — the bare autolink pass.
fn sub_auto(text: &str, ph: &mut Vec<String>) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if let Some(body_start) = match_scheme(&c, i) {
            let mut k = body_start;
            while k < c.len()
                && !is_space(c[k])
                && !matches!(c[k], '<' | '>' | '(' | ')' | '[' | ']')
            {
                k += 1;
            }
            if k > body_start {
                let matched: String = c[i..k].iter().collect();
                if matched.contains('\0') {
                    out.push_str(&matched);
                } else {
                    let url = rstrip_chars(&matched, ".,;:!?");
                    let trail = &matched[url.len()..];
                    if url.is_empty() {
                        out.push_str(&matched);
                    } else {
                        out.push_str(&stash(
                            ph,
                            format!("<a href=\"{url}\" rel=\"nofollow noopener\">{url}</a>"),
                        ));
                        out.push_str(trail);
                    }
                }
                i = k;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// `\*\*([^*]+)\*\*` / `__([^_]+)__` → `<strong>…</strong>`.
fn sub_strong(text: &str, delim: char) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if c[i] == delim && i + 1 < c.len() && c[i + 1] == delim {
            let mut j = i + 2;
            while j < c.len() && c[j] != delim {
                j += 1;
            }
            if j > i + 2 && j + 1 < c.len() && c[j] == delim && c[j + 1] == delim {
                let inner: String = c[i + 2..j].iter().collect();
                out.push_str("<strong>");
                out.push_str(&inner);
                out.push_str("</strong>");
                i = j + 2;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// `(?<![*\w])\*([^*\n]+)\*(?!\*)` / `(?<![_\w])_([^_\n]+)_(?!_)` → `<em>…</em>`.
fn sub_em(text: &str, delim: char) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if c[i] == delim && (i == 0 || (c[i - 1] != delim && !is_word(c[i - 1]))) {
            let mut j = i + 1;
            while j < c.len() && c[j] != delim && c[j] != '\n' {
                j += 1;
            }
            if j > i + 1 && j < c.len() && c[j] == delim && c.get(j + 1) != Some(&delim) {
                let inner: String = c[i + 1..j].iter().collect();
                out.push_str("<em>");
                out.push_str(&inner);
                out.push_str("</em>");
                i = j + 1;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// One `re.sub(r"\x00(\d+)\x00", …)` pass over the stashed placeholders.
fn unstash_once(text: &str, ph: &[String]) -> String {
    let c: Vec<char> = text.chars().collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < c.len() {
        if c[i] == '\0' {
            let mut j = i + 1;
            while j < c.len() && c[j].is_ascii_digit() {
                j += 1;
            }
            if j > i + 1 && j < c.len() && c[j] == '\0' {
                let digits: String = c[i + 1..j].iter().collect();
                if let Some(frag) = digits.parse::<usize>().ok().and_then(|n| ph.get(n)) {
                    out.push_str(frag);
                }
                i = j + 1;
                continue;
            }
        }
        out.push(c[i]);
        i += 1;
    }
    out
}

/// Apply inline Markdown to a line of *already HTML-escaped* text.
///
/// Code spans, images and links (inline, reference-style and angle/bare
/// autolinks) are stashed as opaque placeholders before the remaining
/// transforms run, so autolinking/emphasis can never reach inside a generated
/// `href`/`src` or a code span.
fn inline(text: &str, refs: &Refs) -> String {
    // NUL is our placeholder sentinel; strip any that came from blob content so
    // a repo-controlled `\0<digits>\0` sequence cannot collide with it.
    let mut t = text.replace('\0', "");
    let mut ph: Vec<String> = Vec::new();

    if t.contains('`') {
        t = sub_inline_code(&t, &mut ph);
    }
    // Every bracket construct needs a closing `]`; skipping the three passes
    // when none is present keeps a long run of `[` / `![` linear.
    if t.contains(']') {
        t = sub_image(&t, &mut ph);
        t = sub_link(&t, &mut ph);
        t = sub_ref(&t, &mut ph, refs);
    }
    if t.contains("&gt;") {
        t = sub_angle(&t, &mut ph);
    }
    t = sub_auto(&t, &mut ph);

    t = sub_strong(&t, '*');
    t = sub_strong(&t, '_');
    t = sub_em(&t, '*');
    t = sub_em(&t, '_');

    // A stashed fragment can itself contain another sentinel (an image nested
    // inside a link), so expand repeatedly until none remain — a fragment only
    // ever references *earlier* placeholders, so this terminates.
    for _ in 0..=ph.len() {
        if !t.contains('\0') {
            break;
        }
        t = unstash_once(&t, &ph);
    }
    t
}

/// Split a pipe-table row into trimmed cells (ignoring outer pipes).
fn split_table_row(line: &str) -> Vec<String> {
    let mut s = strip(line);
    if let Some(rest) = s.strip_prefix('|') {
        s = rest;
    }
    if let Some(rest) = s.strip_suffix('|') {
        s = rest;
    }
    s.split('|').map(|c| strip(c).to_string()).collect()
}

/// `^:?-+:?$`
fn is_table_sep_cell(cell: &str) -> bool {
    let c: Vec<char> = cell.chars().collect();
    let mut i = 0;
    if i < c.len() && c[i] == ':' {
        i += 1;
    }
    let dash_start = i;
    while i < c.len() && c[i] == '-' {
        i += 1;
    }
    if i == dash_start {
        return false;
    }
    if i < c.len() && c[i] == ':' {
        i += 1;
    }
    i == c.len()
}

fn is_table_separator(line: &str) -> bool {
    if !line.contains('|') && !line.contains('-') {
        return false;
    }
    let cells = split_table_row(line);
    !cells.is_empty()
        && cells
            .iter()
            .filter(|c| !c.is_empty())
            .all(|c| is_table_sep_cell(c))
        && cells.iter().any(|c| c.contains('-'))
}

/// Parse a GitHub pipe table starting at `lines[i]`; returns `(html, new_i)`.
fn render_table(lines: &[&str], i: usize, n: usize, refs: &Refs) -> (String, usize) {
    let header = split_table_row(lines[i]);
    let seps = split_table_row(lines[i + 1]);
    let aligns: Vec<&str> = seps
        .iter()
        .map(|cell| {
            let left = cell.starts_with(':');
            let right = cell.ends_with(':');
            match (left, right) {
                (true, true) => "center",
                (false, true) => "right",
                (true, false) => "left",
                (false, false) => "",
            }
        })
        .collect();
    let style_for = |idx: usize| -> String {
        let align = aligns.get(idx).copied().unwrap_or("");
        if align.is_empty() {
            String::new()
        } else {
            format!(" style=\"text-align:{align}\"")
        }
    };
    let mut out = String::from("<table class=\"md-table\"><thead><tr>");
    for (idx, cell) in header.iter().enumerate() {
        out.push_str(&format!(
            "<th{}>{}</th>",
            style_for(idx),
            inline(&esc(cell), refs)
        ));
    }
    out.push_str("</tr></thead><tbody>");
    let mut j = i + 2;
    while j < n && lines[j].contains('|') && !strip(lines[j]).is_empty() {
        let row = split_table_row(lines[j]);
        out.push_str("<tr>");
        for idx in 0..header.len() {
            let cell = row.get(idx).map(String::as_str).unwrap_or("");
            out.push_str(&format!(
                "<td{}>{}</td>",
                style_for(idx),
                inline(&esc(cell), refs)
            ));
        }
        out.push_str("</tr>");
        j += 1;
    }
    out.push_str("</tbody></table>");
    (out, j)
}

/// `^\[([ xX])\]\s+(.*)$` — returns `(marker, content)`.
fn match_task(content: &str) -> Option<(char, String)> {
    let c: Vec<char> = content.chars().collect();
    if c.len() < 3 || c[0] != '[' || c[2] != ']' || !matches!(c[1], ' ' | 'x' | 'X') {
        return None;
    }
    let mut i = 3;
    while i < c.len() && is_space(c[i]) {
        i += 1;
    }
    if i == 3 {
        return None; // `\s+` needs at least one whitespace character
    }
    Some((c[1], c[i..].iter().collect()))
}

/// Return `(li_attrs, inner_html)` for one list item (task-list aware). The
/// `<li>` wrapper is emitted by the caller so a nested list can be placed
/// *inside* the still-open item.
fn list_item_parts(content: &str, refs: &Refs) -> (&'static str, String) {
    if let Some((marker, rest)) = match_task(content) {
        let checked = if marker == 'x' || marker == 'X' {
            " checked"
        } else {
            ""
        };
        return (
            " class=\"task\"",
            format!(
                "<input type=\"checkbox\" disabled{checked}> {}",
                inline(&esc(&rest), refs)
            ),
        );
    }
    ("", inline(&esc(content), refs))
}

/// True if `s` (stripped) is a non-empty run of only `ch` (a setext underline).
fn is_setext_underline(s: &str, ch: char) -> bool {
    let s = strip(s);
    !s.is_empty() && s.chars().all(|c| c == ch)
}

/// Strip an ATX heading's optional trailing `\s*#*\s*` closing sequence.
fn strip_atx_close(text: &str) -> &str {
    let t = rstrip(text);
    let t2 = rstrip_chars(t, "#");
    if t2.len() != t.len() {
        rstrip(t2)
    } else {
        t
    }
}

/// Skip a leading whitespace run, returning the byte offset of the first
/// non-whitespace character.
fn after_indent(line: &str) -> usize {
    line.len() - lstrip(line).len()
}

/// `^\s*(```+|~~~+)` — returns the byte offset just past the fence run.
fn match_fence_open(line: &str) -> Option<usize> {
    let start = after_indent(line);
    let rest = &line[start..];
    for ch in ['`', '~'] {
        let run = rest.chars().take_while(|&c| c == ch).count();
        if run >= 3 {
            return Some(start + run);
        }
    }
    None
}

/// `^\s*(```+|~~~+)\s*$` — a bare closing fence.
fn is_fence_close(line: &str) -> bool {
    match match_fence_open(line) {
        Some(end) => line[end..].chars().all(is_space),
        None => false,
    }
}

/// `^(#{1,6})\s+(.*)$` — returns `(level, text)`.
fn match_atx(line: &str) -> Option<(usize, &str)> {
    let hashes = line.chars().take_while(|&c| c == '#').count();
    if !(1..=6).contains(&hashes) {
        return None;
    }
    let rest = &line[hashes..];
    let stripped = lstrip(rest);
    if stripped.len() == rest.len() {
        return None; // `\s+` needs at least one whitespace character
    }
    Some((hashes, stripped))
}

/// `^(\s*)([-*+]|\d+[.)])\s+(.*)$` — returns `(indent_str, is_ordered, content)`.
fn match_list_item(line: &str) -> Option<(&str, bool, &str)> {
    let start = after_indent(line);
    let indent = &line[..start];
    let rest = &line[start..];
    let mut chars = rest.char_indices();
    let (_, first) = chars.next()?;
    let (marker_end, ordered) = if matches!(first, '-' | '*' | '+') {
        (first.len_utf8(), false)
    } else if first.is_ascii_digit() {
        let digits = rest.chars().take_while(char::is_ascii_digit).count();
        let after = rest[digits..].chars().next()?;
        if after != '.' && after != ')' {
            return None;
        }
        (digits + after.len_utf8(), true)
    } else {
        return None;
    };
    let tail = &rest[marker_end..];
    let content = lstrip(tail);
    if content.len() == tail.len() {
        return None; // `\s+` needs at least one whitespace character
    }
    Some((indent, ordered, content))
}

/// `^\s*>\s?(.*)$` — returns the quoted content.
fn match_blockquote(line: &str) -> Option<&str> {
    let start = after_indent(line);
    let rest = line[start..].strip_prefix('>')?;
    let mut it = rest.chars();
    match it.next() {
        Some(c) if is_space(c) => Some(&rest[c.len_utf8()..]),
        _ => Some(rest),
    }
}

/// `^\s*(#{1,6}\s|```|~~~|[-*+]\s|\d+[.)]\s|>\s?)` — the paragraph terminator.
fn is_structural(line: &str) -> bool {
    let rest = &line[after_indent(line)..];
    if rest.starts_with("```") || rest.starts_with("~~~") || rest.starts_with('>') {
        return true;
    }
    let hashes = rest.chars().take_while(|&c| c == '#').count();
    if (1..=6).contains(&hashes) && rest[hashes..].chars().next().is_some_and(is_space) {
        return true;
    }
    let mut it = rest.chars();
    match it.next() {
        Some('-' | '*' | '+') => it.next().is_some_and(is_space),
        Some(c) if c.is_ascii_digit() => {
            let digits = rest.chars().take_while(char::is_ascii_digit).count();
            let mut tail = rest[digits..].chars();
            match tail.next() {
                Some('.') | Some(')') => tail.next().is_some_and(is_space),
                _ => false,
            }
        }
        _ => false,
    }
}

/// `^\s{0,3}\[([^\]]{1,512})\]:\s*(\S+)(?:\s+.*)?$` — a reference definition.
fn match_ref_def(line: &str) -> Option<(String, String)> {
    let c: Vec<char> = line.chars().collect();
    let mut i = 0;
    while i < c.len() && i < 3 && is_space(c[i]) {
        i += 1;
    }
    if i >= c.len() || c[i] != '[' {
        return None;
    }
    let open = i;
    let mut j = open + 1;
    while j < c.len() && c[j] != ']' {
        j += 1;
    }
    let label_len = j - open - 1;
    if j >= c.len() || !(1..=MD_MAX_SPAN).contains(&label_len) {
        return None;
    }
    if j + 1 >= c.len() || c[j + 1] != ':' {
        return None;
    }
    let mut k = j + 2;
    while k < c.len() && is_space(c[k]) {
        k += 1;
    }
    let ustart = k;
    while k < c.len() && !is_space(c[k]) {
        k += 1;
    }
    if k == ustart {
        return None; // `\S+` needs at least one character
    }
    Some((
        c[open + 1..j].iter().collect(),
        c[ustart..k].iter().collect(),
    ))
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum ListKind {
    Ul,
    Ol,
}

impl ListKind {
    fn tag(self) -> &'static str {
        match self {
            ListKind::Ul => "ul",
            ListKind::Ol => "ol",
        }
    }
}

/// Render a *tiny* safe subset of Markdown to HTML (closer to CommonMark).
///
/// Supported: ATX **and** setext (`===`/`---`) headings, fenced code blocks,
/// nested unordered/ordered lists (including `[ ]`/`[x]` task lists), GitHub
/// pipe tables, multi-line and nested blockquotes, paragraphs with hard line
/// breaks (two trailing spaces), reference-style links (`[text][id]` +
/// `[id]: url`), bold/italic, inline code, images, and bare/angle
/// `<https://…>` autolinks. Everything is HTML-escaped before any structure is
/// applied and every URL passes a scheme allow-list, so no raw HTML is ever
/// passed through. Parsing is single-pass and linear, and blockquote recursion
/// is depth bounded, so it cannot be driven into pathological time.
#[must_use]
pub fn render_markdown(source: &str) -> String {
    render_markdown_inner(source, &Refs::new(), 0)
}

fn render_markdown_inner(source: &str, parent_refs: &Refs, depth: u32) -> String {
    if depth > 8 {
        // Bound nested-blockquote recursion.
        return format!("<pre>{}</pre>", esc(source));
    }
    // Hard size cap: above this, skip inline parsing entirely and serve the
    // source as one escaped `<pre>` block.
    if source.chars().count() > MAX_MARKDOWN_BYTES {
        return format!("<pre>{}</pre>", esc(source));
    }

    let normalized = source.replace("\r\n", "\n").replace('\r', "\n");
    let all_lines: Vec<&str> = normalized.split('\n').collect();

    // -- pass 1: collect reference definitions outside code fences ---------- //
    let mut refs: Refs = parent_refs.clone();
    let mut ref_def_lines: Vec<bool> = vec![false; all_lines.len()];
    let mut any_ref_def = false;
    let mut fenced = false;
    for (idx, ln) in all_lines.iter().enumerate() {
        if match_fence_open(ln).is_some() {
            fenced = !fenced;
            continue;
        }
        if fenced {
            continue;
        }
        if let Some((label, mut url)) = match_ref_def(ln) {
            if url.starts_with('<') && url.ends_with('>') && url.chars().count() >= 2 {
                url = url[1..url.len() - 1].to_string();
            }
            // Store the escaped URL so it can be dropped straight into an href.
            refs_setdefault(&mut refs, strip(&label).to_lowercase(), esc(&url));
            ref_def_lines[idx] = true;
            any_ref_def = true;
        }
    }
    let lines: Vec<&str> = if any_ref_def {
        all_lines
            .iter()
            .enumerate()
            .filter(|(j, _)| !ref_def_lines[*j])
            .map(|(_, l)| *l)
            .collect()
    } else {
        all_lines
    };

    let mut out = String::new();
    let mut i = 0usize;
    let n = lines.len();

    // A stack of open lists. The last <li> of each open list is left *open* so
    // a nested list can be emitted inside it; items/lists are closed as
    // indentation decreases.
    let mut list_stack: Vec<(ListKind, usize)> = Vec::new();

    macro_rules! close_lists {
        () => {
            while let Some((kind, _)) = list_stack.pop() {
                out.push_str(&format!("</li></{}>", kind.tag()));
            }
        };
    }

    while i < n {
        let line = lines[i];

        // Fenced code block.
        if match_fence_open(line).is_some() {
            close_lists!();
            i += 1;
            let mut buf: Vec<String> = Vec::new();
            while i < n && !is_fence_close(lines[i]) {
                buf.push(esc(lines[i]));
                i += 1;
            }
            i += 1; // consume the closing fence (if present)
            out.push_str("<pre><code>");
            out.push_str(&buf.join("\n"));
            out.push_str("</code></pre>");
            continue;
        }

        // ATX heading.
        if let Some((level, htext)) = match_atx(line) {
            close_lists!();
            let htext = strip_atx_close(htext);
            out.push_str(&format!(
                "<h{level}>{}</h{level}>",
                inline(&esc(htext), &refs)
            ));
            i += 1;
            continue;
        }

        // GitHub pipe table (header row followed by a separator row).
        if line.contains('|')
            && !strip(line).is_empty()
            && i + 1 < n
            && is_table_separator(lines[i + 1])
        {
            close_lists!();
            let (html, next) = render_table(&lines, i, n, &refs);
            out.push_str(&html);
            i = next;
            continue;
        }

        // List item (unordered/ordered, with indentation-based nesting).
        if let Some((indent_str, ordered, content)) = match_list_item(line) {
            let indent = expandtabs(indent_str, 4).chars().count();
            let typ = if ordered { ListKind::Ol } else { ListKind::Ul };
            // Close any deeper lists; the parent item that contained a nested
            // list is closed by the sibling branch below (or at the end).
            while let Some(&(kind, ind)) = list_stack.last() {
                if ind > indent {
                    list_stack.pop();
                    out.push_str(&format!("</li></{}>", kind.tag()));
                } else {
                    break;
                }
            }
            match list_stack.last() {
                Some(&(kind, ind)) if ind == indent => {
                    if kind != typ {
                        list_stack.pop();
                        out.push_str(&format!("</li></{}><{}>", kind.tag(), typ.tag()));
                        list_stack.push((typ, indent));
                    } else {
                        out.push_str("</li>"); // close the previous sibling item
                    }
                }
                _ => {
                    out.push_str(&format!("<{}>", typ.tag()));
                    list_stack.push((typ, indent));
                }
            }
            let (attrs, inner_html) = list_item_parts(content, &refs);
            out.push_str(&format!("<li{attrs}>{inner_html}")); // left open
            i += 1;
            continue;
        }

        // Blockquote: gather consecutive '>' lines and render them recursively.
        if match_blockquote(line).is_some() {
            close_lists!();
            let mut buf: Vec<&str> = Vec::new();
            while i < n {
                match match_blockquote(lines[i]) {
                    Some(rest) => buf.push(rest),
                    None => break,
                }
                i += 1;
            }
            let inner_html = render_markdown_inner(&buf.join("\n"), &refs, depth + 1);
            out.push_str(&format!("<blockquote>{inner_html}</blockquote>"));
            continue;
        }

        // Blank line.
        if strip(line).is_empty() {
            close_lists!();
            i += 1;
            continue;
        }

        // Setext heading: a single text line underlined by '===' or '---'.
        if i + 1 < n && is_setext_underline(lines[i + 1], '=') {
            close_lists!();
            out.push_str(&format!("<h1>{}</h1>", inline(&esc(strip(line)), &refs)));
            i += 2;
            continue;
        }
        if i + 1 < n && is_setext_underline(lines[i + 1], '-') {
            close_lists!();
            out.push_str(&format!("<h2>{}</h2>", inline(&esc(strip(line)), &refs)));
            i += 2;
            continue;
        }

        // Paragraph (consecutive non-blank, non-structural lines). A soft break
        // joins with a space; a hard break (two trailing spaces) emits <br>.
        close_lists!();
        let mut para: Vec<&str> = Vec::new();
        while i < n && !strip(lines[i]).is_empty() && !is_structural(lines[i]) {
            // Stop before a setext underline so it can retitle the paragraph
            // above (handled on the next loop turn for a single-line para).
            if !para.is_empty()
                && (is_setext_underline(lines[i], '=') || is_setext_underline(lines[i], '-'))
            {
                break;
            }
            para.push(lines[i]);
            i += 1;
        }
        if para.is_empty() {
            // Progress guard (see the module-level divergence note): the scan
            // came up empty, which in Python spins forever. Only an *indented*
            // ATX heading can reach here, so this is unreachable for every
            // input the reference terminates on.
            para.push(lines[i]);
            i += 1;
        }
        out.push_str("<p>");
        for (k, raw) in para.iter().enumerate() {
            out.push_str(&inline(&esc(strip(raw)), &refs));
            if k < para.len() - 1 {
                out.push_str(if raw.ends_with("  ") { "<br>" } else { " " });
            }
        }
        out.push_str("</p>");
    }

    close_lists!();
    out
}

/// Render a README: the Markdown subset when appropriate, else escaped `<pre>`.
#[must_use]
pub fn render_readme(source: &str, is_markdown: bool) -> String {
    if is_markdown {
        return render_markdown(source);
    }
    format!("<pre>{}</pre>", esc(source))
}

// --------------------------------------------------------------------------- //
// Unified-diff parsing
// --------------------------------------------------------------------------- //

/// The classification of one line inside a [`DiffFile`].
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum DiffKind {
    /// An added line (`+…`).
    Add,
    /// A removed line (`-…`).
    Del,
    /// An unchanged context line.
    Ctx,
    /// A hunk header (`@@ … @@`).
    Hunk,
    /// Anything outside a hunk, plus the `\ No newline at end of file` marker.
    Meta,
}

impl DiffKind {
    /// The Python reference's string spelling of this kind.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            DiffKind::Add => "add",
            DiffKind::Del => "del",
            DiffKind::Ctx => "ctx",
            DiffKind::Hunk => "hunk",
            DiffKind::Meta => "meta",
        }
    }
}

/// One classified line of a unified diff.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct DiffLine {
    /// How the line should be rendered.
    pub kind: DiffKind,
    /// The raw line, verbatim (leading `+`/`-`/`@@` included).
    pub text: String,
}

/// What a diff does to one file.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Default)]
pub enum FileStatus {
    /// `new file mode …`
    Added,
    /// `deleted file mode …`
    Deleted,
    /// `rename from` / `rename to`
    Renamed,
    /// The default: content changed in place.
    #[default]
    Modified,
    /// `Binary files …` / `GIT binary patch`
    Binary,
}

impl FileStatus {
    /// The Python reference's string spelling of this status.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            FileStatus::Added => "added",
            FileStatus::Deleted => "deleted",
            FileStatus::Renamed => "renamed",
            FileStatus::Modified => "modified",
            FileStatus::Binary => "binary",
        }
    }
}

/// One file's worth of a parsed unified diff.
#[derive(Clone, Debug, PartialEq, Eq, Default)]
pub struct DiffFile {
    /// The `a/` path from the `diff --git` header.
    pub old_path: String,
    /// The `b/` path from the `diff --git` header.
    pub new_path: String,
    /// What the diff does to this file.
    pub status: FileStatus,
    /// True when git reported the change as binary.
    pub binary: bool,
    /// Number of `+` lines inside hunks.
    pub additions: usize,
    /// Number of `-` lines inside hunks.
    pub deletions: usize,
    /// Every line of the file's diff, classified.
    pub lines: Vec<DiffLine>,
}

impl DiffFile {
    /// The path to show in a heading — `old -> new` for a rename.
    #[must_use]
    pub fn display_path(&self) -> String {
        if self.status == FileStatus::Renamed && self.old_path != self.new_path {
            return format!("{} -> {}", self.old_path, self.new_path);
        }
        if self.new_path.is_empty() {
            self.old_path.clone()
        } else {
            self.new_path.clone()
        }
    }
}

/// `^diff --git a/(.*) b/(.*)$` — the greedy first group makes the split happen
/// at the **last** ` b/` in the line.
fn match_diff_git(line: &str) -> Option<(&str, &str)> {
    let rest = line.strip_prefix("diff --git a/")?;
    let at = rest.rfind(" b/")?;
    Some((&rest[..at], &rest[at + 3..]))
}

/// Parse a unified diff (`git show` output) into per-file records.
#[must_use]
pub fn parse_patch(patch: &str) -> Vec<DiffFile> {
    let mut files: Vec<DiffFile> = Vec::new();
    let mut in_hunk = false;

    for line in patch.split('\n') {
        if let Some((old, new)) = match_diff_git(line) {
            files.push(DiffFile {
                old_path: old.to_string(),
                new_path: new.to_string(),
                ..DiffFile::default()
            });
            in_hunk = false;
            continue;
        }
        let Some(cur) = files.last_mut() else {
            continue;
        };

        if line.starts_with("new file mode") {
            cur.status = FileStatus::Added;
            continue;
        }
        if line.starts_with("deleted file mode") {
            cur.status = FileStatus::Deleted;
            continue;
        }
        if line.starts_with("rename from") || line.starts_with("rename to") {
            cur.status = FileStatus::Renamed;
            continue;
        }
        if line.starts_with("Binary files") || line.starts_with("GIT binary patch") {
            cur.binary = true;
            cur.status = FileStatus::Binary;
            continue;
        }
        if line.starts_with("index ")
            || line.starts_with("similarity ")
            || line.starts_with("dissimilarity ")
        {
            continue;
        }
        if line.starts_with("--- ") || line.starts_with("+++ ") {
            continue;
        }
        if line.starts_with("@@") {
            in_hunk = true;
            cur.lines.push(DiffLine {
                kind: DiffKind::Hunk,
                text: line.to_string(),
            });
            continue;
        }
        if !in_hunk {
            cur.lines.push(DiffLine {
                kind: DiffKind::Meta,
                text: line.to_string(),
            });
            continue;
        }
        let kind = if line.starts_with('+') {
            cur.additions += 1;
            DiffKind::Add
        } else if line.starts_with('-') {
            cur.deletions += 1;
            DiffKind::Del
        } else if line.starts_with('\\') {
            DiffKind::Meta
        } else {
            DiffKind::Ctx
        };
        cur.lines.push(DiffLine {
            kind,
            text: line.to_string(),
        });
    }

    files
}
