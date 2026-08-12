//! Server-rendered HTML (no JavaScript) and the `/api/status` JSON payload — a
//! port of the Python `suitedash.render`.
//!
//! Every dynamic value — service name, label, base URL, metric keys and numbers,
//! error strings — is passed through [`esc`] before it reaches the page. The only
//! styling is a single inline `<style>` block, which the strict CSP permits via
//! `style-src 'unsafe-inline'`; there is no script anywhere. Auto-refresh is a
//! plain `<meta http-equiv="refresh">` so the page updates without JavaScript.
//!
//! Both renderers are **pure**: where Python reads `time.time()` inline, the
//! wall clock arrives as a `now` argument, so a page is a function of its inputs
//! and the goldens are deterministic. The JSON writer reproduces CPython's
//! `json.dumps(payload, indent=2)` byte-for-byte — two-space indent, `": "` key
//! separator, `ensure_ascii` `\uXXXX` escaping (with surrogate pairs), `int` vs
//! `float` rendering, and key order. Cross-checked by `tests/xcheck_render.rs`.
//!
//! **Documented divergence:** CPython passes `allow_nan=False`, so a non-finite
//! number reaching the payload raises `ValueError`; the port emits the `repr`
//! token instead. Every number that can reach it is filtered finite upstream
//! (the metric parsers drop NaN/Inf, the config loader rejects a non-finite
//! threshold), so the path is unreachable in practice.

use crate::alerts::{AlertEvent, AlertView};
use crate::config::Config;
use crate::history::{sparkline_svg, SPARK_HEIGHT, SPARK_WIDTH};
use crate::metrics::{num_out, summarize, OrderedMap, Results, ServiceResult};
use crate::pycompat;

/// The single inline stylesheet — the only styling on the page, and the only
/// thing the strict CSP's `style-src 'unsafe-inline'` permits. Byte-identical
/// to the Python `render._STYLE`.
const STYLE: &str = r##":root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#16181d;
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
a { color:var(--accent); text-decoration:none; }"##;

/// An immutable, copied view of alert + history state for rendering/JSON — the
/// pure payload of the Python `monitor.MonitorSnapshot` (whose lock-guarded
/// owner belongs to the `net` tier).
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Snapshot {
    /// Current alert rows, already firing-first ordered.
    pub alerts: Vec<AlertView>,
    /// Buffered sparkline points as `{service: {metric: [values]}}`.
    pub series: OrderedMap<OrderedMap<Vec<f64>>>,
    /// The bounded transition log, oldest first.
    pub events: Vec<AlertEvent>,
    /// How many rules are configured.
    pub rules_total: usize,
    /// How many rows are firing (derived from `alerts`).
    pub firing_count: usize,
}

impl Snapshot {
    /// Build a snapshot, deriving `firing_count` from `alerts`.
    #[must_use]
    pub fn new(
        alerts: Vec<AlertView>,
        series: OrderedMap<OrderedMap<Vec<f64>>>,
        events: Vec<AlertEvent>,
        rules_total: usize,
    ) -> Self {
        let firing_count = alerts.iter().filter(|a| a.firing).count();
        Snapshot {
            alerts,
            series,
            events,
            rules_total,
            firing_count,
        }
    }

    /// The buffered series for one service (empty when it has no history).
    #[must_use]
    pub fn series_for(&self, service: &str) -> OrderedMap<Vec<f64>> {
        self.series.get(service).cloned().unwrap_or_default()
    }
}

/// HTML-escape any value for text/attribute context — Python
/// `html.escape(str(v), quote=True)`.
#[must_use]
pub fn esc(v: &str) -> String {
    pycompat::html_escape(v)
}

