//! Cross-check: the Rust `suitedash::exporter` reproduces the Python
//! `suitedash.exporter` byte-identically — suitedash's own gauges, the
//! `service=` relabelling of upstream Prometheus samples (existing labels kept,
//! duplicate and spoofed label names dropped, invalid escapes re-encoded,
//! upstream HELP/TYPE dropped, garbled lines skipped, values canonicalised,
//! reserved `suitedash_*` names refused), JSON federation with sanitised metric
//! names (content-type driven *and* sniffed), a hostile service name escaped
//! into the label, a DOWN service, an empty sweep, and a deeply-nested JSON body
//! that must yield no series.
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `exporter`), which
//! drives the real Python exporter.

use suitedash::exporter::render_federated_metrics;
use suitedash::metrics::{Results, ServiceResult};

fn res(name: &str, latency: Option<f64>, raw: &str, ctype: &str) -> ServiceResult {
    let mut r = ServiceResult::new(name, "http://x", true);
    r.latency_ms = latency;
    r.metrics_raw = raw.to_string();
    r.metrics_ctype = ctype.to_string();
    r
}

fn sweep(rs: Vec<ServiceResult>) -> Results {
    let mut out = Results::new();
    for r in rs {
        out.insert(r.name.clone(), r);
    }
    out
}

const PROM: &str = "# HELP http_reqs total\n# TYPE http_reqs counter\n\
                    http_reqs 5\nhttp_reqs{code=\"200\"} 4\nhttp_reqs{code=\"500\"} 1\n\
                    latency_seconds 0.125\n";

const GARBAGE: &str = concat!(
    "good_metric 1\nthis is not prometheus at all\nbad_value abc\n",
    "unterminated{label=\"x 2\nanother_good 2\n",
    "trailing_ts 3 1699999999000\nempty_labels{} 4\n",
    "spaced{ a = \"1\" } 5\ntrail_comma{a=\"1\",} 6\n",
    "dupname{a=\"1\",a=\"2\"} 7\nspoof{service=\"fake\",k=\"v\"} 8\n",
    "esc{t=\"a\\tb\",q=\"x\\\"y\",bs=\"c\\\\d\",nl=\"e\\nf\"} 9\n",
    "suitedash_up 0\nsuitedash_service_up 0\nlegit 7\n",
    "grouped 1_000\nplain 3.0\nnanv NaN\ninfv +Inf\nbigv 1e16\n",
    "just_under 5e14\njust_over 2e15\nfracv 0.5\nnegv -12.25\n",
    "expv 1e-7\nhugev 1e300\n"
);

const JS: &str = concat!(
    r#"{"docs": 1000, "a.b": 2, "ok": true, "tags": ["x"], "9lead": 3,"#,
    r#" "": 4, "suitedash_up": 0, "nested": {"n": 5}, "s": "6.5"}"#
);

fn cases() -> Vec<(&'static str, Results)> {
    let deep = "[".repeat(4000);
    vec![
        (
            "prom",
            sweep(vec![res("alpha", Some(5.0), PROM, "text/plain")]),
        ),
        (
            "garbage",
            sweep(vec![res("s", Some(12.0), GARBAGE, "text/plain")]),
        ),
        (
            "json",
            sweep(vec![res("js", Some(1.5), JS, "application/json")]),
        ),
        ("json_sniffed", sweep(vec![res("js2", None, JS, "")])),
        (
            "hostile_name",
            sweep(vec![res("ev\"il\\\nx", Some(0.5), "m 1\n", "text/plain")]),
        ),
        (
            "mixed",
            sweep(vec![
                res("alpha", Some(12.0), "x 1\n", "text/plain"),
                ServiceResult::new("beta", "http://x", false),
                res("gamma", Some(0.0), "   \n", "text/plain"),
                res(
                    "delta",
                    Some(1234.5678),
                    r#"{"suitedash_x": 1, "ok": 2}"#,
                    "application/json",
                ),
            ]),
        ),
        ("empty", Results::new()),
        (
            "deep_json",
            sweep(vec![res("evil", Some(1.0), &deep, "application/json")]),
        ),
    ]
}

