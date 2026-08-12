//! The no-JS search server — HTML UI + JSON API over the [`Index`].
//!
//! A port of the read side of the Python `websearch.server`, following
//! onioncrawler's serving pattern: **pure** renderers + a pure [`SearchServer::route`]
//! over an `Arc<Mutex<Index>>` (so routing is unit-tested without a socket), and
//! the async accept loop behind the `net` feature. Every field rendered into HTML
//! is escaped; snippets arrive already-escaped from [`crate::ranking::make_snippet`]
//! (only `<mark>` markup survives), so the results page is XSS-safe.
//!
//! The renderers take everything they render as arguments — including the wall
//! clock: the search `now` and its measured `elapsed` are read in the route layer
//! and handed down, so a renderer's output depends only on its inputs.
//! [`SearchServer::with_frontier`] adds an OPTIONAL second handle, read for the
//! `/about` Frontier table alone.
//!
//! Routes: `/` + `/search` (HTML results, `?type=news|files` verticals),
//! `/images` + `/videos` (the media verticals, `?q=`), `/api/search` (JSON, the
//! endpoint the PHP bridge calls; `?limit=`/`?page_size=`/`?sort=` supported),
//! `/suggest` (OpenSearch Suggestions JSON typeahead, `?q=`), `/about` +
//! `/stats`, `/opensearch.xml`, `/metrics`, `/healthz`, `/style.css`,
//! `/favicon.ico`.
//!
//! # Not ported from the Python `websearch.server`
//!
//! Three pieces of the reference server have NO equivalent here. They are gaps,
//! not decisions the code makes elsewhere — anything deployed on an untrusted
//! network must supply them at the reverse proxy:
//!
//! - **Per-client rate limiting.** Python has a token-bucket `RateLimiter`
//!   (`server.py:72`) wired through `make_server(rate=…, burst=…)` and the
//!   `--rate`/`--burst` CLI flags, answering `429` when a client outruns it.
//!   This server applies no rate limit of any kind, so every route is served as
//!   fast as it is asked for.
//! - **HTTP Basic authentication.** Python's `_authorized` (`server.py:610`)
//!   gates every route except `_OPEN_PATHS` (`server.py:47`) behind
//!   `make_server(auth=(user, pw))` / `--auth user:pass`, answering `401` with a
//!   `WWW-Authenticate` challenge. Here every route is public and unauthenticated;
//!   there is no `auth` parameter to pass.
//! - **`/similar`** — the `more_like_this` "related pages" route, deferred.
//!
//! [`SearchServer::route`] therefore never returns `401` or `429`.

use crate::frontier::Frontier;
use crate::index::{ImageResult, Index, Stats, VideoResult};
use crate::ranking::{search, Query, SearchOpts, SearchResult};
use crawlcore::urlparse::{parse_qsl, urlencode, urlsplit};
use std::collections::BTreeMap;
use std::sync::{Arc, Mutex};

const PAGE_SIZE: usize = 10;
const API_MAX_LIMIT: usize = 200;
/// Max image/video results per vertical page (Python `IMAGE_LIMIT`).
const IMAGE_LIMIT: usize = 30;
/// Hard cap on the `/suggest` `q` at the edge — the echoed query and parse cost
/// bound, applied before [`crate::suggest::suggest`]'s own internal `q[:64]`
/// (Python `SUGGEST_MAX_QUERY`).
const SUGGEST_MAX_QUERY: usize = 128;

/// HTML-escape (`&`,`<`,`>`,`"`,`'`) — Python `html.escape(quote=True)`.
#[must_use]
pub fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#x27;"),
            _ => out.push(c),
        }
    }
    out
}

/// Escape a string for embedding in a JSON string literal (no surrounding quotes).
#[must_use]
pub fn json_str(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    for c in s.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out
}

fn jq(s: &str) -> String {
    format!("\"{}\"", json_str(s))
}

fn json_str_array(v: &[String]) -> String {
    let items: Vec<String> = v.iter().map(|s| jq(s)).collect();
    format!("[{}]", items.join(","))
}

