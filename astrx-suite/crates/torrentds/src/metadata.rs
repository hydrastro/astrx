//! BitTorrent peer wire + ut_metadata (BEP-3 / BEP-10 / BEP-9).
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
//! The pure builders/parsers and [`assemble_and_verify`] are unit-testable with
//! crafted bytes (and cross-checked byte-identical to the Python reference);
//! [`fetch_metadata`] + [`serve_metadata`] give a full loopback round-trip.
//!
//! Hostile-input hardening mirrors the reference: `metadata_size` and every
//! peer-wire frame are capped before allocation, and each ut_metadata piece must
//! be exactly its BEP-9 length, so a peer cannot force us to buffer far more than
//! the advertised (and bounded) metadata size before the final hash check.

use crate::bencode::{decode, decode_lenient, decode_prefix, encode, Ben};
use crate::infohash::{sha1, sha256};
use crate::krpc::Dict;
use std::net::SocketAddr;
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

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

/// Any metadata-fetch failure (bad handshake, hostile bytes, hash mismatch, I/O).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MetadataError(pub String);

impl std::fmt::Display for MetadataError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "metadata: {}", self.0)
    }
}
impl std::error::Error for MetadataError {}

fn merr<T>(msg: impl Into<String>) -> Result<T, MetadataError> {
    Err(MetadataError(msg.into()))
}

// --- BEP-3 handshake -------------------------------------------------------

/// Build the 68-byte peer handshake; sets the BEP-10 extension bit when asked.
pub fn build_handshake(info_hash: &[u8; 20], peer_id: &[u8; 20], extensions: bool) -> Vec<u8> {
    let mut reserved = [0u8; 8];
    if extensions {
        reserved[5] |= 0x10; // BEP-10 extension-protocol bit
    }
    let mut out = Vec::with_capacity(HANDSHAKE_LEN);
    out.push(BT_PROTOCOL.len() as u8);
    out.extend_from_slice(BT_PROTOCOL);
    out.extend_from_slice(&reserved);
    out.extend_from_slice(info_hash);
    out.extend_from_slice(peer_id);
    out
}

/// `(reserved, info_hash, peer_id)` decoded from a peer handshake.
pub type Handshake = ([u8; 8], [u8; 20], [u8; 20]);

/// Parse a handshake into `(reserved, info_hash, peer_id)`.
pub fn parse_handshake(data: &[u8]) -> Result<Handshake, MetadataError> {
    if data.len() != HANDSHAKE_LEN {
        return merr("handshake must be 68 bytes");
    }
    if data[0] as usize != BT_PROTOCOL.len() || &data[1..20] != BT_PROTOCOL {
        return merr("not a BitTorrent handshake");
    }
    let mut reserved = [0u8; 8];
    let mut info_hash = [0u8; 20];
    let mut peer_id = [0u8; 20];
    reserved.copy_from_slice(&data[20..28]);
    info_hash.copy_from_slice(&data[28..48]);
    peer_id.copy_from_slice(&data[48..68]);
    Ok((reserved, info_hash, peer_id))
}

/// True if the reserved bytes advertise BEP-10 extension support.
pub fn supports_extensions(reserved: &[u8; 8]) -> bool {
    reserved[5] & 0x10 != 0
}

// --- BEP-3 framing + BEP-10 extended messages ------------------------------

/// Length-prefixed peer message: `<u32 len><msg_id><payload>`.
pub fn build_message(msg_id: u8, payload: &[u8]) -> Vec<u8> {
    let len = 1 + payload.len();
    let mut out = Vec::with_capacity(4 + len);
    out.extend_from_slice(&(len as u32).to_be_bytes());
    out.push(msg_id);
    out.extend_from_slice(payload);
    out
}

/// An extended (BEP-10) message: `msg_id = 20`, first payload byte is `ext_id`.
pub fn build_ext_message(ext_id: u8, payload: &[u8]) -> Vec<u8> {
    let mut body = Vec::with_capacity(1 + payload.len());
    body.push(ext_id);
    body.extend_from_slice(payload);
    build_message(EXT_MSG_ID, &body)
}

