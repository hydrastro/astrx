//! Canonical bencode codec.
//!
//! The STRICT decoder rejects every non-canonical form (leading zeros, negative
//! zero, unsorted/duplicate dict keys, trailing bytes) and is bounded against
//! hostile input (depth cap, bounds checks) — it returns `Result`, never panics
//! on adversarial bytes. Dict canonicality is enforced *by construction*: keys
//! live in a `BTreeMap`, so `encode` cannot emit a non-canonical dict.

use std::collections::BTreeMap;

/// A decoded bencode value. Dict keys are raw bytes, kept sorted by `BTreeMap`.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Ben {
    /// Bencode integer. Bounded to `i64`: every real BitTorrent/KRPC integer
    /// (file sizes, ports, timestamps, counts) fits, and rejecting an
    /// out-of-`i64` integer as malformed is the safe direction. (Python's
    /// arbitrary-precision `int` accepts larger values; that is a language
    /// artifact, not a protocol requirement — see `rejects_out_of_range_int`.)
    Int(i64),
    Bytes(Vec<u8>),
    List(Vec<Ben>),
    Dict(BTreeMap<Vec<u8>, Ben>),
}

/// Every malformed-input / unencodable error (like Python's `BencodeError`).
#[derive(Debug, PartialEq, Eq)]
pub struct BencodeError(pub String);

impl std::fmt::Display for BencodeError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "bencode: {}", self.0)
    }
}
impl std::error::Error for BencodeError {}

fn err<T>(msg: impl Into<String>) -> Result<T, BencodeError> {
    Err(BencodeError(msg.into()))
}

/// Max container nesting; bounds recursion so adversarial input can't blow the stack.
const MAX_DEPTH: usize = 100;

/// Serialise to canonical bencode. Cannot produce a non-canonical dict.
pub fn encode(v: &Ben) -> Vec<u8> {
    let mut out = Vec::new();
    enc(v, &mut out);
    out
}

fn enc(v: &Ben, out: &mut Vec<u8>) {
    match v {
        Ben::Int(n) => out.extend_from_slice(format!("i{n}e").as_bytes()),
        Ben::Bytes(b) => {
            out.extend_from_slice(format!("{}:", b.len()).as_bytes());
            out.extend_from_slice(b);
        }
        Ben::List(items) => {
            out.push(b'l');
            items.iter().for_each(|it| enc(it, out));
            out.push(b'e');
        }
        Ben::Dict(m) => {
            out.push(b'd');
            for (k, val) in m {
                // BTreeMap iterates in sorted key order => canonical, always.
                out.extend_from_slice(format!("{}:", k.len()).as_bytes());
                out.extend_from_slice(k);
                enc(val, out);
            }
            out.push(b'e');
        }
    }
}

/// Decode one complete canonical value; trailing bytes are an error.
pub fn decode(data: &[u8]) -> Result<Ben, BencodeError> {
    let (v, i) = dec(data, 0, 0, true)?;
    if i != data.len() {
        return err("trailing bytes after value");
    }
    Ok(v)
}

/// Decode one value from the front, permitting trailing bytes; returns the value
/// and the number of bytes consumed. Strict (canonical) like [`decode`]. Used by
/// the ut_metadata (BEP-9) `data` message, which appends raw piece bytes right
/// after a bencoded header dict.
pub fn decode_prefix(data: &[u8]) -> Result<(Ben, usize), BencodeError> {
    dec(data, 0, 0, true)
}

/// Tolerantly decode one complete value: out-of-order / duplicate dict keys and
/// redundant leading zeros (and `-0`) are accepted (a duplicate key keeps the
/// last value). Depth and length bounds are still enforced.
///
/// Intended ONLY for SHA-1-verified info-dict bytes: the info-dict is hashed
/// against the infohash on its *raw* wire bytes *before* this is ever called
/// (see the metadata path), so relaxing canonical-form checks here cannot weaken
/// the `sha1(info) == infohash` guarantee. It is never wired into `parse_message`
/// / KRPC or any other network decode — those keep the strict decoder.
pub fn decode_lenient(data: &[u8]) -> Result<Ben, BencodeError> {
    let (v, i) = dec(data, 0, 0, false)?;
    if i != data.len() {
        return err("trailing bytes after value");
    }
    Ok(v)
}

