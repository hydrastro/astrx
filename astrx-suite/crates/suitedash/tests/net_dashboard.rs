//! Loopback end-to-end tests for the `net` tier: the tolerant probe, the bounded
//! concurrent poller, and the dashboard served over a real socket.
//!
//! A port of the Python `tests/mockservice.py` + `test_probe.py` +
//! `test_poller.py` + `test_server.py` / `test_features_server.py`: every test
//! stands up mock services on `127.0.0.1:0` (a healthy Prometheus one, a JSON one
//! whose health is only reachable through a fallback path, one that 500s, a
//! black hole that never answers in time, a byte-dribbling one, and a port with
//! nothing listening), then asserts UP/DOWN, latency, surfaced metrics, that a
//! hung service never stalls the sweep, and that the page, `/api/status` and
//! `/metrics` render over the real polled results. Fully offline and hermetic.
#![cfg(feature = "net")]

use std::collections::HashMap;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

use suitedash::config::{AlertRule, Config, ServiceConfig};
use suitedash::poller::poll_all;
use suitedash::probe::{fetch, probe_service, ProbeError};
use suitedash::server::serve;
use suitedash::{summarize, Dashboard};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

// --------------------------------------------------------------------------- //
// Mock services (the port of tests/mockservice.py)
// --------------------------------------------------------------------------- //

/// One canned response, optionally slow or dribbled.
#[derive(Clone, Debug)]
struct Reply {
    status: u16,
    ctype: String,
    body: Vec<u8>,
    headers: Vec<(String, String)>,
    /// Sleep this long before answering at all (the black-hole case).
    sleep: Duration,
    /// Send the body one byte per interval (a slow-drip peer whose every read
    /// lands inside a sane socket timeout).
    drip: Duration,
    /// Dribble the status line + header block one byte per interval, before any
    /// body — only a total wall-clock deadline over the *header* read reaps it.
    head_drip: Duration,
}

impl Reply {
    fn new(status: u16, ctype: &str, body: &str) -> Self {
        Reply {
            status,
            ctype: ctype.to_string(),
            body: body.as_bytes().to_vec(),
            headers: Vec::new(),
            sleep: Duration::ZERO,
            drip: Duration::ZERO,
            head_drip: Duration::ZERO,
        }
    }
    fn ok(body: &str) -> Self {
        Reply::new(200, "text/plain; charset=utf-8", body)
    }
    fn header(mut self, name: &str, value: &str) -> Self {
        self.headers.push((name.to_string(), value.to_string()));
        self
    }
    fn sleeping(mut self, secs: f64) -> Self {
        self.sleep = Duration::from_secs_f64(secs);
        self
    }
    fn dripping(mut self, secs: f64) -> Self {
        self.drip = Duration::from_secs_f64(secs);
        self
    }
    fn head_dripping(mut self, secs: f64) -> Self {
        self.head_drip = Duration::from_secs_f64(secs);
        self
    }
}

/// A running mock service on loopback.
struct Mock {
    port: u16,
    handle: tokio::task::JoinHandle<()>,
}

impl Mock {
    fn base_url(&self) -> String {
        format!("http://127.0.0.1:{}", self.port)
    }
    fn stop(self) {
        self.handle.abort();
    }
}

/// Stand up a mock service answering `routes`, falling back to `catch_all` (or a
/// 404) for anything else.
async fn spawn_service(routes: &[(&str, Reply)], catch_all: Option<Reply>) -> Mock {
    let table: HashMap<String, Reply> = routes
        .iter()
        .map(|(p, r)| ((*p).to_string(), r.clone()))
        .collect();
    let listener = TcpListener::bind("127.0.0.1:0").await.expect("bind mock");
    let port = listener.local_addr().expect("mock addr").port();
    let handle = tokio::spawn(async move {
        loop {
            let Ok((mut sock, _)) = listener.accept().await else {
                return;
            };
            let table = table.clone();
            let catch_all = catch_all.clone();
            tokio::spawn(async move {
                let path = read_request_path(&mut sock).await;
                let path = path.split('?').next().unwrap_or("").to_string();
                let reply = table
                    .get(&path)
                    .cloned()
                    .or(catch_all)
                    .unwrap_or_else(|| Reply::new(404, "text/plain; charset=utf-8", "nope"));
                respond(sock, reply).await;
            });
        }
    });
    Mock { port, handle }
}

/// A loopback port with nothing listening, so connecting to it is refused.
async fn free_port() -> u16 {
    let l = TcpListener::bind("127.0.0.1:0").await.expect("bind probe");
    let port = l.local_addr().expect("probe addr").port();
    drop(l);
    port
}

fn find(hay: &[u8], needle: &[u8]) -> Option<usize> {
    if hay.len() < needle.len() {
        return None;
    }
    (0..=hay.len() - needle.len()).find(|&i| &hay[i..i + needle.len()] == needle)
}

