//! The HTTP/1.1 client wire layer, written once for every engine that speaks it.
//!
//! # Why this module exists
//!
//! `websearch::httpclient` and `onioncrawler::http` used to be two
//! independently-maintained copies of the same code: the same buffered reader,
//! the same `decode_chunked`, the same streaming `read_chunked`, the same
//! `parse_headers`, the same `Headers`, the same `build_request`, the same
//! `Content-Encoding` handling. The duplication was not cosmetic — it was the
//! largest single source of defects in the workspace. An audit found four
//! *shapes* of bug in that layer, and **each one existed twice, because the code
//! existed twice**:
//!
//! 1. **The chunked-size integer overflow.** `out.len() + size > cap`, where
//!    `size` is the peer's hex chunk header: a declared `ffffffffffffffff` wraps
//!    the sum to a small value that *passes* the check, and the reader is then
//!    handed an unbounded length — 3 MB of wire measured into 529 MB of RSS in
//!    0.6 s, and a panic in debug. The cap arithmetic now goes through
//!    [`crate::budget::Budget`], which does not expose a `+` to overflow.
//! 2. **CRLF injection through a truncated code point.** The request target was
//!    written out with `c as u8`, so U+010D became a raw CR and U+010A a raw LF:
//!    a crawled `<a href="/x\u{010d}\u{010a}X-Injected: 1">` injected a header
//!    line, and a doubled pair smuggled a whole second request onto a keep-alive
//!    socket. [`build_request`] percent-encodes instead.
//! 3. **Bare-LF header smuggling.** Splitting the head on CRLF left a lone LF
//!    *inside* the preceding value — and an `ETag` is stored and replayed as
//!    `If-None-Match` on the next conditional GET, so that was a persistent,
//!    cross-run injection. [`parse_headers`] splits on LF and strips a trailing
//!    CR.
//! 4. **The quadratic header parse.** Joining a duplicate key by scanning the
//!    accumulated list made a head O(headers²): an origin filling the head cap
//!    with tens of thousands of one-byte headers cost about a second of crawler
//!    CPU per response, free, on every page it served. [`parse_headers`] keeps a
//!    name→slot index — as a *local*, never as a field on [`Headers`], which is
//!    held across `.await` points all the way down the fetch path (widening it
//!    once overflowed a debug-build future's stack).
//!
//! Each of those had to be found twice, fixed twice and regression-tested twice.
//! The next framing bug would have landed twice as well. So the wire layer lives
//! here, once; the regression tests for all four shapes are at the bottom of
//! this file; and the engines keep only what genuinely differs between them —
//! `websearch` its SSRF gate (`vet_addrs`/`SafeIp`, the DNS cache, the pinned
//! connect, redirects, the keep-alive `Fetcher`), `onioncrawler` its SOCKS/Tor
//! transport and the `OnionHost` darknet gate.
//!
//! # Two decompression conventions, kept on purpose
//!
//! The one place the two copies had genuinely diverged is what to do with a
//! `Content-Encoding` body that does not decode, and how the cap is expressed.
//! Both behaviours survive, named, as [`decompress_or_raw`] (never fails; an
//! undecodable body is used verbatim) and [`decompress_checked`] (an undecodable
//! declared encoding is a protocol error), selected on the wire path by
//! `ContentEncodingPolicy` (behind the `net` feature). Silently picking one
//! would have changed what an engine stores for a malformed origin, which is
//! exactly the kind of change the byte-identity goldens exist to catch.
//!
//! # Feature tiers
//!
//! Everything above the `net` line is pure and compiles with **no features** and
//! no third-party dependencies. Only the streaming reader (`perform_request`
//! and the chunked body reader behind it) needs `tokio::io::AsyncRead`, so it
//! sits behind the opt-in `net` feature, like the net tiers of the engines.

use crate::inflate::{inflate_gzip, inflate_raw, inflate_zlib};
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

// ---- header collection ----------------------------------------------------

