//! A small, dependency-free JSON parser (RFC 8259) — the stdlib `json.loads`
//! stand-in used by the crawlers' structured-data recovery (JSON-LD, SPA state
//! blobs).
//!
//! Parses hostile, attacker-controlled input, so it is **bounded**: recursion is
//! capped at [`MAX_DEPTH`] (deeper nesting is a clean error, never a stack
//! overflow), matching the spirit of Python's recursion limit while staying
//! `#![forbid(unsafe_code)]`. Object key order is preserved (like a Python
//! `dict`), and a duplicate key keeps the **last** value at its first position —
//! exactly `json.loads`'s default behaviour.
//!
//! Numbers are represented as `f64` (adequate for the recovery use — the callers
//! only read string leaves and coerce the occasional numeric duration); the
//! int/float distinction of `json.loads` is not preserved.

use std::fmt;

/// Maximum nesting depth. Deeper input is rejected rather than recursed.
pub const MAX_DEPTH: usize = 200;

/// A parsed JSON value. Objects preserve insertion order.
#[derive(Clone, Debug, PartialEq)]
pub enum Value {
    /// `null`.
    Null,
    /// `true` / `false`.
    Bool(bool),
    /// A number (ints and floats both as `f64`).
    Num(f64),
    /// A string.
    Str(String),
    /// An array.
    Array(Vec<Value>),
    /// An object (insertion-ordered; duplicate keys keep the last value).
    Object(Vec<(String, Value)>),
}

impl Value {
    /// The string, if this is a `Str`.
    #[must_use]
    pub fn as_str(&self) -> Option<&str> {
        match self {
            Value::Str(s) => Some(s),
            _ => None,
        }
    }

    /// The number, if this is a `Num`.
    #[must_use]
    pub fn as_f64(&self) -> Option<f64> {
        match self {
            Value::Num(n) => Some(*n),
            _ => None,
        }
    }

    /// The boolean, if this is a `Bool`.
    #[must_use]
    pub fn as_bool(&self) -> Option<bool> {
        match self {
            Value::Bool(b) => Some(*b),
            _ => None,
        }
    }

    /// The elements, if this is an `Array`.
    #[must_use]
    pub fn as_array(&self) -> Option<&[Value]> {
        match self {
            Value::Array(v) => Some(v),
            _ => None,
        }
    }

    /// The key/value pairs, if this is an `Object`.
    #[must_use]
    pub fn as_object(&self) -> Option<&[(String, Value)]> {
        match self {
            Value::Object(v) => Some(v),
            _ => None,
        }
    }

    /// True if this is `Null`.
    #[must_use]
    pub fn is_null(&self) -> bool {
        matches!(self, Value::Null)
    }

    /// The value for `key` (case-sensitive), if this is an object holding it.
    /// Returns the value of the **last** matching key (`json.loads` semantics).
    #[must_use]
    pub fn get(&self, key: &str) -> Option<&Value> {
        match self {
            Value::Object(pairs) => pairs.iter().rev().find(|(k, _)| k == key).map(|(_, v)| v),
            _ => None,
        }
    }
}

/// A JSON parse error (position + message).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct JsonError {
    /// Character offset where parsing failed.
    pub pos: usize,
    /// A human-readable message.
    pub msg: String,
}

impl fmt::Display for JsonError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "json error at {}: {}", self.pos, self.msg)
    }
}

impl std::error::Error for JsonError {}

/// Parse a JSON document. Trailing non-whitespace is an error.
///
/// # Errors
/// [`JsonError`] on malformed input or nesting deeper than [`MAX_DEPTH`].
pub fn parse(input: &str) -> Result<Value, JsonError> {
    let chars: Vec<char> = input.chars().collect();
    let mut p = Parser {
        chars: &chars,
        pos: 0,
    };
    p.skip_ws();
    let v = p.parse_value(0)?;
    p.skip_ws();
    if p.pos != p.chars.len() {
        return Err(p.err("trailing data after JSON value"));
    }
    Ok(v)
}

struct Parser<'a> {
    chars: &'a [char],
    pos: usize,
}