/// Human-format a surfaced metric: thousands-separated integers, else a trimmed
/// 6-decimal float, `"n/a"` for a missing value.
#[must_use]
pub fn fmt_num(v: Option<f64>) -> String {
    match num_out(v) {
        None => "n/a".to_string(),
        Some(crate::metrics::NumOut::Int(f)) => {
            pycompat::group_thousands(&pycompat::int_str_f64(f))
        }
        Some(crate::metrics::NumOut::Float(f)) => {
            let s = pycompat::group_thousands(&pycompat::fixed(f, 6));
            s.trim_end_matches('0').trim_end_matches('.').to_string()
        }
    }
}

/// Human-format a latency in milliseconds (`"—"` when unknown).
#[must_use]
pub fn fmt_latency(ms: Option<f64>) -> String {
    match ms {
        None => "—".to_string(),
        Some(ms) if ms < 1000.0 => {
            format!(
                "{} ms",
                pycompat::int_str_f64(pycompat::round_half_even(ms))
            )
        }
        Some(ms) => format!("{} s", pycompat::fixed(ms / 1000.0, 2)),
    }
}

/// `HH:MM:SS UTC` for an epoch timestamp — Python
/// `time.strftime("%H:%M:%S UTC", time.gmtime(ts))`, which floors to the second.
///
/// CPython raises for a timestamp outside the platform's `time_t`; the port
/// wraps instead (unreachable for a real clock).
#[must_use]
pub fn fmt_clock(ts: f64) -> String {
    let secs = if ts.is_finite() { ts.floor() } else { 0.0 } as i64;
    let day = secs.rem_euclid(86_400);
    format!(
        "{:02}:{:02}:{:02} UTC",
        day / 3600,
        (day % 3600) / 60,
        day % 60
    )
}

/// A sparkline `<td>` for a metric, or an empty cell when there is no history
/// yet. `series` is the buffered point list (already finite).
fn spark_cell(series: Option<&Vec<f64>>) -> String {
    match series {
        Some(points) if !points.is_empty() => {
            format!(
                "<td class=s>{}</td>",
                sparkline_svg(points, SPARK_WIDTH, SPARK_HEIGHT)
            )
        }
        _ => "<td class=s></td>".to_string(),
    }
}

/// One service card.
fn card(r: &ServiceResult, series_map: &OrderedMap<Vec<f64>>, sparklines: bool) -> String {
    let cls = if r.up { "card" } else { "card down" };
    let badge = if r.up { "badge up" } else { "badge down" };
    let badge_text = if r.up { "UP" } else { "DOWN" };

    let mut rows = String::new();
    for (k, v) in r.metrics.iter() {
        let spark = if sparklines {
            spark_cell(series_map.get(k))
        } else {
            String::new()
        };
        rows.push_str(&format!(
            "<tr><td class=k>{}</td>{}<td class=v>{}</td></tr>",
            esc(k),
            spark,
            esc(&fmt_num(*v))
        ));
    }
    let table = if rows.is_empty() {
        String::new()
    } else {
        format!("<table class=m>{rows}</table>")
    };

    let err = match &r.error {
        Some(e) if !r.up && !e.is_empty() => format!("<div class=err>{}</div>", esc(e)),
        _ => String::new(),
    };
    let label = if r.label.is_empty() {
        String::new()
    } else {
        format!("<div class=label>{}</div>", esc(&r.label))
    };

    format!(
        "<div class=\"{cls}\">\
         <div class=top><span class=name>{name}</span><span class=\"{badge}\">{badge_text}</span></div>\
         {label}\
         <div class=lat>latency <b>{lat}</b></div>\
         {table}{err}\
         <div class=meta>{base} &middot; checked {checked}</div>\
         </div>",
        name = esc(&r.name),
        lat = fmt_latency(r.latency_ms),
        base = esc(&r.base_url),
        checked = esc(&fmt_clock(r.checked_at)),
    )
}

