//! BLAKE2b (RFC 7693) — hand-rolled, stdlib-only, unkeyed, variable digest
//! length.
//!
//! onioncrawler's fuzzy near-duplicate fingerprint (`simhash64`) hashes each
//! token with an 8-byte BLAKE2b digest, so the persisted SimHash values are
//! byte-identical to the Python `hashlib.blake2b(token, digest_size=8)`
//! reference. The classic RFC 7693 Appendix-A vector `blake2b("abc")` is pinned
//! in the tests; `crawlcore/tests/xcheck_blake2b.rs` cross-checks a spread of
//! inputs and output lengths against Python.
//!
//! Only the unkeyed, sequential (fanout=depth=1) mode is implemented — the only
//! mode the suite uses.

/// BLAKE2b initialization vector (identical to the SHA-512 IV).
const IV: [u64; 8] = [
    0x6a09e667f3bcc908,
    0xbb67ae8584caa73b,
    0x3c6ef372fe94f82b,
    0xa54ff53a5f1d36f1,
    0x510e527fade682d1,
    0x9b05688c2b3e6c1f,
    0x1f83d9abfb41bd6b,
    0x5be0cd19137e2179,
];

/// Message-word permutation schedule (SIGMA): 12 rounds of 16 indices.
const SIGMA: [[usize; 16]; 12] = [
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
    [14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3],
    [11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4],
    [7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8],
    [9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13],
    [2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9],
    [12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11],
    [13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10],
    [6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5],
    [10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0],
    [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
    [14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3],
];

/// The BLAKE2b mixing function G.
#[inline]
#[allow(clippy::too_many_arguments)]
fn g(v: &mut [u64; 16], a: usize, b: usize, c: usize, d: usize, x: u64, y: u64) {
    v[a] = v[a].wrapping_add(v[b]).wrapping_add(x);
    v[d] = (v[d] ^ v[a]).rotate_right(32);
    v[c] = v[c].wrapping_add(v[d]);
    v[b] = (v[b] ^ v[c]).rotate_right(24);
    v[a] = v[a].wrapping_add(v[b]).wrapping_add(y);
    v[d] = (v[d] ^ v[a]).rotate_right(16);
    v[c] = v[c].wrapping_add(v[d]);
    v[b] = (v[b] ^ v[c]).rotate_right(63);
}

/// Compression function F over one 128-byte block (16 little-endian words).
/// `t` is the running byte counter (128-bit); `last` sets the final-block flag.
fn compress(h: &mut [u64; 8], block: &[u8; 128], t: u128, last: bool) {
    let mut m = [0u64; 16];
    for (i, word) in m.iter_mut().enumerate() {
        let mut w = [0u8; 8];
        w.copy_from_slice(&block[i * 8..i * 8 + 8]);
        *word = u64::from_le_bytes(w);
    }
    let mut v = [0u64; 16];
    v[..8].copy_from_slice(h);
    v[8..].copy_from_slice(&IV);
    v[12] ^= t as u64;
    v[13] ^= (t >> 64) as u64;
    if last {
        v[14] ^= u64::MAX;
    }
    for s in SIGMA.iter() {
        g(&mut v, 0, 4, 8, 12, m[s[0]], m[s[1]]);
        g(&mut v, 1, 5, 9, 13, m[s[2]], m[s[3]]);
        g(&mut v, 2, 6, 10, 14, m[s[4]], m[s[5]]);
        g(&mut v, 3, 7, 11, 15, m[s[6]], m[s[7]]);
        g(&mut v, 0, 5, 10, 15, m[s[8]], m[s[9]]);
        g(&mut v, 1, 6, 11, 12, m[s[10]], m[s[11]]);
        g(&mut v, 2, 7, 8, 13, m[s[12]], m[s[13]]);
        g(&mut v, 3, 4, 9, 14, m[s[14]], m[s[15]]);
    }
    for i in 0..8 {
        h[i] ^= v[i] ^ v[i + 8];
    }
}

/// Unkeyed BLAKE2b of `msg` with an `out_len`-byte digest.
///
/// # Panics
/// Panics unless `out_len` is in `1..=64`.
pub fn blake2b(msg: &[u8], out_len: usize) -> Vec<u8> {
    assert!(
        (1..=64).contains(&out_len),
        "blake2b digest length must be in 1..=64"
    );
    let mut h = IV;
    // Parameter block word 0 (unkeyed): digest_length | key_length<<8 |
    // fanout<<16 | depth<<24, with key_length=0 and fanout=depth=1.
    h[0] ^= 0x0101_0000 ^ (out_len as u64);

    // Compress every block but the final one with the running byte counter.
    let full_blocks = if msg.is_empty() {
        0
    } else {
        (msg.len() - 1) / 128
    };
    let mut t: u128 = 0;
    for i in 0..full_blocks {
        let mut block = [0u8; 128];
        block.copy_from_slice(&msg[i * 128..i * 128 + 128]);
        t += 128;
        compress(&mut h, &block, t, false);
    }
    // Final (possibly partial, zero-padded) block carries the LAST flag; its
    // counter is the total message length (unkeyed).
    let start = full_blocks * 128;
    let rem = &msg[start..];
    let mut block = [0u8; 128];
    block[..rem.len()].copy_from_slice(rem);
    t += rem.len() as u128;
    compress(&mut h, &block, t, true);

    let mut out = Vec::with_capacity(64);
    for word in h.iter() {
        out.extend_from_slice(&word.to_le_bytes());
    }
    out.truncate(out_len);
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::hash::to_hex;

    #[test]
    fn rfc7693_appendix_a_abc() {
        // The canonical 64-byte BLAKE2b("abc") vector from RFC 7693 Appendix A.
        assert_eq!(
            to_hex(&blake2b(b"abc", 64)),
            "ba80a53f981c4d0d6a2797b69f12f6e94c212f14685ac4b74b12bb6fdbffa2d1\
             7d87c5392aab792dc252d5de4533cc9518d38aa8dbf1925ab92386edd4009923"
        );
    }

    #[test]
    fn empty_digest8() {
        assert_eq!(to_hex(&blake2b(b"", 8)), "e4a6a0577479b2b4");
    }

    #[test]
    fn spans_two_blocks() {
        let msg: Vec<u8> = (0..200u16).map(|b| b as u8).collect();
        // 200 bytes → one full block + a 72-byte final block.
        assert_eq!(blake2b(&msg, 16).len(), 16);
    }

    #[test]
    #[should_panic(expected = "1..=64")]
    fn rejects_zero_len() {
        let _ = blake2b(b"x", 0);
    }
}