async fn read_request_path(sock: &mut TcpStream) -> String {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 1024];
    while find(&buf, b"\r\n\r\n").is_none() {
        match sock.read(&mut tmp).await {
            Ok(0) | Err(_) => break,
            Ok(n) => buf.extend_from_slice(&tmp[..n]),
        }
    }
    let end = find(&buf, b"\r\n").unwrap_or(buf.len());
    String::from_utf8_lossy(&buf[..end])
        .split(' ')
        .nth(1)
        .unwrap_or("/")
        .to_string()
}

/// A valid HTTP/1.1 head, padded to at least `min_len` bytes with one long
/// harmless header — so a byte-per-`head_drip` dribble of it runs for
/// `min_len * head_drip` seconds if the client never reaps it.
fn padded_head(reply: &Reply, min_len: usize) -> Vec<u8> {
    let mut head = format!(
        "HTTP/1.1 {} OK\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: close\r\n",
        reply.status,
        reply.ctype,
        reply.body.len()
    );
    let pad = min_len.saturating_sub(head.len() + "X-Pad: \r\n\r\n".len());
    if pad > 0 {
        head.push_str(&format!("X-Pad: {}\r\n", "a".repeat(pad)));
    }
    head.push_str("\r\n");
    head.into_bytes()
}

async fn respond(mut sock: TcpStream, reply: Reply) {
    if !reply.sleep.is_zero() {
        tokio::time::sleep(reply.sleep).await;
    }
    if !reply.head_drip.is_zero() {
        for byte in padded_head(&reply, 200) {
            tokio::time::sleep(reply.head_drip).await;
            if sock.write_all(&[byte]).await.is_err() {
                return; // the client gave up at its deadline — expected
            }
        }
        let _ = sock.write_all(&reply.body).await;
        return;
    }
    let mut head = format!("HTTP/1.1 {} OK\r\n", reply.status);
    for (k, v) in &reply.headers {
        head.push_str(&format!("{k}: {v}\r\n"));
    }
    head.push_str(&format!(
        "Content-Type: {}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
        reply.ctype,
        reply.body.len()
    ));
    if sock.write_all(head.as_bytes()).await.is_err() {
        return;
    }
    if reply.drip.is_zero() {
        let _ = sock.write_all(&reply.body).await;
        return;
    }
    for byte in &reply.body {
        tokio::time::sleep(reply.drip).await;
        if sock.write_all(&[*byte]).await.is_err() {
            return;
        }
    }
}

// --- canned service flavours (mockservice.py's) ----------------------------- //

const PROM_METRICS: &str = concat!(
    "# HELP alpha_requests_total Total requests served.\n",
    "# TYPE alpha_requests_total counter\n",
    "alpha_requests_total 42\n",
    "\n", // blank line tolerated
    "# TYPE alpha_uptime_seconds gauge\n",
    "alpha_uptime_seconds 123.5\n",
    "alpha_responses_total{status=\"200\"} 40\n",
    "alpha_responses_total{status=\"404\"} 2\n",
    "alpha_broken NaN\n", // non-finite -> dropped
);

const JSON_STATS: &str = concat!(
    r#"{"docs": 1000, "hosts": 25, "ok": true, "ratio": "0.5", "#,
    r#""tags": ["a", "b"], "nothing": null, "#,
    r#""queue": {"pending": 7, "done": 300}}"#,
);

/// Healthy service: `/health` -> ok, `/metrics` -> Prometheus text.
async fn prometheus_service() -> Mock {
    spawn_service(
        &[
            ("/health", Reply::ok("ok\n")),
            (
                "/metrics",
                Reply::new(200, "text/plain; version=0.0.4", PROM_METRICS),
            ),
        ],
        None,
    )
    .await
}

/// Healthy service whose health is only reachable via a fallback path, and whose
/// metrics are JSON at `/api/stats`.
async fn json_service() -> Mock {
    spawn_service(
        // /health intentionally absent (404) so the prober must fall back.
        &[(
            "/api/stats",
            Reply::new(200, "application/json", JSON_STATS),
        )],
        None,
    )
    .await
}

/// Black hole: every path sleeps far past any sane timeout before replying.
async fn slow_service(secs: f64) -> Mock {
    spawn_service(&[], Some(Reply::ok("late").sleeping(secs))).await
}

/// Every path answers 500 — alive, but never healthy.
async fn broken_service() -> Mock {
    spawn_service(
        &[],
        Some(Reply::new(500, "text/plain; charset=utf-8", "boom")),
    )
    .await
}

// --------------------------------------------------------------------------- //
// probe: the tolerant, bounded fetch
// --------------------------------------------------------------------------- //

#[tokio::test]
async fn fetch_returns_status_body_and_latency() {
    let svc = prometheus_service().await;
    let r = fetch(&svc.base_url(), "/health", Duration::from_secs(2))
        .await
        .expect("fetch ok");
    assert_eq!(r.status, 200);
    assert_eq!(r.body, b"ok\n");
    assert!(r.latency_ms >= 0.0);
    svc.stop();
}

