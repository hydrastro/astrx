//! Darknet host validation and enforcement (Tor `.onion` + optional I2P `.i2p`).
//!
//! The single source of truth for "is this a darknet host we are allowed to
//! touch". Everything that opens a socket or enqueues a URL goes through here so
//! a clearnet / localhost / IP-literal host can never leak out over the network.
//!
//! Ported byte-for-byte from the Python `onion.py`. The regexes there are
//! reproduced as hand-rolled, allocation-free character scans (no `regex`
//! dependency); the cross-check in `tests/xcheck_onion.rs` pins every function's
//! output against goldens emitted by driving the Python module directly.
//!
//! | network  | form                                             |
//! |----------|--------------------------------------------------|
//! | v3 onion | 56 base32 chars + `.onion` (ed25519 key+cksum+ver) |
//! | v2 onion | 16 base32 chars + `.onion` (deprecated, off by default) |
//! | i2p b32  | 52 base32 chars + `.b32.i2p`                     |
//! | i2p name | any hostname ending in `.i2p` (anchored)         |
//!
//! Base32 alphabet (RFC 4648, lowercase): `a-z` and `2-7` (no `0 1 8 9`).
//!
//! # The gate, as a type
//!
//! The free functions ([`is_onion_host`], [`require`-style parsing on the
//! newtypes], …) mirror the Python API for the canonicalizer and text scanner to
//! call. The anti-leak *enforcement* points, however, are the newtypes
//! [`OnionHost`], [`I2pHost`] and [`DarknetHost`]: they wrap a validated,
//! normalized host and can only be constructed through a parser that runs the
//! same admission test `require_onion` / `require_i2p` / `require_darknet` do.
//! A socket-opening API that takes an `&OnionHost` therefore cannot be handed a
//! clearnet string at all — the leak becomes unrepresentable.

use std::fmt;

// --- base32 predicates ------------------------------------------------------

/// `[a-z2-7]` — the strict, lowercase RFC-4648 base32 class the validators use
/// (hosts are lowercased by [`normalize_host`] before validation).
#[inline]
fn is_b32_lower(b: u8) -> bool {
    b.is_ascii_lowercase() || matches!(b, b'2'..=b'7')
}

/// `[a-z2-7]` under `re.IGNORECASE` — i.e. `[a-zA-Z2-7]`. Used by the in-text
/// scanner, which runs over raw (un-normalized) page text.
#[inline]
fn is_b32_ci(c: char) -> bool {
    c.is_ascii_alphabetic() || matches!(c, '2'..='7')
}

// --- syntactic validators (operate on an already-normalized host) -----------

/// `^[a-z2-7]{56}\.onion$`
fn is_v3(h: &str) -> bool {
    let b = h.as_bytes();
    b.len() == 62 && &b[56..] == b".onion" && b[..56].iter().all(|&c| is_b32_lower(c))
}

/// `^[a-z2-7]{16}\.onion$`
fn is_v2(h: &str) -> bool {
    let b = h.as_bytes();
    b.len() == 22 && &b[16..] == b".onion" && b[..16].iter().all(|&c| is_b32_lower(c))
}

/// `^[a-z2-7]{52}\.b32\.i2p$`
fn is_i2p_b32(h: &str) -> bool {
    let b = h.as_bytes();
    b.len() == 60 && &b[52..] == b".b32.i2p" && b[..52].iter().all(|&c| is_b32_lower(c))
}

/// `^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+i2p$` — one or more DNS-ish labels,
/// then the literal `i2p` TLD. Anchored on `.i2p`, so no clearnet TLD can match
/// and a bare `i2p` / `.i2p` (no label) is refused.
fn is_i2p_name(h: &str) -> bool {
    // The string must be `label ('.' label)* '.' i2p`, i.e. strip the trailing
    // literal `i2p`, require a `.` before it, then validate each dot-separated
    // label to the left.
    let Some(prefix) = h.strip_suffix("i2p") else {
        return false;
    };
    let Some(body) = prefix.strip_suffix('.') else {
        return false; // needs a `.` immediately before the `i2p` TLD
    };
    if body.is_empty() {
        return false; // ".i2p" alone — no label
    }
    body.split('.').all(is_i2p_label)
}

