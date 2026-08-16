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
}

/// Decode a byte slice as latin-1 (ISO-8859-1) — every byte maps to the code
/// point of the same value.
fn latin1_decode(bytes: &[u8]) -> String {
    bytes.iter().map(|&b| b as char).collect()
}

/// Strip the bytes that would end a header field early. A value can arrive here
/// from STORAGE — a previous response's `ETag`/`Last-Modified` is replayed as
/// `If-None-Match`/`If-Modified-Since` — so an origin that plants a CR or LF in
/// one would otherwise inject a header line into every later conditional GET,
/// on every subsequent crawl.
fn header_safe(v: &str) -> String {
    v.chars()
        .filter(|c| !matches!(c, '\r' | '\n' | '\0'))
        .collect()
}

/// Percent-encode a request target so it is guaranteed to be a single ASCII
/// token. Paths arrive canonicalised, i.e. already percent-encoded, so existing
/// `%XX` and reserved characters pass through untouched; what gets escaped is
/// exactly what may not appear literally in a request line — SP, the CTLs (CR
/// and LF above all) and every non-ASCII byte.
///
/// Writing the code point out with `c as u8` instead — what this did before —
/// TRUNCATED it: U+010D became a raw CR and U+010A a raw LF, so a crawled
/// `<a href="/x\u{010d}\u{010a}X-Injected: 1">` injected a header line, and a
/// doubled pair smuggled a whole second request onto a keep-alive socket. It
/// also silently mangled every non-Latin-1 IRI.
fn encode_request_target(path: &str) -> String {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    let mut out = String::with_capacity(path.len());
    for b in path.bytes() {
        if b > 0x20 && b < 0x7f {
            out.push(b as char);
        } else {
            out.push('%');
            out.push(HEX[(b >> 4) as usize] as char);
            out.push(HEX[(b & 0x0f) as usize] as char);
        }
    }
    out
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
    let mut s = String::with_capacity(path.len() + host.len() + 64);
    s.push_str(&header_safe(method));
    s.push(' ');
    s.push_str(&encode_request_target(path));
    s.push_str(" HTTP/1.1\r\nHost: ");
    s.push_str(&header_safe(host));
    s.push_str("\r\n");
    for (k, v) in extra_headers {
        s.push_str(&header_safe(k));
        s.push_str(": ");
        s.push_str(&header_safe(v));
        s.push_str("\r\n");
    }
    s.push_str("\r\n");
    s.into_bytes()
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
    // `at` maps a header name to its slot so a duplicate is joined in O(1). The
    // scan used to be linear per header, making a head O(headers²): an origin
    // filling the head cap with tens of thousands of one-byte headers cost about
    // a second of crawler CPU per response, free, on every page it served. It
    // lives here rather than inside `Headers` so the struct — which is held
    // across `.await` points all down the fetch path — does not grow (widening
    // it overflowed a debug-build future's stack).
    let mut at: std::collections::HashMap<String, usize> = std::collections::HashMap::new();
    // Split on LF and drop a trailing CR, rather than splitting on CRLF: a
    // header line terminated by a BARE LF used to leave that LF *inside* the
    // preceding value, and values like `ETag` are stored and replayed on the
    // next conditional GET — a persistent, cross-run header injection.
    for raw in text.split('\n') {
        let raw = raw.strip_suffix('\r').unwrap_or(raw);
        if raw.is_empty() || !raw.contains(':') {
            continue;
        }
        let (k, v) = raw.split_once(':').unwrap();
        let (key, value) = (k.trim().to_lowercase(), v.trim());
        match at.get(&key) {
            // Duplicate keys are joined with `, `, matching the Python parser.
            Some(&i) => {
                let slot = &mut headers.0[i].1;
                slot.push_str(", ");
                slot.push_str(value);
            }
            None => {
                at.insert(key.clone(), headers.0.len());
                headers.0.push((key, value.to_string()));
            }
        }
    }
    headers
}