#[tokio::test]
async fn fetch_never_follows_a_redirect() {
    // follow_location=0 posture: a 3xx is returned as-is, never chased.
    let svc = spawn_service(
        &[(
            "/redir",
            Reply::new(302, "text/plain", "").header("Location", "http://127.0.0.1:1/x"),
        )],
        None,
    )
    .await;
    let r = fetch(&svc.base_url(), "/redir", Duration::from_secs(2))
        .await
        .expect("fetch ok");
    assert_eq!(r.status, 302);
    svc.stop();
}

#[tokio::test]
async fn fetch_rejects_unsupported_schemes() {
    let ftp = fetch("ftp://127.0.0.1", "/x", Duration::from_secs(1)).await;
    assert!(matches!(ftp, Err(ProbeError::Value(ref m)) if m.contains("unsupported scheme")));
    // Documented divergence: the suite ships no TLS, so https is refused loudly
    // instead of being probed.
    let https = fetch("https://127.0.0.1", "/x", Duration::from_secs(1)).await;
    assert!(matches!(https, Err(ProbeError::Value(ref m)) if m.contains("TLS")));
    // A base URL with no host is a config error, not a hang.
    let hostless = fetch("http:///nothing", "/x", Duration::from_secs(1)).await;
    assert!(matches!(hostless, Err(ProbeError::Value(ref m)) if m.contains("no host")));
}

#[tokio::test]
async fn fetch_of_a_dead_port_is_refused_fast() {
    let port = free_port().await;
    let started = Instant::now();
    let err = fetch(
        &format!("http://127.0.0.1:{port}"),
        "/health",
        Duration::from_secs(2),
    )
    .await
    .expect_err("nothing is listening");
    assert_eq!(err, ProbeError::Refused);
    assert!(err.is_fatal());
    assert!(started.elapsed() < Duration::from_secs(1));
}

#[tokio::test]
async fn a_smuggled_probe_path_never_reaches_the_wire() {
    // `health_path`/`metrics_path` are interpolated into the request line, so a
    // CRLF in one makes a single probe emit TWO complete HTTP requests — the
    // second one written entirely by whoever wrote the config value. The config
    // loader rejects such a path, but `ServiceConfig` is public, so `fetch`
    // refuses too — before it opens a socket.
    let accepts = Arc::new(AtomicUsize::new(0));
    let listener = TcpListener::bind("127.0.0.1:0")
        .await
        .expect("bind counter");
    let port = listener.local_addr().expect("counter addr").port();
    let seen = Arc::clone(&accepts);
    let counter = tokio::spawn(async move {
        while listener.accept().await.is_ok() {
            seen.fetch_add(1, Ordering::SeqCst);
        }
    });
    let base = format!("http://127.0.0.1:{port}");

    for path in [
        "/health HTTP/1.1\r\nHost: attacker\r\n\r\nGET /admin",
        "/metrics\r\nX-Forged: 1",
        "/a b",
    ] {
        let err = fetch(&base, path, Duration::from_secs(2))
            .await
            .expect_err("a smuggling path must be refused");
        assert!(
            matches!(err, ProbeError::Value(_)),
            "expected a refusal, got {err:?}"
        );
        assert!(err.is_fatal());
    }
    // A hostile base_url path lands in the same request line and is refused too.
    let err = fetch(
        &format!("{base}/x HTTP/1.1\r\nHost: attacker\r\n\r\nGET /admin"),
        "/health",
        Duration::from_secs(2),
    )
    .await
    .expect_err("a smuggling base_url must be refused");
    assert!(matches!(err, ProbeError::Value(_)), "got {err:?}");

    // The whole probe degrades to a visible DOWN rather than smuggling…
    let cfg = ServiceConfig {
        health_path: "/health HTTP/1.1\r\nHost: attacker\r\n\r\nGET /admin".to_string(),
        ..ServiceConfig::new("alpha", &base)
    };
    let r = probe_service(&cfg, Duration::from_secs(2)).await;
    assert!(!r.up);
    assert!(
        r.error.unwrap_or_default().contains("request path"),
        "the card must say why"
    );
    // …and nothing ever connected to the target.
    assert_eq!(accepts.load(Ordering::SeqCst), 0);
    counter.abort();
}

#[tokio::test]
async fn slow_drip_body_is_reaped_by_the_total_deadline() {
    // A backend that dribbles the body one byte per <timeout window keeps every
    // read alive, so a per-read timeout never fires: only the TOTAL wall-clock
    // deadline reaps it. 200 bytes * 0.1s = ~20s if it is never reaped.
    let svc = spawn_service(&[], Some(Reply::ok(&"x".repeat(200)).dripping(0.1))).await;
    let timeout = Duration::from_millis(500);
    let started = Instant::now();
    let err = fetch(&svc.base_url(), "/metrics", timeout)
        .await
        .expect_err("the drip must not complete");
    assert_eq!(err, ProbeError::Timeout);
    assert!(
        started.elapsed() <= 2 * timeout,
        "slow-drip fetch was not reaped near the timeout ({:?})",
        started.elapsed()
    );
    svc.stop();
}

