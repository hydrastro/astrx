//! Unit tests for the store state machine — the frontier admission codes, lease
//! ordering + host parking, page dedup outcomes, liveness transitions, dead-onion
//! aging, and the snapshot round-trip. The deterministic analytics (PageRank,
//! clustering) are additionally cross-checked against Python in
//! `tests/xcheck_store.rs`.

use super::*;

/// Build a canonical URL directly (bypassing the parser) for store-logic tests.
fn canon(host: &str, path: &str) -> CanonicalUrl {
    CanonicalUrl {
        url: format!("http://{host}{path}"),
        scheme: "http".to_string(),
        host: host.to_string(),
        port: None,
        path: path.to_string(),
        query: String::new(),
    }
}

const H: &str = "site.onion";

#[test]
fn enqueue_ok_then_dup() {
    let mut s = Store::new();
    assert_eq!(
        s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true),
        Enqueued::Ok
    );
    assert_eq!(s.counter("urls_enqueued"), 1);
    // same URL again → dup-url, counter unchanged
    assert_eq!(
        s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true),
        Enqueued::DupUrl
    );
    assert_eq!(s.counter("urls_enqueued"), 1);
    assert_eq!(s.get_host(H).unwrap().enq_count, 1);
}

#[test]
fn enqueue_unique_budget_before_host_row() {
    let mut s = Store::new();
    let caps = Caps {
        max_unique_urls: Some(1),
        ..Caps::default()
    };
    assert_eq!(
        s.enqueue(&canon(H, "/a"), 0, 0, caps, 1.0, false),
        Enqueued::Ok
    );
    // budget now reached; a distinct new host must not even get a host row
    assert_eq!(
        s.enqueue(&canon("other.onion", "/b"), 0, 0, caps, 1.0, false),
        Enqueued::UniqueBudget
    );
    assert!(s.get_host("other.onion").is_none());
}

#[test]
fn enqueue_host_and_template_and_skeleton_caps() {
    let mut s = Store::new();
    // host budget
    let caps = Caps {
        max_pages_per_host: Some(1),
        ..Caps::default()
    };
    assert_eq!(
        s.enqueue(&canon(H, "/a"), 0, 0, caps, 1.0, false),
        Enqueued::Ok
    );
    assert_eq!(
        s.enqueue(&canon(H, "/b"), 0, 0, caps, 1.0, false),
        Enqueued::HostBudget
    );

    // template cap: same path repeated is the same template
    let mut s = Store::new();
    let caps = Caps {
        max_urls_per_template: Some(1),
        ..Caps::default()
    };
    assert_eq!(
        s.enqueue(&canon(H, "/p?x=1"), 0, 0, caps, 1.0, false),
        Enqueued::Ok
    );
    // a different URL, same template key (host+path+query-keys) → template-cap
    let mut c = canon(H, "/p");
    c.url = "http://site.onion/p?x=2".to_string();
    c.query = "x=2".to_string();
    let mut c1 = canon(H, "/p");
    c1.url = "http://site.onion/p?x=1".to_string();
    c1.query = "x=1".to_string();
    // (first insert used /p?x=1 literal path; re-do cleanly)
    let mut s = Store::new();
    assert_eq!(s.enqueue(&c1, 0, 0, caps, 1.0, false), Enqueued::Ok);
    assert_eq!(s.enqueue(&c, 0, 0, caps, 1.0, false), Enqueued::TemplateCap);

    // skeleton cap: numeric path segments collapse to the same skeleton
    let mut s = Store::new();
    let caps = Caps {
        max_urls_per_skeleton: Some(1),
        ..Caps::default()
    };
    assert_eq!(
        s.enqueue(&canon(H, "/post/1"), 0, 0, caps, 1.0, false),
        Enqueued::Ok
    );
    assert_eq!(
        s.enqueue(&canon(H, "/post/2"), 0, 0, caps, 1.0, false),
        Enqueued::SkeletonCap
    );
}

#[test]
fn force_bypasses_caps() {
    let mut s = Store::new();
    let caps = Caps {
        max_pages_per_host: Some(1),
        max_urls_per_template: Some(1),
        max_urls_per_skeleton: Some(1),
        max_unique_urls: Some(1),
    };
    assert_eq!(
        s.enqueue(&canon(H, "/post/1"), 0, 0, caps, 1.0, true),
        Enqueued::Ok
    );
    assert_eq!(
        s.enqueue(&canon(H, "/post/2"), 0, 0, caps, 1.0, true),
        Enqueued::Ok
    );
    assert_eq!(s.get_host(H).unwrap().enq_count, 2);
}

