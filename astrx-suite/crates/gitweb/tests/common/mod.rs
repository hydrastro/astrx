//! The shared, **fully deterministic** git fixture used by the `gitcmd` tests.
//!
//! The recipe mirrors `build_fixture()` in `tests/regen_gitcmd_goldens.py` line
//! for line: same content, same fixed identity and `GIT_AUTHOR_DATE` /
//! `GIT_COMMITTER_DATE`, so every object id is stable and can be embedded as a
//! golden. `fixture_shas()` returns the six commit ids the generator printed;
//! `build()` asserts the repository it just created has exactly those ids, so a
//! drift between the two recipes fails loudly rather than silently comparing
//! different repositories.

#![allow(dead_code)]

use std::ffi::OsStr;
use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};

pub const README_MD: &str = "# Fixture\n\nSome **bold** text and a <script> tag.\n";
pub const MAIN_PY: &str =
    "#!/usr/bin/env python3\nprint(\"<hello> & 'world'\")\nvalue = 1 < 2 and 3 > 2\n";
pub const MAIN_PY_V2: &str = "#!/usr/bin/env python3\nprint(\"<hello> & 'world'\")\nvalue = 1 < 2 and 3 > 2\nUNIQUE_NEEDLE_TOKEN = 1\n--option-like-needle = 2\ndanger = \"<script>alert(1)</script>\"\n";
pub const FEATURE_TXT: &str = "feature branch work\n";
pub const GUIDE_MD: &str = "# Guide\n\n| a | b |\n| - | - |\n| 1 | 2 |\n";
pub const BINARY: &[u8] = b"\x89PNG\r\n\x00\x00fake-binary\x00\xff\xfe\x01\x02payload";
pub const COLON_TXT: &str = "colon path line\nUNIQUE_NEEDLE_TOKEN in a colon path\n";
pub const LFS_OID: &str = "1111111111111111111111111111111111111111111111111111111111111111";
pub const LFS_POINTER: &str = "version https://git-lfs.github.com/spec/v1\noid sha256:1111111111111111111111111111111111111111111111111111111111111111\nsize 12345\n";
pub const LFS_BYTES: &[u8] = b"REAL LFS OBJECT CONTENT\n";
pub const GITMODULES: &str =
    "[submodule \"vendor\"]\n\tpath = vendor\n\turl = https://example.com/vendor.git\n";
pub const DESCRIPTION: &str = "The cross-check fixture repository.\n";
pub const BAD_NAME: &[u8] = b"bad\xffname.txt";

pub const DATES: [&str; 6] = [
    "2020-01-01T00:00:00 +0000",
    "2020-01-02T00:00:00 +0000",
    "2020-01-03T00:00:00 +0000",
    "2020-01-04T00:00:00 +0000",
    "2020-01-05T00:00:00 +0000",
    "2020-01-06T00:00:00 +0000",
];

/// The commit ids the deterministic recipe produces (the generator's output).
pub const SHAS: [&str; 6] = [
    "f89360da54b374c0b4bc512d11642704f9393e56",
    "60ef3a91ef5f932113929ee0355fa8e26b3dab4a",
    "c031439fc0b54b8947f32fb4c39ef068fdf5d849",
    "e3406ff493fdbd3433411d50f3077a4c59d0db4c",
    "981c8439f7dc13f52d006463a4b9fa6ab4a90d66",
    "bd41fa28647c51fa655db6959125e638a5e3747e",
];

/// True when a usable `git` binary is on `PATH`.
pub fn git_available() -> bool {
    Command::new("git")
        .arg("--version")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
}

/// A private temporary directory (removed by [`Fixture`]'s `Drop`).
fn temp_dir(tag: &str) -> PathBuf {
    // A unique-per-process, unique-per-call name; no third-party dependency and
    // no reliance on the OS temp-file APIs beyond `temp_dir()` itself.
    use std::sync::atomic::{AtomicU64, Ordering};
    static SEQ: AtomicU64 = AtomicU64::new(0);
    let nanos = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_nanos())
        .unwrap_or(0);
    let n = SEQ.fetch_add(1, Ordering::Relaxed);
    let dir = std::env::temp_dir().join(format!("gitweb-{tag}-{}-{nanos}-{n}", std::process::id()));
    std::fs::create_dir_all(&dir).expect("create temp dir");
    dir
}

