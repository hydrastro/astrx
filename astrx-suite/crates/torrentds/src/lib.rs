//! torrentds — DHT torrent-metadata indexer + tracker (Rust rewrite).
//!
//! This crate hosts the byte-exact BitTorrent wire core: a canonical bencode
//! codec whose STRICT decoder is hardened against hostile input from anonymous
//! DHT peers (it returns `Result`, never panics), and the infohash (SHA-1 of the
//! canonical info-dict). The DHT node (BEP-5), HTTP/UDP trackers (BEP-3/15/23)
//! and metadata fetch (BEP-9/10) build on top of these.
#![forbid(unsafe_code)]

pub mod bencode;
pub mod bep33;
pub mod classify;
pub mod dht;
pub mod infohash;
pub mod krpc;
pub mod metadata;
pub mod peerstore;
pub mod routing;
pub mod tracker_http;
pub mod tracker_udp;
pub mod transport;

pub use bencode::{decode, decode_lenient, decode_prefix, encode, Ben, BencodeError};
pub use dht::{make_neighbor_id, DhtConfig, DhtNode, InfohashSink};
pub use infohash::{infohash, infohash_v2, sha1, sha256};
pub use krpc::{encode_error, encode_query, encode_response, parse_message, KrpcMessage};
pub use metadata::{
    fetch_metadata, is_v2_info, parse_info, parse_magnet, parse_v2_info, serve_metadata,
    truncate_v2, verify_v2, Magnet, MetadataError, TorrentMeta,
};
pub use peerstore::{Event, Family, PeerStore};
pub use tracker_http::serve_http_tracker;
pub use tracker_udp::UdpTracker;
pub use transport::{KrpcNode, QueryError, QueryHandler, Stats};
