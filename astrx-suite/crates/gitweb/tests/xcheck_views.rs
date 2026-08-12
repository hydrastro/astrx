//! Cross-check: `gitweb::views` renders **byte-identical** HTML/XML to the
//! Python `gitweb.views`, for every view, over the same real repositories.
//!
//! `tests/regen_views_goldens.py` builds two deterministic fixtures — the
//! `gitcmd` cross-check repository set (`common::build`) and a second, hostile
//! one (`common::build_hostile`) whose repo directory name, description, branch,
//! tag, filename, commit subject/body, author identity and `.gitmodules` URL all
//! embed `<script>`, quotes, `&` and a `javascript:` scheme — drives the
//! reference over them and writes `tests/goldens/views.rs`. This test rebuilds
//! the same repositories, renders the same views with the Rust port, and asserts
//! the documents match byte for byte.
//!
//! Two things are pinned so a view is a pure function of its inputs: the clock
//! `relative_date` measures against (`views::Ctx::at`, matching the generator's
//! rebinding of `views.relative_date`), and the constant inline stylesheet,
//! which is elided from every document as `<style>@CSS@</style>` and compared
//! once on its own.
//!
//! # Not compared
//!
//! Nothing. Every view the Python module exposes is covered; the only Python
//! rendering path with no Rust counterpart is the optional Pygments hook, which
//! `Config.syntax_highlight` leaves off by default and which is a documented
//! non-port (see `gitweb::views`).

mod common;

include!("goldens/views.rs");

use gitweb::gitcmd::{
    self, blame, blob_size, branches, commit_meta, commit_patch, compare, discover_repos,
    list_tree, log, log_graph, log_grep, log_path, parse_lfs_pointer, peek_blob, read_blob,
    read_gitmodules, ref_names, resolve_commit, resolve_repo, search_code, tags, valid_path,
    GrepMatch, LfsPointer, Repo, SafePath, SafeQuery, SafeRef,
};
use gitweb::markup::{parse_patch, render_readme};
use gitweb::views::{
    self, atom_feed, blame_page, blob_page, commit_page, compare_page, error_page, graph_page,
    history_page, log_page, opensearch_repo, opensearch_site, page, refs, releases, releases_atom,
    repo_list, search_page, summary, tree_page, BlobView, Ctx, HistoryView, Readme, RefChoices,
    SearchView, TreeView,
};

// --------------------------------------------------------------------------- //
// Helpers
// --------------------------------------------------------------------------- //

fn r(s: &str) -> SafeRef {
    SafeRef::parse(s).unwrap_or_else(|| panic!("invalid test ref {s:?}"))
}

fn p(s: &str) -> SafePath {
    SafePath::parse(s).unwrap_or_else(|| panic!("invalid test path {s:?}"))
}

fn q(s: &str) -> SafeQuery {
    SafeQuery::parse(s).unwrap_or_else(|| panic!("invalid test query {s:?}"))
}

/// Replace the constant stylesheet with the generator's marker.
fn elide(html: &str) -> String {
    html.replace(
        &format!("<style>{}</style>", views::CSS),
        "<style>@CSS@</style>",
    )
}

/// The golden document for `name` (panics if the generator never emitted one).
fn golden(name: &str) -> &'static str {
    VIEWS
        .iter()
        .find(|(k, _)| *k == name)
        .map(|(_, v)| *v)
        .unwrap_or_else(|| panic!("no golden named {name:?}; regenerate tests/goldens/views.rs"))
}

/// Assert one rendered view is byte-identical to the reference's.
#[track_caller]
fn same(name: &str, rendered: &str) {
    let want = golden(name);
    let got = elide(rendered);
    if got != want {
        // Report the first divergence with context, rather than dumping two
        // multi-kilobyte documents.
        let at = got
            .as_bytes()
            .iter()
            .zip(want.as_bytes())
            .position(|(a, b)| a != b)
            .unwrap_or_else(|| got.len().min(want.len()));
        let lo = at.saturating_sub(80);
        panic!(
            "view {name:?} diverges at byte {at} (rust {} bytes, python {} bytes)\n\
             rust:   …{}…\npython: …{}…",
            got.len(),
            want.len(),
            &got[lo..(at + 120).min(got.len())],
            &want[lo..(at + 120).min(want.len())],
        );
    }
}

