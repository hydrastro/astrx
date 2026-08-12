//! CPython-compatible primitives — the byte-identity substrate of the port.
//!
//! Every rendered number, stripped string and split line in `suitedash` has to
//! come out byte-identical to the Python reference, and Rust's stdlib differs
//! from CPython in small ways that would silently corrupt a golden:
//!
//! * `str.strip()`/`str.split()` and `re`'s `\s` treat the four C0 separators
//!   `\x1c`–`\x1f` as whitespace; [`char::is_whitespace`] does not.
//! * `str.splitlines()` breaks on `\v`, `\f`, `\x1c`–`\x1e`, `\x85`, `\u{2028}`
//!   and `\u{2029}` as well as `\n`/`\r`/`\r\n`.
//! * `float(s)` accepts `_` digit separators (`float("1_000") == 1000.0`), which
//!   `str::parse::<f64>()` rejects — and the exporter's canonicalisation of
//!   `grouped 1_000` depends on it.
//! * `repr(f)` switches to exponential notation outside `10**-4 ..= 10**16`
//!   and always keeps a `.0`, where Rust's `{}` never uses an exponent.
//! * `int(f)` is exact for every finite float, including the 309-digit
//!   `int(1e300)`, where `f as i64` saturates.
//!
//! Everything here is `pub(crate)` — an implementation detail of the port, not
//! part of the crate's API. The float formatters are cross-checked against
//! CPython by the unit tests at the bottom of this file (goldens emitted by
//! `tests/regen_goldens.py`, section `gen_pyfmt`).

/// Python's whitespace class (`str.strip()`, `str.split()`, `re`'s `\s`):
/// Unicode `White_Space` plus the C0 separators `\x1c`–`\x1f`.
pub(crate) fn is_space(c: char) -> bool {
    c.is_whitespace() || ('\u{1c}'..='\u{1f}').contains(&c)
}

/// Python `s.strip()`.
pub(crate) fn strip(s: &str) -> &str {
    s.trim_matches(is_space)
}

