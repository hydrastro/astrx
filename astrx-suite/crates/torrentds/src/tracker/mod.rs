//! The tracker subsystem. The shared swarm [`peerstore`] is a synchronous data
//! structure (`rand` tier); the BEP-3/23 [`http`] and BEP-15 [`udp`] tracker
//! servers need an async runtime (`net` tier).
//!
//! Re-exported flat at the crate root: `torrentds::peerstore`,
//! `torrentds::tracker_http`, `torrentds::tracker_udp`.

pub mod peerstore;

#[cfg(feature = "net")]
pub mod http;
#[cfg(feature = "net")]
pub mod udp;
