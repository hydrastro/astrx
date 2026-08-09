//! Heuristic release classifier: turn a torrent name (and file list) into
//! structured attribute facets — resolution, source, video/audio codec, HDR,
//! year, TV season/episode, edition, release group and language — plus a coarse
//! media *kind* (movie / tv / music / book / software / game).
//!
//! This is bitmagnet-class enrichment in pure Rust: **no regex crate**. The
//! Python reference leans on `re`; here we normalise the name once (separators
//! and bracket noise → single spaces, folded to lower case) and then do bounded,
//! word-aligned literal scans over that normalised string. Because the reference
//! patterns are all `\b`-anchored alternations of literals (plus a few tiny
//! numeric grammars: `SxxEyy`, a year, a season, a trailing `-GROUP`), literal
//! token scanning reproduces them exactly — and `tests/xcheck_classify.rs` pins
//! that against the Python output over a corpus of real release names.
//!
//! It never panics; unknown facets are simply absent. The output is small and
//! stable so it can be stored as a compact tag string and faceted on cheaply.

/// Facet keys in stable display order (mirrors the Python `FACET_KEYS`).
pub const FACET_KEYS: [&str; 12] = [
    "kind",
    "year",
    "season",
    "episode",
    "resolution",
    "source",
    "vcodec",
    "acodec",
    "hdr",
    "edition",
    "group",
    "lang",
];

/// Names beyond this many characters are truncated before matching (linearity).
const MAX_NAME: usize = 4096;

/// The separator/bracket-noise characters folded to spaces during normalisation.
const SEP: &[char] = &['.', '_', '-', '[', ']', '(', ')', '{', '}', '+'];

// Ordered (value, alternatives) tables. First entry with any matching
// alternative wins — exactly the Python list-of-`(regex, value)` + `_first`.
// Alternatives are the literal branches of each `\b(?:…)\b`; a branch that can
// never survive normalisation (e.g. `dd+`, whose `+` becomes a space) is kept
// verbatim and simply never matches.
type Table = &'static [(&'static str, &'static [&'static str])];

const RES: Table = &[
    ("2160p", &["4k", "2160p", "uhd"]),
    ("1440p", &["1440p"]),
    ("1080p", &["1080p", "1080i"]),
    ("720p", &["720p", "720i"]),
    ("576p", &["576p", "576i"]),
    ("480p", &["480p", "480i"]),
];

const SOURCE: Table = &[
    ("remux", &["remux"]),
    (
        "bluray",
        &[
            "bluray", "blu ray", "bdrip", "brrip", "bd25", "bd50", "bdremux",
        ],
    ),
    ("web-dl", &["web dl", "webdl"]),
    ("webrip", &["webrip"]),
    ("web", &["web"]),
    ("hdtv", &["hdtv"]),
    ("pdtv", &["pdtv"]),
    ("dvd", &["dvdrip", "dvd5", "dvd9", "dvdr", "dvd"]),
    ("hdrip", &["hdrip"]),
    ("cam", &["cam", "camrip", "hdcam"]),
    ("telesync", &["ts", "telesync", "hdts"]),
];

const VCODEC: Table = &[
    ("x265", &["x265", "h265", "h 265", "hevc"]),
    ("x264", &["x264", "h264", "h 264", "avc"]),
    ("av1", &["av1"]),
    ("xvid", &["xvid", "divx"]),
    ("mpeg2", &["mpeg2"]),
];

const ACODEC: Table = &[
    ("truehd", &["truehd", "true hd"]),
    ("dts-hd", &["dts hd", "dtshd", "dts x", "dtsx", "dts hd ma"]),
    ("dts", &["dts"]),
    ("eac3", &["eac3", "e ac3", "ddp", "dd+"]),
    ("ac3", &["ac3", "dd5 1", "dd"]),
    ("aac", &["aac"]),
    ("flac", &["flac"]),
    ("opus", &["opus"]),
    ("mp3", &["mp3"]),
    ("atmos", &["atmos"]),
];

