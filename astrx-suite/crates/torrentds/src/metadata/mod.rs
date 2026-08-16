//! BitTorrent peer wire + ut_metadata (BEP-3 / BEP-10 / BEP-9), plus BEP-52 v2.
//!
//! Fetches a torrent's *metadata* (the info-dict) from a peer without ever
//! downloading content — the step that turns a harvested infohash into an
//! indexable `.torrent`:
//!
//! * **BEP-3** peer handshake and length-prefixed message framing.
//! * **BEP-10** extended handshake (advertises `ut_metadata` + `metadata_size`).
//! * **BEP-9** ut_metadata: request each 16 KiB piece, reassemble, verify
//!   `sha1(metadata) == info_hash`, then parse the info-dict.
//!
//! The module is split by concern so the byte-exact, dependency-free logic is
//! isolated from the async I/O:
//!
//! * [`wire`] — pure peer-wire framing (handshake + message + ut_metadata
//!   builders, piece math). Cross-checked byte-identical to the Python reference.
//! * [`info`] — pure info-dict → [`TorrentMeta`] parsing (v1, v2, hybrid) and the
//!   SHA-1/SHA-256 assembly checks.
//! * [`magnet`] — pure `magnet:` URI parsing (btih / btmh).
//! * [`fetch`] — the async client ([`fetch_metadata`]) and a loopback peer
//!   ([`serve_metadata`]); compiled only with the `net` feature.
//!
//! Hostile-input hardening mirrors the reference: `metadata_size` and every
//! peer-wire frame are capped before allocation, each ut_metadata piece must be
//! exactly its BEP-9 length, and the v2 `file tree` walk is bounded on depth, node
//! count **and emitted path bytes** — so a peer cannot force us to buffer far more
//! than the advertised (and bounded) metadata size before the final hash check,
//! nor to expand a bounded info-dict into an unbounded file list afterwards.

/// The BEP-3 protocol string.
pub const BT_PROTOCOL: &[u8] = b"BitTorrent protocol";
/// Fixed handshake length (1 + 19 + 8 + 20 + 20).
pub const HANDSHAKE_LEN: usize = 68;
/// ut_metadata piece size (BEP-9): 16 KiB.
pub const PIECE_SIZE: usize = 16384;
/// `read_message` reports a keep-alive (length 0) with this id.
pub const KEEPALIVE: i32 = -1;
/// BEP-10 extended-message id.
pub const EXT_MSG_ID: u8 = 20;
/// Reject an advertised `metadata_size` beyond this (a real info-dict is a few MB).
pub const MAX_METADATA_SIZE: usize = 10 * 1024 * 1024;
/// Reject any peer-wire frame longer than this before allocating.
pub const MAX_MESSAGE_LEN: usize = 1024 * 1024;

/// ut_metadata `msg_type` values (BEP-9).
pub const UT_REQUEST: i64 = 0;
pub const UT_DATA: i64 = 1;
pub const UT_REJECT: i64 = 2;

/// BEP-52 bounds: the `file tree` is attacker-controlled recursive bencode, so the
/// walk is bounded on nesting and total node count independently of bencode's
/// generic depth cap.
pub const MAX_TREE_DEPTH: usize = 60;
pub const MAX_TREE_NODES: usize = 100_000;
/// BEP-52 bound on the walk's **output**: the sum of flattened path bytes.
///
/// [`MAX_METADATA_SIZE`], [`MAX_TREE_DEPTH`] and [`MAX_TREE_NODES`] all bound the
/// *input*; none of them bounds what the walk produces, because every leaf
/// re-materialises its whole key prefix. An info-dict shaped
/// `{"file tree": {<6 MiB name>: {<100 000 short names>: {"": {"length": 1}}}}}`
/// is 8.19 MiB on the wire — under every input cap, and self-consistent because
/// the attacker publishes `sha1(that)` as the infohash himself — yet flattens to
/// ~585 GiB of `String`s. Measured through the real client: 4.0 MiB of input with
/// only 300 such leaves cost +1124 MiB RSS in 745 ms and still returned `Ok`;
/// 800 leaves cost +3.2 GiB. One TCP connection per OOM-kill. 8 MiB of paths is
/// far more than any genuine torrent needs (its names must also fit the 10 MiB
/// metadata cap), so a legitimate file tree never comes near this.
pub const MAX_TREE_PATH_BYTES: usize = 8 * 1024 * 1024;

