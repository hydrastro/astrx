#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens for `gitweb::views`.

Renders **every** server-rendered view with the real Python `gitweb.views` over
two deterministic fixtures and prints Rust literals; `tests/xcheck_views.rs`
renders the same views with the Rust port over the same repositories and asserts
the HTML matches byte for byte.

    cd astrx-suite
    PYTHONPATH=legacy-python/gitweb TZ=UTC \
        python3 crates/gitweb/tests/regen_views_goldens.py \
        > crates/gitweb/tests/goldens/views.rs

The output *is* the golden file (a generated Rust source `xcheck_views.rs`
`include!`s), so regenerating is a single redirect and the diff is reviewable.

The main fixture is `build_fixture()` from `regen_gitcmd_goldens.py`, reused
verbatim (and mirrored line for line by `tests/common/mod.rs::build()`), so the
two ports genuinely render the same objects. A second, independent root holds a
deliberately **hostile** repository — a repo directory name, a branch, a tag, a
filename, a commit subject, a description and a `.gitmodules` URL that all embed
`<script>`, quotes and a `javascript:` scheme — and is mirrored by
`tests/common/mod.rs::build_hostile()`.

Two things are pinned so a view is a pure function of its inputs:

* ``views.relative_date`` is bound to a fixed ``now`` (the reference reads
  ``time.time()``); the Rust port takes the same value through ``views::Ctx``.
* The inline stylesheet is elided from every golden as ``<style>@CSS@</style>``
  — it is a constant, compared once on its own — which keeps the literals below
  readable without weakening the byte-for-byte comparison.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from regen_gitcmd_goldens import build_fixture, rs  # noqa: E402

from gitweb import gitcmd, markup, views  # noqa: E402

#: The clock every ``relative_date`` in a golden is measured against
#: (2021-01-01T00:00:00Z), a year after the newest fixture commit.
NOW = 1609459200.0

#: A base origin for the feeds / OpenSearch descriptors.
BASE = "http://127.0.0.1:8801"

HEADER = """\
// GENERATED FILE — do not edit by hand.
//
// The byte-identical `gitweb::views` cross-check corpus: every view rendered by
// the **real** Python `gitweb.views` over the deterministic fixture repositories
// built by `tests/common/mod.rs`. Regenerate with:
//
// ```text
// cd astrx-suite
// PYTHONPATH=legacy-python/gitweb TZ=UTC \\
//     python3 crates/gitweb/tests/regen_views_goldens.py \\
//     > crates/gitweb/tests/goldens/views.rs
// ```
//
// The constant inline stylesheet is elided from each document as
// `<style>@CSS@</style>` (it is compared once, on its own, as `PY_CSS`), which
// keeps the literals below a readable size without weakening the comparison."""


def elide(html: str) -> str:
    """Replace the constant inline stylesheet with a marker."""
    return html.replace("<style>%s</style>" % views.CSS, "<style>@CSS@</style>")


def emit(name: str, html: str) -> None:
    print("    (%s, %s)," % (rs(name), rs(elide(html))))


# --------------------------------------------------------------------------- #
# The hostile fixture (mirrored by tests/common/mod.rs::build_hostile)
# --------------------------------------------------------------------------- #

HOSTILE_REPO = "evil-repo"
HOSTILE_DIR = 'bad<script>dir'
HOSTILE_BRANCH = "evil<script>"
HOSTILE_TAG = "v<1.0>&"
HOSTILE_FILE = 'a<script>"x".txt'
HOSTILE_SUBJECT = "subject <script>alert('xss')</script> & \"quotes\""
HOSTILE_DESC = 'desc <script>alert("d")</script>\n'
HOSTILE_BODY = "line with <b>markup</b> & 'quotes'\n"
HOSTILE_MODULES = (
    '[submodule "vendor"]\n\tpath = vendor\n\turl = javascript:alert(1)\n'
    '[submodule "ok"]\n\tpath = ok\n\turl = https://example.com/<x>.git\n'
)
HOSTILE_DATE = "2020-06-01T00:00:00 +0000"