/// One alert line — every service-supplied value escaped.
fn alert_row(a: &AlertView) -> String {
    let cond = if a.kind == "down" {
        "service down".to_string()
    } else {
        format!("{} {} {}", a.metric, a.op, fmt_num(Some(a.threshold)))
    };
    let last = match a.last_value {
        None => String::new(),
        Some(v) => format!("last {} &middot; ", esc(&fmt_num(Some(v)))),
    };
    let desc = if a.description.is_empty() {
        &a.rule_id
    } else {
        &a.description
    };
    let cls = if a.firing { "alert firing" } else { "alert" };
    format!(
        "<div class=\"{cls}\"><span class=al-svc>{svc}</span>\
         <span class=al-desc>{desc}</span>\
         <span class=al-cond>{cond}</span>\
         <span class=al-meta>{last}since {since}</span></div>",
        svc = esc(&a.service),
        desc = esc(desc),
        cond = esc(&cond),
        since = esc(&fmt_clock(a.since)),
    )
}

/// The alerts section: firing alerts up top; a muted note when all clear.
fn alerts_panel(snapshot: Option<&Snapshot>) -> String {
    let Some(snapshot) = snapshot.filter(|s| !s.alerts.is_empty()) else {
        return String::new(); // no rules configured -> no panel at all
    };
    let firing: Vec<&AlertView> = snapshot.alerts.iter().filter(|a| a.firing).collect();
    let body = if firing.is_empty() {
        let n = snapshot.alerts.len();
        format!(
            "<div class=alerts-ok>All {n} alert rule{} OK</div>",
            if n == 1 { "" } else { "s" }
        )
    } else {
        let head = format!(
            "<div class=\"summary bad\">{} alert{} firing</div>",
            firing.len(),
            if firing.len() == 1 { "" } else { "s" }
        );
        let rows: String = firing.into_iter().map(alert_row).collect();
        format!("<div class=\"alerts-h\">{head}</div>{rows}")
    };
    format!("<section class=alerts>{body}</section>")
}

/// Render the full status page as one self-contained no-JS HTML document.
///
/// `snapshot` is optional; when given, an alerts panel and per-metric inline-SVG
/// sparklines are rendered. `now` is the wall clock stamped into the footer.
#[must_use]
pub fn render_page(
    results: &Results,
    config: &Config,
    snapshot: Option<&Snapshot>,
    now: f64,
) -> String {
    let s = summarize(results);
    let refresh_meta = if config.refresh_seconds > 0 {
        format!(
            "<meta http-equiv=\"refresh\" content=\"{}\">",
            config.refresh_seconds
        )
    } else {
        String::new()
    };

    let summary = if s.all_up {
        format!(
            "<div class=\"summary ok\">All systems operational &middot; {}/{} up</div>",
            s.up, s.total
        )
    } else {
        format!(
            "<div class=\"summary bad\">{} of {} services DOWN</div>",
            s.down, s.total
        )
    };

    let empty_series = OrderedMap::new();
    let cards: String = results
        .iter()
        .map(|(name, r)| {
            let series = snapshot
                .and_then(|sn| sn.series.get(name))
                .unwrap_or(&empty_series);
            card(r, series, config.sparklines)
        })
        .collect();
    let panel = alerts_panel(snapshot);
    let refresh_note = if config.refresh_seconds > 0 {
        format!("auto-refreshes every {}s", config.refresh_seconds)
    } else {
        "auto-refresh disabled".to_string()
    };

    format!(
        "<!doctype html><html lang=en><head><meta charset=utf-8>\
         <meta name=viewport content=\"width=device-width, initial-scale=1\">\
         {refresh_meta}\
         <title>astrx-suite status</title><style>{STYLE}</style></head><body>\
         <header><div class=wrap>\
         <h1>astrx&#8209;<span>suite</span> status</h1>\
         <div class=sub>service health, latency and key metrics</div>\
         {summary}</div></header>\
         {panel}\
         <main class=wrap><div class=grid>{cards}</div></main>\
         <footer>Generated {clock} &middot; {note} &middot; no JavaScript &middot; \
         <a href=/api/status>/api/status</a> &middot; \
         <a href=/metrics>/metrics</a></footer>\
         </body></html>",
        clock = esc(&fmt_clock(now)),
        note = esc(&refresh_note),
    )
}