fn ctx() -> Ctx {
    Ctx::at("", PY_NOW)
}

/// The port of `GitwebHandler._readme` used by the summary/tree goldens.
fn readme_of(
    repo: &Repo,
    reference: &SafeRef,
    path: &SafePath,
) -> (Option<String>, Option<String>) {
    let Ok(entries) = list_tree(repo, reference, path) else {
        return (None, None);
    };
    let target = entries.iter().find(|e| {
        valid_path(&e.path) && e.otype == "blob" && e.name.to_lowercase().starts_with("readme")
    });
    let Some(target) = target else {
        return (None, None);
    };
    let Some(tpath) = SafePath::parse(&target.path) else {
        return (None, None);
    };
    let Ok(data) = read_blob(repo, reference, &tpath, 512 * 1024) else {
        return (None, None);
    };
    if gitcmd::is_binary(&data) {
        return (None, None);
    }
    let lower = target.name.to_lowercase();
    let is_md = lower.ends_with(".md") || lower.ends_with(".markdown");
    let text = String::from_utf8_lossy(&data).into_owned();
    (Some(render_readme(&text, is_md)), Some(target.name.clone()))
}

fn blob_text(repo: &Repo, path: &str) -> String {
    let data = read_blob(repo, &r("main"), &p(path), 2 * 1024 * 1024).expect("read blob");
    String::from_utf8_lossy(&data).into_owned()
}

/// The `(fixture, xrepo, empty)` triple, or `None` when `git` is unavailable.
fn open_fixture() -> Option<(common::Fixture, Repo, Repo)> {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return None;
    }
    let fx = common::build();
    let repo = resolve_repo(&fx.root, "xrepo").expect("resolve xrepo");
    let empty = resolve_repo(&fx.root, "empty").expect("resolve empty");
    Some((fx, repo, empty))
}

// --------------------------------------------------------------------------- //
// The stylesheet
// --------------------------------------------------------------------------- //

#[test]
fn css_matches_python() {
    assert_eq!(views::CSS, PY_CSS);
}

// --------------------------------------------------------------------------- //
// Views that need no repository
// --------------------------------------------------------------------------- //

#[test]
fn shell_error_and_opensearch_match_python() {
    let c = ctx();
    same("error_404", &error_page(&c, 404, "no such repository"));
    same("error_400", &error_page(&c, 400, "invalid ref"));
    same(
        "error_500_hostile",
        &error_page(&c, 500, "git error: <script>alert('x')</script> & \"q\""),
    );
    same(
        "page_plain",
        &page(&c, "Title <x>", "<p>body &amp; more</p>", None, "", ""),
    );
    same(
        "opensearch_repo",
        &opensearch_repo("xrepo", "http://127.0.0.1:8801"),
    );
    same(
        "opensearch_repo_hostile",
        &opensearch_repo("a<b>&\"c", "http://127.0.0.1:8801"),
    );
    same("opensearch_site", &opensearch_site("http://127.0.0.1:8801"));
    same(
        "prefixed_opensearch_repo",
        &opensearch_repo("xrepo", "http://127.0.0.1:8801/git"),
    );
    // Nothing anywhere emits a script tag or an event handler.
    for (name, html) in VIEWS {
        assert!(!html.contains("<script"), "{name} emitted a <script tag");
        assert!(
            !html.contains("=\"javascript:"),
            "{name} emitted a javascript: URL in an attribute"
        );
        assert!(
            !html.contains(" onerror="),
            "{name} emitted an event handler"
        );
        assert!(
            !html.contains(" onclick="),
            "{name} emitted an event handler"
        );
    }
}

// --------------------------------------------------------------------------- //
// The deterministic fixture
// --------------------------------------------------------------------------- //

