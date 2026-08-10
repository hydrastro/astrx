//! Entity-extraction verticals for the onion index.
//!
//! The intel angle that Recon / Kilos made their signature: let an analyst pivot
//! from a page to *every other indexed onion that carries the same PGP key or
//! cryptocurrency address*. This is the pure extractor — a linear, hard-bounded
//! stdlib scan over page text, no network, that never panics.
//!
//! Extracted kinds: `pgp` (armored PUBLIC KEY BLOCK, keyed by SHA-1 of the
//! whitespace-stripped armor body — a dedupable surrogate for the real OpenPGP
//! fingerprint), `btc` (legacy/P2SH base58 + bech32), `xmr` (95-char standard
//! address), `eth` (`0x` + 40 hex). Extraction is heuristic (no base58/bech32
//! checksum verification) — a lead, not proof — matching the norm for these
//! crawlers.
//!
//! Ported from the Python `entities.py`; the regexes are reproduced as
//! backtracking-free character scans (the length + `\b` boundary semantics are
//! preserved exactly) and cross-checked byte-identical in
//! `tests/xcheck_entities.rs`.

use crawlcore::hash::{sha1, to_hex};
use std::collections::HashSet;

const MAX_TEXT: usize = 2_000_000; // scan at most ~2 MB of page text
const MAX_PER_KIND: usize = 100; // cap entities of each kind per page
const PGP_BODY_CAP: usize = 100_000; // cap a crafted giant armor body
const PGP_BEGIN: &str = "-----BEGIN PGP PUBLIC KEY BLOCK-----";
const PGP_END: &str = "-----END PGP PUBLIC KEY BLOCK-----";

/// The kind of an extracted entity.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Kind {
    /// ASCII-armored PGP public key (value = SHA-1 of the stripped armor body).
    Pgp,
    /// Bitcoin address (legacy/P2SH base58 or bech32).
    Btc,
    /// Monero standard address.
    Xmr,
    /// Ethereum / EVM address.
    Eth,
}

impl Kind {
    /// The lowercase tag used by the Python reference.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            Kind::Pgp => "pgp",
            Kind::Btc => "btc",
            Kind::Xmr => "xmr",
            Kind::Eth => "eth",
        }
    }
}

// --- character classes ------------------------------------------------------

/// Python `\w` (Unicode): a word char is alphanumeric or `_`.
#[inline]
fn word_char(c: char) -> bool {
    c.is_alphanumeric() || c == '_'
}

/// Base58 alphabet `[1-9A-HJ-NP-Za-km-z]` (no `0 O I l`).
#[inline]
fn is_base58(c: char) -> bool {
    matches!(c, '1'..='9' | 'A'..='H' | 'J'..='N' | 'P'..='Z' | 'a'..='k' | 'm'..='z')
}

/// bech32 body class `[a-z0-9]`.
#[inline]
fn is_bech32(c: char) -> bool {
    matches!(c, 'a'..='z' | '0'..='9')
}

/// Hex `[a-fA-F0-9]`.
#[inline]
fn is_hex(c: char) -> bool {
    c.is_ascii_hexdigit()
}

/// `\b` immediately before index `i`. The pattern's first char is always a word
/// char, so the boundary holds iff the preceding char is a non-word char (or the
/// start of input).
#[inline]
fn boundary_before(chars: &[char], i: usize) -> bool {
    i == 0 || !word_char(chars[i - 1])
}

/// `\b` at index `q`. The last matched char is always a word char, so the
/// boundary holds iff the char at `q` is a non-word char (or end of input).
#[inline]
fn boundary_at(chars: &[char], q: usize) -> bool {
    q >= chars.len() || !word_char(chars[q])
}

/// Count the maximal run of a class starting at `p`, capped at `cap`.
fn run_len(chars: &[char], p: usize, cap: usize, class: fn(char) -> bool) -> usize {
    let mut l = 0;
    while l < cap && p + l < chars.len() && class(chars[p + l]) {
        l += 1;
    }
    l
}

// --- per-kind matchers (return the end index of a match anchored at `i`) -----

/// `\b(?:[13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[a-z0-9]{11,71})\b`
fn btc_match(chars: &[char], i: usize) -> Option<usize> {
    if !boundary_before(chars, i) {
        return None;
    }
    match chars[i] {
        '1' | '3' => {
            let p = i + 1;
            let l = run_len(chars, p, 35, is_base58); // 35 > 34, so an over-long run fails the range
            ((25..=34).contains(&l) && boundary_at(chars, p + l)).then_some(p + l)
        }
        'b' => {
            if i + 3 <= chars.len() && chars[i + 1] == 'c' && chars[i + 2] == '1' {
                let p = i + 3;
                let l = run_len(chars, p, 72, is_bech32); // 72 > 71
                ((11..=71).contains(&l) && boundary_at(chars, p + l)).then_some(p + l)
            } else {
                None
            }
        }
        _ => None,
    }
}

