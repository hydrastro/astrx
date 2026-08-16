//! `suitedash` — a zero-dependency ops/status dashboard for the astrx-suite.
//!
//! One no-JavaScript page shows, per suite service, an UP/DOWN badge, response
//! latency and a few key numbers pulled from its `/metrics` (or JSON stats)
//! endpoint, plus a machine-readable `/api/status` JSON view and an aggregate
//! Prometheus `/metrics` exposition federating every polled service.
//!
//! The poller is deliberately **tolerant**: suite services are inconsistent
//! (health lives at `/health` vs `/healthz` vs nowhere; metrics arrive as
//! Prometheus text on one service and JSON on another). Each probe tries the
//! configured health path then known fallbacks, parses metrics as *both*
//! Prometheus text and JSON, and is bounded by a short per-service timeout — so
//! a hung service renders as DOWN without ever blocking the page.
//!
//! A port of the Python `suitedash`. The pure tiers below — [`config`]
//! (defaults + the read-only TOML loader), [`metrics`] (the tolerant parsers and
//! the [`ServiceResult`] every other module consumes), [`history`] (bounded
//! rings + the hand-emitted inline-SVG sparklines), [`alerts`] (the debounced
//! threshold/down engine), [`render`] (the HTML page and the `/api/status` JSON)
//! and [`exporter`] (the aggregate Prometheus exposition) — compile with **zero
//! third-party dependencies**; the concurrent poller and the HTTP server live
//! behind the opt-in `net` feature.
//!
//! Every pure function is cross-checked **byte-identical** to the Python
//! reference: `tests/regen_goldens.py` drives the real `suitedash` package and
//! emits the literals embedded in `tests/xcheck_*.rs`, so a rendered page, a
//! federated exposition, an SVG sparkline, a JSON payload, an alert transition
//! and a parsed config all match the retiring engine to the byte. The handful of
//! places where CPython behaviour cannot be reproduced exactly (and why they are
//! unreachable in practice) are documented in the module that owns them.
//!
//! Because the pure tiers must be a function of their inputs, the two places
//! where Python reads the wall clock inline — [`render::render_page`] /
//! [`render::render_status_json`] and the alert engine's injected `clock` — take
//! the timestamp as an explicit argument here.

#![forbid(unsafe_code)]
#![warn(missing_docs)]

pub mod alerts;
pub mod config;
pub mod exporter;
pub mod history;
pub mod metrics;
pub mod monitor;
pub mod probe;
pub mod render;
pub mod server;

#[cfg(feature = "net")]
pub mod poller;

mod pycompat;

pub use alerts::{AlertEngine, AlertEvent, AlertState, AlertView};
pub use config::{
    apply_service_flags, default_services, load_config, parse_config, AlertRule, Config,
    ConfigError, ServiceConfig, ALLOWED_OPS, MAX_RULES,
};
pub use exporter::{render_federated_metrics, CONTENT_TYPE, MAX_FEDERATE_LINES};
pub use history::{
    sparkline_svg, History, Ring, MAX_SERIES_PER_SERVICE, SPARK_HEIGHT, SPARK_WIDTH,
};
pub use metrics::{
    flatten_json, num_out, parse_metrics, parse_prometheus, summarize, surface, MetricMap, NumOut,
    OrderedMap, Results, ServiceResult, Summary, SurfacedMetrics, AUTO_LIMIT, MAX_METRIC_NAME,
};
pub use monitor::Monitor;
pub use probe::{health_candidates, HEALTH_FALLBACKS, MAX_BODY, MAX_FEDERATE_BODY};
pub use render::{esc, render_page, render_status_json, Snapshot};
pub use server::{Dashboard, Resp, Route, CSP, MAX_REQUEST_HEAD, SERVER_NAME};

#[cfg(feature = "net")]
pub use poller::{default_workers, poll_all, POLL_SLACK};
#[cfg(feature = "net")]
pub use probe::{fetch, probe_service, FetchResult, ProbeError, USER_AGENT};
#[cfg(feature = "net")]
pub use server::{serve, serve_config, HEAD_READ_TIMEOUT, RESPONSE_WRITE_TIMEOUT};
