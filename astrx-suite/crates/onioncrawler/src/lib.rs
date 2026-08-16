//! onioncrawler — the darknet (Tor `.onion`, optional I2P `.i2p`) crawler of the
//! astrx-suite Rust rewrite.
//!
//! Ported one module at a time from the retiring Python engine under
//! `legacy-python/onioncrawler/`, with the Python tests carried over as the
//! executable spec and every ported module cross-checked byte-identical to the
//! reference (see `tests/xcheck_*.rs`).
//!
//! # The crown invariant, as a type
//!
//! This is a service that opens sockets to anonymous, hostile hidden services
//! behind Tor. The one invariant that must never break is that a Tor crawl can
//! never touch a clearnet / localhost / IP-literal host — a leak would
//! deanonymise the operator. In the Python engine that gate is a runtime call
//! (`require_onion`) that every socket path must remember to make. Here it is a
//! **type**: [`onion::OnionHost`] is constructible only through a validating
//! parser, and the (forthcoming) net-tier fetcher takes an `&OnionHost` rather
//! than a `&str`, so handing it a clearnet host is a *compile* error, not a
//! runtime check that can be forgotten.
//!
//! # Feature tiers
//!
//! Mirrors `torrentds`: the pure modules below build under the default (empty)
//! feature set with zero third-party dependencies; live networking is opt-in
//! behind `net`/`rand`.
#![forbid(unsafe_code)]

pub mod abuse;
pub mod canonical;
pub mod entities;
pub mod extract;
// The crawl orchestration loop (net tier): lease → fetch → extract → store.
#[cfg(feature = "net")]
pub mod crawler;
// The darknet fetcher — the `&OnionHost`-gated socket orchestration (net tier).
#[cfg(feature = "net")]
pub mod fetcher;
pub mod http;
pub mod i2p;
pub mod lang;
pub mod onion;
pub mod ratelimit;
pub mod robots;
pub mod serve;
pub mod simhash;
pub mod sitemap;
pub mod socks;
pub mod store;
pub mod submit;
// The `urllib.parse` / `posixpath` subset the canonicalizer + robots parser use
// now lives in the shared `crawlcore::urlparse` (see the imports below).

// Flat facade: re-export the darknet gate + canonicalizer at the crate root so
// call sites read `onioncrawler::OnionHost` / `onioncrawler::canonicalize`
// regardless of internal module grouping (which will grow as the net/store/
// search tiers land).
pub use abuse::{load_abuse_filter, AbuseFilter};
pub use canonical::{canonicalize, CanonicalUrl};
#[cfg(feature = "net")]
pub use crawler::{content_hash, CrawlConfig, Crawler, RunStats};
pub use entities::{extract as extract_entities, Kind as EntityKind};
#[cfg(feature = "net")]
pub use fetcher::{FetchResult, Fetcher};
pub use onion::{
    find_onion_urls, is_darknet_host, is_i2p_host, is_onion_host, normalize_host, onion_version,
    DarknetHost, I2pHost, I2pKind, OnionHost, Refusal, RefusedHost,
};
pub use ratelimit::TokenBucket;
pub use robots::{parse_robots, RobotsRules};
pub use serve::{RateLimits, SearchServer, ServeConfig};
pub use simhash::simhash64;
pub use sitemap::{parse_sitemap, SitemapDoc, SitemapKind};
pub use store::{
    Caps, Enqueued, Facets, FrontierRow, HostCounter, HostRow, Lease, PageRow, Reseed, SearchHit,
    SearchOpts, SearchResults, Store, StoreOutcome,
};
pub use submit::{submit_many, submit_seed, SubmitResult, SubmitStatus, SubmitSummary};
