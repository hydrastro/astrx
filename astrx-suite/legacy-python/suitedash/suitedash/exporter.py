"""Aggregate ``/metrics`` exporter: federate every polled service into one
Prometheus text exposition, plus suitedash's own gauges.

Prometheus can scrape suitedash alone and get the whole suite: each upstream
series is re-emitted with a ``service="<name>"`` label added.  Upstream bodies
are parsed *defensively* — a hostile or garbled ``/metrics`` must never break the
exporter or emit invalid text:

* Only lines matching a strict ``name{labels} value [ts]`` grammar are re-emitted;
  anything else (comments, HELP/TYPE, junk, unparseable label blocks, non-numeric
  values) is skipped.  Upstream HELP/TYPE are dropped so two services exposing the
  same metric name cannot produce a duplicate-TYPE error; the federated samples
  are untyped and grouped by name for a clean exposition.
* JSON ``/metrics`` bodies are flattened one level and emitted as
  ``key{service="…"} value``, with keys sanitised to valid metric names.
* The added ``service`` label value is escaped per the Prometheus text format
  (``\\`` ``"`` newline), so a hostile service name cannot break out of the label.

Everything is bounded: upstream bodies are capped at fetch time
(:data:`suitedash.probe.MAX_FEDERATE_BODY`) and at most :data:`MAX_FEDERATE_LINES`
series per service are federated.  This module is pure — a function of the poll
results — and holds no state.
"""

from __future__ import annotations

import json
import math
import re
from collections import OrderedDict
from typing import List, Optional, Tuple

from .probe import flatten_json

CONTENT_TYPE = "text/plain; version=0.0.4; charset=utf-8"

#: Per-service cap on federated series (upstream bodies are also byte-capped).
MAX_FEDERATE_LINES = 5000

# Prometheus grammar fragments (text exposition format 0.0.4).
_MNAME = r"[a-zA-Z_:][a-zA-Z0-9_:]*"
_LNAME = r"[a-zA-Z_][a-zA-Z0-9_]*"
_LVAL = r'"(?:[^"\\]|\\.)*"'
_ONE_LABEL = _LNAME + r"=" + _LVAL
_LABELS = r"\{\s*(?:" + _ONE_LABEL + r"\s*(?:,\s*" + _ONE_LABEL + r"\s*)*,?\s*)?\}"
#: A whole, well-formed sample line.  A non-matching (garbled) line is skipped.
_SAMPLE_RE = re.compile(
    r"^(" + _MNAME + r")(" + _LABELS + r")?[ \t]+(\S+)(?:[ \t]+\S+)?[ \t]*$"
)
#: One ``name="value"`` pair inside an already-validated label block.
_LABEL_RE = re.compile(r"(" + _LNAME + r')="((?:[^"\\]|\\.)*)"')
_BAD_NAME_CHARS = re.compile(r"[^a-zA-Z0-9_:]")
_NAME_START = re.compile(r"[a-zA-Z_:]")

#: suitedash owns this metric-name prefix.  A federated upstream series must
#: never be emitted into it, or a hostile service could forge our heartbeat
#: (``suitedash_up``) or duplicate an authoritative per-service gauge.
_RESERVED_PREFIX = "suitedash_"


def _is_reserved(name: str) -> bool:
    return name.startswith(_RESERVED_PREFIX)


def _escape_label_value(s) -> str:
    """Escape a string for a Prometheus label value (``\\`` ``"`` newline, CR)."""
    return (
        str(s)
        .replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\n", "\\n")
        .replace("\r", "")
    )


def _unescape_label_value(s: str) -> str:
    """Decode an upstream label value to its logical string.

    The Prometheus text format defines exactly three escapes — ``\\\\``, ``\\"``
    and ``\\n``.  The tolerant sample grammar (:data:`_LVAL`) also lets an
    *invalid* escape such as ``\\t`` through; here a backslash that does not
    introduce a valid escape is decoded as a literal backslash.  Feeding the
    result back through :func:`_escape_label_value` therefore always yields a
    well-formed value, so a hostile upstream can never smuggle an invalid escape
    (or a stray quote/backslash) into the federated exposition.
    """
    if "\\" not in s:
        return s
    out: List[str] = []
    i, n = 0, len(s)
    while i < n:
        c = s[i]
        if c == "\\" and i + 1 < n:
            nxt = s[i + 1]
            if nxt == "\\":
                out.append("\\")
                i += 2
                continue
            if nxt == '"':
                out.append('"')
                i += 2
                continue
            if nxt == "n":
                out.append("\n")
                i += 2
                continue
            # Invalid escape: keep the backslash as a literal character.
            out.append("\\")
            i += 1
            continue
        out.append(c)
        i += 1
    return "".join(out)


def _num(v) -> str:
    """Format a finite number as a Prometheus value token (int when integral)."""
    try:
        f = float(v)
    except (TypeError, ValueError):
        return "0"
    if not math.isfinite(f):
        return "0"
    if f.is_integer() and abs(f) < 1e15:
        return str(int(f))
    return repr(f)


def _sanitize_metric_name(k) -> Optional[str]:
    """Coerce a JSON key to a valid metric name, or ``None`` if impossible."""
    name = _BAD_NAME_CHARS.sub("_", str(k))
    if not name:
        return None
    if not _NAME_START.match(name[0]):
        name = "_" + name
    return name