#[tokio::test]
async fn slow_drip_headers_are_reaped_by_the_total_deadline() {
    // Same attack against the STATUS LINE + HEADER block, which Python needs a
    // watchdog thread to bound; here the header read races the same deadline.
    let svc = spawn_service(&[], Some(Reply::ok("ok").head_dripping(0.1))).await;
    let timeout = Duration::from_millis(500);
    let started = Instant::now();
    let err = fetch(&svc.base_url(), "/metrics", timeout)
        .await
        .expect_err("the header drip must not complete");
    assert_eq!(err, ProbeError::Timeout);
    assert!(
        started.elapsed() <= 2 * timeout,
        "slow-drip HEADER fetch was not reaped near the timeout ({:?})",
        started.elapsed()
    );
    svc.stop();
}

#[tokio::test]
async fn a_header_dribbling_backend_probes_down_within_the_budget() {
    // The whole probe (not just fetch) must degrade a header-dribbling backend to
    // a bounded DOWN result — this is what keeps one hostile engine from wedging
    // the shared probe pool.
    let svc = spawn_service(&[], Some(Reply::ok("ok").head_dripping(0.1))).await;
    let timeout = Duration::from_millis(500);
    let cfg = ServiceConfig::new("drip", svc.base_url());
    let started = Instant::now();
    let r = probe_service(&cfg, timeout).await;
    let elapsed = started.elapsed();
    assert!(!r.up);
    assert_eq!(r.error.as_deref(), Some("timeout"));
    // Health tries the configured path + fallbacks within ONE timeout budget, so
    // the whole probe stays bounded by a small multiple of it.
    assert!(
        elapsed <= 3 * timeout,
        "probe_service hung on a header-dribbling backend ({elapsed:?})"
    );
    svc.stop();
}

// --------------------------------------------------------------------------- //
// probe: one service, end to end
// --------------------------------------------------------------------------- //

#[tokio::test]
async fn probes_up_with_prometheus_metrics() {
    let svc = prometheus_service().await;
    let cfg = ServiceConfig {
        metrics_keys: vec![
            "alpha_requests_total".to_string(),
            "alpha_uptime_seconds".to_string(),
        ],
        ..ServiceConfig::new("alpha", svc.base_url())
    };
    let r = probe_service(&cfg, Duration::from_secs(2)).await;
    assert!(r.up);
    assert_eq!(r.health_path.as_deref(), Some("/health"));
    assert_eq!(r.metrics.get("alpha_requests_total"), Some(&Some(42.0)));
    assert_eq!(r.metrics.get("alpha_uptime_seconds"), Some(&Some(123.5)));
    assert!(r.latency_ms.is_some_and(|ms| ms >= 0.0));
    assert!(r.error.is_none());
    // The raw text is retained for the federation exporter.
    assert!(r.metrics_raw.contains("alpha_requests_total 42"));
    svc.stop();
}

#[tokio::test]
async fn probes_up_via_a_health_fallback_with_json_metrics() {
    let svc = json_service().await;
    let cfg = ServiceConfig {
        // /health 404s -> the prober must fall through to /api/stats.
        health_path: "/health".to_string(),
        metrics_path: "/api/stats".to_string(),
        metrics_keys: vec![
            "docs".to_string(),
            "queue_pending".to_string(),
            "ratio".to_string(),
        ],
        ..ServiceConfig::new("beta", svc.base_url())
    };
    let r = probe_service(&cfg, Duration::from_secs(2)).await;
    assert!(r.up);
    assert_eq!(r.health_path.as_deref(), Some("/api/stats"));
    assert_eq!(r.metrics.get("docs"), Some(&Some(1000.0)));
    assert_eq!(r.metrics.get("queue_pending"), Some(&Some(7.0)));
    assert_eq!(r.metrics.get("ratio"), Some(&Some(0.5)));
    svc.stop();
}

#[tokio::test]
async fn probes_down_on_a_refused_connection() {
    let cfg = ServiceConfig::new("gamma", format!("http://127.0.0.1:{}", free_port().await));
    let r = probe_service(&cfg, Duration::from_secs(1)).await;
    assert!(!r.up);
    assert!(r.latency_ms.is_none());
    assert_eq!(r.error.as_deref(), Some("connection refused"));
    assert!(r.health_path.is_none());
}

