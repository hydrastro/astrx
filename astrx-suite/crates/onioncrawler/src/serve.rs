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
//!
//! # The write endpoints are durable
//!
//! `/purge`, `/add` and `/recrawl` mutate the shared [`Store`] **and commit it**
//! to [`ServeConfig::store_path`] through `crawlcore::atomicfile::write_atomic`,
//! under the same lock as the mutation. Each answers with `"persisted"` saying
//! whether the change reached disk, and a failed write is a `500`. Leaving
//! `store_path` unset is a supported in-memory deployment, and then
//! `"persisted":false` says exactly that.
//!
//! # What bounds a hostile client
//!
//! * **Rate limits** — [`SearchServer::route_limited`] charges every request to
//!   a per-client [`TokenBucket`] before it reaches the store, in two classes
//!   (reads, writes) with [`RateLimits`] configurable per endpoint class;
//!   `/health` and `/metrics` are exempt. This is on the network path
//!   ([`serve`]); the pure [`SearchServer::route`] deliberately has no limiter,
//!   as it has no client to key on.
//! * **A total per-request deadline** — [`ServeLimits::request_timeout`] covers
//!   the head read, the route and the response write together, so a byte-at-a-
//!   time client cannot hold a task and a file descriptor open indefinitely.
//! * **A header cap and a body budget** — see `handle_conn_from`.

use std::sync::{Arc, Mutex};
use std::time::Instant;

use crate::abuse::AbuseFilter;
use crate::lang::known_languages;
use crate::onion::normalize_host;
use crate::ratelimit::TokenBucket;
use crate::store::{Caps, Facets, SearchHit, Store};
use crate::submit::{submit_many, SubmitResult, SubmitSummary};
use crawlcore::urlparse::{parse_qsl, urlencode};

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
    /// The snapshot file the write endpoints commit to after every mutation.
    ///
    /// `None` (the default) makes `/purge`, `/add` and `/recrawl` **RAM-only**:
    /// they change what this process serves and nothing else, and the next
    /// `read_store` brings the purged pages straight back. Every response from a
    /// write endpoint carries `"persisted"` saying which of the two happened, so
    /// an operator running a takedown is never told "removed" about a change
    /// that only exists in memory.
    pub store_path: Option<String>,
    /// Per-client request limits ([`RateLimits`]).
    pub rate_limits: RateLimits,
}

/// The trap budgets a public (unauthenticated) `POST /add` submission is admitted
/// under — the values [`ServeConfig::default`] installs in
/// [`ServeConfig::submit_caps`].
///
/// These are *not* cosmetic. `submit_caps` used to default to `Caps::default()`,
/// i.e. every field `None`, i.e. **no cap at all** on the endpoint the module doc
/// calls "capped". With `allow_public_submit` on and no caps, one POST carrying
/// `max_public_add_urls` (100) distinct v3 onion URLs adds 100 frontier rows and
/// up to 100 host rows; repeated at the write rate limit (1/s) that is 360 000
/// rows an hour, each holding a ~70-byte URL plus its template and skeleton keys,
/// growing the frontier — and every snapshot written from it — until the process
/// is OOM-killed. Nothing else in the path bounds it: the abuse filter only
/// blocks *known* hosts, and a v3 onion address is 2^256 addresses' worth of
/// distinct-looking strings to invent.
pub mod submit_caps {
    /// Global frontier ceiling for public submissions (checked against the
    /// `urls_enqueued` counter). Public intake stops at 100k URLs; the crawler's
    /// own expansion is `force`d and unaffected.
    pub const MAX_UNIQUE_URLS: i64 = 100_000;
    /// Per-host enqueue ceiling. A public submitter seeds a host, they do not
    /// enumerate it — 100 URLs on one onion is already far past a seed.
    pub const MAX_PAGES_PER_HOST: i64 = 100;
    /// Per-(host, template) ceiling — the calendar / query-explosion backstop.
    pub const MAX_URLS_PER_TEMPLATE: i64 = 20;
    /// Per-skeleton ceiling — the id-parameterized page-farm backstop.
    pub const MAX_URLS_PER_SKELETON: i64 = 50;
}

impl Default for ServeConfig {
    fn default() -> Self {
        ServeConfig {
            admin_token: String::new(),
            allow_public_submit: false,
            submit_caps: Caps {
                max_unique_urls: Some(submit_caps::MAX_UNIQUE_URLS),
                max_pages_per_host: Some(submit_caps::MAX_PAGES_PER_HOST),
                max_urls_per_template: Some(submit_caps::MAX_URLS_PER_TEMPLATE),
                max_urls_per_skeleton: Some(submit_caps::MAX_URLS_PER_SKELETON),
            },
            max_public_add_urls: 100,
            allow_v2: false,
            allow_i2p: false,
            recrawl_interval: 0.0,
            store_path: None,
            rate_limits: RateLimits::default(),
        }
    }
}

/// Per-client token-bucket limits for the served endpoints, in two classes.
///
/// Applied by [`SearchServer::route_limited`], which is what the accept loop
/// calls; the buckets are shared by every clone of the server, so the limit is
/// per client and not per connection.
///
/// # Why two classes
///
/// A read costs one bounded search; a write costs a mutation **plus a full
/// snapshot fsync** of the whole index (see [`ServeConfig::store_path`]), so the
/// two cannot share a budget. At the read defaults one client gets 20 searches a
/// second; at the write defaults it gets one snapshot write a second sustained,
/// which is also what bounds how fast a public `/add` can grow the frontier
/// (100 URLs per request × 1 request/s) before [`ServeConfig::submit_caps`]
/// stops it outright.
///
/// # The onion-service caveat
///
/// Behind a Tor onion service every request arrives from `127.0.0.1`, so the
/// per-key table collapses to one shared bucket for the whole world (see the
/// [`crate::ratelimit`] module doc). The read defaults are set for that reading —
/// generous enough not to be a self-DoS on a busy hidden service, tight enough
/// that one client cannot pin the store mutex.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct RateLimits {
    /// Sustained GETs per second per client (`/`, `/search`, `/api/*`, `/find`,
    /// `/cached`, `/stats`, `/robots.txt`, `/opensearch.xml`).
    pub read_rate: f64,
    /// Burst of GETs a client may take at once.
    pub read_burst: f64,
    /// Sustained POSTs per second per client (`/add`, `/purge`, `/recrawl`).
    pub write_rate: f64,
    /// Burst of POSTs a client may take at once.
    pub write_burst: f64,
    /// Distinct clients tracked before LRU eviction (bounded memory).
    pub max_clients: usize,
}

impl Default for RateLimits {
    fn default() -> Self {
        RateLimits {
            read_rate: 20.0,
            read_burst: 60.0,
            write_rate: 1.0,
            write_burst: 10.0,
            max_clients: 4096,
        }
    }
}

impl RateLimits {
    /// A configuration with no limiting at all — for an embedder that limits at
    /// a reverse proxy, and for tests that drive thousands of requests.
    #[must_use]
    pub fn unlimited() -> Self {
        RateLimits {
            read_rate: f64::INFINITY,
            read_burst: f64::INFINITY,
            write_rate: f64::INFINITY,
            write_burst: f64::INFINITY,
            max_clients: 1,
        }
    }
}

/// Which limiter bucket a request is charged to.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum RateClass {
    Read,
    Write,
    /// `/health` and `/metrics`: the monitoring scrape. Exempt on purpose — a
    /// limiter that answers 429 to the health check makes an overloaded server
    /// look dead to its supervisor, which restarts it, which is worse.
    Exempt,
}

impl RateClass {
    fn of(method: &str, path: &str) -> Self {
        if method == "POST" {
            return RateClass::Write;
        }
        match path {
            "/health" | "/metrics" => RateClass::Exempt,
            _ => RateClass::Read,
        }
    }
}

/// What a write endpoint's mutation did to durable storage.
#[derive(Clone, Debug, PartialEq, Eq)]
enum Commit {
    /// Written to [`ServeConfig::store_path`] and fsynced.
    Durable,
    /// Nothing to write: the request changed no state.
    NoChange,
    /// No `store_path` configured — the mutation exists only in this process.
    RamOnly,
    /// The snapshot write failed; the mutation exists only in this process.
    Failed(String),
}

impl Commit {
    /// The `"persisted":…` / `"error":…` tail of a write endpoint's JSON body.
    fn json_fields(&self) -> String {
        match self {
            Commit::Durable | Commit::NoChange => ",\"persisted\":true".to_string(),
            Commit::RamOnly => ",\"persisted\":false,\"warning\":\"no snapshot path configured; \
change is in-memory only and will not survive a restart\""
                .to_string(),
            Commit::Failed(e) => format!(",\"persisted\":false,\"error\":\"{}\"", json_str(e)),
        }
    }

