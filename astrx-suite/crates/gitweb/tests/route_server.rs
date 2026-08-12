//! The serving tier's **pure** contract: routing, status codes, headers, cache
//! validators, access control and the streaming endpoints — all without a socket.
//!
//! `Route::of` is exercised on its own (path parsing, percent-decoding, the
//! reverse-proxy prefix, the POST surface), then the whole request→response
//! mapping is driven through `Server::route` over the deterministic fixture
//! repository set from `tests/common/mod.rs`. Every assertion here is about the
//! *protocol* — the HTML bodies are compared byte-for-byte against the Python
//! reference in `tests/xcheck_views.rs`.

mod common;

use gitweb::gitcmd::GitError;
use gitweb::server::{
    make_etag, negotiate_encoding, normalize_prefix, reason, Action, Body, Config, Request, Resp,
    Route, Server, CSP,
};

// --------------------------------------------------------------------------- //
// Helpers
// --------------------------------------------------------------------------- //

fn server(fx: &common::Fixture) -> Server {
    Server::new(Config::new(&fx.root)).expect("server")
}

fn open() -> Option<(common::Fixture, Server)> {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return None;
    }
    let fx = common::build();
    let srv = server(&fx);
    Some((fx, srv))
}

fn get(srv: &Server, target: &str) -> Resp {
    srv.route(&Request::get(target)).resp
}

fn body_text(resp: &Resp) -> String {
    resp.body.text()
}

/// Drain a streamed body into bytes.
fn drain(resp: Resp) -> Vec<u8> {
    match resp.body {
        Body::Bytes(b) => b,
        Body::Stream(s) => s.flatten().collect(),
    }
}

/// Assert the standard security headers are present on `resp`.
#[track_caller]
fn assert_security_headers(resp: &Resp) {
    assert_eq!(resp.header("X-Content-Type-Options"), Some("nosniff"));
    assert_eq!(resp.header("X-Frame-Options"), Some("DENY"));
    assert_eq!(resp.header("Referrer-Policy"), Some("no-referrer"));
    assert_eq!(resp.header("Content-Security-Policy"), Some(CSP));
}

// --------------------------------------------------------------------------- //
// Route parsing (no filesystem, no git)
// --------------------------------------------------------------------------- //

#[test]
fn route_parsing_matches_the_reference() {
    let r = |t: &str| Route::of("GET", t, "");
    assert_eq!(r("/"), Route::Home);
    assert_eq!(r(""), Route::Home);
    assert_eq!(r("/?q=x"), Route::Home);
    assert_eq!(r("/health"), Route::Health);
    assert_eq!(r("/metrics"), Route::Metrics);
    assert_eq!(r("/opensearch.xml"), Route::OpensearchSite);
    assert_eq!(
        r("/myrepo/"),
        Route::Summary {
            repo: "myrepo".to_string()
        }
    );
    assert_eq!(
        r("/myrepo/log?ref=main"),
        Route::Action {
            repo: "myrepo".to_string(),
            action: Action::Log
        }
    );
    assert_eq!(
        r("/myrepo/commit.patch?id=abc"),
        Route::Action {
            repo: "myrepo".to_string(),
            action: Action::CommitPatch
        }
    );
    assert_eq!(
        r("/myrepo/info/refs?service=git-upload-pack"),
        Route::InfoRefs {
            repo: "myrepo".to_string()
        }
    );
    // An unknown action, or too many segments, resolves the repository first.
    assert_eq!(
        r("/myrepo/nope"),
        Route::UnknownAction {
            repo: "myrepo".to_string()
        }
    );
    assert_eq!(
        r("/myrepo/log/extra"),
        Route::UnknownAction {
            repo: "myrepo".to_string()
        }
    );
    // `health` only wins as a single segment.
    assert_eq!(
        r("/health/x"),
        Route::UnknownAction {
            repo: "health".to_string()
        }
    );
    // Every action token round-trips.
    for token in [
        "refs",
        "releases",
        "releases.atom",
        "patches",
        "patches.mbox",
        "log",
        "commit",
        "tree",
        "blob",
        "raw",
        "blame",
        "history",
        "atom",
        "archive",
        "compare",
        "search",
        "graph",
        "patch",
        "commit.patch",
        "opensearch.xml",
    ] {
        let action = Action::parse(token).unwrap_or_else(|| panic!("{token} not an action"));
        assert_eq!(action.as_str(), token);
        assert_eq!(
            r(&format!("/r/{token}")),
            Route::Action {
                repo: "r".to_string(),
                action
            }
        );
    }
    assert_eq!(Action::parse("git-receive-pack"), None);
}

#[test]
fn encoded_separators_never_create_a_new_path_segment() {
    // "%2f" decodes *after* the split, so a traversal cannot forge segments.
    assert_eq!(
        Route::of("GET", "/..%2f..%2fetc%2fpasswd", ""),
        Route::Summary {
            repo: "../../etc/passwd".to_string()
        }
    );
    assert_eq!(
        Route::of("GET", "/a%2fb/log", ""),
        Route::Action {
            repo: "a/b".to_string(),
            action: Action::Log
        }
    );
}

#[test]
fn reverse_proxy_prefix_mounting() {
    assert_eq!(normalize_prefix(""), "");
    assert_eq!(normalize_prefix("git"), "/git");
    assert_eq!(normalize_prefix("/git/"), "/git");
    assert_eq!(normalize_prefix("  /git//  "), "/git");

    assert_eq!(Route::of("GET", "/git", "/git"), Route::Home);
    assert_eq!(Route::of("GET", "/git/", "/git"), Route::Home);
    assert_eq!(
        Route::of("GET", "/git/r/", "/git"),
        Route::Summary {
            repo: "r".to_string()
        }
    );
    // Unprefixed paths are not served under a prefixed mount.
    assert_eq!(Route::of("GET", "/", "/git"), Route::NotFound);
    assert_eq!(Route::of("GET", "/r/", "/git"), Route::NotFound);
    assert_eq!(Route::of("GET", "/gitx/r/", "/git"), Route::NotFound);
}

#[test]
fn post_routes_only_reach_the_smart_http_rpcs() {
    assert_eq!(
        Route::of("POST", "/r/git-upload-pack", ""),
        Route::UploadPack {
            repo: "r".to_string()
        }
    );
    assert_eq!(
        Route::of("POST", "/r/git-receive-pack", ""),
        Route::ReceivePack {
            repo: "r".to_string()
        }
    );
    assert_eq!(Route::of("POST", "/r/log", ""), Route::PostNotFound);
    assert_eq!(Route::of("POST", "/", ""), Route::PostNotFound);
    assert_eq!(Route::of("POST", "/a/b/c", ""), Route::PostNotFound);
    assert_eq!(
        Route::of("POST", "/r/git-upload-pack", "/git"),
        Route::NotFound
    );
}

