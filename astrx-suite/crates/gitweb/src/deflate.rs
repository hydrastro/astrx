//! A small, dependency-free DEFLATE compressor (RFC 1951) with gzip (RFC 1952)
//! and zlib (RFC 1950) wrappers.
//!
//! The serving tier content-codes HTML responses when the client advertises
//! `gzip`/`deflate`, exactly as the Python reference's `send_html` does
//! (`gzip.compress(body, compresslevel=6, mtime=0)` /
//! `zlib.compress(body, 6)`). CPython gets that from zlib; this crate has no
//! third-party dependencies, so the compressor lives here. `crawlcore::inflate`
//! is the matching decompressor and round-trips everything this module emits.
//!
//! The encoder is a greedy LZ77 matcher (32 KiB window, bounded hash chains)
//! emitting **fixed-Huffman** blocks. That is a valid, universally decodable
//! DEFLATE stream; it simply is not bit-for-bit what zlib's level-6 dynamic
//! Huffman encoder produces. **This is the module's one documented divergence:**
//! the compressed *bytes* differ from CPython's, while the decompressed bytes,
//! the `Content-Encoding` header and every cache validator are identical. A
//! compressed body is never part of a byte-identity golden — the cross-checks
//! compare the rendered document, which is what a client sees after decoding.

/// Window size (RFC 1951 caps the back-reference distance at 32 KiB).
const WINDOW: usize = 32 * 1024;
/// Longest match a length code can express.
const MAX_MATCH: usize = 258;
/// Shortest match worth emitting.
const MIN_MATCH: usize = 3;
/// Hash table size (a power of two).
const HASH_SIZE: usize = 1 << 15;
/// How far down a hash chain to walk before giving up on a longer match.
const MAX_CHAIN: usize = 128;
/// A match at least this long ends the search immediately.
const GOOD_MATCH: usize = 128;
/// Emit a fresh block every this many input bytes, so peak state stays bounded.
const BLOCK_SIZE: usize = 1 << 16;

/// `LENGTH_BASE[i]` is the smallest match length coded by symbol `257 + i`.
const LENGTH_BASE: [usize; 29] = [
    3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31, 35, 43, 51, 59, 67, 83, 99, 115, 131,
    163, 195, 227, 258,
];
/// Extra bits carried after each length symbol.
const LENGTH_EXTRA: [u32; 29] = [
    0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0,
];
/// `DIST_BASE[i]` is the smallest distance coded by distance symbol `i`.
const DIST_BASE: [usize; 30] = [
    1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537,
    2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577,
];
/// Extra bits carried after each distance symbol.
const DIST_EXTRA: [u32; 30] = [
    0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13,
    13,
];

/// A DEFLATE bit sink: bits are packed into bytes least-significant-bit first,
/// while a Huffman code's own bits are emitted most-significant first.
struct BitWriter {
    out: Vec<u8>,
    buf: u32,
    bits: u32,
}

impl BitWriter {
    fn new(capacity: usize) -> Self {
        BitWriter {
            out: Vec::with_capacity(capacity),
            buf: 0,
            bits: 0,
        }
    }

    /// Write `n` bits of `value`, least-significant bit first.
    fn write_bits(&mut self, value: u32, n: u32) {
        self.buf |= (value & ((1u32 << n) - 1)) << self.bits;
        self.bits += n;
        while self.bits >= 8 {
            self.out.push((self.buf & 0xff) as u8);
            self.buf >>= 8;
            self.bits -= 8;
        }
    }

    /// Write an `n`-bit Huffman code, most-significant bit first.
    fn write_code(&mut self, code: u32, n: u32) {
        let mut reversed = 0u32;
        for i in 0..n {
            reversed |= ((code >> (n - 1 - i)) & 1) << i;
        }
        self.write_bits(reversed, n);
    }

    /// Pad to a byte boundary and return the bytes.
    fn finish(mut self) -> Vec<u8> {
        if self.bits > 0 {
            self.out.push((self.buf & 0xff) as u8);
        }
        self.out
    }
}