// --------------------------------------------------------------------------- //
// JSON
// --------------------------------------------------------------------------- //

/// A JSON value shaped for CPython's `json.dumps(..., indent=2)`.
enum J {
    Null,
    Bool(bool),
    /// An already-rendered numeric token (Python `int` digits or `float` repr).
    Num(String),
    Str(String),
    Arr(Vec<J>),
    Obj(Vec<(String, J)>),
}

impl J {
    fn int(n: usize) -> J {
        J::Num(n.to_string())
    }
    fn from_num_out(v: Option<f64>) -> J {
        num_out(v).map_or(J::Null, |n| J::Num(n.to_json_token()))
    }
    fn float(v: f64) -> J {
        J::Num(pycompat::repr_f64(v))
    }
    fn opt_str(v: Option<&String>) -> J {
        v.map_or(J::Null, |s| J::Str(s.clone()))
    }
}

/// A quoted JSON string byte-identical to CPython's `ensure_ascii=True`
/// encoder: short escapes for `"`, `\` and `\b\f\n\r\t`, `\u00xx` for the other
/// C0 controls, and `\uXXXX` (surrogate pairs above the BMP) for everything
/// outside printable ASCII.
fn json_quote(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('"');
    for c in s.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\u{8}' => out.push_str("\\b"),
            '\u{c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            ' '..='~' => out.push(c),
            c => {
                let n = c as u32;
                if n < 0x1_0000 {
                    out.push_str(&format!("\\u{n:04x}"));
                } else {
                    let n = n - 0x1_0000;
                    out.push_str(&format!(
                        "\\u{:04x}\\u{:04x}",
                        0xd800 | (n >> 10),
                        0xdc00 | (n & 0x3ff)
                    ));
                }
            }
        }
    }
    out.push('"');
    out
}

fn dump(v: &J, level: usize, out: &mut String) {
    match v {
        J::Null => out.push_str("null"),
        J::Bool(b) => out.push_str(if *b { "true" } else { "false" }),
        J::Num(s) => out.push_str(s),
        J::Str(s) => out.push_str(&json_quote(s)),
        J::Arr(items) => {
            if items.is_empty() {
                out.push_str("[]");
                return;
            }
            out.push_str("[\n");
            for (i, item) in items.iter().enumerate() {
                if i > 0 {
                    out.push_str(",\n");
                }
                out.push_str(&"  ".repeat(level + 1));
                dump(item, level + 1, out);
            }
            out.push('\n');
            out.push_str(&"  ".repeat(level));
            out.push(']');
        }
        J::Obj(pairs) => {
            if pairs.is_empty() {
                out.push_str("{}");
                return;
            }
            out.push_str("{\n");
            for (i, (k, val)) in pairs.iter().enumerate() {
                if i > 0 {
                    out.push_str(",\n");
                }
                out.push_str(&"  ".repeat(level + 1));
                out.push_str(&json_quote(k));
                out.push_str(": ");
                dump(val, level + 1, out);
            }
            out.push('\n');
            out.push_str(&"  ".repeat(level));
            out.push('}');
        }
    }
}

/// The per-service object of `/api/status` (Python `ServiceResult.to_json`).
fn service_to_json(r: &ServiceResult) -> J {
    let metrics = r
        .metrics
        .iter()
        .map(|(k, v)| (k.to_string(), J::from_num_out(*v)))
        .collect();
    J::Obj(vec![
        ("up".to_string(), J::Bool(r.up)),
        (
            "latency_ms".to_string(),
            r.latency_ms.map_or(J::Null, J::float),
        ),
        ("metrics".to_string(), J::Obj(metrics)),
        (
            "checked_at".to_string(),
            J::float(pycompat::round_ndigits(r.checked_at, 3)),
        ),
        ("error".to_string(), J::opt_str(r.error.as_ref())),
        (
            "health_path".to_string(),
            J::opt_str(r.health_path.as_ref()),
        ),
    ])
}

