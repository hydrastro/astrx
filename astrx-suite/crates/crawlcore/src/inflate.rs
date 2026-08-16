//! A dependency-free DEFLATE (RFC 1951) decompressor, with zlib (RFC 1950) and
//! gzip (RFC 1952) wrappers.
//!
//! Used to decode `Content-Encoding: gzip`/`deflate` HTTP responses without a
//! third-party crate (`flate2`), keeping the suite's zero-dep invariant. The
//! decoder is a faithful Rust port of the algorithm in zlib's reference `puff.c`
//! (Mark Adler, public domain); cross-checked byte-identical against Python's
//! `zlib` in `tests/xcheck_inflate.rs`.
//!
//! Every entry point takes a `max_out` cap on the *decompressed* size and returns
//! `(bytes, truncated)`: a decompression bomb (a tiny input expanding without
//! bound) is stopped at the cap with `truncated == true`, never exhausting
//! memory. Trailing checksums (gzip CRC-32 / ISIZE, zlib Adler-32) are parsed
//! past but not verified — the crawler wants the content, not integrity, and the
//! decoded bytes are what the cross-check pins.

use std::fmt;

/// A malformed-input error from the inflater.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InflateError(pub String);

impl fmt::Display for InflateError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

impl std::error::Error for InflateError {}

const MAXBITS: usize = 15;
const MAXLCODES: usize = 286;
const MAXDCODES: usize = 30;

/// Length base for length symbols 257..=285 (index = symbol - 257).
#[rustfmt::skip]
const LENS: [u16; 29] = [
    3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15, 17, 19, 23, 27, 31,
    35, 43, 51, 59, 67, 83, 99, 115, 131, 163, 195, 227, 258,
];
/// Extra bits for each length symbol.
#[rustfmt::skip]
const LEXT: [u16; 29] = [
    0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2,
    3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 5, 5, 0,
];
/// Distance base for distance symbols 0..=29.
#[rustfmt::skip]
const DISTS: [u16; 30] = [
    1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193,
    257, 385, 513, 769, 1025, 1537, 2049, 3073, 4097, 6145, 8193, 12289, 16385, 24577,
];
/// Extra bits for each distance symbol.
#[rustfmt::skip]
const DEXT: [u16; 30] = [
    0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6,
    7, 7, 8, 8, 9, 9, 10, 10, 11, 11, 12, 12, 13, 13,
];
/// Order of the 19 code-length-code lengths in a dynamic block header.
#[rustfmt::skip]
const ORDER: [usize; 19] = [16, 17, 18, 0, 8, 7, 9, 6, 10, 5, 11, 4, 12, 3, 13, 2, 14, 1, 15];

/// A canonical Huffman table (counts per length + symbols sorted by length).
struct Huffman {
    count: [u16; MAXBITS + 1],
    symbol: Vec<u16>,
}

impl Huffman {
    /// Build from per-symbol code lengths (0 = unused).
    fn construct(lengths: &[u16]) -> Huffman {
        let mut count = [0u16; MAXBITS + 1];
        for &len in lengths {
            count[len as usize] += 1;
        }
        let mut offs = [0u16; MAXBITS + 1];
        for len in 1..MAXBITS {
            offs[len + 1] = offs[len] + count[len];
        }
        let mut symbol = vec![0u16; lengths.len()];
        for (sym, &len) in lengths.iter().enumerate() {
            if len != 0 {
                symbol[offs[len as usize] as usize] = sym as u16;
                offs[len as usize] += 1;
            }
        }
        Huffman { count, symbol }
    }

