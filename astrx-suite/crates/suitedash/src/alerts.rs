//! Threshold + down-detection alerting evaluated once per poll sweep — a port of
//! the Python `suitedash.alerts`.
//!
//! The engine is a small, *stateful* object: it is handed each poll's results and
//! advances a per-`(service, rule)` [`AlertState`] — a breach *streak*, a
//! firing/ok flag, when the current status began, and the last observed value. A
//! metric rule fires only after its condition holds for `for_polls` consecutive
//! sweeps (debounced), and clears the moment the condition no longer holds. A
//! `down` rule fires when a service's last probe was DOWN.
//!
//! Everything is bounded: the rule list is capped by the config loader, the
//! number of live states is naturally bounded by `rules × services` (states for
//! services no longer polled are pruned each sweep), and the transition event log
//! is a fixed-length ring.
//!
//! The engine never panics on a hostile value — a bad comparison degrades to
//! "not breaching". Where Python injects a `clock` callable, [`AlertEngine::update`]
//! takes the timestamp as an argument, keeping the whole module pure and its
//! goldens deterministic. Cross-checked byte-identical to Python by
//! `tests/xcheck_alerts.rs`.

use crate::config::AlertRule;
use crate::metrics::Results;
use std::collections::{HashMap, HashSet, VecDeque};

/// Sort weight for the well-known severities; anything else sorts last.
fn severity_order(severity: &str) -> u8 {
    match severity {
        "critical" => 0,
        "warning" => 1,
        "info" => 2,
        _ => 3,
    }
}

/// Mutable per-`(service, rule)` state carried across poll sweeps.
#[derive(Clone, Debug, PartialEq)]
pub struct AlertState {
    /// The rule this state belongs to.
    pub rule_id: String,
    /// The service this state belongs to.
    pub service: String,
    /// Consecutive breaching sweeps observed so far.
    pub streak: i64,
    /// Whether the rule is currently firing.
    pub firing: bool,
    /// When the current status began (epoch seconds).
    pub since: f64,
    /// The last observed value (`None` for a `down` rule or an absent metric).
    pub last_value: Option<f64>,
    /// How many times this rule has fired.
    pub total_fires: u64,
}

/// An immutable snapshot row for rendering / JSON (firing-first ordered).
#[derive(Clone, Debug, PartialEq)]
pub struct AlertView {
    /// Service the row is about.
    pub service: String,
    /// Rule id.
    pub rule_id: String,
    /// `"metric"` or `"down"`.
    pub kind: String,
    /// The rule's severity.
    pub severity: String,
    /// The rule's description.
    pub description: String,
    /// The watched metric (empty for a `down` rule).
    pub metric: String,
    /// The comparison operator.
    pub op: String,
    /// The threshold compared against.
    pub threshold: f64,
    /// The debounce window.
    pub for_polls: i64,
    /// Whether the rule is firing.
    pub firing: bool,
    /// `"firing"` or `"ok"`.
    pub status: String,
    /// When the current status began (epoch seconds).
    pub since: f64,
    /// The last observed value.
    pub last_value: Option<f64>,
    /// The current breach streak.
    pub streak: i64,
}

/// A single firing/clear transition, retained in a bounded log.
#[derive(Clone, Debug, PartialEq)]
pub struct AlertEvent {
    /// When the transition happened (epoch seconds).
    pub at: f64,
    /// The service.
    pub service: String,
    /// The rule id.
    pub rule_id: String,
    /// `"firing"` or `"ok"`.
    pub status: String,
    /// The value observed at the transition.
    pub value: Option<f64>,
}

