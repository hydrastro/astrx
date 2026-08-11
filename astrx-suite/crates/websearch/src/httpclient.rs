//! The clearnet HTTP/1.1 fetcher — where the SSRF gate reaches a socket.
//!
//! This module holds the **pure** wire helpers — HTTP request building, response
//! head / chunked-body parsing, `Content-Encoding` decompression (via the
//! dependency-free [`crawlcore::inflate`]), `Content-Type` parsing, body charset
//! decoding, the allow-list authority check, and — the crown jewel — the
//! [`vet_addrs`] SSRF gate that turns a list of resolved addresses into
//! [`SafeIp`]s (or refuses the whole host). The async socket orchestration
//! (a TTL DNS cache, the pinned SSRF-checked connect, `perform_request`, the
//! redirect-following `fetch`, and the keep-alive `Fetcher`) lands with the net
//! tier, on top of these helpers.
//!
//! The Python reference (`websearch/httpclient.py`) speaks HTTP through the
//! stdlib `http.client`; the response-side wire helpers here reproduce that
//! client's behaviour and are unit-tested, while the hand-rolled Python helpers
//! (`_parse_content_type`, `_decompress`, `_authority_exempt`, the
//! `_ip_is_internal` / `_resolve_checked` SSRF gate) are cross-checked
//! byte-identical in `tests/xcheck_httpclient.rs`.

use crate::ssrf::SafeIp;
use crawlcore::inflate::{inflate_gzip, inflate_raw, inflate_zlib};
use std::fmt;
use std::net::IpAddr;

/// The crawler's default User-Agent.
pub const DEFAULT_UA: &str = "astrx-websearch/1.0 (+https://example.invalid/bot)";

/// HTTP status codes that trigger a redirect (matches the Python `_REDIRECT_CODES`).
pub const REDIRECT_CODES: &[u16] = &[301, 302, 303, 307, 308];

/// The default port for a URL scheme (`https` → 443, else 80).
#[must_use]
pub fn default_port(scheme: &str) -> u16 {
    if scheme.eq_ignore_ascii_case("https") {
        443
    } else {
        80
    }
}

/// An HTTP protocol / framing error.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct HttpError(pub String);

impl fmt::Display for HttpError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for HttpError {}

// ---- the SSRF gate --------------------------------------------------------

/// The reason a host was refused before any socket was opened.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum GateError {
    /// A resolved address was internal; carries its string form (the Python
    /// `blocked-internal:<ip>` payload).
    Blocked(String),
}

impl fmt::Display for GateError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            GateError::Blocked(ip) => write!(f, "blocked-internal:{ip}"),
        }
    }
}

/// Vet a host's resolved addresses into [`SafeIp`]s — the crown-jewel SSRF gate.
///
/// When `block_internal` is set and the authority is **not** `exempt`, every
/// resolved address must clear [`SafeIp::from_ip`]; if *any* is internal the
/// whole host is refused with [`GateError::Blocked`] (carrying the first
/// offending address), exactly like the Python `_resolve_checked`. Returning
/// `Vec<SafeIp>` (never a bare `IpAddr`) is what lets the net-tier connect take
/// `&SafeIp` and pin to a vetted address, so DNS rebinding cannot swap in an
/// internal IP after the check.
///
/// The `exempt` / `!block_internal` path uses the [`SafeIp::exempt`] escape hatch
/// — the only way an internal address becomes connectable, and only for an
/// operator-allow-listed authority.
///
/// # Errors
/// [`GateError::Blocked`] if a resolved address is internal and not exempted.
pub fn vet_addrs(
    addrs: &[IpAddr],
    block_internal: bool,
    exempt: bool,
) -> Result<Vec<SafeIp>, GateError> {
    if block_internal && !exempt {
        let mut out = Vec::with_capacity(addrs.len());
        for &ip in addrs {
            match SafeIp::from_ip(ip) {
                Some(safe) => out.push(safe),
                None => return Err(GateError::Blocked(ip.to_string())),
            }
        }
        Ok(out)
    } else {
        // Exempt (operator-allow-listed) or the guard is off: accept as-is,
        // including internal addresses, through the audit-visible escape hatch.
        Ok(addrs.iter().map(|&ip| SafeIp::exempt(ip)).collect())
    }
}

