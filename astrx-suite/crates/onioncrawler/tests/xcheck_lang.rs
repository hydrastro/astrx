//! Cross-check: the Rust `lang::guess_lang` matches the Python reference
//! (`legacy-python/onioncrawler/lang.py`) — same stop-word sets, same score /
//! margin thresholds, and the same tie-break (earliest language in the fixed
//! order wins) — over representative Latin + Cyrillic samples. Expected values
//! were emitted by driving the Python module directly.

use onioncrawler::lang::{guess_lang, known_languages};

#[test]
fn guess_lang_xcheck() {
    // (text, min_tokens, expected)
    let cases: &[(&str, usize, &str)] = &[
        (
            "the quick brown fox jumps over the lazy dog and it is on the log",
            8,
            "en",
        ),
        (
            "el gato de la casa que no es de los perros con la comida para el",
            8,
            "es",
        ),
        (
            "le chat de la maison et les chiens dans le jardin pour vous",
            8,
            "fr",
        ),
        (
            "der Hund und die Katze mit dem Ball ist nicht auf das Haus",
            8,
            "de",
        ),
        (
            "questo di che la per con non una come ma se anche gli",
            8,
            "it",
        ),
        ("de que os para com nao por mais dos ao seu uma", 8, "pt"),
        ("и в не на что с по как это из за для же", 8, "ru"),
        ("short text", 8, "un"),
        ("aaa bbb ccc ddd eee fff ggg hhh", 8, "un"),
        ("the and of to", 3, "en"),
    ];
    for (text, mt, expected) in cases {
        assert_eq!(
            guess_lang(text, *mt),
            *expected,
            "guess_lang({text:?}, min_tokens={mt})"
        );
    }
}

#[test]
fn known_languages_xcheck() {
    assert_eq!(
        known_languages(),
        vec!["de", "en", "es", "fr", "it", "pt", "ru"]
    );
}
