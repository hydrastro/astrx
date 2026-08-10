//! crawlcore — the shared crawl library for the astrx-suite Rust rewrite.
//!
//! Pure, dependency-free building blocks used by every engine: a ReDoS-safe
//! robots path-glob matcher, the SimHash near-duplicate bit-math, recrawl
//! scheduling arithmetic, and stateless structural bot-trap predicates. Ported
//! from the Python crawlcore, with the Python tests carried over as the spec.
#![forbid(unsafe_code)]

pub mod dedup;
pub mod globmatch;
pub mod hash;
pub mod scheduler;
pub mod traps;