    /// Kraft-inequality slack: `0` = complete, `> 0` = incomplete, `< 0` =
    /// over-subscribed. This is what puff's `construct` returns and what the
    /// port originally dropped on the floor — without it crawlcore happily
    /// decoded streams every real decoder rejects. Concretely, the 13-byte
    /// over-subscribed stream `05 c0 01 09 00 00 00 80 20 fb 7f 5a 04` returned
    /// `Ok([0x00])` here while Python's zlib raised "invalid literal/lengths
    /// set" — a content differential a hostile origin can aim at the indexer.
    fn slack(&self) -> i32 {
        let mut left: i32 = 1;
        for len in 1..=MAXBITS {
            left <<= 1;
            left -= i32::from(self.count[len]);
            if left < 0 {
                return left; // over-subscribed: stop early, as puff does
            }
        }
        left
    }

    /// Whether an incomplete code is the one degenerate case RFC 1951 permits:
    /// no used symbols at all, or exactly one symbol of length 1. `n` is the
    /// number of symbols the code was built over.
    fn incomplete_ok(&self, n: usize) -> bool {
        n == usize::from(self.count[0]) + usize::from(self.count[1])
    }
}

struct State<'a> {
    input: &'a [u8],
    incnt: usize,
    bitbuf: u32,
    bitcnt: u32,
    out: Vec<u8>,
    max_out: usize,
    truncated: bool,
}

impl<'a> State<'a> {
    fn new(input: &'a [u8], max_out: usize) -> Self {
        State {
            input,
            incnt: 0,
            bitbuf: 0,
            bitcnt: 0,
            out: Vec::new(),
            max_out,
            truncated: false,
        }
    }

    /// Read `need` bits, least-significant bit first (RFC 1951 §3.1.1).
    fn bits(&mut self, need: u32) -> Result<u32, InflateError> {
        let mut val = self.bitbuf;
        while self.bitcnt < need {
            if self.incnt >= self.input.len() {
                return Err(InflateError("out of input".to_string()));
            }
            val |= u32::from(self.input[self.incnt]) << self.bitcnt;
            self.incnt += 1;
            self.bitcnt += 8;
        }
        self.bitbuf = val >> need;
        self.bitcnt -= need;
        Ok(val & ((1u32 << need) - 1))
    }

    /// Push a decoded byte, honouring the output cap. Returns `true` if the cap
    /// was hit (caller should stop decoding).
    fn emit(&mut self, byte: u8) -> bool {
        if self.out.len() < self.max_out {
            self.out.push(byte);
            false
        } else {
            self.truncated = true;
            true
        }
    }

    /// Decode one symbol from a Huffman table (puff.c's `decode`).
    fn decode(&mut self, h: &Huffman) -> Result<u16, InflateError> {
        let mut code: i32 = 0;
        let mut first: i32 = 0;
        let mut index: i32 = 0;
        for len in 1..=MAXBITS {
            code |= self.bits(1)? as i32;
            let count = i32::from(h.count[len]);
            if code - count < first {
                return Ok(h.symbol[(index + (code - first)) as usize]);
            }
            index += count;
            first += count;
            first <<= 1;
            code <<= 1;
        }
        Err(InflateError("invalid Huffman code".to_string()))
    }

    /// A stored (uncompressed) block.
    fn stored(&mut self) -> Result<(), InflateError> {
        // discard remaining bits in the current byte
        self.bitbuf = 0;
        self.bitcnt = 0;
        if self.incnt + 4 > self.input.len() {
            return Err(InflateError("stored block header truncated".to_string()));
        }
        let len =
            usize::from(self.input[self.incnt]) | (usize::from(self.input[self.incnt + 1]) << 8);
        let nlen = usize::from(self.input[self.incnt + 2])
            | (usize::from(self.input[self.incnt + 3]) << 8);
        self.incnt += 4;
        if nlen != (!len & 0xffff) {
            return Err(InflateError("stored block length mismatch".to_string()));
        }
        if self.incnt + len > self.input.len() {
            return Err(InflateError("stored block data truncated".to_string()));
        }
        for _ in 0..len {
            let b = self.input[self.incnt];
            self.incnt += 1;
            if self.emit(b) {
                return Ok(());
            }
        }
        Ok(())
    }