fn dec(data: &[u8], i: usize, depth: usize, strict: bool) -> Result<(Ben, usize), BencodeError> {
    if depth > MAX_DEPTH {
        return err(format!("nested too deeply (>{MAX_DEPTH})"));
    }
    match data.get(i) {
        None => err("unexpected end of data"),
        Some(b'i') => dec_int(data, i, strict),
        Some(b'l') => dec_list(data, i, depth, strict),
        Some(b'd') => dec_dict(data, i, depth, strict),
        Some(c) if c.is_ascii_digit() => dec_bytes(data, i, strict),
        Some(c) => err(format!("invalid token {:?} at {i}", *c as char)),
    }
}

fn dec_int(data: &[u8], i: usize, strict: bool) -> Result<(Ben, usize), BencodeError> {
    let end = data[i..]
        .iter()
        .position(|&b| b == b'e')
        .map(|p| i + p)
        .ok_or(BencodeError("unterminated integer".into()))?;
    let body = &data[i + 1..end];
    if body.is_empty() || body == b"-" {
        return err("empty integer");
    }
    let neg = body[0] == b'-';
    let digits = if neg { &body[1..] } else { body };
    if digits.is_empty() || !digits.iter().all(u8::is_ascii_digit) {
        return err("non-numeric integer");
    }
    // Canonical-form checks (leading zero, -0) are strict-only.
    if strict {
        if neg && digits == b"0" {
            return err("negative zero is not canonical");
        }
        if digits.len() > 1 && digits[0] == b'0' {
            return err("leading zero is not canonical");
        }
    }
    let n: i64 = std::str::from_utf8(body)
        .unwrap()
        .parse()
        .map_err(|_| BencodeError("integer overflow".into()))?;
    Ok((Ben::Int(n), end + 1))
}

fn dec_bytes(data: &[u8], i: usize, strict: bool) -> Result<(Ben, usize), BencodeError> {
    let colon = data[i..]
        .iter()
        .position(|&b| b == b':')
        .map(|p| i + p)
        .ok_or(BencodeError("missing ':' in string".into()))?;
    let len_field = &data[i..colon];
    if strict && len_field.len() > 1 && len_field[0] == b'0' {
        return err("leading zero in string length");
    }
    if len_field.is_empty() || !len_field.iter().all(u8::is_ascii_digit) {
        return err("invalid string length");
    }
    let n: usize = std::str::from_utf8(len_field)
        .unwrap()
        .parse()
        .map_err(|_| BencodeError("string length overflow".into()))?;
    let start = colon + 1;
    let end = start
        .checked_add(n)
        .ok_or(BencodeError("length overflow".into()))?;
    if end > data.len() {
        return err("string longer than remaining data");
    }
    Ok((Ben::Bytes(data[start..end].to_vec()), end))
}

fn dec_list(
    data: &[u8],
    mut i: usize,
    depth: usize,
    strict: bool,
) -> Result<(Ben, usize), BencodeError> {
    let mut out = Vec::new();
    i += 1; // skip 'l'
    loop {
        match data.get(i) {
            None => return err("unterminated list"),
            Some(b'e') => return Ok((Ben::List(out), i + 1)),
            _ => {
                let (v, next) = dec(data, i, depth + 1, strict)?;
                out.push(v);
                i = next;
            }
        }
    }
}

