"""Threshold + down-detection alerting evaluated once per poll sweep.

The engine is a small, *stateful* object: it is handed each poll's results and
advances a per-``(service, rule)`` :class:`AlertState` — a breach *streak*, a
firing/ok flag, when the current status began, and the last observed value.  A
metric rule fires only after its condition holds for ``for_polls`` consecutive
sweeps (debounced), and clears the moment the condition no longer holds.  A
``down`` rule fires when a service's last probe was DOWN.

Everything is bounded: the rule list is capped by the config loader, the number
of live states is naturally bounded by ``rules x services`` (states for
services no longer polled are pruned each sweep), and a transition event log is
a fixed-length :class:`collections.deque`.

The engine holds no lock of its own; :class:`suitedash.monitor.Monitor` owns the
lock and calls :meth:`update`/:meth:`views` under it.  It never raises on a
hostile value — a bad comparison degrades to "not breaching".
"""

from __future__ import annotations

import math
import time
from collections import deque
from dataclasses import dataclass, field
from typing import Callable, Dict, List, Optional, Sequence, Tuple

from .config import AlertRule

#: Comparison operators a metric rule may use, kept tiny and total.
_OPS: Dict[str, Callable[[float, float], bool]] = {
    ">": lambda a, b: a > b,
    ">=": lambda a, b: a >= b,
    "<": lambda a, b: a < b,
    "<=": lambda a, b: a <= b,
    "==": lambda a, b: a == b,
    "!=": lambda a, b: a != b,
}

_SEVERITY_ORDER = {"critical": 0, "warning": 1, "info": 2}


@dataclass
class AlertState:
    """Mutable per-``(service, rule)`` state carried across poll sweeps."""

    rule_id: str
    service: str
    streak: int = 0
    firing: bool = False
    since: float = 0.0
    last_value: Optional[float] = None
    total_fires: int = 0


@dataclass(frozen=True)
class AlertView:
    """An immutable snapshot row for rendering / JSON (firing-first ordered)."""

    service: str
    rule_id: str
    kind: str
    severity: str
    description: str
    metric: str
    op: str
    threshold: float
    for_polls: int
    firing: bool
    status: str
    since: float
    last_value: Optional[float]
    streak: int


@dataclass(frozen=True)
class AlertEvent:
    """A single firing/clear transition, retained in a bounded log."""

    at: float
    service: str
    rule_id: str
    status: str  # "firing" | "ok"
    value: Optional[float]


def _eval(rule: AlertRule, result) -> Tuple[bool, Optional[float]]:
    """Return ``(breaching, observed_value)`` for ``rule`` against one result.

    Never raises: an unknown operator or an un-comparable value is "not
    breaching".  A metric rule against a DOWN service (metrics unknown) is not
    breaching — the separate ``down`` rule is what catches that.
    """
    if rule.kind == "down":
        return (not bool(getattr(result, "up", False))), None
    if not getattr(result, "up", False):
        return False, None
    v = result.metrics.get(rule.metric)
    if v is None:
        return False, None
    try:
        fv = float(v)
    except (TypeError, ValueError):
        return False, None
    if not math.isfinite(fv):
        return False, None
    op = _OPS.get(rule.op)
    if op is None:
        return False, fv
    try:
        return bool(op(fv, rule.threshold)), fv
    except Exception:  # pragma: no cover - operators above are total
        return False, fv


class AlertEngine:
    """Stateful rule evaluator.  Feed it results with :meth:`update` per sweep."""

    def __init__(
        self,
        rules: Sequence[AlertRule],
        alert_history: int = 128,
        clock: Callable[[], float] = time.time,
    ):
        self.rules: List[AlertRule] = list(rules)
        self._clock = clock
        self._states: Dict[Tuple[str, str], AlertState] = {}
        self._events: "deque[AlertEvent]" = deque(maxlen=max(1, int(alert_history)))

    def _targets(self, rule: AlertRule, results) -> Sequence[str]:
        if rule.service in ("*", ""):
            return list(results.keys())
        return [rule.service]

    def update(self, results) -> None:
        """Advance every rule's state against this sweep's ``results``."""
        now = self._clock()
        seen: set = set()
        for rule in self.rules:
            for svc in self._targets(rule, results):
                r = results.get(svc)
                if r is None:
                    continue  # rule references a service we do not poll
                key = (svc, rule.id)
                seen.add(key)
                st = self._states.get(key)
                if st is None:
                    st = AlertState(rule_id=rule.id, service=svc, since=now)
                    self._states[key] = st
                breaching, value = _eval(rule, r)
                st.last_value = value
                st.streak = st.streak + 1 if breaching else 0
                firing = st.streak >= max(1, rule.for_polls)
                if firing != st.firing:
                    st.firing = firing
                    st.since = now
                    if firing:
                        st.total_fires += 1
                    self._events.append(
                        AlertEvent(
                            at=now,
                            service=svc,
                            rule_id=rule.id,
                            status="firing" if firing else "ok",
                            value=value,
                        )
                    )
        # Prune states whose (service, rule) was not targeted this sweep so the
        # state map can never outgrow rules x currently-polled services.
        for key in [k for k in self._states if k not in seen]:
            del self._states[key]

    def views(self) -> List[AlertView]:
        """Current alert rows, firing first then by severity/service/rule."""
        by_id = {r.id: r for r in self.rules}
        out: List[AlertView] = []
        for (svc, rid), st in self._states.items():
            rule = by_id.get(rid)
            if rule is None:  # pragma: no cover - rules are stable per engine
                continue
            out.append(
                AlertView(
                    service=svc,
                    rule_id=rid,
                    kind=rule.kind,
                    severity=rule.severity,
                    description=rule.description,
                    metric=rule.metric,
                    op=rule.op,
                    threshold=rule.threshold,
                    for_polls=rule.for_polls,
                    firing=st.firing,
                    status="firing" if st.firing else "ok",
                    since=st.since,
                    last_value=st.last_value,
                    streak=st.streak,
                )
            )
        out.sort(
            key=lambda a: (
                not a.firing,
                _SEVERITY_ORDER.get(a.severity, 3),
                a.service,
                a.rule_id,
            )
        )
        return out

    def events(self) -> List[AlertEvent]:
        """The bounded transition log, oldest first."""
        return list(self._events)
