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
    /// Extra request headers sent on the **initial** request only (e.g.
    /// `If-None-Match` / `If-Modified-Since` for a conditional GET). Empty values
    /// are skipped.
    pub extra_headers: Vec<(String, String)>,
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
            extra_headers: Vec::new(),
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
        let mut headers = vec![
            ("User-Agent".to_string(), opts.user_agent.clone()),
            (
                "Accept".to_string(),
                "text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1".to_string(),
            ),
            ("Accept-Encoding".to_string(), opts.accept_encoding.clone()),
            ("Connection".to_string(), "close".to_string()),
        ];
        // Conditional-GET / extra headers on the INITIAL request only (empty
        // values skipped), matching the Python `extra_headers if redirects == 0`.
        if redirects == 0 {
            for (k, v) in &opts.extra_headers {
                if !v.is_empty() {
                    headers.push((k.clone(), v.clone()));
                }
            }
        }

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

/// A keep-alive-capable fetcher with the **same** SSRF guarantees as [`fetch`].
///
/// Maintains a per-instance pool of idle keep-alive connections keyed by
/// `(scheme, host, port)`. A pooled connection is reused **only after its pinned
/// address re-clears the [`vet_addrs`] gate** — the crown SSRF invariant, re-run
/// on every hop even for a reused socket — and every fresh connect (and every
/// redirect hop) still goes through [`resolve_checked`] + a pinned connect. So a
/// pooled connection can never bypass the `&SafeIp` gate: it is dialed to a vetted
/// address and, before each reuse, that pinned address must still clear the
/// internal-IP check under the *current* policy.
///
/// Not shared across tasks — give each crawl worker its own `Fetcher` (mirrors the
/// Python `httpclient.Fetcher`). HTTPS is refused (no stdlib TLS), like [`fetch`].
pub struct Fetcher {
    keep_alive: bool,
    pool: HashMap<(String, String, u16), (TcpStream, IpAddr)>,
    opened: u64,
    reused: u64,
}

impl Fetcher {
    /// A fetcher whose connections are pooled + reused when `keep_alive` is set.
    #[must_use]
    pub fn new(keep_alive: bool) -> Self {
        Fetcher {
            keep_alive,
            pool: HashMap::new(),
            opened: 0,
            reused: 0,
        }
    }

    /// New sockets opened over this fetcher's life (observability / tests).
    #[must_use]
    pub fn opened(&self) -> u64 {
        self.opened
    }

    /// Pooled connections reused over this fetcher's life (observability / tests).
    #[must_use]
    pub fn reused(&self) -> u64 {
        self.reused
    }

    /// Drop every pooled connection (closing the sockets). Mirrors Python `close`.
    pub fn close(&mut self) {
        self.pool.clear();
    }

    /// Obtain a connection for `(scheme, host, port)`: reuse a pooled keep-alive
    /// socket **only** after its pinned address re-clears [`vet_addrs`] under the
    /// current policy, else open a fresh [`resolve_checked`]-vetted, pinned socket.
    /// Returns `(stream, pinned_addr, reused)`.
    async fn acquire(
        &mut self,
        scheme: &str,
        host: &str,
        port: u16,
        opts: &FetchOpts,
    ) -> Result<(TcpStream, IpAddr, bool), HttpError> {
        let key = (scheme.to_string(), host.to_string(), port);
        if let Some((stream, pinned)) = self.pool.remove(&key) {
            let exempt = authority_exempt(host, port, &opts.allow_hosts);
            // Re-run the &SafeIp gate on the pinned address before reuse: a pooled
            // socket must never skip the internal-IP check (SSRF on every hop).
            if vet_addrs(&[pinned], opts.block_internal, exempt).is_ok() {
                self.reused += 1;
                return Ok((stream, pinned, true));
            }
            // Policy changed under us (the pin is now internal + not exempt): drop
            // the pooled socket and fall through to a fresh, re-validated connect.
            drop(stream);
        }
        let addrs = resolve_checked(host, port, opts.block_internal, &opts.allow_hosts).await?;
        let mut last: Option<String> = None;
        for safe in &addrs {
            match tokio::time::timeout(opts.timeout, TcpStream::connect((safe.addr(), port))).await
            {
                Ok(Ok(stream)) => {
                    self.opened += 1;
                    return Ok((stream, safe.addr(), false));
                }
                Ok(Err(e)) => last = Some(format!("connect:{e}")),
                Err(_) => last = Some("connect: timed out".to_string()),
            }
        }
        Err(HttpError(
            last.unwrap_or_else(|| "connect: no address".to_string()),
        ))
    }

