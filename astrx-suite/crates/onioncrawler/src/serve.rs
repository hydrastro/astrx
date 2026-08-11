//! The no-JS HTTP serving layer — the read side of the onioncrawler exposed over
//! loopback, ported from the Python `onioncrawler.search` server.
//!
//! Structured like `torrentds`'s search server: pure renderers + a pure
//! [`SearchServer::route`] (it locks the shared `Arc<Mutex<Store>>`
//! *synchronously* — no `.await` while the lock is held), with only the async
//! accept loop ([`serve`]) and per-connection HTTP parsing ([`handle_conn`])
//! behind the `net` feature. So the entire routing + HTML/JSON/XML rendering is
//! testable in the pure (zero-dep) tier without a socket, and a loopback
//! round-trip test exercises the async glue.
//!
//! GET endpoints: `/` and `/search` (HTML), `/api/search` (JSON), `/find` +
//! `/api/find` (entity pivot), `/stats` (HTML) + `/api/stats` (JSON),
//! `/cached?url=` (offline page snapshot), `/health` (JSON), `/metrics`
//! (Prometheus text), `/robots.txt`, `/opensearch.xml`. POST endpoints (gated by
//! a `Bearer` admin token, with an optional public-submit path): `/add`
//! (submit onion seeds through the abuse filter + `add_seed` caps), `/purge`
//! (block a host + delete its pages), `/recrawl` (requeue due pages). The crawl
//! orchestration loop is the remaining increment.

use std::sync::{Arc, Mutex};

use crate::abuse::AbuseFilter;
use crate::onion::normalize_host;
use crate::store::{Caps, Store};
use crate::submit::{submit_many, SubmitResult, SubmitSummary};
use crate::urlparse::{parse_qsl, quote_plus};

/// Configuration for the write endpoints + submission policy.
#[derive(Clone, Debug)]
pub struct ServeConfig {
    /// Bearer token for the admin endpoints; empty disables them.
    pub admin_token: String,
    /// Allow unauthenticated `POST /add` (honours `submit_caps`).
    pub allow_public_submit: bool,
    /// Trap budgets applied to public submissions.
    pub submit_caps: Caps,
    /// Max URLs accepted in one public `/add` (the rest are `skipped`).
    pub max_public_add_urls: usize,
    /// Admit deprecated v2 `.onion` hosts.
    pub allow_v2: bool,
    /// Admit `.i2p` hosts (off ⇒ `.onion` only).
    pub allow_i2p: bool,
    /// Fallback recrawl interval for `POST /recrawl`.
    pub recrawl_interval: f64,
}

impl Default for ServeConfig {
    fn default() -> Self {
        ServeConfig {
            admin_token: String::new(),
            allow_public_submit: false,
            submit_caps: Caps::default(),
            max_public_add_urls: 100,
            allow_v2: false,
            allow_i2p: false,
            recrawl_interval: 0.0,
        }
    }
}

fn now_secs() -> f64 {
    use std::time::{SystemTime, UNIX_EPOCH};
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs_f64())
        .unwrap_or(0.0)
}

// ---------------------------------------------------------------- escaping

/// HTML-escape text for element/attribute content.
pub fn esc(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for c in s.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#39;"),
            _ => out.push(c),
        }
    }
    out
}

/// Escape a string for a JSON double-quoted value (no surrounding quotes).
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

fn opt_num(x: Option<f64>) -> String {
    match x {
        Some(v) => format!("{v}"),
        None => "null".to_string(),
    }
}

// --------------------------------------------------------------- responses

/// A rendered HTTP response.
#[derive(Clone, Debug)]
pub struct Resp {
    pub status: u16,
    pub ctype: &'static str,
    pub body: Vec<u8>,
}

impl Resp {
    fn html(status: u16, body: String) -> Self {
        Resp {
            status,
            ctype: "text/html; charset=utf-8",
            body: body.into_bytes(),
        }
    }
    fn json(status: u16, body: String) -> Self {
        Resp {
            status,
            ctype: "application/json",
            body: body.into_bytes(),
        }
    }
    fn text(status: u16, ctype: &'static str, body: String) -> Self {
        Resp {
            status,
            ctype,
            body: body.into_bytes(),
        }
    }
    fn not_found() -> Self {
        Resp::html(404, layout("Not found", "<p>404 — not found</p>"))
    }
}

#[cfg(feature = "net")]
fn reason(status: u16) -> &'static str {
    match status {
        200 => "OK",
        400 => "Bad Request",
        404 => "Not Found",
        405 => "Method Not Allowed",
        _ => "OK",
    }
}

// ----------------------------------------------------------- query helpers

fn split_target(target: &str) -> (&str, &str) {
    target.split_once('?').unwrap_or((target, ""))
}

