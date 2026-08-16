//! The no-JavaScript HTTP dashboard — a port of the Python `suitedash.server`.
//!
//! Following the suite's serving convention (`websearch::serve`), the state
//! owner and the routing are **pure** and unit-testable without a socket, and
//! only the polling + accept loop live behind the `net` feature:
//!
//! * [`Dashboard`] owns the config, the [`Monitor`] (alerts + history) and the
//!   optional TTL poll cache, and [`Dashboard::route`] renders one request over
//!   an **already-polled** sweep — a function of its arguments.
//! * `net` adds `Dashboard::poll` (one bounded, concurrent sweep through
//!   `poller::poll_all`, feeding the monitor on every real sweep),
//!   `Dashboard::handle`, and the `serve` accept loop.
//!
//! Routes (`GET`, and `HEAD` for the same headers without a body):
//!
//! * `/`             — server-rendered HTML status page (auto-refreshing)
//! * `/api/status`   — the same snapshot as JSON (incl. alert state)
//! * `/metrics`      — aggregate Prometheus exposition federating every service
//! * `/healthz`      — the dashboard's own liveness (`ok`)
//! * `/favicon.ico`  — 204
//! * anything else   — 404
//!
//! Every response carries a strict CSP ([`CSP`]: `default-src 'none'` + inline
//! styles only), `nosniff`, `DENY` framing, `no-referrer` and `no-store`. The
//! accept loop binds whatever the config says — `127.0.0.1` by default; a Tor
//! onion service or a reverse proxy is the intended front — and bounds
//! concurrent connections to `max_workers` (the Slowloris guard Python
//! implements with a `BoundedSemaphore`), the request head to 64 KiB *and to one
//! total deadline*, the response write to a deadline of its own, and every
//! outbound probe to the per-service timeout. A connection therefore has a
//! bounded lifetime, not merely a bounded idle time.
//!
//! **Documented divergences** from CPython's `BaseHTTPRequestHandler`: no `Date`
//! header is emitted (the head stays a pure function of the response), the
//! `Server` header is the fixed [`SERVER_NAME`] rather than
//! `suitedash/1.0 Python/3.x`, an unsupported method answers `501` with a plain
//! text body instead of CPython's HTML error page, and the request log line is a
//! compact `peer "METHOD TARGET" status` rather than the Common Log Format.

use crate::config::Config;
use crate::exporter::CONTENT_TYPE as METRICS_CONTENT_TYPE;
use crate::metrics::Results;
use crate::monitor::Monitor;
use crate::pycompat;
use crate::render::{render_page, render_status_json};
use std::sync::Mutex;
use std::time::{Duration, Instant};

/// The strict Content-Security-Policy every response carries: no scripts at all,
/// inline styles only (the page's single `<style>` block), no framing, no forms.
pub const CSP: &str =
    "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; \
     frame-ancestors 'none'";

/// The `Server` header value.
pub const SERVER_NAME: &str = "suitedash/1.0";

/// Cap on a request head (status line + headers) the server will buffer.
pub const MAX_REQUEST_HEAD: usize = 64 * 1024;

/// Upper clamp applied to any duration read from the config, so a hostile or
/// fat-fingered value can neither panic [`Duration::from_secs_f64`] nor wedge a
/// sweep for a geological age.
const MAX_CONFIG_SECONDS: f64 = 86_400.0;

/// A configured duration in seconds → a [`Duration`], clamped to
/// `0 ..= MAX_CONFIG_SECONDS` (NaN → 0).
fn config_duration(seconds: f64) -> Duration {
    let secs = if seconds.is_finite() {
        seconds.clamp(0.0, MAX_CONFIG_SECONDS)
    } else {
        0.0
    };
    Duration::from_secs_f64(secs)
}

/// An HTTP reply: status, content type and body.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Resp {
    /// HTTP status code.
    pub status: u16,
    /// The `Content-Type` header value.
    pub ctype: &'static str,
    /// The response body (empty for `204`/`HEAD`).
    pub body: String,
}

impl Resp {
    /// An HTML reply.
    #[must_use]
    pub fn html(status: u16, body: String) -> Self {
        Resp {
            status,
            ctype: "text/html; charset=utf-8",
            body,
        }
    }