#[test]
fn enqueue_refuses_inactive_host() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    s.set_host_state(H, "blocked", Some("x"));
    // even a forced seed cannot create a frontier row on an inactive host
    assert_eq!(
        s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true),
        Enqueued::HostDead
    );
}

#[test]
fn add_seed_revives_dead_host() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    if let Some(h) = s.hosts.get_mut(H) {
        h.state = "dead".to_string();
        h.up = false;
    }
    assert_eq!(
        s.add_seed(&canon(H, "/a"), 0, 0, Caps::default(), 2.0, true),
        Enqueued::Ok
    );
    // (add_seed uses the same (…, caps, now, force) order as enqueue)
    let h = s.get_host(H).unwrap();
    assert_eq!(h.state, "active");
    assert!(h.up);
}

#[test]
fn lease_orders_and_parks_host() {
    let mut s = Store::new();
    // three URLs on distinct hosts so per-host parking doesn't block them
    s.enqueue(&canon("a.onion", "/1"), 5, 2, Caps::default(), 1.0, true); // id 1
    s.enqueue(&canon("b.onion", "/2"), 0, 1, Caps::default(), 1.0, true); // id 2 — best (priority 1)
    s.enqueue(&canon("c.onion", "/3"), 0, 2, Caps::default(), 1.0, true); // id 3
    let l = s.lease(100.0, 300.0).expect("lease");
    assert_eq!(l.host, "b.onion"); // lowest priority wins
    assert_eq!(l.tries, 1);
    // its host is parked until the lease expires
    assert_eq!(s.get_host("b.onion").unwrap().next_allowed, 400.0);
    // leftover pick: (priority 2, depth 0, id 3) beats (priority 2, depth 5, id 1)
    let l2 = s.lease(100.0, 300.0).expect("lease2");
    assert_eq!(l2.host, "c.onion");
}

#[test]
fn lease_skips_parked_and_inactive_hosts() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true);
    s.set_next_allowed(H, 1000.0); // parked into the future
    assert!(s.lease(100.0, 300.0).is_none());
    // once the park window passes, it leases
    assert!(s.lease(1000.0, 300.0).is_some());
}

#[test]
fn reclaim_expired_and_all() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true);
    let l = s.lease(100.0, 50.0).unwrap(); // lease_expires = 150
    assert_eq!(s.pending_summary(100.0), (0, 1));
    // not yet expired
    assert_eq!(s.reclaim_expired(140.0), 0);
    // expired → back to queued
    assert_eq!(s.reclaim_expired(200.0), 1);
    // lease again then graceful reclaim-all
    let _ = s.lease(200.0, 5000.0);
    assert_eq!(s.reclaim_all_leased(), 1);
    let _ = l;
}

#[test]
fn mark_error_truncates_and_done_terminal() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true);
    let l = s.lease(1.0, 300.0).unwrap();
    let long = "e".repeat(1000);
    s.mark_error(l.id, &long);
    let row = s.frontier.get(&l.id).unwrap();
    assert_eq!(row.status, "error");
    assert_eq!(row.last_error.as_ref().unwrap().len(), 500);
}

#[test]
fn set_host_state_dead_letters_queued() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true);
    s.enqueue(&canon(H, "/b"), 0, 0, Caps::default(), 1.0, true);
    s.set_host_state(H, "trapped", Some("loop"));
    for r in s.frontier.values() {
        assert_eq!(r.status, "error");
        assert_eq!(r.last_error.as_deref(), Some("host-trapped:loop"));
    }
}

