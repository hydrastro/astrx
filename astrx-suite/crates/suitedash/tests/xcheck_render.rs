//! Cross-check: the Rust `suitedash::render` reproduces the Python
//! `suitedash.render` byte-identically — the whole no-JS HTML page (inline
//! stylesheet, `<meta refresh>`, summary pill, alerts panel, per-service cards
//! with inline-SVG sparklines, latency/number formatting, escaped hostile names,
//! labels and error strings) and the `/api/status` JSON payload
//! (`json.dumps(indent=2)` layout, key order, `int`-vs-`float` rendering, the
//! `ensure_ascii` `\uXXXX` escapes with surrogate pairs, and the `alerts` block
//! with its bounded event log).
//!
//! Five scenarios: a mixed up/down suite with a snapshot, a single service with
//! no snapshot and refresh disabled, a hostile-input page, an all-clear alerts
//! panel, and an empty service list. Goldens emitted by
//! `tests/regen_goldens.py` (section `render`), which drives the real Python
//! renderers with a stubbed `time` module (the Rust renderers take `now` as an
//! argument).

use suitedash::alerts::AlertEngine;
use suitedash::config::{AlertRule, Config};
use suitedash::history::History;
use suitedash::metrics::{OrderedMap, Results, ServiceResult, SurfacedMetrics};
use suitedash::render::{render_page, render_status_json, Snapshot};

/// The Python `_rules()` fixture: a debounced metric rule, a wildcard `down`
/// rule, a wildcard metric rule, a rule aimed at a service that is never polled,
/// and one carrying an operator the engine does not know.
fn rules() -> Vec<AlertRule> {
    vec![
        AlertRule {
            id: "busy".to_string(),
            service: "alpha".to_string(),
            kind: "metric".to_string(),
            metric: "q".to_string(),
            op: ">".to_string(),
            threshold: 10.0,
            for_polls: 3,
            severity: "warning".to_string(),
            description: "alpha queue is deep".to_string(),
        },
        AlertRule {
            id: "down".to_string(),
            service: "*".to_string(),
            kind: "down".to_string(),
            for_polls: 1,
            severity: "critical".to_string(),
            description: "a suite service is down".to_string(),
            ..AlertRule::default()
        },
        AlertRule {
            id: "mem".to_string(),
            service: "*".to_string(),
            kind: "metric".to_string(),
            metric: "mem".to_string(),
            op: ">=".to_string(),
            threshold: 100.0,
            for_polls: 2,
            severity: "info".to_string(),
            description: String::new(),
        },
        AlertRule {
            id: "ghost".to_string(),
            service: "nosuch".to_string(),
            kind: "down".to_string(),
            for_polls: 1,
            severity: "info".to_string(),
            description: "never targeted".to_string(),
            ..AlertRule::default()
        },
        AlertRule {
            id: "weird".to_string(),
            service: "alpha".to_string(),
            kind: "metric".to_string(),
            metric: "q".to_string(),
            op: "~~".to_string(),
            threshold: 0.0,
            for_polls: 1,
            severity: "nonsense".to_string(),
            description: "unknown operator".to_string(),
        },
    ]
}

#[allow(clippy::too_many_arguments)]
fn res(
    name: &str,
    up: bool,
    metrics: &[(&str, Option<f64>)],
    latency: Option<f64>,
    checked_at: f64,
    error: Option<&str>,
    health_path: Option<&str>,
    label: &str,
) -> ServiceResult {
    let mut r = ServiceResult::new(name, "http://x", up);
    let mut m = SurfacedMetrics::new();
    for (k, v) in metrics {
        m.insert(*k, *v);
    }
    r.metrics = m;
    r.latency_ms = latency;
    r.checked_at = checked_at;
    r.error = error.map(str::to_string);
    r.health_path = health_path.map(str::to_string);
    r.label = label.to_string();
    r
}

fn sweep(rs: Vec<ServiceResult>) -> Results {
    let mut out = Results::new();
    for r in rs {
        out.insert(r.name.clone(), r);
    }
    out
}

fn plain(name: &str, up: bool, metrics: &[(&str, Option<f64>)]) -> ServiceResult {
    res(name, up, metrics, None, 0.0, None, None, "")
}

/// The `gitweb` card fixture (UP, three surfaced metrics, one absent).
fn up_service() -> ServiceResult {
    res(
        "gitweb",
        true,
        &[
            ("gitweb_requests_total", Some(1204.0)),
            ("gitweb_uptime_seconds", Some(512.4)),
            ("gitweb_missing", None),
        ],
        Some(3.25),
        1_723_000_000.5,
        None,
        Some("/health"),
        "Read-only git web viewer",
    )
}