#[test]
fn federated_exposition_matches_python() {
    let want: &[(&str, &str)] = &[
        (
            "prom",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
suitedash_service_up{service="alpha"} 1
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
suitedash_service_scrape_duration_seconds{service="alpha"} 0.005
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
suitedash_service_metric_count{service="alpha"} 4
http_reqs{service="alpha"} 5
http_reqs{service="alpha",code="200"} 4
http_reqs{service="alpha",code="500"} 1
latency_seconds{service="alpha"} 0.125
"#,
        ),
        (
            "garbage",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
suitedash_service_up{service="s"} 1
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
suitedash_service_scrape_duration_seconds{service="s"} 0.012
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
suitedash_service_metric_count{service="s"} 20
good_metric{service="s"} 1
another_good{service="s"} 2
trailing_ts{service="s"} 3
empty_labels{service="s"} 4
trail_comma{service="s",a="1"} 6
dupname{service="s",a="1"} 7
spoof{service="s",k="v"} 8
esc{service="s",t="a\\tb",q="x\"y",bs="c\\d",nl="e\nf"} 9
legit{service="s"} 7
grouped{service="s"} 1000
plain{service="s"} 3
nanv{service="s"} 0
infv{service="s"} 0
bigv{service="s"} 1e+16
just_under{service="s"} 500000000000000
just_over{service="s"} 2000000000000000.0
fracv{service="s"} 0.5
negv{service="s"} -12.25
expv{service="s"} 1e-07
hugev{service="s"} 1e+300
"#,
        ),
        (
            "json",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
suitedash_service_up{service="js"} 1
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
suitedash_service_scrape_duration_seconds{service="js"} 0.0015
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
suitedash_service_metric_count{service="js"} 6
docs{service="js"} 1000
a_b{service="js"} 2
ok{service="js"} 1
_9lead{service="js"} 3
nested_n{service="js"} 5
s{service="js"} 6.5
"#,
        ),
        (
            "json_sniffed",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
suitedash_service_up{service="js2"} 1
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
suitedash_service_metric_count{service="js2"} 6
docs{service="js2"} 1000
a_b{service="js2"} 2
ok{service="js2"} 1
_9lead{service="js2"} 3
nested_n{service="js2"} 5
s{service="js2"} 6.5
"#,
        ),
        (
            "hostile_name",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
suitedash_service_up{service="ev\"il\\\nx"} 1
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
suitedash_service_scrape_duration_seconds{service="ev\"il\\\nx"} 0.0005
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
suitedash_service_metric_count{service="ev\"il\\\nx"} 1
m{service="ev\"il\\\nx"} 1
"#,
        ),
        (
            "mixed",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
suitedash_service_up{service="alpha"} 1
suitedash_service_up{service="beta"} 0
suitedash_service_up{service="gamma"} 1
suitedash_service_up{service="delta"} 1
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
suitedash_service_scrape_duration_seconds{service="alpha"} 0.012
suitedash_service_scrape_duration_seconds{service="gamma"} 0
suitedash_service_scrape_duration_seconds{service="delta"} 1.2345678
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
suitedash_service_metric_count{service="alpha"} 1
suitedash_service_metric_count{service="beta"} 0
suitedash_service_metric_count{service="gamma"} 0
suitedash_service_metric_count{service="delta"} 1
x{service="alpha"} 1
ok{service="delta"} 2
"#,
        ),
        (
            "empty",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
"#,
        ),
        (
            "deep_json",
            r#"# HELP suitedash_up 1 if the suitedash dashboard is running.
# TYPE suitedash_up gauge
suitedash_up 1
# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.
# TYPE suitedash_service_up gauge
suitedash_service_up{service="evil"} 1
# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took.
# TYPE suitedash_service_scrape_duration_seconds gauge
suitedash_service_scrape_duration_seconds{service="evil"} 0.001
# HELP suitedash_service_metric_count Federated upstream series emitted for the service.
# TYPE suitedash_service_metric_count gauge
suitedash_service_metric_count{service="evil"} 0
"#,
        ),
    ];
    for ((label, results), (wlabel, wtext)) in cases().iter().zip(want) {
        assert_eq!(label, wlabel, "case order");
        assert_eq!(&render_federated_metrics(results), wtext, "case: {label}");
    }
}
