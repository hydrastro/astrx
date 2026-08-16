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

/// Idle keep-alive sockets one [`Fetcher`] holds at once.
///
/// The pool is keyed by `(scheme, host, port)`, so an unbounded pool grows with
/// the number of AUTHORITIES a crawl touches, not with its concurrency: 300
/// distinct authorities took the process from 310 to 910 file descriptors and
/// held them, and a broad `--keep-alive` crawl exhausts a 1024-descriptor limit
/// after roughly a thousand hosts. A crawl worker fetches one host at a time and
/// revisits recent hosts, so a small pool captures nearly all the reuse.
const POOL_MAX_IDLE: usize = 32;
/// How long an idle pooled socket may be kept before it is closed.
///
/// Servers close idle keep-alives after 5–75 s; past that the entry is very
/// likely dead anyway, and holding it costs a descriptor for nothing.
const POOL_IDLE_TIMEOUT: Duration = Duration::from_secs(60);

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
/// `(scheme, host, port)`, bounded at [`POOL_MAX_IDLE`] entries (least recently
/// used evicted first) and swept of anything idle past [`POOL_IDLE_TIMEOUT`].
/// A pooled connection is reused **only after its pinned
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
    pool: ConnPool<TcpStream>,
    opened: u64,
    reused: u64,
}

/// The idle-connection pool: at most [`POOL_MAX_IDLE`] entries, least-recently
/// used evicted first, and each entry closed once it has been idle for
/// [`POOL_IDLE_TIMEOUT`].
///
/// Generic over the socket type only so the eviction policy can be unit-tested
/// without opening 300 real sockets; the crawler only ever uses `TcpStream`.
struct ConnPool<S> {
    entries: HashMap<(String, String, u16), PoolEntry<S>>,
    /// Monotonic use stamp — the smallest one is the least recently used.
    clock: u64,
    max_idle: usize,
    idle_timeout: Duration,
    evicted: u64,
    expired: u64,
}

struct PoolEntry<S> {
    stream: S,
    pinned: IpAddr,
    used: u64,
    idle_since: Instant,
}

impl<S> ConnPool<S> {
    fn new(max_idle: usize, idle_timeout: Duration) -> Self {
        ConnPool {
            entries: HashMap::new(),
            clock: 0,
            max_idle,
            idle_timeout,
            evicted: 0,
            expired: 0,
        }
    }

    /// Take the pooled connection for `key`, dropping it instead if it has been
    /// idle past the timeout (a socket the peer has almost certainly closed).
    fn take(&mut self, key: &(String, String, u16), now: Instant) -> Option<(S, IpAddr)> {
        let e = self.entries.remove(key)?;
        if now.duration_since(e.idle_since) > self.idle_timeout {
            self.expired += 1;
            return None; // `e.stream` drops here: the fd is released
        }
        Some((e.stream, e.pinned))
    }

    /// Pool a connection, first retiring anything idle past the timeout and then,
    /// if still at the cap, the least recently used entry. Without both, the map
    /// only ever grows: entries were added per authority and removed only by a
    /// same-key acquire or `close()`.
    fn put(&mut self, key: (String, String, u16), stream: S, pinned: IpAddr, now: Instant) {
        let timeout = self.idle_timeout;
        let before = self.entries.len();
        self.entries
            .retain(|_, e| now.duration_since(e.idle_since) <= timeout);
        self.expired += (before - self.entries.len()) as u64;

        while self.entries.len() >= self.max_idle.max(1) {
            let Some(lru) = self
                .entries
                .iter()
                .min_by_key(|(_, e)| e.used)
                .map(|(k, _)| k.clone())
            else {
                break;
            };
            self.entries.remove(&lru);
            self.evicted += 1;
        }

        self.clock += 1;
        let used = self.clock;
        self.entries.insert(
            key,
            PoolEntry {
                stream,
                pinned,
                used,
                idle_since: now,
            },
        );
    }

    fn len(&self) -> usize {
        self.entries.len()
    }

    fn clear(&mut self) {
        self.entries.clear();
    }
}