#[test]
fn listing_views_match_python() {
    let Some((fx, repo, empty)) = open_fixture() else {
        return;
    };
    let c = ctx();
    let repos = discover_repos(&fx.root).expect("discover");
    same("repo_list", &repo_list(&c, &repos, ""));
    let matched: Vec<Repo> = repos
        .iter()
        .filter(|r| {
            r.name.to_lowercase().contains("xre") || r.description.to_lowercase().contains("xre")
        })
        .cloned()
        .collect();
    same("repo_list_filtered", &repo_list(&c, &matched, "xre"));
    same("repo_list_no_match", &repo_list(&c, &[], "zzz_no_such"));
    same("repo_list_empty_root", &repo_list(&c, &[], ""));

    let (html, name) = readme_of(&repo, &r("main"), &SafePath::root());
    same(
        "summary",
        &summary(
            &c,
            &repo,
            "main",
            &log(&repo, &r("main"), 0, 10).expect("log"),
            &Readme {
                html: html.as_deref(),
                name: name.as_deref(),
            },
            Some("http://127.0.0.1:8801/xrepo"),
        ),
    );
    same(
        "summary_no_clone_no_readme",
        &summary(&c, &empty, "main", &[], &Readme::default(), None),
    );

    same(
        "refs",
        &refs(
            &c,
            &repo,
            &branches(&repo).expect("branches"),
            &tags(&repo).expect("tags"),
        ),
    );
    same(
        "refs_empty",
        &refs(
            &c,
            &empty,
            &branches(&empty).expect("branches"),
            &tags(&empty).expect("tags"),
        ),
    );
    same(
        "releases",
        &releases(&c, &repo, &tags(&repo).expect("tags")),
    );
    same("releases_empty", &releases(&c, &empty, &[]));
    same(
        "releases_atom",
        &releases_atom(
            &c,
            &repo,
            &tags(&repo).expect("tags"),
            "http://127.0.0.1:8801",
        ),
    );
    same(
        "releases_atom_relative",
        &releases_atom(&c, &repo, &tags(&repo).expect("tags"), ""),
    );
    same(
        "releases_atom_empty",
        &releases_atom(&c, &empty, &[], "http://127.0.0.1:8801"),
    );

    same(
        "log",
        &log_page(
            &c,
            &repo,
            "main",
            &log(&repo, &r("main"), 0, 50).expect("log"),
            1,
            1,
        ),
    );
    same(
        "log_paged",
        &log_page(
            &c,
            &repo,
            "main",
            &log(&repo, &r("main"), 2, 2).expect("log"),
            2,
            3,
        ),
    );
    same("log_empty", &log_page(&c, &empty, "main", &[], 1, 1));

    same(
        "atom",
        &atom_feed(
            &c,
            &repo,
            "main",
            &log(&repo, &r("main"), 0, 20).expect("log"),
            "http://127.0.0.1:8801",
        ),
    );
    same(
        "atom_relative",
        &atom_feed(
            &c,
            &repo,
            "main",
            &log(&repo, &r("main"), 0, 2).expect("log"),
            "",
        ),
    );
    same(
        "atom_empty",
        &atom_feed(&c, &empty, "main", &[], "http://127.0.0.1:8801"),
    );
    drop(fx);
}

#[test]
fn commit_and_diff_views_match_python() {
    let Some((fx, repo, _empty)) = open_fixture() else {
        return;
    };
    let c = ctx();
    for (name, rev) in [
        ("commit_c2", &fx.shas[1]),
        ("commit_merge", &fx.shas[4]),
        ("commit_root", &fx.shas[0]),
        ("commit_submodule", &fx.shas[5]),
    ] {
        let rev = r(rev);
        let commit = commit_meta(&repo, &rev).expect("commit meta");
        let files = parse_patch(&commit_patch(&repo, &rev).expect("commit patch"));
        same(name, &commit_page(&c, &repo, &commit, &files));
    }
    same(
        "compare",
        &compare_page(
            &c,
            &repo,
            "main",
            "feature",
            &parse_patch(&compare(&repo, &r("main"), &r("feature")).expect("compare")),
        ),
    );
    same(
        "compare_identical",
        &compare_page(
            &c,
            &repo,
            "main",
            "main",
            &parse_patch(&compare(&repo, &r("main"), &r("main")).expect("compare")),
        ),
    );
    same(
        "page_repo",
        &page(&c, "T", "<p>b</p>", Some("xrepo"), "log", &repo.description),
    );
    drop(fx);
}

