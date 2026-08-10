//! The DHT subsystem (BEP-5). The Kademlia [`routing`] table is synchronous and
//! only needs a CSPRNG (`rand` tier); the async KRPC [`transport`] and the live
//! DHT [`node`] need an async runtime (`net` tier).
//!
//! Re-exported flat at the crate root: `torrentds::routing`, `torrentds::transport`,
//! and the node types (`torrentds::DhtNode`, …).

pub mod routing;

#[cfg(feature = "net")]
pub mod node;
#[cfg(feature = "net")]
pub mod transport;
