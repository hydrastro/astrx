#![no_main]
//! Fuzz the metadata info-dict path: `decode_info_dict` followed by `parse_info`.
//!
//! In production these bytes are SHA-1/SHA-256-verified before decoding, so
//! `decode_info_dict` uses the lenient decoder. Here we feed it raw fuzz bytes to
//! prove the decode-and-parse pipeline never panics on hostile structure:
//! v1/v2/hybrid routing, the depth/node-bounded `file tree` walk, and the
//! saturating file-length summation (an attacker controls the lengths, so the
//! total must saturate rather than overflow-panic).

use libfuzzer_sys::fuzz_target;
use torrentds::metadata::{decode_info_dict, parse_info};

fuzz_target!(|data: &[u8]| {
    let Ok(dict) = decode_info_dict(data) else {
        return; // undecodable / not-a-dict — must be an Err, never a panic
    };
    // No trusted infohash or raw bytes in the fuzzing context: pass None/None and
    // let `parse_info` recompute. A `TorrentMeta` or a `MetadataError` are both
    // acceptable outcomes; a panic (e.g. an overflow or unbounded recursion) is a bug.
    let _ = parse_info(&dict, None, None);
});