/// The extended handshake (ext id 0). `ut_metadata_id` is the id we ask the peer
/// to use for ut_metadata sent to us; `metadata_size` is set by whoever holds it.
pub fn build_ext_handshake(metadata_size: Option<i64>, ut_metadata_id: i64) -> Vec<u8> {
    let mut m = Dict::new();
    m.insert(b"ut_metadata".to_vec(), Ben::Int(ut_metadata_id));
    let mut d = Dict::new();
    d.insert(b"m".to_vec(), Ben::Dict(m));
    if let Some(n) = metadata_size {
        d.insert(b"metadata_size".to_vec(), Ben::Int(n));
    }
    build_ext_message(0, &encode(&Ben::Dict(d)))
}

pub fn build_ut_metadata_request(piece: i64, ext_id: u8) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"msg_type".to_vec(), Ben::Int(UT_REQUEST));
    d.insert(b"piece".to_vec(), Ben::Int(piece));
    build_ext_message(ext_id, &encode(&Ben::Dict(d)))
}

pub fn build_ut_metadata_data(piece: i64, total_size: i64, data: &[u8], ext_id: u8) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"msg_type".to_vec(), Ben::Int(UT_DATA));
    d.insert(b"piece".to_vec(), Ben::Int(piece));
    d.insert(b"total_size".to_vec(), Ben::Int(total_size));
    let mut payload = encode(&Ben::Dict(d));
    payload.extend_from_slice(data);
    build_ext_message(ext_id, &payload)
}

pub fn build_ut_metadata_reject(piece: i64, ext_id: u8) -> Vec<u8> {
    let mut d = Dict::new();
    d.insert(b"msg_type".to_vec(), Ben::Int(UT_REJECT));
    d.insert(b"piece".to_vec(), Ben::Int(piece));
    build_ext_message(ext_id, &encode(&Ben::Dict(d)))
}

// --- assembly + verification + info-dict parsing ---------------------------

/// Number of 16 KiB pieces an info-dict of `metadata_size` bytes spans.
pub fn num_pieces(metadata_size: usize) -> usize {
    metadata_size.div_ceil(PIECE_SIZE)
}

/// Exact byte length ut_metadata piece `idx` must carry: [`PIECE_SIZE`] for every
/// piece but the last, which is the remainder. Enforcing this bounds retained
/// memory to the advertised (bounded) total instead of `pieces * MAX_MESSAGE_LEN`.
pub fn expected_piece_len(idx: usize, metadata_size: usize, total_pieces: usize) -> usize {
    if total_pieces == 0 {
        0
    } else if idx + 1 < total_pieces {
        PIECE_SIZE
    } else {
        metadata_size - (total_pieces - 1) * PIECE_SIZE
    }
}

/// Concatenate ordered pieces and check `sha1(metadata) == info_hash`. Returns
/// the metadata on success, `None` on mismatch.
pub fn assemble_and_verify(pieces: &[Vec<u8>], info_hash: &[u8; 20]) -> Option<Vec<u8>> {
    let metadata = pieces.concat();
    if sha1(&metadata) == *info_hash {
        Some(metadata)
    } else {
        None
    }
}

/// A parsed torrent info-dict (v1, v2, or hybrid).
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TorrentMeta {
    /// The 20-byte DHT/peer-wire infohash: v1 SHA-1 for v1/hybrid, truncated
    /// SHA-256 for v2-only.
    pub info_hash: [u8; 20],
    pub name: String,
    pub total_size: u64,
    pub piece_length: u64,
    pub piece_count: usize,
    pub files: Vec<(String, u64)>,
    /// The raw, verified info-dict bytes (kept so a byte-exact `.torrent` can be
    /// rebuilt whose info section still hashes to the infohash).
    pub info_bytes: Option<Vec<u8>>,
    /// The full 32-byte SHA-256 infohash (BEP-52); `None` for pure v1.
    pub info_hash_v2: Option<[u8; 32]>,
    /// `"v1"`, `"v2"`, or `"hybrid"`.
    pub version: &'static str,
    /// A name-independent content fingerprint: SHA-256 of the v1 `pieces` blob or
    /// the v2 `file tree`; `None` when no piece data exists.
    pub content_id: Option<[u8; 32]>,
}

/// Decode a SHA-1-verified info-dict, tolerating mildly non-canonical real data
/// (the caller has already checked `sha1(metadata) == info_hash` on these exact
/// bytes, so a lenient decode cannot weaken security).
pub fn decode_info_dict(metadata: &[u8]) -> Result<Dict, MetadataError> {
    let value = match decode(metadata) {
        Ok(v) => v,
        Err(_) => decode_lenient(metadata)
            .map_err(|e| MetadataError(format!("undecodable info-dict: {}", e.0)))?,
    };
    match value {
        Ben::Dict(d) => Ok(d),
        _ => merr("info-dict is not a dict"),
    }
}

