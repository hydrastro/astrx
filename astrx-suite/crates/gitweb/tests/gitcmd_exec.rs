//! Behaviour of `gitweb::gitcmd` against a **real** `git` process: the security
//! invariant (argv-only, root-confined, `--`-terminated), the caps and
//! timeouts, the persistent `cat-file` batch reader, the streaming readers and
//! the read-only Smart-HTTP transport.
//!
//! The byte-for-byte agreement with the Python reference lives in
//! `xcheck_gitcmd.rs`; this file covers the properties that are about *process
//! behaviour* rather than output text. Every test skips cleanly when no `git`
//! binary is available.

mod common;

use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};
use std::time::Duration;

use gitweb::gitcmd::{
    self, commit_count, commit_count_path, discover_repos, list_tree, log, log_path, peek_blob,
    read_blob, resolve_repo, run_git, run_git_with, search_code, stream_archive,
    stream_archive_with, stream_blob, stream_blob_with, upload_pack_advertise, upload_pack_rpc,
    GitCatFile, GitError, RefPattern, Repo, RepoName, RunOptions, SafePath, SafeQuery, SafeRef,
};

fn r(s: &str) -> SafeRef {
    SafeRef::parse(s).unwrap_or_else(|| panic!("invalid test ref {s:?}"))
}

fn p(s: &str) -> SafePath {
    SafePath::parse(s).unwrap_or_else(|| panic!("invalid test path {s:?}"))
}

fn q(s: &str) -> SafeQuery {
    SafeQuery::parse(s).unwrap_or_else(|| panic!("invalid test query {s:?}"))
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

/// A throwaway repository at an arbitrary directory (used for the hostile-name
/// and big-blob cases, where the shared deterministic fixture does not fit).
struct Scratch {
    dir: PathBuf,
}

impl Drop for Scratch {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.dir);
    }
}

impl Scratch {
    fn new(tag: &str) -> Scratch {
        use std::sync::atomic::{AtomicU64, Ordering};
        static SEQ: AtomicU64 = AtomicU64::new(0);
        let n = SEQ.fetch_add(1, Ordering::Relaxed);
        let nanos = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .map(|d| d.as_nanos())
            .unwrap_or(0);
        let dir = std::env::temp_dir().join(format!(
            "gitweb-scratch-{tag}-{}-{nanos}-{n}",
            std::process::id()
        ));
        std::fs::create_dir_all(&dir).expect("mkdir scratch");
        Scratch { dir }
    }

    fn git(&self, cwd: &Path, args: &[&str]) {
        let out = Command::new("git")
            .args(args)
            .current_dir(cwd)
            .env("HOME", "/nonexistent")
            .env("GIT_CONFIG_GLOBAL", "/dev/null")
            // Never interactive: GIT_TERMINAL_PROMPT alone only suppresses the TTY
            // prompt — git still runs an askpass/credential helper if the ambient
            // environment provides one (on a desktop that pops a GUI dialog and
            // hangs the test). Shut every door.
            .env("GIT_TERMINAL_PROMPT", "0")
            .env("GIT_CONFIG_NOSYSTEM", "1")
            .env("GIT_ASKPASS", "")
            .env_remove("SSH_ASKPASS")
            .env_remove("SSH_ASKPASS_REQUIRE")
            .env("GIT_CONFIG_SYSTEM", "/dev/null")
            .env("GIT_AUTHOR_NAME", "T")
            .env("GIT_AUTHOR_EMAIL", "t@e.x")
            .env("GIT_COMMITTER_NAME", "T")
            .env("GIT_COMMITTER_EMAIL", "t@e.x")
            .env("GIT_AUTHOR_DATE", "2020-01-01T00:00:00 +0000")
            .env("GIT_COMMITTER_DATE", "2020-01-01T00:00:00 +0000")
            .stdin(Stdio::null())
            .output()
            .expect("run git");
        assert!(
            out.status.success(),
            "git {args:?}: {}",
            String::from_utf8_lossy(&out.stderr)
        );
    }
}