/// Response headers: lowercased keys, insertion-ordered, duplicate keys joined
/// with `, ` — matching the message semantics of Python's `http.client`, which
/// both engines were ported from.
///
/// Deliberately just a `Vec` of pairs and nothing else. This value is held
/// across `.await` points the whole length of the fetch path, so every field
/// added here is added to a future that several async layers keep on the stack;
/// adding a duplicate-key index as a field once overflowed a debug-build task's
/// stack (`net_multiworker::keep_alive_reuses_connections`, "thread has
/// overflowed its stack"). The index [`parse_headers`] needs lives inside
/// `parse_headers`, as a local, and dies there.
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

// ---- small byte helpers ---------------------------------------------------

/// Decode a byte slice as latin-1 (ISO-8859-1) — every byte maps to the code
/// point of the same value. This is how a response head is read: HTTP/1.1 heads
/// are bytes, and latin-1 is the total, lossless byte→char mapping.
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

fn find_bytes(buf: &[u8], needle: &[u8]) -> Option<usize> {
    find_sub(buf, 0, needle)
}

fn find_sub(buf: &[u8], from: usize, needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || buf.len() < needle.len() {
        return None;
    }
    // `from > last` yields an empty inclusive range (→ None), never an OOB slice.
    let last = buf.len() - needle.len();
    (from..=last).find(|&i| &buf[i..i + needle.len()] == needle)
}

// ---- request side ---------------------------------------------------------

/// Build a request: request-line + `Host` + extra headers (in order) + the blank
/// line. An empty `path` becomes `/`.
///
/// Everything that reaches the wire passes `header_safe` (CR/LF/NUL stripped)
/// or `encode_request_target` (percent-encoded), so neither a crawled URL nor
/// a header value replayed out of storage can add a line to the request.
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

// ---- response head --------------------------------------------------------

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
            // Duplicate keys are joined with `, `, matching `http.client`.
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

/// Parse a chunk-size line (`1a3`, or `1a3;ext=1` with a chunk extension) into
/// its length. Shared by the in-memory and streaming chunk decoders so the two
/// cannot drift on what counts as a valid size.
///
/// # Errors
/// [`HttpError`] if the size is not hex, or does not fit in a `usize`.
fn parse_chunk_size(size_line: &[u8]) -> Result<usize, HttpError> {
    let size_hex = trim_ascii(size_line.split(|&b| b == b';').next().unwrap_or(&[]));
    std::str::from_utf8(size_hex)
        .ok()
        .and_then(|s| usize::from_str_radix(s, 16).ok())
        .ok_or_else(|| HttpError(format!("bad chunk size: {size_line:?}")))
}

/// Decode a complete `Transfer-Encoding: chunked` body buffer into
/// `(bytes, truncated)`, capping the decoded output at `max_bytes`.
///
/// This is the pure, in-memory form (a whole body already in a buffer). The
/// streaming form, which reads chunks off a socket under the same budget, is
/// behind the `net` feature — see `perform_request`.
///
/// Note the deliberate difference in what `truncated` means between the two: the
/// in-memory form reports `truncated` once the output *reaches* `max_bytes`
/// (there is no more room, whether or not a byte was actually dropped), while
/// the streaming form reports it only when a chunk genuinely does not fit. Both
/// behaviours predate this module and are asserted by the engines' goldens.
///
/// # Errors
/// [`HttpError`] on a missing delimiter, a bad chunk size, or a short chunk.
pub fn decode_chunked(buf: &[u8], max_bytes: usize) -> Result<(Vec<u8>, bool), HttpError> {
    // The cap lives in a `Budget`, not in `out.len() + size > max_bytes`: `size`
    // is the peer's declared chunk length and can be `usize::MAX`, whose sum
    // wraps to a small value that passes such a check.
    let mut budget = crate::budget::Budget::new(max_bytes);
    let mut out: Vec<u8> = Vec::new();
    let mut truncated = false;
    let mut i = 0;
    loop {
        let line_end = find_sub(buf, i, b"\r\n")
            .ok_or_else(|| HttpError("chunk size: no CRLF".to_string()))?;
        let size_line = trim_ascii(&buf[i..line_end]);
        i = line_end + 2;
        let size = parse_chunk_size(size_line)?;
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
        let grant = budget.take(size);
        out.extend_from_slice(&data[..grant]);
        if budget.is_exhausted() {
            truncated = true;
        }
    }
    Ok((out, truncated))
}

// ---- Content-Type / Content-Encoding / charset -----------------------------

