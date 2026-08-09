"""Tolerant, bounded, SSRF-safe probing of a single suite service.

Design notes
------------
* **Transport.** We use :class:`http.client.HTTPConnection` directly rather
  than :mod:`urllib.request`.  It never follows redirects (the ``follow_location
  = 0`` posture the AstrX PHP bridge uses for SSRF hardening), targets an
  explicit host/port taken *only* from config, and honours a short socket
  timeout for both connect and read.  The scheme is restricted to http/https
  and the response body is capped, so a hostile or huge endpoint cannot make
  the dashboard chase a redirect off-box or buffer unbounded data.

* **Liveness is tolerant.** Services disagree on where health lives
  (``/health`` vs ``/healthz`` vs nothing).  We try the configured path, then a
  list of known fallbacks, and treat *any* 2xx as UP.  A refused connection or a
  timeout is a fast DOWN; a non-2xx status just means "try the next path".  The
  whole liveness check is bounded by a single ``timeout`` budget so trying
  several paths can never multiply the wall-clock cost.

* **Metrics are tolerant.** ``/metrics`` may be Prometheus text on one service
  and JSON on another.  :func:`parse_metrics` auto-detects: it parses JSON and
  flattens one level, or parses ``name value`` Prometheus lines (ignoring
  ``#`` HELP/TYPE comments).  Non-finite values (NaN/Inf) are dropped so the
  JSON API stays strictly valid.
"""

from __future__ import annotations

import http.client
import json
import math
import socket
import ssl
import threading
import time
from dataclasses import dataclass, field
from typing import Dict, List, Optional, Tuple
from urllib.parse import urlsplit

from .config import ServiceConfig

#: Hard cap on a health/metrics response body (defensive; metrics are tiny).
MAX_BODY = 1 << 20  # 1 MiB

#: Cap on the raw metrics text retained on each result for the aggregate
#: ``/metrics`` federation exporter — bounds the memory a poll snapshot (and the
#: optional TTL cache) can hold, independent of the 1 MiB fetch cap.
MAX_FEDERATE_BODY = 1 << 18  # 256 KiB

#: Body is read in chunks this size so a *total* wall-clock deadline can be
#: enforced between reads — a single large ``read`` would otherwise block
#: accumulating a byte-at-a-time drip and never observe the deadline.
_READ_CHUNK = 1 << 16  # 64 KiB

#: Health paths tried after the configured one.  Order matters: cheap, common
#: liveness routes first, then JSON stats endpoints, then a bare ``/`` (a 200 on
#: the index is a last-resort "it's listening and serving" signal).
HEALTH_FALLBACKS: Tuple[str, ...] = (
    "/health",
    "/healthz",
    "/livez",
    "/stats",
    "/api/stats",
    "/status",
    "/",
)

#: When a service surfaces no explicit metric keys, show at most this many.
AUTO_LIMIT = 6


class FetchResult:
    """A minimal HTTP response: status, content-type, (capped) body, latency."""

    __slots__ = ("status", "content_type", "body", "latency_ms")

    def __init__(self, status: int, content_type: str, body: bytes, latency_ms: float):
        self.status = status
        self.content_type = content_type or ""
        self.body = body
        self.latency_ms = latency_ms


def _abort_connection(conn: "http.client.HTTPConnection") -> None:
    """Force-abort ``conn`` from a watchdog thread to unblock an in-flight header
    read a dribbling backend is holding open past the deadline.

    ``conn.close()`` alone is INSUFFICIENT here: while ``getresponse()`` is
    reading, http.client wraps the socket in a ``makefile()`` whose ``SocketIO``
    holds an I/O reference, so ``socket.close()`` is reference-counted and does
    not shut the underlying fd — the blocked ``recv`` keeps consuming the drip.
    ``shutdown(SHUT_RDWR)`` acts on the fd directly, forcing that ``recv`` to
    return EOF at once; the header read then fails and :func:`probe_service`
    maps it to a DOWN result near ``timeout``. ``close()`` then releases the fd.
    """
    sock = getattr(conn, "sock", None)
    if sock is not None:
        try:
            sock.shutdown(socket.SHUT_RDWR)
        except OSError:  # pragma: no cover - already closed / not connected
            pass
    try:
        conn.close()
    except Exception:  # pragma: no cover - defensive against a close() race
        pass


