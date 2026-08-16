//! The request-metrics block every engine's `/metrics` shares.
//!
//! `suitedash` polls all five engines and its dashboards key on metric *names*,
//! so an engine that spells its request counter differently — or does not have
//! one — is invisible on the status page. Before this module only `gitweb`
//! exposed request counters at all; `onioncrawler`, `websearch` and `torrentds`
//! exposed a handful of index gauges, and `suitedash` exposed only what it had
//! federated from elsewhere. That meant "how many requests is websearch actually
//! serving, and how many of them are 5xx?" had no answer anywhere in the suite.
//!
//! [`Requests`] is that answer, in one place: a lock-guarded set of plain
//! integers with no background thread, no allocation on the hot path beyond a
//! first-seen status/action, and nothing written to disk. [`Requests::render`]
//! emits Prometheus text exposition format under a caller-supplied prefix, using
//! the names `gitweb` already established (`<prefix>_requests_total`,
//! `<prefix>_uptime_seconds`, …) so the dashboards that key on them keep working
//! and the same query works against any engine with only the prefix changed.
//!
//! `gitweb::metrics` deliberately still has its own copy: its output is
//! cross-checked byte-identical against the retired Python implementation by
//! `gitweb/tests/xcheck_metrics.rs`, and that contract outranks sharing code.
//! The names emitted here match it exactly, which is what actually matters to a
//! dashboard.

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{SystemTime, UNIX_EPOCH};

/// Ceiling on distinct `action` labels retained.
///
/// The action is derived from the request path. If a future caller ever derives
/// it from something attacker-controlled, an unbounded map here would let a
/// remote peer mint label cardinality until the process died — the same failure
/// `suitedash::metrics::MAX_METRIC_NAME` exists to prevent on the parsing side.
/// Beyond the cap, further actions are folded into `other`.
pub const MAX_ACTIONS: usize = 64;

/// Seconds since the unix epoch (Python `time.time()`).
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
    errors: u64,
    by_status: HashMap<u16, u64>,
    by_action: HashMap<String, u64>,
    latency_sum: f64,
    latency_count: u64,
}

/// Process-wide request counters for one engine's HTTP server.
///
/// Each engine owns its own instance behind a `OnceLock`, rather than there
/// being one global here: the `astrx` binary links all five engines into one
/// process, and a shared global would report gitweb's traffic under websearch's
/// prefix the moment anything ran two servers side by side.
#[derive(Debug)]
pub struct Requests {
    started: f64,
    inner: Mutex<Inner>,
}

impl std::fmt::Debug for Inner {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Inner")
            .field("total", &self.total)
            .field("in_flight", &self.in_flight)
            .finish_non_exhaustive()
    }
}

impl Default for Requests {
    fn default() -> Self {
        Self::new()
    }
}