/// Parse a `Content-Type` header value into `(media_type, charset)`.
///
/// The media type is lower-cased and stripped; the charset (if a `charset=`
/// parameter is present) is unquoted and lower-cased.
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

/// Decompress a response body per its `Content-Encoding`, **never failing**: a
/// body that does not decode is returned as-is, so a malformed origin yields the
/// raw bytes rather than an error.
///
/// The output is capped at `max_bytes + 1`, so the caller detects truncation as
/// `len > max_bytes` — the convention `websearch` inherited from its Python
/// `_one`. (`saturating_add`, because `max_bytes + 1` at `usize::MAX` is itself
/// an overflow of exactly the shape this module exists to remove.) `enc` is
/// matched verbatim and is expected already trimmed + lower-cased; `gzip` uses
/// the gzip wrapper, `deflate`/`zlib` try the zlib wrapper first and then raw
/// DEFLATE, and anything else (identity / unknown) passes through unchanged.
///
/// See [`decompress_checked`] for the other convention, and
/// `ContentEncodingPolicy` for which engine uses which and why.
#[must_use]
pub fn decompress_or_raw(raw: &[u8], enc: &str, max_bytes: usize) -> Vec<u8> {
    let limit = max_bytes.saturating_add(1);
    match enc {
        "gzip" => inflate_gzip(raw, limit).map_or_else(|_| raw.to_vec(), |(b, _)| b),
        "deflate" | "zlib" => match inflate_zlib(raw, limit) {
            Ok((b, _)) => b,
            Err(_) => inflate_raw(raw, limit).map_or_else(|_| raw.to_vec(), |(b, _)| b),
        },
        _ => raw.to_vec(),
    }
}

