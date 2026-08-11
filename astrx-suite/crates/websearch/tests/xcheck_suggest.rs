//! Cross-check: the Rust `websearch::suggest` typeahead reproduces the Python
//! `websearch.suggest` byte-identically — `levenshtein` (a code-point edit
//! distance with the length-diff shortcut and the row-minimum early exit) and
//! `suggest` (steps 0/a/b), plus the `/suggest` endpoint's OpenSearch
//! Suggestions JSON body. Goldens emitted by `tests/regen_goldens.py`
//! (`gen_suggest`), which drives the real Python module + a SQLite/FTS5 corpus.
//!
//! # Two documented divergences (same standard as the rest of the port)
//!
//! * **Diacritics.** The `suggest` cross-check runs on an ASCII / diacritic-free
//!   corpus, where `ranking::words` (the FTS5 `unicode61` stand-in) tokenises
//!   identically to SQLite. Diacritic folding (`remove_diacritics 2`) is NOT
//!   reproduced — the same "behaviourally faithful" boundary as the BM25 search.
//!   `levenshtein` itself IS Unicode-correct and is cross-checked on non-ASCII
//!   pairs (café/cafe, naïve/naive, résumé/resume, Straße/Strasse) to lock its
//!   code-point iteration.
//! * **PopularQueries / step (0).** The `/suggest` endpoint passes an EMPTY
//!   `popular` slice (the in-process popular-query tracker is intentionally not
//!   ported), so its bodies never contain step-(0) output. The `suggest` function
//!   itself ports step (0) faithfully and is cross-checked here WITH a non-empty
//!   `popular` (including a case-insensitive de-dup collision against a vocab
//!   completion).

use std::sync::{Arc, Mutex};
use websearch::index::{DocFields, Index};
use websearch::serve::SearchServer;
use websearch::suggest::{levenshtein, suggest};

fn sv(v: &[&str]) -> Vec<String> {
    v.iter().map(|s| (*s).to_string()).collect()
}

/// The ASCII / diacritic-free corpus, byte-for-byte the `DOCS` list built in
/// `gen_suggest()` (`index.connect(":memory:")` + `upsert_document`). Term
/// document-frequencies are engineered (repeats across docs) so the `doc DESC,
/// term ASC` ordering of `vocab_prefix` is exercised, not incidental.
const DOCS: &[(&str, &str, &str, &str)] = &[
    (
        "http://h/1",
        "Rust programming language",
        "the rust programming guide",
        "rust is a programming language and rust powers programs",
    ),
    (
        "http://h/2",
        "Programming in Rust",
        "learn programming",
        "programming programs and programmers write programs",
    ),
    (
        "http://h/3",
        "Programmer notes",
        "a programmer writes code",
        "programmable systems and programmer tips",
    ),
    (
        "http://h/4",
        "Program manager",
        "program management",
        "the program runs a program every day",
    ),
    (
        "http://h/5",
        "Programs galore",
        "many programs",
        "programs programs programs everywhere",
    ),
    (
        "http://h/6",
        "Programmable chips",
        "programmable hardware",
        "programmable and programmatic devices",
    ),
    (
        "http://h/7",
        "Web crawler design",
        "building a web crawler",
        "the web crawler fetches web pages",
    ),
    (
        "http://h/8",
        "Java and JavaScript",
        "java jvm notes",
        "javascript runs java bytecode sometimes",
    ),
    (
        "http://h/9",
        "Hello world",
        "the hello message",
        "hello there hello again",
    ),
    (
        "http://h/10",
        "Python testing",
        "testing python code",
        "testing tester tested tests testable testing",
    ),
    (
        "http://h/11",
        "Test harness",
        "a test suite",
        "test tests tester tested testable testing testy",
    ),
    (
        "http://h/12",
        "Database systems",
        "database indexing",
        "database queries and database tuning",
    ),
];