    /// Decode a compressed block's symbols with the given Huffman tables.
    fn codes(&mut self, lencode: &Huffman, distcode: &Huffman) -> Result<(), InflateError> {
        loop {
            let symbol = self.decode(lencode)?;
            if symbol == 256 {
                return Ok(()); // end of block
            }
            if symbol < 256 {
                if self.emit(symbol as u8) {
                    return Ok(());
                }
            } else {
                let s = (symbol - 257) as usize;
                if s >= 29 {
                    return Err(InflateError("invalid length symbol".to_string()));
                }
                let len = LENS[s] as usize + self.bits(u32::from(LEXT[s]))? as usize;
                let dsym = self.decode(distcode)? as usize;
                if dsym >= MAXDCODES {
                    return Err(InflateError("invalid distance symbol".to_string()));
                }
                let dist = DISTS[dsym] as usize + self.bits(u32::from(DEXT[dsym]))? as usize;
                if dist > self.out.len() {
                    return Err(InflateError("distance too far back".to_string()));
                }
                for _ in 0..len {
                    let b = self.out[self.out.len() - dist];
                    if self.emit(b) {
                        return Ok(());
                    }
                }
            }
        }
    }

    /// A fixed-Huffman block.
    fn fixed(&mut self) -> Result<(), InflateError> {
        // The fixed literal/length alphabet has 288 symbols (0..=287); 286/287
        // are reserved but MUST be present so the canonical length-9 codes get
        // the right base (otherwise any literal >= 144 decodes wrong).
        let mut lengths = [0u16; 288];
        for (i, l) in lengths.iter_mut().enumerate() {
            *l = match i {
                0..=143 => 8,
                144..=255 => 9,
                256..=279 => 7,
                _ => 8, // 280..=287
            };
        }
        let lencode = Huffman::construct(&lengths);
        let distcode = Huffman::construct(&[5u16; MAXDCODES]);
        self.codes(&lencode, &distcode)
    }

    /// A dynamic-Huffman block: read the code-length descriptions, then decode.
    fn dynamic(&mut self) -> Result<(), InflateError> {
        let nlen = self.bits(5)? as usize + 257;
        let ndist = self.bits(5)? as usize + 1;
        let ncode = self.bits(4)? as usize + 4;
        if nlen > MAXLCODES || ndist > MAXDCODES {
            return Err(InflateError("too many codes".to_string()));
        }
        // code-length code lengths
        let mut cl = [0u16; 19];
        for i in 0..ncode {
            cl[ORDER[i]] = self.bits(3)? as u16;
        }
        let clcode = Huffman::construct(&cl);
        // The code-length code must be COMPLETE — puff refuses any non-zero
        // slack here outright (its error -4).
        if clcode.slack() != 0 {
            return Err(InflateError("incomplete code-length code".to_string()));
        }

        // read literal/length + distance code lengths
        let mut lengths = vec![0u16; nlen + ndist];
        let mut index = 0;
        while index < nlen + ndist {
            let symbol = self.decode(&clcode)?;
            if symbol < 16 {
                lengths[index] = symbol;
                index += 1;
            } else {
                let (repeat, value) = match symbol {
                    16 => {
                        if index == 0 {
                            return Err(InflateError("repeat with no previous length".to_string()));
                        }
                        (3 + self.bits(2)? as usize, lengths[index - 1])
                    }
                    17 => (3 + self.bits(3)? as usize, 0),
                    18 => (11 + self.bits(7)? as usize, 0),
                    _ => return Err(InflateError("invalid code-length symbol".to_string())),
                };
                if index + repeat > nlen + ndist {
                    return Err(InflateError("repeat overflows code lengths".to_string()));
                }
                for _ in 0..repeat {
                    lengths[index] = value;
                    index += 1;
                }
            }
        }
        if lengths[256] == 0 {
            return Err(InflateError("no end-of-block code".to_string()));
        }
        let lencode = Huffman::construct(&lengths[..nlen]);
        // Over-subscribed is always fatal; incomplete is allowed only in the
        // degenerate no-symbol/one-symbol case (puff's
        // `err < 0 || nlen != count[0] + count[1]` test, errors -7 and -8).
        if lencode.slack() != 0 && (lencode.slack() < 0 || !lencode.incomplete_ok(nlen)) {
            return Err(InflateError("invalid literal/length code".to_string()));
        }
        let distcode = Huffman::construct(&lengths[nlen..]);
        if distcode.slack() != 0 && (distcode.slack() < 0 || !distcode.incomplete_ok(ndist)) {
            return Err(InflateError("invalid distance code".to_string()));
        }
        self.codes(&lencode, &distcode)
    }

