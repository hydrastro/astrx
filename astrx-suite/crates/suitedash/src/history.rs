//! Bounded in-memory history + hand-emitted inline-SVG sparklines (no JS) — a
//! port of the Python `suitedash.history`.
//!
//! A [`Ring`] is a fixed-capacity buffer of recent numeric samples; [`History`]
//! keeps one ring per `(service, metric)` and evicts the least-recently-updated
//! series once `max_series` distinct pairs exist, so memory is doubly bounded
//! (capacity × series). Retention is bounded **per service** as well
//! ([`MAX_SERIES_PER_SERVICE`]) and eviction always takes from the service
//! holding the most rings, so one service whose metric *names* churn cannot push
//! another service's history out — least of all a DOWN service's, whose rings
//! stop being refreshed the moment it goes down and are therefore exactly what a
//! global least-recently-updated rule would delete first. History is purely
//! in-memory and resets on restart — that is intentional; suitedash is a live
//! status view, not a TSDB.
//!
//! [`sparkline_svg`] renders a tiny `<svg><polyline/></svg>` from a point list
//! *by hand* — no external library, no script. Every numeric input is filtered
//! for finiteness and clamped to a safe magnitude before any range arithmetic,
//! and every emitted coordinate is clamped into the viewport and formatted as a
//! finite decimal, so NaN/Inf/huge/empty/one-point inputs can never produce
//! invalid XML or an exploding path. Cross-checked byte-identical to Python by
//! `tests/xcheck_history.rs`.

use crate::metrics::{OrderedMap, Results, MAX_METRIC_NAME};
use std::collections::HashMap;
use std::collections::VecDeque;

/// Lower clamp for a ring's capacity.
pub const MIN_CAPACITY: i64 = 2;
/// Upper clamp for a ring's capacity.
pub const MAX_CAPACITY: i64 = 10_000;
/// Upper clamp for the number of distinct `(service, metric)` rings.
pub const MAX_SERIES: i64 = 100_000;

/// Cap on the rings retained for any single service.
///
/// The metric names on a card come from the service's own `/metrics` body, so a
/// service that emits a *fresh* name every sweep (six auto-surfaced keys of
/// `metric_<sweep>_<j>`) mints new permanent ring keys forever. Without a
/// per-service cap it simply grew until the global `max_series` bound, then kept
/// going by evicting the globally least-recently-updated ring — which belongs to
/// whichever service has *not* been updated lately, i.e. the one that just went
/// DOWN and whose sparklines the operator is looking at. 64 distinct metrics per
/// service is far more than any suite service surfaces (`AUTO_LIMIT` is 6, and
/// the shipped configs name three).
pub const MAX_SERIES_PER_SERVICE: i64 = 64;

/// Values are clamped to ± this before range math so `max - min` can never
/// overflow to `+Inf` (e.g. `1e308 - (-1e308)`) and poison the coordinate
/// scaling.
const CLAMP: f64 = 1e12;

/// The sparkline viewport width used by the status page.
pub const SPARK_WIDTH: f64 = 100.0;
/// The sparkline viewport height used by the status page.
pub const SPARK_HEIGHT: f64 = 20.0;

/// A fixed-capacity ring of `f64` samples; oldest evicted on overflow.
#[derive(Clone, Debug, PartialEq)]
pub struct Ring {
    buf: VecDeque<f64>,
    capacity: usize,
}

impl Ring {
    /// A ring holding at most `capacity` samples (clamped to `1..=MAX_CAPACITY`).
    #[must_use]
    pub fn new(capacity: i64) -> Self {
        let cap = capacity.clamp(1, MAX_CAPACITY) as usize;
        Ring {
            buf: VecDeque::with_capacity(cap.min(1024)),
            capacity: cap,
        }
    }

    /// Append a sample, evicting the oldest once full.
    pub fn push(&mut self, value: f64) {
        if self.buf.len() == self.capacity {
            self.buf.pop_front();
        }
        self.buf.push_back(value);
    }

    /// The retained samples, oldest first.
    #[must_use]
    pub fn values(&self) -> Vec<f64> {
        self.buf.iter().copied().collect()
    }

    /// How many samples are retained.
    #[must_use]
    pub fn len(&self) -> usize {
        self.buf.len()
    }

    /// `true` when no sample has been pushed yet.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.buf.is_empty()
    }
}

