//! Bounded in-memory history + hand-emitted inline-SVG sparklines (no JS) — a
//! port of the Python `suitedash.history`.
//!
//! A [`Ring`] is a fixed-capacity buffer of recent numeric samples; [`History`]
//! keeps one ring per `(service, metric)` and evicts the least-recently-updated
//! series once `max_series` distinct pairs exist, so memory is doubly bounded
//! (capacity × series). History is purely in-memory and resets on restart — that
//! is intentional; suitedash is a live status view, not a TSDB.
//!
//! [`sparkline_svg`] renders a tiny `<svg><polyline/></svg>` from a point list
//! *by hand* — no external library, no script. Every numeric input is filtered
//! for finiteness and clamped to a safe magnitude before any range arithmetic,
//! and every emitted coordinate is clamped into the viewport and formatted as a
//! finite decimal, so NaN/Inf/huge/empty/one-point inputs can never produce
//! invalid XML or an exploding path. Cross-checked byte-identical to Python by
//! `tests/xcheck_history.rs`.

use crate::metrics::{OrderedMap, Results};
use std::collections::HashMap;
use std::collections::VecDeque;

/// Lower clamp for a ring's capacity.
pub const MIN_CAPACITY: i64 = 2;
/// Upper clamp for a ring's capacity.
pub const MAX_CAPACITY: i64 = 10_000;
/// Upper clamp for the number of distinct `(service, metric)` rings.
pub const MAX_SERIES: i64 = 100_000;

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
        History {
            capacity: capacity.clamp(MIN_CAPACITY, MAX_CAPACITY),
            max_series: max_series.clamp(1, MAX_SERIES),
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
                if self.rings.len() >= self.max_series as usize {
                    self.evict_oldest();
                }
                let mut ring = Ring::new(self.capacity);
                ring.push(fv);
                self.rings.insert(key, (seq, ring));
            }
        }
    }

    fn evict_oldest(&mut self) {
        if let Some(oldest) = self
            .rings
            .iter()
            .min_by_key(|(_, (seq, _))| *seq)
            .map(|(k, _)| k.clone())
        {
            self.rings.remove(&oldest);
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