#[tokio::test]
async fn a_service_that_only_500s_is_down_with_the_last_status() {
    let svc = broken_service().await;
    let cfg = ServiceConfig::new("boom", svc.base_url());
    let r = probe_service(&cfg, Duration::from_secs(2)).await;
    assert!(!r.up);
    assert_eq!(r.error.as_deref(), Some("http 500"));
    assert!(r.metrics.is_empty());
    svc.stop();
}

#[tokio::test]
async fn a_missing_metric_key_surfaces_as_none() {
    let svc = prometheus_service().await;
    let cfg = ServiceConfig {
        metrics_keys: vec![
            "alpha_requests_total".to_string(),
            "does_not_exist".to_string(),
        ],
        ..ServiceConfig::new("alpha", svc.base_url())
    };
    let r = probe_service(&cfg, Duration::from_secs(2)).await;
    assert_eq!(r.metrics.get("alpha_requests_total"), Some(&Some(42.0)));
    assert_eq!(r.metrics.get("does_not_exist"), Some(&None));
    svc.stop();
}

// --------------------------------------------------------------------------- //
// poller: concurrent, bounded, order-preserving
// --------------------------------------------------------------------------- //

/// The four-service fixture the Python poller tests use: healthy Prometheus,
/// healthy JSON (via a fallback), a refused port and a black hole.
struct Fixture {
    prom: Mock,
    json: Mock,
    slow: Mock,
    services: Vec<ServiceConfig>,
}

async fn fixture() -> Fixture {
    let prom = prometheus_service().await;
    let json = json_service().await;
    let slow = slow_service(5.0).await;
    let services = vec![
        ServiceConfig {
            metrics_keys: vec!["alpha_requests_total".to_string()],
            ..ServiceConfig::new("alpha", prom.base_url())
        },
        ServiceConfig {
            metrics_path: "/api/stats".to_string(),
            metrics_keys: vec!["docs".to_string(), "queue_pending".to_string()],
            ..ServiceConfig::new("beta", json.base_url())
        },
        ServiceConfig::new("gamma", format!("http://127.0.0.1:{}", free_port().await)),
        ServiceConfig::new("delta", slow.base_url()),
    ];
    Fixture {
        prom,
        json,
        slow,
        services,
    }
}

impl Fixture {
    fn stop(self) {
        self.prom.stop();
        self.json.stop();
        self.slow.stop();
    }
}

#[tokio::test]
async fn poll_reports_up_down_metrics_and_order() {
    let f = fixture().await;
    let timeout = Duration::from_millis(600);
    let results = poll_all(&f.services, timeout, 0).await;

    assert_eq!(
        results.keys().collect::<Vec<_>>(),
        vec!["alpha", "beta", "gamma", "delta"]
    );
    assert!(results.get("alpha").expect("alpha").up);
    assert!(results.get("beta").expect("beta").up);
    assert!(!results.get("gamma").expect("gamma").up); // refused
    assert!(!results.get("delta").expect("delta").up); // timed out

    // Both parsers are represented in the surfaced numbers.
    assert_eq!(
        results
            .get("alpha")
            .and_then(|r| r.metrics.get("alpha_requests_total")),
        Some(&Some(42.0))
    );
    assert_eq!(
        results.get("beta").and_then(|r| r.metrics.get("docs")),
        Some(&Some(1000.0))
    );
    assert_eq!(
        results
            .get("beta")
            .and_then(|r| r.metrics.get("queue_pending")),
        Some(&Some(7.0))
    );
    // Latency is present for an UP service and absent for a DOWN one.
    assert!(results.get("alpha").expect("alpha").latency_ms.is_some());
    assert!(results.get("delta").expect("delta").latency_ms.is_none());

    let s = summarize(&results);
    assert_eq!((s.total, s.up, s.down, s.all_up), (4, 2, 2, false));
    f.stop();
}

#[tokio::test]
async fn a_black_hole_never_stalls_the_sweep() {
    let f = fixture().await;
    let timeout = Duration::from_millis(600);
    let started = Instant::now();
    let results = poll_all(&f.services, timeout, 0).await;
    let elapsed = started.elapsed();
    // Concurrent probes -> the whole sweep is ~one timeout, never the sum, and it
    // never waits on the 5s straggler.
    assert!(
        elapsed < timeout + Duration::from_millis(1500),
        "poll_all did not stay bounded ({elapsed:?})"
    );
    let delta = results.get("delta").expect("delta");
    assert!(!delta.up);
    assert_eq!(delta.error.as_deref(), Some("timeout"));
    f.stop();
}

#[tokio::test]
async fn an_empty_service_list_polls_to_an_empty_sweep() {
    let results = poll_all(&[], Duration::from_millis(100), 0).await;
    assert!(results.is_empty());
    let s = summarize(&results);
    assert_eq!((s.total, s.up, s.down, s.all_up), (0, 0, 0, true));
}

// --------------------------------------------------------------------------- //
// server: the dashboard over a real socket
// --------------------------------------------------------------------------- //

