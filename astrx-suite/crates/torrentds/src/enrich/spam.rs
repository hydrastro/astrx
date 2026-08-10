//! Fake / spam-torrent heuristics (port of `legacy-python/torrentds/spam.py`).
//!
//! A cheap, deterministic scoring pass over *already-verified* metadata that flags
//! likely fakes and spam. It never touches the network and never trusts a length
//! or a name — it only inspects the parsed file layout + display name, so it is
//! trivially unit-testable with synthetic good/bad torrents. Pure, dependency-free.
//!
//! Signals (each contributes a weight; the total is the `spam_score`):
//! * **exe-in-media** an executable inside a video/audio/image/document torrent.
//! * **decoy layout** one dominant huge file plus several tiny padding decoys.
//! * **piece mismatch** `piece_count` grossly inconsistent with the size.
//! * **spam name** URLs, domain tags, or promo phrases stuffed into the name.
//!
//! The name heuristics reproduce the Python `re` patterns by hand (no regex dep):
//! a case-insensitive URL/`www.` probe, a `\b<label>.<tld>\b` domain probe, and a
//! promo-phrase alternation with flexible whitespace.

/// Default flag threshold: a single strong signal (or two weak ones) trips it.
pub const DEFAULT_THRESHOLD: f64 = 3.0;

/// Executables that have no business inside a media torrent.
const EXE_EXTS: &[&str] = &[
    "exe", "scr", "bat", "cmd", "com", "msi", "pif", "vbs", "js", "jar", "ps1", "hta",
];
/// Tiny files typically used as decoys / advertising in a fake release.
const DECOY_EXTS: &[&str] = &[
    "txt", "url", "lnk", "nfo", "htm", "html", "website", "torrent", "md", "diz",
];
/// Media categories in which an executable is highly suspicious.
const MEDIA_CATEGORIES: &[&str] = &["video", "audio", "image", "document"];

/// Known TLDs for the domain-in-name probe (mirrors the Python alternation).
const DOMAIN_TLDS: &[&str] = &[
    "com", "net", "org", "info", "xyz", "to", "cc", "ru", "biz", "site", "top", "club", "online",
    "download",
];

const TINY_FILE_BYTES: u64 = 512 * 1024; // a "tiny" decoy is < 512 KiB
const DECOY_MIN_TOTAL: u64 = 50 * 1024 * 1024; // only in a torrent claiming > 50 MiB
const DOMINANT_FRACTION: f64 = 0.85; // one file is >= 85% of the total size
const DECOY_MIN_COUNT: usize = 3; // need at least this many decoys

/// Operator-tunable weights + threshold (mirrors Python's `SpamConfig`).
#[derive(Debug, Clone)]
pub struct SpamConfig {
    pub threshold: f64,
    pub exe_in_media: f64,
    pub decoy_layout: f64,
    pub piece_mismatch: f64,
    pub url: f64,
    pub domain: f64,
    pub promo: f64,
    /// Mismatch trips when `piece_count` differs from expected by more than this
    /// multiplicative factor (guards against tiny-file / padding-file noise).
    pub mismatch_factor: f64,
}

impl Default for SpamConfig {
    fn default() -> Self {
        Self {
            threshold: DEFAULT_THRESHOLD,
            exe_in_media: 4.0,
            decoy_layout: 3.0,
            piece_mismatch: 3.0,
            url: 2.0,
            domain: 2.0,
            promo: 2.0,
            mismatch_factor: 3.0,
        }
    }
}

fn ext(path: &str) -> String {
    let base = path.rsplit('/').next().unwrap_or(path);
    match base.rsplit_once('.') {
        Some((_, e)) => e.to_ascii_lowercase(),
        None => String::new(),
    }
}

fn is_word(c: u8) -> bool {
    c.is_ascii_alphanumeric() || c == b'_'
}

/// `(?:https?://|www\.)`, case-insensitive, anywhere in the name.
fn url_in_name(lname: &str) -> bool {
    lname.contains("http://") || lname.contains("https://") || lname.contains("www.")
}

/// `\b[a-z0-9][a-z0-9-]{1,}\.(?:<tld>)\b`, case-insensitive.
fn domain_in_name(lname: &str) -> bool {
    let b = lname.as_bytes();
    for (dot, &c) in b.iter().enumerate() {
        if c != b'.' {
            continue;
        }
        for tld in DOMAIN_TLDS {
            let t = tld.as_bytes();
            let end = dot + 1 + t.len();
            if end <= b.len() && &b[dot + 1..end] == t {
                // word boundary after the TLD
                let boundary_after = end == b.len() || !is_word(b[end]);
                if boundary_after && label_before(b, dot) {
                    return true;
                }
            }
        }
    }
    false
}

