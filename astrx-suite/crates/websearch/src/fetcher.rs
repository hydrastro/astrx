//! The clearnet net tier — where the SSRF gate becomes a *runtime-enforced,
//! type-pinned* connect.
//!
//! [`resolve_checked`] resolves a host (through a TTL DNS cache), vets **every**
//! resolved address with [`crate::httpclient::vet_addrs`] into `Vec<SafeIp>`, and
//! [`connect_pinned`] dials **only** a `SafeIp` — so the socket goes to a
//! validated address and DNS rebinding cannot swap in an internal IP between the
//! check and the connect. The SSRF re-check runs on the initial request **and
//! every redirect hop**.
//!
//! This is the async analogue of the Python `httpclient.fetch`. HTTPS is refused
//! (stdlib has no TLS; a TLS crate would break the zero-dep-by-default invariant
//! — a future opt-in `tls` feature), matching the other engines.

use crate::canonical::canonicalize;
use crate::httpclient::{
    authority_exempt, default_port, parse_content_type, perform_request, vet_addrs, FetchResult,
    HttpError, HttpResponse, DEFAULT_UA, REDIRECT_CODES,
};
use crate::ssrf::SafeIp;
use crawlcore::urlparse::{host_port, urlsplit};
use std::collections::{HashMap, HashSet};
use std::net::IpAddr;
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};
use tokio::net::TcpStream;

/// How long a resolved address set is cached.
const DNS_TTL: Duration = Duration::from_secs(300);
/// Hard bound on the DNS cache so a broad crawl cannot leak memory.
const DNS_CACHE_MAX: usize = 4096;

type DnsMap = HashMap<(String, u16), (Instant, Vec<IpAddr>)>;

fn dns_cache() -> &'static Mutex<DnsMap> {
    static CACHE: OnceLock<Mutex<DnsMap>> = OnceLock::new();
    CACHE.get_or_init(|| Mutex::new(HashMap::new()))
}

/// Drop all cached DNS entries (tests / long-running processes).
pub fn clear_dns_cache() {
    dns_cache().lock().expect("dns cache mutex").clear();
}

/// Resolve `host:port` with a TTL cache over the resolver.
///
/// IMPORTANT: only the *resolution* is cached; the internal-IP SSRF check still
/// runs on the cached addresses on every call (see [`resolve_checked`]), so
/// caching cannot smuggle an internal address past the gate — and pinning to a
/// cached, already-validated address is, if anything, stronger against DNS
/// rebinding within the TTL.
async fn getaddrinfo_cached(host: &str, port: u16) -> Result<Vec<IpAddr>, HttpError> {
    let key = (host.to_string(), port);
    let now = Instant::now();
    {
        let cache = dns_cache().lock().expect("dns cache mutex");
        if let Some((expiry, addrs)) = cache.get(&key) {
            if *expiry > now {
                return Ok(addrs.clone());
            }
        }
    }
    let addrs: Vec<IpAddr> = tokio::net::lookup_host((host, port))
        .await
        .map_err(|e| HttpError(format!("dns:{e}")))?
        .map(|sa| sa.ip())
        .collect();
    {
        let mut cache = dns_cache().lock().expect("dns cache mutex");
        // Bound the cache: purge expired entries first, then (if still full of
        // live ones) drop it wholesale. Eviction is always safe — the SSRF check
        // re-runs on every resolve, so a purged host is re-resolved and
        // re-validated, never trusted stale.
        if cache.len() >= DNS_CACHE_MAX && !cache.contains_key(&key) {
            cache.retain(|_, (expiry, _)| *expiry > now);
            if cache.len() >= DNS_CACHE_MAX {
                cache.clear();
            }
        }
        cache.insert(key, (now + DNS_TTL, addrs.clone()));
    }
    Ok(addrs)
}

/// Resolve `host:port` and vet the result into `Vec<SafeIp>` — refusing the whole
/// host if **any** resolved address is internal (unless the authority is
/// allow-listed). Returns the pinned, vetted addresses for a rebinding-safe
/// connect.
///
/// # Errors
/// [`HttpError`] carrying `blocked-internal:<ip>` if a resolved address is
/// internal and not exempt, or `dns:<err>` on resolution failure.
pub async fn resolve_checked(
    host: &str,
    port: u16,
    block_internal: bool,
    allow_hosts: &[String],
) -> Result<Vec<SafeIp>, HttpError> {
    let addrs = getaddrinfo_cached(host, port).await?;
    if addrs.is_empty() {
        return Err(HttpError("dns: no addresses".to_string()));
    }
    let exempt = authority_exempt(host, port, allow_hosts);
    vet_addrs(&addrs, block_internal, exempt).map_err(|e| HttpError(e.to_string()))
}

/// Dial the first reachable **vetted** address, pinned. Taking `&[SafeIp]` (not
/// `&[IpAddr]`) is the compile-time SSRF gate: only addresses that cleared
/// [`vet_addrs`] can be connected.
async fn connect_pinned(
    addrs: &[SafeIp],
    port: u16,
    timeout: Duration,
) -> Result<TcpStream, HttpError> {
    let mut last: Option<String> = None;
    for safe in addrs {
        match tokio::time::timeout(timeout, TcpStream::connect((safe.addr(), port))).await {
            Ok(Ok(stream)) => return Ok(stream),
            Ok(Err(e)) => last = Some(format!("connect:{e}")),
            Err(_) => last = Some("connect: timed out".to_string()),
        }
    }
    Err(HttpError(
        last.unwrap_or_else(|| "connect: no address".to_string()),
    ))
}