/// True if this `host[:port]` was explicitly allow-listed for internal use.
///
/// A port-less form (`host`) and a `host:port` form both match; an IPv6 literal
/// additionally matches its bracketed forms (`[host]`, `[host]:port`). Matching
/// is case-insensitive. Mirrors the Python `_authority_exempt`.
#[must_use]
pub fn authority_exempt(host: &str, port: u16, allow_hosts: &[String]) -> bool {
    if allow_hosts.is_empty() {
        return false;
    }
    let h = host.to_lowercase();
    let mut forms = vec![h.clone(), format!("{h}:{port}")];
    if h.contains(':') {
        forms.push(format!("[{h}]"));
        forms.push(format!("[{h}]:{port}"));
    }
    let allow: Vec<String> = allow_hosts.iter().map(|x| x.to_lowercase()).collect();
    forms.iter().any(|f| allow.contains(f))
}

// ---- Content-Type / decompression / body decode ---------------------------

/// Parse a `Content-Type` header value into `(media_type, charset)`.
///
/// The media type is lower-cased and stripped; the charset (if a `charset=`
/// parameter is present) is unquoted and lower-cased. Mirrors the Python
/// `_parse_content_type`.
#[must_use]
pub fn parse_content_type(value: &str) -> (String, Option<String>) {
    let mut parts = value.split(';');
    let ctype = parts.next().unwrap_or("").trim().to_lowercase();
    let mut charset = None;
    for p in parts {
        let p = p.trim();
        if p.to_lowercase().starts_with("charset=") {
            // split on the first '=' of the *original* fragment (case preserved)
            let eq = p.find('=').expect("startswith charset= implies an '='");
            let raw = p[eq + 1..]
                .trim()
                .trim_matches('"')
                .trim_matches('\'')
                .to_lowercase();
            charset = Some(raw);
        }
    }
    (ctype, charset)
}

/// Decompress a response body per its `Content-Encoding`, capping the output at
/// `max_bytes + 1` (so the caller can detect truncation as `len > max_bytes`,
/// exactly like the Python `_one`). `gzip` uses the gzip wrapper; `deflate` /
/// `zlib` try the zlib wrapper first, then raw DEFLATE; anything else (identity /
/// unknown) passes through unchanged. A body that fails to decode is returned
/// as-is (never an error), matching the Python `_decompress`.
#[must_use]
pub fn decompress(raw: &[u8], enc: &str, max_bytes: usize) -> Vec<u8> {
    let limit = max_bytes + 1;
    match enc {
        "gzip" => inflate_gzip(raw, limit).map_or_else(|_| raw.to_vec(), |(b, _)| b),
        "deflate" | "zlib" => match inflate_zlib(raw, limit) {
            Ok((b, _)) => b,
            Err(_) => inflate_raw(raw, limit).map_or_else(|_| raw.to_vec(), |(b, _)| b),
        },
        _ => raw.to_vec(),
    }
}