#[test]
fn store_page_stored_updated_unchanged_duplicate() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    // stored
    assert_eq!(
        s.store_page(
            "http://site.onion/a",
            H,
            Some("T"),
            Some("hello world text"),
            Some("h1"),
            Some(200),
            Some("text/html"),
            Some(16),
            10.0,
            true,
            None,
            None,
            None
        ),
        StoreOutcome::Stored
    );
    assert_eq!(s.get_host(H).unwrap().pages_count, 1);
    assert_eq!(s.counter("pages_stored"), 1);
    let p = s.get_page("http://site.onion/a").unwrap();
    assert!(p.simhash.unwrap() != 0);
    assert!(p.lang.is_some());

    // same url, same hash → unchanged
    assert_eq!(
        s.store_page(
            "http://site.onion/a",
            H,
            Some("T"),
            Some("hello world text"),
            Some("h1"),
            Some(200),
            Some("text/html"),
            Some(16),
            20.0,
            true,
            None,
            None,
            None
        ),
        StoreOutcome::Unchanged
    );
    // same url, new hash → updated (cluster_id reset)
    assert_eq!(
        s.store_page(
            "http://site.onion/a",
            H,
            Some("T2"),
            Some("different body"),
            Some("h2"),
            Some(200),
            Some("text/html"),
            Some(16),
            30.0,
            true,
            None,
            None,
            None
        ),
        StoreOutcome::Updated
    );
    // a NEW url whose content_hash was already *stored* → duplicate, no page.
    // (Note h1, not h2: the update path never adds to seen_hashes — only fresh
    // inserts do — exactly as the Python reference, so h2 was never recorded.)
    assert_eq!(
        s.store_page(
            "http://site.onion/b",
            H,
            Some("T"),
            Some("hello world text"),
            Some("h1"),
            Some(200),
            Some("text/html"),
            Some(16),
            40.0,
            true,
            None,
            None,
            None
        ),
        StoreOutcome::Duplicate
    );
    assert_eq!(s.get_host(H).unwrap().dup_count, 1);
    assert_eq!(s.page_count(), 1);
}

#[test]
fn store_page_indexes_entities() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    // a famous legacy BTC address should be extracted + indexed
    let text = "donate to 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2 today";
    s.store_page(
        "http://site.onion/x",
        H,
        None,
        Some(text),
        Some("h"),
        Some(200),
        None,
        None,
        1.0,
        true,
        None,
        None,
        None,
    );
    let hits = s.find_by_entity("btc", "1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2", 10, 0);
    assert_eq!(hits.len(), 1);
    assert_eq!(hits[0].url, "http://site.onion/x");
    assert_eq!(*s.entity_counts().get("btc").unwrap(), 1usize);
}

#[test]
fn touch_page_grows_interval() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    s.store_page(
        "http://site.onion/a",
        H,
        None,
        Some("body"),
        Some("h"),
        Some(200),
        None,
        None,
        1.0,
        true,
        None,
        None,
        Some(100.0),
    );
    // grow ×2, cap 150
    s.touch_page("http://site.onion/a", 5.0, 2.0, 150.0, 0.0);
    assert_eq!(
        s.get_page("http://site.onion/a").unwrap().recrawl_interval,
        Some(150.0)
    );
    assert_eq!(
        s.get_page("http://site.onion/a").unwrap().last_seen,
        Some(5.0)
    );
}

#[test]
fn liveness_down_then_up_transitions() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    assert!(!s.record_fetch_down(H, 2.0, 3)); // cf=1
    assert!(!s.record_fetch_down(H, 3.0, 3)); // cf=2
    assert!(s.record_fetch_down(H, 4.0, 3)); // cf=3 → down
    assert!(!s.get_host(H).unwrap().up);
    // recovery
    assert!(s.record_fetch_up(H, 5.0));
    assert!(s.get_host(H).unwrap().up);
    let hist = s.uptime_history(H, 10);
    assert_eq!(hist.len(), 2);
    assert_eq!(hist[0], (5.0, true)); // newest first
    assert_eq!(hist[1], (4.0, false));
}

#[test]
fn age_dead_hosts_demotes_and_dead_letters() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true);
    // knock the host down
    for t in 0..3 {
        s.record_fetch_down(H, t as f64, 3);
    }
    assert!(!s.get_host(H).unwrap().up);
    // two aging cycles at threshold 2 → demote
    assert_eq!(s.age_dead_hosts(2, 100.0), 0); // down_recrawls → 1
    assert_eq!(s.age_dead_hosts(2, 200.0), 1); // → 2 >= 2, demoted
    assert_eq!(s.get_host(H).unwrap().state, "dead");
    // its queued URL was dead-lettered
    assert!(s.frontier.values().all(|r| r.status == "error"));
}