fn get<'a>(params: &'a [(String, String)], key: &str) -> Option<&'a str> {
    params
        .iter()
        .find(|(k, _)| k == key)
        .map(|(_, v)| v.as_str())
}

fn clamp_usize(v: Option<&str>, default: usize, min: usize, max: usize) -> usize {
    v.and_then(|s| s.parse::<i64>().ok())
        .map(|n| n.clamp(min as i64, max as i64) as usize)
        .unwrap_or(default)
}

// ------------------------------------------------------------- HTML chrome

const STYLE: &str = "body{font:15px/1.5 system-ui,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1rem;color:#111}\
a{color:#0645ad;text-decoration:none}a:hover{text-decoration:underline}\
.r{margin:1.1rem 0}.u{color:#093;font-size:.85rem;word-break:break-all}\
.s{color:#333}.meta{color:#666;font-size:.8rem}mark{background:#ff9}\
form{margin:.5rem 0}input[type=text]{width:70%;padding:.4rem}\
.facets{color:#444;font-size:.85rem}.facets b{color:#111}nav a{margin-right:1rem}";

fn layout(title: &str, body: &str) -> String {
    format!(
        "<!doctype html><html lang=en><head><meta charset=utf-8>\
<meta name=viewport content=\"width=device-width,initial-scale=1\">\
<link rel=search type=\"application/opensearchdescription+xml\" href=\"/opensearch.xml\" title=\"onioncrawler\">\
<title>{t}</title><style>{s}</style></head><body>{b}</body></html>",
        t = esc(title),
        s = STYLE,
        b = body,
    )
}

fn search_form(q: &str) -> String {
    format!(
        "<form action=/search method=get><input type=text name=q value=\"{}\" \
placeholder=\"search the darknet index\" autofocus> <button>Search</button></form>",
        esc(q)
    )
}

// ------------------------------------------------------------- the server

/// A search/serve front-end over a shared [`Store`]. Cheap to clone (it is just
/// an `Arc` handle + config), so each accepted connection gets its own copy.
#[derive(Clone)]
pub struct SearchServer {
    store: Arc<Mutex<Store>>,
    abuse: Option<Arc<AbuseFilter>>,
    base_url: String,
    config: ServeConfig,
}

impl std::fmt::Debug for SearchServer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SearchServer")
            .field("base_url", &self.base_url)
            .field("admin", &!self.config.admin_token.is_empty())
            .field("public_submit", &self.config.allow_public_submit)
            .finish_non_exhaustive()
    }
}

impl SearchServer {
    /// A server over `store`. `base_url` (e.g. `http://127.0.0.1:8888`) is used
    /// for the OpenSearch descriptor; an empty string yields relative templates.
    /// Write endpoints are disabled until [`with_admin`](Self::with_admin) (or
    /// public submit via [`with_config`](Self::with_config)) is set.
    #[must_use]
    pub fn new(store: Arc<Mutex<Store>>, base_url: impl Into<String>) -> Self {
        SearchServer {
            store,
            abuse: None,
            base_url: base_url.into(),
            config: ServeConfig::default(),
        }
    }

    /// Enable the admin write endpoints (`/purge`, `/recrawl`, authed `/add`)
    /// behind a `Bearer` token.
    #[must_use]
    pub fn with_admin(mut self, token: impl Into<String>) -> Self {
        self.config.admin_token = token.into();
        self
    }

    /// Replace the full serve configuration (submission policy + admin token).
    #[must_use]
    pub fn with_config(mut self, config: ServeConfig) -> Self {
        self.config = config;
        self
    }

    /// Attach an abuse filter so submissions to blocklisted hosts are refused.
    #[must_use]
    pub fn with_abuse(mut self, abuse: Arc<AbuseFilter>) -> Self {
        self.abuse = Some(abuse);
        self
    }

    fn admin_enabled(&self) -> bool {
        !self.config.admin_token.is_empty()
    }

    fn admin_ok(&self, auth: Option<&str>) -> bool {
        self.admin_enabled()
            && auth.and_then(|a| a.strip_prefix("Bearer ")).map(str::trim)
                == Some(self.config.admin_token.as_str())
    }

    fn auth_error(&self) -> Resp {
        if self.admin_enabled() {
            Resp::json(401, "{\"error\":\"auth required\"}".to_string())
        } else {
            Resp::json(403, "{\"error\":\"admin disabled\"}".to_string())
        }
    }