def fetch(base_url: str, path: str, timeout: float) -> FetchResult:
    """GET ``base_url + path`` with a short timeout and **no redirect following**.

    Raises :class:`ConnectionRefusedError`, :class:`TimeoutError`/``socket.timeout``,
    or :class:`OSError` on transport failure; the caller maps those to DOWN.
    """
    parts = urlsplit(base_url)
    scheme = (parts.scheme or "http").lower()
    if scheme not in ("http", "https"):
        raise ValueError("unsupported scheme: %r" % scheme)
    host = parts.hostname
    if not host:
        raise ValueError("base_url has no host: %r" % base_url)
    port = parts.port or (443 if scheme == "https" else 80)

    if not path.startswith("/"):
        path = "/" + path
    full = parts.path.rstrip("/") + path

    if scheme == "https":
        conn: http.client.HTTPConnection = http.client.HTTPSConnection(
            host, port, timeout=timeout, context=ssl.create_default_context()
        )
    else:
        conn = http.client.HTTPConnection(host, port, timeout=timeout)

    t0 = time.monotonic()
    deadline = t0 + timeout
    try:
        # http.client does not chase 3xx redirects — this is the follow_location=0
        # SSRF posture. Targets come from config only.
        conn.request(
            "GET",
            full,
            headers={
                "Accept": "application/json, text/plain, */*",
                "User-Agent": "suitedash/1.0",
                "Connection": "close",
            },
        )
        # getresponse() reads the status line + headers. Those are bounded only by
        # the per-recv socket timeout (and http.client's _MAXLINE/_MAXHEADERS caps),
        # which a backend that DRIBBLES the header block one byte per <timeout window
        # defeats exactly as a body drip would — every recv lands inside the socket
        # timeout, so a single header can pin this probe-pool worker for ~_MAXLINE*
        # timeout (hours), and stacked polls then wedge the whole fixed pool. Bound
        # the header read by the SAME total wall-clock deadline as the body: a
        # watchdog closes the socket at the deadline, so a stalled getresponse() read
        # fails and the caller maps it to DOWN near `timeout`.
        watchdog = threading.Timer(
            max(0.0, deadline - time.monotonic()), _abort_connection, args=(conn,)
        )
        watchdog.daemon = True
        watchdog.start()
        try:
            resp = conn.getresponse()
        finally:
            watchdog.cancel()
        # The body — the large, backend-controlled surface — is read under the same
        # TOTAL wall-clock deadline so a slow drip cannot pin the probe worker.
        body = _read_capped(resp, conn, deadline)
        latency = (time.monotonic() - t0) * 1000.0
        return FetchResult(resp.status, resp.getheader("Content-Type", ""), body, latency)
    finally:
        try:
            conn.close()
        except Exception:  # pragma: no cover - defensive
            pass


def _read_capped(resp, conn, deadline: float) -> bytes:
    """Read up to :data:`MAX_BODY` bytes, enforcing a **total** wall-clock
    ``deadline`` (a :func:`time.monotonic` timestamp) across the whole body.

    The per-socket timeout only bounds a *single* ``recv``: a hostile backend
    that dribbles one byte just inside that window keeps a plain ``read`` alive
    indefinitely (every ``recv`` succeeds), pinning the probe worker far past the
    intended ``timeout`` — the abandoned-straggler design in :mod:`suitedash.poller`
    assumes the socket timeout reaps it "shortly after", which a drip defeats.

    Reading with :meth:`http.client.HTTPResponse.read1` (returns whatever is
    already buffered, at most one underlying read) and shrinking the socket
    timeout to the remaining budget before each read bounds the body to
    ``deadline`` — a straggler is reaped near ``timeout``, not days later. Raises
    :class:`TimeoutError` when the deadline passes; the caller already maps that
    (and ``socket.timeout``) to a DOWN result.
    """
    out = bytearray()
    sock = getattr(conn, "sock", None)
    while len(out) <= MAX_BODY:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("read deadline exceeded")
        # Cap this recv to the remaining budget so no single read can outlast the
        # deadline, even against an active byte-at-a-time dribble.
        if sock is not None:
            try:
                sock.settimeout(remaining)
            except OSError:  # pragma: no cover - socket already closed
                pass
        chunk = resp.read1(min(_READ_CHUNK, MAX_BODY + 1 - len(out)))
        if not chunk:
            break  # EOF — whole body read
        out += chunk
    return bytes(out[:MAX_BODY])


# --------------------------------------------------------------------------- #
# Metrics parsing
# --------------------------------------------------------------------------- #


