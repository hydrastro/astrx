"""Configuration: the list of services to poll and top-level server settings.

Everything has sensible defaults for the standard astrx-suite localhost ports,
so ``suitedash`` runs with no arguments.  A small TOML file (parsed with the
standard-library :mod:`tomllib`, read-only) or CLI flags can override any of
it.  A service entry is just ``name``/``base_url`` plus which paths to probe
and which metric keys to surface on its card.
"""

from __future__ import annotations

import math
import tomllib
from dataclasses import dataclass, field, replace
from typing import List, Optional, Sequence, Tuple

#: Bounds applied when loading, so a config file can never ask for unbounded
#: memory or an absurd number of rules.
MAX_RULES = 256
MAX_FOR_POLLS = 100_000
MIN_HISTORY_CAPACITY = 2
MAX_HISTORY_CAPACITY = 10_000
MAX_HISTORY_SERIES = 100_000
MAX_ALERT_HISTORY = 10_000

#: The comparison operators a metric alert rule may use.
ALLOWED_OPS = (">", ">=", "<", "<=", "==", "!=")


@dataclass(frozen=True)
class ServiceConfig:
    """One polled service.

    ``health_path`` is tried first for liveness; if it does not answer 2xx a
    set of known fallbacks is tried (see :mod:`suitedash.probe`).  ``metrics_path``
    is fetched for numbers and parsed as Prometheus text *or* JSON automatically.
    ``metrics_keys`` selects which parsed numbers to surface on the card (in
    order); empty means "auto-pick the first few".
    """

    name: str
    base_url: str
    health_path: str = "/health"
    metrics_path: str = "/metrics"
    metrics_keys: Tuple[str, ...] = ()
    label: str = ""  # optional human-friendly caption

    def with_base(self, base_url: str) -> "ServiceConfig":
        return replace(self, base_url=base_url.rstrip("/"))


@dataclass(frozen=True)
class AlertRule:
    """One alerting rule, evaluated once per poll sweep.

    Two kinds:

    * ``kind="metric"`` — fires when ``metric <op> threshold`` holds for
      ``for_polls`` consecutive sweeps (debounced) and clears when it no longer
      holds.  Only the metric keys *surfaced* on a service (its ``metrics_keys``)
      are visible to rules.
    * ``kind="down"`` — fires when the service's last probe was DOWN.

    ``service`` is a service name, or ``"*"`` (the default) to apply the rule to
    every polled service.  ``op`` is one of :data:`ALLOWED_OPS`.
    """

    id: str
    service: str = "*"
    kind: str = "metric"  # "metric" | "down"
    metric: str = ""
    op: str = ">"
    threshold: float = 0.0
    for_polls: int = 1
    severity: str = "warning"  # critical | warning | info (free-form; sorts these)
    description: str = ""


def default_services() -> List[ServiceConfig]:
    """The four astrx-suite services on their standard loopback ports.

    Health paths intentionally mirror each tool's *documented* (inconsistent)
    endpoint; the tolerant prober covers the cases where reality differs.
    Metric keys are real gauges each service exposes.  Note that torrentds is
    pointed at its JSON ``/api/stats`` on purpose, so the default config
    exercises the JSON metrics parser against a real service.
    """
    return [
        ServiceConfig(
            name="gitweb",
            base_url="http://127.0.0.1:8801",
            health_path="/health",
            metrics_path="/metrics",
            metrics_keys=(
                "gitweb_requests_total",
                "gitweb_requests_in_flight",
                "gitweb_uptime_seconds",
            ),
            label="Read-only git web viewer",
        ),
        ServiceConfig(
            name="onioncrawler",
            base_url="http://127.0.0.1:8802",
            health_path="/healthz",
            metrics_path="/metrics",
            metrics_keys=(
                "onioncrawler_pages",
                "onioncrawler_hosts",
                "onioncrawler_frontier_queued",
            ),
            label="Onion search / crawler",
        ),
        ServiceConfig(
            name="websearch",
            base_url="http://127.0.0.1:8803",
            health_path="/stats",
            metrics_path="/metrics",
            metrics_keys=(
                "websearch_docs",
                "websearch_hosts",
                "websearch_searches_total",
            ),
            label="Clear-web search",
        ),
        ServiceConfig(
            name="torrentds",
            base_url="http://127.0.0.1:8804",
            health_path="/health",
            metrics_path="/api/stats",  # JSON — exercises the JSON parser
            metrics_keys=(
                "torrents",
                "pending",
                "total_size",
            ),
            label="Torrent DHT indexer",
        ),
    ]


