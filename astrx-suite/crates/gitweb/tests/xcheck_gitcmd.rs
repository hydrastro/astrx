//! Cross-check: `gitweb::gitcmd` is byte-identical to the Python
//! `gitweb.gitcmd`, over a **real** git repository built by both sides.
//!
//! `tests/regen_gitcmd_goldens.py` builds a fully deterministic fixture (fixed
//! identity, fixed `GIT_AUTHOR_DATE`/`GIT_COMMITTER_DATE`, fixed content, so
//! every object id is stable — the generator's `--check-determinism` mode
//! builds it twice and diffs the ids), drives the reference implementation over
//! it and prints the literals embedded below. `tests/common/mod.rs` builds the
//! same repository from the same recipe here and asserts the same commit ids,
//! so the two sides genuinely compare the same objects.
//!
//! Covered: every validator, `object_spec`, every output parser
//! (`cat-file --batch` headers, `log`/`log --graph`/`ls-tree`/`for-each-ref`/
//! `blame --porcelain`/`grep -z`/`show -s`, `.gitmodules`, LFS pointers), the
//! UTF-8 replacement policy, repository discovery and confinement, the caps, and
//! the local-file helpers.
//!
//! # Not byte-compared
//!
//! * `format_patch` ends with git's own `-- \n<version>\n` signature; the
//!   golden has the version replaced by `<GIT-VERSION>` and this test does the
//!   same before comparing, so the assertion does not pin a git release.
//! * `upload_pack_advertise` embeds `agent=git/<version>` capabilities; it is
//!   checked structurally in `gitcmd_exec.rs` instead.

mod common;

use std::path::Path;

use gitweb::gitcmd::{
    self, blame, blob_size, branches, commit_count, commit_count_grep, commit_count_path,
    commit_meta, commit_patch, compare, decode_output, default_branch, discover_repos,
    format_patch, is_binary, lfs_object_path, lfs_object_size, list_tree, log, log_graph, log_grep,
    log_path, object_spec, object_type, parse_batch_header, parse_gitmodules, parse_lfs_pointer,
    peek_blob, peek_blob_with, read_blob, read_blob_raw_path, read_file, read_gitmodules,
    ref_exists, ref_names, resolve_commit, resolve_repo, search_code, search_code_with,
    stat_object, stream_file_with, tags, valid_path, valid_query, valid_ref, valid_repo_name,
    GitCatFile, GitError, Repo, SafePath, SafeQuery, SafeRef,
};

// --------------------------------------------------------------------------- //
// Helpers
// --------------------------------------------------------------------------- //

