//! Byte-exact BitTorrent v1 infohash, and the hand-rolled SHA-1 it uses.
//!
//! SHA-1 is kept in-crate so the crate has no dependencies (std has no crypto).
//! The BEP-52 v2 infohash (SHA-256 of the info dict) will live here too when the
//! v2 metadata path lands.

use crate::bencode::{encode, Ben};

/// The v1 infohash: SHA-1 of the canonical bencoding of the info dictionary.
pub fn infohash(info: &Ben) -> [u8; 20] {
    sha1(&encode(info))
}

/// Hand-rolled SHA-1 (RFC 3174). Verified byte-identical to Python's hashlib.
pub fn sha1(msg: &[u8]) -> [u8; 20] {
    let bit_len = (msg.len() as u64).wrapping_mul(8);
    let mut data = msg.to_vec();
    data.push(0x80);
    while data.len() % 64 != 56 {
        data.push(0);
    }
    data.extend_from_slice(&bit_len.to_be_bytes());

    let mut h: [u32; 5] = [
        0x6745_2301,
        0xEFCD_AB89,
        0x98BA_DCFE,
        0x1032_5476,
        0xC3D2_E1F0,
    ];
    for chunk in data.chunks_exact(64) {
        let mut w = [0u32; 80];
        for (i, word) in w.iter_mut().take(16).enumerate() {
            *word = u32::from_be_bytes([
                chunk[i * 4],
                chunk[i * 4 + 1],
                chunk[i * 4 + 2],
                chunk[i * 4 + 3],
            ]);
        }
        for i in 16..80 {
            w[i] = (w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16]).rotate_left(1);
        }
        let (mut a, mut b, mut c, mut d, mut e) = (h[0], h[1], h[2], h[3], h[4]);
        for (i, &wi) in w.iter().enumerate() {
            let (f, k) = match i {
                0..=19 => ((b & c) | ((!b) & d), 0x5A82_7999u32),
                20..=39 => (b ^ c ^ d, 0x6ED9_EBA1),
                40..=59 => ((b & c) | (b & d) | (c & d), 0x8F1B_BCDC),
                _ => (b ^ c ^ d, 0xCA62_C1D6),
            };
            let tmp = a
                .rotate_left(5)
                .wrapping_add(f)
                .wrapping_add(e)
                .wrapping_add(k)
                .wrapping_add(wi);
            e = d;
            d = c;
            c = b.rotate_left(30);
            b = a;
            a = tmp;
        }
        h[0] = h[0].wrapping_add(a);
        h[1] = h[1].wrapping_add(b);
        h[2] = h[2].wrapping_add(c);
        h[3] = h[3].wrapping_add(d);
        h[4] = h[4].wrapping_add(e);
    }
    let mut out = [0u8; 20];
    for (i, word) in h.iter().enumerate() {
        out[i * 4..i * 4 + 4].copy_from_slice(&word.to_be_bytes());
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::bencode::Ben;
    use std::collections::BTreeMap;

    fn hexs(b: &[u8]) -> String {
        b.iter().map(|x| format!("{x:02x}")).collect()
    }

    #[test]
    fn infohash_matches_python_golden() {
        // Same info dict + SHA-1 as the Python torrentds; golden from hashlib.
        let mut m = BTreeMap::new();
        m.insert(b"length".to_vec(), Ben::Int(12345));
        m.insert(b"name".to_vec(), Ben::Bytes(b"test.txt".to_vec()));
        m.insert(b"piece length".to_vec(), Ben::Int(16384));
        m.insert(b"pieces".to_vec(), Ben::Bytes(vec![1u8; 20]));
        let info = Ben::Dict(m);
        assert_eq!(
            hexs(&infohash(&info)),
            "3657fdccc5f3627152a5358dc8ca1ef12862c5fd"
        );
    }

    #[test]
    fn sha1_empty_and_abc() {
        // RFC 3174 / well-known vectors.
        assert_eq!(hexs(&sha1(b"")), "da39a3ee5e6b4b0d3255bfef95601890afd80709");
        assert_eq!(
            hexs(&sha1(b"abc")),
            "a9993e364706816aba3e25717850c26c9cd0d89d"
        );
    }
}
