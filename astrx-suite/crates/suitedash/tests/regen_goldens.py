#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens for suitedash.

Drives the REAL Python `suitedash` package (the reference this crate ports) and
prints the literals embedded in `tests/xcheck_*.rs` — plus the CPython float
formatting goldens embedded in `src/pycompat.rs`'s unit tests. Re-running this
and diffing against those literals proves the Rust port stays byte-identical.

    PYTHONPATH=legacy-python/suitedash \
        python3 crates/suitedash/tests/regen_goldens.py [section ...]

Sections: pyfmt, metrics, history, alerts, render, exporter, config.
"""

from __future__ import annotations

import sys
from collections import OrderedDict


# --------------------------------------------------------------------------- #
# helpers
# --------------------------------------------------------------------------- #


def resc(s: str) -> str:
    """An escaped Rust string literal holding exactly `s`."""
    out = []
    for ch in s:
        if ch == "\\":
            out.append("\\\\")
        elif ch == '"':
            out.append('\\"')
        elif ch == "\n":
            out.append("\\n")
        elif ch == "\r":
            out.append("\\r")
        elif ch == "\t":
            out.append("\\t")
        elif ord(ch) < 0x20 or ord(ch) == 0x7F:
            out.append("\\u{%x}" % ord(ch))
        else:
            out.append(ch)
    return '"%s"' % "".join(out)


def rs(s: str) -> str:
    """A Rust literal holding exactly `s` — a raw string when the content is
    printable (readable, diff-friendly goldens), else an escaped one."""
    if any(ord(c) < 0x20 and c not in "\n\t" or ord(c) == 0x7F for c in s):
        return resc(s)
    hashes = "#"
    while '"' + hashes in s:
        hashes += "#"
    return 'r%s"%s"%s' % (hashes, s, hashes)


def rb(b: bytes) -> str:
    """A Rust byte-string literal holding exactly `b`."""
    out = []
    for ch in b:
        c = chr(ch)
        if c == "\\":
            out.append("\\\\")
        elif c == '"':
            out.append('\\"')
        elif c == "\n":
            out.append("\\n")
        elif c == "\r":
            out.append("\\r")
        elif c == "\t":
            out.append("\\t")
        elif 0x20 <= ch < 0x7F:
            out.append(c)
        else:
            out.append("\\x%02x" % ch)
    return 'b"%s"' % "".join(out)


def num(v) -> str:
    """`repr` of a float / None, the shared dump spelling for both languages."""
    if v is None:
        return "None"
    return repr(float(v))


def result(name, up=True, metrics=None, latency=None, checked_at=0.0, error=None,
           health_path=None, label="", metrics_raw="", metrics_ctype=""):
    from suitedash.probe import ServiceResult
    return ServiceResult(
        name=name, base_url="http://x", up=up,
        metrics=OrderedDict(metrics or []), latency_ms=latency,
        checked_at=checked_at, error=error, health_path=health_path,
        label=label, metrics_raw=metrics_raw, metrics_ctype=metrics_ctype,
    )


def results(*rs_):
    out = OrderedDict()
    for r in rs_:
        out[r.name] = r
    return out


# --------------------------------------------------------------------------- #
# pyfmt — CPython float formatting (goldens live in src/pycompat.rs)
# --------------------------------------------------------------------------- #


def gen_pyfmt() -> None:
    print("== pyfmt: (value, repr(v), str(int(v)) or '', '%.6f' % v) ==")
    vals = [0.0, -0.0, 1.0, -1.0, 0.5, 1.5, 0.1, 1 / 3, 2.675, 0.125, 100.0,
            1234.5, 0.0001, 1e-05, 1e-07, 1e15, 1e16, 1.5e16,
            1.2345678901234567e19, 123.456789012, -2.5, 1e300, 5e-324,
            2.2250738585072014e-308]
    for v in vals:
        i = str(int(v)) if float(v).is_integer() else ""
        print("%r\t%r\t%s\t%r" % (v, repr(v), i, "%.6f" % v))

    print("== pyfmt: (x, round(x, 6), round(x)) ==")
    for x in [1 / 3, 123.456789012, 5e-07, 2.5, 3.5, -2.5, 0.5, 1.5, 2.0000005, 1e300]:
        print("%r\t%r\t%r" % (x, round(x, 6), float(round(x))))

    print("== pyfmt: float(s) / int(s) acceptance ==")
    for s in ["1_000", "  1.5  ", "+2", "-2.5e3", ".5", "5.", "1e1_0", "Infinity",
              "-inf", "NaN", "", "abc", ".", "_1", "1_", "1__0", "1e", "0x10", "1 2"]:
        try:
            f = repr(float(s))
        except ValueError:
            f = "ValueError"
        try:
            i = repr(int(s))
        except ValueError:
            i = "ValueError"
        print("%r\tfloat=%s\tint=%s" % (s, f, i))


# --------------------------------------------------------------------------- #
# metrics — the two tolerant parsers
# --------------------------------------------------------------------------- #

PROM_CASES = [
    "# HELP x_total help\n# TYPE x_total counter\nx_total 42\nx_ratio 0.75\n",
    "# a comment\n\n   \nlonelytoken\n# TYPE y gauge\ny 3\n",
    'r_total{code="200"} 40\nr_total{code="500"} 2\n',
    "good 1\nbad NaN\ninf_v +Inf\nneg -Inf\n",
    "z 9 1699999999000\n",
    "grouped 1_000\nspaced   7   \ntabbed\t8\t\n",
    "dup 1\ndup 2\n",
    'lbl{a="1"} 5\nlbl 6\n',
    "neg_exp 1e-7\nbig 1e16\nfrac .5\n",
    "",
    "   \n\n",
    "\x00\x01 garbage {{{ \nok 1\n",
    "nbsp\u00a01\nsep\x1e2 3\n",
    "unicode_ws 1 2\n",
    "crlf 1\r\nvtab 2\x0b3\n",
]

JSON_CASES = [
    '{"docs": 1000, "ok": true, "ratio": "0.5", "tags": ["a","b"], "nothing": null,'
    ' "queue": {"pending": 7, "done": 300, "name": "q"}}',
    '{"a": 1, "b": 2}',
    "[1, 2, 3]",
    '{"nested": {"deep": {"x": 1}}, "flat": 2}',
    '{"neg": -3.5, "exp": 1e3, "str_bad": "abc", "bool_false": false}',
    '{"dup": 1, "dup": 2}',
    "{not json",
    '{"big": 12345678901234567890, "huge": 1e400}',
    '{"": 1, "a.b": 2}',
]

METRICS_CASES = [
    (b"a 1\nb 2\n", "text/plain"),
    (b'{"a": 1, "b": 2}', "application/json"),
    (b'{"a": 5}', ""),
    (b"", "text/plain"),
    (b'{"a": 5}', "text/plain"),
    (b"a 1\n", "application/json"),
    (b"not json at all", "application/json"),
    (b"\xff\xfe bad utf8 \xc3\xa9 1\nok 2\n", "text/plain"),
    (b"   ", "text/plain"),
]


def _map_lit(m) -> str:
    return "&[%s]" % ", ".join('("%s", %s)' % (k.replace('\\', '\\\\').replace('"', '\\"'), repr(float(v)))
                               for k, v in m.items())


def gen_metrics() -> None:
    from suitedash.probe import flatten_json, parse_metrics, parse_prometheus
    import json

    print("== metrics: parse_prometheus (input, expected) ==")
    for text in PROM_CASES:
        print("    (%s, %s)," % (rs(text), _map_lit(parse_prometheus(text))))

    print("== metrics: flatten_json (input, expected) ==")
    for text in JSON_CASES:
        try:
            m = flatten_json(json.loads(text))
        except Exception:
            m = None
        print("    (%s, %s)," % (rs(text),
                                 "None" if m is None else "Some(%s)" % _map_lit(m)))

    print("== metrics: parse_metrics (body, ctype, expected) ==")
    for body, ctype in METRICS_CASES:
        print("    (%s, %s, %s)," % (rb(body), rs(ctype),
                                     _map_lit(parse_metrics(body, ctype))))

    print("== metrics: _num_out (value, json token) ==")
    from suitedash.probe import _num_out
    for v in [None, 0.0, 7.0, -7.0, 7.5, 1204.0, 512.4, 1 / 3, 1e-7, 1e16, 1e300,
              0.1 + 0.2, -0.0]:
        n = _num_out(v)
        print("    (%s, %s)," % ("None" if v is None else "Some(%r)" % float(v),
                                 "None" if n is None else 'Some("%s")' % json.dumps(n)))

    print("== metrics: surface (keys, expected) ==")
    from suitedash.probe import _surface
    metrics = OrderedDict([("b", 2.0), ("a", 1.0), ("c", 3.0), ("d", 4.0),
                           ("e", 5.0), ("f", 6.0), ("g", 7.0)])
    for keys in [(), ("a", "missing"), ("c",)]:
        surfaced = _surface(metrics, keys)
        print("    (&[%s], &[%s])," % (
            ", ".join('"%s"' % k for k in keys),
            ", ".join('("%s", %s)' % (k, "None" if v is None else "Some(%s)" % repr(float(v)))
                      for k, v in surfaced.items())))


# --------------------------------------------------------------------------- #
# history — rings + the hand-emitted sparkline SVG
# --------------------------------------------------------------------------- #

SPARK_CASES = [
    ([], 100.0, 20.0),
    ([42], 100.0, 20.0),
    ([7, 7, 7, 7], 100.0, 20.0),
    ([1, 2, 3, 4, 5], 100.0, 20.0),
    ([5, 4, 3, 2, 1], 100.0, 20.0),
    ([0, 100, 0, 100, 0, 100], 100.0, 20.0),
    ([1204, 1210, 1211, 1250, 1249, 1300], 100.0, 20.0),
    ([0.5, 0.25, 0.125], 100.0, 20.0),
    ([-5, 0, 5], 100.0, 20.0),
    ([1e308, -1e308, 1e308, 0.0], 100.0, 20.0),
    ([float("nan"), float("inf"), float("-inf"), 5], 100.0, 20.0),
    ([float("nan"), float("inf")], 100.0, 20.0),
    ([1, 2, 3], 3.0, 4.0),
    ([1, 2, 3], 100.0, 4.5),
    ([1, 2, 3], 100.0, 5.0),
    ([1, 2, 3], 100.0, 2.0),
    ([1, 2, 3], 100.0, 4.0001),
    ([1, 2, 3], 1.0, 1.0),
    ([1, 2, 3], 0.0, 0.0),
    ([1, 2, 3], 250.5, 33.25),
    ([1, 2, 3], 1e9, 1e9),
    ([1, 2, 3], float("nan"), 20.0),
    ([1, 2, 3], 100.0, float("inf")),
    ([3, 1, 4, 1, 5, 9, 2, 6, 5, 3, 5], 100.0, 20.0),
    ([0.1, 0.2, 0.30000000000000004], 100.0, 20.0),
]


def _flit(v: float) -> str:
    if v != v:
        return "f64::NAN"
    if v == float("inf"):
        return "f64::INFINITY"
    if v == float("-inf"):
        return "f64::NEG_INFINITY"
    return repr(float(v))


def gen_history() -> None:
    from suitedash.history import History, Ring, sparkline_svg

    print("== history: sparkline_svg (points, w, h, svg) ==")
    for points, w, h in SPARK_CASES:
        print("    (&[%s], %s, %s, %s)," % (
            ", ".join(_flit(p) for p in points), _flit(w), _flit(h),
            rs(sparkline_svg(points, width=w, height=h))))

    print("== history: ring/eviction dump ==")
    lines = []
    r = Ring(3)
    for i in range(5):
        r.push(i)
    lines.append("ring3: %s" % r.values())
    h = History(capacity=3, max_series=3)
    sweeps = [
        results(result("a", metrics=[("x", 1), ("y", 2)]),
                result("b", metrics=[("z", 3)])),
        results(result("a", metrics=[("x", 2), ("y", None)]),
                result("b", up=False, metrics=[("z", 9)])),
        results(result("a", metrics=[("x", 3)]),
                result("c", metrics=[("w", 4)])),
        results(result("a", metrics=[("x", 4), ("y", float("inf"))])),
    ]
    for i, sweep in enumerate(sweeps):
        h.record(sweep)
        dump = "; ".join(
            "%s/%s=%s" % (svc, metric, ",".join(num(v) for v in vals))
            for svc, mm in h.all_series().items() for metric, vals in mm.items())
        lines.append("sweep%d: %s" % (i, dump))
    for ln in lines:
        print("    %s," % rs(ln))


# --------------------------------------------------------------------------- #
# alerts — the stateful engine across a firing/resolving/flapping sequence
# --------------------------------------------------------------------------- #


def _rules():
    from suitedash.config import AlertRule
    return [
        AlertRule(id="busy", service="alpha", kind="metric", metric="q", op=">",
                  threshold=10.0, for_polls=3, severity="warning",
                  description="alpha queue is deep"),
        AlertRule(id="down", service="*", kind="down", for_polls=1,
                  severity="critical", description="a suite service is down"),
        AlertRule(id="mem", service="*", kind="metric", metric="mem", op=">=",
                  threshold=100.0, for_polls=2, severity="info", description=""),
        AlertRule(id="ghost", service="nosuch", kind="down", for_polls=1,
                  severity="info", description="never targeted"),
        AlertRule(id="weird", service="alpha", kind="metric", metric="q", op="~~",
                  threshold=0.0, for_polls=1, severity="nonsense",
                  description="unknown operator"),
    ]


def _sweeps():
    """A scripted (now, results) sequence: firing, resolving and flapping."""
    return [
        (1000.0, results(result("alpha", metrics=[("q", 50), ("mem", 10)]),
                         result("beta", metrics=[("mem", 500)]))),
        (1001.0, results(result("alpha", metrics=[("q", 50), ("mem", 10)]),
                         result("beta", metrics=[("mem", 500)]))),
        (1002.0, results(result("alpha", metrics=[("q", 50), ("mem", 10)]),
                         result("beta", metrics=[("mem", 500)]))),
        (1003.0, results(result("alpha", metrics=[("q", 5), ("mem", 10)]),
                         result("beta", up=False))),
        (1004.0, results(result("alpha", metrics=[("q", 50), ("mem", None)]),
                         result("beta", metrics=[("mem", 99.5)]))),
        (1005.0, results(result("alpha", up=False))),
        (1006.0, results(result("alpha", metrics=[("q", 11), ("mem", 100)]))),
        (1007.0, results(result("alpha", up=False))),
        (1008.0, results(result("alpha", metrics=[("q", float("nan"))]))),
        (1009.0, results(result("alpha", metrics=[("q", 50)]),
                         result("beta", metrics=[("mem", 100)]),
                         result("gamma", up=False))),
    ]


def gen_alerts() -> None:
    from suitedash.alerts import AlertEngine

    clock = {"t": 0.0}
    eng = AlertEngine(_rules(), alert_history=6, clock=lambda: clock["t"])
    lines = []
    for i, (now, res) in enumerate(_sweeps()):
        clock["t"] = now
        eng.update(res)
        for v in eng.views():
            lines.append("s%d view %s|%s|%s|%s|%s|%s|%s|%s|%d|%s|%s|%s|%s|%d" % (
                i, v.service, v.rule_id, v.kind, v.severity, v.description,
                v.metric, v.op, num(v.threshold), v.for_polls, v.firing,
                v.status, num(v.since), num(v.last_value), v.streak))
        for e in eng.events():
            lines.append("s%d event %s|%s|%s|%s|%s" % (
                i, num(e.at), e.service, e.rule_id, e.status, num(e.value)))
    print("== alerts: engine dump ==")
    for ln in lines:
        print("    %s," % rs(ln))


# --------------------------------------------------------------------------- #
# render — the HTML page and the /api/status JSON
# --------------------------------------------------------------------------- #


def _scenarios():
    """(label, results, config kwargs, snapshot|None, now)."""
    from suitedash.config import Config
    from suitedash.monitor import MonitorSnapshot
    from suitedash.alerts import AlertEngine

    up = result("gitweb", up=True, latency=3.25, checked_at=1723000000.5,
                health_path="/health", label="Read-only git web viewer",
                metrics=[("gitweb_requests_total", 1204.0),
                         ("gitweb_uptime_seconds", 512.4),
                         ("gitweb_missing", None)])
    down = result("torrentds", up=False, checked_at=1723000000.25,
                  error="connection refused", label="Torrent DHT indexer")
    hostile = result('<b>"ev&il"</b>', up=True, latency=1500.0,
                     checked_at=1723000000.0, health_path="/x",
                     label="café — résumé \U0001f600",
                     metrics=[("a<b>", 1.0), ("café", 0.5),
                              ("huge", 1e300), ("big_int", 12345678901234567890.0),
                              ("tiny", 1e-7), ("grouped", 9876543.25)])
    hostile_down = result("bad'svc", up=False, checked_at=1723000000.0,
                          error='timeout <script>alert("x")</script>')

    eng = AlertEngine(_rules(), alert_history=6, clock=lambda: 1723000000.0)
    for _ in range(3):
        eng.update(results(
            result("gitweb", metrics=[("q", 50), ("mem", 500)]),
            result("torrentds", up=False)))
    series = OrderedDict([
        ("gitweb", OrderedDict([("gitweb_requests_total", [1200.0, 1202.0, 1204.0]),
                                ("gitweb_uptime_seconds", [500.0, 506.2, 512.4])])),
    ])
    snap = MonitorSnapshot(alerts=eng.views(), series=series, events=eng.events(),
                           rules_total=len(_rules()))
    empty_snap = MonitorSnapshot(alerts=[], series=OrderedDict(), events=[],
                                 rules_total=0)

    # Stamped at t=0 so the JSON exercises `round(since, 3) if since else None`.
    ok_eng = AlertEngine(_rules()[1:2], alert_history=4, clock=lambda: 0.0)
    ok_eng.update(results(result("gitweb")))
    ok_snap = MonitorSnapshot(alerts=ok_eng.views(), series=OrderedDict(),
                              events=ok_eng.events(), rules_total=1)

    return [
        ("full", results(up, down), dict(refresh_seconds=15, sparklines=True),
         snap, 1723000123.456),
        ("nosnapshot", results(up), dict(refresh_seconds=0, sparklines=False),
         None, 1723000123.0),
        ("hostile", results(hostile, hostile_down),
         dict(refresh_seconds=5, sparklines=True), empty_snap, 1723000000.0),
        ("allclear", results(up), dict(refresh_seconds=15, sparklines=True),
         ok_snap, 1723000000.0),
        ("empty", results(), dict(refresh_seconds=15, sparklines=True), None,
         1723000000.0),
    ]


class _FakeTime:
    """A `time` module stand-in so the renderers' `time.time()` is deterministic."""

    def __init__(self, now):
        self.now = now

    def time(self):
        return self.now

    def gmtime(self, ts=None):
        import time as _t
        return _t.gmtime(self.now if ts is None else ts)

    def strftime(self, fmt, t):
        import time as _t
        return _t.strftime(fmt, t)