/// One `parse_lfs_pointer` case: `(pointer bytes, Some((oid, size)) | None)`.
type LfsCase<'a> = (&'a [u8], Option<(&'a str, u64)>);
/// One `log_graph` row: `(sha, short, parents, author, ts, subject)`.
type GraphRow<'a> = (&'a str, &'a str, &'a [&'a str], &'a str, i64, &'a str);
/// One `stat_object` case: `(ref, path, Some((sha, type, size)) | None)`.
type StatCase<'a> = (&'a str, &'a str, Option<(&'a str, &'a str, u64)>);
/// One `search_code` case: `(query, more, [(path, lineno, text)])`.
type SearchCase<'a> = (&'a str, bool, &'a [(&'a str, usize, &'a str)]);

fn r(s: &str) -> SafeRef {
    SafeRef::parse(s).unwrap_or_else(|| panic!("invalid test ref {s:?}"))
}

fn p(s: &str) -> SafePath {
    SafePath::parse(s).unwrap_or_else(|| panic!("invalid test path {s:?}"))
}

fn q(s: &str) -> SafeQuery {
    SafeQuery::parse(s).unwrap_or_else(|| panic!("invalid test query {s:?}"))
}

/// The `git` version, so the `format-patch` signature can be normalised the way
/// the generator does.
fn git_version() -> String {
    let out = std::process::Command::new("git")
        .arg("--version")
        .output()
        .expect("git --version");
    String::from_utf8_lossy(&out.stdout)
        .split_whitespace()
        .last()
        .unwrap_or("")
        .to_string()
}

fn open_fixture() -> Option<(common::Fixture, Repo)> {
    if !common::git_available() {
        eprintln!("SKIP: no usable `git` binary on PATH");
        return None;
    }
    let fx = common::build();
    let repo = resolve_repo(&fx.root, "xrepo").expect("resolve xrepo");
    Some((fx, repo))
}

// --------------------------------------------------------------------------- //
// Pure functions (no git process involved)
// --------------------------------------------------------------------------- //

#[test]
fn validators_match_python() {
    let repo_names: &[(&str, bool)] = &[
    ("myrepo", true),
    ("my.repo", true),
    ("my_repo-2", true),
    ("", false),
    (".", false),
    ("..", false),
    ("-repo", false),
    ("a/b", false),
    ("a b", false),
    ("a;b", false),
    ("a|b", false),
    ("a$b", false),
    ("a`b", false),
    ("a\nb", false),
    ("a\\b", false),
    ("réal", false),
    ("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", true),
    ("..hidden", true),
    (".hidden", true),
    ("repo.git", true),
    ("--upload-pack=/tmp/x", false),
    ];
    for &(name, want) in repo_names {
        assert_eq!(valid_repo_name(name), want, "valid_repo_name({name:?})");
        assert_eq!(
            gitcmd::RepoName::parse(name).is_some(),
            want,
            "RepoName::parse({name:?})"
        );
    }

    let refs: &[(&str, bool)] = &[
    ("main", true),
    ("v1.0", true),
    ("feature/x", true),
    ("a+b", true),
    ("a-b", true),
    ("a_b", true),
    ("HEAD", true),
    ("", false),
    ("-main", false),
    ("/main", false),
    (".main", false),
    ("main/", false),
    ("a..b", false),
    ("a@{0}", false),
    ("refs/heads/main.lock", false),
    ("a:b", false),
    ("a b", false),
    ("a;b", false),
    ("a|b", false),
    ("a$(id)b", false),
    ("a`id`b", false),
    ("a\nb", false),
    ("a*b", false),
    ("a?b", false),
    ("a[b", false),
    ("a^b", false),
    ("a~b", false),
    ("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", true),
    ("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", false),
    ("--upload-pack=/tmp/x", false),
    ("refs/heads/", false),
    ("café", false),
    ("0123456789abcdef0123456789abcdef01234567", true),
    ];
    for &(value, want) in refs {
        assert_eq!(valid_ref(value), want, "valid_ref({value:?})");
        assert_eq!(
            SafeRef::parse(value).is_some(),
            want,
            "SafeRef::parse({value:?})"
        );
    }

    let paths: &[(&str, bool)] = &[
    ("", true),
    ("a", true),
    ("a/b/c.txt", true),
    ("a b/c.txt", true),
    ("a:b.txt", true),
    ("a;b", true),
    ("a|b", true),
    ("a$(id)", true),
    ("a`id`", true),
    ("/etc/passwd", false),
    ("-rf", false),
    ("../etc/passwd", false),
    ("a/../b", false),
    ("a/..", false),
    ("../", false),
    ("a/./b", true),
    ("..a", true),
    ("a..b", true),
    ("a\u{0}b", false),
    ("a\nb", false),
    ("a\tb", false),
    ("a\u{1f}b", false),
    ("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", true),
    ("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", false),
    ("café/naïve.txt", true),
    ("--output=/tmp/x", false),
    ];
    for &(value, want) in paths {
        assert_eq!(valid_path(value), want, "valid_path({value:?})");
        assert_eq!(
            SafePath::parse(value).is_some(),
            want,
            "SafePath::parse({value:?})"
        );
    }

    let queries: &[(&str, bool)] = &[
    ("", false),
    ("needle", true),
    ("--looks-like-an-option", true),
    ("a;rm -rf /", true),
    ("$(id)", true),
    ("`id`", true),
    ("a\nb", true),
    ("a\u{0}b", false),
    ("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", true),
    ("xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", false),
    ("café", true),
    ];
    for &(value, want) in queries {
        assert_eq!(valid_query(value), want, "valid_query({value:?})");
        assert_eq!(
            SafeQuery::parse(value).is_some(),
            want,
            "SafeQuery::parse({value:?})"
        );
    }
}

#[test]
fn object_spec_matches_python() {
    let cases: &[(&str, &str, &str)] = &[
        ("main", "", "main"),
        ("main", "a/b.txt", "main:a/b.txt"),
        ("v1.0", "src", "v1.0:src"),
        ("abc123", "x", "abc123:x"),
    ];
    for &(reference, path, want) in cases {
        assert_eq!(object_spec(&r(reference), &p(path)), want);
    }
}

#[test]
fn batch_header_parsing_matches_python() {
    type Want<'a> = Option<(&'a str, &'a str, u64)>;
    let cases: &[(&[u8], Want)] = &[
        (b"", None),
        (b"\n", None),
        (b"deadbeef missing\n", None),
        (b"deadbeef ambiguous\n", None),
        (
            b"e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 blob 0\n",
            Some(("e69de29bb2d1d6434b8b29ae775ad8c2e48c5391", "blob", 0)),
        ),
        (
            b"e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 blob 12345\n",
            Some(("e69de29bb2d1d6434b8b29ae775ad8c2e48c5391", "blob", 12345)),
        ),
        (
            b"e69de29bb2d1d6434b8b29ae775ad8c2e48c5391   tree   42  \n",
            Some(("e69de29bb2d1d6434b8b29ae775ad8c2e48c5391", "tree", 42)),
        ),
        (b"only two\n", None),
        (b"a b c d\n", None),
        (b"abc blob notanumber\n", None),
        (b"abc blob -1\n", None),
        (b"abc\xffdef blob 7\n", Some(("abc�def", "blob", 7))),
        (b"abc commit 0", Some(("abc", "commit", 0))),
    ];
    for &(line, want) in cases {
        let got = parse_batch_header(line);
        match want {
            None => assert!(got.is_none(), "parse_batch_header({line:?}) = {got:?}"),
            Some((sha, otype, size)) => {
                let got = got.unwrap_or_else(|| panic!("parse_batch_header({line:?}) = None"));
                assert_eq!(
                    (got.sha.as_str(), got.otype.as_str(), got.size),
                    (sha, otype, size)
                );
            }
        }
    }
}

#[test]
fn gitmodules_parsing_matches_python() {
    let cases: &[(&str, &[(&str, &str)])] = &[
    ("[submodule \"vendor\"]\n\tpath = vendor\n\turl = https://example.com/vendor.git\n", &[("vendor", "https://example.com/vendor.git")]),
    ("", &[]),
    ("[submodule \"a\"]\npath = a\nurl = u1\n[submodule \"b\"]\n\tPATH = b\n\tURL = u2\n", &[("a", "u1"), ("b", "u2")]),
    ("path = orphan\nurl = ou\n", &[("orphan", "ou")]),
    ("[submodule \"a\"]\npath = a\n", &[("a", "")]),
    ("[submodule \"a\"]\nurl = only-url\n", &[]),
    ("[submodule \"a\"]\npath = dup\nurl = first\n[submodule \"b\"]\npath = dup\nurl = second\n", &[("dup", "second")]),
    ("  [ submodule ]  \n  path  =  spaced  \n  url  =  spaced-url  \n", &[("spaced", "spaced-url")]),
    ("path=a=b\nurl=http://x/?a=b\n", &[("a=b", "http://x/?a=b")]),
    ("[x]\npath = p\n[y]\n", &[("p", "")]),
    ("no equals here\n", &[]),
    ];
    for &(text, want) in cases {
        let got = parse_gitmodules(text);
        let got: Vec<(&str, &str)> = got.iter().map(|(k, v)| (k.as_str(), v.as_str())).collect();
        assert_eq!(got.as_slice(), want, "parse_gitmodules({text:?})");
    }
}

#[test]
fn lfs_pointer_parsing_matches_python() {
    let cases: &[LfsCase] = &[
    (b"version https://git-lfs.github.com/spec/v1\noid sha256:1111111111111111111111111111111111111111111111111111111111111111\nsize 12345\n", Some(("1111111111111111111111111111111111111111111111111111111111111111", 12345))),
    (b"version https://git-lfs.github.com/spec/v1\noid sha256:not-hex\nsize 5\n", None),
    (b"version https://git-lfs.github.com/spec/v1\nsize 5\n", None),
    (b"version https://git-lfs.github.com/spec/v1\noid sha256:1111111111111111111111111111111111111111111111111111111111111111\n", None),
    (b"not a pointer at all\n", None),
    (b"", None),
    (b"version https://git-lfs.github.com/spec/v1\noid sha256:1111111111111111111111111111111111111111111111111111111111111111\nsize 0\n", Some(("1111111111111111111111111111111111111111111111111111111111111111", 0))),
    (b"version https://git-lfs.github.com/spec/v1\noid sha256:1111111111111111111111111111111111111111111111111111111111111111\nsize 1\n", Some(("1111111111111111111111111111111111111111111111111111111111111111", 1))),
    (b"version https://git-lfs.github.com/spec/v1\noid sha256:1111111111111111111111111111111111111111111111111111111111111111\nsize 7\nxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx", None),
    (b"version https://git-lfs.github.com/spec/v1\noid sha256:1111111111111111111111111111111111111111111111111111111111111111\nsize \xff9\n", None),
    (b"#!/usr/bin/env python3\nprint(\"<hello> & 'world'\")\nvalue = 1 < 2 and 3 > 2\n", None),
    ];
    for &(data, want) in cases {
        match (parse_lfs_pointer(data), want) {
            (None, None) => {}
            (Some(got), Some((oid, size))) => {
                assert_eq!((got.oid.as_str(), got.size), (oid, size), "{data:?}");
            }
            (got, want) => panic!("parse_lfs_pointer({data:?}) = {got:?}, want {want:?}"),
        }
    }
}

#[test]
fn output_decoding_matches_python() {
    let cases: &[(&[u8], &str)] = &[
        (b"plain ascii", "plain ascii"),
        (b"caf\xc3\xa9", "café"),
        (b"\xff", "�"),
        (b"\x80", "�"),
        (b"\xc3", "�"),
        (b"\xc3(", "�("),
        (b"\xe2\x82", "�"),
        (b"\xe2\x82(", "�("),
        (b"\xf0\x9f\x92", "�"),
        (b"\xed\xa0\x80", "���"),
        (b"\xf4\x90\x80\x80", "����"),
        (b"\xc0\x80", "��"),
        (b"abc\xffdef", "abc�def"),
        (b"\xf8\x88\x80\x80\x80", "�����"),
        (b"a\x00b", "a\u{0}b"),
        (b"", ""),
    ];
    for &(data, want) in cases {
        assert_eq!(decode_output(data), want, "decode_output({data:?})");
    }
}

#[test]
fn is_binary_matches_python() {
    let cases: &[(&[u8], bool)] = &[
    (b"", false),
    (b"text", false),
    (b"a\x00b", true),
    (b"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\x00", false),
    (b"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\x00", true),
    (b"\x89PNG\r\n\x00\x00fake-binary\x00\xff\xfe\x01\x02payload", true),
    ];
    for &(data, want) in cases {
        assert_eq!(is_binary(data), want);
    }
}

#[test]
fn caps_match_python() {
    assert_eq!(gitcmd::DEFAULT_TIMEOUT, 15);
    assert_eq!(gitcmd::DEFAULT_MAX_BYTES, 12_582_912);
    assert_eq!(gitcmd::MAX_STDERR_BYTES, 65_536);
    assert_eq!(gitcmd::MAX_QUERY_BYTES, 512);
    assert_eq!(gitcmd::GREP_TIMEOUT, 10);
    assert_eq!(gitcmd::GREP_MAX_BYTES, 4_194_304);
    assert_eq!(gitcmd::GREP_MAX_MATCHES, 1000);
    assert_eq!(gitcmd::GREP_MAX_COUNT_PER_FILE, 100);
    assert_eq!(gitcmd::PATCH_TIMEOUT, 30);
    assert_eq!(gitcmd::PATCH_MAX_BYTES, 12_582_912);
    assert_eq!(gitcmd::UPLOAD_PACK_TIMEOUT, 120);
    assert_eq!(gitcmd::UPLOAD_PACK_ADVERTISE_MAX_BYTES, 12_582_912);
    assert_eq!(gitcmd::FIELD_SEP, '\u{1f}');

    let mut allowed = gitcmd::ALLOWED_SUBCOMMANDS.to_vec();
    allowed.sort_unstable();
    let want: &[&str] = &[
        "archive",
        "blame",
        "cat-file",
        "diff-tree",
        "for-each-ref",
        "format-patch",
        "grep",
        "log",
        "ls-tree",
        "rev-list",
        "rev-parse",
        "show",
        "symbolic-ref",
        "upload-pack",
    ];
    assert_eq!(allowed.as_slice(), want);
    // The read-only guarantee: the push side is never in the allow-list.
    assert!(gitcmd::is_allowed_subcommand("upload-pack"));
    assert!(!gitcmd::is_allowed_subcommand("receive-pack"));
    assert!(!gitcmd::is_allowed_subcommand("push"));
    assert!(!gitcmd::is_allowed_subcommand("commit"));
}

#[test]
fn spec_ok_matches_python() {
    let cases: &[(&str, bool)] = &[
        ("main:normal", true),
        ("main:readme\nHACK.txt", false),
        ("main:a\u{7f}b", false),
        ("main:a\u{1f}b", false),
    ];
    for &(spec, want) in cases {
        assert_eq!(GitCatFile::spec_ok(spec), want, "spec_ok({spec:?})");
    }
}

// --------------------------------------------------------------------------- //
// Repository discovery / confinement
// --------------------------------------------------------------------------- //

#[test]
fn discover_repos_matches_python() {
    let Some((fx, _repo)) = open_fixture() else {
        return;
    };
    let want: &[(&str, bool, &str, Option<i64>)] = &[
        ("bare.git", true, "", Some(1578268800)),
        ("empty", false, "", None),
        (
            "xrepo",
            false,
            "The cross-check fixture repository.",
            Some(1578268800),
        ),
    ];
    let got = discover_repos(&fx.root).expect("discover");
    let got: Vec<(&str, bool, &str, Option<i64>)> = got
        .iter()
        .map(|repo| {
            (
                repo.name.as_str(),
                repo.bare,
                repo.description.as_str(),
                repo.last_commit_ts,
            )
        })
        .collect();
    assert_eq!(got.as_slice(), want);
}

#[test]
fn resolve_repo_matches_python() {
    let Some((fx, _repo)) = open_fixture() else {
        return;
    };
    let want: &[(&str, &str, &str)] = &[
        ("..", "bad_request", "invalid repository name"),
        (".", "bad_request", "invalid repository name"),
        ("", "bad_request", "invalid repository name"),
        ("-x", "bad_request", "invalid repository name"),
        ("no_such_repo", "not_found", "no such repository"),
        ("../outside", "bad_request", "invalid repository name"),
        ("escape", "not_found", "no such repository"),
        ("notrepo", "not_found", "no such repository"),
        ("xrepo/../xrepo", "bad_request", "invalid repository name"),
        ("/etc", "bad_request", "invalid repository name"),
        ("xrepo\u{0}", "bad_request", "invalid repository name"),
        (".hidden", "not_found", "no such repository"),
    ];
    for &(name, kind, message) in want {
        match resolve_repo(&fx.root, name) {
            Ok(repo) => {
                assert_eq!(kind, "ok", "resolve_repo({name:?}) unexpectedly succeeded");
                assert_eq!(repo.name, message);
            }
            Err(GitError::BadRequest(msg)) => {
                assert_eq!((kind, msg.as_str()), ("bad_request", message), "{name:?}");
            }
            Err(GitError::NotFound(msg)) => {
                assert_eq!((kind, msg.as_str()), ("not_found", message), "{name:?}");
            }
            Err(other) => panic!("resolve_repo({name:?}) = {other:?}"),
        }
    }
}

// --------------------------------------------------------------------------- //
// Refs
// --------------------------------------------------------------------------- //

#[test]
fn refs_match_python() {
    let Some((fx, repo)) = open_fixture() else {
        return;
    };
    let bare = resolve_repo(&fx.root, "bare.git").expect("bare");
    let empty = resolve_repo(&fx.root, "empty").expect("empty");

    let want_default: &[(&str, &str)] =
        &[("xrepo", "main"), ("bare.git", "main"), ("empty", "main")];
    for &(label, want) in want_default {
        let target = match label {
            "xrepo" => &repo,
            "bare.git" => &bare,
            _ => &empty,
        };
        assert_eq!(
            default_branch(target).expect("default_branch"),
            want,
            "{label}"
        );
    }

    let want_branches: &[&str] = &["feature", "main"];
    let want_tags: &[&str] = &["light", "v1.0", "v2.0"];
    let (got_branches, got_tags) = ref_names(&repo).expect("ref_names");
    assert_eq!(got_branches, want_branches);
    assert_eq!(got_tags, want_tags);

    let want_rows: &[(&str, &str, &str, &str, i64, &str)] = &[
        (
            "main",
            "branch",
            "bd41fa2",
            "Add submodule pin and an LFS pointer",
            1578268800,
            "Test Author",
        ),
        (
            "feature",
            "branch",
            "c031439",
            "Feature branch work",
            1578009600,
            "Test Author",
        ),
    ];
    let got = branches(&repo).expect("branches");
    let got: Vec<(&str, &str, &str, &str, i64, &str)> = got
        .iter()
        .map(|row| {
            (
                row.name.as_str(),
                row.kind.as_str(),
                row.target.as_str(),
                row.subject.as_str(),
                row.ts,
                row.author.as_str(),
            )
        })
        .collect();
    assert_eq!(got.as_slice(), want_rows);

    let want_tag_rows: &[(&str, &str, &str, &str, i64, &str)] = &[
        ("v2.0", "tag", "b299faf", "Second release", 1578268800, ""),
        (
            "light",
            "tag",
            "60ef3a9",
            "Extend main.py and add a binary",
            1577923200,
            "Test Author",
        ),
        ("v1.0", "tag", "951ba66", "First release", 1577836800, ""),
    ];
    let got = tags(&repo).expect("tags");
    let got: Vec<(&str, &str, &str, &str, i64, &str)> = got
        .iter()
        .map(|row| {
            (
                row.name.as_str(),
                row.kind.as_str(),
                row.target.as_str(),
                row.subject.as_str(),
                row.ts,
                row.author.as_str(),
            )
        })
        .collect();
    assert_eq!(got.as_slice(), want_tag_rows);

    let want_exists: &[(&str, bool)] = &[
        ("main", true),
        ("feature", true),
        ("v1.0", true),
        ("v2.0", true),
        ("light", true),
        ("HEAD", true),
        ("nope", false),
        ("f89360da54b374c0b4bc512d11642704f9393e56", true),
        ("f89360da", true),
    ];
    for &(reference, want) in want_exists {
        assert_eq!(
            ref_exists(&repo, &r(reference)).expect("ref_exists"),
            want,
            "ref_exists({reference:?})"
        );
    }

    // An empty (unborn-HEAD) repository lists nothing and does not error.
    assert!(branches(&empty).expect("branches").is_empty());
    assert!(tags(&empty).expect("tags").is_empty());
}

// --------------------------------------------------------------------------- //
// Log / graph / history
// --------------------------------------------------------------------------- //

#[test]
fn log_matches_python() {
    let Some((fx, repo)) = open_fixture() else {
        return;
    };
    let want: &[(&str, &str, &str, &str, i64, &str)] = &[
        (
            "bd41fa28647c51fa655db6959125e638a5e3747e",
            "bd41fa2",
            "Test Author",
            "author@example.com",
            1578268800,
            "Add submodule pin and an LFS pointer",
        ),
        (
            "981c8439f7dc13f52d006463a4b9fa6ab4a90d66",
            "981c843",
            "Test Author",
            "author@example.com",
            1578182400,
            "Merge feature into main",
        ),
        (
            "e3406ff493fdbd3433411d50f3077a4c59d0db4c",
            "e3406ff",
            "Test Author",
            "author@example.com",
            1578096000,
            "Add guide mentioning SEARCHKEYWORD",
        ),
        (
            "c031439fc0b54b8947f32fb4c39ef068fdf5d849",
            "c031439",
            "Test Author",
            "author@example.com",
            1578009600,
            "Feature branch work",
        ),
        (
            "60ef3a91ef5f932113929ee0355fa8e26b3dab4a",
            "60ef3a9",
            "Test Author",
            "author@example.com",
            1577923200,
            "Extend main.py and add a binary",
        ),
        (
            "f89360da54b374c0b4bc512d11642704f9393e56",
            "f89360d",
            "Test Author",
            "author@example.com",
            1577836800,
            "Add README and sources",
        ),
    ];
    let got = log(&repo, &r("main"), 0, 50).expect("log");
    let got: Vec<(&str, &str, &str, &str, i64, &str)> = got
        .iter()
        .map(|row| {
            (
                row.sha.as_str(),
                row.short.as_str(),
                row.author.as_str(),
                row.email.as_str(),
                row.ts,
                row.subject.as_str(),
            )
        })
        .collect();
    assert_eq!(got.as_slice(), want);

    let want_page: &[&str] = &[
        "981c8439f7dc13f52d006463a4b9fa6ab4a90d66",
        "e3406ff493fdbd3433411d50f3077a4c59d0db4c",
    ];
    let page: Vec<String> = log(&repo, &r("main"), 1, 2)
        .expect("log page")
        .into_iter()
        .map(|row| row.sha)
        .collect();
    assert_eq!(page, want_page);

    assert_eq!(commit_count(&repo, &r("main")).expect("count"), 6);
    assert_eq!(commit_count(&repo, &r("feature")).expect("count"), 3);
    assert_eq!(commit_count(&repo, &r("v1.0")).expect("count"), 1);

    let want_graph: &[GraphRow] = &[
        (
            "bd41fa28647c51fa655db6959125e638a5e3747e",
            "bd41fa2",
            &["981c8439f7dc13f52d006463a4b9fa6ab4a90d66"],
            "Test Author",
            1578268800,
            "Add submodule pin and an LFS pointer",
        ),
        (
            "981c8439f7dc13f52d006463a4b9fa6ab4a90d66",
            "981c843",
            &[
                "e3406ff493fdbd3433411d50f3077a4c59d0db4c",
                "c031439fc0b54b8947f32fb4c39ef068fdf5d849",
            ],
            "Test Author",
            1578182400,
            "Merge feature into main",
        ),
        (
            "e3406ff493fdbd3433411d50f3077a4c59d0db4c",
            "e3406ff",
            &["60ef3a91ef5f932113929ee0355fa8e26b3dab4a"],
            "Test Author",
            1578096000,
            "Add guide mentioning SEARCHKEYWORD",
        ),
        (
            "c031439fc0b54b8947f32fb4c39ef068fdf5d849",
            "c031439",
            &["60ef3a91ef5f932113929ee0355fa8e26b3dab4a"],
            "Test Author",
            1578009600,
            "Feature branch work",
        ),
        (
            "60ef3a91ef5f932113929ee0355fa8e26b3dab4a",
            "60ef3a9",
            &["f89360da54b374c0b4bc512d11642704f9393e56"],
            "Test Author",
            1577923200,
            "Extend main.py and add a binary",
        ),
        (
            "f89360da54b374c0b4bc512d11642704f9393e56",
            "f89360d",
            &[],
            "Test Author",
            1577836800,
            "Add README and sources",
        ),
    ];
    let got = log_graph(&repo, &r("main"), 0, 50).expect("log_graph");
    assert_eq!(got.len(), want_graph.len());
    for (row, &(sha, short, parents, author, ts, subject)) in got.iter().zip(want_graph) {
        assert_eq!(
            (
                row.sha.as_str(),
                row.short.as_str(),
                row.author.as_str(),
                row.ts,
                row.subject.as_str()
            ),
            (sha, short, author, ts, subject)
        );
        assert_eq!(row.parents, parents);
    }

    let want_path: &[(&str, &str)] = &[
        (
            "60ef3a91ef5f932113929ee0355fa8e26b3dab4a",
            "Extend main.py and add a binary",
        ),
        (
            "f89360da54b374c0b4bc512d11642704f9393e56",
            "Add README and sources",
        ),
    ];
    let got = log_path(&repo, &r("main"), &p("src/main.py"), 0, 50, false).expect("log_path");
    let got: Vec<(&str, &str)> = got
        .iter()
        .map(|row| (row.sha.as_str(), row.subject.as_str()))
        .collect();
    assert_eq!(got.as_slice(), want_path);

    assert_eq!(
        commit_count_path(&repo, &r("main"), &p("src/main.py")).expect("count"),
        2
    );
    assert_eq!(
        commit_count_path(&repo, &r("main"), &p("docs")).expect("count"),
        1
    );
    assert_eq!(
        commit_count_path(&repo, &r("main"), &p("no/such/path")).expect("count"),
        0
    );

    // The empty repository: `log` is NotFound, the counters are 0.
    let empty = resolve_repo(&fx.root, "empty").expect("empty");
    assert_eq!(
        log(&empty, &r("main"), 0, 50).unwrap_err(),
        GitError::NotFound("no such ref".to_string())
    );
    assert_eq!(commit_count(&empty, &r("main")).expect("count"), 0);

    // The bare clone sees the same tip through the same API.
    let bare = resolve_repo(&fx.root, "bare.git").expect("bare");
    assert_eq!(
        log(&bare, &r("main"), 0, 1).expect("bare log")[0].sha,
        fx.shas[5]
    );
}

// --------------------------------------------------------------------------- //
// Commits, patches and diffs
// --------------------------------------------------------------------------- //

#[test]
fn commit_meta_matches_python() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    type Row<'a> = (
        &'a str,
        &'a str,
        &'a str,
        &'a str,
        &'a str,
        &'a str,
        &'a str,
        &'a str,
        &'a str,
        &'a [&'a str],
        &'a str,
        &'a str,
        &'a str,
        &'a str,
        bool,
    );
    let want: &[Row] = &[
    ("60ef3a91ef5f932113929ee0355fa8e26b3dab4a", "60ef3a91ef5f932113929ee0355fa8e26b3dab4a", "60ef3a9", "Test Author", "author@example.com", "2020-01-02T00:00:00+00:00", "Test Author", "author@example.com", "2020-01-02T00:00:00+00:00", &["f89360da54b374c0b4bc512d11642704f9393e56"], "Extend main.py and add a binary", "", "N", "", false),
    ("981c8439f7dc13f52d006463a4b9fa6ab4a90d66", "981c8439f7dc13f52d006463a4b9fa6ab4a90d66", "981c843", "Test Author", "author@example.com", "2020-01-05T00:00:00+00:00", "Test Author", "author@example.com", "2020-01-05T00:00:00+00:00", &["e3406ff493fdbd3433411d50f3077a4c59d0db4c", "c031439fc0b54b8947f32fb4c39ef068fdf5d849"], "Merge feature into main", "", "N", "", false),
    ("v2.0", "tag v2.0\nTagger: Test Author <author@example.com>\n\nSecond release\nbd41fa28647c51fa655db6959125e638a5e3747e", "bd41fa2", "Test Author", "author@example.com", "2020-01-06T00:00:00+00:00", "Test Author", "author@example.com", "2020-01-06T00:00:00+00:00", &["981c8439f7dc13f52d006463a4b9fa6ab4a90d66"], "Add submodule pin and an LFS pointer", "", "N", "", false),
    ];
    for &(
        rev,
        sha,
        short,
        author_name,
        author_email,
        author_date,
        committer_name,
        committer_email,
        committer_date,
        parents,
        subject,
        body,
        signature_status,
        signing_key,
        verified,
    ) in want
    {
        let got = commit_meta(&repo, &r(rev)).expect("commit_meta");
        assert_eq!(got.sha, sha, "{rev}");
        assert_eq!(got.short, short, "{rev}");
        assert_eq!(got.author_name, author_name, "{rev}");
        assert_eq!(got.author_email, author_email, "{rev}");
        assert_eq!(got.author_date, author_date, "{rev}");
        assert_eq!(got.committer_name, committer_name, "{rev}");
        assert_eq!(got.committer_email, committer_email, "{rev}");
        assert_eq!(got.committer_date, committer_date, "{rev}");
        assert_eq!(got.parents, parents, "{rev}");
        assert_eq!(got.subject, subject, "{rev}");
        assert_eq!(got.body, body, "{rev}");
        assert_eq!(got.signature_status, signature_status, "{rev}");
        assert_eq!(got.signing_key, signing_key, "{rev}");
        assert_eq!(got.signature_verified(), verified, "{rev}");
        assert!(!got.signature_present(), "{rev}");
    }
    assert_eq!(
        commit_meta(&repo, &r("nosuchrev")).unwrap_err(),
        GitError::NotFound("no such commit".to_string())
    );
}