fn git(cwd: &Path, date: &str, args: &[&str]) -> String {
    let out = Command::new("git")
        .args(args)
        .current_dir(cwd)
        .env("HOME", "/nonexistent")
        .env("GIT_CONFIG_GLOBAL", "/dev/null")
        .env("GIT_CONFIG_SYSTEM", "/dev/null")
        .env("GIT_AUTHOR_NAME", "Test Author")
        .env("GIT_AUTHOR_EMAIL", "author@example.com")
        .env("GIT_COMMITTER_NAME", "Test Author")
        .env("GIT_COMMITTER_EMAIL", "author@example.com")
        .env("GIT_AUTHOR_DATE", date)
        .env("GIT_COMMITTER_DATE", date)
        .env("TZ", "UTC")
        .stdin(Stdio::null())
        .output()
        .expect("run git");
    assert!(
        out.status.success(),
        "git {args:?} failed: {}",
        String::from_utf8_lossy(&out.stderr)
    );
    String::from_utf8_lossy(&out.stdout).trim().to_string()
}

fn write(base: &Path, rel: &str, text: &str) {
    let path = base.join(rel);
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent).expect("mkdir");
    }
    std::fs::write(path, text).expect("write");
}

fn write_bytes(base: &Path, rel: &str, data: &[u8]) {
    let path = base.join(rel);
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent).expect("mkdir");
    }
    std::fs::write(path, data).expect("write");
}

/// A built fixture; the whole tree is removed when this is dropped.
pub struct Fixture {
    tmp: PathBuf,
    /// The repository root the server would be configured with.
    pub root: PathBuf,
    /// The six commit ids, oldest first.
    pub shas: Vec<String>,
}

impl Drop for Fixture {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.tmp);
    }
}

impl Fixture {
    /// The `xrepo` worktree repository's directory.
    pub fn repo_dir(&self) -> PathBuf {
        self.root.join("xrepo")
    }
}

