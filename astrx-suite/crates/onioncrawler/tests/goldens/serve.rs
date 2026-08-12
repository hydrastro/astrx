// GENERATED FILE — do not edit by hand.
//
// The byte-identical `onioncrawler::serve` cross-check corpus: the stylesheet,
// the search form, the facet row, the pager and a result row, all rendered by
// the **real** Python `onioncrawler.search.SearchApp`. Regenerate with:
//
// ```text
// cd astrx-suite
// PYTHONPATH=legacy-python/onioncrawler TZ=UTC \
//     python3 crates/onioncrawler/tests/regen_serve_goldens.py \
//     > crates/onioncrawler/tests/goldens/serve.rs
// ```

/// The two fixture hosts (56-char v3 labels, so the reference's host-filter
/// validation admits them).
pub const HOST: &str = "abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion";
pub const HOST2: &str = "bcdefghijklmnopqrstuvwxyz2345672bcdefghijklmnopqrstuvwxy.onion";

pub const PY_CSS: &str = "\nbody{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;\nmax-width:760px;margin:0 auto;padding:1.2rem;color:#111;background:#fafafa;line-height:1.45}\nheader a{text-decoration:none;color:#5b21b6}\nh1{font-size:1.4rem;margin:.2rem 0 1rem}\nform{margin-bottom:1rem}\n.row{display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap}\ninput[type=text]{flex:1;min-width:12rem;padding:.55rem .7rem;font-size:1rem;border:1px solid #ccc;border-radius:6px}\ninput[type=date],select{padding:.4rem;border:1px solid #ccc;border-radius:6px}\n.filters input[type=text]{min-width:8rem}\nbutton{padding:.55rem 1rem;font-size:1rem;border:0;border-radius:6px;background:#5b21b6;color:#fff;cursor:pointer}\n.result{margin:1rem 0;padding-bottom:.8rem;border-bottom:1px solid #eee}\n.result .title{font-size:1.08rem;font-weight:600;color:#1a0dab}\n.result .url{color:#0a7d33;font-size:.86rem;word-break:break-all}\n.result .snip{color:#333;font-size:.95rem;margin-top:.15rem}\n.result .meta{color:#888;font-size:.78rem;margin-top:.2rem}\nmark{background:#fde68a;padding:0 1px}\n.nav{margin-top:1.2rem;display:flex;gap:1rem}\n.facets{font-size:.82rem;color:#555;margin:.4rem 0 1rem}\n.facets a{color:#5b21b6;text-decoration:none;margin-right:.5rem}\n.muted{color:#888;font-size:.85rem}\nfooter{margin-top:2rem;color:#999;font-size:.78rem}\n";

pub const PY_MAX_PAGE: usize = 100000;