/// Per-`(service, metric)` ring buffers, bounded in capacity and count.
///
/// Insertion order (and the LRU refresh Python's `OrderedDict.move_to_end`
/// performs on every update) is preserved with a monotonic sequence number, so
/// [`History::all_series`] iterates in exactly the reference's order and
/// eviction drops exactly the reference's series.
#[derive(Clone, Debug)]
pub struct History {
    /// Samples retained per series.
    pub capacity: i64,
    /// Maximum number of distinct series.
    pub max_series: i64,
    /// Maximum number of distinct series for any one service.
    pub max_series_per_service: i64,
    rings: HashMap<(String, String), (u64, Ring)>,
    next_seq: u64,
}

impl Default for History {
    fn default() -> Self {
        History::new(60, 256)
    }
}

impl History {
    /// A history keeping `capacity` samples for up to `max_series` series (both
    /// clamped, so a direct constructor call stays bounded).
    #[must_use]
    pub fn new(capacity: i64, max_series: i64) -> Self {
        let max_series = max_series.clamp(1, MAX_SERIES);
        History {
            capacity: capacity.clamp(MIN_CAPACITY, MAX_CAPACITY),
            max_series,
            max_series_per_service: max_series.min(MAX_SERIES_PER_SERVICE),
            rings: HashMap::new(),
            next_seq: 0,
        }
    }

    /// Append this sweep's finite metric samples for every UP service.
    pub fn record(&mut self, results: &Results) {
        for (name, r) in results.iter() {
            if !r.up {
                continue;
            }
            for (metric, v) in r.metrics.iter() {
                let Some(fv) = *v else { continue };
                if !fv.is_finite() {
                    continue;
                }
                let key = (name.to_string(), metric.to_string());
                let seq = self.next_seq;
                self.next_seq += 1;
                if let Some(entry) = self.rings.get_mut(&key) {
                    entry.0 = seq; // mark most-recently-updated
                    entry.1.push(fv);
                    continue;
                }
                // A ring key lives until it is evicted, so it is retained memory
                // an untrusted `/metrics` body chose. The parsers already cap the
                // name (`MAX_METRIC_NAME`); `record` is public and takes any
                // `Results`, so re-check rather than trust the caller.
                if metric.len() > MAX_METRIC_NAME {
                    continue;
                }
                if self.service_len(name) >= self.max_series_per_service as usize {
                    self.evict_oldest_of(name);
                } else if self.rings.len() >= self.max_series as usize {
                    self.evict_from_the_largest_holder();
                }
                let mut ring = Ring::new(self.capacity);
                ring.push(fv);
                self.rings.insert(key, (seq, ring));
            }
        }
    }

    /// How many rings `service` currently holds.
    fn service_len(&self, service: &str) -> usize {
        self.rings.keys().filter(|(svc, _)| svc == service).count()
    }

    /// Drop the least-recently-updated ring belonging to `service`.
    fn evict_oldest_of(&mut self, service: &str) {
        let victim = self
            .rings
            .iter()
            .filter(|((svc, _), _)| svc == service)
            .min_by_key(|(_, (seq, _))| *seq)
            .map(|(k, _)| k.clone());
        if let Some(k) = victim {
            self.rings.remove(&k);
        }
    }

    /// Make room by dropping the least-recently-updated ring **of the service
    /// that holds the most rings**.
    ///
    /// Evicting the global LRU instead is what let one misbehaving service
    /// delete everyone else's history: its rings are refreshed every sweep, so
    /// they are never the global LRU, while a service that went DOWN stops being
    /// updated at all and its rings sort oldest immediately. With the default
    /// 256-series budget and a service rotating six auto-surfaced names per
    /// sweep, the budget is full after ~43 sweeps and every eviction from then on
    /// takes a ring from whoever has *stopped* reporting — the DOWN service whose
    /// sparklines are the reason the page is open. Taking from the largest holder
    /// means a service can only ever evict its own series, or one belonging to a
    /// service consuming even more of the budget than it is.
    fn evict_from_the_largest_holder(&mut self) {
        let mut counts: HashMap<&str, usize> = HashMap::new();
        for (svc, _) in self.rings.keys() {
            *counts.entry(svc.as_str()).or_insert(0) += 1;
        }
        let Some(most) = counts.values().copied().max() else {
            return;
        };
        let victim = self
            .rings
            .iter()
            .filter(|((svc, _), _)| counts.get(svc.as_str()) == Some(&most))
            .min_by_key(|(_, (seq, _))| *seq)
            .map(|(k, _)| k.clone());
        if let Some(k) = victim {
            self.rings.remove(&k);
        }
    }