/// Evaluate `rule` against one result: `(breaching, observed_value)`.
///
/// Never fails: an unknown operator or an un-comparable value is "not
/// breaching". A metric rule against a DOWN service (metrics unknown) is not
/// breaching — the separate `down` rule is what catches that.
fn eval(rule: &AlertRule, result: &crate::metrics::ServiceResult) -> (bool, Option<f64>) {
    if rule.kind == "down" {
        return (!result.up, None);
    }
    if !result.up {
        return (false, None);
    }
    let Some(v) = result.metrics.get(&rule.metric) else {
        return (false, None);
    };
    let Some(fv) = *v else {
        return (false, None);
    };
    if !fv.is_finite() {
        return (false, None);
    }
    let breaching = match rule.op.as_str() {
        ">" => fv > rule.threshold,
        ">=" => fv >= rule.threshold,
        "<" => fv < rule.threshold,
        "<=" => fv <= rule.threshold,
        "==" => fv == rule.threshold,
        "!=" => fv != rule.threshold,
        _ => return (false, Some(fv)), // unknown operator: never breaching
    };
    (breaching, Some(fv))
}

/// Stateful rule evaluator. Feed it results with [`AlertEngine::update`] per
/// sweep.
#[derive(Clone, Debug)]
pub struct AlertEngine {
    /// The configured rules, in file order.
    pub rules: Vec<AlertRule>,
    states: HashMap<(String, String), AlertState>,
    events: VecDeque<AlertEvent>,
    event_cap: usize,
}

impl AlertEngine {
    /// An engine over `rules`, retaining at most `alert_history` transitions.
    #[must_use]
    pub fn new(rules: &[AlertRule], alert_history: i64) -> Self {
        AlertEngine {
            rules: rules.to_vec(),
            states: HashMap::new(),
            events: VecDeque::new(),
            event_cap: alert_history.max(1) as usize,
        }
    }

    /// The services a rule applies to this sweep (`"*"`/`""` = all of them).
    fn targets(rule: &AlertRule, results: &Results) -> Vec<String> {
        if rule.service == "*" || rule.service.is_empty() {
            results.keys().map(str::to_string).collect()
        } else {
            vec![rule.service.clone()]
        }
    }

    /// Advance every rule's state against this sweep's `results`, stamping any
    /// transition with `now`.
    pub fn update(&mut self, results: &Results, now: f64) {
        let mut seen: HashSet<(String, String)> = HashSet::new();
        for rule in &self.rules {
            for svc in Self::targets(rule, results) {
                let Some(r) = results.get(&svc) else {
                    continue; // rule references a service we do not poll
                };
                let key = (svc.clone(), rule.id.clone());
                seen.insert(key.clone());
                let st = self.states.entry(key).or_insert_with(|| AlertState {
                    rule_id: rule.id.clone(),
                    service: svc.clone(),
                    streak: 0,
                    firing: false,
                    since: now,
                    last_value: None,
                    total_fires: 0,
                });
                let (breaching, value) = eval(rule, r);
                st.last_value = value;
                st.streak = if breaching { st.streak + 1 } else { 0 };
                let firing = st.streak >= rule.for_polls.max(1);
                if firing != st.firing {
                    st.firing = firing;
                    st.since = now;
                    if firing {
                        st.total_fires += 1;
                    }
                    if self.events.len() == self.event_cap {
                        self.events.pop_front();
                    }
                    self.events.push_back(AlertEvent {
                        at: now,
                        service: svc.clone(),
                        rule_id: rule.id.clone(),
                        status: if firing { "firing" } else { "ok" }.to_string(),
                        value,
                    });
                }
            }
        }
        // Prune states whose (service, rule) was not targeted this sweep so the
        // state map can never outgrow rules x currently-polled services.
        self.states.retain(|k, _| seen.contains(k));
    }

    /// Current alert rows, firing first then by severity/service/rule.
    #[must_use]
    pub fn views(&self) -> Vec<AlertView> {
        let by_id: HashMap<&str, &AlertRule> =
            self.rules.iter().map(|r| (r.id.as_str(), r)).collect();
        let mut out: Vec<AlertView> = Vec::with_capacity(self.states.len());
        for ((svc, rid), st) in &self.states {
            let Some(rule) = by_id.get(rid.as_str()) else {
                continue; // rules are stable per engine
            };
            out.push(AlertView {
                service: svc.clone(),
                rule_id: rid.clone(),
                kind: rule.kind.clone(),
                severity: rule.severity.clone(),
                description: rule.description.clone(),
                metric: rule.metric.clone(),
                op: rule.op.clone(),
                threshold: rule.threshold,
                for_polls: rule.for_polls,
                firing: st.firing,
                status: if st.firing { "firing" } else { "ok" }.to_string(),
                since: st.since,
                last_value: st.last_value,
                streak: st.streak,
            });
        }
        out.sort_by(|a, b| {
            (
                !a.firing,
                severity_order(&a.severity),
                &a.service,
                &a.rule_id,
            )
                .cmp(&(
                    !b.firing,
                    severity_order(&b.severity),
                    &b.service,
                    &b.rule_id,
                ))
        });
        out
    }