/// Any metadata-fetch failure (bad handshake, hostile bytes, hash mismatch, I/O).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MetadataError(pub String);

impl MetadataError {
    /// The human-readable failure message.
    #[must_use]
    pub fn message(&self) -> &str {
        &self.0
    }
}

impl std::fmt::Display for MetadataError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "metadata: {}", self.0)
    }
}
impl std::error::Error for MetadataError {}

/// Shorthand for building an `Err(MetadataError(..))`, shared across submodules.
pub(crate) fn merr<T>(msg: impl Into<String>) -> Result<T, MetadataError> {
    Err(MetadataError(msg.into()))
}

mod info;
mod magnet;
mod wire;

pub use info::{
    assemble_and_verify, assemble_and_verify_v2, build_torrent_file, decode_info_dict,
    is_hybrid_info, is_v2_info, parse_info, parse_v2_info, truncate_v2, verify_v2, walk_file_tree,
    TorrentMeta,
};
pub use magnet::{parse_magnet, Magnet};
pub use wire::{
    build_ext_handshake, build_ext_message, build_handshake, build_message, build_ut_metadata_data,
    build_ut_metadata_reject, build_ut_metadata_request, expected_piece_len, num_pieces,
    parse_handshake, supports_extensions, Handshake,
};

#[cfg(feature = "net")]
mod fetch;
#[cfg(feature = "net")]
pub use fetch::{fetch_metadata, read_message, serve_metadata};

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bencode::Dict;
    use crate::bencode::{decode, encode, Ben};
    use crate::infohash::{sha1, sha256};

    fn v1_single_file(name: &[u8], length: i64, piece_hashes: usize) -> Vec<u8> {
        let mut info = Dict::new();
        info.insert(b"length".to_vec(), Ben::Int(length));
        info.insert(b"name".to_vec(), Ben::Bytes(name.to_vec()));
        info.insert(b"piece length".to_vec(), Ben::Int(PIECE_SIZE as i64));
        info.insert(
            b"pieces".to_vec(),
            Ben::Bytes(vec![0xABu8; 20 * piece_hashes]),
        );
        encode(&Ben::Dict(info))
    }

    fn v2_metadata() -> Vec<u8> {
        let leaf = |len: i64| {
            let mut inner = Dict::new();
            inner.insert(b"length".to_vec(), Ben::Int(len));
            inner.insert(b"pieces root".to_vec(), Ben::Bytes(vec![0u8; 32]));
            let mut l = Dict::new();
            l.insert(b"".to_vec(), Ben::Dict(inner));
            Ben::Dict(l)
        };
        let mut ft = Dict::new();
        ft.insert(b"file.bin".to_vec(), leaf(500));
        let mut info = Dict::new();
        info.insert(b"file tree".to_vec(), Ben::Dict(ft));
        info.insert(b"meta version".to_vec(), Ben::Int(2));
        info.insert(b"name".to_vec(), Ben::Bytes(b"v2dir".to_vec()));
        info.insert(b"piece length".to_vec(), Ben::Int(PIECE_SIZE as i64));
        encode(&Ben::Dict(info))
    }

    #[test]
    fn handshake_round_trip_and_extensions() {
        let ih = [0x11u8; 20];
        let pid = [0x22u8; 20];
        let hs = build_handshake(&ih, &pid, true);
        assert_eq!(hs.len(), HANDSHAKE_LEN);
        let (reserved, got_ih, got_pid) = parse_handshake(&hs).unwrap();
        assert!(supports_extensions(&reserved));
        assert_eq!(got_ih, ih);
        assert_eq!(got_pid, pid);
        // extensions off -> bit clear
        let (r2, _, _) = parse_handshake(&build_handshake(&ih, &pid, false)).unwrap();
        assert!(!supports_extensions(&r2));
        // bad handshakes rejected
        assert!(parse_handshake(&[0u8; 67]).is_err());
        assert!(parse_handshake(&[0u8; 68]).is_err());
    }

    #[test]
    fn piece_math() {
        assert_eq!(num_pieces(0), 0);
        assert_eq!(num_pieces(1), 1);
        assert_eq!(num_pieces(PIECE_SIZE), 1);
        assert_eq!(num_pieces(PIECE_SIZE + 1), 2);
        // last piece is the remainder
        assert_eq!(expected_piece_len(0, PIECE_SIZE + 5, 2), PIECE_SIZE);
        assert_eq!(expected_piece_len(1, PIECE_SIZE + 5, 2), 5);
        assert_eq!(expected_piece_len(0, 0, 0), 0); // guard: no underflow panic
    }

    #[test]
    fn total_size_saturates_on_hostile_lengths() {
        // Attacker controls the info-dict; summing i64::MAX file lengths must
        // saturate, not overflow-panic (debug) or wrap (release).
        let mkfile = |len: i64| {
            let mut e = Dict::new();
            e.insert(b"length".to_vec(), Ben::Int(len));
            e.insert(b"path".to_vec(), Ben::List(vec![Ben::Bytes(b"f".to_vec())]));
            Ben::Dict(e)
        };
        let mut d = Dict::new();
        d.insert(b"name".to_vec(), Ben::Bytes(b"x".to_vec()));
        d.insert(
            b"files".to_vec(),
            Ben::List(vec![mkfile(i64::MAX), mkfile(i64::MAX), mkfile(i64::MAX)]),
        );
        let m = parse_info(&d, Some([0u8; 20]), None).unwrap();
        assert_eq!(m.total_size, u64::MAX);
    }

    #[test]
    fn assemble_verifies_sha1() {
        let meta = v1_single_file(b"x", 10, 1);
        let ih = sha1(&meta);
        let pieces: Vec<Vec<u8>> = meta.chunks(PIECE_SIZE).map(<[u8]>::to_vec).collect();
        assert_eq!(
            assemble_and_verify(&pieces, &ih).as_deref(),
            Some(meta.as_slice())
        );
        assert_eq!(assemble_and_verify(&pieces, &[0u8; 20]), None); // wrong hash
    }

    #[test]
    fn parse_info_single_and_multi_file() {
        let meta = v1_single_file(b"hello.txt", 1234, 1);
        let Ben::Dict(info) = decode(&meta).unwrap() else {
            panic!()
        };
        let m = parse_info(&info, None, None).unwrap();
        assert_eq!(m.name, "hello.txt");
        assert_eq!(m.total_size, 1234);
        assert_eq!(m.piece_length, PIECE_SIZE as u64);
        assert_eq!(m.piece_count, 1);
        assert_eq!(m.files, vec![("hello.txt".to_string(), 1234)]);
        assert_eq!(m.info_hash, sha1(&meta));

        // multi-file
        let mut d = Dict::new();
        d.insert(b"name".to_vec(), Ben::Bytes(b"pack".to_vec()));
        let mkfile = |parts: &[&[u8]], len: i64| {
            let mut e = Dict::new();
            e.insert(b"length".to_vec(), Ben::Int(len));
            e.insert(
                b"path".to_vec(),
                Ben::List(parts.iter().map(|p| Ben::Bytes(p.to_vec())).collect()),
            );
            Ben::Dict(e)
        };
        d.insert(
            b"files".to_vec(),
            Ben::List(vec![
                mkfile(&[b"a", b"1.bin"], 100),
                mkfile(&[b"b.bin"], 50),
            ]),
        );
        let m2 = parse_info(&d, None, None).unwrap();
        assert_eq!(m2.total_size, 150);
        assert_eq!(
            m2.files,
            vec![("a/1.bin".to_string(), 100), ("b.bin".to_string(), 50)]
        );
    }

    #[test]
    fn v2_parse_and_verify() {
        let meta = v2_metadata();
        let Ben::Dict(info) = decode(&meta).unwrap() else {
            panic!()
        };
        assert!(is_v2_info(&info));
        assert!(!is_hybrid_info(&info));
        let v2_full = sha256(&meta);
        let m = parse_v2_info(&info, Some(&meta), Some(&truncate_v2(&v2_full))).unwrap();
        assert_eq!(m.version, "v2");
        assert_eq!(m.info_hash_v2, Some(v2_full));
        assert_eq!(m.info_hash, truncate_v2(&v2_full));
        assert_eq!(m.files, vec![("file.bin".to_string(), 500)]);
        // verify_v2 accepts both the 32- and 20-byte forms, rejects a wrong hash
        assert!(verify_v2(&meta, &v2_full));
        assert!(verify_v2(&meta, &truncate_v2(&v2_full)));
        assert!(!verify_v2(&meta, &[0u8; 32]));
        // a mismatched requested infohash is rejected (no silent substitute)
        assert!(parse_v2_info(&info, Some(&meta), Some(&[9u8; 20])).is_err());
    }

    #[test]
    fn walk_file_tree_bounds_depth() {
        // A pathologically deep file tree is rejected, not stack-overflowed.
        let mut leaf_inner = Dict::new();
        leaf_inner.insert(b"length".to_vec(), Ben::Int(1));
        let mut leaf = Dict::new();
        leaf.insert(b"".to_vec(), Ben::Dict(leaf_inner));
        let mut cur = Ben::Dict(leaf);
        for _ in 0..(MAX_TREE_DEPTH + 5) {
            let mut d = Dict::new();
            d.insert(b"x".to_vec(), cur);
            cur = Ben::Dict(d);
        }
        let Ben::Dict(tree) = cur else { panic!() };
        assert!(walk_file_tree(&tree).is_err());
    }

    /// Regression: the `file tree` walk must bound its **output**, not just its
    /// input. `MAX_METADATA_SIZE` / `MAX_TREE_DEPTH` / `MAX_TREE_NODES` all cap
    /// what arrives on the wire, but each leaf re-materialises its whole key
    /// prefix, so `{<big name>: {<many short names>: {"": {"length": 1}}}}` costs
    /// `leaves × prefix` bytes of `String`. The 100 000-leaf / 6 MiB-name shape is
    /// 8.19 MiB on the wire (under every input cap, and hash-consistent because
    /// the attacker publishes its SHA-1 himself) and projects to ~585 GiB → OOM.
    /// Here 200 leaves under a 64 KiB name = ~12.8 MiB of paths must be rejected;
    /// before the fix this returned `Ok` with all 200 paths materialised.
    #[test]
    fn walk_file_tree_bounds_total_path_bytes() {
        let leaf = || {
            let mut inner = Dict::new();
            inner.insert(b"length".to_vec(), Ben::Int(1));
            let mut l = Dict::new();
            l.insert(b"".to_vec(), Ben::Dict(inner));
            Ben::Dict(l)
        };
        let big_name = vec![b'A'; 64 * 1024];
        let tree_with = |leaves: usize| {
            let mut children = Dict::new();
            for i in 0..leaves {
                children.insert(format!("f{i:06}").into_bytes(), leaf());
            }
            let mut tree = Dict::new();
            tree.insert(big_name.clone(), Ben::Dict(children));
            tree
        };
        // 200 × ~64 KiB of prefix ≈ 12.8 MiB of output → refused.
        let err = walk_file_tree(&tree_with(200)).expect_err("path amplification refused");
        assert!(
            err.message().contains("paths too large"),
            "unexpected error: {err}"
        );
        // The same shape within budget still parses (the guard doesn't over-reach):
        // 100 × ~64 KiB ≈ 6.4 MiB < MAX_TREE_PATH_BYTES.
        let ok = walk_file_tree(&tree_with(100)).expect("under the cap");
        assert_eq!(ok.len(), 100);
        assert!(ok[0].0.starts_with("AAAA"));
        // Sanity: an ordinary tree is unaffected.
        let mut plain = Dict::new();
        plain.insert(b"dir".to_vec(), {
            let mut d = Dict::new();
            d.insert(b"file.bin".to_vec(), leaf());
            Ben::Dict(d)
        });
        assert_eq!(
            walk_file_tree(&plain).unwrap(),
            vec![("dir/file.bin".to_string(), 1)]
        );
    }

    #[test]
    fn parse_magnet_matches_python() {
        let v1 = [
            0x01, 0x23, 0x45, 0x67, 0x89, 0xab, 0xcd, 0xef, 0x01, 0x23, 0x45, 0x67, 0x89, 0xab,
            0xcd, 0xef, 0x01, 0x23, 0x45, 0x67,
        ];
        let v2 = [0xAAu8; 32];

        // v1 hex + display name ("Test+Name" -> space)
        let m = parse_magnet(
            "magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&dn=Test+Name",
        )
        .unwrap();
        assert_eq!(m.v1_infohash, Some(v1));
        assert_eq!(m.name.as_deref(), Some("Test Name"));
        assert_eq!(m.dht_infohash(), Some(v1));

        // v1 base32 form (same 20 bytes)
        let mb = parse_magnet("magnet:?xt=urn:btih:AERUKZ4JVPG66AJDIVTYTK6N54ASGRLH").unwrap();
        assert_eq!(mb.v1_infohash, Some(v1));

        // v2 btmh + %20 in the name; dht infohash is the truncated v2
        let m2 = parse_magnet(&format!(
            "magnet:?xt=urn:btmh:1220{}&dn=v2%20movie",
            "aa".repeat(32)
        ))
        .unwrap();
        assert_eq!(m2.v2_infohash, Some(v2));
        assert_eq!(m2.v1_infohash, None);
        assert_eq!(m2.name.as_deref(), Some("v2 movie"));
        assert_eq!(m2.dht_infohash(), Some([0xAAu8; 20]));

        // hybrid: both present, dht prefers v1
        let mh = parse_magnet(&format!(
            "magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&xt=urn:btmh:1220{}",
            "aa".repeat(32)
        ))
        .unwrap();
        assert_eq!(mh.v1_infohash, Some(v1));
        assert_eq!(mh.v2_infohash, Some(v2));
        assert_eq!(mh.dht_infohash(), Some(v1));

        // not a magnet / no usable xt -> error
        assert!(parse_magnet("http://example/x").is_err());
        assert!(parse_magnet("magnet:?dn=nothing").is_err());
        // fail closed (like Python): a recognised urn that fails to decode aborts
        assert!(parse_magnet("magnet:?xt=urn:btih:ZZZZ").is_err());
        assert!(parse_magnet(
            "magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567&xt=urn:btmh:GARBAGE"
        )
        .is_err());
    }

    // --- async client/server round-trips (require the `net` feature) ---
    #[cfg(feature = "net")]
    use std::time::Duration;

    #[cfg(feature = "net")]
    #[tokio::test]
    async fn fetch_round_trip_single_piece() {
        let meta = v1_single_file(b"hello.txt", 1234, 1);
        let ih = sha1(&meta);
        let (addr, handle) = serve_metadata(meta.clone(), false).await.unwrap();
        let got = fetch_metadata(
            &ih,
            &addr.ip().to_string(),
            addr.port(),
            Duration::from_secs(5),
            None,
            None,
        )
        .await
        .unwrap();
        assert_eq!(got.name, "hello.txt");
        assert_eq!(got.total_size, 1234);
        assert_eq!(got.info_hash, ih);
        assert_eq!(got.info_bytes.as_deref(), Some(meta.as_slice()));
        handle.abort();
    }

    #[cfg(feature = "net")]
    #[tokio::test]
    async fn fetch_round_trip_multi_piece() {
        // ~40 KB info-dict spans 3 ut_metadata pieces.
        let meta = v1_single_file(b"big.bin", 100_000_000, 2000);
        assert!(meta.len() > 2 * PIECE_SIZE);
        let ih = sha1(&meta);
        let (addr, handle) = serve_metadata(meta.clone(), false).await.unwrap();
        let got = fetch_metadata(
            &ih,
            &addr.ip().to_string(),
            addr.port(),
            Duration::from_secs(5),
            None,
            None,
        )
        .await
        .unwrap();
        assert_eq!(got.name, "big.bin");
        assert_eq!(got.piece_count, 2000);
        assert_eq!(got.info_bytes.as_deref(), Some(meta.as_slice()));
        handle.abort();
    }

    #[cfg(feature = "net")]
    #[tokio::test]
    async fn fetch_rejects_corrupt_metadata() {
        let meta = v1_single_file(b"hello.txt", 1234, 1);
        let ih = sha1(&meta);
        let (addr, handle) = serve_metadata(meta, true).await.unwrap(); // corrupt=true
        let r = fetch_metadata(
            &ih,
            &addr.ip().to_string(),
            addr.port(),
            Duration::from_secs(5),
            None,
            None,
        )
        .await;
        assert!(r.is_err(), "corrupt metadata must fail verification");
        handle.abort();
    }

    #[cfg(feature = "net")]
    #[tokio::test]
    async fn fetch_v2_round_trip() {
        let meta = v2_metadata();
        let v2_full = sha256(&meta);
        let dht20 = truncate_v2(&v2_full); // 20-byte truncated SHA-256 on the wire
        let (addr, handle) = serve_metadata(meta.clone(), false).await.unwrap();
        let got = fetch_metadata(
            &dht20,
            &addr.ip().to_string(),
            addr.port(),
            Duration::from_secs(5),
            None,
            Some(&v2_full),
        )
        .await
        .unwrap();
        assert_eq!(got.version, "v2");
        assert_eq!(got.info_hash_v2, Some(v2_full));
        assert_eq!(got.files, vec![("file.bin".to_string(), 500)]);
        handle.abort();
    }
}
