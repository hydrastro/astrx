//! I2P HTTP-proxy helpers for reaching `.i2p` eepsites via a local I2P router's
//! HTTP proxy (default `127.0.0.1:4444`).
//!
//! Two transport shapes, analogous to the SOCKS module:
//! * plain-http eepsite → send an **absolute-form** request line to the proxy
//!   (`GET http://site.i2p/path HTTP/1.1`); the proxy forwards it into I2P.
//! * https eepsite → issue an HTTP `CONNECT site.i2p:port` to the proxy, then run
//!   TLS over the returned tunnel (origin-form).
//!
//! The encoders are pure functions (unit-testable without a socket, cross-checked
//! in `tests/xcheck_i2p.rs`). The eepsite name is sent to the proxy verbatim — we
//! NEVER resolve `.i2p` locally, which is both how I2P works and an anti-leak
//! requirement (the address never becomes an IP on this host).
//!
//! # The gate
//!
//! "We never resolve it here" is only half of the anti-leak property: the other
//! half is that the *proxy* must not resolve it either. An I2P router's HTTP
//! proxy forwards a non-`.i2p` host to its configured **outproxy**, which does a
//! clearnet DNS lookup and opens a clearnet TCP connection — so
//! `build_http_connect("tracker.example.com", 443)` would have been a leak
//! authored by this module, not by I2P. Both encoders below therefore refuse any
//! host that is not a valid eepsite ([`crate::onion::is_i2p_host`]), and
//! [`build_proxy_get_target_checked`] takes a validated [`I2pHost`] so the
//! refusal happens at the type level, the way [`crate::fetcher::Fetcher::open`]
//! takes an `&OnionHost`.

use std::fmt;

use crate::onion::{is_i2p_host, I2pHost};

/// An I2P proxy error.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct I2PError(pub String);

impl fmt::Display for I2PError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for I2PError {}

/// Encode an HTTP `CONNECT` request for the I2P HTTP proxy (for https eepsites):
/// `CONNECT host:port HTTP/1.1` + `Host` + `User-Agent` + `Proxy-Connection` +
/// the blank line.
///
/// # Errors
/// [`I2PError`] if `port` is 0, `host` is empty / contains a space, CR or LF, or
/// `host` is not a valid `.i2p` eepsite.
pub fn build_http_connect(host: &str, port: u16) -> Result<Vec<u8>, I2PError> {
    if port == 0 {
        return Err(I2PError("invalid port 0".to_string()));
    }
    if host.is_empty() || host.contains([' ', '\r', '\n']) {
        return Err(I2PError(format!("invalid host {host:?}")));
    }
    // The darknet gate. Without it this function will happily encode
    // `CONNECT news.ycombinator.com:443`, and an I2P router hands that to its
    // outproxy — a clearnet DNS lookup and TCP connection from the operator's
    // own address, which is the exact deanonymisation the crate exists to make
    // unrepresentable.
    if !is_i2p_host(host) {
        return Err(I2PError(format!("not an .i2p eepsite: {host:?}")));
    }
    let hp = format!("{host}:{port}");
    Ok(format!(
        "CONNECT {hp} HTTP/1.1\r\nHost: {hp}\r\nUser-Agent: OnionCrawler-I2P/1.0\r\nProxy-Connection: close\r\n\r\n"
    )
    .into_bytes())
}

/// [`build_proxy_get_target`] behind the darknet gate: the eepsite is a
/// validated [`I2pHost`], so a clearnet host cannot be passed at all, and the
/// path is checked before it becomes part of a request line.
///
/// This is what a call site should use. The raw encoder below stays as it is
/// because it is the byte-pinned port of the Python reference
/// (`tests/xcheck_i2p.rs`), but it takes `&str` for both host and path and
/// validates neither.
///
/// # Errors
/// [`I2PError`] if `scheme` is not `http`/`https`, or `path` contains a space,
/// CR or LF.
pub fn build_proxy_get_target_checked(
    eepsite: &I2pHost,
    scheme: &str,
    port: Option<u16>,
    path: &str,
) -> Result<String, I2PError> {
    if scheme != "http" && scheme != "https" {
        return Err(I2PError(format!("unsupported scheme {scheme:?}")));
    }
    // The target goes into `GET {target} HTTP/1.1\r\n`. A path carrying CR/LF —
    // and a crawled `<a href>` is attacker-controlled — splices a second request
    // into the proxy connection (`/x HTTP/1.1\r\nGET http://evil.example/ …`),
    // which is request smuggling *through* the router, i.e. a clearnet fetch we
    // never asked for. `build_http_connect` rejects these in its host; the GET
    // path had no such check.
    if path.contains([' ', '\r', '\n']) {
        return Err(I2PError(format!("invalid path {path:?}")));
    }
    Ok(build_proxy_get_target(scheme, eepsite.as_str(), port, path))
}