fn alert_to_json(a: &AlertView) -> J {
    let is_metric = a.kind == "metric";
    J::Obj(vec![
        ("service".to_string(), J::Str(a.service.clone())),
        ("rule".to_string(), J::Str(a.rule_id.clone())),
        ("kind".to_string(), J::Str(a.kind.clone())),
        ("severity".to_string(), J::Str(a.severity.clone())),
        ("status".to_string(), J::Str(a.status.clone())),
        ("firing".to_string(), J::Bool(a.firing)),
        (
            "since".to_string(),
            if a.since == 0.0 {
                J::Null
            } else {
                J::float(pycompat::round_ndigits(a.since, 3))
            },
        ),
        ("last_value".to_string(), J::from_num_out(a.last_value)),
        ("streak".to_string(), J::Num(a.streak.to_string())),
        ("for_polls".to_string(), J::Num(a.for_polls.to_string())),
        (
            "metric".to_string(),
            if a.metric.is_empty() {
                J::Null
            } else {
                J::Str(a.metric.clone())
            },
        ),
        (
            "op".to_string(),
            if is_metric {
                J::Str(a.op.clone())
            } else {
                J::Null
            },
        ),
        (
            "threshold".to_string(),
            if is_metric {
                J::from_num_out(Some(a.threshold))
            } else {
                J::Null
            },
        ),
        (
            "description".to_string(),
            if a.description.is_empty() {
                J::Null
            } else {
                J::Str(a.description.clone())
            },
        ),
    ])
}

/// The `alerts` block for `/api/status` — states + a bounded event log.
fn alerts_json(snapshot: &Snapshot) -> J {
    let events = snapshot
        .events
        .iter()
        .map(|e| {
            J::Obj(vec![
                ("at".to_string(), J::float(pycompat::round_ndigits(e.at, 3))),
                ("service".to_string(), J::Str(e.service.clone())),
                ("rule".to_string(), J::Str(e.rule_id.clone())),
                ("status".to_string(), J::Str(e.status.clone())),
                ("value".to_string(), J::from_num_out(e.value)),
            ])
        })
        .collect();
    J::Obj(vec![
        ("rules".to_string(), J::int(snapshot.rules_total)),
        ("firing".to_string(), J::int(snapshot.firing_count)),
        (
            "states".to_string(),
            J::Arr(snapshot.alerts.iter().map(alert_to_json).collect()),
        ),
        ("recent".to_string(), J::Arr(events)),
    ])
}

