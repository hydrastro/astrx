//! crawlcore — the shared crawl library for the astrx-suite Rust rewrite.
//!
//! Pure, dependency-free building blocks used by every engine: a ReDoS-safe
//! robots path-glob matcher, the SimHash near-duplicate bit-math, recrawl
//! scheduling arithmetic, stateless structural bot-trap predicates, shared
//! hashing (SHA-1/SHA-256/MD5 + BLAKE2b), and a DEFLATE/gzip/zlib inflater.
//!
//! It also holds the two primitives that exist because the same bug was written
//! independently in several engines: [`budget::Budget`], which makes a
//! wire-controlled length impossible to overflow past a cap, and
//! [`atomicfile::write_atomic`], which publishes a snapshot by rename so a torn
//! write cannot destroy the previous good one.
//! Ported from the Python crawlcore, with the Python tests carried over as the
//! spec.
#![forbid(unsafe_code)]

pub mod atomicfile;
pub mod blake2b;
pub mod budget;
pub mod dedup;
pub mod globmatch;
pub mod hash;
pub mod inflate;
pub mod json;
pub mod scheduler;
pub mod traps;
pub mod urlparse;
