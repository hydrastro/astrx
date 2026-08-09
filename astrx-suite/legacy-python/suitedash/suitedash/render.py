"""Server-rendered HTML (no JavaScript) and the ``/api/status`` JSON payload.

Every dynamic value — service name, label, base URL, metric keys and numbers,
error strings — is passed through :func:`html.escape` before it reaches the
page.  The only styling is a single inline ``<style>`` block, which the strict
CSP permits via ``style-src 'unsafe-inline'``; there is no script anywhere.
Auto-refresh is a plain ``<meta http-equiv="refresh">`` so the page updates
without JavaScript.
"""

from __future__ import annotations

import html
import json
import time
from collections import OrderedDict
from typing import Optional

from .config import Config
from .history import sparkline_svg
from .poller import summarize
from .probe import ServiceResult, _num_out

_STYLE = """
:root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#16181d;
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
a { color:var(--accent); text-decoration:none; }
""".strip()


def _e(v) -> str:
    """Escape any value for HTML text/attribute context."""
    return html.escape("" if v is None else str(v), quote=True)


def _fmt_num(v: Optional[float]) -> str:
    """Human-format a surfaced metric: thousands-separated ints, else trimmed float."""
    n = _num_out(v)
    if n is None:
        return "n/a"
    if isinstance(n, int):
        return format(n, ",")
    return format(n, ",.6f").rstrip("0").rstrip(".")


def _fmt_latency(ms: Optional[float]) -> str:
    if ms is None:
        return "—"
    if ms < 1000:
        return "%d ms" % round(ms)
    return "%.2f s" % (ms / 1000.0)


def _fmt_clock(ts: float) -> str:
    return time.strftime("%H:%M:%S UTC", time.gmtime(ts))


def _spark_cell(series) -> str:
    """A sparkline ``<td>`` for a metric, or an empty cell when there is no
    history yet.  ``series`` is the buffered point list (already finite)."""
    if not series:
        return "<td class=s></td>"
    return "<td class=s>%s</td>" % sparkline_svg(series)


def _card(r: ServiceResult, series_map=None, sparklines: bool = True) -> str:
    cls = "card" if r.up else "card down"
    badge = "badge up" if r.up else "badge down"
    badge_text = "UP" if r.up else "DOWN"
    series_map = series_map or {}

    rows = []
    for k, v in r.metrics.items():
        spark = _spark_cell(series_map.get(k)) if sparklines else ""
        rows.append(
            "<tr><td class=k>%s</td>%s<td class=v>%s</td></tr>"
            % (_e(k), spark, _e(_fmt_num(v)))
        )
    table = "<table class=m>%s</table>" % "".join(rows) if rows else ""

    err = ""
    if not r.up and r.error:
        err = "<div class=err>%s</div>" % _e(r.error)

    label = "<div class=label>%s</div>" % _e(r.label) if r.label else ""

    return (
        '<div class="%s">'
        '<div class=top><span class=name>%s</span><span class="%s">%s</span></div>'
        "%s"
        '<div class=lat>latency <b>%s</b></div>'
        "%s%s"
        '<div class=meta>%s &middot; checked %s</div>'
        "</div>"
    ) % (
        cls,
        _e(r.name),
        badge,
        badge_text,
        label,
        _fmt_latency(r.latency_ms),
        table,
        err,
        _e(r.base_url),
        _e(_fmt_clock(r.checked_at)),
    )


def _alert_row(a) -> str:
    """One alert line — every service-supplied value escaped."""
    if a.kind == "down":
        cond = "service down"
    else:
        cond = "%s %s %s" % (a.metric, a.op, _fmt_num(a.threshold))
    last = "" if a.last_value is None else "last %s &middot; " % _e(_fmt_num(a.last_value))
    desc = a.description or a.rule_id
    cls = "alert firing" if a.firing else "alert"
    return (
        '<div class="%s"><span class=al-svc>%s</span>'
        "<span class=al-desc>%s</span>"
        "<span class=al-cond>%s</span>"
        "<span class=al-meta>%ssince %s</span></div>"
    ) % (cls, _e(a.service), _e(desc), _e(cond), last, _e(_fmt_clock(a.since)))


def _alerts_panel(snapshot) -> str:
    """The alerts section: firing alerts up top; a muted note when all clear."""
    if snapshot is None or not snapshot.alerts:
        return ""  # no rules configured -> no panel at all
    firing = [a for a in snapshot.alerts if a.firing]
    if firing:
        head = '<div class="summary bad">%d alert%s firing</div>' % (
            len(firing),
            "" if len(firing) == 1 else "s",
        )
        rows = "".join(_alert_row(a) for a in firing)
        body = '<div class="alerts-h">%s</div>%s' % (head, rows)
    else:
        n = len(snapshot.alerts)
        body = '<div class=alerts-ok>All %d alert rule%s OK</div>' % (
            n,
            "" if n == 1 else "s",
        )
    return '<section class=alerts>%s</section>' % body