def gen_render() -> None:
    from suitedash import render
    from suitedash.config import Config

    real_time = render.time
    print("== render: page + /api/status per scenario ==")
    for label, res, cfg_kw, snap, now in _scenarios():
        cfg = Config(**cfg_kw)
        render.time = _FakeTime(now)
        try:
            page = render.render_page(res, cfg, snap)
            api = render.render_status_json(res, snap).decode("utf-8")
        finally:
            render.time = real_time
        print("--- %s: page ---" % label)
        print("    %s," % rs(page))
        print("--- %s: json ---" % label)
        print("    %s," % rs(api))


# --------------------------------------------------------------------------- #
# exporter — the aggregate Prometheus exposition
# --------------------------------------------------------------------------- #


def _exporter_cases():
    prom = ("# HELP http_reqs total\n# TYPE http_reqs counter\n"
            'http_reqs 5\nhttp_reqs{code="200"} 4\nhttp_reqs{code="500"} 1\n'
            "latency_seconds 0.125\n")
    garbage = ('good_metric 1\nthis is not prometheus at all\nbad_value abc\n'
               'unterminated{label="x 2\nanother_good 2\n'
               'trailing_ts 3 1699999999000\nempty_labels{} 4\n'
               'spaced{ a = "1" } 5\ntrail_comma{a="1",} 6\n'
               'dupname{a="1",a="2"} 7\nspoof{service="fake",k="v"} 8\n'
               'esc{t="a\\tb",q="x\\"y",bs="c\\\\d",nl="e\\nf"} 9\n'
               'suitedash_up 0\nsuitedash_service_up 0\nlegit 7\n'
               "grouped 1_000\nplain 3.0\nnanv NaN\ninfv +Inf\nbigv 1e16\n"
               "just_under 5e14\njust_over 2e15\nfracv 0.5\nnegv -12.25\n"
               "expv 1e-7\nhugev 1e300\n")
    js = ('{"docs": 1000, "a.b": 2, "ok": true, "tags": ["x"], "9lead": 3,'
          ' "": 4, "suitedash_up": 0, "nested": {"n": 5}, "s": "6.5"}')
    return [
        ("prom", results(result("alpha", latency=5.0, metrics_raw=prom,
                                metrics_ctype="text/plain"))),
        ("garbage", results(result("s", latency=12.0, metrics_raw=garbage,
                                   metrics_ctype="text/plain"))),
        ("json", results(result("js", latency=1.5, metrics_raw=js,
                                metrics_ctype="application/json"))),
        ("json_sniffed", results(result("js2", latency=None, metrics_raw=js,
                                        metrics_ctype=""))),
        ("hostile_name", results(result('ev"il\\\nx', latency=0.5,
                                        metrics_raw="m 1\n",
                                        metrics_ctype="text/plain"))),
        ("mixed", results(
            result("alpha", latency=12.0, metrics_raw="x 1\n",
                   metrics_ctype="text/plain"),
            result("beta", up=False),
            result("gamma", latency=0.0, metrics_raw="   \n",
                   metrics_ctype="text/plain"),
            result("delta", latency=1234.5678,
                   metrics_raw='{"suitedash_x": 1, "ok": 2}',
                   metrics_ctype="application/json"))),
        ("empty", results()),
        ("deep_json", results(result("evil", latency=1.0,
                                     metrics_raw="[" * 4000,
                                     metrics_ctype="application/json"))),
    ]