    /// Route one request to a response. Pure and synchronous: it locks the store,
    /// computes, and unlocks before returning — safe to call from a test without
    /// any socket, and never holds the lock across an `.await`. `auth` is the raw
    /// `Authorization` header value (e.g. `"Bearer <token>"`), if present.
    #[must_use]
    pub fn route(&self, method: &str, target: &str, body: &str, auth: Option<&str>) -> Resp {
        let (path, query) = split_target(target);
        let params = parse_qsl(query, true);
        if method == "POST" {
            return match path {
                "/add" => self.do_add(&params, body, auth),
                "/purge" => self.do_purge(&params, body, auth),
                "/recrawl" => self.do_recrawl(auth),
                _ => Resp::json(404, "{\"error\":\"not found\"}".to_string()),
            };
        }
        if method != "GET" && method != "HEAD" {
            return Resp::json(405, "{\"error\":\"method not allowed\"}".to_string());
        }
        match path {
            "/" | "/search" => self.html_search(&params),
            "/api/search" => self.api_search(&params),
            "/find" => self.html_find(&params),
            "/api/find" => self.api_find(&params),
            "/stats" => self.html_stats(),
            "/api/stats" => self.api_stats(),
            "/cached" => self.cached(&params),
            "/health" => self.health(),
            "/metrics" => self.metrics(),
            "/robots.txt" => Resp::text(200, "text/plain; charset=utf-8", ROBOTS.to_string()),
            "/opensearch.xml" => Resp::text(
                200,
                "application/opensearchdescription+xml",
                opensearch(&self.base_url),
            ),
            _ => Resp::not_found(),
        }
    }

    fn search_opts(
        &self,
        params: &[(String, String)],
    ) -> (String, usize, usize, crate::store::SearchOpts) {
        let q = get(params, "q").unwrap_or("").to_string();
        let limit = clamp_usize(get(params, "limit"), 10, 1, 100);
        let page = clamp_usize(get(params, "page"), 1, 1, 1_000_000);
        let offset = (page - 1) * limit;
        let opts = crate::store::SearchOpts {
            limit,
            offset,
            host: get(params, "host").map(str::to_string),
            since: get(params, "since").and_then(|s| s.parse().ok()),
            until: get(params, "until").and_then(|s| s.parse().ok()),
            lang: get(params, "lang").map(str::to_string),
            authority_weight: get(params, "authority")
                .and_then(|s| s.parse().ok())
                .unwrap_or(0.0),
            collapse: matches!(get(params, "collapse"), Some("1" | "true" | "on")),
            simhash_threshold: 3,
        };
        (q, limit, page, opts)
    }

    fn html_search(&self, params: &[(String, String)]) -> Resp {
        let (q, limit, page, opts) = self.search_opts(params);
        let store = self.store.lock().expect("store lock");
        if q.trim().is_empty() {
            let body = format!(
                "<h1>onioncrawler</h1>{}<p class=meta>{} pages · {} hosts indexed</p>",
                search_form(&q),
                store.page_count(),
                store.host_count()
            );
            return Resp::html(200, layout("onioncrawler", &body));
        }
        let res = store.search(&q, &opts);
        let facets = store.search_facets(
            &q,
            opts.host.as_deref(),
            opts.since,
            opts.until,
            opts.lang.as_deref(),
            8,
        );
        let mut b = format!("<h1>onioncrawler</h1>{}", search_form(&q));
        b.push_str(&format!(
            "<p class=meta>{} result{} for <b>{}</b></p>",
            res.total,
            if res.total == 1 { "" } else { "s" },
            esc(&q)
        ));
        for h in &res.hits {
            let title = h.title.clone().unwrap_or_else(|| h.url.clone());
            b.push_str(&format!(
                "<div class=r><a href=\"{u}\">{t}</a><div class=u>{u}</div>\
<div class=s>{sn}</div><div class=meta>{host} · {lang}</div></div>",
                u = esc(&h.url),
                t = esc(&title),
                sn = h.snippet, // safe by construction: alnum tokens + our <mark>
                host = esc(&h.host),
                lang = esc(h.lang.as_deref().unwrap_or("un")),
            ));
        }
        // facets
        if !facets.hosts.is_empty() || !facets.langs.is_empty() {
            b.push_str("<div class=facets>");
            if !facets.hosts.is_empty() {
                b.push_str("<p><b>Hosts:</b> ");
                for (host, n) in &facets.hosts {
                    b.push_str(&format!(
                        "<a href=\"/search?q={}&host={}\">{} ({})</a> ",
                        quote_plus(&q),
                        quote_plus(host),
                        esc(host),
                        n
                    ));
                }
                b.push_str("</p>");
            }
            if !facets.langs.is_empty() {
                b.push_str("<p><b>Languages:</b> ");
                for (lang, n) in &facets.langs {
                    b.push_str(&format!(
                        "<a href=\"/search?q={}&lang={}\">{} ({})</a> ",
                        quote_plus(&q),
                        quote_plus(lang),
                        esc(lang),
                        n
                    ));
                }
                b.push_str("</p>");
            }
            b.push_str("</div>");
        }
        // pagination
        b.push_str("<nav>");
        if page > 1 {
            b.push_str(&format!(
                "<a href=\"/search?q={}&page={}\">« Prev</a>",
                quote_plus(&q),
                page - 1
            ));
        }
        if offset_has_more(res.total, page, limit) {
            b.push_str(&format!(
                "<a href=\"/search?q={}&page={}\">Next »</a>",
                quote_plus(&q),
                page + 1
            ));
        }
        b.push_str("</nav>");
        Resp::html(200, layout(&format!("{q} — onioncrawler"), &b))
    }

