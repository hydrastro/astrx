//! Tolerant, bounded probing of a single suite service — the network half of the
//! Python `suitedash.probe` (its parsers already live in [`crate::metrics`]).
//!
//! # Design notes
//!
//! * **Transport.** A hand-rolled HTTP/1.1 GET over a `tokio::net::TcpStream`, the
//!   async analogue of Python's bare `http.client.HTTPConnection`: it **never
//!   follows redirects** (the `follow_location = 0` posture the AstrX PHP bridge
//!   uses for SSRF hardening), targets an explicit host/port taken *only* from
//!   config, restricts the scheme to `http`/`https`, refuses to emit a request
//!   line whose target or host could smuggle a second request into it, and caps
//!   the response body — so a hostile or huge endpoint can neither make the
//!   dashboard chase a redirect off-box nor buffer unbounded data.
//!
//! * **Liveness is tolerant.** Services disagree on where health lives
//!   (`/health` vs `/healthz` vs nothing). The configured path is tried first,
//!   then [`HEALTH_FALLBACKS`] in order (see [`health_candidates`]), and *any*
//!   2xx is UP. A refused connection or a timeout is a fast DOWN; a non-2xx
//!   status just means "try the next path". The whole liveness check shares a
//!   single `timeout` budget, so trying several paths can never multiply the
//!   wall-clock cost.
//!
//! * **Metrics are tolerant.** The metrics body is handed to
//!   [`crate::metrics::parse_metrics`], which auto-detects Prometheus text
//!   versus JSON. A service that is UP but whose metrics endpoint fails still
//!   renders UP with no numbers.
//!
//! * **Bounded everywhere.** Both the header block and the body are read under
//!   one *total* wall-clock deadline (Python needs a watchdog thread plus
//!   `read1` for this; an async read simply loses the race and is dropped), the
//!   body is capped at [`MAX_BODY`], the head at 64 KiB, and the text retained
//!   for the federation exporter at [`MAX_FEDERATE_BODY`]. A backend that
//!   dribbles one byte per socket-timeout window is therefore reaped near
//!   `timeout`, not hours later.
//!
//! # SSRF posture (deliberately *not* the crawler's `SafeIp` gate)
//!
//! `websearch` vets every resolved address against an internal-IP denylist
//! because it fetches attacker-influenced URLs. suitedash does the opposite: its
//! whole purpose is to poll the operator's own services, which live on
//! **loopback** by default (`http://127.0.0.1:8801` …). Applying the internal-IP
//! gate here would refuse every default target. The Python reference makes the
//! same choice — its hardening is *scheme restriction + no redirect following +
//! explicit config-only targets + capped body + short timeouts*, and this port
//! reproduces exactly that, the same posture `websearch::federation` uses for
//! operator-configured shard URLs. Nothing here ever probes a user-supplied
//! address: the target set comes from the config file / CLI flags only.
//!
//! **Documented divergence:** `https://` targets are refused with a clear error
//! (rendered as DOWN) rather than probed. The suite ships no TLS implementation —
//! a TLS crate would break the zero-third-party-dependency invariant — exactly as
//! `websearch`'s fetcher refuses HTTPS.

use crate::config::ServiceConfig;

/// Hard cap on a health/metrics response body (defensive; metrics are tiny).
pub const MAX_BODY: usize = 1 << 20; // 1 MiB

/// Cap on the raw metrics text retained on each result for the aggregate
/// `/metrics` federation exporter — bounds the memory a poll snapshot (and the
/// optional TTL cache) can hold, independent of the [`MAX_BODY`] fetch cap.
pub const MAX_FEDERATE_BODY: usize = 1 << 18; // 256 KiB

/// Health paths tried after the configured one. Order matters: cheap, common
/// liveness routes first, then JSON stats endpoints, then a bare `/` (a 200 on
/// the index is a last-resort "it's listening and serving" signal).
pub const HEALTH_FALLBACKS: [&str; 7] = [
    "/health",
    "/healthz",
    "/livez",
    "/stats",
    "/api/stats",
    "/status",
    "/",
];

