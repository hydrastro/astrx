//! Kademlia routing primitives for the Mainline DHT (BEP-5).
//!
//! Transport-agnostic and fully synchronous, so it is directly unit-testable:
//! 160-bit node IDs and XOR distance; [`Node`] records; a bounded [`KBucket`];
//! and a [`RoutingTable`] of 160 bit-indexed buckets (bucket *i* holds contacts
//! whose XOR distance from our own ID has its most-significant set bit at
//! position *i*). Plus the compact "nodes" (26-byte) and "peers" (6-byte) wire
//! codecs.

use std::net::{Ipv4Addr, Ipv6Addr, SocketAddrV4};
use std::time::Instant;

pub const ID_BITS: usize = 160;
pub const ID_BYTES: usize = 20;
/// Nodes per bucket (Mainline uses k = 8).
pub const DEFAULT_K: usize = 8;

/// A 160-bit node ID.
pub type NodeId = [u8; ID_BYTES];

/// A fresh random 160-bit node ID (from the OS CSPRNG).
pub fn random_node_id() -> NodeId {
    let mut id = [0u8; ID_BYTES];
    getrandom::getrandom(&mut id).expect("CSPRNG unavailable");
    id
}

/// XOR distance as a 20-byte big-endian value (lexicographic order == numeric).
pub fn distance(a: &NodeId, b: &NodeId) -> [u8; ID_BYTES] {
    let mut d = [0u8; ID_BYTES];
    for i in 0..ID_BYTES {
        d[i] = a[i] ^ b[i];
    }
    d
}

/// Index of the k-bucket `other` belongs to relative to `self_id`: the position
/// (0..=159) of the most-significant set bit of the XOR distance, or `None` when
/// the IDs are identical (no bucket).
pub fn bucket_index(self_id: &NodeId, other: &NodeId) -> Option<usize> {
    let d = distance(self_id, other);
    let mut lead = 0usize;
    for &byte in &d {
        if byte == 0 {
            lead += 8;
        } else {
            lead += byte.leading_zeros() as usize;
            break;
        }
    }
    if lead == ID_BITS {
        None
    } else {
        Some(ID_BITS - 1 - lead)
    }
}

/// A DHT contact: id + IPv4 endpoint + freshness.
#[derive(Debug, Clone)]
pub struct Node {
    pub id: NodeId,
    pub addr: SocketAddrV4,
    pub last_seen: Instant,
}

impl Node {
    pub fn new(id: NodeId, addr: SocketAddrV4) -> Self {
        Self {
            id,
            addr,
            last_seen: Instant::now(),
        }
    }

    pub fn touch(&mut self) {
        self.last_seen = Instant::now();
    }

    /// The 26-byte compact node info (20 id + 4 IP + 2 port).
    pub fn compact(&self) -> [u8; 26] {
        let mut out = [0u8; 26];
        out[..20].copy_from_slice(&self.id);
        out[20..24].copy_from_slice(&self.addr.ip().octets());
        out[24..26].copy_from_slice(&self.addr.port().to_be_bytes());
        out
    }
}

// Node identity is its id (like the Python `__eq__`/`__hash__`).
impl PartialEq for Node {
    fn eq(&self, other: &Self) -> bool {
        self.id == other.id
    }
}
impl Eq for Node {}

// --- compact endpoint / node / peer codecs -------------------------------

/// Encode an IPv4 endpoint to 6 bytes (4 IP + 2 port).
pub fn encode_endpoint(addr: &SocketAddrV4) -> [u8; 6] {
    let mut b = [0u8; 6];
    b[..4].copy_from_slice(&addr.ip().octets());
    b[4..6].copy_from_slice(&addr.port().to_be_bytes());
    b
}

/// Decode a 6-byte IPv4 compact endpoint.
pub fn decode_endpoint(blob: &[u8]) -> Option<SocketAddrV4> {
    if blob.len() != 6 {
        return None;
    }
    let ip = Ipv4Addr::new(blob[0], blob[1], blob[2], blob[3]);
    Some(SocketAddrV4::new(
        ip,
        u16::from_be_bytes([blob[4], blob[5]]),
    ))
}