#[test]
fn link_graph_authority_ranks_hub_higher() {
    let mut s = Store::new();
    for h in ["hub.onion", "a.onion", "b.onion", "c.onion"] {
        s.ensure_host(h, 1.0);
    }
    // everyone links to the hub
    s.add_link_edge("a.onion", "hub.onion", 1);
    s.add_link_edge("b.onion", "hub.onion", 1);
    s.add_link_edge("c.onion", "hub.onion", 1);
    s.add_link_edge("hub.onion", "hub.onion", 1); // self-link ignored
    assert_eq!(s.compute_authority(20, 0.85), 4);
    let hub = s.get_host("hub.onion").unwrap().authority;
    let leaf = s.get_host("a.onion").unwrap().authority;
    assert!((hub - 1.0).abs() < 1e-9); // normalized max
    assert!(hub > leaf);
}

#[test]
fn cluster_mirrors_groups_near_duplicates() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    let base = "the quick brown fox jumps over the lazy dog in the meadow every morning";
    s.store_page(
        "http://site.onion/1",
        H,
        None,
        Some(base),
        Some("h1"),
        Some(200),
        None,
        None,
        1.0,
        false,
        None,
        None,
        None,
    );
    // near-identical: one word changed
    let near = base.replace("meadow", "field");
    s.store_page(
        "http://site.onion/2",
        H,
        None,
        Some(&near),
        Some("h2"),
        Some(200),
        None,
        None,
        1.0,
        false,
        None,
        None,
        None,
    );
    // totally different
    s.store_page(
        "http://site.onion/3",
        H,
        None,
        Some("completely unrelated content about finance and markets"),
        Some("h3"),
        Some(200),
        None,
        None,
        1.0,
        false,
        None,
        None,
        None,
    );
    let clusters = s.cluster_mirrors(5, 1000);
    let c1 = s.get_page("http://site.onion/1").unwrap().cluster_id;
    let c2 = s.get_page("http://site.onion/2").unwrap().cluster_id;
    assert_eq!(c1, c2); // the two near-dups share a cluster
    assert_eq!(clusters, 1);
}

#[test]
fn reseed_requeues_settled_and_refuses_inactive() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/root"), 0, 0, Caps::default(), 1.0, true);
    let l = s.lease(1.0, 300.0).unwrap();
    s.mark_done(l.id);
    // reseed flips the done row back to queued
    assert_eq!(
        s.reseed_url(&canon(H, "/root"), Caps::default(), 5.0, true),
        Reseed::Requeued
    );
    assert_eq!(s.frontier.get(&l.id).unwrap().status, "queued");
    // block the host: reseed now refuses
    s.set_host_state(H, "blocked", Some("x"));
    assert_eq!(
        s.reseed_url(&canon(H, "/root"), Caps::default(), 6.0, true),
        Reseed::HostDead
    );
    // a brand-new curated root enqueues
    let mut s2 = Store::new();
    assert_eq!(
        s2.reseed_url(&canon(H, "/new"), Caps::default(), 1.0, true),
        Reseed::Enqueue(Enqueued::Ok)
    );
}

#[test]
fn reap_and_recrawl_scheduling() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/never"), 0, 0, Caps::default(), 1.0, true); // tries=0, enqueued_at=1
                                                                      // reaped once older than ttl and never attempted
    assert_eq!(s.reap_unverified(10.0, 100.0), 1);
    assert_eq!(s.frontier.len(), 0);

    // recrawl due: a done row whose page is past its interval requeues
    let mut s = Store::new();
    s.enqueue(&canon(H, "/p"), 0, 0, Caps::default(), 1.0, true);
    let l = s.lease(1.0, 300.0).unwrap();
    s.mark_done(l.id);
    s.store_page(
        "http://site.onion/p",
        H,
        None,
        Some("b"),
        Some("h"),
        Some(200),
        None,
        None,
        10.0,
        true,
        None,
        None,
        Some(50.0),
    );
    assert_eq!(s.mark_recrawl_due(40.0, 0.0), 0); // 10+50=60 > 40 → not due
    assert_eq!(s.mark_recrawl_due(100.0, 0.0), 1); // due → requeued
    assert_eq!(s.frontier.get(&l.id).unwrap().status, "queued");
}

#[test]
fn purge_host_removes_pages_and_blocks() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    s.store_page(
        "http://site.onion/a",
        H,
        None,
        Some("x"),
        Some("h"),
        Some(200),
        None,
        None,
        1.0,
        true,
        None,
        None,
        None,
    );
    s.enqueue(&canon(H, "/b"), 0, 0, Caps::default(), 1.0, true);
    assert_eq!(s.purge_host(H), 1);
    assert_eq!(s.page_count(), 0);
    assert_eq!(s.get_host(H).unwrap().state, "blocked");
    assert!(s.frontier.values().all(|r| r.status == "error"));
}

