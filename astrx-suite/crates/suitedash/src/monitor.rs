//! Thread-safe glue holding the alert engine + history across poll sweeps — a
//! port of the Python `suitedash.monitor`.
//!
//! [`crate::server::Dashboard`] owns one [`Monitor`]. Every **real** poll sweep
//! (never a cache hit) calls [`Monitor::ingest`], which — under a single lock —
//! records history and advances alert state atomically. Renderers call
//! [`Monitor::snapshot`] to get an immutable, copied view they can walk without
//! holding the lock; that view is the pure [`Snapshot`] the renderers already
//! consume, so Python's `MonitorSnapshot` has no separate type here.
//!
//! Because suitedash polls on request, "one poll sweep" is one real probe of the
//! service list; alert debounce (`for_polls`) and history sampling advance per
//! sweep, not on a wall-clock timer. A Prometheus scrape of `/metrics` also
//! drives a sweep, giving a steady cadence when one is configured.
//!
//! Only [`std::sync::Mutex`] is used, so this module — like the renderers it
//! feeds — compiles with zero third-party dependencies; it is the shared-state
//! owner of the serving tier, the same role `websearch`'s `SearchServer` plays.

use crate::alerts::AlertEngine;
use crate::config::Config;
use crate::history::History;
use crate::metrics::Results;
use crate::render::Snapshot;
use std::sync::Mutex;

/// The lock-guarded state: the alert engine and the history rings.
struct Inner {
    engine: AlertEngine,
    history: History,
}

/// Stateful, lock-guarded owner of the alert engine and history buffers.
pub struct Monitor {
    inner: Mutex<Inner>,
    rules_total: usize,
}

impl Monitor {
    /// A monitor over `config`'s alert rules and history bounds.
    #[must_use]
    pub fn new(config: &Config) -> Self {
        Monitor {
            inner: Mutex::new(Inner {
                engine: AlertEngine::new(&config.alert_rules, config.alert_history),
                history: History::new(config.history_capacity, config.history_max_series),
            }),
            rules_total: config.alert_rules.len(),
        }
    }

    /// Record history and advance alerts for one poll sweep, atomically.
    ///
    /// `now` is the wall clock a firing/clearing transition is stamped with —
    /// Python reads `time.time()` inside the engine; here the clock is explicit,
    /// keeping the engine itself pure.
    pub fn ingest(&self, results: &Results, now: f64) {
        let mut inner = self.inner.lock().expect("monitor mutex");
        inner.history.record(results);
        inner.engine.update(results, now);
    }

    /// A copied, lock-free view of the current alert + history state.
    #[must_use]
    pub fn snapshot(&self) -> Snapshot {
        let inner = self.inner.lock().expect("monitor mutex");
        Snapshot::new(
            inner.engine.views(),
            inner.history.all_series(),
            inner.engine.events(),
            self.rules_total,
        )
    }

    /// How many alert rules are configured.
    #[must_use]
    pub fn rules_total(&self) -> usize {
        self.rules_total
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::AlertRule;
    use crate::metrics::{ServiceResult, SurfacedMetrics};

    fn results(up: bool, value: f64) -> Results {
        let mut r = ServiceResult::new("alpha", "http://x", up);
        let mut m = SurfacedMetrics::new();
        m.insert("q", Some(value));
        r.metrics = m;
        let mut out = Results::new();
        out.insert("alpha", r);
        out
    }

    fn config() -> Config {
        Config {
            alert_rules: vec![
                AlertRule {
                    id: "busy".to_string(),
                    service: "alpha".to_string(),
                    metric: "q".to_string(),
                    op: ">".to_string(),
                    threshold: 10.0,
                    for_polls: 2,
                    ..AlertRule::default()
                },
                AlertRule {
                    id: "down".to_string(),
                    service: "*".to_string(),
                    kind: "down".to_string(),
                    for_polls: 1,
                    severity: "critical".to_string(),
                    ..AlertRule::default()
                },
            ],
            ..Config::default()
        }
    }

    #[test]
    fn ingest_advances_alerts_and_history_together() {
        let m = Monitor::new(&config());
        assert_eq!(m.rules_total(), 2);
        // Nothing ingested yet: no states, no series.
        let empty = m.snapshot();
        assert!(empty.alerts.is_empty());
        assert!(empty.series.is_empty());

        m.ingest(&results(true, 50.0), 1000.0);
        let after_one = m.snapshot();
        assert_eq!(after_one.rules_total, 2);
        assert_eq!(after_one.firing_count, 0); // debounced: needs two sweeps
        assert_eq!(after_one.series_for("alpha").get("q"), Some(&vec![50.0]));

        m.ingest(&results(true, 50.0), 1001.0);
        let after_two = m.snapshot();
        assert_eq!(after_two.firing_count, 1);
        assert_eq!(
            after_two.series_for("alpha").get("q"),
            Some(&vec![50.0, 50.0])
        );
        assert_eq!(after_two.events.len(), 1);
        assert_eq!(after_two.events[0].status, "firing");
    }

    #[test]
    fn a_down_service_fires_the_down_rule_and_records_no_history() {
        let m = Monitor::new(&config());
        m.ingest(&results(false, 50.0), 2000.0);
        let snap = m.snapshot();
        let down = snap
            .alerts
            .iter()
            .find(|a| a.rule_id == "down")
            .expect("the down rule has a state");
        assert!(down.firing);
        assert_eq!(down.since, 2000.0);
        assert!(snap.series_for("alpha").is_empty());
    }

    #[test]
    fn snapshots_are_independent_copies() {
        let m = Monitor::new(&config());
        m.ingest(&results(true, 1.0), 3000.0);
        let first = m.snapshot();
        m.ingest(&results(true, 2.0), 3001.0);
        assert_eq!(first.series_for("alpha").get("q"), Some(&vec![1.0]));
        assert_eq!(
            m.snapshot().series_for("alpha").get("q"),
            Some(&vec![1.0, 2.0])
        );
    }
}