    /// A JSON reply.
    #[must_use]
    pub fn json(status: u16, body: String) -> Self {
        Resp {
            status,
            ctype: "application/json; charset=utf-8",
            body,
        }
    }

    /// A plain-text reply.
    #[must_use]
    pub fn text(status: u16, body: &str) -> Self {
        Resp {
            status,
            ctype: "text/plain; charset=utf-8",
            body: body.to_string(),
        }
    }

    /// A Prometheus exposition reply.
    #[must_use]
    pub fn metrics(body: String) -> Self {
        Resp {
            status: 200,
            ctype: METRICS_CONTENT_TYPE,
            body,
        }
    }

    /// The full response head — status line, security headers, blank line.
    ///
    /// Pure, so the security posture is asserted by unit tests rather than by a
    /// live socket. `Content-Length` is the body's byte length even for a `HEAD`
    /// request, whose body is suppressed by the writer.
    #[must_use]
    pub fn head(&self) -> String {
        format!(
            "HTTP/1.1 {status} {reason}\r\nServer: {SERVER_NAME}\r\nContent-Type: {ctype}\r\n\
             Content-Length: {len}\r\nX-Content-Type-Options: nosniff\r\n\
             X-Frame-Options: DENY\r\nReferrer-Policy: no-referrer\r\n\
             Content-Security-Policy: {CSP}\r\nCache-Control: no-store\r\n\
             Connection: close\r\n\r\n",
            status = self.status,
            reason = reason(self.status),
            ctype = self.ctype,
            len = self.body.len(),
        )
    }
}

/// The reason phrase for the handful of statuses this server emits.
#[must_use]
pub fn reason(status: u16) -> &'static str {
    match status {
        204 => "No Content",
        404 => "Not Found",
        500 => "Internal Server Error",
        501 => "Not Implemented",
        _ => "OK",
    }
}

/// One of the dashboard's routes.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Route {
    /// `/` — the HTML status page.
    Page,
    /// `/api/status` — the JSON snapshot.
    Status,
    /// `/metrics` — the aggregate Prometheus exposition.
    Metrics,
    /// `/healthz` — the dashboard's own liveness.
    Health,
    /// `/favicon.ico` — an empty 204.
    Favicon,
    /// Anything else — 404.
    NotFound,
}

impl Route {
    /// The route a raw request target selects.
    ///
    /// Reproduces the Python normalisation exactly: the query string is dropped
    /// and trailing slashes are stripped, with an all-slash path folding back to
    /// `/` (`self.path.split("?", 1)[0].rstrip("/") or "/"`).
    #[must_use]
    pub fn of(target: &str) -> Route {
        let path = target.split('?').next().unwrap_or("");
        let path = pycompat::rstrip_chars(path, "/");
        match if path.is_empty() { "/" } else { path } {
            "/" => Route::Page,
            "/api/status" => Route::Status,
            "/metrics" => Route::Metrics,
            "/healthz" => Route::Health,
            "/favicon.ico" => Route::Favicon,
            _ => Route::NotFound,
        }
    }

    /// Whether rendering this route needs a fresh poll sweep. `/healthz` and
    /// `/favicon.ico` deliberately never probe the suite, so a liveness check
    /// can never be turned into a probe amplifier.
    ///
    /// The three that do poll cost one sweep — `len(services)` outbound probes —
    /// per unauthenticated request, and the page's `<meta refresh>` makes each
    /// open browser tab a client of its own. That is the reference's behaviour and
    /// the goldens pin `cache_ttl = 0.0` (caching off) as the default, so the
    /// collapse of N requests into one sweep is opt-in: set `cache_ttl` to at
    /// least the refresh interval on any instance reachable by more than the
    /// operator (see [`Dashboard::cached`], and the shipped
    /// `suitedash.example.toml`).
    #[must_use]
    pub fn needs_poll(self) -> bool {
        matches!(self, Route::Page | Route::Status | Route::Metrics)
    }
}

/// The dashboard's shared state: the config, the alert/history [`Monitor`] and
/// the optional TTL poll cache.
pub struct Dashboard {
    config: Config,
    monitor: Monitor,
    cache: Mutex<Option<(Instant, Results)>>,
}

