//! The clearnet HTTP/1.1 fetcher — where the SSRF gate reaches a socket.
//!
//! What lives here is what is *clearnet-specific*: the crown jewel, the
//! [`vet_addrs`] SSRF gate that turns a list of resolved addresses into
//! [`SafeIp`]s (or refuses the whole host), the operator allow-list check
//! [`authority_exempt`], the crawler's request defaults, and [`FetchResult`].
//! The async socket orchestration on top of them — a TTL DNS cache, the pinned
//! SSRF-checked connect, the redirect-following `fetch`, the keep-alive
//! `Fetcher` — lands with the net tier in [`crate::fetcher`].
//!
//! The HTTP/1.1 **wire layer** is deliberately no longer here. Request building,
//! status-line / header / chunked-body parsing, `Content-Encoding` decompression,
//! `Content-Type` parsing and body charset decoding are shared with
//! `onioncrawler` in [`crawlcore::http`]: they were two independently-maintained
//! copies until four framing / injection defects had each been found and fixed
//! twice, once per copy (that module's doc names all four). Everything moved is
//! re-exported below, so this module's public surface — and the import paths in
//! `tests/xcheck_httpclient.rs` — are unchanged.
//!
//! The Python reference (`websearch/httpclient.py`) speaks HTTP through the
//! stdlib `http.client`, which the shared wire layer reproduces; the hand-rolled
//! Python helpers (`_parse_content_type`, `_decompress`, `_authority_exempt`, the
//! `_ip_is_internal` / `_resolve_checked` SSRF gate) are cross-checked
//! byte-identical in `tests/xcheck_httpclient.rs`.

use crate::ssrf::SafeIp;
use std::fmt;
use std::net::IpAddr;

/// The shared HTTP/1.1 wire layer, at this crate's historical paths.
pub use crawlcore::http::{
    build_request, decode_body, decode_chunked, parse_content_type, parse_headers,
    parse_status_line, Headers, HttpError, HttpResponse,
};

/// This crate's `Content-Encoding` convention, which is the one thing about the
/// wire layer the two engines genuinely disagreed on: a body that fails to
/// decode is returned **as-is** rather than failing the fetch, and the output is
/// capped at `max_bytes + 1` so the caller detects truncation as
/// `len > max_bytes` — matching the Python `_one`. (`onioncrawler` treats an
/// undecodable declared encoding as a protocol error; see
/// [`crawlcore::http::decompress_checked`].)
pub use crawlcore::http::decompress_or_raw as decompress;

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

// ---- the shape a completed fetch takes ------------------------------------

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

/// Send one request on `stream` and read one response, under this crate's
/// `Content-Encoding` convention (see [`decompress`]).
///
/// The framing itself — the head cap, the chunked reader with its
/// [`crawlcore::budget::Budget`]-bounded chunk sizes, the content-length and
/// read-to-EOF paths, the keep-alive decision — is
/// [`crawlcore::http::perform_request`], shared with `onioncrawler`, so a
/// framing fix lands once. The caller owns the stream lifecycle and has already
/// taken it through the SSRF gate ([`vet_addrs`] → a pinned connect).
///
/// This returns the shared future rather than `await`ing it inside an `async fn`
/// of its own, and that is not a style choice: this fetch path is already several
/// async layers deep, a debug-build future nests its callee's state inline, and
/// wrapping it in one more `async fn` was enough to abort
/// `net_multiworker::keep_alive_reuses_connections` with "thread has overflowed
/// its stack". Forwarding the future keeps the frame exactly the size it was
/// before the wire layer moved.
///
/// # Errors
/// [`HttpError`] on I/O failure or a malformed response.
#[cfg(feature = "net")]
pub fn perform_request<'a, S>(
    stream: &'a mut S,
    method: &'a str,
    host: &'a str,
    path: &'a str,
    headers: &'a [(String, String)],
    max_bytes: usize,
) -> impl std::future::Future<Output = Result<HttpResponse, HttpError>> + 'a
where
    S: tokio::io::AsyncRead + tokio::io::AsyncWrite + Unpin + 'a,
{
    crawlcore::http::perform_request(
        stream,
        method,
        host,
        path,
        headers,
        max_bytes,
        crawlcore::http::ContentEncodingPolicy::OrRaw,
    )
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

    /// The re-exported wire layer keeps this crate's observable behaviour: a body
    /// that does not decode comes back verbatim rather than as an error.
    #[test]
    fn decompress_identity_and_unknown() {
        assert_eq!(decompress(b"hello", "", 100), b"hello");
        assert_eq!(decompress(b"hello", "identity", 100), b"hello");
        assert_eq!(decompress(b"hello", "br", 100), b"hello"); // unknown → as-is
        assert_eq!(decompress(b"not gzip", "gzip", 100), b"not gzip"); // undecodable → as-is
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