#[test]
fn patches_match_python() {
    let Some((fx, repo)) = open_fixture() else {
        return;
    };
    let want_c2: &str =
        "diff --git a/assets/logo.bin b/assets/logo.bin\nnew file mode 100644\nindex 0000000..3ec7e77\nBinary files /dev/null and b/assets/logo.bin differ\ndiff --git a/src/main.py b/src/main.py\nindex f44e7c5..28857a7 100644\n--- a/src/main.py\n+++ b/src/main.py\n@@ -1,3 +1,6 @@\n #!/usr/bin/env python3\n print(\"<hello> & 'world'\")\n value = 1 < 2 and 3 > 2\n+UNIQUE_NEEDLE_TOKEN = 1\n+--option-like-needle = 2\n+danger = \"<script>alert(1)</script>\"\n"
        ;
    assert_eq!(
        commit_patch(&repo, &r(&fx.shas[1])).expect("patch"),
        want_c2
    );

    let want_c1: &str =
        "diff --git a/README.md b/README.md\nnew file mode 100644\nindex 0000000..934fe44\n--- /dev/null\n+++ b/README.md\n@@ -0,0 +1,3 @@\n+# Fixture\n+\n+Some **bold** text and a <script> tag.\ndiff --git a/Zebra.txt b/Zebra.txt\nnew file mode 100644\nindex 0000000..28f1f1d\n--- /dev/null\n+++ b/Zebra.txt\n@@ -0,0 +1 @@\n+zebra\ndiff --git a/apple.txt b/apple.txt\nnew file mode 100644\nindex 0000000..4c479de\n--- /dev/null\n+++ b/apple.txt\n@@ -0,0 +1 @@\n+apple\ndiff --git a/run.sh b/run.sh\nnew file mode 100755\nindex 0000000..4163036\n--- /dev/null\n+++ b/run.sh\n@@ -0,0 +1,2 @@\n+#!/bin/sh\n+echo hi\ndiff --git a/src/main.py b/src/main.py\nnew file mode 100644\nindex 0000000..f44e7c5\n--- /dev/null\n+++ b/src/main.py\n@@ -0,0 +1,3 @@\n+#!/usr/bin/env python3\n+print(\"<hello> & 'world'\")\n+value = 1 < 2 and 3 > 2\ndiff --git a/weird dir/a:b.txt b/weird dir/a:b.txt\nnew file mode 100644\nindex 0000000..e19970b\n--- /dev/null\n+++ b/weird dir/a:b.txt\t\n@@ -0,0 +1,2 @@\n+colon path line\n+UNIQUE_NEEDLE_TOKEN in a colon path\ndiff --git a/weird dir/bad�name.txt b/weird dir/bad�name.txt\nnew file mode 100644\nindex 0000000..dd954d7\n--- /dev/null\n+++ b/weird dir/bad�name.txt\t\n@@ -0,0 +1 @@\n+non-utf8 name\ndiff --git a/zdir/inner.txt b/zdir/inner.txt\nnew file mode 100644\nindex 0000000..f05648e\n--- /dev/null\n+++ b/zdir/inner.txt\n@@ -0,0 +1 @@\n+inner\n"
        ;
    assert_eq!(
        commit_patch(&repo, &r(&fx.shas[0])).expect("patch"),
        want_c1
    );

    let want_mbox: &[u8] =
        b"From 60ef3a91ef5f932113929ee0355fa8e26b3dab4a Mon Sep 17 00:00:00 2001\nFrom: Test Author <author@example.com>\nDate: Thu, 2 Jan 2020 00:00:00 +0000\nSubject: [PATCH] Extend main.py and add a binary\n\n---\n assets/logo.bin | Bin 0 -> 31 bytes\n src/main.py     |   3 +++\n 2 files changed, 3 insertions(+)\n create mode 100644 assets/logo.bin\n\ndiff --git a/assets/logo.bin b/assets/logo.bin\nnew file mode 100644\nindex 0000000000000000000000000000000000000000..3ec7e7706ef2a260abb4ebc76bd937158cb8bff4\nGIT binary patch\nliteral 31\nmcmeAS@N?(oVqi#1%udx!%FIhFs$}^8kCCY$u`(w=F$DmcG77l>\n\nliteral 0\nHcmV?d00001\n\ndiff --git a/src/main.py b/src/main.py\nindex f44e7c5..28857a7 100644\n--- a/src/main.py\n+++ b/src/main.py\n@@ -1,3 +1,6 @@\n #!/usr/bin/env python3\n print(\"<hello> & 'world'\")\n value = 1 < 2 and 3 > 2\n+UNIQUE_NEEDLE_TOKEN = 1\n+--option-like-needle = 2\n+danger = \"<script>alert(1)</script>\"\n-- \n<GIT-VERSION>\n\n"
        ;
    let got = format_patch(&repo, &r(&fx.shas[1])).expect("format_patch");
    let got = String::from_utf8_lossy(&got).replace(&git_version(), "<GIT-VERSION>");
    assert_eq!(got.as_bytes(), want_mbox);

    let want_compare: &str =
        "diff --git a/.gitmodules b/.gitmodules\nnew file mode 100644\nindex 0000000..fc6e0f7\n--- /dev/null\n+++ b/.gitmodules\n@@ -0,0 +1,3 @@\n+[submodule \"vendor\"]\n+\tpath = vendor\n+\turl = https://example.com/vendor.git\ndiff --git a/assets/big.lfs b/assets/big.lfs\nnew file mode 100644\nindex 0000000..2d6381a\n--- /dev/null\n+++ b/assets/big.lfs\n@@ -0,0 +1,3 @@\n+version https://git-lfs.github.com/spec/v1\n+oid sha256:1111111111111111111111111111111111111111111111111111111111111111\n+size 12345\ndiff --git a/assets/logo.bin b/assets/logo.bin\nnew file mode 100644\nindex 0000000..3ec7e77\nBinary files /dev/null and b/assets/logo.bin differ\ndiff --git a/docs/guide.md b/docs/guide.md\nnew file mode 100644\nindex 0000000..26df0b5\n--- /dev/null\n+++ b/docs/guide.md\n@@ -0,0 +1,5 @@\n+# Guide\n+\n+| a | b |\n+| - | - |\n+| 1 | 2 |\ndiff --git a/feature.txt b/feature.txt\nnew file mode 100644\nindex 0000000..4b8cb1e\n--- /dev/null\n+++ b/feature.txt\n@@ -0,0 +1 @@\n+feature branch work\ndiff --git a/src/main.py b/src/main.py\nindex f44e7c5..28857a7 100644\n--- a/src/main.py\n+++ b/src/main.py\n@@ -1,3 +1,6 @@\n #!/usr/bin/env python3\n print(\"<hello> & 'world'\")\n value = 1 < 2 and 3 > 2\n+UNIQUE_NEEDLE_TOKEN = 1\n+--option-like-needle = 2\n+danger = \"<script>alert(1)</script>\"\ndiff --git a/vendor b/vendor\nnew file mode 160000\nindex 0000000..f89360d\n--- /dev/null\n+++ b/vendor\n@@ -0,0 +1 @@\n+Subproject commit f89360da54b374c0b4bc512d11642704f9393e56\n"
        ;
    assert_eq!(
        compare(&repo, &r("v1.0"), &r("main")).expect("compare"),
        want_compare
    );
}