/// Decode a response body to `String`, preferring an explicit `charset`, then a
/// sniffed `charset=` marker in the first 2 KiB, then UTF-8, then latin-1.
///
/// Faithful to the Python `decode_body`'s **sniffing logic**; the actual byte→text
/// step only reproduces the encodings the stdlib decodes natively (UTF-8, ASCII,
/// latin-1 / ISO-8859-1) — an exotic label falls through to the UTF-8/latin-1
/// tail rather than pulling in an encodings dependency. Lossy (`replace`)
/// decoding matches Python's `errors="replace"`.
#[must_use]
pub fn decode_body(body: &[u8], charset: Option<&str>) -> String {
    if let Some(cs) = charset {
        if let Some(s) = decode_with(body, cs) {
            return s;
        }
    }
    let head: Vec<u8> = body
        .iter()
        .take(2048)
        .map(|b| b.to_ascii_lowercase())
        .collect();
    for marker in [b"charset=".as_slice(), b"charset =".as_slice()] {
        if let Some(i) = find_bytes(&head, marker) {
            let frag = &head[i + marker.len()..(i + marker.len() + 40).min(head.len())];
            let cleaned: Vec<u8> = frag
                .iter()
                .copied()
                .filter(|c| !b" \"';>".contains(c))
                .collect();
            let label = cleaned.rsplit(|&b| b == b'/').next().unwrap_or(&cleaned);
            // Python decodes the label as ascii/ignore — non-ASCII bytes drop.
            let cs: String = label
                .iter()
                .filter(|&&b| b.is_ascii())
                .map(|&b| b as char)
                .collect();
            if !cs.is_empty() {
                if let Some(s) = decode_with(body, &cs) {
                    return s;
                }
                break;
            }
        }
    }
    match std::str::from_utf8(body) {
        Ok(s) => s.to_string(),
        Err(_) => latin1_decode(body),
    }
}

/// Decode `body` under a charset *label* if it is one the stdlib reproduces
/// exactly; `None` otherwise (the caller then falls through). UTF-8 is lossy
/// (`replace`); latin-1 family is total.
fn decode_with(body: &[u8], charset: &str) -> Option<String> {
    match charset.trim().to_lowercase().as_str() {
        "utf-8" | "utf8" | "us-ascii" | "ascii" => Some(String::from_utf8_lossy(body).into_owned()),
        "latin-1" | "latin1" | "iso-8859-1" | "iso8859-1" | "8859-1" | "cp819" => {
            Some(latin1_decode(body))
        }
        _ => None,
    }
}

// ---- HTTP/1.1 wire helpers (reproduce stdlib http.client on the response) --

/// Response headers: lowercased keys, insertion-ordered, duplicate keys joined
/// with `, ` — matching `http.client`'s message semantics.
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

fn latin1_decode(bytes: &[u8]) -> String {
    bytes.iter().map(|&b| b as char).collect()
}

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

fn find_bytes(buf: &[u8], needle: &[u8]) -> Option<usize> {
    find_sub(buf, 0, needle)
}

fn find_sub(buf: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || buf.len() < needle.len() {
        return None;
    }
    let last = buf.len() - needle.len();
    (from..=last).find(|&i| &buf[i..i + needle.len()] == needle)
}

/// Build a request: request-line + `Host` + extra headers (in order) + blank
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

/// Parse a header block into [`Headers`]. Lines without a `:` are skipped;
/// duplicate keys are joined with `, `.
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

/// Decode a complete `Transfer-Encoding: chunked` body buffer into
/// `(bytes, truncated)`, capping the decoded output at `max_bytes`.
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
            break;
        }
        if i + size + 2 > buf.len() {
            return Err(HttpError("chunk data shorter than declared".to_string()));
        }
        let data = &buf[i..i + size];
        i += size + 2;
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

/// The outcome of a fetch (after following redirects). Mirrors the Python
/// `FetchResult`.
#[derive(Debug, Clone)]
pub struct FetchResult {
    /// The originally-requested URL.
    pub url: String,
    /// The final URL after redirects.
    pub final_url: String,
    /// HTTP status (0 if the request never completed).
    pub status: u16,
    /// Response headers of the final hop.
    pub headers: Headers,
    /// Response body (bounded, possibly truncated).
    pub body: Vec<u8>,
    /// The bare media type (no parameters), lowercased.
    pub content_type: String,
    /// The response charset, if any.
    pub charset: Option<String>,
    /// A human-readable error, if the fetch failed.
    pub error: Option<String>,
    /// Whether the body was truncated at the byte cap.
    pub truncated: bool,
    /// How many redirects were followed.
    pub redirects: u32,
}

impl FetchResult {
    /// True iff the fetch completed with status 200 and no error.
    #[must_use]
    pub fn ok(&self) -> bool {
        self.error.is_none() && self.status == 200
    }

