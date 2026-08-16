//! This process's request counters, and the route → action classifier that
//! bounds their label cardinality.
//!
//! Before this existed, `onioncrawler`'s `/metrics` was the store's index gauges and nothing else. Nothing anywhere
//! reported how many requests the server was handling, how long they took, or
//! how many of them were failing — so "is it slow, or is it down?" had no answer
//! short of reading the access log, which this engine did not have either.
//!
//! The counters live in [`crawlcore::metrics::Requests`], shared with the other
//! engines so every engine's `/metrics` uses the same names with only the prefix
//! changed.

use std::sync::OnceLock;

pub use crawlcore::metrics::Requests;

/// The metric-name prefix for this engine.
pub const PREFIX: &str = "onioncrawler";

/// The process-wide registry, shared by the accept loop and `/metrics`.
///
/// Per-crate rather than a single global in `crawlcore`: the `astrx` binary
/// links all five engines into one process, and one shared registry would file
/// another engine's traffic under `onioncrawler_requests_total`.
#[must_use]
pub fn registry() -> &'static Requests {
    static REG: OnceLock<Requests> = OnceLock::new();
    REG.get_or_init(Requests::new)
}

/// Classify a request path into a stable action label.
///
/// Returns a `&'static str` by construction, which is what keeps
/// `onioncrawler_action_total`'s cardinality finite: the label can never be a
/// fragment of the request, however the peer crafts the path.
#[must_use]
pub fn action_of(path: &str) -> &'static str {
    let path = path.split(['?', '#']).next().unwrap_or(path);
    match path {
        "/" | "/search" => "search",
        "/api/search" => "api_search",
        "/find" => "find",
        "/api/find" => "api_find",
        "/stats" => "stats",
        "/api/stats" => "api_stats",
        "/cached" => "cached",
        "/add" => "add",
        "/health" | "/healthz" => "health",
        "/metrics" => "metrics",
        "/robots.txt" => "robots",
        "/opensearch.xml" => "opensearch",
        _ => "other",
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn actions_are_stable_and_unknown_paths_fall_back_to_other() {
        assert_eq!(action_of("/search?q=x"), "search");
        assert_eq!(action_of("/api/find?host=a.onion"), "api_find");
        assert_eq!(action_of("/cached?url=http%3A%2F%2Fa.onion%2F"), "cached");
        assert_eq!(action_of("/health"), "health");
        assert_eq!(action_of("/../../etc/passwd"), "other");
        assert_eq!(action_of("/wp-login.php"), "other");
        // A query string or fragment never changes the action, so a peer cannot
        // mint label cardinality by varying it.
        assert_eq!(action_of("/metrics?x=1"), "metrics");
        assert_eq!(action_of("/metrics#f"), "metrics");
    }
}
