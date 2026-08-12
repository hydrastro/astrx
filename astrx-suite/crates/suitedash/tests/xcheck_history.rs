//! Cross-check: the Rust `suitedash::history` reproduces the Python
//! `suitedash.history` byte-identically — the hand-emitted inline-SVG
//! sparklines (empty, single point, flat, spiky, descending, negative, huge,
//! NaN/Inf-only, sub-pixel and absurd viewports, bad dimensions) and the bounded
//! ring/series book-keeping (capacity eviction, `move_to_end` LRU ordering,
//! series eviction, DOWN services and non-finite samples skipped).
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `history`), which drives
//! the real Python module.

use suitedash::history::{sparkline_svg, History, Ring};
use suitedash::metrics::{Results, ServiceResult, SurfacedMetrics};

#[test]
fn sparkline_svg_matches_python() {
    let cases: &[(&[f64], f64, f64, &str)] = &[
        (
            &[],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"></svg>"#,
        ),
        (
            &[42.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,10 100,10"/></svg>"#,
        ),
        (
            &[7.0, 7.0, 7.0, 7.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,10 33.33,10 66.67,10 100,10"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0, 4.0, 5.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 25,14.5 50,10 75,5.5 100,1"/></svg>"#,
        ),
        (
            &[5.0, 4.0, 3.0, 2.0, 1.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,1 25,5.5 50,10 75,14.5 100,19"/></svg>"#,
        ),
        (
            &[0.0, 100.0, 0.0, 100.0, 0.0, 100.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 20,1 40,19 60,1 80,19 100,1"/></svg>"#,
        ),
        (
            &[1204.0, 1210.0, 1211.0, 1250.0, 1249.0, 1300.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 20,17.88 40,17.69 60,10.37 80,10.56 100,1"/></svg>"#,
        ),
        (
            &[0.5, 0.25, 0.125],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,1 50,13 100,19"/></svg>"#,
        ),
        (
            &[-5.0, 0.0, 5.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 50,10 100,1"/></svg>"#,
        ),
        (
            &[1e+308, -1e+308, 1e+308, 0.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,1 33.33,19 66.67,1 100,10"/></svg>"#,
        ),
        (
            &[f64::NAN, f64::INFINITY, f64::NEG_INFINITY, 5.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,10 100,10"/></svg>"#,
        ),
        (
            &[f64::NAN, f64::INFINITY],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            3.0,
            4.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="3" height="4" viewBox="0 0 3 4" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,4 1.5,2 3,0"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            100.0,
            4.5,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="4.5" viewBox="0 0 100 4.5" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,3.5 50,2.25 100,1"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            100.0,
            5.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="5" viewBox="0 0 100 5" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,4 50,2.5 100,1"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            100.0,
            2.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="2" viewBox="0 0 100 2" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,2 50,1 100,0"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            100.0,
            4.0001,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="4" viewBox="0 0 100 4" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,3 50,2 100,1"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            1.0,
            1.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1" viewBox="0 0 1 1" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,1 0.5,0.5 1,0"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            0.0,
            0.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1" viewBox="0 0 1 1" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,1 0.5,0.5 1,0"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            250.5,
            33.25,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="250.5" height="33.25" viewBox="0 0 250.5 33.25" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,32.25 125.25,16.62 250.5,1"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            1000000000.0,
            1000000000.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100000" height="100000" viewBox="0 0 100000 100000" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,99999 50000,50000 100000,1"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            f64::NAN,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 50,10 100,1"/></svg>"#,
        ),
        (
            &[1.0, 2.0, 3.0],
            100.0,
            f64::INFINITY,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 50,10 100,1"/></svg>"#,
        ),
        (
            &[3.0, 1.0, 4.0, 1.0, 5.0, 9.0, 2.0, 6.0, 5.0, 3.0, 5.0],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,14.5 10,19 20,12.25 30,19 40,10 50,1 60,16.75 70,7.75 80,10 90,14.5 100,10"/></svg>"#,
        ),
        (
            &[0.1, 0.2, 0.30000000000000004],
            100.0,
            20.0,
            r#"<svg xmlns="http://www.w3.org/2000/svg" width="100" height="20" viewBox="0 0 100 20" class="spark" preserveAspectRatio="none" role="img"><polyline fill="none" stroke="currentColor" stroke-width="1" points="0,19 50,10 100,1"/></svg>"#,
        ),
    ];
    for (points, w, h, want) in cases {
        assert_eq!(
            &sparkline_svg(points, *w, *h),
            want,
            "sparkline_svg({points:?}, {w}, {h})"
        );
    }
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

#[test]
fn ring_and_history_match_python() {
    let want: &[&str] = &[
        r#"ring3: [2.0, 3.0, 4.0]"#,
        r#"sweep0: a/x=1.0; a/y=2.0; b/z=3.0"#,
        r#"sweep1: a/y=2.0; a/x=1.0,2.0; b/z=3.0"#,
        r#"sweep2: b/z=3.0; a/x=1.0,2.0,3.0; c/w=4.0"#,
        r#"sweep3: b/z=3.0; c/w=4.0; a/x=2.0,3.0,4.0"#,
    ];
    let mut got: Vec<String> = Vec::new();

    let mut ring = Ring::new(3);
    for i in 0..5 {
        ring.push(f64::from(i));
    }
    got.push(format!(
        "ring3: [{}]",
        ring.values()
            .iter()
            .map(|v| format!("{v:?}"))
            .collect::<Vec<_>>()
            .join(", ")
    ));

    let mut h = History::new(3, 3);
    let sweeps: [Results; 4] = [
        sweep(vec![
            res("a", true, &[("x", Some(1.0)), ("y", Some(2.0))]),
            res("b", true, &[("z", Some(3.0))]),
        ]),
        sweep(vec![
            res("a", true, &[("x", Some(2.0)), ("y", None)]),
            res("b", false, &[("z", Some(9.0))]),
        ]),
        sweep(vec![
            res("a", true, &[("x", Some(3.0))]),
            res("c", true, &[("w", Some(4.0))]),
        ]),
        sweep(vec![res(
            "a",
            true,
            &[("x", Some(4.0)), ("y", Some(f64::INFINITY))],
        )]),
    ];

    for (i, s) in sweeps.iter().enumerate() {
        h.record(s);
        let mut parts: Vec<String> = Vec::new();
        for (svc, mm) in h.all_series().iter() {
            for (metric, vals) in mm.iter() {
                parts.push(format!(
                    "{svc}/{metric}={}",
                    vals.iter()
                        .map(|v| format!("{v:?}"))
                        .collect::<Vec<_>>()
                        .join(",")
                ));
            }
        }
        got.push(format!("sweep{i}: {}", parts.join("; ")));
    }
    assert_eq!(got, want.to_vec());
}