/// `[a-z0-9](?:[a-z0-9-]*[a-z0-9])?` — a label starting and ending alphanumeric,
/// with `-` allowed only in the interior. Single-char labels are valid.
fn is_i2p_label(label: &str) -> bool {
    let b = label.as_bytes();
    let alnum = |c: u8| c.is_ascii_lowercase() || c.is_ascii_digit();
    match b {
        [] => false,
        [only] => alnum(*only),
        [first, mid @ .., last] => {
            alnum(*first) && alnum(*last) && mid.iter().all(|&c| alnum(c) || c == b'-')
        }
    }
}

// --- normalization ----------------------------------------------------------

/// Lowercase, strip surrounding whitespace, any `userinfo@`, any `:port` (or a
/// bracketed IPv6 body), and **all** trailing dots. Never fails.
///
/// Stripping every trailing dot (not just one) is deliberate: `<h>.onion.` and
/// `<h>.onion` route to the same hidden service, but the dotted form would
/// otherwise be a distinct key that slips a dotless-host blocklist and splits
/// per-host politeness. `rstrip('.')` makes normalization idempotent so every
/// trailing-dot variant collapses to the single canonical key.
#[must_use]
pub fn normalize_host(host: &str) -> String {
    let mut h = host.trim().to_lowercase();
    // strip userinfo if somehow present (take the part after the last '@')
    if let Some(idx) = h.rfind('@') {
        h = h[idx + 1..].to_string();
    }
    if let Some(rest) = h.strip_prefix('[') {
        // bracketed IPv6 — never an onion; keep the bracket body only
        h = rest.split(']').next().unwrap_or("").to_string();
    } else if let Some(idx) = h.rfind(':') {
        // onion hosts contain no ':', so a ':' is always a port separator
        h.truncate(idx);
    }
    // strip ALL trailing dots (FQDN root)
    let end = h.trim_end_matches('.').len();
    h.truncate(end);
    h
}

// --- onion admission --------------------------------------------------------

/// True iff *host* is a syntactically valid `.onion` address (port and case are
/// normalized first; v2 is accepted only when *allow_v2*).
#[must_use]
pub fn is_onion_host(host: &str, allow_v2: bool) -> bool {
    let h = normalize_host(host);
    if h.is_empty() {
        return false;
    }
    is_v3(&h) || (allow_v2 && is_v2(&h))
}

/// Return `Some(3)`, `Some(2)`, or `None` for the given host (ignores any allow
/// flag — reports what the host *is*).
#[must_use]
pub fn onion_version(host: &str) -> Option<u8> {
    let h = normalize_host(host);
    if is_v3(&h) {
        Some(3)
    } else if is_v2(&h) {
        Some(2)
    } else {
        None
    }
}

// --- i2p admission ----------------------------------------------------------

/// Which flavour of `.i2p` eepsite a host is.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum I2pKind {
    /// 52 base32 chars + `.b32.i2p` (base32 of the destination hash).
    B32,
    /// A named eepsite (`stats.i2p`).
    Name,
}

impl I2pKind {
    /// The lowercase tag used by the Python reference (`"b32"` / `"name"`).
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            I2pKind::B32 => "b32",
            I2pKind::Name => "name",
        }
    }
}

/// True iff *host* is a syntactically valid `.i2p` eepsite (b32 or named).
#[must_use]
pub fn is_i2p_host(host: &str) -> bool {
    let h = normalize_host(host);
    if h.is_empty() {
        return false;
    }
    is_i2p_b32(&h) || is_i2p_name(&h)
}

