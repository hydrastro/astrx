//! Cross-check: the Rust `suitedash::alerts` engine reproduces the Python
//! `suitedash.alerts` byte-identically across a scripted sweep sequence — a
//! debounced metric rule climbing to firing and clearing on recovery, a wildcard
//! `down` rule flapping, a wildcard metric rule over two services, a rule aimed
//! at a service that is never polled, an unknown operator, an absent metric, a
//! NaN sample, state pruning when a service stops being polled, and the bounded
//! (6-entry) transition log. Every `views()` row and every retained event is
//! compared after every sweep.
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `alerts`), which drives
//! the real Python engine with a hand-cranked clock (the Rust `update` takes the
//! timestamp as an argument instead).

use suitedash::alerts::AlertEngine;
use suitedash::config::AlertRule;
use suitedash::metrics::{Results, ServiceResult, SurfacedMetrics};

/// The Python `_rules()` fixture: a debounced metric rule, a wildcard `down`
/// rule, a wildcard metric rule, a rule aimed at a service that is never polled,
/// and one carrying an operator the engine does not know.
fn rules() -> Vec<AlertRule> {
    vec![
        AlertRule {
            id: "busy".to_string(),
            service: "alpha".to_string(),
            kind: "metric".to_string(),
            metric: "q".to_string(),
            op: ">".to_string(),
            threshold: 10.0,
            for_polls: 3,
            severity: "warning".to_string(),
            description: "alpha queue is deep".to_string(),
        },
        AlertRule {
            id: "down".to_string(),
            service: "*".to_string(),
            kind: "down".to_string(),
            for_polls: 1,
            severity: "critical".to_string(),
            description: "a suite service is down".to_string(),
            ..AlertRule::default()
        },
        AlertRule {
            id: "mem".to_string(),
            service: "*".to_string(),
            kind: "metric".to_string(),
            metric: "mem".to_string(),
            op: ">=".to_string(),
            threshold: 100.0,
            for_polls: 2,
            severity: "info".to_string(),
            description: String::new(),
        },
        AlertRule {
            id: "ghost".to_string(),
            service: "nosuch".to_string(),
            kind: "down".to_string(),
            for_polls: 1,
            severity: "info".to_string(),
            description: "never targeted".to_string(),
            ..AlertRule::default()
        },
        AlertRule {
            id: "weird".to_string(),
            service: "alpha".to_string(),
            kind: "metric".to_string(),
            metric: "q".to_string(),
            op: "~~".to_string(),
            threshold: 0.0,
            for_polls: 1,
            severity: "nonsense".to_string(),
            description: "unknown operator".to_string(),
        },
    ]
}

/// The Python `result(name, up, metrics)` fixture.
fn res(name: &str, up: bool, metrics: &[(&str, Option<f64>)]) -> ServiceResult {
    let mut r = ServiceResult::new(name, "http://x", up);
    let mut m = SurfacedMetrics::new();
    for (k, v) in metrics {
        m.insert(*k, *v);
    }
    r.metrics = m;
    r
}

fn sweep(rs: Vec<ServiceResult>) -> Results {
    let mut out = Results::new();
    for r in rs {
        out.insert(r.name.clone(), r);
    }
    out
}

/// `repr()` of a float / `None`, the dump spelling shared with the generator.
fn num(v: Option<f64>) -> String {
    v.map_or_else(|| "None".to_string(), |f| format!("{f:?}"))
}

/// Python's `str(bool)`.
fn pybool(b: bool) -> &'static str {
    if b {
        "True"
    } else {
        "False"
    }
}