fn ben_int(d: &Dict, key: &[u8]) -> i64 {
    match d.get(key) {
        Some(Ben::Int(n)) => *n,
        _ => 0,
    }
}

/// Parse an info-dict into a [`TorrentMeta`], routing BEP-52 v2/hybrid dicts to
/// [`parse_v2_info`] and everything else through the classic v1 layout.
/// `info_hash` is used verbatim when supplied, else recomputed.
pub fn parse_info(
    info: &Dict,
    info_hash: Option<[u8; 20]>,
    info_bytes: Option<Vec<u8>>,
) -> Result<TorrentMeta, MetadataError> {
    if is_v2_info(info) {
        return parse_v2_info(
            info,
            info_bytes.as_deref(),
            info_hash.as_ref().map(<[u8; 20]>::as_slice),
        );
    }
    let name = match info.get(b"name".as_slice()) {
        Some(Ben::Bytes(b)) => String::from_utf8_lossy(b).into_owned(),
        _ => String::new(),
    };
    let piece_length = ben_int(info, b"piece length").max(0) as u64;
    let pieces_blob = match info.get(b"pieces".as_slice()) {
        Some(Ben::Bytes(b)) => Some(b),
        _ => None,
    };
    let piece_count = pieces_blob.map_or(0, |b| b.len() / 20);

    let mut files: Vec<(String, u64)> = Vec::new();
    let total_size;
    if let Some(Ben::List(entries)) = info.get(b"files".as_slice()) {
        for entry in entries {
            if let Ben::Dict(ed) = entry {
                let length = ben_int(ed, b"length").max(0) as u64;
                let path = match ed.get(b"path".as_slice()) {
                    Some(Ben::List(parts)) => parts
                        .iter()
                        .filter_map(|p| match p {
                            Ben::Bytes(b) => Some(String::from_utf8_lossy(b).into_owned()),
                            _ => None,
                        })
                        .collect::<Vec<_>>()
                        .join("/"),
                    _ => String::new(),
                };
                let path = if path.is_empty() { name.clone() } else { path };
                files.push((path, length));
            }
        }
        // Saturating: an attacker crafts the info-dict (its SHA-1 is the infohash),
        // so file lengths are hostile; summing i64::MAX-sized entries must not
        // overflow-panic (debug) or wrap (release).
        total_size = files
            .iter()
            .fold(0u64, |acc, (_, l)| acc.saturating_add(*l));
    } else {
        let length = ben_int(info, b"length").max(0) as u64;
        files.push((name.clone(), length));
        total_size = length;
    }

    // Name-independent content fingerprint: the v1 piece-hash blob.
    let content_id = pieces_blob.filter(|b| !b.is_empty()).map(|b| sha256(b));
    let info_hash = info_hash.unwrap_or_else(|| sha1(&encode(&Ben::Dict(info.clone()))));
    Ok(TorrentMeta {
        info_hash,
        name,
        total_size,
        piece_length,
        piece_count,
        files,
        info_bytes,
        info_hash_v2: None,
        version: "v1",
        content_id,
    })
}

/// `sha1(encode(info))` — the v1 infohash of an info-dict.
pub fn infohash_of(info: &Dict) -> [u8; 20] {
    sha1(&encode(&Ben::Dict(info.clone())))
}

// --- BEP-52 v2 / hybrid ----------------------------------------------------

/// True if `info` is a BEP-52 v2 (or hybrid) info-dict.
pub fn is_v2_info(info: &Dict) -> bool {
    matches!(info.get(b"meta version".as_slice()), Some(Ben::Int(2)))
        && matches!(info.get(b"file tree".as_slice()), Some(Ben::Dict(_)))
}

/// True if `info` carries BOTH v2 and v1 (`pieces`) structures.
pub fn is_hybrid_info(info: &Dict) -> bool {
    is_v2_info(info) && matches!(info.get(b"pieces".as_slice()), Some(Ben::Bytes(_)))
}

/// The 20-byte truncated v2 infohash used where the DHT/peer wire needs 20 bytes.
pub fn truncate_v2(info_hash_v2: &[u8; 32]) -> [u8; 20] {
    let mut t = [0u8; 20];
    t.copy_from_slice(&info_hash_v2[..20]);
    t
}