/// Return the [`I2pKind`] of *host*, or `None` if it is not a valid eepsite.
#[must_use]
pub fn i2p_kind(host: &str) -> Option<I2pKind> {
    let h = normalize_host(host);
    if is_i2p_b32(&h) {
        Some(I2pKind::B32)
    } else if is_i2p_name(&h) {
        Some(I2pKind::Name)
    } else {
        None
    }
}

// --- darknet admission (the frontier/submission boundary test) --------------

/// True iff *host* is a permitted darknet host: an `.onion` always, and an
/// `.i2p` only when *allow_i2p*. Clearnet / localhost / IP-literals are always
/// false. This is the admission test used at every frontier / submission
/// boundary; per-network socket locking is enforced by the newtypes below.
#[must_use]
pub fn is_darknet_host(host: &str, allow_v2: bool, allow_i2p: bool) -> bool {
    is_onion_host(host, allow_v2) || (allow_i2p && is_i2p_host(host))
}

// --- refusal error ----------------------------------------------------------

/// Why a host was refused admission. `NotDarknet` is the union refusal (neither
/// a permitted `.onion` nor an admitted `.i2p`); the three variants mirror the
/// Python `NotOnionError` / `NotDarknetError` messages.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Refusal {
    /// Not a permitted `.onion` (the crown anti-leak refusal).
    NotOnion,
    /// Not a valid `.i2p` eepsite.
    NotI2p,
    /// Neither a permitted `.onion` nor an admitted `.i2p`.
    NotDarknet,
}

impl Refusal {
    fn adjective(self) -> &'static str {
        match self {
            Refusal::NotOnion => "non-onion",
            Refusal::NotI2p => "non-i2p",
            Refusal::NotDarknet => "non-darknet",
        }
    }
}

/// A host that failed darknet admission, carrying the original (un-normalized)
/// argument for the diagnostic — as the Python `require_*` helpers do.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RefusedHost {
    /// The original host string that was refused.
    pub host: String,
    /// Which admission test it failed.
    pub reason: Refusal,
}

impl fmt::Display for RefusedHost {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(
            f,
            "refusing {} host: '{}'",
            self.reason.adjective(),
            self.host
        )
    }
}

impl std::error::Error for RefusedHost {}

// --- the crown-jewel newtypes ----------------------------------------------

/// A validated, normalized Tor `.onion` host. **Constructible only** through
/// [`OnionHost::parse`], which runs the same admission test the Python
/// `require_onion` did — so an API that accepts an `&OnionHost` can never be
/// handed a clearnet host. This is the anti-leak invariant expressed as a type.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct OnionHost(String);

impl OnionHost {
    /// Parse and validate, accepting **only** `.onion` (v2 gated behind
    /// *allow_v2*). Returns the normalized host wrapped, or [`RefusedHost`].
    ///
    /// # Errors
    /// [`Refusal::NotOnion`] if *host* is not a permitted `.onion` address.
    pub fn parse(host: &str, allow_v2: bool) -> Result<Self, RefusedHost> {
        let h = normalize_host(host);
        if is_onion_host(&h, allow_v2) {
            Ok(OnionHost(h))
        } else {
            Err(RefusedHost {
                host: host.to_string(),
                reason: Refusal::NotOnion,
            })
        }
    }

    /// The normalized host, e.g. `abcd…56….onion`.
    #[must_use]
    pub fn as_str(&self) -> &str {
        &self.0
    }

    /// The onion protocol version (`3` or `2`).
    #[must_use]
    pub fn version(&self) -> u8 {
        // Always Some for a validated host; 3 is the safe fallback.
        onion_version(&self.0).unwrap_or(3)
    }

    /// Consume into the owned host string.
    #[must_use]
    pub fn into_string(self) -> String {
        self.0
    }
}

