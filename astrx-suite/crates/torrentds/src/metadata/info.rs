//! Pure info-dict parsing: turn a decoded info dictionary into a [`TorrentMeta`]
//! (name, files, sizes, piece info) for v1, BEP-52 v2, and hybrid torrents, plus
//! the SHA-1 / SHA-256 assembly-and-verify checks. No I/O, no third-party deps.

use super::{merr, MetadataError, MAX_TREE_DEPTH, MAX_TREE_NODES, MAX_TREE_PATH_BYTES};
use crate::bencode::Dict;
use crate::bencode::{decode, decode_lenient, encode, Ben};
use crate::infohash::{sha1, sha256};

/// Concatenate ordered pieces and check `sha1(metadata) == info_hash`. Returns
/// the metadata on success, `None` on mismatch.
#[must_use]
pub fn assemble_and_verify(pieces: &[Vec<u8>], info_hash: &[u8; 20]) -> Option<Vec<u8>> {
    let metadata = pieces.concat();
    if sha1(&metadata) == *info_hash {
        Some(metadata)
    } else {
        None
    }
}

/// v2 analogue of [`assemble_and_verify`] (SHA-256 instead of SHA-1).
#[must_use]
pub fn assemble_and_verify_v2(pieces: &[Vec<u8>], info_hash_v2: &[u8]) -> Option<Vec<u8>> {
    let metadata = pieces.concat();
    verify_v2(&metadata, info_hash_v2).then_some(metadata)
}