/// The liveness paths to try, in order: the configured `health_path` first, then
/// [`HEALTH_FALLBACKS`], with empties dropped and duplicates removed (Python
/// `_probe_health`'s `candidates` list).
///
/// Pure, so the fallback *order* — the part of the tolerance contract a config
/// change can silently break — is unit-testable without a socket.
#[must_use]
pub fn health_candidates(cfg: &ServiceConfig) -> Vec<String> {
    let mut out: Vec<String> = Vec::with_capacity(HEALTH_FALLBACKS.len() + 1);
    for p in std::iter::once(cfg.health_path.as_str()).chain(HEALTH_FALLBACKS) {
        if !p.is_empty() && !out.iter().any(|seen| seen == p) {
            out.push(p.to_string());
        }
    }
    out
}

#[cfg(feature = "net")]
pub use net_impl::{fetch, probe_service, FetchResult, ProbeError, USER_AGENT};

#[cfg(feature = "net")]
mod net_impl {
    use super::{health_candidates, MAX_BODY, MAX_FEDERATE_BODY};
    use crate::config::ServiceConfig;
    use crate::metrics::{parse_metrics, surface, MetricMap, ServiceResult};
    use crate::pycompat;
    use crawlcore::urlparse::{host_port, urlsplit};
    use std::fmt;
    use std::io::ErrorKind;
    use std::time::{Duration, SystemTime, UNIX_EPOCH};
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpStream;
    use tokio::time::{timeout_at, Instant};

    /// The `User-Agent` every probe sends (Python's `suitedash/1.0`).
    pub const USER_AGENT: &str = "suitedash/1.0";

    /// Cap on the status line + header block we will buffer for one response.
    const MAX_HEAD: usize = 64 * 1024;

    /// Socket read granularity. The body is read in chunks so the *total*
    /// wall-clock deadline is observed between reads.
    const READ_CHUNK: usize = 1 << 16; // 64 KiB

    /// A minimal HTTP response: status, content-type, (capped) body, latency.
    #[derive(Clone, Debug, PartialEq)]
    pub struct FetchResult {
        /// HTTP status code.
        pub status: u16,
        /// The `Content-Type` header value (empty when absent).
        pub content_type: String,
        /// The response body, truncated to [`MAX_BODY`].
        pub body: Vec<u8>,
        /// Round-trip time of the whole exchange, in milliseconds.
        pub latency_ms: f64,
    }

    /// Why one probe fetch failed.
    ///
    /// The variants are the Rust stand-ins for the exception classes Python's
    /// `_probe_health` distinguishes, because the distinction is behavioural: a
    /// [`ProbeError::Refused`], [`ProbeError::Timeout`], [`ProbeError::Os`] or
    /// [`ProbeError::Value`] ends the whole liveness check immediately, while a
    /// [`ProbeError::Reset`] or [`ProbeError::Protocol`] just moves on to the
    /// next candidate path (see [`ProbeError::is_fatal`]).
    #[derive(Clone, Debug, PartialEq, Eq)]
    pub enum ProbeError {
        /// The connection was refused — nothing is listening
        /// (`ConnectionRefusedError`).
        Refused,
        /// The per-probe wall-clock deadline expired (`socket.timeout` /
        /// `TimeoutError`).
        Timeout,
        /// The peer reset the connection or the pipe broke mid-exchange
        /// (`ConnectionResetError` / `BrokenPipeError`).
        Reset(String),
        /// A malformed or truncated HTTP response (`http.client.HTTPException`).
        Protocol(String),
        /// Any other transport failure — DNS, host unreachable, …
        /// (`OSError`).
        Os(String),
        /// The target itself is unusable: an unsupported scheme or a base URL
        /// with no host (`ValueError`).
        Value(String),
    }

    impl ProbeError {
        /// The message rendered onto the service card (Python `_errstr`).
        #[must_use]
        pub fn message(&self) -> String {
            match self {
                ProbeError::Refused => "connection refused".to_string(),
                ProbeError::Timeout => "timeout".to_string(),
                ProbeError::Reset(m)
                | ProbeError::Protocol(m)
                | ProbeError::Os(m)
                | ProbeError::Value(m) => m.clone(),
            }
        }

        /// `true` when this failure ends the whole liveness check rather than
        /// moving on to the next candidate health path.
        #[must_use]
        pub fn is_fatal(&self) -> bool {
            matches!(
                self,
                ProbeError::Refused
                    | ProbeError::Timeout
                    | ProbeError::Os(_)
                    | ProbeError::Value(_)
            )
        }
    }