/// A valid `\b[a-z0-9][a-z0-9-]{1,}` label ends immediately before `dot`?
fn label_before(b: &[u8], dot: usize) -> bool {
    if dot == 0 {
        return false;
    }
    let is_label = |c: u8| c.is_ascii_alphanumeric() || c == b'-';
    // The last label char (at dot-1) must be part of a [a-z0-9-] run.
    if !is_label(b[dot - 1]) {
        return false;
    }
    // Scan back over the [a-z0-9-] run.
    let mut start = dot - 1;
    while start > 0 && is_label(b[start - 1]) {
        start -= 1;
    }
    // Need some p in [start, dot-2] with an alnum first char and a \b before it.
    for p in start..dot.saturating_sub(1) {
        if b[p].is_ascii_alphanumeric() && (p == 0 || !is_word(b[p - 1])) {
            return true;
        }
    }
    false
}

/// `a` then (zero-or-more, or one-or-more if `plus`) whitespace then `b`, anywhere.
fn seq_ws(lname: &str, a: &str, b: &str, plus: bool) -> bool {
    let bytes = lname.as_bytes();
    let a = a.as_bytes();
    let b_ = b.as_bytes();
    let mut i = 0;
    while i + a.len() <= bytes.len() {
        if &bytes[i..i + a.len()] == a {
            let mut j = i + a.len();
            let mut gaps = 0;
            while j < bytes.len() && (bytes[j] as char).is_whitespace() {
                j += 1;
                gaps += 1;
            }
            if (!plus || gaps >= 1) && j + b_.len() <= bytes.len() && &bytes[j..j + b_.len()] == b_
            {
                return true;
            }
        }
        i += 1;
    }
    false
}

/// `xxx\b`: an "xxx" run whose following char is a word boundary.
fn xxx_boundary(lname: &str) -> bool {
    let b = lname.as_bytes();
    let mut i = 0;
    while i + 3 <= b.len() {
        if &b[i..i + 3] == b"xxx" && (i + 3 == b.len() || !is_word(b[i + 3])) {
            return true;
        }
        i += 1;
    }
    false
}

/// The promo-phrase alternation (case-insensitive), on an already-lowercased name.
fn promo_in_name(lname: &str) -> bool {
    seq_ws(lname, "free", "download", false)
        || seq_ws(lname, "watch", "online", false)
        || seq_ws(lname, "full", "movie", false)
        || seq_ws(lname, "download", "free", false)
        || lname.contains("keygen")
        || lname.contains("crack") // crack(?:ed)? — both contain "crack"
        || seq_ws(lname, "serial", "key", false)
        || seq_ws(lname, "activation", "key", false)
        || xxx_boundary(lname)
        || seq_ws(lname, "visit", "us", true)
        || seq_ws(lname, "new", "rip", false)
}

/// Return `(spam_score, reasons)` for one torrent. Higher == spammier.
#[must_use]
pub fn score(
    name: &str,
    files: &[(String, u64)],
    total_size: u64,
    piece_length: u64,
    piece_count: usize,
    category: &str,
    config: &SpamConfig,
) -> (f64, Vec<String>) {
    let mut reasons: Vec<String> = Vec::new();
    let mut total = 0.0;
    let exts: Vec<String> = files.iter().map(|(p, _)| ext(p)).collect();

    // -- exe-in-media --
    if MEDIA_CATEGORIES.contains(&category) && exts.iter().any(|e| EXE_EXTS.contains(&e.as_str())) {
        total += config.exe_in_media;
        reasons.push(format!("executable in {category} torrent"));
    }

    // -- decoy layout --
    if files.len() >= 2 && total_size >= DECOY_MIN_TOTAL {
        let biggest = files.iter().map(|(_, l)| *l).max().unwrap_or(0);
        let decoys = files
            .iter()
            .zip(exts.iter())
            .filter(|((_, l), e)| *l < TINY_FILE_BYTES && DECOY_EXTS.contains(&e.as_str()))
            .count();
        if biggest as f64 >= DOMINANT_FRACTION * total_size as f64 && decoys >= DECOY_MIN_COUNT {
            total += config.decoy_layout;
            reasons.push(format!("one huge file + {decoys} tiny decoy(s)"));
        }
    }

    // -- size vs piece mismatch --
    if piece_length > 0 && piece_count > 0 && total_size > 0 {
        let expected = total_size.div_ceil(piece_length);
        // abs_diff over u64 (both operands non-negative): a hostile `total_size`
        // near u64::MAX with `piece_length == 1` makes `expected` exceed i64::MAX,
        // where the old `as i64` subtraction overflow-panicked (debug) / wrapped to
        // a false-negative (release). The info-dict is attacker-crafted.
        if expected >= 1 && (piece_count as u64).abs_diff(expected) > 2 {
            let ratio = piece_count as f64 / expected as f64;
            if ratio > config.mismatch_factor || ratio < 1.0 / config.mismatch_factor {
                total += config.piece_mismatch;
                reasons.push(format!("piece_count {piece_count} vs expected ~{expected}"));
            }
        }
    }

    // -- name spam --
    let lname = name.to_lowercase();
    if url_in_name(&lname) {
        total += config.url;
        reasons.push("url in name".to_string());
    }
    if domain_in_name(&lname) {
        total += config.domain;
        reasons.push("domain tag in name".to_string());
    }
    if promo_in_name(&lname) {
        total += config.promo;
        reasons.push("promotional keyword in name".to_string());
    }

    (total, reasons)
}

