//! The no-JS search server — HTML UI + JSON API over the [`Index`].
//!
//! A port of the read side of the Python `websearch.server`, following
//! onioncrawler's serving pattern: **pure** renderers + a pure [`SearchServer::route`]
//! over an `Arc<Mutex<Index>>` (so routing is unit-tested without a socket), and
//! the async accept loop behind the `net` feature. Every field rendered into HTML
//! is escaped; snippets arrive already-escaped from [`crate::ranking::make_snippet`]
//! (only `<mark>` markup survives), so the results page is XSS-safe.
//!
//! Routes: `/` + `/search` (HTML results, `?type=news|files` verticals),
//! `/api/search` (JSON, the endpoint the PHP bridge calls; `?limit=`/`?page_size=`/
//! `?sort=` supported), `/about` + `/stats`, `/opensearch.xml`, `/metrics`,
//! `/healthz`, `/style.css`, `/favicon.ico`. The image/video verticals, `/suggest`,
//! and `/similar` are deferred (htmlparse stage 2 / suggest).

use crate::index::Index;
use crate::ranking::{search, Query, SearchOpts, SearchResult};
use crawlcore::urlparse::{parse_qsl, urlencode, urlsplit};
use std::sync::{Arc, Mutex};

const PAGE_SIZE: usize = 10;
const API_MAX_LIMIT: usize = 200;

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
}

const STYLE: &str = "\
:root { color-scheme: light dark; }\n\
* { box-sizing: border-box; }\n\
body { font: 16px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; margin: 0; background: #fafafa; color: #1a1a1a; }\n\
a { color: #1a56db; text-decoration: none; } a:hover { text-decoration: underline; }\n\
header { background: #fff; border-bottom: 1px solid #e5e5e5; padding: 18px 20px; }\n\
.wrap { max-width: 760px; margin: 0 auto; }\n\
.brand { font-weight: 700; font-size: 20px; color:#111; } .brand span { color: #1a56db; }\n\
form.search { display: flex; gap: 8px; margin-top: 12px; }\n\
form.search input[type=text] { flex: 1; padding: 11px 14px; border: 1px solid #cbcbcb; border-radius: 8px; }\n\
form.search button { padding: 11px 18px; border: 0; border-radius: 8px; background: #1a56db; color: #fff; cursor: pointer; }\n\
main { padding: 20px; } .meta { color: #666; font-size: 13px; margin: 4px 0 18px; }\n\
.result { margin: 0 0 22px; } .result .url { color: #0a7d33; font-size: 13px; word-break: break-all; }\n\
.result h2 { font-size: 18px; margin: 2px 0 3px; } .result .snippet { color: #333; font-size: 14px; }\n\
.result .sub { color: #777; font-size: 12px; margin-top: 3px; }\n\
mark { background: #fff2ac; color: inherit; padding: 0 1px; border-radius: 2px; }\n\
.pager { margin: 26px 0; display: flex; gap: 14px; } .empty { color:#555; }\n\
table.stats td, table.stats th { text-align: left; padding: 4px 18px 4px 0; }\n\
footer { color:#999; font-size:12px; padding: 24px 20px; }\n\
@media (prefers-color-scheme: dark) { body { background:#161616; color:#e8e8e8; } header { background:#1f1f1f; border-color:#333; } a { color:#7aa2f7; } }\n";

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
    let tab = |href: &str, label: &str, key: &str| {
        let cls = if key == active { "tab active" } else { "tab" };
        format!(
            "<a class='{cls}' href='{}'>{label}</a>",
            esc(&format!("{href}{qs}"))
        )
    };
    format!(
        "<div class=tabs>{}{}</div>",
        tab("/search", "Web", "web"),
        tab("/search?type=news", "News", "news"),
    )
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

impl SearchServer {
    fn render_results(
        &self,
        q: &str,
        resp: &crate::ranking::SearchResponse,
        page: usize,
        active: &str,
    ) -> String {
        let mut s = String::from("<main><div class=wrap>");
        s.push_str(&vertical_tabs(q, active));
        s.push_str(&active_filters(&resp.query));
        let total = resp.total;
        s.push_str(&format!(
            "<div class=meta>About {total} result{} (0.000 seconds)</div>",
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
                let href = urlencode(&[
                    ("q".to_string(), q.to_string()),
                    ("page".to_string(), (page - 1).to_string()),
                ]);
                s.push_str(&format!("<a href='/search?{href}'>&larr; Prev</a>"));
            }
            s.push_str(&format!("<span>Page {page} of {last}</span>"));
            if page < last {
                let href = urlencode(&[
                    ("q".to_string(), q.to_string()),
                    ("page".to_string(), (page + 1).to_string()),
                ]);
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

    fn render_about(&self, ix: &Index) -> String {
        let st = ix.stats();
        let mut b =
            String::from("<main><div class=wrap><h1>Index statistics</h1><table class=stats>");
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
        b.push_str("<footer><a href='/'>&larr; Back to search</a></footer></div></main>");
        b
    }
}

/// A read-only search server over a shared [`Index`].
pub struct SearchServer {
    index: Arc<Mutex<Index>>,
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
    /// A new server over `index`, describing itself at `base_url`.
    #[must_use]
    pub fn new(index: Arc<Mutex<Index>>, base_url: impl Into<String>) -> Self {
        SearchServer {
            index,
            base_url: base_url.into(),
        }
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
            "/api/search" => self.api_search(&params),
            "/about" | "/stats" => {
                let ix = self.index.lock().expect("index mutex");
                Resp::html(
                    200,
                    wrap_page(
                        "astrx search - stats",
                        &(header("") + &self.render_about(&ix)),
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
        let resp = {
            let ix = self.index.lock().expect("index mutex");
            search(&ix, &q, &opts)
        };
        let title = format!("{q} - astrx search");
        let body = header(&q) + &self.render_results(&q, &resp, page, active);
        Resp::html(200, wrap_page(&title, &body))
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
        let resp = {
            let ix = self.index.lock().expect("index mutex");
            search(&ix, &q, &opts)
        };
        let parsed = &resp.query;
        let phrases: Vec<String> = parsed.phrases.iter().map(|p| json_str_array(p)).collect();
        let results: Vec<String> = resp.results.iter().map(result_json).collect();
        let payload = format!(
            "{{\"query\":{},\"parsed\":{{\"optional\":{},\"required\":{},\"excluded\":{},\
\"phrases\":[{}],\"intitle\":{},\"site\":{},\"lang\":{},\"filetype\":{},\"after\":{},\"before\":{}}},\
\"page\":{},\"page_size\":{},\"total\":{},\"elapsed_seconds\":0,\"results\":[{}]}}",
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
            results.join(",")
        );
        Resp::json(200, payload)
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
}
