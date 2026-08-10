//! Pure enrichment / health passes over already-parsed torrents: the regex-free
//! release [`classify`]er, BEP-33 scrape estimation ([`bep33`]), and the fake /
//! spam-torrent heuristics ([`spam`]).
//!
//! All dependency-free and re-exported flat at the crate root
//! (`torrentds::classify`, `torrentds::bep33`, `torrentds::spam`).

pub mod bep33;
pub mod classify;
pub mod spam;
