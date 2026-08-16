//! Operator-configurable abuse filtering (REQUIRED, not optional).
//!
//! Three lists, shipped empty for the operator to fill: a **host** blocklist
//! (onion addresses never to index), a **keyword** blocklist (if any keyword
//! appears in a page's title/text the page is dropped), and a **media** blocklist
//! (one hex SHA-256 per line; a downloaded media resource whose bytes hash to a
//! listed digest drops the page — the Ahmia-grade media-hash path). A fourth,
//! **host_md5s**, holds Ahmia-format `md5(onion_domain)` digests so an operator
//! can subscribe to a published banned-domain hash list without ever holding the
//! plaintext onion addresses.
//!
//! Operators of any legitimate onion search index MUST configure this to exclude
//! abusive material, in particular CSAM. `abuse.page_blocked` is on the hot path:
//! it is checked on every page before it can be stored, and a hit means the page
//! is never indexed.
//!
//! Ported from the Python `abuse.py`; cross-checked byte-identical in
//! `tests/xcheck_abuse.rs`. Hashing comes from the shared [`crawlcore::hash`].

use crate::onion::normalize_host;
use crawlcore::hash::{md5, sha256, to_hex};
use std::collections::HashSet;

/// An operator-configured abuse filter over host / keyword / media / host-md5
/// blocklists.
#[derive(Debug, Clone, Default)]
pub struct AbuseFilter {
    hosts: HashSet<String>,     // normalized onion hosts
    keywords: Vec<String>,      // lowercased, in configured order
    media: HashSet<String>,     // lowercase hex sha256 digests
    host_md5s: HashSet<String>, // lowercase hex md5(onion_domain) digests
}

/// `strip().lower()` — the normalized form of a hex digest line.
fn norm_hash(h: &str) -> String {
    h.trim().to_lowercase()
}

/// The keyword-boundary class: `[0-9a-z]` under `re.IGNORECASE` — i.e. ASCII
/// alphanumeric. (Note: unlike `\b`, `_` is NOT a boundary char here, matching
/// the Python `(?<![0-9a-z])…(?![0-9a-z])` pattern.)
fn is_boundary_word(b: u8) -> bool {
    b.is_ascii_alphanumeric()
}

/// Whether *kw* (already lowercased) occurs in *hay* bounded by non-alphanumeric
/// chars on both sides, case-insensitively — the Python `_compile_kw` regex.
fn keyword_matches(kw: &str, hay_lower: &str) -> bool {
    if kw.is_empty() {
        return false;
    }
    let hb = hay_lower.as_bytes();
    let klen = kw.len();
    let mut start = 0;
    while let Some(rel) = hay_lower[start..].find(kw) {
        let i = start + rel;
        let end = i + klen;
        let before_ok = i == 0 || !is_boundary_word(hb[i - 1]);
        let after_ok = end >= hb.len() || !is_boundary_word(hb[end]);
        if before_ok && after_ok {
            return true;
        }
        // Advance one CHARACTER, not one byte: `i + 1` lands inside a multi-byte
        // code point whenever the keyword starts with a non-ASCII char (the
        // shipped lists are not ASCII-only), and re-slicing there panics —
        // taking the crawl worker, and with one worker the whole crawl's
        // unsaved work, down. Overlapping starts are still allowed.
        start = i + hay_lower[i..].chars().next().map_or(1, char::len_utf8);
    }
    false
}

impl AbuseFilter {
    /// Build a filter from the four raw lists (blank/whitespace entries dropped).
    #[must_use]
    pub fn new(
        hosts: &[String],
        keywords: &[String],
        media_hashes: &[String],
        host_md5s: &[String],
    ) -> Self {
        AbuseFilter {
            hosts: hosts
                .iter()
                .filter(|h| !h.trim().is_empty())
                .map(|h| normalize_host(h))
                .collect(),
            keywords: keywords
                .iter()
                .filter(|k| !k.trim().is_empty())
                .map(|k| k.to_lowercase())
                .collect(),
            media: media_hashes
                .iter()
                .filter(|h| !h.trim().is_empty())
                .map(|h| norm_hash(h))
                .collect(),
            host_md5s: host_md5s
                .iter()
                .filter(|h| !h.trim().is_empty())
                .map(|h| norm_hash(h))
                .collect(),
        }
    }