const HDR: Table = &[
    ("dolby-vision", &["dolby vision", "dovi", "dv"]),
    ("hdr10+", &["hdr10+", "hdr10plus"]),
    ("hdr10", &["hdr10"]),
    ("hdr", &["hdr"]),
];

const EDITION: Table = &[
    ("extended", &["extended", "extended cut"]),
    ("remastered", &["remaster", "remastered"]),
    ("directors-cut", &["director's cut", "directors cut"]),
    ("unrated", &["unrated"]),
    ("imax", &["imax"]),
    ("proper", &["proper"]),
    ("repack", &["repack"]),
];

const LANG: Table = &[
    ("multi", &["multi"]),
    ("dual", &["dual"]),
    ("it", &["ita", "italian"]),
    ("fr", &["fre", "french", "vostfr", "truefrench"]),
    ("de", &["ger", "german"]),
    ("es", &["spa", "spanish", "castellano"]),
    ("ru", &["rus", "russian"]),
    ("ja", &["jap", "japanese", "jpn"]),
];

const GAME_HINTS: &[&str] = &[
    "repack",
    "fitgirl",
    "dodi",
    "codex",
    "plaza",
    "skidrow",
    "goty",
    "razor1911",
    "flt",
    "reloaded",
];
const MUSIC_EXT: &[&str] = &[
    "mp3", "flac", "wav", "aac", "ogg", "m4a", "opus", "ape", "alac",
];
const BOOK_EXT: &[&str] = &["epub", "mobi", "azw3", "pdf", "djvu", "cbz", "cbr"];
const SOFTWARE_EXT: &[&str] = &["exe", "msi", "dmg", "apk", "deb", "rpm", "pkg", "iso"];
const GROUP_STOP: &[&str] = &["x264", "x265", "h264", "h265", "1080p", "720p", "2160p"];

/// Extracted attribute facets. Absent facets are `None`; `tag_string` renders the
/// present ones in [`FACET_KEYS`] order.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct Facets {
    pub kind: Option<&'static str>,
    pub year: Option<u32>,
    pub season: Option<u32>,
    pub episode: Option<u32>,
    pub resolution: Option<&'static str>,
    pub source: Option<&'static str>,
    pub vcodec: Option<&'static str>,
    pub acodec: Option<&'static str>,
    pub hdr: Option<&'static str>,
    pub edition: Option<&'static str>,
    pub group: Option<String>,
    pub lang: Option<&'static str>,
}

impl Facets {
    /// Serialise to a compact, searchable `key:value` token string in
    /// [`FACET_KEYS`] order, e.g. `"kind:movie year:2019 resolution:1080p
    /// source:web-dl vcodec:x265"`.
    pub fn tag_string(&self) -> String {
        let mut parts: Vec<String> = Vec::new();
        if let Some(v) = self.kind {
            parts.push(format!("kind:{v}"));
        }
        if let Some(v) = self.year {
            parts.push(format!("year:{v}"));
        }
        if let Some(v) = self.season {
            parts.push(format!("season:{v}"));
        }
        if let Some(v) = self.episode {
            parts.push(format!("episode:{v}"));
        }
        if let Some(v) = self.resolution {
            parts.push(format!("resolution:{v}"));
        }
        if let Some(v) = self.source {
            parts.push(format!("source:{v}"));
        }
        if let Some(v) = self.vcodec {
            parts.push(format!("vcodec:{v}"));
        }
        if let Some(v) = self.acodec {
            parts.push(format!("acodec:{v}"));
        }
        if let Some(v) = self.hdr {
            parts.push(format!("hdr:{v}"));
        }
        if let Some(v) = self.edition {
            parts.push(format!("edition:{v}"));
        }
        if let Some(v) = &self.group {
            parts.push(format!("group:{v}"));
        }
        if let Some(v) = self.lang {
            parts.push(format!("lang:{v}"));
        }
        parts.join(" ")
    }
}