def _hgit(cwd, *args):
    env = dict(os.environ)
    env.update(
        {
            "HOME": "/nonexistent",
            "GIT_CONFIG_GLOBAL": os.devnull,
            "GIT_CONFIG_SYSTEM": os.devnull,
            "GIT_AUTHOR_NAME": "Eve <script>",
            "GIT_AUTHOR_EMAIL": "eve+<x>@example.com",
            "GIT_COMMITTER_NAME": "Eve <script>",
            "GIT_COMMITTER_EMAIL": "eve+<x>@example.com",
            "GIT_AUTHOR_DATE": HOSTILE_DATE,
            "GIT_COMMITTER_DATE": HOSTILE_DATE,
            "TZ": "UTC",
        }
    )
    subprocess.run(
        ["git", *args], cwd=cwd, env=env, check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )


def build_hostile(root: str) -> str:
    """Build the hostile repository set under `root`; return its commit sha."""
    os.makedirs(root, exist_ok=True)
    repo = os.path.join(root, HOSTILE_REPO)
    os.makedirs(repo)
    _hgit(repo, "init", "-q", "-b", "main")
    with open(os.path.join(repo, ".git", "description"), "w") as fh:
        fh.write(HOSTILE_DESC)
    with open(os.path.join(repo, HOSTILE_FILE), "w") as fh:
        fh.write("<script>alert(1)</script>\nplain & \"quoted\" line\n")
    with open(os.path.join(repo, ".gitmodules"), "w") as fh:
        fh.write(HOSTILE_MODULES)
    _hgit(repo, "add", "-A")
    _hgit(repo, "commit", "-q", "-m", HOSTILE_SUBJECT + "\n\n" + HOSTILE_BODY)
    sha = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=repo, check=True,
        stdout=subprocess.PIPE,
    ).stdout.decode().strip()
    _hgit(repo, "update-index", "--add", "--cacheinfo", "160000,%s,vendor" % sha)
    _hgit(repo, "update-index", "--add", "--cacheinfo", "160000,%s,ok" % sha)
    _hgit(repo, "commit", "-q", "-m", "pin submodule")
    sha = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=repo, check=True,
        stdout=subprocess.PIPE,
    ).stdout.decode().strip()
    _hgit(repo, "branch", HOSTILE_BRANCH)
    _hgit(repo, "tag", "-a", HOSTILE_TAG, "-m", "tag <b>notes</b> & more")
    # A repository whose *directory name* is hostile: it can never be resolved
    # (`valid_repo_name` refuses it) but discovery still lists it on the home page.
    other = os.path.join(root, HOSTILE_DIR)
    os.makedirs(other)
    _hgit(other, "init", "-q", "-b", "main")
    with open(os.path.join(other, ".git", "description"), "w") as fh:
        fh.write('other <script>desc</script>\n')
    with open(os.path.join(other, "f.txt"), "w") as fh:
        fh.write("hi\n")
    _hgit(other, "add", "-A")
    _hgit(other, "commit", "-q", "-m", "c")
    return sha


# --------------------------------------------------------------------------- #
# README helper (the port of GitwebHandler._readme)
# --------------------------------------------------------------------------- #


def readme_of(repo, ref, path):
    try:
        entries = gitcmd.list_tree(repo, ref, path)
    except Exception:
        return None, None
    target = None
    for entry in entries:
        if not gitcmd.valid_path(entry.path):
            continue
        if entry.type == "blob" and entry.name.lower().startswith("readme"):
            target = entry
            break
    if target is None:
        return None, None
    try:
        data = gitcmd.read_blob(repo, ref, target.path, 512 * 1024)
    except Exception:
        return None, None
    if gitcmd.is_binary(data):
        return None, None
    is_md = target.name.lower().endswith((".md", ".markdown"))
    return markup.render_readme(data.decode("utf-8", "replace"), is_md), target.name


# --------------------------------------------------------------------------- #
# Generation
# --------------------------------------------------------------------------- #