#[test]
fn engine_matches_python() {
    let want: &[&str] = &[
        r#"s0 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s0 view beta|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s0 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1000.0|50.0|1"#,
        r#"s0 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|10.0|0"#,
        r#"s0 view beta|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|500.0|1"#,
        r#"s0 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|50.0|0"#,
        r#"s1 view beta|mem|metric|info||mem|>=|100.0|2|True|firing|1001.0|500.0|2"#,
        r#"s1 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s1 view beta|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s1 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1000.0|50.0|2"#,
        r#"s1 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|10.0|0"#,
        r#"s1 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|50.0|0"#,
        r#"s1 event 1001.0|beta|mem|firing|500.0"#,
        r#"s2 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|True|firing|1002.0|50.0|3"#,
        r#"s2 view beta|mem|metric|info||mem|>=|100.0|2|True|firing|1001.0|500.0|3"#,
        r#"s2 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s2 view beta|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s2 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|10.0|0"#,
        r#"s2 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|50.0|0"#,
        r#"s2 event 1001.0|beta|mem|firing|500.0"#,
        r#"s2 event 1002.0|alpha|busy|firing|50.0"#,
        r#"s3 view beta|down|down|critical|a suite service is down||>|0.0|1|True|firing|1003.0|None|1"#,
        r#"s3 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s3 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1003.0|5.0|0"#,
        r#"s3 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|10.0|0"#,
        r#"s3 view beta|mem|metric|info||mem|>=|100.0|2|False|ok|1003.0|None|0"#,
        r#"s3 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|5.0|0"#,
        r#"s3 event 1001.0|beta|mem|firing|500.0"#,
        r#"s3 event 1002.0|alpha|busy|firing|50.0"#,
        r#"s3 event 1003.0|alpha|busy|ok|5.0"#,
        r#"s3 event 1003.0|beta|down|firing|None"#,
        r#"s3 event 1003.0|beta|mem|ok|None"#,
        r#"s4 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1000.0|None|0"#,
        r#"s4 view beta|down|down|critical|a suite service is down||>|0.0|1|False|ok|1004.0|None|0"#,
        r#"s4 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1003.0|50.0|1"#,
        r#"s4 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|None|0"#,
        r#"s4 view beta|mem|metric|info||mem|>=|100.0|2|False|ok|1003.0|99.5|0"#,
        r#"s4 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|50.0|0"#,
        r#"s4 event 1001.0|beta|mem|firing|500.0"#,
        r#"s4 event 1002.0|alpha|busy|firing|50.0"#,
        r#"s4 event 1003.0|alpha|busy|ok|5.0"#,
        r#"s4 event 1003.0|beta|down|firing|None"#,
        r#"s4 event 1003.0|beta|mem|ok|None"#,
        r#"s4 event 1004.0|beta|down|ok|None"#,
        r#"s5 view alpha|down|down|critical|a suite service is down||>|0.0|1|True|firing|1005.0|None|1"#,
        r#"s5 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1003.0|None|0"#,
        r#"s5 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|None|0"#,
        r#"s5 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|None|0"#,
        r#"s5 event 1002.0|alpha|busy|firing|50.0"#,
        r#"s5 event 1003.0|alpha|busy|ok|5.0"#,
        r#"s5 event 1003.0|beta|down|firing|None"#,
        r#"s5 event 1003.0|beta|mem|ok|None"#,
        r#"s5 event 1004.0|beta|down|ok|None"#,
        r#"s5 event 1005.0|alpha|down|firing|None"#,
        r#"s6 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1006.0|None|0"#,
        r#"s6 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1003.0|11.0|1"#,
        r#"s6 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|100.0|1"#,
        r#"s6 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|11.0|0"#,
        r#"s6 event 1003.0|alpha|busy|ok|5.0"#,
        r#"s6 event 1003.0|beta|down|firing|None"#,
        r#"s6 event 1003.0|beta|mem|ok|None"#,
        r#"s6 event 1004.0|beta|down|ok|None"#,
        r#"s6 event 1005.0|alpha|down|firing|None"#,
        r#"s6 event 1006.0|alpha|down|ok|None"#,
        r#"s7 view alpha|down|down|critical|a suite service is down||>|0.0|1|True|firing|1007.0|None|1"#,
        r#"s7 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1003.0|None|0"#,
        r#"s7 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|None|0"#,
        r#"s7 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|None|0"#,
        r#"s7 event 1003.0|beta|down|firing|None"#,
        r#"s7 event 1003.0|beta|mem|ok|None"#,
        r#"s7 event 1004.0|beta|down|ok|None"#,
        r#"s7 event 1005.0|alpha|down|firing|None"#,
        r#"s7 event 1006.0|alpha|down|ok|None"#,
        r#"s7 event 1007.0|alpha|down|firing|None"#,
        r#"s8 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1008.0|None|0"#,
        r#"s8 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1003.0|None|0"#,
        r#"s8 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|None|0"#,
        r#"s8 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|None|0"#,
        r#"s8 event 1003.0|beta|mem|ok|None"#,
        r#"s8 event 1004.0|beta|down|ok|None"#,
        r#"s8 event 1005.0|alpha|down|firing|None"#,
        r#"s8 event 1006.0|alpha|down|ok|None"#,
        r#"s8 event 1007.0|alpha|down|firing|None"#,
        r#"s8 event 1008.0|alpha|down|ok|None"#,
        r#"s9 view gamma|down|down|critical|a suite service is down||>|0.0|1|True|firing|1009.0|None|1"#,
        r#"s9 view alpha|down|down|critical|a suite service is down||>|0.0|1|False|ok|1008.0|None|0"#,
        r#"s9 view beta|down|down|critical|a suite service is down||>|0.0|1|False|ok|1009.0|None|0"#,
        r#"s9 view alpha|busy|metric|warning|alpha queue is deep|q|>|10.0|3|False|ok|1003.0|50.0|1"#,
        r#"s9 view alpha|mem|metric|info||mem|>=|100.0|2|False|ok|1000.0|None|0"#,
        r#"s9 view beta|mem|metric|info||mem|>=|100.0|2|False|ok|1009.0|100.0|1"#,
        r#"s9 view gamma|mem|metric|info||mem|>=|100.0|2|False|ok|1009.0|None|0"#,
        r#"s9 view alpha|weird|metric|nonsense|unknown operator|q|~~|0.0|1|False|ok|1000.0|50.0|0"#,
        r#"s9 event 1004.0|beta|down|ok|None"#,
        r#"s9 event 1005.0|alpha|down|firing|None"#,
        r#"s9 event 1006.0|alpha|down|ok|None"#,
        r#"s9 event 1007.0|alpha|down|firing|None"#,
        r#"s9 event 1008.0|alpha|down|ok|None"#,
        r#"s9 event 1009.0|gamma|down|firing|None"#,
    ];

    let sweeps: Vec<(f64, Results)> = vec![
        (
            1000.0,
            sweep(vec![
                res("alpha", true, &[("q", Some(50.0)), ("mem", Some(10.0))]),
                res("beta", true, &[("mem", Some(500.0))]),
            ]),
        ),
        (
            1001.0,
            sweep(vec![
                res("alpha", true, &[("q", Some(50.0)), ("mem", Some(10.0))]),
                res("beta", true, &[("mem", Some(500.0))]),
            ]),
        ),
        (
            1002.0,
            sweep(vec![
                res("alpha", true, &[("q", Some(50.0)), ("mem", Some(10.0))]),
                res("beta", true, &[("mem", Some(500.0))]),
            ]),
        ),
        (
            1003.0,
            sweep(vec![
                res("alpha", true, &[("q", Some(5.0)), ("mem", Some(10.0))]),
                res("beta", false, &[]),
            ]),
        ),
        (
            1004.0,
            sweep(vec![
                res("alpha", true, &[("q", Some(50.0)), ("mem", None)]),
                res("beta", true, &[("mem", Some(99.5))]),
            ]),
        ),
        (1005.0, sweep(vec![res("alpha", false, &[])])),
        (
            1006.0,
            sweep(vec![res(
                "alpha",
                true,
                &[("q", Some(11.0)), ("mem", Some(100.0))],
            )]),
        ),
        (1007.0, sweep(vec![res("alpha", false, &[])])),
        (
            1008.0,
            sweep(vec![res("alpha", true, &[("q", Some(f64::NAN))])]),
        ),
        (
            1009.0,
            sweep(vec![
                res("alpha", true, &[("q", Some(50.0))]),
                res("beta", true, &[("mem", Some(100.0))]),
                res("gamma", false, &[]),
            ]),
        ),
    ];

    let mut eng = AlertEngine::new(&rules(), 6);
    let mut got: Vec<String> = Vec::new();
    for (i, (now, results)) in sweeps.iter().enumerate() {
        eng.update(results, *now);
        for v in eng.views() {
            got.push(format!(
                "s{i} view {}|{}|{}|{}|{}|{}|{}|{}|{}|{}|{}|{}|{}|{}",
                v.service,
                v.rule_id,
                v.kind,
                v.severity,
                v.description,
                v.metric,
                v.op,
                num(Some(v.threshold)),
                v.for_polls,
                pybool(v.firing),
                v.status,
                num(Some(v.since)),
                num(v.last_value),
                v.streak
            ));
        }
        for e in eng.events() {
            got.push(format!(
                "s{i} event {}|{}|{}|{}|{}",
                num(Some(e.at)),
                e.service,
                e.rule_id,
                e.status,
                num(e.value)
            ));
        }
    }
    assert_eq!(got, want.to_vec());
}