#[test]
fn tree_and_blob_views_match_python() {
    let Some((fx, repo, _empty)) = open_fixture() else {
        return;
    };
    let c = ctx();
    let (branch_names, tag_names) = ref_names(&repo).expect("ref names");
    let commit_sha = resolve_commit(&repo, &r("main"));
    let submodules = read_gitmodules(&repo, &r("main"));
    let choices = RefChoices {
        branches: &branch_names,
        tags: &tag_names,
        commit_sha: &commit_sha,
    };
    let root_entries = list_tree(&repo, &r("main"), &SafePath::root()).expect("tree");
    let (readme_html, readme_name) = readme_of(&repo, &r("main"), &SafePath::root());
    same(
        "tree_root",
        &tree_page(
            &c,
            &repo,
            "main",
            "",
            &root_entries,
            &Readme {
                html: readme_html.as_deref(),
                name: readme_name.as_deref(),
            },
            &TreeView {
                page_num: 1,
                total_pages: 1,
                total_entries: Some(root_entries.len()),
                refs: choices,
                submodules: &submodules,
            },
        ),
    );
    let sub_entries = list_tree(&repo, &r("main"), &p("src")).expect("tree src");
    same(
        "tree_subdir",
        &tree_page(
            &c,
            &repo,
            "main",
            "src",
            &sub_entries,
            &Readme::default(),
            &TreeView {
                page_num: 1,
                total_pages: 1,
                total_entries: Some(sub_entries.len()),
                refs: choices,
                submodules: &[],
            },
        ),
    );
    same(
        "tree_paged",
        &tree_page(
            &c,
            &repo,
            "main",
            "",
            &root_entries[2..4],
            &Readme::default(),
            &TreeView {
                page_num: 2,
                total_pages: 5,
                total_entries: Some(root_entries.len()),
                refs: choices,
                submodules: &submodules,
            },
        ),
    );
    same(
        "tree_nested_path",
        &tree_page(
            &c,
            &repo,
            "main",
            "weird dir",
            &list_tree(&repo, &r("main"), &p("weird dir")).expect("tree weird"),
            &Readme::default(),
            &TreeView {
                page_num: 1,
                total_pages: 1,
                total_entries: None,
                refs: choices,
                submodules: &[],
            },
        ),
    );

    let main_py = blob_text(&repo, "src/main.py");
    let main_py_size = blob_size(&repo, &r("main"), &p("src/main.py"));
    same(
        "blob_text",
        &blob_page(
            &c,
            &repo,
            "main",
            "src/main.py",
            &BlobView {
                size: main_py_size,
                text: Some(&main_py),
                refs: choices,
                ..BlobView::default()
            },
        ),
    );
    same(
        "blob_highlight",
        &blob_page(
            &c,
            &repo,
            "main",
            "src/main.py",
            &BlobView {
                size: main_py_size,
                text: Some(&main_py),
                highlight: &[2, 3],
                refs: choices,
                ..BlobView::default()
            },
        ),
    );
    same(
        "blob_binary",
        &blob_page(
            &c,
            &repo,
            "main",
            "assets/logo.bin",
            &BlobView {
                size: blob_size(&repo, &r("main"), &p("assets/logo.bin")),
                binary: true,
                refs: choices,
                ..BlobView::default()
            },
        ),
    );
    same(
        "blob_too_large",
        &blob_page(
            &c,
            &repo,
            "main",
            "src/main.py",
            &BlobView {
                size: 99_000_000,
                too_large: true,
                refs: choices,
                ..BlobView::default()
            },
        ),
    );
    same(
        "blob_image",
        &blob_page(
            &c,
            &repo,
            "main",
            "assets/logo.bin",
            &BlobView {
                size: 42,
                binary: true,
                is_image: true,
                refs: choices,
                ..BlobView::default()
            },
        ),
    );
    let guide = blob_text(&repo, "docs/guide.md");
    let guide_md = gitweb::markup::render_markdown(&guide);
    let guide_size = blob_size(&repo, &r("main"), &p("docs/guide.md"));
    same(
        "blob_markdown_rendered",
        &blob_page(
            &c,
            &repo,
            "main",
            "docs/guide.md",
            &BlobView {
                size: guide_size,
                text: Some(&guide),
                refs: choices,
                rendered_md: Some(&guide_md),
                ..BlobView::default()
            },
        ),
    );
    same(
        "blob_markdown_source",
        &blob_page(
            &c,
            &repo,
            "main",
            "docs/guide.md",
            &BlobView {
                size: guide_size,
                text: Some(&guide),
                refs: choices,
                rendered_md: Some(&guide_md),
                show_source: true,
                ..BlobView::default()
            },
        ),
    );
    let lfs: LfsPointer = parse_lfs_pointer(&peek_blob(&repo, &r("main"), &p("assets/big.lfs")))
        .expect("lfs pointer");
    same(
        "blob_lfs_pointer",
        &blob_page(
            &c,
            &repo,
            "main",
            "assets/big.lfs",
            &BlobView {
                size: blob_size(&repo, &r("main"), &p("assets/big.lfs")),
                refs: choices,
                lfs: Some(&lfs),
                ..BlobView::default()
            },
        ),
    );
    same(
        "blob_lfs_served",
        &blob_page(
            &c,
            &repo,
            "main",
            "assets/big.lfs",
            &BlobView {
                size: 24,
                text: Some("REAL LFS OBJECT CONTENT\n"),
                refs: choices,
                lfs_served: Some(&lfs),
                ..BlobView::default()
            },
        ),
    );
    same(
        "blob_no_refs",
        &blob_page(
            &c,
            &repo,
            "main",
            "apple.txt",
            &BlobView {
                size: 6,
                text: Some("apple\n"),
                ..BlobView::default()
            },
        ),
    );
    drop(fx);
}

