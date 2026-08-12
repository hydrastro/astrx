//! Cross-check: the Rust `suitedash::config` loader reproduces the Python
//! `suitedash.config` byte-identically — the defaults, every top-level override
//! with its clamps and CPython coercions (`str()`/`int()`/`float()`/truthiness),
//! `[[service]]` replacement with stripped/`/`-trimmed URLs, `[[alert]]`
//! validation (operators, required metric, `for`/`for_polls`, severity/kind
//! case-folding, threshold coercion), unique-id resolution (an auto id may never
//! collide with an explicit one), the `MAX_RULES` bound, and the shipped
//! `suitedash.example.toml` parsed end to end. Rejected documents are compared
//! as rejections.
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `config`), which drives
//! the real Python `load_config` / `apply_service_flags`.
//!
//! **Not compared:** the exception *message* of a rejected document. CPython
//! raises `ValueError`/`TOMLDecodeError`/`TypeError` with prose this port does
//! not reproduce verbatim; every case below is checked to be rejected, and the
//! accepted cases are compared field by field.

use suitedash::config::{apply_service_flags, parse_config, Config};

/// The dump spelling shared with the generator.
fn dump(cfg: &Config) -> String {
    let mut parts = vec![format!(
        "host={} port={} refresh={} timeout={:?} workers={} ttl={:?} \
         hist={} series={} alerts={} spark={}",
        cfg.host,
        cfg.port,
        cfg.refresh_seconds,
        cfg.timeout_seconds,
        cfg.max_workers,
        cfg.cache_ttl,
        cfg.history_capacity,
        cfg.history_max_series,
        cfg.alert_history,
        if cfg.sparklines { "True" } else { "False" }
    )];
    for s in &cfg.services {
        parts.push(format!(
            "svc {}|{}|{}|{}|{}|{}",
            s.name,
            s.base_url,
            s.health_path,
            s.metrics_path,
            s.metrics_keys.join(","),
            s.label
        ));
    }
    for r in &cfg.alert_rules {
        parts.push(format!(
            "rule {}|{}|{}|{}|{}|{:?}|{}|{}|{}",
            r.id,
            r.service,
            r.kind,
            r.metric,
            r.op,
            r.threshold,
            r.for_polls,
            r.severity,
            r.description
        ));
    }
    parts.join(" ;; ")
}