def _looks_json(text: str, ctype: str) -> bool:
    if "json" in (ctype or "").lower():
        return True
    return text.lstrip()[:1] in ("{", "[")


def _merge_labels(label_block: Optional[str], service: str) -> str:
    """Build the inner label list, our ``service`` label first.

    Label *names* are de-duplicated (first wins, and our ``service`` is always
    authoritative) because a repeated label name is a Prometheus parse error.
    Each upstream *value* is decoded to its logical string and re-escaped, so an
    invalid escape or stray quote/backslash from a hostile service can never
    produce invalid exposition."""
    parts = ['service="%s"' % _escape_label_value(service)]
    seen = {"service"}
    if label_block:
        inner = label_block[1:-1]  # strip the validated { }
        for m in _LABEL_RE.finditer(inner):
            name = m.group(1)
            if name in seen:
                continue  # drop duplicate names (incl. an upstream 'service')
            seen.add(name)
            value = _escape_label_value(_unescape_label_value(m.group(2)))
            parts.append('%s="%s"' % (name, value))
    return ",".join(parts)


def _federate_prometheus(service: str, raw: str) -> List[Tuple[str, str]]:
    out: List[Tuple[str, str]] = []
    for line in raw.splitlines():
        s = line.strip()
        if not s or s.startswith("#"):
            continue
        m = _SAMPLE_RE.match(s)
        if m is None:
            continue  # garbled / unparseable label block -> skip
        name, labels, value = m.group(1), m.group(2), m.group(3)
        if _is_reserved(name):
            continue  # never let an upstream forge/duplicate our own gauges
        try:
            fv = float(value)  # reject junk; NaN/Inf are canonicalised by _num
        except ValueError:
            continue
        out.append(
            (name, "%s{%s} %s" % (name, _merge_labels(labels, service), _num(fv)))
        )
        if len(out) >= MAX_FEDERATE_LINES:
            break
    return out


def _federate_json(service: str, raw: str) -> List[Tuple[str, str]]:
    try:
        flat = flatten_json(json.loads(raw))
    except Exception:
        # Includes RecursionError from a deeply-nested body (``[[[[…``), which is
        # NOT a ValueError/TypeError and would otherwise escape render and 500 the
        # whole /metrics endpoint. A hostile body must only ever yield no series.
        return []
    esc = _escape_label_value(service)
    out: List[Tuple[str, str]] = []
    for k, v in flat.items():
        name = _sanitize_metric_name(k)
        if name is None or _is_reserved(name):
            continue  # skip reserved suitedash_* names an upstream might forge
        out.append((name, '%s{service="%s"} %s' % (name, esc, _num(v))))
        if len(out) >= MAX_FEDERATE_LINES:
            break
    return out


def _federate_service(service: str, result) -> List[Tuple[str, str]]:
    raw = getattr(result, "metrics_raw", "") or ""
    if not raw.strip():
        return []
    ctype = getattr(result, "metrics_ctype", "") or ""
    if _looks_json(raw, ctype):
        return _federate_json(service, raw)
    return _federate_prometheus(service, raw)


def render_federated_metrics(results) -> bytes:
    """Federate ``results`` into one Prometheus exposition (bytes, UTF-8)."""
    out: List[str] = []
    out.append("# HELP suitedash_up 1 if the suitedash dashboard is running.")
    out.append("# TYPE suitedash_up gauge")
    out.append("suitedash_up 1")

    up_samples: List[str] = []
    dur_samples: List[str] = []
    cnt_samples: List[str] = []
    federated: List[Tuple[str, str]] = []

    for name, r in results.items():
        lbl = _escape_label_value(name)
        up_samples.append(
            'suitedash_service_up{service="%s"} %d' % (lbl, 1 if r.up else 0)
        )
        lat = getattr(r, "latency_ms", None)
        if lat is not None and math.isfinite(lat):
            dur_samples.append(
                'suitedash_service_scrape_duration_seconds{service="%s"} %s'
                % (lbl, _num(lat / 1000.0))
            )
        fed = _federate_service(name, r)
        cnt_samples.append(
            'suitedash_service_metric_count{service="%s"} %d' % (lbl, len(fed))
        )
        federated.extend(fed)

    out.append("# HELP suitedash_service_up 1 if the service's last probe was UP, else 0.")
    out.append("# TYPE suitedash_service_up gauge")
    out.extend(up_samples)
    out.append(
        "# HELP suitedash_service_scrape_duration_seconds Seconds the last successful probe took."
    )
    out.append("# TYPE suitedash_service_scrape_duration_seconds gauge")
    out.extend(dur_samples)
    out.append(
        "# HELP suitedash_service_metric_count Federated upstream series emitted for the service."
    )
    out.append("# TYPE suitedash_service_metric_count gauge")
    out.extend(cnt_samples)

    # Group federated upstream samples by metric name so each family is a single
    # contiguous block — a clean, tool-friendly exposition.
    grouped: "OrderedDict[str, List[str]]" = OrderedDict()
    for name, line in federated:
        grouped.setdefault(name, []).append(line)
    for lines in grouped.values():
        out.extend(lines)

    return ("\n".join(out) + "\n").encode("utf-8")