fn corpus() -> Index {
    let mut ix = Index::new();
    for (url, title, description, body) in DOCS {
        ix.upsert_document(
            url,
            DocFields {
                title,
                description,
                body,
                host: "h",
                fetched_at: 100.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
    }
    ix
}

#[test]
fn levenshtein_matches_python() {
    // (a, b, max_dist, distance) — goldens from the real Python `levenshtein`.
    // Covers: equal strings, the |la-lb|>max_dist length shortcut, the row-min
    // early exit, max_dist 1 vs 2, empty strings, and non-ASCII (code-point) pairs.
    let cases: &[(&str, &str, usize, usize)] = &[
        ("", "", 1, 0),
        ("", "a", 1, 1),
        ("a", "", 1, 1),
        ("abc", "abc", 2, 0),
        ("kitten", "sitting", 3, 3),
        ("kitten", "sitting", 2, 3),
        ("flaw", "lawn", 2, 2),
        ("flaw", "lawn", 1, 2),
        ("rust", "rusty", 1, 1),
        ("rust", "runs", 1, 2),
        ("rust", "runs", 2, 2),
        ("java", "jaba", 1, 1),
        ("javascript", "javascrpit", 2, 2),
        ("abcdef", "abc", 2, 3),
        ("abc", "abcdef", 2, 3),
        ("book", "back", 2, 2),
        ("book", "back", 1, 2),
        ("café", "cafe", 1, 1),
        ("cafe", "café", 1, 1),
        ("naïve", "naive", 1, 1),
        ("naïve", "naive", 2, 1),
        ("café", "café", 1, 0),
        ("résumé", "resume", 2, 2),
        ("résumé", "resume", 1, 2),
        ("Straße", "Strasse", 2, 2),
        ("a", "b", 1, 1),
        ("ab", "ba", 1, 2),
        ("ab", "ba", 2, 2),
    ];
    for (a, b, m, want) in cases {
        assert_eq!(
            levenshtein(a, b, *m),
            *want,
            "levenshtein({a:?}, {b:?}, {m})"
        );
    }
}

#[test]
fn suggest_matches_python() {
    let ix = corpus();

    // (query, expected) with popular=[] and the default limit (10). Covers step
    // (a) single-word prefix + doc-DESC ordering, multi-word prefix_head reattach,
    // a short fuzzy fragment (max_dist=1), a longer typo (max_dist=2), a query
    // where step (a) already fills >=5 (fuzzy skipped), a pure-typo the fuzzy pass
    // resolves, and the no-match / whitespace boundaries.
    let cases: &[(&str, &[&str])] = &[
        (
            "prog",
            &[
                "programs",
                "programmable",
                "programming",
                "program",
                "programmatic",
                "programmer",
                "programmers",
            ],
        ),
        ("web cra", &["web crawler"]),
        ("jaba", &["java"]),
        ("javascrpit", &["javascript"]),
        (
            "program",
            &[
                "programs",
                "programmable",
                "programming",
                "programmatic",
                "programmer",
                "programmers",
            ],
        ),
        ("helo", &["hello"]),
        (
            "test",
            &["testable", "tested", "tester", "testing", "tests", "testy"],
        ),
        ("databse", &["database"]),
        ("  ", &[]),
        ("rust", &[]),
        ("xyzzy", &[]),
        ("prog lang", &["prog language"]),
    ];
    for (q, want) in cases {
        assert_eq!(suggest(&ix, q, &[], 10), sv(want), "suggest({q:?})");
    }

    // (query, popular, expected) — step (0) popular completion, original case
    // preserved, and the case-insensitive de-dup collision ("Rust Programming"
    // suppresses the vocab completion "rust programming").
    let pop_cases: &[(&str, &[&str], &[&str])] = &[
        (
            "rust pro",
            &["Rust Programming", "Rust Project"],
            &[
                "Rust Programming",
                "Rust Project",
                "rust programs",
                "rust programmable",
                "rust program",
                "rust programmatic",
                "rust programmer",
                "rust programmers",
            ],
        ),
        (
            "prog",
            &["Programming Guide"],
            &[
                "Programming Guide",
                "programs",
                "programmable",
                "programming",
                "program",
                "programmatic",
                "programmer",
                "programmers",
            ],
        ),
    ];
    for (q, pop, want) in pop_cases {
        assert_eq!(
            suggest(&ix, q, &sv(pop), 10),
            sv(want),
            "suggest({q:?}, {pop:?})"
        );
    }

    // limit boundary — the list is truncated to exactly `limit`.
    assert_eq!(
        suggest(&ix, "program", &[], 3),
        sv(&["programs", "programmable", "programming"])
    );
    assert_eq!(suggest(&ix, "test", &[], 2), sv(&["testable", "tested"]));
}

#[test]
fn suggest_json_body_matches_python() {
    let srv = SearchServer::new(Arc::new(Mutex::new(corpus())), "http://x");

    // (request target, exact body) — byte-identical to `json.dumps([q, terms],
    // ensure_ascii=False)`: SPACED separators, `"`/`\` escaped, non-ASCII raw,
    // `<`/`>`/`&` NOT escaped, and empty `q` short-circuiting to `["", []]`.
    let cases: &[(&str, &str)] = &[
        (
            "/suggest?q=prog",
            r#"["prog", ["programs", "programmable", "programming", "program", "programmatic", "programmer", "programmers"]]"#,
        ),
        ("/suggest?q=web+cra", r#"["web cra", ["web crawler"]]"#),
        ("/suggest?q=", r#"["", []]"#),
        ("/suggest", r#"["", []]"#),
        (r#"/suggest?q=++a%22b%5Cc++"#, r#"["a\"b\\c", []]"#),
        ("/suggest?q=caf%C3%A9", r#"["café", []]"#),
        ("/suggest?q=na%C3%AFve", r#"["naïve", []]"#),
        ("/suggest?q=%3Cb%3E%26amp%3B", r#"["<b>&amp;", []]"#),
        ("/suggest?q=xyzzy", r#"["xyzzy", []]"#),
    ];
    for (target, want) in cases {
        let resp = srv.route("GET", target);
        assert_eq!(resp.status, 200, "status for {target}");
        assert_eq!(
            resp.ctype, "application/x-suggestions+json; charset=utf-8",
            "ctype for {target}"
        );
        assert_eq!(&resp.body, want, "body for {target}");
    }
}
