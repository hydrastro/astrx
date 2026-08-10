//! Hand-rolled SOCKS5 (RFC 1928) CONNECT with optional username/password auth
//! (RFC 1929).
//!
//! The *encoding* is factored into pure functions ([`build_greeting`],
//! [`build_userpass_auth`], [`build_connect_request`]) so the exact RFC-1928 byte
//! layout is unit-testable without a socket — cross-checked in
//! `tests/xcheck_socks.rs`. Hostnames are sent as `ATYP=0x03` (DOMAINNAME) so the
//! SOCKS proxy (Tor) does the DNS resolution: we NEVER resolve a `.onion` locally
//! — that is both the only way `.onion` works and a hard anti-leak requirement.
//! A distinct username/password selects a distinct Tor circuit (stream
//! isolation), e.g. one circuit per host.
//!
//! The async [`socks5_connect`] (behind the `net` feature) performs the handshake
//! over a tokio TCP stream and returns the connected tunnel.

use std::fmt;

/// SOCKS protocol version (5).
pub const VER: u8 = 0x05;
const RSV: u8 = 0x00;

/// Auth method: no authentication required.
pub const M_NOAUTH: u8 = 0x00;
/// Auth method: username/password (RFC 1929).
pub const M_USERPASS: u8 = 0x02;
/// Auth method sentinel: no acceptable method.
pub const M_NONE_ACCEPTABLE: u8 = 0xFF;

const CMD_CONNECT: u8 = 0x01;
const ATYP_DOMAIN: u8 = 0x03;
// Only referenced when parsing a reply's BND.ADDR in the async connect path.
#[cfg(feature = "net")]
const ATYP_IPV4: u8 = 0x01;
#[cfg(feature = "net")]
const ATYP_IPV6: u8 = 0x04;

/// A SOCKS negotiation error.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SocksError(pub String);

impl fmt::Display for SocksError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for SocksError {}

/// RFC-1928 §6 reply-code text.
#[must_use]
pub fn reply_text(code: u8) -> &'static str {
    match code {
        0x00 => "succeeded",
        0x01 => "general SOCKS server failure",
        0x02 => "connection not allowed by ruleset",
        0x03 => "network unreachable",
        0x04 => "host unreachable",
        0x05 => "connection refused",
        0x06 => "TTL expired",
        0x07 => "command not supported",
        0x08 => "address type not supported",
        _ => "unknown reply code",
    }
}

// --- pure encoders ----------------------------------------------------------

/// Client greeting: `VER, NMETHODS, METHODS…`. Offers username/password (then
/// no-auth) when `use_userpass`, else no-auth only.
#[must_use]
pub fn build_greeting(use_userpass: bool) -> Vec<u8> {
    let methods: &[u8] = if use_userpass {
        &[M_USERPASS, M_NOAUTH]
    } else {
        &[M_NOAUTH]
    };
    let mut out = Vec::with_capacity(2 + methods.len());
    out.push(VER);
    out.push(methods.len() as u8);
    out.extend_from_slice(methods);
    out
}

/// RFC-1929 sub-negotiation: `VER(1), ULEN, UNAME, PLEN, PASSWD`.
///
/// # Errors
/// [`SocksError`] if the username or password exceeds 255 bytes.
pub fn build_userpass_auth(username: &str, password: &str) -> Result<Vec<u8>, SocksError> {
    let u = username.as_bytes();
    let p = password.as_bytes();
    if u.len() > 255 || p.len() > 255 {
        return Err(SocksError(
            "socks username/password too long (max 255 bytes)".to_string(),
        ));
    }
    let mut out = Vec::with_capacity(3 + u.len() + p.len());
    out.push(0x01);
    out.push(u.len() as u8);
    out.extend_from_slice(u);
    out.push(p.len() as u8);
    out.extend_from_slice(p);
    Ok(out)
}

/// CONNECT request with a DOMAINNAME target (remote resolution):
/// `VER, CMD, RSV, ATYP=0x03, LEN, HOST…, PORT(2, big-endian)`.
///
/// # Errors
/// [`SocksError`] if `host` is non-ASCII (darknet hosts are always ASCII; the
/// Python IDNA path is unreachable here and intentionally unimplemented), longer
/// than 255 bytes, or `port` is 0.
pub fn build_connect_request(host: &str, port: u16) -> Result<Vec<u8>, SocksError> {
    if !host.is_ascii() {
        return Err(SocksError(format!(
            "non-ASCII socks host (IDNA unsupported): {host:?}"
        )));
    }
    let hb = host.as_bytes();
    if hb.len() > 255 {
        return Err(SocksError(
            "socks hostname too long (max 255 bytes)".to_string(),
        ));
    }
    if port == 0 {
        return Err(SocksError("invalid port 0".to_string()));
    }
    let mut out = Vec::with_capacity(7 + hb.len());
    out.extend_from_slice(&[VER, CMD_CONNECT, RSV, ATYP_DOMAIN, hb.len() as u8]);
    out.extend_from_slice(hb);
    out.extend_from_slice(&port.to_be_bytes());
    Ok(out)
}

// --- async connect (net tier) ----------------------------------------------

#[cfg(feature = "net")]
mod connect {
    use super::{
        build_connect_request, build_greeting, build_userpass_auth, reply_text, SocksError,
        ATYP_DOMAIN, ATYP_IPV4, ATYP_IPV6, M_NOAUTH, M_NONE_ACCEPTABLE, M_USERPASS, VER,
    };
    use std::time::Duration;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpStream;