impl Parser<'_> {
    fn err(&self, msg: &str) -> JsonError {
        JsonError {
            pos: self.pos,
            msg: msg.to_string(),
        }
    }

    fn peek(&self) -> Option<char> {
        self.chars.get(self.pos).copied()
    }

    fn bump(&mut self) -> Option<char> {
        let c = self.chars.get(self.pos).copied();
        if c.is_some() {
            self.pos += 1;
        }
        c
    }

    fn skip_ws(&mut self) {
        while let Some(c) = self.peek() {
            if c == ' ' || c == '\t' || c == '\n' || c == '\r' {
                self.pos += 1;
            } else {
                break;
            }
        }
    }

    fn parse_value(&mut self, depth: usize) -> Result<Value, JsonError> {
        if depth > MAX_DEPTH {
            return Err(self.err("maximum nesting depth exceeded"));
        }
        self.skip_ws();
        match self.peek() {
            Some('{') => self.parse_object(depth),
            Some('[') => self.parse_array(depth),
            Some('"') => Ok(Value::Str(self.parse_string()?)),
            Some('t') | Some('f') => self.parse_bool(),
            Some('n') => self.parse_null(),
            Some(c) if c == '-' || c.is_ascii_digit() => self.parse_number(),
            Some(_) => Err(self.err("unexpected character")),
            None => Err(self.err("unexpected end of input")),
        }
    }

    fn expect(&mut self, lit: &str, val: Value) -> Result<Value, JsonError> {
        for want in lit.chars() {
            if self.bump() != Some(want) {
                return Err(self.err("invalid literal"));
            }
        }
        Ok(val)
    }

    fn parse_bool(&mut self) -> Result<Value, JsonError> {
        if self.peek() == Some('t') {
            self.expect("true", Value::Bool(true))
        } else {
            self.expect("false", Value::Bool(false))
        }
    }

    fn parse_null(&mut self) -> Result<Value, JsonError> {
        self.expect("null", Value::Null)
    }

    fn parse_number(&mut self) -> Result<Value, JsonError> {
        // Enforce the RFC-8259 grammar strictly (like `json.loads`): a leading
        // zero admits no more integer digits, a `.` requires >=1 fraction digit,
        // and an exponent requires >=1 digit. `f64::parse` alone is too lax
        // (it accepts `01` / `1.`), which would let a blob parse in Rust that
        // Python rejects.
        let start = self.pos;
        if self.peek() == Some('-') {
            self.pos += 1;
        }
        // integer part: "0" | [1-9][0-9]*
        match self.peek() {
            Some('0') => self.pos += 1,
            Some(c) if c.is_ascii_digit() => {
                self.pos += 1;
                while matches!(self.peek(), Some(d) if d.is_ascii_digit()) {
                    self.pos += 1;
                }
            }
            _ => return Err(self.err("invalid number")),
        }
        // fraction: "." 1*digit
        if self.peek() == Some('.') {
            self.pos += 1;
            if !matches!(self.peek(), Some(d) if d.is_ascii_digit()) {
                return Err(self.err("invalid number: digit expected after '.'"));
            }
            while matches!(self.peek(), Some(d) if d.is_ascii_digit()) {
                self.pos += 1;
            }
        }
        // exponent: ("e"|"E") ["+"|"-"] 1*digit
        if matches!(self.peek(), Some('e') | Some('E')) {
            self.pos += 1;
            if matches!(self.peek(), Some('+') | Some('-')) {
                self.pos += 1;
            }
            if !matches!(self.peek(), Some(d) if d.is_ascii_digit()) {
                return Err(self.err("invalid number: digit expected in exponent"));
            }
            while matches!(self.peek(), Some(d) if d.is_ascii_digit()) {
                self.pos += 1;
            }
        }
        let s: String = self.chars[start..self.pos].iter().collect();
        s.parse::<f64>().map(Value::Num).map_err(|_| JsonError {
            pos: start,
            msg: "invalid number".to_string(),
        })
    }

    fn parse_string(&mut self) -> Result<String, JsonError> {
        // opening quote
        if self.bump() != Some('"') {
            return Err(self.err("expected string"));
        }
        let mut out = String::new();
        loop {
            match self.bump() {
                None => return Err(self.err("unterminated string")),
                Some('"') => return Ok(out),
                Some('\\') => match self.bump() {
                    Some('"') => out.push('"'),
                    Some('\\') => out.push('\\'),
                    Some('/') => out.push('/'),
                    Some('b') => out.push('\u{08}'),
                    Some('f') => out.push('\u{0c}'),
                    Some('n') => out.push('\n'),
                    Some('r') => out.push('\r'),
                    Some('t') => out.push('\t'),
                    Some('u') => out.push(self.parse_unicode_escape()?),
                    _ => return Err(self.err("invalid escape")),
                },
                Some(c) if (c as u32) < 0x20 => return Err(self.err("control character in string")),
                Some(c) => out.push(c),
            }
        }
    }

    fn hex4(&mut self) -> Result<u32, JsonError> {
        let mut v = 0u32;
        for _ in 0..4 {
            let c = self
                .bump()
                .ok_or_else(|| self.err("truncated \\u escape"))?;
            let d = c
                .to_digit(16)
                .ok_or_else(|| self.err("bad hex in \\u escape"))?;
            v = v * 16 + d;
        }
        Ok(v)
    }

    fn parse_unicode_escape(&mut self) -> Result<char, JsonError> {
        let cp = self.hex4()?;
        // Surrogate pair: a high surrogate must be followed by \uDC00-\uDFFF.
        if (0xD800..=0xDBFF).contains(&cp) {
            if self.bump() == Some('\\') && self.bump() == Some('u') {
                let lo = self.hex4()?;
                if (0xDC00..=0xDFFF).contains(&lo) {
                    let c = 0x10000 + ((cp - 0xD800) << 10) + (lo - 0xDC00);
                    return char::from_u32(c).ok_or_else(|| self.err("invalid surrogate pair"));
                }
            }
            return Err(self.err("invalid surrogate pair"));
        }
        char::from_u32(cp).ok_or_else(|| self.err("invalid code point"))
    }

    fn parse_array(&mut self, depth: usize) -> Result<Value, JsonError> {
        self.pos += 1; // '['
        let mut items = Vec::new();
        self.skip_ws();
        if self.peek() == Some(']') {
            self.pos += 1;
            return Ok(Value::Array(items));
        }
        loop {
            let v = self.parse_value(depth + 1)?;
            items.push(v);
            self.skip_ws();
            match self.bump() {
                Some(',') => {
                    self.skip_ws();
                }
                Some(']') => return Ok(Value::Array(items)),
                _ => return Err(self.err("expected ',' or ']'")),
            }
        }
    }

    fn parse_object(&mut self, depth: usize) -> Result<Value, JsonError> {
        self.pos += 1; // '{'
        let mut pairs: Vec<(String, Value)> = Vec::new();
        self.skip_ws();
        if self.peek() == Some('}') {
            self.pos += 1;
            return Ok(Value::Object(pairs));
        }
        loop {
            self.skip_ws();
            if self.peek() != Some('"') {
                return Err(self.err("expected object key"));
            }
            let key = self.parse_string()?;
            self.skip_ws();
            if self.bump() != Some(':') {
                return Err(self.err("expected ':'"));
            }
            let val = self.parse_value(depth + 1)?;
            // Duplicate key → keep the last value at its first position (json.loads).
            if let Some(slot) = pairs.iter_mut().find(|(k, _)| *k == key) {
                slot.1 = val;
            } else {
                pairs.push((key, val));
            }
            self.skip_ws();
            match self.bump() {
                Some(',') => {}
                Some('}') => return Ok(Value::Object(pairs)),
                _ => return Err(self.err("expected ',' or '}'")),
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn scalars_and_nesting() {
        assert_eq!(parse("null").unwrap(), Value::Null);
        assert_eq!(parse("true").unwrap(), Value::Bool(true));
        assert_eq!(parse("  false ").unwrap(), Value::Bool(false));
        assert_eq!(parse("-3.5e2").unwrap(), Value::Num(-350.0));
        assert_eq!(parse("\"hi\"").unwrap(), Value::Str("hi".to_string()));
        let v = parse("{\"a\": [1, 2, {\"b\": \"c\"}]}").unwrap();
        assert_eq!(v.get("a").unwrap().as_array().unwrap().len(), 3);
        assert_eq!(
            v.get("a").unwrap().as_array().unwrap()[2]
                .get("b")
                .unwrap()
                .as_str(),
            Some("c")
        );
    }

    #[test]
    fn string_escapes_and_unicode() {
        assert_eq!(parse(r#""a\nb\tc""#).unwrap().as_str(), Some("a\nb\tc"));
        assert_eq!(parse(r#""café""#).unwrap().as_str(), Some("café"));
        // surrogate pair for U+1F600 😀
        assert_eq!(parse(r#""😀""#).unwrap().as_str(), Some("😀"));
        assert_eq!(parse(r#""\/\\\"""#).unwrap().as_str(), Some("/\\\""));
    }

    #[test]
    fn duplicate_key_keeps_last() {
        let v = parse(r#"{"k": 1, "k": 2}"#).unwrap();
        assert_eq!(v.get("k").unwrap().as_f64(), Some(2.0));
        assert_eq!(v.as_object().unwrap().len(), 1); // one key, last value
    }

    #[test]
    fn errors_and_depth() {
        assert!(parse("").is_err());
        assert!(parse("{bad}").is_err());
        assert!(parse("[1, 2").is_err());
        assert!(parse("123 456").is_err()); // trailing data
        assert!(parse(r#""unterminated"#).is_err());
        let deep = "[".repeat(MAX_DEPTH + 5);
        assert!(parse(&deep).is_err()); // no stack overflow, clean error
    }
}
