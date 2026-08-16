//! This crate's view of the HTTP/1.1 client that runs over an already-connected
//! (SOCKS-tunnelled) stream.
//!
//! The wire layer itself is **not** here any more: request building, response-head
//! and chunked-body parsing, and `Content-Encoding` decompression are shared with
//! `websearch` in [`crawlcore::http`]. They were two independently-maintained
//! copies of the same code until four framing / injection defects had each been
//! found and fixed twice, once per copy — the chunked-size integer overflow, the
//! CRLF injection through a truncated code point, the bare-LF header smuggling
//! and the O(headers²) head parse. That module's doc names all four; this one
//! re-exports the results at their historical paths, so `tests/xcheck_http.rs`
//! and every call site keep working unchanged.
//!
//! What is genuinely this crate's stays this crate's: the SOCKS5 transport
//! ([`crate::socks`]), the `&OnionHost` anti-leak gate ([`crate::fetcher`]), the
//! I2P proxy encoders ([`crate::i2p`]), and — below — the darknet
//! `Content-Encoding` convention, which is the one thing about the wire layer the
//! two engines genuinely disagreed on.
//!
//! Redirects are handled one hop at a time by the caller (the fetcher), because a
//! redirect to a new host needs a fresh tunnel.

/// The shared HTTP/1.1 wire layer, at this crate's historical paths. Cross-checked
/// byte-identical to the Python `http_client.py` in `tests/xcheck_http.rs`.
pub use crawlcore::http::{
    build_request, decode_chunked, parse_headers, parse_status_line, Headers, HttpError,
    HttpResponse,
};

/// This crate's `Content-Encoding` convention: a declared `gzip`/`deflate` body
/// that does not decode is a **protocol error**, not content — a hidden service
/// that lies about its encoding gets an error, not indexed garbage — and the
/// decompressed size is hard-capped at `max_bytes` with a `truncated` flag, so a
/// decompression bomb stops at the cap. Mirrors the Python `_decompress`.
/// (`websearch` returns an undecodable body as-is instead; see
/// [`crawlcore::http::decompress_or_raw`].)
pub use crawlcore::http::decompress_checked as decompress;

/// Send one request on `stream` and read one response, under this crate's
/// `Content-Encoding` convention (see [`decompress`]). The caller owns the stream
/// lifecycle — in practice a SOCKS5 tunnel to a validated [`crate::onion::OnionHost`].
/// Body reads are bounded by `max_bytes`; a larger body is truncated
/// (`truncated == true`) rather than read unbounded.
///
/// The framing — the head cap, the chunked reader with its
/// [`crawlcore::budget::Budget`]-bounded chunk sizes, the content-length and
/// read-to-EOF paths, the keep-alive decision — is
/// [`crawlcore::http::perform_request`], shared with `websearch`, so a framing fix
/// lands once.
///
/// This forwards the shared future rather than `await`ing it inside an `async fn`
/// of its own: the fetch path is several async layers deep, a debug-build future
/// nests its callee's state inline, and one extra `async fn` here was enough to
/// abort a keep-alive test in the sibling crate with "thread has overflowed its
/// stack".
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
        crawlcore::http::ContentEncodingPolicy::Checked,
    )
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

    /// The re-exported wire layer keeps this crate's observable behaviour: an
    /// origin that declares an encoding its body does not honour is an error,
    /// where `websearch` would take the bytes verbatim.
    #[test]
    fn an_undecodable_declared_encoding_is_an_error() {
        assert!(decompress(b"<html>not gzip</html>", "gzip", 1_000_000).is_err());
        assert_eq!(
            decompress(b"<html>", "identity", 1_000_000).unwrap(),
            (b"<html>".to_vec(), false)
        );
    }
}