    fn api_search(&self, params: &[(String, String)]) -> Resp {
        let (q, _limit, _page, opts) = self.search_opts(params);
        let store = self.store.lock().expect("store lock");
        let res = store.search(&q, &opts);
        let mut items = Vec::with_capacity(res.hits.len());
        for h in &res.hits {
            items.push(format!(
                "{{\"url\":\"{}\",\"title\":{},\"host\":\"{}\",\"lang\":{},\"snippet\":\"{}\",\"rank\":{},\"fetched_at\":{},\"last_seen\":{}}}",
                json_str(&h.url),
                h.title.as_ref().map(|t| format!("\"{}\"", json_str(t))).unwrap_or_else(|| "null".to_string()),
                json_str(&h.host),
                h.lang.as_ref().map(|l| format!("\"{}\"", json_str(l))).unwrap_or_else(|| "null".to_string()),
                json_str(&h.snippet),
                h.rank,
                opt_num(h.fetched_at),
                opt_num(h.last_seen),
            ));
        }
        let out = format!(
            "{{\"query\":\"{}\",\"total\":{},\"count\":{},\"results\":[{}]}}",
            json_str(&q),
            res.total,
            res.hits.len(),
            items.join(",")
        );
        Resp::json(200, out)
    }

    fn html_find(&self, params: &[(String, String)]) -> Resp {
        let kind = get(params, "kind").unwrap_or("");
        let value = get(params, "value").unwrap_or("");
        if kind.is_empty() || value.is_empty() {
            return Resp::html(
                400,
                layout(
                    "find",
                    "<p>need <code>kind</code> and <code>value</code></p>",
                ),
            );
        }
        let store = self.store.lock().expect("store lock");
        let hits = store.find_by_entity(kind, value, 100, 0);
        let mut b = format!(
            "<h1>{} <code>{}</code></h1><p class=meta>{} layout(s)</p>",
            esc(kind),
            esc(value),
            hits.len()
        );
        for h in &hits {
            let title = h.title.clone().unwrap_or_else(|| h.url.clone());
            b.push_str(&format!(
                "<div class=r><a href=\"{u}\">{t}</a><div class=u>{u}</div><div class=meta>{host}</div></div>",
                u = esc(&h.url),
                t = esc(&title),
                host = esc(&h.host),
            ));
        }
        Resp::html(200, layout("find — onioncrawler", &b))
    }

    fn api_find(&self, params: &[(String, String)]) -> Resp {
        let kind = get(params, "kind").unwrap_or("");
        let value = get(params, "value").unwrap_or("");
        if kind.is_empty() || value.is_empty() {
            return Resp::json(400, "{\"error\":\"need kind and value\"}".to_string());
        }
        let store = self.store.lock().expect("store lock");
        let hits = store.find_by_entity(kind, value, 100, 0);
        let items: Vec<String> = hits
            .iter()
            .map(|h| {
                format!(
                    "{{\"url\":\"{}\",\"host\":\"{}\",\"title\":{},\"last_seen\":{}}}",
                    json_str(&h.url),
                    json_str(&h.host),
                    h.title
                        .as_ref()
                        .map(|t| format!("\"{}\"", json_str(t)))
                        .unwrap_or_else(|| "null".to_string()),
                    opt_num(h.last_seen),
                )
            })
            .collect();
        Resp::json(
            200,
            format!(
                "{{\"kind\":\"{}\",\"value\":\"{}\",\"count\":{},\"results\":[{}]}}",
                json_str(kind),
                json_str(value),
                hits.len(),
                items.join(",")
            ),
        )
    }

