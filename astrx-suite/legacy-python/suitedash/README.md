# suitedash

A **zero-dependency, no-JavaScript ops/status dashboard** for the astrx-suite.
Standard-library Python 3.11 only. One server-rendered page shows, per suite
service, an **UP/DOWN** badge, **response latency**, and **a few key numbers**
pulled from its metrics — plus a machine-readable `GET /api/status` JSON view.
It binds `127.0.0.1` by default and is designed to run behind Tor or a reverse
proxy.

```
┌ astrx-suite status ────────────── All systems operational · 4/4 up ┐
│  gitweb            [UP]     onioncrawler     [UP]                   │
│  latency 3 ms               latency 6 ms                           │
│  requests_total  1,204      pages          8,102                    │
│  uptime_seconds  512.4      hosts            377                   │
│                                                                    │
│  websearch         [UP]     torrentds       [DOWN]                 │
│  latency 4 ms               connection refused                     │
│  docs            9,812                                              │
└────────────────────────────────────────────────────────────────────┘
```

## Why it exists

The suite services are intentionally **inconsistent**: health lives at `/health`
on one, `/healthz` on another, and nowhere obvious on a third; metrics are
Prometheus text on some and JSON on others. suitedash is deliberately *tolerant*
so one page can watch all of them:

- **Liveness** — it probes the configured `health_path`, then falls back through
  `/health`, `/healthz`, `/livez`, `/stats`, `/api/stats`, `/`. **Any 2xx = UP.**
  A refused connection or a timeout is a fast DOWN.
- **Metrics** — it fetches `metrics_path` and parses it as **Prometheus text**
  (`name value` lines, `#` HELP/TYPE comments ignored) **or JSON** (flattened one
  level), auto-detecting which. Non-finite values (NaN/Inf) are dropped so the
  JSON API stays strictly valid.
- **Bounded** — every service has a short per-service timeout (default 3 s) and
  all services are probed concurrently, so a hung service renders as DOWN and
  **the page never hangs**.

## Endpoints consumed (defaults)

| Service        | Port | Health probed | Metrics probed  | Metrics format   |
| -------------- | ---- | ------------- | --------------- | ---------------- |
| `gitweb`       | 8801 | `/health`     | `/metrics`      | Prometheus text  |
| `onioncrawler` | 8802 | `/healthz`    | `/metrics`      | Prometheus text  |
| `websearch`    | 8803 | `/stats`      | `/metrics`      | text `name value`|
| `torrentds`    | 8804 | `/health`     | `/api/stats`    | JSON             |

The tolerant fallback chain means these defaults keep working even when a
service's real health path differs from the one configured (e.g. websearch's
real liveness is `/healthz`, reached by fallback).

## Endpoints served

| Path           | Response                                                        |
| -------------- | -------------------------------------------------------------- |
| `GET /`        | The no-JS HTML status page (auto-refreshing via `<meta refresh>`), incl. an alerts panel and per-metric inline-SVG sparklines. |
| `GET /api/status` | `{"summary": {...}, "services": {name: {up, latency_ms, metrics{...}, checked_at, error, health_path}}, "alerts": {rules, firing, states[...], recent[...]}}` |
| `GET /metrics` | Aggregate Prometheus exposition federating every polled service's metrics (each series relabelled `service="…"`), plus suitedash's own `suitedash_up` / `suitedash_service_up` / `suitedash_service_scrape_duration_seconds` gauges. |
| `GET /healthz` | The dashboard's own liveness (`ok`).                            |

### Alerting, history & the aggregate exporter

- **Alerting.** Define `[[alert]]` rules (see the example config): a `metric` rule
  fires when `metric <op> threshold` holds for `for` consecutive poll sweeps
  (debounced) and clears on recovery; a `down` rule fires when a service is DOWN.
  `service = "*"` applies a rule to every service. Per-`(service, rule)` state —
  firing/ok, since-when, last value — drives the alerts panel (firing first) and
  the `/api/status` `alerts` block. Rule count and the transition log are bounded.
- **History + sparklines.** A bounded in-memory ring buffer per `(service, metric)`
  feeds a tiny hand-emitted inline-`<svg>` sparkline on each card — no JavaScript,
  no external library. Non-finite / huge values are clamped so the SVG can never
  be malformed. **History is in-memory only and resets on restart** (this is a
  live status view, not a time-series database).
- **Aggregate `/metrics`.** Point Prometheus at suitedash alone to scrape the whole
  suite. Upstream bodies are parsed defensively — garbled lines are skipped, a
  hostile body can't break the exposition — and the added `service` label is
  escaped per the Prometheus text format.

> suitedash polls **on request**, so a "poll sweep" is one real probe of the
> service list; alert debounce and history sampling advance per sweep (a scrape of
> `/metrics` also drives one). Set `cache_ttl > 0` to coalesce bursts.

## Run

No install needed — it is stdlib only:

```bash
# From this directory, with defaults (polls 127.0.0.1:8801-8804, serves :8805)
python3 -m suitedash                       # http://127.0.0.1:8805/

# Or after `pip install .`, via the console script:
suitedash --port 8805 --refresh 15 --timeout 3

# Retarget a service inline (repeatable), no config file needed:
suitedash --service gitweb=http://10.0.0.5:8801

# One-shot: poll once, print /api/status JSON, exit non-zero if anything is down
suitedash --check
```