def _to_number(text: str) -> Optional[float]:
    """Parse a Prometheus/JSON scalar to a *finite* float, else ``None``.

    Prometheus permits ``NaN``/``+Inf``/``-Inf``; we deliberately reject
    non-finite values so downstream JSON serialisation stays strictly valid.
    """
    if text is None:
        return None
    s = text.strip()
    if not s:
        return None
    try:
        v = float(s)
    except (TypeError, ValueError):
        return None
    return v if math.isfinite(v) else None


def parse_prometheus(text: str) -> Dict[str, float]:
    """Parse Prometheus text exposition ``name value`` lines.

    ``#`` comment lines (HELP/TYPE) and blanks are ignored.  A trailing
    timestamp is tolerated.  For a labelled series (``name{a="b"} 3``) the value
    is stored under both the full token and the bare base name (first series
    wins for the base name), so a config key like ``gitweb_requests_total``
    resolves whether or not it carries labels.
    """
    out: Dict[str, float] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 2:
            continue
        name, value = parts[0], parts[1]
        num = _to_number(value)
        if num is None:
            continue
        base = name.split("{", 1)[0]
        out.setdefault(base, num)
        if "{" in name:
            out[name] = num
    return out


def flatten_json(obj) -> Dict[str, float]:
    """Flatten a JSON object *one level* into numeric ``key -> float`` pairs.

    Top-level scalars are kept; a nested object contributes ``parent_child``
    keys for its numeric leaves.  Numeric strings are coerced.  Lists, ``null``
    and deeper nesting are ignored — a status card wants a handful of numbers.
    """
    out: Dict[str, float] = {}
    if not isinstance(obj, dict):
        return out
    for k, v in obj.items():
        key = str(k)
        if isinstance(v, bool):
            out[key] = float(v)
        elif isinstance(v, (int, float)):
            if math.isfinite(float(v)):
                out[key] = float(v)
        elif isinstance(v, str):
            num = _to_number(v)
            if num is not None:
                out[key] = num
        elif isinstance(v, dict):
            for k2, v2 in v.items():
                sub = "%s_%s" % (key, k2)
                if isinstance(v2, bool):
                    out[sub] = float(v2)
                elif isinstance(v2, (int, float)) and math.isfinite(float(v2)):
                    out[sub] = float(v2)
                elif isinstance(v2, str):
                    num = _to_number(v2)
                    if num is not None:
                        out[sub] = num
    return out


def parse_metrics(body: bytes, content_type: str = "") -> Dict[str, float]:
    """Parse a metrics body as JSON *or* Prometheus text, auto-detecting which.

    JSON is preferred when the content-type says so or the body opens with
    ``{``/``[``; otherwise Prometheus text is tried first.  Either way both
    strategies are attempted before giving up, so a mislabelled endpoint still
    parses.
    """
    text = (body or b"").decode("utf-8", "replace").strip()
    if not text:
        return {}
    ctype = (content_type or "").lower()
    looks_json = "json" in ctype or text[:1] in "{["

    if looks_json:
        try:
            return flatten_json(json.loads(text))
        except ValueError:
            pass

    prom = parse_prometheus(text)
    if prom:
        return prom

    # Last resort: it may have been unadvertised JSON.
    try:
        return flatten_json(json.loads(text))
    except ValueError:
        return {}


# --------------------------------------------------------------------------- #
# Service probe
# --------------------------------------------------------------------------- #


@dataclass
class ServiceResult:
    """The outcome of probing one service (one refresh)."""

    name: str
    base_url: str
    up: bool
    latency_ms: Optional[float] = None
    metrics: Dict[str, Optional[float]] = field(default_factory=dict)
    checked_at: float = field(default_factory=time.time)
    error: Optional[str] = None
    health_path: Optional[str] = None
    label: str = ""
    #: Raw (capped) upstream metrics text + its content-type, retained only so
    #: the aggregate ``/metrics`` exporter can re-emit relabelled series.  Never
    #: serialised into the ``/api/status`` JSON (see :meth:`to_json`).
    metrics_raw: str = ""
    metrics_ctype: str = ""

    @classmethod
    def down(cls, cfg: ServiceConfig, error: str) -> "ServiceResult":
        return cls(
            name=cfg.name,
            base_url=cfg.base_url,
            up=False,
            error=error,
            label=cfg.label,
        )

    def to_json(self) -> dict:
        return {
            "up": self.up,
            "latency_ms": self.latency_ms,
            "metrics": {k: _num_out(v) for k, v in self.metrics.items()},
            "checked_at": round(self.checked_at, 3),
            "error": self.error,
            "health_path": self.health_path,
        }