    fn html_stats(&self) -> Resp {
        let store = self.store.lock().expect("store lock");
        let m = store.metrics();
        let ec = store.entity_counts();
        let g = |k: &str| *m.get(k).unwrap_or(&0);
        let mut b = String::from("<h1>stats</h1><table>");
        for k in [
            "pages",
            "hosts",
            "hosts_active",
            "hosts_up",
            "hosts_down",
            "hosts_dead",
            "frontier_queued",
            "frontier_done",
            "frontier_error",
            "urls_enqueued",
            "duplicates",
            "link_edges",
            "trap_events",
        ] {
            b.push_str(&format!("<tr><td>{}</td><td><b>{}</b></td></tr>", k, g(k)));
        }
        b.push_str("</table>");
        if !ec.is_empty() {
            let mut kinds: Vec<(&String, &usize)> = ec.iter().collect();
            kinds.sort_by(|a, b| a.0.cmp(b.0));
            b.push_str("<h2>entities</h2><p class=facets>");
            for (kind, n) in kinds {
                b.push_str(&format!("<b>{}</b>: {} &nbsp; ", esc(kind), n));
            }
            b.push_str("</p>");
        }
        Resp::html(200, layout("stats — onioncrawler", &b))
    }

    fn api_stats(&self) -> Resp {
        let store = self.store.lock().expect("store lock");
        let mut entries: Vec<(&str, i64)> = store.metrics().into_iter().collect();
        entries.sort_by(|a, b| a.0.cmp(b.0));
        let items: Vec<String> = entries
            .iter()
            .map(|(k, v)| format!("\"{}\":{}", json_str(k), v))
            .collect();
        Resp::json(200, format!("{{{}}}", items.join(",")))
    }

    fn cached(&self, params: &[(String, String)]) -> Resp {
        let Some(url) = get(params, "url") else {
            return Resp::html(400, layout("cached", "<p>need <code>url</code></p>"));
        };
        let store = self.store.lock().expect("store lock");
        match store.get_page_snapshot(url) {
            Some(snap) => {
                let title = snap.title.clone().unwrap_or_else(|| snap.url.clone());
                let body = format!(
                    "<h1>{}</h1><p class=u>{}</p><p class=meta>cached · {}</p><hr><pre style=\"white-space:pre-wrap\">{}</pre>",
                    esc(&title),
                    esc(&snap.url),
                    esc(&snap.host),
                    esc(snap.body.as_deref().unwrap_or("")),
                );
                Resp::html(200, layout(&format!("cached: {title}"), &body))
            }
            None => Resp::not_found(),
        }
    }

    fn health(&self) -> Resp {
        let store = self.store.lock().expect("store lock");
        let m = store.metrics();
        let g = |k: &str| *m.get(k).unwrap_or(&0);
        Resp::json(
            200,
            format!(
                "{{\"status\":\"ok\",\"pages\":{},\"hosts\":{},\"frontier_queued\":{}}}",
                g("pages"),
                g("hosts"),
                g("frontier_queued")
            ),
        )
    }

    fn metrics(&self) -> Resp {
        let store = self.store.lock().expect("store lock");
        let mut entries: Vec<(&str, i64)> = store.metrics().into_iter().collect();
        entries.sort_by(|a, b| a.0.cmp(b.0));
        let mut out = String::new();
        for (k, v) in entries {
            out.push_str(&format!("onioncrawler_{k} {v}\n"));
        }
        Resp::text(200, "text/plain; version=0.0.4; charset=utf-8", out)
    }

    // ------------------------------------------------------- write endpoints

    /// `POST /add` — submit onion URL(s) as crawl seeds. Public (capped) when
    /// `allow_public_submit`; otherwise admin-only. URLs come from repeated
    /// `url=` fields and/or a newline-separated `urls=` blob (form body + query).
    fn do_add(&self, params: &[(String, String)], body: &str, auth: Option<&str>) -> Resp {
        let public = self.config.allow_public_submit;
        if !public && !self.admin_ok(auth) {
            return self.auth_error();
        }
        let form = parse_qsl(body, true);
        let mut urls: Vec<String> = Vec::new();
        for (k, v) in form.iter().chain(params.iter()) {
            match k.as_str() {
                "url" => urls.push(v.clone()),
                "urls" => urls.extend(v.lines().map(str::to_string)),
                _ => {}
            }
        }
        if urls.is_empty() {
            return Resp::json(400, "{\"error\":\"no url(s) provided\"}".to_string());
        }
        // Public submit ⇒ untrusted (honour caps + per-call limit); admin ⇒ trusted.
        let (caps, max_urls) = if public {
            (
                Some(self.config.submit_caps),
                Some(self.config.max_public_add_urls),
            )
        } else {
            (None, None)
        };
        let now = now_secs();
        let summary = {
            let mut store = self.store.lock().expect("store lock");
            submit_many(
                &mut store,
                self.abuse.as_deref(),
                urls,
                self.config.allow_v2,
                caps,
                max_urls,
                self.config.allow_i2p,
                now,
            )
        };
        Resp::json(200, summary_json(&summary))
    }

