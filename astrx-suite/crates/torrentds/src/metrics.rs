//! This process's request counters, and the route → action classifier that
//! bounds their label cardinality.
//!
//! Before this existed, `torrentds`'s `/metrics` was the store's index gauges and nothing else. Nothing anywhere
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
pub const PREFIX: &str = "torrentds";

/// The process-wide registry, shared by the accept loop and `/metrics`.
///
/// Per-crate rather than a single global in `crawlcore`: the `astrx` binary
/// links all five engines into one process, and one shared registry would file
/// another engine's traffic under `torrentds_requests_total`.
#[must_use]
pub fn registry() -> &'static Requests {
    static REG: OnceLock<Requests> = OnceLock::new();
    REG.get_or_init(Requests::new)
}

/// Classify a request path into a stable action label.
///
/// Returns a `&'static str` by construction, which is what keeps
/// `torrentds_action_total`'s cardinality finite: the label can never be a
/// fragment of the request, however the peer crafts the path.
#[must_use]
pub fn action_of(path: &str) -> &'static str {
    let path = path.split(['?', '#']).next().unwrap_or(path);
    match path {
        "/" | "/search" => "search",
        "/recent" => "recent",
        "/browse" => "browse",
        "/api/search" => "api_search",
        "/api/stats" => "api_stats",
        "/feed" | "/rss" | "/feed.xml" => "feed",
        "/health" => "health",
        "/metrics" => "metrics",
        "/torznab/api" | "/torznab" => "torznab",
        p if p.starts_with("/api/torrent/") => "api_detail",
        p if p.starts_with("/torrent/") && p.ends_with(".torrent") => "torrent_file",
        p if p.starts_with("/t/") => "detail",
        _ => "other",
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn actions_are_stable_and_unknown_paths_fall_back_to_other() {
        assert_eq!(action_of("/search?q=x"), "search");
        assert_eq!(action_of("/api/torrent/abcdef"), "api_detail");
        assert_eq!(action_of("/torrent/abcdef.torrent"), "torrent_file");
        assert_eq!(action_of("/t/abcdef"), "detail");
        assert_eq!(action_of("/feed.xml"), "feed");
        assert_eq!(action_of("/../../etc/passwd"), "other");
        assert_eq!(action_of("/wp-login.php"), "other");
        // A query string or fragment never changes the action, so a peer cannot
        // mint label cardinality by varying it.
        assert_eq!(action_of("/metrics?x=1"), "metrics");
        assert_eq!(action_of("/metrics#f"), "metrics");
    }
}