/// Normalise a name: take the first [`MAX_NAME`] chars, lower-case, fold every
/// separator/whitespace run to a single space, and trim — matching Python's
/// `_norm`.
fn normalize(name: &str) -> String {
    let lowered: String = name
        .chars()
        .take(MAX_NAME)
        .collect::<String>()
        .to_lowercase();
    let mut out = String::with_capacity(lowered.len());
    let mut prev_space = true; // leading spaces are dropped
    for ch in lowered.chars() {
        if SEP.contains(&ch) || ch.is_whitespace() {
            if !prev_space {
                out.push(' ');
                prev_space = true;
            }
        } else {
            out.push(ch);
            prev_space = false;
        }
    }
    if out.ends_with(' ') {
        out.pop();
    }
    out
}

/// True if `needle` occurs in the normalised bytes at word boundaries on both
/// sides (`\b…\b` over a space-delimited, single-spaced string).
fn has_word(nb: &[u8], needle: &[u8]) -> bool {
    let nl = needle.len();
    if nl == 0 || nl > nb.len() {
        return false;
    }
    let mut i = 0;
    while i + nl <= nb.len() {
        if &nb[i..i + nl] == needle {
            let before_ok = i == 0 || nb[i - 1] == b' ';
            let after = i + nl;
            let after_ok = after == nb.len() || nb[after] == b' ';
            if before_ok && after_ok {
                return true;
            }
        }
        i += 1;
    }
    false
}

/// First table value with any matching alternative (Python's `_first`).
fn first(table: Table, nb: &[u8]) -> Option<&'static str> {
    for (val, needles) in table {
        if needles.iter().any(|w| has_word(nb, w.as_bytes())) {
            return Some(val);
        }
    }
    None
}

fn parse_u32(bytes: &[u8]) -> u32 {
    let mut v: u32 = 0;
    for &b in bytes {
        v = v.saturating_mul(10).saturating_add(u32::from(b - b'0'));
    }
    v
}

/// `\bs(\d{1,2})[ ]?e(\d{1,3})\b` — leftmost season/episode.
fn find_sxxexx(nb: &[u8]) -> Option<(u32, u32)> {
    let mut i = 0;
    while i < nb.len() {
        let boundary_before = i == 0 || nb[i - 1] == b' ';
        if boundary_before && nb[i] == b's' {
            let ds = i + 1;
            let mut j = ds;
            while j < nb.len() && nb[j].is_ascii_digit() && j - ds < 2 {
                j += 1;
            }
            if j > ds {
                let mut k = j;
                if k < nb.len() && nb[k] == b' ' {
                    k += 1;
                }
                if k < nb.len() && nb[k] == b'e' {
                    let de = k + 1;
                    let mut m = de;
                    while m < nb.len() && nb[m].is_ascii_digit() && m - de < 3 {
                        m += 1;
                    }
                    if m > de && (m == nb.len() || nb[m] == b' ') {
                        return Some((parse_u32(&nb[ds..j]), parse_u32(&nb[de..m])));
                    }
                }
            }
        }
        i += 1;
    }
    None
}

/// `\b(?:season|series)[ ]?(\d{1,2})\b | \bs(\d{2})\b` — leftmost season.
fn find_season(nb: &[u8]) -> Option<u32> {
    let mut i = 0;
    while i < nb.len() {
        let boundary_before = i == 0 || nb[i - 1] == b' ';
        if boundary_before {
            for kw in [b"season".as_slice(), b"series".as_slice()] {
                if nb[i..].starts_with(kw) {
                    let mut k = i + kw.len();
                    if k < nb.len() && nb[k] == b' ' {
                        k += 1;
                    }
                    let ds = k;
                    while k < nb.len() && nb[k].is_ascii_digit() && k - ds < 2 {
                        k += 1;
                    }
                    if k > ds && (k == nb.len() || nb[k] == b' ') {
                        return Some(parse_u32(&nb[ds..k]));
                    }
                }
            }
            // alt2: s + exactly two digits + boundary
            if nb[i] == b's'
                && i + 3 <= nb.len()
                && nb[i + 1].is_ascii_digit()
                && nb[i + 2].is_ascii_digit()
                && (i + 3 == nb.len() || nb[i + 3] == b' ')
            {
                return Some(parse_u32(&nb[i + 1..i + 3]));
            }
        }
        i += 1;
    }
    None
}