    /// `POST /purge` — admin: block host(s) and delete their indexed pages.
    fn do_purge(&self, params: &[(String, String)], body: &str, auth: Option<&str>) -> Resp {
        if !self.admin_ok(auth) {
            return self.auth_error();
        }
        let form = parse_qsl(body, true);
        let hosts: Vec<String> = form
            .iter()
            .chain(params.iter())
            .filter(|(k, _)| k == "host")
            .map(|(_, v)| v.clone())
            .collect();
        if hosts.is_empty() {
            return Resp::json(400, "{\"error\":\"no host provided\"}".to_string());
        }
        let mut store = self.store.lock().expect("store lock");
        let purged: Vec<String> = hosts
            .iter()
            .map(|h| {
                let removed = store.purge_host(h);
                format!(
                    "{{\"host\":\"{}\",\"pages_removed\":{}}}",
                    json_str(&normalize_host(h)),
                    removed
                )
            })
            .collect();
        Resp::json(200, format!("{{\"purged\":[{}]}}", purged.join(",")))
    }

    /// `POST /recrawl` — admin: requeue every due page for recrawl.
    fn do_recrawl(&self, auth: Option<&str>) -> Resp {
        if !self.admin_ok(auth) {
            return self.auth_error();
        }
        let now = now_secs();
        let n = {
            let mut store = self.store.lock().expect("store lock");
            store.mark_recrawl_due(now, self.config.recrawl_interval)
        };
        Resp::json(200, format!("{{\"recrawl_due\":{n}}}"))
    }
}

fn result_json(r: &SubmitResult) -> String {
    let field = |o: &Option<String>| {
        o.as_ref()
            .map(|s| format!("\"{}\"", json_str(s)))
            .unwrap_or_else(|| "null".to_string())
    };
    format!(
        "{{\"status\":\"{}\",\"host\":{},\"url\":{}}}",
        r.status.as_str(),
        field(&r.host),
        field(&r.url)
    )
}

fn summary_json(s: &SubmitSummary) -> String {
    let results: Vec<String> = s.results.iter().map(result_json).collect();
    format!(
        "{{\"ok\":{},\"dup\":{},\"not-onion\":{},\"blocked\":{},\"capped\":{},\"skipped\":{},\"results\":[{}]}}",
        s.ok, s.dup, s.not_onion, s.blocked, s.capped, s.skipped, results.join(",")
    )
}

fn offset_has_more(total: usize, page: usize, limit: usize) -> bool {
    page * limit < total
}

const ROBOTS: &str = "User-agent: *\nDisallow: /\n";

fn opensearch(base_url: &str) -> String {
    let tpl = |suffix: &str| {
        if base_url.is_empty() {
            format!("{suffix}?q={{searchTerms}}")
        } else {
            format!("{base_url}{suffix}?q={{searchTerms}}")
        }
    };
    format!(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\
<OpenSearchDescription xmlns=\"http://a9.com/-/spec/opensearch/1.1/\">\
<ShortName>onioncrawler</ShortName>\
<Description>Darknet index search</Description>\
<InputEncoding>UTF-8</InputEncoding>\
<Url type=\"text/html\" template=\"{}\"/>\
<Url type=\"application/json\" template=\"{}\"/>\
</OpenSearchDescription>\n",
        esc(&tpl("/search")),
        esc(&tpl("/api/search")),
    )
}

// ------------------------------------------------------------ async server

/// Serve the search front-end on `listener` until the process ends. Each
/// accepted connection is handled on its own task. `net`-only.
#[cfg(feature = "net")]
pub async fn serve(listener: tokio::net::TcpListener, server: SearchServer) -> std::io::Result<()> {
    loop {
        let (stream, _peer) = listener.accept().await?;
        let srv = server.clone();
        tokio::spawn(async move {
            let _ = handle_conn(stream, srv).await;
        });
    }
}