fn find_sub(buf: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || buf.len() < needle.len() {
        return None;
    }
    // `from > last` yields an empty inclusive range (→ None), never an OOB slice.
    let last = buf.len() - needle.len();
    (from..=last).find(|&i| &buf[i..i + needle.len()] == needle)
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
        // NB: `i + size + 2 > buf.len()` would OVERFLOW on a huge declared size
        // and wrap into an inverted `buf[i..i + size]` range below. Compare
        // against the bytes that actually remain.
        if size > buf.len().saturating_sub(i).saturating_sub(2) {
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

/// A parsed HTTP response.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct HttpResponse {
    /// Status code.
    pub status: u16,
    /// Reason phrase.
    pub reason: String,
    /// Response headers.
    pub headers: Headers,
    /// Decompressed body (bounded, possibly truncated).
    pub body: Vec<u8>,
    /// Whether the body (or a compressed body's output) was capped.
    pub truncated: bool,
    /// Whether the connection can be safely reused (HTTP/1.1, no `close`, body
    /// fully framed and drained).
    pub reusable: bool,
}

impl HttpResponse {
    /// A header value by name (case-insensitive).
    #[must_use]
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.get(name)
    }
}

/// Cap on the response head (status line + headers) size. Used by the async
/// reader in `perform_request` (net tier).
#[cfg(feature = "net")]
const MAX_HEAD: usize = 256 * 1024;