/// A quoted JSON string literal byte-identical to Python
/// `json.dumps(s, ensure_ascii=False)`: short escapes for `"`, `\`, and
/// `\b`/`\f`/`\n`/`\r`/`\t`, `\uXXXX` (lowercase) for the other C0 control
/// characters, and every other code point — non-ASCII, and `<`/`>`/`&` — emitted
/// raw. Distinct from [`json_str`], which lacks the `\b`/`\f` short forms.
fn json_dq(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('"');
    for c in s.chars() {
        match c {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\u{08}' => out.push_str("\\b"),
            '\u{0c}' => out.push_str("\\f"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            c if (c as u32) < 0x20 => out.push_str(&format!("\\u{:04x}", c as u32)),
            c => out.push(c),
        }
    }
    out.push('"');
    out
}

/// The OpenSearch Suggestions JSON body `[query, [completions…]]`, byte-identical
/// to Python `json.dumps([q, terms], ensure_ascii=False)` — note the SPACED
/// separators (`", "`), unlike the compact [`json_str_array`] used by the search
/// API.
fn suggestions_json(q: &str, terms: &[String]) -> String {
    let items: Vec<String> = terms.iter().map(|t| json_dq(t)).collect();
    format!("[{}, [{}]]", json_dq(q), items.join(", "))
}

fn json_opt_str(v: &Option<String>) -> String {
    v.as_ref().map_or_else(|| "null".to_string(), |s| jq(s))
}

fn json_opt_num(v: Option<f64>) -> String {
    v.map_or_else(|| "null".to_string(), fmt_num)
}

/// Format an f64 without a trailing `.0` for integers (compact JSON numbers).
fn fmt_num(n: f64) -> String {
    if n.fract() == 0.0 && n.abs() < 1e15 {
        format!("{}", n as i64)
    } else {
        format!("{n}")
    }
}

/// An HTTP reply.
#[derive(Clone, Debug, PartialEq)]
pub struct Resp {
    /// Status code.
    pub status: u16,
    /// `Content-Type` header value.
    pub ctype: &'static str,
    /// Response body.
    pub body: String,
}

impl Resp {
    fn html(status: u16, body: String) -> Self {
        Resp {
            status,
            ctype: "text/html; charset=utf-8",
            body,
        }
    }
    fn json(status: u16, body: String) -> Self {
        Resp {
            status,
            ctype: "application/json; charset=utf-8",
            body,
        }
    }
    fn text(status: u16, body: &str) -> Self {
        Resp {
            status,
            ctype: "text/plain; charset=utf-8",
            body: body.to_string(),
        }
    }
    fn css(body: &'static str) -> Self {
        Resp {
            status: 200,
            ctype: "text/css; charset=utf-8",
            body: body.to_string(),
        }
    }
    fn xml(body: String) -> Self {
        Resp {
            status: 200,
            ctype: "application/opensearchdescription+xml; charset=utf-8",
            body,
        }
    }
    fn suggestions(status: u16, body: String) -> Self {
        Resp {
            status,
            ctype: "application/x-suggestions+json; charset=utf-8",
            body,
        }
    }
}

/// The stylesheet served at `/style.css`.
///
/// Byte-identical to the Python `websearch.server.STYLE`. It is kept verbatim
/// (a raw string, newlines and all) rather than re-flowed, so a diff against
/// the reference is trivial — an earlier hand-transcription silently dropped
/// half the rules, which left the vertical tabs, the image grid, the pager and
/// the stats tables unstyled.
const STYLE: &str = r#"
:root { color-scheme: light dark; }
* { box-sizing: border-box; }
body { font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
       Helvetica, Arial, sans-serif; margin: 0; background: #fafafa;
       color: #1a1a1a; }
a { color: #1a56db; text-decoration: none; }
a:hover { text-decoration: underline; }
header { background: #fff; border-bottom: 1px solid #e5e5e5; padding: 18px 20px; }
.wrap { max-width: 760px; margin: 0 auto; }
.brand { font-weight: 700; font-size: 20px; letter-spacing: -.3px; color:#111; }
.brand span { color: #1a56db; }
form.search { display: flex; gap: 8px; margin-top: 12px; }
form.search input[type=text] { flex: 1; padding: 11px 14px; font-size: 16px;
       border: 1px solid #cbcbcb; border-radius: 8px; background:#fff; }
form.search button { padding: 11px 18px; font-size: 15px; border: 0;
       border-radius: 8px; background: #1a56db; color: #fff; cursor: pointer; }
main { padding: 20px; }
.meta { color: #666; font-size: 13px; margin: 4px 0 18px; }
.result { margin: 0 0 22px; }
.result .url { color: #0a7d33; font-size: 13px; word-break: break-all; }
.result h2 { font-size: 18px; margin: 2px 0 3px; font-weight: 600; }
.result .snippet { color: #333; font-size: 14px; }
.result .sub { color: #777; font-size: 12px; margin-top: 3px; }
mark { background: #fff2ac; color: inherit; padding: 0 1px; border-radius: 2px; }
.pager { margin: 26px 0; display: flex; gap: 14px; align-items: center; }
.pager a { padding: 7px 14px; border: 1px solid #cbcbcb; border-radius: 8px;
       background:#fff; }
.empty { color:#555; }
table.stats { border-collapse: collapse; }
table.stats td, table.stats th { text-align: left; padding: 4px 18px 4px 0; }
footer { color:#999; font-size:12px; padding: 24px 20px; }
code { background:#eee; padding:1px 5px; border-radius:4px; }
.tabs { display:flex; gap:6px; margin: 2px 0 16px; }
.tabs a.tab { padding:6px 14px; border:1px solid #cbcbcb; border-radius:8px;
       background:#fff; color:#333; font-size:14px; }
.tabs a.tab.active { background:#1a56db; border-color:#1a56db; color:#fff; }
.imggrid { display:flex; flex-wrap:wrap; gap:14px; }
figure.imgcard { margin:0; width:180px; }
figure.imgcard img.thumb { width:180px; height:135px; object-fit:cover;
       background:#eee; border:1px solid #e5e5e5; border-radius:8px; }
figure.imgcard .thumb.noimg { width:180px; height:135px; display:flex;
       align-items:center; justify-content:center; background:#eee;
       border:1px solid #e5e5e5; border-radius:8px; color:#888;
       font-size:13px; text-transform:uppercase; letter-spacing:.05em; }
figure.imgcard figcaption { font-size:12px; color:#444; margin-top:4px;
       overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
figure.imgcard .imghost { display:block; color:#0a7d33; }
@media (prefers-color-scheme: dark) {
  body { background:#161616; color:#e8e8e8; }
  header, form.search input[type=text] { background:#1f1f1f; border-color:#333; }
  .brand { color:#f2f2f2; } a { color:#7aa2f7; }
  .result .url { color:#5fbf7f; } .result .snippet { color:#cfcfcf; }
  mark { background:#5b5220; color:#fff; }
  .pager a { background:#1f1f1f; border-color:#333; }
  .tabs a.tab { background:#1f1f1f; border-color:#333; color:#cfcfcf; }
  .tabs a.tab.active { background:#1a56db; border-color:#1a56db; color:#fff; }
  figure.imgcard figcaption { color:#cfcfcf; }
  figure.imgcard .imghost { color:#5fbf7f; }
}
"#;

fn wrap_page(title: &str, body: &str) -> String {
    format!(
        "<!doctype html><html lang=en><head><meta charset=utf-8>\
<meta name=viewport content='width=device-width, initial-scale=1'>\
<title>{}</title><link rel=stylesheet href=/style.css>\
<link rel=search type='application/opensearchdescription+xml' title='astrx search' href=/opensearch.xml></head>\
<body>{}</body></html>",
        esc(title),
        body
    )
}

fn header(q: &str) -> String {
    format!(
        "<header><div class=wrap>\
<a class=brand href='/'>astrx<span>search</span></a>\
<form class=search method=get action='/search'>\
<input type=text name=q value='{}' placeholder='Search the crawl…' autofocus autocomplete=off>\
<button type=submit>Search</button></form></div></header>",
        esc(q)
    )
}

fn opensearch_xml(base: &str) -> String {
    let b = esc(base);
    format!(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\
<OpenSearchDescription xmlns=\"http://a9.com/-/spec/opensearch/1.1/\">\n\
  <ShortName>astrx search</ShortName>\n\
  <Description>Zero-dependency clearnet search engine (crawler + inverted index + BM25).</Description>\n\
  <InputEncoding>UTF-8</InputEncoding>\n\
  <Url type=\"text/html\" method=\"get\" template=\"{b}/search?q={{searchTerms}}\"/>\n\
  <Url type=\"application/json\" method=\"get\" template=\"{b}/api/search?q={{searchTerms}}\"/>\n\
</OpenSearchDescription>\n"
    )
}

fn fmt_date(ts: f64) -> String {
    if ts <= 0.0 {
        return String::new();
    }
    let days = (ts as i64).div_euclid(86400);
    let (y, m, d) = civil_from_days(days);
    format!("{y:04}-{m:02}-{d:02}")
}

/// Inverse of `days_from_civil` — a day count since the epoch to `(y, m, d)`.
fn civil_from_days(z: i64) -> (i64, i64, i64) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097; // [0, 146096]
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365; // [0, 399]
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100); // [0, 365]
    let mp = (5 * doy + 2) / 153; // [0, 11]
    let d = doy - (153 * mp + 2) / 5 + 1; // [1, 31]
    let m = if mp < 10 { mp + 3 } else { mp - 9 }; // [1, 12]
    (if m <= 2 { y + 1 } else { y }, m, d)
}

fn active_filters(q: &Query) -> String {
    let mut bits: Vec<String> = Vec::new();
    if let Some(s) = &q.site {
        bits.push(format!("site:{s}"));
    }
    if let Some(l) = &q.lang {
        bits.push(format!("lang:{l}"));
    }
    if let Some(f) = &q.filetype {
        bits.push(format!("filetype:{f}"));
    }
    if !q.intitle.is_empty() {
        bits.push(format!("intitle:{}", q.intitle.join(" ")));
    }
    if let Some(a) = q.after {
        bits.push(format!("after:{}", fmt_date(a)));
    }
    if let Some(b) = q.before {
        bits.push(format!("before:{}", fmt_date(b)));
    }
    if bits.is_empty() {
        String::new()
    } else {
        format!("<div class=meta>Filters: {}</div>", esc(&bits.join(" · ")))
    }
}

fn result_row(r: &SearchResult) -> String {
    let mut s = String::from("<div class=result>");
    s.push_str(&format!("<div class=url>{}</div>", esc(&r.url)));
    s.push_str(&format!(
        "<h2><a href='{}'>{}</a></h2>",
        esc(&r.url),
        esc(if r.title.is_empty() { &r.url } else { &r.title })
    ));
    if !r.snippet.is_empty() {
        s.push_str(&format!("<div class=snippet>{}</div>", r.snippet));
    }
    let mut sub = esc(&r.host);
    let d = fmt_date(r.fetched_at);
    if !d.is_empty() {
        sub.push_str(" &middot; ");
        sub.push_str(&d);
    }
    if !r.lang.is_empty() {
        sub.push_str(" &middot; ");
        sub.push_str(&esc(&r.lang));
    }
    s.push_str(&format!("<div class=sub>{sub}</div></div>"));
    s
}

fn vertical_tabs(q: &str, active: &str) -> String {
    let qs = if q.is_empty() {
        String::new()
    } else {
        format!("?{}", urlencode(&[("q".to_string(), q.to_string())]))
    };
    // A plain tab (Web / Images / Videos) links to its own path with `?q=` appended.
    let tab = |href: &str, label: &str, key: &str| {
        let cls = if key == active { "tab active" } else { "tab" };
        format!(
            "<a class='{cls}' href='{}'>{label}</a>",
            esc(&format!("{href}{qs}"))
        )
    };
    // A `/search?type=` vertical (News / Files) puts `type` and `q` in one query.
    let vtab = |label: &str, key: &str| {
        let href = if q.is_empty() {
            format!(
                "/search?{}",
                urlencode(&[("type".to_string(), key.to_string())])
            )
        } else {
            format!(
                "/search?{}",
                urlencode(&[
                    ("type".to_string(), key.to_string()),
                    ("q".to_string(), q.to_string()),
                ])
            )
        };
        let cls = if key == active { "tab active" } else { "tab" };
        format!("<a class='{cls}' href='{}'>{label}</a>", esc(&href))
    };
    format!(
        "<div class=tabs>{}{}{}{}{}</div>",
        tab("/search", "Web", "web"),
        vtab("News", "news"),
        tab("/images", "Images", "images"),
        tab("/videos", "Videos", "videos"),
        vtab("Files", "files"),
    )
}

/// Whole seconds → `H:MM:SS` / `M:SS` (empty string if unknown/negative).
/// Mirrors the Python `_fmt_duration`.
fn fmt_duration(secs: Option<i64>) -> String {
    let s = match secs {
        Some(s) => s,
        None => return String::new(),
    };
    if s < 0 {
        return String::new();
    }
    let h = s / 3600;
    let rem = s % 3600;
    let m = rem / 60;
    let sec = rem % 60;
    if h != 0 {
        format!("{h}:{m:02}:{sec:02}")
    } else {
        format!("{m}:{sec:02}")
    }
}

/// No-JS image results. Each thumbnail is a plain `<img src>` pointing at the
/// ORIGINAL remote URL (the browser loads it — the server never fetches it), and
/// every field is HTML-escaped. Byte-identical to the Python `_render_images`.
fn render_images(q: &str, images: &[ImageResult]) -> String {
    let mut s = String::from("<main><div class=wrap>");
    s.push_str(&vertical_tabs(q, "images"));
    if q.is_empty() {
        s.push_str(
            "<p class=meta>Enter a query to search images harvested from crawled pages \
(no image is fetched by the server).</p>",
        );
    } else if images.is_empty() {
        s.push_str(&format!(
            "<p class=empty>No images matched <strong>{}</strong>.</p>",
            esc(q)
        ));
    } else {
        s.push_str(&format!(
            "<div class=meta>{} image result{}</div>",
            images.len(),
            if images.len() == 1 { "" } else { "s" }
        ));
        s.push_str("<div class=imggrid>");
        for im in images {
            let cap_src = if !im.alt.is_empty() {
                &im.alt
            } else if !im.title.is_empty() {
                &im.title
            } else {
                &im.src
            };
            s.push_str(&format!(
                "<figure class=imgcard>\
<a href='{page}' rel='noreferrer nofollow'>\
<img class=thumb loading=lazy referrerpolicy=no-referrer src='{src}' alt='{alt}'></a>\
<figcaption>{cap}<span class=imghost>{host}</span></figcaption>\
</figure>",
                page = esc(&im.page_url),
                src = esc(&im.src),
                alt = esc(&im.alt),
                cap = esc(cap_src),
                host = esc(&im.host),
            ));
        }
        s.push_str("</div>");
    }
    s.push_str("<footer><a href='/'>&larr; Back to search</a></footer></div></main>");
    s
}

/// No-JS video results. Each card links to the source PAGE and shows the harvested
/// thumbnail (loaded by the browser from its ORIGINAL URL — the server fetches
/// nothing); every field is HTML-escaped. Byte-identical to Python `_render_videos`.
fn render_videos(q: &str, videos: &[VideoResult]) -> String {
    let mut s = String::from("<main><div class=wrap>");
    s.push_str(&vertical_tabs(q, "videos"));
    if q.is_empty() {
        s.push_str(
            "<p class=meta>Enter a query to search videos harvested from crawled pages \
(no video or thumbnail is fetched by the server).</p>",
        );
    } else if videos.is_empty() {
        s.push_str(&format!(
            "<p class=empty>No videos matched <strong>{}</strong>.</p>",
            esc(q)
        ));
    } else {
        s.push_str(&format!(
            "<div class=meta>{} video result{}</div>",
            videos.len(),
            if videos.len() == 1 { "" } else { "s" }
        ));
        s.push_str("<div class=imggrid>");
        for v in videos {
            let cap_src = if !v.title.is_empty() {
                v.title.as_str()
            } else if !v.watch_url.is_empty() {
                v.watch_url.as_str()
            } else if !v.embed_url.is_empty() {
                v.embed_url.as_str()
            } else if !v.video_url.is_empty() {
                v.video_url.as_str()
            } else {
                "video"
            };
            let media = if v.thumbnail_url.is_empty() {
                "<div class='thumb noimg'>video</div>".to_string()
            } else {
                format!(
                    "<img class=thumb loading=lazy referrerpolicy=no-referrer src='{}' alt=''>",
                    esc(&v.thumbnail_url)
                )
            };
            let host = esc(&v.host);
            let mut bits: Vec<String> = Vec::new();
            if !v.source.is_empty() {
                bits.push(esc(&v.source));
            }
            let dur = fmt_duration(v.duration);
            if !dur.is_empty() {
                bits.push(esc(&dur));
            }
            let sub = if host.is_empty() {
                bits.join(" &middot; ")
            } else {
                let mut all: Vec<String> = Vec::with_capacity(bits.len() + 1);
                all.push(host);
                all.extend(bits);
                all.join(" &middot; ")
            };
            s.push_str(&format!(
                "<figure class=imgcard>\
<a href='{page}' rel='noreferrer nofollow'>{media}</a>\
<figcaption>{cap}<span class=imghost>{sub}</span></figcaption>\
</figure>",
                page = esc(&v.page_url),
                cap = esc(cap_src),
            ));
        }
        s.push_str("</div>");
    }
    s.push_str("<footer><a href='/'>&larr; Back to search</a></footer></div></main>");
    s
}

fn render_home() -> String {
    "<main><div class=wrap><p class=meta>A from-scratch crawler + inverted index + \
explicit ranking. Enter a query above. Supports <code>\"exact phrase\"</code>, \
<code>+required</code>, <code>-excluded</code> terms and the <code>site:</code>, \
<code>lang:</code>, <code>filetype:</code>, <code>intitle:</code>, \
<code>before:</code>/<code>after:</code> operators.</p>\
<footer><a href='/about'>About &amp; stats</a></footer></div></main>"
        .to_string()
}

/// The base pager query for the results page: `q`, plus `type` for the `news` /
/// `files` verticals so "Prev"/"Next" stay INSIDE the vertical the user is on.
/// Mirrors the Python `_pg` dict (insertion order `q`, `type`, then `page`).
fn pager_params(q: &str, active: &str) -> Vec<(String, String)> {
    let mut pg = vec![("q".to_string(), q.to_string())];
    if active == "news" || active == "files" {
        pg.push(("type".to_string(), active.to_string()));
    }
    pg
}

/// One pager href: [`pager_params`] with `page` appended, percent-encoded.
/// Like the Python, the encoded query string goes into the attribute unescaped —
/// `urlencode` already percent-encodes every character that could break out of
/// it (`'`, `"`, `<`, `>`, `&` inside values), so this is XSS-safe.
fn pager_href(q: &str, active: &str, page: usize) -> String {
    let mut pg = pager_params(q, active);
    pg.push(("page".to_string(), page.to_string()));
    urlencode(&pg)
}

/// The results page. PURE: `elapsed` (seconds) is measured by the caller and
/// passed in, exactly as `now` is. Byte-identical to the Python `_render_results`.
fn render_results(
    q: &str,
    resp: &crate::ranking::SearchResponse,
    page: usize,
    active: &str,
    elapsed: f64,
) -> String {
    let mut s = String::from("<main><div class=wrap>");
    s.push_str(&vertical_tabs(q, active));
    s.push_str(&active_filters(&resp.query));
    let total = resp.total;
    s.push_str(&format!(
        "<div class=meta>About {total} result{} ({elapsed:.3} seconds)</div>",
        if total == 1 { "" } else { "s" }
    ));
    if resp.results.is_empty() {
        s.push_str(&format!(
            "<p class=empty>No pages matched <strong>{}</strong>. Try fewer or different terms.</p>",
            esc(q)
        ));
    }
    for r in &resp.results {
        s.push_str(&result_row(r));
    }
    let last = std::cmp::max(1, total.div_ceil(PAGE_SIZE));
    if last > 1 {
        s.push_str("<div class=pager>");
        if page > 1 {
            let href = pager_href(q, active, page - 1);
            s.push_str(&format!("<a href='/search?{href}'>&larr; Prev</a>"));
        }
        s.push_str(&format!("<span>Page {page} of {last}</span>"));
        if page < last {
            let href = pager_href(q, active, page + 1);
            s.push_str(&format!("<a href='/search?{href}'>Next &rarr;</a>"));
        }
        s.push_str("</div>");
    }
    let jhref = urlencode(&[("q".to_string(), q.to_string())]);
    s.push_str(&format!(
        "<footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?{jhref}'>JSON API</a></footer></div></main>"
    ));
    s
}

/// The `/about` (= `/stats`) page. PURE: both the index [`Stats`] and the
/// optional frontier `status → count` map are gathered by the caller.
///
/// `frontier` is `None` when the server was built without a [`Frontier`] handle
/// ([`SearchServer::new`]); the Frontier section is then omitted — as it is for
/// an EMPTY map, matching the Python `if st.get("frontier")` (a falsy empty dict
/// prints nothing). Byte-identical to the Python `_render_about`.
fn render_about(st: &Stats, frontier: Option<&BTreeMap<String, usize>>) -> String {
    let mut b = String::from("<main><div class=wrap><h1>Index statistics</h1><table class=stats>");
    b.push_str(&format!(
        "<tr><td>Documents indexed</td><td>{}</td></tr>",
        st.docs
    ));
    b.push_str(&format!(
        "<tr><td>Distinct hosts</td><td>{}</td></tr>",
        st.hosts
    ));
    b.push_str(&format!(
        "<tr><td>Link edges</td><td>{}</td></tr>",
        st.links
    ));
    if let Some(newest) = st.newest {
        b.push_str(&format!(
            "<tr><td>Newest fetch</td><td>{}</td></tr>",
            fmt_date(newest)
        ));
        if let Some(oldest) = st.oldest {
            b.push_str(&format!(
                "<tr><td>Oldest fetch</td><td>{}</td></tr>",
                fmt_date(oldest)
            ));
        }
    }
    b.push_str("</table>");
    let rows = |pairs: &[(String, usize)]| -> String {
        pairs
            .iter()
            .map(|(k, v)| format!("<tr><td>{}</td><td>{v}</td></tr>", esc(k)))
            .collect::<String>()
    };
    if !st.top_hosts.is_empty() {
        b.push_str(&format!(
            "<h2>Top hosts</h2><table class=stats>{}</table>",
            rows(&st.top_hosts)
        ));
    }
    if !st.languages.is_empty() {
        b.push_str(&format!(
            "<h2>Languages</h2><table class=stats>{}</table>",
            rows(&st.languages)
        ));
    }
    // Frontier counts by status, ordered by status name (the Python sorts the
    // dict items; a `BTreeMap` already iterates in that order).
    if let Some(counts) = frontier {
        if !counts.is_empty() {
            let pairs: Vec<(String, usize)> = counts.iter().map(|(k, v)| (k.clone(), *v)).collect();
            b.push_str(&format!(
                "<h2>Frontier</h2><table class=stats>{}</table>",
                rows(&pairs)
            ));
        }
    }
    b.push_str("<footer><a href='/'>&larr; Back to search</a></footer></div></main>");
    b
}

/// A read-only search server over a shared [`Index`], and OPTIONALLY over the
/// shared [`Frontier`] that fills it (used only to render the `/about` Frontier
/// table — nothing on the read path queues work).
pub struct SearchServer {
    index: Arc<Mutex<Index>>,
    frontier: Option<Arc<Mutex<Frontier>>>,
    base_url: String,
}

fn now_secs() -> f64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

fn param<'a>(params: &'a [(String, String)], name: &str) -> Option<&'a str> {
    params
        .iter()
        .find(|(k, _)| k == name)
        .map(|(_, v)| v.as_str())
}

impl SearchServer {
    /// A new server over `index`, describing itself at `base_url`. No frontier
    /// handle, so `/about` omits the Frontier table — see
    /// [`with_frontier`](Self::with_frontier).
    #[must_use]
    pub fn new(index: Arc<Mutex<Index>>, base_url: impl Into<String>) -> Self {
        SearchServer {
            index,
            frontier: None,
            base_url: base_url.into(),
        }
    }

    /// A new server that ALSO reads `frontier`, so `/about` renders the Frontier
    /// `status → count` table (the Python `stats(conn)["frontier"]`, which comes
    /// free there because the frontier shares the index's SQLite file). The
    /// handle is read-only in practice: the only thing the server calls on it is
    /// [`Frontier::counts`].
    #[must_use]
    pub fn with_frontier(
        index: Arc<Mutex<Index>>,
        frontier: Arc<Mutex<Frontier>>,
        base_url: impl Into<String>,
    ) -> Self {
        SearchServer {
            index,
            frontier: Some(frontier),
            base_url: base_url.into(),
        }
    }

    /// The frontier `status → count` map, or `None` when this server has no
    /// frontier handle. Locks the frontier ALONE (never while holding the index
    /// lock), so the two mutexes are never nested.
    fn frontier_counts(&self) -> Option<BTreeMap<String, usize>> {
        self.frontier
            .as_ref()
            .map(|f| f.lock().expect("frontier mutex").counts())
    }

    /// Route a request (`GET`/`HEAD` only). Pure — no socket. `target` is the raw
    /// request target (`path?query`).
    #[must_use]
    pub fn route(&self, method: &str, target: &str) -> Resp {
        if method != "GET" && method != "HEAD" {
            return Resp::text(405, "method not allowed");
        }
        let s = urlsplit(target, "");
        let params = parse_qsl(&s.query, true);
        match s.path.as_str() {
            "/style.css" => Resp::css(STYLE),
            "/healthz" => Resp::text(200, "ok"),
            "/favicon.ico" => Resp {
                status: 204,
                ctype: "text/plain; charset=utf-8",
                body: String::new(),
            },
            "/metrics" => self.metrics(),
            "/opensearch.xml" => Resp::xml(opensearch_xml(&self.base_url)),
            "/images" => self.images_html(&params),
            "/videos" => self.videos_html(&params),
            "/api/search" => self.api_search(&params),
            "/suggest" => self.suggest(&params),
            "/about" | "/stats" => {
                let st = {
                    let ix = self.index.lock().expect("index mutex");
                    ix.stats()
                };
                let counts = self.frontier_counts();
                Resp::html(
                    200,
                    wrap_page(
                        "astrx search - stats",
                        &(header("") + &render_about(&st, counts.as_ref())),
                    ),
                )
            }
            "/" | "/search" => self.search_html(&params),
            _ => Resp::html(
                404,
                wrap_page(
                    "Not found",
                    &(header("") + "<main><div class=wrap><p>Not found.</p></div></main>"),
                ),
            ),
        }
    }

    fn query_and_page(params: &[(String, String)]) -> (String, usize) {
        let q = param(params, "q").unwrap_or("").trim().to_string();
        let page = param(params, "page")
            .and_then(|p| p.parse::<usize>().ok())
            .map_or(1, |p| p.max(1));
        (q, page)
    }

    fn search_html(&self, params: &[(String, String)]) -> Resp {
        let vertical = param(params, "type").unwrap_or("");
        let (sort, only_files, active) = match vertical {
            "news" => ("fresh", false, "news"),
            "files" => ("relevance", true, "files"),
            _ => ("relevance", false, "web"),
        };
        let (q, page) = Self::query_and_page(params);
        if q.is_empty() {
            return Resp::html(
                200,
                wrap_page("astrx search", &(header("") + &render_home())),
            );
        }
        let opts = SearchOpts {
            page,
            page_size: PAGE_SIZE,
            now: now_secs(),
            sort: sort.to_string(),
            only_files,
        };
        let (resp, elapsed) = self.timed_search(&q, &opts);
        let title = format!("{q} - astrx search");
        let body = header(&q) + &render_results(&q, &resp, page, active, elapsed);
        Resp::html(200, wrap_page(&title, &body))
    }

    /// Run a search and return it with its wall-clock duration in seconds — the
    /// `elapsed` the Python `ranking.search` returns as its third value. The
    /// clock lives HERE, in the impure route layer; the renderers take the number
    /// as a parameter and stay pure.
    fn timed_search(&self, q: &str, opts: &SearchOpts) -> (crate::ranking::SearchResponse, f64) {
        let t0 = std::time::Instant::now();
        let resp = {
            let ix = self.index.lock().expect("index mutex");
            search(&ix, q, opts)
        };
        (resp, t0.elapsed().as_secs_f64())
    }

    fn images_html(&self, params: &[(String, String)]) -> Resp {
        let q = param(params, "q").unwrap_or("").trim().to_string();
        let images = if q.is_empty() {
            Vec::new()
        } else {
            let ix = self.index.lock().expect("index mutex");
            ix.image_search(&q, IMAGE_LIMIT)
        };
        let title = if q.is_empty() {
            "astrx images".to_string()
        } else {
            format!("{q} - images - astrx search")
        };
        Resp::html(
            200,
            wrap_page(&title, &(header(&q) + &render_images(&q, &images))),
        )
    }

    fn videos_html(&self, params: &[(String, String)]) -> Resp {
        let q = param(params, "q").unwrap_or("").trim().to_string();
        let videos = if q.is_empty() {
            Vec::new()
        } else {
            let ix = self.index.lock().expect("index mutex");
            ix.video_search(&q, IMAGE_LIMIT)
        };
        let title = if q.is_empty() {
            "astrx videos".to_string()
        } else {
            format!("{q} - videos - astrx search")
        };
        Resp::html(
            200,
            wrap_page(&title, &(header(&q) + &render_videos(&q, &videos))),
        )
    }

    fn api_search(&self, params: &[(String, String)]) -> Resp {
        let (q, mut page) = Self::query_and_page(params);
        let mut page_size = PAGE_SIZE;
        if let Some(ps) = param(params, "page_size").and_then(|v| v.parse::<usize>().ok()) {
            if ps > 0 {
                page_size = ps.min(API_MAX_LIMIT);
            }
        }
        if let Some(lim) = param(params, "limit").and_then(|v| v.parse::<usize>().ok()) {
            if lim > 0 {
                page_size = lim.min(API_MAX_LIMIT);
                page = 1;
            }
        }
        let vtype = param(params, "type").unwrap_or("");
        let mut sort = if vtype == "news" {
            "fresh"
        } else {
            "relevance"
        };
        if let Some(rs) = param(params, "sort") {
            if rs == "relevance" || rs == "fresh" {
                sort = rs;
            }
        }
        let opts = SearchOpts {
            page,
            page_size,
            now: now_secs(),
            sort: sort.to_string(),
            only_files: vtype == "files",
        };
        let (resp, elapsed) = self.timed_search(&q, &opts);
        let parsed = &resp.query;
        let phrases: Vec<String> = parsed.phrases.iter().map(|p| json_str_array(p)).collect();
        let results: Vec<String> = resp.results.iter().map(result_json).collect();
        let payload = format!(
            "{{\"query\":{},\"parsed\":{{\"optional\":{},\"required\":{},\"excluded\":{},\
\"phrases\":[{}],\"intitle\":{},\"site\":{},\"lang\":{},\"filetype\":{},\"after\":{},\"before\":{}}},\
\"page\":{},\"page_size\":{},\"total\":{},\"elapsed_seconds\":{},\"results\":[{}]}}",
            jq(&q),
            json_str_array(&parsed.optional),
            json_str_array(&parsed.required),
            json_str_array(&parsed.excluded),
            phrases.join(","),
            json_str_array(&parsed.intitle),
            json_opt_str(&parsed.site),
            json_opt_str(&parsed.lang),
            json_opt_str(&parsed.filetype),
            json_opt_num(parsed.after),
            json_opt_num(parsed.before),
            page,
            page_size,
            resp.total,
            // Python: `round(elapsed, 6)`, the same rounding it applies to `score`.
            round6(elapsed),
            results.join(",")
        );
        Resp::json(200, payload)
    }

    /// OpenSearch Suggestions typeahead: `q` is stripped then capped to the first
    /// [`SUGGEST_MAX_QUERY`] code points and echoed verbatim (original case). The
    /// body is `[q, [terms…]]` where `terms` is [`crate::suggest::suggest`] with an
    /// EMPTY `popular` slice (the in-process popular-query tracker is intentionally
    /// not implemented). An empty `q` short-circuits to `["", []]` without a search.
    /// Mirrors the Python `_suggest`.
    fn suggest(&self, params: &[(String, String)]) -> Resp {
        let q: String = param(params, "q")
            .unwrap_or("")
            .trim()
            .chars()
            .take(SUGGEST_MAX_QUERY)
            .collect();
        let terms = if q.is_empty() {
            Vec::new()
        } else {
            let ix = self.index.lock().expect("index mutex");
            crate::suggest::suggest(&ix, &q, &[], crate::suggest::MAX_SUGGESTIONS)
        };
        Resp::suggestions(200, suggestions_json(&q, &terms))
    }

    fn metrics(&self) -> Resp {
        let (docs, hosts) = {
            let ix = self.index.lock().expect("index mutex");
            let st = ix.stats();
            (st.docs, st.hosts)
        };
        let body =
            format!("# astrx-websearch metrics\nwebsearch_docs {docs}\nwebsearch_hosts {hosts}\n");
        Resp::text(200, &body)
    }
}

fn result_json(r: &SearchResult) -> String {
    format!(
        "{{\"url\":{},\"title\":{},\"host\":{},\"snippet_html\":{},\"score\":{},\
\"fetched_at\":{},\"lang\":{},\"simhash\":{}}}",
        jq(&r.url),
        jq(&r.title),
        jq(&r.host),
        jq(&r.snippet),
        round6(r.score),
        fmt_num(r.fetched_at),
        jq(&r.lang),
        r.simhash
    )
}

fn round6(n: f64) -> String {
    let r = (n * 1e6).round() / 1e6;
    fmt_num(r)
}

#[cfg(feature = "net")]
pub use net_impl::serve;

#[cfg(feature = "net")]
mod net_impl {
    use super::{Resp, SearchServer};
    use std::sync::Arc;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    /// Accept and serve connections until the listener errors. Each request is one
    /// `Connection: close` round-trip through [`SearchServer::route`].
    ///
    /// # Errors
    /// Propagates a fatal `accept()` error.
    pub async fn serve(listener: TcpListener, server: Arc<SearchServer>) -> std::io::Result<()> {
        loop {
            let (mut sock, _) = listener.accept().await?;
            let srv = server.clone();
            tokio::spawn(async move {
                let mut buf = Vec::new();
                let mut tmp = [0u8; 4096];
                // read the request head
                while find(&buf, b"\r\n\r\n").is_none() && buf.len() < 64 * 1024 {
                    match sock.read(&mut tmp).await {
                        Ok(0) | Err(_) => break,
                        Ok(n) => buf.extend_from_slice(&tmp[..n]),
                    }
                }
                let (method, target) = parse_request_line(&buf);
                let resp = srv.route(&method, &target);
                let _ = write_resp(&mut sock, &resp).await;
            });
        }
    }

    fn find(b: &[u8], sep: &[u8]) -> Option<usize> {
        if b.len() < sep.len() {
            return None;
        }
        (0..=b.len() - sep.len()).find(|&i| &b[i..i + sep.len()] == sep)
    }

    fn parse_request_line(buf: &[u8]) -> (String, String) {
        let end = find(buf, b"\r\n").unwrap_or(buf.len());
        let line = String::from_utf8_lossy(&buf[..end]);
        let mut it = line.split(' ');
        let method = it.next().unwrap_or("GET").to_string();
        let target = it.next().unwrap_or("/").to_string();
        (method, target)
    }

    async fn write_resp(sock: &mut tokio::net::TcpStream, resp: &Resp) -> std::io::Result<()> {
        let head = format!(
            "HTTP/1.1 {} {}\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
            resp.status,
            reason(resp.status),
            resp.ctype,
            resp.body.len()
        );
        sock.write_all(head.as_bytes()).await?;
        sock.write_all(resp.body.as_bytes()).await?;
        Ok(())
    }

    fn reason(status: u16) -> &'static str {
        match status {
            200 => "OK",
            204 => "No Content",
            404 => "Not Found",
            405 => "Method Not Allowed",
            _ => "OK",
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::index::{DocFields, Index};

    fn server_with_docs() -> SearchServer {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://a/rust",
            DocFields {
                title: "Rust guide",
                body: "learning rust programming today",
                host: "a",
                lang: "en",
                fetched_at: 1_700_000_000.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
        ix.upsert_document(
            "http://b/java",
            DocFields {
                title: "Java notes",
                body: "some java things",
                host: "b",
                lang: "en",
                fetched_at: 1_700_000_000.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
        SearchServer::new(Arc::new(Mutex::new(ix)), "http://localhost:8803")
    }

    #[test]
    fn html_search_lists_matches() {
        let srv = server_with_docs();
        let r = srv.route("GET", "/search?q=rust");
        assert_eq!(r.status, 200);
        assert!(r.body.contains("http://a/rust"));
        assert!(!r.body.contains("http://b/java")); // 'rust' does not match java doc
        assert!(r.body.contains("astrx"));
    }

    #[test]
    fn home_and_about_and_health() {
        let srv = server_with_docs();
        assert!(srv.route("GET", "/").body.contains("Enter a query"));
        assert!(srv
            .route("GET", "/about")
            .body
            .contains("Documents indexed"));
        assert_eq!(srv.route("GET", "/healthz").body, "ok");
        assert_eq!(
            srv.route("GET", "/style.css").ctype,
            "text/css; charset=utf-8"
        );
        assert_eq!(srv.route("GET", "/nope").status, 404);
        assert_eq!(srv.route("POST", "/").status, 405);
    }

    #[test]
    fn api_search_json_shape() {
        let srv = server_with_docs();
        let r = srv.route("GET", "/api/search?q=rust+site:a");
        assert_eq!(r.status, 200);
        assert_eq!(r.ctype, "application/json; charset=utf-8");
        assert!(r.body.contains("\"query\":\"rust site:a\""));
        assert!(r.body.contains("\"site\":\"a\""));
        assert!(r.body.contains("\"url\":\"http://a/rust\""));
        assert!(r.body.contains("\"total\":1"));
    }

    #[test]
    fn opensearch_and_dates() {
        let srv = server_with_docs();
        let x = srv.route("GET", "/opensearch.xml");
        assert!(x.body.contains("http://localhost:8803/api/search?q="));
        // date round-trips through the civil<->days conversion
        assert_eq!(fmt_date(1_577_836_800.0), "2020-01-01");
        assert_eq!(fmt_date(1_623_715_200.0), "2021-06-15");
    }

    #[test]
    fn api_escapes_and_no_xss() {
        // a doc whose title carries markup must be JSON-escaped in the API and
        // HTML-escaped in the UI (never rendered as live markup).
        let mut ix = Index::new();
        ix.upsert_document(
            "http://a/x",
            DocFields {
                title: "<script>alert(1)</script> rust",
                body: "rust body text here",
                host: "a",
                fetched_at: 1_700_000_000.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
        let srv = SearchServer::new(Arc::new(Mutex::new(ix)), "http://x");
        let html = srv.route("GET", "/search?q=rust").body;
        assert!(html.contains("&lt;script&gt;"));
        assert!(!html.contains("<script>alert"));
        let json = srv.route("GET", "/api/search?q=rust").body;
        assert!(json.contains("<script>")); // JSON keeps the raw title (string-escaped), not HTML
    }

    #[test]
    fn images_route_prompt_rows_and_empty() {
        let mut ix = Index::new();
        ix.replace_images(
            1,
            "https://ex.com/p",
            "ex.com",
            &[crate::htmlparse::Image {
                src: "https://ex.com/a.jpg".into(),
                alt: "a rusty cat".into(),
                title: String::new(),
                context: String::new(),
            }],
        );
        let srv = SearchServer::new(Arc::new(Mutex::new(ix)), "http://x");
        // no query -> prompt, Images tab active
        let prompt = srv.route("GET", "/images");
        assert_eq!(prompt.status, 200);
        assert!(prompt.body.contains("Enter a query to search images"));
        assert!(prompt.body.contains("class='tab active' href='/images'"));
        // a query renders the matching row
        let hit = srv.route("GET", "/images?q=cat").body;
        assert!(hit.contains("1 image result"));
        assert!(hit.contains("src='https://ex.com/a.jpg'"));
        // no match -> empty state
        assert!(srv
            .route("GET", "/images?q=zzzz")
            .body
            .contains("No images matched"));
    }

    #[test]
    fn videos_route_prompt_and_rows() {
        let mut ix = Index::new();
        ix.replace_videos(
            1,
            "https://ex.com/v",
            "ex.com",
            &[crate::structured::Video {
                video_url: String::new(),
                embed_url: "https://yt/e".into(),
                watch_url: "https://yt/w".into(),
                title: "funny dogs".into(),
                thumbnail: "https://yt/t.jpg".into(),
                source: "youtube".into(),
                duration: Some(3723),
                context: String::new(),
            }],
        );
        let srv = SearchServer::new(Arc::new(Mutex::new(ix)), "http://x");
        assert!(srv
            .route("GET", "/videos")
            .body
            .contains("Enter a query to search videos"));
        let hit = srv.route("GET", "/videos?q=dogs").body;
        assert!(hit.contains("1 video result"));
        assert!(hit.contains("src='https://yt/t.jpg'"));
        assert!(hit.contains("1:02:03")); // duration H:MM:SS
        assert!(hit.contains("ex.com &middot; youtube &middot; 1:02:03"));
    }

    #[test]
    fn images_route_escapes_script_in_alt() {
        let mut ix = Index::new();
        ix.replace_images(
            1,
            "https://ex.com/p",
            "ex.com",
            &[crate::htmlparse::Image {
                src: "https://ex.com/a.jpg".into(),
                alt: "<script>alert(1)</script> cat".into(),
                title: String::new(),
                context: String::new(),
            }],
        );
        let srv = SearchServer::new(Arc::new(Mutex::new(ix)), "http://x");
        let body = srv.route("GET", "/images?q=cat").body;
        assert!(body.contains("&lt;script&gt;"));
        assert!(!body.contains("<script>alert"));
    }

    // Cross-check: the PURE renderers + duration formatter are byte-identical to
    // the Python `_render_images` / `_render_videos` / `_fmt_duration` /
    // `_vertical_tabs`. Goldens emitted by driving the real Python module.
    #[test]
    fn media_renderers_byte_identical_to_python() {
        // _fmt_duration
        assert_eq!(fmt_duration(None), "");
        assert_eq!(fmt_duration(Some(-1)), "");
        assert_eq!(fmt_duration(Some(0)), "0:00");
        assert_eq!(fmt_duration(Some(5)), "0:05");
        assert_eq!(fmt_duration(Some(65)), "1:05");
        assert_eq!(fmt_duration(Some(125)), "2:05");
        assert_eq!(fmt_duration(Some(3599)), "59:59");
        assert_eq!(fmt_duration(Some(3600)), "1:00:00");
        assert_eq!(fmt_duration(Some(3661)), "1:01:01");
        assert_eq!(fmt_duration(Some(7323)), "2:02:03");
        assert_eq!(fmt_duration(Some(3723)), "1:02:03");
        // _vertical_tabs (empty + query, active variations)
        assert_eq!(vertical_tabs("", "videos"), "<div class=tabs><a class='tab' href='/search'>Web</a><a class='tab' href='/search?type=news'>News</a><a class='tab' href='/images'>Images</a><a class='tab active' href='/videos'>Videos</a><a class='tab' href='/search?type=files'>Files</a></div>");
        assert_eq!(vertical_tabs("cats", "images"), "<div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab active' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div>");
        assert_eq!(vertical_tabs("foo bar & baz", "web"), "<div class=tabs><a class='tab active' href='/search?q=foo+bar+%26+baz'>Web</a><a class='tab' href='/search?type=news&amp;q=foo+bar+%26+baz'>News</a><a class='tab' href='/images?q=foo+bar+%26+baz'>Images</a><a class='tab' href='/videos?q=foo+bar+%26+baz'>Videos</a><a class='tab' href='/search?type=files&amp;q=foo+bar+%26+baz'>Files</a></div>");
        // _render_images: prompt / empty / rows
        assert_eq!(render_images("", &[]), "<main><div class=wrap><div class=tabs><a class='tab' href='/search'>Web</a><a class='tab' href='/search?type=news'>News</a><a class='tab active' href='/images'>Images</a><a class='tab' href='/videos'>Videos</a><a class='tab' href='/search?type=files'>Files</a></div><p class=meta>Enter a query to search images harvested from crawled pages (no image is fetched by the server).</p><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        assert_eq!(render_images("cats", &[]), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab active' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><p class=empty>No images matched <strong>cats</strong>.</p><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        let imgs = vec![
            ImageResult {
                src: "https://ex.com/a.jpg".into(),
                alt: "A <cat> & dog".into(),
                title: "T1".into(),
                page_url: "https://ex.com/p1".into(),
                host: "ex.com".into(),
            },
            ImageResult {
                src: "https://ex.com/b.jpg".into(),
                alt: String::new(),
                title: String::new(),
                page_url: "https://ex.com/p2".into(),
                host: String::new(),
            },
        ];
        assert_eq!(render_images("cats", &imgs[..1]), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab active' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>1 image result</div><div class=imggrid><figure class=imgcard><a href='https://ex.com/p1' rel='noreferrer nofollow'><img class=thumb loading=lazy referrerpolicy=no-referrer src='https://ex.com/a.jpg' alt='A &lt;cat&gt; &amp; dog'></a><figcaption>A &lt;cat&gt; &amp; dog<span class=imghost>ex.com</span></figcaption></figure></div><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        assert_eq!(render_images("cats", &imgs), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab active' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>2 image results</div><div class=imggrid><figure class=imgcard><a href='https://ex.com/p1' rel='noreferrer nofollow'><img class=thumb loading=lazy referrerpolicy=no-referrer src='https://ex.com/a.jpg' alt='A &lt;cat&gt; &amp; dog'></a><figcaption>A &lt;cat&gt; &amp; dog<span class=imghost>ex.com</span></figcaption></figure><figure class=imgcard><a href='https://ex.com/p2' rel='noreferrer nofollow'><img class=thumb loading=lazy referrerpolicy=no-referrer src='https://ex.com/b.jpg' alt=''></a><figcaption>https://ex.com/b.jpg<span class=imghost></span></figcaption></figure></div><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        // _render_videos: prompt / empty / rows
        assert_eq!(render_videos("", &[]), "<main><div class=wrap><div class=tabs><a class='tab' href='/search'>Web</a><a class='tab' href='/search?type=news'>News</a><a class='tab' href='/images'>Images</a><a class='tab active' href='/videos'>Videos</a><a class='tab' href='/search?type=files'>Files</a></div><p class=meta>Enter a query to search videos harvested from crawled pages (no video or thumbnail is fetched by the server).</p><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        assert_eq!(render_videos("dogs", &[]), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=dogs'>Web</a><a class='tab' href='/search?type=news&amp;q=dogs'>News</a><a class='tab' href='/images?q=dogs'>Images</a><a class='tab active' href='/videos?q=dogs'>Videos</a><a class='tab' href='/search?type=files&amp;q=dogs'>Files</a></div><p class=empty>No videos matched <strong>dogs</strong>.</p><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        let vids = vec![
            VideoResult {
                video_url: String::new(),
                embed_url: "https://yt/embed/1".into(),
                watch_url: "https://yt/watch/1".into(),
                title: "Fun <b>clip</b> & more".into(),
                thumbnail_url: "https://yt/t1.jpg".into(),
                source: "youtube".into(),
                duration: Some(3723),
                page_url: "https://ex.com/v1".into(),
                host: "ex.com".into(),
            },
            VideoResult {
                video_url: "https://ex.com/x.mp4".into(),
                embed_url: String::new(),
                watch_url: String::new(),
                title: String::new(),
                thumbnail_url: String::new(),
                source: String::new(),
                duration: None,
                page_url: "https://ex.com/v2".into(),
                host: String::new(),
            },
        ];
        assert_eq!(render_videos("dogs", &vids), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=dogs'>Web</a><a class='tab' href='/search?type=news&amp;q=dogs'>News</a><a class='tab' href='/images?q=dogs'>Images</a><a class='tab active' href='/videos?q=dogs'>Videos</a><a class='tab' href='/search?type=files&amp;q=dogs'>Files</a></div><div class=meta>2 video results</div><div class=imggrid><figure class=imgcard><a href='https://ex.com/v1' rel='noreferrer nofollow'><img class=thumb loading=lazy referrerpolicy=no-referrer src='https://yt/t1.jpg' alt=''></a><figcaption>Fun &lt;b&gt;clip&lt;/b&gt; &amp; more<span class=imghost>ex.com &middot; youtube &middot; 1:02:03</span></figcaption></figure><figure class=imgcard><a href='https://ex.com/v2' rel='noreferrer nofollow'><div class='thumb noimg'>video</div></a><figcaption>https://ex.com/x.mp4<span class=imghost></span></figcaption></figure></div><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
    }

    // ---- pager verticals + elapsed (defects 1 and 3) -----------------------

    /// A [`SearchResponse`](crate::ranking::SearchResponse) with no rows but a
    /// stated `total`, so the pure renderer's pager/meta/empty-state bytes can be
    /// compared with the Python's for the same arguments. (Result ROWS are left
    /// out on purpose: the Rust `result_row` omits the `similar` link because
    /// `/similar` is deferred, a pre-existing divergence unrelated to these.)
    fn resp(total: usize) -> crate::ranking::SearchResponse {
        crate::ranking::SearchResponse {
            results: Vec::new(),
            total,
            query: crate::ranking::parse_query(""),
        }
    }

    /// Byte-identical to the Python `_render_results` for the pager + elapsed:
    /// the `news`/`files` verticals carry `type=` on every page link (`q`, `type`,
    /// `page` — the Python `_pg` insertion order), `web` carries none, and the
    /// meta line prints `%.3f` of the elapsed seconds handed in. Goldens emitted
    /// by driving the real Python module with `results=[]`.
    #[test]
    fn results_pager_and_elapsed_byte_identical_to_python() {
        // news: type= survives on both Prev and Next
        assert_eq!(render_results("cats", &resp(57), 3, "news", 0.012_345), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab active' href='/search?type=news&amp;q=cats'>News</a><a class='tab' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>About 57 results (0.012 seconds)</div><p class=empty>No pages matched <strong>cats</strong>. Try fewer or different terms.</p><div class=pager><a href='/search?q=cats&type=news&page=2'>&larr; Prev</a><span>Page 3 of 6</span><a href='/search?q=cats&type=news&page=4'>Next &rarr;</a></div><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=cats'>JSON API</a></footer></div></main>");
        // files: same, with type=files
        assert_eq!(render_results("cats", &resp(57), 3, "files", 0.012_345), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab active' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>About 57 results (0.012 seconds)</div><p class=empty>No pages matched <strong>cats</strong>. Try fewer or different terms.</p><div class=pager><a href='/search?q=cats&type=files&page=2'>&larr; Prev</a><span>Page 3 of 6</span><a href='/search?q=cats&type=files&page=4'>Next &rarr;</a></div><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=cats'>JSON API</a></footer></div></main>");
        // web: no type= at all (the vertical-less default)
        assert_eq!(render_results("cats", &resp(57), 3, "web", 0.012_345), "<main><div class=wrap><div class=tabs><a class='tab active' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>About 57 results (0.012 seconds)</div><p class=empty>No pages matched <strong>cats</strong>. Try fewer or different terms.</p><div class=pager><a href='/search?q=cats&page=2'>&larr; Prev</a><span>Page 3 of 6</span><a href='/search?q=cats&page=4'>Next &rarr;</a></div><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=cats'>JSON API</a></footer></div></main>");
        // first page (Next only), zero elapsed
        assert_eq!(render_results("cats", &resp(57), 1, "news", 0.0), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab active' href='/search?type=news&amp;q=cats'>News</a><a class='tab' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>About 57 results (0.000 seconds)</div><p class=empty>No pages matched <strong>cats</strong>. Try fewer or different terms.</p><div class=pager><span>Page 1 of 6</span><a href='/search?q=cats&type=news&page=2'>Next &rarr;</a></div><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=cats'>JSON API</a></footer></div></main>");
        // last page (Prev only), elapsed rounded to 3 places
        assert_eq!(render_results("cats", &resp(57), 6, "files", 1.234_567_8), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab active' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>About 57 results (1.235 seconds)</div><p class=empty>No pages matched <strong>cats</strong>. Try fewer or different terms.</p><div class=pager><a href='/search?q=cats&type=files&page=5'>&larr; Prev</a><span>Page 6 of 6</span></div><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=cats'>JSON API</a></footer></div></main>");
        // a query needing percent-encoding, carried through every pager href
        assert_eq!(render_results("foo bar & baz", &resp(25), 2, "news", 0.5), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=foo+bar+%26+baz'>Web</a><a class='tab active' href='/search?type=news&amp;q=foo+bar+%26+baz'>News</a><a class='tab' href='/images?q=foo+bar+%26+baz'>Images</a><a class='tab' href='/videos?q=foo+bar+%26+baz'>Videos</a><a class='tab' href='/search?type=files&amp;q=foo+bar+%26+baz'>Files</a></div><div class=meta>About 25 results (0.500 seconds)</div><p class=empty>No pages matched <strong>foo bar &amp; baz</strong>. Try fewer or different terms.</p><div class=pager><a href='/search?q=foo+bar+%26+baz&type=news&page=1'>&larr; Prev</a><span>Page 2 of 3</span><a href='/search?q=foo+bar+%26+baz&type=news&page=3'>Next &rarr;</a></div><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=foo+bar+%26+baz'>JSON API</a></footer></div></main>");
        // single page: no pager at all, singular "result"
        assert_eq!(render_results("cats", &resp(1), 1, "web", 0.001), "<main><div class=wrap><div class=tabs><a class='tab active' href='/search?q=cats'>Web</a><a class='tab' href='/search?type=news&amp;q=cats'>News</a><a class='tab' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>About 1 result (0.001 seconds)</div><p class=empty>No pages matched <strong>cats</strong>. Try fewer or different terms.</p><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=cats'>JSON API</a></footer></div></main>");
        assert_eq!(render_results("cats", &resp(7), 1, "news", 0.25), "<main><div class=wrap><div class=tabs><a class='tab' href='/search?q=cats'>Web</a><a class='tab active' href='/search?type=news&amp;q=cats'>News</a><a class='tab' href='/images?q=cats'>Images</a><a class='tab' href='/videos?q=cats'>Videos</a><a class='tab' href='/search?type=files&amp;q=cats'>Files</a></div><div class=meta>About 7 results (0.250 seconds)</div><p class=empty>No pages matched <strong>cats</strong>. Try fewer or different terms.</p><footer><a href='/about'>About &amp; stats</a> &middot; <a href='/api/search?q=cats'>JSON API</a></footer></div></main>");
    }

    /// The regression itself, end to end through [`SearchServer::route`]: paging
    /// inside a vertical must STAY in that vertical. Before the fix every pager
    /// href was `q=…&page=N`, so "Next" dropped `type=` and landed on plain Web.
    #[test]
    fn route_pager_preserves_the_vertical() {
        // 12 docs => 2 pages of 10, so the pager renders. They are PDFs so they
        // survive the `files` vertical's downloadable-document filter too.
        let mut ix = Index::new();
        for i in 0..12 {
            ix.upsert_document(
                &format!("http://a/rust/{i}.pdf"),
                DocFields {
                    title: "Rust guide",
                    body: "learning rust programming today",
                    host: "a",
                    lang: "en",
                    fetched_at: 1_700_000_000.0,
                    http_status: 200,
                    content_type: "application/pdf",
                    ..DocFields::default()
                },
            );
        }
        let srv = SearchServer::new(Arc::new(Mutex::new(ix)), "http://x");

        for vertical in ["news", "files"] {
            let p1 = srv
                .route("GET", &format!("/search?q=rust&type={vertical}"))
                .body;
            assert!(
                p1.contains(&format!(
                    "href='/search?q=rust&type={vertical}&page=2'>Next"
                )),
                "page 1 of ?type={vertical} lost the vertical: {p1}"
            );
            // …and page 2 links back with it, so the vertical is never dropped.
            let p2 = srv
                .route("GET", &format!("/search?q=rust&type={vertical}&page=2"))
                .body;
            assert!(
                p2.contains(&format!(
                    "href='/search?q=rust&type={vertical}&page=1'>&larr; Prev"
                )),
                "page 2 of ?type={vertical} lost the vertical: {p2}"
            );
            assert!(
                !p1.contains("href='/search?q=rust&page="),
                "bare href leaked"
            );
        }
        // The plain web vertical still pages without a `type=`.
        let web = srv.route("GET", "/search?q=rust").body;
        assert!(web.contains("href='/search?q=rust&page=2'>Next"));
        assert!(!web.contains("type=news&page="));
    }

    /// The clock is real, and it reaches both surfaces: the HTML meta line prints
    /// a `%.3f` number of seconds and the JSON carries a numeric
    /// `elapsed_seconds` — neither is the old hardcoded zero-literal.
    #[test]
    fn route_renders_measured_elapsed() {
        let srv = server_with_docs();
        let html = srv.route("GET", "/search?q=rust").body;
        let meta = html
            .split("<div class=meta>")
            .nth(1)
            .and_then(|s| s.split("</div>").next())
            .expect("meta line");
        // "About 1 result (D.DDD seconds)" — three decimals, from a real measurement.
        let secs = meta
            .rsplit('(')
            .next()
            .and_then(|s| s.split(" seconds)").next())
            .expect("elapsed");
        let (int, frac) = secs.split_once('.').expect("D.DDD");
        assert_eq!(frac.len(), 3, "meta={meta}");
        assert!(
            int.bytes().all(|b| b.is_ascii_digit()) && frac.bytes().all(|b| b.is_ascii_digit()),
            "meta={meta}"
        );
        assert!(secs.parse::<f64>().expect("a number") >= 0.0);

        let json = srv.route("GET", "/api/search?q=rust").body;
        let field = json
            .split("\"elapsed_seconds\":")
            .nth(1)
            .and_then(|s| s.split(',').next())
            .expect("elapsed_seconds");
        assert!(
            field.parse::<f64>().expect("a JSON number") >= 0.0,
            "json={json}"
        );
    }

    /// `%.3f`, member for member, against the Python format spec (both round the
    /// exact binary double, ties to even).
    #[test]
    fn elapsed_formatting_matches_python_percent_3f() {
        for (v, want) in [
            (0.0_f64, "0.000"),
            (0.000_4, "0.000"),
            (0.000_5, "0.001"), // 0.0005 is just above the tie in binary
            (0.001, "0.001"),
            (0.012_345, "0.012"),
            (0.25, "0.250"),
            (0.5, "0.500"),
            (1.234_567_8, "1.235"),
            (1.000_5, "1.000"), // an exact tie: to even
            (12.987_65, "12.988"),
        ] {
            assert_eq!(format!("{v:.3}"), want, "for {v}");
        }
    }

    // ---- /about frontier table (defect 2) ----------------------------------

    fn stats_fixture() -> Stats {
        Stats {
            docs: 3,
            hosts: 2,
            links: 5,
            oldest: Some(1_600_000_000.0),
            newest: Some(1_700_000_000.0),
            top_hosts: vec![("a.example".to_string(), 2), ("b.example".to_string(), 1)],
            languages: vec![("en".to_string(), 3)],
        }
    }

    fn counts(pairs: &[(&str, usize)]) -> BTreeMap<String, usize> {
        pairs.iter().map(|(k, v)| ((*k).to_string(), *v)).collect()
    }

    /// Byte-identical to the Python `_render_about`, with and without the
    /// Frontier section. Goldens emitted by driving the real Python module.
    #[test]
    fn about_frontier_table_byte_identical_to_python() {
        let st = stats_fixture();
        // Present: one row per status, ordered by status name, counts escaped.
        let fr = counts(&[
            ("done", 7),
            ("error", 1),
            ("leased", 2),
            ("queued", 4),
            ("skipped", 3),
        ]);
        assert_eq!(render_about(&st, Some(&fr)), "<main><div class=wrap><h1>Index statistics</h1><table class=stats><tr><td>Documents indexed</td><td>3</td></tr><tr><td>Distinct hosts</td><td>2</td></tr><tr><td>Link edges</td><td>5</td></tr><tr><td>Newest fetch</td><td>2023-11-14</td></tr><tr><td>Oldest fetch</td><td>2020-09-13</td></tr></table><h2>Top hosts</h2><table class=stats><tr><td>a.example</td><td>2</td></tr><tr><td>b.example</td><td>1</td></tr></table><h2>Languages</h2><table class=stats><tr><td>en</td><td>3</td></tr></table><h2>Frontier</h2><table class=stats><tr><td>done</td><td>7</td></tr><tr><td>error</td><td>1</td></tr><tr><td>leased</td><td>2</td></tr><tr><td>queued</td><td>4</td></tr><tr><td>skipped</td><td>3</td></tr></table><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        // Absent (no handle) and EMPTY (a frontier with nothing in it) both omit
        // the section — the Python's falsy-empty-dict behaviour.
        let without = "<main><div class=wrap><h1>Index statistics</h1><table class=stats><tr><td>Documents indexed</td><td>3</td></tr><tr><td>Distinct hosts</td><td>2</td></tr><tr><td>Link edges</td><td>5</td></tr><tr><td>Newest fetch</td><td>2023-11-14</td></tr><tr><td>Oldest fetch</td><td>2020-09-13</td></tr></table><h2>Top hosts</h2><table class=stats><tr><td>a.example</td><td>2</td></tr><tr><td>b.example</td><td>1</td></tr></table><h2>Languages</h2><table class=stats><tr><td>en</td><td>3</td></tr></table><footer><a href='/'>&larr; Back to search</a></footer></div></main>";
        assert_eq!(render_about(&st, None), without);
        assert_eq!(render_about(&st, Some(&BTreeMap::new())), without);
        // A status name carrying markup is escaped, never rendered live.
        let nasty = counts(&[("a<b>&\"'", 1)]);
        let esc_st = Stats {
            top_hosts: vec![("<b>&x</b>".to_string(), 1)],
            languages: Vec::new(),
            ..stats_fixture()
        };
        assert_eq!(render_about(&esc_st, Some(&nasty)), "<main><div class=wrap><h1>Index statistics</h1><table class=stats><tr><td>Documents indexed</td><td>3</td></tr><tr><td>Distinct hosts</td><td>2</td></tr><tr><td>Link edges</td><td>5</td></tr><tr><td>Newest fetch</td><td>2023-11-14</td></tr><tr><td>Oldest fetch</td><td>2020-09-13</td></tr></table><h2>Top hosts</h2><table class=stats><tr><td>&lt;b&gt;&amp;x&lt;/b&gt;</td><td>1</td></tr></table><h2>Frontier</h2><table class=stats><tr><td>a&lt;b&gt;&amp;&quot;&#x27;</td><td>1</td></tr></table><footer><a href='/'>&larr; Back to search</a></footer></div></main>");
    }

    /// End to end through [`SearchServer::route`]: `/about` (and its `/stats`
    /// alias) renders the live [`Frontier::counts`] when the server holds a
    /// frontier, and omits the section when it does not.
    #[test]
    fn route_about_renders_the_live_frontier() {
        let mut fr = Frontier::new();
        fr.add("http://a/1", "a", 0);
        fr.add("http://a/2", "a", 0);
        fr.add("http://b/1", "b", 0);
        fr.complete("http://a/1", "done", None);
        fr.complete("http://b/1", "error", Some("boom"));
        let fr = Arc::new(Mutex::new(fr));

        let ix = Arc::new(Mutex::new(Index::new()));
        let with = SearchServer::with_frontier(ix.clone(), fr.clone(), "http://x");
        for path in ["/about", "/stats"] {
            let body = with.route("GET", path).body;
            assert!(body.contains("<h2>Frontier</h2>"), "{path}: {body}");
            assert!(body.contains("<tr><td>done</td><td>1</td></tr>"), "{body}");
            assert!(body.contains("<tr><td>error</td><td>1</td></tr>"), "{body}");
            assert!(
                body.contains("<tr><td>queued</td><td>1</td></tr>"),
                "{body}"
            );
        }
        // The table tracks the live frontier: queue one more and it moves.
        fr.lock().unwrap().add("http://c/1", "c", 0);
        assert!(with
            .route("GET", "/about")
            .body
            .contains("<tr><td>queued</td><td>2</td></tr>"));

        // A server built with `new` (the `websearch serve` path — a restored
        // snapshot carries no frontier) omits the section entirely.
        let without = SearchServer::new(ix, "http://x");
        let body = without.route("GET", "/about").body;
        assert!(!body.contains("Frontier"), "{body}");
        assert!(body.contains("Documents indexed"), "{body}");
    }

    /// An EMPTY live frontier omits the section too — matching the Python, where
    /// `stats(conn)["frontier"]` is a falsy empty dict before anything is queued.
    #[test]
    fn route_about_omits_an_empty_frontier() {
        let srv = SearchServer::with_frontier(
            Arc::new(Mutex::new(Index::new())),
            Arc::new(Mutex::new(Frontier::new())),
            "http://x",
        );
        assert!(!srv.route("GET", "/about").body.contains("Frontier"));
    }
}