/// `\b4[0-9AB][1-9A-HJ-NP-Za-km-z]{93}\b`
fn xmr_match(chars: &[char], i: usize) -> Option<usize> {
    if !boundary_before(chars, i) || chars[i] != '4' {
        return None;
    }
    let c1 = *chars.get(i + 1)?;
    if !(c1.is_ascii_digit() || c1 == 'A' || c1 == 'B') {
        return None;
    }
    let p = i + 2;
    let l = run_len(chars, p, 93, is_base58);
    (l == 93 && boundary_at(chars, p + 93)).then_some(p + 93)
}

/// `\b0x[a-fA-F0-9]{40}\b`
fn eth_match(chars: &[char], i: usize) -> Option<usize> {
    if !boundary_before(chars, i) || chars[i] != '0' || chars.get(i + 1) != Some(&'x') {
        return None;
    }
    let p = i + 2;
    let l = run_len(chars, p, 40, is_hex);
    (l == 40 && boundary_at(chars, p + 40)).then_some(p + 40)
}

/// Non-overlapping, leftmost scan (a `finditer`), stopping after `limit` matches.
fn finditer(
    chars: &[char],
    matcher: fn(&[char], usize) -> Option<usize>,
    limit: usize,
) -> Vec<(usize, usize)> {
    let mut out = Vec::new();
    let mut i = 0;
    while i < chars.len() && out.len() < limit {
        if let Some(end) = matcher(chars, i) {
            out.push((i, end));
            i = end.max(i + 1);
        } else {
            i += 1;
        }
    }
    out
}

fn add(
    out: &mut Vec<(Kind, String)>,
    seen: &mut HashSet<(Kind, String)>,
    kind: Kind,
    value: String,
) {
    if seen.insert((kind, value.clone())) {
        out.push((kind, value));
    }
}

/// Return a de-duplicated list of `(kind, value)` entities found in *text*.
///
/// Order: PGP keys first (document order), then btc, xmr, eth. Bounded in both
/// scan length (~2 MB) and per-kind count (100). Never panics.
#[must_use]
pub fn extract(text: &str) -> Vec<(Kind, String)> {
    if text.is_empty() {
        return Vec::new();
    }
    let t: String = text.chars().take(MAX_TEXT).collect();
    let mut out: Vec<(Kind, String)> = Vec::new();
    let mut seen: HashSet<(Kind, String)> = HashSet::new();

    // PGP: a linear substring scan (str::find is C-level; a missing END simply
    // ends the scan) — a hostile page of many BEGIN markers can't go quadratic.
    let mut n = 0;
    let mut pos = 0;
    while n < MAX_PER_KIND {
        let Some(b) = t[pos..].find(PGP_BEGIN).map(|x| pos + x) else {
            break;
        };
        let after_begin = b + PGP_BEGIN.len();
        let Some(e) = t[after_begin..].find(PGP_END).map(|x| after_begin + x) else {
            break; // no closing marker -> no more complete blocks
        };
        let body: String = t[after_begin..e].chars().take(PGP_BODY_CAP).collect();
        let stripped: String = body.chars().filter(|c| !c.is_whitespace()).collect();
        add(
            &mut out,
            &mut seen,
            Kind::Pgp,
            to_hex(&sha1(stripped.as_bytes())),
        );
        pos = e + PGP_END.len();
        n += 1;
    }

    // btc / xmr / eth: three independent non-overlapping scans, in that order.
    let chars: Vec<char> = t.chars().collect();
    for (kind, matcher) in [
        (Kind::Btc, btc_match as fn(&[char], usize) -> Option<usize>),
        (Kind::Xmr, xmr_match),
        (Kind::Eth, eth_match),
    ] {
        for (s, en) in finditer(&chars, matcher, MAX_PER_KIND) {
            add(&mut out, &mut seen, kind, chars[s..en].iter().collect());
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn empty_and_none() {
        assert!(extract("").is_empty());
        assert!(extract("nothing to see here, just prose").is_empty());
    }

    #[test]
    fn eth_rejects_over_and_under_length() {
        // exactly 40 hex → match; 39 or 41 → none (the trailing \b rejects
        // longer hashes, and 39 can't reach {40}).
        let ok = "pay 0x52908400098527886E0F7030069857D2E4169EE7 now";
        assert_eq!(
            extract(ok),
            vec![(
                Kind::Eth,
                "0x52908400098527886E0F7030069857D2E4169EE7".into()
            )]
        );
        let long = "0x52908400098527886E0F7030069857D2E4169EE7a"; // 41 hex
        assert!(extract(long).is_empty());
    }

    #[test]
    fn btc_adjacency_blocked() {
        // embedded in a longer word-char run → no boundary → no match.
        assert!(extract("xx1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2xx").is_empty());
    }
}