#[test]
fn load_config_matches_python() {
    // (toml, dump) — a dump of "ERROR" means CPython rejected the document.
    let cases: &[(&str, &str)] = &[
        // empty
        (
            r#""#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer"#,
        ),
        // toplevel
        (
            r#"host = "0.0.0.0"
port = 9000
refresh_seconds = 0
timeout_seconds = 1.5
max_workers = 0
cache_ttl = -2.0
history_capacity = 1
history_max_series = 999999999
alert_history = 0
sparklines = false
"#,
            r#"host=0.0.0.0 port=9000 refresh=0 timeout=1.5 workers=1 ttl=0.0 hist=2 series=100000 alerts=1 spark=False ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer"#,
        ),
        // clamp_high
        (
            r#"history_capacity = 999999
alert_history = 99999999
history_max_series = 0
max_workers = 3
cache_ttl = 2.5
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=3 ttl=2.5 hist=10000 series=1 alerts=10000 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer"#,
        ),
        // coercions
        (
            r#"host = 5
port = "9001"
refresh_seconds = 3.9
timeout_seconds = "2"
sparklines = 0
"#,
            r#"host=5 port=9001 refresh=3 timeout=2.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=False ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer"#,
        ),
        // services
        (
            r#"[[service]]
name = "only"
base_url = "http://x:1//"
metrics_keys = ["a", "b"]

[[service]]
name = "  spaced  "
base_url = " http://y:2 "
health_path = "/hz"
metrics_path = "/m"
label = "L"
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc only|http://x:1|/health|/metrics|a,b| ;; svc spaced|http://y:2|/hz|/m||L"#,
        ),
        // service_falsy_keys
        (
            r#"[[service]]
name = "x"
base_url = "http://x"
metrics_keys = []
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc x|http://x|/health|/metrics||"#,
        ),
        // service_int_keys
        (
            r#"[[service]]
name = "x"
base_url = "http://x"
metrics_keys = [1, 2.5, true]
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc x|http://x|/health|/metrics|1,2.5,True|"#,
        ),
        // alerts
        (
            r#"[[alert]]
id="busy"
service="gitweb"
metric="m"
op=">="
threshold=100
for=3
severity="warning"
description="d"

[[alert]]
id="down"
kind="down"
service="*"
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; rule busy|gitweb|metric|m|>=|100.0|3|warning|d ;; rule down|*|down||>|0.0|1|warning|"#,
        ),
        // alert_defaults
        (
            r#"[[alert]]
metric="m"

[[alert]]
kind="DOWN"
service="  "
severity="  CRITICAL "
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; rule rule-1|*|metric|m|>|0.0|1|warning| ;; rule rule-2|*|down||>|0.0|1|critical|"#,
        ),
        // alert_for_polls
        (
            r#"[[alert]]
id="a"
metric="m"
for=0

[[alert]]
id="b"
metric="m"
for_polls=99999999

[[alert]]
id="c"
metric="m"
for=2
for_polls=9
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; rule a|*|metric|m|>|0.0|1|warning| ;; rule b|*|metric|m|>|0.0|100000|warning| ;; rule c|*|metric|m|>|0.0|2|warning|"#,
        ),
        // alert_autoid
        (
            r#"[[alert]]
id="rule-2"
service="svc"
metric="cpu"
op=">"
threshold=90

[[alert]]
service="svc"
metric="mem"
op=">"
threshold=10

[[alert]]
service="svc"
metric="io"
op=">"
threshold=1
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; rule rule-2|svc|metric|cpu|>|90.0|1|warning| ;; rule rule-3|svc|metric|mem|>|10.0|1|warning| ;; rule rule-4|svc|metric|io|>|1.0|1|warning|"#,
        ),
        // alert_threshold_str
        (
            r#"[[alert]]
id="a"
metric="m"
threshold="1_000.5"
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; rule a|*|metric|m|>|1000.5|1|warning|"#,
        ),
        // comments
        (
            r#"# lead
port = 8080 # trailing

# another
host = 'lit'
"#,
            r#"host=lit port=8080 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer"#,
        ),
        // empty_arrays
        (
            r#"service = []
alert = []
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer"#,
        ),
        // example_file
        (
            r#"# suitedash configuration (TOML, parsed read-only with the stdlib tomllib).
#
# Every value below matches the built-in defaults, so this file is optional —
# `suitedash` runs with no config at all. Copy it, edit it, and pass it with
#   suitedash --config /etc/suitedash/suitedash.toml
#
# Top-level server settings ------------------------------------------------
host = "127.0.0.1"        # loopback only; put Tor / a reverse proxy in front
port = 8805               # the dashboard's own port
refresh_seconds = 15      # <meta refresh> interval; set <= 0 to disable
timeout_seconds = 3.0     # per-service probe budget (never blocks the page)
max_workers = 16          # max concurrent inbound connections (Slowloris guard)
cache_ttl = 0.0           # >0 caches a poll snapshot for N seconds

# History + sparklines (all in-memory, bounded, reset on restart) -----------
history_capacity = 60     # samples kept per (service, metric) sparkline ring
history_max_series = 256  # max distinct (service, metric) rings before eviction
alert_history = 128       # max alert firing/clear transitions retained
sparklines = true         # render inline-SVG sparklines on the page

# Services to poll ---------------------------------------------------------
# A [[service]] block: name + base_url, the health path to probe first (the
# prober falls back to /health, /healthz, /stats, /api/stats, / if it 404s),
# the metrics path (parsed as Prometheus text OR JSON automatically), and the
# metric keys to surface on the card. Omit metrics_keys to auto-pick a few.

[[service]]
name = "gitweb"
base_url = "http://127.0.0.1:8801"
health_path = "/health"
metrics_path = "/metrics"
metrics_keys = ["gitweb_requests_total", "gitweb_requests_in_flight", "gitweb_uptime_seconds"]
label = "Read-only git web viewer"

[[service]]
name = "onioncrawler"
base_url = "http://127.0.0.1:8802"
health_path = "/healthz"
metrics_path = "/metrics"
metrics_keys = ["onioncrawler_pages", "onioncrawler_hosts", "onioncrawler_frontier_queued"]
label = "Onion search / crawler"

[[service]]
name = "websearch"
base_url = "http://127.0.0.1:8803"
health_path = "/stats"
metrics_path = "/metrics"
metrics_keys = ["websearch_docs", "websearch_hosts", "websearch_searches_total"]
label = "Clear-web search"

[[service]]
name = "torrentds"
base_url = "http://127.0.0.1:8804"
health_path = "/health"
metrics_path = "/api/stats"   # JSON — parsed by the JSON path of the parser
metrics_keys = ["torrents", "pending", "total_size"]
label = "Torrent DHT indexer"

# Alert rules --------------------------------------------------------------
# Evaluated once per poll sweep. A [[alert]] block is either:
#   kind = "metric"  fires when `metric <op> threshold` holds for `for` sweeps
#                    (op is one of  >  >=  <  <=  ==  != ; only a service's
#                    surfaced metrics_keys are visible to rules), or
#   kind = "down"    fires when the service's last probe was DOWN.
# `service` is a service name or "*" (every polled service). State (firing/ok,
# since-when, last value) is tracked per (service, rule) and shown in the alerts
# panel and in /api/status. Rule count is bounded; omit the section for none.

[[alert]]
id = "any-service-down"
kind = "down"
service = "*"
for = 1
severity = "critical"
description = "A suite service is down"

[[alert]]
id = "gitweb-inflight-high"
kind = "metric"
service = "gitweb"
metric = "gitweb_requests_in_flight"
op = ">"
threshold = 100
for = 3            # must breach 3 consecutive sweeps before firing (debounce)
severity = "warning"
description = "gitweb has a sustained backlog of in-flight requests"
"#,
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; rule any-service-down|*|down||>|0.0|1|critical|A suite service is down ;; rule gitweb-inflight-high|gitweb|metric|gitweb_requests_in_flight|>|100.0|3|warning|gitweb has a sustained backlog of in-flight requests"#,
        ),
        // err_bad_op
        (
            r#"[[alert]]
id="x"
metric="m"
op="=~"
threshold=1
"#,
            r#"ERROR"#,
        ),
        // err_no_metric
        (
            r#"[[alert]]
id="x"
op=">"
threshold=1
"#,
            r#"ERROR"#,
        ),
        // err_dup_id
        (
            r#"[[alert]]
id="dup"
kind="down"

[[alert]]
id="dup"
kind="down"
"#,
            r#"ERROR"#,
        ),
        // err_bad_kind
        (
            r#"[[alert]]
id="x"
kind="weird"
"#,
            r#"ERROR"#,
        ),
        // err_threshold
        (
            r#"[[alert]]
id="x"
metric="m"
threshold="abc"
"#,
            r#"ERROR"#,
        ),
        // err_threshold_inf
        (
            r#"[[alert]]
id="x"
metric="m"
threshold=inf
"#,
            r#"ERROR"#,
        ),
        // err_for
        (
            r#"[[alert]]
id="x"
metric="m"
for="abc"
"#,
            r#"ERROR"#,
        ),
        // err_service_no_name
        (
            r#"[[service]]
base_url = "http://x"
"#,
            r#"ERROR"#,
        ),
        // err_service_no_base
        (
            r#"[[service]]
name = "x"
"#,
            r#"ERROR"#,
        ),
        // err_keys_not_list
        (
            r#"[[service]]
name="x"
base_url="http://x"
metrics_keys=5
"#,
            r#"ERROR"#,
        ),
        // err_service_not_array
        (
            r#"service = "nope"
"#,
            r#"ERROR"#,
        ),
        // err_alert_not_array
        (
            r#"alert = "nope"
"#,
            r#"ERROR"#,
        ),
        // err_toml_syntax
        (
            r#"port = 
"#,
            r#"ERROR"#,
        ),
        // err_toml_dup_key
        (
            r#"port = 1
port = 2
"#,
            r#"ERROR"#,
        ),
        // err_port_str
        (
            r#"port = "abc"
"#,
            r#"ERROR"#,
        ),
    ];
    for (text, want) in cases {
        match parse_config(text, None) {
            Ok(cfg) => assert_eq!(&dump(&cfg), want, "config: {text:?}"),
            Err(e) => assert_eq!(*want, "ERROR", "unexpected error for {text:?}: {e}"),
        }
    }
}