/// Decompress a response body per its `Content-Encoding`, hard-capping the
/// decompressed size at `max_bytes` (a decompression bomb is stopped at the cap
/// with `truncated == true`) and **failing** if a declared `gzip`/`deflate` body
/// does not decode — the convention `onioncrawler` inherited from its Python
/// `_decompress`, where a corrupt body is a protocol error rather than content.
///
/// `encoding` is trimmed and lower-cased here, so the raw header value can be
/// passed straight in. `identity`/empty/unknown encodings pass through
/// unchanged; `deflate` tries raw DEFLATE first and then a zlib wrapper (servers
/// send both).
///
/// # Errors
/// [`HttpError`] if a declared `gzip`/`x-gzip`/`deflate` body fails to decode.
pub fn decompress_checked(
    body: &[u8],
    encoding: &str,
    max_bytes: usize,
) -> Result<(Vec<u8>, bool), HttpError> {
    let enc = encoding.trim().to_lowercase();
    if enc.is_empty() || enc == "identity" {
        return Ok((body.to_vec(), false));
    }
    let fail =
        |e: crate::inflate::InflateError| HttpError(format!("failed to decompress {enc:?}: {e}"));
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

// ---- one parsed response --------------------------------------------------

/// A single parsed HTTP response (one hop, before any redirect handling).
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

// ---- the streaming half (net feature) --------------------------------------

/// How a response's `Content-Encoding` is applied on the wire path.
///
/// The two engines had genuinely diverged here, so both conventions survive and
/// the caller names the one it wants rather than inheriting whichever copy it
/// happened to be reading. Nothing else about [`perform_request`] differs
/// between them.
#[cfg(feature = "net")]
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ContentEncodingPolicy {
    /// Never fail on a body that does not decode — use it verbatim, and cut an
    /// over-cap body at the cap with `truncated` set. What `websearch` does,
    /// inherited from its Python `_one`: a malformed origin still yields
    /// indexable bytes. See [`decompress_or_raw`].
    OrRaw,
    /// Treat a declared `gzip`/`deflate` body that does not decode as a protocol
    /// error, failing the whole request. What `onioncrawler` does, inherited from
    /// its Python `_decompress`. See [`decompress_checked`].
    Checked,
}

/// Cap on the response head (status line + headers) size.
#[cfg(feature = "net")]
const MAX_HEAD: usize = 256 * 1024;

#[cfg(feature = "net")]
mod net_io {
    use super::{
        build_request, decompress_checked, decompress_or_raw, find_sub, parse_chunk_size,
        parse_headers, parse_status_line, trim_ascii, ContentEncodingPolicy, Headers, HttpError,
        HttpResponse, MAX_HEAD,
    };
    use crate::budget::Budget;
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

    /// Read a `Transfer-Encoding: chunked` body off the stream, capping the
    /// decoded output at `max_bytes`.
    ///
    /// Unlike the in-memory [`super::decode_chunked`], this reports `truncated`
    /// only when a chunk genuinely does not fit — a body that ends exactly on the
    /// cap is complete. Both engines behaved this way before the code was shared;
    /// see the note on `decode_chunked`.
    async fn read_chunked<S: AsyncRead + Unpin>(
        r: &mut Reader<'_, S>,
        max_bytes: usize,
    ) -> Result<(Vec<u8>, bool), HttpError> {
        // The cap is a `Budget`, not `out.len() + size > max_bytes`: `size` is the
        // peer's hex chunk header and can be `usize::MAX`, whose sum wraps to a
        // small value that passes such a check — and `read_n` is then handed an
        // unbounded length (measured: 3 MB of wire became 529 MB RSS in 0.6 s).
        // A `Budget` has no arithmetic to wrap: it answers with what is left.
        let mut budget = Budget::new(max_bytes);
        let mut out: Vec<u8> = Vec::new();
        let mut truncated = false;
        loop {
            let line = r.read_until(b"\r\n", 16 * 1024).await?;
            let stripped = trim_ascii(&line[..line.len().saturating_sub(2)]);
            let size = parse_chunk_size(stripped)?;
            if size == 0 {
                let _ = r.read_until(b"\r\n", 16 * 1024).await; // trailers / final CRLF
                break;
            }
            let grant = budget.take(size);
            if grant < size {
                // stop before over-reading; abandon framing (non-reusable)
                out.extend_from_slice(&r.read_n(grant).await?);
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
    /// stream lifecycle — and, in `websearch`, has already vetted and connected it
    /// through the SSRF gate; in `onioncrawler`, tunnelled it through SOCKS to a
    /// validated `OnionHost`. Body reads are bounded by `max_bytes`; a larger body
    /// is truncated (`truncated == true`) rather than read unbounded.
    ///
    /// `encoding` selects between the two `Content-Encoding` conventions — see
    /// [`ContentEncodingPolicy`]. Everything else here (head cap, framing choice,
    /// keep-alive decision) is identical for every caller, which is the point.
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
        encoding: ContentEncodingPolicy,
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
            // `length` is the origin's declared size: ask the budget for it and
            // take what it grants rather than comparing it against the cap.
            let mut budget = Budget::new(max_bytes);
            let want = budget.take(length);
            (reader.read_n(want).await?, budget.overrun(), true)
        } else {
            let b = reader.read_all(max_bytes).await;
            let trunc = !reader.eof;
            (b, trunc, false)
        };

        let body = match encoding {
            ContentEncodingPolicy::OrRaw => {
                // Decompress per Content-Encoding (bounded); a body over
                // `max_bytes` after decompression is truncated.
                let enc = hdrs
                    .get("content-encoding")
                    .unwrap_or("")
                    .trim()
                    .to_lowercase();
                let body = decompress_or_raw(&raw_body, &enc, max_bytes);
                if body.len() > max_bytes {
                    truncated = true;
                    body[..max_bytes].to_vec()
                } else {
                    body
                }
            }
            ContentEncodingPolicy::Checked => {
                let (body, dec_trunc) = decompress_checked(
                    &raw_body,
                    hdrs.get("content-encoding").unwrap_or(""),
                    max_bytes,
                )?;
                truncated = truncated || dec_trunc;
                body
            }
        };
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
            build_request("GET", "", "example.com", &[]),
            b"GET / HTTP/1.1\r\nHost: example.com\r\n\r\n"
        );
        let (v, s, r) = parse_status_line(b"HTTP/1.1 200 OK").unwrap();
        assert_eq!((v.as_str(), s, r.as_str()), ("HTTP/1.1", 200, "OK"));
        assert!(parse_status_line(b"garbage").is_err());
        assert!(parse_status_line(b"HTTP/1.1 notnum OK").is_err());
    }

    #[test]
    fn headers_lowercase_join_and_lookup() {
        let h = parse_headers(b"Content-Type: text/html\r\nSet-Cookie: a\r\nSet-Cookie: b");
        assert_eq!(h.get("content-type"), Some("text/html"));
        assert_eq!(h.get("Set-Cookie"), Some("a, b"));
        assert_eq!(h.pairs().len(), 2);
    }

    #[test]
    fn chunked_decode_and_truncate() {
        let (body, trunc) = decode_chunked(b"4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n", 100).unwrap();
        assert_eq!(body, b"Wikipedia");
        assert!(!trunc);
        let (body, trunc) = decode_chunked(b"4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n", 6).unwrap();
        assert_eq!(body, b"Wikipe");
        assert!(trunc);
        // chunk extensions after ';' are ignored
        assert_eq!(
            decode_chunked(b"4;ext=1\r\nWiki\r\n0\r\n\r\n", 100).unwrap(),
            (b"Wiki".to_vec(), false)
        );
    }

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
    }

    #[test]
    fn body_decode_charsets() {
        assert_eq!(decode_body(b"caf\xc3\xa9", Some("utf-8")), "café");
        assert_eq!(decode_body(b"caf\xe9", Some("latin-1")), "café");
        assert_eq!(
            decode_body(b"<meta charset=utf-8>\xc3\xa9", None),
            "<meta charset=utf-8>é"
        );
    }

    /// The two decompression conventions are kept apart deliberately (see the
    /// module doc): one never fails and caps at `max_bytes + 1`, the other
    /// reports a corrupt declared encoding as an error and caps at `max_bytes`.
    /// This test is the record of that divergence.
    #[test]
    fn the_two_decompression_conventions_stay_distinct() {
        // gzip("hello world").
        let hex = "1f8b0800000000000203cb48cdc9c95728cf2fca49010085114a0d0b000000";
        let gz: Vec<u8> = (0..hex.len() / 2)
            .map(|i| u8::from_str_radix(&hex[i * 2..i * 2 + 2], 16).unwrap())
            .collect();
        // …and an origin that declares `gzip` and sends something else, which is
        // by far the commonest way a real body fails to decode.
        let lying = b"<html>not gzip at all</html>";

        // Same happy path.
        assert_eq!(decompress_or_raw(&gz, "gzip", 1_000_000), b"hello world");
        assert_eq!(
            decompress_checked(&gz, "gzip", 1_000_000).unwrap(),
            (b"hello world".to_vec(), false)
        );

        // Divergence 1: an undecodable body is content vs. an error.
        assert_eq!(decompress_or_raw(lying, "gzip", 1_000_000), lying);
        assert!(decompress_checked(lying, "gzip", 1_000_000).is_err());

        // Divergence 2: the cap. `or_raw` inflates one byte past it so the
        // caller can see the overshoot; `checked` stops at it and says so.
        assert_eq!(decompress_or_raw(&gz, "gzip", 5).len(), 6);
        assert_eq!(
            decompress_checked(&gz, "gzip", 5).unwrap(),
            (b"hello".to_vec(), true)
        );

        // Divergence 3: the accepted labels. `or_raw` matches verbatim (its
        // caller lower-cases) and knows `zlib`; `checked` normalises and knows
        // `x-gzip`.
        assert_eq!(decompress_or_raw(&gz, "GZIP", 1_000_000), gz);
        assert_eq!(
            decompress_checked(&gz, " GZIP ", 1_000_000).unwrap(),
            (b"hello world".to_vec(), false)
        );
        assert_eq!(
            decompress_checked(&gz, "x-gzip", 1_000_000).unwrap(),
            (b"hello world".to_vec(), false)
        );

        // Both pass identity / unknown encodings through untouched.
        assert_eq!(decompress_or_raw(b"raw", "br", 100), b"raw");
        assert_eq!(
            decompress_checked(b"raw", "br", 100).unwrap(),
            (b"raw".to_vec(), false)
        );
    }

    /// `max_bytes + 1` at the top of the range used to be a literal `+`, which
    /// panics in debug and wraps to a zero cap in release.
    #[test]
    fn an_absurd_cap_does_not_overflow_the_or_raw_limit() {
        assert_eq!(decompress_or_raw(b"raw", "identity", usize::MAX), b"raw");
    }
}