@dataclass
class Config:
    """Top-level server + poller settings."""

    host: str = "127.0.0.1"
    port: int = 8805
    refresh_seconds: int = 15  # <=0 disables the meta-refresh
    timeout_seconds: float = 3.0  # per-service probe budget
    max_workers: int = 16  # bounded connection/probe pool
    cache_ttl: float = 0.0  # >0 caches poll results for N seconds
    verbose: bool = True
    services: List[ServiceConfig] = field(default_factory=default_services)
    # Alerting + history (all in-memory, bounded, reset on restart).
    alert_rules: List[AlertRule] = field(default_factory=list)
    history_capacity: int = 60  # samples retained per (service, metric) sparkline
    history_max_series: int = 256  # max distinct (service, metric) ring buffers
    alert_history: int = 128  # max alert firing/clear transitions retained
    sparklines: bool = True  # render inline-SVG sparklines on the page


def _as_service(entry: dict) -> ServiceConfig:
    name = str(entry.get("name") or "").strip()
    base = str(entry.get("base_url") or "").strip().rstrip("/")
    if not name or not base:
        raise ValueError("each [[service]] needs a name and a base_url")
    keys = entry.get("metrics_keys") or ()
    if not isinstance(keys, (list, tuple)):
        raise ValueError("metrics_keys must be a list for service %r" % name)
    return ServiceConfig(
        name=name,
        base_url=base,
        health_path=str(entry.get("health_path", "/health")),
        metrics_path=str(entry.get("metrics_path", "/metrics")),
        metrics_keys=tuple(str(k) for k in keys),
        label=str(entry.get("label", "")),
    )


def _as_rule(entry: dict, rid: str) -> AlertRule:
    """Build one :class:`AlertRule` from a ``[[alert]]`` table (validated).

    ``rid`` is the already-resolved, guaranteed-unique rule id (see
    :func:`_build_rules`); the engine keys per-``(service, rule id)`` state on it,
    so a collision would silently merge two rules into one."""
    kind = str(entry.get("kind", "metric")).strip().lower() or "metric"
    if kind not in ("metric", "down"):
        raise ValueError("alert %r: kind must be 'metric' or 'down'" % rid)
    service = str(entry.get("service", "*")).strip() or "*"
    metric = str(entry.get("metric", "")).strip()
    op = str(entry.get("op", ">")).strip()
    if kind == "metric":
        if not metric:
            raise ValueError("alert %r: a metric rule needs a metric" % rid)
        if op not in ALLOWED_OPS:
            raise ValueError(
                "alert %r: op must be one of %s" % (rid, ", ".join(ALLOWED_OPS))
            )
    try:
        threshold = float(entry.get("threshold", 0.0))
    except (TypeError, ValueError):
        raise ValueError("alert %r: threshold must be a number" % rid)
    if not math.isfinite(threshold):
        raise ValueError("alert %r: threshold must be finite" % rid)
    try:
        for_polls = int(entry.get("for", entry.get("for_polls", 1)))
    except (TypeError, ValueError):
        raise ValueError("alert %r: for must be an integer" % rid)
    for_polls = max(1, min(MAX_FOR_POLLS, for_polls))
    severity = str(entry.get("severity", "warning")).strip().lower() or "warning"
    return AlertRule(
        id=rid,
        service=service,
        kind=kind,
        metric=metric,
        op=op,
        threshold=threshold,
        for_polls=for_polls,
        severity=severity,
        description=str(entry.get("description", "")),
    )