impl Dashboard {
    /// A dashboard over `config`.
    #[must_use]
    pub fn new(config: Config) -> Self {
        Dashboard {
            monitor: Monitor::new(&config),
            config,
            cache: Mutex::new(None),
        }
    }

    /// The configuration being served.
    #[must_use]
    pub fn config(&self) -> &Config {
        &self.config
    }

    /// The alert + history monitor.
    #[must_use]
    pub fn monitor(&self) -> &Monitor {
        &self.monitor
    }

    /// The per-service probe budget (`timeout_seconds`, clamped).
    #[must_use]
    pub fn probe_timeout(&self) -> Duration {
        config_duration(self.config.timeout_seconds)
    }

    /// How long a poll snapshot stays fresh (`cache_ttl`, clamped); `0` disables
    /// caching.
    #[must_use]
    pub fn cache_ttl(&self) -> Duration {
        config_duration(self.config.cache_ttl)
    }

    /// The most recent sweep, if caching is enabled and it is still fresh.
    ///
    /// A cache hit deliberately does **not** advance alert debounce or history:
    /// "for N polls" counts real probes, never re-reads of a cached snapshot.
    #[must_use]
    pub fn cached(&self) -> Option<Results> {
        let ttl = self.cache_ttl();
        if ttl.is_zero() {
            return None;
        }
        let cache = self.cache.lock().expect("poll cache mutex");
        match cache.as_ref() {
            Some((at, results)) if at.elapsed() < ttl => Some(results.clone()),
            _ => None,
        }
    }

    /// Record `results` as the newest cached sweep (a no-op when caching is off).
    pub fn cache(&self, results: &Results) {
        if self.cache_ttl().is_zero() {
            return;
        }
        let mut cache = self.cache.lock().expect("poll cache mutex");
        *cache = Some((Instant::now(), results.clone()));
    }

    /// Render one request over an already-polled `results` sweep.
    ///
    /// Pure: the only clock is the `now` handed in (stamped into the page footer
    /// and the JSON `generated_at`). `GET` and `HEAD` render identically — the
    /// writer is what suppresses a `HEAD` body.
    #[must_use]
    pub fn route(&self, method: &str, target: &str, results: &Results, now: f64) -> Resp {
        if method != "GET" && method != "HEAD" {
            return Resp::text(501, "unsupported method\n");
        }
        match Route::of(target) {
            Route::Page => Resp::html(
                200,
                render_page(results, &self.config, Some(&self.monitor.snapshot()), now),
            ),
            Route::Status => Resp::json(
                200,
                render_status_json(results, Some(&self.monitor.snapshot()), now),
            ),
            Route::Metrics => Resp::metrics(crate::exporter::render_metrics_page(results)),
            Route::Health => Resp::text(200, "ok\n"),
            Route::Favicon => Resp {
                status: 204,
                ctype: "image/x-icon",
                body: String::new(),
            },
            Route::NotFound => Resp::text(404, "not found\n"),
        }
    }
}

#[cfg(feature = "net")]
pub use net_impl::{serve, serve_config, HEAD_READ_TIMEOUT, RESPONSE_WRITE_TIMEOUT};

#[cfg(feature = "net")]
mod net_impl {
    use super::{Dashboard, Resp, Route, MAX_REQUEST_HEAD};
    use crate::config::Config;
    use crate::metrics::Results;
    use crate::poller::poll_all;
    use std::sync::Arc;
    use std::time::{Duration, SystemTime, UNIX_EPOCH};
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::{TcpListener, TcpStream};
    use tokio::sync::Semaphore;
    use tokio::time::{timeout_at, Instant};

    /// The **total** wall-clock budget for reading one request head, from the
    /// first byte to the terminating `\r\n\r\n`.
    ///
    /// It has to be total, not per-read. Wrapping a single `read()` in this
    /// timeout restarted it on every byte, so a client sending one byte every
    /// nine seconds renewed its lease forever: with `max_workers = 1`, one socket
    /// dribbling `X-Pad-N: a\r\n` every 3 s and never terminating the head locked
    /// every other client out of the dashboard indefinitely — and the default
    /// `max_workers` is 16, so sixteen such sockets take it offline. Python
    /// relies on the bounded connection pool alone; the pool is only a Slowloris
    /// guard if the slots are guaranteed to come back.
    pub const HEAD_READ_TIMEOUT: Duration = Duration::from_secs(10);