/// The fixed literal/length code for `sym` as `(code, bit length)` (RFC 1951 §3.2.6).
fn fixed_lit(sym: u32) -> (u32, u32) {
    match sym {
        0..=143 => (0b0011_0000 + sym, 8),
        144..=255 => (0b1_1001_0000 + (sym - 144), 9),
        256..=279 => (sym - 256, 7),
        _ => (0b1100_0000 + (sym - 280), 8),
    }
}

/// The length symbol index for a match of `len` bytes.
fn length_index(len: usize) -> usize {
    let mut i = LENGTH_BASE.len() - 1;
    while i > 0 && len < LENGTH_BASE[i] {
        i -= 1;
    }
    i
}

/// The distance symbol index for a back-reference of `dist` bytes.
fn dist_index(dist: usize) -> usize {
    let mut i = DIST_BASE.len() - 1;
    while i > 0 && dist < DIST_BASE[i] {
        i -= 1;
    }
    i
}

fn hash3(data: &[u8], pos: usize) -> usize {
    let a = u32::from(data[pos]);
    let b = u32::from(data[pos + 1]);
    let c = u32::from(data[pos + 2]);
    (((a << 10) ^ (b << 5) ^ c).wrapping_mul(0x9E37_79B1) >> 17) as usize % HASH_SIZE
}

/// Emit `data` as DEFLATE *stored* (uncompressed) blocks — the fallback for
/// input a fixed-Huffman block would make bigger (already-compressed bytes).
fn stored_blocks(data: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(data.len() + 5 * (data.len() / 65535 + 1));
    let mut chunks = data.chunks(65535).peekable();
    let mut wrote_any = false;
    while let Some(chunk) = chunks.next() {
        let last = chunks.peek().is_none();
        out.push(u8::from(last)); // BFINAL in bit 0, BTYPE = 00, then pad to byte
        let n = chunk.len() as u16;
        out.extend_from_slice(&n.to_le_bytes());
        out.extend_from_slice(&(!n).to_le_bytes());
        out.extend_from_slice(chunk);
        wrote_any = true;
    }
    if !wrote_any {
        out.extend_from_slice(&[1, 0, 0, 0xff, 0xff]); // one final empty block
    }
    out
}

/// The byte length [`stored_blocks`] would produce for `len` input bytes.
fn stored_len(len: usize) -> usize {
    len + 5 * (len / 65535 + 1)
}

/// Compress `data` into a raw DEFLATE stream (no gzip/zlib wrapper).
///
/// Falls back to stored blocks when Huffman coding would *grow* the input, so
/// the output never exceeds the input by more than 5 bytes per 64 KiB.
#[must_use]
pub fn deflate_raw(data: &[u8]) -> Vec<u8> {
    let coded = deflate_fixed(data);
    if coded.len() > stored_len(data.len()) {
        return stored_blocks(data);
    }
    coded
}