def render_page(
    results: "OrderedDict[str, ServiceResult]", config: Config, snapshot=None
) -> str:
    """Render the full status page as one self-contained no-JS HTML document.

    ``snapshot`` is an optional :class:`suitedash.monitor.MonitorSnapshot`; when
    given, an alerts panel and per-metric inline-SVG sparklines are rendered.
    """
    s = summarize(results)
    refresh_meta = ""
    if config.refresh_seconds and config.refresh_seconds > 0:
        refresh_meta = '<meta http-equiv="refresh" content="%d">' % int(
            config.refresh_seconds
        )

    if s["all_up"]:
        summary = '<div class="summary ok">All systems operational &middot; %d/%d up</div>' % (
            s["up"],
            s["total"],
        )
    else:
        summary = '<div class="summary bad">%d of %d services DOWN</div>' % (
            s["down"],
            s["total"],
        )

    sparklines = getattr(config, "sparklines", True)
    series = snapshot.series if snapshot is not None else {}
    cards = "".join(
        _card(r, series.get(name, {}), sparklines)
        for name, r in results.items()
    )
    alerts_panel = _alerts_panel(snapshot)
    now = _fmt_clock(time.time())
    refresh_note = (
        "auto-refreshes every %ds" % config.refresh_seconds
        if config.refresh_seconds and config.refresh_seconds > 0
        else "auto-refresh disabled"
    )

    return (
        "<!doctype html><html lang=en><head><meta charset=utf-8>"
        '<meta name=viewport content="width=device-width, initial-scale=1">'
        "%s"
        "<title>astrx-suite status</title><style>%s</style></head><body>"
        "<header><div class=wrap>"
        "<h1>astrx&#8209;<span>suite</span> status</h1>"
        '<div class=sub>service health, latency and key metrics</div>'
        "%s</div></header>"
        "%s"
        '<main class=wrap><div class=grid>%s</div></main>'
        "<footer>Generated %s &middot; %s &middot; no JavaScript &middot; "
        "<a href=/api/status>/api/status</a> &middot; "
        "<a href=/metrics>/metrics</a></footer>"
        "</body></html>"
    ) % (refresh_meta, _STYLE, summary, alerts_panel, cards, _e(now), _e(refresh_note))


def _alert_to_json(a) -> "OrderedDict":
    return OrderedDict(
        [
            ("service", a.service),
            ("rule", a.rule_id),
            ("kind", a.kind),
            ("severity", a.severity),
            ("status", a.status),
            ("firing", a.firing),
            ("since", round(a.since, 3) if a.since else None),
            ("last_value", _num_out(a.last_value)),
            ("streak", a.streak),
            ("for_polls", a.for_polls),
            ("metric", a.metric or None),
            ("op", a.op if a.kind == "metric" else None),
            ("threshold", _num_out(a.threshold) if a.kind == "metric" else None),
            ("description", a.description or None),
        ]
    )


def _alerts_json(snapshot) -> "OrderedDict":
    """The ``alerts`` block for ``/api/status`` — states + a bounded event log."""
    events = [
        OrderedDict(
            [
                ("at", round(e.at, 3)),
                ("service", e.service),
                ("rule", e.rule_id),
                ("status", e.status),
                ("value", _num_out(e.value)),
            ]
        )
        for e in snapshot.events
    ]
    return OrderedDict(
        [
            ("rules", snapshot.rules_total),
            ("firing", snapshot.firing_count),
            ("states", [_alert_to_json(a) for a in snapshot.alerts]),
            ("recent", events),
        ]
    )


def render_status_json(
    results: "OrderedDict[str, ServiceResult]", snapshot=None
) -> bytes:
    """Render ``/api/status`` as ``{service: {up, latency_ms, metrics{...}, ...}}``.

    When ``snapshot`` (a :class:`suitedash.monitor.MonitorSnapshot`) is supplied,
    an ``alerts`` block with per-rule state and a bounded event log is included.
    """
    payload = OrderedDict()
    payload["generated_at"] = round(time.time(), 3)
    payload["summary"] = summarize(results)
    services = OrderedDict()
    for name, r in results.items():
        services[name] = r.to_json()
    payload["services"] = services
    if snapshot is not None:
        payload["alerts"] = _alerts_json(snapshot)
    return json.dumps(payload, indent=2, allow_nan=False).encode("utf-8")