    /// The buffered samples for one `(service, metric)`, oldest first.
    #[must_use]
    pub fn series(&self, service: &str, metric: &str) -> Vec<f64> {
        self.rings
            .get(&(service.to_string(), metric.to_string()))
            .map_or_else(Vec::new, |(_, r)| r.values())
    }

    /// A copy of every ring as `{service: {metric: [values]}}`, in the
    /// least-recently-updated-first order the reference's `OrderedDict` yields.
    #[must_use]
    pub fn all_series(&self) -> OrderedMap<OrderedMap<Vec<f64>>> {
        let mut ordered: Vec<_> = self.rings.iter().collect();
        ordered.sort_by_key(|(_, (seq, _))| *seq);
        let mut out: OrderedMap<OrderedMap<Vec<f64>>> = OrderedMap::new();
        for ((svc, metric), (_, ring)) in ordered {
            if !out.contains_key(svc) {
                out.insert(svc.clone(), OrderedMap::new());
            }
            if let Some(inner) = out.get_mut(svc) {
                inner.insert(metric.clone(), ring.values());
            }
        }
        out
    }

    /// How many series are currently retained.
    #[must_use]
    pub fn len(&self) -> usize {
        self.rings.len()
    }

    /// `true` when nothing has been recorded yet.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.rings.is_empty()
    }
}

/// Finite, trimmed decimal for an SVG coordinate (`"0"` for anything odd).
fn fmt_coord(v: f64) -> String {
    if !v.is_finite() {
        return "0".to_string();
    }
    let s = format!("{v:.2}");
    let s = if s.contains('.') {
        s.trim_end_matches('0').trim_end_matches('.').to_string()
    } else {
        s
    };
    if s.is_empty() {
        "0".to_string()
    } else {
        s
    }
}

fn clampf(v: f64, lo: f64, hi: f64) -> f64 {
    if !v.is_finite() {
        return lo;
    }
    if v < lo {
        return lo;
    }
    if v > hi {
        return hi;
    }
    v
}

/// A finite float for a viewport dimension, or `default` for NaN/Inf — so a bad
/// width/height can never raise here.
fn safe_dim(v: f64, default: f64) -> f64 {
    if v.is_finite() {
        v
    } else {
        default
    }
}

