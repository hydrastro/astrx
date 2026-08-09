"""Aggregate /metrics federation: relabels upstream series, skips garbage, emits
suitedash's own gauges, escapes hostile labels, and stays valid Prometheus text.
Pure/offline."""

import os
import re
import sys
import unittest
from collections import OrderedDict

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from suitedash.exporter import _federate_json, render_federated_metrics
from suitedash.probe import ServiceResult, parse_prometheus

# An INDEPENDENT strict check that every emitted sample line is well-formed
# Prometheus text: name, optional label block, a value, optional timestamp.
_LINE = re.compile(
    r'^[a-zA-Z_:][a-zA-Z0-9_:]*'
    r'(\{([a-zA-Z_][a-zA-Z0-9_]*="(?:[^"\\]|\\.)*"(?:,[a-zA-Z_][a-zA-Z0-9_]*="(?:[^"\\]|\\.)*")*)?\})?'
    r'[ \t]+\S+([ \t]+\S+)?[ \t]*$'
)


def _up(name, raw, ctype="text/plain", latency=5.0):
    return ServiceResult(
        name=name, base_url="http://x", up=True, latency_ms=latency,
        metrics_raw=raw, metrics_ctype=ctype,
    )


def _assert_valid_exposition(testcase, text):
    for ln in text.splitlines():
        if not ln or ln.startswith("#"):
            continue
        testcase.assertRegex(ln, _LINE, "invalid exposition line: %r" % ln)


def _assert_strictly_parseable(testcase, text):
    """Stricter than the line grammar: reject the two hostile constructs a real
    Prometheus parser fails the whole scrape on — an *invalid* label-value escape
    (only ``\\`` ``"`` ``n`` are legal after a backslash) and a *duplicate* label
    name within one series."""
    _assert_valid_exposition(testcase, text)
    for ln in text.splitlines():
        if not ln or ln.startswith("#"):
            continue
        block = re.search(r"\{(.*)\}", ln)
        if block:
            names = re.findall(r"(?:^|,)\s*([a-zA-Z_][a-zA-Z0-9_]*)=", block.group(1))
            testcase.assertEqual(
                len(names), len(set(names)), "duplicate label name: %r" % ln
            )
        i = 0
        while i < len(ln):
            if ln[i] == '"':
                i += 1
                while i < len(ln):
                    c = ln[i]
                    if c == "\\":
                        testcase.assertLess(i + 1, len(ln), "dangling escape: %r" % ln)
                        testcase.assertIn(
                            ln[i + 1], '\\"n', "invalid escape sequence: %r" % ln
                        )
                        i += 2
                        continue
                    if c == '"':
                        i += 1
                        break
                    i += 1
            else:
                i += 1


def _series_identities(text):
    """Map ``name{labels}`` -> count of value lines, for duplicate-series checks."""
    ids = {}
    for ln in text.splitlines():
        if not ln or ln.startswith("#"):
            continue
        m = re.match(r"^(\S+?(?:\{[^}]*\})?)[ \t]+\S+(?:[ \t]+\S+)?[ \t]*$", ln)
        if m:
            ids[m.group(1)] = ids.get(m.group(1), 0) + 1
    return ids