/// Render `/api/status` as `{generated_at, summary, services{…}, alerts{…}}`.
///
/// When `snapshot` is supplied, an `alerts` block with per-rule state and a
/// bounded event log is included. `now` is the `generated_at` clock.
#[must_use]
pub fn render_status_json(results: &Results, snapshot: Option<&Snapshot>, now: f64) -> String {
    let s = summarize(results);
    let mut payload = vec![
        (
            "generated_at".to_string(),
            J::float(pycompat::round_ndigits(now, 3)),
        ),
        (
            "summary".to_string(),
            J::Obj(vec![
                ("total".to_string(), J::int(s.total)),
                ("up".to_string(), J::int(s.up)),
                ("down".to_string(), J::int(s.down)),
                ("all_up".to_string(), J::Bool(s.all_up)),
            ]),
        ),
        (
            "services".to_string(),
            J::Obj(
                results
                    .iter()
                    .map(|(name, r)| (name.to_string(), service_to_json(r)))
                    .collect(),
            ),
        ),
    ];
    if let Some(sn) = snapshot {
        payload.push(("alerts".to_string(), alerts_json(sn)));
    }
    let mut out = String::new();
    dump(&J::Obj(payload), 0, &mut out);
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::metrics::SurfacedMetrics;

    fn one_result() -> Results {
        let mut r = ServiceResult::new("gitweb", "http://127.0.0.1:8801", true);
        r.latency_ms = Some(3.25);
        r.checked_at = 1_723_000_000.5;
        r.health_path = Some("/health".to_string());
        r.label = "Read-only git web viewer".to_string();
        let mut m = SurfacedMetrics::new();
        m.insert("gitweb_requests_total", Some(1204.0));
        m.insert("gitweb_uptime_seconds", Some(512.4));
        m.insert("gitweb_missing", None);
        r.metrics = m;
        let mut results = Results::new();
        results.insert("gitweb", r);
        results
    }

    #[test]
    fn escapes_every_dynamic_value() {
        let mut r = ServiceResult::new("<svc>", "http://x/?a=1&b=2", false);
        r.error = Some("bad \"quote\" & <tag>".to_string());
        let mut results = Results::new();
        results.insert("<svc>", r);
        let html = render_page(&results, &Config::default(), None, 0.0);
        assert!(html.contains("&lt;svc&gt;"));
        assert!(html.contains("&amp;b=2"));
        assert!(html.contains("bad &quot;quote&quot; &amp; &lt;tag&gt;"));
        assert!(!html.contains("<svc>"));
    }

    #[test]
    fn number_formatting() {
        assert_eq!(fmt_num(None), "n/a");
        assert_eq!(fmt_num(Some(1204.0)), "1,204");
        assert_eq!(fmt_num(Some(512.4)), "512.4");
        assert_eq!(fmt_num(Some(0.5)), "0.5");
        assert_eq!(fmt_num(Some(1e-7)), "0");
        assert_eq!(fmt_latency(None), "—");
        assert_eq!(fmt_latency(Some(3.4)), "3 ms");
        assert_eq!(fmt_latency(Some(2.5)), "2 ms");
        assert_eq!(fmt_latency(Some(1500.0)), "1.50 s");
        assert_eq!(fmt_clock(0.0), "00:00:00 UTC");
        assert_eq!(fmt_clock(1_723_000_000.9), "03:06:40 UTC");
    }

    #[test]
    fn json_is_python_shaped() {
        let json = render_status_json(&one_result(), None, 1_723_000_000.0);
        assert!(json.starts_with("{\n  \"generated_at\": 1723000000.0,\n  \"summary\": {\n"));
        assert!(json.contains("\n        \"gitweb_requests_total\": 1204,\n"));
        assert!(json.contains("\n        \"gitweb_uptime_seconds\": 512.4,\n"));
        assert!(json.contains("\n        \"gitweb_missing\": null\n"));
        assert!(json.contains("\"latency_ms\": 3.25"));
        assert!(json.contains("\"checked_at\": 1723000000.5"));
        assert!(json.ends_with('}'));
    }

    #[test]
    fn json_escapes_like_ensure_ascii() {
        assert_eq!(json_quote("caf\u{e9}"), "\"caf\\u00e9\"");
        assert_eq!(json_quote("a\u{1f600}b"), "\"a\\ud83d\\ude00b\"");
        assert_eq!(json_quote("q\"\\\n\u{1}"), "\"q\\\"\\\\\\n\\u0001\"");
        assert_eq!(json_quote("~\u{7f}"), "\"~\\u007f\"");
    }

    #[test]
    fn refresh_meta_is_dropped_when_disabled() {
        let mut cfg = Config {
            refresh_seconds: 0,
            ..Config::default()
        };
        let html = render_page(&one_result(), &cfg, None, 0.0);
        assert!(!html.contains("http-equiv"));
        assert!(html.contains("auto-refresh disabled"));
        cfg.refresh_seconds = 15;
        let html = render_page(&one_result(), &cfg, None, 0.0);
        assert!(html.contains("<meta http-equiv=\"refresh\" content=\"15\">"));
        assert!(html.contains("auto-refreshes every 15s"));
    }

    #[test]
    fn no_panel_without_rules() {
        assert_eq!(alerts_panel(None), "");
        assert_eq!(alerts_panel(Some(&Snapshot::default())), "");
    }
}