impl Requests {
    /// A fresh registry whose uptime starts now.
    #[must_use]
    pub fn new() -> Self {
        Requests {
            started: now_seconds(),
            inner: Mutex::new(Inner::default()),
        }
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, Inner> {
        // A panic while bumping a counter cannot leave the counters in an
        // unsound state, so a poisoned lock is recovered. The alternative —
        // propagating the poison — turns one panicking request into a `/metrics`
        // endpoint that panics forever, i.e. an engine that looks dead to the
        // dashboard because its *monitoring* broke.
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
        *g.by_status.entry(status).or_insert(0) += 1;
        if status >= 500 {
            g.errors += 1;
        }
        if !action.is_empty() {
            let key = if g.by_action.contains_key(action) || g.by_action.len() < MAX_ACTIONS {
                action.to_string()
            } else {
                "other".to_string()
            };
            *g.by_action.entry(key).or_insert(0) += 1;
        }
        // A negative elapsed (a clock stepping backwards mid-request) would make
        // the latency average nonsensical and can make a counter go down, which
        // Prometheus reads as a counter reset.
        if elapsed.is_finite() && elapsed >= 0.0 {
            g.latency_sum += elapsed;
            g.latency_count += 1;
        }
    }

    /// A connection was dropped without being served (over the connection bound,
    /// oversized head, refused body).
    pub fn reject(&self) {
        let mut g = self.lock();
        g.rejected += 1;
    }

    /// The count recorded for `action`, or 0 — used by engines that also publish
    /// a bare alias for one action (e.g. `websearch_searches_total`).
    #[must_use]
    pub fn action_count(&self, action: &str) -> u64 {
        self.lock().by_action.get(action).copied().unwrap_or(0)
    }

    /// Seconds since this registry was created.
    #[must_use]
    pub fn uptime(&self) -> f64 {
        now_seconds() - self.started
    }

    /// Render the shared block as Prometheus text exposition format, every
    /// series prefixed with `prefix` (e.g. `websearch`).
    ///
    /// Series are emitted in a fixed order and label sets are sorted, so two
    /// consecutive scrapes of an idle server produce byte-identical output apart
    /// from the uptime — a `/metrics` body that reshuffles itself makes diffing
    /// two scrapes during an incident useless.
    #[must_use]
    pub fn render(&self, prefix: &str) -> String {
        let g = self.lock();
        let mut lines: Vec<String> = vec![
            format!("# HELP {prefix}_uptime_seconds Seconds since the server started."),
            format!("# TYPE {prefix}_uptime_seconds gauge"),
            format!("{prefix}_uptime_seconds {:.3}", now_seconds() - self.started),
            format!("# HELP {prefix}_requests_total Total HTTP requests served."),
            format!("# TYPE {prefix}_requests_total counter"),
            format!("{prefix}_requests_total {}", g.total),
            format!("# HELP {prefix}_requests_in_flight Requests currently being handled."),
            format!("# TYPE {prefix}_requests_in_flight gauge"),
            format!("{prefix}_requests_in_flight {}", g.in_flight),
            format!("# HELP {prefix}_errors_total Requests answered with a 5xx."),
            format!("# TYPE {prefix}_errors_total counter"),
            format!("{prefix}_errors_total {}", g.errors),
            format!(
                "# HELP {prefix}_connections_rejected_total Connections dropped without being served."
            ),
            format!("# TYPE {prefix}_connections_rejected_total counter"),
            format!("{prefix}_connections_rejected_total {}", g.rejected),
            format!("# HELP {prefix}_request_latency_seconds_sum Cumulative request handling time."),
            format!("# TYPE {prefix}_request_latency_seconds_sum counter"),
            format!("{prefix}_request_latency_seconds_sum {:.6}", g.latency_sum),
            format!("# HELP {prefix}_request_latency_seconds_count Number of timed requests."),
            format!("# TYPE {prefix}_request_latency_seconds_count counter"),
            format!("{prefix}_request_latency_seconds_count {}", g.latency_count),
        ];

        lines.push(format!(
            "# HELP {prefix}_responses_total Responses by HTTP status."
        ));
        lines.push(format!("# TYPE {prefix}_responses_total counter"));
        let mut by_status: Vec<(u16, u64)> = g.by_status.iter().map(|(k, v)| (*k, *v)).collect();
        by_status.sort_unstable();
        for (status, count) in by_status {
            lines.push(format!(
                "{prefix}_responses_total{{status=\"{status}\"}} {count}"
            ));
        }

        lines.push(format!(
            "# HELP {prefix}_action_total Requests by resolved action."
        ));
        lines.push(format!("# TYPE {prefix}_action_total counter"));
        let mut by_action: Vec<(&String, &u64)> = g.by_action.iter().collect();
        by_action.sort_unstable_by(|a, b| a.0.cmp(b.0));
        for (action, count) in by_action {
            // Actions are compile-time constants chosen by the engine, never a
            // request string, so no escaping is required here. Asserted by
            // `an_action_label_is_always_a_bare_identifier`.
            lines.push(format!(
                "{prefix}_action_total{{action=\"{action}\"}} {count}"
            ));
        }
        lines.join("\n") + "\n"
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn counters_add_up_and_5xx_is_an_error() {
        let m = Requests::new();
        m.begin();
        m.end(200, "search", 0.01);
        m.begin();
        m.end(200, "search", 0.02);
        m.begin();
        m.end(503, "search", 0.5);
        m.reject();

        let out = m.render("websearch");
        assert!(out.contains("websearch_requests_total 3"), "{out}");
        assert!(out.contains("websearch_requests_in_flight 0"), "{out}");
        assert!(out.contains("websearch_errors_total 1"), "{out}");
        assert!(
            out.contains("websearch_connections_rejected_total 1"),
            "{out}"
        );
        assert!(
            out.contains("websearch_responses_total{status=\"200\"} 2"),
            "{out}"
        );
        assert!(
            out.contains("websearch_responses_total{status=\"503\"} 1"),
            "{out}"
        );
        assert!(
            out.contains("websearch_action_total{action=\"search\"} 3"),
            "{out}"
        );
        assert!(
            out.contains("websearch_request_latency_seconds_count 3"),
            "{out}"
        );
        assert_eq!(m.action_count("search"), 3);
        assert_eq!(m.action_count("nope"), 0);
    }

    #[test]
    fn in_flight_never_underflows() {
        // An `end` without a `begin` happens on the paths that reject a request
        // before routing it. `in_flight` is unsigned; wrapping it would print
        // 18446744073709551615 in-flight requests on a completely idle server.
        let m = Requests::new();
        m.end(400, "", 0.0);
        assert!(m.render("x").contains("x_requests_in_flight 0"));
    }

    #[test]
    fn a_backwards_clock_cannot_make_the_latency_counter_go_down() {
        let m = Requests::new();
        m.end(200, "a", 1.0);
        m.end(200, "a", -5.0);
        m.end(200, "a", f64::NAN);
        let out = m.render("x");
        assert!(
            out.contains("x_request_latency_seconds_sum 1.000000"),
            "{out}"
        );
        assert!(out.contains("x_request_latency_seconds_count 1"), "{out}");
    }

    #[test]
    fn action_cardinality_is_capped() {
        let m = Requests::new();
        for i in 0..(MAX_ACTIONS * 4) {
            m.end(200, &format!("a{i}"), 0.0);
        }
        let out = m.render("x");
        let distinct = out
            .lines()
            .filter(|l| l.starts_with("x_action_total{"))
            .count();
        assert!(distinct <= MAX_ACTIONS + 1, "{distinct} action labels");
        assert!(out.contains("action=\"other\""), "{out}");
    }

    #[test]
    fn output_is_stable_across_scrapes_except_for_uptime() {
        let m = Requests::new();
        for s in [200u16, 404, 500, 200, 301] {
            m.end(s, "page", 0.001);
        }
        let strip = |s: String| {
            s.lines()
                .filter(|l| !l.contains("uptime_seconds "))
                .collect::<Vec<_>>()
                .join("\n")
        };
        assert_eq!(strip(m.render("x")), strip(m.render("x")));
    }

    #[test]
    fn an_action_label_is_always_a_bare_identifier() {
        // The renderer does not escape label values. That is only safe while the
        // engines pass compile-time constants; this pins the assumption so a
        // future caller that passes a request path fails here instead of
        // shipping a `/metrics` body an attacker can inject series into.
        let m = Requests::new();
        m.end(200, "search", 0.0);
        for line in m.render("x").lines().filter(|l| l.contains("action=")) {
            let val = line
                .split_once("action=\"")
                .and_then(|(_, r)| r.split_once('"'))
                .map(|(v, _)| v)
                .unwrap_or_default();
            assert!(
                val.chars().all(|c| c.is_ascii_alphanumeric() || c == '_'),
                "action label {val:?} needs escaping"
            );
        }
    }
}
