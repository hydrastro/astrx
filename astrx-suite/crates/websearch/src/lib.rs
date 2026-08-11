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

pub mod canonical;
pub mod dedup;
pub mod frontier;
pub mod htmlparse;
pub mod httpclient;
pub mod index;
pub mod robots;
pub mod ssrf;

#[cfg(feature = "net")]
pub mod fetcher;

pub use canonical::{canonicalize, host_of, in_scope, is_http_url};
pub use dedup::simhash;
pub use frontier::{Frontier, HostRow, Lease};
pub use htmlparse::{extract as extract_html, guess_lang, Extracted};
pub use httpclient::{
    authority_exempt, decode_body, decompress, parse_content_type, vet_addrs, FetchResult,
    GateError, Headers, HttpError,
};
pub use index::{content_hash, DocFields, Document, Index, Stats};
pub use robots::{parse as parse_robots, Robots};
pub use ssrf::{ip_is_internal, SafeIp};

#[cfg(feature = "net")]
pub use fetcher::{clear_dns_cache, fetch, resolve_checked, FetchOpts};