// --------------------------------------------------------------------------- //
// The confinement invariant
// --------------------------------------------------------------------------- //

#[test]
fn traversal_and_symlink_escapes_are_refused() {
    let Some((fx, _repo)) = open_fixture() else {
        return;
    };
    // Every shape of "leave the configured root".
    let traversals = [
        "..",
        "../",
        "../..",
        "../outside",
        "..%2f..",
        "/etc",
        "/etc/passwd",
        "//etc",
        "xrepo/../xrepo",
        "xrepo/.git",
        "./xrepo",
        ".",
        "",
    ];
    for name in traversals {
        let err = resolve_repo(&fx.root, name).expect_err("must be refused");
        assert!(
            matches!(err, GitError::BadRequest(_) | GitError::NotFound(_)),
            "{name:?} -> {err:?}"
        );
    }

    // `escape` is a symlink under the root pointing at a real repository
    // *outside* it. It must neither resolve nor be discovered.
    let err = resolve_repo(&fx.root, "escape").expect_err("symlink escape must be refused");
    assert_eq!(err, GitError::NotFound("no such repository".to_string()));
    let names: Vec<String> = discover_repos(&fx.root)
        .expect("discover")
        .into_iter()
        .map(|repo| repo.name)
        .collect();
    assert!(!names.contains(&"escape".to_string()), "{names:?}");

    // Every resolved repository really is a direct child of the real root.
    let root_real = std::fs::canonicalize(&fx.root).expect("canonicalize root");
    for repo in discover_repos(&fx.root).expect("discover") {
        assert_eq!(
            repo.path.as_path().parent(),
            Some(root_real.as_path()),
            "{} escaped the root",
            repo.name
        );
    }
}

#[test]
fn option_like_and_metacharacter_values_never_reach_argv() {
    // A leading-dash value is rejected by the validators, so it can never be
    // built into an argv element at all.
    for hostile in [
        "--upload-pack=/tmp/evil",
        "--output=/tmp/evil",
        "-n",
        "--exec=/bin/sh",
        "-c",
    ] {
        assert!(SafeRef::parse(hostile).is_none(), "SafeRef({hostile:?})");
        assert!(RepoName::parse(hostile).is_none(), "RepoName({hostile:?})");
        assert!(
            RefPattern::parse(hostile).is_none(),
            "RefPattern({hostile:?})"
        );
    }
    // A path may not begin with `-` either (it would be read as an option even
    // though it is a legal filename).
    for hostile in ["-rf", "--output=/tmp/evil"] {
        assert!(SafePath::parse(hostile).is_none(), "SafePath({hostile:?})");
    }
    // Shell metacharacters and newlines are refused in a ref.
    for hostile in [
        "a;id", "a|id", "a&&id", "a$(id)", "a`id`", "a\nb", "a\rb", "a b", "a>out", "a<in", "a'b",
        "a\"b", "a\\b", "a{b}", "a\0b",
    ] {
        assert!(SafeRef::parse(hostile).is_none(), "SafeRef({hostile:?})");
        assert!(RepoName::parse(hostile).is_none(), "RepoName({hostile:?})");
    }
    // A NUL or any control character is refused in a path (a NUL cannot be in
    // an argv element at all, and a newline would desync the batch reader).
    for hostile in ["a\0b", "a\nb", "a\rb", "a\tb", "a\u{1f}b", "a\u{1}b"] {
        assert!(SafePath::parse(hostile).is_none(), "SafePath({hostile:?})");
    }
    // A NUL is refused in a query; everything else is data.
    assert!(SafeQuery::parse("a\0b").is_none());
    assert!(SafeQuery::parse("--fixed-strings").is_some());
}

