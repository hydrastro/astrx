"""Alerting: debounced metric rules, down-detection, wildcards, bounds, and the
TOML rule loader. Pure/offline — drives the engine with synthetic results."""

import os
import sys
import tempfile
import unittest
from collections import OrderedDict

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from suitedash.alerts import AlertEngine
from suitedash.config import MAX_RULES, AlertRule, load_config
from suitedash.probe import ServiceResult


class _Clock:
    """A hand-cranked clock so 'since' timestamps are deterministic."""

    def __init__(self, t=1000.0):
        self.t = float(t)

    def __call__(self):
        return self.t

    def tick(self, dt=1.0):
        self.t += dt


def _results(**services):
    """Build an OrderedDict of name -> ServiceResult from up/metrics specs."""
    out = OrderedDict()
    for name, spec in services.items():
        up = spec.get("up", True)
        out[name] = ServiceResult(
            name=name, base_url="http://x", up=up, metrics=spec.get("metrics", {})
        )
    return out


class TestMetricRuleDebounce(unittest.TestCase):
    def setUp(self):
        self.clock = _Clock()
        self.rule = AlertRule(
            id="busy", service="alpha", kind="metric",
            metric="q", op=">", threshold=10, for_polls=3,
        )
        self.eng = AlertEngine([self.rule], clock=self.clock)

    def _view(self):
        return self.eng.views()[0]

    def test_fires_only_after_n_consecutive_breaches(self):
        for _ in range(2):  # 2 breaches -> not yet
            self.clock.tick()
            self.eng.update(_results(alpha={"metrics": {"q": 50}}))
        self.assertFalse(self._view().firing)
        self.assertEqual(self._view().streak, 2)

        self.clock.tick()  # 3rd consecutive breach -> fires
        self.eng.update(_results(alpha={"metrics": {"q": 50}}))
        v = self._view()
        self.assertTrue(v.firing)
        self.assertEqual(v.status, "firing")
        self.assertEqual(v.last_value, 50.0)

    def test_streak_resets_on_a_single_good_poll(self):
        for _ in range(2):
            self.eng.update(_results(alpha={"metrics": {"q": 50}}))
        self.eng.update(_results(alpha={"metrics": {"q": 5}}))  # good -> reset
        self.assertEqual(self._view().streak, 0)
        # Must climb from zero again; one more breach is not enough.
        self.eng.update(_results(alpha={"metrics": {"q": 50}}))
        self.assertFalse(self._view().firing)

    def test_clears_on_recovery_and_since_advances(self):
        for _ in range(3):
            self.clock.tick()
            self.eng.update(_results(alpha={"metrics": {"q": 50}}))
        fired_since = self._view().since
        self.assertTrue(self._view().firing)

        self.clock.tick(5)
        self.eng.update(_results(alpha={"metrics": {"q": 1}}))  # recover
        v = self._view()
        self.assertFalse(v.firing)
        self.assertNotEqual(v.since, fired_since)  # since moved on the transition
        # One firing + one clear transition were logged.
        statuses = [e.status for e in self.eng.events()]
        self.assertEqual(statuses, ["firing", "ok"])

    def test_missing_metric_never_fires(self):
        for _ in range(5):
            self.eng.update(_results(alpha={"metrics": {}}))  # key absent
        self.assertFalse(self._view().firing)
        self.assertIsNone(self._view().last_value)

    def test_metric_rule_does_not_fire_while_service_down(self):
        for _ in range(5):
            self.eng.update(_results(alpha={"up": False}))
        self.assertFalse(self._view().firing)


class TestDownRule(unittest.TestCase):
    def test_down_detection_fires_and_clears(self):
        clock = _Clock()
        eng = AlertEngine(
            [AlertRule(id="d", service="svc", kind="down", for_polls=1)], clock=clock
        )
        eng.update(_results(svc={"up": False}))
        self.assertTrue(eng.views()[0].firing)
        clock.tick()
        eng.update(_results(svc={"up": True, "metrics": {}}))
        self.assertFalse(eng.views()[0].firing)

    def test_wildcard_applies_to_every_service(self):
        eng = AlertEngine([AlertRule(id="any", service="*", kind="down")])
        eng.update(_results(a={"up": True}, b={"up": False}, c={"up": False}))
        firing = {v.service for v in eng.views() if v.firing}
        self.assertEqual(firing, {"b", "c"})