/// Compress `data` into raw DEFLATE fixed-Huffman blocks.
fn deflate_fixed(data: &[u8]) -> Vec<u8> {
    let mut w = BitWriter::new(data.len() / 2 + 64);
    if data.len() < MIN_MATCH {
        // Too short for any back-reference: one fixed-Huffman literal block.
        w.write_bits(1, 1); // BFINAL
        w.write_bits(1, 2); // BTYPE = fixed Huffman
        for &b in data {
            let (code, n) = fixed_lit(u32::from(b));
            w.write_code(code, n);
        }
        let (code, n) = fixed_lit(256);
        w.write_code(code, n);
        return w.finish();
    }

    let mut head = vec![usize::MAX; HASH_SIZE];
    let mut prev = vec![usize::MAX; data.len()];
    let mut pos = 0usize;
    let mut block_start = 0usize;
    let mut block_open = false;

    while pos < data.len() {
        if !block_open {
            let last = data.len() - pos <= BLOCK_SIZE;
            w.write_bits(u32::from(last), 1); // BFINAL
            w.write_bits(1, 2); // BTYPE = fixed Huffman
            block_open = true;
            block_start = pos;
        }

        let (mut best_len, mut best_dist) = (0usize, 0usize);
        if pos + MIN_MATCH <= data.len() {
            let h = hash3(data, pos);
            let mut candidate = head[h];
            let limit = pos.saturating_sub(WINDOW);
            let max_len = MAX_MATCH.min(data.len() - pos);
            let mut chain = MAX_CHAIN;
            while candidate != usize::MAX && candidate >= limit && chain > 0 {
                chain -= 1;
                // Cheap rejection before the full compare.
                if data[candidate + best_len.min(max_len - 1)]
                    == data[pos + best_len.min(max_len - 1)]
                {
                    let mut len = 0usize;
                    while len < max_len && data[candidate + len] == data[pos + len] {
                        len += 1;
                    }
                    if len > best_len {
                        best_len = len;
                        best_dist = pos - candidate;
                        if len >= GOOD_MATCH {
                            break;
                        }
                    }
                }
                candidate = prev[candidate];
            }
            // Insert this position into the chain.
            prev[pos] = head[h];
            head[h] = pos;
        }

        if best_len >= MIN_MATCH {
            let li = length_index(best_len);
            let (code, n) = fixed_lit(257 + li as u32);
            w.write_code(code, n);
            if LENGTH_EXTRA[li] > 0 {
                w.write_bits((best_len - LENGTH_BASE[li]) as u32, LENGTH_EXTRA[li]);
            }
            let di = dist_index(best_dist);
            w.write_code(di as u32, 5);
            if DIST_EXTRA[di] > 0 {
                w.write_bits((best_dist - DIST_BASE[di]) as u32, DIST_EXTRA[di]);
            }
            // Index every position the match covers so later matches can find them.
            for k in 1..best_len {
                let at = pos + k;
                if at + MIN_MATCH <= data.len() {
                    let h = hash3(data, at);
                    prev[at] = head[h];
                    head[h] = at;
                }
            }
            pos += best_len;
        } else {
            let (code, n) = fixed_lit(u32::from(data[pos]));
            w.write_code(code, n);
            pos += 1;
        }

        if pos - block_start >= BLOCK_SIZE || pos == data.len() {
            let (code, n) = fixed_lit(256);
            w.write_code(code, n);
            block_open = false;
        }
    }
    if block_open {
        let (code, n) = fixed_lit(256);
        w.write_code(code, n);
    }
    w.finish()
}

/// CRC-32 (IEEE), the checksum a gzip trailer carries.
#[must_use]
pub fn crc32(data: &[u8]) -> u32 {
    let mut crc = 0xffff_ffffu32;
    for &b in data {
        crc ^= u32::from(b);
        for _ in 0..8 {
            let mask = 0u32.wrapping_sub(crc & 1);
            crc = (crc >> 1) ^ (0xEDB8_8320 & mask);
        }
    }
    !crc
}

/// Adler-32, the checksum a zlib trailer carries.
#[must_use]
pub fn adler32(data: &[u8]) -> u32 {
    let (mut a, mut b) = (1u32, 0u32);
    for &byte in data {
        a = (a + u32::from(byte)) % 65521;
        b = (b + a) % 65521;
    }
    (b << 16) | a
}

/// Wrap `data` in a gzip container — CPython's
/// `gzip.compress(data, compresslevel=6, mtime=0)` framing (fixed header, XFL 0,
/// OS "unknown").
#[must_use]
pub fn gzip_compress(data: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(data.len() / 2 + 32);
    out.extend_from_slice(&[0x1f, 0x8b, 0x08, 0x00, 0, 0, 0, 0, 0x00, 0xff]);
    out.extend_from_slice(&deflate_raw(data));
    out.extend_from_slice(&crc32(data).to_le_bytes());
    out.extend_from_slice(&((data.len() as u64 & 0xffff_ffff) as u32).to_le_bytes());
    out
}

/// Wrap `data` in a zlib container — CPython's `zlib.compress(data, 6)` framing.
#[must_use]
pub fn zlib_compress(data: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(data.len() / 2 + 8);
    out.extend_from_slice(&[0x78, 0x9c]); // CM=8, CINFO=7, FLEVEL=2, FCHECK ok
    out.extend_from_slice(&deflate_raw(data));
    out.extend_from_slice(&adler32(data).to_be_bytes());
    out
}