/// Byte-exact v2 verification: recompute SHA-256 over `info_bytes` and compare to
/// `expected` (32-byte full, or 20-byte truncated DHT form). Any other length is
/// rejected.
pub fn verify_v2(info_bytes: &[u8], expected: &[u8]) -> bool {
    let digest = sha256(info_bytes);
    match expected.len() {
        32 => digest == expected,
        20 => digest[..20] == *expected,
        _ => false,
    }
}

/// v2 analogue of [`assemble_and_verify`] (SHA-256 instead of SHA-1).
pub fn assemble_and_verify_v2(pieces: &[Vec<u8>], info_hash_v2: &[u8]) -> Option<Vec<u8>> {
    let metadata = pieces.concat();
    verify_v2(&metadata, info_hash_v2).then_some(metadata)
}

/// Flatten a BEP-52 `file tree` into `[(path, length), …]`. Each leaf is a
/// `{"": {"length": N, …}}` node whose accumulated key path is the file path. The
/// recursion is bounded on both depth and total node count — the tree is hostile
/// network data.
pub fn walk_file_tree(file_tree: &Dict) -> Result<Vec<(String, u64)>, MetadataError> {
    let mut out = Vec::new();
    let mut nodes = 0usize;
    let mut prefix: Vec<String> = Vec::new();
    walk_tree_rec(file_tree, &mut prefix, 0, &mut nodes, &mut out)?;
    Ok(out)
}

fn walk_tree_rec(
    node: &Dict,
    prefix: &mut Vec<String>,
    depth: usize,
    nodes: &mut usize,
    out: &mut Vec<(String, u64)>,
) -> Result<(), MetadataError> {
    if depth > MAX_TREE_DEPTH {
        return merr(format!("file tree nested too deeply (>{MAX_TREE_DEPTH})"));
    }
    // A file leaf: the empty-string key holds the length / pieces-root.
    if let Some(Ben::Dict(leaf)) = node.get(b"".as_slice()) {
        if leaf.contains_key(b"length".as_slice()) {
            let length = ben_int(leaf, b"length").max(0) as u64;
            out.push((prefix.join("/"), length));
            return Ok(());
        }
    }
    for (name, child) in node {
        *nodes += 1;
        if *nodes > MAX_TREE_NODES {
            return merr(format!("file tree too large (>{MAX_TREE_NODES} nodes)"));
        }
        if name.is_empty() {
            continue; // the leaf key, handled above
        }
        let Ben::Dict(child_dict) = child else {
            continue;
        };
        prefix.push(String::from_utf8_lossy(name).into_owned());
        walk_tree_rec(child_dict, prefix, depth + 1, nodes, out)?;
        prefix.pop();
    }
    Ok(())
}

/// Parse a BEP-52 v2 (or hybrid) info-dict. The v2 infohash is SHA-256 over the
/// raw verified bytes when supplied (so a non-canonical dict still hashes right),
/// else over a re-encode. When `dht_info_hash` is given the recomputed hash is
/// verified against it (full 32-byte, 20-byte primary, or truncated SHA-256); a
/// mismatch is rejected so a substitute dict is never silently accepted.
pub fn parse_v2_info(
    info: &Dict,
    info_bytes: Option<&[u8]>,
    dht_info_hash: Option<&[u8]>,
) -> Result<TorrentMeta, MetadataError> {
    let Some(Ben::Dict(file_tree)) = info.get(b"file tree".as_slice()) else {
        return merr("v2 info-dict has no file tree");
    };
    let name = match info.get(b"name".as_slice()) {
        Some(Ben::Bytes(b)) => String::from_utf8_lossy(b).into_owned(),
        _ => String::new(),
    };
    let piece_length = ben_int(info, b"piece length").max(0) as u64;
    let files = walk_file_tree(file_tree)?;
    let total_size = files
        .iter()
        .fold(0u64, |acc, (_, l)| acc.saturating_add(*l));

    let raw = match info_bytes {
        Some(b) => b.to_vec(),
        None => encode(&Ben::Dict(info.clone())),
    };
    let v2_full = sha256(&raw);
    let truncated = truncate_v2(&v2_full);

    let (piece_count, version, primary) = if is_hybrid_info(info) {
        let pc = match info.get(b"pieces".as_slice()) {
            Some(Ben::Bytes(b)) => b.len() / 20,
            _ => 0,
        };
        (pc, "hybrid", sha1(&raw)) // hybrid: the DHT key is the v1 SHA-1
    } else {
        let pc = if piece_length > 0 {
            total_size.div_ceil(piece_length) as usize
        } else {
            0
        };
        (pc, "v2", truncated) // v2-only: the DHT key is the truncated SHA-256
    };

    if let Some(dht) = dht_info_hash {
        let ok = match dht.len() {
            32 => dht == v2_full.as_slice(),
            20 => dht == primary.as_slice() || dht == truncated.as_slice(),
            _ => false,
        };
        if !ok {
            return merr("v2 info-dict does not match requested infohash");
        }
    }

    // Content fingerprint: the file-tree digest (paths + lengths + pieces roots).
    let content_id = Some(sha256(&encode(&Ben::Dict(file_tree.clone()))));
    Ok(TorrentMeta {
        info_hash: primary,
        name,
        total_size,
        piece_length,
        piece_count,
        files,
        info_bytes: info_bytes.map(<[u8]>::to_vec),
        info_hash_v2: Some(v2_full),
        version,
        content_id,
    })
}