// --------------------------------------------------------------------------- //
// Trees and blobs
// --------------------------------------------------------------------------- //

type TreeRow<'a> = (&'a str, &'a str, &'a str, Option<u64>, &'a str, &'a str);

fn assert_tree(repo: &Repo, path: &str, want: &[TreeRow]) {
    let got = list_tree(repo, &r("main"), &p(path)).expect("list_tree");
    let got: Vec<TreeRow> = got
        .iter()
        .map(|e| {
            (
                e.mode.as_str(),
                e.otype.as_str(),
                e.sha.as_str(),
                e.size,
                e.name.as_str(),
                e.path.as_str(),
            )
        })
        .collect();
    assert_eq!(got.as_slice(), want, "list_tree({path:?})");
}

#[test]
fn list_tree_matches_python() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    assert_tree(
        &repo,
        "",
        &[
            (
                "040000",
                "tree",
                "a85fafe9d8053d96d95e35cf22f537656bf8663a",
                None,
                "assets",
                "assets",
            ),
            (
                "040000",
                "tree",
                "d6e2f133ecebd74d643ac0d3c9f826ed829694b2",
                None,
                "docs",
                "docs",
            ),
            (
                "040000",
                "tree",
                "c330f5cfaa2b34c923a5f24d501de6d7f77c5805",
                None,
                "src",
                "src",
            ),
            (
                "040000",
                "tree",
                "c8750ba02d7d6f4e255ca151a672badf9c1e2cc5",
                None,
                "weird dir",
                "weird dir",
            ),
            (
                "040000",
                "tree",
                "108aabee1ecf7ab27858b9b94edb90863ce0f006",
                None,
                "zdir",
                "zdir",
            ),
            (
                "100644",
                "blob",
                "fc6e0f74854ca401d53bddd3946b76840129ba7e",
                Some(74),
                ".gitmodules",
                ".gitmodules",
            ),
            (
                "100644",
                "blob",
                "4c479defff9a675f4fa1a8867096d90733e9b769",
                Some(6),
                "apple.txt",
                "apple.txt",
            ),
            (
                "100644",
                "blob",
                "4b8cb1e5e8d930f324c54994bece54e9b4a1ef10",
                Some(20),
                "feature.txt",
                "feature.txt",
            ),
            (
                "100644",
                "blob",
                "934fe4487f156aa7d5492bd3223d847010377360",
                Some(50),
                "README.md",
                "README.md",
            ),
            (
                "100755",
                "blob",
                "4163036efa65bd4a469e752267498f01ea36a55c",
                Some(18),
                "run.sh",
                "run.sh",
            ),
            (
                "160000",
                "commit",
                "f89360da54b374c0b4bc512d11642704f9393e56",
                None,
                "vendor",
                "vendor",
            ),
            (
                "100644",
                "blob",
                "28f1f1d2375cc0f9bc99633cc813188ec17afaff",
                Some(6),
                "Zebra.txt",
                "Zebra.txt",
            ),
        ],
    );
    assert_tree(
        &repo,
        "src",
        &[(
            "100644",
            "blob",
            "28857a79803db1875ba6948ccb39846c1c4a0fe5",
            Some(160),
            "main.py",
            "src/main.py",
        )],
    );
    assert_tree(
        &repo,
        "weird dir",
        &[
            (
                "100644",
                "blob",
                "e19970bcc8784b021f2efe5458f2988d2b249596",
                Some(52),
                "a:b.txt",
                "weird dir/a:b.txt",
            ),
            (
                "100644",
                "blob",
                "dd954d7d829f4025701a360bc7b773fa0e324051",
                Some(14),
                "bad�name.txt",
                "weird dir/bad�name.txt",
            ),
        ],
    );
    assert_eq!(
        list_tree(&repo, &r("main"), &p("no/such/dir")).unwrap_err(),
        GitError::NotFound("no such tree".to_string())
    );
}

