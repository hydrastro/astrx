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
pub mod routing;
pub mod transport;

pub use bencode::{decode, decode_lenient, decode_prefix, encode, Ben, BencodeError};
pub use dht::{make_neighbor_id, DhtConfig, DhtNode, InfohashSink};
pub use infohash::{infohash, sha1};
pub use krpc::{encode_error, encode_query, encode_response, parse_message, KrpcMessage};
pub use transport::{KrpcNode, QueryError, QueryHandler, Stats};
