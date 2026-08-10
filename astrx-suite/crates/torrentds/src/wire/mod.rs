//! The wire codec + identity core: the canonical bencode codec, the KRPC (BEP-5)
//! message codec, and byte-exact BitTorrent infohashes (SHA-1 / SHA-256).
//!
//! Every module here is pure and dependency-free — this is the auditable heart of
//! the crate, where hostile bytes from anonymous DHT peers are parsed. Each is
//! re-exported flat at the crate root (`torrentds::bencode`, `torrentds::krpc`,
//! `torrentds::infohash`), so this grouping is an on-disk detail, not an API break.

pub mod bencode;
pub mod infohash;
pub mod krpc;