class TestEngineBounds(unittest.TestCase):
    def test_states_are_pruned_when_a_service_stops_being_polled(self):
        eng = AlertEngine([AlertRule(id="any", service="*", kind="down")])
        eng.update(_results(a={"up": False}, b={"up": False}))
        self.assertEqual(len(eng.views()), 2)
        eng.update(_results(a={"up": False}))  # b vanished
        self.assertEqual({v.service for v in eng.views()}, {"a"})

    def test_event_log_is_bounded(self):
        eng = AlertEngine(
            [AlertRule(id="d", service="s", kind="down", for_polls=1)],
            alert_history=3,
        )
        # Flap up/down repeatedly -> many transitions, but log holds only 3.
        for i in range(20):
            eng.update(_results(s={"up": bool(i % 2)}))
        self.assertLessEqual(len(eng.events()), 3)


class TestRuleConfigLoader(unittest.TestCase):
    def _load(self, toml_text):
        with tempfile.NamedTemporaryFile("w", suffix=".toml", delete=False) as fh:
            fh.write(toml_text)
            path = fh.name
        try:
            return load_config(path)
        finally:
            os.unlink(path)

    def test_parses_metric_and_down_rules(self):
        cfg = self._load(
            '[[alert]]\nid="busy"\nservice="gitweb"\nmetric="m"\nop=">="\n'
            'threshold=100\nfor=3\nseverity="warning"\n\n'
            '[[alert]]\nid="down"\nkind="down"\nservice="*"\n'
        )
        self.assertEqual(len(cfg.alert_rules), 2)
        r0 = cfg.alert_rules[0]
        self.assertEqual((r0.id, r0.op, r0.threshold, r0.for_polls), ("busy", ">=", 100.0, 3))
        self.assertEqual(cfg.alert_rules[1].kind, "down")

    def test_rejects_bad_operator(self):
        with self.assertRaises(ValueError):
            self._load('[[alert]]\nid="x"\nmetric="m"\nop="=~"\nthreshold=1\n')

    def test_rejects_metric_rule_without_metric(self):
        with self.assertRaises(ValueError):
            self._load('[[alert]]\nid="x"\nop=">"\nthreshold=1\n')

    def test_for_polls_is_clamped_to_at_least_one(self):
        cfg = self._load('[[alert]]\nid="x"\nmetric="m"\nop=">"\nthreshold=1\nfor=0\n')
        self.assertEqual(cfg.alert_rules[0].for_polls, 1)

    def test_rule_count_is_bounded(self):
        blocks = "".join(
            '[[alert]]\nid="r%d"\nmetric="m"\nop=">"\nthreshold=1\n\n' % i
            for i in range(MAX_RULES + 25)
        )
        cfg = self._load(blocks)
        self.assertEqual(len(cfg.alert_rules), MAX_RULES)

    def test_duplicate_explicit_rule_id_is_rejected(self):
        with self.assertRaises(ValueError):
            self._load(
                '[[alert]]\nid="dup"\nkind="down"\n\n'
                '[[alert]]\nid="dup"\nkind="down"\n'
            )

    def test_auto_id_does_not_collide_with_explicit_id(self):
        # Rule 0 explicitly claims "rule-2"; rule 1 has no id and would otherwise
        # auto-get "rule-2" from its index (idx+1). The loader must hand rule 1 a
        # distinct id so the engine keeps BOTH rules instead of merging them into
        # one (which silently dropped an alert before the fix).
        cfg = self._load(
            '[[alert]]\nid="rule-2"\nservice="svc"\nmetric="cpu"\nop=">"\nthreshold=90\n\n'
            '[[alert]]\nservice="svc"\nmetric="mem"\nop=">"\nthreshold=10\n'
        )
        self.assertEqual(len(cfg.alert_rules), 2)
        ids = [r.id for r in cfg.alert_rules]
        self.assertEqual(len(set(ids)), 2, "rule ids collided: %r" % ids)
        # Both rules must be live in the engine: breaching both metrics fires two.
        eng = AlertEngine(cfg.alert_rules)
        eng.update(_results(svc={"up": True, "metrics": {"cpu": 99, "mem": 99}}))
        fired = {(v.metric, v.firing) for v in eng.views()}
        self.assertEqual(fired, {("cpu", True), ("mem", True)})


if __name__ == "__main__":
    unittest.main()