/// The four defects this module was created to stop having to fix twice. Each
/// one was found, fixed and regression-tested independently in
/// `websearch::httpclient` and in `onioncrawler::http`; from here there is one
/// copy of the code and one copy of the test.
#[cfg(test)]
mod audit_regression {
    use super::*;

    /// `out.len() + size > max_bytes` OVERFLOWS: `size` is the origin's hex
    /// chunk header, so `ffffffffffffffff` wraps the sum to a small value that
    /// PASSES the check, and the reader is then handed an unbounded length
    /// (measured: 3 MB of wire became 529 MB RSS in 0.6 s).
    #[test]
    fn a_chunk_size_near_usize_max_cannot_bypass_the_body_cap() {
        let mut buf = b"1\r\nA\r\nffffffffffffffff\r\n".to_vec();
        buf.extend_from_slice(&b"B".repeat(4096));
        buf.extend_from_slice(b"\r\n0\r\n\r\n");
        // Must refuse rather than wrap into an inverted slice range.
        assert!(decode_chunked(&buf, 1024).is_err());
    }

    /// The same shape one layer up: a *sequence* of chunks whose total exceeds
    /// the cap is cut at the cap, and the budget — not `+` — is what says so.
    #[test]
    fn a_body_larger_than_the_cap_is_cut_at_it() {
        let (body, trunc) = decode_chunked(b"4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n", 6).unwrap();
        assert_eq!(body, b"Wikipe");
        assert!(trunc);
    }