/// Convenience: is this torrent's score at or above the flag threshold?
#[must_use]
pub fn is_spam(
    name: &str,
    files: &[(String, u64)],
    total_size: u64,
    piece_length: u64,
    piece_count: usize,
    category: &str,
    config: &SpamConfig,
) -> bool {
    let (s, _) = score(
        name,
        files,
        total_size,
        piece_length,
        piece_count,
        category,
        config,
    );
    s >= config.threshold
}

#[cfg(test)]
mod tests {
    use super::*;

    fn f(p: &str, l: u64) -> (String, u64) {
        (p.to_string(), l)
    }

    #[test]
    fn clean_torrent_scores_zero() {
        let files = vec![f("movie/movie.mkv", 1_400_000_000)];
        let (s, r) = score(
            "Some.Movie.2019.1080p.BluRay.x264",
            &files,
            1_400_000_000,
            262_144,
            5340,
            "video",
            &SpamConfig::default(),
        );
        assert_eq!(s, 0.0);
        assert!(r.is_empty());
    }

    #[test]
    fn exe_in_media_flags() {
        let files = vec![f("movie.mkv", 700_000_000), f("setup.exe", 5_000_000)];
        let (s, _) = score(
            "Movie",
            &files,
            705_000_000,
            262_144,
            2689,
            "video",
            &SpamConfig::default(),
        );
        assert_eq!(s, 4.0);
        // not media => no exe flag
        let (s2, _) = score(
            "App",
            &files,
            705_000_000,
            262_144,
            2689,
            "software",
            &SpamConfig::default(),
        );
        assert_eq!(s2, 0.0);
    }

    #[test]
    fn decoy_layout_flags() {
        let files = vec![
            f("movie.mkv", 900_000_000),
            f("readme.txt", 1000),
            f("visit.url", 200),
            f("info.nfo", 500),
        ];
        let (s, _) = score(
            "Movie",
            &files,
            900_002_700,
            262_144,
            3433,
            "video",
            &SpamConfig::default(),
        );
        assert_eq!(s, 3.0);
    }

    #[test]
    fn name_spam_matches() {
        let cfg = SpamConfig::default();
        let hit = |n: &str| score(n, &[], 0, 0, 0, "other", &cfg).0;
        assert_eq!(hit("Movie www.piratesite.com FREE"), 4.0); // url + domain
        assert_eq!(hit("Album [visit us]"), 2.0); // promo
        assert_eq!(hit("Game keygen crack"), 2.0); // promo (single alternation hit)
        assert_eq!(hit("Clip xxx"), 2.0); // xxx\b
        assert_eq!(hit("Clean.Release.2020"), 0.0);
    }

    #[test]
    fn domain_boundary_semantics() {
        let dom = |n: &str| domain_in_name(&n.to_lowercase());
        assert!(dom("get it at example.com now"));
        assert!(dom("site.to")); // 2-char tld with valid label
        assert!(!dom(".com")); // no label before the dot
        assert!(!dom("a.comic")); // "com" not at a word boundary (comic)
        assert!(dom("my-tracker.org"));
    }

    /// Regression: a hostile info-dict with a colossal `total_size` and
    /// `piece_length == 1` drives `expected` past `i64::MAX`. The old
    /// `(piece_count as i64 - expected as i64)` subtraction overflow-panicked in
    /// debug and wrapped to a false-negative in release; the `abs_diff` over
    /// `u64` must neither panic nor miss the mismatch. Tests run in debug, so a
    /// regression here surfaces as a panic (test failure), not a silent pass.
    #[test]
    fn piece_mismatch_hostile_size_no_overflow() {
        let cfg = SpamConfig::default();
        // `expected == u64::MAX`; wildly inconsistent with a single piece.
        let (s, r) = score("x", &[], u64::MAX, 1, 1, "other", &cfg);
        assert_eq!(s, cfg.piece_mismatch);
        assert!(r.iter().any(|m| m.contains("piece_count")));

        // `expected == 2^63`, the exact value whose `as i64` cast lands on
        // `i64::MIN` and made `piece_count - expected` overflow in debug.
        let (s2, r2) = score("x", &[], 1u64 << 63, 1, 1, "other", &cfg);
        assert_eq!(s2, cfg.piece_mismatch);
        assert!(r2.iter().any(|m| m.contains("piece_count")));

        // Consistent counts (expected ~ piece_count) must still NOT flag, even
        // at extreme magnitudes: 2^40 pieces of length 2 covering 2^41 bytes.
        let big = 1u64 << 41;
        let (s3, _) = score("x", &[], big, 2, (big / 2) as usize, "other", &cfg);
        assert_eq!(s3, 0.0);
    }
}
