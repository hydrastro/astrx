//! The `astrx` dispatcher's contract: **everything after the subcommand reaches
//! the engine byte-for-byte**.
//!
//! Every documented invocation in the README, `deploy/FLEET.md`, the compose
//! file and every operator's shell history has to keep working with `astrx ` in
//! front of it. The way that breaks is not a crash — it is a dispatcher that
//! helpfully normalises `--flag=value`, drops a `--`, or grabs a flag it thinks
//! is its own, and an engine that then does something subtly different. These
//! tests pin the argument vector itself, so that regression is caught here
//! rather than by a crawl that quietly used the wrong seed file.

#![cfg(feature = "net")]

use astrx::dispatch::{split, Action, ENGINES};

fn argv(args: &[&str]) -> Vec<String> {
    args.iter().map(|s| (*s).to_string()).collect()
}

/// The tokens the dispatcher would hand the engine, or `None` if it did not
/// dispatch to one.
fn rest_for(args: &[&str]) -> Option<(String, Vec<String>)> {
    match split(&argv(args)) {
        Action::Engine { engine, rest } => Some((engine.name.to_string(), rest)),
        Action::Doctor(rest) => Some(("doctor".to_string(), rest)),
        _ => None,
    }
}

#[test]
fn every_engine_row_dispatches() {
    for e in ENGINES {
        let (name, rest) = rest_for(&[e.name]).expect("engine row must dispatch");
        assert_eq!(name, e.name);
        assert!(rest.is_empty());
    }
    assert_eq!(rest_for(&["doctor"]).unwrap().0, "doctor");
}

#[test]
fn everything_after_the_subcommand_is_passed_through_untouched() {
    let tail = [
        "crawl",
        "--db",
        "/data/web.db",
        "--seeds=/data/seeds.txt",
        "--max-pages",
        "-1",
        "",
        "https://example.com/a b?c=d&e=--f",
        "--",
        "--not-a-flag",
        "-x",
        "--",
    ];
    let mut full = vec!["websearch"];
    full.extend_from_slice(&tail);
    let (name, rest) = rest_for(&full).unwrap();
    assert_eq!(name, "websearch");
    // Byte-identical, same order, same count: no normalisation of `--k=v`, no
    // `--` stripping, no dropping of the empty string.
    assert_eq!(rest, argv(&tail));
}

#[test]
fn a_flag_that_looks_like_a_subcommand_stays_a_value() {
    // `--root doctor` must reach gitweb as a value, not be re-read by astrx as
    // its own `doctor` subcommand. Getting this wrong turns "serve the repos in
    // ./doctor" into "run diagnostics", with an exit code that looks fine.
    let (name, rest) = rest_for(&["gitweb", "--root", "doctor"]).unwrap();
    assert_eq!(name, "gitweb");
    assert_eq!(rest, argv(&["--root", "doctor"]));

    // Same for an engine name used as a value.
    let (name, rest) = rest_for(&["gitweb", "--root", "websearch", "--port", "8801"]).unwrap();
    assert_eq!(name, "gitweb");
    assert_eq!(rest, argv(&["--root", "websearch", "--port", "8801"]));
}

#[test]
fn engine_help_flags_belong_to_the_engine_not_to_astrx() {
    for flag in ["--help", "-h", "--version", "-V"] {
        let (name, rest) = rest_for(&["websearch", flag]).unwrap();
        assert_eq!(name, "websearch");
        assert_eq!(rest, argv(&[flag]), "{flag} must reach the engine");
    }
}

#[test]
fn a_leading_double_dash_marks_the_next_token_as_the_subcommand() {
    let (name, rest) = rest_for(&["--", "doctor", "--db-dir", "/data"]).unwrap();
    assert_eq!(name, "doctor");
    assert_eq!(rest, argv(&["--db-dir", "/data"]));

    // Only the *first* `--` is consumed; a second one belongs to the engine.
    let (name, rest) = rest_for(&["--", "websearch", "crawl", "--", "-x"]).unwrap();
    assert_eq!(name, "websearch");
    assert_eq!(rest, argv(&["crawl", "--", "-x"]));
}

#[test]
fn a_bare_double_dash_with_nothing_after_it_is_a_usage_error() {
    assert!(matches!(split(&argv(&["--"])), Action::Usage(_)));
}

#[test]
fn astrx_level_help_and_version_are_recognised_only_in_first_position() {
    for flag in ["-h", "--help", "help"] {
        let Action::Print(text) = split(&argv(&[flag])) else {
            panic!("{flag} should print astrx's own help");
        };
        assert!(text.contains("usage: astrx"));
        for e in ENGINES {
            assert!(text.contains(e.name), "help must list {}", e.name);
            assert!(
                text.contains(e.about),
                "help must say what {} is for",
                e.name
            );
        }
        assert!(text.contains("doctor"));
    }
    let Action::Print(v) = split(&argv(&["--version"])) else {
        panic!("--version should print");
    };
    assert!(v.starts_with("astrx "));
}

#[test]
fn an_unknown_leading_flag_is_a_usage_error_naming_it() {
    let Action::Usage(msg) = split(&argv(&["--log-format=json", "websearch"])) else {
        panic!("an astrx-level flag astrx does not know must be a usage error");
    };
    // Naming the token matters: forwarding it would surface as websearch
    // complaining about a flag the operator aimed at astrx.
    assert!(msg.contains("--log-format"), "{msg}");
    assert!(msg.contains("usage: astrx"), "{msg}");
}

#[test]
fn an_unknown_subcommand_lists_the_real_ones() {
    let Action::Usage(msg) = split(&argv(&["gitwbe", "--root", "/repos"])) else {
        panic!("a typo'd engine name must be a usage error");
    };
    assert!(msg.contains("gitwbe"), "{msg}");
    for e in ENGINES {
        assert!(msg.contains(e.name), "{msg} should list {}", e.name);
    }
}

#[test]
fn no_arguments_at_all_is_a_usage_error() {
    assert!(matches!(split(&[]), Action::Usage(_)));
}
