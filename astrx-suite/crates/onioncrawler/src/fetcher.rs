//! The darknet fetcher — the orchestration where the anti-leak gate reaches a
//! socket, and where it becomes a **compile-time** guarantee.
//!
//! [`Fetcher::open`] takes an `&OnionHost` (not a `&str`), so the only way to
//! reach a socket-opening call is to first pass a host through the validating
//! [`OnionHost::parse`] gate. Handing it a clearnet / localhost / IP-literal host
//! is therefore unrepresentable — the leak the whole crawler is built to prevent
//! cannot be written.
//!
//! Two transports:
//! * [`Transport::TorSocks`] — real deployment: SOCKS5 → local Tor → `.onion`
//!   with **remote** DNS (a `.onion` is never resolved on this host) and optional
//!   per-host stream isolation (a distinct SOCKS username → a distinct circuit).
//! * [`Transport::Direct`] — **testing only**: plain HTTP to a loopback fixture,
//!   mapping synthetic `.onion` hosts to `127.0.0.1:<port>`. It still runs the
//!   full `OnionHost` gate + canonicalization + redirect loop, so the pipeline is
//!   exercised for real while the transport is swapped. Never anonymous.
//!
//! Onion services carry their own end-to-end encryption over the Tor circuit, so
//! HTTP is the norm; `https` is refused here (stdlib has no TLS, and a TLS crate
//! would break the zero-dep-by-default invariant — a future opt-in `tls` feature).
//!
//! This is the async analogue of the Python `fetcher.py` `BaseFetcher`.

use crate::canonical::canonicalize;
use crate::http::{self, HttpResponse};
use crate::onion::OnionHost;
use crate::socks::socks5_connect;
use crawlcore::urlparse::urljoin;
use std::collections::HashMap;
use std::time::Duration;
use tokio::net::TcpStream;

/// The crawler's User-Agent (identifies the research crawler + abuse contact).
pub const USER_AGENT: &str =
    "OnionCrawler/1.0 (+research crawler; abuse-filtered; contact=operator)";

/// The outcome of a fetch (after following redirects).
#[derive(Debug, Clone)]
pub struct FetchResult {
    /// The originally-requested URL.
    pub url: String,
    /// The final URL after redirects (canonicalized).
    pub final_url: String,
    /// HTTP status (0 if the request never completed).
    pub status: u16,
    /// Response headers of the final hop.
    pub headers: http::Headers,
    /// Response body (bounded, possibly truncated).
    pub body: Vec<u8>,
    /// The bare content-type (media type, no parameters), lowercased.
    pub content_type: String,
    /// True iff a 2xx response completed.
    pub ok: bool,
    /// A human-readable error, if the fetch failed.
    pub error: Option<String>,
    /// Whether the body was truncated at the byte cap.
    pub truncated: bool,
}

impl FetchResult {
    fn failed(url: &str, current: &str, error: String) -> Self {
        FetchResult {
            url: url.to_string(),
            final_url: current.to_string(),
            status: 0,
            headers: http::Headers::default(),
            body: Vec::new(),
            content_type: String::new(),
            ok: false,
            error: Some(error),
            truncated: false,
        }
    }
}

/// How the fetcher reaches the network.
pub enum Transport {
    /// TESTING ONLY: synthetic `.onion` host → loopback `(ip, port)`, plain HTTP.
    Direct(HashMap<String, (String, u16)>),
    /// Tor SOCKS5 proxy (remote DNS + optional per-host stream isolation).
    TorSocks {
        /// Proxy host (e.g. `127.0.0.1`).
        proxy_host: String,
        /// Proxy port (e.g. `9050`).
        proxy_port: u16,
        /// Isolate streams per host (a distinct SOCKS username → a distinct circuit).
        stream_isolation: bool,
        /// The shared secret used as the per-host SOCKS password.
        isolation_secret: String,
    },
}

/// A darknet fetcher: a transport plus fetch limits.
pub struct Fetcher {
    transport: Transport,
    /// Body byte cap per fetch.
    pub max_bytes: usize,
    /// Maximum redirect hops.
    pub max_redirects: usize,
    /// Per-connection timeout.
    pub timeout: Duration,
    /// Whether to admit deprecated v2 onions.
    pub allow_v2: bool,
}

impl Fetcher {
    /// A testing fetcher mapping synthetic `.onion` hosts to loopback addresses.
    #[must_use]
    pub fn direct(hostmap: HashMap<String, (String, u16)>) -> Self {
        Fetcher {
            transport: Transport::Direct(hostmap),
            max_bytes: 2_000_000,
            max_redirects: 5,
            timeout: Duration::from_secs(60),
            allow_v2: false,
        }
    }