#[test]
fn shell_metacharacters_in_a_pathspec_do_not_execute() {
    let Some((fx, repo)) = open_fixture() else {
        return;
    };
    // `;`, `|`, `$(…)` and backticks are all legal in a git filename, so the
    // validator accepts them — the protection is that they are an argv element
    // after `--`, never a shell word. A shell-based implementation would create
    // the canary; this one must not.
    let canary = fx.root.join("canary-pathspec");
    let hostile = format!("a;touch {}", canary.display());
    let hostile_path = SafePath::parse(&hostile).expect("legal filename, must validate");

    assert_eq!(
        commit_count_path(&repo, &r("main"), &hostile_path).expect("count"),
        0
    );
    assert!(log_path(&repo, &r("main"), &hostile_path, 0, 50, false)
        .expect("log_path")
        .is_empty());
    assert!(!canary.exists(), "a shell ran: {canary:?} was created");

    for shape in [
        "a$(touch /tmp/gitweb-canary-x)",
        "a`touch /tmp/gitweb-canary-y`",
        "a|id",
        "a&&id",
    ] {
        let path = SafePath::parse(shape).expect("legal filename");
        assert!(log_path(&repo, &r("main"), &path, 0, 50, false)
            .expect("log_path")
            .is_empty());
    }
    assert!(!Path::new("/tmp/gitweb-canary-x").exists());
    assert!(!Path::new("/tmp/gitweb-canary-y").exists());
}

#[test]
fn shell_metacharacters_in_a_search_term_do_not_execute() {
    let Some((fx, repo)) = open_fixture() else {
        return;
    };
    let canary = fx.root.join("canary-query");
    for shape in [
        format!("x; touch {}", canary.display()),
        format!("$(touch {})", canary.display()),
        format!("`touch {}`", canary.display()),
        "-n".to_string(),
        "--output=/tmp/gitweb-canary-z".to_string(),
        "--fixed-strings".to_string(),
    ] {
        let query = SafeQuery::parse(&shape).expect("query validates");
        // A term that looks like an option is the operand of `-e`, and
        // `--fixed-strings` makes it a literal: git can only ever report lines
        // that genuinely contain it (`-n` finds `--option-like-needle`), never
        // interpret it as an option.
        let (matches, more) = search_code(&repo, &r("main"), &query).expect("search");
        for hit in &matches {
            assert!(
                hit.text.contains(&shape),
                "{shape:?} matched a line that does not contain it: {hit:?}"
            );
        }
        assert!(!more);
    }
    assert!(!canary.exists(), "a shell ran: {canary:?} was created");
    assert!(!Path::new("/tmp/gitweb-canary-z").exists());
}

#[test]
fn a_repository_path_full_of_metacharacters_still_works() {
    if !common::git_available() {
        eprintln!("SKIP: no usable `git` binary on PATH");
        return;
    }
    // The strongest end-to-end proof that no shell is involved: the *root* and
    // the repository directory are full of shell metacharacters. Anything that
    // built a command string would break here (or execute the substitution).
    let scratch = Scratch::new("meta");
    let root = scratch.dir.join("ro ot;$(id)`id`&x");
    std::fs::create_dir_all(&root).expect("mkdir root");
    let repo_dir = root.join("re.po-1");
    std::fs::create_dir_all(&repo_dir).expect("mkdir repo");
    scratch.git(&repo_dir, &["init", "-q", "-b", "main"]);
    std::fs::write(repo_dir.join("f.txt"), "hello\n").expect("write");
    scratch.git(&repo_dir, &["add", "-A"]);
    scratch.git(&repo_dir, &["commit", "-q", "-m", "init"]);

    let repo = resolve_repo(&root, "re.po-1").expect("resolve");
    assert_eq!(commit_count(&repo, &r("main")).expect("count"), 1);
    assert_eq!(
        read_blob(&repo, &r("main"), &p("f.txt"), 1 << 20).expect("read"),
        b"hello\n"
    );
    assert_eq!(
        list_tree(&repo, &r("main"), &SafePath::root())
            .expect("tree")
            .len(),
        1
    );
}