/// `\b(19\d{2}|20\d{2})\b` — leftmost 4-digit 19xx/20xx token.
fn find_year(nb: &[u8]) -> Option<u32> {
    let mut i = 0;
    while i + 4 <= nb.len() {
        let boundary_before = i == 0 || nb[i - 1] == b' ';
        if boundary_before {
            let w = &nb[i..i + 4];
            let century = (w[0] == b'1' && w[1] == b'9') || (w[0] == b'2' && w[1] == b'0');
            let after = i + 4;
            if century
                && w[2].is_ascii_digit()
                && w[3].is_ascii_digit()
                && (after == nb.len() || nb[after] == b' ')
            {
                return Some(parse_u32(w));
            }
        }
        i += 1;
    }
    None
}

/// `-([A-Za-z0-9]{2,20})\s*$` on the RAW (un-normalised) trimmed name.
fn find_group(raw: &str) -> Option<String> {
    let trimmed = raw.trim();
    let idx = trimmed.rfind('-')?;
    let suffix = &trimmed[idx + 1..];
    if (2..=20).contains(&suffix.len()) && suffix.bytes().all(|b| b.is_ascii_alphanumeric()) {
        let cand = suffix.to_lowercase();
        if !GROUP_STOP.contains(&cand.as_str()) {
            return Some(cand);
        }
    }
    None
}

/// Extension of the single largest file (best `kind` signal). Ties keep the
/// first file, matching Python's `max`.
fn dominant_ext(files: &[(&str, u64)]) -> String {
    let mut best: Option<&(&str, u64)> = None;
    for f in files {
        match best {
            None => best = Some(f),
            Some(b) if f.1 > b.1 => best = Some(f), // strictly-greater keeps the first max
            _ => {}
        }
    }
    match best {
        Some((path, _)) => match path.rsplit_once('.') {
            Some((_, ext)) if !ext.is_empty() => ext.to_lowercase(),
            _ => String::new(),
        },
        None => String::new(),
    }
}

/// Classify a torrent `name` (and optional `(path, size)` file list) into facets.
pub fn classify(name: &str, files: &[(&str, u64)]) -> Facets {
    let raw: String = name.chars().take(MAX_NAME).collect();
    let n = normalize(name);
    let nb = n.as_bytes();

    let mut f = Facets {
        resolution: first(RES, nb),
        source: first(SOURCE, nb),
        vcodec: first(VCODEC, nb),
        acodec: first(ACODEC, nb),
        hdr: first(HDR, nb),
        edition: first(EDITION, nb),
        lang: first(LANG, nb),
        ..Facets::default()
    };

    if let Some((s, e)) = find_sxxexx(nb) {
        f.season = Some(s);
        f.episode = Some(e);
    } else if let Some(s) = find_season(nb) {
        f.season = Some(s);
    }
    f.year = find_year(nb);
    f.group = find_group(&raw);

    // media kind: TV if season/episode, else extension + name hints.
    let ext = dominant_ext(files);
    let has_res = f.resolution.is_some();
    let game_hint = GAME_HINTS.iter().any(|w| has_word(nb, w.as_bytes()));
    f.kind = if f.season.is_some() || f.episode.is_some() {
        Some("tv")
    } else if MUSIC_EXT.contains(&ext.as_str()) {
        Some("music")
    } else if BOOK_EXT.contains(&ext.as_str()) {
        Some("book")
    } else if game_hint && (SOFTWARE_EXT.contains(&ext.as_str()) || !has_res) {
        Some("game")
    } else if SOFTWARE_EXT.contains(&ext.as_str()) && !has_res {
        Some("software")
    } else if has_res || f.source.is_some() || f.year.is_some() {
        Some("movie")
    } else {
        None
    };
    f
}

