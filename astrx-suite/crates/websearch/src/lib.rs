//! websearch — the clearnet search engine of the astrx-suite Rust rewrite.
//!
//! Ported one module at a time from the retiring Python engine under
//! `legacy-python/websearch/`, with the Python tests carried over as the
//! executable spec and every ported module cross-checked byte-identical to the
//! reference (see `tests/xcheck_*.rs`).
//!
//! # The crown invariant, as a type
//!
//! A clearnet crawler fetches arbitrary, attacker-influenced URLs. The invariant
//! that must never break is that it can never be steered at an *internal*
//! address (localhost, RFC-1918, the `169.254.169.254` cloud-metadata endpoint,
//! …) — a Server-Side Request Forgery. In the Python engine that guard is a
//! runtime resolve-and-check every socket path must remember. Here it is a
//! **type**: [`ssrf::SafeIp`] wraps an `IpAddr` that has passed the
//! internal-address check, and the (forthcoming) net-tier connect takes a
//! `&SafeIp`, so dialing an unvetted/internal address is a *compile* error.
//!
//! # Feature tiers
//!
//! Mirrors the other engines: the pure modules below build under the default
//! (empty) feature set with zero third-party dependencies; live networking is
//! opt-in behind `net`/`rand`.
#![forbid(unsafe_code)]

pub mod atom;
pub mod canonical;
pub mod crawler;
pub mod dedup;
pub mod federation;
pub mod frontier;
pub mod htmlparse;
pub mod httpclient;
pub mod index;
// This process's request counters (uptime, requests, statuses, errors) and the
// route->action classifier that bounds their label cardinality. Stdlib-only, so
// it is not behind `net` — `/metrics` renders in the default build too.
pub mod metrics;
pub mod pdftext;
pub mod query;
pub mod ranking;
pub mod robots;
pub mod serve;
pub mod ssrf;
pub mod structured;
pub mod suggest;

#[cfg(feature = "net")]
pub mod fetcher;

// The command line, shared by the standalone `websearch` binary and by
// `astrx websearch …`. Behind `net` because every runnable subcommand ends in a
// socket, matching the `[[bin]]` `required-features` — the default build stays a
// pure, zero-dependency library.
#[cfg(feature = "net")]
pub mod cli;

pub use atom::{render as render_atom, FeedMeta};
pub use canonical::{canonicalize, host_of, in_scope, is_http_url};
pub use crawler::{public_resolved, trap_ok, CrawlConfig, CrawlStats};
pub use dedup::simhash;
pub use federation::{norm_host, owns, shard_for};
pub use frontier::{Frontier, HostRow, Lease};
pub use htmlparse::{extract as extract_html, guess_lang, Extracted, Image};
pub use httpclient::{
    authority_exempt, decode_body, decompress, parse_content_type, vet_addrs, FetchResult,
    GateError, Headers, HttpError,
};
pub use index::{
    content_hash, prefix_upper, DocFields, Document, ImageResult, Index, Stats, StoredImage,
    StoredVideo, VideoResult, FUZZY_SCAN_CAP, MAX_IMAGES_PER_DOC, MAX_VIDEOS_PER_DOC,
};
pub use pdftext::{extract_text as extract_pdf_text, extract_title as extract_pdf_title};
pub use query::{parse_query, Query};
pub use ranking::{search, SearchOpts, SearchResponse, SearchResult};
pub use robots::{parse as parse_robots, Robots};
pub use serve::{Resp, SearchServer};
pub use ssrf::{ip_is_internal, SafeIp};
pub use structured::{
    balanced_json, classify_player, collect_readable, extract_state_json, first_str, first_url,
    is_direct_media, iter_dicts, parse_duration, type_of, Video,
};
pub use suggest::{levenshtein, suggest};

#[cfg(feature = "net")]
pub use crawler::Crawler;
#[cfg(feature = "net")]
pub use federation::{
    federated_search, normalize_bases, FederatedOpts, FederatedResponse, ShardOutcome, ShardResult,
};
#[cfg(feature = "net")]
pub use fetcher::{clear_dns_cache, fetch, resolve_checked, FetchOpts, Fetcher};