#[cfg(feature = "net")]
mod net_io {
    use super::{
        build_request, decompress, find_sub, parse_headers, parse_status_line, trim_ascii, Headers,
        HttpError, HttpResponse, MAX_HEAD,
    };
    use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt};

    const READ_CHUNK: usize = 65536;

    /// A buffered reader over an async stream.
    struct Reader<'a, S> {
        stream: &'a mut S,
        buf: Vec<u8>,
        eof: bool,
    }

    impl<'a, S: AsyncRead + Unpin> Reader<'a, S> {
        fn new(stream: &'a mut S) -> Self {
            Reader {
                stream,
                buf: Vec::new(),
                eof: false,
            }
        }

        async fn fill(&mut self) -> Result<usize, HttpError> {
            if self.eof {
                return Ok(0);
            }
            let mut tmp = [0u8; READ_CHUNK];
            let n = self
                .stream
                .read(&mut tmp)
                .await
                .map_err(|e| HttpError(format!("read: {e}")))?;
            if n == 0 {
                self.eof = true;
                return Ok(0);
            }
            self.buf.extend_from_slice(&tmp[..n]);
            Ok(n)
        }

        async fn read_until(&mut self, sep: &[u8], cap: usize) -> Result<Vec<u8>, HttpError> {
            loop {
                if let Some(i) = find_sub(&self.buf, 0, sep) {
                    return Ok(self.buf.drain(..i + sep.len()).collect());
                }
                if self.buf.len() > cap {
                    return Err(HttpError("delimiter not found within cap".to_string()));
                }
                if self.fill().await? == 0 {
                    return Err(HttpError("connection closed before delimiter".to_string()));
                }
            }
        }

        async fn read_n(&mut self, n: usize) -> Result<Vec<u8>, HttpError> {
            while self.buf.len() < n {
                if self.fill().await? == 0 {
                    return Err(HttpError(format!("connection closed before {n} bytes")));
                }
            }
            Ok(self.buf.drain(..n).collect())
        }

        async fn read_all(&mut self, cap: usize) -> Vec<u8> {
            while !self.eof && self.buf.len() <= cap {
                if self.fill().await.unwrap_or(0) == 0 {
                    break;
                }
            }
            let take = self.buf.len().min(cap);
            self.buf.drain(..take).collect()
        }
    }

    /// Read a `Transfer-Encoding: chunked` body from the stream, capping the
    /// decoded output at `max_bytes`.
    async fn read_chunked<S: AsyncRead + Unpin>(
        r: &mut Reader<'_, S>,
        max_bytes: usize,
    ) -> Result<(Vec<u8>, bool), HttpError> {
        let mut out: Vec<u8> = Vec::new();
        let mut truncated = false;
        loop {
            let line = r.read_until(b"\r\n", 16 * 1024).await?;
            let stripped = trim_ascii(&line[..line.len().saturating_sub(2)]);
            let size_hex = trim_ascii(stripped.split(|&b| b == b';').next().unwrap_or(&[]));
            let size = std::str::from_utf8(size_hex)
                .ok()
                .and_then(|s| usize::from_str_radix(s, 16).ok())
                .ok_or_else(|| HttpError(format!("bad chunk size: {stripped:?}")))?;
            if size == 0 {
                let _ = r.read_until(b"\r\n", 16 * 1024).await; // trailers / final CRLF
                break;
            }
            // NB: `out.len() + size > max_bytes` would OVERFLOW. `size` is the
            // onion's hex chunk header and can be usize::MAX, whose sum wraps to
            // a small value that passes the check — and `read_n` is then handed
            // an unbounded length, voiding the whole body budget. Compare
            // against the room that is left instead.
            let want = max_bytes.saturating_sub(out.len());
            if size > want {
                // stop before over-reading; abandon framing (non-reusable)
                out.extend_from_slice(&r.read_n(want).await?);
                truncated = true;
                break;
            }
            let data = r.read_n(size).await?;
            r.read_n(2).await?; // trailing CRLF
            out.extend_from_slice(&data);
        }
        Ok((out, truncated))
    }

    /// Send one request on `stream` and read one response. The caller owns the
    /// stream lifecycle. Body reads are bounded by `max_bytes`; a larger body is
    /// truncated (`truncated == true`) rather than read unbounded.
    ///
    /// # Errors
    /// [`HttpError`] on I/O failure or a malformed response.
    pub async fn perform_request<S: AsyncRead + AsyncWrite + Unpin>(
        stream: &mut S,
        method: &str,
        host: &str,
        path: &str,
        headers: &[(String, String)],
        max_bytes: usize,
    ) -> Result<HttpResponse, HttpError> {
        let req = build_request(method, path, host, headers);
        stream
            .write_all(&req)
            .await
            .map_err(|e| HttpError(format!("write: {e}")))?;

        let mut reader = Reader::new(stream);
        let head = reader.read_until(b"\r\n\r\n", MAX_HEAD).await?;
        let head = &head[..head.len().saturating_sub(4)]; // drop the blank line
        let (status_line, header_block) = match find_sub(head, 0, b"\r\n") {
            Some(i) => (&head[..i], &head[i + 2..]),
            None => (head, &head[head.len()..]),
        };
        let (version, status, reason) = parse_status_line(status_line)?;
        let hdrs: Headers = parse_headers(header_block);

        let conn = hdrs.get("connection").unwrap_or("").to_lowercase();
        let keep_alive = version.eq_ignore_ascii_case("HTTP/1.1") && !conn.contains("close");

        // Bodyless responses: nothing to drain -> reusable iff keep-alive.
        if method.eq_ignore_ascii_case("HEAD")
            || status == 204
            || status == 304
            || (100..200).contains(&status)
        {
            return Ok(HttpResponse {
                status,
                reason,
                headers: hdrs,
                body: Vec::new(),
                truncated: false,
                reusable: keep_alive,
            });
        }

        let te = hdrs.get("transfer-encoding").unwrap_or("").to_lowercase();
        let (raw_body, mut truncated, framed) = if te.contains("chunked") {
            let (b, t) = read_chunked(&mut reader, max_bytes).await?;
            (b, t, true)
        } else if let Some(cl) = hdrs.get("content-length") {
            let length: usize = cl
                .trim()
                .parse()
                .map_err(|_| HttpError("invalid content-length".to_string()))?;
            let (want, trunc) = if length > max_bytes {
                (max_bytes, true)
            } else {
                (length, false)
            };
            (reader.read_n(want).await?, trunc, true)
        } else {
            let b = reader.read_all(max_bytes).await;
            let trunc = !reader.eof;
            (b, trunc, false)
        };

        let (body, dec_trunc) = decompress(
            &raw_body,
            hdrs.get("content-encoding").unwrap_or(""),
            max_bytes,
        )?;
        truncated = truncated || dec_trunc;
        let reusable = keep_alive && framed && !truncated;

        Ok(HttpResponse {
            status,
            reason,
            headers: hdrs,
            body,
            truncated,
            reusable,
        })
    }
}

#[cfg(feature = "net")]
pub use net_io::perform_request;

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
