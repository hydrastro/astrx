//! URL canonicalization, dedup keys, and structural keys for trap detection.
//!
//! From any (possibly relative) URL this produces the stable **canonical URL**
//! (the dedup / frontier identity), a **template key** (host + path + sorted
//! query *keys*, values dropped — catches query-explosion / calendar bombs) and
//! a **skeleton key** (host + path with numeric/hex/date-ish segments collapsed
//! to `#` — a backstop for id-parameterized page farms). Only http/https darknet
//! URLs survive; by default that means `.onion` only (the crown invariant),
//! `allow_i2p` also admits `.i2p`. Clearnet never survives.
//!
//! Ported from the Python `canonical.py`; the `urllib`/`posixpath` behaviour it
//! leans on is reproduced in [`crate::urlparse`], and the whole pipeline is
//! cross-checked byte-identical in `tests/xcheck_canonical.rs`.

use crate::onion::{is_darknet_host, normalize_host};
use crate::urlparse::{
    host_port, normpath, parse_qsl, quote, unquote, urlencode, urljoin, urlsplit, urlunsplit,
};

/// Query params that never identify content — dropped entirely (case-insensitive
/// on the key). Mirrors the Python `TRACKING_PARAMS` frozenset.
const TRACKING_PARAMS: &[&str] = &[
    "utm_source",
    "utm_medium",
    "utm_campaign",
    "utm_term",
    "utm_content",
    "utm_id",
    "utm_reader",
    "utm_name",
    "utm_social",
    "utm_social-type",
    "gclid",
    "gclsrc",
    "dclid",
    "fbclid",
    "yclid",
    "msclkid",
    "mc_cid",
    "mc_eid",
    "igshid",
    "ref",
    "referrer",
    "referer",
    "source",
    "sessionid",
    "session_id",
    "sid",
    "phpsessid",
    "jsessionid",
    "aspsessionid",
    "cfid",
    "cftoken",
    "s",
    "spm",
    "scm",
    "share",
    "_ga",
    "_gl",
    "trk",
    "cmpid",
    "campaign",
];

/// The conservative path safe-set the Python `_normalize_path` re-quotes with.
const PATH_SAFE: &str = "/-._~!$&'()*+,;=:@";

fn is_tracking(key_lower: &str) -> bool {
    TRACKING_PARAMS.contains(&key_lower)
}

/// Percent-decode, collapse `//`, resolve `.`/`..`, then re-quote — preserving a
/// meaningful trailing slash. Mirrors `canonical._normalize_path`.
fn normalize_path(path: &str) -> String {
    if path.is_empty() {
        return "/".to_string();
    }
    let had_trailing = path.ends_with('/') && path != "/";
    let decoded = unquote(path);
    let mut norm = normpath(&decoded);
    if norm == "." {
        norm = "/".to_string();
    }
    if !norm.starts_with('/') {
        norm = format!("/{norm}");
    }
    if had_trailing && !norm.ends_with('/') {
        norm.push('/');
    }
    quote(&norm, PATH_SAFE)
}

/// Drop tracking params, sort, re-encode. Mirrors `canonical._clean_query`.
fn clean_query(query: &str) -> String {
    if query.is_empty() {
        return String::new();
    }
    let mut kept: Vec<(String, String)> = parse_qsl(query, true)
        .into_iter()
        .filter(|(k, _)| !is_tracking(&k.to_lowercase()))
        .collect();
    kept.sort();
    urlencode(&kept)
}

/// `^[0-9]+$`
fn numericish(s: &str) -> bool {
    !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit())
}

/// `^[0-9a-f]{8,}$` (applied to the lowercased segment)
fn hexish(s: &str) -> bool {
    s.len() >= 8 && s.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'))
}

/// `^\d{4}(-\d{1,2}(-\d{1,2})?)?$`
fn dateish(s: &str) -> bool {
    let b = s.as_bytes();
    if b.len() < 4 || !b[..4].iter().all(u8::is_ascii_digit) {
        return false;
    }
    if b.len() == 4 {
        return true;
    }
    let digits = |start: usize| -> usize {
        let mut n = 0;
        while n < 2 && start + n < b.len() && b[start + n].is_ascii_digit() {
            n += 1;
        }
        n
    };
    // -\d{1,2}
    let mut i = 4;
    if b.get(i) != Some(&b'-') {
        return false;
    }
    i += 1;
    let g1 = digits(i);
    if g1 == 0 {
        return false;
    }
    i += g1;
    if i == b.len() {
        return true;
    }
    // -\d{1,2}
    if b.get(i) != Some(&b'-') {
        return false;
    }
    i += 1;
    let g2 = digits(i);
    if g2 == 0 {
        return false;
    }
    i + g2 == b.len()
}