/// The snapshot of the `full` scenario: two firing alerts + two sparkline series.
fn full_snapshot() -> Snapshot {
    let mut eng = AlertEngine::new(&rules(), 6);
    for _ in 0..3 {
        eng.update(
            &sweep(vec![
                plain("gitweb", true, &[("q", Some(50.0)), ("mem", Some(500.0))]),
                plain("torrentds", false, &[]),
            ]),
            1_723_000_000.0,
        );
    }
    let mut series: OrderedMap<OrderedMap<Vec<f64>>> = OrderedMap::new();
    let mut inner: OrderedMap<Vec<f64>> = OrderedMap::new();
    inner.insert("gitweb_requests_total", vec![1200.0, 1202.0, 1204.0]);
    inner.insert("gitweb_uptime_seconds", vec![500.0, 506.2, 512.4]);
    series.insert("gitweb", inner);
    Snapshot::new(eng.views(), series, eng.events(), rules().len())
}

/// The `allclear` scenario's snapshot: one non-firing wildcard `down` rule.
fn ok_snapshot() -> Snapshot {
    let only_down: Vec<AlertRule> = rules()[1..2].to_vec();
    let mut eng = AlertEngine::new(&only_down, 4);
    // Stamped at t=0 so the JSON exercises `round(since, 3) if since else None`.
    eng.update(&sweep(vec![plain("gitweb", true, &[])]), 0.0);
    Snapshot::new(eng.views(), OrderedMap::new(), eng.events(), 1)
}

fn scenarios() -> Vec<(&'static str, Results, Config, Option<Snapshot>, f64)> {
    let down = res(
        "torrentds",
        false,
        &[],
        None,
        1_723_000_000.25,
        Some("connection refused"),
        None,
        "Torrent DHT indexer",
    );
    let hostile = res(
        "<b>\"ev&il\"</b>",
        true,
        &[
            ("a<b>", Some(1.0)),
            ("café", Some(0.5)),
            ("huge", Some(1e300)),
            ("big_int", Some(12_345_678_901_234_567_890.0)),
            ("tiny", Some(1e-7)),
            ("grouped", Some(9_876_543.25)),
        ],
        Some(1500.0),
        1_723_000_000.0,
        None,
        Some("/x"),
        "café — résumé \u{1f600}",
    );
    let hostile_down = res(
        "bad'svc",
        false,
        &[],
        None,
        1_723_000_000.0,
        Some("timeout <script>alert(\"x\")</script>"),
        None,
        "",
    );
    let cfg = |refresh: i64, sparklines: bool| Config {
        refresh_seconds: refresh,
        sparklines,
        ..Config::default()
    };
    vec![
        (
            "full",
            sweep(vec![up_service(), down]),
            cfg(15, true),
            Some(full_snapshot()),
            1_723_000_123.456,
        ),
        (
            "nosnapshot",
            sweep(vec![up_service()]),
            cfg(0, false),
            None,
            1_723_000_123.0,
        ),
        (
            "hostile",
            sweep(vec![hostile, hostile_down]),
            cfg(5, true),
            Some(Snapshot::default()),
            1_723_000_000.0,
        ),
        (
            "allclear",
            sweep(vec![up_service()]),
            cfg(15, true),
            Some(ok_snapshot()),
            1_723_000_000.0,
        ),
        (
            "empty",
            Results::new(),
            cfg(15, true),
            None,
            1_723_000_000.0,
        ),
    ]
}