/// Read one HTTP/1.1 request from `stream`, route it, and write the response.
/// Connection-per-request (no keep-alive) — simple and correct for a loopback
/// admin UI. `net`-only.
#[cfg(feature = "net")]
pub async fn handle_conn(
    mut stream: tokio::net::TcpStream,
    server: SearchServer,
) -> std::io::Result<()> {
    use tokio::io::AsyncReadExt;

    const MAX_HEAD: usize = 64 * 1024;
    let mut buf = Vec::new();
    let mut tmp = [0u8; 4096];
    // read until end of headers
    let head_end = loop {
        if let Some(pos) = find(&buf, b"\r\n\r\n") {
            break pos;
        }
        if buf.len() > MAX_HEAD {
            let _ = write_resp(
                &mut stream,
                &Resp::json(400, "{\"error\":\"header too large\"}".into()),
            )
            .await;
            return Ok(());
        }
        let n = stream.read(&mut tmp).await?;
        if n == 0 {
            return Ok(());
        }
        buf.extend_from_slice(&tmp[..n]);
    };

    let head = String::from_utf8_lossy(&buf[..head_end]).to_string();
    let mut lines = head.split("\r\n");
    let request_line = lines.next().unwrap_or("");
    let mut it = request_line.split_whitespace();
    let method = it.next().unwrap_or("GET").to_string();
    let target = it.next().unwrap_or("/").to_string();

    // read the body if a Content-Length was given, and capture Authorization
    let mut content_length = 0usize;
    let mut auth: Option<String> = None;
    for line in lines {
        if let Some((k, v)) = line.split_once(':') {
            let k = k.trim();
            if k.eq_ignore_ascii_case("content-length") {
                content_length = v.trim().parse().unwrap_or(0);
            } else if k.eq_ignore_ascii_case("authorization") {
                auth = Some(v.trim().to_string());
            }
        }
    }
    let mut body = buf[head_end + 4..].to_vec();
    while body.len() < content_length {
        let n = stream.read(&mut tmp).await?;
        if n == 0 {
            break;
        }
        body.extend_from_slice(&tmp[..n]);
    }
    let body_str = String::from_utf8_lossy(&body).to_string();

    let resp = server.route(&method, &target, &body_str, auth.as_deref());
    write_resp(&mut stream, &resp).await
}

#[cfg(feature = "net")]
async fn write_resp(stream: &mut tokio::net::TcpStream, resp: &Resp) -> std::io::Result<()> {
    use tokio::io::AsyncWriteExt;
    let head = format!(
        "HTTP/1.1 {} {}\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",
        resp.status,
        reason(resp.status),
        resp.ctype,
        resp.body.len(),
    );
    stream.write_all(head.as_bytes()).await?;
    stream.write_all(&resp.body).await?;
    stream.flush().await
}