def _num_out(v: Optional[float]):
    """Render a metric for output: ``None``, an ``int`` when integral, else float."""
    if v is None:
        return None
    f = float(v)
    if f.is_integer():
        return int(f)
    return round(f, 6)


def _errstr(exc: BaseException) -> str:
    msg = str(exc).strip()
    return msg or exc.__class__.__name__


def _probe_health(
    cfg: ServiceConfig, timeout: float
) -> Tuple[bool, Optional[float], Optional[str], Optional[str]]:
    """Return ``(up, latency_ms, health_path, error)`` within one ``timeout`` budget."""
    candidates: List[str] = []
    for p in (cfg.health_path, *HEALTH_FALLBACKS):
        if p and p not in candidates:
            candidates.append(p)

    deadline = time.monotonic() + timeout
    last_err: Optional[str] = None
    for path in candidates:
        remaining = deadline - time.monotonic()
        if remaining <= 0.05:
            break
        try:
            fr = fetch(cfg.base_url, path, min(timeout, remaining))
        except ConnectionRefusedError:
            return False, None, None, "connection refused"
        except (socket.timeout, TimeoutError):
            return False, None, None, "timeout"
        except (ConnectionResetError, BrokenPipeError) as exc:
            last_err = _errstr(exc)
            continue
        except http.client.HTTPException as exc:
            # Malformed/short response (e.g. a header read reaped at the deadline
            # yields BadStatusLine). Not an OSError, so guard it explicitly to keep
            # probe_service's "never raises" contract; try the next path.
            last_err = _errstr(exc)
            continue
        except OSError as exc:
            # host unreachable / DNS / etc — not going to get better per-path.
            return False, None, None, _errstr(exc)
        except ValueError as exc:
            return False, None, None, _errstr(exc)
        if 200 <= fr.status < 300:
            return True, round(fr.latency_ms, 2), path, None
        last_err = "http %d" % fr.status
    return False, None, None, last_err


def _surface(
    metrics: Dict[str, float], keys: Tuple[str, ...]
) -> Dict[str, Optional[float]]:
    """Select the numbers to show: the configured ``keys`` (``None`` if absent),
    or, when none are configured, the first :data:`AUTO_LIMIT` sorted by name."""
    out: Dict[str, Optional[float]] = {}
    if keys:
        for k in keys:
            out[k] = metrics.get(k)
    else:
        for k in sorted(metrics)[:AUTO_LIMIT]:
            out[k] = metrics[k]
    return out


def probe_service(cfg: ServiceConfig, timeout: float) -> ServiceResult:
    """Probe one service: liveness (tolerant) then metrics (tolerant).

    Never raises — any transport failure becomes a DOWN result.  Bounded by
    ``timeout`` for liveness and a second ``timeout`` for the metrics fetch, so
    the worst case for one service is ~``2*timeout`` even if it is a black hole;
    the poller additionally caps this from the outside.
    """
    up, latency_ms, health_path, err = _probe_health(cfg, timeout)

    metrics: Dict[str, float] = {}
    metrics_raw = ""
    metrics_ctype = ""
    if up:
        try:
            fr = fetch(cfg.base_url, cfg.metrics_path, timeout)
            if 200 <= fr.status < 300:
                metrics_ctype = fr.content_type
                # Retain the raw text (capped) for the federation exporter. Cap
                # by BYTES (not characters) so retained memory truly matches
                # MAX_FEDERATE_BODY; a multi-byte sequence split at the cut is
                # handled by the "replace" error mode.
                metrics_raw = (fr.body or b"")[:MAX_FEDERATE_BODY].decode(
                    "utf-8", "replace"
                )
                metrics = parse_metrics(fr.body, fr.content_type)
        except Exception:
            metrics = {}  # up, but metrics unavailable — card still renders UP

    return ServiceResult(
        name=cfg.name,
        base_url=cfg.base_url,
        up=up,
        latency_ms=latency_ms,
        metrics=_surface(metrics, cfg.metrics_keys),
        checked_at=time.time(),
        error=err,
        health_path=health_path,
        label=cfg.label,
        metrics_raw=metrics_raw,
        metrics_ctype=metrics_ctype,
    )
