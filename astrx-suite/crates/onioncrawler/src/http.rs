//! A minimal HTTP/1.1 client that runs over any already-connected (SOCKS-tunnelled)
//! stream.
//!
//! This module holds the **pure** wire helpers — request building, response-head
//! / chunked-body parsing, and `Content-Encoding` decompression (via the
//! dependency-free [`crawlcore::inflate`]) — cross-checked byte-identical to the
//! Python `http_client.py` in `tests/xcheck_http.rs`. The async `perform_request`
//! (streaming reader with a raw byte budget, tying these together) lands with the
//! net tier, on top of these helpers.
//!
//! Redirects are handled one hop at a time by the caller (the fetcher), because a
//! redirect to a new host needs a fresh tunnel.

use crawlcore::inflate::{inflate_gzip, inflate_raw, inflate_zlib};
use std::fmt;

/// An HTTP protocol / framing error.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct HttpError(pub String);

impl fmt::Display for HttpError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for HttpError {}

/// Response headers: lowercased keys, insertion-ordered, duplicate keys joined
/// with `, ` — matching the Python parser's dict semantics.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Headers(Vec<(String, String)>);

impl Headers {
    /// The value for `name` (case-insensitive), or `None`.
    #[must_use]
    pub fn get(&self, name: &str) -> Option<&str> {
        let lname = name.to_lowercase();
        self.0
            .iter()
            .find(|(k, _)| *k == lname)
            .map(|(_, v)| v.as_str())
    }

    /// The header pairs, in insertion order.
    #[must_use]
    pub fn pairs(&self) -> &[(String, String)] {
        &self.0
    }

    fn insert(&mut self, key: String, value: String) {
        if let Some(slot) = self.0.iter_mut().find(|(k, _)| *k == key) {
            slot.1 = format!("{}, {}", slot.1, value);
        } else {
            self.0.push((key, value));
        }
    }
}

/// Decode a byte slice as latin-1 (ISO-8859-1) — every byte maps to the code
/// point of the same value.
fn latin1_decode(bytes: &[u8]) -> String {
    bytes.iter().map(|&b| b as char).collect()
}

/// Encode a string as latin-1. Inputs here are ASCII (methods, paths, hosts,
/// header names/values), for which this equals the UTF-8 bytes.
fn latin1_encode(s: &str) -> Vec<u8> {
    s.chars().map(|c| c as u8).collect()
}

fn trim_ascii(b: &[u8]) -> &[u8] {
    let start = b
        .iter()
        .position(|c| !c.is_ascii_whitespace())
        .unwrap_or(b.len());
    let end = b
        .iter()
        .rposition(|c| !c.is_ascii_whitespace())
        .map_or(start, |p| p + 1);
    &b[start..end]
}

/// Build a request: request-line + `Host` + extra headers (in order) + the blank
/// line. An empty `path` becomes `/`.
#[must_use]
pub fn build_request(
    method: &str,
    path: &str,
    host: &str,
    extra_headers: &[(String, String)],
) -> Vec<u8> {
    let path = if path.is_empty() { "/" } else { path };
    let mut s = format!("{method} {path} HTTP/1.1\r\nHost: {host}\r\n");
    for (k, v) in extra_headers {
        s.push_str(&format!("{k}: {v}\r\n"));
    }
    s.push_str("\r\n");
    latin1_encode(&s)
}

/// Parse a status line into `(version, status, reason)`.
///
/// # Errors
/// [`HttpError`] if the line lacks a numeric status or does not begin with
/// `HTTP/`.
pub fn parse_status_line(line: &[u8]) -> Result<(String, u16, String), HttpError> {
    let decoded = latin1_decode(line);
    let s = decoded.trim_end_matches(['\r', '\n']);
    let mut parts = s.splitn(3, ' ');
    let version = parts.next().unwrap_or("").to_string();
    let status = parts
        .next()
        .and_then(|p| p.parse::<u16>().ok())
        .ok_or_else(|| HttpError(format!("bad status line: {line:?}")))?;
    let reason = parts.next().unwrap_or("").to_string();
    if !version.to_uppercase().starts_with("HTTP/") {
        return Err(HttpError(format!("not an HTTP response: {line:?}")));
    }
    Ok((version, status, reason))
}

/// Parse a header block (the bytes between the status line and the blank line)
/// into [`Headers`]. Lines without a `:` are skipped; duplicate keys are joined
/// with `, `.
#[must_use]
pub fn parse_headers(block: &[u8]) -> Headers {
    let mut headers = Headers::default();
    let text = latin1_decode(block);
    for raw in text.split("\r\n") {
        if raw.is_empty() || !raw.contains(':') {
            continue;
        }
        let (k, v) = raw.split_once(':').unwrap();
        headers.insert(k.trim().to_lowercase(), v.trim().to_string());
    }
    headers
}