    fn io_err(e: impl std::fmt::Display) -> SocksError {
        SocksError(format!("socks io: {e}"))
    }

    async fn read_exact(stream: &mut TcpStream, n: usize) -> Result<Vec<u8>, SocksError> {
        let mut buf = vec![0u8; n];
        stream
            .read_exact(&mut buf)
            .await
            .map_err(|_| SocksError("socks proxy closed connection early".to_string()))?;
        Ok(buf)
    }

    /// Consume the BND.ADDR/BND.PORT tail of a reply (value unused).
    async fn read_bind_address(stream: &mut TcpStream) -> Result<(), SocksError> {
        let atyp = read_exact(stream, 1).await?[0];
        match atyp {
            ATYP_IPV4 => {
                read_exact(stream, 4).await?;
            }
            ATYP_IPV6 => {
                read_exact(stream, 16).await?;
            }
            ATYP_DOMAIN => {
                let ln = read_exact(stream, 1).await?[0] as usize;
                read_exact(stream, ln).await?;
            }
            other => return Err(SocksError(format!("unknown ATYP in reply: {other}"))),
        }
        read_exact(stream, 2).await?; // port
        Ok(())
    }

    /// Open a TCP connection to the proxy and perform a SOCKS5 CONNECT to
    /// (`dest_host`, `dest_port`) with **remote** name resolution. Returns the
    /// connected stream (now a transparent tunnel) or a [`SocksError`].
    ///
    /// The whole handshake is bounded by `timeout`.
    ///
    /// # Errors
    /// Any I/O failure, an unacceptable/failed auth method, or a non-success
    /// SOCKS reply code.
    pub async fn socks5_connect(
        proxy_host: &str,
        proxy_port: u16,
        dest_host: &str,
        dest_port: u16,
        auth: Option<(&str, &str)>,
        timeout: Duration,
    ) -> Result<TcpStream, SocksError> {
        let use_userpass = matches!(auth, Some((u, _)) if !u.is_empty());
        tokio::time::timeout(timeout, async {
            let mut stream = TcpStream::connect((proxy_host, proxy_port))
                .await
                .map_err(io_err)?;

            // 1) greeting
            stream
                .write_all(&build_greeting(use_userpass))
                .await
                .map_err(io_err)?;
            let resp = read_exact(&mut stream, 2).await?;
            if resp[0] != VER {
                return Err(SocksError(format!(
                    "bad SOCKS version in method reply: {}",
                    resp[0]
                )));
            }
            let method = resp[1];
            if method == M_NONE_ACCEPTABLE {
                return Err(SocksError("no acceptable SOCKS auth method".to_string()));
            }

            // 2) auth
            if method == M_USERPASS {
                let (u, p) = auth.filter(|(u, _)| !u.is_empty()).ok_or_else(|| {
                    SocksError("proxy demands user/pass but none provided".to_string())
                })?;
                stream
                    .write_all(&build_userpass_auth(u, p)?)
                    .await
                    .map_err(io_err)?;
                let ar = read_exact(&mut stream, 2).await?;
                if ar[1] != 0x00 {
                    return Err(SocksError(
                        "SOCKS username/password auth failed".to_string(),
                    ));
                }
            } else if method != M_NOAUTH {
                return Err(SocksError(format!(
                    "unsupported SOCKS method selected: {method}"
                )));
            }

            // 3) connect
            stream
                .write_all(&build_connect_request(dest_host, dest_port)?)
                .await
                .map_err(io_err)?;
            let rep = read_exact(&mut stream, 3).await?; // VER, REP, RSV
            if rep[0] != VER {
                return Err(SocksError(format!(
                    "bad SOCKS version in connect reply: {}",
                    rep[0]
                )));
            }
            if rep[1] != 0x00 {
                return Err(SocksError(format!(
                    "SOCKS connect failed: {}",
                    reply_text(rep[1])
                )));
            }
            read_bind_address(&mut stream).await?;
            Ok(stream)
        })
        .await
        .map_err(|_| SocksError("socks handshake timed out".to_string()))?
    }
}

#[cfg(feature = "net")]
pub use connect::socks5_connect;

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn greeting_and_auth_bytes() {
        assert_eq!(build_greeting(false), vec![0x05, 0x01, 0x00]);
        assert_eq!(build_greeting(true), vec![0x05, 0x02, 0x02, 0x00]);
        assert_eq!(
            build_userpass_auth("user", "pass").unwrap(),
            b"\x01\x04user\x04pass"
        );
        assert!(build_userpass_auth(&"x".repeat(256), "p").is_err());
    }

    #[test]
    fn connect_request_bytes_and_errors() {
        let r = build_connect_request("abc.onion", 8080).unwrap();
        assert_eq!(&r[..5], &[0x05, 0x01, 0x00, 0x03, 9]);
        assert_eq!(&r[5..14], b"abc.onion");
        assert_eq!(&r[14..], &[0x1f, 0x90]); // 8080 big-endian
        assert!(build_connect_request("abc.onion", 0).is_err());
        assert!(build_connect_request(&format!("{}.onion", "a".repeat(250)), 80).is_err());
        assert!(build_connect_request("naïve.onion", 80).is_err()); // non-ASCII
    }
}