#[test]
fn run_git_only_ever_runs_read_only_subcommands() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    for refused in [
        "receive-pack",
        "push",
        "commit",
        "init",
        "clone",
        "config",
        "daemon",
        "fetch",
        "gc",
        "update-ref",
        "am",
        "apply",
        "-c",
        "--exec-path=/tmp",
        "",
    ] {
        let err = run_git(&repo.path, &[refused, "--version"]).expect_err("must be refused");
        assert_eq!(
            err,
            GitError::BadRequest(format!("refused git subcommand: {refused}")),
            "{refused:?}"
        );
    }
    let err = run_git(&repo.path, &[]).expect_err("must be refused");
    assert_eq!(
        err,
        GitError::BadRequest("refused git subcommand: (none)".to_string())
    );
    // …and the read-only ones do run.
    let out = run_git(&repo.path, &["rev-parse", "--verify", "HEAD"]).expect("rev-parse");
    assert_eq!(out.code, 0);
    assert_eq!(String::from_utf8_lossy(&out.stdout).trim(), common::SHAS[5]);
}

// --------------------------------------------------------------------------- //
// Caps and timeouts
// --------------------------------------------------------------------------- //

#[test]
fn stdout_is_hard_capped_and_the_child_is_killed() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let out = run_git_with(
        &repo.path,
        &["log", "--format=%H", "main", "--"],
        RunOptions {
            // The subject here is the byte cap, not speed: `git log` on the
            // six-commit fixture writes its first chunk and is killed at 8
            // bytes, and neither assertion below says anything about how long
            // that took. The timeout is only what turns a child that never
            // writes into a failed test instead of a hung one, so it is set
            // above anything scheduling noise on a loaded 2-core runner can
            // produce rather than at the 15s production default (that default
            // bounds how long a request may take, which is a different
            // question). A genuine hang now takes two minutes to surface
            // instead of fifteen seconds; it still surfaces.
            timeout: Duration::from_secs(120),
            max_bytes: 8,
            check: false,
        },
    )
    .expect("run");
    assert_eq!(out.stdout.len(), 8);
    // Truncation kills the child, so its status is meaningless and reported as
    // success (the reference's `returncode = 0 if capped`).
    assert_eq!(out.code, 0);
}

#[test]
fn a_zero_budget_times_out_with_the_reference_message() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let err = run_git_with(
        &repo.path,
        &["log", "--format=%H", "main", "--"],
        RunOptions {
            timeout: Duration::from_secs(0),
            max_bytes: 1 << 20,
            check: false,
        },
    )
    .expect_err("must time out");
    assert_eq!(
        err,
        GitError::Failed("git log timed out after 0s".to_string())
    );
}

#[test]
fn check_turns_a_nonzero_exit_into_an_error() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let err = run_git_with(
        &repo.path,
        &["rev-parse", "--verify", "no-such-ref-at-all"],
        RunOptions {
            check: true,
            ..RunOptions::default()
        },
    )
    .expect_err("must fail");
    let GitError::Failed(message) = err else {
        panic!("wrong variant");
    };
    assert!(message.starts_with("git rev-parse failed ("), "{message}");
}

#[test]
fn a_huge_blob_is_read_within_the_cap() {
    if !common::git_available() {
        eprintln!("SKIP: no usable `git` binary on PATH");
        return;
    }
    let scratch = Scratch::new("big");
    let root = scratch.dir.join("repos");
    let repo_dir = root.join("bigrepo");
    std::fs::create_dir_all(&repo_dir).expect("mkdir");
    scratch.git(&repo_dir, &["init", "-q", "-b", "main"]);
    // 32 MiB of text (no NUL): far above every inline cap.
    let big = vec![b'A'; 32 * 1024 * 1024];
    std::fs::write(repo_dir.join("big.txt"), &big).expect("write big");
    std::fs::write(repo_dir.join("small.txt"), b"small\n").expect("write small");
    scratch.git(&repo_dir, &["add", "-A"]);
    scratch.git(&repo_dir, &["commit", "-q", "-m", "big"]);

    let repo = resolve_repo(&root, "bigrepo").expect("resolve");
    // The batch reader stops at the cap and respawns; it never drains 32 MiB.
    assert_eq!(peek_blob(&repo, &r("main"), &p("big.txt")).len(), 8192);
    assert_eq!(
        read_blob(&repo, &r("main"), &p("big.txt"), 8192)
            .expect("read")
            .len(),
        8192
    );
    assert_eq!(
        gitcmd::blob_size(&repo, &r("main"), &p("big.txt")),
        32 * 1024 * 1024
    );
    // …and the reader is still correctly aligned afterwards.
    assert_eq!(
        read_blob(&repo, &r("main"), &p("small.txt"), 1 << 20).expect("read"),
        b"small\n"
    );

    // The streaming reader honours its own byte cap without buffering the blob.
    let streamed: usize = stream_blob_with(&repo, &r("main"), &p("big.txt"), 65536, 4096)
        .expect("stream")
        .map(|chunk| chunk.len())
        .sum();
    assert_eq!(streamed, 4096);
}