#[test]
fn action_labels_are_the_reference_metric_names() {
    assert_eq!(Route::Home.action_label(), "home");
    assert_eq!(Route::Health.action_label(), "health");
    assert_eq!(Route::Metrics.action_label(), "metrics");
    assert_eq!(Route::OpensearchSite.action_label(), "opensearch-site");
    assert_eq!(Route::NotFound.action_label(), "");
    assert_eq!(Route::PostNotFound.action_label(), "");
    assert_eq!(
        Route::UnknownAction {
            repo: "r".to_string()
        }
        .action_label(),
        ""
    );
    assert_eq!(
        Route::UploadPack {
            repo: "r".to_string()
        }
        .action_label(),
        "upload-pack"
    );
    assert_eq!(
        Route::ReceivePack {
            repo: "r".to_string()
        }
        .action_label(),
        "receive-pack"
    );
}

// --------------------------------------------------------------------------- //
// Header / validator helpers
// --------------------------------------------------------------------------- //

#[test]
fn content_encoding_negotiation_matches_the_reference() {
    assert_eq!(negotiate_encoding(None), "");
    assert_eq!(negotiate_encoding(Some("")), "");
    assert_eq!(negotiate_encoding(Some("gzip")), "gzip");
    assert_eq!(negotiate_encoding(Some("GZIP, deflate")), "gzip");
    assert_eq!(negotiate_encoding(Some("deflate")), "deflate");
    assert_eq!(negotiate_encoding(Some("br")), "");
    // An explicit q=0 is a refusal; deflate then wins.
    assert_eq!(negotiate_encoding(Some("gzip;q=0, deflate")), "deflate");
    assert_eq!(negotiate_encoding(Some("gzip;q=0, deflate;q=0")), "");
    assert_eq!(negotiate_encoding(Some("gzip;q=0.5")), "gzip");
    // Faithful to the reference's own quirk: the token is keyed without
    // stripping the whitespace before the `;`, so this is not recognised.
    assert_eq!(negotiate_encoding(Some("gzip ;q=1")), "");
}

#[test]
fn etags_fold_in_the_render_version_and_every_part() {
    let a = make_etag(&["sha", "path"]);
    assert_eq!(a.len(), 32);
    assert!(a.chars().all(|c| c.is_ascii_hexdigit()));
    assert_ne!(a, make_etag(&["sha", "path2"]));
    assert_ne!(a, make_etag(&["sha"]));
    // The separator is real: ("ab","c") and ("a","bc") must not collide.
    assert_ne!(make_etag(&["ab", "c"]), make_etag(&["a", "bc"]));
}

#[test]
fn reason_phrases_cover_every_status_emitted() {
    for (status, phrase) in [
        (200u16, "OK"),
        (302, "Found"),
        (304, "Not Modified"),
        (400, "Bad Request"),
        (401, "Unauthorized"),
        (403, "Forbidden"),
        (404, "Not Found"),
        (500, "Internal Server Error"),
        (503, "Service Unavailable"),
    ] {
        assert_eq!(reason(status), phrase);
    }
}

// --------------------------------------------------------------------------- //
// The browse surface
// --------------------------------------------------------------------------- //

#[test]
fn browse_endpoints_answer_200_with_the_security_headers() {
    let Some((fx, srv)) = open() else { return };
    let sha = &fx.shas[1];
    let cases: &[(&str, &str)] = &[
        ("/", "Repositories"),
        ("/xrepo/", "Default branch"),
        ("/xrepo/refs", "Branches"),
        ("/xrepo/releases", "Releases"),
        ("/xrepo/log?ref=main", "Add README and sources"),
        ("/xrepo/tree?ref=main", "README.md"),
        ("/xrepo/tree?ref=main&path=src", "main.py"),
        ("/xrepo/blob?ref=main&path=src/main.py", "&lt;hello&gt;"),
        ("/xrepo/blame?ref=main&path=src/main.py", "Test Author"),
        ("/xrepo/history?ref=main&path=src/main.py", "History of"),
        ("/xrepo/graph?ref=main", "<svg"),
        (
            "/xrepo/search?q=UNIQUE_NEEDLE_TOKEN&type=code",
            "UNIQUE_NEEDLE_TOKEN",
        ),
        ("/xrepo/search", "Enter a term"),
        ("/xrepo/compare?from=main&to=feature", "Compare"),
        ("/xrepo/patches", "Patches"),
    ];
    for (target, needle) in cases {
        let resp = get(&srv, target);
        assert_eq!(resp.status, 200, "{target} -> {}", body_text(&resp));
        assert_eq!(
            resp.header("Content-Type"),
            Some("text/html; charset=utf-8"),
            "{target}"
        );
        assert_security_headers(&resp);
        let body = body_text(&resp);
        assert!(body.contains(needle), "{target} missing {needle:?}");
        assert!(body.starts_with("<!doctype html>"), "{target}");
        assert!(!body.contains("<script"), "{target} emitted a script tag");
    }
    // A commit page and its patch.
    let commit = get(&srv, &format!("/xrepo/commit?id={sha}"));
    assert_eq!(commit.status, 200);
    assert!(body_text(&commit).contains("diff-add"));
    assert!(body_text(&commit).contains("commit.patch"));
    drop(fx);
}

#[test]
fn operational_endpoints() {
    let Some((fx, srv)) = open() else { return };
    let health = get(&srv, "/health");
    assert_eq!(health.status, 200);
    assert_eq!(health.body.as_bytes(), Some(&b"ok\n"[..]));
    assert_eq!(
        health.header("Content-Type"),
        Some("text/plain; charset=utf-8")
    );

    // Drive one labelled request, then check the counter surfaces.
    let routed = srv.handle(&Request::get("/xrepo/"));
    assert_eq!(routed.action, "summary");
    let metrics = get(&srv, "/metrics");
    assert_eq!(metrics.status, 200);
    assert_eq!(
        metrics.header("Content-Type"),
        Some("text/plain; version=0.0.4; charset=utf-8")
    );
    let text = body_text(&metrics);
    assert!(text.contains("gitweb_requests_total"));
    assert!(text.contains("gitweb_responses_total"));
    assert!(text.contains("gitweb_action_total{action=\"summary\"}"));

    let os = get(&srv, "/opensearch.xml");
    assert_eq!(os.status, 200);
    assert_eq!(
        os.header("Content-Type"),
        Some("application/opensearchdescription+xml")
    );
    assert!(body_text(&os).contains("{searchTerms}"));
    drop(fx);
}

#[test]
fn feeds_and_descriptors_carry_their_own_content_types() {
    let Some((fx, srv)) = open() else { return };
    let atom = get(&srv, "/xrepo/atom?ref=main");
    assert_eq!(atom.status, 200);
    assert_eq!(
        atom.header("Content-Type"),
        Some("application/atom+xml; charset=utf-8")
    );
    assert!(body_text(&atom).contains("<feed"));

    let releases = get(&srv, "/xrepo/releases.atom");
    assert_eq!(releases.status, 200);
    assert!(body_text(&releases).contains("releases</title>"));

    let repo_os = get(&srv, "/xrepo/opensearch.xml");
    assert_eq!(repo_os.status, 200);
    assert!(body_text(&repo_os).contains("/xrepo/search"));
    drop(fx);
}