/// A minimal HTTP/1.1 client: returns `(status, head, body)`.
async fn http_get(port: u16, path: &str) -> (u16, String, String) {
    http_request(port, "GET", path).await
}

async fn http_request(port: u16, method: &str, path: &str) -> (u16, String, String) {
    let mut sock = TcpStream::connect(("127.0.0.1", port))
        .await
        .expect("connect to the dashboard");
    sock.write_all(
        format!("{method} {path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n")
            .as_bytes(),
    )
    .await
    .expect("write request");
    let mut raw = Vec::new();
    sock.read_to_end(&mut raw).await.expect("read response");
    let text = String::from_utf8_lossy(&raw).into_owned();
    let (head, body) = text.split_once("\r\n\r\n").unwrap_or((text.as_str(), ""));
    let status = head
        .split("\r\n")
        .next()
        .and_then(|line| line.split(' ').nth(1))
        .and_then(|code| code.parse::<u16>().ok())
        .unwrap_or(0);
    (status, head.to_string(), body.to_string())
}

/// A dashboard over `services` + `rules`, bound to an ephemeral loopback port.
async fn spawn_dashboard(
    services: Vec<ServiceConfig>,
    rules: Vec<AlertRule>,
) -> (u16, DashboardHandle) {
    let config = Config {
        host: "127.0.0.1".to_string(),
        port: 0,
        refresh_seconds: 7,
        timeout_seconds: 0.6,
        verbose: false,
        services,
        alert_rules: rules,
        ..Config::default()
    };
    let listener = TcpListener::bind("127.0.0.1:0")
        .await
        .expect("bind dashboard");
    let port = listener.local_addr().expect("dashboard addr").port();
    let handle = tokio::spawn(serve(listener, Arc::new(Dashboard::new(config))));
    (port, DashboardHandle(handle))
}

/// A handle that aborts the dashboard's accept loop when the test ends.
struct DashboardHandle(tokio::task::JoinHandle<std::io::Result<()>>);

impl DashboardHandle {
    fn stop(self) {
        self.0.abort();
    }
}

/// The full dashboard fixture: two live services, a refused one whose name is
/// hostile, a black hole, and two alert rules.
async fn dashboard_fixture() -> (u16, DashboardHandle, Fixture) {
    let f = fixture().await;
    let mut services = f.services.clone();
    services[0].label = "prometheus mock".to_string();
    services[0].metrics_keys = vec![
        "alpha_requests_total".to_string(),
        "alpha_uptime_seconds".to_string(),
    ];
    // A hostile service name, to prove HTML + Prometheus-label escaping.
    services[2].name = "<script>x</script>&\"".to_string();
    let rules = vec![
        AlertRule {
            id: "alpha-busy".to_string(),
            service: "alpha".to_string(),
            kind: "metric".to_string(),
            metric: "alpha_requests_total".to_string(),
            op: ">".to_string(),
            threshold: 0.0,
            for_polls: 1,
            severity: "warning".to_string(),
            description: "alpha is serving requests".to_string(),
        },
        AlertRule {
            id: "any-down".to_string(),
            service: "*".to_string(),
            kind: "down".to_string(),
            for_polls: 1,
            severity: "critical".to_string(),
            description: "a service is down".to_string(),
            ..AlertRule::default()
        },
    ];
    let (port, dash) = spawn_dashboard(services, rules).await;
    (port, dash, f)
}

#[tokio::test]
async fn the_page_renders_badges_metrics_and_stays_bounded() {
    let (port, dash, f) = dashboard_fixture().await;
    let started = Instant::now();
    let (status, head, body) = http_get(port, "/").await;
    let elapsed = started.elapsed();
    assert_eq!(status, 200);
    assert!(head.contains("Content-Type: text/html; charset=utf-8"));
    assert!(body.contains(">UP<"));
    assert!(body.contains(">DOWN<"));
    assert!(body.contains("alpha_requests_total"));
    assert!(body.contains("42")); // the Prometheus value
    assert!(body.contains("1,000")); // the JSON value, thousands-formatted
    assert!(body.contains("2 of 4 services DOWN"));
    assert!(body.contains("<meta http-equiv=\"refresh\" content=\"7\">"));
    // timeout 0.6 + slack; the page must not wait on the 5s black hole.
    assert!(
        elapsed < Duration::from_millis(2500),
        "the page did not render within the bound ({elapsed:?})"
    );

    // Security headers on every response.
    assert!(head.contains("default-src 'none'"));
    assert!(head.contains("X-Content-Type-Options: nosniff"));
    assert!(head.contains("X-Frame-Options: DENY"));
    assert!(head.contains("Referrer-Policy: no-referrer"));

    // Hostile service names never reach the page unescaped.
    assert!(body.contains("&lt;script&gt;x&lt;/script&gt;&amp;&quot;"));
    assert!(!body.contains("<script>x</script>"));

    dash.stop();
    f.stop();
}