    fn run(&mut self) -> Result<(), InflateError> {
        loop {
            let last = self.bits(1)?;
            let btype = self.bits(2)?;
            match btype {
                0 => self.stored()?,
                1 => self.fixed()?,
                2 => self.dynamic()?,
                _ => return Err(InflateError("invalid block type".to_string())),
            }
            if last == 1 || self.truncated {
                return Ok(());
            }
        }
    }
}

/// Inflate a raw DEFLATE stream (no wrapper), capping decompressed output at
/// `max_out`. Returns `(bytes, truncated)`.
///
/// # Errors
/// [`InflateError`] on malformed DEFLATE data.
pub fn inflate_raw(data: &[u8], max_out: usize) -> Result<(Vec<u8>, bool), InflateError> {
    inflate_raw_at(data, max_out).map(|(out, trunc, _)| (out, trunc))
}

/// As [`inflate_raw`], but also reports how many input bytes the stream
/// consumed, so a wrapper can find what follows it (gzip members concatenate).
fn inflate_raw_at(data: &[u8], max_out: usize) -> Result<(Vec<u8>, bool, usize), InflateError> {
    let mut state = State::new(data, max_out);
    state.run()?;
    let used = state.incnt;
    Ok((state.out, state.truncated, used))
}

/// Inflate a zlib (RFC 1950) stream: a 2-byte header, the DEFLATE body, and a
/// 4-byte Adler-32 trailer (parsed past, not verified).
///
/// # Errors
/// [`InflateError`] on a bad zlib header or malformed body.
pub fn inflate_zlib(data: &[u8], max_out: usize) -> Result<(Vec<u8>, bool), InflateError> {
    if data.len() < 2 {
        return Err(InflateError("zlib stream too short".to_string()));
    }
    let cmf = data[0];
    let flg = data[1];
    if cmf & 0x0f != 8 {
        return Err(InflateError("zlib: not DEFLATE".to_string()));
    }
    if (u16::from(cmf) * 256 + u16::from(flg)) % 31 != 0 {
        return Err(InflateError("zlib: bad header check".to_string()));
    }
    // FDICT set => the stream needs a preset dictionary we do not have, so it
    // cannot be decoded. Stepping over the 4-byte dict id and inflating anyway
    // (what this used to do) produced plausible-looking output for a stream
    // Python's `zlib.decompress` rejects with "Error 2 while decompressing" —
    // the same accept-where-everyone-else-refuses family as an over-subscribed
    // Huffman table.
    if flg & 0x20 != 0 {
        return Err(InflateError(
            "zlib: preset dictionary required (FDICT)".to_string(),
        ));
    }
    inflate_raw(&data[2..], max_out)
}

