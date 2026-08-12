//! Cross-check: `gitweb::metrics` is byte-identical to the Python
//! `gitweb.metrics` — the counter arithmetic and the Prometheus text exposition
//! format (`%.3f` uptime, `%.6f` latency sum, statuses sorted numerically and
//! actions lexicographically).
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `gen_metrics`). The
//! uptime is frozen there by patching `time.time`, so the rendered text is
//! deterministic; here the same value is injected into the snapshot.

use gitweb::metrics::{Metrics, Snapshot};

/// The exact call sequence the generator drives the Python `Metrics` with.
fn driven() -> Metrics {
    let m = Metrics::new();
    m.begin();
    m.begin();
    m.begin();
    m.end(200, "repo", 0.0125);
    m.end(404, "", 0.5);
    m.end(200, "log", 1.0 / 3.0);
    m.reject();
    m.reject();
    m.end(500, "blob", 2.5);
    m.end(200, "repo", 0.001);
    m.end(301, "atom", 0.0);
    m.begin();
    m
}

#[test]
fn snapshot_counters_match_python() {
    let snap = driven().snapshot();
    assert_eq!(snap.total, 6);
    assert_eq!(snap.in_flight, 1);
    assert_eq!(snap.rejected, 2);
    assert_eq!(snap.latency_count, 6);
    assert_eq!(snap.latency_sum, 3.346833333333333);

    let mut by_status = snap.by_status.clone();
    by_status.sort_by_key(|(s, _)| *s);
    let want_status: &[(u16, u64)] = &[(200, 3), (301, 1), (404, 1), (500, 1)];
    assert_eq!(by_status, want_status);

    let mut by_action: Vec<(&str, u64)> = snap
        .by_action
        .iter()
        .map(|(a, n)| (a.as_str(), *n))
        .collect();
    by_action.sort_by_key(|(a, _)| *a);
    let want_action: &[(&str, u64)] = &[("atom", 1), ("blob", 1), ("log", 1), ("repo", 2)];
    assert_eq!(by_action, want_action);

    // Python `dict` insertion order: first-seen status/action wins its slot.
    assert_eq!(
        snap.by_status.iter().map(|(s, _)| *s).collect::<Vec<_>>(),
        vec![200u16, 404, 500, 301]
    );
    assert_eq!(
        snap.by_action
            .iter()
            .map(|(a, _)| a.as_str())
            .collect::<Vec<_>>(),
        vec!["repo", "log", "blob", "atom"]
    );
}

#[test]
fn render_prometheus_matches_python() {
    let want = "# HELP gitweb_uptime_seconds Seconds since the server started.\n# TYPE gitweb_uptime_seconds gauge\ngitweb_uptime_seconds 12.346\n# HELP gitweb_requests_total Total HTTP requests served.\n# TYPE gitweb_requests_total counter\ngitweb_requests_total 6\n# HELP gitweb_requests_in_flight Requests currently being handled.\n# TYPE gitweb_requests_in_flight gauge\ngitweb_requests_in_flight 1\n# HELP gitweb_connections_rejected_total Connections dropped by the worker-pool limiter.\n# TYPE gitweb_connections_rejected_total counter\ngitweb_connections_rejected_total 2\n# HELP gitweb_request_latency_seconds_sum Cumulative request handling time.\n# TYPE gitweb_request_latency_seconds_sum counter\ngitweb_request_latency_seconds_sum 3.346833\n# HELP gitweb_request_latency_seconds_count Number of timed requests.\n# TYPE gitweb_request_latency_seconds_count counter\ngitweb_request_latency_seconds_count 6\n# HELP gitweb_responses_total Responses by HTTP status.\n# TYPE gitweb_responses_total counter\ngitweb_responses_total{status=\"200\"} 3\ngitweb_responses_total{status=\"301\"} 1\ngitweb_responses_total{status=\"404\"} 1\ngitweb_responses_total{status=\"500\"} 1\n# HELP gitweb_action_total Requests by resolved action.\n# TYPE gitweb_action_total counter\ngitweb_action_total{action=\"atom\"} 1\ngitweb_action_total{action=\"blob\"} 1\ngitweb_action_total{action=\"log\"} 1\ngitweb_action_total{action=\"repo\"} 2\n";
    let mut snap = driven().snapshot();
    snap.uptime = 12.3456789;
    assert_eq!(snap.render_prometheus(), want);
}

#[test]
fn render_prometheus_empty_matches_python() {
    let want_empty = "# HELP gitweb_uptime_seconds Seconds since the server started.\n# TYPE gitweb_uptime_seconds gauge\ngitweb_uptime_seconds 0.000\n# HELP gitweb_requests_total Total HTTP requests served.\n# TYPE gitweb_requests_total counter\ngitweb_requests_total 0\n# HELP gitweb_requests_in_flight Requests currently being handled.\n# TYPE gitweb_requests_in_flight gauge\ngitweb_requests_in_flight 0\n# HELP gitweb_connections_rejected_total Connections dropped by the worker-pool limiter.\n# TYPE gitweb_connections_rejected_total counter\ngitweb_connections_rejected_total 0\n# HELP gitweb_request_latency_seconds_sum Cumulative request handling time.\n# TYPE gitweb_request_latency_seconds_sum counter\ngitweb_request_latency_seconds_sum 0.000000\n# HELP gitweb_request_latency_seconds_count Number of timed requests.\n# TYPE gitweb_request_latency_seconds_count counter\ngitweb_request_latency_seconds_count 0\n# HELP gitweb_responses_total Responses by HTTP status.\n# TYPE gitweb_responses_total counter\n# HELP gitweb_action_total Requests by resolved action.\n# TYPE gitweb_action_total counter\n";
    let snap = Snapshot::default();
    assert_eq!(snap.render_prometheus(), want_empty);
    // The live path renders the same shape (only `uptime` differs).
    let live = Metrics::new().render_prometheus();
    assert!(live.starts_with("# HELP gitweb_uptime_seconds"));
    assert!(live.ends_with("# TYPE gitweb_action_total counter\n"));
}

#[test]
fn in_flight_never_goes_negative() {
    let m = Metrics::new();
    m.end(200, "x", 0.0);
    assert_eq!(m.snapshot().in_flight, 0);
}

#[test]
fn registry_is_shared() {
    let before = gitweb::metrics::registry().snapshot().rejected;
    gitweb::metrics::registry().reject();
    assert_eq!(gitweb::metrics::registry().snapshot().rejected, before + 1);
}