/// A canonicalized darknet URL plus the structural keys used by trap detection.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CanonicalUrl {
    /// The canonical URL string — the stable dedup / frontier identity.
    pub url: String,
    /// Lowercased scheme (`http` / `https`).
    pub scheme: String,
    /// Normalized host (the dedup key; see [`normalize_host`]).
    pub host: String,
    /// Explicit non-default port, if any.
    pub port: Option<u16>,
    /// Normalized, re-quoted path.
    pub path: String,
    /// Cleaned, sorted query string (may be empty).
    pub query: String,
}

impl CanonicalUrl {
    /// Sorted, unique query keys (values dropped).
    #[must_use]
    pub fn query_keys(&self) -> Vec<String> {
        if self.query.is_empty() {
            return Vec::new();
        }
        let mut set = std::collections::BTreeSet::new();
        for (k, _) in parse_qsl(&self.query, true) {
            set.insert(k);
        }
        set.into_iter().collect()
    }

    /// `host + path + sorted query KEYS` — collapses `/cal?year=…&month=…` farms
    /// to one template so enqueueing can be capped.
    #[must_use]
    pub fn template_key(&self) -> String {
        let qk = self.query_keys().join(",");
        if qk.is_empty() {
            format!("{}{}", self.host, self.path)
        } else {
            format!("{}{}?{}", self.host, self.path, qk)
        }
    }

    /// `host + path with numeric/hex/date-ish segments → '#'` (+ query keys) — a
    /// global backstop for id-parameterized page farms (`/post/12345`).
    #[must_use]
    pub fn skeleton_key(&self) -> String {
        let mut segs: Vec<String> = Vec::new();
        for seg in self.path.split('/') {
            if seg.is_empty() {
                segs.push(String::new());
                continue;
            }
            let low = seg.to_lowercase();
            if numericish(&low) || hexish(&low) || dateish(&low) {
                segs.push("#".to_string());
            } else {
                segs.push(low);
            }
        }
        let sk = segs.join("/");
        let qk = self.query_keys().join(",");
        if qk.is_empty() {
            format!("{}{}", self.host, sk)
        } else {
            format!("{}{}?{}", self.host, sk, qk)
        }
    }
}

/// Return a [`CanonicalUrl`], or `None` if the URL is not a usable darknet URL.
///
/// *base* is the page the link was found on (for resolving relative URLs);
/// *allow_i2p* additionally admits `.i2p` hosts (default: `.onion` only).
///
/// Divergence from Python (deliberate, not corpus-reachable): an unparseable or
/// out-of-range `:port` returns `None` here, where CPython's `SplitResult.port`
/// would raise — canonicalization stays total and fail-safe.
#[must_use]
pub fn canonicalize(
    url: &str,
    base: Option<&str>,
    allow_v2: bool,
    allow_i2p: bool,
) -> Option<CanonicalUrl> {
    let joined;
    let url = match base {
        Some(b) if !b.is_empty() => {
            joined = urljoin(b, url);
            joined.as_str()
        }
        _ => url,
    };

    let sp = urlsplit(url, "");
    let scheme = sp.scheme.to_lowercase();
    if scheme != "http" && scheme != "https" {
        return None;
    }

    let (hostname, port_str) = host_port(&sp.netloc);
    let host = normalize_host(&hostname);
    if !is_darknet_host(&host, allow_v2, allow_i2p) {
        return None;
    }

    let port: Option<u16> = match port_str {
        None => None,
        Some(ps) => {
            let n: i64 = ps.trim().parse().ok()?;
            if !(0..=65535).contains(&n) {
                return None;
            }
            let default = if scheme == "http" { 80 } else { 443 };
            let n = n as u16;
            if n == default {
                None
            } else {
                Some(n)
            }
        }
    };

    let netloc = match port {
        None => host.clone(),
        Some(p) => format!("{host}:{p}"),
    };
    let path = normalize_path(&sp.path);
    let query = clean_query(&sp.query);
    let url_out = urlunsplit(&scheme, &netloc, &path, &query, "");

    Some(CanonicalUrl {
        url: url_out,
        scheme,
        host,
        port,
        path,
        query,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_clearnet_and_bad_scheme() {
        assert!(canonicalize("http://example.com/", None, false, false).is_none());
        let onion = format!("http://{}.onion/", "a".repeat(56));
        assert!(canonicalize(
            &format!("ftp://{}.onion/", "a".repeat(56)),
            None,
            false,
            false
        )
        .is_none());
        assert!(canonicalize(&onion, None, false, false).is_some());
    }

    #[test]
    fn drops_default_port_and_fragment() {
        let base = format!("http://{}.onion:80/x#frag", "a".repeat(56));
        let c = canonicalize(&base, None, false, false).unwrap();
        assert_eq!(c.port, None);
        assert!(!c.url.contains('#'));
        assert!(!c.url.contains(":80"));
    }

    #[test]
    fn skeleton_collapses_ids() {
        let u = format!("http://{}.onion/post/12345/2020-01-02", "a".repeat(56));
        let c = canonicalize(&u, None, false, false).unwrap();
        assert!(c.skeleton_key().ends_with("/post/#/#"));
    }
}
