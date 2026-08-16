//! `astrx` — one binary for the whole suite.
//!
//! The suite is five engines (`gitweb`, `onioncrawler`, `websearch`,
//! `torrentds`, `suitedash`). Each still ships its own binary, because every
//! unit file, container `CMD` and runbook in existence names one. This crate
//! adds a *single* entrypoint on top of them:
//!
//! ```text
//! astrx gitweb --root /repos --port 8801
//! astrx websearch crawl --db /data/web.db --seeds /data/seeds.txt
//! astrx doctor --db-dir /data --repo-root /repos
//! ```
//!
//! Nothing is re-implemented here. [`dispatch`] inspects exactly one token — the
//! engine name — and hands the entire remainder of the command line to that
//! engine's `cli::run`, which is the same function its standalone binary calls.
//! `astrx websearch crawl …` and `websearch crawl …` therefore share one
//! parser, one help text and one set of exit codes, and cannot drift.
//!
//! [`doctor`] is the one piece of behaviour that is genuinely new: the
//! cross-engine diagnostics an operator wants at 3am, in one command with one
//! exit code.
//!
//! The crate holds no third-party dependencies: it depends on the five engine
//! crates and on `crawlcore`, all first-party path dependencies.

#![forbid(unsafe_code)]
#![warn(missing_docs)]

// Both modules are behind `net`, because that is the tier in which the engines
// have a `cli` module to dispatch to at all, and because `doctor` opens sockets
// to probe ports and the Tor proxy. Without `net` this crate is deliberately
// empty rather than half-working: an `astrx` that could dispatch to some engines
// but not others would be worse than none, since an operator cannot tell from
// the binary's name which build they are holding.
#[cfg(feature = "net")]
pub mod dispatch;
#[cfg(feature = "net")]
pub mod doctor;

#[cfg(feature = "net")]
pub use dispatch::{run, ENGINES};