#[cfg(test)]
mod tests {
    use super::*;
    use crawlcore::inflate::{inflate_gzip, inflate_raw, inflate_zlib};

    fn roundtrip(data: &[u8]) {
        let raw = deflate_raw(data);
        let (back, truncated) = inflate_raw(&raw, 64 << 20).expect("inflate raw");
        assert!(!truncated);
        assert_eq!(back, data, "raw deflate round-trip failed");

        let gz = gzip_compress(data);
        let (back, _) = inflate_gzip(&gz, 64 << 20).expect("inflate gzip");
        assert_eq!(back, data, "gzip round-trip failed");

        let zl = zlib_compress(data);
        let (back, _) = inflate_zlib(&zl, 64 << 20).expect("inflate zlib");
        assert_eq!(back, data, "zlib round-trip failed");
    }

    #[test]
    fn round_trips_edge_cases() {
        roundtrip(b"");
        roundtrip(b"a");
        roundtrip(b"ab");
        roundtrip(b"abc");
        roundtrip(b"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
        roundtrip(&[0u8; 300]);
        roundtrip(&(0..=255u8).collect::<Vec<u8>>());
    }

    #[test]
    fn round_trips_realistic_html() {
        let mut doc = String::new();
        for i in 0..2000 {
            doc.push_str(&format!(
                "<tr><td class=\"mono\"><a href=\"/r/commit?id={i}\">abc{i}</a></td>\
                 <td>subject line {i} — café</td></tr>"
            ));
        }
        roundtrip(doc.as_bytes());
        assert!(
            gzip_compress(doc.as_bytes()).len() < doc.len() / 3,
            "compression made no headway"
        );
    }

    #[test]
    fn round_trips_incompressible_and_long_inputs() {
        // A deterministic pseudo-random (incompressible) payload.
        let mut x: u32 = 0x1234_5678;
        let mut noise = Vec::with_capacity(300_000);
        for _ in 0..300_000 {
            x = x.wrapping_mul(1_664_525).wrapping_add(1_013_904_223);
            noise.push((x >> 24) as u8);
        }
        roundtrip(&noise);
        // Multi-block, highly repetitive input (crosses the block boundary).
        roundtrip(&b"the quick brown fox. ".repeat(20_000));
    }

    #[test]
    fn incompressible_input_falls_back_to_stored_blocks() {
        let mut x: u32 = 0xdead_beef;
        let mut noise = Vec::with_capacity(200_000);
        for _ in 0..200_000 {
            x = x.wrapping_mul(1_664_525).wrapping_add(1_013_904_223);
            noise.push((x >> 24) as u8);
        }
        let raw = deflate_raw(&noise);
        assert!(
            raw.len() <= noise.len() + 5 * (noise.len() / 65535 + 1),
            "stored fallback did not engage: {} vs {}",
            raw.len(),
            noise.len()
        );
        let (back, _) = inflate_raw(&raw, 64 << 20).expect("inflate stored");
        assert_eq!(back, noise);
    }

    #[test]
    fn checksums_match_the_reference_values() {
        assert_eq!(crc32(b""), 0);
        assert_eq!(crc32(b"123456789"), 0xCBF4_3926);
        assert_eq!(adler32(b""), 1);
        assert_eq!(adler32(b"123456789"), 0x091E_01DE);
    }

    #[test]
    fn gzip_header_matches_cpython_framing() {
        let gz = gzip_compress(b"hello");
        assert_eq!(&gz[..4], &[0x1f, 0x8b, 0x08, 0x00]);
        assert_eq!(&gz[4..8], &[0, 0, 0, 0], "mtime must be 0");
        assert_eq!(&gz[8..10], &[0x00, 0xff], "XFL=0, OS=unknown");
        assert_eq!(&gz[gz.len() - 4..], &5u32.to_le_bytes());
    }
}