#[tokio::test]
async fn api_status_matches_the_polled_results() {
    let (port, dash, f) = dashboard_fixture().await;
    let (status, head, body) = http_get(port, "/api/status").await;
    assert_eq!(status, 200);
    assert!(head.contains("Content-Type: application/json; charset=utf-8"));

    // Shape (the byte-for-byte JSON writer is cross-checked in xcheck_render).
    assert!(body.contains("\"total\": 4"));
    assert!(body.contains("\"down\": 2"));
    assert!(body.contains("\"alpha_requests_total\": 42"));
    assert!(body.contains("\"docs\": 1000"));
    assert!(body.contains("\"health_path\": \"/api/stats\"")); // beta's fallback
    assert!(body.contains("\"error\": \"timeout\"")); // the black hole
                                                      // Alert state rides along: the metric rule and the down rule both fire.
    assert!(body.contains("\"rule\": \"alpha-busy\""));
    assert!(body.contains("\"rule\": \"any-down\""));
    assert!(body.contains("\"last_value\": 42"));
    // The hostile name is JSON-escaped, never raw.
    assert!(body.contains("<script>x</script>&\\\""));

    dash.stop();
    f.stop();
}

#[tokio::test]
async fn metrics_endpoint_federates_the_polled_upstreams() {
    let (port, dash, f) = dashboard_fixture().await;
    let (status, head, body) = http_get(port, "/metrics").await;
    assert_eq!(status, 200);
    assert!(head.contains("Content-Type: text/plain; version=0.0.4"));
    assert!(body.contains("suitedash_up 1"));
    assert!(body.contains("suitedash_service_up{service=\"alpha\"} 1"));
    assert!(body.contains("suitedash_service_up{service=\"delta\"} 0"));
    // A real upstream series, relabelled with service="alpha".
    assert!(body.contains("alpha_requests_total{service=\"alpha\"} 42"));
    assert!(body.contains("alpha_responses_total{service=\"alpha\",status=\"200\"} 40"));
    // The JSON upstream is federated too.
    assert!(body.contains("docs{service=\"beta\"} 1000"));
    // The hostile service name is escaped inside the label value.
    assert!(body.contains("service=\"<script>x</script>&\\\"\""));
    assert!(!body.contains("service=\"<script>x</script>&\"\""));

    dash.stop();
    f.stop();
}

#[tokio::test]
async fn healthz_favicon_404_and_head_do_not_poll() {
    let (port, dash) = spawn_dashboard(
        // A black-hole-only service list: if these routes polled, they would take
        // the full timeout; they must answer instantly instead.
        vec![ServiceConfig::new(
            "delta",
            format!("http://127.0.0.1:{}", free_port().await),
        )],
        Vec::new(),
    )
    .await;

    let (status, _, body) = http_get(port, "/healthz").await;
    assert_eq!((status, body.trim()), (200, "ok"));

    let (status, head, body) = http_get(port, "/favicon.ico").await;
    assert_eq!(status, 204);
    assert!(head.contains("Content-Length: 0"));
    assert!(body.is_empty());

    let (status, _, body) = http_get(port, "/nope").await;
    assert_eq!((status, body.trim()), (404, "not found"));

    // HEAD gets the identical headers with no body.
    let (status, head, body) = http_request(port, "HEAD", "/healthz").await;
    assert_eq!(status, 200);
    assert!(head.contains("Content-Length: 3"));
    assert!(body.is_empty());

    // An unsupported method is refused, not routed.
    let (status, _, _) = http_request(port, "POST", "/").await;
    assert_eq!(status, 501);

    dash.stop();
}

#[tokio::test]
async fn sparklines_appear_once_history_has_samples() {
    let (port, dash, f) = dashboard_fixture().await;
    // Poll twice so the rings hold more than one sample.
    let _ = http_get(port, "/").await;
    let (_, _, body) = http_get(port, "/").await;
    assert!(body.contains("<svg"));
    assert!(body.contains("<polyline"));
    // Every inline <svg>…</svg> fragment is balanced and self-contained.
    let fragments: Vec<&str> = body
        .match_indices("<svg")
        .map(|(i, _)| &body[i..])
        .collect();
    assert!(!fragments.is_empty());
    for frag in fragments {
        let end = frag.find("</svg>").expect("every <svg> is closed");
        let svg = &frag[..end];
        assert!(!svg.contains("NaN") && !svg.contains("inf"));
        assert_eq!(svg.matches("<svg").count(), 1);
    }
    // The alerts panel rendered both firing rules.
    assert!(body.contains("alert firing"));
    assert!(body.contains("alpha is serving requests"));
    assert!(body.contains("a service is down"));

    dash.stop();
    f.stop();
}

