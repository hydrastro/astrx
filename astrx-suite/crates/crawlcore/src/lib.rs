//! crawlcore — the shared crawl library for the astrx-suite Rust rewrite.
//!
//! Pure, dependency-free building blocks used by every engine: a ReDoS-safe
//! robots path-glob matcher, the SimHash near-duplicate bit-math, recrawl
//! scheduling arithmetic, stateless structural bot-trap predicates, shared
//! hashing (SHA-1/SHA-256/MD5 + BLAKE2b), and a DEFLATE/gzip/zlib inflater.
//!
//! It also holds the primitives that exist because the same bug was written
//! independently in several engines: [`budget::Budget`], which makes a
//! wire-controlled length impossible to overflow past a cap;
//! [`atomicfile::write_atomic`], which publishes a snapshot by rename so a torn
//! write cannot destroy the previous good one; and [`http`], the HTTP/1.1 client
//! wire layer that `websearch` and `onioncrawler` each used to keep a copy of —
//! four framing / injection defects deep, every one of them fixed twice.
//! Ported from the Python crawlcore, with the Python tests carried over as the
//! spec.
//!
//! # Feature tiers
//!
//! The default build is stdlib-only, with zero third-party dependencies — that
//! is the crate's contract and every module below is written to keep it. The one
//! exception is the *streaming* half of [`http`], which needs
//! `tokio::io::AsyncRead` to read a body off a socket; it sits behind the opt-in
//! `net` feature (`net = ["dep:tokio"]`), exactly as the engines' net tiers do,
//! so `cargo build -p crawlcore` still pulls in nothing at all.
#![forbid(unsafe_code)]

pub mod atomicfile;
pub mod blake2b;
pub mod budget;
pub mod dedup;
pub mod globmatch;
pub mod hash;
pub mod http;
pub mod inflate;
pub mod json;
pub mod scheduler;
pub mod traps;
pub mod urlparse;