#[test]
fn metrics_gauges() {
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    s.enqueue(&canon(H, "/a"), 0, 0, Caps::default(), 1.0, true);
    s.store_page(
        "http://site.onion/a",
        H,
        None,
        Some("x"),
        Some("h"),
        Some(200),
        None,
        None,
        1.0,
        true,
        None,
        None,
        None,
    );
    let m = s.metrics();
    assert_eq!(m["frontier_queued"], 1);
    assert_eq!(m["pages"], 1);
    assert_eq!(m["hosts"], 1);
    assert_eq!(m["hosts_active"], 1);
}

#[test]
fn snapshot_round_trips() {
    let mut s = Store::new();
    s.enqueue(&canon(H, "/a"), 1, 0, Caps::default(), 1.0, true);
    let l = s.lease(2.0, 300.0).unwrap();
    s.mark_done(l.id);
    s.store_page(
        "http://site.onion/a",
        H,
        Some("Title"),
        Some("donate 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2"),
        Some("h1"),
        Some(200),
        Some("text/html"),
        Some(42),
        10.0,
        true,
        Some("etag"),
        Some("mod"),
        Some(60.0),
    );
    s.add_link_edge("a.onion", H, 3);
    s.record_fetch_down(H, 5.0, 3);
    s.log_trap(H, "http://site.onion/trap", "loop", 6.0);
    s.compute_authority(10, 0.85);

    let blob = s.snapshot();
    let restored = Store::restore(&blob).expect("restore ok");
    // deterministic snapshot ⇒ identical bytes ⇒ identical logical state
    assert_eq!(restored.snapshot(), blob);
    assert_eq!(
        restored
            .get_page("http://site.onion/a")
            .unwrap()
            .etag
            .as_deref(),
        Some("etag")
    );
    assert_eq!(restored.counter("pages_stored"), 1);
    assert_eq!(
        restored
            .find_by_entity("btc", "1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2", 5, 0)
            .len(),
        1
    );
    // corrupt blob → None, never a panic
    assert!(Store::restore(&blob[..blob.len() - 3]).is_none());
    assert!(Store::restore(&[]).is_none());
    assert!(Store::restore(&[99]).is_none());
}

/// A snapshot prefix that is valid up to the entity section, whose per-page
/// entity count is then whatever the caller says.
fn blob_with_entity_count(count: usize) -> Vec<u8> {
    let mut w = super::codec::Writer::new();
    w.u8(SNAPSHOT_VERSION);
    w.len(0); // meta
    w.i64(1); // next_frontier_id
    w.i64(1); // next_page_id
    w.len(0); // hosts
    w.len(0); // frontier
    w.len(0); // pages
    w.len(1); // entities: one page's list …
    w.i64(7); // … for page id 7 …
    w.len(count); // … claiming this many (key, value) pairs
    w.into_bytes()
}

#[test]
fn a_hostile_entity_count_is_refused_before_it_is_reserved() {
    // 65 bytes of file claiming 2^64-1 entity pairs. `Vec::with_capacity` on a
    // count read straight from the blob turns that into a request for
    // `usize::MAX * 48` bytes — a capacity-overflow panic that unwinds out of
    // `restore`, which is documented (and relied on by `read_store`) to answer
    // `None` for any corrupt input instead. A count of one *fits*, so the check
    // has to be against the bytes actually left, not a fixed ceiling.
    let blob = blob_with_entity_count(usize::MAX);
    assert!(blob.len() < 128);
    assert!(Store::restore(&blob).is_none());

    // and the tight bound: each pair is two length-prefixed strings, so 16
    // bytes minimum — one pair needs 16 bytes that are not there either.
    assert!(Store::restore(&blob_with_entity_count(1)).is_none());
    assert!(Store::restore(&blob_with_entity_count(1_000_000_000)).is_none());

    // a genuine snapshot with real entities still restores
    let mut s = Store::new();
    s.ensure_host(H, 1.0);
    s.store_page(
        "http://site.onion/a",
        H,
        Some("t"),
        Some("pay 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2 now"),
        Some("h"),
        Some(200),
        Some("text/html"),
        None,
        10.0,
        false,
        None,
        None,
        None,
    );
    let good = s.snapshot();
    assert_eq!(
        Store::restore(&good)
            .expect("valid snapshot")
            .find_by_entity("btc", "1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2", 5, 0)
            .len(),
        1
    );
}