fn dec_dict(
    data: &[u8],
    mut i: usize,
    depth: usize,
    strict: bool,
) -> Result<(Ben, usize), BencodeError> {
    let mut out: BTreeMap<Vec<u8>, Ben> = BTreeMap::new();
    i += 1; // skip 'd'
    let mut last_key: Option<Vec<u8>> = None;
    loop {
        match data.get(i) {
            None => return err("unterminated dict"),
            Some(b'e') => return Ok((Ben::Dict(out), i + 1)),
            Some(c) if !c.is_ascii_digit() => return err("dict key must be a byte string"),
            _ => {
                let (Ben::Bytes(key), next) = dec_bytes(data, i, strict)? else {
                    unreachable!("dec_bytes yields Ben::Bytes")
                };
                // Ordering / no-duplicate-keys is a canonical-form (strict) check;
                // lenient decode keeps the last value for a duplicated key.
                if strict {
                    if let Some(lk) = &last_key {
                        if key <= *lk {
                            return err("dict keys not sorted / duplicated");
                        }
                    }
                }
                let (v, next2) = dec(data, next, depth + 1, strict)?;
                out.insert(key.clone(), v);
                last_key = Some(key);
                i = next2;
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn hexs(b: &[u8]) -> String {
        b.iter().map(|x| format!("{x:02x}")).collect()
    }

    // Golden values from the real Python torrentds.bencode (the spec).
    const GOLDEN_MIXED_HEX: &str =
        "6c343a7370616d693432656c313a786564313a61693165313a626c693265693365656565";

    #[test]
    fn mixed_encode_and_roundtrip() {
        let mut m = BTreeMap::new();
        m.insert(b"a".to_vec(), Ben::Int(1));
        m.insert(b"b".to_vec(), Ben::List(vec![Ben::Int(2), Ben::Int(3)]));
        let val = Ben::List(vec![
            Ben::Bytes(b"spam".to_vec()),
            Ben::Int(42),
            Ben::List(vec![Ben::Bytes(b"x".to_vec())]),
            Ben::Dict(m),
        ]);
        let enc = encode(&val);
        assert_eq!(hexs(&enc), GOLDEN_MIXED_HEX);
        assert_eq!(encode(&decode(&enc).unwrap()), enc); // re-encode is canonical
    }

    #[test]
    fn rejects_malformed() {
        for bad in [
            &b"i03e"[..],
            b"i-0e",
            b"i1ejunk",
            b"d1:bi1e1:ai2ee",
            b"01:a",
            b"5:ab",
            b"x",
            b"",
        ] {
            assert!(decode(bad).is_err(), "should reject {bad:?}");
        }
    }

    #[test]
    fn deep_nesting_is_bounded() {
        let mut d = vec![b'l'; 5000];
        d.extend(vec![b'e'; 5000]);
        assert!(decode(&d).is_err());
    }

    #[test]
    fn rejects_out_of_range_int() {
        // i64 bounds accept; one past either bound is rejected as malformed
        // (deliberate — Python's arbitrary-precision int would accept these).
        assert_eq!(decode(b"i9223372036854775807e"), Ok(Ben::Int(i64::MAX)));
        assert_eq!(decode(b"i-9223372036854775808e"), Ok(Ben::Int(i64::MIN)));
        assert!(decode(b"i9223372036854775808e").is_err());
        assert!(decode(b"i-9223372036854775809e").is_err());
        assert!(decode(b"i123456789012345678901234567890e").is_err());
    }

    #[test]
    fn decode_prefix_returns_consumed_and_allows_trailer() {
        // one value off the front, trailing bytes permitted (BEP-9 data message)
        assert_eq!(decode_prefix(b"i42eEXTRA"), Ok((Ben::Int(42), 4)));
        let (v, used) = decode_prefix(b"d1:ai1eeRAWPIECEBYTES").unwrap();
        assert_eq!(used, 8);
        assert!(matches!(v, Ben::Dict(_)));
        // still strict about canonical form on the prefix itself
        assert!(decode_prefix(b"i03e").is_err());
        // a bare complete value consumes exactly its length
        assert_eq!(
            decode_prefix(b"4:spam"),
            Ok((Ben::Bytes(b"spam".to_vec()), 6))
        );
    }

    #[test]
    fn decode_lenient_tolerates_noncanonical() {
        // unsorted keys: strict rejects, lenient accepts (last value on dup wins)
        assert!(decode(b"d1:bi1e1:ai2ee").is_err());
        let v = decode_lenient(b"d1:bi1e1:ai2ee").unwrap();
        let Ben::Dict(m) = v else { panic!("dict") };
        assert_eq!(m.get(b"a".as_slice()), Some(&Ben::Int(2)));
        assert_eq!(m.get(b"b".as_slice()), Some(&Ben::Int(1)));
        // leading zeros / -0 tolerated
        assert_eq!(decode_lenient(b"i03e"), Ok(Ben::Int(3)));
        assert_eq!(decode_lenient(b"i-0e"), Ok(Ben::Int(0)));
        assert_eq!(decode_lenient(b"03:abc"), Ok(Ben::Bytes(b"abc".to_vec())));
        // duplicate key: last wins
        let Ben::Dict(m2) = decode_lenient(b"d1:ai1e1:ai9ee").unwrap() else {
            panic!("dict")
        };
        assert_eq!(m2.get(b"a".as_slice()), Some(&Ben::Int(9)));
        // safety bounds still enforced: trailing garbage and overlong strings rejected
        assert!(decode_lenient(b"i1ejunk").is_err());
        assert!(decode_lenient(b"5:ab").is_err());
    }
}