/// Return a well-formed inline `<svg>` sparkline for `points`.
///
/// Robust by construction: non-finite points are dropped, the remaining values
/// are clamped to a safe magnitude, and every emitted number is clamped into the
/// `width × height` viewport. An empty series yields a valid empty
/// `<svg></svg>`; a single point yields a flat mid-line; a flat series
/// (all-equal, incl. huge values) yields a mid-line — never invalid XML.
///
/// Python's `sparkline_svg` additionally tolerates *non-numeric* points and
/// dimensions (`"oops"`, `None`), which it drops; those are unrepresentable in
/// the typed signature, and the NaN/Inf paths they degrade to are covered here.
#[must_use]
pub fn sparkline_svg(points: &[f64], width: f64, height: f64) -> String {
    let w = clampf(safe_dim(width, SPARK_WIDTH), 1.0, 100_000.0);
    let h = clampf(safe_dim(height, SPARK_HEIGHT), 1.0, 100_000.0);
    let pad = if h > 4.0 { 1.0 } else { 0.0 };

    let clean: Vec<f64> = points
        .iter()
        .copied()
        .filter(|v| v.is_finite())
        .map(|v| clampf(v, -CLAMP, CLAMP))
        .collect();

    let open_tag = format!(
        "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{}\" height=\"{}\" \
         viewBox=\"0 0 {} {}\" class=\"spark\" preserveAspectRatio=\"none\" role=\"img\">",
        fmt_coord(w),
        fmt_coord(h),
        fmt_coord(w),
        fmt_coord(h)
    );
    if clean.is_empty() {
        return open_tag + "</svg>";
    }

    let lo = clean.iter().copied().fold(f64::INFINITY, f64::min);
    let hi = clean.iter().copied().fold(f64::NEG_INFINITY, f64::max);
    let span = hi - lo;
    let usable = (h - 2.0 * pad).max(0.0);

    let y_for = |v: f64| -> f64 {
        let norm = if !span.is_finite() || span <= 0.0 {
            0.5
        } else {
            let n = (v - lo) / span;
            if n.is_finite() {
                n
            } else {
                0.5
            }
        };
        let norm = clampf(norm, 0.0, 1.0);
        clampf(pad + (1.0 - norm) * usable, 0.0, h)
    };

    let n = clean.len();
    let pts = if n == 1 {
        let y = fmt_coord(y_for(clean[0]));
        format!("{},{} {},{}", fmt_coord(0.0), y, fmt_coord(w), y)
    } else {
        let step = w / (n - 1) as f64;
        let coords: Vec<String> = clean
            .iter()
            .enumerate()
            .map(|(i, v)| {
                let x = clampf(i as f64 * step, 0.0, w);
                format!("{},{}", fmt_coord(x), fmt_coord(y_for(*v)))
            })
            .collect();
        coords.join(" ")
    };

    format!(
        "{open_tag}<polyline fill=\"none\" stroke=\"currentColor\" stroke-width=\"1\" \
         points=\"{pts}\"/></svg>"
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::metrics::{ServiceResult, SurfacedMetrics};

    /// `(name, up, [(metric, value)])` specs for a synthetic sweep.
    type Spec<'a> = (&'a str, bool, &'a [(&'a str, Option<f64>)]);

    fn results(specs: &[Spec<'_>]) -> Results {
        let mut out = Results::new();
        for (name, up, metrics) in specs {
            let mut r = ServiceResult::new(*name, "x", *up);
            let mut m = SurfacedMetrics::new();
            for (k, v) in *metrics {
                m.insert(*k, *v);
            }
            r.metrics = m;
            out.insert(*name, r);
        }
        out
    }

    #[test]
    fn ring_is_bounded_and_evicts_oldest() {
        let mut ring = Ring::new(3);
        for i in 0..5 {
            ring.push(f64::from(i));
        }
        assert_eq!(ring.values(), vec![2.0, 3.0, 4.0]);
        assert_eq!(ring.len(), 3);
    }

    #[test]
    fn records_finite_samples_for_up_services_only() {
        let mut h = History::new(10, 100);
        h.record(&results(&[
            ("a", true, &[("m", Some(1.0))]),
            ("down", false, &[("m", Some(9.0))]),
        ]));
        h.record(&results(&[("a", true, &[("m", Some(2.0))])]));
        assert_eq!(h.series("a", "m"), vec![1.0, 2.0]);
        assert!(h.series("down", "m").is_empty());
    }

    #[test]
    fn skips_none_and_non_finite() {
        let mut h = History::new(10, 256);
        h.record(&results(&[(
            "a",
            true,
            &[("m", None), ("n", Some(f64::INFINITY))],
        )]));
        assert!(h.series("a", "m").is_empty());
        assert!(h.series("a", "n").is_empty());
    }

    #[test]
    fn series_count_is_bounded_evicting_oldest() {
        let mut h = History::new(5, 2);
        h.record(&results(&[("a", true, &[("x", Some(1.0))])]));
        h.record(&results(&[("b", true, &[("y", Some(1.0))])]));
        h.record(&results(&[("c", true, &[("z", Some(1.0))])]));
        assert_eq!(h.len(), 2);
        assert!(h.series("a", "x").is_empty());
        let all = h.all_series();
        assert_eq!(all.keys().collect::<Vec<_>>(), vec!["b", "c"]);
    }

    #[test]
    fn capacity_is_clamped_to_a_sane_minimum() {
        assert!(History::new(0, 256).capacity >= 2);
    }

    /// One sweep of a service whose metric NAMES rotate (`m_<sweep>_<j>`), the
    /// shape a hostile `/metrics` body produces against auto-surfaced keys.
    fn churn_sweep(sweep: usize) -> Results {
        let names: Vec<String> = (0..6).map(|j| format!("m_{sweep}_{j}")).collect();
        let pairs: Vec<(&str, Option<f64>)> =
            names.iter().map(|n| (n.as_str(), Some(1.0))).collect();
        results(&[("churn", true, &pairs)])
    }

    /// A service that mints six new metric names every sweep must not be able to
    /// delete another service's history — least of all one that is DOWN, whose
    /// rings are never refreshed again and so sort oldest under a global
    /// least-recently-updated rule. Measured before the fix: `beta`'s three
    /// sparklines were gone well inside 64 sweeps.
    #[test]
    fn a_churning_service_cannot_evict_a_down_services_history() {
        let mut h = History::new(10, 8);
        h.record(&results(&[(
            "beta",
            true,
            &[("b1", Some(1.0)), ("b2", Some(2.0)), ("b3", Some(3.0))],
        )]));
        // beta then goes DOWN and stops reporting entirely.
        for sweep in 0..64 {
            h.record(&churn_sweep(sweep));
        }
        assert_eq!(h.series("beta", "b1"), vec![1.0]);
        assert_eq!(h.series("beta", "b2"), vec![2.0]);
        assert_eq!(h.series("beta", "b3"), vec![3.0]);
        assert!(h.len() <= 8, "the global bound still holds: {}", h.len());
    }

    /// …and the churner itself is bounded per service, not merely globally: with
    /// a generous `max_series` it retained 384 names after 64 sweeps.
    #[test]
    fn retained_series_are_bounded_per_service() {
        let mut h = History::new(10, MAX_SERIES);
        for sweep in 0..64 {
            h.record(&churn_sweep(sweep));
        }
        assert_eq!(h.len(), MAX_SERIES_PER_SERVICE as usize);
        // The survivors are the most recent names, not the first ones seen.
        assert!(h.series("churn", "m_0_0").is_empty());
        assert_eq!(h.series("churn", "m_63_0"), vec![1.0]);
    }

    /// A 150 000-byte metric name never becomes a permanent ring key, even when
    /// `record` is called directly with results the capped parsers never made.
    #[test]
    fn an_over_long_metric_name_is_never_retained() {
        let huge = "a".repeat(150_000);
        let mut h = History::new(10, 256);
        h.record(&results(&[(
            "svc",
            true,
            &[(huge.as_str(), Some(1.0)), ("ok", Some(2.0))],
        )]));
        assert_eq!(h.len(), 1);
        assert_eq!(h.series("svc", "ok"), vec![2.0]);
    }

    #[test]
    fn all_series_keeps_least_recently_updated_first() {
        let mut h = History::new(5, 10);
        h.record(&results(&[
            ("a", true, &[("x", Some(1.0))]),
            ("b", true, &[("y", Some(1.0))]),
        ]));
        // Touching `a` again moves it to the end (Python's move_to_end).
        h.record(&results(&[("a", true, &[("x", Some(2.0))])]));
        assert_eq!(h.all_series().keys().collect::<Vec<_>>(), vec!["b", "a"]);
    }

    #[test]
    fn empty_series_is_valid_svg_without_polyline() {
        let svg = sparkline_svg(&[], SPARK_WIDTH, SPARK_HEIGHT);
        assert!(svg.ends_with("></svg>"));
        assert!(!svg.contains("polyline"));
    }

    #[test]
    fn all_non_finite_yields_valid_empty_svg() {
        let svg = sparkline_svg(&[f64::NAN, f64::INFINITY], SPARK_WIDTH, SPARK_HEIGHT);
        assert!(!svg.contains("polyline"));
    }

    #[test]
    fn single_point_is_a_flat_two_point_line() {
        let svg = sparkline_svg(&[42.0], SPARK_WIDTH, SPARK_HEIGHT);
        assert!(svg.contains("points=\"0,10 100,10\""));
    }

    #[test]
    fn huge_values_stay_inside_the_viewport() {
        let svg = sparkline_svg(&[1e308, -1e308, 1e308, 0.0], SPARK_WIDTH, SPARK_HEIGHT);
        let pts = svg.split("points=\"").nth(1).unwrap();
        let pts = pts.split('"').next().unwrap();
        for pair in pts.split(' ') {
            let (x, y) = pair.split_once(',').unwrap();
            let (x, y): (f64, f64) = (x.parse().unwrap(), y.parse().unwrap());
            assert!((0.0..=100.0).contains(&x) && (0.0..=20.0).contains(&y));
        }
    }

    #[test]
    fn bad_dimensions_degrade_to_the_defaults() {
        for (w, h) in [(f64::NAN, 20.0), (100.0, f64::INFINITY)] {
            let svg = sparkline_svg(&[1.0, 2.0, 3.0], w, h);
            assert!(svg.starts_with("<svg "));
        }
    }
}