/// Python `s.lstrip()`.
pub(crate) fn lstrip(s: &str) -> &str {
    s.trim_start_matches(is_space)
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

/// Python `s.split()` (no separator): split on runs of whitespace, dropping the
/// leading/trailing empties.
pub(crate) fn split_whitespace(s: &str) -> Vec<&str> {
    s.split(is_space).filter(|t| !t.is_empty()).collect()
}

/// Remove `_` digit separators, rejecting any that is not flanked by two digits
/// (CPython's `float`/`int` underscore rule).
fn strip_underscores(s: &str) -> Option<String> {
    if !s.contains('_') {
        return Some(s.to_string());
    }
    let chars: Vec<char> = s.chars().collect();
    let mut out = String::with_capacity(s.len());
    for (i, &c) in chars.iter().enumerate() {
        if c != '_' {
            out.push(c);
            continue;
        }
        let prev_ok = i > 0 && chars[i - 1].is_ascii_digit();
        let next_ok = chars.get(i + 1).is_some_and(char::is_ascii_digit);
        if !prev_ok || !next_ok {
            return None;
        }
    }
    Some(out)
}

/// Python `float(s)`: `None` where CPython raises `ValueError`.
///
/// Accepts surrounding whitespace, a sign, `inf`/`infinity`/`nan` in any case,
/// and a decimal significand/exponent with `_` separators between digits.
///
/// **Divergence (documented):** CPython also accepts non-ASCII Unicode decimal
/// digits (`float("١٢٣") == 123.0`); those are rejected here, since the stdlib
/// exposes no Unicode decimal-digit table. Every other accepted spelling is
/// reproduced.
pub(crate) fn py_float(s: &str) -> Option<f64> {
    let t = strip(s);
    if t.is_empty() {
        return None;
    }
    let body = t.strip_prefix(['+', '-']).unwrap_or(t);
    let neg = t.starts_with('-');
    let lower = body.to_ascii_lowercase();
    if lower == "inf" || lower == "infinity" {
        return Some(if neg {
            f64::NEG_INFINITY
        } else {
            f64::INFINITY
        });
    }
    if lower == "nan" {
        return Some(f64::NAN);
    }
    // Grammar: digits[.digits][(e|E)[sign]digits] with at least one significand
    // digit, validated before the underscores are removed.
    let cleaned = strip_underscores(body)?;
    let b: Vec<char> = cleaned.chars().collect();
    let mut i = 0usize;
    let mut mantissa_digits = 0usize;
    while i < b.len() && b[i].is_ascii_digit() {
        i += 1;
        mantissa_digits += 1;
    }
    if i < b.len() && b[i] == '.' {
        i += 1;
        while i < b.len() && b[i].is_ascii_digit() {
            i += 1;
            mantissa_digits += 1;
        }
    }
    if mantissa_digits == 0 {
        return None;
    }
    if i < b.len() && (b[i] == 'e' || b[i] == 'E') {
        i += 1;
        if i < b.len() && (b[i] == '+' || b[i] == '-') {
            i += 1;
        }
        let mut exp_digits = 0usize;
        while i < b.len() && b[i].is_ascii_digit() {
            i += 1;
            exp_digits += 1;
        }
        if exp_digits == 0 {
            return None;
        }
    }
    if i != b.len() {
        return None;
    }
    let v: f64 = cleaned.parse().ok()?;
    Some(if neg { -v } else { v })
}

/// Python `int(s)` (base 10): `None` where CPython raises `ValueError`.
///
/// **Divergence (documented):** CPython integers are unbounded; a literal
/// outside `i64` is rejected here rather than parsed exactly.
pub(crate) fn py_int_str(s: &str) -> Option<i64> {
    let t = strip(s);
    let body = t.strip_prefix(['+', '-']).unwrap_or(t);
    let cleaned = strip_underscores(body)?;
    if cleaned.is_empty() || !cleaned.chars().all(|c| c.is_ascii_digit()) {
        return None;
    }
    let signed = if t.starts_with('-') {
        format!("-{cleaned}")
    } else {
        cleaned
    };
    signed.parse().ok()
}

/// `true` when `f` is what Python's `float.is_integer()` calls integral
/// (non-finite values are never integral).
pub(crate) fn is_integral(f: f64) -> bool {
    f.is_finite() && f.fract() == 0.0
}

/// Python `repr(f)` for a **finite** float: shortest round-tripping digits,
/// exponential notation when the decimal point falls outside
/// `-4 < decpt <= 16`, and an added `.0` when fixed notation has no fraction.
pub(crate) fn repr_f64(f: f64) -> String {
    if f.is_nan() {
        return "nan".to_string();
    }
    if f.is_infinite() {
        return if f < 0.0 { "-inf" } else { "inf" }.to_string();
    }
    // Rust's LowerExp emits the same shortest round-tripping digit string that
    // CPython's repr uses, in a canonical `[-]d[.ddd]e[-]dd` form.
    let sci = format!("{f:e}");
    let (mant, exp) = sci.split_once('e').unwrap_or((sci.as_str(), "0"));
    let neg = mant.starts_with('-');
    let mant = mant.trim_start_matches('-');
    let digits: String = mant.chars().filter(|c| *c != '.').collect();
    let exp: i32 = exp.parse().unwrap_or(0);
    let decpt = exp + 1;
    let body = if decpt <= -4 || decpt > 16 {
        let mut m = digits[..1].to_string();
        if digits.len() > 1 {
            m.push('.');
            m.push_str(&digits[1..]);
        }
        let e = decpt - 1;
        format!("{m}e{}{:02}", if e < 0 { '-' } else { '+' }, e.abs())
    } else if decpt <= 0 {
        format!("0.{}{}", "0".repeat((-decpt) as usize), digits)
    } else if (decpt as usize) >= digits.len() {
        format!("{}{}.0", digits, "0".repeat(decpt as usize - digits.len()))
    } else {
        format!(
            "{}.{}",
            &digits[..decpt as usize],
            &digits[decpt as usize..]
        )
    };
    if neg {
        format!("-{body}")
    } else {
        body
    }
}

/// Python `str(int(f))` for an **integral finite** float — exact for every
/// magnitude, including the 309 digits of `int(1e300)`.
pub(crate) fn int_str_f64(f: f64) -> String {
    if !f.is_finite() {
        return repr_f64(f);
    }
    let neg = f < 0.0;
    let a = f.abs().trunc();
    if a < 9.007_199_254_740_992e15 {
        // Exactly representable in i64 — no bignum needed.
        let n = a as i64;
        return if neg && n != 0 {
            format!("-{n}")
        } else {
            n.to_string()
        };
    }
    // a = mantissa * 2^exp2 with exp2 >= 0; expand by repeated doubling.
    let bits = a.to_bits();
    let raw_exp = ((bits >> 52) & 0x7ff) as i64;
    let frac = bits & 0x000f_ffff_ffff_ffff;
    let (mantissa, exp2) = if raw_exp == 0 {
        (frac, -1074i64)
    } else {
        (frac | (1u64 << 52), raw_exp - 1075)
    };
    let mut digits: Vec<u8> = Vec::new(); // little-endian decimal digits
    let mut m = mantissa;
    if m == 0 {
        digits.push(0);
    }
    while m > 0 {
        digits.push((m % 10) as u8);
        m /= 10;
    }
    for _ in 0..exp2.max(0) {
        let mut carry = 0u8;
        for d in digits.iter_mut() {
            let v = *d * 2 + carry;
            *d = v % 10;
            carry = v / 10;
        }
        if carry > 0 {
            digits.push(carry);
        }
    }
    let mut out = String::with_capacity(digits.len() + 1);
    if neg {
        out.push('-');
    }
    for d in digits.iter().rev() {
        out.push((b'0' + d) as char);
    }
    out
}

/// Python `round(f)` (no `ndigits`): nearest integer, ties to even, as a float.
pub(crate) fn round_half_even(f: f64) -> f64 {
    f.round_ties_even()
}

/// Python `round(f, ndigits)`: correctly rounded at `ndigits` decimal places
/// (ties to even), then converted back to the nearest double — exactly what
/// CPython's `_Py_dg_dtoa`/`_Py_dg_strtod` round-trip does.
pub(crate) fn round_ndigits(f: f64, ndigits: usize) -> f64 {
    if !f.is_finite() {
        return f;
    }
    fixed(f, ndigits).parse().unwrap_or(f)
}

/// Python `"%.*f" % v` — Rust's `{:.n$}` produces the same correctly-rounded
/// (ties-to-even) digits and the same `inf`/`-inf` spelling, but writes NaN as
/// `NaN` where CPython writes `nan`.
pub(crate) fn fixed(v: f64, precision: usize) -> String {
    if v.is_nan() {
        return "nan".to_string();
    }
    format!("{v:.precision$}")
}

/// Python `format(n, ",")` grouping applied to an already-formatted decimal:
/// commas every three digits of the integer part, sign and fraction preserved.
pub(crate) fn group_thousands(s: &str) -> String {
    let (sign, rest) = match s.strip_prefix('-') {
        Some(r) => ("-", r),
        None => ("", s),
    };
    let (int_part, frac) = match rest.split_once('.') {
        Some((i, f)) => (i, Some(f)),
        None => (rest, None),
    };
    let mut grouped = String::with_capacity(int_part.len() * 4 / 3 + 2);
    for (i, c) in int_part.chars().enumerate() {
        if i > 0 && (int_part.len() - i) % 3 == 0 {
            grouped.push(',');
        }
        grouped.push(c);
    }
    let mut out = String::with_capacity(sign.len() + grouped.len() + 8);
    out.push_str(sign);
    out.push_str(&grouped);
    if let Some(f) = frac {
        out.push('.');
        out.push_str(f);
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

#[cfg(test)]
mod tests {
    use super::*;

    /// Cross-check: `(value, repr(v), str(int(v)) or "", '%.6f' % v)` emitted by
    /// `tests/regen_goldens.py` (`gen_pyfmt`) from the real CPython.
    #[test]
    fn float_formatting_matches_cpython() {
        let cases: &[(f64, &str, &str, &str)] = &[
            (0.0, "0.0", "0", "0.000000"),
            (-0.0, "-0.0", "0", "-0.000000"),
            (1.0, "1.0", "1", "1.000000"),
            (-1.0, "-1.0", "-1", "-1.000000"),
            (0.5, "0.5", "", "0.500000"),
            (1.5, "1.5", "", "1.500000"),
            (0.1, "0.1", "", "0.100000"),
            (
                1.0 / 3.0,
                "0.3333333333333333",
                "",
                "0.333333",
            ),
            (2.675, "2.675", "", "2.675000"),
            (0.125, "0.125", "", "0.125000"),
            (100.0, "100.0", "100", "100.000000"),
            (1234.5, "1234.5", "", "1234.500000"),
            (0.0001, "0.0001", "", "0.000100"),
            (1e-05, "1e-05", "", "0.000010"),
            (1e-07, "1e-07", "", "0.000000"),
            (1e15, "1000000000000000.0", "1000000000000000", "1000000000000000.000000"),
            (1e16, "1e+16", "10000000000000000", "10000000000000000.000000"),
            (1.5e16, "1.5e+16", "15000000000000000", "15000000000000000.000000"),
            (
                1.2345678901234567e19,
                "1.2345678901234567e+19",
                "12345678901234567168",
                "12345678901234567168.000000",
            ),
            (123.456789012, "123.456789012", "", "123.456789"),
            (-2.5, "-2.5", "", "-2.500000"),
            (1e300, "1e+300", "1000000000000000052504760255204420248704468581108159154915854115511802457988908195786371375080447864043704443832883878176942523235360430575644792184786706982848387200926575803737830233794788090059368953234970799945081119038967640880074652742780142494579258788820056842838115669472196386865459400540160", "1000000000000000052504760255204420248704468581108159154915854115511802457988908195786371375080447864043704443832883878176942523235360430575644792184786706982848387200926575803737830233794788090059368953234970799945081119038967640880074652742780142494579258788820056842838115669472196386865459400540160.000000"),
            (5e-324, "5e-324", "", "0.000000"),
            (2.2250738585072014e-308, "2.2250738585072014e-308", "", "0.000000"),
        ];
        for (v, want_repr, want_int, want_f6) in cases {
            assert_eq!(&repr_f64(*v), want_repr, "repr({v})");
            if !want_int.is_empty() {
                assert_eq!(&int_str_f64(*v), want_int, "int({v})");
            }
            assert_eq!(&format!("{v:.6}"), want_f6, "%.6f of {v}");
        }
    }

    #[test]
    fn round_matches_cpython() {
        // (x, round(x, 6), round(x)) from CPython.
        let cases: &[(f64, f64, f64)] = &[
            (0.3333333333333333, 0.333333, 0.0),
            (123.456789012, 123.456789, 123.0),
            (5e-07, 0.0, 0.0),
            (2.5, 2.5, 2.0),
            (3.5, 3.5, 4.0),
            (-2.5, -2.5, -2.0),
            (0.5, 0.5, 0.0),
            (1.5, 1.5, 2.0),
            (2.0000005, 2.000001, 2.0),
            (1e300, 1e300, 1e300),
        ];
        for (x, want6, want0) in cases {
            assert_eq!(round_ndigits(*x, 6), *want6, "round({x}, 6)");
            assert_eq!(round_half_even(*x), *want0, "round({x})");
        }
    }

    #[test]
    fn py_float_matches_cpython() {
        // Accepted spellings (value) and rejections (None), from CPython.
        assert_eq!(py_float("1_000"), Some(1000.0));
        assert_eq!(py_float("  1.5  "), Some(1.5));
        assert_eq!(py_float("+2"), Some(2.0));
        assert_eq!(py_float("-2.5e3"), Some(-2500.0));
        assert_eq!(py_float(".5"), Some(0.5));
        assert_eq!(py_float("5."), Some(5.0));
        assert_eq!(py_float("1e1_0"), Some(1e10));
        assert_eq!(py_float("Infinity"), Some(f64::INFINITY));
        assert_eq!(py_float("-inf"), Some(f64::NEG_INFINITY));
        assert!(py_float("NaN").is_some_and(f64::is_nan));
        assert_eq!(py_float(""), None);
        assert_eq!(py_float("abc"), None);
        assert_eq!(py_float("."), None);
        assert_eq!(py_float("_1"), None);
        assert_eq!(py_float("1_"), None);
        assert_eq!(py_float("1__0"), None);
        assert_eq!(py_float("1e"), None);
        assert_eq!(py_float("0x10"), None);
        assert_eq!(py_float("1 2"), None);
    }

    #[test]
    fn py_int_str_matches_cpython() {
        assert_eq!(py_int_str(" 42 "), Some(42));
        assert_eq!(py_int_str("-7"), Some(-7));
        assert_eq!(py_int_str("1_000"), Some(1000));
        assert_eq!(py_int_str("3.5"), None);
        assert_eq!(py_int_str(""), None);
        assert_eq!(py_int_str("0x10"), None);
    }

    #[test]
    fn splitlines_matches_cpython() {
        assert_eq!(splitlines(""), Vec::<&str>::new());
        assert_eq!(splitlines("a\nb"), vec!["a", "b"]);
        assert_eq!(splitlines("a\r\nb\n"), vec!["a", "b"]);
        assert_eq!(splitlines("a\rb"), vec!["a", "b"]);
        assert_eq!(splitlines("a\u{b}b\u{c}c\u{85}d"), vec!["a", "b", "c", "d"]);
        assert_eq!(splitlines("\n"), vec![""]);
    }

    #[test]
    fn grouping_matches_cpython() {
        assert_eq!(group_thousands("1234567"), "1,234,567");
        assert_eq!(group_thousands("-1234567.891234"), "-1,234,567.891234");
        assert_eq!(group_thousands("100"), "100");
        assert_eq!(group_thousands("1000"), "1,000");
    }
}