    /// Budget for writing one response, head and body together.
    ///
    /// The mirror image of the read side: a client that completes its request and
    /// then stops reading (advertising a zero window) leaves `write_all` pending
    /// forever once the socket buffer fills, holding its connection permit just as
    /// effectively as a dribbled head. Generous, because the far end may be a slow
    /// link pulling a page with many services on it — it exists to bound the
    /// pathological case, not to police slow readers.
    pub const RESPONSE_WRITE_TIMEOUT: Duration = Duration::from_secs(30);

    /// Ceiling on the concurrent-connection bound, so an absurd `max_workers`
    /// cannot overflow the permit pool's own limit.
    const MAX_CONNECTIONS: usize = 65_536;

    /// Epoch seconds (Python `time.time()`).
    fn now_secs() -> f64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_or(0.0, |d| d.as_secs_f64())
    }

    impl Dashboard {
        /// The probe-pool bound for one sweep — Python's
        /// `ThreadPoolExecutor(max_workers=max(4, len(services) * 2 + 2))`, a
        /// pool separate from the inbound connection bound so a slow service
        /// straggler never starves request handling.
        #[must_use]
        pub fn probe_workers(&self) -> usize {
            self.config()
                .services
                .len()
                .saturating_mul(2)
                .saturating_add(2)
                .max(4)
        }

        /// One poll sweep, served from the TTL cache when one is configured and
        /// still fresh.
        ///
        /// Alert state and history advance on every **real** sweep and never on a
        /// cache hit, so `for_polls` debounce counts actual probes.
        pub async fn poll(&self) -> Results {
            if let Some(hit) = self.cached() {
                return hit;
            }
            let results = poll_all(
                &self.config().services,
                self.probe_timeout(),
                self.probe_workers(),
            )
            .await;
            self.monitor().ingest(&results, now_secs());
            self.cache(&results);
            results
        }

        /// Poll if the route needs it, then render — the async half of
        /// [`Dashboard::route`].
        pub async fn handle(&self, method: &str, target: &str) -> Resp {
            let results = if (method == "GET" || method == "HEAD") && Route::of(target).needs_poll()
            {
                self.poll().await
            } else {
                Results::new()
            };
            self.route(method, target, &results, now_secs())
        }
    }

    fn find(hay: &[u8], needle: &[u8]) -> Option<usize> {
        if needle.is_empty() || hay.len() < needle.len() {
            return None;
        }
        (0..=hay.len() - needle.len()).find(|&i| &hay[i..i + needle.len()] == needle)
    }

    /// Read one request head, bounded in size and by a **total** `deadline` that
    /// spans every read. `None` means the client disconnected, overflowed the cap,
    /// or ran out of time (see [`HEAD_READ_TIMEOUT`]).
    async fn read_head(sock: &mut TcpStream, deadline: Instant) -> Option<String> {
        let mut buf: Vec<u8> = Vec::new();
        let mut tmp = [0u8; 4096];
        loop {
            if let Some(end) = find(&buf, b"\r\n\r\n") {
                return Some(String::from_utf8_lossy(&buf[..end]).into_owned());
            }
            if buf.len() > MAX_REQUEST_HEAD {
                return None;
            }
            match timeout_at(deadline, sock.read(&mut tmp)).await {
                Ok(Ok(0)) | Ok(Err(_)) | Err(_) => return None,
                Ok(Ok(n)) => buf.extend_from_slice(&tmp[..n]),
            }
        }
    }

    /// Write `bytes`, giving up at `deadline`; `false` means the write failed or
    /// the deadline passed and the connection should be dropped.
    async fn write_by(sock: &mut TcpStream, bytes: &[u8], deadline: Instant) -> bool {
        matches!(
            timeout_at(deadline, sock.write_all(bytes)).await,
            Ok(Ok(()))
        )
    }

    /// `GET /api/status?x=1 HTTP/1.1` → `("GET", "/api/status?x=1")`.
    ///
    /// The request line ends at the first CR **or** LF. Splitting on `"\r\n"`
    /// alone let a bare `\n` inside the target survive into the rest of the
    /// handler: `GET /x\n127.0.0.1:1 "GET /admin" 200 HTTP/1.1` yielded the
    /// target `"/x\n127.0.0.1:1"`.
    fn request_line(head: &str) -> (String, String) {
        let line = head.split(['\r', '\n']).next().unwrap_or("");
        let mut parts = line.split(' ').filter(|p| !p.is_empty());
        (
            parts.next().unwrap_or("GET").to_string(),
            parts.next().unwrap_or("/").to_string(),
        )
    }

    /// One request-log field, made safe to print.
    ///
    /// The log is one line per request — `peer "METHOD TARGET" status` — so a
    /// control character in a field forges entries: the target
    /// `/x\n127.0.0.1:1 "GET /admin" 200` printed a second, entirely
    /// attacker-written line that no reader of the log could tell from a real
    /// request. Controls become `\xNN`, quotes and backslashes are escaped, and
    /// the field is truncated so a 64 KiB request line cannot become a 64 KiB log
    /// line either.
    fn log_token(s: &str) -> String {
        const MAX_LOGGED: usize = 200;
        let mut out = String::with_capacity(s.len().min(MAX_LOGGED) + 2);
        let mut truncated = false;
        for (i, c) in s.chars().enumerate() {
            if i >= MAX_LOGGED {
                truncated = true;
                break;
            }
            match c {
                '"' | '\\' => {
                    out.push('\\');
                    out.push(c);
                }
                c if (c as u32) < 0x20 || c as u32 == 0x7f => {
                    out.push_str(&format!("\\x{:02x}", c as u32));
                }
                c => out.push(c),
            }
        }
        if truncated {
            out.push('…');
        }
        out
    }

    /// Serve one connection: read the head, route it, write the reply. A `HEAD`
    /// request gets the identical headers with no body.
    ///
    /// Every phase is bounded in wall-clock time, so a connection cannot outlive
    /// `HEAD_READ_TIMEOUT` + one poll sweep + `RESPONSE_WRITE_TIMEOUT` however the
    /// peer behaves — which is what makes the `max_workers` permit pool an actual
    /// Slowloris guard rather than a Slowloris target.
    async fn handle_conn(mut sock: TcpStream, dash: &Dashboard, peer: &str) {
        let Some(head) = read_head(&mut sock, Instant::now() + HEAD_READ_TIMEOUT).await else {
            return;
        };
        let (method, target) = request_line(&head);
        let started = std::time::Instant::now();
        crate::exporter::registry().begin();
        let resp = dash.handle(&method, &target).await;
        let elapsed = started.elapsed().as_secs_f64();
        let action = crate::exporter::action_of(&target);
        crate::exporter::registry().end(resp.status, action, elapsed);
        if dash.config().verbose {
            if crawlcore::logfmt::format().is_json() {
                eprintln!(
                    "{}",
                    crawlcore::logfmt::request_line(
                        crate::exporter::PREFIX,
                        &crawlcore::logfmt::Request {
                            method: &method,
                            // Raw, not `log_token`-sanitised: the JSON encoder
                            // escapes the control characters `log_token` had to
                            // strip, so the machine-readable form can keep the
                            // exact bytes the peer sent without the forged-line
                            // risk that motivated `log_token` in the first place.
                            path: &target,
                            status: resp.status,
                            duration_ms: elapsed * 1000.0,
                            peer,
                            action,
                        }
                    )
                );
            } else {
                // Byte-identical to what this server has always printed.
                eprintln!(
                    "{peer} \"{} {}\" {}",
                    log_token(&method),
                    log_token(&target),
                    resp.status
                );
            }
        }
        // The write budget starts here, so a slow sweep never eats it.
        let deadline = Instant::now() + RESPONSE_WRITE_TIMEOUT;
        if !write_by(&mut sock, resp.head().as_bytes(), deadline).await {
            return;
        }
        if method != "HEAD"
            && !resp.body.is_empty()
            && !write_by(&mut sock, resp.body.as_bytes(), deadline).await
        {
            return;
        }
        let _ = timeout_at(deadline, sock.flush()).await;
    }

    /// Accept and serve connections until the listener errors.
    ///
    /// At most `config.max_workers` connections are handled at once (clamped to
    /// `MAX_CONNECTIONS`, beyond which the bound is meaningless anyway); a
    /// connection arriving with every slot taken is closed immediately rather
    /// than queued (Python's `BoundedSemaphore` + `shutdown_request` Slowloris
    /// guard).
    ///
    /// # Errors
    /// Propagates a fatal `accept()` error.
    pub async fn serve(listener: TcpListener, dash: Arc<Dashboard>) -> std::io::Result<()> {
        let max_conns = usize::try_from(dash.config().max_workers)
            .unwrap_or(1)
            .clamp(1, MAX_CONNECTIONS);
        let slots = Arc::new(Semaphore::new(max_conns));
        loop {
            let (sock, peer) = listener.accept().await?;
            let Ok(permit) = Arc::clone(&slots).try_acquire_owned() else {
                // Counted before the drop: a dashboard refusing connections at
                // its `max_workers` bound and a dashboard nobody is asking for
                // look identical in every other counter.
                crate::exporter::registry().reject();
                drop(sock); // over the connection bound: refuse, never queue
                continue;
            };
            let dash = Arc::clone(&dash);
            tokio::spawn(async move {
                let _permit = permit;
                handle_conn(sock, &dash, &peer.to_string()).await;
            });
        }
    }

    /// Bind `config.host:config.port` and serve the dashboard until the listener
    /// errors (Python `server.serve`).
    ///
    /// # Errors
    /// A bind failure, or a fatal `accept()` error from [`serve`].
    pub async fn serve_config(config: Config) -> std::io::Result<()> {
        let addr = format!("{}:{}", config.host, config.port);
        let listener = TcpListener::bind(&addr)
            .await
            .map_err(|e| std::io::Error::new(e.kind(), format!("cannot bind {addr}: {e}")))?;
        let bound = listener.local_addr()?;
        if config.verbose {
            println!(
                "suitedash serving {} service(s) at http://{}:{}/  (JSON: /api/status)",
                config.services.len(),
                bound.ip(),
                bound.port()
            );
        }
        serve(listener, Arc::new(Dashboard::new(config))).await
    }

    #[cfg(test)]
    mod tests {
        use super::{log_token, request_line, MAX_REQUEST_HEAD};

        /// A bare `\n` in the target used to survive `request_line` (which split
        /// on `"\r\n"` only) and reach `eprintln!`, where it forged a second,
        /// entirely attacker-written line in the request log.
        #[test]
        fn a_bare_newline_cannot_forge_a_second_log_line() {
            // The newline sits inside the target token itself (no space around
            // it), so splitting the head on "\r\n" alone kept it and `eprintln!`
            // printed `peer "GET /x` and then `127.0.0.1:1 "GET /admin" 200" 404`
            // — a second log line nothing downstream can tell from a real request.
            let head = "GET /x\n127.0.0.1:1 \"GET /admin\" 200 HTTP/1.1\r\nHost: h\r\n";
            let (method, target) = request_line(head);
            assert_eq!((method.as_str(), target.as_str()), ("GET", "/x"));
            let line = format!("peer \"{} {}\" 404", log_token(&method), log_token(&target));
            assert_eq!(line, "peer \"GET /x\" 404");
            assert!(!line.contains('\n'));
        }

        #[test]
        fn log_fields_are_escaped_and_bounded() {
            assert_eq!(log_token("/a\r\nb\tc\u{7f}"), "/a\\x0d\\x0ab\\x09c\\x7f");
            assert_eq!(log_token("/ok?x=1"), "/ok?x=1");
            // A 64 KiB request line must not become a 64 KiB log line.
            let long = log_token(&"a".repeat(MAX_REQUEST_HEAD));
            assert_eq!(long.chars().count(), 201);
            assert!(long.ends_with('…'));
        }

        #[test]
        fn the_request_line_ends_at_the_first_terminator() {
            assert_eq!(
                request_line("GET /api/status?x=1 HTTP/1.1\r\nHost: h"),
                ("GET".to_string(), "/api/status?x=1".to_string())
            );
            // No line at all, and no target: the Python defaults.
            assert_eq!(request_line(""), ("GET".to_string(), "/".to_string()));
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::{AlertRule, ServiceConfig};
    use crate::metrics::{ServiceResult, SurfacedMetrics};

    fn config() -> Config {
        Config {
            refresh_seconds: 7,
            services: vec![ServiceConfig::new("alpha", "http://127.0.0.1:9001")],
            alert_rules: vec![AlertRule {
                id: "any-down".to_string(),
                service: "*".to_string(),
                kind: "down".to_string(),
                for_polls: 1,
                severity: "critical".to_string(),
                description: "a service is down".to_string(),
                ..AlertRule::default()
            }],
            ..Config::default()
        }
    }

    fn sweep() -> Results {
        let mut up = ServiceResult::new("alpha", "http://127.0.0.1:9001", true);
        up.latency_ms = Some(2.5);
        up.checked_at = 1_723_000_000.0;
        up.health_path = Some("/health".to_string());
        up.metrics_raw = "alpha_requests_total 42\n".to_string();
        up.metrics_ctype = "text/plain".to_string();
        let mut m = SurfacedMetrics::new();
        m.insert("alpha_requests_total", Some(42.0));
        up.metrics = m;

        let mut down = ServiceResult::new("beta", "http://127.0.0.1:9002", false);
        down.error = Some("connection refused".to_string());
        down.checked_at = 1_723_000_000.0;

        let mut results = Results::new();
        results.insert("alpha", up);
        results.insert("beta", down);
        results
    }

    fn dash() -> Dashboard {
        Dashboard::new(config())
    }

    #[test]
    fn target_normalisation_matches_python() {
        assert_eq!(Route::of("/"), Route::Page);
        assert_eq!(Route::of(""), Route::Page);
        assert_eq!(Route::of("//"), Route::Page);
        assert_eq!(Route::of("/?x=1"), Route::Page);
        assert_eq!(Route::of("/api/status/"), Route::Status);
        assert_eq!(Route::of("/metrics?debug=1"), Route::Metrics);
        assert_eq!(Route::of("/healthz"), Route::Health);
        assert_eq!(Route::of("/favicon.ico"), Route::Favicon);
        assert_eq!(Route::of("/nope"), Route::NotFound);
        assert_eq!(Route::of("/api"), Route::NotFound);
    }

    #[test]
    fn only_the_rendering_routes_poll() {
        assert!(Route::Page.needs_poll());
        assert!(Route::Status.needs_poll());
        assert!(Route::Metrics.needs_poll());
        assert!(!Route::Health.needs_poll());
        assert!(!Route::Favicon.needs_poll());
        assert!(!Route::NotFound.needs_poll());
    }

    #[test]
    fn page_route_renders_the_sweep() {
        let d = dash();
        let r = d.route("GET", "/", &sweep(), 1_723_000_000.0);
        assert_eq!(r.status, 200);
        assert_eq!(r.ctype, "text/html; charset=utf-8");
        assert!(r.body.contains("<!doctype html>"));
        assert!(r.body.contains("alpha_requests_total"));
        assert!(r.body.contains("1 of 2 services DOWN"));
        assert!(r
            .body
            .contains("<meta http-equiv=\"refresh\" content=\"7\">"));
        assert!(!r.body.contains("<script"));
    }

    #[test]
    fn status_route_is_json_and_carries_alert_state() {
        let d = dash();
        d.monitor().ingest(&sweep(), 1_723_000_000.0);
        let r = d.route("GET", "/api/status", &sweep(), 1_723_000_000.0);
        assert_eq!(r.ctype, "application/json; charset=utf-8");
        assert!(r.body.contains("\"total\": 2"));
        assert!(r.body.contains("\"alpha_requests_total\": 42"));
        assert!(r.body.contains("\"alerts\""));
        assert!(r.body.contains("\"rule\": \"any-down\""));
        assert!(r.body.contains("\"firing\": true"));
    }

    #[test]
    fn metrics_route_federates_and_never_polls_upstream_names_into_ours() {
        let r = dash().route("GET", "/metrics", &sweep(), 0.0);
        assert_eq!(r.ctype, METRICS_CONTENT_TYPE);
        assert!(r.body.contains("suitedash_up 1"));
        assert!(r.body.contains("suitedash_service_up{service=\"alpha\"} 1"));
        assert!(r.body.contains("suitedash_service_up{service=\"beta\"} 0"));
        assert!(r
            .body
            .contains("alpha_requests_total{service=\"alpha\"} 42"));
    }

    #[test]
    fn health_favicon_not_found_and_bad_method() {
        let d = dash();
        let empty = Results::new();
        let health = d.route("GET", "/healthz", &empty, 0.0);
        assert_eq!((health.status, health.body.as_str()), (200, "ok\n"));
        let icon = d.route("GET", "/favicon.ico", &empty, 0.0);
        assert_eq!((icon.status, icon.ctype), (204, "image/x-icon"));
        assert!(icon.body.is_empty());
        assert_eq!(d.route("GET", "/nope", &empty, 0.0).status, 404);
        assert_eq!(d.route("POST", "/", &empty, 0.0).status, 501);
        // HEAD renders exactly like GET; the writer is what drops the body.
        assert_eq!(
            d.route("HEAD", "/healthz", &empty, 0.0),
            d.route("GET", "/healthz", &empty, 0.0)
        );
    }

    #[test]
    fn every_response_carries_the_security_headers() {
        let head = dash().route("GET", "/", &sweep(), 0.0).head();
        assert!(head.starts_with("HTTP/1.1 200 OK\r\n"));
        assert!(head.contains("X-Content-Type-Options: nosniff\r\n"));
        assert!(head.contains("X-Frame-Options: DENY\r\n"));
        assert!(head.contains("Referrer-Policy: no-referrer\r\n"));
        assert!(head.contains("Cache-Control: no-store\r\n"));
        assert!(head.contains("Connection: close\r\n"));
        assert!(head.contains(&format!("Content-Security-Policy: {CSP}\r\n")));
        assert!(head.ends_with("\r\n\r\n"));
        let icon = dash()
            .route("GET", "/favicon.ico", &Results::new(), 0.0)
            .head();
        assert!(icon.starts_with("HTTP/1.1 204 No Content\r\n"));
        assert!(icon.contains("Content-Length: 0\r\n"));
    }

    #[test]
    fn content_length_is_the_body_byte_length() {
        let r = Resp::text(200, "caf\u{e9}\n"); // 5 bytes, 4 chars
        assert!(r.head().contains("Content-Length: 6\r\n"));
    }

    #[test]
    fn hostile_service_names_are_escaped_on_every_rendered_route() {
        let hostile = "<script>x</script>&\"";
        let cfg = Config {
            services: vec![ServiceConfig::new(hostile, "http://127.0.0.1:9003")],
            ..Config::default()
        };
        let mut results = Results::new();
        results.insert(hostile, ServiceResult::new(hostile, "http://x", false));
        let d = Dashboard::new(cfg);
        let page = d.route("GET", "/", &results, 0.0).body;
        assert!(page.contains("&lt;script&gt;x&lt;/script&gt;&amp;&quot;"));
        assert!(!page.contains("<script>x</script>"));
        let metrics = d.route("GET", "/metrics", &results, 0.0).body;
        assert!(metrics.contains("suitedash_service_up{service=\"<script>x</script>&\\\"\"} 0"));
    }

    #[test]
    fn ttl_cache_is_off_by_default_and_bounded_when_on() {
        let d = dash(); // cache_ttl = 0
        d.cache(&sweep());
        assert!(d.cached().is_none());

        let cached = Dashboard::new(Config {
            cache_ttl: 30.0,
            ..config()
        });
        assert!(cached.cached().is_none()); // nothing stored yet
        cached.cache(&sweep());
        assert_eq!(cached.cached().map(|r| r.len()), Some(2));
    }

    #[test]
    fn hostile_durations_are_clamped_not_panics() {
        assert_eq!(config_duration(-5.0), Duration::ZERO);
        assert_eq!(config_duration(f64::NAN), Duration::ZERO);
        assert_eq!(config_duration(f64::INFINITY), Duration::ZERO);
        assert_eq!(
            config_duration(1e300),
            Duration::from_secs_f64(MAX_CONFIG_SECONDS)
        );
        let d = Dashboard::new(Config {
            timeout_seconds: -1.0,
            cache_ttl: f64::NAN,
            ..config()
        });
        assert_eq!(d.probe_timeout(), Duration::ZERO);
        assert!(d.cache_ttl().is_zero());
    }
}