/// Rebuild a valid `.torrent` around pre-verified `info_bytes`. The `info` value
/// is spliced in **verbatim** (never re-encoded), so its SHA-1 still equals the
/// original infohash even when the info-dict was itself non-canonical. Other
/// top-level keys (`announce`, `announce-list`, `creation date`) are emitted in
/// canonical byte order.
#[must_use]
pub fn build_torrent_file(
    info_bytes: &[u8],
    announce: Option<&str>,
    announce_list: &[String],
    creation_date: Option<i64>,
) -> Vec<u8> {
    let mut entries: Vec<(&[u8], Vec<u8>)> = Vec::new();
    if let Some(a) = announce {
        if !a.is_empty() {
            entries.push((b"announce", encode(&Ben::Bytes(a.as_bytes().to_vec()))));
        }
    }
    if !announce_list.is_empty() {
        let list = Ben::List(
            announce_list
                .iter()
                .map(|a| Ben::List(vec![Ben::Bytes(a.as_bytes().to_vec())]))
                .collect(),
        );
        entries.push((b"announce-list", encode(&list)));
    }
    if let Some(cd) = creation_date {
        entries.push((b"creation date", encode(&Ben::Int(cd))));
    }
    entries.push((b"info", info_bytes.to_vec()));
    entries.sort_by(|a, b| a.0.cmp(b.0)); // canonical top-level key order
    let mut out = vec![b'd'];
    for (key, value) in &entries {
        out.extend_from_slice(format!("{}:", key.len()).as_bytes());
        out.extend_from_slice(key);
        out.extend_from_slice(value);
    }
    out.push(b'e');
    out
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
            .map_err(|e| MetadataError(format!("undecodable info-dict: {}", e.message())))?,
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

// --- BEP-52 v2 / hybrid ----------------------------------------------------

/// True if `info` is a BEP-52 v2 (or hybrid) info-dict.
#[must_use]
pub fn is_v2_info(info: &Dict) -> bool {
    matches!(info.get(b"meta version".as_slice()), Some(Ben::Int(2)))
        && matches!(info.get(b"file tree".as_slice()), Some(Ben::Dict(_)))
}

/// True if `info` carries BOTH v2 and v1 (`pieces`) structures.
#[must_use]
pub fn is_hybrid_info(info: &Dict) -> bool {
    is_v2_info(info) && matches!(info.get(b"pieces".as_slice()), Some(Ben::Bytes(_)))
}

/// The 20-byte truncated v2 infohash used where the DHT/peer wire needs 20 bytes.
#[must_use]
pub fn truncate_v2(info_hash_v2: &[u8; 32]) -> [u8; 20] {
    let mut t = [0u8; 20];
    t.copy_from_slice(&info_hash_v2[..20]);
    t
}

/// Byte-exact v2 verification: recompute SHA-256 over `info_bytes` and compare to
/// `expected` (32-byte full, or 20-byte truncated DHT form). Any other length is
/// rejected.
#[must_use = "a discarded verification result silently accepts unverified data"]
pub fn verify_v2(info_bytes: &[u8], expected: &[u8]) -> bool {
    let digest = sha256(info_bytes);
    match expected.len() {
        32 => digest == expected,
        20 => digest[..20] == *expected,
        _ => false,
    }
}

/// Flatten a BEP-52 `file tree` into `[(path, length), …]`. Each leaf is a
/// `{"": {"length": N, …}}` node whose accumulated key path is the file path. The
/// recursion is bounded on depth, total node count **and total emitted path
/// bytes** — the tree is hostile network data, and the last of those is the only
/// bound on the walk's output (see [`MAX_TREE_PATH_BYTES`]).
pub fn walk_file_tree(file_tree: &Dict) -> Result<Vec<(String, u64)>, MetadataError> {
    let mut walk = TreeWalk {
        nodes: 0,
        prefix_bytes: 0,
        path_bytes: 0,
        out: Vec::new(),
    };
    let mut prefix: Vec<String> = Vec::new();
    walk_tree_rec(file_tree, &mut prefix, 0, &mut walk)?;
    Ok(walk.out)
}

/// The running budget of one [`walk_file_tree`].
struct TreeWalk {
    /// Tree nodes visited so far (capped by [`MAX_TREE_NODES`]).
    nodes: usize,
    /// Byte length the *current* prefix would have once joined with `/`. Kept
    /// incrementally so the output check below is O(1) and never has to build the
    /// string in order to price it — pricing by building is exactly the O(prefix)
    /// per leaf cost that makes the amplification work.
    prefix_bytes: usize,
    /// Total path bytes emitted so far (capped by [`MAX_TREE_PATH_BYTES`]).
    path_bytes: usize,
    out: Vec<(String, u64)>,
}

fn walk_tree_rec(
    node: &Dict,
    prefix: &mut Vec<String>,
    depth: usize,
    walk: &mut TreeWalk,
) -> Result<(), MetadataError> {
    if depth > MAX_TREE_DEPTH {
        return merr(format!("file tree nested too deeply (>{MAX_TREE_DEPTH})"));
    }
    // A file leaf: the empty-string key holds the length / pieces-root.
    if let Some(Ben::Dict(leaf)) = node.get(b"".as_slice()) {
        if leaf.contains_key(b"length".as_slice()) {
            // Bound the OUTPUT, not just the input: every leaf re-materialises the
            // whole key prefix, so a 6 MiB directory name with 100 000 leaves under
            // it is 8.19 MiB on the wire but ~585 GiB of paths here (measured: 300
            // leaves = +1124 MiB RSS, and it returned Ok). Checked before the join
            // so the oversized path is never allocated at all.
            if walk.path_bytes.saturating_add(walk.prefix_bytes) > MAX_TREE_PATH_BYTES {
                return merr(format!(
                    "file tree paths too large (>{MAX_TREE_PATH_BYTES} bytes)"
                ));
            }
            walk.path_bytes += walk.prefix_bytes;
            let length = ben_int(leaf, b"length").max(0) as u64;
            walk.out.push((prefix.join("/"), length));
            return Ok(());
        }
    }
    for (name, child) in node {
        walk.nodes += 1;
        if walk.nodes > MAX_TREE_NODES {
            return merr(format!("file tree too large (>{MAX_TREE_NODES} nodes)"));
        }
        if name.is_empty() {
            continue; // the leaf key, handled above
        }
        let Ben::Dict(child_dict) = child else {
            continue;
        };
        let component = String::from_utf8_lossy(name).into_owned();
        // What `prefix.join("/")` will cost once this component is on the stack.
        let added = component.len() + usize::from(!prefix.is_empty());
        prefix.push(component);
        walk.prefix_bytes += added;
        let result = walk_tree_rec(child_dict, prefix, depth + 1, walk);
        walk.prefix_bytes -= added;
        prefix.pop();
        result?;
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