fn find_sub(buf: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || from > buf.len() {
        return None;
    }
    (from..=buf.len().saturating_sub(needle.len())).find(|&i| &buf[i..i + needle.len()] == needle)
}

/// Decode a complete `Transfer-Encoding: chunked` body buffer into
/// `(bytes, truncated)`, capping the decoded output at `max_bytes`.
///
/// This is the pure, in-memory form used for cross-checking; the streaming
/// variant (reading chunks off the wire under a raw byte budget) lands with the
/// async `perform_request`.
///
/// # Errors
/// [`HttpError`] on a missing delimiter, a bad chunk size, or a short chunk.
pub fn decode_chunked(buf: &[u8], max_bytes: usize) -> Result<(Vec<u8>, bool), HttpError> {
    let mut out: Vec<u8> = Vec::new();
    let mut truncated = false;
    let mut i = 0;
    loop {
        let line_end = find_sub(buf, i, b"\r\n")
            .ok_or_else(|| HttpError("chunk size: no CRLF".to_string()))?;
        let size_line = trim_ascii(&buf[i..line_end]);
        i = line_end + 2;
        let size_hex = trim_ascii(size_line.split(|&b| b == b';').next().unwrap_or(&[]));
        let size = std::str::from_utf8(size_hex)
            .ok()
            .and_then(|s| usize::from_str_radix(s, 16).ok())
            .ok_or_else(|| HttpError(format!("bad chunk size: {size_line:?}")))?;
        if size == 0 {
            break; // last chunk; trailers/blank line ignored for the in-memory form
        }
        if i + size + 2 > buf.len() {
            return Err(HttpError("chunk data shorter than declared".to_string()));
        }
        let data = &buf[i..i + size];
        i += size + 2; // data + trailing CRLF
        if out.len() < max_bytes {
            out.extend_from_slice(data);
            if out.len() >= max_bytes {
                truncated = true;
                out.truncate(max_bytes);
            }
        } else {
            truncated = true;
        }
    }
    Ok((out, truncated))
}

/// Decompress a response body per its `Content-Encoding`, hard-capping the
/// decompressed size at `max_bytes` (a decompression bomb is stopped at the cap
/// with `truncated == true`). `identity`/empty/unknown encodings pass through
/// unchanged. `deflate` tries raw DEFLATE first, then a zlib wrapper (servers
/// send both). Mirrors the Python `_decompress`.
///
/// # Errors
/// [`HttpError`] if a declared `gzip`/`deflate` body fails to decode.
pub fn decompress(
    body: &[u8],
    encoding: &str,
    max_bytes: usize,
) -> Result<(Vec<u8>, bool), HttpError> {
    let enc = encoding.trim().to_lowercase();
    if enc.is_empty() || enc == "identity" {
        return Ok((body.to_vec(), false));
    }
    let fail = |e: crawlcore::inflate::InflateError| {
        HttpError(format!("failed to decompress {enc:?}: {e}"))
    };
    match enc.as_str() {
        "gzip" | "x-gzip" => inflate_gzip(body, max_bytes).map_err(fail),
        "deflate" => match inflate_raw(body, max_bytes) {
            Ok(out) => Ok(out),
            Err(_) => inflate_zlib(body, max_bytes).map_err(fail),
        },
        // unknown encoding: return as-is rather than crash
        _ => Ok((body.to_vec(), false)),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn request_and_status() {
        assert_eq!(
            build_request("GET", "", "h.onion", &[]),
            b"GET / HTTP/1.1\r\nHost: h.onion\r\n\r\n"
        );
        let (v, s, r) = parse_status_line(b"HTTP/1.1 200 OK").unwrap();
        assert_eq!((v.as_str(), s, r.as_str()), ("HTTP/1.1", 200, "OK"));
        assert!(parse_status_line(b"garbage").is_err());
        assert!(parse_status_line(b"HTTP/1.1 notnum OK").is_err());
    }

    #[test]
    fn headers_dedup_and_lookup() {
        let h = parse_headers(b"Content-Type: text/html\r\nSet-Cookie: a\r\nSet-Cookie: b");
        assert_eq!(h.get("content-type"), Some("text/html"));
        assert_eq!(h.get("Set-Cookie"), Some("a, b")); // case-insensitive get, joined
    }

    #[test]
    fn chunked_truncation() {
        let (body, trunc) = decode_chunked(b"4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n", 6).unwrap();
        assert_eq!(body, b"Wikipe");
        assert!(trunc);
    }
}