pub const PY_FORMS: &[(&str, &str)] = &[
    ("empty", "<form action='/search' method='get'><div class=row><input type=text name=q value=\"\" placeholder='search indexed .onion pages' autofocus><button type=submit>Search</button></div><div class='row filters'><input type=text name=host value=\"\" placeholder='host filter (x.onion)'><label>lang <select name=lang><option value=\"\" selected></option><option value=\"de\">de</option><option value=\"en\">en</option><option value=\"es\">es</option><option value=\"fr\">fr</option><option value=\"it\">it</option><option value=\"pt\">pt</option><option value=\"ru\">ru</option><option value=\"un\">un</option></select></label><label>from <input type=date name=since value=\"\"></label><label>to <input type=date name=until value=\"\"></label></div></form>"),
    ("query-only", "<form action='/search' method='get'><div class=row><input type=text name=q value=\"widget\" placeholder='search indexed .onion pages' autofocus><button type=submit>Search</button></div><div class='row filters'><input type=text name=host value=\"\" placeholder='host filter (x.onion)'><label>lang <select name=lang><option value=\"\" selected></option><option value=\"de\">de</option><option value=\"en\">en</option><option value=\"es\">es</option><option value=\"fr\">fr</option><option value=\"it\">it</option><option value=\"pt\">pt</option><option value=\"ru\">ru</option><option value=\"un\">un</option></select></label><label>from <input type=date name=since value=\"\"></label><label>to <input type=date name=until value=\"\"></label></div></form>"),
    ("all-filters", "<form action='/search' method='get'><div class=row><input type=text name=q value=\"widget\" placeholder='search indexed .onion pages' autofocus><button type=submit>Search</button></div><div class='row filters'><input type=text name=host value=\"abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion\" placeholder='host filter (x.onion)'><label>lang <select name=lang><option value=\"\"></option><option value=\"de\" selected>de</option><option value=\"en\">en</option><option value=\"es\">es</option><option value=\"fr\">fr</option><option value=\"it\">it</option><option value=\"pt\">pt</option><option value=\"ru\">ru</option><option value=\"un\">un</option></select></label><label>from <input type=date name=since value=\"2024-01-02\"></label><label>to <input type=date name=until value=\"2024-03-04\"></label></div></form>"),
    ("hostile", "<form action='/search' method='get'><div class=row><input type=text name=q value=\"&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;\" placeholder='search indexed .onion pages' autofocus><button type=submit>Search</button></div><div class='row filters'><input type=text name=host value=\"\" placeholder='host filter (x.onion)'><label>lang <select name=lang><option value=\"\"></option><option value=\"de\">de</option><option value=\"en\">en</option><option value=\"es\">es</option><option value=\"fr\">fr</option><option value=\"it\">it</option><option value=\"pt\">pt</option><option value=\"ru\">ru</option><option value=\"un\">un</option></select></label><label>from <input type=date name=since value=\"&quot;onmouseover=&quot;x\"></label><label>to <input type=date name=until value=\"&lt;script&gt;\"></label></div></form>"),
];

pub const PY_FACETS: &[(&str, &str)] = &[
    ("no-filters", "<div class=facets>hosts: <a href='/search?q=widget&page=1&host=abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion'>abcdefghijklmnop… (17)</a> <a href='/search?q=widget&page=1&host=bcdefghijklmnopqrstuvwxyz2345672bcdefghijklmnopqrstuvwxy.onion'>bcdefghijklmnopq… (8)</a> &nbsp;·&nbsp; langs: <a href='/search?q=widget&page=1&lang=en'>en (17)</a> <a href='/search?q=widget&page=1&lang=de'>de (8)</a></div>"),
    ("host-filtered", "<div class=facets>hosts: <a href='/search?q=widget&page=1&host=abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion&since=2023-01-01&until=2038-01-01'>abcdefghijklmnop… (17)</a> &nbsp;·&nbsp; langs: <a href='/search?q=widget&page=1&host=abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion&lang=en&since=2023-01-01&until=2038-01-01'>en (17)</a></div>"),
];

pub const PY_PAGE: &[(&str, &str)] = &[
    ("result", "<div class=result><div class=title>Widget shop</div><div class=url>http://abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion/</div><div class=snip>the widget <mark>emporium</mark> is the one that is in the shop and it is…</div><div class=meta>host: abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion · lang: en · last seen: 2023-11-14 22:13 UTC</div></div>"),
    ("window", "<p class=muted>Results 1-1 of 1 match(es)</p>"),
    ("nav-empty", "<div class=nav></div>"),
    ("footer", "<footer>No JavaScript. No logging. Bound to localhost. Operator is responsible for abuse filtering.</footer>"),
    ("no-results", "<p class=muted>No results.</p>"),
    ("window-paged", "<p class=muted>Results 11-20 of 25 match(es)</p>"),
    ("nav-paged", "<div class=nav><a href='/search?q=widget&page=1&since=2023-01-01&until=2038-01-01'>« Prev</a><a href='/search?q=widget&page=3&since=2023-01-01&until=2038-01-01'>Next »</a></div>"),
    ("landing", "<p class=muted>25 pages indexed. Enter a query above. This index serves .onion pages only.</p>"),
];
