//! Cross-check: the Rust canonicalizer produces the exact same canonical URL,
//! template key, skeleton key and query keys as the Python reference
//! (`legacy-python/onioncrawler/canonical.py`) — which leans on `urllib.parse`
//! and `posixpath.normpath`. The corpus exercises port/default-port handling,
//! `.`/`..` and double-slash path collapse, percent-encode/decode case
//! (uppercase on encode, lowercased only in the skeleton), tracking-param drop,
//! query sort/dedup/blank-values, `+`↔space, `;`-as-literal, fragment drop,
//! relative resolution (absolute/relative/dotdot/query-only/proto-relative/
//! frag-only/empty against a base), i2p/v2 gating, and id-segment collapse.
//! Every expected value was emitted by driving the Python module directly.

use onioncrawler::canonicalize;

fn v3() -> String {
    "a".repeat(56)
}
fn ot() -> String {
    "b".repeat(56)
}
fn c16() -> String {
    "c".repeat(16)
}
fn d52() -> String {
    "d".repeat(52)
}

type Exp = Option<(String, String, String, Vec<&'static str>)>;

fn chk(url: &str, base: Option<&str>, v2: bool, i2p: bool, exp: Exp) {
    let got = canonicalize(url, base, v2, i2p);
    match exp {
        None => assert!(got.is_none(), "expected None for {url:?} base={base:?}"),
        Some((u, t, s, qk)) => {
            let c = got.unwrap_or_else(|| panic!("expected Some for {url:?} base={base:?}"));
            assert_eq!(c.url, u, "url for {url:?}");
            assert_eq!(c.template_key(), t, "template_key for {url:?}");
            assert_eq!(c.skeleton_key(), s, "skeleton_key for {url:?}");
            let qkv: Vec<String> = qk.iter().map(|x| (*x).to_string()).collect();
            assert_eq!(c.query_keys(), qkv, "query_keys for {url:?}");
        }
    }
}

/// `(url, tmpl, skel, qk)` where url/tmpl/skel share the `{V3}.onion` host.
fn e(url_tail: &str, tmpl_tail: &str, skel_tail: &str, qk: Vec<&'static str>) -> Exp {
    let h = v3();
    Some((
        format!("http://{h}.onion{url_tail}"),
        format!("{h}.onion{tmpl_tail}"),
        format!("{h}.onion{skel_tail}"),
        qk,
    ))
}

#[test]
fn canonicalize_xcheck() {
    let v3 = v3();

    // --- absolute, normalization -------------------------------------------
    chk(
        &format!("http://{v3}.onion/"),
        None,
        false,
        false,
        e("/", "/", "/", vec![]),
    );
    chk(
        &format!("http://{v3}.onion"),
        None,
        false,
        false,
        e("/", "/", "/", vec![]),
    );
    chk(
        &format!("HTTP://{}.ONION/Path/To", v3.to_uppercase()),
        None,
        false,
        false,
        e("/Path/To", "/Path/To", "/path/to", vec![]),
    );
    chk(
        &format!("http://{v3}.onion:80/x"),
        None,
        false,
        false,
        e("/x", "/x", "/x", vec![]),
    );
    chk(
        &format!("https://{v3}.onion:443/x"),
        None,
        false,
        false,
        Some((
            format!("https://{v3}.onion/x"),
            format!("{v3}.onion/x"),
            format!("{v3}.onion/x"),
            vec![],
        )),
    );
    chk(
        &format!("http://{v3}.onion:8080/x"),
        None,
        false,
        false,
        e(":8080/x", "/x", "/x", vec![]),
    );
    chk(
        &format!("http://{v3}.onion/a/./b/../c"),
        None,
        false,
        false,
        e("/a/c", "/a/c", "/a/c", vec![]),
    );
    chk(
        &format!("http://{v3}.onion//a///b"),
        None,
        false,
        false,
        e("//a/b", "//a/b", "//a/b", vec![]),
    );
    chk(
        &format!("http://{v3}.onion/a/b/"),
        None,
        false,
        false,
        e("/a/b/", "/a/b/", "/a/b/", vec![]),
    );
    chk(
        &format!("http://{v3}.onion/../../etc"),
        None,
        false,
        false,
        e("/etc", "/etc", "/etc", vec![]),
    );
    chk(
        &format!("http://{v3}.onion/%7Euser/%2e/x"),
        None,
        false,
        false,
        e("/~user/x", "/~user/x", "/~user/x", vec![]),
    );
    chk(
        &format!("http://{v3}.onion/a b/c"),
        None,
        false,
        false,
        e("/a%20b/c", "/a%20b/c", "/a%20b/c", vec![]),
    );
    chk(
        &format!("http://{v3}.onion/café/menü"),
        None,
        false,
        false,
        e(
            "/caf%C3%A9/men%C3%BC",
            "/caf%C3%A9/men%C3%BC",
            "/caf%c3%a9/men%c3%bc",
            vec![],
        ),
    );

    // --- query cleaning -----------------------------------------------------
    chk(
        &format!("http://{v3}.onion/s?utm_source=x&q=1&ref=y"),
        None,
        false,
        false,
        e("/s?q=1", "/s?q", "/s?q", vec!["q"]),
    );
    chk(
        &format!("http://{v3}.onion/s?a=&b=2&c"),
        None,
        false,
        false,
        e("/s?a=&b=2&c=", "/s?a,b,c", "/s?a,b,c", vec!["a", "b", "c"]),
    );
    chk(
        &format!("http://{v3}.onion/s?b=2&a=1"),
        None,
        false,
        false,
        e("/s?a=1&b=2", "/s?a,b", "/s?a,b", vec!["a", "b"]),
    );
    chk(
        &format!("http://{v3}.onion/s?a=2&a=1"),
        None,
        false,
        false,
        e("/s?a=1&a=2", "/s?a", "/s?a", vec!["a"]),
    );
    chk(
        &format!("http://{v3}.onion/s?q=hello world&r=a+b"),
        None,
        false,
        false,
        e("/s?q=hello+world&r=a+b", "/s?q,r", "/s?q,r", vec!["q", "r"]),
    );
    chk(
        &format!("http://{v3}.onion/s?a=1;b=2"),
        None,
        false,
        false,
        e("/s?a=1%3Bb%3D2", "/s?a", "/s?a", vec!["a"]),
    );
    chk(
        &format!("http://{v3}.onion/s?path=/x/y&eq=a%3Db"),
        None,
        false,
        false,
        e(
            "/s?eq=a%3Db&path=%2Fx%2Fy",
            "/s?eq,path",
            "/s?eq,path",
            vec!["eq", "path"],
        ),
    );
    chk(
        &format!("http://{v3}.onion/x?y=1#frag"),
        None,
        false,
        false,
        e("/x?y=1", "/x?y", "/x?y", vec!["y"]),
    );

    // --- rejections + i2p/v2 gating ----------------------------------------
    chk("http://example.com/", None, false, false, None);
    chk(&format!("ftp://{v3}.onion/"), None, false, false, None);
    chk(
        &format!("http://{}.onion/", c16()),
        None,
        false,
        false,
        None,
    );
    chk(
        &format!("http://{}.onion/x", c16()),
        None,
        true,
        false,
        Some((
            format!("http://{}.onion/x", c16()),
            format!("{}.onion/x", c16()),
            format!("{}.onion/x", c16()),
            vec![],
        )),
    );
    chk("http://stats.i2p/x", None, false, false, None);
    chk(
        "http://stats.i2p/x",
        None,
        false,
        true,
        Some((
            "http://stats.i2p/x".into(),
            "stats.i2p/x".into(),
            "stats.i2p/x".into(),
            vec![],
        )),
    );
    chk(
        &format!("http://{}.b32.i2p/x", d52()),
        None,
        false,
        true,
        Some((
            format!("http://{}.b32.i2p/x", d52()),
            format!("{}.b32.i2p/x", d52()),
            format!("{}.b32.i2p/x", d52()),
            vec![],
        )),
    );

    // --- relative resolution against a base --------------------------------
    let base_ax = format!("http://{v3}.onion/a/x");
    let base_ab = format!("http://{v3}.onion/a/b");
    let base_abc = format!("http://{v3}.onion/a/b/c");
    let base_a = format!("http://{v3}.onion/a");
    let base_abq = format!("http://{v3}.onion/a/b?q=1");
    chk(
        "/b/c",
        Some(&base_ax),
        false,
        false,
        e("/b/c", "/b/c", "/b/c", vec![]),
    );
    chk(
        "sub/page",
        Some(&base_ab),
        false,
        false,
        e("/a/sub/page", "/a/sub/page", "/a/sub/page", vec![]),
    );
    chk(
        "../c",
        Some(&base_abc),
        false,
        false,
        e("/a/c", "/a/c", "/a/c", vec![]),
    );
    chk(
        "?q=1",
        Some(&base_ab),
        false,
        false,
        e("/a/b?q=1", "/a/b?q", "/a/b?q", vec!["q"]),
    );
    chk(
        &format!("//{}.onion/x", ot()),
        Some(&base_a),
        false,
        false,
        Some((
            format!("http://{}.onion/x", ot()),
            format!("{}.onion/x", ot()),
            format!("{}.onion/x", ot()),
            vec![],
        )),
    );
    chk(
        "#top",
        Some(&base_abq),
        false,
        false,
        e("/a/b?q=1", "/a/b?q", "/a/b?q", vec!["q"]),
    );
    chk(
        "",
        Some(&base_abq),
        false,
        false,
        e("/a/b?q=1", "/a/b?q", "/a/b?q", vec!["q"]),
    );

    // --- skeleton / template shape -----------------------------------------
    chk(
        &format!("http://{v3}.onion/post/12345/comments"),
        None,
        false,
        false,
        e(
            "/post/12345/comments",
            "/post/12345/comments",
            "/post/#/comments",
            vec![],
        ),
    );
    chk(
        &format!("http://{v3}.onion/x/abcdef0123456789/y"),
        None,
        false,
        false,
        e(
            "/x/abcdef0123456789/y",
            "/x/abcdef0123456789/y",
            "/x/#/y",
            vec![],
        ),
    );
    chk(
        &format!("http://{v3}.onion/2020/01/02/title"),
        None,
        false,
        false,
        e(
            "/2020/01/02/title",
            "/2020/01/02/title",
            "/#/#/#/title",
            vec![],
        ),
    );
    chk(
        &format!("http://{v3}.onion/Foo/BarBaz"),
        None,
        false,
        false,
        e("/Foo/BarBaz", "/Foo/BarBaz", "/foo/barbaz", vec![]),
    );
    chk(
        &format!("http://{v3}.onion/cal?year=2020&month=1&day=2"),
        None,
        false,
        false,
        e(
            "/cal?day=2&month=1&year=2020",
            "/cal?day,month,year",
            "/cal?day,month,year",
            vec!["day", "month", "year"],
        ),
    );
}