    /// `latin1_encode`'s `c as u8` TRUNCATED code points: U+010D became a raw CR
    /// and U+010A a raw LF, so a crawled href injected a header line and a
    /// doubled pair smuggled a second request onto a keep-alive socket.
    #[test]
    fn a_request_target_can_never_inject_a_header_line() {
        let req = build_request("GET", "/x\u{010d}\u{010a}X-Injected: 1", "example.com", &[]);
        let text = String::from_utf8_lossy(&req);
        assert!(!text.contains("X-Injected: 1\r\n"), "injected: {text:?}");
        assert!(text.starts_with("GET /x%C4%8D%C4%8A"), "{text:?}");
        // The head must contain exactly one CRLFCRLF — i.e. one request.
        assert_eq!(text.matches("\r\n\r\n").count(), 1, "{text:?}");
    }

    /// A stored `ETag` is replayed as `If-None-Match` on the next conditional
    /// GET, so a CR/LF planted in one is a persistent, cross-run injection.
    #[test]
    fn a_stored_header_value_cannot_inject_on_replay() {
        let hs = vec![(
            "If-None-Match".to_string(),
            "\"x\"\r\nX-Evil: 1".to_string(),
        )];
        let req = build_request("GET", "/", "example.com", &hs);
        let text = String::from_utf8_lossy(&req);
        // The CR/LF are stripped, so the whole thing collapses into ONE header
        // value — no line of the request may begin with the smuggled name.
        assert!(
            !text.lines().any(|l| l.starts_with("X-Evil:")),
            "smuggled a header line: {text:?}"
        );
        assert!(
            text.contains("If-None-Match: \"x\"X-Evil: 1\r\n"),
            "{text:?}"
        );
        assert_eq!(text.matches("\r\n\r\n").count(), 1, "{text:?}");
    }

    /// A header line terminated by a BARE LF used to leave that LF inside the
    /// preceding value, which is then stored and replayed.
    #[test]
    fn a_bare_lf_terminates_a_header_line() {
        let h = parse_headers(b"ETag: \"a\"\nX-Next: b\r\n");
        assert_eq!(h.get("etag"), Some("\"a\""));
        assert_eq!(h.get("x-next"), Some("b"));
    }