#[test]
fn blame_history_search_and_graph_match_python() {
    let Some((fx, repo, empty)) = open_fixture() else {
        return;
    };
    let c = ctx();
    same(
        "blame",
        &blame_page(
            &c,
            &repo,
            "main",
            "src/main.py",
            &blame(&repo, &r("main"), &p("src/main.py")).expect("blame"),
        ),
    );
    same(
        "blame_empty",
        &blame_page(&c, &repo, "main", "src/main.py", &[]),
    );

    let hist = log_path(&repo, &r("main"), &p("src/main.py"), 0, 50, false).expect("log path");
    same(
        "history",
        &history_page(
            &c,
            &repo,
            "main",
            "src/main.py",
            &hist,
            &HistoryView {
                page_num: 1,
                total_pages: 1,
                follow: false,
            },
        ),
    );
    same(
        "history_follow_paged",
        &history_page(
            &c,
            &repo,
            "main",
            "src/main.py",
            &hist,
            &HistoryView {
                page_num: 2,
                total_pages: 4,
                follow: true,
            },
        ),
    );
    same(
        "history_empty",
        &history_page(
            &c,
            &repo,
            "main",
            "nope.txt",
            &[],
            &HistoryView {
                page_num: 1,
                total_pages: 1,
                follow: false,
            },
        ),
    );

    let (matches, truncated) =
        search_code(&repo, &r("main"), &q("UNIQUE_NEEDLE_TOKEN")).expect("grep");
    same(
        "search_code",
        &search_page(
            &c,
            &repo,
            "UNIQUE_NEEDLE_TOKEN",
            "code",
            "main",
            &SearchView {
                code_matches: Some(&matches),
                code_truncated: truncated,
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );
    let (xss, _): (Vec<GrepMatch>, bool) =
        search_code(&repo, &r("main"), &q("script")).expect("grep");
    same(
        "search_code_xss",
        &search_page(
            &c,
            &repo,
            "script",
            "code",
            "main",
            &SearchView {
                code_matches: Some(&xss),
                code_truncated: true,
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );
    same(
        "search_code_none",
        &search_page(
            &c,
            &repo,
            "zzz_no_such_zzz",
            "code",
            "main",
            &SearchView {
                code_matches: Some(&[]),
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );
    let grep_rows = log_grep(&repo, &r("main"), &q("SEARCHKEYWORD"), 0, 50).expect("log grep");
    same(
        "search_log",
        &search_page(
            &c,
            &repo,
            "SEARCHKEYWORD",
            "log",
            "main",
            &SearchView {
                log_rows: Some(&grep_rows),
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );
    let paged = log_grep(&repo, &r("main"), &q("e"), 0, 2).expect("log grep");
    same(
        "search_log_paged",
        &search_page(
            &c,
            &repo,
            "e",
            "log",
            "main",
            &SearchView {
                log_rows: Some(&paged),
                page_num: 2,
                total_pages: 3,
                ..SearchView::default()
            },
        ),
    );
    same(
        "search_empty",
        &search_page(
            &c,
            &repo,
            "",
            "code",
            "main",
            &SearchView {
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );
    same(
        "search_invalid",
        &search_page(
            &c,
            &repo,
            "bad\u{0}nul",
            "code",
            "main",
            &SearchView {
                invalid: true,
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );

    same(
        "graph",
        &graph_page(
            &c,
            &repo,
            "main",
            &log_graph(&repo, &r("main"), 0, 50).expect("graph"),
            1,
            1,
        ),
    );
    same(
        "graph_paged",
        &graph_page(
            &c,
            &repo,
            "main",
            &log_graph(&repo, &r("main"), 0, 2).expect("graph"),
            1,
            3,
        ),
    );
    same("graph_empty", &graph_page(&c, &empty, "main", &[], 1, 1));
    drop(fx);
}

#[test]
fn reverse_proxy_prefix_matches_python() {
    let Some((fx, repo, _empty)) = open_fixture() else {
        return;
    };
    let c = Ctx::at("/git", PY_NOW);
    let repos = discover_repos(&fx.root).expect("discover");
    same("prefixed_repo_list", &repo_list(&c, &repos, ""));
    let (html, name) = readme_of(&repo, &r("main"), &SafePath::root());
    same(
        "prefixed_summary",
        &summary(
            &c,
            &repo,
            "main",
            &log(&repo, &r("main"), 0, 3).expect("log"),
            &Readme {
                html: html.as_deref(),
                name: name.as_deref(),
            },
            Some("http://127.0.0.1:8801/git/xrepo"),
        ),
    );
    let (branch_names, tag_names) = ref_names(&repo).expect("ref names");
    let commit_sha = resolve_commit(&repo, &r("main"));
    let sub_entries = list_tree(&repo, &r("main"), &p("src")).expect("tree src");
    same(
        "prefixed_tree",
        &tree_page(
            &c,
            &repo,
            "main",
            "src",
            &sub_entries,
            &Readme::default(),
            &TreeView {
                page_num: 1,
                total_pages: 1,
                total_entries: Some(sub_entries.len()),
                refs: RefChoices {
                    branches: &branch_names,
                    tags: &tag_names,
                    commit_sha: &commit_sha,
                },
                submodules: &[],
            },
        ),
    );
    same(
        "prefixed_atom",
        &atom_feed(
            &c,
            &repo,
            "main",
            &log(&repo, &r("main"), 0, 2).expect("log"),
            "http://127.0.0.1:8801",
        ),
    );
    drop(fx);
}

// --------------------------------------------------------------------------- //
// The hostile fixture: every rendered field embeds markup
// --------------------------------------------------------------------------- //

#[test]
fn hostile_repository_renders_escaped_and_matches_python() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build_hostile();
    assert_eq!(
        fx.head, HOSTILE_HEAD,
        "the hostile fixture recipe drifted from the goldens; regenerate with \
         tests/regen_views_goldens.py"
    );
    let evil = resolve_repo(&fx.root, common::HOSTILE_REPO).expect("resolve evil-repo");
    let c = ctx();
    let head = r(&fx.head);

    let repos = discover_repos(&fx.root).expect("discover");
    same("hostile_repo_list", &repo_list(&c, &repos, ""));
    same(
        "hostile_summary",
        &summary(
            &c,
            &evil,
            "main",
            &log(&evil, &r("main"), 0, 10).expect("log"),
            &Readme::default(),
            Some("http://127.0.0.1:8801/evil-repo"),
        ),
    );
    same(
        "hostile_refs",
        &refs(
            &c,
            &evil,
            &branches(&evil).expect("branches"),
            &tags(&evil).expect("tags"),
        ),
    );
    same(
        "hostile_releases",
        &releases(&c, &evil, &tags(&evil).expect("tags")),
    );
    same(
        "hostile_releases_atom",
        &releases_atom(
            &c,
            &evil,
            &tags(&evil).expect("tags"),
            "http://127.0.0.1:8801",
        ),
    );
    same(
        "hostile_log",
        &log_page(
            &c,
            &evil,
            "main",
            &log(&evil, &r("main"), 0, 50).expect("log"),
            1,
            1,
        ),
    );
    same(
        "hostile_commit",
        &commit_page(
            &c,
            &evil,
            &commit_meta(&evil, &head).expect("commit meta"),
            &parse_patch(&commit_patch(&evil, &head).expect("commit patch")),
        ),
    );
    let (hbranches, htags) = ref_names(&evil).expect("ref names");
    let hsha = resolve_commit(&evil, &r("main"));
    let hchoices = RefChoices {
        branches: &hbranches,
        tags: &htags,
        commit_sha: &hsha,
    };
    let hentries = list_tree(&evil, &r("main"), &SafePath::root()).expect("tree");
    let hmods = read_gitmodules(&evil, &r("main"));
    same(
        "hostile_tree",
        &tree_page(
            &c,
            &evil,
            "main",
            "",
            &hentries,
            &Readme::default(),
            &TreeView {
                page_num: 1,
                total_pages: 1,
                total_entries: Some(hentries.len()),
                refs: hchoices,
                submodules: &hmods,
            },
        ),
    );
    let hfile = p(common::HOSTILE_FILE);
    let content = read_blob(&evil, &r("main"), &hfile, 1 << 20).expect("read hostile blob");
    let content = String::from_utf8_lossy(&content).into_owned();
    assert_eq!(content, HOSTILE_BLOB);
    same(
        "hostile_blob",
        &blob_page(
            &c,
            &evil,
            "main",
            common::HOSTILE_FILE,
            &BlobView {
                size: blob_size(&evil, &r("main"), &hfile),
                text: Some(&content),
                refs: hchoices,
                ..BlobView::default()
            },
        ),
    );
    same(
        "hostile_blame",
        &blame_page(
            &c,
            &evil,
            "main",
            common::HOSTILE_FILE,
            &blame(&evil, &r("main"), &hfile).expect("blame"),
        ),
    );
    same(
        "hostile_history",
        &history_page(
            &c,
            &evil,
            "main",
            common::HOSTILE_FILE,
            &log_path(&evil, &r("main"), &hfile, 0, 50, false).expect("log path"),
            &HistoryView {
                page_num: 1,
                total_pages: 1,
                follow: false,
            },
        ),
    );
    same(
        "hostile_atom",
        &atom_feed(
            &c,
            &evil,
            "main",
            &log(&evil, &r("main"), 0, 20).expect("log"),
            "http://127.0.0.1:8801",
        ),
    );
    let (hmatches, htrunc) = search_code(&evil, &r("main"), &q("script")).expect("grep");
    same(
        "hostile_search_code",
        &search_page(
            &c,
            &evil,
            "script",
            "code",
            "main",
            &SearchView {
                code_matches: Some(&hmatches),
                code_truncated: htrunc,
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );
    same(
        "hostile_search_log",
        &search_page(
            &c,
            &evil,
            "subject",
            "log",
            "main",
            &SearchView {
                log_rows: Some(&log_grep(&evil, &r("main"), &q("subject"), 0, 50).expect("grep")),
                page_num: 1,
                total_pages: 1,
                ..SearchView::default()
            },
        ),
    );
    same(
        "hostile_graph",
        &graph_page(
            &c,
            &evil,
            "main",
            &log_graph(&evil, &r("main"), 0, 50).expect("graph"),
            1,
            1,
        ),
    );

    // Every hostile string reaches the page escaped, and never as live markup.
    for name in [
        "hostile_repo_list",
        "hostile_summary",
        "hostile_refs",
        "hostile_log",
        "hostile_commit",
        "hostile_tree",
        "hostile_blob",
        "hostile_blame",
        "hostile_history",
        "hostile_search_code",
        "hostile_graph",
    ] {
        let html = golden(name);
        assert!(
            !html.contains("<script>") && !html.contains("</script>"),
            "{name} contains live script markup"
        );
        assert!(html.contains("&lt;"), "{name} never escaped anything");
    }
    // The `javascript:` submodule URL is shown as inert text, never as an href.
    let tree = golden("hostile_tree");
    assert!(tree.contains("<span class=\"muted\">javascript:alert(1)</span>"));
    assert!(!tree.contains("href=\"javascript:"));
    // ...while the https one is linked, with its markup escaped.
    assert!(tree.contains("href=\"https://example.com/&lt;x&gt;.git\""));
    drop(fx);
}