/// Inflate a gzip (RFC 1952) stream: a variable-length header (magic, method,
/// flags, and any FEXTRA/FNAME/FCOMMENT/FHCRC fields), the DEFLATE body, and an
/// 8-byte CRC-32/ISIZE trailer (parsed past, not verified).
///
/// gzip files may hold **several concatenated members**, and `gzip.decompress`
/// returns all of them joined; decoding only the first — what this used to do,
/// silently and with `truncated == false` — let an origin hide half a page from
/// the indexer while every browser saw the whole thing.
///
/// # Errors
/// [`InflateError`] on a bad gzip header or malformed body.
pub fn inflate_gzip(data: &[u8], max_out: usize) -> Result<(Vec<u8>, bool), InflateError> {
    let mut out: Vec<u8> = Vec::new();
    let mut rest = data;
    loop {
        let room = max_out.saturating_sub(out.len());
        let (bytes, truncated, used) = inflate_gzip_member(rest, room)?;
        out.extend_from_slice(&bytes);
        if truncated {
            return Ok((out, true));
        }
        // Past the member's 8-byte CRC-32/ISIZE trailer; anything left that
        // still starts with the gzip magic is another member.
        let after = used.saturating_add(8);
        if after >= rest.len() {
            return Ok((out, false));
        }
        rest = &rest[after..];
        if rest.len() < 2 || rest[0] != 0x1f || rest[1] != 0x8b {
            // Trailing garbage, exactly as CPython's `gzip.decompress` treats a
            // non-member tail: stop, keep what decoded.
            return Ok((out, false));
        }
        if room == 0 {
            return Ok((out, true));
        }
    }
}

/// One gzip member: `(bytes, truncated, input_bytes_consumed_before_the_trailer)`.
fn inflate_gzip_member(
    data: &[u8],
    max_out: usize,
) -> Result<(Vec<u8>, bool, usize), InflateError> {
    if data.len() < 10 {
        return Err(InflateError("gzip stream too short".to_string()));
    }
    if data[0] != 0x1f || data[1] != 0x8b {
        return Err(InflateError("gzip: bad magic".to_string()));
    }
    if data[2] != 8 {
        return Err(InflateError("gzip: not DEFLATE".to_string()));
    }
    let flg = data[3];
    let mut pos = 10; // fixed header
    let need = |pos: usize, n: usize| -> Result<(), InflateError> {
        if pos + n > data.len() {
            Err(InflateError("gzip header truncated".to_string()))
        } else {
            Ok(())
        }
    };
    if flg & 0x04 != 0 {
        // FEXTRA: 2-byte length + that many bytes
        need(pos, 2)?;
        let xlen = usize::from(data[pos]) | (usize::from(data[pos + 1]) << 8);
        pos += 2;
        need(pos, xlen)?;
        pos += xlen;
    }
    if flg & 0x08 != 0 {
        // FNAME: zero-terminated
        pos = skip_zstring(data, pos)?;
    }
    if flg & 0x10 != 0 {
        // FCOMMENT: zero-terminated
        pos = skip_zstring(data, pos)?;
    }
    if flg & 0x02 != 0 {
        // FHCRC: 2 bytes
        need(pos, 2)?;
        pos += 2;
    }
    let (out, truncated, used) = inflate_raw_at(&data[pos..], max_out)?;
    Ok((out, truncated, pos + used))
}

fn skip_zstring(data: &[u8], mut pos: usize) -> Result<usize, InflateError> {
    while pos < data.len() {
        let b = data[pos];
        pos += 1;
        if b == 0 {
            return Ok(pos);
        }
    }
    Err(InflateError("gzip header string unterminated".to_string()))
}

#[cfg(test)]
mod tests {
    use super::*;

    // A fixed-Huffman DEFLATE stream for "hello" (produced by zlib, raw).
    const HELLO_RAW: &[u8] = &[0xcb, 0x48, 0xcd, 0xc9, 0xc9, 0x07, 0x00];

    #[test]
    fn raw_roundtrip() {
        let (out, trunc) = inflate_raw(HELLO_RAW, 1_000_000).unwrap();
        assert_eq!(out, b"hello");
        assert!(!trunc);
    }

    #[test]
    fn output_cap_truncates() {
        let (out, trunc) = inflate_raw(HELLO_RAW, 3).unwrap();
        assert_eq!(out, b"hel");
        assert!(trunc);
    }

    #[test]
    fn bad_input_errors() {
        assert!(inflate_raw(&[0xff, 0xff, 0xff], 100).is_err()); // invalid block type
        assert!(inflate_zlib(&[0x00], 100).is_err());
        assert!(inflate_gzip(&[0x1f, 0x8b], 100).is_err());
    }
}

