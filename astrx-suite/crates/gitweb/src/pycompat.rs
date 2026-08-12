//! CPython-compatible primitives — the byte-identity substrate of the port.
//!
//! Every escaped fragment, stripped string and rendered date in `gitweb` has to
//! come out byte-identical to the Python reference, and Rust's stdlib differs
//! from CPython in small ways that would silently corrupt a golden:
//!
//! * `str.strip()`/`str.split()` and `re`'s `\s` treat the four C0 separators
//!   `\x1c`–`\x1f` as whitespace; [`char::is_whitespace`] does not.
//! * `str.expandtabs(4)` (which `render_markdown` uses to measure list-item
//!   indentation) advances to the next tab stop and resets the column on
//!   `\n`/`\r`; there is no stdlib equivalent.
//! * `str.split(None, 1)` splits on a *run* of whitespace and keeps the rest of
//!   the string verbatim (trailing whitespace included), unlike
//!   [`str::splitn`] on a single separator.
//! * `time.strftime("%Y-…", time.gmtime(ts))` renders the year *unpadded*
//!   (`gmtime(-62135596800)` → `1-01-01`) and accepts negative timestamps.
//!
//! Everything here is `pub(crate)` — an implementation detail of the port, not
//! part of the crate's API. It deliberately mirrors the equivalent private
//! module in `suitedash` so the two crates behave identically; there is no
//! cross-crate dependency between them.

/// Python's whitespace class (`str.strip()`, `str.split()`, `re`'s `\s`):
/// Unicode `White_Space` plus the C0 separators `\x1c`–`\x1f`.
pub(crate) fn is_space(c: char) -> bool {
    c.is_whitespace() || ('\u{1c}'..='\u{1f}').contains(&c)
}

/// Python's `\w` (and `str.isalnum()` + `_`): a Unicode alphanumeric or `_`.
pub(crate) fn is_word(c: char) -> bool {
    c.is_alphanumeric() || c == '_'
}

/// Python `s.strip()`.
pub(crate) fn strip(s: &str) -> &str {
    s.trim_matches(is_space)
}

/// Python `s.lstrip()`.
pub(crate) fn lstrip(s: &str) -> &str {
    s.trim_start_matches(is_space)
}

/// Python `s.rstrip()`.
pub(crate) fn rstrip(s: &str) -> &str {
    s.trim_end_matches(is_space)
}

/// Python `s.rstrip(chars)` for an explicit cut set.
pub(crate) fn rstrip_chars<'a>(s: &'a str, chars: &str) -> &'a str {
    s.trim_end_matches(|c| chars.contains(c))
}

/// Python `s.splitlines()` — no trailing empty element for a final break, and
/// `[]` for the empty string.
pub(crate) fn splitlines(s: &str) -> Vec<&str> {
    let mut out = Vec::new();
    let mut start = 0usize;
    let mut it = s.char_indices().peekable();
    while let Some((i, c)) = it.next() {
        if !matches!(
            c,
            '\n' | '\r'
                | '\u{b}'
                | '\u{c}'
                | '\u{1c}'
                | '\u{1d}'
                | '\u{1e}'
                | '\u{85}'
                | '\u{2028}'
                | '\u{2029}'
        ) {
            continue;
        }
        out.push(&s[start..i]);
        let mut next = i + c.len_utf8();
        if c == '\r' {
            if let Some(&(j, '\n')) = it.peek() {
                it.next();
                next = j + 1;
            }
        }
        start = next;
    }
    if start < s.len() {
        out.push(&s[start..]);
    }
    out
}

/// Python `s.split(None, maxsplit)`: split on runs of whitespace, dropping the
/// leading empties, and stop after `maxsplit` splits (the remainder — trailing
/// whitespace and all — becomes the final element).
pub(crate) fn split_whitespace_maxsplit(s: &str, maxsplit: usize) -> Vec<&str> {
    let mut out: Vec<&str> = Vec::new();
    let mut rest = s;
    loop {
        rest = lstrip(rest);
        if rest.is_empty() {
            return out;
        }
        if out.len() == maxsplit {
            out.push(rest);
            return out;
        }
        match rest.find(is_space) {
            Some(i) => {
                out.push(&rest[..i]);
                rest = &rest[i..];
            }
            None => {
                out.push(rest);
                return out;
            }
        }
    }
}

/// Python `s.expandtabs(tabsize)`: a tab advances to the next multiple of
/// `tabsize`; `\n`/`\r` reset the column.
pub(crate) fn expandtabs(s: &str, tabsize: usize) -> String {
    let mut out = String::with_capacity(s.len());
    let mut col = 0usize;
    for c in s.chars() {
        match c {
            '\t' => {
                if tabsize > 0 {
                    let incr = tabsize - (col % tabsize);
                    col += incr;
                    for _ in 0..incr {
                        out.push(' ');
                    }
                }
            }
            '\n' | '\r' => {
                out.push(c);
                col = 0;
            }
            _ => {
                out.push(c);
                col += 1;
            }
        }
    }
    out
}