impl fmt::Display for OnionHost {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

/// A validated, normalized I2P `.i2p` eepsite. Constructible only through
/// [`I2pHost::parse`] (the `require_i2p` analogue); accepts **only** `.i2p`.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub struct I2pHost(String);

impl I2pHost {
    /// Parse and validate, accepting **only** `.i2p`.
    ///
    /// # Errors
    /// [`Refusal::NotI2p`] if *host* is not a valid `.i2p` eepsite.
    pub fn parse(host: &str) -> Result<Self, RefusedHost> {
        let h = normalize_host(host);
        if is_i2p_host(&h) {
            Ok(I2pHost(h))
        } else {
            Err(RefusedHost {
                host: host.to_string(),
                reason: Refusal::NotI2p,
            })
        }
    }

    /// The normalized eepsite host.
    #[must_use]
    pub fn as_str(&self) -> &str {
        &self.0
    }

    /// Whether this is a `b32` or a named eepsite.
    #[must_use]
    pub fn kind(&self) -> I2pKind {
        i2p_kind(&self.0).unwrap_or(I2pKind::Name)
    }

    /// Consume into the owned host string.
    #[must_use]
    pub fn into_string(self) -> String {
        self.0
    }
}

impl fmt::Display for I2pHost {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(&self.0)
    }
}

/// A validated darknet host — either a Tor [`OnionHost`] or (when I2P is
/// enabled) an [`I2pHost`]. This is the `require_darknet` analogue used at
/// admission boundaries that accept either network; the per-network fetchers
/// still take the concrete `&OnionHost` / `&I2pHost`, so the two networks can
/// never cross-leak at the socket.
#[derive(Debug, Clone, PartialEq, Eq, Hash)]
pub enum DarknetHost {
    /// A Tor `.onion` host.
    Onion(OnionHost),
    /// An I2P `.i2p` eepsite.
    I2p(I2pHost),
}

impl DarknetHost {
    /// Parse and validate: an `.onion` always, an `.i2p` only when *allow_i2p*.
    ///
    /// # Errors
    /// [`Refusal::NotDarknet`] if *host* is neither a permitted `.onion` nor an
    /// admitted `.i2p`.
    pub fn parse(host: &str, allow_v2: bool, allow_i2p: bool) -> Result<Self, RefusedHost> {
        let h = normalize_host(host);
        if is_onion_host(&h, allow_v2) {
            return Ok(DarknetHost::Onion(OnionHost(h)));
        }
        if allow_i2p && is_i2p_host(&h) {
            return Ok(DarknetHost::I2p(I2pHost(h)));
        }
        Err(RefusedHost {
            host: host.to_string(),
            reason: Refusal::NotDarknet,
        })
    }

    /// The normalized host, regardless of network.
    #[must_use]
    pub fn as_str(&self) -> &str {
        match self {
            DarknetHost::Onion(o) => o.as_str(),
            DarknetHost::I2p(i) => i.as_str(),
        }
    }

    /// True for a Tor `.onion` host.
    #[must_use]
    pub fn is_onion(&self) -> bool {
        matches!(self, DarknetHost::Onion(_))
    }

    /// True for an I2P `.i2p` eepsite.
    #[must_use]
    pub fn is_i2p(&self) -> bool {
        matches!(self, DarknetHost::I2p(_))
    }
}

impl fmt::Display for DarknetHost {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(self.as_str())
    }
}

// --- in-text discovery scanner ---------------------------------------------