    /// The bounded transition log, oldest first.
    #[must_use]
    pub fn events(&self) -> Vec<AlertEvent> {
        self.events.iter().cloned().collect()
    }

    /// The per-`(service, rule)` state for one pair, if it is live.
    #[must_use]
    pub fn state(&self, service: &str, rule_id: &str) -> Option<&AlertState> {
        self.states.get(&(service.to_string(), rule_id.to_string()))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::metrics::{ServiceResult, SurfacedMetrics};

    /// `(name, up, [(metric, value)])` specs for a synthetic sweep.
    type Spec<'a> = (&'a str, bool, &'a [(&'a str, f64)]);

    fn results(specs: &[Spec<'_>]) -> Results {
        let mut out = Results::new();
        for (name, up, metrics) in specs {
            let mut r = ServiceResult::new(*name, "http://x", *up);
            let mut m = SurfacedMetrics::new();
            for (k, v) in *metrics {
                m.insert(*k, Some(*v));
            }
            r.metrics = m;
            out.insert(*name, r);
        }
        out
    }

    fn metric_rule() -> AlertRule {
        AlertRule {
            id: "busy".to_string(),
            service: "alpha".to_string(),
            kind: "metric".to_string(),
            metric: "q".to_string(),
            op: ">".to_string(),
            threshold: 10.0,
            for_polls: 3,
            ..AlertRule::default()
        }
    }

    #[test]
    fn fires_only_after_n_consecutive_breaches() {
        let mut eng = AlertEngine::new(&[metric_rule()], 128);
        let mut t = 1000.0;
        for _ in 0..2 {
            t += 1.0;
            eng.update(&results(&[("alpha", true, &[("q", 50.0)])]), t);
        }
        assert!(!eng.views()[0].firing);
        assert_eq!(eng.views()[0].streak, 2);

        t += 1.0;
        eng.update(&results(&[("alpha", true, &[("q", 50.0)])]), t);
        let v = &eng.views()[0];
        assert!(v.firing);
        assert_eq!(v.status, "firing");
        assert_eq!(v.last_value, Some(50.0));
    }

    #[test]
    fn streak_resets_on_a_single_good_poll() {
        let mut eng = AlertEngine::new(&[metric_rule()], 128);
        for _ in 0..2 {
            eng.update(&results(&[("alpha", true, &[("q", 50.0)])]), 1000.0);
        }
        eng.update(&results(&[("alpha", true, &[("q", 5.0)])]), 1000.0);
        assert_eq!(eng.views()[0].streak, 0);
        eng.update(&results(&[("alpha", true, &[("q", 50.0)])]), 1000.0);
        assert!(!eng.views()[0].firing);
    }

    #[test]
    fn clears_on_recovery_and_since_advances() {
        let mut eng = AlertEngine::new(&[metric_rule()], 128);
        let mut t = 1000.0;
        for _ in 0..3 {
            t += 1.0;
            eng.update(&results(&[("alpha", true, &[("q", 50.0)])]), t);
        }
        let fired_since = eng.views()[0].since;
        assert!(eng.views()[0].firing);

        t += 5.0;
        eng.update(&results(&[("alpha", true, &[("q", 1.0)])]), t);
        let v = &eng.views()[0];
        assert!(!v.firing);
        assert_ne!(v.since, fired_since);
        let statuses: Vec<String> = eng.events().iter().map(|e| e.status.clone()).collect();
        assert_eq!(statuses, vec!["firing", "ok"]);
    }

    #[test]
    fn missing_metric_never_fires() {
        let mut eng = AlertEngine::new(&[metric_rule()], 128);
        for _ in 0..5 {
            eng.update(&results(&[("alpha", true, &[])]), 1000.0);
        }
        assert!(!eng.views()[0].firing);
        assert_eq!(eng.views()[0].last_value, None);
    }

    #[test]
    fn metric_rule_does_not_fire_while_service_down() {
        let mut eng = AlertEngine::new(&[metric_rule()], 128);
        for _ in 0..5 {
            eng.update(&results(&[("alpha", false, &[])]), 1000.0);
        }
        assert!(!eng.views()[0].firing);
    }

    #[test]
    fn down_detection_fires_and_clears() {
        let rule = AlertRule {
            id: "d".to_string(),
            service: "svc".to_string(),
            kind: "down".to_string(),
            for_polls: 1,
            ..AlertRule::default()
        };
        let mut eng = AlertEngine::new(&[rule], 128);
        eng.update(&results(&[("svc", false, &[])]), 1000.0);
        assert!(eng.views()[0].firing);
        eng.update(&results(&[("svc", true, &[])]), 1001.0);
        assert!(!eng.views()[0].firing);
    }

    #[test]
    fn wildcard_applies_to_every_service() {
        let rule = AlertRule {
            id: "any".to_string(),
            kind: "down".to_string(),
            ..AlertRule::default()
        };
        let mut eng = AlertEngine::new(&[rule], 128);
        eng.update(
            &results(&[("a", true, &[]), ("b", false, &[]), ("c", false, &[])]),
            1000.0,
        );
        let firing: Vec<String> = eng
            .views()
            .iter()
            .filter(|v| v.firing)
            .map(|v| v.service.clone())
            .collect();
        assert_eq!(firing, vec!["b", "c"]);
    }

    #[test]
    fn states_are_pruned_when_a_service_stops_being_polled() {
        let rule = AlertRule {
            id: "any".to_string(),
            kind: "down".to_string(),
            ..AlertRule::default()
        };
        let mut eng = AlertEngine::new(&[rule], 128);
        eng.update(&results(&[("a", false, &[]), ("b", false, &[])]), 1000.0);
        assert_eq!(eng.views().len(), 2);
        eng.update(&results(&[("a", false, &[])]), 1000.0);
        let live: Vec<String> = eng.views().iter().map(|v| v.service.clone()).collect();
        assert_eq!(live, vec!["a"]);
    }

    #[test]
    fn event_log_is_bounded() {
        let rule = AlertRule {
            id: "d".to_string(),
            service: "s".to_string(),
            kind: "down".to_string(),
            for_polls: 1,
            ..AlertRule::default()
        };
        let mut eng = AlertEngine::new(&[rule], 3);
        for i in 0..20 {
            eng.update(&results(&[("s", i % 2 == 1, &[])]), 1000.0 + f64::from(i));
        }
        assert!(eng.events().len() <= 3);
    }

    #[test]
    fn views_sort_firing_then_severity_then_service() {
        let mk = |id: &str, svc: &str, sev: &str| AlertRule {
            id: id.to_string(),
            service: svc.to_string(),
            kind: "down".to_string(),
            severity: sev.to_string(),
            for_polls: 1,
            ..AlertRule::default()
        };
        let rules = [
            mk("warn", "b", "warning"),
            mk("crit", "b", "critical"),
            mk("ok", "a", "critical"),
        ];
        let mut eng = AlertEngine::new(&rules, 128);
        eng.update(&results(&[("a", true, &[]), ("b", false, &[])]), 1000.0);
        let order: Vec<(String, String)> = eng
            .views()
            .iter()
            .map(|v| (v.service.clone(), v.rule_id.clone()))
            .collect();
        assert_eq!(
            order,
            vec![
                ("b".to_string(), "crit".to_string()),
                ("b".to_string(), "warn".to_string()),
                ("a".to_string(), "ok".to_string()),
            ]
        );
    }
}
