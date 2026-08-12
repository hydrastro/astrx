#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens for `gitweb::gitcmd`.

Builds a **fully deterministic** fixture repository set (fixed identity, fixed
`GIT_AUTHOR_DATE`/`GIT_COMMITTER_DATE`, fixed content, so every object id is
stable), drives the **real** Python `gitweb.gitcmd` over it and prints Rust
literals. `tests/xcheck_gitcmd.rs` builds the same fixture with the same recipe
and asserts the Rust port produces exactly these values.

    cd astrx-suite
    PYTHONPATH=legacy-python/gitweb TZ=UTC \
        python3 crates/gitweb/tests/regen_gitcmd_goldens.py

The fixture recipe lives in `build_fixture()` below and is mirrored line for
line by `fixture()` in `xcheck_gitcmd.rs`; keep the two in step. Object ids are
embedded as goldens, which is only sound because the recipe is deterministic —
`--check-determinism` builds it twice and diffs the ids.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile

# --------------------------------------------------------------------------- #
# Rust literal helpers (same as tests/regen_goldens.py)
# --------------------------------------------------------------------------- #


def rs(s: str) -> str:
    """Render `s` as a Rust `&str` literal (always escaped, never raw)."""
    out = ['"']
    for ch in s:
        o = ord(ch)
        if ch == "\\":
            out.append("\\\\")
        elif ch == '"':
            out.append('\\"')
        elif ch == "\n":
            out.append("\\n")
        elif ch == "\r":
            out.append("\\r")
        elif ch == "\t":
            out.append("\\t")
        elif o < 0x20 or o == 0x7F or 0x80 <= o <= 0x9F:
            out.append("\\u{%x}" % o)
        else:
            out.append(ch)
    out.append('"')
    return "".join(out)


def rb(b: bytes) -> str:
    """Render `b` as a Rust `&[u8]` literal."""
    out = ['b"']
    for byte in b:
        if byte == 0x5C:
            out.append("\\\\")
        elif byte == 0x22:
            out.append('\\"')
        elif byte == 0x0A:
            out.append("\\n")
        elif byte == 0x0D:
            out.append("\\r")
        elif byte == 0x09:
            out.append("\\t")
        elif 0x20 <= byte < 0x7F:
            out.append(chr(byte))
        else:
            out.append("\\x%02x" % byte)
    out.append('"')
    return "".join(out)


def rlist(items) -> str:
    """Render a list of strings as a Rust `&[&str]` literal."""
    return "&[%s]" % ", ".join(rs(x) for x in items)


# --------------------------------------------------------------------------- #
# The deterministic fixture
# --------------------------------------------------------------------------- #

README_MD = "# Fixture\n\nSome **bold** text and a <script> tag.\n"
MAIN_PY = (
    "#!/usr/bin/env python3\n"
    'print("<hello> & \'world\'")\n'
    "value = 1 < 2 and 3 > 2\n"
)
MAIN_PY_V2 = MAIN_PY + (
    "UNIQUE_NEEDLE_TOKEN = 1\n"
    "--option-like-needle = 2\n"
    "danger = \"<script>alert(1)</script>\"\n"
)
FEATURE_TXT = "feature branch work\n"
GUIDE_MD = "# Guide\n\n| a | b |\n| - | - |\n| 1 | 2 |\n"
BINARY = b"\x89PNG\r\n\x00\x00fake-binary\x00\xff\xfe\x01\x02payload"
COLON_TXT = "colon path line\nUNIQUE_NEEDLE_TOKEN in a colon path\n"
LFS_OID = "1111111111111111111111111111111111111111111111111111111111111111"
LFS_POINTER = (
    "version https://git-lfs.github.com/spec/v1\n"
    f"oid sha256:{LFS_OID}\n"
    "size 12345\n"
)
GITMODULES = '[submodule "vendor"]\n\tpath = vendor\n\turl = https://example.com/vendor.git\n'
DESCRIPTION = "The cross-check fixture repository.\n"
# A real object in local git-lfs storage, so `lfs_object_path` has one to find.
LFS_BYTES = b"REAL LFS OBJECT CONTENT\n"
# A filename git accepts but UTF-8 does not: proves the lossy decode policy.
BAD_NAME = b"bad\xffname.txt"