/// Options for a [`fetch`].
#[derive(Debug, Clone)]
pub struct FetchOpts {
    /// The `User-Agent` header.
    pub user_agent: String,
    /// Per-connection timeout.
    pub timeout: Duration,
    /// Response body byte cap.
    pub max_bytes: usize,
    /// Maximum redirect hops.
    pub max_redirects: u32,
    /// The `Accept-Encoding` header (decompression is handled locally).
    pub accept_encoding: String,
    /// Refuse hosts that resolve to internal addresses (the SSRF guard).
    pub block_internal: bool,
    /// Authorities (`host` / `host:port`) exempt from the internal-address block.
    pub allow_hosts: Vec<String>,
}

impl Default for FetchOpts {
    fn default() -> Self {
        FetchOpts {
            user_agent: DEFAULT_UA.to_string(),
            timeout: Duration::from_secs(10),
            max_bytes: 2_000_000,
            max_redirects: 5,
            accept_encoding: "gzip, deflate".to_string(),
            block_internal: true,
            allow_hosts: Vec::new(),
        }
    }
}

fn result_from(url: &str, current: &str, resp: HttpResponse, redirects: u32) -> FetchResult {
    let (content_type, charset) = parse_content_type(resp.header("content-type").unwrap_or(""));
    FetchResult {
        url: url.to_string(),
        final_url: current.to_string(),
        status: resp.status,
        headers: resp.headers,
        body: resp.body,
        content_type,
        charset,
        error: None,
        truncated: resp.truncated,
        redirects,
    }
}

/// Fetch `url`, following up to `opts.max_redirects` redirects.
///
/// `allow(url)` (if given) is consulted for the initial URL and every redirect
/// target; a target that fails stops the chain with a `blocked` result. The SSRF
/// gate (resolve → [`vet_addrs`] → pinned connect) runs on the initial request
/// **and every hop**, so a redirect to an internal address is refused just like
/// the initial URL. HTTPS is refused without the `tls` feature.
pub async fn fetch(
    url: &str,
    opts: &FetchOpts,
    allow: Option<&(dyn Fn(&str) -> bool + Sync)>,
) -> FetchResult {
    let mut current = url.to_string();
    let mut seen: HashSet<String> = HashSet::new();
    let mut redirects = 0u32;

    loop {
        if let Some(pred) = allow {
            if !pred(&current) {
                return FetchResult::failed(url, &current, "blocked".to_string(), redirects);
            }
        }

        let s = urlsplit(&current, "");
        let scheme = s.scheme.to_lowercase();
        if scheme != "http" && scheme != "https" {
            return FetchResult::failed(url, &current, "unsupported-scheme".to_string(), redirects);
        }
        if scheme == "https" {
            return FetchResult::failed(
                url,
                &current,
                "https requires the tls feature".to_string(),
                redirects,
            );
        }
        let (host, port_str) = host_port(&s.netloc);
        if host.is_empty() {
            return FetchResult::failed(url, &current, "no-host".to_string(), redirects);
        }
        let port = match port_str {
            Some(p) => match p.parse::<u16>() {
                Ok(p) => p,
                Err(_) => {
                    return FetchResult::failed(url, &current, "bad-port".to_string(), redirects)
                }
            },
            None => default_port(&scheme),
        };

        // The SSRF gate — every hop resolves, vets, and pins afresh.
        let addrs = match resolve_checked(&host, port, opts.block_internal, &opts.allow_hosts).await
        {
            Ok(a) => a,
            Err(e) => return FetchResult::failed(url, &current, e.to_string(), redirects),
        };
        let mut stream = match connect_pinned(&addrs, port, opts.timeout).await {
            Ok(s) => s,
            Err(e) => return FetchResult::failed(url, &current, e.to_string(), redirects),
        };

        let host_header = if port == default_port(&scheme) {
            host.clone()
        } else {
            format!("{host}:{port}")
        };
        let mut path = if s.path.is_empty() {
            "/".to_string()
        } else {
            s.path.clone()
        };
        if !s.query.is_empty() {
            path.push('?');
            path.push_str(&s.query);
        }
        let headers = [
            ("User-Agent".to_string(), opts.user_agent.clone()),
            (
                "Accept".to_string(),
                "text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1".to_string(),
            ),
            ("Accept-Encoding".to_string(), opts.accept_encoding.clone()),
            ("Connection".to_string(), "close".to_string()),
        ];

        let resp = match perform_request(
            &mut stream,
            "GET",
            &host_header,
            &path,
            &headers,
            opts.max_bytes,
        )
        .await
        {
            Ok(r) => r,
            Err(e) => return FetchResult::failed(url, &current, format!("http:{e}"), redirects),
        };

        // Follow one redirect hop; a broken or looping redirect returns as-is.
        if REDIRECT_CODES.contains(&resp.status) && redirects < opts.max_redirects {
            let target = resp
                .header("location")
                .and_then(|loc| canonicalize(loc, Some(&current)));
            match target {
                Some(t) if !seen.contains(&t) => {
                    seen.insert(t.clone());
                    current = t;
                    redirects += 1;
                    continue;
                }
                _ => return result_from(url, &current, resp, redirects),
            }
        }
        return result_from(url, &current, resp, redirects);
    }
}