/// Convenience: `classify(name, files).tag_string()`.
pub fn tag_string(name: &str, files: &[(&str, u64)]) -> String {
    classify(name, files).tag_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalises_separators_and_brackets() {
        assert_eq!(
            normalize("The.Film.2019.1080p.BluRay.x265"),
            "the film 2019 1080p bluray x265"
        );
        assert_eq!(
            normalize("The Film (2019) [1080p] BluRay x265"),
            "the film 2019 1080p bluray x265"
        );
        assert_eq!(normalize("  a._ b  "), "a b");
    }

    #[test]
    fn identical_facets_for_dotted_and_spaced() {
        let a = classify("The.Film.2019.1080p.BluRay.x265", &[]);
        let b = classify("The Film (2019) [1080p] BluRay x265", &[]);
        assert_eq!(a, b);
        assert_eq!(a.resolution, Some("1080p"));
        assert_eq!(a.source, Some("bluray"));
        assert_eq!(a.vcodec, Some("x265"));
        assert_eq!(a.year, Some(2019));
        assert_eq!(a.kind, Some("movie"));
    }

    #[test]
    fn tv_season_episode() {
        let f = classify("Show.Name.S02E07.1080p.WEB-DL.DDP.5.1.H264-GRP", &[]);
        assert_eq!(f.season, Some(2));
        assert_eq!(f.episode, Some(7));
        assert_eq!(f.kind, Some("tv"));
        assert_eq!(f.source, Some("web-dl"));
        assert_eq!(f.vcodec, Some("x264"));
        assert_eq!(f.acodec, Some("eac3")); // standalone "ddp" token -> eac3
        assert_eq!(f.group.as_deref(), Some("grp"));
    }

    #[test]
    fn season_only() {
        let f = classify("Some Show Season 3 1080p", &[]);
        assert_eq!(f.season, Some(3));
        assert_eq!(f.episode, None);
        assert_eq!(f.kind, Some("tv"));
    }

    #[test]
    fn group_stopwords_are_not_groups() {
        assert_eq!(classify("Movie.2020.1080p.BluRay-x264", &[]).group, None);
        assert_eq!(classify("Movie.2020.1080p.BluRay-1080p", &[]).group, None);
        assert_eq!(
            classify("Movie.2020.1080p.BluRay-RARBG", &[])
                .group
                .as_deref(),
            Some("rarbg")
        );
    }

    #[test]
    fn kind_from_extension() {
        assert_eq!(
            classify(
                "Great Album (2019) FLAC",
                &[("01 - track.flac", 40_000_000)]
            )
            .kind,
            Some("music")
        );
        assert_eq!(
            classify("Some Book", &[("book.epub", 5_000_000)]).kind,
            Some("book")
        );
        assert_eq!(
            classify("Cool App 3.0", &[("setup.exe", 90_000_000)]).kind,
            Some("software")
        );
    }

    #[test]
    fn game_hint_kind() {
        assert_eq!(
            classify("Some.Game.v1.2-FitGirl.Repack", &[]).kind,
            Some("game")
        );
    }

    #[test]
    fn hdr_plus_stripped_to_hdr10() {
        // The '+' is a separator, so "HDR10+" normalises to "hdr10" -> "hdr10".
        assert_eq!(
            classify("Film 2019 2160p HDR10+ x265", &[]).hdr,
            Some("hdr10")
        );
        assert_eq!(
            classify("Film 2019 2160p HDR10Plus x265", &[]).hdr,
            Some("hdr10+")
        );
    }
}