#[cfg(test)]
mod audit_regression {
    use super::*;

    fn unhex(s: &str) -> Vec<u8> {
        (0..s.len())
            .step_by(2)
            .map(|i| u8::from_str_radix(&s[i..i + 2], 16).unwrap())
            .collect()
    }

    /// A DEFLATE block whose literal/length code is OVER-SUBSCRIBED (Kraft sum
    /// 1.25). Every real decoder refuses it — Python's zlib raises "invalid
    /// literal/lengths set" — but this returned `Ok([0x00])`, because the port
    /// dropped puff's `construct` return value. A hostile origin can use that to
    /// feed the indexer a body no browser will ever render.
    #[test]
    fn an_oversubscribed_huffman_table_is_refused() {
        assert!(inflate_raw(
            &unhex("05c00109000000802 0fb7f5a04".replace(' ', "").as_str()),
            1 << 20
        )
        .is_err());
    }

    /// The other half of the same missing check: an INCOMPLETE code (Kraft sum
    /// 0.75) that is not the degenerate one-symbol case RFC 1951 allows.
    #[test]
    fn an_incomplete_huffman_table_is_refused() {
        assert!(inflate_raw(
            &unhex("05c0010900000080 20ffaf0e01".replace(' ', "").as_str()),
            1 << 20
        )
        .is_err());
    }

    /// The completeness check must not reject what real compressors emit.
    #[test]
    fn well_formed_streams_still_decode() {
        // Fixed-Huffman block for "hello".
        assert_eq!(
            inflate_raw(&[0xcb, 0x48, 0xcd, 0xc9, 0xc9, 0x07, 0x00], 1 << 20)
                .unwrap()
                .0,
            b"hello"
        );
        // A real DYNAMIC-Huffman block (BTYPE=2) — what the new check gates.
        let dynamic = unhex(concat!(
            "edcec11183201404d05636f78c75e49899d800444012044504a5fa60262de4b6e7fd7ff7f5a3",
            "c2b2d9e71b3286e2a1c38ed736cd2b425611a9c54ed40343301deea2dd4d07643b2a368dd036",
            "ab1655e5e1ecb285d87ecddae1160ab2daad37eef8d50f42275425a358bf03173ce6d1fa1d41",
            "43bab378d9444cf5dad2c1a8732687d2a1279040020924904002092490400209249040020924",
            "904002092490400209fc0ff003",
        ));
        let (out, truncated) = inflate_raw(&dynamic, 1 << 20).unwrap();
        assert_eq!(out.len(), 6440);
        assert!(!truncated);
        assert!(out.starts_with(b"The quick brown fox"));
    }

    /// FDICT means the stream needs a preset dictionary we do not have, so it is
    /// undecodable. Skipping the 4-byte dict id and inflating anyway produced
    /// plausible output for a stream `zlib.decompress` rejects.
    #[test]
    fn a_zlib_stream_needing_a_preset_dictionary_is_refused() {
        assert!(inflate_zlib(
            &unhex("782000000001cb48cdc9c95728cf2fca490100000000"),
            1 << 20
        )
        .is_err());
    }

    /// gzip files may hold several concatenated members and `gzip.decompress`
    /// joins them all. Decoding only the first — silently, with
    /// `truncated == false` — let an origin hide half a page from the indexer.
    #[test]
    fn every_gzip_member_is_decoded_not_just_the_first() {
        let two = unhex(concat!(
            "1f8b080094317c6a02ff737474740400f1080d9b04000000",
            "1f8b080094317c6a02ff7372727202003f1bda3904000000",
        ));
        assert_eq!(
            inflate_gzip(&two, 1 << 20).unwrap(),
            (b"AAAABBBB".to_vec(), false)
        );
        // …and the output cap still governs across members.
        let (out, truncated) = inflate_gzip(&two, 6).unwrap();
        assert!(out.len() <= 6 && truncated);
    }
}