def _resolve_rule_ids(entries: Sequence[dict]) -> List[str]:
    """Assign a unique id to every ``[[alert]]`` entry.

    Explicit ids must be unique (a duplicate is a hard error).  Entries without
    an id get ``rule-<n>`` chosen from a set that already contains every explicit
    id AND every id assigned so far, so an auto-id can never collide with an
    explicit one — a collision would make the engine merge two rules into one and
    silently drop an alert."""
    explicit: List[str] = []
    for e in entries:
        rid = str(e.get("id") or "").strip()
        if rid:
            if rid in explicit:
                raise ValueError("alert %r: duplicate alert id" % rid)
            explicit.append(rid)
    used = set(explicit)
    ids: List[str] = []
    for i, e in enumerate(entries):
        rid = str(e.get("id") or "").strip()
        if not rid:
            n = i + 1
            rid = "rule-%d" % n
            while rid in used:
                n += 1
                rid = "rule-%d" % n
            used.add(rid)
        ids.append(rid)
    return ids


def _build_rules(entries: Sequence[dict]) -> List[AlertRule]:
    """Validate ids for uniqueness, then build every rule."""
    ids = _resolve_rule_ids(entries)
    return [_as_rule(e, rid) for e, rid in zip(entries, ids)]


def load_config(path: Optional[str] = None, base: Optional[Config] = None) -> Config:
    """Return a :class:`Config`, overlaying an optional TOML file on defaults.

    Top-level keys (``host``, ``port``, ``refresh_seconds``,
    ``timeout_seconds``, ``max_workers``, ``cache_ttl``) override the matching
    field.  A ``[[service]]`` array-of-tables, if present, *replaces* the whole
    service list so the file is the single source of truth for what to poll.
    """
    cfg = base or Config()
    if not path:
        return cfg
    with open(path, "rb") as fh:
        data = tomllib.load(fh)

    if "host" in data:
        cfg.host = str(data["host"])
    if "port" in data:
        cfg.port = int(data["port"])
    if "refresh_seconds" in data:
        cfg.refresh_seconds = int(data["refresh_seconds"])
    if "timeout_seconds" in data:
        cfg.timeout_seconds = float(data["timeout_seconds"])
    if "max_workers" in data:
        cfg.max_workers = max(1, int(data["max_workers"]))
    if "cache_ttl" in data:
        cfg.cache_ttl = max(0.0, float(data["cache_ttl"]))
    if "history_capacity" in data:
        cfg.history_capacity = max(
            MIN_HISTORY_CAPACITY, min(MAX_HISTORY_CAPACITY, int(data["history_capacity"]))
        )
    if "history_max_series" in data:
        cfg.history_max_series = max(
            1, min(MAX_HISTORY_SERIES, int(data["history_max_series"]))
        )
    if "alert_history" in data:
        cfg.alert_history = max(1, min(MAX_ALERT_HISTORY, int(data["alert_history"])))
    if "sparklines" in data:
        cfg.sparklines = bool(data["sparklines"])

    svc = data.get("service")
    if svc:
        if not isinstance(svc, list):
            raise ValueError("[[service]] must be an array of tables")
        cfg.services = [_as_service(e) for e in svc]

    alerts = data.get("alert")
    if alerts:
        if not isinstance(alerts, list):
            raise ValueError("[[alert]] must be an array of tables")
        # Bounded: never load more than MAX_RULES rules from a file. Ids are
        # resolved to be unique so no two rules can silently collapse in the engine.
        cfg.alert_rules = _build_rules(alerts[:MAX_RULES])
    return cfg


def apply_service_flags(cfg: Config, specs: Sequence[str]) -> Config:
    """Override a service's base_url from ``name=base_url`` CLI specs.

    Unknown names are appended as minimal services (health ``/health``,
    metrics ``/metrics``, auto-surfaced keys).  Handy for quick one-off checks
    without writing a config file.
    """
    by_name = {s.name: i for i, s in enumerate(cfg.services)}
    for spec in specs:
        name, sep, base = spec.partition("=")
        name, base = name.strip(), base.strip().rstrip("/")
        if not sep or not name or not base:
            raise ValueError("--service expects name=base_url, got %r" % spec)
        if name in by_name:
            i = by_name[name]
            cfg.services[i] = cfg.services[i].with_base(base)
        else:
            cfg.services.append(ServiceConfig(name=name, base_url=base))
            by_name[name] = len(cfg.services) - 1
    return cfg