/// Scan arbitrary *text* for embedded `.onion` references and return candidate
/// URL strings (deduplicated, order-preserving, capped at *limit*).
///
/// Reproduces the Python `find_onion_urls` / `_ONION_IN_TEXT` regex exactly: an
/// optional `http(s)://` scheme, a 56- or 16-char base32 host that is **not**
/// preceded by another base32 char (so a host inside a longer base32 blob is not
/// mis-sliced and a bogus longer TLD like `.oniony` is not matched), an optional
/// `:port` (1–5 digits) and an optional path that runs until whitespace or one
/// of `" ' < > ) ] }`. Case-insensitive; hosts are lowercased in the output.
///
/// Each candidate still has to pass canonicalization + the abuse blocklist in
/// the caller; this is only the syntactic extraction. v2 hosts are dropped
/// unless *allow_v2*.
#[must_use]
pub fn find_onion_urls(
    text: &str,
    allow_v2: bool,
    limit: usize,
    default_scheme: &str,
) -> Vec<String> {
    if text.is_empty() || limit == 0 {
        return Vec::new();
    }
    let chars: Vec<char> = text.chars().collect();
    let n = chars.len();
    let default_scheme = default_scheme.to_lowercase();

    let mut out: Vec<String> = Vec::new();
    let mut seen: std::collections::HashSet<String> = std::collections::HashSet::new();
    let mut i = 0usize;

    while i < n {
        match scan_at(&chars, i, &default_scheme) {
            Some(m) => {
                // The regex matched here; advance past it whether or not we keep
                // the candidate (mirrors `finditer`'s non-overlapping scan).
                let next = m.end.max(i + 1);
                if is_onion_host(&m.host, allow_v2) {
                    let netloc = match &m.port {
                        Some(p) => format!("{}:{}", m.host, p),
                        None => m.host.clone(),
                    };
                    let url = format!("{}://{}{}", m.scheme, netloc, m.path);
                    if seen.insert(url.clone()) {
                        out.push(url);
                        if out.len() >= limit {
                            break;
                        }
                    }
                }
                i = next;
            }
            None => i += 1,
        }
    }
    out
}

/// One `_ONION_IN_TEXT` match anchored at a start position.
struct OnionMatch {
    scheme: String,
    host: String,
    port: Option<String>,
    path: String,
    end: usize,
}

/// `starts_with`, case-insensitive over ASCII, on a `char` slice.
fn starts_with_ci(chars: &[char], i: usize, pat: &str) -> bool {
    let plen = pat.chars().count();
    if i + plen > chars.len() {
        return false;
    }
    chars[i..i + plen]
        .iter()
        .zip(pat.chars())
        .all(|(c, p)| c.eq_ignore_ascii_case(&p))
}

/// `[a-z2-7]{len}` (case-insensitive) starting at `start`.
fn is_b32_run(chars: &[char], start: usize, len: usize) -> bool {
    start + len <= chars.len() && chars[start..start + len].iter().all(|&c| is_b32_ci(c))
}

/// `\.onion` (case-insensitive) at `at`.
fn dot_onion_at(chars: &[char], at: usize) -> bool {
    at + 6 <= chars.len()
        && chars[at] == '.'
        && chars[at + 1..at + 6]
            .iter()
            .zip(['o', 'n', 'i', 'o', 'n'])
            .all(|(c, p)| c.eq_ignore_ascii_case(&p))
}

/// A path stops before whitespace (`\s`) or any of `" ' < > ) ] }`.
fn is_path_stop(c: char) -> bool {
    c.is_whitespace() || matches!(c, '"' | '\'' | '<' | '>' | ')' | ']' | '}')
}