def gen(root: str, hostile_root: str, shas, hostile_sha: str) -> None:
    repo = gitcmd.resolve_repo(root, "xrepo")
    empty = gitcmd.resolve_repo(root, "empty")
    evil = gitcmd.resolve_repo(hostile_root, HOSTILE_REPO)
    c1, c2, c5, c6 = shas["c1"], shas["c2"], shas["c5"], shas["c6"]

    print(HEADER)
    print("/// The Python `gitweb.views.CSS`, verbatim.")
    print("pub const PY_CSS: &str = %s;" % rs(views.CSS))
    print()
    print("/// The clock every `relative_date` below was measured against.")
    print("pub const PY_NOW: f64 = %r;" % NOW)
    print()
    print("/// The hostile fixture's HEAD commit sha (the recipe is deterministic).")
    print("pub const HOSTILE_HEAD: &str = %s;" % rs(hostile_sha))
    print()
    print("/// The hostile file's blob content, as the views receive it.")
    print("pub const HOSTILE_BLOB: &str = %s;" % rs(
        gitcmd.read_blob(evil, "main", HOSTILE_FILE, 1 << 20).decode("utf-8", "replace")))
    print()
    print("/// `(view name, rendered document with `<style>…</style>` elided)`.")
    print("pub static VIEWS: &[(&str, &str)] = &[")

    # -- repo list ---------------------------------------------------------- #
    repos = gitcmd.discover_repos(root)
    emit("repo_list", views.repo_list(repos, ""))
    match = [r for r in repos if "xre" in r.name.lower()
             or "xre" in (r.description or "").lower()]
    emit("repo_list_filtered", views.repo_list(match, "xre"))
    emit("repo_list_no_match", views.repo_list([], "zzz_no_such"))
    emit("repo_list_empty_root", views.repo_list([], ""))

    # -- summary ------------------------------------------------------------ #
    readme_html, readme_name = readme_of(repo, "main", "")
    emit(
        "summary",
        views.summary(repo, "main", gitcmd.log(repo, "main", 0, 10),
                      readme_html, readme_name,
                      clone_url="%s/xrepo" % BASE),
    )
    emit(
        "summary_no_clone_no_readme",
        views.summary(empty, "main", [], None, None, clone_url=None),
    )

    # -- refs / releases ---------------------------------------------------- #
    emit("refs", views.refs(repo, gitcmd.branches(repo), gitcmd.tags(repo)))
    emit("refs_empty", views.refs(empty, gitcmd.branches(empty), gitcmd.tags(empty)))
    emit("releases", views.releases(repo, gitcmd.tags(repo)))
    emit("releases_empty", views.releases(empty, []))
    emit("releases_atom", views.releases_atom(repo, gitcmd.tags(repo), BASE))
    emit("releases_atom_relative", views.releases_atom(repo, gitcmd.tags(repo), ""))
    emit("releases_atom_empty", views.releases_atom(empty, [], BASE))

    # -- log ---------------------------------------------------------------- #
    emit("log", views.log_page(repo, "main", gitcmd.log(repo, "main", 0, 50), 1, 1))
    emit("log_paged", views.log_page(repo, "main", gitcmd.log(repo, "main", 2, 2), 2, 3))
    emit("log_empty", views.log_page(empty, "main", [], 1, 1))

    # -- commit / compare --------------------------------------------------- #
    for label, rev in (("commit_c2", c2), ("commit_merge", c5), ("commit_root", c1),
                       ("commit_submodule", c6)):
        emit(
            label,
            views.commit_page(repo, gitcmd.commit_meta(repo, rev),
                              markup.parse_patch(gitcmd.commit_patch(repo, rev))),
        )
    emit(
        "compare",
        views.compare_page(repo, "main", "feature",
                           markup.parse_patch(gitcmd.compare(repo, "main", "feature"))),
    )
    emit("compare_identical",
         views.compare_page(repo, "main", "main",
                            markup.parse_patch(gitcmd.compare(repo, "main", "main"))))

    # -- tree --------------------------------------------------------------- #
    branches_names, tags_names = gitcmd.ref_names(repo)
    commit_sha = gitcmd.resolve_commit(repo, "main")
    submodules = gitcmd.read_gitmodules(repo, "main")
    root_entries = gitcmd.list_tree(repo, "main", "")
    emit(
        "tree_root",
        views.tree_page(repo, "main", "", root_entries, readme_html, readme_name,
                        page_num=1, total_pages=1, total_entries=len(root_entries),
                        branches=branches_names, tags=tags_names,
                        commit_sha=commit_sha, submodules=submodules),
    )
    sub_entries = gitcmd.list_tree(repo, "main", "src")
    emit(
        "tree_subdir",
        views.tree_page(repo, "main", "src", sub_entries, None, None,
                        page_num=1, total_pages=1, total_entries=len(sub_entries),
                        branches=branches_names, tags=tags_names,
                        commit_sha=commit_sha, submodules={}),
    )
    emit(
        "tree_paged",
        views.tree_page(repo, "main", "", root_entries[2:4], None, None,
                        page_num=2, total_pages=5, total_entries=len(root_entries),
                        branches=branches_names, tags=tags_names,
                        commit_sha=commit_sha, submodules=submodules),
    )
    emit(
        "tree_nested_path",
        views.tree_page(repo, "main", "weird dir",
                        gitcmd.list_tree(repo, "main", "weird dir"), None, None,
                        page_num=1, total_pages=1, total_entries=None,
                        branches=branches_names, tags=tags_names,
                        commit_sha=commit_sha, submodules={}),
    )

    # -- blob --------------------------------------------------------------- #
    def blob_text(path, max_bytes=2 * 1024 * 1024):
        return gitcmd.read_blob(repo, "main", path, max_bytes).decode("utf-8", "replace")

    emit(
        "blob_text",
        views.blob_page(repo, "main", "src/main.py",
                        size=gitcmd.blob_size(repo, "main", "src/main.py"),
                        text=blob_text("src/main.py"), binary=False, too_large=False,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha),
    )
    emit(
        "blob_highlight",
        views.blob_page(repo, "main", "src/main.py",
                        size=gitcmd.blob_size(repo, "main", "src/main.py"),
                        text=blob_text("src/main.py"), binary=False, too_large=False,
                        highlight={2, 3}, branches=branches_names, tags=tags_names,
                        commit_sha=commit_sha),
    )
    emit(
        "blob_binary",
        views.blob_page(repo, "main", "assets/logo.bin",
                        size=gitcmd.blob_size(repo, "main", "assets/logo.bin"),
                        text=None, binary=True, too_large=False,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha),
    )
    emit(
        "blob_too_large",
        views.blob_page(repo, "main", "src/main.py", size=99_000_000, text=None,
                        binary=False, too_large=True,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha),
    )
    emit(
        "blob_image",
        views.blob_page(repo, "main", "assets/logo.bin", size=42, text=None,
                        binary=True, too_large=False, is_image=True,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha),
    )
    guide = blob_text("docs/guide.md")
    emit(
        "blob_markdown_rendered",
        views.blob_page(repo, "main", "docs/guide.md",
                        size=gitcmd.blob_size(repo, "main", "docs/guide.md"),
                        text=guide, binary=False, too_large=False,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha,
                        rendered_md=markup.render_markdown(guide), show_source=False),
    )
    emit(
        "blob_markdown_source",
        views.blob_page(repo, "main", "docs/guide.md",
                        size=gitcmd.blob_size(repo, "main", "docs/guide.md"),
                        text=guide, binary=False, too_large=False,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha,
                        rendered_md=markup.render_markdown(guide), show_source=True),
    )
    lfs = gitcmd.parse_lfs_pointer(gitcmd.peek_blob(repo, "main", "assets/big.lfs", 8192))
    emit(
        "blob_lfs_pointer",
        views.blob_page(repo, "main", "assets/big.lfs",
                        size=gitcmd.blob_size(repo, "main", "assets/big.lfs"),
                        text=None, binary=False, too_large=False,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha,
                        lfs=lfs),
    )
    emit(
        "blob_lfs_served",
        views.blob_page(repo, "main", "assets/big.lfs", size=24, text="REAL LFS OBJECT CONTENT\n",
                        binary=False, too_large=False,
                        branches=branches_names, tags=tags_names, commit_sha=commit_sha,
                        lfs_served=lfs),
    )
    emit(
        "blob_no_refs",
        views.blob_page(repo, "main", "apple.txt", size=6, text="apple\n",
                        binary=False, too_large=False),
    )

    # -- blame / history ----------------------------------------------------- #
    emit("blame", views.blame_page(repo, "main", "src/main.py",
                                   gitcmd.blame(repo, "main", "src/main.py")))
    emit("blame_empty", views.blame_page(repo, "main", "src/main.py", []))
    hist = gitcmd.log_path(repo, "main", "src/main.py", 0, 50, False)
    emit("history", views.history_page(repo, "main", "src/main.py", hist, 1, 1, False))
    emit("history_follow_paged",
         views.history_page(repo, "main", "src/main.py", hist, 2, 4, True))
    emit("history_empty", views.history_page(repo, "main", "nope.txt", [], 1, 1, False))

    # -- atom ---------------------------------------------------------------- #
    emit("atom", views.atom_feed(repo, "main", gitcmd.log(repo, "main", 0, 20), BASE))
    emit("atom_relative", views.atom_feed(repo, "main", gitcmd.log(repo, "main", 0, 2), ""))
    emit("atom_empty", views.atom_feed(empty, "main", [], BASE))

    # -- search --------------------------------------------------------------- #
    matches, truncated = gitcmd.search_code(repo, "main", "UNIQUE_NEEDLE_TOKEN")
    emit(
        "search_code",
        views.search_page(repo, "UNIQUE_NEEDLE_TOKEN", "code", "main",
                          code_matches=matches, code_truncated=truncated),
    )
    xss, xtrunc = gitcmd.search_code(repo, "main", "script")
    emit(
        "search_code_xss",
        views.search_page(repo, "script", "code", "main",
                          code_matches=xss, code_truncated=True),
    )
    emit(
        "search_code_none",
        views.search_page(repo, "zzz_no_such_zzz", "code", "main",
                          code_matches=[], code_truncated=False),
    )
    grep_rows = gitcmd.log_grep(repo, "main", "SEARCHKEYWORD", 0, 50)
    emit(
        "search_log",
        views.search_page(repo, "SEARCHKEYWORD", "log", "main", log_rows=grep_rows),
    )
    emit(
        "search_log_paged",
        views.search_page(repo, "e", "log", "main",
                          log_rows=gitcmd.log_grep(repo, "main", "e", 0, 2),
                          page_num=2, total_pages=3),
    )
    emit("search_empty", views.search_page(repo, "", "code", "main"))
    emit("search_invalid", views.search_page(repo, "bad\x00nul", "code", "main", invalid=True))

    # -- graph ---------------------------------------------------------------- #
    emit("graph", views.graph_page(repo, "main", gitcmd.log_graph(repo, "main", 0, 50), 1, 1))
    emit("graph_paged",
         views.graph_page(repo, "main", gitcmd.log_graph(repo, "main", 0, 2), 1, 3))
    emit("graph_empty", views.graph_page(empty, "main", [], 1, 1))

    # -- error pages / shell --------------------------------------------------- #
    emit("error_404", views.error_page(404, "no such repository"))
    emit("error_400", views.error_page(400, "invalid ref"))
    emit("error_500_hostile",
         views.error_page(500, "git error: <script>alert('x')</script> & \"q\""))
    emit("page_plain", views.page("Title <x>", "<p>body &amp; more</p>"))
    emit("page_repo", views.page("T", "<p>b</p>", repo_name="xrepo",
                                 active_tab="log", repo_desc=repo.description))

    # -- OpenSearch ------------------------------------------------------------ #
    emit("opensearch_repo", views.opensearch_repo("xrepo", BASE))
    emit("opensearch_repo_hostile", views.opensearch_repo('a<b>&"c', BASE))
    emit("opensearch_site", views.opensearch_site(BASE))

    # -- the same views under a reverse-proxy prefix ---------------------------- #
    views.push_url_prefix("/git")
    try:
        emit("prefixed_repo_list", views.repo_list(repos, ""))
        emit(
            "prefixed_summary",
            views.summary(repo, "main", gitcmd.log(repo, "main", 0, 3),
                          readme_html, readme_name, clone_url="%s/git/xrepo" % BASE),
        )
        emit(
            "prefixed_tree",
            views.tree_page(repo, "main", "src", sub_entries, None, None,
                            page_num=1, total_pages=1, total_entries=len(sub_entries),
                            branches=branches_names, tags=tags_names,
                            commit_sha=commit_sha, submodules={}),
        )
        emit("prefixed_atom", views.atom_feed(repo, "main",
                                              gitcmd.log(repo, "main", 0, 2), BASE))
        emit("prefixed_opensearch_repo",
             views.opensearch_repo("xrepo", BASE + "/git"))
    finally:
        views.push_url_prefix("")

    # -- the hostile fixture ---------------------------------------------------- #
    print("    // The hostile fixture: every rendered field embeds markup.")
    hrepos = gitcmd.discover_repos(hostile_root)
    emit("hostile_repo_list", views.repo_list(hrepos, ""))
    hbranches, htags = gitcmd.ref_names(evil)
    hsha = gitcmd.resolve_commit(evil, "main")
    hentries = gitcmd.list_tree(evil, "main", "")
    hmods = gitcmd.read_gitmodules(evil, "main")
    emit("hostile_summary",
         views.summary(evil, "main", gitcmd.log(evil, "main", 0, 10), None, None,
                       clone_url="%s/%s" % (BASE, HOSTILE_REPO)))
    emit("hostile_refs", views.refs(evil, gitcmd.branches(evil), gitcmd.tags(evil)))
    emit("hostile_releases", views.releases(evil, gitcmd.tags(evil)))
    emit("hostile_releases_atom", views.releases_atom(evil, gitcmd.tags(evil), BASE))
    emit("hostile_log", views.log_page(evil, "main", gitcmd.log(evil, "main", 0, 50), 1, 1))
    emit("hostile_commit",
         views.commit_page(evil, gitcmd.commit_meta(evil, hostile_sha),
                           markup.parse_patch(gitcmd.commit_patch(evil, hostile_sha))))
    emit("hostile_tree",
         views.tree_page(evil, "main", "", hentries, None, None,
                         page_num=1, total_pages=1, total_entries=len(hentries),
                         branches=hbranches, tags=htags, commit_sha=hsha,
                         submodules=hmods))
    hfile_size = gitcmd.blob_size(evil, "main", HOSTILE_FILE)
    hfile = gitcmd.read_blob(evil, "main", HOSTILE_FILE, 1 << 20).decode("utf-8", "replace")
    emit("hostile_blob",
         views.blob_page(evil, "main", HOSTILE_FILE, size=hfile_size, text=hfile,
                         binary=False, too_large=False, branches=hbranches,
                         tags=htags, commit_sha=hsha))
    emit("hostile_blame",
         views.blame_page(evil, "main", HOSTILE_FILE,
                          gitcmd.blame(evil, "main", HOSTILE_FILE)))
    emit("hostile_history",
         views.history_page(evil, "main", HOSTILE_FILE,
                            gitcmd.log_path(evil, "main", HOSTILE_FILE, 0, 50, False),
                            1, 1, False))
    emit("hostile_atom", views.atom_feed(evil, "main", gitcmd.log(evil, "main", 0, 20), BASE))
    hmatches, htrunc = gitcmd.search_code(evil, "main", "script")
    emit("hostile_search_code",
         views.search_page(evil, "script", "code", "main",
                           code_matches=hmatches, code_truncated=htrunc))
    emit("hostile_search_log",
         views.search_page(evil, "subject", "log", "main",
                           log_rows=gitcmd.log_grep(evil, "main", "subject", 0, 50)))
    emit("hostile_graph",
         views.graph_page(evil, "main", gitcmd.log_graph(evil, "main", 0, 50), 1, 1))
    print("];")


def main() -> None:
    # Pin `relative_date` to a fixed clock: `views` imports it by value, so
    # rebinding the module attribute is enough and leaves `markup` untouched.
    views.relative_date = lambda ts: markup.relative_date(ts, NOW)
    tmp = tempfile.mkdtemp(prefix="gw-views-xcheck-")
    try:
        root = os.path.join(tmp, "repos")
        shas = build_fixture(root)
        hostile_root = os.path.join(tmp, "hostile")
        hostile_sha = build_hostile(hostile_root)
        gen(root, hostile_root, shas, hostile_sha)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    main()