    /// An error result (status 0, empty body).
    #[must_use]
    pub fn failed(url: &str, current: &str, error: String, redirects: u32) -> Self {
        FetchResult {
            url: url.to_string(),
            final_url: current.to_string(),
            status: 0,
            headers: Headers::default(),
            body: Vec::new(),
            content_type: String::new(),
            charset: None,
            error: Some(error),
            truncated: false,
            redirects,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn content_type_parse() {
        assert_eq!(
            parse_content_type("text/HTML; charset=UTF-8"),
            ("text/html".to_string(), Some("utf-8".to_string()))
        );
        assert_eq!(
            parse_content_type("application/json"),
            ("application/json".to_string(), None)
        );
        assert_eq!(
            parse_content_type("text/plain; charset=\"ISO-8859-1\""),
            ("text/plain".to_string(), Some("iso-8859-1".to_string()))
        );
    }

    #[test]
    fn ssrf_gate_blocks_and_pins() {
        let pub_addr: IpAddr = "8.8.8.8".parse().unwrap();
        let internal: IpAddr = "127.0.0.1".parse().unwrap();
        // all-public → vetted
        let ok = vet_addrs(&[pub_addr], true, false).unwrap();
        assert_eq!(ok.len(), 1);
        assert_eq!(ok[0].addr(), pub_addr);
        // any internal → the whole host is refused
        assert_eq!(
            vet_addrs(&[pub_addr, internal], true, false),
            Err(GateError::Blocked("127.0.0.1".to_string()))
        );
        // exempt → the internal address is accepted through the escape hatch
        let exempt = vet_addrs(&[internal], true, true).unwrap();
        assert_eq!(exempt[0].addr(), internal);
        // guard off → accepted as-is
        assert_eq!(vet_addrs(&[internal], false, false).unwrap().len(), 1);
    }

    #[test]
    fn authority_exemption() {
        let allow = vec!["intranet:8080".to_string(), "[::1]".to_string()];
        assert!(authority_exempt("intranet", 8080, &allow));
        assert!(!authority_exempt("intranet", 80, &allow)); // wrong port form absent
        assert!(authority_exempt("::1", 80, &allow)); // IPv6 bracketed form
        assert!(!authority_exempt("intranet", 8080, &[]));
    }

    #[test]
    fn decompress_identity_and_unknown() {
        assert_eq!(decompress(b"hello", "", 100), b"hello");
        assert_eq!(decompress(b"hello", "identity", 100), b"hello");
        assert_eq!(decompress(b"hello", "br", 100), b"hello"); // unknown → as-is
    }

    #[test]
    fn wire_request_and_headers() {
        assert_eq!(
            build_request("GET", "", "example.com", &[]),
            b"GET / HTTP/1.1\r\nHost: example.com\r\n\r\n"
        );
        let (v, s, r) = parse_status_line(b"HTTP/1.1 200 OK").unwrap();
        assert_eq!((v.as_str(), s, r.as_str()), ("HTTP/1.1", 200, "OK"));
        assert!(parse_status_line(b"garbage").is_err());
        let h = parse_headers(b"Content-Type: text/html\r\nSet-Cookie: a\r\nSet-Cookie: b");
        assert_eq!(h.get("content-type"), Some("text/html"));
        assert_eq!(h.get("set-cookie"), Some("a, b"));
    }

    #[test]
    fn chunked_decode() {
        let (body, trunc) = decode_chunked(b"4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n", 100).unwrap();
        assert_eq!(body, b"Wikipedia");
        assert!(!trunc);
    }

    #[test]
    fn body_decode_charsets() {
        assert_eq!(decode_body(b"caf\xc3\xa9", Some("utf-8")), "café");
        assert_eq!(decode_body(b"caf\xe9", Some("latin-1")), "café");
        // sniff from a meta tag when no explicit charset
        assert_eq!(
            decode_body(b"<meta charset=utf-8>\xc3\xa9", None),
            "<meta charset=utf-8>é"
        );
    }
}
