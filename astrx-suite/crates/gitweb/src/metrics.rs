//! Tiny thread-safe, dependency-free request metrics.
//!
//! A single process-wide [`Metrics`] instance (see [`registry`]) accumulates
//! request counters and latency so `/metrics` can expose them in a
//! Prometheus-style text exposition format and `/health` can answer cheaply.
//! Everything is plain integers/floats under one [`Mutex`]; there is no
//! background thread and nothing is ever written to disk.
//!
//! A faithful port of the Python `gitweb.metrics`. [`Snapshot::render_prometheus`]
//! is pure and cross-checked byte-identical in `tests/xcheck_metrics.rs`.

use std::sync::{Mutex, OnceLock};
use std::time::{SystemTime, UNIX_EPOCH};

/// Seconds since the unix epoch, as `time.time()` reports them.
fn now_seconds() -> f64 {
    match SystemTime::now().duration_since(UNIX_EPOCH) {
        Ok(d) => d.as_secs_f64(),
        Err(e) => -e.duration().as_secs_f64(),
    }
}

#[derive(Default)]
struct Inner {
    total: u64,
    in_flight: u64,
    rejected: u64,
    by_status: Vec<(u16, u64)>,
    by_action: Vec<(String, u64)>,
    latency_sum: f64,
    latency_count: u64,
}

fn bump<K: PartialEq>(map: &mut Vec<(K, u64)>, key: K) {
    match map.iter_mut().find(|(k, _)| *k == key) {
        Some((_, v)) => *v += 1,
        None => map.push((key, 1)),
    }
}

/// Process-wide request counters and latency accumulator.
pub struct Metrics {
    started: f64,
    inner: Mutex<Inner>,
}

impl Default for Metrics {
    fn default() -> Self {
        Self::new()
    }
}

impl Metrics {
    /// A fresh registry whose uptime starts now.
    #[must_use]
    pub fn new() -> Self {
        Self {
            started: now_seconds(),
            inner: Mutex::new(Inner::default()),
        }
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, Inner> {
        // A panic inside a metrics update cannot corrupt the counters into an
        // unsound state, so a poisoned lock is recovered rather than propagated.
        self.inner.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// A request has started being handled.
    pub fn begin(&self) {
        self.lock().in_flight += 1;
    }

    /// A request has finished with `status`, resolved to `action`, taking
    /// `elapsed` seconds. An empty `action` is not counted.
    pub fn end(&self, status: u16, action: &str, elapsed: f64) {
        let mut g = self.lock();
        g.total += 1;
        g.in_flight = g.in_flight.saturating_sub(1);
        bump(&mut g.by_status, status);
        if !action.is_empty() {
            bump(&mut g.by_action, action.to_string());
        }
        g.latency_sum += elapsed;
        g.latency_count += 1;
    }

    /// A connection was dropped by the worker-pool limiter.
    pub fn reject(&self) {
        self.lock().rejected += 1;
    }

    /// A consistent copy of every counter, plus the current uptime.
    #[must_use]
    pub fn snapshot(&self) -> Snapshot {
        let g = self.lock();
        Snapshot {
            uptime: now_seconds() - self.started,
            total: g.total,
            in_flight: g.in_flight,
            rejected: g.rejected,
            by_status: g.by_status.clone(),
            by_action: g.by_action.clone(),
            latency_sum: g.latency_sum,
            latency_count: g.latency_count,
        }
    }

    /// Render the current snapshot as Prometheus text exposition format.
    #[must_use]
    pub fn render_prometheus(&self) -> String {
        self.snapshot().render_prometheus()
    }
}

/// A point-in-time copy of a [`Metrics`] registry.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Snapshot {
    /// Seconds since the registry was created.
    pub uptime: f64,
    /// Total requests served.
    pub total: u64,
    /// Requests currently being handled.
    pub in_flight: u64,
    /// Connections dropped by the worker-pool limiter.
    pub rejected: u64,
    /// Responses by HTTP status, in first-seen order (Python `dict` order).
    pub by_status: Vec<(u16, u64)>,
    /// Requests by resolved action, in first-seen order (Python `dict` order).
    pub by_action: Vec<(String, u64)>,
    /// Cumulative request handling time, in seconds.
    pub latency_sum: f64,
    /// Number of timed requests.
    pub latency_count: u64,
}

impl Snapshot {
    /// Render this snapshot as Prometheus text exposition format.
    #[must_use]
    pub fn render_prometheus(&self) -> String {
        let mut lines: Vec<String> = vec![
            "# HELP gitweb_uptime_seconds Seconds since the server started.".to_string(),
            "# TYPE gitweb_uptime_seconds gauge".to_string(),
            format!("gitweb_uptime_seconds {:.3}", self.uptime),
            "# HELP gitweb_requests_total Total HTTP requests served.".to_string(),
            "# TYPE gitweb_requests_total counter".to_string(),
            format!("gitweb_requests_total {}", self.total),
            "# HELP gitweb_requests_in_flight Requests currently being handled.".to_string(),
            "# TYPE gitweb_requests_in_flight gauge".to_string(),
            format!("gitweb_requests_in_flight {}", self.in_flight),
            "# HELP gitweb_connections_rejected_total Connections dropped by the worker-pool limiter."
                .to_string(),
            "# TYPE gitweb_connections_rejected_total counter".to_string(),
            format!("gitweb_connections_rejected_total {}", self.rejected),
            "# HELP gitweb_request_latency_seconds_sum Cumulative request handling time."
                .to_string(),
            "# TYPE gitweb_request_latency_seconds_sum counter".to_string(),
            format!("gitweb_request_latency_seconds_sum {:.6}", self.latency_sum),
            "# HELP gitweb_request_latency_seconds_count Number of timed requests.".to_string(),
            "# TYPE gitweb_request_latency_seconds_count counter".to_string(),
            format!("gitweb_request_latency_seconds_count {}", self.latency_count),
        ];
        lines.push("# HELP gitweb_responses_total Responses by HTTP status.".to_string());
        lines.push("# TYPE gitweb_responses_total counter".to_string());
        let mut by_status = self.by_status.clone();
        by_status.sort_by_key(|(s, _)| *s);
        for (status, count) in &by_status {
            lines.push(format!(
                "gitweb_responses_total{{status=\"{status}\"}} {count}"
            ));
        }
        lines.push("# HELP gitweb_action_total Requests by resolved action.".to_string());
        lines.push("# TYPE gitweb_action_total counter".to_string());
        let mut by_action = self.by_action.clone();
        by_action.sort_by(|a, b| a.0.cmp(&b.0));
        for (action, count) in &by_action {
            lines.push(format!(
                "gitweb_action_total{{action=\"{action}\"}} {count}"
            ));
        }
        lines.join("\n") + "\n"
    }
}

/// The process-wide instance shared by every request.
///
/// The Python reference builds it at import time; here it is created on first
/// use, so `uptime` counts from the first metrics call rather than from process
/// start.
#[must_use]
pub fn registry() -> &'static Metrics {
    static REGISTRY: OnceLock<Metrics> = OnceLock::new();
    REGISTRY.get_or_init(Metrics::new)
}