### Configuration

Everything has defaults; override with a small TOML file and/or flags. See
[`suitedash.example.toml`](suitedash.example.toml):

```toml
port = 8805
refresh_seconds = 15
timeout_seconds = 3.0

[[service]]
name = "gitweb"
base_url = "http://127.0.0.1:8801"
health_path = "/health"
metrics_path = "/metrics"
metrics_keys = ["gitweb_requests_total", "gitweb_uptime_seconds"]
label = "Read-only git web viewer"
```

```bash
suitedash --config /etc/suitedash/suitedash.toml
```

CLI flags (`--host`, `--port`, `--refresh`, `--timeout`, `--max-workers`,
`--cache-ttl`, `--service NAME=URL`) override file/default values. A `[[service]]`
array in the file replaces the built-in service list entirely.

## Security posture

- **Binds `127.0.0.1`** by default (loopback only).
- **SSRF-safe fetch**, mirroring the AstrX PHP bridge: targets come from config
  only, the fetcher **never follows redirects** (`follow_location = 0`), uses a
  short connect+read timeout, restricts the scheme to http/https, and caps the
  response body.
- **Strict CSP** on every response: `default-src 'none'; style-src 'unsafe-inline';
  base-uri 'none'; form-action 'none'; frame-ancestors 'none'`, plus `nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`. No JavaScript, no
  external resources, no cookies.
- **All output escaped** with `html.escape` — a hostile service name or metric
  value cannot inject markup.
- **Bounded worker pool** guards against Slowloris-style thread exhaustion.

## Tor hidden-service deployment

The dashboard is plain HTTP on loopback, exactly what a Tor onion service
expects to reverse-proxy. Keep it bound to `127.0.0.1` so it is reachable *only*
through Tor.

1. Add an onion service to `torrc`:

   ```
   HiddenServiceDir /var/lib/tor/astrx_suitedash/
   HiddenServicePort 80 127.0.0.1:8805
   ```

2. Run suitedash on loopback (systemd unit provided in
   [`packaging/suitedash.service`](packaging/suitedash.service)):

   ```bash
   suitedash --config /etc/suitedash/suitedash.toml --host 127.0.0.1 --port 8805 --quiet
   ```

3. Browse the `.onion` from `HiddenServiceDir/hostname` in Tor Browser. The page
   needs **no JavaScript**, so it works at the most restrictive setting.

### Docker

```bash
docker build -t suitedash .
docker run --rm -p 127.0.0.1:8805:8805 \
  -v /etc/suitedash/suitedash.toml:/etc/suitedash/suitedash.toml:ro \
  suitedash --config /etc/suitedash/suitedash.toml --host 0.0.0.0
```

## Tests

Fully offline — the suite stands up its own mock services on loopback (one
Prometheus-text service, one JSON service, one that refuses connections, one
that sleeps past the timeout):

```bash
python3 -m unittest discover -s tests -t . -v
```

They assert correct UP/DOWN per service, that both the Prometheus and JSON
parsers surface the right numbers, that the page renders within a bound even
with a slow service, that everything is escaped, and that `/api/status` matches
the page.

## Layout

| File                         | Role                                                     |
| ---------------------------- | -------------------------------------------------------- |
| `suitedash/config.py`        | Service list + settings; TOML loader; CLI-flag overrides.|
| `suitedash/probe.py`         | SSRF-safe fetch, tolerant health probe, Prometheus+JSON parsers. |
| `suitedash/poller.py`        | Concurrent, hard-bounded poll of all services.           |
| `suitedash/alerts.py`        | Debounced threshold + down-detection rule engine (stateful). |
| `suitedash/history.py`       | Bounded per-metric ring buffers + inline-SVG sparklines. |
| `suitedash/monitor.py`       | Thread-safe glue: ingests each sweep, snapshots for render. |
| `suitedash/exporter.py`      | Aggregate `/metrics` Prometheus federation (defensive).  |
| `suitedash/render.py`        | No-JS HTML page (all escaped) + `/api/status` JSON.      |
| `suitedash/server.py`        | Bounded `http.server` with strict CSP; routes.           |
| `suitedash/cli.py`           | `suitedash` console entry point (`--config`, `--check`, …).|

## Limitations

- **History is in-memory only** and **resets on restart** — sparklines show a
  bounded recent window, not durable time-series. For long-term storage and
  notification, scrape the aggregate `GET /metrics` with Prometheus/Alertmanager;
  suitedash's own alerting is a lightweight, in-process convenience.
- **Poll-on-request** by default: each page load (or `/metrics` scrape) re-polls
  and advances alert/history state. Set `cache_ttl > 0` to serve a cached snapshot
  under bursty load (cache hits do not advance alert debounce counters).
- **Rules see surfaced metrics only** — a metric rule can reference any key a
  service surfaces via its `metrics_keys` (or the auto-picked few).
- **No SOCKS/Tor egress** for *polling* — it fetches services directly over the
  local network. The Tor section covers *serving* the dashboard as an onion.
- **Labelled Prometheus series** collapse to the first-seen value under their
  base metric name; surface the full `name{labels}` token if you need a
  specific series.
```
