//! Poll every configured service concurrently, bounded so the page never hangs —
//! a port of the Python `suitedash.poller`.
//!
//! Each service is probed by its own task. Results are gathered against a single
//! wall-clock deadline of roughly `timeout` (probes run in parallel, so the whole
//! sweep costs about one timeout, not the sum). A service that blows the deadline
//! — a black hole that accepts the connection then never answers — is reported
//! DOWN and its straggler is *abandoned*: dropping the [`JoinSet`] aborts it, and
//! we never wait on it. That is what keeps the dashboard responsive.
//!
//! Concurrency is additionally bounded by a permit pool ([`tokio::sync::Semaphore`]),
//! the analogue of Python's `ThreadPoolExecutor(max_workers=…)`, so a large
//! service list cannot open unbounded sockets at once.
//!
//! The sweep roll-up Python calls `poller.summarize` lives with the rest of the
//! pure result core, in [`crate::metrics::summarize`].

use crate::config::ServiceConfig;
use crate::metrics::{Results, ServiceResult};
use crate::probe::probe_service;
use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tokio::sync::Semaphore;
use tokio::task::JoinSet;
use tokio::time::{timeout_at, Instant};

/// Slack added to the gather deadline over the per-service timeout, so a probe
/// that legitimately finishes right at the timeout is still counted (Python
/// `POLL_SLACK`).
pub const POLL_SLACK: Duration = Duration::from_millis(500);

/// Hard ceiling on the in-flight probe bound, so an absurd configured value can
/// neither exhaust the permit pool's own limit nor pretend to be meaningful — a
/// sweep with this many probes in flight is unbounded in every practical sense.
pub const MAX_WORKERS: usize = 4096;

/// The default in-flight probe bound for `service_count` services — Python's
/// transient `ThreadPoolExecutor(max_workers=max(4, len(services) * 2))`.
///
/// Always at least the service count, so the bound never serialises a sweep (a
/// queued probe would otherwise be reported DOWN when the gather deadline
/// passes, exactly as it would in the reference).
#[must_use]
pub fn default_workers(service_count: usize) -> usize {
    service_count.saturating_mul(2).max(4)
}

/// Epoch seconds (Python `time.time()`), for the timed-out stragglers' stamp.
fn now_secs() -> f64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_or(0.0, |d| d.as_secs_f64())
}

/// Probe all `services` concurrently and return results **in input order**.
///
/// `max_workers` bounds how many probes may be in flight at once; `0` selects
/// [`default_workers`] and anything above [`MAX_WORKERS`] is clamped to it. Total
/// wall time is bounded by `timeout + POLL_SLACK` regardless of how many services
/// hang: a service that misses the deadline gets a DOWN result with the error
/// `timeout` and its task is aborted rather than awaited.
pub async fn poll_all(
    services: &[ServiceConfig],
    timeout: Duration,
    max_workers: usize,
) -> Results {
    let deadline = Instant::now() + timeout + POLL_SLACK;
    let permits = if max_workers == 0 {
        default_workers(services.len())
    } else {
        max_workers
    }
    .clamp(1, MAX_WORKERS);
    let slots = Arc::new(Semaphore::new(permits));

    let mut set: JoinSet<(usize, ServiceResult)> = JoinSet::new();
    for (i, cfg) in services.iter().enumerate() {
        let cfg = cfg.clone();
        let slots = Arc::clone(&slots);
        set.spawn(async move {
            // The pool bound. The semaphore is never closed, so acquiring only
            // fails while the runtime is tearing down — in which case the probe
            // runs unbounded and is aborted with the set anyway.
            let _permit = slots.acquire_owned().await;
            (i, probe_service(&cfg, timeout).await)
        });
    }

    let mut done: Vec<Option<ServiceResult>> = services.iter().map(|_| None).collect();
    loop {
        match timeout_at(deadline, set.join_next()).await {
            Ok(Some(Ok((i, r)))) => {
                if let Some(slot) = done.get_mut(i) {
                    *slot = Some(r);
                }
            }
            // A panicking probe leaves its slot empty; it renders DOWN below.
            Ok(Some(Err(_))) => {}
            // Every probe joined, or the deadline passed — in which case the
            // stragglers are abandoned (dropping the set aborts them).
            Ok(None) | Err(_) => break,
        }
    }
    drop(set);

    let now = now_secs();
    let mut results = Results::new();
    for (cfg, slot) in services.iter().zip(done.iter_mut()) {
        let result = slot
            .take()
            .unwrap_or_else(|| ServiceResult::down(cfg, "timeout", now));
        results.insert(cfg.name.clone(), result);
    }
    results
}