/// Try to match the `_ONION_IN_TEXT` pattern starting exactly at `i`.
fn scan_at(chars: &[char], i: usize, default_scheme: &str) -> Option<OnionMatch> {
    let n = chars.len();

    // Optional scheme. The scheme group is greedy, so `http(s)://` at `i` is
    // always consumed here; the host can never begin with `://`, so there is no
    // no-scheme alternative to backtrack to.
    let (scheme, host_start) = if starts_with_ci(chars, i, "https://") {
        ("https".to_string(), i + 8)
    } else if starts_with_ci(chars, i, "http://") {
        ("http".to_string(), i + 7)
    } else {
        (default_scheme.to_string(), i)
    };

    // Negative look-behind `(?<![a-z2-7])`: the host must not be preceded by a
    // base32 char. (When a scheme matched, the preceding char is `/`.)
    if host_start > 0 && is_b32_ci(chars[host_start - 1]) {
        return None;
    }

    // Host body: `[a-z2-7]{56}` then `[a-z2-7]{16}` (the regex alternation order).
    let body_len = if is_b32_run(chars, host_start, 56) && dot_onion_at(chars, host_start + 56) {
        56
    } else if is_b32_run(chars, host_start, 16) && dot_onion_at(chars, host_start + 16) {
        16
    } else {
        return None;
    };

    let host: String = chars[host_start..host_start + body_len]
        .iter()
        .map(|c| c.to_ascii_lowercase())
        .collect::<String>()
        + ".onion";
    let mut pos = host_start + body_len + 6; // past ".onion"

    // Optional `:port` — 1..=5 digits (greedy). A bare `:` with no digit leaves
    // the colon unconsumed (the port group simply does not match).
    let mut port: Option<String> = None;
    if pos < n && chars[pos] == ':' {
        let mut j = pos + 1;
        let mut digits = String::new();
        while j < n && digits.len() < 5 && chars[j].is_ascii_digit() {
            digits.push(chars[j]);
            j += 1;
        }
        if !digits.is_empty() {
            port = Some(digits);
            pos = j;
        }
    }

    // Optional path — `/` then a run up to a stop char. Absent path => "/".
    let mut path = String::from("/");
    if pos < n && chars[pos] == '/' {
        let mut p = String::from("/");
        let mut j = pos + 1;
        while j < n && !is_path_stop(chars[j]) {
            p.push(chars[j]);
            j += 1;
        }
        path = p;
        pos = j;
    }

    Some(OnionMatch {
        scheme,
        host,
        port,
        path,
        end: pos,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    const V3: &str = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"; // 56
    const V2: &str = "bbbbbbbbbbbbbbbb"; // 16

    #[test]
    fn onionhost_parse_gates_clearnet_and_v2() {
        // v3 accepted; clearnet, localhost, IP-literal, v2(off) all refused.
        assert!(OnionHost::parse(&format!("{V3}.onion"), false).is_ok());
        for bad in ["example.com", "localhost", "127.0.0.1", "stats.i2p", ""] {
            let e = OnionHost::parse(bad, false).unwrap_err();
            assert_eq!(e.reason, Refusal::NotOnion);
        }
        // v2 refused off, accepted on.
        assert!(OnionHost::parse(&format!("{V2}.onion"), false).is_err());
        let v2 = OnionHost::parse(&format!("{V2}.onion"), true).unwrap();
        assert_eq!(v2.version(), 2);
    }

    #[test]
    fn onionhost_normalizes_and_reports_version() {
        // uppercase + explicit port: normalized to lowercase, port stripped.
        let h = OnionHost::parse(&format!("{}.ONION:9050", V3.to_uppercase()), false).unwrap();
        assert_eq!(h.as_str(), format!("{V3}.onion"));
        assert_eq!(h.version(), 3);
        assert!(h.to_string().ends_with(".onion"));
    }

    #[test]
    fn i2p_and_darknet_newtypes() {
        assert!(I2pHost::parse("stats.i2p").is_ok());
        assert_eq!(I2pHost::parse("stats.i2p").unwrap().kind(), I2pKind::Name);
        assert!(I2pHost::parse(&format!("{V3}.onion")).is_err());

        // require_darknet: onion always; i2p only with the flag.
        assert!(DarknetHost::parse(&format!("{V3}.onion"), false, false)
            .unwrap()
            .is_onion());
        assert!(DarknetHost::parse("stats.i2p", false, false).is_err());
        assert!(DarknetHost::parse("stats.i2p", false, true)
            .unwrap()
            .is_i2p());
        assert_eq!(
            DarknetHost::parse("evil.com", false, true)
                .unwrap_err()
                .reason,
            Refusal::NotDarknet
        );
    }

    #[test]
    fn refusal_message_matches_python_shape() {
        let e = OnionHost::parse("evil.com", false).unwrap_err();
        assert_eq!(e.to_string(), "refusing non-onion host: 'evil.com'");
    }

    #[test]
    fn find_onion_urls_basic() {
        let text = format!("bare {V3}.onion here");
        assert_eq!(
            find_onion_urls(&text, false, 100, "http"),
            vec![format!("http://{V3}.onion/")]
        );
    }
}
