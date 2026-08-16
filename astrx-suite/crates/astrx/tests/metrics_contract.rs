//! The suite's `/metrics` contract, asserted across crate boundaries.
//!
//! `suitedash` is the only consumer of the other engines' `/metrics`, and it
//! reaches them over HTTP — so nothing inside an engine crate can tell whether
//! the body it produces is one `suitedash` can actually read. That gap is what
//! this file closes: every engine's real exposition goes through
//! [`suitedash::metrics::parse_metrics`], the exact function that parses it in
//! production, and the metric names `suitedash::config::default_services` asks
//! for are required to come back out.
//!
//! This is the test that would have caught the shipped bug: the default
//! configuration has always surfaced `websearch_searches_total`, and `websearch`
//! has never emitted it — the dashboard rendered a blank for that row and
//! nothing anywhere failed.
//!
//! `astrx` is the only crate that depends on all six, so it is the only place
//! this can be written.

#![cfg(feature = "net")]

use std::sync::{Arc, Mutex};

use suitedash::metrics::{parse_metrics, surface, MetricMap};

/// The `Content-Type` every engine serves `/metrics` with. Passed to
/// `parse_metrics` exactly as the prober would.
const PROM_CTYPE: &str = "text/plain; version=0.0.4; charset=utf-8";

fn parsed(body: &str) -> MetricMap {
    parse_metrics(body.as_bytes(), PROM_CTYPE)
}

fn assert_has(map: &MetricMap, body: &str, names: &[&str]) {
    for name in names {
        assert!(
            map.get(name).is_some(),
            "suitedash could not read {name} out of:\n{body}"
        );
    }
}

// ---------------------------------------------------------------------------
// Per-engine bodies
// ---------------------------------------------------------------------------

fn gitweb_metrics() -> String {
    let m = gitweb::metrics::Metrics::new();
    m.begin();
    m.end(200, "summary", 0.004);
    m.end(500, "log", 0.5);
    m.reject();
    m.render_prometheus()
}

fn websearch_metrics() -> String {
    let mut ix = websearch::Index::new();
    ix.upsert_document(
        "https://example.com/a",
        websearch::index::DocFields {
            title: "Title",
            body: "hello world",
            host: "example.com",
            fetched_at: 1_700_000_000.0,
            http_status: 200,
            ..websearch::index::DocFields::default()
        },
    );
    ix.finalize();
    let srv = websearch::SearchServer::new(Arc::new(Mutex::new(ix)), "http://127.0.0.1:8803");
    // Drive a search through the real registry so the counters are non-zero:
    // an all-zero exposition parses even when the series are wired up wrong.
    let reg = websearch::metrics::registry();
    reg.end(200, websearch::metrics::action_of("/search?q=hello"), 0.01);
    reg.end(
        200,
        websearch::metrics::action_of("/api/search?q=hello"),
        0.02,
    );
    reg.end(404, websearch::metrics::action_of("/nope"), 0.001);
    srv.route("GET", "/metrics").body
}

fn onioncrawler_metrics() -> String {
    let mut store = onioncrawler::store::Store::new();
    store.ensure_host("a.onion", 1.0);
    let srv = onioncrawler::serve::SearchServer::new(
        Arc::new(Mutex::new(store)),
        "http://127.0.0.1:8802",
    );
    let reg = onioncrawler::metrics::registry();
    reg.end(200, onioncrawler::metrics::action_of("/search"), 0.01);
    reg.end(503, onioncrawler::metrics::action_of("/api/search"), 0.2);
    let resp = srv.route("GET", "/metrics", "", None);
    String::from_utf8_lossy(&resp.body).into_owned()
}

fn torrentds_metrics() -> String {
    let store = torrentds::store::Store::new();
    let srv = torrentds::search::SearchServer::new(Arc::new(Mutex::new(store)), None, "");
    let reg = torrentds::metrics::registry();
    reg.end(200, torrentds::metrics::action_of("/search?q=x"), 0.03);
    reg.end(200, torrentds::metrics::action_of("/api/stats"), 0.001);
    srv.metrics_text()
}

