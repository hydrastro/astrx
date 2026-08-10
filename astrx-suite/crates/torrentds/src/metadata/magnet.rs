//! Pure `magnet:` URI parsing: `xt=urn:btih:` (v1, hex or base32) and/or
//! `xt=urn:btmh:1220<64hex>` (BEP-52 v2 multihash), plus an optional `dn` display
//! name. Hybrid magnets carrying both `xt` values are supported. No third-party
//! deps; fail-closed on a recognised-but-malformed `xt`, matching the reference.

use super::{merr, MetadataError};

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
    #[must_use]
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