#[test]
fn blobs_match_python() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let want_stat: &[StatCase] = &[
        (
            "main",
            "",
            Some(("bd41fa28647c51fa655db6959125e638a5e3747e", "commit", 249)),
        ),
        (
            "main",
            "README.md",
            Some(("934fe4487f156aa7d5492bd3223d847010377360", "blob", 50)),
        ),
        (
            "main",
            "src",
            Some(("c330f5cfaa2b34c923a5f24d501de6d7f77c5805", "tree", 35)),
        ),
        (
            "main",
            "src/main.py",
            Some(("28857a79803db1875ba6948ccb39846c1c4a0fe5", "blob", 160)),
        ),
        (
            "main",
            "vendor",
            Some(("f89360da54b374c0b4bc512d11642704f9393e56", "commit", 187)),
        ),
        ("main", "no/such/file", None),
        (
            "v1.0",
            "README.md",
            Some(("934fe4487f156aa7d5492bd3223d847010377360", "blob", 50)),
        ),
        (
            "main",
            "assets/logo.bin",
            Some(("3ec7e7706ef2a260abb4ebc76bd937158cb8bff4", "blob", 31)),
        ),
    ];
    for &(reference, path, want) in want_stat {
        match (stat_object(&repo, &r(reference), &p(path)), want) {
            (None, None) => {}
            (Some(got), Some((sha, otype, size))) => {
                assert_eq!(
                    (got.sha.as_str(), got.otype.as_str(), got.size),
                    (sha, otype, size),
                    "stat_object({reference:?}, {path:?})"
                );
            }
            (got, want) => panic!("stat_object({reference:?}, {path:?}) = {got:?}, want {want:?}"),
        }
    }

    let want_type: &[(&str, &str, Option<&str>, u64)] = &[
        ("main", "", Some("commit"), 249),
        ("main", "README.md", Some("blob"), 50),
        ("main", "src", Some("tree"), 35),
        ("main", "src/main.py", Some("blob"), 160),
        ("main", "vendor", Some("commit"), 187),
        ("main", "no/such/file", None, 0),
        ("v1.0", "README.md", Some("blob"), 50),
        ("main", "assets/logo.bin", Some("blob"), 31),
    ];
    for &(reference, path, otype, size) in want_type {
        assert_eq!(
            object_type(&repo, &r(reference), &p(path)).as_deref(),
            otype,
            "object_type({reference:?}, {path:?})"
        );
        assert_eq!(
            blob_size(&repo, &r(reference), &p(path)),
            size,
            "blob_size({reference:?}, {path:?})"
        );
    }

    let readme: &[u8] = b"# Fixture\n\nSome **bold** text and a <script> tag.\n";
    let readme_capped: &[u8] = b"# Fixtur";
    let logo: &[u8] = b"\x89PNG\r\n\x00\x00fake-binary\x00\xff\xfe\x01\x02payload";
    assert_eq!(
        read_blob(&repo, &r("main"), &p("README.md"), 1 << 20).expect("read"),
        readme
    );
    assert_eq!(
        read_blob(&repo, &r("main"), &p("README.md"), 8).expect("read"),
        readme_capped
    );
    assert_eq!(
        read_blob(&repo, &r("main"), &p("assets/logo.bin"), 1 << 20).expect("read"),
        logo
    );
    assert_eq!(
        read_blob(&repo, &r("main"), &p("no/such/file"), 1 << 20).unwrap_err(),
        GitError::NotFound("no such blob".to_string())
    );

    let peek: &[u8] =
        b"#!/usr/bin/env python3\nprint(\"<hello> & 'world'\")\nvalue = 1 < 2 and 3 > 2\nUNIQUE_NEEDLE_TOKEN = 1\n--option-like-needle = 2\ndanger = \"<script>alert(1)</script>\"\n"
        ;
    let peek4: &[u8] = b"#!/u";
    assert_eq!(peek_blob(&repo, &r("main"), &p("src/main.py")), peek);
    assert_eq!(
        peek_blob_with(&repo, &r("main"), &p("src/main.py"), 4),
        peek4
    );
    assert!(peek_blob_with(&repo, &r("main"), &p("src/main.py"), 0).is_empty());

    let want_modules: &[&str] = &["vendor", "https://example.com/vendor.git"];
    let got: Vec<String> = read_gitmodules(&repo, &r("main"))
        .into_iter()
        .flat_map(|(k, v)| [k, v])
        .collect();
    assert_eq!(got, want_modules);

    // A repo-derived tree path round-trips; the lossily decoded non-UTF-8 name
    // cannot address its own blob again, so it 404s (never the wrong object).
    let want_raw: &[(&str, Option<&[u8]>)] = &[
        (
            "weird dir/a:b.txt",
            Some(b"colon path line\nUNIQUE_NEEDLE_TOKEN in a colon path\n"),
        ),
        ("weird dir/bad�name.txt", None),
    ];
    for &(path, want) in want_raw {
        match (read_blob_raw_path(&repo, &r("main"), path, 1 << 20), want) {
            (Ok(got), Some(data)) => assert_eq!(got, data, "{path:?}"),
            (Err(GitError::NotFound(_)), None) => {}
            (got, want) => panic!("read_blob_raw_path({path:?}) = {got:?}, want {want:?}"),
        }
    }
}