// --- magnet URIs -----------------------------------------------------------

/// A parsed magnet URI: a v1 and/or v2 infohash plus an optional display name.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Magnet {
    pub v1_infohash: Option<[u8; 20]>,
    pub v2_infohash: Option<[u8; 32]>,
    pub name: Option<String>,
}

impl Magnet {
    /// The 20-byte infohash for the DHT / peer wire: v1 if present, else the
    /// truncated v2 SHA-256.
    pub fn dht_infohash(&self) -> Option<[u8; 20]> {
        if let Some(v1) = self.v1_infohash {
            return Some(v1);
        }
        self.v2_infohash.map(|v2| {
            let mut t = [0u8; 20];
            t.copy_from_slice(&v2[..20]);
            t
        })
    }
}

fn hex_decode(s: &str) -> Option<Vec<u8>> {
    let b = s.as_bytes();
    if b.len() % 2 != 0 {
        return None;
    }
    let mut out = Vec::with_capacity(b.len() / 2);
    for pair in b.chunks_exact(2) {
        let hi = (pair[0] as char).to_digit(16)?;
        let lo = (pair[1] as char).to_digit(16)?;
        out.push((hi << 4 | lo) as u8);
    }
    Some(out)
}

/// RFC-4648 base32 decode (no padding needed for the 32-char btih case).
fn base32_decode(s: &str) -> Option<Vec<u8>> {
    let mut bits = 0u32;
    let mut nbits = 0u32;
    let mut out = Vec::new();
    for c in s.chars() {
        let v = match c.to_ascii_uppercase() {
            u @ 'A'..='Z' => u as u32 - 'A' as u32,
            d @ '2'..='7' => d as u32 - '2' as u32 + 26,
            '=' => break,
            _ => return None,
        };
        bits = (bits << 5) | v;
        nbits += 5;
        if nbits >= 8 {
            nbits -= 8;
            out.push((bits >> nbits) as u8);
        }
    }
    Some(out)
}

fn decode_btih(value: &str) -> Option<[u8; 20]> {
    let value = value.trim();
    let raw = match value.len() {
        40 => hex_decode(value)?,
        32 => base32_decode(value)?,
        _ => return None,
    };
    (raw.len() == 20).then(|| {
        let mut a = [0u8; 20];
        a.copy_from_slice(&raw);
        a
    })
}

fn decode_btmh(value: &str) -> Option<[u8; 32]> {
    let raw = hex_decode(value.trim())?;
    // multihash: 0x12 0x20 (sha2-256, 32 bytes) then exactly 32 digest bytes.
    if raw.len() == 34 && raw[0] == 0x12 && raw[1] == 0x20 {
        let mut a = [0u8; 32];
        a.copy_from_slice(&raw[2..]);
        Some(a)
    } else {
        None
    }
}

