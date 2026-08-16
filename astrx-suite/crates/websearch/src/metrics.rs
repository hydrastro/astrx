//! This process's request counters, and the route → action classifier that
//! bounds their label cardinality.
//!
//! Before this existed, `websearch`'s `/metrics` had exactly two series
//! (`websearch_docs`, `websearch_hosts`) — both index gauges. Nothing anywhere
//! reported how many requests the server was handling or how many of them were
//! failing, and `suitedash`'s own default configuration asks for
//! `websearch_searches_total` (see `suitedash::config::default_services`), a
//! metric that did not exist: the dashboard has been rendering a permanent `—`
//! for it. Both gaps are closed here.
//!
//! The counters live in [`crawlcore::metrics::Requests`], shared with the other
//! engines so every engine's `/metrics` uses the same names with only the prefix
//! changed.

use std::sync::OnceLock;

pub use crawlcore::metrics::Requests;

/// The metric-name prefix for this engine (`websearch_docs`, …).
pub const PREFIX: &str = "websearch";

/// The process-wide registry, shared by the accept loop and `/metrics`.
///
/// Per-crate rather than a single global in `crawlcore`: the `astrx` binary
/// links all five engines into one process, and one shared registry would file
/// gitweb's traffic under `websearch_requests_total`.
#[must_use]
pub fn registry() -> &'static Requests {
    static REG: OnceLock<Requests> = OnceLock::new();
    REG.get_or_init(Requests::new)
}

/// Classify a raw request target into a stable action label.
///
/// Returns a `&'static str` by construction, which is what keeps
/// `websearch_action_total`'s cardinality finite: the label can never be a
/// fragment of the request, however the peer crafts the path.
#[must_use]
pub fn action_of(target: &str) -> &'static str {
    let (path, query) = match target.split_once('?') {
        Some((p, q)) => (p, q),
        None => (target, ""),
    };
    let path = path.split('#').next().unwrap_or(path);
    match path {
        // `/` renders the search page whether or not anything was searched for;
        // counting a bare landing hit as a search would inflate
        // `websearch_searches_total` with every health-check and crawler visit.
        "/" if !has_query_term(query) => "home",
        "/" | "/search" => "search",
        "/api/search" => "api_search",
        "/suggest" => "suggest",
        "/images" => "images",
        "/videos" => "videos",
        "/about" | "/stats" => "stats",
        "/opensearch.xml" => "opensearch",
        "/metrics" => "metrics",
        "/healthz" => "health",
        "/style.css" => "style",
        "/favicon.ico" => "favicon",
        _ => "other",
    }
}

/// Whether the query string carries a non-empty `q=`.
fn has_query_term(query: &str) -> bool {
    query
        .split('&')
        .filter_map(|kv| kv.split_once('='))
        .any(|(k, v)| k == "q" && !v.is_empty())
}

/// Total searches served — the bare alias `suitedash`'s default configuration
/// asks for, covering both the HTML and the JSON search paths.
#[must_use]
pub fn searches_total() -> u64 {
    let r = registry();
    r.action_count("search") + r.action_count("api_search")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn actions_cover_every_route_and_default_to_other() {
        assert_eq!(action_of("/search?q=cat"), "search");
        assert_eq!(action_of("/api/search?q=cat&page=2"), "api_search");
        assert_eq!(action_of("/suggest?q=c"), "suggest");
        assert_eq!(action_of("/stats"), "stats");
        assert_eq!(action_of("/metrics"), "metrics");
        assert_eq!(action_of("/healthz"), "health");
        assert_eq!(action_of("/../../etc/passwd"), "other");
        assert_eq!(action_of("/wp-admin.php"), "other");
    }

    #[test]
    fn a_bare_landing_hit_is_not_counted_as_a_search() {
        // Health checkers, uptime robots and crawlers all hit `/`. Counting
        // those as searches makes `websearch_searches_total` a traffic counter,
        // and the graph an operator uses to spot "nobody can search any more"
        // stops moving for the wrong reason.
        assert_eq!(action_of("/"), "home");
        assert_eq!(action_of("/?"), "home");
        assert_eq!(action_of("/?q="), "home");
        assert_eq!(action_of("/?page=2"), "home");
        assert_eq!(action_of("/?q=cat"), "search");
        assert_eq!(action_of("/?page=2&q=cat"), "search");
    }

    #[test]
    fn a_fragment_or_repeated_question_mark_does_not_change_the_action() {
        assert_eq!(action_of("/search#frag"), "search");
        assert_eq!(action_of("/search?q=a?b"), "search");
    }
}
