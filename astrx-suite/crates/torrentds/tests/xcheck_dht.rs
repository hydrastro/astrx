// Exercises `routing` (encode_nodes/Node), which lives behind the `rand` feature.
#![cfg(feature = "rand")]
//! Cross-check: the KRPC datagrams the Rust DHT node assembles are byte-identical
//! to the reference Python implementation (`legacy-python/torrentds`).
//!
//! The expected hex was produced by driving the Python `krpc.encode_query` /
//! `encode_response` with the exact argument dicts `dht.rs` builds (see
//! `/tmp/xcheck_dht.py` in the delivery notes). Because both stacks canonicalise
//! bencode dict keys, an identical `{key: value}` set must serialise identically —
//! so this pins that the Rust node's method args and responses match the wire the
//! Python node (and every other BEP-5 peer) expects.

use std::net::{Ipv4Addr, SocketAddrV4};
use torrentds::krpc::Dict;
use torrentds::routing::{encode_nodes, Node};
use torrentds::{encode_query, encode_response, Ben};

fn to_hex(bytes: &[u8]) -> String {
    let mut s = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        s.push_str(&format!("{b:02x}"));
    }
    s
}

fn dict(pairs: Vec<(&[u8], Ben)>) -> Dict {
    pairs.into_iter().map(|(k, v)| (k.to_vec(), v)).collect()
}

#[test]
fn dht_datagrams_match_python_reference() {
    let txn = b"aa";
    let self_id = vec![0x11u8; 20];
    let source_id = vec![0x22u8; 20];
    let info_hash = vec![0x42u8; 20];
    let target = vec![0x99u8; 20];
    let token = vec![0xDE, 0xAD, 0xBE, 0xEF, 0x01, 0x02, 0x03, 0x04];

    let n1 = Node::new(
        [0xAB; 20],
        SocketAddrV4::new(Ipv4Addr::new(10, 0, 0, 7), 6881),
    );
    let n2 = Node::new(
        [0xCD; 20],
        SocketAddrV4::new(Ipv4Addr::new(192, 168, 1, 1), 51413),
    );
    let nodes_blob = encode_nodes(&[n1, n2]);

    // outbound queries
    let ping_q = encode_query(
        txn,
        b"ping",
        dict(vec![(b"id", Ben::Bytes(self_id.clone()))]),
    );
    let find_node_q = encode_query(
        txn,
        b"find_node",
        dict(vec![
            (b"id", Ben::Bytes(source_id.clone())),
            (b"target", Ben::Bytes(target.clone())),
        ]),
    );
    let get_peers_q = encode_query(
        txn,
        b"get_peers",
        dict(vec![
            (b"id", Ben::Bytes(self_id.clone())),
            (b"info_hash", Ben::Bytes(info_hash.clone())),
        ]),
    );
    let announce_q = encode_query(
        txn,
        b"announce_peer",
        dict(vec![
            (b"id", Ben::Bytes(self_id.clone())),
            (b"info_hash", Ben::Bytes(info_hash.clone())),
            (b"port", Ben::Int(6881)),
            (b"token", Ben::Bytes(token.clone())),
            (b"implied_port", Ben::Int(0)),
        ]),
    );
    let sample_q = encode_query(
        txn,
        b"sample_infohashes",
        dict(vec![
            (b"id", Ben::Bytes(self_id.clone())),
            (b"target", Ben::Bytes(target.clone())),
        ]),
    );

    // inbound responses
    let ping_r = encode_response(txn, dict(vec![(b"id", Ben::Bytes(self_id.clone()))]));
    let find_node_r = encode_response(
        txn,
        dict(vec![
            (b"id", Ben::Bytes(self_id.clone())),
            (b"nodes", Ben::Bytes(nodes_blob.clone())),
        ]),
    );
    let get_peers_r = encode_response(
        txn,
        dict(vec![
            (b"id", Ben::Bytes(self_id.clone())),
            (b"token", Ben::Bytes(token.clone())),
            (b"nodes", Ben::Bytes(nodes_blob.clone())),
        ]),
    );
    let announce_r = encode_response(txn, dict(vec![(b"id", Ben::Bytes(self_id.clone()))]));

    // golden hex from the Python reference
    assert_eq!(to_hex(&ping_q), "64313a6164323a696432303a111111111111111111111111111111111111111165313a71343a70696e67313a74323a6161313a79313a7165");
    assert_eq!(to_hex(&find_node_q), "64313a6164323a696432303a2222222222222222222222222222222222222222363a74617267657432303a999999999999999999999999999999999999999965313a71393a66696e645f6e6f6465313a74323a6161313a79313a7165");
    assert_eq!(to_hex(&get_peers_q), "64313a6164323a696432303a1111111111111111111111111111111111111111393a696e666f5f6861736832303a424242424242424242424242424242424242424265313a71393a6765745f7065657273313a74323a6161313a79313a7165");
    assert_eq!(to_hex(&announce_q), "64313a6164323a696432303a111111111111111111111111111111111111111131323a696d706c6965645f706f7274693065393a696e666f5f6861736832303a4242424242424242424242424242424242424242343a706f7274693638383165353a746f6b656e383adeadbeef0102030465313a7131333a616e6e6f756e63655f70656572313a74323a6161313a79313a7165");
    assert_eq!(to_hex(&sample_q), "64313a6164323a696432303a1111111111111111111111111111111111111111363a74617267657432303a999999999999999999999999999999999999999965313a7131373a73616d706c655f696e666f686173686573313a74323a6161313a79313a7165");
    assert_eq!(to_hex(&ping_r), "64313a7264323a696432303a111111111111111111111111111111111111111165313a74323a6161313a79313a7265");
    assert_eq!(to_hex(&find_node_r), "64313a7264323a696432303a1111111111111111111111111111111111111111353a6e6f64657335323aabababababababababababababababababababab0a0000071ae1cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdc0a80101c8d565313a74323a6161313a79313a7265");
    assert_eq!(to_hex(&get_peers_r), "64313a7264323a696432303a1111111111111111111111111111111111111111353a6e6f64657335323aabababababababababababababababababababab0a0000071ae1cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdc0a80101c8d5353a746f6b656e383adeadbeef0102030465313a74323a6161313a79313a7265");
    assert_eq!(to_hex(&announce_r), "64313a7264323a696432303a111111111111111111111111111111111111111165313a74323a6161313a79313a7265");
}