#[test]
fn resolve_commit_matches_python() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let want: &[(&str, &str)] = &[
        ("main", "bd41fa28647c51fa655db6959125e638a5e3747e"),
        ("feature", "c031439fc0b54b8947f32fb4c39ef068fdf5d849"),
        ("v1.0", "f89360da54b374c0b4bc512d11642704f9393e56"),
        ("v2.0", "bd41fa28647c51fa655db6959125e638a5e3747e"),
        ("light", "60ef3a91ef5f932113929ee0355fa8e26b3dab4a"),
        ("nope", "nope"),
        ("f89360da", "f89360da54b374c0b4bc512d11642704f9393e56"),
    ];
    for &(reference, sha) in want {
        assert_eq!(resolve_commit(&repo, &r(reference)), sha, "{reference:?}");
    }
}

#[test]
fn blame_matches_python() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let want: &[(&str, &str, usize, &str)] = &[
        ("f89360da", "Test Author", 1, "#!/usr/bin/env python3"),
        ("f89360da", "Test Author", 2, "print(\"<hello> & 'world'\")"),
        ("f89360da", "Test Author", 3, "value = 1 < 2 and 3 > 2"),
        ("60ef3a91", "Test Author", 4, "UNIQUE_NEEDLE_TOKEN = 1"),
        ("60ef3a91", "Test Author", 5, "--option-like-needle = 2"),
        (
            "60ef3a91",
            "Test Author",
            6,
            "danger = \"<script>alert(1)</script>\"",
        ),
    ];
    let got = blame(&repo, &r("main"), &p("src/main.py")).expect("blame");
    let got: Vec<(&str, &str, usize, &str)> = got
        .iter()
        .map(|line| {
            (
                line.short.as_str(),
                line.author.as_str(),
                line.lineno,
                line.content.as_str(),
            )
        })
        .collect();
    assert_eq!(got.as_slice(), want);
    assert_eq!(
        blame(&repo, &r("main"), &p("no/such/file")).unwrap_err(),
        GitError::NotFound("cannot blame path".to_string())
    );
}