/// HTML-escape (`&`, `<`, `>`, `"`, `'`) — Python `html.escape(quote=True)`.
pub(crate) fn html_escape(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#x27;"),
            _ => out.push(c),
        }
    }
    out
}

/// A broken-down UTC time, the fields of Python's `time.struct_time` that the
/// `gitweb` date helpers format.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) struct Tm {
    pub(crate) year: i64,
    pub(crate) mon: u32,
    pub(crate) day: u32,
    pub(crate) hour: u32,
    pub(crate) min: u32,
    pub(crate) sec: u32,
}

/// Python `time.gmtime(ts)` — civil UTC time from a unix timestamp, correct for
/// negative values (floor division, unlike Rust's truncating `/` and `%`).
pub(crate) fn gmtime(ts: i64) -> Tm {
    let days = ts.div_euclid(86_400);
    let secs = ts.rem_euclid(86_400);
    let (year, mon, day) = civil_from_days(days);
    Tm {
        year,
        mon,
        day,
        hour: (secs / 3600) as u32,
        min: ((secs / 60) % 60) as u32,
        sec: (secs % 60) as u32,
    }
}

/// Howard Hinnant's `civil_from_days`: days since 1970-01-01 → (y, m, d).
fn civil_from_days(z: i64) -> (i64, u32, u32) {
    let z = z + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { y + 1 } else { y }, m as u32, d as u32)
}

/// Python `calendar.timegm((y, mon, day, hour, min, sec, …))` — the inverse of
/// [`gmtime`], with no range or field validation (matching the stdlib).
pub(crate) fn timegm(year: i64, mon: i64, day: i64, hour: i64, min: i64, sec: i64) -> i64 {
    days_from_civil(year, mon, day) * 86_400 + hour * 3600 + min * 60 + sec
}

/// Howard Hinnant's `days_from_civil`: (y, m, d) → days since 1970-01-01.
fn days_from_civil(y: i64, m: i64, d: i64) -> i64 {
    let y = if m <= 2 { y - 1 } else { y };
    let era = y.div_euclid(400);
    let yoe = y - era * 400;
    let mp = if m > 2 { m - 3 } else { m + 9 };
    let doy = (153 * mp + 2) / 5 + d - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe - 719_468
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn whitespace_class_matches_python() {
        assert!(is_space('\u{1c}'));
        assert!(is_space('\u{1f}'));
        assert!(is_space('\u{a0}'));
        assert!(!is_space('\u{1b}'));
        assert!(is_space(' '));
        assert!(!is_space('a'));
        assert_eq!(strip("\u{1c} a \u{1f}"), "a");
    }

    #[test]
    fn split_maxsplit_keeps_the_remainder_verbatim() {
        assert_eq!(
            split_whitespace_maxsplit("Basic  abc  ", 1),
            ["Basic", "abc  "]
        );
        assert_eq!(
            split_whitespace_maxsplit("  Basic abc def", 1),
            ["Basic", "abc def"]
        );
        assert_eq!(split_whitespace_maxsplit("Basic", 1), ["Basic"]);
        assert!(split_whitespace_maxsplit("   ", 1).is_empty());
        assert!(split_whitespace_maxsplit("", 1).is_empty());
    }

    #[test]
    fn expandtabs_matches_python() {
        assert_eq!(expandtabs("\t", 4), "    ");
        assert_eq!(expandtabs("a\tb", 4), "a   b");
        assert_eq!(expandtabs("abcd\te", 4), "abcd    e");
        assert_eq!(expandtabs("  \t", 4), "    ");
    }

    #[test]
    fn gmtime_matches_python() {
        // (ts, y, mon, day, h, m, s) from CPython's time.gmtime.
        let cases: &[(i64, i64, u32, u32, u32, u32, u32)] = &[
            (0, 1970, 1, 1, 0, 0, 0),
            (1, 1970, 1, 1, 0, 0, 1),
            (86_400, 1970, 1, 2, 0, 0, 0),
            (1_700_000_000, 2023, 11, 14, 22, 13, 20),
            (951_782_400, 2000, 2, 29, 0, 0, 0),
            (-1, 1969, 12, 31, 23, 59, 59),
            (-86_400, 1969, 12, 31, 0, 0, 0),
            (-62_135_596_800, 1, 1, 1, 0, 0, 0),
            (253_402_300_799, 9999, 12, 31, 23, 59, 59),
        ];
        for &(ts, y, mo, d, h, mi, s) in cases {
            let t = gmtime(ts);
            assert_eq!(
                (t.year, t.mon, t.day, t.hour, t.min, t.sec),
                (y, mo, d, h, mi, s),
                "gmtime({ts})"
            );
            assert_eq!(
                timegm(y, mo as i64, d as i64, h as i64, mi as i64, s as i64),
                ts,
                "timegm({ts})"
            );
        }
    }
}