// --------------------------------------------------------------------------- //
// The persistent cat-file batch reader
// --------------------------------------------------------------------------- //

#[test]
fn a_control_character_in_a_repo_derived_path_cannot_desync_the_reader() {
    if !common::git_available() {
        eprintln!("SKIP: no usable `git` binary on PATH");
        return;
    }
    // git allows any byte but NUL and `/` in a filename, newline included. Such
    // a repo-derived path must never be written to the batch process's stdin: it
    // would inject a second request and return the wrong blob's bytes later.
    let scratch = Scratch::new("hostile");
    let root = scratch.dir.join("repos");
    let repo_dir = root.join("hostilerepo");
    std::fs::create_dir_all(&repo_dir).expect("mkdir");
    scratch.git(&repo_dir, &["init", "-q", "-b", "main"]);
    let hostile = "readme\nHACK.txt";
    std::fs::write(repo_dir.join(hostile), "HOSTILE-BLOB-CONTENTS\n").expect("write hostile");
    std::fs::write(repo_dir.join("README.md"), "# Real Readme\n").expect("write readme");
    std::fs::write(repo_dir.join("normal.txt"), "normal file contents\n").expect("write normal");
    scratch.git(&repo_dir, &["add", "-A"]);
    scratch.git(&repo_dir, &["commit", "-q", "-m", "hostile filename repo"]);

    let repo = resolve_repo(&root, "hostilerepo").expect("resolve");
    assert!(!GitCatFile::spec_ok(&format!("main:{hostile}")));
    assert!(GitCatFile::spec_ok("main:normal.txt"));

    // Reading the hostile (newline) path is refused, not injected…
    assert_eq!(
        gitcmd::read_blob_raw_path(&repo, &r("main"), hostile, 1 << 20).unwrap_err(),
        GitError::NotFound("no such blob".to_string())
    );
    // …and every later read stays correct (it would be wrong if desynced).
    for _ in 0..3 {
        assert_eq!(
            read_blob(&repo, &r("main"), &p("normal.txt"), 1 << 20).expect("read"),
            b"normal file contents\n"
        );
        assert_eq!(
            read_blob(&repo, &r("main"), &p("README.md"), 1 << 20).expect("read"),
            b"# Real Readme\n"
        );
        assert!(gitcmd::read_blob_raw_path(&repo, &r("main"), hostile, 1 << 20).is_err());
    }
}