// --------------------------------------------------------------------------- //
// Search
// --------------------------------------------------------------------------- //

#[test]
fn search_matches_python() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let want: &[SearchCase] = &[
        (
            "UNIQUE_NEEDLE_TOKEN",
            false,
            &[
                ("src/main.py", 4, "UNIQUE_NEEDLE_TOKEN = 1"),
                (
                    "weird dir/a:b.txt",
                    2,
                    "UNIQUE_NEEDLE_TOKEN in a colon path",
                ),
            ],
        ),
        (
            "--option-like-needle",
            false,
            &[("src/main.py", 5, "--option-like-needle = 2")],
        ),
        ("zzz_no_such_zzz", false, &[]),
        (
            "<script>",
            false,
            &[
                ("README.md", 3, "Some **bold** text and a <script> tag."),
                ("src/main.py", 6, "danger = \"<script>alert(1)</script>\""),
            ],
        ),
    ];
    for &(query, more, rows) in want {
        let (got, got_more) = search_code(&repo, &r("main"), &q(query)).expect("search");
        let got: Vec<(&str, usize, &str)> = got
            .iter()
            .map(|m| (m.path.as_str(), m.lineno, m.text.as_str()))
            .collect();
        assert_eq!(got.as_slice(), rows, "search_code({query:?})");
        assert_eq!(got_more, more, "search_code({query:?}).more");
    }

    // The parse-time cap flags "more".
    let (got, more) =
        search_code_with(&repo, &r("main"), &q("UNIQUE_NEEDLE_TOKEN"), 1).expect("search");
    assert_eq!(got.len(), 1);
    assert!(more);

    let want_grep: &[(&str, &[&str], u64)] = &[
        (
            "SEARCHKEYWORD",
            &["e3406ff493fdbd3433411d50f3077a4c59d0db4c"],
            1,
        ),
        ("Merge", &["981c8439f7dc13f52d006463a4b9fa6ab4a90d66"], 1),
        ("zzz_none", &[], 0),
    ];
    for &(query, shas, count) in want_grep {
        let got: Vec<String> = log_grep(&repo, &r("main"), &q(query), 0, 50)
            .expect("log_grep")
            .into_iter()
            .map(|row| row.sha)
            .collect();
        assert_eq!(got, shas, "log_grep({query:?})");
        assert_eq!(
            commit_count_grep(&repo, &r("main"), &q(query)).expect("count"),
            count,
            "commit_count_grep({query:?})"
        );
    }
}