impl Fetcher {
    /// A fetcher whose connections are pooled + reused when `keep_alive` is set.
    #[must_use]
    pub fn new(keep_alive: bool) -> Self {
        Fetcher {
            keep_alive,
            pool: ConnPool::new(POOL_MAX_IDLE, POOL_IDLE_TIMEOUT),
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

    /// Idle connections currently pooled — never more than [`POOL_MAX_IDLE`].
    #[must_use]
    pub fn pooled(&self) -> usize {
        self.pool.len()
    }

    /// Pooled connections closed to stay under the cap (observability / tests).
    #[must_use]
    pub fn pool_evicted(&self) -> u64 {
        self.pool.evicted
    }

    /// Pooled connections closed for sitting idle too long.
    #[must_use]
    pub fn pool_expired(&self) -> u64 {
        self.pool.expired
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
        if let Some((stream, pinned)) = self.pool.take(&key, Instant::now()) {
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
                        self.pool.put(
                            (scheme.clone(), host.clone(), port),
                            stream,
                            pinned,
                            Instant::now(),
                        );
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

#[cfg(test)]
mod audit_regression {
    use super::*;

    fn key(n: usize) -> (String, String, u16) {
        ("http".to_string(), format!("h{n}.example"), 80)
    }

    fn ip() -> IpAddr {
        IpAddr::from([93, 184, 216, 34])
    }

    /// AUDIT REGRESSION (MEDIUM). The pool gained an entry per `(scheme, host,
    /// port)` and lost one only to a same-key `acquire` or `close()`, so it grew
    /// with the number of authorities a crawl touched: 300 distinct authorities
    /// took the process from 310 to 910 file descriptors and held them, and a
    /// broad `--keep-alive` crawl ran out of descriptors after ~1000 hosts.
    #[test]
    fn three_hundred_authorities_do_not_hold_three_hundred_sockets() {
        let mut pool: ConnPool<u32> = ConnPool::new(POOL_MAX_IDLE, POOL_IDLE_TIMEOUT);
        let now = Instant::now();
        for n in 0..300usize {
            pool.put(key(n), n as u32, ip(), now);
            assert!(
                pool.len() <= POOL_MAX_IDLE,
                "pool reached {} entries (cap {POOL_MAX_IDLE})",
                pool.len()
            );
        }
        assert_eq!(pool.len(), POOL_MAX_IDLE);
        assert_eq!(pool.evicted, 300 - POOL_MAX_IDLE as u64);
        // The survivors are the most recent authorities — the ones a crawl
        // working through a host is about to ask for again.
        assert!(pool.take(&key(299), now).is_some());
        assert!(pool.take(&key(0), now).is_none());
    }

    /// Eviction is least-recently-USED, not least-recently-inserted: an entry
    /// that keeps being taken and re-pooled survives a flood of one-shot hosts.
    #[test]
    fn the_hot_authority_survives_a_flood_of_cold_ones() {
        let mut pool: ConnPool<u32> = ConnPool::new(4, POOL_IDLE_TIMEOUT);
        let now = Instant::now();
        pool.put(key(1), 1, ip(), now);
        for n in 100..120usize {
            // Re-pooling the hot key refreshes its stamp, as a real reuse does.
            let (s, p) = pool.take(&key(1), now).expect("hot entry still pooled");
            pool.put(key(n), n as u32, ip(), now);
            pool.put(key(1), s, p, now);
        }
        assert!(
            pool.take(&key(1), now).is_some(),
            "the repeatedly-reused connection was evicted before the cold ones"
        );
    }

    /// An idle socket is closed rather than handed to a request that would then
    /// have to discover the peer hung up: both on the take path and on the sweep
    /// that runs before every insert.
    #[test]
    fn an_idle_connection_is_not_reused_and_not_kept() {
        let mut pool: ConnPool<u32> = ConnPool::new(8, Duration::from_millis(50));
        let t0 = Instant::now();
        pool.put(key(1), 1, ip(), t0);
        // Still fresh.
        let (s, p) = pool.take(&key(1), t0 + Duration::from_millis(10)).unwrap();
        pool.put(key(1), s, p, t0);
        // Past the idle timeout: taking it yields nothing and the entry is gone.
        assert!(pool
            .take(&key(1), t0 + Duration::from_millis(200))
            .is_none());
        assert_eq!(pool.expired, 1);
        assert_eq!(pool.len(), 0);

        // …and an insert sweeps other entries that went idle in the meantime.
        pool.put(key(2), 2, ip(), t0);
        pool.put(key(3), 3, ip(), t0 + Duration::from_millis(500));
        assert_eq!(pool.len(), 1, "the stale entry survived an insert");
        assert!(pool
            .take(&key(2), t0 + Duration::from_millis(500))
            .is_none());
    }
}