def gen_exporter() -> None:
    from suitedash.exporter import render_federated_metrics
    print("== exporter: federated exposition per case ==")
    for label, res in _exporter_cases():
        print("--- %s ---" % label)
        print("    %s," % rs(render_federated_metrics(res).decode("utf-8")))


# --------------------------------------------------------------------------- #
# config — the TOML loader
# --------------------------------------------------------------------------- #

CONFIG_CASES = [
    ("empty", ""),
    ("toplevel", 'host = "0.0.0.0"\nport = 9000\nrefresh_seconds = 0\n'
                 "timeout_seconds = 1.5\nmax_workers = 0\ncache_ttl = -2.0\n"
                 "history_capacity = 1\nhistory_max_series = 999999999\n"
                 "alert_history = 0\nsparklines = false\n"),
    ("clamp_high", "history_capacity = 999999\nalert_history = 99999999\n"
                   "history_max_series = 0\nmax_workers = 3\ncache_ttl = 2.5\n"),
    ("coercions", 'host = 5\nport = "9001"\nrefresh_seconds = 3.9\n'
                  'timeout_seconds = "2"\nsparklines = 0\n'),
    ("services", '[[service]]\nname = "only"\nbase_url = "http://x:1//"\n'
                 'metrics_keys = ["a", "b"]\n\n'
                 '[[service]]\nname = "  spaced  "\nbase_url = " http://y:2 "\n'
                 'health_path = "/hz"\nmetrics_path = "/m"\nlabel = "L"\n'),
    ("service_falsy_keys", '[[service]]\nname = "x"\nbase_url = "http://x"\n'
                           "metrics_keys = []\n"),
    ("service_int_keys", '[[service]]\nname = "x"\nbase_url = "http://x"\n'
                         "metrics_keys = [1, 2.5, true]\n"),
    ("alerts", '[[alert]]\nid="busy"\nservice="gitweb"\nmetric="m"\nop=">="\n'
               'threshold=100\nfor=3\nseverity="warning"\ndescription="d"\n\n'
               '[[alert]]\nid="down"\nkind="down"\nservice="*"\n'),
    ("alert_defaults", '[[alert]]\nmetric="m"\n\n[[alert]]\nkind="DOWN"\n'
                       'service="  "\nseverity="  CRITICAL "\n'),
    ("alert_for_polls", '[[alert]]\nid="a"\nmetric="m"\nfor=0\n\n'
                        '[[alert]]\nid="b"\nmetric="m"\nfor_polls=99999999\n\n'
                        '[[alert]]\nid="c"\nmetric="m"\nfor=2\nfor_polls=9\n'),
    ("alert_autoid", '[[alert]]\nid="rule-2"\nservice="svc"\nmetric="cpu"\n'
                     'op=">"\nthreshold=90\n\n'
                     '[[alert]]\nservice="svc"\nmetric="mem"\nop=">"\nthreshold=10\n\n'
                     '[[alert]]\nservice="svc"\nmetric="io"\nop=">"\nthreshold=1\n'),
    ("alert_threshold_str", '[[alert]]\nid="a"\nmetric="m"\nthreshold="1_000.5"\n'),
    ("comments", "# lead\nport = 8080 # trailing\n\n# another\nhost = 'lit'\n"),
    ("empty_arrays", "service = []\nalert = []\n"),
    ("example_file", None),  # the shipped suitedash.example.toml
    # --- rejected ---
    ("err_bad_op", '[[alert]]\nid="x"\nmetric="m"\nop="=~"\nthreshold=1\n'),
    ("err_no_metric", '[[alert]]\nid="x"\nop=">"\nthreshold=1\n'),
    ("err_dup_id", '[[alert]]\nid="dup"\nkind="down"\n\n[[alert]]\nid="dup"\nkind="down"\n'),
    ("err_bad_kind", '[[alert]]\nid="x"\nkind="weird"\n'),
    ("err_threshold", '[[alert]]\nid="x"\nmetric="m"\nthreshold="abc"\n'),
    ("err_threshold_inf", '[[alert]]\nid="x"\nmetric="m"\nthreshold=inf\n'),
    ("err_for", '[[alert]]\nid="x"\nmetric="m"\nfor="abc"\n'),
    ("err_service_no_name", '[[service]]\nbase_url = "http://x"\n'),
    ("err_service_no_base", '[[service]]\nname = "x"\n'),
    ("err_keys_not_list", '[[service]]\nname="x"\nbase_url="http://x"\nmetrics_keys=5\n'),
    ("err_service_not_array", 'service = "nope"\n'),
    ("err_alert_not_array", 'alert = "nope"\n'),
    ("err_toml_syntax", "port = \n"),
    ("err_toml_dup_key", "port = 1\nport = 2\n"),
    ("err_port_str", 'port = "abc"\n'),
]