fn suitedash_metrics() -> String {
    let mut results = suitedash::metrics::Results::new();
    let mut up = suitedash::metrics::ServiceResult::new("gitweb", "http://127.0.0.1:8801", true);
    up.latency_ms = Some(3.5);
    up.metrics_raw = "gitweb_requests_total 42\n".to_string();
    up.metrics_ctype = "text/plain".to_string();
    results.insert("gitweb", up);
    let reg = suitedash::exporter::registry();
    reg.end(200, suitedash::exporter::action_of("/"), 0.05);
    reg.end(200, suitedash::exporter::action_of("/api/status"), 0.04);
    suitedash::exporter::render_metrics_page(&results)
}

/// Every engine, by the name `suitedash` polls it under.
fn all_engines() -> Vec<(&'static str, String)> {
    vec![
        ("gitweb", gitweb_metrics()),
        ("onioncrawler", onioncrawler_metrics()),
        ("websearch", websearch_metrics()),
        ("torrentds", torrentds_metrics()),
        ("suitedash", suitedash_metrics()),
    ]
}

// ---------------------------------------------------------------------------
// The contract
// ---------------------------------------------------------------------------

#[test]
fn every_engine_serves_metrics_suitedash_can_parse() {
    for (engine, body) in all_engines() {
        assert!(!body.is_empty(), "{engine} served an empty /metrics");
        let map = parsed(&body);
        assert!(
            !map.is_empty(),
            "suitedash parsed ZERO metrics out of {engine}'s /metrics:\n{body}"
        );
        // Every series must be a finite number under a usable name — a body that
        // parses to a map full of dropped values is not an exposition anyone can
        // alert on.
        for key in map.keys() {
            let v = map.get(key).copied().expect("key came from the map");
            assert!(v.is_finite(), "{engine}: {key} is not finite");
        }
    }
}

#[test]
fn the_shared_request_block_is_present_and_identically_named_on_every_engine() {
    // The point of the shared block: one query works against any engine with
    // only the prefix changed. Before this, only gitweb had any of these.
    for (engine, body) in all_engines() {
        let map = parsed(&body);
        assert_has(
            &map,
            &body,
            &[
                &format!("{engine}_uptime_seconds"),
                &format!("{engine}_requests_total"),
                &format!("{engine}_requests_in_flight"),
                &format!("{engine}_request_latency_seconds_sum"),
                &format!("{engine}_request_latency_seconds_count"),
            ],
        );
    }
}

#[test]
fn every_metric_suitedash_is_configured_to_surface_actually_exists() {
    // The strongest form of the contract: take the shipped default
    // configuration, take each engine's real body, and require that every
    // configured key resolves to a number. A surfaced key with no value renders
    // as a blank cell on the dashboard and fires no alert — silence that looks
    // exactly like "nothing is wrong".
    let bodies: Vec<(&str, String)> = all_engines();
    for svc in suitedash::config::default_services() {
        // torrentds's default service points at its JSON `/api/stats`, not at
        // `/metrics`; that path is covered by `torrentds_json_stats_still_parse`
        // below.
        if svc.metrics_path != "/metrics" {
            continue;
        }
        let (_, body) = bodies
            .iter()
            .find(|(name, _)| *name == svc.name)
            .unwrap_or_else(|| panic!("no body for configured service {}", svc.name));
        let map = parsed(body);
        let surfaced = surface(&map, &svc.metrics_keys);
        for key in &svc.metrics_keys {
            assert!(
                surfaced.get(key).copied().flatten().is_some(),
                "suitedash's default config surfaces {key} for {}, but its /metrics does not \
                 emit it:\n{body}",
                svc.name
            );
        }
    }
}