/// Decode `%XX` escapes and `+`→space (query-value semantics, like `parse_qs`).
fn pct_decode(s: &str) -> String {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%' && i + 2 < b.len() {
            if let (Some(h), Some(l)) = (
                (b[i + 1] as char).to_digit(16),
                (b[i + 2] as char).to_digit(16),
            ) {
                out.push((h * 16 + l) as u8);
                i += 3;
                continue;
            }
        }
        out.push(if b[i] == b'+' { b' ' } else { b[i] });
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// Parse `magnet:?xt=urn:btih:…` (v1) and/or `xt=urn:btmh:1220<64hex>` (v2),
/// plus an optional `dn` display name. Supports hybrid magnets (both `xt`).
pub fn parse_magnet(uri: &str) -> Result<Magnet, MetadataError> {
    if !uri.starts_with("magnet:") {
        return merr("not a magnet URI");
    }
    let query = uri.split_once('?').map_or("", |x| x.1);
    let mut m = Magnet::default();
    for pair in query.split('&') {
        let (key, value) = pair.split_once('=').unwrap_or((pair, ""));
        match key {
            "xt" => {
                // Fail closed, like Python: an `xt` carrying a recognised urn that
                // fails to decode aborts the parse rather than silently dropping it.
                let value = pct_decode(value);
                if let Some(rest) = value.strip_prefix("urn:btih:") {
                    m.v1_infohash =
                        Some(decode_btih(rest).ok_or_else(|| MetadataError("bad btih".into()))?);
                } else if let Some(rest) = value.strip_prefix("urn:btmh:") {
                    m.v2_infohash =
                        Some(decode_btmh(rest).ok_or_else(|| MetadataError("bad btmh".into()))?);
                }
            }
            "dn" => m.name = Some(pct_decode(value)),
            _ => {}
        }
    }
    if m.v1_infohash.is_none() && m.v2_infohash.is_none() {
        return merr("magnet has no usable xt (btih/btmh)");
    }
    Ok(m)
}

// --- async client: fetch metadata from a peer ------------------------------

fn random_peer_id() -> [u8; 20] {
    let mut id = [0u8; 20];
    id[..8].copy_from_slice(b"-TD0001-");
    let mut rnd = [0u8; 12];
    let _ = getrandom::getrandom(&mut rnd);
    id[8..].copy_from_slice(&rnd);
    id
}

async fn read_exact_to(
    stream: &mut TcpStream,
    n: usize,
    timeout: Duration,
) -> Result<Vec<u8>, MetadataError> {
    let mut buf = vec![0u8; n];
    match tokio::time::timeout(timeout, stream.read_exact(&mut buf)).await {
        Ok(Ok(_)) => Ok(buf),
        Ok(Err(e)) => merr(format!("peer connection failed: {e}")),
        Err(_) => merr("read timed out"),
    }
}

async fn write_all_to(stream: &mut TcpStream, bytes: &[u8]) -> Result<(), MetadataError> {
    stream
        .write_all(bytes)
        .await
        .map_err(|e| MetadataError(format!("peer connection failed: {e}")))
}

/// Read one length-prefixed peer message → `(msg_id, payload)`. A keep-alive is
/// `(KEEPALIVE, [])`; for extended messages `msg_id == 20` and `payload[0]` is
/// the extended id. Frames beyond [`MAX_MESSAGE_LEN`] are rejected pre-alloc.
pub async fn read_message(
    stream: &mut TcpStream,
    timeout: Duration,
) -> Result<(i32, Vec<u8>), MetadataError> {
    let header = read_exact_to(stream, 4, timeout).await?;
    let length = u32::from_be_bytes([header[0], header[1], header[2], header[3]]) as usize;
    if length == 0 {
        return Ok((KEEPALIVE, Vec::new()));
    }
    if length > MAX_MESSAGE_LEN {
        return merr(format!("peer message too large: {length} bytes"));
    }
    let body = read_exact_to(stream, length, timeout).await?;
    Ok((i32::from(body[0]), body[1..].to_vec()))
}

/// Read messages until the peer's extended handshake (ext id 0), returning
/// `(their ut_metadata id, metadata_size)`.
async fn read_ext_handshake(
    stream: &mut TcpStream,
    timeout: Duration,
) -> Result<(Option<i64>, Option<i64>), MetadataError> {
    loop {
        let (msg_id, payload) = read_message(stream, timeout).await?;
        if msg_id == i32::from(EXT_MSG_ID) && !payload.is_empty() && payload[0] == 0 {
            if let Ok(Ben::Dict(d)) = decode(&payload[1..]) {
                let ut = match d.get(b"m".as_slice()) {
                    Some(Ben::Dict(m)) => match m.get(b"ut_metadata".as_slice()) {
                        Some(Ben::Int(id)) => Some(*id),
                        _ => None,
                    },
                    _ => None,
                };
                let size = match d.get(b"metadata_size".as_slice()) {
                    Some(Ben::Int(n)) => Some(*n),
                    _ => None,
                };
                return Ok((ut, size));
            }
        }
    }
}