#[test]
fn the_batch_reader_is_reused_and_survives_being_closed() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let reader = GitCatFile::new(&repo.path);
    let first = reader.check("main:README.md").expect("check");
    let second = reader.check("main:src/main.py").expect("check");
    assert_ne!(first.sha, second.sha);
    assert_eq!(first.otype, "blob");
    assert!(reader.check("main:no/such/file").is_none());
    // A spec with a control character is refused before any write.
    assert!(reader.check("main:a\nb").is_none());
    assert!(reader.read("main:a\nb", 16).is_none());
    // Interleaved content reads stay aligned.
    let readme = reader.read("main:README.md", 1 << 20).expect("read");
    assert!(!readme.truncated);
    assert_eq!(readme.data.len() as u64, readme.stat.size);
    let capped = reader.read("main:README.md", 4).expect("read");
    assert!(capped.truncated);
    assert_eq!(capped.data, b"# Fi");
    // …even right after the cap forced a respawn.
    let again = reader.read("main:README.md", 1 << 20).expect("read");
    assert_eq!(again.data, readme.data);

    reader.close();
    // Closing is idempotent and the processes respawn on demand.
    reader.close();
    assert_eq!(
        reader.check("main:README.md").expect("check").sha,
        first.sha
    );

    // The module-level cache tears down without breaking later use.
    assert!(gitcmd::stat_object(&repo, &r("main"), &p("README.md")).is_some());
    gitcmd::close_catfiles();
    assert!(gitcmd::stat_object(&repo, &r("main"), &p("README.md")).is_some());
}

// --------------------------------------------------------------------------- //
// Streaming readers
// --------------------------------------------------------------------------- //

#[test]
fn stream_blob_yields_the_whole_blob_and_honours_its_cap() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let whole: Vec<u8> = stream_blob(&repo, &r("main"), &p("README.md"))
        .expect("stream")
        .flatten()
        .collect();
    assert_eq!(whole, common::README_MD.as_bytes());

    let capped: Vec<u8> = stream_blob_with(&repo, &r("main"), &p("README.md"), 4, 6)
        .expect("stream")
        .flatten()
        .collect();
    assert_eq!(capped, b"# Fixt");

    // A binary blob streams verbatim.
    let logo: Vec<u8> = stream_blob(&repo, &r("main"), &p("assets/logo.bin"))
        .expect("stream")
        .flatten()
        .collect();
    assert_eq!(logo, common::BINARY);

    // Dropping a stream half-way tears the child down and does not hang.
    let mut partial = stream_blob_with(&repo, &r("main"), &p("README.md"), 4, 0).expect("stream");
    assert_eq!(partial.next().expect("first chunk"), b"# Fi");
    drop(partial);
}

#[test]
fn stream_archive_produces_a_gzip_stream() {
    let Some((_fx, repo)) = open_fixture() else {
        return;
    };
    let tar: Vec<u8> = stream_archive(&repo, &r("main"), "xrepo-main/")
        .expect("archive")
        .flatten()
        .collect();
    assert!(tar.len() > 100, "archive too small: {}", tar.len());
    assert_eq!(&tar[..2], b"\x1f\x8b", "not a gzip stream");

    // The byte cap stops the stream early.
    let capped: Vec<u8> = stream_archive_with(&repo, &r("main"), "xrepo-main/", 1024, 100)
        .expect("archive")
        .flatten()
        .collect();
    assert_eq!(capped.len(), 100);

    // A prefix full of metacharacters is glued into one `--prefix=…` argv
    // element, so it can never be read as an option or reach a shell.
    let hostile: Vec<u8> = stream_archive(&repo, &r("main"), "--upload-pack=/tmp/evil;id`id`/")
        .expect("archive")
        .flatten()
        .collect();
    assert_eq!(&hostile[..2], b"\x1f\x8b");
}

// --------------------------------------------------------------------------- //
// Read-only Smart HTTP transport
// --------------------------------------------------------------------------- //

/// One git pkt-line (4-hex length prefix + data).
fn pkt(data: &[u8]) -> Vec<u8> {
    let mut out = format!("{:04x}", data.len() + 4).into_bytes();
    out.extend_from_slice(data);
    out
}