/// Encode an IPv6 endpoint to 18 bytes (16 IP + 2 port) — BEP-7.
pub fn encode_endpoint6(ip: &Ipv6Addr, port: u16) -> [u8; 18] {
    let mut b = [0u8; 18];
    b[..16].copy_from_slice(&ip.octets());
    b[16..18].copy_from_slice(&port.to_be_bytes());
    b
}

/// Decode an 18-byte IPv6 compact endpoint — BEP-7.
pub fn decode_endpoint6(blob: &[u8]) -> Option<(Ipv6Addr, u16)> {
    if blob.len() != 18 {
        return None;
    }
    let mut o = [0u8; 16];
    o.copy_from_slice(&blob[..16]);
    Some((Ipv6Addr::from(o), u16::from_be_bytes([blob[16], blob[17]])))
}

/// Concatenate nodes into a compact "nodes" string.
pub fn encode_nodes(nodes: &[Node]) -> Vec<u8> {
    let mut out = Vec::with_capacity(nodes.len() * 26);
    for n in nodes {
        out.extend_from_slice(&n.compact());
    }
    out
}

/// Parse a compact "nodes" string; a ragged tail is silently dropped.
pub fn decode_nodes(blob: &[u8]) -> Vec<Node> {
    let mut out = Vec::new();
    for chunk in blob.chunks_exact(26) {
        let mut id = [0u8; 20];
        id.copy_from_slice(&chunk[..20]);
        if let Some(addr) = decode_endpoint(&chunk[20..26]) {
            out.push(Node::new(id, addr));
        }
    }
    out
}

/// Decode compact "peers" (a list of 6-byte values); non-6-byte values dropped.
pub fn decode_peers<'a, I>(values: I) -> Vec<SocketAddrV4>
where
    I: IntoIterator<Item = &'a [u8]>,
{
    values.into_iter().filter_map(decode_endpoint).collect()
}

// --- k-buckets and routing table -----------------------------------------

/// A bounded set of nodes ordered least- to most-recently seen.
#[derive(Debug)]
pub struct KBucket {
    pub k: usize,
    pub nodes: Vec<Node>,
}

impl KBucket {
    pub fn new(k: usize) -> Self {
        Self {
            k,
            nodes: Vec::new(),
        }
    }

    pub fn len(&self) -> usize {
        self.nodes.len()
    }

    pub fn is_empty(&self) -> bool {
        self.nodes.is_empty()
    }

    pub fn get(&self, id: &NodeId) -> Option<&Node> {
        self.nodes.iter().find(|n| &n.id == id)
    }

    /// Insert or refresh `node`. Returns `true` if it is now present (added or
    /// refreshed), `false` if the bucket was full and the node was dropped.
    pub fn add(&mut self, node: Node) -> bool {
        if let Some(pos) = self.nodes.iter().position(|n| n.id == node.id) {
            // refresh: update the endpoint, touch, and move to the tail.
            let mut existing = self.nodes.remove(pos);
            existing.addr = node.addr;
            existing.touch();
            self.nodes.push(existing);
            return true;
        }
        if self.nodes.len() < self.k {
            self.nodes.push(node);
            return true;
        }
        false
    }

    pub fn remove(&mut self, id: &NodeId) {
        if let Some(pos) = self.nodes.iter().position(|n| &n.id == id) {
            self.nodes.remove(pos);
        }
    }
}

/// 160 bit-indexed k-buckets rooted at `self_id`.
#[derive(Debug)]
pub struct RoutingTable {
    pub self_id: NodeId,
    buckets: Vec<KBucket>,
}

impl RoutingTable {
    pub fn new(self_id: NodeId, k: usize) -> Self {
        Self {
            self_id,
            buckets: (0..ID_BITS).map(|_| KBucket::new(k)).collect(),
        }
    }

    /// Add a contact. Our own ID is never stored (returns `false`).
    pub fn add_node(&mut self, node: Node) -> bool {
        match bucket_index(&self.self_id, &node.id) {
            None => false,
            Some(idx) => self.buckets[idx].add(node),
        }
    }

    pub fn remove_node(&mut self, id: &NodeId) {
        if let Some(idx) = bucket_index(&self.self_id, id) {
            self.buckets[idx].remove(id);
        }
    }

    pub fn all_nodes(&self) -> Vec<Node> {
        self.buckets
            .iter()
            .flat_map(|b| b.nodes.iter().cloned())
            .collect()
    }

