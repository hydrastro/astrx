//! torrentds — DHT torrent-metadata indexer + tracker (Rust rewrite).
//!
//! This crate hosts the byte-exact BitTorrent wire core: a canonical bencode
//! codec whose STRICT decoder is hardened against hostile input from anonymous
//! DHT peers (it returns `Result`, never panics), and the infohash (SHA-1 of the
//! canonical info-dict). The DHT node (BEP-5), HTTP/UDP trackers (BEP-3/15/23)
//! and metadata fetch (BEP-9/10) build on top of these.
//!
//! # Feature tiers
//!
//! The crate is layered so its pure, auditable core carries **no third-party
//! dependencies** — that is the default build. Live networking is opt-in:
//!
//! * *(default, no features)* — the pure wire core: [`bencode`], [`infohash`],
//!   [`krpc`], [`bep33`], [`classify`], and all of [`metadata`]'s parsing/builders.
//!   Zero third-party dependencies.
//! * **`rand`** — adds the sync CSPRNG-backed structures ([`routing`] table,
//!   [`peerstore`] swarm store); pulls in `getrandom`.
//! * **`net`** — adds the live async node ([`dht`]), the [`tracker_http`] /
//!   [`tracker_udp`] servers, the [`transport`] layer, and [`metadata`]'s
//!   `fetch_metadata`/`serve_metadata`; pulls in `tokio` (and implies `rand`).
#![forbid(unsafe_code)]

// --- Pure wire core: zero third-party deps, always compiled ---
pub mod bencode;
pub mod bep33;
pub mod classify;
pub mod infohash;
pub mod krpc;
pub mod metadata;
pub mod spam;
pub mod store;

// --- Sync, CSPRNG-backed structures: require `rand` ---
#[cfg(feature = "rand")]
pub mod peerstore;
#[cfg(feature = "rand")]
pub mod routing;

// --- Live networking: require `net` (implies `rand`) ---
#[cfg(feature = "net")]
pub mod dht;
#[cfg(feature = "net")]
pub mod tracker_http;
#[cfg(feature = "net")]
pub mod tracker_udp;
#[cfg(feature = "net")]
pub mod transport;

// --- Re-exports: pure core (always available) ---
pub use bencode::{decode, decode_lenient, decode_prefix, encode, Ben, BencodeError};
pub use infohash::{infohash, infohash_v2, sha1, sha256};
pub use krpc::{
    encode_error, encode_query, encode_response, parse_message, Dict, KrpcError, KrpcMessage,
    ParseError,
};
pub use metadata::{
    is_v2_info, parse_info, parse_magnet, parse_v2_info, truncate_v2, verify_v2, Magnet,
    MetadataError, TorrentMeta,
};

// --- Re-exports: `rand` tier ---
#[cfg(feature = "rand")]
pub use peerstore::{Event, Family, PeerStore};
#[cfg(feature = "rand")]
pub use routing::{Node, NodeId};

// --- Re-exports: `net` tier ---
#[cfg(feature = "net")]
pub use dht::{make_neighbor_id, DhtConfig, DhtNode, GetPeersOutcome, InfohashSink, SampleOutcome};
#[cfg(feature = "net")]
pub use metadata::{fetch_metadata, serve_metadata};
#[cfg(feature = "net")]
pub use tracker_http::serve_http_tracker;
#[cfg(feature = "net")]
pub use tracker_udp::UdpTracker;
#[cfg(feature = "net")]
pub use transport::{KrpcNode, QueryError, QueryHandler, Stats};