/// The request-line target sent to the HTTP proxy for a plain-http eepsite: the
/// **absolute** URL (origin-form is only valid after a CONNECT tunnel). A default
/// port is omitted; an empty path becomes `/`.
///
/// Byte-pinned to the Python reference, so it validates nothing — prefer
/// [`build_proxy_get_target_checked`], which takes a gated [`I2pHost`].
#[must_use]
pub fn build_proxy_get_target(scheme: &str, host: &str, port: Option<u16>, path: &str) -> String {
    let default = if scheme == "https" { 443 } else { 80 };
    let hostport = match port {
        None => host.to_string(),
        Some(p) if p == default => host.to_string(),
        Some(p) => format!("{host}:{p}"),
    };
    let path = if path.is_empty() { "/" } else { path };
    format!("{scheme}://{hostport}{path}")
}

/// Read the proxy's `CONNECT` reply headers and return `Ok` iff it is a 2xx.
/// Consumes up to the terminating `CRLFCRLF`.
///
/// # Errors
/// [`I2PError`] if the proxy closes early, the reply exceeds `max_head`, or the
/// status line is malformed / non-2xx.
#[cfg(feature = "net")]
pub async fn read_connect_reply<S>(stream: &mut S, max_head: usize) -> Result<(), I2PError>
where
    S: tokio::io::AsyncRead + Unpin,
{
    use tokio::io::AsyncReadExt;
    let find = |b: &[u8], sep: &[u8]| {
        (0..=b.len().saturating_sub(sep.len())).find(|&i| &b[i..i + sep.len()] == sep)
    };

    let mut buf: Vec<u8> = Vec::new();
    let mut tmp = [0u8; 1024];
    while find(&buf, b"\r\n\r\n").is_none() {
        let n = stream
            .read(&mut tmp)
            .await
            .map_err(|e| I2PError(format!("i2p read: {e}")))?;
        if n == 0 {
            return Err(I2PError(
                "i2p proxy closed connection during CONNECT".to_string(),
            ));
        }
        buf.extend_from_slice(&tmp[..n]);
        if buf.len() > max_head {
            return Err(I2PError("i2p proxy CONNECT reply too large".to_string()));
        }
    }
    let line_end = find(&buf, b"\r\n").unwrap_or(buf.len());
    let line: String = buf[..line_end].iter().map(|&b| b as char).collect(); // latin-1
    let mut parts = line.splitn(3, ' ');
    let version = parts.next().unwrap_or("");
    let code = parts.next();
    if !version.to_uppercase().starts_with("HTTP/") || code.is_none() {
        return Err(I2PError(format!("bad i2p proxy CONNECT reply: {line:?}")));
    }
    let code: u16 = code
        .unwrap()
        .parse()
        .map_err(|_| I2PError(format!("bad i2p proxy status: {line:?}")))?;
    if !(200..300).contains(&code) {
        return Err(I2PError(format!("i2p proxy CONNECT failed: {line:?}")));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn connect_and_target() {
        assert!(build_http_connect("stats.i2p", 443).is_ok());
        assert!(build_http_connect("stats.i2p", 0).is_err());
        assert!(build_http_connect("bad host", 80).is_err());
        assert_eq!(
            build_proxy_get_target("http", "stats.i2p", None, "/path"),
            "http://stats.i2p/path"
        );
        assert_eq!(
            build_proxy_get_target("http", "stats.i2p", Some(80), "/"),
            "http://stats.i2p/"
        );
    }

    /// The I2P proxy encoders are the least-exercised path in the crate and the
    /// only one where the darknet gate was a `&str` rather than a type.
    #[test]
    fn the_proxy_encoders_refuse_clearnet() {
        // Each of these, encoded and sent to a router's HTTP proxy, is handed to
        // the outproxy: a clearnet DNS lookup and TCP connect from the operator.
        for host in [
            "example.com",
            "127.0.0.1",
            "localhost",
            "[::1]",
            "8.8.8.8",
            &format!("{}.onion", "a".repeat(56)), // right darknet, wrong network
            "notaneepsite",
            "evil.i2p.example.com", // the suffix is not where it looks
        ] {
            assert!(
                build_http_connect(host, 443).is_err(),
                "CONNECT gate let through {host}"
            );
            assert!(I2pHost::parse(host).is_err(), "I2pHost admitted {host}");
        }
        // a real eepsite still encodes, byte for byte as before
        let b32 = format!("{}.b32.i2p", "a".repeat(52));
        assert!(build_http_connect("stats.i2p", 443).is_ok());
        assert!(build_http_connect(&b32, 80).is_ok());
    }

    #[test]
    fn checked_target_gates_host_scheme_and_path() {
        let site = I2pHost::parse("stats.i2p").unwrap();
        assert_eq!(
            build_proxy_get_target_checked(&site, "http", None, "/path").unwrap(),
            "http://stats.i2p/path"
        );
        // a CRLF in the path would splice a second request line into the proxy
        // connection — the raw encoder happily embeds it
        assert!(build_proxy_get_target_checked(
            &site,
            "http",
            None,
            "/x HTTP/1.1\r\nGET http://evil.example/"
        )
        .is_err());
        assert!(build_proxy_get_target(
            "http",
            "stats.i2p",
            None,
            "/x HTTP/1.1\r\nGET http://evil.example/"
        )
        .contains("\r\n"));
        // and only http/https reach a proxy
        assert!(build_proxy_get_target_checked(&site, "file", None, "/etc/passwd").is_err());
        assert!(build_proxy_get_target_checked(&site, "https", Some(8443), "").is_ok());
    }
}