def _dump_config(cfg) -> str:
    parts = ["host=%s port=%d refresh=%d timeout=%s workers=%d ttl=%s "
             "hist=%d series=%d alerts=%d spark=%s" % (
                 cfg.host, cfg.port, cfg.refresh_seconds, num(cfg.timeout_seconds),
                 cfg.max_workers, num(cfg.cache_ttl), cfg.history_capacity,
                 cfg.history_max_series, cfg.alert_history, cfg.sparklines)]
    for s in cfg.services:
        parts.append("svc %s|%s|%s|%s|%s|%s" % (
            s.name, s.base_url, s.health_path, s.metrics_path,
            ",".join(s.metrics_keys), s.label))
    for r in cfg.alert_rules:
        parts.append("rule %s|%s|%s|%s|%s|%s|%d|%s|%s" % (
            r.id, r.service, r.kind, r.metric, r.op, num(r.threshold),
            r.for_polls, r.severity, r.description))
    return " ;; ".join(parts)


def gen_config() -> None:
    import os
    import tempfile
    from suitedash.config import Config, apply_service_flags, load_config

    here = os.path.dirname(os.path.abspath(__file__))
    example = os.path.join(here, "..", "..", "..", "legacy-python", "suitedash",
                           "suitedash.example.toml")

    print("== config: (toml, dump) ==")
    for label, text in CONFIG_CASES:
        if text is None:
            with open(example, "r", encoding="utf-8") as fh:
                text = fh.read()
        with tempfile.NamedTemporaryFile("w", suffix=".toml", delete=False,
                                         encoding="utf-8") as fh:
            fh.write(text)
            path = fh.name
        try:
            dump = _dump_config(load_config(path))
        except Exception as exc:
            dump = "ERROR"
        finally:
            os.unlink(path)
        print("    // %s" % label)
        print("    (%s, %s)," % (rs(text), rs(dump)))

    print("== config: apply_service_flags (specs, dump) ==")
    for specs in [["gitweb=http://10.0.0.5:8801/"],
                  ["newsvc=http://h:9/"],
                  ["gitweb=http://a", "newsvc=http://b", "gitweb=http://c/"],
                  ["  spaced  =  http://d//  "],
                  ["oops"], ["=http://x"], ["name="]]:
        try:
            dump = _dump_config(apply_service_flags(Config(), list(specs)))
        except ValueError:
            dump = "ERROR"
        print("    (&[%s], %s)," % (", ".join('"%s"' % s for s in specs), rs(dump)))


SECTIONS = {
    "pyfmt": gen_pyfmt,
    "metrics": gen_metrics,
    "history": gen_history,
    "alerts": gen_alerts,
    "render": gen_render,
    "exporter": gen_exporter,
    "config": gen_config,
}

if __name__ == "__main__":
    wanted = sys.argv[1:] or list(SECTIONS)
    for name in wanted:
        SECTIONS[name]()