    impl fmt::Display for ProbeError {
        fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
            f.write_str(&self.message())
        }
    }

    impl std::error::Error for ProbeError {}

    /// Map a transport error the way Python's `except` ladder classifies it: a
    /// refusal and a timeout are fast, fatal DOWNs; a reset/broken pipe retries
    /// the next path; anything else is a fatal `OSError`.
    fn from_io(e: &std::io::Error) -> ProbeError {
        match e.kind() {
            ErrorKind::ConnectionRefused => ProbeError::Refused,
            ErrorKind::TimedOut => ProbeError::Timeout,
            ErrorKind::ConnectionReset | ErrorKind::BrokenPipe => ProbeError::Reset(e.to_string()),
            _ => ProbeError::Os(e.to_string()),
        }
    }

    /// CPython's `repr()` of a string, for the `ValueError` messages.
    fn py_repr(s: &str) -> String {
        crate::config::toml::Value::Str(s.to_string()).py_repr()
    }

    /// Refuse a request-line field that would end the field and start another.
    ///
    /// The request is built by interpolation — `GET {target} HTTP/1.1\r\nHost:
    /// {host}\r\n…` — so a space or a control byte in either field is not a
    /// strange path, it is a second request: `health_path = "/health HTTP/1.1\r\n
    /// Host: attacker\r\n\r\nGET /admin"` puts two complete HTTP requests on the
    /// wire from one probe. [`crate::config::parse_config`] rejects such a path
    /// when it loads one, but [`ServiceConfig`] is a public struct an embedder can
    /// fill in directly (and `base_url`'s own path lands in the same line), so the
    /// emitter refuses as well. Checked before `connect`, so a bad target never
    /// even opens a socket.
    fn check_request_field(what: &str, value: &str) -> Result<(), ProbeError> {
        if let Some(c) = value
            .chars()
            .find(|c| *c == ' ' || (*c as u32) < 0x20 || *c as u32 == 0x7f)
        {
            return Err(ProbeError::Value(format!(
                "{what} may not contain {}: {}",
                py_repr(&c.to_string()),
                py_repr(value)
            )));
        }
        Ok(())
    }

    /// Epoch seconds (Python `time.time()`).
    fn now_secs() -> f64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_or(0.0, |d| d.as_secs_f64())
    }

    /// A deadline-bounded buffered reader over the probe socket.
    ///
    /// Every fill races the *total* wall-clock deadline, so no single read — and
    /// no sequence of reads — can outlast it, even against an active
    /// byte-at-a-time dribble. This is what Python needs a watchdog thread plus
    /// `read1` + `settimeout` to achieve.
    struct Reader {
        stream: TcpStream,
        buf: Vec<u8>,
        pos: usize,
        eof: bool,
        deadline: Instant,
    }

    impl Reader {
        fn new(stream: TcpStream, deadline: Instant) -> Self {
            Reader {
                stream,
                buf: Vec::new(),
                pos: 0,
                eof: false,
                deadline,
            }
        }

        /// Pull one chunk from the socket. `Ok(0)` means EOF.
        async fn fill(&mut self) -> Result<usize, ProbeError> {
            if self.eof {
                return Ok(0);
            }
            let mut tmp = [0u8; READ_CHUNK];
            match timeout_at(self.deadline, self.stream.read(&mut tmp)).await {
                Err(_) => Err(ProbeError::Timeout),
                Ok(Err(e)) => Err(from_io(&e)),
                Ok(Ok(0)) => {
                    self.eof = true;
                    Ok(0)
                }
                Ok(Ok(n)) => {
                    self.buf.extend_from_slice(&tmp[..n]);
                    Ok(n)
                }
            }
        }

        fn available(&self) -> usize {
            self.buf.len() - self.pos
        }

        fn take(&mut self, n: usize) -> Vec<u8> {
            let n = n.min(self.available());
            let out = self.buf[self.pos..self.pos + n].to_vec();
            self.pos += n;
            out
        }

        /// Read up to and including `sep`, returning the bytes before it. Fails
        /// with [`ProbeError::Protocol`] if the peer closes first or `cap` is hit
        /// — the shapes CPython reports as `BadStatusLine`/`LineTooLong`.
        async fn read_until(&mut self, sep: &[u8], cap: usize) -> Result<Vec<u8>, ProbeError> {
            let mut searched = self.pos;
            loop {
                if let Some(i) = find(&self.buf[searched..], sep).map(|i| i + searched) {
                    let out = self.buf[self.pos..i].to_vec();
                    self.pos = i + sep.len();
                    return Ok(out);
                }
                // Re-examine the last `sep.len() - 1` bytes next round, so a
                // separator straddling two reads is still found.
                searched = self.buf.len().saturating_sub(sep.len() - 1).max(self.pos);
                if self.available() > cap {
                    return Err(ProbeError::Protocol("response line too long".to_string()));
                }
                if self.fill().await? == 0 {
                    return Err(ProbeError::Protocol(
                        "connection closed mid-response".to_string(),
                    ));
                }
            }
        }

        /// Read exactly `n` bytes, or fewer if the peer closes first (Python's
        /// `read1` loop tolerates a short body rather than raising).
        async fn read_n(&mut self, n: usize) -> Result<Vec<u8>, ProbeError> {
            while self.available() < n {
                if self.fill().await? == 0 {
                    break;
                }
            }
            Ok(self.take(n))
        }

        /// Read until EOF, capped at `cap` bytes.
        async fn read_to_end(&mut self, cap: usize) -> Result<Vec<u8>, ProbeError> {
            while self.available() < cap {
                if self.fill().await? == 0 {
                    break;
                }
            }
            Ok(self.take(cap))
        }

        /// Read a `Transfer-Encoding: chunked` body, capped at `cap` bytes.
        async fn read_chunked(&mut self, cap: usize) -> Result<Vec<u8>, ProbeError> {
            let mut out: Vec<u8> = Vec::new();
            loop {
                let line = self.read_until(b"\r\n", 1024).await?;
                let text = String::from_utf8_lossy(&line);
                let size_tok = text.split(';').next().unwrap_or("").trim();
                let Ok(size) = usize::from_str_radix(size_tok, 16) else {
                    return Err(ProbeError::Protocol(format!(
                        "invalid chunk size {size_tok:?}"
                    )));
                };
                if size == 0 {
                    // Trailer block, then the final CRLF.
                    while let Ok(t) = self.read_until(b"\r\n", 1024).await {
                        if t.is_empty() {
                            break;
                        }
                    }
                    break;
                }
                // NB: `size` is the peer's hex chunk header and is unbounded, so
                // reading the chunk whole and only THEN trimming into `out`
                // buffered the entire stream in `self.buf` — `cap` bounded the
                // output but nothing bounded the read, and the dashboard was
                // OOM-killed outright. Never ask for more than the room left.
                let room = cap.saturating_sub(out.len());
                if size > room {
                    out.extend_from_slice(&self.read_n(room).await?);
                    break; // framing abandoned; the connection is not reusable
                }
                let chunk = self.read_n(size).await?;
                let short = chunk.len() < size;
                out.extend_from_slice(&chunk);
                if short {
                    break; // peer closed mid-chunk; keep what arrived
                }
                // The chunk's trailing CRLF. A peer that omits it has desynced the
                // framing, so stop rather than parse the remainder as a size line.
                if self.read_until(b"\r\n", 8).await.is_err() || out.len() >= cap {
                    break;
                }
            }
            Ok(out)
        }
    }

    fn find(hay: &[u8], needle: &[u8]) -> Option<usize> {
        if needle.is_empty() || hay.len() < needle.len() {
            return None;
        }
        (0..=hay.len() - needle.len()).find(|&i| &hay[i..i + needle.len()] == needle)
    }

    /// `HTTP/1.1 200 OK` → `200`. A head that does not start with a version and a
    /// three-digit code is CPython's `BadStatusLine`.
    fn parse_status(line: &[u8]) -> Result<u16, ProbeError> {
        let text = String::from_utf8_lossy(line);
        let mut parts = text.split(' ').filter(|p| !p.is_empty());
        let version = parts.next().unwrap_or("");
        if !version.starts_with("HTTP/") {
            return Err(ProbeError::Protocol(format!(
                "bad status line: {}",
                py_repr(text.trim_end())
            )));
        }
        let code = parts.next().unwrap_or("");
        code.parse::<u16>().map_err(|_| {
            ProbeError::Protocol(format!("bad status line: {}", py_repr(text.trim_end())))
        })
    }

    /// The value of `name` in a header block (case-insensitive, first wins).
    fn header<'a>(block: &'a str, name: &str) -> Option<&'a str> {
        for line in block.split("\r\n") {
            let Some((k, v)) = line.split_once(':') else {
                continue;
            };
            if k.trim().eq_ignore_ascii_case(name) {
                return Some(v.trim());
            }
        }
        None
    }

    /// GET `base_url + path` with a short total timeout and **no redirect
    /// following**.
    ///
    /// The scheme must be `http` (see the module's HTTPS note), the host comes
    /// from the operator's config, the assembled request target and host carry no
    /// byte that could smuggle a second request (see `check_request_field`), the
    /// body is capped at [`MAX_BODY`], and the header block *and* body share one
    /// wall-clock deadline.
    ///
    /// # Errors
    /// A [`ProbeError`] describing the transport failure; the caller maps it to a
    /// DOWN result.
    pub async fn fetch(
        base_url: &str,
        path: &str,
        timeout: Duration,
    ) -> Result<FetchResult, ProbeError> {
        let parts = urlsplit(base_url, "http");
        let scheme = if parts.scheme.is_empty() {
            "http".to_string()
        } else {
            parts.scheme.to_lowercase()
        };
        if scheme != "http" && scheme != "https" {
            return Err(ProbeError::Value(format!(
                "unsupported scheme: {}",
                py_repr(&scheme)
            )));
        }
        if scheme == "https" {
            // See the module docs: no stdlib TLS, and a TLS crate would break the
            // suite's zero-third-party-dependency invariant.
            return Err(ProbeError::Value(
                "https requires a TLS feature suitedash does not ship".to_string(),
            ));
        }
        let (host, port_str) = host_port(&parts.netloc);
        if host.is_empty() {
            return Err(ProbeError::Value(format!(
                "base_url has no host: {}",
                py_repr(base_url)
            )));
        }
        let port: u16 = match port_str.as_deref().filter(|p| !p.is_empty()) {
            Some(p) => p.parse().map_err(|_| {
                ProbeError::Value(format!(
                    "Port could not be cast to integer value as {}",
                    py_repr(p)
                ))
            })?,
            None => 80,
        };

        let mut full = pycompat::rstrip_chars(&parts.path, "/").to_string();
        if !path.starts_with('/') {
            full.push('/');
        }
        full.push_str(path);
        check_request_field("request path", &full)?;
        check_request_field("host", &host)?;

        let started = Instant::now();
        let deadline = started + timeout;

        let stream = match timeout_at(deadline, TcpStream::connect((host.as_str(), port))).await {
            Err(_) => return Err(ProbeError::Timeout),
            Ok(Err(e)) => return Err(from_io(&e)),
            Ok(Ok(s)) => s,
        };

        // `Connection: close` + no redirect following: one request, one response,
        // straight from config-supplied host/port.
        let host_header = if port == 80 {
            host.clone()
        } else {
            format!("{host}:{port}")
        };
        let request = format!(
            "GET {full} HTTP/1.1\r\nHost: {host_header}\r\nAccept-Encoding: identity\r\n\
             Accept: application/json, text/plain, */*\r\nUser-Agent: {USER_AGENT}\r\n\
             Connection: close\r\n\r\n"
        );
        let mut reader = Reader::new(stream, deadline);
        match timeout_at(deadline, reader.stream.write_all(request.as_bytes())).await {
            Err(_) => return Err(ProbeError::Timeout),
            Ok(Err(e)) => return Err(from_io(&e)),
            Ok(Ok(())) => {}
        }

        // The header block is read under the SAME total deadline as the body, so
        // a backend that dribbles the head cannot pin this probe.
        let head = reader.read_until(b"\r\n\r\n", MAX_HEAD).await?;
        let head = String::from_utf8_lossy(&head).to_string();
        let (status_line, header_block) = head.split_once("\r\n").unwrap_or((head.as_str(), ""));
        let status = parse_status(status_line.as_bytes())?;
        let content_type = header(header_block, "content-type")
            .unwrap_or_default()
            .to_string();

        let te = header(header_block, "transfer-encoding")
            .unwrap_or_default()
            .to_lowercase();
        // These statuses carry no body by definition (`http.client`'s `length = 0`
        // shortcut); waiting for EOF on one would burn the whole deadline against
        // a peer that holds the socket open.
        let bodyless = status == 204 || status == 304 || (100..200).contains(&status);
        let body = if bodyless {
            Vec::new()
        } else if te.contains("chunked") {
            reader.read_chunked(MAX_BODY).await?
        } else if let Some(len) =
            header(header_block, "content-length").and_then(|v| v.trim().parse::<usize>().ok())
        {
            reader.read_n(len.min(MAX_BODY)).await?
        } else {
            reader.read_to_end(MAX_BODY).await?
        };

        Ok(FetchResult {
            status,
            content_type,
            body,
            latency_ms: started.elapsed().as_secs_f64() * 1000.0,
        })
    }

    /// Try every candidate health path within ONE `timeout` budget, returning
    /// `(up, latency_ms, health_path, error)` — Python `_probe_health`.
    async fn probe_health(
        cfg: &ServiceConfig,
        timeout: Duration,
    ) -> (bool, Option<f64>, Option<String>, Option<String>) {
        let deadline = Instant::now() + timeout;
        let mut last_err: Option<String> = None;
        for path in health_candidates(cfg) {
            let remaining = deadline.saturating_duration_since(Instant::now());
            // Python: `if remaining <= 0.05: break` — too little left to be worth
            // another connect.
            if remaining <= Duration::from_millis(50) {
                break;
            }
            match fetch(&cfg.base_url, &path, timeout.min(remaining)).await {
                Err(e) if e.is_fatal() => return (false, None, None, Some(e.message())),
                Err(e) => {
                    // Reset / malformed response: not fatal, try the next path.
                    last_err = Some(e.message());
                }
                Ok(fr) => {
                    if (200..300).contains(&fr.status) {
                        return (
                            true,
                            Some(pycompat::round_ndigits(fr.latency_ms, 2)),
                            Some(path),
                            None,
                        );
                    }
                    last_err = Some(format!("http {}", fr.status));
                }
            }
        }
        (false, None, None, last_err)
    }

    /// Probe one service: liveness (tolerant) then metrics (tolerant).
    ///
    /// Never fails — any transport failure becomes a DOWN [`ServiceResult`].
    /// Bounded by `timeout` for liveness and a second `timeout` for the metrics
    /// fetch, so the worst case for one service is ~`2 * timeout` even if it is a
    /// black hole; [`crate::poller::poll_all`] additionally caps this from the
    /// outside.
    pub async fn probe_service(cfg: &ServiceConfig, timeout: Duration) -> ServiceResult {
        let (up, latency_ms, health_path, error) = probe_health(cfg, timeout).await;

        let mut metrics = MetricMap::new();
        let mut metrics_raw = String::new();
        let mut metrics_ctype = String::new();
        if up {
            // A failed metrics fetch leaves the service UP with no numbers.
            if let Ok(fr) = fetch(&cfg.base_url, &cfg.metrics_path, timeout).await {
                if (200..300).contains(&fr.status) {
                    metrics_ctype = fr.content_type.clone();
                    // Retain the raw text (capped) for the federation exporter.
                    // Cap by BYTES so retained memory truly matches
                    // MAX_FEDERATE_BODY; a multi-byte sequence split at the cut is
                    // handled by the lossy decode.
                    let keep = fr.body.len().min(MAX_FEDERATE_BODY);
                    metrics_raw = String::from_utf8_lossy(&fr.body[..keep]).into_owned();
                    metrics = parse_metrics(&fr.body, &fr.content_type);
                }
            }
        }

        ServiceResult {
            name: cfg.name.clone(),
            base_url: cfg.base_url.clone(),
            up,
            latency_ms,
            metrics: surface(&metrics, &cfg.metrics_keys),
            checked_at: now_secs(),
            error,
            health_path,
            label: cfg.label.clone(),
            metrics_raw,
            metrics_ctype,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn candidates_put_the_configured_path_first_and_dedup() {
        let cfg = ServiceConfig {
            health_path: "/healthz".to_string(),
            ..ServiceConfig::new("svc", "http://127.0.0.1:1")
        };
        assert_eq!(
            health_candidates(&cfg),
            vec![
                "/healthz",
                "/health",
                "/livez",
                "/stats",
                "/api/stats",
                "/status",
                "/"
            ]
        );
    }

    #[test]
    fn candidates_keep_the_fallback_order_for_an_unknown_path() {
        let cfg = ServiceConfig {
            health_path: "/ping".to_string(),
            ..ServiceConfig::new("svc", "http://127.0.0.1:1")
        };
        let mut expected = vec!["/ping".to_string()];
        expected.extend(HEALTH_FALLBACKS.iter().map(|p| (*p).to_string()));
        assert_eq!(health_candidates(&cfg), expected);
    }

    #[test]
    fn an_empty_health_path_falls_straight_through_to_the_fallbacks() {
        let cfg = ServiceConfig {
            health_path: String::new(),
            ..ServiceConfig::new("svc", "http://127.0.0.1:1")
        };
        assert_eq!(health_candidates(&cfg), HEALTH_FALLBACKS.to_vec());
    }
}