/// Find the first occurrence of `needle` in `hay` (bounds-safe).
#[cfg(feature = "net")]
fn find(hay: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || hay.len() < needle.len() {
        return None;
    }
    let last = hay.len() - needle.len();
    (0..=last).find(|&i| &hay[i..i + needle.len()] == needle)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::store::Store;

    fn server_with_pages() -> SearchServer {
        let mut s = Store::new();
        s.ensure_host("a.onion", 1.0);
        s.store_page(
            "http://a.onion/1",
            "a.onion",
            Some("Widget Emporium"),
            Some("the finest widgets on the darknet market here"),
            Some("h1"),
            Some(200),
            Some("text/html"),
            None,
            10.0,
            false,
            None,
            None,
            None,
        );
        s.store_page(
            "http://a.onion/2",
            "a.onion",
            Some("About"),
            Some("contact us and donate 1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2 today"),
            Some("h2"),
            Some(200),
            Some("text/html"),
            None,
            11.0,
            false,
            None,
            None,
            None,
        );
        SearchServer::new(Arc::new(Mutex::new(s)), "http://127.0.0.1:8888")
    }

    fn body(r: &Resp) -> String {
        String::from_utf8_lossy(&r.body).to_string()
    }

    #[test]
    fn html_search_renders_hits_and_form() {
        let srv = server_with_pages();
        let r = srv.route("GET", "/search?q=widget", "", None);
        assert_eq!(r.status, 200);
        let b = body(&r);
        assert!(b.contains("Widget Emporium"));
        assert!(b.contains("http://a.onion/1"));
        assert!(b.contains("<mark>widget</mark>") || b.contains("widget"));
        assert!(b.contains("<form"));
    }

    #[test]
    fn empty_query_shows_landing() {
        let srv = server_with_pages();
        let r = srv.route("GET", "/", "", None);
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("pages · "));
    }

    #[test]
    fn api_search_is_json() {
        let srv = server_with_pages();
        let r = srv.route("GET", "/api/search?q=widget", "", None);
        assert_eq!(r.status, 200);
        assert_eq!(r.ctype, "application/json");
        let b = body(&r);
        assert!(b.contains("\"total\":1"));
        assert!(b.contains("http://a.onion/1"));
    }

    #[test]
    fn find_by_entity_endpoint() {
        let srv = server_with_pages();
        let r = srv.route(
            "GET",
            "/api/find?kind=btc&value=1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2",
            "",
            None,
        );
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("http://a.onion/2"));
        // missing params → 400
        assert_eq!(srv.route("GET", "/api/find", "", None).status, 400);
    }

    #[test]
    fn stats_metrics_health() {
        let srv = server_with_pages();
        assert!(body(&srv.route("GET", "/api/stats", "", None)).contains("\"pages\":2"));
        let m = srv.route("GET", "/metrics", "", None);
        assert_eq!(m.ctype, "text/plain; version=0.0.4; charset=utf-8");
        assert!(body(&m).contains("onioncrawler_pages 2"));
        assert!(body(&srv.route("GET", "/health", "", None)).contains("\"status\":\"ok\""));
    }

    #[test]
    fn cached_snapshot_and_404() {
        let srv = server_with_pages();
        let r = srv.route("GET", "/cached?url=http%3A%2F%2Fa.onion%2F1", "", None);
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("Widget Emporium"));
        assert_eq!(
            srv.route("GET", "/cached?url=http://nope.onion/x", "", None)
                .status,
            404
        );
    }

    #[test]
    fn opensearch_robots_and_unknown() {
        let srv = server_with_pages();
        let os = srv.route("GET", "/opensearch.xml", "", None);
        assert!(body(&os).contains("OpenSearchDescription"));
        assert!(body(&os).contains("http://127.0.0.1:8888/search?q="));
        assert!(body(&srv.route("GET", "/robots.txt", "", None)).contains("Disallow: /"));
        assert_eq!(srv.route("GET", "/nope", "", None).status, 404);
    }

    #[test]
    fn host_facet_filter_roundtrips() {
        let srv = server_with_pages();
        // search restricted to a host returns only that host's page
        let r = srv.route("GET", "/api/search?q=widget&host=a.onion", "", None);
        assert!(body(&r).contains("\"total\":1"));
        let r = srv.route("GET", "/api/search?q=widget&host=other.onion", "", None);
        assert!(body(&r).contains("\"total\":0"));
    }

    #[test]
    fn admin_endpoints_require_auth() {
        let srv = server_with_pages().with_admin("secret");
        // no token → 401
        assert_eq!(srv.route("POST", "/recrawl", "", None).status, 401);
        // wrong token → 401
        assert_eq!(
            srv.route("POST", "/recrawl", "", Some("Bearer nope"))
                .status,
            401
        );
        // right token → 200
        let r = srv.route("POST", "/recrawl", "", Some("Bearer secret"));
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("recrawl_due"));
    }

    #[test]
    fn admin_disabled_returns_403() {
        // no admin token configured → admin endpoints are 403 (disabled)
        let srv = server_with_pages();
        assert_eq!(
            srv.route("POST", "/purge", "host=a.onion", None).status,
            403
        );
    }

    #[test]
    fn public_submit_enqueues_and_caps() {
        let cfg = ServeConfig {
            allow_public_submit: true,
            submit_caps: Caps {
                max_unique_urls: Some(1),
                ..Caps::default()
            },
            ..ServeConfig::default()
        };
        let srv = SearchServer::new(Arc::new(Mutex::new(Store::new())), "").with_config(cfg);
        let h1 = format!("http://{}.onion/", "a".repeat(56));
        let h2 = format!("http://{}.onion/", "b".repeat(56));
        // public POST /add (no auth) enqueues the first, caps the second
        let r = srv.route("POST", "/add", &format!("url={h1}"), None);
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("\"ok\":1"));
        let r = srv.route("POST", "/add", &format!("url={h2}"), None);
        assert!(body(&r).contains("\"capped\":1"));
        // a clearnet URL is refused
        let r = srv.route("POST", "/add", "url=http://example.com/", None);
        assert!(body(&r).contains("\"not-onion\":1"));
    }

    #[test]
    fn admin_add_is_trusted_and_purge_works() {
        let srv = server_with_pages().with_admin("tok");
        // admin /add without a token → 401
        assert_eq!(srv.route("POST", "/add", "url=x", None).status, 401);
        // admin purge removes the seeded host's pages
        let r = srv.route("POST", "/purge", "host=a.onion", Some("Bearer tok"));
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("\"host\":\"a.onion\""));
        // its pages are gone from search now
        assert!(body(&srv.route("GET", "/api/search?q=widget", "", None)).contains("\"total\":0"));
    }

    #[cfg(feature = "net")]
    #[tokio::test]
    async fn loopback_round_trip() {
        use tokio::io::{AsyncReadExt, AsyncWriteExt};
        let srv = server_with_pages();
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move {
            let _ = super::serve(listener, srv).await;
        });

        let mut c = tokio::net::TcpStream::connect(addr).await.unwrap();
        c.write_all(b"GET /api/search?q=widget HTTP/1.1\r\nHost: x\r\n\r\n")
            .await
            .unwrap();
        let mut resp = Vec::new();
        c.read_to_end(&mut resp).await.unwrap();
        let text = String::from_utf8_lossy(&resp);
        assert!(
            text.starts_with("HTTP/1.1 200 OK"),
            "status line: {text:.40}"
        );
        assert!(text.contains("application/json"));
        assert!(text.contains("\"total\":1"));
        assert!(text.contains("http://a.onion/1"));
    }
}