    /// Parsing a head was O(headers²): an origin filling the head cap with tens
    /// of thousands of one-byte headers cost ~1 s of crawler CPU per response.
    ///
    /// What is asserted is how the cost SCALES with the size of one head, not how
    /// long any parse took. The two sides parse the SAME 8 000 headers — four
    /// blocks of 2 000 against one block of 8 000 — so a linear parse costs the
    /// same on both and the ratio is 1, while a quadratic one costs
    /// `8000²/(4·2000²) = 4` times more on the single big block.
    ///
    /// What makes the ratio survive a loaded runner is the best-of-9 minimum
    /// below, not any cancellation between the two sides. Contention does not
    /// cancel out of a quotient: an extra `d` on both sides gives `(L+d)/(S+d)`,
    /// which is not `L/S` but drifts toward 1, and in practice the perturbations
    /// are independent, so they do not even land on both sides. Both effects were
    /// observed here — a run whose small-side minimum was inflated to 22.3 ms
    /// against a typical 13.5–14.5 ms read 0.711, and a run that caught the large
    /// side instead read 1.426. What bounds them is that a preemption can only
    /// ever inflate a sample, so the minimum of nine is the reading closest to the
    /// work itself, and it only takes one of the nine to run cleanly. Matching the
    /// two sides' durations (~14 ms each) is still worth doing — it keeps the two
    /// minima comparable estimators — but it is not what carries the test.
    ///
    /// Measured over 16 runs of the whole `--lib` binary under a 16-way CPU load
    /// on this 2-core box: 1.046–1.426, with the one 0.711 above. Reverting
    /// `parse_headers` to the linear duplicate scan gives 4.13–4.30 and fails 6 of
    /// 6 — the predicted 4. The bar of 2.5 sits 1.75× above the worst reading a
    /// correct parse gave and 1.65× below the best the defect gave.
    ///
    /// What this cannot rule out: the two sides have different working sets, 2 000
    /// rows against 8 000, so a `Vec<(String, String)>` of about 94 KB against
    /// about 375 KB before the strings themselves. On a machine where the large
    /// side spills a cache level the small side fits inside, a CORRECT parse's
    /// ratio rises for a reason that has nothing to do with the exponent. It does
    /// not happen here — this box has 32 KB of L1d and 1 MB of L2, so both sides
    /// miss L1 and both fit L2, which is why the readings sit near 1.05 — but a
    /// box with a 256 KB L2 would straddle the tiers. The margin is what absorbs
    /// that: the defect this names lands at ~4.2, not at 1.5.
    ///
    /// The bound was `elapsed() < 2 s` on a single 20 000-header parse, which was
    /// wrong in both directions. It sat only 4.3× above what a CORRECT parse
    /// measured — 62 ms idle, 464 ms under load on two cores — so a runner four
    /// times slower than this one failed it for no reason. And it barely
    /// discriminated against the defect it names: the quadratic parse cost about
    /// 1 s, so a regression landing anywhere under 2 s passed. A ratio has no
    /// such blind spot, because what it measures is the exponent rather than the
    /// machine.
    #[test]
    fn many_headers_parse_in_linear_time() {
        // `h0: v\r\n`, `h1: v\r\n`, … — the shape an origin fills the head with.
        fn block_of(n: usize) -> String {
            let mut block = String::new();
            for i in 0..n {
                block.push_str(&format!("h{i}: v\r\n"));
            }
            block
        }
        // One sample: `reps` parses of `block`, timed together.
        fn sample(block: &str, reps: usize, want: usize) -> std::time::Duration {
            let t = std::time::Instant::now();
            for _ in 0..reps {
                let h = parse_headers(block.as_bytes());
                assert_eq!(h.pairs().len(), want);
            }
            t.elapsed()
        }

        let small = block_of(2_000);
        let large = block_of(8_000);
        // Alternate the two sides so they see the same stretch of whatever else
        // the machine is doing, and keep the fastest of each: a preemption can
        // only ever inflate a sample, never shorten it, so the minimum is the
        // reading closest to the work itself. A mean would carry the scheduler in.
        let (mut best_small, mut best_large) = (std::time::Duration::MAX, std::time::Duration::MAX);
        for _ in 0..9 {
            best_small = best_small.min(sample(&small, 4, 2_000));
            best_large = best_large.min(sample(&large, 1, 8_000));
        }
        // `2 * large < 5 * small`, i.e. a factor of 2.5, in integer arithmetic.
        assert!(
            best_large * 2 < best_small * 5,
            "8 000 headers in one block took {best_large:?} against {best_small:?} for the \
             same 8 000 in four blocks, a factor of {:.2} where linear is ~1 and quadratic \
             is ~4: the parse is quadratic again",
            best_large.as_secs_f64() / best_small.as_secs_f64().max(f64::MIN_POSITIVE)
        );
    }
}
