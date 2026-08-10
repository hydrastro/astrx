//! Cross-check: the Rust spam heuristics score byte-identically to the Python
//! reference (`legacy-python/torrentds/spam.py`). Goldens were emitted by driving
//! `spam.score(...)` directly over the corpus below. This pins the hand-rolled
//! URL / domain-`\b` / promo-alternation matchers to Python's `re` semantics.

use torrentds::spam::{score, SpamConfig};

type Case = (
    &'static str,
    &'static [(&'static str, u64)],
    u64,
    u64,
    usize,
    &'static str,
);

fn files(parts: &[(&'static str, u64)]) -> Vec<(String, u64)> {
    parts.iter().map(|(p, l)| (p.to_string(), *l)).collect()
}

#[test]
fn spam_scores_match_python() {
    // (name, files, total_size, piece_length, piece_count, category, expected_score)
    let cases: &[(Case, f64)] = &[
        (
            (
                "Some.Movie.2019.1080p.BluRay.x264",
                &[("movie/movie.mkv", 1_400_000_000)],
                1_400_000_000,
                262_144,
                5340,
                "video",
            ),
            0.0,
        ),
        (
            (
                "Movie",
                &[("movie.mkv", 700_000_000), ("setup.exe", 5_000_000)],
                705_000_000,
                262_144,
                2689,
                "video",
            ),
            4.0,
        ),
        (
            (
                "App",
                &[("movie.mkv", 700_000_000), ("setup.exe", 5_000_000)],
                705_000_000,
                262_144,
                2689,
                "software",
            ),
            0.0,
        ),
        (
            (
                "Movie",
                &[
                    ("movie.mkv", 900_000_000),
                    ("readme.txt", 1000),
                    ("visit.url", 200),
                    ("info.nfo", 500),
                ],
                900_002_700,
                262_144,
                3433,
                "video",
            ),
            3.0,
        ),
        (
            ("Movie www.piratesite.com FREE", &[], 0, 0, 0, "other"),
            4.0,
        ),
        (("Album [visit us]", &[], 0, 0, 0, "other"), 2.0),
        (("Game keygen crack", &[], 0, 0, 0, "other"), 2.0),
        (("Clip xxx", &[], 0, 0, 0, "other"), 2.0),
        (("Clean.Release.2020", &[], 0, 0, 0, "other"), 0.0),
        (("get it at example.com now", &[], 0, 0, 0, "other"), 2.0),
        (("download.here.site.to", &[], 0, 0, 0, "other"), 2.0),
        (("a.comic.book", &[], 0, 0, 0, "other"), 0.0),
        (("Free Download Full Movie HD", &[], 0, 0, 0, "other"), 2.0),
        (("watch online now", &[], 0, 0, 0, "other"), 2.0),
        (("Serial Key + Activation Key", &[], 0, 0, 0, "other"), 2.0),
        (("new  rip 2021", &[], 0, 0, 0, "other"), 2.0),
        (
            (
                "fake",
                &[
                    ("big.iso", 1_000_000_000),
                    ("a.txt", 100),
                    ("b.url", 100),
                    ("c.lnk", 100),
                ],
                1_000_000_300,
                262_144,
                30,
                "other",
            ),
            6.0,
        ),
        (
            (
                "mismatch",
                &[("x.bin", 1_000_000_000)],
                1_000_000_000,
                262_144,
                99999,
                "other",
            ),
            3.0,
        ),
        (
            ("Movie.from.thepiratebay.org.x264", &[], 0, 0, 0, "video"),
            2.0,
        ),
        (
            (
                "cracked.software.keygen.www.warez.biz",
                &[],
                0,
                0,
                0,
                "software",
            ),
            6.0,
        ),
    ];

    let cfg = SpamConfig::default();
    for (i, ((name, fs, ts, pl, pc, cat), expect)) in cases.iter().enumerate() {
        let (s, reasons) = score(name, &files(fs), *ts, *pl, *pc, cat, &cfg);
        assert_eq!(
            s, *expect,
            "case {i} ({name:?}) scored {s}, expected {expect}; reasons={reasons:?}"
        );
    }
}