    /// One request/response on `current` (a single hop) over a pooled or fresh
    /// connection. A *reused* socket that turns out stale (closed by the peer while
    /// idle) is retried **once** on a fresh connection — the retry re-runs
    /// [`resolve_checked`] via [`acquire`](Self::acquire), so the SSRF gate holds
    /// on the second try too. A *fresh* connection that fails is a real error and
    /// is never retried. Returns the parsed response or a human error string
    /// matching [`fetch`]'s error payloads.
    async fn fetch_once(
        &mut self,
        current: &str,
        opts: &FetchOpts,
        send_extra: bool,
    ) -> Result<HttpResponse, String> {
        let s = urlsplit(current, "");
        let scheme = s.scheme.to_lowercase();
        if scheme != "http" && scheme != "https" {
            return Err("unsupported-scheme".to_string());
        }
        if scheme == "https" {
            return Err("https requires the tls feature".to_string());
        }
        let (host, port_str) = host_port(&s.netloc);
        if host.is_empty() {
            return Err("no-host".to_string());
        }
        let port = match port_str {
            Some(p) => p.parse::<u16>().map_err(|_| "bad-port".to_string())?,
            None => default_port(&scheme),
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
        let mut headers = vec![
            ("User-Agent".to_string(), opts.user_agent.clone()),
            (
                "Accept".to_string(),
                "text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1".to_string(),
            ),
            ("Accept-Encoding".to_string(), opts.accept_encoding.clone()),
            (
                "Connection".to_string(),
                if self.keep_alive {
                    "keep-alive"
                } else {
                    "close"
                }
                .to_string(),
            ),
        ];
        // Conditional-GET / extra headers on the INITIAL request only (empty values
        // skipped), matching the free `fetch` and the Python `_one`.
        if send_extra {
            for (k, v) in &opts.extra_headers {
                if !v.is_empty() {
                    headers.push((k.clone(), v.clone()));
                }
            }
        }

        let mut last_err = String::new();
        // At most two attempts (see [`fetch_once`](Self::fetch_once) doc): the pool
        // is popped on attempt 0, so the retry always takes the fresh path.
        for attempt in 0..2 {
            let (mut stream, pinned, reused) = match self.acquire(&scheme, &host, port, opts).await
            {
                Ok(t) => t,
                // Gate / DNS / connect failures are real errors (no "http:" prefix),
                // exactly like the free `fetch`; never retried.
                Err(e) => return Err(e.to_string()),
            };
            match perform_request(
                &mut stream,
                "GET",
                &host_header,
                &path,
                &headers,
                opts.max_bytes,
            )
            .await
            {
                Ok(resp) => {
                    // Pool the socket only if we asked to keep alive and the framed
                    // body was fully drained with no peer "close" (perform_request's
                    // `reusable`), so no unread bytes remain on the wire.
                    if self.keep_alive && resp.reusable {
                        self.pool
                            .insert((scheme.clone(), host.clone(), port), (stream, pinned));
                    }
                    return Ok(resp);
                }
                Err(e) => {
                    // `stream` is dropped here (closed). Only a stale *reused*
                    // connection is worth one retry on a fresh, re-resolved socket.
                    if reused && attempt == 0 {
                        last_err = format!("http:{e}");
                        continue;
                    }
                    return Err(format!("http:{e}"));
                }
            }
        }
        Err(last_err)
    }

    /// Fetch `url` through the pool, following up to `opts.max_redirects`
    /// redirects. Same redirect / `allow` / SSRF-on-every-hop semantics as the free
    /// [`fetch`], but connections are reused across calls and across hops. `allow`
    /// is consulted for the initial URL and every redirect target.
    pub async fn fetch(
        &mut self,
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
            // Box the per-hop future: `perform_request` carries sizable read
            // buffers, and this fetcher sits several async layers below the crawl
            // loop — keeping that future on the heap keeps the caller's stack flat.
            let resp = match Box::pin(self.fetch_once(&current, opts, redirects == 0)).await {
                Ok(r) => r,
                Err(e) => return FetchResult::failed(url, &current, e, redirects),
            };
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
}