DATES = [
    "2020-01-01T00:00:00 +0000",
    "2020-01-02T00:00:00 +0000",
    "2020-01-03T00:00:00 +0000",
    "2020-01-04T00:00:00 +0000",
    "2020-01-05T00:00:00 +0000",
    "2020-01-06T00:00:00 +0000",
]


def _env(date: str) -> dict:
    return dict(
        os.environ,
        HOME="/nonexistent",
        GIT_CONFIG_GLOBAL=os.devnull,
        GIT_CONFIG_SYSTEM=os.devnull,
        GIT_AUTHOR_NAME="Test Author",
        GIT_AUTHOR_EMAIL="author@example.com",
        GIT_COMMITTER_NAME="Test Author",
        GIT_COMMITTER_EMAIL="author@example.com",
        GIT_AUTHOR_DATE=date,
        GIT_COMMITTER_DATE=date,
        TZ="UTC",
    )


def _git(cwd: str, date: str, *args: str) -> str:
    out = subprocess.run(
        ["git", *args], cwd=cwd, env=_env(date), check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    return out.stdout.decode("utf-8", "replace").strip()


def _write(base: str, rel: str, text: str) -> None:
    path = os.path.join(base, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)


def _write_bytes(base: str, rel: str, data: bytes) -> None:
    path = os.path.join(base, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "wb") as fh:
        fh.write(data)


def build_fixture(root: str) -> dict:
    """Create the repository set under `root`; returns the interesting shas.

    Mirrored by `fixture()` in `xcheck_gitcmd.rs`.
    """
    os.makedirs(root, exist_ok=True)
    repo = os.path.join(root, "xrepo")
    os.makedirs(repo)

    _git(repo, DATES[0], "init", "-q", "-b", "main")
    _git(repo, DATES[0], "config", "user.name", "Test Author")
    _git(repo, DATES[0], "config", "user.email", "author@example.com")
    _write(repo, ".git/description", DESCRIPTION)

    # c1 — README, sources, and names that exercise the tree sort.
    _write(repo, "README.md", README_MD)
    _write(repo, "src/main.py", MAIN_PY)
    _write(repo, "Zebra.txt", "zebra\n")
    _write(repo, "apple.txt", "apple\n")
    _write(repo, "zdir/inner.txt", "inner\n")
    _write(repo, "run.sh", "#!/bin/sh\necho hi\n")
    os.chmod(os.path.join(repo, "run.sh"), 0o755)
    _write(repo, "weird dir/a:b.txt", COLON_TXT)
    with open(os.path.join(repo.encode(), b"weird dir", BAD_NAME), "wb") as fh:
        fh.write(b"non-utf8 name\n")
    _git(repo, DATES[0], "add", "-A")
    _git(repo, DATES[0], "commit", "-q", "-m", "Add README and sources")
    c1 = _git(repo, DATES[0], "rev-parse", "HEAD")

    # c2 — edit the source, add a binary asset.
    _write(repo, "src/main.py", MAIN_PY_V2)
    _write_bytes(repo, "assets/logo.bin", BINARY)
    _git(repo, DATES[1], "add", "-A")
    _git(repo, DATES[1], "commit", "-q", "-m", "Extend main.py and add a binary")
    c2 = _git(repo, DATES[1], "rev-parse", "HEAD")

    # c3 — a branch with its own commit.
    _git(repo, DATES[2], "checkout", "-q", "-b", "feature")
    _write(repo, "feature.txt", FEATURE_TXT)
    _git(repo, DATES[2], "add", "-A")
    _git(repo, DATES[2], "commit", "-q", "-m", "Feature branch work")
    c3 = _git(repo, DATES[2], "rev-parse", "HEAD")

    # c4 — main moves on.
    _git(repo, DATES[3], "checkout", "-q", "main")
    _write(repo, "docs/guide.md", GUIDE_MD)
    _git(repo, DATES[3], "add", "-A")
    _git(repo, DATES[3], "commit", "-q", "-m", "Add guide mentioning SEARCHKEYWORD")
    c4 = _git(repo, DATES[3], "rev-parse", "HEAD")

    # c5 — a real two-parent merge.
    _git(repo, DATES[4], "merge", "-q", "--no-ff", "feature", "-m", "Merge feature into main")
    c5 = _git(repo, DATES[4], "rev-parse", "HEAD")

    # c6 — a submodule gitlink + .gitmodules and an LFS pointer.
    _write(repo, ".gitmodules", GITMODULES)
    _write(repo, "assets/big.lfs", LFS_POINTER)
    _git(repo, DATES[5], "update-index", "--add", "--cacheinfo", f"160000,{c1},vendor")
    _git(repo, DATES[5], "add", ".gitmodules", "assets/big.lfs")
    _git(repo, DATES[5], "commit", "-q", "-m", "Add submodule pin and an LFS pointer")
    c6 = _git(repo, DATES[5], "rev-parse", "HEAD")

    # A real object in local git-lfs storage (no network, no git-lfs binary).
    lfs_dir = os.path.join(repo, ".git", "lfs", "objects", LFS_OID[0:2], LFS_OID[2:4])
    os.makedirs(lfs_dir, exist_ok=True)
    with open(os.path.join(lfs_dir, LFS_OID), "wb") as fh:
        fh.write(LFS_BYTES)

    # Tags: one annotated on an old commit, one annotated on the tip, one light.
    _git(repo, DATES[0], "tag", "-a", "v1.0", "-m", "First release", c1)
    _git(repo, DATES[5], "tag", "-a", "v2.0", "-m", "Second release")
    _git(repo, DATES[5], "tag", "light", c2)

    # A bare clone, an empty repo, a plain directory and a hidden one.
    subprocess.run(
        ["git", "clone", "-q", "--bare", repo, os.path.join(root, "bare.git")],
        check=True, env=_env(DATES[5]), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    os.makedirs(os.path.join(root, "empty"))
    _git(os.path.join(root, "empty"), DATES[0], "init", "-q", "-b", "main")
    os.makedirs(os.path.join(root, "notrepo"))
    with open(os.path.join(root, "notrepo", "x.txt"), "w") as fh:
        fh.write("not a repo\n")
    os.makedirs(os.path.join(root, ".hidden"))

    # A symlink under the root pointing at a real repository *outside* it: must
    # never be listed or resolvable (the confinement property).
    outside = os.path.join(os.path.dirname(root), "outside")
    os.makedirs(outside, exist_ok=True)
    _git(outside, DATES[0], "init", "-q", "-b", "main")
    os.symlink(outside, os.path.join(root, "escape"))

    return {"c1": c1, "c2": c2, "c3": c3, "c4": c4, "c5": c5, "c6": c6}


# --------------------------------------------------------------------------- #
# Golden sections
# --------------------------------------------------------------------------- #


def gen_pure() -> None:
    from gitweb import gitcmd

    print("// ==== gitcmd: valid_repo_name / valid_ref / valid_path / valid_query ====")
    names = [
        "myrepo", "my.repo", "my_repo-2", "", ".", "..", "-repo", "a/b", "a b",
        "a;b", "a|b", "a$b", "a`b", "a\nb", "a\\b", "réal", "a" * 300, "..hidden",
        ".hidden", "repo.git", "--upload-pack=/tmp/x",
    ]
    for n in names:
        print("    (%s, %s)," % (rs(n), str(gitcmd.valid_repo_name(n)).lower()))
    print("// ---- refs ----")
    refs = [
        "main", "v1.0", "feature/x", "a+b", "a-b", "a_b", "HEAD", "", "-main",
        "/main", ".main", "main/", "a..b", "a@{0}", "refs/heads/main.lock",
        "a:b", "a b", "a;b", "a|b", "a$(id)b", "a`id`b", "a\nb", "a*b", "a?b",
        "a[b", "a^b", "a~b", "x" * 256, "x" * 257, "--upload-pack=/tmp/x",
        "refs/heads/", "café", "0123456789abcdef0123456789abcdef01234567",
    ]
    for r in refs:
        print("    (%s, %s)," % (rs(r), str(gitcmd.valid_ref(r)).lower()))
    print("// ---- paths ----")
    paths = [
        "", "a", "a/b/c.txt", "a b/c.txt", "a:b.txt", "a;b", "a|b", "a$(id)",
        "a`id`", "/etc/passwd", "-rf", "../etc/passwd", "a/../b", "a/..", "../",
        "a/./b", "..a", "a..b", "a\x00b", "a\nb", "a\tb", "a\x1fb", "x" * 4096,
        "x" * 4097, "café/naïve.txt", "--output=/tmp/x",
    ]
    for p in paths:
        print("    (%s, %s)," % (rs(p), str(gitcmd.valid_path(p)).lower()))
    print("// ---- queries ----")
    queries = [
        "", "needle", "--looks-like-an-option", "a;rm -rf /", "$(id)", "`id`",
        "a\nb", "a\x00b", "x" * 512, "x" * 513, "café",
    ]
    for q in queries:
        print("    (%s, %s)," % (rs(q), str(gitcmd.valid_query(q)).lower()))

    print("// ==== gitcmd::object_spec (ref, path, spec) ====")
    for r, p in [("main", ""), ("main", "a/b.txt"), ("v1.0", "src"), ("abc123", "x")]:
        print("    (%s, %s, %s)," % (rs(r), rs(p), rs(gitcmd.object_spec(r, p))))

    print("// ==== gitcmd::_parse_batch_header (line, sha, type, size) | None ====")
    lines = [
        b"", b"\n", b"deadbeef missing\n", b"deadbeef ambiguous\n",
        b"e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 blob 0\n",
        b"e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 blob 12345\n",
        b"e69de29bb2d1d6434b8b29ae775ad8c2e48c5391   tree   42  \n",
        b"only two\n", b"a b c d\n", b"abc blob notanumber\n",
        b"abc blob -1\n", b"abc\xffdef blob 7\n", b"abc commit 0",
    ]
    for line in lines:
        st = gitcmd._parse_batch_header(line)
        if st is None:
            print("    (%s, None)," % rb(line))
        else:
            print(
                "    (%s, Some((%s, %s, %d)))," % (rb(line), rs(st.sha), rs(st.type), st.size)
            )

    print("// ==== gitcmd::_parse_gitmodules (text, [(path, url)]) ====")
    texts = [
        GITMODULES,
        "",
        "[submodule \"a\"]\npath = a\nurl = u1\n[submodule \"b\"]\n\tPATH = b\n\tURL = u2\n",
        "path = orphan\nurl = ou\n",
        "[submodule \"a\"]\npath = a\n",
        "[submodule \"a\"]\nurl = only-url\n",
        "[submodule \"a\"]\npath = dup\nurl = first\n[submodule \"b\"]\npath = dup\nurl = second\n",
        "  [ submodule ]  \n  path  =  spaced  \n  url  =  spaced-url  \n",
        "path=a=b\nurl=http://x/?a=b\n",
        "[x]\npath = p\n[y]\n",
        "no equals here\n",
    ]
    for t in texts:
        mapping = gitcmd._parse_gitmodules(t)
        pairs = ", ".join("(%s, %s)" % (rs(k), rs(v)) for k, v in mapping.items())
        print("    (%s, &[%s])," % (rs(t), pairs))

    print("// ==== gitcmd::parse_lfs_pointer (data, oid, size) | None ====")
    pointers = [
        LFS_POINTER.encode(),
        b"version https://git-lfs.github.com/spec/v1\noid sha256:not-hex\nsize 5\n",
        b"version https://git-lfs.github.com/spec/v1\nsize 5\n",
        b"version https://git-lfs.github.com/spec/v1\noid sha256:" + LFS_OID.encode() + b"\n",
        b"not a pointer at all\n",
        b"",
        b"version https://git-lfs.github.com/spec/v1\noid sha256:" + LFS_OID.encode()
        + b"\nsize 0\n",
        b"version https://git-lfs.github.com/spec/v1\noid sha256:" + LFS_OID.upper().encode()
        + b"\nsize 1\n",
        ("version https://git-lfs.github.com/spec/v1\noid sha256:%s\nsize 7\n" % LFS_OID).encode()
        + b"x" * 2000,
        b"version https://git-lfs.github.com/spec/v1\noid sha256:" + LFS_OID.encode()
        + b"\nsize \xff9\n",
        MAIN_PY.encode(),
    ]
    for data in pointers:
        p = gitcmd.parse_lfs_pointer(data)
        if p is None:
            print("    (%s, None)," % rb(data))
        else:
            print("    (%s, Some((%s, %d)))," % (rb(data), rs(p.oid), p.size))

    print("// ==== gitcmd::_text — the decode policy (bytes, text) ====")
    blobs = [
        b"plain ascii", b"caf\xc3\xa9", b"\xff", b"\x80", b"\xc3", b"\xc3\x28",
        b"\xe2\x82", b"\xe2\x82\x28", b"\xf0\x9f\x92", b"\xed\xa0\x80",
        b"\xf4\x90\x80\x80", b"\xc0\x80", b"abc\xffdef", b"\xf8\x88\x80\x80\x80",
        b"a\x00b", b"",
    ]
    for b in blobs:
        print("    (%s, %s)," % (rb(b), rs(b.decode("utf-8", "replace"))))

    print("// ==== gitcmd::is_binary (data, is_binary) ====")
    for b in [b"", b"text", b"a\x00b", b"x" * 8192 + b"\x00", b"x" * 8191 + b"\x00", BINARY]:
        print("    (%s, %s)," % (rb(b), str(gitcmd.is_binary(b)).lower()))

    print("// ==== gitcmd: the caps ====")
    print("    DEFAULT_TIMEOUT=%d" % gitcmd.DEFAULT_TIMEOUT)
    print("    DEFAULT_MAX_BYTES=%d" % gitcmd.DEFAULT_MAX_BYTES)
    print("    MAX_STDERR_BYTES=%d" % gitcmd.MAX_STDERR_BYTES)
    print("    MAX_QUERY_BYTES=%d" % gitcmd.MAX_QUERY_BYTES)
    print("    GREP_TIMEOUT=%d" % gitcmd.GREP_TIMEOUT)
    print("    GREP_MAX_BYTES=%d" % gitcmd.GREP_MAX_BYTES)
    print("    GREP_MAX_MATCHES=%d" % gitcmd.GREP_MAX_MATCHES)
    print("    GREP_MAX_COUNT_PER_FILE=%d" % gitcmd.GREP_MAX_COUNT_PER_FILE)
    print("    PATCH_TIMEOUT=%d" % gitcmd.PATCH_TIMEOUT)
    print("    PATCH_MAX_BYTES=%d" % gitcmd.PATCH_MAX_BYTES)
    print("    UPLOAD_PACK_TIMEOUT=%d" % gitcmd.UPLOAD_PACK_TIMEOUT)
    print("    UPLOAD_PACK_ADVERTISE_MAX_BYTES=%d" % gitcmd.UPLOAD_PACK_ADVERTISE_MAX_BYTES)
    print("    FIELD_SEP=%s" % rs(gitcmd.FIELD_SEP))
    print("    ALLOWED_SUBCOMMANDS=%s" % rlist(sorted(gitcmd.ALLOWED_SUBCOMMANDS)))


def gen_repo(root: str, shas: dict) -> None:
    from gitweb import gitcmd

    print("// ==== fixture commit ids ====")
    for key in ("c1", "c2", "c3", "c4", "c5", "c6"):
        print("    %s = %s" % (key, rs(shas[key])))

    print("// ==== gitcmd::discover_repos (name, bare, description, last_commit_ts) ====")
    for repo in gitcmd.discover_repos(root):
        print(
            "    (%s, %s, %s, %s),"
            % (
                rs(repo.name),
                str(repo.bare).lower(),
                rs(repo.description),
                "Some(%d)" % repo.last_commit_ts if repo.last_commit_ts is not None else "None",
            )
        )

    repo = gitcmd.resolve_repo(root, "xrepo")
    bare = gitcmd.resolve_repo(root, "bare.git")
    empty = gitcmd.resolve_repo(root, "empty")

    print("// ==== gitcmd::resolve_repo errors (name, kind, message) ====")
    for name in [
        "..", ".", "", "-x", "no_such_repo", "../outside", "escape", "notrepo",
        "xrepo/../xrepo", "/etc", "xrepo\x00", ".hidden",
    ]:
        try:
            got = gitcmd.resolve_repo(root, name)
            print("    (%s, %s, %s)," % (rs(name), rs("ok"), rs(got.name)))
        except gitcmd.BadRequest as exc:
            print("    (%s, %s, %s)," % (rs(name), rs("bad_request"), rs(str(exc))))
        except gitcmd.NotFound as exc:
            print("    (%s, %s, %s)," % (rs(name), rs("not_found"), rs(str(exc))))

    print("// ==== gitcmd::default_branch ====")
    for label, r in (("xrepo", repo), ("bare.git", bare), ("empty", empty)):
        print("    (%s, %s)," % (rs(label), rs(gitcmd.default_branch(r))))

    print("// ==== gitcmd::ref_names (branches, tags) ====")
    branches_, tags_ = gitcmd.ref_names(repo)
    print("    branches = %s" % rlist(branches_))
    print("    tags = %s" % rlist(tags_))

    print("// ==== gitcmd::branches (name, kind, target, subject, ts, author) ====")
    for row in gitcmd.branches(repo):
        print(
            "    (%s, %s, %s, %s, %d, %s),"
            % (rs(row.name), rs(row.kind), rs(row.target), rs(row.subject), row.ts, rs(row.author))
        )
    print("// ==== gitcmd::tags ====")
    for row in gitcmd.tags(repo):
        print(
            "    (%s, %s, %s, %s, %d, %s),"
            % (rs(row.name), rs(row.kind), rs(row.target), rs(row.subject), row.ts, rs(row.author))
        )

    print("// ==== gitcmd::ref_exists (ref, exists) ====")
    for r in ["main", "feature", "v1.0", "v2.0", "light", "HEAD", "nope", shas["c1"],
              shas["c1"][:8]]:
        print("    (%s, %s)," % (rs(r), str(gitcmd.ref_exists(repo, r)).lower()))

    print("// ==== gitcmd::log main (sha, short, author, email, ts, subject) ====")
    for row in gitcmd.log(repo, "main", 0, 50):
        print(
            "    (%s, %s, %s, %s, %d, %s),"
            % (rs(row.sha), rs(row.short), rs(row.author), rs(row.email), row.ts, rs(row.subject))
        )
    print("// ==== gitcmd::log main skip=1 limit=2 (sha) ====")
    print("    %s" % rlist([row.sha for row in gitcmd.log(repo, "main", 1, 2)]))
    print("// ==== gitcmd::commit_count ====")
    print("    main=%d" % gitcmd.commit_count(repo, "main"))
    print("    feature=%d" % gitcmd.commit_count(repo, "feature"))
    print("    v1.0=%d" % gitcmd.commit_count(repo, "v1.0"))

    print("// ==== gitcmd::log_graph main (sha, short, parents, author, ts, subject) ====")
    for row in gitcmd.log_graph(repo, "main", 0, 50):
        print(
            "    (%s, %s, %s, %s, %d, %s),"
            % (rs(row.sha), rs(row.short), rlist(row.parents), rs(row.author), row.ts,
               rs(row.subject))
        )

    print("// ==== gitcmd::log_path src/main.py (sha, subject) ====")
    for row in gitcmd.log_path(repo, "main", "src/main.py", 0, 50, False):
        print("    (%s, %s)," % (rs(row.sha), rs(row.subject)))
    print("// ==== gitcmd::commit_count_path ====")
    print("    src/main.py=%d" % gitcmd.commit_count_path(repo, "main", "src/main.py"))
    print("    docs=%d" % gitcmd.commit_count_path(repo, "main", "docs"))
    print("    nope=%d" % gitcmd.commit_count_path(repo, "main", "no/such/path"))

    print("// ==== gitcmd::commit_meta (13 fields) ====")
    for rev in (shas["c2"], shas["c5"], "v2.0"):
        c = gitcmd.commit_meta(repo, rev)
        print(
            "    (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s),"
            % (
                rs(rev), rs(c.sha), rs(c.short), rs(c.author_name), rs(c.author_email),
                rs(c.author_date), rs(c.committer_name), rs(c.committer_email),
                rs(c.committer_date), rlist(c.parents), rs(c.subject), rs(c.body),
                rs(c.signature_status), rs(c.signing_key),
                str(c.signature_verified).lower(),
            )
        )

    print("// ==== gitcmd::commit_patch c2 ====")
    print("    %s" % rs(gitcmd.commit_patch(repo, shas["c2"])))
    print("// ==== gitcmd::commit_patch c1 (initial commit) ====")
    print("    %s" % rs(gitcmd.commit_patch(repo, shas["c1"])))

    print("// ==== gitcmd::format_patch c2 (git version line normalised) ====")
    patch = gitcmd.format_patch(repo, shas["c2"])
    version = subprocess.run(
        ["git", "--version"], check=True, stdout=subprocess.PIPE
    ).stdout.decode().strip().split()[-1]
    print("    %s" % rb(patch.replace(version.encode(), b"<GIT-VERSION>")))

    print("// ==== gitcmd::compare v1.0..main ====")
    print("    %s" % rs(gitcmd.compare(repo, "v1.0", "main")))

    print("// ==== gitcmd::list_tree root (mode, type, sha, size, name, path) ====")
    for e in gitcmd.list_tree(repo, "main", ""):
        print(
            "    (%s, %s, %s, %s, %s, %s),"
            % (rs(e.mode), rs(e.type), rs(e.sha),
               "Some(%d)" % e.size if e.size is not None else "None", rs(e.name), rs(e.path))
        )
    print("// ==== gitcmd::list_tree src ====")
    for e in gitcmd.list_tree(repo, "main", "src"):
        print(
            "    (%s, %s, %s, %s, %s, %s),"
            % (rs(e.mode), rs(e.type), rs(e.sha),
               "Some(%d)" % e.size if e.size is not None else "None", rs(e.name), rs(e.path))
        )
    print("// ==== gitcmd::list_tree 'weird dir' ====")
    for e in gitcmd.list_tree(repo, "main", "weird dir"):
        print(
            "    (%s, %s, %s, %s, %s, %s),"
            % (rs(e.mode), rs(e.type), rs(e.sha),
               "Some(%d)" % e.size if e.size is not None else "None", rs(e.name), rs(e.path))
        )

    print("// ==== gitcmd::stat_object (ref, path, sha, type, size) | None ====")
    specs = [
        ("main", ""), ("main", "README.md"), ("main", "src"), ("main", "src/main.py"),
        ("main", "vendor"), ("main", "no/such/file"), ("v1.0", "README.md"),
        ("main", "assets/logo.bin"),
    ]
    for r, p in specs:
        st = gitcmd.stat_object(repo, r, p)
        if st is None:
            print("    (%s, %s, None)," % (rs(r), rs(p)))
        else:
            print(
                "    (%s, %s, Some((%s, %s, %d)))," % (rs(r), rs(p), rs(st.sha), rs(st.type),
                                                       st.size)
            )

    print("// ==== gitcmd::object_type / blob_size ====")
    for r, p in specs:
        otype = gitcmd.object_type(repo, r, p)
        print(
            "    (%s, %s, %s, %d),"
            % (rs(r), rs(p), "Some(%s)" % rs(otype) if otype is not None else "None",
               gitcmd.blob_size(repo, r, p))
        )

    print("// ==== gitcmd::read_blob README.md / capped at 8 ====")
    print("    %s" % rb(gitcmd.read_blob(repo, "main", "README.md", 1 << 20)))
    print("    %s" % rb(gitcmd.read_blob(repo, "main", "README.md", 8)))
    print("    %s" % rb(gitcmd.read_blob(repo, "main", "assets/logo.bin", 1 << 20)))
    print("// ==== gitcmd::peek_blob (n=8192, n=4) ====")
    print("    %s" % rb(gitcmd.peek_blob(repo, "main", "src/main.py", 8192)))
    print("    %s" % rb(gitcmd.peek_blob(repo, "main", "src/main.py", 4)))

    print("// ==== gitcmd::read_gitmodules ====")
    print("    %s" % rlist(sum(([k, v] for k, v in gitcmd.read_gitmodules(repo, "main").items()),
                               [])))

    print("// ==== gitcmd::resolve_commit (ref, sha) ====")
    for r in ["main", "feature", "v1.0", "v2.0", "light", "nope", shas["c1"][:8]]:
        print("    (%s, %s)," % (rs(r), rs(gitcmd.resolve_commit(repo, r))))

    print("// ==== gitcmd::blame src/main.py (short, author, lineno, content) ====")
    for line in gitcmd.blame(repo, "main", "src/main.py"):
        print(
            "    (%s, %s, %d, %s)," % (rs(line.short), rs(line.author), line.lineno,
                                       rs(line.content))
        )

    print("// ==== gitcmd::search_code (path, lineno, text) + truncated ====")
    for query in ["UNIQUE_NEEDLE_TOKEN", "--option-like-needle", "zzz_no_such_zzz", "<script>"]:
        matches, more = gitcmd.search_code(repo, "main", query)
        print("    query=%s more=%s" % (rs(query), str(more).lower()))
        for m in matches:
            print("      (%s, %d, %s)," % (rs(m.path), m.lineno, rs(m.text)))
    print("// ==== gitcmd::search_code max_matches=1 ====")
    matches, more = gitcmd.search_code(repo, "main", "UNIQUE_NEEDLE_TOKEN", max_matches=1)
    print("    len=%d more=%s" % (len(matches), str(more).lower()))

    print("// ==== gitcmd::log_grep / commit_count_grep ====")
    for query in ["SEARCHKEYWORD", "Merge", "zzz_none"]:
        rows = gitcmd.log_grep(repo, "main", query, 0, 50)
        print(
            "    (%s, %s, %d),"
            % (rs(query), rlist([r.sha for r in rows]),
               gitcmd.commit_count_grep(repo, "main", query))
        )

    print("// ==== gitcmd::log on an empty repo (raises NotFound) ====")
    try:
        gitcmd.log(empty, "main", 0, 50)
        print("    ok")
    except gitcmd.NotFound as exc:
        print("    not_found=%s" % rs(str(exc)))
    print("// ==== gitcmd::commit_count on an empty repo ====")
    print("    %d" % gitcmd.commit_count(empty, "main"))
    print("// ==== gitcmd::branches/tags on an empty repo ====")
    print("    branches=%d tags=%d" % (len(gitcmd.branches(empty)), len(gitcmd.tags(empty))))

    print("// ==== gitcmd: the bare clone sees the same tip ====")
    print("    bare_log0=%s" % rs(gitcmd.log(bare, "main", 0, 1)[0].sha))
    print("    bare_default_branch=%s" % rs(gitcmd.default_branch(bare)))

    print("// ==== gitcmd::lfs_object_path (oid, found) ====")
    oids = [
        LFS_OID, "0" * 64, "z" * 64, LFS_OID[:-1], "../../../../etc/passwd",
        "A" * 64, "abcdef0123456789" * 4, "1" * 63 + "g",
    ]
    for oid in oids:
        print("    (%s, %s)," % (rs(oid), str(gitcmd.lfs_object_path(repo, oid) is not None).lower()))
    found = gitcmd.lfs_object_path(repo, LFS_OID)
    print("    confined=%s" % str(
        os.path.realpath(found).startswith(os.path.realpath(repo.path) + os.sep)).lower())
    print("    size=%d" % gitcmd.lfs_object_size(found))
    print("    read_all=%s" % rb(gitcmd.read_file(found, 1 << 20)))
    print("    read_5=%s" % rb(gitcmd.read_file(found, 5)))
    print("    read_0=%s" % rb(gitcmd.read_file(found, 0)))
    print("    peek=%s" % rb(gitcmd.peek_file(found)))
    print("    missing_size=%d" % gitcmd.lfs_object_size(os.path.join(root, "nope")))
    print("    missing_read=%s" % rb(gitcmd.read_file(os.path.join(root, "nope"), 10)))
    print("    stream=%s" % rb(b"".join(gitcmd.stream_file(found, chunk_size=7))))
    print("    stream_capped=%s" % rb(b"".join(gitcmd.stream_file(found, chunk_size=7,
                                                                 max_bytes=10))))

    print("// ==== gitcmd::read_blob on repo-derived tree paths (path, Ok(bytes) | NotFound) ====")
    # The lossily decoded non-UTF-8 name cannot address its own blob again (the
    # replacement character is not the byte git stored), so it 404s rather than
    # reading the wrong object — the same in both ports.
    for e in gitcmd.list_tree(repo, "main", "weird dir"):
        if e.type != "blob":
            continue
        try:
            print("    (%s, Some(%s))," % (rs(e.path), rb(gitcmd.read_blob(repo, "main", e.path,
                                                                          1 << 20))))
        except gitcmd.NotFound:
            print("    (%s, None)," % rs(e.path))

    print("// ==== gitcmd: a spec with a control character is refused, no bleed ====")
    cf = gitcmd._catfile(repo.path)
    for spec in ["main:normal", "main:readme\nHACK.txt", "main:a\x7fb", "main:a\x1fb"]:
        print("    (%s, %s)," % (rs(spec), str(cf._spec_ok(spec)).lower()))


def main() -> None:
    tmp = tempfile.mkdtemp(prefix="gw-gitcmd-xcheck-")
    try:
        root = os.path.join(tmp, "repos")
        shas = build_fixture(root)
        if "--check-determinism" in sys.argv:
            tmp2 = tempfile.mkdtemp(prefix="gw-gitcmd-xcheck2-")
            try:
                shas2 = build_fixture(os.path.join(tmp2, "repos"))
            finally:
                shutil.rmtree(tmp2, ignore_errors=True)
            if shas != shas2:
                raise SystemExit("FIXTURE IS NOT DETERMINISTIC: %r != %r" % (shas, shas2))
            print("fixture is deterministic: %r" % (shas,))
            return
        gen_pure()
        gen_repo(root, shas)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    main()