    // -- host ---------------------------------------------------------------

    /// True iff *host* is on the explicit host blocklist or its `md5(domain)` is
    /// on the Ahmia-format `host_md5s` list.
    #[must_use]
    pub fn host_blocked(&self, host: &str) -> bool {
        let h = normalize_host(host);
        if self.hosts.contains(&h) {
            return true;
        }
        !self.host_md5s.is_empty() && self.host_md5s.contains(&Self::host_md5(&h))
    }

    /// Ahmia's ban key: `md5` hex of the normalized onion host (an interop
    /// format, not a security hash).
    #[must_use]
    pub fn host_md5(host: &str) -> String {
        to_hex(&md5(normalize_host(host).as_bytes()))
    }

    /// Our explicit host blocklist, republished in Ahmia's `md5(domain)` format
    /// (sorted) so others can subscribe to it.
    #[must_use]
    pub fn banned_host_md5s(&self) -> Vec<String> {
        let mut v: Vec<String> = self.hosts.iter().map(|h| Self::host_md5(h)).collect();
        v.sort();
        v
    }

    // -- content ------------------------------------------------------------

    /// Return the first configured keyword that appears in any of *texts*, or
    /// `None`.
    #[must_use]
    pub fn content_hit(&self, texts: &[&str]) -> Option<String> {
        let hay: String = texts
            .iter()
            .filter(|t| !t.is_empty())
            .copied()
            .collect::<Vec<_>>()
            .join("\n");
        if hay.is_empty() {
            return None;
        }
        let hay_lower = hay.to_lowercase();
        self.keywords
            .iter()
            .find(|kw| keyword_matches(kw, &hay_lower))
            .cloned()
    }

    /// Return a reason string if the page must be dropped, else `None`.
    #[must_use]
    pub fn page_blocked(&self, host: &str, title: &str, text: &str) -> Option<String> {
        if self.host_blocked(host) {
            return Some(format!("blocked-host:{}", normalize_host(host)));
        }
        self.content_hit(&[title, text])
            .map(|kw| format!("blocked-keyword:{kw}"))
    }

    // -- media --------------------------------------------------------------

    /// Whether any media blocklist is configured.
    #[must_use]
    pub fn has_media_blocklist(&self) -> bool {
        !self.media.is_empty()
    }

    /// SHA-256 hex digest of raw media bytes (the media blocklist key).
    #[must_use]
    pub fn hash_media(data: &[u8]) -> String {
        to_hex(&sha256(data))
    }

    /// True iff *hash_hex* (a hex sha256) is on the media blocklist.
    #[must_use]
    pub fn media_blocked(&self, hash_hex: &str) -> bool {
        if hash_hex.is_empty() || self.media.is_empty() {
            return false;
        }
        self.media.contains(&norm_hash(hash_hex))
    }

    /// Hash *data* and return the offending hex digest if blocklisted, else
    /// `None`. A no-op (returns `None`) when no media blocklist is configured.
    #[must_use]
    pub fn media_bytes_blocked(&self, data: &[u8]) -> Option<String> {
        if self.media.is_empty() || data.is_empty() {
            return None;
        }
        let h = Self::hash_media(data);
        self.media.contains(&h).then_some(h)
    }

    /// The configured host blocklist entries (normalized), sorted. Symmetric
    /// with [`keywords`](Self::keywords) / [`media_hashes`](Self::media_hashes),
    /// so an operator front-end can tell an *empty* filter from a configured one
    /// (the CLI refuses to crawl silently with no abuse filtering).
    #[must_use]
    pub fn hosts(&self) -> Vec<String> {
        let mut v: Vec<String> = self.hosts.iter().cloned().collect();
        v.sort();
        v
    }

    /// The configured keywords (lowercased, in order).
    #[must_use]
    pub fn keywords(&self) -> &[String] {
        &self.keywords
    }