/// Connect to a peer and fetch + verify the info-dict for `info_hash` (BEP-9).
///
/// `timeout` bounds the **entire** fetch, not just each read: a hostile peer that
/// trickles keep-alives (or data for already-filled pieces) one-per-read-window
/// can never make `received` advance, so a per-read timer alone would let it pin
/// the connection open forever. The overall deadline here closes that off.
/// The 20-byte `info_hash` is always what goes on the BEP-3 handshake. When
/// `info_hash_v2` (20-byte truncated or 32-byte full SHA-256) is given, the
/// assembled metadata is verified with SHA-256 (BEP-52) instead of SHA-1.
pub async fn fetch_metadata(
    info_hash: &[u8; 20],
    host: &str,
    port: u16,
    timeout: Duration,
    peer_id: Option<[u8; 20]>,
    info_hash_v2: Option<&[u8]>,
) -> Result<TorrentMeta, MetadataError> {
    let peer_id = peer_id.unwrap_or_else(random_peer_id);
    match tokio::time::timeout(
        timeout,
        fetch_inner(info_hash, host, port, timeout, peer_id, info_hash_v2),
    )
    .await
    {
        Ok(result) => result,
        Err(_) => merr("metadata fetch exceeded deadline"),
    }
}

async fn fetch_inner(
    info_hash: &[u8; 20],
    host: &str,
    port: u16,
    timeout: Duration,
    peer_id: [u8; 20],
    info_hash_v2: Option<&[u8]>,
) -> Result<TorrentMeta, MetadataError> {
    let mut stream = match tokio::time::timeout(timeout, TcpStream::connect((host, port))).await {
        Ok(Ok(s)) => s,
        Ok(Err(e)) => return merr(format!("peer connection failed: {e}")),
        Err(_) => return merr("connect timed out"),
    };

    // BEP-3 handshake.
    write_all_to(&mut stream, &build_handshake(info_hash, &peer_id, true)).await?;
    let (reserved, their_ih, _pid) =
        parse_handshake(&read_exact_to(&mut stream, HANDSHAKE_LEN, timeout).await?)?;
    if their_ih != *info_hash {
        return merr("peer served a different info_hash");
    }
    if !supports_extensions(&reserved) {
        return merr("peer does not support BEP-10 extensions");
    }

    // BEP-10 extended handshake.
    write_all_to(&mut stream, &build_ext_handshake(None, 1)).await?;
    let (peer_ut_id, metadata_size) = read_ext_handshake(&mut stream, timeout).await?;

    let peer_ut = peer_ut_id.filter(|&x| x > 0);
    let msize = metadata_size.filter(|&n| n > 0);
    let (Some(peer_ut), Some(msize)) = (peer_ut, msize) else {
        return merr("peer does not offer ut_metadata");
    };
    if msize as usize > MAX_METADATA_SIZE {
        return merr(format!("advertised metadata_size too large: {msize}"));
    }
    let ext_id =
        u8::try_from(peer_ut).map_err(|_| MetadataError("invalid ut_metadata id".into()))?;
    let metadata_size = msize as usize;
    let total_pieces = num_pieces(metadata_size);

    // BEP-9: request every piece, then collect data messages.
    for i in 0..total_pieces {
        write_all_to(&mut stream, &build_ut_metadata_request(i as i64, ext_id)).await?;
    }
    let mut pieces: Vec<Option<Vec<u8>>> = vec![None; total_pieces];
    let mut received = 0;
    while received < total_pieces {
        let (msg_id, payload) = read_message(&mut stream, timeout).await?;
        if msg_id != i32::from(EXT_MSG_ID) || payload.is_empty() {
            continue;
        }
        let body = &payload[1..]; // strip the extended id
        let (header, consumed) = match decode_prefix(body) {
            Ok(x) => x,
            Err(_) => continue,
        };
        let Ben::Dict(h) = header else { continue };
        match h.get(b"msg_type".as_slice()) {
            Some(Ben::Int(t)) if *t == UT_REJECT => return merr("peer rejected metadata request"),
            Some(Ben::Int(t)) if *t == UT_DATA => {}
            _ => continue,
        }
        let idx = match h.get(b"piece".as_slice()) {
            Some(Ben::Int(i)) if *i >= 0 && (*i as usize) < total_pieces => *i as usize,
            _ => continue,
        };
        if pieces[idx].is_none() {
            let piece = body[consumed..].to_vec();
            // BEP-9 fixes each piece length; a wrong-sized piece is provably bogus
            // and aborting bounds memory to metadata_size, not pieces * 1 MiB.
            if piece.len() != expected_piece_len(idx, metadata_size, total_pieces) {
                return merr(format!(
                    "peer sent wrong-sized metadata piece {idx} ({} bytes)",
                    piece.len()
                ));
            }
            pieces[idx] = Some(piece);
            received += 1;
        }
    }

    let collected: Vec<Vec<u8>> = pieces.into_iter().flatten().collect();
    let metadata = match info_hash_v2 {
        Some(v2) => assemble_and_verify_v2(&collected, v2).ok_or_else(|| {
            MetadataError("assembled metadata failed SHA-256 verification".into())
        })?,
        None => assemble_and_verify(&collected, info_hash)
            .ok_or_else(|| MetadataError("assembled metadata failed SHA-1 verification".into()))?,
    };
    let info = decode_info_dict(&metadata)?;
    parse_info(&info, Some(*info_hash), Some(metadata))
}