    pub fn len(&self) -> usize {
        self.buckets.iter().map(KBucket::len).sum()
    }

    pub fn is_empty(&self) -> bool {
        self.len() == 0
    }

    /// Up to `count` known nodes closest (XOR) to `target`.
    pub fn find_closest(&self, target: &NodeId, count: usize) -> Vec<Node> {
        let mut nodes = self.all_nodes();
        nodes.sort_by_key(|n| distance(&n.id, target));
        nodes.truncate(count);
        nodes
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn id(byte: u8) -> NodeId {
        [byte; ID_BYTES]
    }

    fn id_lsb(bits_from_self: usize) -> NodeId {
        // an id whose XOR-distance from all-zero has its top set bit at position n
        let mut x = [0u8; ID_BYTES];
        let bit = bits_from_self;
        x[ID_BYTES - 1 - bit / 8] = 1 << (bit % 8);
        x
    }

    fn addr(last: u8, port: u16) -> SocketAddrV4 {
        SocketAddrV4::new(Ipv4Addr::new(10, 0, 0, last), port)
    }

    #[test]
    fn distance_and_bucket_index() {
        let z = id(0x00);
        assert_eq!(bucket_index(&z, &z), None); // identical -> no bucket
        assert_eq!(bucket_index(&z, &id_lsb(0)), Some(0)); // lowest bit
        assert_eq!(bucket_index(&z, &id_lsb(159)), Some(159)); // highest bit
                                                               // distance is symmetric and XOR
        assert_eq!(distance(&id(0x0F), &id(0xF0)), [0xFF; ID_BYTES]);
    }

    #[test]
    fn compact_node_round_trip() {
        let n = Node::new(id(0xAB), addr(7, 6881));
        let wire = encode_nodes(std::slice::from_ref(&n));
        assert_eq!(wire.len(), 26);
        let back = decode_nodes(&wire);
        assert_eq!(back.len(), 1);
        assert_eq!(back[0].id, n.id);
        assert_eq!(back[0].addr, n.addr);
        // ragged tail dropped
        let mut ragged = wire.clone();
        ragged.extend_from_slice(&[1, 2, 3]);
        assert_eq!(decode_nodes(&ragged).len(), 1);
    }

    #[test]
    fn endpoint_round_trip() {
        let a = addr(200, 51413);
        assert_eq!(decode_endpoint(&encode_endpoint(&a)), Some(a));
        assert_eq!(decode_endpoint(&[0; 5]), None);
        let v6: Ipv6Addr = "2001:db8::1".parse().unwrap();
        assert_eq!(
            decode_endpoint6(&encode_endpoint6(&v6, 6969)),
            Some((v6, 6969))
        );
    }

    #[test]
    fn kbucket_add_refresh_full_remove() {
        let mut b = KBucket::new(2);
        assert!(b.add(Node::new(id(1), addr(1, 1))));
        assert!(b.add(Node::new(id(2), addr(2, 2))));
        // full: a third distinct node is rejected
        assert!(!b.add(Node::new(id(3), addr(3, 3))));
        // refresh id(1): updates endpoint, moves to tail, still fits
        assert!(b.add(Node::new(id(1), addr(9, 9))));
        assert_eq!(b.nodes.last().unwrap().id, id(1));
        assert_eq!(b.get(&id(1)).unwrap().addr, addr(9, 9));
        b.remove(&id(2));
        assert_eq!(b.len(), 1);
    }

    #[test]
    fn routing_table_add_and_find_closest() {
        let me = id(0x00);
        let mut rt = RoutingTable::new(me, DEFAULT_K);
        assert!(!rt.add_node(Node::new(me, addr(1, 1)))); // never store self
        for b in 1..=20u8 {
            assert!(rt.add_node(Node::new(id(b), addr(b, 1000 + b as u16))));
        }
        assert_eq!(rt.len(), 20);
        // closest to id(0x01) should start with id(0x01) itself (distance 0)
        let closest = rt.find_closest(&id(0x01), 3);
        assert_eq!(closest[0].id, id(0x01));
        // results are in non-decreasing XOR distance order
        for w in closest.windows(2) {
            assert!(distance(&w[0].id, &id(0x01)) <= distance(&w[1].id, &id(0x01)));
        }
    }
}