#[test]
fn absolute_links_follow_the_host_and_forwarded_proto() {
    let Some((fx, srv)) = open() else { return };
    let mut req = Request::get("/xrepo/atom?ref=main");
    req.host = Some("git.example.onion");
    req.forwarded_proto = Some("https, http");
    let body = srv.route(&req).resp.body.text();
    assert!(body.contains("https://git.example.onion/xrepo/"), "{body}");
    // An unknown scheme degrades to http rather than being echoed.
    let mut req = Request::get("/xrepo/atom?ref=main");
    req.host = Some("h");
    req.forwarded_proto = Some("javascript");
    assert!(srv.route(&req).resp.body.text().contains("http://h/xrepo/"));
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Hostile input
// --------------------------------------------------------------------------- //

#[test]
fn hostile_paths_refs_and_repo_ids_are_refused() {
    let Some((fx, srv)) = open() else { return };
    let cases: &[(&str, u16)] = &[
        // Traversal in the repository id.
        ("/..%2f..%2fetc%2fpasswd", 400),
        // `..` is not even a well-formed repository id.
        ("/../../etc/", 400),
        // Traversal / absolute paths in the `path` parameter.
        ("/xrepo/blob?ref=main&path=../../../../etc/passwd", 400),
        ("/xrepo/blob?ref=main&path=/etc/passwd", 400),
        ("/xrepo/tree?ref=main&path=../..", 400),
        ("/xrepo/raw?ref=main&path=../../etc/passwd", 400),
        // Option-like and metacharacter-carrying refs.
        ("/xrepo/log?ref=--output=/tmp/x", 400),
        ("/xrepo/log?ref=a;id", 400),
        ("/xrepo/log?ref=a..b", 400),
        ("/xrepo/commit?id=--output=/tmp/x", 400),
        ("/xrepo/commit.patch?id=--output=/tmp/x", 400),
        ("/xrepo/compare?from=main&to=--upload-pack=x", 400),
        ("/xrepo/compare?from=main", 400),
        ("/xrepo/blob?ref=main", 400),
        ("/xrepo/blame?ref=main", 400),
        // Unknown repositories and paths.
        ("/does-not-exist/", 404),
        ("/xrepo/nope", 404),
        ("/xrepo/log/extra", 404),
        ("/xrepo/blob?ref=main&path=no/such/file.txt", 404),
        ("/xrepo/tree?ref=main&path=no/such/dir", 404),
        ("/xrepo/blame?ref=main&path=no/such.txt", 404),
        // The symlink that escapes the root is neither listed nor resolvable.
        ("/escape/", 404),
    ];
    for (target, want) in cases {
        let resp = get(&srv, target);
        assert_eq!(resp.status, *want, "{target} -> {}", body_text(&resp));
        assert_eq!(
            resp.header("Content-Type"),
            Some("text/html; charset=utf-8")
        );
        assert_security_headers(&resp);
        let body = body_text(&resp);
        assert!(body.contains(&format!("Error {want}")), "{target}");
        assert!(!body.contains("<script"), "{target}");
    }
    // The escaping symlink is not listed on the home page either.
    assert!(!get(&srv, "/").body.text().contains("escape"));
    drop(fx);
}

#[test]
fn a_hostile_search_term_is_reported_not_executed() {
    let Some((fx, srv)) = open() else { return };
    // A NUL cannot appear in an argv element: reported, not 500'd.
    let resp = get(&srv, "/xrepo/search?q=bad%00nul&type=code");
    assert_eq!(resp.status, 200);
    assert!(body_text(&resp).contains("invalid character"));
    // An option-like term is a literal: it matches the literal line.
    let resp = get(&srv, "/xrepo/search?q=--option-like-needle&type=code");
    assert_eq!(resp.status, 200);
    assert!(body_text(&resp).contains("--option-like-needle = 2"));
    // A term that looks like an output redirect writes nothing and matches nothing.
    let sentinel = fx.root.join("pwned_marker");
    let target = format!(
        "/xrepo/search?q=--output%3D{}&type=code",
        sentinel.display()
    );
    let resp = get(&srv, &target);
    assert_eq!(resp.status, 200);
    assert!(!sentinel.exists(), "grep treated --output as an option");
    assert!(body_text(&resp).contains("No code matches"));
    // Matched lines are escaped, never live markup.
    let resp = get(&srv, "/xrepo/search?q=script&type=code");
    assert_eq!(resp.status, 200);
    let body = body_text(&resp);
    assert!(body.contains("&lt;script&gt;"));
    assert!(!body.contains("<script>alert(1)</script>"));
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Conditional GET / content coding
// --------------------------------------------------------------------------- //

#[test]
fn conditional_get_revalidates_the_blob_commit_tree_and_patch() {
    let Some((fx, srv)) = open() else { return };
    let sha = &fx.shas[1];
    for target in [
        "/xrepo/blob?ref=main&path=src/main.py".to_string(),
        "/xrepo/tree?ref=main".to_string(),
        format!("/xrepo/commit?id={sha}"),
    ] {
        let first = get(&srv, &target);
        assert_eq!(first.status, 200, "{target}");
        let etag = first.header("ETag").expect("etag").to_string();
        assert!(etag.starts_with('"'), "{target}: {etag}");
        assert_eq!(
            first.header("Cache-Control"),
            Some("max-age=0, must-revalidate")
        );
        let mut req = Request::get(&target);
        req.if_none_match = Some(&etag);
        let second = srv.route(&req).resp;
        assert_eq!(second.status, 304, "{target}");
        assert_eq!(second.header("Content-Length"), Some("0"));
        assert!(second.body.as_bytes().is_some_and(<[u8]>::is_empty));
        // A weak validator for the same entity also matches.
        let weak = format!("W/{etag}");
        let mut req = Request::get(&target);
        req.if_none_match = Some(&weak);
        assert_eq!(srv.route(&req).resp.status, 304, "{target} weak");
        // `*` always matches.
        let mut req = Request::get(&target);
        req.if_none_match = Some("*");
        assert_eq!(srv.route(&req).resp.status, 304, "{target} star");
    }

    // A different rendered variant of the same blob must not 304.
    let base = get(&srv, "/xrepo/blob?ref=main&path=src/main.py");
    let etag = base.header("ETag").expect("etag").to_string();
    let mut req = Request::get("/xrepo/blob?ref=main&path=src/main.py&highlight=1-2");
    req.if_none_match = Some(&etag);
    assert_eq!(srv.route(&req).resp.status, 200);

    // ...nor a different tree page.
    let paged = Server::new(Config {
        tree_page_size: 2,
        ..Config::new(&fx.root)
    })
    .expect("server");
    let page1 = get(&paged, "/xrepo/tree?ref=main");
    let tetag = page1.header("ETag").expect("etag").to_string();
    let mut req = Request::get("/xrepo/tree?ref=main&page=2");
    req.if_none_match = Some(&tetag);
    assert_eq!(paged.route(&req).resp.status, 200);
    assert!(body_text(&page1).contains("page 1 of"));
    assert!(body_text(&page1).contains("Next"));
    assert!(paged
        .route(&Request::get("/xrepo/tree?ref=main&page=2"))
        .resp
        .body
        .text()
        .contains("Prev"));
    drop(fx);
}

#[test]
fn raw_and_patch_revalidate_encoding_independently() {
    let Some((fx, srv)) = open() else { return };
    // /raw is never content-coded, so its ETag carries no encoding suffix and a
    // gzip-advertising browser must still get a 304.
    let raw = get(&srv, "/xrepo/raw?ref=main&path=src/main.py");
    assert_eq!(raw.status, 200);
    let etag = raw.header("ETag").expect("etag").to_string();
    assert!(!etag.contains("-gzip"), "{etag}");
    let mut req = Request::get("/xrepo/raw?ref=main&path=src/main.py");
    req.if_none_match = Some(&etag);
    req.accept_encoding = Some("gzip");
    let second = srv.route(&req).resp;
    assert_eq!(second.status, 304);
    assert_eq!(second.header("Vary"), None);

    let sha = &fx.shas[1];
    let target = format!("/xrepo/commit.patch?id={sha}");
    let patch = get(&srv, &target);
    assert_eq!(patch.status, 200);
    let etag = patch.header("ETag").expect("etag").to_string();
    let mut req = Request::get(&target);
    req.if_none_match = Some(&etag);
    req.accept_encoding = Some("gzip");
    assert_eq!(srv.route(&req).resp.status, 304);
    drop(fx);
}

#[test]
fn html_responses_are_content_coded_when_the_client_asks() {
    let Some((fx, srv)) = open() else { return };
    let plain = get(&srv, "/xrepo/");
    assert_eq!(plain.header("Content-Encoding"), None);
    assert_eq!(plain.header("Vary"), Some("Accept-Encoding"));
    let plain_len = plain.body.as_bytes().expect("bytes").len();

    for (accept, coding) in [("gzip", "gzip"), ("deflate", "deflate")] {
        let mut req = Request::get("/xrepo/");
        req.accept_encoding = Some(accept);
        let resp = srv.route(&req).resp;
        assert_eq!(resp.status, 200);
        assert_eq!(resp.header("Content-Encoding"), Some(coding));
        assert_eq!(resp.header("Vary"), Some("Accept-Encoding"));
        let coded = resp.body.as_bytes().expect("bytes");
        assert!(coded.len() < plain_len, "{coding} did not compress");
        // An error page is content-coded on the same terms.
        let mut err = Request::get("/nope/");
        err.accept_encoding = Some(accept);
        let err = srv.route(&err).resp;
        assert_eq!(err.status, 404);
        assert_eq!(err.header("Content-Encoding"), Some(coding));
        assert_eq!(
            resp.header("Content-Length"),
            Some(coded.len().to_string().as_str())
        );
        // The bytes really are the advertised coding, and decode to the page.
        let (back, _) = if coding == "gzip" {
            crawlcore::inflate::inflate_gzip(coded, 64 << 20).expect("gunzip")
        } else {
            crawlcore::inflate::inflate_zlib(coded, 64 << 20).expect("inflate")
        };
        assert_eq!(back, plain.body.as_bytes().expect("bytes"));
    }
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Redirects, streaming and downloads
// --------------------------------------------------------------------------- //

#[test]
fn tree_and_blob_redirect_to_each_other_for_the_wrong_object_type() {
    let Some((fx, srv)) = open() else { return };
    let to_blob = get(&srv, "/xrepo/tree?ref=main&path=README.md");
    assert_eq!(to_blob.status, 302);
    assert_eq!(
        to_blob.header("Location"),
        Some("/xrepo/blob?ref=main&path=README.md")
    );
    let to_tree = get(&srv, "/xrepo/blob?ref=main&path=src");
    assert_eq!(to_tree.status, 302);
    assert_eq!(
        to_tree.header("Location"),
        Some("/xrepo/tree?ref=main&path=src")
    );
    // Under a prefix the redirect keeps the mount point.
    let prefixed = Server::new(Config {
        url_prefix: "/git".to_string(),
        ..Config::new(&fx.root)
    })
    .expect("server");
    assert_eq!(
        get(&prefixed, "/git/xrepo/tree?ref=main&path=README.md").header("Location"),
        Some("/git/xrepo/blob?ref=main&path=README.md")
    );
    drop(fx);
}

#[test]
fn raw_streams_text_binary_and_lfs_content() {
    let Some((fx, srv)) = open() else { return };
    let text = get(&srv, "/xrepo/raw?ref=main&path=apple.txt");
    assert_eq!(text.status, 200);
    assert_eq!(
        text.header("Content-Type"),
        Some("text/plain; charset=utf-8")
    );
    assert_eq!(
        text.header("Content-Disposition"),
        Some("inline; filename=\"apple.txt\"")
    );
    assert_eq!(text.header("Content-Length"), Some("6"));
    assert_eq!(text.header("Connection"), Some("close"));
    assert!(text.close);
    assert_security_headers(&text);
    assert_eq!(drain(text), b"apple\n");

    let binary = get(&srv, "/xrepo/raw?ref=main&path=assets/logo.bin");
    assert_eq!(binary.status, 200);
    assert_eq!(
        binary.header("Content-Type"),
        Some("application/octet-stream")
    );
    assert!(binary
        .header("Content-Disposition")
        .is_some_and(|d| d.starts_with("attachment")));
    assert_eq!(drain(binary), common::BINARY);

    // A file whose *name* is hostile still yields a sanitised filename header.
    let odd = get(&srv, "/xrepo/raw?ref=main&path=weird%20dir/a:b.txt");
    assert_eq!(odd.status, 200);
    assert_eq!(
        odd.header("Content-Disposition"),
        Some("inline; filename=\"a_b.txt\"")
    );

    // The LFS pointer's object is in local storage: the REAL bytes are served.
    let lfs = get(&srv, "/xrepo/raw?ref=main&path=assets/big.lfs");
    assert_eq!(lfs.status, 200);
    assert_eq!(
        lfs.header("Content-Length"),
        Some(common::LFS_BYTES.len().to_string().as_str())
    );
    assert_eq!(drain(lfs), common::LFS_BYTES);

    // A tiny /raw cap truncates rather than streaming without bound.
    let capped = Server::new(Config {
        raw_max_bytes: 3,
        ..Config::new(&fx.root)
    })
    .expect("server");
    let short = get(&capped, "/xrepo/raw?ref=main&path=apple.txt");
    assert_eq!(short.header("Content-Length"), Some("3"));
    assert_eq!(drain(short), b"app");
    drop(fx);
}

#[test]
fn archive_streams_a_gzip_tarball() {
    let Some((fx, srv)) = open() else { return };
    let resp = get(&srv, "/xrepo/archive?ref=main");
    assert_eq!(resp.status, 200);
    assert_eq!(resp.header("Content-Type"), Some("application/gzip"));
    assert_eq!(
        resp.header("Content-Disposition"),
        Some("attachment; filename=\"xrepo-main.tar.gz\"")
    );
    assert_eq!(resp.header("Content-Length"), None); // length unknown
    assert_eq!(resp.header("Connection"), Some("close"));
    assert!(resp.close);
    let bytes = drain(resp);
    assert_eq!(&bytes[..2], &[0x1f, 0x8b], "not gzip");
    let (tar, _) = crawlcore::inflate::inflate_gzip(&bytes, 64 << 20).expect("gunzip");
    let names = String::from_utf8_lossy(&tar).into_owned();
    assert!(names.contains("xrepo-main/README.md"), "prefix missing");
    // An unknown ref is a 404 before anything streams.
    assert_eq!(get(&srv, "/xrepo/archive?ref=nosuchref").status, 404);
    drop(fx);
}

#[test]
fn patch_downloads_are_mailbox_formatted_and_safely_named() {
    let Some((fx, srv)) = open() else { return };
    let sha = &fx.shas[1];
    for action in ["patch", "commit.patch"] {
        let resp = get(&srv, &format!("/xrepo/{action}?id={sha}"));
        assert_eq!(resp.status, 200);
        assert_eq!(
            resp.header("Content-Type"),
            Some("text/plain; charset=utf-8")
        );
        assert_eq!(
            resp.header("Content-Disposition"),
            Some(format!("attachment; filename=\"xrepo-{}.patch\"", &sha[..12]).as_str())
        );
        assert_security_headers(&resp);
        assert!(resp.body.text().starts_with("From "));
    }
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Git Smart HTTP
// --------------------------------------------------------------------------- //

#[test]
fn info_refs_advertises_upload_pack_and_refuses_push() {
    let Some((fx, srv)) = open() else { return };
    let adv = get(&srv, "/xrepo/info/refs?service=git-upload-pack");
    assert_eq!(adv.status, 200);
    assert_eq!(
        adv.header("Content-Type"),
        Some("application/x-git-upload-pack-advertisement")
    );
    assert!(adv
        .header("Cache-Control")
        .is_some_and(|c| c.contains("no-cache")));
    assert_eq!(adv.header("X-Content-Type-Options"), Some("nosniff"));
    assert_eq!(adv.header("Content-Encoding"), None); // git frames its own
    let body = adv.body.as_bytes().expect("bytes");
    assert!(body.starts_with(b"001e# service=git-upload-pack\n0000"));

    // Protocol v2 sends the capability advertisement with no service banner.
    let mut req = Request::get("/xrepo/info/refs?service=git-upload-pack");
    req.git_protocol = Some("version=2");
    let v2 = srv.route(&req).resp;
    assert_eq!(v2.status, 200);
    let body = v2.body.as_bytes().expect("bytes");
    assert!(!body.starts_with(b"001e# service="));
    assert!(String::from_utf8_lossy(&body[..32]).contains("version 2"));

    // Push is refused at the advertisement and at the RPC.
    let push = get(&srv, "/xrepo/info/refs?service=git-receive-pack");
    assert_eq!(push.status, 403);
    assert!(push.close);
    assert!(push.body.text().contains("read-only"));
    let rpc = srv
        .route(&Request::post("/xrepo/git-receive-pack", b"0000"))
        .resp;
    assert_eq!(rpc.status, 403);

    // No service (the dumb protocol) or an unknown one is unsupported.
    assert_eq!(get(&srv, "/xrepo/info/refs").status, 404);
    assert_eq!(get(&srv, "/xrepo/info/refs?service=git-nope").status, 404);
    // Confinement holds on the clone path too.
    assert_eq!(
        get(&srv, "/no-such-repo/info/refs?service=git-upload-pack").status,
        404
    );
    assert_eq!(
        get(&srv, "/..%2f..%2fetc/info/refs?service=git-upload-pack").status,
        400
    );
    drop(fx);
}

#[test]
fn upload_pack_rpc_accepts_a_gzip_body_and_caps_an_over_large_one() {
    let Some((fx, srv)) = open() else { return };
    // A flush-only request is enough to get the result content type.
    let resp = srv
        .route(&Request::post("/xrepo/git-upload-pack", b"0000"))
        .resp;
    assert_eq!(resp.status, 200);
    assert_eq!(
        resp.header("Content-Type"),
        Some("application/x-git-upload-pack-result")
    );
    assert_eq!(resp.header("Connection"), Some("close"));
    assert!(resp.close);
    drop(drain(resp));

    // The same body, gzip-coded, is inflated and served.
    let gzipped = gzip_bytes(b"0000");
    let mut req = Request::post("/xrepo/git-upload-pack", &gzipped);
    req.content_encoding = Some("gzip");
    let resp = srv.route(&req).resp;
    assert_eq!(resp.status, 200);
    assert_eq!(
        resp.header("Content-Type"),
        Some("application/x-git-upload-pack-result")
    );
    drop(drain(resp));

    // An over-large body is refused before any pack work.
    let tiny = Server::new(Config {
        clone_max_body_bytes: 16,
        ..Config::new(&fx.root)
    })
    .expect("server");
    let big = vec![b'X'; 1024];
    let resp = tiny
        .route(&Request::post("/xrepo/git-upload-pack", &big))
        .resp;
    assert_eq!(resp.status, 400);
    // A malformed compressed body is a 400, not a panic.
    let mut req = Request::post("/xrepo/git-upload-pack", b"\x1f\x8b\x08garbage");
    req.content_encoding = Some("gzip");
    assert_eq!(srv.route(&req).resp.status, 400);
    drop(fx);
}

#[test]
fn disabling_clone_404s_every_rpc_and_hides_the_clone_command() {
    let Some((fx, _srv)) = open() else { return };
    let srv = Server::new(Config {
        enable_clone: false,
        ..Config::new(&fx.root)
    })
    .expect("server");
    assert_eq!(
        get(&srv, "/xrepo/info/refs?service=git-upload-pack").status,
        404
    );
    assert_eq!(
        srv.route(&Request::post("/xrepo/git-upload-pack", b"0000"))
            .resp
            .status,
        404
    );
    assert_eq!(
        srv.route(&Request::post("/xrepo/git-receive-pack", b"0000"))
            .resp
            .status,
        404
    );
    // Browsing is unaffected, and no clone command is advertised.
    let mut req = Request::get("/xrepo/");
    req.host = Some("127.0.0.1:1");
    let summary = srv.route(&req).resp;
    assert_eq!(summary.status, 200);
    assert!(!summary.body.text().contains("git clone"));
    drop(fx);
}

#[test]
fn the_summary_advertises_the_clone_url() {
    let Some((fx, srv)) = open() else { return };
    let mut req = Request::get("/xrepo/");
    req.host = Some("127.0.0.1:8801");
    assert!(srv
        .route(&req)
        .resp
        .body
        .text()
        .contains("git clone http://127.0.0.1:8801/xrepo"));

    // A configured external base URL + prefix compose (an onion deployment).
    let onion = Server::new(Config {
        url_prefix: "/git".to_string(),
        clone_base_url: "http://example.onion/".to_string(),
        ..Config::new(&fx.root)
    })
    .expect("server");
    let mut req = Request::get("/git/xrepo/");
    req.host = Some("127.0.0.1:8801");
    assert!(onion
        .route(&req)
        .resp
        .body
        .text()
        .contains("git clone http://example.onion/git/xrepo"));

    // With no Host and no configured base, no clone box is rendered.
    assert!(!get(&srv, "/xrepo/").body.text().contains("git clone"));
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Access control
// --------------------------------------------------------------------------- //

#[test]
fn access_control_is_off_by_default_and_gates_everything_when_on() {
    let Some((fx, srv)) = open() else { return };
    assert!(srv.credential().is_none());
    assert!(srv.authorized(None));
    assert_eq!(get(&srv, "/").status, 200);

    let salt = "abcd1234";
    let spec = format!("alice:{}", gitweb::auth::hash_password("s3cret", salt));
    let gated = Server::new(Config {
        auth: spec,
        ..Config::new(&fx.root)
    })
    .expect("server");
    assert!(gated.credential().is_some());

    // No credentials -> 401 with a Basic challenge, on every endpoint.
    for target in [
        "/",
        "/xrepo/",
        "/health",
        "/metrics",
        "/xrepo/raw?ref=main&path=apple.txt",
    ] {
        let resp = get(&gated, target);
        assert_eq!(resp.status, 401, "{target}");
        assert!(resp
            .header("WWW-Authenticate")
            .is_some_and(|v| v.starts_with("Basic")));
        assert_eq!(
            resp.header("Content-Type"),
            Some("text/plain; charset=utf-8")
        );
        assert!(resp.close);
    }
    // The clone endpoints are gated too.
    assert_eq!(
        gated
            .route(&Request::post("/xrepo/git-upload-pack", b"0000"))
            .resp
            .status,
        401
    );
    assert_eq!(
        get(&gated, "/xrepo/info/refs?service=git-upload-pack").status,
        401
    );

    let auth_header = |user: &str, pw: &str| -> String {
        format!("Basic {}", b64(format!("{user}:{pw}").as_bytes()))
    };
    // Wrong password, wrong user, garbage, and a non-ASCII username: all 401.
    for header in [
        auth_header("alice", "nope"),
        auth_header("bob", "s3cret"),
        "Basic !!!not-base64".to_string(),
        "Bearer token".to_string(),
        auth_header("ü", "s3cret"),
    ] {
        let mut req = Request::get("/xrepo/");
        req.authorization = Some(&header);
        assert_eq!(gated.route(&req).resp.status, 401, "{header}");
    }
    // Correct credentials browse normally.
    let good = auth_header("alice", "s3cret");
    let mut req = Request::get("/xrepo/");
    req.authorization = Some(&good);
    assert_eq!(gated.route(&req).resp.status, 200);
    drop(fx);
}

#[test]
fn a_requested_but_unusable_credential_refuses_to_start() {
    let Some((fx, _srv)) = open() else { return };
    // A malformed --auth aborts startup rather than serving with auth off.
    let err = Server::new(Config {
        auth: "bob:plaintext".to_string(),
        ..Config::new(&fx.root)
    })
    .expect_err("must refuse");
    assert!(err.contains("invalid --auth"), "{err}");

    // An --auth-file with no usable spec likewise refuses.
    let path = fx.root.join("empty_auth.txt");
    std::fs::write(&path, "# only a comment\n\n").expect("write");
    let err = Server::new(Config {
        auth_file: path.display().to_string(),
        ..Config::new(&fx.root)
    })
    .expect_err("must refuse");
    assert!(err.contains("refusing to start"), "{err}");

    // A good --auth-file works, and its credential is used.
    let spec = format!("bob:{}", gitweb::auth::hash_password("pw", "ff"));
    std::fs::write(&path, format!("# comment\n\n{spec}\nignored\n")).expect("write");
    let srv = Server::new(Config {
        auth_file: path.display().to_string(),
        ..Config::new(&fx.root)
    })
    .expect("server");
    assert_eq!(
        srv.credential().map(|c| c.user.clone()),
        Some("bob".to_string())
    );

    // A root that is not a directory is refused too.
    assert!(Server::new(Config::new(fx.root.join("nope")))
        .expect_err("must refuse")
        .contains("root is not a directory"));
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Pagination and the empty-repository paths
// --------------------------------------------------------------------------- //

#[test]
fn an_empty_repository_renders_empty_pages_not_404s() {
    let Some((fx, srv)) = open() else { return };
    for (target, needle) in [
        ("/empty/log", "No commits"),
        ("/empty/graph", "No commits"),
        ("/empty/", "Default branch"),
        ("/empty/refs", "No branches"),
        ("/empty/releases", "No releases yet"),
        ("/empty/atom", "<feed"),
    ] {
        let resp = get(&srv, target);
        assert_eq!(resp.status, 200, "{target}");
        assert!(body_text(&resp).contains(needle), "{target}");
    }
    drop(fx);
}

#[test]
fn page_parameters_are_clamped_not_trusted() {
    let Some((fx, _srv)) = open() else { return };
    let srv = Server::new(Config {
        page_size: 2,
        ..Config::new(&fx.root)
    })
    .expect("server");
    for (target, expect) in [
        ("/xrepo/log?ref=main&page=1", "page 1 of 3"),
        ("/xrepo/log?ref=main&page=3", "page 3 of 3"),
        // Beyond the end, below the start, and unparseable all clamp.
        ("/xrepo/log?ref=main&page=99999", "page 3 of 3"),
        ("/xrepo/log?ref=main&page=-5", "page 1 of 3"),
        ("/xrepo/log?ref=main&page=abc", "page 1 of 3"),
        ("/xrepo/log?ref=main&page=", "page 1 of 3"),
        (
            "/xrepo/log?ref=main&page=99999999999999999999999",
            "page 3 of 3",
        ),
    ] {
        let resp = get(&srv, target);
        assert_eq!(resp.status, 200, "{target}");
        assert!(body_text(&resp).contains(expect), "{target}");
    }
    drop(fx);
}

#[test]
fn an_oversized_blob_is_never_inlined() {
    let Some((fx, _srv)) = open() else { return };
    let srv = Server::new(Config {
        max_blob_bytes: 8,
        ..Config::new(&fx.root)
    })
    .expect("server");
    let resp = get(&srv, "/xrepo/blob?ref=main&path=src/main.py");
    assert_eq!(resp.status, 200);
    let body = body_text(&resp);
    assert!(body.contains("inline display limit"));
    assert!(!body.contains("env python3"));
    drop(fx);
}

#[test]
fn markdown_blobs_render_with_a_source_toggle_and_lfs_pointers_show_the_pointer() {
    let Some((fx, srv)) = open() else { return };
    let rendered = get(&srv, "/xrepo/blob?ref=main&path=docs/guide.md");
    assert_eq!(rendered.status, 200);
    assert!(body_text(&rendered).contains("md-table"));
    assert!(body_text(&rendered).contains("View source"));
    let source = get(
        &srv,
        "/xrepo/blob?ref=main&path=docs/guide.md&display=source",
    );
    assert_eq!(source.status, 200);
    assert!(body_text(&source).contains("class=\"line\""));
    assert!(body_text(&source).contains("View rendered"));

    // The fixture's LFS object *is* in local storage, so the real content shows.
    let lfs = get(&srv, "/xrepo/blob?ref=main&path=assets/big.lfs");
    assert_eq!(lfs.status, 200);
    let body = body_text(&lfs);
    assert!(body.contains("Stored with Git LFS"));
    assert!(body.contains("REAL LFS OBJECT CONTENT"));

    // A binary blob is never inlined.
    let binary = get(&srv, "/xrepo/blob?ref=main&path=assets/logo.bin");
    assert_eq!(binary.status, 200);
    assert!(body_text(&binary).contains("Binary file"));

    // A submodule pin and its .gitmodules URL surface on the tree.
    let tree = get(&srv, "/xrepo/tree?ref=main");
    assert!(body_text(&tree).contains("submodule"));
    assert!(body_text(&tree).contains("https://example.com/vendor.git"));
    drop(fx);
}

// --------------------------------------------------------------------------- //
// The patch/mail archive
// --------------------------------------------------------------------------- //

#[test]
fn the_patch_archive_reads_a_configured_mbox() {
    let Some((fx, srv)) = open() else { return };
    // With no --patches-dir the page shows the empty state.
    let empty = get(&srv, "/xrepo/patches");
    assert_eq!(empty.status, 200);
    assert!(body_text(&empty).contains("No patch archive is configured"));

    let dir = fx.root.join("mboxes");
    std::fs::create_dir_all(&dir).expect("mkdir");
    let mbox = "From git@localhost Mon Sep 17 00:00:00 2001\n\
                From: Alice <alice@example.com>\n\
                Subject: [PATCH] make it <better>\n\
                Date: Wed, 01 Jan 2020 00:00:00 +0000\n\
                Message-Id: <one@example.com>\n\n\
                ---\n diff --git a/x b/x\n";
    std::fs::write(dir.join("xrepo.mbox"), mbox).expect("write mbox");
    let srv = Server::new(Config {
        patches_dir: dir.display().to_string(),
        ..Config::new(&fx.root)
    })
    .expect("server");

    let list = get(&srv, "/xrepo/patches");
    assert_eq!(list.status, 200);
    let body = body_text(&list);
    assert!(body.contains("make it &lt;better&gt;"), "{body}");
    assert!(!body.contains("<better>"));

    // The thread id is the archive's own; a bogus one is a 404.
    let tid = gitweb::mailarchive::thread_id("[PATCH] make it <better>");
    let thread = get(&srv, &format!("/xrepo/patches?thread={tid}"));
    assert_eq!(thread.status, 200);
    assert!(thread.body.text().contains("download mbox"));
    assert_eq!(get(&srv, "/xrepo/patches?thread=nope").status, 404);

    let dl = get(&srv, &format!("/xrepo/patches.mbox?thread={tid}"));
    assert_eq!(dl.status, 200);
    assert_eq!(dl.header("Content-Type"), Some("application/mbox"));
    assert_eq!(
        dl.header("Content-Disposition"),
        Some(format!("attachment; filename=\"{tid}.mbox\"").as_str())
    );
    assert!(dl.body.text().starts_with("From "));
    assert_eq!(get(&srv, "/xrepo/patches.mbox?thread=nope").status, 404);
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Response heads
// --------------------------------------------------------------------------- //

#[test]
fn the_response_head_is_a_pure_function_of_the_response() {
    let Some((fx, srv)) = open() else { return };
    let head = get(&srv, "/xrepo/").head();
    assert!(head.starts_with("HTTP/1.1 200 OK\r\n"));
    assert!(head.contains("Content-Type: text/html; charset=utf-8\r\n"));
    assert!(head.contains("X-Content-Type-Options: nosniff\r\n"));
    assert!(head.contains("X-Frame-Options: DENY\r\n"));
    assert!(head.contains("Referrer-Policy: no-referrer\r\n"));
    assert!(head.contains(&format!("Content-Security-Policy: {CSP}\r\n")));
    assert!(head.contains("form-action 'self'"));
    assert!(head.ends_with("\r\n\r\n"));
    assert!(get(&srv, "/nope/")
        .head()
        .starts_with("HTTP/1.1 404 Not Found\r\n"));
    // A whitespace-only ref is "not given": it falls back to the default branch.
    assert_eq!(get(&srv, "/xrepo/log?ref=%20").status, 200);
    drop(fx);
}

#[test]
fn head_requests_route_exactly_like_get() {
    let Some((fx, srv)) = open() else { return };
    let mut head = Request::get("/xrepo/log?ref=main");
    head.method = "HEAD";
    let a = srv.route(&head).resp;
    let b = get(&srv, "/xrepo/log?ref=main");
    assert_eq!(a.status, b.status);
    assert_eq!(a.headers, b.headers);
    assert_eq!(a.body.as_bytes(), b.body.as_bytes());
    drop(fx);
}

#[test]
fn git_error_variants_map_onto_the_reference_statuses() {
    let Some((fx, srv)) = open() else { return };
    // The mapping is exercised through the routes above; assert the shape here
    // so a future variant cannot silently become a 200.
    for (err, want) in [
        (GitError::BadRequest("x".to_string()), 400u16),
        (GitError::NotFound("x".to_string()), 404),
        (GitError::Failed("x".to_string()), 500),
    ] {
        assert_eq!(err.message(), "x");
        let target = match want {
            400 => "/xrepo/log?ref=--evil",
            404 => "/nope/",
            _ => continue,
        };
        assert_eq!(get(&srv, target).status, want);
    }
    drop(fx);
}

// --------------------------------------------------------------------------- //
// Tiny local helpers
// --------------------------------------------------------------------------- //

/// Standard base64, for the Basic-auth headers above.
fn b64(data: &[u8]) -> String {
    const ALPHABET: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut out = String::new();
    for chunk in data.chunks(3) {
        let b = [
            chunk[0],
            *chunk.get(1).unwrap_or(&0),
            *chunk.get(2).unwrap_or(&0),
        ];
        let n = (u32::from(b[0]) << 16) | (u32::from(b[1]) << 8) | u32::from(b[2]);
        for i in 0..4 {
            if i <= chunk.len() {
                out.push(ALPHABET[((n >> (18 - 6 * i)) & 0x3f) as usize] as char);
            } else {
                out.push('=');
            }
        }
    }
    out
}

/// Minimal gzip framing over stored DEFLATE blocks, for the coded POST body.
fn gzip_bytes(data: &[u8]) -> Vec<u8> {
    let mut out = vec![0x1f, 0x8b, 0x08, 0x00, 0, 0, 0, 0, 0x00, 0xff];
    let n = data.len() as u16;
    out.push(1); // BFINAL, BTYPE = stored
    out.extend_from_slice(&n.to_le_bytes());
    out.extend_from_slice(&(!n).to_le_bytes());
    out.extend_from_slice(data);
    let mut crc = 0xffff_ffffu32;
    for &b in data {
        crc ^= u32::from(b);
        for _ in 0..8 {
            let mask = 0u32.wrapping_sub(crc & 1);
            crc = (crc >> 1) ^ (0xEDB8_8320 & mask);
        }
    }
    out.extend_from_slice(&(!crc).to_le_bytes());
    out.extend_from_slice(&(data.len() as u32).to_le_bytes());
    out
}

// --------------------------------------------------------------------------- //
// XSS / injection: the hostile fixture, served
// --------------------------------------------------------------------------- //

#[test]
fn hostile_repository_content_is_escaped_on_every_served_page() {
    if !common::git_available() {
        eprintln!("skipping: no usable `git` on PATH");
        return;
    }
    let fx = common::build_hostile();
    let srv = Server::new(Config::new(&fx.root)).expect("server");
    let file = crawlcore::urlparse::quote(common::HOSTILE_FILE, "");
    let targets = [
        "/".to_string(),
        "/?q=script".to_string(),
        "/evil-repo/".to_string(),
        "/evil-repo/refs".to_string(),
        "/evil-repo/releases".to_string(),
        "/evil-repo/log".to_string(),
        "/evil-repo/graph".to_string(),
        "/evil-repo/tree".to_string(),
        format!("/evil-repo/blob?path={file}"),
        format!("/evil-repo/blame?path={file}"),
        format!("/evil-repo/history?path={file}"),
        "/evil-repo/search?q=script&type=code".to_string(),
        "/evil-repo/search?q=subject&type=log".to_string(),
        format!("/evil-repo/commit?id={}", fx.head),
        "/evil-repo/patches".to_string(),
        // The XML surfaces too.
        "/evil-repo/atom".to_string(),
        "/evil-repo/releases.atom".to_string(),
        "/evil-repo/opensearch.xml".to_string(),
    ];
    let mut saw_escaped = 0usize;
    for target in &targets {
        let resp = get(&srv, target);
        assert_eq!(resp.status, 200, "{target} -> {}", body_text(&resp));
        let body = body_text(&resp);
        // Not one live tag, event handler or javascript: URL anywhere.
        assert!(!body.contains("<script"), "{target} emitted a script tag");
        assert!(!body.contains("</script>"), "{target} emitted a script tag");
        assert!(
            !body.contains("=\"javascript:"),
            "{target} emitted a javascript: URL in an attribute"
        );
        for handler in [" onerror=", " onclick=", " onload="] {
            assert!(!body.contains(handler), "{target} emitted {handler}");
        }
        if body.contains("&lt;script&gt;") {
            saw_escaped += 1;
        }
    }
    assert!(
        saw_escaped >= 10,
        "only {saw_escaped} pages actually rendered the hostile content"
    );

    // The specific fields, each escaped where it is rendered.
    assert!(get(&srv, "/").body.text().contains("bad&lt;script&gt;dir"));
    assert!(get(&srv, "/evil-repo/")
        .body
        .text()
        .contains("desc &lt;script&gt;alert(&quot;d&quot;)&lt;/script&gt;"));
    let refs = get(&srv, "/evil-repo/refs").body.text();
    assert!(refs.contains("evil&lt;script&gt;")); // the branch name
    assert!(refs.contains("v&lt;1.0&gt;&amp;")); // the tag name
                                                 // git strips `<`/`>` out of an ident, so the hostile author survives as
                                                 // "Eve script"; the subject and body keep their markup, escaped.
    let log = get(&srv, "/evil-repo/log").body.text();
    assert!(log.contains("subject &lt;script&gt;alert(&#x27;xss&#x27;)&lt;/script&gt;"));
    let commit = get(&srv, &format!("/evil-repo/commit?id={}", fx.head))
        .body
        .text();
    assert!(commit.contains("Eve script &lt;eve+x@example.com&gt;"));
    let tree = get(&srv, "/evil-repo/tree").body.text();
    assert!(tree.contains("a&lt;script&gt;&quot;x&quot;.txt")); // the filename
    assert!(tree.contains("<span class=\"muted\">javascript:alert(1)</span>"));
    assert!(tree.contains("href=\"https://example.com/&lt;x&gt;.git\""));
    let blob = get(&srv, &format!("/evil-repo/blob?path={file}"))
        .body
        .text();
    assert!(blob.contains("&lt;script&gt;alert(1)&lt;/script&gt;")); // the content

    // The XML feeds stay well-formed-ish: the markup is entity-escaped there too.
    let atom = get(&srv, "/evil-repo/atom").body.text();
    assert!(atom.contains("&lt;script&gt;"));
    assert!(!atom.contains("<script"));

    // A hostile repository *id* never resolves at all.
    for target in ["/bad<script>dir/", "/bad%3Cscript%3Edir/"] {
        let resp = get(&srv, target);
        assert_eq!(resp.status, 400, "{target}");
        assert!(!resp.body.text().contains("<script"));
    }
    // ...and a hostile ref in the URL is refused before git ever sees it: the
    // branch/tag git accepted can never be addressed, only listed (escaped).
    for target in [
        "/evil-repo/log?ref=evil<script>",
        "/evil-repo/tree?ref=v<1.0>&",
        "/evil-repo/blob?path=../../etc/passwd",
    ] {
        let resp = get(&srv, target);
        assert_eq!(resp.status, 400, "{target}");
        assert!(!resp.body.text().contains("<script"));
    }
    // A well-formed but non-existent hostile filename is a clean 404.
    let missing = get(&srv, "/evil-repo/blob?path=<script>.txt");
    assert_eq!(missing.status, 404);
    assert!(!missing.body.text().contains("<script"));
    // The error page itself escapes whatever it echoes.
    let err = get(&srv, "/evil-repo/patches?thread=%3Cscript%3E");
    assert_eq!(err.status, 404);
    assert!(!err.body.text().contains("<script"));
    drop(fx);
}
