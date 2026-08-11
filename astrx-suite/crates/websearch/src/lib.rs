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

pub mod dedup;
pub mod ssrf;

pub use dedup::simhash;
pub use ssrf::{ip_is_internal, SafeIp};