// --- loopback peer that serves an info-dict (tests / demo) -----------------

async fn serve_one(
    mut stream: TcpStream,
    metadata: &[u8],
    corrupt: bool,
) -> Result<(), MetadataError> {
    let timeout = Duration::from_secs(15);
    let (_reserved, ih, _pid) =
        parse_handshake(&read_exact_to(&mut stream, HANDSHAKE_LEN, timeout).await?)?;
    write_all_to(&mut stream, &build_handshake(&ih, &random_peer_id(), true)).await?;

    // Learn the client's ut_metadata id from its extended handshake.
    let (client_ut, _size) = read_ext_handshake(&mut stream, timeout).await?;
    let client_ext = u8::try_from(client_ut.unwrap_or(1)).unwrap_or(1);

    // Advertise our metadata; the client will request pieces with our id (2).
    let our_ut_id: i64 = 2;
    write_all_to(
        &mut stream,
        &build_ext_handshake(Some(metadata.len() as i64), our_ut_id),
    )
    .await?;

    loop {
        let (msg_id, payload) = match read_message(&mut stream, timeout).await {
            Ok(x) => x,
            Err(_) => break,
        };
        if msg_id != i32::from(EXT_MSG_ID) || payload.is_empty() || payload[0] != our_ut_id as u8 {
            continue;
        }
        let (header, _) = match decode_prefix(&payload[1..]) {
            Ok(x) => x,
            Err(_) => continue,
        };
        let Ben::Dict(h) = header else { continue };
        if h.get(b"msg_type".as_slice()) != Some(&Ben::Int(UT_REQUEST)) {
            continue;
        }
        let piece = match h.get(b"piece".as_slice()) {
            Some(Ben::Int(p)) if *p >= 0 => *p as usize,
            _ => 0,
        };
        // Saturating so a hostile `piece` index can't overflow into a `start > end`
        // slice panic; an out-of-range piece just yields an empty chunk.
        let start = piece.saturating_mul(PIECE_SIZE).min(metadata.len());
        let end = piece
            .saturating_add(1)
            .saturating_mul(PIECE_SIZE)
            .min(metadata.len());
        let mut chunk = metadata[start..end].to_vec();
        if corrupt {
            for b in chunk.iter_mut() {
                *b ^= 0xFF;
            }
        }
        write_all_to(
            &mut stream,
            &build_ut_metadata_data(piece as i64, metadata.len() as i64, &chunk, client_ext),
        )
        .await?;
    }
    Ok(())
}

/// Start a loopback peer serving `metadata` (the info-dict bytes). Returns the
/// bound address and the server task's handle (abort it to stop). `corrupt`
/// flips the served bytes so the client's SHA-1 verification must fail.
pub async fn serve_metadata(
    metadata: Vec<u8>,
    corrupt: bool,
) -> std::io::Result<(SocketAddr, tokio::task::JoinHandle<()>)> {
    let listener = TcpListener::bind("127.0.0.1:0").await?;
    let addr = listener.local_addr()?;
    let handle = tokio::spawn(async move {
        while let Ok((stream, _)) = listener.accept().await {
            let md = metadata.clone();
            tokio::spawn(async move {
                let _ = serve_one(stream, &md, corrupt).await;
            });
        }
    });
    Ok((addr, handle))
}

#[cfg(test)]
mod tests {
    use super::*;

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
}