class TestFederation(unittest.TestCase):
    def test_relabels_prometheus_samples_with_service_label(self):
        raw = (
            "# HELP http_reqs total\n"
            "# TYPE http_reqs counter\n"
            "http_reqs 5\n"
            'http_reqs{code="200"} 4\n'
        )
        out = render_federated_metrics(OrderedDict([("alpha", _up("alpha", raw))])).decode()
        self.assertIn('http_reqs{service="alpha"} 5', out)
        # Existing labels are preserved, service injected first.
        self.assertIn('http_reqs{service="alpha",code="200"} 4', out)
        # Upstream HELP/TYPE are dropped (no duplicate-TYPE hazard on federation).
        self.assertNotIn("# TYPE http_reqs", out)
        _assert_valid_exposition(self, out)

    def test_skips_garbage_and_non_numeric_lines(self):
        raw = (
            "good_metric 1\n"
            "this is not prometheus at all\n"
            "bad_value abc\n"
            "unterminated{label=\"x 2\n"
            "another_good 2\n"
        )
        out = render_federated_metrics(OrderedDict([("s", _up("s", raw))])).decode()
        self.assertIn('good_metric{service="s"} 1', out)
        self.assertIn('another_good{service="s"} 2', out)
        self.assertNotIn("not prometheus", out)
        self.assertNotIn("abc", out)
        self.assertNotIn("unterminated", out)
        # Exactly the two good series were federated for this service.
        self.assertIn('suitedash_service_metric_count{service="s"} 2', out)
        _assert_valid_exposition(self, out)

    def test_emits_suitedash_own_gauges(self):
        results = OrderedDict([
            ("alpha", _up("alpha", "x 1\n", latency=12.0)),
            ("beta", ServiceResult(name="beta", base_url="x", up=False)),
        ])
        out = render_federated_metrics(results).decode()
        self.assertIn("suitedash_up 1", out)
        self.assertIn('suitedash_service_up{service="alpha"} 1', out)
        self.assertIn('suitedash_service_up{service="beta"} 0', out)
        self.assertIn('suitedash_service_scrape_duration_seconds{service="alpha"} 0.012', out)
        _assert_valid_exposition(self, out)

    def test_json_upstream_is_federated_and_names_sanitized(self):
        raw = '{"docs": 1000, "a.b": 2, "ok": true, "tags": ["x"]}'
        out = render_federated_metrics(
            OrderedDict([("js", _up("js", raw, ctype="application/json"))])
        ).decode()
        self.assertIn('docs{service="js"} 1000', out)
        self.assertIn('a_b{service="js"} 2', out)  # '.' sanitized to '_'
        self.assertNotIn("tags", out)  # non-numeric leaf skipped
        _assert_valid_exposition(self, out)

    def test_hostile_service_name_is_escaped_in_the_label(self):
        hostile = 'ev"il\\\nx'
        out = render_federated_metrics(
            OrderedDict([(hostile, _up(hostile, "m 1\n"))])
        ).decode()
        # The raw quote/backslash/newline must not appear unescaped in the label;
        # they must be emitted as \" \\ \n .
        self.assertIn('service="ev\\"il\\\\\\nx"', out)
        self.assertNotIn('service="ev"il', out)  # no unescaped break-out
        _assert_valid_exposition(self, out)

    def test_hostile_body_never_breaks_the_exporter(self):
        raw = "\x00\x01 garbage {{{ \n" * 50 + 'evil{a="}"} 3\n'
        out = render_federated_metrics(OrderedDict([("s", _up("s", raw))])).decode()
        self.assertIn("suitedash_up 1", out)  # always present
        _assert_valid_exposition(self, out)

    def test_down_service_still_valid_with_no_upstream_series(self):
        results = OrderedDict([("d", ServiceResult(name="d", base_url="x", up=False))])
        out = render_federated_metrics(results).decode()
        self.assertIn("suitedash_up 1", out)
        self.assertIn('suitedash_service_up{service="d"} 0', out)
        _assert_valid_exposition(self, out)

    def test_output_reparses_as_prometheus(self):
        raw = "widgets_total 7\n"
        out = render_federated_metrics(OrderedDict([("w", _up("w", raw))])).decode()
        m = parse_prometheus(out)
        self.assertEqual(m["suitedash_up"], 1.0)
        # Base name resolves through the injected label.
        self.assertEqual(m["widgets_total"], 7.0)