// --------------------------------------------------------------------------- //
// Local-file helpers (git-lfs objects)
// --------------------------------------------------------------------------- //

#[test]
fn lfs_helpers_match_python() {
    let Some((fx, repo)) = open_fixture() else {
        return;
    };
    let want: &[(&str, bool)] = &[
        (
            "1111111111111111111111111111111111111111111111111111111111111111",
            true,
        ),
        (
            "0000000000000000000000000000000000000000000000000000000000000000",
            false,
        ),
        (
            "zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz",
            false,
        ),
        (
            "111111111111111111111111111111111111111111111111111111111111111",
            false,
        ),
        ("../../../../etc/passwd", false),
        (
            "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
            false,
        ),
        (
            "abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789",
            false,
        ),
        (
            "111111111111111111111111111111111111111111111111111111111111111g",
            false,
        ),
    ];
    for &(oid, found) in want {
        assert_eq!(
            lfs_object_path(&repo, oid).is_some(),
            found,
            "lfs_object_path({oid:?})"
        );
    }

    let found = lfs_object_path(&repo, common::LFS_OID).expect("lfs object");
    let repo_real = std::fs::canonicalize(repo.path.as_path()).expect("canonicalize");
    assert!(
        found.starts_with(&repo_real),
        "confined under the repository"
    );
    assert_eq!(lfs_object_size(&found), 24);
    assert_eq!(read_file(&found, 1 << 20), common::LFS_BYTES);
    assert_eq!(read_file(&found, 5), b"REAL ");
    assert!(read_file(&found, 0).is_empty());
    assert_eq!(gitcmd::peek_file(&found), common::LFS_BYTES);

    let missing = fx.root.join("nope");
    assert_eq!(lfs_object_size(&missing), 0);
    assert!(read_file(&missing, 10).is_empty());

    let streamed: Vec<u8> = stream_file_with(&found, 7, 0)
        .expect("stream")
        .flatten()
        .collect();
    assert_eq!(streamed, common::LFS_BYTES);
    let capped: Vec<u8> = stream_file_with(&found, 7, 10)
        .expect("stream")
        .flatten()
        .collect();
    assert_eq!(capped, b"REAL LFS O");
    assert!(stream_file_with(Path::new("/no/such/file"), 7, 0).is_err());
}