#[test]
fn websearch_reports_the_searches_counter_the_dashboard_asks_for() {
    // Regression test for the shipped gap, pinned to a value rather than mere
    // presence: `websearch_searches_total` must move when searches happen.
    let before = parsed(&websearch_metrics())
        .get("websearch_searches_total")
        .copied()
        .expect("websearch_searches_total must exist");
    let reg = websearch::metrics::registry();
    reg.end(200, websearch::metrics::action_of("/search?q=again"), 0.01);
    let after = parsed(&websearch_metrics())
        .get("websearch_searches_total")
        .copied()
        .expect("websearch_searches_total must exist");
    assert!(
        after > before,
        "searches_total did not advance: {before} -> {after}"
    );

    // And a bare `/` hit is not a search — otherwise every uptime check inflates
    // the number an operator uses to spot "nobody can search any more".
    //
    // Asserted against a private registry, not the process-wide one: the tests
    // in this binary run in parallel and all of them touch the global, so an
    // exact-count claim about it would be flaky rather than wrong.
    let solo = crawlcore::metrics::Requests::new();
    solo.end(200, websearch::metrics::action_of("/"), 0.001);
    solo.end(200, websearch::metrics::action_of("/?q="), 0.001);
    assert_eq!(
        solo.action_count("search") + solo.action_count("api_search"),
        0
    );
    solo.end(200, websearch::metrics::action_of("/?q=cat"), 0.001);
    solo.end(
        200,
        websearch::metrics::action_of("/api/search?q=cat"),
        0.001,
    );
    assert_eq!(
        solo.action_count("search") + solo.action_count("api_search"),
        2
    );
}

#[test]
fn the_engines_index_gauges_keep_their_historical_names() {
    // These names predate the shared block and the dashboards key on them; the
    // shared block was appended, never substituted for them.
    let map = parsed(&websearch_metrics());
    assert_has(&map, "", &["websearch_docs", "websearch_hosts"]);

    let map = parsed(&onioncrawler_metrics());
    assert_has(
        &map,
        "",
        &[
            "onioncrawler_pages",
            "onioncrawler_hosts",
            "onioncrawler_frontier_queued",
        ],
    );

    let map = parsed(&torrentds_metrics());
    assert_has(
        &map,
        "",
        &[
            "torrentds_torrents",
            "torrentds_files",
            "torrentds_total_size",
            "torrentds_pending",
        ],
    );

    let map = parsed(&suitedash_metrics());
    assert_has(&map, "", &["suitedash_up"]);
}

#[test]
fn torrentds_json_stats_still_parse_under_the_keys_the_default_config_uses() {
    // The default configuration points torrentds at its JSON `/api/stats`, which
    // exercises `parse_metrics`'s JSON branch. Adding the Prometheus block must
    // not have disturbed it.
    let store = torrentds::store::Store::new();
    let stats = store.stats();
    let body = format!(
        "{{\"torrents\":{},\"pending\":{},\"total_size\":{}}}",
        stats.torrents, stats.pending, stats.total_size
    );
    let map = parse_metrics(body.as_bytes(), "application/json");
    assert_has(&map, &body, &["torrents", "pending", "total_size"]);
}

#[test]
fn a_scrape_of_an_untouched_engine_is_still_valid_prometheus() {
    // The first scrape after a restart hits an engine with no traffic at all. An
    // exposition that is only well-formed once a request has been served makes
    // the dashboard show a brand-new node as broken.
    let store = torrentds::store::Store::new();
    let srv = torrentds::search::SearchServer::new(Arc::new(Mutex::new(store)), None, "");
    let body = srv.metrics_text();
    for line in body.lines() {
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        let (name, value) = line
            .rsplit_once(' ')
            .unwrap_or_else(|| panic!("not a `name value` line: {line:?}"));
        assert!(
            !name.is_empty() && value.parse::<f64>().is_ok(),
            "not a valid sample: {line:?}"
        );
    }
    assert!(!parse_metrics(body.as_bytes(), PROM_CTYPE).is_empty());
}