class TestFederationHardening(unittest.TestCase):
    """Regression tests: a hostile upstream must never make the federated
    exposition invalid, forge suitedash's own gauges, or crash the exporter."""

    def test_invalid_upstream_escape_is_reencoded_not_passed_through(self):
        # backslash-t is an INVALID Prometheus escape; verbatim re-emission would
        # fail the whole scrape. It must be normalised to a valid escaped backslash.
        raw = 'm{tag="a\\tb"} 1\n'
        out = render_federated_metrics(OrderedDict([("evil", _up("evil", raw))])).decode()
        self.assertIn(r'm{service="evil",tag="a\\tb"} 1', out)
        self.assertNotIn(r'tag="a\tb"', out)  # the raw invalid escape is gone
        _assert_strictly_parseable(self, out)

    def test_legit_escapes_round_trip(self):
        raw = 'm{a="x\\"y",b="c\\\\d",c="e\\nf"} 1\n'
        out = render_federated_metrics(OrderedDict([("s", _up("s", raw))])).decode()
        self.assertIn(r'a="x\"y"', out)
        self.assertIn(r'b="c\\d"', out)
        self.assertIn(r'c="e\nf"', out)
        _assert_strictly_parseable(self, out)

    def test_duplicate_upstream_label_names_are_deduped(self):
        raw = 'm{a="1",a="2"} 1\n'
        out = render_federated_metrics(OrderedDict([("s", _up("s", raw))])).decode()
        self.assertIn('m{service="s",a="1"} 1', out)  # first wins
        self.assertNotIn('a="2"', out)
        _assert_strictly_parseable(self, out)

    def test_upstream_service_label_is_dropped_for_ours(self):
        raw = 'm{service="spoof",k="v"} 1\n'
        out = render_federated_metrics(OrderedDict([("real", _up("real", raw))])).decode()
        self.assertIn('m{service="real",k="v"} 1', out)
        self.assertNotIn("spoof", out)
        _assert_strictly_parseable(self, out)

    def test_upstream_cannot_forge_reserved_prometheus_series(self):
        # A hostile prometheus body reusing suitedash's own names must be skipped
        # so it cannot duplicate/forge our authoritative gauges (up=True here).
        raw = "suitedash_up 0\nsuitedash_service_up 0\nlegit 7\n"
        out = render_federated_metrics(OrderedDict([("evil", _up("evil", raw))])).decode()
        self.assertIn("suitedash_up 1", out)                         # our heartbeat intact
        self.assertIn('suitedash_service_up{service="evil"} 1', out)  # authoritative
        self.assertNotIn('suitedash_service_up{service="evil"} 0', out)  # forge dropped
        self.assertNotIn('suitedash_up{service="evil"}', out)        # forge dropped
        self.assertIn('legit{service="evil"} 7', out)                # non-reserved kept
        self.assertIn('suitedash_service_metric_count{service="evil"} 1', out)
        # No series identity appears twice (no duplicate-series scrape error).
        for ident, n in _series_identities(out).items():
            self.assertEqual(n, 1, "duplicate series: %r" % ident)

    def test_upstream_cannot_forge_reserved_json_series(self):
        raw = '{"suitedash_up": 0, "ok": 5}'
        out = render_federated_metrics(
            OrderedDict([("evil", _up("evil", raw, ctype="application/json"))])
        ).decode()
        self.assertIn("suitedash_up 1", out)
        self.assertNotIn('suitedash_up{service="evil"}', out)  # reserved name skipped
        self.assertIn('ok{service="evil"} 5', out)
        for ident, n in _series_identities(out).items():
            self.assertEqual(n, 1, "duplicate series: %r" % ident)

    def test_deeply_nested_json_body_yields_no_series_and_valid_output(self):
        # A deeply-nested body makes json.loads raise RecursionError (NOT a
        # ValueError/TypeError); it must be swallowed, not escape and 500 /metrics.
        deep = "[" * 4000
        self.assertEqual(_federate_json("evil", deep), [])
        out = render_federated_metrics(
            OrderedDict([("evil", _up("evil", deep, ctype="application/json"))])
        ).decode()
        self.assertIn("suitedash_up 1", out)  # exporter did not crash
        self.assertIn('suitedash_service_metric_count{service="evil"} 0', out)
        _assert_strictly_parseable(self, out)

    def test_federated_values_are_canonicalized(self):
        # float() accepts '1_000'; verbatim re-emission is invalid Prometheus.
        raw = "grouped 1_000\nplain 3.0\n"
        out = render_federated_metrics(OrderedDict([("s", _up("s", raw))])).decode()
        self.assertIn('grouped{service="s"} 1000', out)
        self.assertNotIn("1_000", out)
        self.assertIn('plain{service="s"} 3', out)
        _assert_strictly_parseable(self, out)


if __name__ == "__main__":
    unittest.main()