#[test]
fn page_matches_python() {
    let want: &[(&str, &str)] = &[
        (
            "full",
            r#"<!doctype html><html lang=en><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="15"><title>astrx-suite status</title><style>:root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#16181d;
  --muted:#5b6472; --line:#e4e7ec; --up:#16794b; --up-bg:#e7f6ee;
  --down:#b42318; --down-bg:#fdecea; --accent:#1a56db; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#0d1117; --card:#161b22; --ink:#e6edf3; --muted:#8b949e;
    --line:#2a2f37; --up:#3fb950; --up-bg:#12261a; --down:#f85149;
    --down-bg:#2b1514; --accent:#539bf5; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--ink);
  font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
header { padding:22px 20px 8px; }
.wrap { max-width:1000px; margin:0 auto; }
h1 { margin:0; font-size:20px; letter-spacing:-.3px; }
h1 span { color:var(--accent); }
.sub { color:var(--muted); font-size:13px; margin-top:2px; }
.summary { display:inline-block; margin-top:12px; padding:6px 12px; border-radius:999px;
  font-size:13px; font-weight:600; border:1px solid var(--line); }
.summary.ok { color:var(--up); background:var(--up-bg); border-color:transparent; }
.summary.bad { color:var(--down); background:var(--down-bg); border-color:transparent; }
.grid { display:grid; gap:14px; padding:14px 20px 28px;
  grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px;
  padding:16px 16px 14px; }
.card.down { border-color:var(--down); }
.top { display:flex; align-items:baseline; justify-content:space-between; gap:10px; }
.name { font-weight:700; font-size:16px; }
.badge { font-size:12px; font-weight:700; padding:3px 9px; border-radius:999px;
  letter-spacing:.3px; }
.badge.up { color:var(--up); background:var(--up-bg); }
.badge.down { color:var(--down); background:var(--down-bg); }
.label { color:var(--muted); font-size:12px; margin:2px 0 10px; }
.lat { font-size:13px; color:var(--muted); margin-bottom:10px; }
.lat b { color:var(--ink); font-variant-numeric:tabular-nums; }
table.m { width:100%; border-collapse:collapse; font-size:13px; }
table.m td { padding:3px 0; border-top:1px solid var(--line); }
table.m td.k { color:var(--muted); }
table.m td.v { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
table.m tr:first-child td { border-top:0; }
.err { color:var(--down); font-size:12px; margin-top:8px; word-break:break-word; }
.meta { color:var(--muted); font-size:12px; margin-top:10px; }
table.m td.s { width:104px; text-align:right; padding-left:10px; }
svg.spark { color:var(--accent); vertical-align:middle; opacity:.85; }
.alerts { padding:0 20px; margin:6px auto 0; max-width:1000px; }
.alerts-h { margin-bottom:8px; }
.alerts-ok { display:inline-block; padding:6px 12px; border-radius:999px; font-size:13px;
  font-weight:600; color:var(--up); background:var(--up-bg); }
.alert { display:flex; flex-wrap:wrap; gap:8px; align-items:baseline;
  background:var(--card); border:1px solid var(--line); border-left:4px solid var(--muted);
  border-radius:8px; padding:8px 12px; margin-bottom:8px; font-size:13px; }
.alert.firing { border-left-color:var(--down); background:var(--down-bg); }
.alert .al-svc { font-weight:700; }
.alert .al-cond { font-variant-numeric:tabular-nums; color:var(--down); font-weight:600; }
.alert .al-meta { color:var(--muted); margin-left:auto; }
.alert-log { color:var(--muted); font-size:12px; margin-top:2px; }
footer { color:var(--muted); font-size:12px; text-align:center; padding:0 20px 26px; }
footer code { background:var(--card); padding:1px 5px; border-radius:5px; border:1px solid var(--line); }
a { color:var(--accent); text-decoration:none; }</style></head><body><header><div class=wrap><h1>astrx&#8209;<span>suite</span> status</h1><div class=sub>service health, latency and key metrics</div><div class="summary bad">1 of 2 services DOWN</div></div></header><section class=alerts><div class="alerts-h"><div class="summary bad">2 alerts firing</div></div><div class="alert firing"><span class=al-svc>torrentds</span><span class=al-desc>a suite service is down</span><span class=al-cond>service down</span><span class=al-meta>since 03:06:40 UTC</span></div><div class="alert firing"><span class=al-svc>gitweb</span><span class=al-desc>mem</span><span class=al-cond>mem &gt;= 100</span><span class=al-meta>last 500 &middot; since 03:06:40 UTC</span></div></section><main class=wrap><div class=grid><div class="card"><div class=top><span class=name>gitweb</span><span class="badge up">UP</span></div><div class=label>Read-only git web viewer</div><div class=lat>latency <b>3 ms</b></div><table class=m><tr><td class=k>gitweb_requests_total</td><td class=s><svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 50,10 100,1"/></svg></td><td class=v>1,204</td></tr><tr><td class=k>gitweb_uptime_seconds</td><td class=s><svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 50,10 100,1"/></svg></td><td class=v>512.4</td></tr><tr><td class=k>gitweb_missing</td><td class=s></td><td class=v>n/a</td></tr></table><div class=meta>http://x &middot; checked 03:06:40 UTC</div></div><div class="card down"><div class=top><span class=name>torrentds</span><span class="badge down">DOWN</span></div><div class=label>Torrent DHT indexer</div><div class=lat>latency <b>—</b></div><div class=err>connection refused</div><div class=meta>http://x &middot; checked 03:06:40 UTC</div></div></div></main><footer>Generated 03:08:43 UTC &middot; auto-refreshes every 15s &middot; no JavaScript &middot; <a href=/api/status>/api/status</a> &middot; <a href=/metrics>/metrics</a></footer></body></html>"#,
        ),
        (
            "nosnapshot",
            r#"<!doctype html><html lang=en><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><title>astrx-suite status</title><style>:root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#16181d;
  --muted:#5b6472; --line:#e4e7ec; --up:#16794b; --up-bg:#e7f6ee;
  --down:#b42318; --down-bg:#fdecea; --accent:#1a56db; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#0d1117; --card:#161b22; --ink:#e6edf3; --muted:#8b949e;
    --line:#2a2f37; --up:#3fb950; --up-bg:#12261a; --down:#f85149;
    --down-bg:#2b1514; --accent:#539bf5; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--ink);
  font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
header { padding:22px 20px 8px; }
.wrap { max-width:1000px; margin:0 auto; }
h1 { margin:0; font-size:20px; letter-spacing:-.3px; }
h1 span { color:var(--accent); }
.sub { color:var(--muted); font-size:13px; margin-top:2px; }
.summary { display:inline-block; margin-top:12px; padding:6px 12px; border-radius:999px;
  font-size:13px; font-weight:600; border:1px solid var(--line); }
.summary.ok { color:var(--up); background:var(--up-bg); border-color:transparent; }
.summary.bad { color:var(--down); background:var(--down-bg); border-color:transparent; }
.grid { display:grid; gap:14px; padding:14px 20px 28px;
  grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px;
  padding:16px 16px 14px; }
.card.down { border-color:var(--down); }
.top { display:flex; align-items:baseline; justify-content:space-between; gap:10px; }
.name { font-weight:700; font-size:16px; }
.badge { font-size:12px; font-weight:700; padding:3px 9px; border-radius:999px;
  letter-spacing:.3px; }
.badge.up { color:var(--up); background:var(--up-bg); }
.badge.down { color:var(--down); background:var(--down-bg); }
.label { color:var(--muted); font-size:12px; margin:2px 0 10px; }
.lat { font-size:13px; color:var(--muted); margin-bottom:10px; }
.lat b { color:var(--ink); font-variant-numeric:tabular-nums; }
table.m { width:100%; border-collapse:collapse; font-size:13px; }
table.m td { padding:3px 0; border-top:1px solid var(--line); }
table.m td.k { color:var(--muted); }
table.m td.v { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
table.m tr:first-child td { border-top:0; }
.err { color:var(--down); font-size:12px; margin-top:8px; word-break:break-word; }
.meta { color:var(--muted); font-size:12px; margin-top:10px; }
table.m td.s { width:104px; text-align:right; padding-left:10px; }
svg.spark { color:var(--accent); vertical-align:middle; opacity:.85; }
.alerts { padding:0 20px; margin:6px auto 0; max-width:1000px; }
.alerts-h { margin-bottom:8px; }
.alerts-ok { display:inline-block; padding:6px 12px; border-radius:999px; font-size:13px;
  font-weight:600; color:var(--up); background:var(--up-bg); }
.alert { display:flex; flex-wrap:wrap; gap:8px; align-items:baseline;
  background:var(--card); border:1px solid var(--line); border-left:4px solid var(--muted);
  border-radius:8px; padding:8px 12px; margin-bottom:8px; font-size:13px; }
.alert.firing { border-left-color:var(--down); background:var(--down-bg); }
.alert .al-svc { font-weight:700; }
.alert .al-cond { font-variant-numeric:tabular-nums; color:var(--down); font-weight:600; }
.alert .al-meta { color:var(--muted); margin-left:auto; }
.alert-log { color:var(--muted); font-size:12px; margin-top:2px; }
footer { color:var(--muted); font-size:12px; text-align:center; padding:0 20px 26px; }
footer code { background:var(--card); padding:1px 5px; border-radius:5px; border:1px solid var(--line); }
a { color:var(--accent); text-decoration:none; }</style></head><body><header><div class=wrap><h1>astrx&#8209;<span>suite</span> status</h1><div class=sub>service health, latency and key metrics</div><div class="summary ok">All systems operational &middot; 1/1 up</div></div></header><main class=wrap><div class=grid><div class="card"><div class=top><span class=name>gitweb</span><span class="badge up">UP</span></div><div class=label>Read-only git web viewer</div><div class=lat>latency <b>3 ms</b></div><table class=m><tr><td class=k>gitweb_requests_total</td><td class=v>1,204</td></tr><tr><td class=k>gitweb_uptime_seconds</td><td class=v>512.4</td></tr><tr><td class=k>gitweb_missing</td><td class=v>n/a</td></tr></table><div class=meta>http://x &middot; checked 03:06:40 UTC</div></div></div></main><footer>Generated 03:08:43 UTC &middot; auto-refresh disabled &middot; no JavaScript &middot; <a href=/api/status>/api/status</a> &middot; <a href=/metrics>/metrics</a></footer></body></html>"#,
        ),
        (
            "hostile",
            r#"<!doctype html><html lang=en><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="5"><title>astrx-suite status</title><style>:root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#16181d;
  --muted:#5b6472; --line:#e4e7ec; --up:#16794b; --up-bg:#e7f6ee;
  --down:#b42318; --down-bg:#fdecea; --accent:#1a56db; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#0d1117; --card:#161b22; --ink:#e6edf3; --muted:#8b949e;
    --line:#2a2f37; --up:#3fb950; --up-bg:#12261a; --down:#f85149;
    --down-bg:#2b1514; --accent:#539bf5; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--ink);
  font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
header { padding:22px 20px 8px; }
.wrap { max-width:1000px; margin:0 auto; }
h1 { margin:0; font-size:20px; letter-spacing:-.3px; }
h1 span { color:var(--accent); }
.sub { color:var(--muted); font-size:13px; margin-top:2px; }
.summary { display:inline-block; margin-top:12px; padding:6px 12px; border-radius:999px;
  font-size:13px; font-weight:600; border:1px solid var(--line); }
.summary.ok { color:var(--up); background:var(--up-bg); border-color:transparent; }
.summary.bad { color:var(--down); background:var(--down-bg); border-color:transparent; }
.grid { display:grid; gap:14px; padding:14px 20px 28px;
  grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px;
  padding:16px 16px 14px; }
.card.down { border-color:var(--down); }
.top { display:flex; align-items:baseline; justify-content:space-between; gap:10px; }
.name { font-weight:700; font-size:16px; }
.badge { font-size:12px; font-weight:700; padding:3px 9px; border-radius:999px;
  letter-spacing:.3px; }
.badge.up { color:var(--up); background:var(--up-bg); }
.badge.down { color:var(--down); background:var(--down-bg); }
.label { color:var(--muted); font-size:12px; margin:2px 0 10px; }
.lat { font-size:13px; color:var(--muted); margin-bottom:10px; }
.lat b { color:var(--ink); font-variant-numeric:tabular-nums; }
table.m { width:100%; border-collapse:collapse; font-size:13px; }
table.m td { padding:3px 0; border-top:1px solid var(--line); }
table.m td.k { color:var(--muted); }
table.m td.v { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
table.m tr:first-child td { border-top:0; }
.err { color:var(--down); font-size:12px; margin-top:8px; word-break:break-word; }
.meta { color:var(--muted); font-size:12px; margin-top:10px; }
table.m td.s { width:104px; text-align:right; padding-left:10px; }
svg.spark { color:var(--accent); vertical-align:middle; opacity:.85; }
.alerts { padding:0 20px; margin:6px auto 0; max-width:1000px; }
.alerts-h { margin-bottom:8px; }
.alerts-ok { display:inline-block; padding:6px 12px; border-radius:999px; font-size:13px;
  font-weight:600; color:var(--up); background:var(--up-bg); }
.alert { display:flex; flex-wrap:wrap; gap:8px; align-items:baseline;
  background:var(--card); border:1px solid var(--line); border-left:4px solid var(--muted);
  border-radius:8px; padding:8px 12px; margin-bottom:8px; font-size:13px; }
.alert.firing { border-left-color:var(--down); background:var(--down-bg); }
.alert .al-svc { font-weight:700; }
.alert .al-cond { font-variant-numeric:tabular-nums; color:var(--down); font-weight:600; }
.alert .al-meta { color:var(--muted); margin-left:auto; }
.alert-log { color:var(--muted); font-size:12px; margin-top:2px; }
footer { color:var(--muted); font-size:12px; text-align:center; padding:0 20px 26px; }
footer code { background:var(--card); padding:1px 5px; border-radius:5px; border:1px solid var(--line); }
a { color:var(--accent); text-decoration:none; }</style></head><body><header><div class=wrap><h1>astrx&#8209;<span>suite</span> status</h1><div class=sub>service health, latency and key metrics</div><div class="summary bad">1 of 2 services DOWN</div></div></header><main class=wrap><div class=grid><div class="card"><div class=top><span class=name>&lt;b&gt;&quot;ev&amp;il&quot;&lt;/b&gt;</span><span class="badge up">UP</span></div><div class=label>café — résumé 😀</div><div class=lat>latency <b>1.50 s</b></div><table class=m><tr><td class=k>a&lt;b&gt;</td><td class=s></td><td class=v>1</td></tr><tr><td class=k>café</td><td class=s></td><td class=v>0.5</td></tr><tr><td class=k>huge</td><td class=s></td><td class=v>1,000,000,000,000,000,052,504,760,255,204,420,248,704,468,581,108,159,154,915,854,115,511,802,457,988,908,195,786,371,375,080,447,864,043,704,443,832,883,878,176,942,523,235,360,430,575,644,792,184,786,706,982,848,387,200,926,575,803,737,830,233,794,788,090,059,368,953,234,970,799,945,081,119,038,967,640,880,074,652,742,780,142,494,579,258,788,820,056,842,838,115,669,472,196,386,865,459,400,540,160</td></tr><tr><td class=k>big_int</td><td class=s></td><td class=v>12,345,678,901,234,567,168</td></tr><tr><td class=k>tiny</td><td class=s></td><td class=v>0</td></tr><tr><td class=k>grouped</td><td class=s></td><td class=v>9,876,543.25</td></tr></table><div class=meta>http://x &middot; checked 03:06:40 UTC</div></div><div class="card down"><div class=top><span class=name>bad&#x27;svc</span><span class="badge down">DOWN</span></div><div class=lat>latency <b>—</b></div><div class=err>timeout &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</div><div class=meta>http://x &middot; checked 03:06:40 UTC</div></div></div></main><footer>Generated 03:06:40 UTC &middot; auto-refreshes every 5s &middot; no JavaScript &middot; <a href=/api/status>/api/status</a> &middot; <a href=/metrics>/metrics</a></footer></body></html>"#,
        ),
        (
            "allclear",
            r#"<!doctype html><html lang=en><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="15"><title>astrx-suite status</title><style>:root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#16181d;
  --muted:#5b6472; --line:#e4e7ec; --up:#16794b; --up-bg:#e7f6ee;
  --down:#b42318; --down-bg:#fdecea; --accent:#1a56db; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#0d1117; --card:#161b22; --ink:#e6edf3; --muted:#8b949e;
    --line:#2a2f37; --up:#3fb950; --up-bg:#12261a; --down:#f85149;
    --down-bg:#2b1514; --accent:#539bf5; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--ink);
  font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
header { padding:22px 20px 8px; }
.wrap { max-width:1000px; margin:0 auto; }
h1 { margin:0; font-size:20px; letter-spacing:-.3px; }
h1 span { color:var(--accent); }
.sub { color:var(--muted); font-size:13px; margin-top:2px; }
.summary { display:inline-block; margin-top:12px; padding:6px 12px; border-radius:999px;
  font-size:13px; font-weight:600; border:1px solid var(--line); }
.summary.ok { color:var(--up); background:var(--up-bg); border-color:transparent; }
.summary.bad { color:var(--down); background:var(--down-bg); border-color:transparent; }
.grid { display:grid; gap:14px; padding:14px 20px 28px;
  grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px;
  padding:16px 16px 14px; }
.card.down { border-color:var(--down); }
.top { display:flex; align-items:baseline; justify-content:space-between; gap:10px; }
.name { font-weight:700; font-size:16px; }
.badge { font-size:12px; font-weight:700; padding:3px 9px; border-radius:999px;
  letter-spacing:.3px; }
.badge.up { color:var(--up); background:var(--up-bg); }
.badge.down { color:var(--down); background:var(--down-bg); }
.label { color:var(--muted); font-size:12px; margin:2px 0 10px; }
.lat { font-size:13px; color:var(--muted); margin-bottom:10px; }
.lat b { color:var(--ink); font-variant-numeric:tabular-nums; }
table.m { width:100%; border-collapse:collapse; font-size:13px; }
table.m td { padding:3px 0; border-top:1px solid var(--line); }
table.m td.k { color:var(--muted); }
table.m td.v { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
table.m tr:first-child td { border-top:0; }
.err { color:var(--down); font-size:12px; margin-top:8px; word-break:break-word; }
.meta { color:var(--muted); font-size:12px; margin-top:10px; }
table.m td.s { width:104px; text-align:right; padding-left:10px; }
svg.spark { color:var(--accent); vertical-align:middle; opacity:.85; }
.alerts { padding:0 20px; margin:6px auto 0; max-width:1000px; }
.alerts-h { margin-bottom:8px; }
.alerts-ok { display:inline-block; padding:6px 12px; border-radius:999px; font-size:13px;
  font-weight:600; color:var(--up); background:var(--up-bg); }
.alert { display:flex; flex-wrap:wrap; gap:8px; align-items:baseline;
  background:var(--card); border:1px solid var(--line); border-left:4px solid var(--muted);
  border-radius:8px; padding:8px 12px; margin-bottom:8px; font-size:13px; }
.alert.firing { border-left-color:var(--down); background:var(--down-bg); }
.alert .al-svc { font-weight:700; }
.alert .al-cond { font-variant-numeric:tabular-nums; color:var(--down); font-weight:600; }
.alert .al-meta { color:var(--muted); margin-left:auto; }
.alert-log { color:var(--muted); font-size:12px; margin-top:2px; }
footer { color:var(--muted); font-size:12px; text-align:center; padding:0 20px 26px; }
footer code { background:var(--card); padding:1px 5px; border-radius:5px; border:1px solid var(--line); }
a { color:var(--accent); text-decoration:none; }</style></head><body><header><div class=wrap><h1>astrx&#8209;<span>suite</span> status</h1><div class=sub>service health, latency and key metrics</div><div class="summary ok">All systems operational &middot; 1/1 up</div></div></header><section class=alerts><div class=alerts-ok>All 1 alert rule OK</div></section><main class=wrap><div class=grid><div class="card"><div class=top><span class=name>gitweb</span><span class="badge up">UP</span></div><div class=label>Read-only git web viewer</div><div class=lat>latency <b>3 ms</b></div><table class=m><tr><td class=k>gitweb_requests_total</td><td class=s></td><td class=v>1,204</td></tr><tr><td class=k>gitweb_uptime_seconds</td><td class=s></td><td class=v>512.4</td></tr><tr><td class=k>gitweb_missing</td><td class=s></td><td class=v>n/a</td></tr></table><div class=meta>http://x &middot; checked 03:06:40 UTC</div></div></div></main><footer>Generated 03:06:40 UTC &middot; auto-refreshes every 15s &middot; no JavaScript &middot; <a href=/api/status>/api/status</a> &middot; <a href=/metrics>/metrics</a></footer></body></html>"#,
        ),
        (
            "empty",
            r#"<!doctype html><html lang=en><head><meta charset=utf-8><meta name=viewport content="width=device-width, initial-scale=1"><meta http-equiv="refresh" content="15"><title>astrx-suite status</title><style>:root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#16181d;
  --muted:#5b6472; --line:#e4e7ec; --up:#16794b; --up-bg:#e7f6ee;
  --down:#b42318; --down-bg:#fdecea; --accent:#1a56db; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#0d1117; --card:#161b22; --ink:#e6edf3; --muted:#8b949e;
    --line:#2a2f37; --up:#3fb950; --up-bg:#12261a; --down:#f85149;
    --down-bg:#2b1514; --accent:#539bf5; } }
* { box-sizing: border-box; }
body { margin:0; background:var(--bg); color:var(--ink);
  font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
header { padding:22px 20px 8px; }
.wrap { max-width:1000px; margin:0 auto; }
h1 { margin:0; font-size:20px; letter-spacing:-.3px; }
h1 span { color:var(--accent); }
.sub { color:var(--muted); font-size:13px; margin-top:2px; }
.summary { display:inline-block; margin-top:12px; padding:6px 12px; border-radius:999px;
  font-size:13px; font-weight:600; border:1px solid var(--line); }
.summary.ok { color:var(--up); background:var(--up-bg); border-color:transparent; }
.summary.bad { color:var(--down); background:var(--down-bg); border-color:transparent; }
.grid { display:grid; gap:14px; padding:14px 20px 28px;
  grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px;
  padding:16px 16px 14px; }
.card.down { border-color:var(--down); }
.top { display:flex; align-items:baseline; justify-content:space-between; gap:10px; }
.name { font-weight:700; font-size:16px; }
.badge { font-size:12px; font-weight:700; padding:3px 9px; border-radius:999px;
  letter-spacing:.3px; }
.badge.up { color:var(--up); background:var(--up-bg); }
.badge.down { color:var(--down); background:var(--down-bg); }
.label { color:var(--muted); font-size:12px; margin:2px 0 10px; }
.lat { font-size:13px; color:var(--muted); margin-bottom:10px; }
.lat b { color:var(--ink); font-variant-numeric:tabular-nums; }
table.m { width:100%; border-collapse:collapse; font-size:13px; }
table.m td { padding:3px 0; border-top:1px solid var(--line); }
table.m td.k { color:var(--muted); }
table.m td.v { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
table.m tr:first-child td { border-top:0; }
.err { color:var(--down); font-size:12px; margin-top:8px; word-break:break-word; }
.meta { color:var(--muted); font-size:12px; margin-top:10px; }
table.m td.s { width:104px; text-align:right; padding-left:10px; }
svg.spark { color:var(--accent); vertical-align:middle; opacity:.85; }
.alerts { padding:0 20px; margin:6px auto 0; max-width:1000px; }
.alerts-h { margin-bottom:8px; }
.alerts-ok { display:inline-block; padding:6px 12px; border-radius:999px; font-size:13px;
  font-weight:600; color:var(--up); background:var(--up-bg); }
.alert { display:flex; flex-wrap:wrap; gap:8px; align-items:baseline;
  background:var(--card); border:1px solid var(--line); border-left:4px solid var(--muted);
  border-radius:8px; padding:8px 12px; margin-bottom:8px; font-size:13px; }
.alert.firing { border-left-color:var(--down); background:var(--down-bg); }
.alert .al-svc { font-weight:700; }
.alert .al-cond { font-variant-numeric:tabular-nums; color:var(--down); font-weight:600; }
.alert .al-meta { color:var(--muted); margin-left:auto; }
.alert-log { color:var(--muted); font-size:12px; margin-top:2px; }
footer { color:var(--muted); font-size:12px; text-align:center; padding:0 20px 26px; }
footer code { background:var(--card); padding:1px 5px; border-radius:5px; border:1px solid var(--line); }
a { color:var(--accent); text-decoration:none; }</style></head><body><header><div class=wrap><h1>astrx&#8209;<span>suite</span> status</h1><div class=sub>service health, latency and key metrics</div><div class="summary ok">All systems operational &middot; 0/0 up</div></div></header><main class=wrap><div class=grid></div></main><footer>Generated 03:06:40 UTC &middot; auto-refreshes every 15s &middot; no JavaScript &middot; <a href=/api/status>/api/status</a> &middot; <a href=/metrics>/metrics</a></footer></body></html>"#,
        ),
    ];
    for ((label, results, config, snapshot, now), (wlabel, wpage)) in scenarios().iter().zip(want) {
        assert_eq!(label, wlabel, "scenario order");
        assert_eq!(
            &render_page(results, config, snapshot.as_ref(), *now),
            wpage,
            "page: {label}"
        );
    }
}

#[test]
fn status_json_matches_python() {
    let want: &[(&str, &str)] = &[
        (
            "full",
            r#"{
  "generated_at": 1723000123.456,
  "summary": {
    "total": 2,
    "up": 1,
    "down": 1,
    "all_up": false
  },
  "services": {
    "gitweb": {
      "up": true,
      "latency_ms": 3.25,
      "metrics": {
        "gitweb_requests_total": 1204,
        "gitweb_uptime_seconds": 512.4,
        "gitweb_missing": null
      },
      "checked_at": 1723000000.5,
      "error": null,
      "health_path": "/health"
    },
    "torrentds": {
      "up": false,
      "latency_ms": null,
      "metrics": {},
      "checked_at": 1723000000.25,
      "error": "connection refused",
      "health_path": null
    }
  },
  "alerts": {
    "rules": 5,
    "firing": 2,
    "states": [
      {
        "service": "torrentds",
        "rule": "down",
        "kind": "down",
        "severity": "critical",
        "status": "firing",
        "firing": true,
        "since": 1723000000.0,
        "last_value": null,
        "streak": 3,
        "for_polls": 1,
        "metric": null,
        "op": null,
        "threshold": null,
        "description": "a suite service is down"
      },
      {
        "service": "gitweb",
        "rule": "mem",
        "kind": "metric",
        "severity": "info",
        "status": "firing",
        "firing": true,
        "since": 1723000000.0,
        "last_value": 500,
        "streak": 3,
        "for_polls": 2,
        "metric": "mem",
        "op": ">=",
        "threshold": 100,
        "description": null
      },
      {
        "service": "gitweb",
        "rule": "down",
        "kind": "down",
        "severity": "critical",
        "status": "ok",
        "firing": false,
        "since": 1723000000.0,
        "last_value": null,
        "streak": 0,
        "for_polls": 1,
        "metric": null,
        "op": null,
        "threshold": null,
        "description": "a suite service is down"
      },
      {
        "service": "torrentds",
        "rule": "mem",
        "kind": "metric",
        "severity": "info",
        "status": "ok",
        "firing": false,
        "since": 1723000000.0,
        "last_value": null,
        "streak": 0,
        "for_polls": 2,
        "metric": "mem",
        "op": ">=",
        "threshold": 100,
        "description": null
      }
    ],
    "recent": [
      {
        "at": 1723000000.0,
        "service": "torrentds",
        "rule": "down",
        "status": "firing",
        "value": null
      },
      {
        "at": 1723000000.0,
        "service": "gitweb",
        "rule": "mem",
        "status": "firing",
        "value": 500
      }
    ]
  }
}"#,
        ),
        (
            "nosnapshot",
            r#"{
  "generated_at": 1723000123.0,
  "summary": {
    "total": 1,
    "up": 1,
    "down": 0,
    "all_up": true
  },
  "services": {
    "gitweb": {
      "up": true,
      "latency_ms": 3.25,
      "metrics": {
        "gitweb_requests_total": 1204,
        "gitweb_uptime_seconds": 512.4,
        "gitweb_missing": null
      },
      "checked_at": 1723000000.5,
      "error": null,
      "health_path": "/health"
    }
  }
}"#,
        ),
        (
            "hostile",
            r#"{
  "generated_at": 1723000000.0,
  "summary": {
    "total": 2,
    "up": 1,
    "down": 1,
    "all_up": false
  },
  "services": {
    "<b>\"ev&il\"</b>": {
      "up": true,
      "latency_ms": 1500.0,
      "metrics": {
        "a<b>": 1,
        "caf\u00e9": 0.5,
        "huge": 1000000000000000052504760255204420248704468581108159154915854115511802457988908195786371375080447864043704443832883878176942523235360430575644792184786706982848387200926575803737830233794788090059368953234970799945081119038967640880074652742780142494579258788820056842838115669472196386865459400540160,
        "big_int": 12345678901234567168,
        "tiny": 0.0,
        "grouped": 9876543.25
      },
      "checked_at": 1723000000.0,
      "error": null,
      "health_path": "/x"
    },
    "bad'svc": {
      "up": false,
      "latency_ms": null,
      "metrics": {},
      "checked_at": 1723000000.0,
      "error": "timeout <script>alert(\"x\")</script>",
      "health_path": null
    }
  },
  "alerts": {
    "rules": 0,
    "firing": 0,
    "states": [],
    "recent": []
  }
}"#,
        ),
        (
            "allclear",
            r#"{
  "generated_at": 1723000000.0,
  "summary": {
    "total": 1,
    "up": 1,
    "down": 0,
    "all_up": true
  },
  "services": {
    "gitweb": {
      "up": true,
      "latency_ms": 3.25,
      "metrics": {
        "gitweb_requests_total": 1204,
        "gitweb_uptime_seconds": 512.4,
        "gitweb_missing": null
      },
      "checked_at": 1723000000.5,
      "error": null,
      "health_path": "/health"
    }
  },
  "alerts": {
    "rules": 1,
    "firing": 0,
    "states": [
      {
        "service": "gitweb",
        "rule": "down",
        "kind": "down",
        "severity": "critical",
        "status": "ok",
        "firing": false,
        "since": null,
        "last_value": null,
        "streak": 0,
        "for_polls": 1,
        "metric": null,
        "op": null,
        "threshold": null,
        "description": "a suite service is down"
      }
    ],
    "recent": []
  }
}"#,
        ),
        (
            "empty",
            r#"{
  "generated_at": 1723000000.0,
  "summary": {
    "total": 0,
    "up": 0,
    "down": 0,
    "all_up": true
  },
  "services": {}
}"#,
        ),
    ];
    for ((label, results, _cfg, snapshot, now), (wlabel, wjson)) in scenarios().iter().zip(want) {
        assert_eq!(label, wlabel, "scenario order");
        assert_eq!(
            &render_status_json(results, snapshot.as_ref(), *now),
            wjson,
            "json: {label}"
        );
    }
}

/// The sparklines on the `full` page come from a real [`History`], not a
/// hand-built series map — so the two modules agree end to end.
#[test]
fn history_feeds_the_same_sparklines() {
    let mut h = History::new(60, 256);
    for (total, uptime) in [(1200.0, 500.0), (1202.0, 506.2), (1204.0, 512.4)] {
        h.record(&sweep(vec![plain(
            "gitweb",
            true,
            &[
                ("gitweb_requests_total", Some(total)),
                ("gitweb_uptime_seconds", Some(uptime)),
            ],
        )]));
    }
    assert_eq!(h.all_series(), full_snapshot().series);
}