/// One request attempt against the dashboard: `None` when the server closed the
/// connection without answering, i.e. every connection slot was taken.
async fn try_get(port: u16) -> Option<u16> {
    let mut sock = TcpStream::connect(("127.0.0.1", port)).await.ok()?;
    sock.write_all(b"GET /healthz HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n")
        .await
        .ok()?;
    let mut raw = Vec::new();
    sock.read_to_end(&mut raw).await.ok()?;
    if raw.is_empty() {
        return None; // refused: the accept loop had no permit and dropped us
    }
    String::from_utf8_lossy(&raw)
        .split(' ')
        .nth(1)
        .and_then(|code| code.parse::<u16>().ok())
}

#[tokio::test]
async fn a_slowloris_client_cannot_hold_a_connection_slot_open() {
    // `max_workers = 1` makes one held connection the whole server; the default
    // of 16 just means the attack needs 16 sockets.
    let config = Config {
        host: "127.0.0.1".to_string(),
        port: 0,
        max_workers: 1,
        verbose: false,
        services: Vec::new(), // /healthz never polls; keep the test about the socket
        ..Config::default()
    };
    let listener = TcpListener::bind("127.0.0.1:0")
        .await
        .expect("bind dashboard");
    let port = listener.local_addr().expect("dashboard addr").port();
    let dash = DashboardHandle(tokio::spawn(serve(
        listener,
        Arc::new(Dashboard::new(config)),
    )));

    // The attack: open a request head and never terminate it, sending one more
    // header every 3 s — comfortably inside the 10 s window that used to be
    // restarted on every single read, so the lease renewed itself forever.
    let mut slow = TcpStream::connect(("127.0.0.1", port))
        .await
        .expect("slowloris connect");
    slow.write_all(b"GET / HTTP/1.1\r\nHost: 127.0.0.1\r\n")
        .await
        .expect("slowloris head");
    let dribbler = tokio::spawn(async move {
        for i in 0..100 {
            tokio::time::sleep(Duration::from_secs(3)).await;
            if slow
                .write_all(format!("X-Pad-{i}: a\r\n").as_bytes())
                .await
                .is_err()
            {
                return;
            }
        }
    });

    // While it is held, the dashboard is offline for everyone else.
    tokio::time::sleep(Duration::from_millis(300)).await;
    assert_eq!(
        try_get(port).await,
        None,
        "the slow client should be holding the only connection slot"
    );

    // The total head deadline must give the slot back regardless.
    let give_up = Instant::now() + suitedash::server::HEAD_READ_TIMEOUT + Duration::from_secs(5);
    let mut served = None;
    while Instant::now() < give_up {
        if let Some(status) = try_get(port).await {
            served = Some(status);
            break;
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
    }
    assert_eq!(
        served,
        Some(200),
        "a Slowloris client held its connection slot past the head deadline: \
         the dashboard was offline for every other client"
    );

    dribbler.abort();
    dash.stop();
}

#[tokio::test]
async fn a_recursion_bomb_upstream_keeps_metrics_at_200() {
    // A deeply-nested JSON body must not take down the aggregate exporter.
    let svc = spawn_service(
        &[
            ("/health", Reply::ok("ok\n")),
            (
                "/metrics",
                Reply::new(200, "application/json", &"[".repeat(5000)),
            ),
        ],
        None,
    )
    .await;
    let (port, dash) =
        spawn_dashboard(vec![ServiceConfig::new("bomb", svc.base_url())], Vec::new()).await;
    let (status, _, body) = http_get(port, "/metrics").await;
    assert_eq!(status, 200); // not 500
    assert!(body.contains("suitedash_up 1"));
    assert!(body.contains("suitedash_service_up{service=\"bomb\"} 1"));
    assert!(body.contains("suitedash_service_metric_count{service=\"bomb\"} 0"));
    dash.stop();
    svc.stop();
}

#[tokio::test]
async fn a_cached_sweep_is_reused_and_does_not_re_probe() {
    // With a TTL configured, a second request inside the window serves the cached
    // snapshot: the identical `checked_at` proves no second probe ran.
    let svc = prometheus_service().await;
    let config = Config {
        host: "127.0.0.1".to_string(),
        port: 0,
        timeout_seconds: 0.6,
        cache_ttl: 30.0,
        verbose: false,
        services: vec![ServiceConfig::new("alpha", svc.base_url())],
        ..Config::default()
    };
    let dash = Dashboard::new(config);
    let first = dash.poll().await;
    let second = dash.poll().await;
    assert_eq!(
        first.get("alpha").map(|r| r.checked_at),
        second.get("alpha").map(|r| r.checked_at)
    );
    // A cache hit never advances history, so every ring still holds ONE sample.
    let series = dash.monitor().snapshot().series_for("alpha");
    assert!(
        !series.is_empty(),
        "the first (real) sweep recorded history"
    );
    for (_, points) in series.iter() {
        assert_eq!(points.len(), 1, "a cache hit must not record history again");
    }
    svc.stop();
}