    /// A real Tor SOCKS5 fetcher (per-host stream isolation on by default).
    #[must_use]
    pub fn tor(proxy_host: &str, proxy_port: u16) -> Self {
        Fetcher {
            transport: Transport::TorSocks {
                proxy_host: proxy_host.to_string(),
                proxy_port,
                stream_isolation: true,
                isolation_secret: "onioncrawler".to_string(),
            },
            max_bytes: 2_000_000,
            max_redirects: 5,
            timeout: Duration::from_secs(60),
            allow_v2: false,
        }
    }

    fn default_port(scheme: &str) -> u16 {
        if scheme == "https" {
            443
        } else {
            80
        }
    }

    /// Open a socket to a **validated** onion host. Taking an `&OnionHost` (not a
    /// `&str`) is the compile-time anti-leak gate: a clearnet host cannot be
    /// passed here at all.
    async fn open(&self, onion: &OnionHost, port: u16, scheme: &str) -> Result<TcpStream, String> {
        if scheme == "https" {
            return Err("https is not supported without the tls feature".to_string());
        }
        match &self.transport {
            Transport::Direct(map) => {
                let (ip, p) = map
                    .get(onion.as_str())
                    .ok_or_else(|| format!("no direct mapping for {}", onion.as_str()))?;
                tokio::time::timeout(self.timeout, TcpStream::connect((ip.as_str(), *p)))
                    .await
                    .map_err(|_| "connect timed out".to_string())?
                    .map_err(|e| format!("connect: {e}"))
            }
            Transport::TorSocks {
                proxy_host,
                proxy_port,
                stream_isolation,
                isolation_secret,
            } => {
                let auth = stream_isolation
                    .then(|| (format!("host-{}", onion.as_str()), isolation_secret.clone()));
                let auth_ref = auth.as_ref().map(|(u, p)| (u.as_str(), p.as_str()));
                socks5_connect(
                    proxy_host,
                    *proxy_port,
                    onion.as_str(),
                    port,
                    auth_ref,
                    self.timeout,
                )
                .await
                .map_err(|e| e.to_string())
            }
        }
    }

    /// Fetch *url*, following redirects one hop at a time. Every hop is
    /// canonicalized and gated through [`OnionHost::parse`] before a socket is
    /// opened; a non-onion URL is refused as a clean non-ok result.
    pub async fn fetch(&self, url: &str) -> FetchResult {
        let mut current = url.to_string();
        for hop in 0..=self.max_redirects {
            let Some(cu) = canonicalize(&current, None, self.allow_v2, false) else {
                return FetchResult::failed(
                    url,
                    &current,
                    "not a fetchable .onion URL".to_string(),
                );
            };
            // The anti-leak gate: only a validated .onion survives.
            let onion = match OnionHost::parse(&cu.host, self.allow_v2) {
                Ok(o) => o,
                Err(e) => {
                    return FetchResult::failed(url, &current, format!("non-onion refused: {e}"))
                }
            };
            let port = cu.port.unwrap_or_else(|| Self::default_port(&cu.scheme));
            let path = if cu.query.is_empty() {
                cu.path.clone()
            } else {
                format!("{}?{}", cu.path, cu.query)
            };
            let host_header = if port == Self::default_port(&cu.scheme) {
                cu.host.clone()
            } else {
                format!("{}:{}", cu.host, port)
            };
            let headers = [
                ("User-Agent".to_string(), USER_AGENT.to_string()),
                (
                    "Accept".to_string(),
                    "text/html,text/plain;q=0.9,*/*;q=0.1".to_string(),
                ),
                ("Accept-Encoding".to_string(), "gzip, deflate".to_string()),
                ("Connection".to_string(), "close".to_string()),
            ];

            let mut stream = match self.open(&onion, port, &cu.scheme).await {
                Ok(s) => s,
                Err(e) => return FetchResult::failed(url, &current, e),
            };
            let resp: HttpResponse = match http::perform_request(
                &mut stream,
                "GET",
                &host_header,
                &path,
                &headers,
                self.max_bytes,
            )
            .await
            {
                Ok(r) => r,
                Err(e) => return FetchResult::failed(url, &current, format!("http: {e}")),
            };

            // Follow one redirect hop.
            if (300..400).contains(&resp.status) && hop < self.max_redirects {
                if let Some(loc) = resp.header("location") {
                    current = urljoin(&cu.url, loc);
                    continue;
                }
            }

            let content_type = resp
                .header("content-type")
                .unwrap_or("")
                .split(';')
                .next()
                .unwrap_or("")
                .trim()
                .to_lowercase();
            return FetchResult {
                url: url.to_string(),
                final_url: cu.url.clone(),
                status: resp.status,
                headers: resp.headers,
                body: resp.body,
                content_type,
                ok: (200..300).contains(&resp.status),
                error: None,
                truncated: resp.truncated,
            };
        }
        FetchResult::failed(url, &current, "too many redirects".to_string())
    }
}
