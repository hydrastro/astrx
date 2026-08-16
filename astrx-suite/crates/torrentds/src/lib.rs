//! torrentds — DHT torrent-metadata indexer + tracker (Rust rewrite).
//!
//! This crate hosts the byte-exact BitTorrent wire core: a canonical bencode
//! codec whose STRICT decoder is hardened against hostile input from anonymous
//! DHT peers (it returns `Result`, never panics), and the infohash (SHA-1 of the
//! canonical info-dict). The DHT node (BEP-5), HTTP/UDP trackers (BEP-3/15/23)
//! and metadata fetch (BEP-9/10) build on top of these.
//!
//! # Source layout
//!
//! On disk the modules are grouped by subsystem — `wire/` (bencode, krpc,
//! infohash), `enrich/` (classify, bep33, spam), `dht/` (routing, node,
//! transport), `tracker/` (peerstore, http, udp), plus `metadata/` and `store`.
//! Each is re-exported flat at the crate root, so the public path is stable and
//! grouping-agnostic (`torrentds::bencode`, `torrentds::routing`, …).
//!
//! # Feature tiers
//!
//! The crate is layered so its pure, auditable core carries **no third-party
//! dependencies** — that is the default build. Live networking is opt-in:
//!
//! * *(default, no features)* — the pure wire core: [`bencode`], [`infohash`],
//!   [`krpc`], [`bep33`], [`classify`], [`spam`], [`store`], and all of
//!   [`metadata`]'s parsing/builders. Zero third-party dependencies.
//! * **`rand`** — adds the sync CSPRNG-backed structures ([`routing`] table,
//!   [`peerstore`] swarm store); pulls in `getrandom`.
//! * **`net`** — adds the live async DHT node, the [`tracker_http`] /
//!   [`tracker_udp`] servers, the [`transport`] layer, and [`metadata`]'s
//!   `fetch_metadata`/`serve_metadata`; pulls in `tokio` (and implies `rand`).
#![forbid(unsafe_code)]
#![warn(missing_debug_implementations)]

// --- Subsystem groupings (private on-disk parents; re-exported flat below) ---
mod enrich;
pub mod metadata;
// This process's request counters (uptime, requests, statuses, errors) and the
// route->action classifier that bounds their label cardinality. Stdlib-only, so
// it is not behind `net` — `/metrics` renders in the default build too.
pub mod metrics;
pub mod store;
mod wire;

#[cfg(feature = "rand")]
mod dht;
#[cfg(feature = "net")]
pub mod indexer;
#[cfg(feature = "net")]
pub mod search;

// The command line, shared by the standalone `torrentds` binary and by
// `astrx torrentds …`. Behind `net` because every runnable subcommand ends in a
// socket, matching the `[[bin]]` `required-features` — the default build stays a
// pure, zero-dependency library.
#[cfg(feature = "net")]
pub mod cli;
#[cfg(feature = "rand")]
mod tracker;

// --- Flat module facade: stable public paths regardless of the on-disk grouping.
#[cfg(feature = "rand")]
pub use dht::routing;
#[cfg(feature = "net")]
pub use dht::transport;
pub use enrich::{bep33, classify, spam};
#[cfg(feature = "net")]
pub use tracker::http as tracker_http;
#[cfg(feature = "rand")]
pub use tracker::peerstore;
#[cfg(feature = "net")]
pub use tracker::udp as tracker_udp;
pub use wire::{bencode, infohash, krpc};

// --- Re-exports: pure core (always available) ---
pub use bencode::{decode, decode_lenient, decode_prefix, encode, Ben, BencodeError, Dict};
pub use infohash::{infohash, infohash_v2, sha1, sha256};
pub use krpc::{
    encode_error, encode_query, encode_response, parse_message, KrpcError, KrpcMessage, ParseError,
};
pub use metadata::{
    build_torrent_file, is_v2_info, parse_info, parse_magnet, parse_v2_info, truncate_v2,
    verify_v2, Magnet, MetadataError, TorrentMeta,
};

// --- Re-exports: `rand` tier ---
#[cfg(feature = "rand")]
pub use peerstore::{Event, Family, PeerStore, ScrapeCounts};
#[cfg(feature = "rand")]
pub use routing::{InfoHash, Node, NodeId};

// --- Re-exports: `net` tier ---
#[cfg(feature = "net")]
pub use dht::node::{
    default_bootstrap, make_neighbor_id, DhtConfig, DhtNode, GetPeersOutcome, InfohashSink,
    SampleOutcome,
};
#[cfg(feature = "net")]
pub use indexer::{Indexer, IndexerConfig, IndexerStats};
#[cfg(feature = "net")]
pub use metadata::{fetch_metadata, serve_metadata};
#[cfg(feature = "net")]
pub use search::{serve_search, SearchServer};
#[cfg(feature = "net")]
pub use tracker_http::serve_http_tracker;
#[cfg(feature = "net")]
pub use tracker_udp::UdpTracker;
#[cfg(feature = "net")]
pub use transport::{KrpcNode, QueryError, QueryHandler, Stats};