/// Build the fixture repository set. Mirrors the generator's `build_fixture()`.
pub fn build() -> Fixture {
    let tmp = temp_dir("gitcmd");
    let root = tmp.join("repos");
    std::fs::create_dir_all(&root).expect("mkdir root");
    let repo = root.join("xrepo");
    std::fs::create_dir_all(&repo).expect("mkdir repo");

    git(&repo, DATES[0], &["init", "-q", "-b", "main"]);
    git(&repo, DATES[0], &["config", "user.name", "Test Author"]);
    git(
        &repo,
        DATES[0],
        &["config", "user.email", "author@example.com"],
    );
    write(&repo, ".git/description", DESCRIPTION);

    // c1 — README, sources, and names that exercise the tree sort.
    write(&repo, "README.md", README_MD);
    write(&repo, "src/main.py", MAIN_PY);
    write(&repo, "Zebra.txt", "zebra\n");
    write(&repo, "apple.txt", "apple\n");
    write(&repo, "zdir/inner.txt", "inner\n");
    write(&repo, "run.sh", "#!/bin/sh\necho hi\n");
    set_executable(&repo.join("run.sh"));
    write(&repo, "weird dir/a:b.txt", COLON_TXT);
    let bad = repo.join("weird dir").join(OsStr::from_bytes(BAD_NAME));
    std::fs::write(bad, b"non-utf8 name\n").expect("write non-utf8 name");
    git(&repo, DATES[0], &["add", "-A"]);
    git(
        &repo,
        DATES[0],
        &["commit", "-q", "-m", "Add README and sources"],
    );
    let c1 = git(&repo, DATES[0], &["rev-parse", "HEAD"]);

    // c2 — edit the source, add a binary asset.
    write(&repo, "src/main.py", MAIN_PY_V2);
    write_bytes(&repo, "assets/logo.bin", BINARY);
    git(&repo, DATES[1], &["add", "-A"]);
    git(
        &repo,
        DATES[1],
        &["commit", "-q", "-m", "Extend main.py and add a binary"],
    );
    let c2 = git(&repo, DATES[1], &["rev-parse", "HEAD"]);

    // c3 — a branch with its own commit.
    git(&repo, DATES[2], &["checkout", "-q", "-b", "feature"]);
    write(&repo, "feature.txt", FEATURE_TXT);
    git(&repo, DATES[2], &["add", "-A"]);
    git(
        &repo,
        DATES[2],
        &["commit", "-q", "-m", "Feature branch work"],
    );
    let c3 = git(&repo, DATES[2], &["rev-parse", "HEAD"]);

    // c4 — main moves on.
    git(&repo, DATES[3], &["checkout", "-q", "main"]);
    write(&repo, "docs/guide.md", GUIDE_MD);
    git(&repo, DATES[3], &["add", "-A"]);
    git(
        &repo,
        DATES[3],
        &["commit", "-q", "-m", "Add guide mentioning SEARCHKEYWORD"],
    );
    let c4 = git(&repo, DATES[3], &["rev-parse", "HEAD"]);

    // c5 — a real two-parent merge.
    git(
        &repo,
        DATES[4],
        &[
            "merge",
            "-q",
            "--no-ff",
            "feature",
            "-m",
            "Merge feature into main",
        ],
    );
    let c5 = git(&repo, DATES[4], &["rev-parse", "HEAD"]);

    // c6 — a submodule gitlink + .gitmodules and an LFS pointer.
    write(&repo, ".gitmodules", GITMODULES);
    write(&repo, "assets/big.lfs", LFS_POINTER);
    git(
        &repo,
        DATES[5],
        &[
            "update-index",
            "--add",
            "--cacheinfo",
            &format!("160000,{c1},vendor"),
        ],
    );
    git(&repo, DATES[5], &["add", ".gitmodules", "assets/big.lfs"]);
    git(
        &repo,
        DATES[5],
        &["commit", "-q", "-m", "Add submodule pin and an LFS pointer"],
    );
    let c6 = git(&repo, DATES[5], &["rev-parse", "HEAD"]);

    // A real object in local git-lfs storage (no network, no git-lfs binary).
    let lfs_dir = repo
        .join(".git")
        .join("lfs")
        .join("objects")
        .join(&LFS_OID[0..2])
        .join(&LFS_OID[2..4]);
    std::fs::create_dir_all(&lfs_dir).expect("mkdir lfs");
    std::fs::write(lfs_dir.join(LFS_OID), LFS_BYTES).expect("write lfs object");

    // Tags: one annotated on an old commit, one annotated on the tip, one light.
    git(
        &repo,
        DATES[0],
        &["tag", "-a", "v1.0", "-m", "First release", &c1],
    );
    git(
        &repo,
        DATES[5],
        &["tag", "-a", "v2.0", "-m", "Second release"],
    );
    git(&repo, DATES[5], &["tag", "light", &c2]);

    // A bare clone, an empty repo, a plain directory and a hidden one.
    git(
        &root,
        DATES[5],
        &[
            "clone",
            "-q",
            "--bare",
            repo.to_str().expect("utf-8 repo path"),
            root.join("bare.git").to_str().expect("utf-8 bare path"),
        ],
    );
    std::fs::create_dir_all(root.join("empty")).expect("mkdir empty");
    git(&root.join("empty"), DATES[0], &["init", "-q", "-b", "main"]);
    std::fs::create_dir_all(root.join("notrepo")).expect("mkdir notrepo");
    std::fs::write(root.join("notrepo").join("x.txt"), "not a repo\n").expect("write");
    std::fs::create_dir_all(root.join(".hidden")).expect("mkdir hidden");

    // A symlink under the root pointing at a real repository *outside* it: must
    // never be listed or resolvable (the confinement property).
    let outside = tmp.join("outside");
    std::fs::create_dir_all(&outside).expect("mkdir outside");
    git(&outside, DATES[0], &["init", "-q", "-b", "main"]);
    std::os::unix::fs::symlink(&outside, root.join("escape")).expect("symlink");

    let shas = vec![c1, c2, c3, c4, c5, c6];
    assert_eq!(
        shas,
        SHAS.to_vec(),
        "the fixture recipe drifted from the goldens (or git changed its \
         object format); regenerate with tests/regen_gitcmd_goldens.py"
    );
    Fixture { tmp, root, shas }
}

fn set_executable(path: &Path) {
    use std::os::unix::fs::PermissionsExt;
    let mut perms = std::fs::metadata(path).expect("stat").permissions();
    perms.set_mode(0o755);
    std::fs::set_permissions(path, perms).expect("chmod");
}

// --------------------------------------------------------------------------- //
// The hostile fixture (mirrors `build_hostile()` in regen_views_goldens.py)
// --------------------------------------------------------------------------- //
//
// An entirely separate root, so the `gitcmd` goldens (which pin
// `discover_repos` over the main root) are unaffected. Every repository-derived
// field git will hand a view — the repo directory name, the description, a
// branch, a tag, a filename, a commit subject/body, an author name/email and a
// `.gitmodules` URL — embeds `<script>`, quotes, `&` or a `javascript:` scheme.