/// The shipped example config must round-trip through the Rust loader too — it
/// is the file the README tells operators to copy.
#[test]
fn shipped_example_config_parses() {
    // The shipped example config. It used to be included from the Python
    // package; it now lives in this crate (it documents THIS binary's options),
    // so the test tree has no dependency on a reference implementation that is
    // no longer present.
    let text = include_str!("../suitedash.example.toml");
    let cfg = parse_config(text, None).expect("the shipped example config must parse");
    assert_eq!(cfg.services.len(), 4);
    assert_eq!(cfg.alert_rules.len(), 2);
}

#[test]
fn rule_count_is_bounded_like_python() {
    let mut text = String::new();
    for i in 0..suitedash::config::MAX_RULES + 25 {
        text.push_str(&format!(
            "[[alert]]\nid=\"r{i}\"\nmetric=\"m\"\nop=\">\"\nthreshold=1\n\n"
        ));
    }
    let cfg = parse_config(&text, None).unwrap();
    assert_eq!(cfg.alert_rules.len(), suitedash::config::MAX_RULES);
    assert_eq!(cfg.alert_rules[255].id, "r255");
}

#[test]
fn apply_service_flags_matches_python() {
    let cases: &[(&[&str], &str)] = &[
        (
            &["gitweb=http://10.0.0.5:8801/"],
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://10.0.0.5:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer"#,
        ),
        (
            &["newsvc=http://h:9/"],
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; svc newsvc|http://h:9|/health|/metrics||"#,
        ),
        (
            &["gitweb=http://a", "newsvc=http://b", "gitweb=http://c/"],
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://c|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; svc newsvc|http://b|/health|/metrics||"#,
        ),
        (
            &["  spaced  =  http://d//  "],
            r#"host=127.0.0.1 port=8805 refresh=15 timeout=3.0 workers=16 ttl=0.0 hist=60 series=256 alerts=128 spark=True ;; svc gitweb|http://127.0.0.1:8801|/health|/metrics|gitweb_requests_total,gitweb_requests_in_flight,gitweb_uptime_seconds|Read-only git web viewer ;; svc onioncrawler|http://127.0.0.1:8802|/healthz|/metrics|onioncrawler_pages,onioncrawler_hosts,onioncrawler_frontier_queued|Onion search / crawler ;; svc websearch|http://127.0.0.1:8803|/stats|/metrics|websearch_docs,websearch_hosts,websearch_searches_total|Clear-web search ;; svc torrentds|http://127.0.0.1:8804|/health|/api/stats|torrents,pending,total_size|Torrent DHT indexer ;; svc spaced|http://d|/health|/metrics||"#,
        ),
        (&["oops"], r#"ERROR"#),
        (&["=http://x"], r#"ERROR"#),
        (&["name="], r#"ERROR"#),
    ];
    for (specs, want) in cases {
        let specs: Vec<String> = specs.iter().map(|s| (*s).to_string()).collect();
        match apply_service_flags(Config::default(), &specs) {
            Ok(cfg) => assert_eq!(&dump(&cfg), want, "flags: {specs:?}"),
            Err(_) => assert_eq!(*want, "ERROR", "unexpected error for {specs:?}"),
        }
    }
}
