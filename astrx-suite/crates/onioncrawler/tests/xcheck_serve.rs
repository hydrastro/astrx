//! Byte-identical cross-check of the served HTML against the Python reference
//! (`legacy-python/onioncrawler/onioncrawler/search.py`).
//!
//! The goldens in `goldens/serve.rs` are rendered by the **real** Python
//! `SearchApp` (regenerate with `tests/regen_serve_goldens.py`); here the Rust
//! port renders the same requests over the mirror fixture and every ported
//! fragment is compared byte for byte.
//!
//! The two servers are not the same application — the Rust port adds
//! `/api/stats`, an OpenSearch `<link rel=search>`, a `?limit=` page size and
//! carries its own product name — so a whole-document comparison would pin
//! things that are deliberately different. What is pinned here is everything the
//! Python is the specification for: the stylesheet, the search form (query row +
//! `row filters` row), the facet row (`[:16]` truncation and filter-preserving
//! hrefs), the pager (`_qs`), the result row and the `.muted` / `<footer>` copy.
//!
//! Two deliberate divergences, both narrow:
//!
//! * `esc` renders `'` as `&#39;` where Python's `html.escape` renders `&#x27;`
//!   — both are correct HTML; no fixture here contains an apostrophe.
//! * `_clean_filters`'s `is_darknet_host` validation of the *host filter* is not
//!   ported (the Rust store admits arbitrary host strings, which its fixtures
//!   rely on), so a hostile host filter is escaped rather than dropped — see
//!   `serve::tests::hostile_query_and_filters_are_escaped`.

use std::sync::{Arc, Mutex};

use onioncrawler::serve::{MAX_PAGE, STYLE};
use onioncrawler::store::Store;
use onioncrawler::SearchServer;

include!("goldens/serve.rs");

/// The mirror fixture: 17 English pages on [`HOST`] and 8 German pages on
/// [`HOST2`], every one matching `widget`; only the first carries `emporium`.
fn server() -> SearchServer {
    const NOW: f64 = 1_700_000_000.0;
    let mut s = Store::new();
    s.ensure_host(HOST, NOW);
    s.ensure_host(HOST2, NOW);
    for i in 0..17 {
        let body = if i == 0 {
            "the widget emporium is the one that is in the shop and it is for the market"
                .to_string()
        } else {
            format!("the widget is the one that is in shop {i} and it is for the market")
        };
        let url = if i == 0 {
            format!("http://{HOST}/")
        } else {
            format!("http://{HOST}/{i}")
        };
        let title = if i == 0 {
            "Widget shop".to_string()
        } else {
            format!("Widget shop {i}")
        };
        s.store_page(
            &url,
            HOST,
            Some(&title),
            Some(&body),
            Some(&format!("en{i}")),
            Some(200),
            Some("text/html"),
            None,
            NOW,
            false,
            None,
            None,
            None,
        );
    }
    for i in 0..8 {
        s.store_page(
            &format!("http://{HOST2}/{i}"),
            HOST2,
            Some(&format!("Widget laden {i}")),
            Some(&format!(
                "das widget ist ein der die und mit von auf ist nicht im laden {i}"
            )),
            Some(&format!("de{i}")),
            Some(200),
            Some("text/html"),
            None,
            NOW,
            false,
            None,
            None,
            None,
        );
    }
    SearchServer::new(Arc::new(Mutex::new(s)), "")
}

fn render(srv: &SearchServer, target: &str) -> String {
    let r = srv.route("GET", target, "", None);
    assert_eq!(r.status, 200, "{target}");
    String::from_utf8_lossy(&r.body).to_string()
}

/// The `start`..`end` slice of `html`, inclusive — the same extraction the
/// generator performs on the Python output.
fn between(html: &str, start: &str, end: &str) -> String {
    let i = html
        .find(start)
        .unwrap_or_else(|| panic!("no {start:?} in\n{html}"));
    let j = html[i..]
        .find(end)
        .unwrap_or_else(|| panic!("no {end:?} after {start:?} in\n{html}"))
        + i
        + end.len();
    html[i..j].to_string()
}

fn golden<'a>(table: &'a [(&str, &str)], label: &str) -> &'a str {
    table
        .iter()
        .find(|(l, _)| *l == label)
        .unwrap_or_else(|| panic!("no golden {label:?}"))
        .1
}

#[test]
fn stylesheet_matches_python_byte_for_byte() {
    assert_eq!(STYLE, PY_CSS);
    assert_eq!(MAX_PAGE, PY_MAX_PAGE);
}

#[test]
fn search_form_matches_python_byte_for_byte() {
    let srv = server();
    for (label, target) in [
        ("empty", "/search".to_string()),
        ("query-only", "/search?q=widget".to_string()),
        (
            "all-filters",
            format!("/search?q=widget&host={HOST}&lang=de&since=2024-01-02&until=2024-03-04"),
        ),
        (
            "hostile",
            "/search?q=%22%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E&lang=%3Cb%3E\
&since=%22onmouseover%3D%22x&until=%3Cscript%3E"
                .to_string(),
        ),
    ] {
        let got = between(&render(&srv, &target), "<form ", "</form>");
        assert_eq!(got, golden(PY_FORMS, label), "form {label}");
    }
}

#[test]
fn facet_row_matches_python_byte_for_byte() {
    let srv = server();
    for (label, target) in [
        ("no-filters", "/search?q=widget".to_string()),
        (
            "host-filtered",
            format!("/search?q=widget&host={HOST}&since=2023-01-01&until=2038-01-01"),
        ),
    ] {
        let got = between(&render(&srv, &target), "<div class=facets>", "</div>");
        assert_eq!(got, golden(PY_FACETS, label), "facets {label}");
    }
}

#[test]
fn result_row_and_page_furniture_match_python_byte_for_byte() {
    let srv = server();
    let page = render(&srv, "/search?q=emporium");
    for (label, start, end) in [
        ("result", "<div class=result>", "</div></div>"),
        ("window", "<p class=muted>Results", "</p>"),
        ("nav-empty", "<div class=nav>", "</div>"),
        ("footer", "<footer>", "</footer>"),
    ] {
        assert_eq!(
            between(&page, start, end),
            golden(PY_PAGE, label),
            "{label}"
        );
    }
    for (label, target) in [
        ("no-results", "/search?q=nothingmatchesthisatall"),
        ("landing", "/search"),
    ] {
        assert_eq!(
            between(&render(&srv, target), "<p class=muted>", "</p>"),
            golden(PY_PAGE, label),
            "{label}"
        );
    }
}

#[test]
fn pager_links_match_python_byte_for_byte() {
    // page 2 of the 25-match window, with a date range in force: both links,
    // each re-emitting `since` and `until` through the ported `_qs`
    let page = render(
        &server(),
        "/search?q=widget&since=2023-01-01&until=2038-01-01&page=2",
    );
    assert_eq!(
        between(&page, "<p class=muted>Results", "</p>"),
        golden(PY_PAGE, "window-paged")
    );
    assert_eq!(
        between(&page, "<div class=nav>", "</div>"),
        golden(PY_PAGE, "nav-paged")
    );
}