/// The resolvable hostile repository's directory name.
pub const HOSTILE_REPO: &str = "evil-repo";
/// A repository whose *directory name* is hostile: never resolvable
/// (`valid_repo_name` refuses it) but still listed by discovery.
pub const HOSTILE_DIR: &str = "bad<script>dir";
/// A branch name git accepts and the URL validator does not.
pub const HOSTILE_BRANCH: &str = "evil<script>";
/// A tag name git accepts and the URL validator does not.
pub const HOSTILE_TAG: &str = "v<1.0>&";
/// A filename git accepts, carrying markup and a double quote.
pub const HOSTILE_FILE: &str = "a<script>\"x\".txt";
/// The hostile commit subject.
pub const HOSTILE_SUBJECT: &str = "subject <script>alert('xss')</script> & \"quotes\"";
/// The hostile repository description.
pub const HOSTILE_DESC: &str = "desc <script>alert(\"d\")</script>\n";
/// The hostile commit body.
pub const HOSTILE_BODY: &str = "line with <b>markup</b> & 'quotes'\n";
/// A `.gitmodules` with an unlinkable (`javascript:`) and a linkable URL.
pub const HOSTILE_MODULES: &str = "[submodule \"vendor\"]\n\tpath = vendor\n\turl = javascript:alert(1)\n[submodule \"ok\"]\n\tpath = ok\n\turl = https://example.com/<x>.git\n";
/// The hostile blob's content.
pub const HOSTILE_CONTENT: &str = "<script>alert(1)</script>\nplain & \"quoted\" line\n";
/// The fixed commit date of the hostile fixture.
pub const HOSTILE_DATE: &str = "2020-06-01T00:00:00 +0000";

fn hostile_git(cwd: &Path, args: &[&str]) -> String {
    let out = Command::new("git")
        .args(args)
        .current_dir(cwd)
        .env("HOME", "/nonexistent")
        .env("GIT_CONFIG_GLOBAL", "/dev/null")
        .env("GIT_CONFIG_SYSTEM", "/dev/null")
        .env("GIT_AUTHOR_NAME", "Eve <script>")
        .env("GIT_AUTHOR_EMAIL", "eve+<x>@example.com")
        .env("GIT_COMMITTER_NAME", "Eve <script>")
        .env("GIT_COMMITTER_EMAIL", "eve+<x>@example.com")
        .env("GIT_AUTHOR_DATE", HOSTILE_DATE)
        .env("GIT_COMMITTER_DATE", HOSTILE_DATE)
        .env("TZ", "UTC")
        .stdin(Stdio::null())
        .output()
        .expect("run git");
    assert!(
        out.status.success(),
        "git {args:?} failed: {}",
        String::from_utf8_lossy(&out.stderr)
    );
    String::from_utf8_lossy(&out.stdout).trim().to_string()
}

/// A built hostile fixture; the whole tree is removed when this is dropped.
pub struct HostileFixture {
    tmp: PathBuf,
    /// The repository root the server would be configured with.
    pub root: PathBuf,
    /// The hostile repository's HEAD commit sha.
    pub head: String,
}

impl Drop for HostileFixture {
    fn drop(&mut self) {
        let _ = std::fs::remove_dir_all(&self.tmp);
    }
}

/// Build the hostile repository set. Mirrors `build_hostile()` in the generator.
pub fn build_hostile() -> HostileFixture {
    let tmp = temp_dir("hostile");
    let root = tmp.join("hostile");
    std::fs::create_dir_all(&root).expect("mkdir hostile root");
    let repo = root.join(HOSTILE_REPO);
    std::fs::create_dir_all(&repo).expect("mkdir evil repo");

    hostile_git(&repo, &["init", "-q", "-b", "main"]);
    write(&repo, ".git/description", HOSTILE_DESC);
    write(&repo, HOSTILE_FILE, HOSTILE_CONTENT);
    write(&repo, ".gitmodules", HOSTILE_MODULES);
    hostile_git(&repo, &["add", "-A"]);
    let message = format!("{HOSTILE_SUBJECT}\n\n{HOSTILE_BODY}");
    hostile_git(&repo, &["commit", "-q", "-m", &message]);
    let first = hostile_git(&repo, &["rev-parse", "HEAD"]);
    for path in ["vendor", "ok"] {
        hostile_git(
            &repo,
            &[
                "update-index",
                "--add",
                "--cacheinfo",
                &format!("160000,{first},{path}"),
            ],
        );
    }
    hostile_git(&repo, &["commit", "-q", "-m", "pin submodule"]);
    let head = hostile_git(&repo, &["rev-parse", "HEAD"]);
    hostile_git(&repo, &["branch", HOSTILE_BRANCH]);
    hostile_git(
        &repo,
        &["tag", "-a", HOSTILE_TAG, "-m", "tag <b>notes</b> & more"],
    );

    let other = root.join(HOSTILE_DIR);
    std::fs::create_dir_all(&other).expect("mkdir hostile dir");
    hostile_git(&other, &["init", "-q", "-b", "main"]);
    write(&other, ".git/description", "other <script>desc</script>\n");
    write(&other, "f.txt", "hi\n");
    hostile_git(&other, &["add", "-A"]);
    hostile_git(&other, &["commit", "-q", "-m", "c"]);

    HostileFixture { tmp, root, head }
}
