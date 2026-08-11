//! Cross-check: the Rust `websearch::frontier::Frontier` reproduces the Python
//! `websearch.frontier.Frontier` (SQLite-backed) — the lease ordering
//! (depth, then insertion), per-host politeness gating, the missing-host
//! `LEFT JOIN` fallback, host budgets, `reclaim`, `complete`, `counts`,
//! `next_ready_time`, and the robots cache. Goldens emitted by
//! `tests/regen_goldens.py` (the `gen_frontier` section), driven with a
//! deterministic clock so `added_at` equals insertion order.

use std::collections::BTreeMap;
use websearch::frontier::Frontier;

fn lease_url(f: &mut Frontier, now: f64, budget: Option<u64>) -> Option<String> {
    f.lease(now, 120.0, budget).map(|l| l.url)
}

#[test]
fn frontier_matches_python() {
    let mut f = Frontier::new();

    // adds (added_at = insertion order)
    assert!(f.add("http://a/1", "a", 0)); // add_a1 True
    assert!(f.add("http://a/2", "a", 1)); // add_a2 True
    assert!(f.add("http://b/1", "b", 0)); // add_b1 True
    assert!(!f.add("http://a/1", "a", 0)); // add_a1_dup False
    assert!(f.seen("http://a/1")); // seen_a1 True
    assert!(!f.seen("http://z")); // seen_z False

    // lease1000 → a/1 (fallback creates host a), then hold a
    assert_eq!(
        lease_url(&mut f, 1000.0, None).as_deref(),
        Some("http://a/1")
    );
    f.note_fetch("a", 1100.0);
    // lease1000b → b/1 (fallback creates host b), then hold b
    assert_eq!(
        lease_url(&mut f, 1000.0, None).as_deref(),
        Some("http://b/1")
    );
    f.note_fetch("b", 1100.0);
    // lease1000none → None (a,b held; a/2's host a held)
    assert_eq!(lease_url(&mut f, 1000.0, None), None);
    // nrt → 1100
    assert_eq!(f.next_ready_time(None), Some(1100.0));
    // lease1200 → a/2 (a now ready), then hold a again
    assert_eq!(
        lease_url(&mut f, 1200.0, None).as_deref(),
        Some("http://a/2")
    );
    f.note_fetch("a", 1300.0);

    // complete a/1 done, b/1 error
    f.complete("http://a/1", "done", None);
    f.complete("http://b/1", "error", Some("boom"));
    assert_eq!(f.total_done(), 2); // total_done
    assert!(!f.has_queued()); // has_queued False (a/2 leased)
    let counts: BTreeMap<String, usize> = f.counts();
    assert_eq!(counts.get("done"), Some(&1));
    assert_eq!(counts.get("error"), Some(&1));
    assert_eq!(counts.get("leased"), Some(&1));
    assert_eq!(counts.get("queued"), None);

    // reclaim@1400: a/2 lease_until 1320 < 1400 → back to queued
    f.reclaim(1400.0);
    assert!(f.has_queued()); // has_queued2 True
    let counts2 = f.counts();
    assert_eq!(counts2.get("done"), Some(&1));
    assert_eq!(counts2.get("error"), Some(&1));
    assert_eq!(counts2.get("queued"), Some(&1));
    assert_eq!(counts2.get("leased"), None);

    // budget gating: a.fetched=2 → budget 2 refuses, budget 3 allows a/2
    assert_eq!(lease_url(&mut f, 2000.0, Some(2)), None);
    assert_eq!(
        lease_url(&mut f, 2000.0, Some(3)).as_deref(),
        Some("http://a/2")
    );

    // robots cache
    assert_eq!(f.cache_get("r:a"), None);
    f.cache_set("r:a", "UA: *");
    assert_eq!(f.cache_get("r:a").as_deref(), Some("UA: *"));

    // host row for a: next_time 1300, no crawl_delay, robots not done, fetched 2
    let hr = f.host_row("a");
    assert_eq!(hr.next_time, 1300.0);
    assert_eq!(hr.crawl_delay, None);
    assert!(!hr.robots_done);
    assert_eq!(hr.fetched, 2);
}