    /// The HTTP status a write endpoint answers with. A failed commit is a 500:
    /// the operator asked for a takedown, the pages are gone from RAM but will
    /// be back on the next load, and a 200 here would say otherwise.
    fn status(&self) -> u16 {
        match self {
            Commit::Failed(_) => 500,
            _ => 200,
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
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        429 => "Too Many Requests",
        500 => "Internal Server Error",
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

/// Upper bound on the page number we will honour — the Python
/// `search.MAX_PAGE`. Prevents a crafted `page` query param from producing an
/// absurd `OFFSET`; 100k pages is far more than a human search UI needs.
pub const MAX_PAGE: usize = 100_000;

/// How many hosts / languages the facet row shows — the Python `_facet_html`
/// `[:6]` slice.
const FACET_TOP: usize = 6;

/// How many characters of a host a facet link shows before the ellipsis — the
/// Python `_facet_html` `h['host'][:16]` slice (a v3 onion is 62 chars, which
/// would wrap the facet row).
const FACET_HOST_CHARS: usize = 16;

// ------------------------------------------------------------- HTML chrome

/// The inline stylesheet, **byte-identical** to the Python reference
/// `onioncrawler.search.PAGE_STYLE` (`legacy-python/onioncrawler/onioncrawler/
/// search.py`). Kept verbatim — same rule order, same line breaks — rather than
/// re-flowed, so any future drift between the two is a readable diff; the
/// cross-check `tests/xcheck_serve.rs` pins it against the real Python constant.
/// Every class it styles (`.row` `.filters` `.result` `.title` `.url` `.snip`
/// `.meta` `.facets` `.nav` `.muted`) is emitted by the renderers below.
pub const STYLE: &str = r"
body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
max-width:760px;margin:0 auto;padding:1.2rem;color:#111;background:#fafafa;line-height:1.45}
header a{text-decoration:none;color:#5b21b6}
h1{font-size:1.4rem;margin:.2rem 0 1rem}
form{margin-bottom:1rem}
.row{display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap}
input[type=text]{flex:1;min-width:12rem;padding:.55rem .7rem;font-size:1rem;border:1px solid #ccc;border-radius:6px}
input[type=date],select{padding:.4rem;border:1px solid #ccc;border-radius:6px}
.filters input[type=text]{min-width:8rem}
button{padding:.55rem 1rem;font-size:1rem;border:0;border-radius:6px;background:#5b21b6;color:#fff;cursor:pointer}
.result{margin:1rem 0;padding-bottom:.8rem;border-bottom:1px solid #eee}
.result .title{font-size:1.08rem;font-weight:600;color:#1a0dab}
.result .url{color:#0a7d33;font-size:.86rem;word-break:break-all}
.result .snip{color:#333;font-size:.95rem;margin-top:.15rem}
.result .meta{color:#888;font-size:.78rem;margin-top:.2rem}
mark{background:#fde68a;padding:0 1px}
.nav{margin-top:1.2rem;display:flex;gap:1rem}
.facets{font-size:.82rem;color:#555;margin:.4rem 0 1rem}
.facets a{color:#5b21b6;text-decoration:none;margin-right:.5rem}
.muted{color:#888;font-size:.85rem}
footer{margin-top:2rem;color:#999;font-size:.78rem}
";

/// The Python `render_page` footer.
const FOOTER: &str = "<footer>No JavaScript. No logging. Bound to localhost. \
Operator is responsible for abuse filtering.</footer>";

/// The `<header>` chrome (`header a` + `h1` are styled by [`STYLE`]).
const HEADER: &str = "<header><a href='/'><h1>onioncrawler</h1></a></header>";

/// The `&larr; search` back link the Python secondary pages open with.
const BACK: &str = "<p><a href='/'>&larr; search</a></p>";

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

// ----------------------------------------------------------------- filters

/// The cleaned query filters — the port of the Python `_clean_filters` result
/// dict: the host / language selectors plus both the *raw* `YYYY-MM-DD` date
/// strings (reflected back into the form and re-emitted on every link) and
/// their parsed epochs (handed to the store).
#[derive(Clone, Debug, Default, PartialEq)]
struct Filters {
    host: String,
    lang: String,
    since_s: String,
    until_s: String,
    since: Option<f64>,
    until: Option<f64>,
    /// The explicit `?limit=` (a Rust-side parameter the Python has no analogue
    /// for — its page size is server config). Carried through every pager and
    /// facet link so paging does not silently revert to the default.
    limit: Option<usize>,
}

impl Filters {
    /// The Python `_clean_filters(qs)`.
    fn from_params(params: &[(String, String)]) -> Self {
        let s = |k: &str| get(params, k).unwrap_or("").trim().to_string();
        let since_s = s("since");
        let until_s = s("until");
        Filters {
            host: s("host").to_lowercase(),
            lang: s("lang").to_lowercase(),
            since: parse_date(&since_s, false),
            until: parse_date(&until_s, true),
            since_s,
            until_s,
            limit: get(params, "limit")
                .and_then(|v| v.parse::<i64>().ok())
                .map(|n| n.clamp(1, 100) as usize),
        }
    }

    fn opt(v: &str) -> Option<String> {
        if v.is_empty() {
            None
        } else {
            Some(v.to_string())
        }
    }

    /// The query string for a link at `page` that preserves every active filter
    /// — the Python `_qs` (`q`, `page`, then `host`, `lang`, `since`, `until`),
    /// plus this port's own `limit` last.
    fn qs(&self, q: &str, page: usize) -> String {
        let mut pairs: Vec<(String, String)> = vec![
            ("q".to_string(), q.to_string()),
            ("page".to_string(), page.to_string()),
        ];
        for (k, v) in [
            ("host", &self.host),
            ("lang", &self.lang),
            ("since", &self.since_s),
            ("until", &self.until_s),
        ] {
            if !v.is_empty() {
                pairs.push((k.to_string(), v.clone()));
            }
        }
        if let Some(n) = self.limit {
            pairs.push(("limit".to_string(), n.to_string()));
        }
        urlencode(&pairs)
    }
}

/// The Python `_parse_date`: a `YYYY-MM-DD` string to a UTC epoch, with `end`
/// pinning the last second of that day (an inclusive upper bound). A string that
/// is not a date falls back to a raw epoch number, so the JSON API's numeric
/// `since=` / `until=` keeps working alongside the form's date inputs.
fn parse_date(s: &str, end: bool) -> Option<f64> {
    let s = s.trim();
    if s.is_empty() {
        return None;
    }
    match ymd_epoch(s) {
        Some(epoch) => Some(if end { epoch + (86_400.0 - 1.0) } else { epoch }),
        None => s.parse::<f64>().ok().filter(|v| v.is_finite()),
    }
}

/// `strptime(s, "%Y-%m-%d")` + `calendar.timegm`: a calendar date to a UTC
/// epoch, rejecting anything that is not a real date.
fn ymd_epoch(s: &str) -> Option<f64> {
    let mut it = s.split('-');
    let (y, m, d) = (it.next()?, it.next()?, it.next()?);
    if it.next().is_some() {
        return None;
    }
    let num = |p: &str, max_len: usize| -> Option<i64> {
        if p.is_empty() || p.len() > max_len || !p.bytes().all(|b| b.is_ascii_digit()) {
            return None;
        }
        p.parse::<i64>().ok()
    };
    let (y, m, d) = (num(y, 4)?, num(m, 2)?, num(d, 2)?);
    if !(1..=9999).contains(&y) || !(1..=12).contains(&m) || d < 1 || d > days_in_month(y, m) {
        return None;
    }
    Some((days_from_civil(y, m, d) * 86_400) as f64)
}

fn is_leap(y: i64) -> bool {
    (y % 4 == 0 && y % 100 != 0) || y % 400 == 0
}

fn days_in_month(y: i64, m: i64) -> i64 {
    match m {
        1 | 3 | 5 | 7 | 8 | 10 | 12 => 31,
        4 | 6 | 9 | 11 => 30,
        _ => {
            if is_leap(y) {
                29
            } else {
                28
            }
        }
    }
}

/// Howard Hinnant's `days_from_civil`: `(y, m, d)` → days since 1970-01-01.
fn days_from_civil(y: i64, m: i64, d: i64) -> i64 {
    let y = if m <= 2 { y - 1 } else { y };
    let era = if y >= 0 { y } else { y - 399 } / 400;
    let yoe = y - era * 400;
    let mp = if m > 2 { m - 3 } else { m + 9 };
    let doy = (153 * mp + 2) / 5 + d - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe - 719_468
}

/// Its inverse: days since 1970-01-01 → `(y, m, d)`.
fn civil_from_days(z: i64) -> (i64, i64, i64) {
    let z = z + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    (if m <= 2 { y + 1 } else { y }, m, d)
}

/// The Python `_fmt_time`: `%Y-%m-%d %H:%M UTC`, or `unknown`.
fn fmt_time(ts: Option<f64>) -> String {
    let secs = match ts {
        Some(t) if t != 0.0 && t.is_finite() => t as i64,
        _ => return "unknown".to_string(),
    };
    let (y, mo, d) = civil_from_days(secs.div_euclid(86_400));
    let tod = secs.rem_euclid(86_400);
    format!(
        "{y:04}-{mo:02}-{d:02} {:02}:{:02} UTC",
        tod / 3600,
        tod % 3600 / 60
    )
}

// ------------------------------------------------------------- HTML pieces

/// The Python `_safe_snippet`: escape the snippet, then restore our own
/// `<mark>` highlight tags.
fn safe_snippet(snip: &str) -> String {
    if snip.is_empty() {
        return String::new();
    }
    esc(snip)
        .replace("&lt;mark&gt;", "<mark>")
        .replace("&lt;/mark&gt;", "</mark>")
}

/// The first `n` *characters* of `s` (Python's `s[:n]`).
fn head_chars(s: &str, n: usize) -> String {
    s.chars().take(n).collect()
}

/// The search form — the port of the Python `render_page` form block: the query
/// row plus the `row filters` row (host / language / date range), every control
/// reflecting the value currently in force.
fn search_form(q: &str, f: &Filters) -> String {
    let langs = known_languages();
    let mut opts = String::new();
    for c in std::iter::once("")
        .chain(langs.iter().copied())
        .chain(if langs.contains(&"un") {
            None
        } else {
            Some("un")
        })
    {
        opts.push_str(&format!(
            "<option value=\"{c}\"{}>{c}</option>",
            if f.lang == c { " selected" } else { "" }
        ));
    }
    format!(
        "<form action='/search' method='get'>\
<div class=row>\
<input type=text name=q value=\"{q}\" placeholder='search indexed .onion pages' autofocus>\
<button type=submit>Search</button></div>\
<div class='row filters'>\
<input type=text name=host value=\"{host}\" placeholder='host filter (x.onion)'>\
<label>lang <select name=lang>{opts}</select></label>\
<label>from <input type=date name=since value=\"{since}\"></label>\
<label>to <input type=date name=until value=\"{until}\"></label>\
</div></form>",
        q = esc(q),
        host = esc(&f.host),
        since = esc(&f.since_s),
        until = esc(&f.until_s),
    )
}

/// One result row — the Python `render_page` `<div class=result>` block.
fn result_html(h: &SearchHit) -> String {
    let title = h
        .title
        .as_deref()
        .filter(|t| !t.is_empty())
        .unwrap_or(&h.url);
    format!(
        "<div class=result>\
<div class=title>{t}</div>\
<div class=url>{u}</div>\
<div class=snip>{s}</div>\
<div class=meta>host: {host} · lang: {lang} · last seen: {seen}</div>\
</div>",
        t = esc(title),
        u = esc(&h.url),
        s = safe_snippet(&h.snippet),
        host = esc(&h.host),
        lang = esc(h.lang.as_deref().filter(|l| !l.is_empty()).unwrap_or("un")),
        seen = fmt_time(h.last_seen),
    )
}

/// The facet row — the Python `_facet_html`. Each link re-emits every active
/// filter (via [`Filters::qs`]) with just its own dimension replaced, and hosts
/// are truncated so a v3 onion cannot wrap the row.
///
/// The hrefs are not `esc`aped, matching the reference: `urlencode` already
/// percent-encodes everything outside `[A-Za-z0-9_.\-~+%&=]`, so no quote or
/// angle bracket can survive into the attribute.
fn facet_html(q: &str, f: &Filters, facets: &Facets) -> String {
    let mut bits: Vec<String> = Vec::new();
    if !facets.hosts.is_empty() {
        let hs: Vec<String> = facets
            .hosts
            .iter()
            .take(FACET_TOP)
            .map(|(host, n)| {
                let mut ff = f.clone();
                ff.host = host.clone();
                format!(
                    "<a href='/search?{}'>{}… ({n})</a>",
                    ff.qs(q, 1),
                    esc(&head_chars(host, FACET_HOST_CHARS))
                )
            })
            .collect();
        bits.push(format!("hosts: {}", hs.join(" ")));
    }
    if !facets.langs.is_empty() {
        let ls: Vec<String> = facets
            .langs
            .iter()
            .take(FACET_TOP)
            .map(|(lang, n)| {
                let mut ff = f.clone();
                ff.lang = lang.clone();
                format!("<a href='/search?{}'>{} ({n})</a>", ff.qs(q, 1), esc(lang))
            })
            .collect();
        bits.push(format!("langs: {}", ls.join(" ")));
    }
    if bits.is_empty() {
        String::new()
    } else {
        format!("<div class=facets>{}</div>", bits.join(" &nbsp;·&nbsp; "))
    }
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
    /// The two rate-limit buckets, shared by every clone of this handle — a
    /// per-connection bucket would limit nothing, since a flood is a flood of
    /// connections.
    limiter: Arc<Mutex<(TokenBucket, TokenBucket)>>,
    /// The monotonic origin the limiter's clock is measured from.
    /// [`std::time::SystemTime`] is not usable here: an NTP step backwards makes
    /// `now - last` negative, which *removes* tokens from every bucket.
    started: Instant,
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
        let config = ServeConfig::default();
        SearchServer {
            limiter: Arc::new(Mutex::new(buckets(&config.rate_limits))),
            store,
            abuse: None,
            base_url: base_url.into(),
            config,
            started: Instant::now(),
        }
    }

    /// Enable the admin write endpoints (`/purge`, `/recrawl`, authed `/add`)
    /// behind a `Bearer` token.
    #[must_use]
    pub fn with_admin(mut self, token: impl Into<String>) -> Self {
        self.config.admin_token = token.into();
        self
    }

    /// Replace the full serve configuration (submission policy + admin token +
    /// rate limits + snapshot path). Rebuilds the rate-limit buckets, so the
    /// limits in `config` are the ones in force.
    #[must_use]
    pub fn with_config(mut self, config: ServeConfig) -> Self {
        self.limiter = Arc::new(Mutex::new(buckets(&config.rate_limits)));
        self.config = config;
        self
    }

    /// Commit the write endpoints to `path`, so a purge survives a restart.
    #[must_use]
    pub fn with_store_path(mut self, path: impl Into<String>) -> Self {
        self.config.store_path = Some(path.into());
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
        let Some(given) = auth.and_then(|a| a.strip_prefix("Bearer ")).map(str::trim) else {
            return false;
        };
        // Constant-time: `==` on `str` is a `memcmp` that returns at the first
        // differing byte, so the time to reject "Bearer a…" vs "Bearer b…" leaks
        // how many leading bytes were right. Over a loopback socket that
        // difference is measurable, and it turns guessing a 32-byte token from
        // 2^256 work into 32 × 256 requests.
        self.admin_enabled() && ct_eq(given.as_bytes(), self.config.admin_token.as_bytes())
    }

    /// Persist the just-mutated store to [`ServeConfig::store_path`].
    ///
    /// Called with the store lock still held, so the bytes written are exactly
    /// the state the request produced and a concurrent writer cannot interleave
    /// a second mutation between the snapshot and the rename. `changed` is the
    /// endpoint's own answer to "did this request alter any state" — a `/add`
    /// that enqueued nothing must not fsync the whole index.
    fn commit(&self, store: &Store, changed: bool) -> Commit {
        if !changed {
            return Commit::NoChange;
        }
        let Some(path) = self.config.store_path.as_deref() else {
            return Commit::RamOnly;
        };
        // Published by rename (the same durable-publish the CLI's `write_store`
        // uses): a crash midway through a plain `fs::write` would leave a
        // truncated blob that `Store::restore` correctly refuses, i.e. a purge
        // would destroy the whole index instead of one host.
        match crawlcore::atomicfile::write_atomic(path, &store.snapshot()) {
            Ok(()) => Commit::Durable,
            Err(e) => Commit::Failed(format!("cannot write {path}: {e}")),
        }
    }

    fn auth_error(&self) -> Resp {
        if self.admin_enabled() {
            Resp::json(401, "{\"error\":\"auth required\"}".to_string())
        } else {
            Resp::json(403, "{\"error\":\"admin disabled\"}".to_string())
        }
    }

    /// Charge one request to `client`'s bucket for its [`RateClass`]; `true` if
    /// it may proceed.
    fn rate_ok(&self, client: &str, class: RateClass) -> bool {
        if class == RateClass::Exempt {
            return true;
        }
        let now = self.started.elapsed().as_secs_f64();
        let mut b = self.limiter.lock().expect("limiter lock");
        let bucket = match class {
            RateClass::Write => &mut b.1,
            _ => &mut b.0,
        };
        bucket.allow(client, 1.0, now)
    }

    /// [`route`](Self::route), with the caller's [`RateLimits`] applied first.
    ///
    /// This is what the accept loop calls, and it is the only place the limiter
    /// lives: `client` is the peer address, so the buckets are per client and
    /// shared across that client's connections. Reads and writes are charged to
    /// separate buckets ([`RateClass`]); `/health` and `/metrics` are exempt.
    /// A request over its class's budget is answered `429` and never reaches the
    /// store — which is the point, since the expensive part of both a search and
    /// a purge happens under the store mutex.
    #[must_use]
    pub fn route_limited(
        &self,
        client: &str,
        method: &str,
        target: &str,
        body: &str,
        auth: Option<&str>,
    ) -> Resp {
        let (path, _) = split_target(target);
        if !self.rate_ok(client, RateClass::of(method, path)) {
            return Resp::json(429, "{\"error\":\"rate limited\"}".to_string());
        }
        self.route(method, target, body, auth)
    }

    /// Route one request to a response. Pure and synchronous: it locks the store,
    /// computes, and unlocks before returning — safe to call from a test without
    /// any socket, and never holds the lock across an `.await`. `auth` is the raw
    /// `Authorization` header value (e.g. `"Bearer <token>"`), if present.
    ///
    /// **No rate limiting happens here** — it has no client to key on. The
    /// network path goes through [`route_limited`](Self::route_limited); an
    /// embedder calling `route` directly is doing its own limiting (or none).
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
    ) -> (String, usize, usize, Filters, crate::store::SearchOpts) {
        let q = get(params, "q").unwrap_or("").trim().to_string();
        let f = Filters::from_params(params);
        let limit = f.limit.unwrap_or(10);
        // The page is clamped to `MAX_PAGE` (the Python `min(max(1, page),
        // MAX_PAGE)`), so a crafted `?page=` cannot produce an absurd offset.
        let page = clamp_usize(get(params, "page"), 1, 1, MAX_PAGE);
        let offset = (page - 1) * limit;
        let opts = crate::store::SearchOpts {
            limit,
            offset,
            host: Filters::opt(&f.host),
            since: f.since,
            until: f.until,
            lang: Filters::opt(&f.lang),
            authority_weight: get(params, "authority")
                .and_then(|s| s.parse().ok())
                .unwrap_or(0.0),
            collapse: matches!(get(params, "collapse"), Some("1" | "true" | "on")),
            simhash_threshold: 3,
        };
        (q, limit, page, f, opts)
    }

    fn html_search(&self, params: &[(String, String)]) -> Resp {
        let (q, limit, page, f, opts) = self.search_opts(params);
        let offset = opts.offset;
        let store = self.store.lock().expect("store lock");
        let mut b = String::from(HEADER);
        b.push_str(&search_form(&q, &f));
        if q.is_empty() {
            b.push_str(&format!(
                "<p class=muted>{} pages indexed. Enter a query above. \
This index serves .onion pages only.</p>",
                store.page_count()
            ));
            b.push_str(FOOTER);
            return Resp::html(200, layout("onioncrawler", &b));
        }
        let res = store.search(&q, &opts);
        if res.total == 0 {
            b.push_str("<p class=muted>No results.</p>");
        } else {
            b.push_str(&format!(
                "<p class=muted>Results {}-{} of {} match(es){}</p>",
                offset + 1,
                (offset + limit).min(res.total),
                res.total,
                if opts.collapse {
                    " · near-duplicate mirrors collapsed"
                } else {
                    ""
                }
            ));
            let facets = store.search_facets(
                &q,
                opts.host.as_deref(),
                opts.since,
                opts.until,
                opts.lang.as_deref(),
                FACET_TOP,
            );
            b.push_str(&facet_html(&q, &f, &facets));
            for h in &res.hits {
                b.push_str(&result_html(h));
            }
            b.push_str("<div class=nav>");
            if page > 1 {
                b.push_str(&format!(
                    "<a href='/search?{}'>« Prev</a>",
                    f.qs(&q, page - 1)
                ));
            }
            if offset_has_more(res.total, page, limit) {
                b.push_str(&format!(
                    "<a href='/search?{}'>Next »</a>",
                    f.qs(&q, page + 1)
                ));
            }
            b.push_str("</div>");
        }
        b.push_str(FOOTER);
        Resp::html(200, layout(&format!("{q} — onioncrawler"), &b))
    }

    fn api_search(&self, params: &[(String, String)]) -> Resp {
        let (q, _limit, _page, _f, opts) = self.search_opts(params);
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
        // The Python `render_find`: back link, heading, then one `.result` row
        // per carrying page (or an explicit empty note).
        let mut b = format!(
            "{BACK}<h1>Pages carrying {}: <code>{}</code></h1>",
            esc(kind),
            esc(value),
        );
        if hits.is_empty() {
            b.push_str(&format!(
                "<p class=muted>No indexed onion carries this {}.</p>",
                esc(kind)
            ));
        }
        for h in &hits {
            let title = h
                .title
                .as_deref()
                .filter(|t| !t.is_empty())
                .unwrap_or(&h.url);
            // As in the reference, the entity pivot *links* the page (the search
            // page deliberately does not); its `.name` wrapper is spelled
            // `.title` here so the stylesheet actually styles it.
            b.push_str(&format!(
                "<div class=result><div class=title><a href=\"{u}\" rel=noopener>{t}</a></div>\
<div class=url>{u}</div><div class=meta>host: {host}</div></div>",
                u = esc(&h.url),
                t = esc(title),
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
        let mut b = String::from(BACK);
        b.push_str("<h1>Index statistics</h1><table class=list><tbody>");
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
            b.push_str(&format!(
                "<tr><td>{}</td><td class=mono>{}</td></tr>",
                k,
                g(k)
            ));
        }
        b.push_str("</tbody></table>");
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
                let title = snap
                    .title
                    .clone()
                    .filter(|t| !t.is_empty())
                    .unwrap_or_else(|| snap.url.clone());
                // The Python `render_cached`.
                let body = format!(
                    "{BACK}<h1>{}</h1>\
<p class=muted>Cached text snapshot of <code>{}</code> — the live onion may be \
offline. Text only; no scripts or media.</p>\
<pre class=cached style='white-space:pre-wrap'>{}</pre>",
                    esc(&title),
                    esc(&snap.url),
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

    /// `/metrics` — Prometheus text exposition.
    ///
    /// The store's index/frontier/host gauges keep their exact
    /// `onioncrawler_<name>` spellings and their sorted order, because
    /// `suitedash`'s default configuration surfaces `onioncrawler_pages`,
    /// `onioncrawler_hosts` and `onioncrawler_frontier_queued` by name. The
    /// request block every engine shares ([`crawlcore::metrics`]) is appended
    /// after them — before it, this endpoint could say how big the index was but
    /// not whether anyone could reach it.
    fn metrics(&self) -> Resp {
        let mut out = String::new();
        {
            let store = self.store.lock().expect("store lock");
            let mut entries: Vec<(&str, i64)> = store.metrics().into_iter().collect();
            entries.sort_by(|a, b| a.0.cmp(b.0));
            for (k, v) in entries {
                out.push_str(&format!("onioncrawler_{k} {v}\n"));
            }
        }
        // Rendered outside the store lock: `/metrics` is what a monitor scrapes
        // when the engine is already struggling, and holding the store mutex
        // across it would make the scrape itself a source of contention.
        out.push_str(&crate::metrics::registry().render(crate::metrics::PREFIX));
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
        let (summary, commit) = {
            let mut store = self.store.lock().expect("store lock");
            let summary = submit_many(
                &mut store,
                self.abuse.as_deref(),
                urls,
                self.config.allow_v2,
                caps,
                max_urls,
                self.config.allow_i2p,
                now,
            );
            // A submission that enqueued nothing (all dup / not-onion / capped)
            // changed no state — an unauthenticated caller must not be able to
            // make us fsync the whole index by POSTing the same URL forever.
            let commit = self.commit(&store, summary.ok > 0);
            (summary, commit)
        };
        Resp::json(
            commit.status(),
            summary_json(&summary, &commit.json_fields()),
        )
    }

    /// `POST /purge` — admin: block host(s), delete their indexed pages, and
    /// commit the result to disk.
    ///
    /// The commit is the point of the endpoint. Purge is the takedown control an
    /// operator is legally obliged to be able to use, and without a write it
    /// only edited this process's memory: `search --db crawl.db` reloads the
    /// snapshot on the next start and every purged page is back, indexed and
    /// served, with the host un-blocked. The response says which of the two
    /// happened (`"persisted"`), and a failed write is a 500, not a 200.
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
        let (purged, commit) = {
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
            // Always a real change even when 0 pages were removed: the host is
            // now `blocked`, which is what keeps it out of future crawls.
            let commit = self.commit(&store, true);
            (purged, commit)
        };
        Resp::json(
            commit.status(),
            format!(
                "{{\"purged\":[{}]{}}}",
                purged.join(","),
                commit.json_fields()
            ),
        )
    }

    /// `POST /recrawl` — admin: requeue every due page for recrawl, and commit.
    fn do_recrawl(&self, auth: Option<&str>) -> Resp {
        if !self.admin_ok(auth) {
            return self.auth_error();
        }
        let now = now_secs();
        let (n, commit) = {
            let mut store = self.store.lock().expect("store lock");
            let n = store.mark_recrawl_due(now, self.config.recrawl_interval);
            // Same reasoning as /purge: the requeue is a frontier mutation, so
            // without a commit the next restart serves a frontier that never
            // heard about it. Nothing due ⇒ nothing to write.
            let commit = self.commit(&store, n > 0);
            (n, commit)
        };
        Resp::json(
            commit.status(),
            format!("{{\"recrawl_due\":{n}{}}}", commit.json_fields()),
        )
    }
}

/// Constant-time byte equality, for the admin-token check. Compares every byte
/// of an equal-length pair, so the time taken carries no information about how
/// far the match got.
fn ct_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() {
        return false;
    }
    let mut diff = 0u8;
    for (x, y) in a.iter().zip(b.iter()) {
        diff |= x ^ y;
    }
    diff == 0
}

/// The two limiter buckets ([`RateClass::Read`], [`RateClass::Write`]).
fn buckets(limits: &RateLimits) -> (TokenBucket, TokenBucket) {
    (
        TokenBucket::new(limits.read_rate, limits.read_burst, limits.max_clients),
        TokenBucket::new(limits.write_rate, limits.write_burst, limits.max_clients),
    )
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

/// The `/add` summary as JSON. `extra` is spliced in before the closing brace
/// (each field already comma-prefixed) — that is where the commit status goes.
fn summary_json(s: &SubmitSummary, extra: &str) -> String {
    let results: Vec<String> = s.results.iter().map(result_json).collect();
    format!(
        "{{\"ok\":{},\"dup\":{},\"not-onion\":{},\"blocked\":{},\"capped\":{},\"skipped\":{},\"results\":[{}]{extra}}}",
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

/// The resource limits the accept loop enforces on one connection.
#[cfg(feature = "net")]
#[derive(Clone, Copy, Debug)]
pub struct ServeLimits {
    /// Total deadline for one request: the head read, the route (which takes the
    /// store mutex), and the response write — all of it, once, not per read.
    ///
    /// A per-read timeout would not help: it restarts on every byte, so a client
    /// sending one byte every 29 s never trips it and owns the task, the socket
    /// and its file descriptor for as long as it likes. That is Slowloris, and
    /// the cost here is one fd + one task per connection, with nothing in the
    /// code that ever reaps them. The write side needs the same deadline: a peer
    /// that opens a window of 1 byte and stops reading blocks `write_all` on a
    /// 200 KB search page just as effectively.
    pub request_timeout: std::time::Duration,
}

#[cfg(feature = "net")]
impl Default for ServeLimits {
    fn default() -> Self {
        ServeLimits {
            // Generous for a loopback/onion round trip (a slow onion GET of a
            // large `/cached` page still fits), decisive against a dribbler.
            request_timeout: std::time::Duration::from_secs(30),
        }
    }
}

/// Serve the search front-end on `listener` until the process ends, under the
/// default [`ServeLimits`]. Each accepted connection is handled on its own task.
/// `net`-only.
///
/// # Errors
/// Propagates a fatal `accept()` error.
#[cfg(feature = "net")]
pub async fn serve(listener: tokio::net::TcpListener, server: SearchServer) -> std::io::Result<()> {
    serve_with_limits(listener, server, ServeLimits::default()).await
}

/// [`serve`] with explicit limits.
///
/// # Errors
/// Propagates a fatal `accept()` error.
#[cfg(feature = "net")]
pub async fn serve_with_limits(
    listener: tokio::net::TcpListener,
    server: SearchServer,
    limits: ServeLimits,
) -> std::io::Result<()> {
    loop {
        let (stream, peer) = listener.accept().await?;
        let srv = server.clone();
        // The limiter keys on the peer *address*, not the connection: a client
        // that opens 1000 connections must still get one client's budget.
        let client = peer.ip().to_string();
        tokio::spawn(async move {
            // One deadline over the whole round trip. On expiry the future is
            // dropped, which drops `stream` and closes the fd — the only thing
            // that reclaims a slot from a peer that has stopped talking.
            let _ = tokio::time::timeout(
                limits.request_timeout,
                handle_conn_from(stream, &client, srv),
            )
            .await;
        });
    }
}

/// Read one HTTP/1.1 request from `stream`, route it, and write the response.
/// Connection-per-request (no keep-alive) — simple and correct for a loopback
/// admin UI. `net`-only.
///
/// Unrate-limited (the peer address is unknown to it); [`serve`] uses
/// [`handle_conn_from`]. Note there is **no timeout inside**: the deadline is
/// the caller's, so that it covers the read, the route and the write together
/// rather than resetting per read.
///
/// # Errors
/// Any socket read/write error.
#[cfg(feature = "net")]
pub async fn handle_conn(
    stream: tokio::net::TcpStream,
    server: SearchServer,
) -> std::io::Result<()> {
    handle_conn_from(stream, "-", server).await
}

/// [`handle_conn`], charging the request to `client`'s rate-limit buckets.
///
/// # Errors
/// Any socket read/write error.
#[cfg(feature = "net")]
pub async fn handle_conn_from(
    mut stream: tokio::net::TcpStream,
    client: &str,
    server: SearchServer,
) -> std::io::Result<()> {
    use crawlcore::budget::Budget;
    use tokio::io::AsyncReadExt;

    const MAX_HEAD: usize = 64 * 1024;
    // The largest request body this server will buffer. Every POST here is a
    // small form (`/add` takes at most `max_public_add_urls` URLs), so 1 MiB is
    // generous. Without it a single unauthenticated connection could declare
    // `Content-Length: 4000000000` and stream until the process died — the body
    // is buffered BEFORE `route()` runs, so even the 401 path allocated first
    // (measured: 4 GB resident in 6 s).
    const MAX_BODY: usize = 1024 * 1024;
    let mut buf = Vec::new();
    let mut tmp = [0u8; 4096];
    // read until end of headers
    let head_end = loop {
        if let Some(pos) = find(&buf, b"\r\n\r\n") {
            break pos;
        }
        if buf.len() > MAX_HEAD {
            // Counted so an oversized-head flood shows up somewhere: it never
            // reaches `route`, so without this it is invisible in every counter
            // and the server just looks idle while it is being hammered.
            crate::metrics::registry().reject();
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
    // The declared length never touches an allocation or a read length directly:
    // it is spent against a `Budget`, whose `take` saturates. So
    // `Content-Length: 18446744073709551615` is simply a request for more than
    // the 1 MiB that exists, and the loop ends when the budget does — no `+`/`>`
    // on a wire number that could wrap and re-open the cap.
    let mut budget = Budget::new(MAX_BODY);
    let head_tail = &buf[head_end + 4..];
    let mut body = head_tail[..budget.take(head_tail.len())].to_vec();
    while body.len() < content_length && !budget.is_exhausted() {
        let n = stream.read(&mut tmp).await?;
        if n == 0 {
            break;
        }
        let keep = budget.take(n);
        body.extend_from_slice(&tmp[..keep]);
    }
    let body_str = String::from_utf8_lossy(&body).to_string();

    let started = std::time::Instant::now();
    crate::metrics::registry().begin();
    let resp = server.route_limited(client, &method, &target, &body_str, auth.as_deref());
    let elapsed = started.elapsed().as_secs_f64();
    let action = crate::metrics::action_of(&target);
    crate::metrics::registry().end(resp.status, action, elapsed);
    crawlcore::logfmt::access(
        crate::metrics::PREFIX,
        &crawlcore::logfmt::Request {
            method: &method,
            path: &target,
            status: resp.status,
            duration_ms: elapsed * 1000.0,
            // `client` is `"-"` on the plain `handle_conn` path, which has no
            // peer to charge the rate limiter to.
            peer: client,
            action,
        },
    );
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

    /// A store with `n` distinct 62-char v3-style onion hosts, each holding one
    /// page that matches `widget` — enough to exercise the facet slice.
    /// The 54-char stem every fixture host in [`server_with_many_hosts`] shares
    /// (+ a 2-digit index = a 56-char v3 label, i.e. a 62-char host).
    fn host_stem() -> String {
        "abcdefghijklmnopqrstuvwxyz234567".repeat(2)[..54].to_string()
    }

    fn server_with_many_hosts(n: usize) -> SearchServer {
        let mut s = Store::new();
        for i in 0..n {
            let host = format!("{}{:02}.onion", host_stem(), i);
            s.ensure_host(&host, 1.0);
            // The body decides the guessed language (there is no lang setter):
            // English or German stop words, so the language facet has two rows.
            let body = if i % 2 == 0 {
                "the widget is the one that is in the shop and it is for the market"
            } else {
                "das widget ist ein der die und mit von auf ist nicht im shop"
            };
            s.store_page(
                &format!("http://{host}/"),
                &host,
                Some("Widget shop"),
                Some(body),
                Some(&format!("h{i}")),
                Some(200),
                Some("text/html"),
                None,
                10.0 + i as f64,
                false,
                None,
                None,
                None,
            );
        }
        SearchServer::new(Arc::new(Mutex::new(s)), "")
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
        let b = body(&r);
        // The Python landing copy, and the full form is offered up front.
        assert!(b.contains(
            "<p class=muted>2 pages indexed. Enter a query above. \
This index serves .onion pages only.</p>"
        ));
        assert!(b.contains("<div class='row filters'>"));
        assert!(b.contains(FOOTER));
    }

    // -- defect 1: the stylesheet and the markup share one vocabulary --------

    #[test]
    fn stylesheet_is_the_python_reference_verbatim() {
        // 21 rules / 1316 bytes, in the reference's order (see
        // legacy-python/onioncrawler/onioncrawler/search.py PAGE_STYLE).
        assert_eq!(STYLE.len(), 1316);
        assert_eq!(STYLE.matches('}').count(), 20);
        assert!(STYLE.starts_with("\nbody{font-family:-apple-system,"));
        assert!(STYLE.ends_with("footer{margin-top:2rem;color:#999;font-size:.78rem}\n"));
    }

    #[test]
    fn markup_uses_only_classes_the_stylesheet_defines() {
        let srv = server_with_pages();
        let b = body(&srv.route("GET", "/search?q=widget", "", None));
        for frag in [
            "<header><a href='/'>",
            "<div class=row>",
            "<div class='row filters'>",
            "<div class=result>",
            "<div class=title>",
            "<div class=url>",
            "<div class=snip>",
            "<div class=meta>",
            "<p class=muted>",
            "<footer>",
        ] {
            assert!(b.contains(frag), "missing {frag} in\n{b}");
        }
        // the abbreviated vocabulary the stylesheet never styled is gone
        for dead in ["<div class=r>", "<div class=u>", "<div class=s>", "<nav>"] {
            assert!(!b.contains(dead), "stale {dead} in\n{b}");
        }
        // every class the markup emits is styled by the sheet
        for sel in [
            ".row", ".filters", ".result", ".title", ".url", ".snip", ".meta", ".facets", ".nav",
            ".muted", "header ", "footer{", "mark{",
        ] {
            assert!(STYLE.contains(sel), "stylesheet lacks {sel}");
        }
    }

    #[test]
    fn result_row_matches_the_reference_shape() {
        let srv = server_with_pages();
        let b = body(&srv.route("GET", "/search?q=widget", "", None));
        assert!(
            b.contains(
                "<div class=result><div class=title>Widget Emporium</div>\
<div class=url>http://a.onion/1</div>"
            ),
            "{b}"
        );
        assert!(
            b.contains(
                "<div class=meta>host: a.onion · lang: en · last seen: 1970-01-01 00:00 UTC</div>"
            ),
            "{b}"
        );
    }

    // -- defect 2: the form exposes host / lang / since / until -------------

    #[test]
    fn search_form_exposes_all_four_filters() {
        let srv = server_with_pages();
        let b = body(&srv.route(
            "GET",
            "/search?q=widget&host=a.onion&lang=de&since=2024-01-02&until=2024-03-04",
            "",
            None,
        ));
        // the query row, then the filters row with every control reflected back
        assert!(b.contains(
            "<form action='/search' method='get'><div class=row>\
<input type=text name=q value=\"widget\" \
placeholder='search indexed .onion pages' autofocus>\
<button type=submit>Search</button></div>"
        ));
        assert!(b.contains(
            "<div class='row filters'>\
<input type=text name=host value=\"a.onion\" placeholder='host filter (x.onion)'>"
        ));
        // one option per known language, plus "" and "un"; the active one selected
        assert!(b.contains("<label>lang <select name=lang><option value=\"\"></option>"));
        assert!(b.contains("<option value=\"de\" selected>de</option>"));
        assert!(b.contains("<option value=\"en\">en</option>"));
        assert!(b.contains("<option value=\"un\">un</option>"));
        assert!(b.contains("<label>from <input type=date name=since value=\"2024-01-02\"></label>"));
        assert!(b.contains(
            "<label>to <input type=date name=until value=\"2024-03-04\"></label></div></form>"
        ));
    }

    #[test]
    fn date_filters_from_the_form_reach_the_store() {
        let srv = server_with_pages();
        // pages are stored at last_seen 10.0/11.0 (1970-01-01), so a 2024 lower
        // bound must exclude them and the epoch-day upper bound must keep them.
        let b = body(&srv.route("GET", "/api/search?q=widget&since=2024-01-01", "", None));
        assert!(b.contains("\"total\":0"), "{b}");
        let b = body(&srv.route("GET", "/api/search?q=widget&until=1970-01-01", "", None));
        assert!(b.contains("\"total\":1"), "{b}");
        // a raw epoch still works for the JSON API
        let b = body(&srv.route("GET", "/api/search?q=widget&since=1700000000", "", None));
        assert!(b.contains("\"total\":0"), "{b}");
    }

    #[test]
    fn parse_date_ports_the_reference() {
        assert_eq!(parse_date("1970-01-01", false), Some(0.0));
        assert_eq!(parse_date("1970-01-01", true), Some(86_399.0));
        assert_eq!(parse_date("2024-02-29", false), Some(1_709_164_800.0));
        assert_eq!(parse_date("  2024-01-02  ", false), Some(1_704_153_600.0));
        // not a date → no filter (or the numeric fallback)
        assert_eq!(parse_date("", false), None);
        assert_eq!(parse_date("2023-02-29", false), None);
        assert_eq!(parse_date("2023-13-01", false), None);
        assert_eq!(parse_date("nope", false), None);
        assert_eq!(parse_date("2024-01-02T03", false), None);
        assert_eq!(parse_date("12.5", false), Some(12.5));
    }

    #[test]
    fn fmt_time_ports_the_reference() {
        assert_eq!(fmt_time(None), "unknown");
        assert_eq!(fmt_time(Some(0.0)), "unknown");
        assert_eq!(fmt_time(Some(f64::NAN)), "unknown");
        assert_eq!(fmt_time(Some(1.0)), "1970-01-01 00:00 UTC");
        assert_eq!(fmt_time(Some(1_700_000_000.0)), "2023-11-14 22:13 UTC");
    }

    // -- defect 3: every link re-emits every active filter ------------------

    #[test]
    fn pager_links_preserve_every_filter() {
        let srv = server_with_many_hosts(8);
        // `host=` is blank (dropped, like the reference), the other three are
        // active; the fixture pages sit inside the date window.
        let target =
            "/search?q=widget&host=&lang=en&since=1970-01-01&until=2038-01-01&limit=1&page=2";
        let b = body(&srv.route("GET", target, "", None));
        let want_prev = "<a href='/search?q=widget&page=1&lang=en&since=1970-01-01&until=2038-01-01&limit=1'>« Prev</a>";
        let want_next = "<a href='/search?q=widget&page=3&lang=en&since=1970-01-01&until=2038-01-01&limit=1'>Next »</a>";
        assert!(b.contains(want_prev), "{b}");
        assert!(b.contains(want_next), "{b}");
        // and the window really is the requested size (before the fix, `limit`
        // was dropped from the href and page 2 silently reverted to 10)
        assert_eq!(b.matches("<div class=result>").count(), 1);
        assert!(
            b.contains("<p class=muted>Results 2-2 of 4 match(es)</p>"),
            "{b}"
        );
    }

    #[test]
    fn qs_is_the_python_urlencode_order() {
        let f = Filters {
            host: "x.onion".to_string(),
            lang: "en".to_string(),
            since_s: "2024-01-02".to_string(),
            until_s: "2024-03-04".to_string(),
            since: None,
            until: None,
            limit: Some(25),
        };
        assert_eq!(
            f.qs("dark web", 3),
            "q=dark+web&page=3&host=x.onion&lang=en&since=2024-01-02&until=2024-03-04&limit=25"
        );
        // empty dimensions are omitted, exactly like the reference `_qs`
        assert_eq!(Filters::default().qs("q u", 1), "q=q+u&page=1");
    }

    #[test]
    fn facet_links_preserve_every_filter() {
        let srv = server_with_many_hosts(3);
        let b = body(&srv.route(
            "GET",
            "/search?q=widget&lang=en&since=1970-01-01&until=2038-01-01&limit=50",
            "",
            None,
        ));
        let host = format!("{}00.onion", host_stem());
        // a host facet keeps q + lang + since + until + limit and adds its host
        assert!(
            b.contains(&format!(
                "<a href='/search?q=widget&page=1&host={host}&lang=en\
&since=1970-01-01&until=2038-01-01&limit=50'>"
            )),
            "{b}"
        );
        // a language facet replaces only the lang dimension
        assert!(
            b.contains(
                "<a href='/search?q=widget&page=1&lang=en&since=1970-01-01\
&until=2038-01-01&limit=50'>en ("
            ),
            "{b}"
        );
    }

    // -- defect 4: the page clamp ------------------------------------------

    #[test]
    fn page_is_clamped_to_max_page() {
        assert_eq!(MAX_PAGE, 100_000);
        let srv = server_with_pages();
        // a crafted page beyond the cap lands exactly on MAX_PAGE …
        let at_cap = body(&srv.route("GET", "/search?q=widget&page=100000", "", None));
        assert_eq!(
            body(&srv.route("GET", "/search?q=widget&page=100001", "", None)),
            at_cap
        );
        assert_eq!(
            body(&srv.route("GET", "/search?q=widget&page=999999999", "", None)),
            at_cap
        );
        // the offset it produces is bounded (the old 1M cap allowed 10M), and
        // the window line is the reference's `min(offset + per, total)`
        assert!(
            at_cap.contains("<p class=muted>Results 999991-1 of 1 match(es)</p>"),
            "{at_cap}"
        );
        assert!(at_cap.contains("<a href='/search?q=widget&page=99999'>« Prev</a>"));
        // … and a negative / junk page clamps up to 1
        let first = body(&srv.route("GET", "/search?q=widget&page=1", "", None));
        assert_eq!(
            body(&srv.route("GET", "/search?q=widget&page=-5", "", None)),
            first
        );
        assert_eq!(
            body(&srv.route("GET", "/search?q=widget&page=junk", "", None)),
            first
        );
    }

    // -- defect 5: the facet row is short and does not wrap ------------------

    #[test]
    fn facet_row_caps_at_six_and_truncates_hosts() {
        let srv = server_with_many_hosts(8);
        let b = body(&srv.route("GET", "/search?q=widget&limit=100", "", None));
        let facets = b
            .split("<div class=facets>")
            .nth(1)
            .and_then(|s| s.split("</div>").next())
            .unwrap_or_default()
            .to_string();
        // 6 of the 8 hosts (the reference `[:6]`), plus the two languages
        assert_eq!(facets.matches("<a href=").count(), 6 + 2, "{facets}");
        // the link *text* is 16 characters of the host then the ellipsis — the
        // full 62-char host survives only inside the href, where it must
        assert_eq!(
            facets.matches(">abcdefghijklmnop… (1)</a>").count(),
            6,
            "{facets}"
        );
        assert!(!facets.contains("abcdefghijklmnopq…"), "{facets}");
        assert!(facets.contains(" &nbsp;·&nbsp; langs: "), "{facets}");
    }

    // -- XSS ----------------------------------------------------------------

    #[test]
    fn hostile_query_and_filters_are_escaped() {
        let mut s = Store::new();
        s.ensure_host("evil.onion", 1.0);
        s.store_page(
            "http://evil.onion/<script>alert(1)</script>",
            "evil.onion",
            Some("<script>alert('t')</script>"),
            Some("widget <script>alert(2)</script> shop <img src=x onerror=1>"),
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
        let srv = SearchServer::new(Arc::new(Mutex::new(s)), "");
        let hostile = "/search?q=widget&host=%22%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E\
&lang=%3Cb%3E&since=%22onmouseover%3D%22x&until=%3Cscript%3E";
        for target in [
            "/search?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E",
            hostile,
            "/search?q=widget&page=%22%3E%3Cscript%3E",
            "/find?kind=%3Cscript%3E&value=%3Cscript%3E",
            "/cached?url=http%3A%2F%2Fevil.onion%2F%3Cscript%3Ealert(1)%3C%2Fscript%3E",
        ] {
            let b = body(&srv.route("GET", target, "", None));
            assert!(
                !b.to_lowercase().contains("<script"),
                "unescaped script for {target}:\n{b}"
            );
            assert!(!b.contains("<img"), "unescaped tag for {target}:\n{b}");
        }
        // the cached snapshot renders the stored body as inert text
        let b = body(&srv.route(
            "GET",
            "/cached?url=http%3A%2F%2Fevil.onion%2F%3Cscript%3Ealert(1)%3C%2Fscript%3E",
            "",
            None,
        ));
        assert!(b.contains("&lt;img src=x onerror=1&gt;"), "{b}");
        // every reflected value comes back escaped — no value can close its own
        // attribute, and the `?`-links are percent-encoded by `urlencode`
        let b = body(&srv.route("GET", hostile, "", None));
        assert!(
            b.contains(
                "<input type=text name=host value=\"&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;\" "
            ),
            "{b}"
        );
        assert!(
            b.contains("<input type=date name=since value=\"&quot;onmouseover=&quot;x\">"),
            "{b}"
        );
        assert!(!b.contains("\"onmouseover"), "{b}");
        let b = body(&srv.route(
            "GET",
            "/search?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E",
            "",
            None,
        ));
        assert!(
            b.contains("<input type=text name=q value=\"&lt;script&gt;alert(1)&lt;/script&gt;\" "),
            "{b}"
        );
        // the marked-up snippet keeps its own <mark> and escapes the rest
        let b = body(&srv.route("GET", "/search?q=widget", "", None));
        assert!(b.contains("<mark>widget</mark>"), "{b}");
        assert!(b.contains("&lt;script&gt;"), "{b}");
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

    // -- the write endpoints are durable ------------------------------------

    fn tmp_db(tag: &str, line: u32) -> std::path::PathBuf {
        std::env::temp_dir().join(format!(
            "onioncrawler_serve_{tag}_{}_{line}.db",
            std::process::id()
        ))
    }

    /// Reload the snapshot the server committed — i.e. what a restart would see.
    fn reload(path: &std::path::Path) -> Store {
        let bytes = std::fs::read(path).expect("snapshot written");
        Store::restore(&bytes).expect("snapshot parses")
    }

    #[test]
    fn purge_survives_a_restart() {
        let path = tmp_db("purge", line!());
        let srv = server_with_pages()
            .with_admin("tok")
            .with_store_path(path.to_str().unwrap());
        // Seed the file with the pre-purge state, exactly as `search --db` does.
        {
            let s = srv.store.lock().unwrap();
            std::fs::write(&path, s.snapshot()).unwrap();
        }
        assert_eq!(reload(&path).page_count(), 2);

        let r = srv.route("POST", "/purge", "host=a.onion", Some("Bearer tok"));
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("\"pages_removed\":2"), "{}", body(&r));
        // the response says the takedown reached disk …
        assert!(body(&r).contains("\"persisted\":true"), "{}", body(&r));
        // … and it did: a restart re-reads the file and the pages are still gone
        // and the host still blocked. Before the fix this reload returned all 2
        // pages, un-blocked, because /purge never wrote anything.
        let after = reload(&path);
        assert_eq!(after.page_count(), 0);
        assert_eq!(
            after.get_host("a.onion").map(|h| h.state.as_str()),
            Some("blocked")
        );
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn add_and_recrawl_survive_a_restart() {
        let path = tmp_db("addrecrawl", line!());
        let srv = server_with_pages()
            .with_admin("tok")
            .with_store_path(path.to_str().unwrap());
        let host = "z".repeat(56) + ".onion";

        let r = srv.route(
            "POST",
            "/add",
            &format!("url=http://{host}/"),
            Some("Bearer tok"),
        );
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("\"persisted\":true"), "{}", body(&r));
        // the seed is in the frontier of the *file*, not just of this process
        assert_eq!(reload(&path).counter("urls_enqueued"), 1);

        // an /add that enqueues nothing (the same URL again → dup) must not
        // rewrite the snapshot, but still reports the durable state truthfully
        let r = srv.route(
            "POST",
            "/add",
            &format!("url=http://{host}/"),
            Some("Bearer tok"),
        );
        assert!(body(&r).contains("\"dup\":1"), "{}", body(&r));
        assert!(body(&r).contains("\"persisted\":true"), "{}", body(&r));

        // Take the seed through a crawl (lease → done → page stored) so it is a
        // settled row that `/recrawl` has something to requeue.
        {
            let mut s = srv.store.lock().unwrap();
            let lease = s.lease(1_000.0, 300.0).expect("the seed is queued");
            s.mark_done(lease.id);
            s.store_page(
                &lease.url,
                &host,
                Some("Seeded"),
                Some("a body"),
                Some("hz"),
                Some(200),
                Some("text/html"),
                None,
                10.0,
                false,
                None,
                None,
                None,
            );
        }
        let r = srv.route("POST", "/recrawl", "", Some("Bearer tok"));
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("\"recrawl_due\":1"), "{}", body(&r));
        assert!(body(&r).contains("\"persisted\":true"), "{}", body(&r));
        // the requeue is in the file, so a restart still knows the page is due
        assert_eq!(reload(&path).frontier_by_status().get("queued"), Some(&1));
        std::fs::remove_file(&path).ok();
    }

    #[test]
    fn a_failed_commit_is_a_500_that_says_so() {
        // A path under a directory that does not exist: the atomic write cannot
        // even create its temp sibling.
        let path = std::env::temp_dir()
            .join(format!("onioncrawler_no_such_dir_{}", std::process::id()))
            .join("crawl.db");
        let srv = server_with_pages()
            .with_admin("tok")
            .with_store_path(path.to_str().unwrap());
        let r = srv.route("POST", "/purge", "host=a.onion", Some("Bearer tok"));
        // The operator is told the takedown is not durable, rather than getting
        // a 200 for a change that a restart will undo.
        assert_eq!(r.status, 500);
        assert!(body(&r).contains("\"persisted\":false"), "{}", body(&r));
        assert!(body(&r).contains("\"error\":"), "{}", body(&r));
        // it did still take effect in RAM (strictly safer for a takedown)
        assert!(body(&srv.route("GET", "/api/search?q=widget", "", None)).contains("\"total\":0"));
    }

    #[test]
    fn without_a_store_path_writes_are_flagged_in_memory_only() {
        let srv = server_with_pages().with_admin("tok");
        let r = srv.route("POST", "/purge", "host=a.onion", Some("Bearer tok"));
        assert_eq!(r.status, 200);
        assert!(body(&r).contains("\"persisted\":false"), "{}", body(&r));
        assert!(
            body(&r).contains("no snapshot path configured"),
            "{}",
            body(&r)
        );
    }

    // -- the public submit caps are real ------------------------------------

    #[test]
    fn default_submit_caps_are_set() {
        // Before the fix every one of these was `None` — i.e. the endpoint the
        // module doc calls "capped" admitted an unbounded public flood.
        let c = ServeConfig::default().submit_caps;
        assert_eq!(c.max_unique_urls, Some(submit_caps::MAX_UNIQUE_URLS));
        assert_eq!(c.max_pages_per_host, Some(submit_caps::MAX_PAGES_PER_HOST));
        assert_eq!(
            c.max_urls_per_template,
            Some(submit_caps::MAX_URLS_PER_TEMPLATE)
        );
        assert_eq!(
            c.max_urls_per_skeleton,
            Some(submit_caps::MAX_URLS_PER_SKELETON)
        );
        for cap in [
            c.max_unique_urls,
            c.max_pages_per_host,
            c.max_urls_per_template,
            c.max_urls_per_skeleton,
        ] {
            // `cap_hit` treats a non-positive cap as "no cap", so 0 would be a
            // silent way of leaving the endpoint uncapped.
            assert!(cap.is_some_and(|n| n > 0), "cap must be positive: {cap:?}");
        }
    }

    #[test]
    fn default_public_submit_caps_a_flood() {
        let cfg = ServeConfig {
            allow_public_submit: true,
            ..ServeConfig::default()
        };
        let srv = SearchServer::new(Arc::new(Mutex::new(Store::new())), "").with_config(cfg);
        let host = "q".repeat(56) + ".onion";
        // One unauthenticated POST of `/0`, `/1`, … — the id-parameterized page
        // farm the skeleton cap exists for (every one of these collapses to the
        // skeleton `<host>/#`). With the old all-`None` defaults every URL was
        // accepted, so a public client could grow the frontier without limit.
        let n = submit_caps::MAX_URLS_PER_SKELETON as usize;
        let urls: Vec<String> = (0..n + 10).map(|i| format!("http://{host}/{i}")).collect();
        let r = srv.route("POST", "/add", &format!("urls={}", urls.join("%0A")), None);
        let b = body(&r);
        assert!(b.contains(&format!("\"ok\":{n}")), "{b}");
        assert!(b.contains("\"capped\":10"), "{b}");
        assert!(b.contains("\"status\":\"capped\""), "{b}");
    }

    // -- the rate limiter is wired to the endpoints -------------------------

    fn limited_server(limits: RateLimits) -> SearchServer {
        let cfg = ServeConfig {
            admin_token: "tok".to_string(),
            allow_public_submit: true,
            rate_limits: limits,
            ..ServeConfig::default()
        };
        server_with_pages().with_config(cfg)
    }

    #[test]
    fn reads_are_rate_limited_per_client() {
        let srv = limited_server(RateLimits {
            read_rate: 0.0, // no refill inside the test
            read_burst: 3.0,
            ..RateLimits::default()
        });
        for i in 0..3 {
            assert_eq!(
                srv.route_limited("10.0.0.1", "GET", "/search?q=widget", "", None)
                    .status,
                200,
                "request {i} should be inside the burst"
            );
        }
        // Before the fix `ratelimit.rs` had no call site at all and this was 200:
        // one client could hold the store mutex with back-to-back searches.
        let r = srv.route_limited("10.0.0.1", "GET", "/search?q=widget", "", None);
        assert_eq!(r.status, 429);
        assert!(body(&r).contains("rate limited"));
        // a different client has its own bucket
        assert_eq!(
            srv.route_limited("10.0.0.2", "GET", "/search?q=widget", "", None)
                .status,
            200
        );
    }

    #[test]
    fn writes_have_their_own_budget_and_health_is_exempt() {
        let srv = limited_server(RateLimits {
            read_rate: 0.0,
            read_burst: 1.0,
            write_rate: 0.0,
            write_burst: 2.0,
            max_clients: 64,
        });
        let h = format!("http://{}.onion/", "w".repeat(56));
        // two writes, then the third is refused — the write bucket is charged
        // whether or not the request is authenticated, so an unauthenticated
        // flood on /purge is limited too
        assert_eq!(
            srv.route_limited("10.0.0.3", "POST", "/add", &format!("url={h}"), None)
                .status,
            200
        );
        assert_eq!(
            srv.route_limited("10.0.0.3", "POST", "/purge", "host=a.onion", None)
                .status,
            401 // rejected by auth, but it did spend a token
        );
        assert_eq!(
            srv.route_limited("10.0.0.3", "POST", "/recrawl", "", Some("Bearer tok"))
                .status,
            429
        );
        // the read bucket is separate and still has its single token
        assert_eq!(
            srv.route_limited("10.0.0.3", "GET", "/search?q=widget", "", None)
                .status,
            200
        );
        assert_eq!(
            srv.route_limited("10.0.0.3", "GET", "/search?q=widget", "", None)
                .status,
            429
        );
        // monitoring never gets a 429 — a 429 to the health check reads as
        // "process is dead" to a supervisor, which then restarts it
        for _ in 0..10 {
            assert_eq!(
                srv.route_limited("10.0.0.3", "GET", "/health", "", None)
                    .status,
                200
            );
            assert_eq!(
                srv.route_limited("10.0.0.3", "GET", "/metrics", "", None)
                    .status,
                200
            );
        }
    }

    #[test]
    fn rate_limit_buckets_are_shared_across_clones() {
        // Each accepted connection gets its own `SearchServer` clone; if the
        // buckets were cloned with it, the limit would be per connection — i.e.
        // no limit at all against a client that reconnects.
        let srv = limited_server(RateLimits {
            read_rate: 0.0,
            read_burst: 1.0,
            ..RateLimits::default()
        });
        assert_eq!(
            srv.clone()
                .route_limited("10.0.0.4", "GET", "/search?q=widget", "", None)
                .status,
            200
        );
        assert_eq!(
            srv.clone()
                .route_limited("10.0.0.4", "GET", "/search?q=widget", "", None)
                .status,
            429
        );
    }

    #[test]
    fn admin_token_comparison_is_constant_time() {
        assert!(ct_eq(b"abc", b"abc"));
        assert!(!ct_eq(b"abc", b"abd"));
        assert!(!ct_eq(b"abc", b"ab"));
        assert!(ct_eq(b"", b""));
        // and the endpoint still discriminates correctly through it
        let srv = server_with_pages().with_admin("s3cret");
        assert_eq!(
            srv.route("POST", "/recrawl", "", Some("Bearer s3cret"))
                .status,
            200
        );
        // a prefix of the token is refused (it was also refused before — what
        // changed is that it now takes the same time as a token that shares no
        // bytes at all)
        assert_eq!(
            srv.route("POST", "/recrawl", "", Some("Bearer s3cre"))
                .status,
            401
        );
        assert_eq!(
            srv.route("POST", "/recrawl", "", Some("Bearer aaaaaa"))
                .status,
            401
        );
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

    /// A client that dribbles one byte per interval and never finishes the head.
    ///
    /// Before the deadline there was no timeout anywhere in `handle_conn`: the
    /// head-read loop simply awaited the next byte, so this connection lived for
    /// as long as the peer cared to keep it — one task, one socket and one file
    /// descriptor each, with nothing in the code that would ever reap them. A
    /// per-read timeout would not have helped either, since every dribbled byte
    /// resets it; only a deadline over the whole round trip ends this.
    ///
    /// The durations are scaled down from production, but only so far. The
    /// invariant is `dribble total > request_timeout >> a normal round trip`,
    /// and the same `request_timeout` also governs the well-behaved request at
    /// the end of this test — head read, route, search and write, ~2 ms on an
    /// idle box and tens of milliseconds on a contended one. At the 300 ms this
    /// used to run with, the second half of the invariant was inside the noise:
    /// a loaded 2-core runner can stall a task for longer than that, the server
    /// would drop a perfectly good request, and the final assertion would fail
    /// with no bug behind it. 4 s of dribble against a 2 s deadline keeps both
    /// halves — the slowloris is still cut off by the total deadline, and the
    /// normal request keeps a ~40x margin against its loaded cost — for ~3 s
    /// more wall clock, which is the cheaper side of the trade.
    #[cfg(feature = "net")]
    #[tokio::test]
    async fn a_slowloris_client_is_cut_off_by_the_total_deadline() {
        use std::time::Duration;
        use tokio::io::{AsyncReadExt, AsyncWriteExt};
        let srv = server_with_pages();
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        let limits = super::ServeLimits {
            request_timeout: Duration::from_secs(2),
        };
        tokio::spawn(async move {
            let _ = super::serve_with_limits(listener, srv, limits).await;
        });

        let mut c = tokio::net::TcpStream::connect(addr).await.unwrap();
        // never sends the blank line that ends the head
        c.write_all(b"GET /api/search?q=widget HTTP/1.1\r\n")
            .await
            .unwrap();
        for _ in 0..20 {
            // one byte per 200 ms: under a 2 s *per-read* timeout, forever
            tokio::time::sleep(Duration::from_millis(200)).await;
            if c.write_all(b"X").await.is_err() {
                break;
            }
        }
        // The server hit its deadline and dropped the connection, so this read
        // ends (clean EOF or a reset) instead of hanging. Without the deadline
        // it hangs and this `expect` is what fails.
        let mut buf = Vec::new();
        let r = tokio::time::timeout(Duration::from_secs(5), c.read_to_end(&mut buf))
            .await
            .expect("connection must not still be open");
        assert!(r.is_err() || r.unwrap() == 0, "unexpected body: {buf:?}");

        // And a well-behaved request on the same server is unaffected — the
        // deadline is a deadline on stalling, not a budget the server spends on
        // every client. This runs under the same 2 s against a round trip that
        // takes single-digit milliseconds.
        let mut c = tokio::net::TcpStream::connect(addr).await.unwrap();
        c.write_all(b"GET /api/search?q=widget HTTP/1.1\r\nHost: x\r\n\r\n")
            .await
            .unwrap();
        let mut resp = Vec::new();
        c.read_to_end(&mut resp).await.unwrap();
        assert!(String::from_utf8_lossy(&resp).contains("\"total\":1"));
    }
}