#[test]
fn upload_pack_advertises_refs_and_serves_a_pack() {
    let Some((fx, repo)) = open_fixture() else {
        return;
    };
    let advert = upload_pack_advertise(&repo, false).expect("advertise");
    let text = String::from_utf8_lossy(&advert);
    assert!(text.contains("refs/heads/main"), "{text}");
    assert!(text.contains(&fx.shas[5]), "{text}");
    assert!(
        text.ends_with("0000"),
        "advertisement must end with a flush pkt"
    );
    // The pack transport is read-only: nothing advertises a write capability.
    assert!(!text.contains("report-status"), "{text}");
    assert!(!text.contains("delete-refs"), "{text}");

    // Protocol v2 advertises the command list instead of refs.
    let v2 = String::from_utf8_lossy(&upload_pack_advertise(&repo, true).expect("advertise v2"))
        .into_owned();
    assert!(v2.contains("version 2"), "{v2}");
    assert!(v2.contains("ls-refs"), "{v2}");

    // A real (v0) fetch of the tip produces a pack.
    let mut request = pkt(format!(
        "want {} multi_ack_detailed side-band-64k thin-pack ofs-delta agent=gitweb-test\n",
        fx.shas[5]
    )
    .as_bytes());
    request.extend_from_slice(b"0000");
    request.extend_from_slice(&pkt(b"done\n"));
    let body: Vec<u8> = upload_pack_rpc(&repo, request, false)
        .expect("rpc")
        .flatten()
        .collect();
    assert!(!body.is_empty(), "upload-pack produced nothing");
    assert!(
        body.windows(4).any(|w| w == b"PACK") || body.len() > 100,
        "no pack data in {} bytes",
        body.len()
    );

    // An empty request terminates cleanly rather than hanging.
    let empty: Vec<u8> = upload_pack_rpc(&repo, b"0000".to_vec(), false)
        .expect("rpc")
        .flatten()
        .collect();
    assert!(empty.len() < 4096, "unexpected payload: {}", empty.len());
}

// --------------------------------------------------------------------------- //
// Miscellaneous behaviour
// --------------------------------------------------------------------------- //

#[test]
fn discovery_ignores_everything_that_is_not_a_repository() {
    let Some((fx, _repo)) = open_fixture() else {
        return;
    };
    let names: Vec<String> = discover_repos(&fx.root)
        .expect("discover")
        .into_iter()
        .map(|repo| repo.name)
        .collect();
    // Sorted, hidden entries skipped, plain directories skipped, escape skipped.
    assert_eq!(names, vec!["bare.git", "empty", "xrepo"]);
    // A root that does not exist is empty, not an error.
    assert!(discover_repos(Path::new("/no/such/root"))
        .expect("discover")
        .is_empty());
}

#[test]
fn ref_patterns_are_validated() {
    assert_eq!(RefPattern::heads().as_str(), "refs/heads/");
    assert_eq!(RefPattern::tags().as_str(), "refs/tags/");
    assert!(RefPattern::parse("refs/heads/").is_some());
    assert!(RefPattern::parse("refs/tags/v1.0").is_some());
    assert!(RefPattern::parse("--sort=-creatordate").is_none());
    assert!(RefPattern::parse("-x").is_none());
    assert!(RefPattern::parse("").is_none());
    assert!(RefPattern::parse("a b").is_none());
}

#[test]
fn empty_repositories_do_not_error() {
    let Some((fx, _repo)) = open_fixture() else {
        return;
    };
    let empty = resolve_repo(&fx.root, "empty").expect("empty");
    assert_eq!(gitcmd::default_branch(&empty).expect("branch"), "main");
    assert!(gitcmd::branches(&empty).expect("branches").is_empty());
    assert!(gitcmd::tags(&empty).expect("tags").is_empty());
    assert_eq!(commit_count(&empty, &r("main")).expect("count"), 0);
    assert!(gitcmd::stat_object(&empty, &r("main"), &SafePath::root()).is_none());
    assert!(!gitcmd::ref_exists(&empty, &r("main")).expect("exists"));
    assert!(gitcmd::read_gitmodules(&empty, &r("main")).is_empty());
    assert_eq!(
        log(&empty, &r("main"), 0, 50).unwrap_err(),
        GitError::NotFound("no such ref".to_string())
    );
    assert!(gitcmd::search_code(&empty, &r("main"), &q("anything"))
        .expect("search")
        .0
        .is_empty());
    assert!(gitcmd::log_grep(&empty, &r("main"), &q("anything"), 0, 50)
        .expect("log_grep")
        .is_empty());
}