    /// The media blocklist digests, sorted.
    #[must_use]
    pub fn media_hashes(&self) -> Vec<String> {
        let mut v: Vec<String> = self.media.iter().cloned().collect();
        v.sort();
        v
    }
}

/// Read a blocklist file: one entry per line, `#` starts a comment, blank lines
/// dropped. Returns an empty list if *path* is missing or unreadable.
#[must_use]
pub fn read_list_file(path: &str) -> Vec<String> {
    let Ok(contents) = std::fs::read_to_string(path) else {
        return Vec::new();
    };
    contents
        .lines()
        .filter_map(|line| {
            let line = line.split('#').next().unwrap_or("").trim();
            (!line.is_empty()).then(|| line.to_string())
        })
        .collect()
}

/// Load an [`AbuseFilter`] from the four optional list-file paths.
#[must_use]
pub fn load_abuse_filter(
    hosts_path: Option<&str>,
    keywords_path: Option<&str>,
    media_path: Option<&str>,
    host_md5_path: Option<&str>,
) -> AbuseFilter {
    let read = |p: Option<&str>| p.map(read_list_file).unwrap_or_default();
    AbuseFilter::new(
        &read(hosts_path),
        &read(keywords_path),
        &read(media_path),
        &read(host_md5_path),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    fn onion(c: char) -> String {
        format!("{}.onion", c.to_string().repeat(56))
    }

    #[test]
    fn keyword_boundaries() {
        let f = AbuseFilter::new(&[], &["scam".into()], &[], &[]);
        assert!(f.content_hit(&["this is a SCAM!"]).is_some()); // case-insensitive, punctuation boundary
        assert!(f.content_hit(&["x_scam_y"]).is_some()); // '_' is a boundary here
        assert!(f.content_hit(&["scamper ahead"]).is_none()); // 'scamper' not a hit
        assert!(f.content_hit(&["descamisado"]).is_none());
    }

    #[test]
    fn host_and_media() {
        let a = onion('a');
        let f = AbuseFilter::new(std::slice::from_ref(&a), &[], &["ABC123".into()], &[]);
        assert!(f.host_blocked(&format!("{a}:9050"))); // normalized (port stripped)
        assert!(!f.host_blocked(&onion('b')));
        assert!(f.media_blocked("abc123")); // normalized lowercase
        assert!(!f.media_blocked(""));
        assert_eq!(f.media_bytes_blocked(b""), None); // empty data
    }
}

#[cfg(test)]
mod audit_regression {
    use super::*;

    /// `start = i + 1` lands inside a multi-byte code point whenever the keyword
    /// starts with a non-ASCII character, and re-slicing there PANICS — taking
    /// the crawl worker down, and with a single worker the whole crawl's unsaved
    /// work with it. The shipped blocklists are not ASCII-only.
    #[test]
    fn a_non_ascii_keyword_never_panics_the_scan() {
        let f = AbuseFilter::new(
            &[],
            &[
                "\u{43f}\u{440}\u{438}\u{432}\u{435}\u{442}".to_string(), // Cyrillic
                "\u{4f60}\u{597d}".to_string(),                           // Han
                "caf\u{e9}".to_string(),                                  // Latin-1 tail
                "\u{1f600}".to_string(),                                  // astral
            ],
            &[],
            &[],
        );
        // The advance only runs when the keyword IS found but the word-boundary
        // check rejects that occurrence — so each haystack puts an ASCII
        // alphanumeric immediately before a real match. `start = i + 1` then
        // lands inside the keyword's leading multi-byte character.
        for hay in [
            "x\u{43f}\u{440}\u{438}\u{432}\u{435}\u{442}",
            "7\u{4f60}\u{597d}",
            "z\u{1f600}",
            "a\u{43f}\u{440}\u{438}\u{432}\u{435}\u{442} \u{43f}\u{440}\u{438}\u{432}\u{435}\u{442}",
        ] {
            let _ = f.content_hit(&[hay]); // must not panic
        }
        // …and the filter still actually matches.
        assert!(f
            .content_hit(&["a \u{43f}\u{440}\u{438}\u{432}\u{435}\u{442} b"])
            .is_some());
        assert!(f.content_hit(&["nothing here"]).is_none());
    }
}
