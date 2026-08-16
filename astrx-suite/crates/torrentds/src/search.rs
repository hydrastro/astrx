//! No-JS, server-rendered search UI + JSON API over the metadata [`Store`] (port
//! of `legacy-python/torrentds/search.py`).
//!
//! Routes: `GET /` and `/search` (results), `/browse` (category tiles / listing),
//! `/recent` (newest), `/t/<ih>` (detail), `/torrent/<ih>.torrent` (rebuilt
//! `.torrent`), `/api/search`, `/api/torrent/<ih>`, `/api/stats` (JSON),
//! `/feed` · `/rss` (RSS 2.0), `/metrics` (Prometheus text), `/health`, and a
//! token-gated `POST /api/block` for the operator blocklist.
//!
//! Everything is plain server-rendered HTML with inline CSS and no JavaScript;
//! all torrent-controlled text is HTML-escaped, and RSS text is XML-sanitised so
//! one hostile name can't break feed well-formedness. Listings hide spam-flagged
//! torrents by default (`?show_spam=1` reveals them) and collapse cross-infohash
//! duplicates. This module is `net`-tier: it needs an async runtime.

use crate::metadata::build_torrent_file;
use crate::peerstore::PeerStore;
use crate::store::{Filters, Order, SearchResult, Stats, Store, TorrentRecord, CATEGORIES};
use std::sync::{Arc, Mutex};
use std::time::Duration;
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};

const UNITS: &[&str] = &["B", "KiB", "MiB", "GiB", "TiB", "PiB"];

/// Human-readable byte size (e.g. `1.4 GiB`), matching the Python `human_size`.
#[must_use]
pub fn human_size(n: u64) -> String {
    let mut x = n as f64;
    for (i, unit) in UNITS.iter().enumerate() {
        if x < 1024.0 || i == UNITS.len() - 1 {
            return if *unit == "B" {
                format!("{} {}", x as u64, unit)
            } else {
                format!("{x:.1} {unit}")
            };
        }
        x /= 1024.0;
    }
    format!("{n} B")
}

/// HTML-escape `& < > " '` (the `quote=True` set from Python's `html.escape`).
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

/// Drop code points illegal in XML 1.0, then XML-escape — so one hostile torrent
/// name can't make the RSS feed non-well-formed.
#[must_use]
pub fn xml_text(s: &str) -> String {
    let mut cleaned = String::with_capacity(s.len());
    for c in s.chars() {
        let cp = c as u32;
        let allowed = matches!(cp, 0x9 | 0xA | 0xD)
            || (0x20..=0xD7FF).contains(&cp)
            || (0xE000..=0xFFFD).contains(&cp)
            || (0x10000..=0x10FFFF).contains(&cp);
        if !allowed {
            continue;
        }
        if (0xFDD0..=0xFDEF).contains(&cp) || matches!(cp & 0xFFFF, 0xFFFE | 0xFFFF) {
            continue; // noncharacters
        }
        cleaned.push(c);
    }
    // xml.sax.saxutils.escape handles & < > only.
    let mut out = String::with_capacity(cleaned.len());
    for c in cleaned.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            _ => out.push(c),
        }
    }
    out
}

/// JSON string escaping (a minimal, dependency-free encoder — the API shapes are
/// crate-controlled, so a full serializer isn't needed).
fn json_str(s: &str) -> String {
    let mut out = String::with_capacity(s.len() + 2);
    out.push('"');
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
    out.push('"');
    out
}

/// The inline stylesheet, byte-identical to the Python `PAGE_CSS` (1427 B, 26
/// rules) — including its leading/trailing newlines and line breaks.
///
/// Kept as a **raw string, verbatim**: the pages are cross-checked byte-for-byte
/// against the reference, so re-flowing this (as a hand-transcribed, `\`-joined
/// literal) both changes the served bytes and makes reference drift invisible in
/// a diff. Paste changes in from `search.py` rather than editing by hand.
pub const PAGE_CSS: &str = r#"
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:900px;
margin:0 auto;padding:1.5rem;color:#1a1a1a;background:#fafafa}
a{color:#0b57d0;text-decoration:none}a:hover{text-decoration:underline}
h1{font-size:1.4rem}form{margin:1rem 0}
input[type=text]{width:70%;padding:.5rem;font-size:1rem;border:1px solid #ccc;border-radius:4px}
select{padding:.4rem;font-size:.9rem;border:1px solid #ccc;border-radius:4px;margin:.2rem .4rem .2rem 0}
button{padding:.5rem 1rem;font-size:1rem;border:0;background:#0b57d0;color:#fff;border-radius:4px;cursor:pointer}
.filters{margin:.4rem 0}
.result{background:#fff;border:1px solid #e3e3e3;border-radius:6px;padding:.8rem 1rem;margin:.6rem 0}
.result .name{font-weight:600;font-size:1.05rem}
.meta{color:#555;font-size:.85rem;margin-top:.3rem}
.meta span{margin-right:1rem}
.cat{display:inline-block;background:#eef;border-radius:3px;padding:0 .4rem;color:#456}
.facet{display:inline-block;background:#f0f0f0;border-radius:3px;padding:0 .35rem;margin-right:.25rem;color:#555;font-size:.8rem}
.meta .facet{margin-right:.25rem}
.sw{color:#137333}.sw b{color:#0b8043}.lc{color:#a50e0e}
.magnet{font-family:monospace;font-size:.8rem;word-break:break-all}
.hash{font-family:monospace;color:#888;font-size:.75rem}
.muted{color:#888}.empty{padding:2rem;text-align:center;color:#888}
.pager{margin:1rem 0}.pager a{margin-right:1rem}
footer{margin-top:2rem;color:#aaa;font-size:.8rem}
"#;

// --- filter / order UI vocabulary (Python `_SINCE_PRESETS` … `_VALID_ORDERS`) --

/// Recency-window presets (label, `since=` seconds) offered in the UI.
const SINCE_PRESETS: &[(&str, &str)] = &[
    ("Any time", ""),
    ("Past hour", "3600"),
    ("Past day", "86400"),
    ("Past week", "604800"),
    ("Past month", "2592000"),
];

/// Minimum-size presets (label, `min_size=` bytes) offered in the UI.
const SIZE_PRESETS: &[(&str, &str)] = &[
    ("Any size", ""),
    ("> 100 MB", "104857600"),
    ("> 1 GB", "1073741824"),
    ("> 5 GB", "5368709120"),
];

/// Orderings offered in the UI. Note `oldest` is a *valid* `order=` param (see
/// [`order_from_str`]) but deliberately has no option here, mirroring Python's
/// `_ORDERS` — an `?order=oldest` request therefore renders with no option
/// selected, exactly as the reference does.
const ORDERS: &[(&str, &str)] = &[
    ("Relevance", "relevance"),
    ("Latest", "latest"),
    ("Largest", "size"),
    ("Most seen", "seen"),
];

/// The accepted `order=` spellings (Python's `_VALID_ORDERS`); anything else
/// falls back to relevance.
fn order_from_str(s: &str) -> Order {
    match s {
        "latest" => Order::Latest,
        "oldest" => Order::Oldest,
        "size" => Order::Size,
        "seen" => Order::Seen,
        _ => Order::Relevance,
    }
}

/// The `order=` query-param spelling for an [`Order`] (inverse of
/// [`order_from_str`]), used for the `selected` state and the pager links.
fn order_param(o: Order) -> &'static str {
    match o {
        Order::Relevance => "relevance",
        Order::Latest => "latest",
        Order::Oldest => "oldest",
        Order::Size => "size",
        Order::Seen => "seen",
    }
}

/// The filter state as the *UI* sees it: the raw `category` / `min_size` /
/// `since` / `order` query-param values, which the form reflects back as the
/// selected options and the pager preserves in its links.
///
/// This is deliberately distinct from the store-side [`Filters`]: `since` there
/// is resolved against the clock into an absolute `min_last_seen`, which cannot
/// be rendered back into a preset option or a stable link. Like the reference,
/// only these four are surfaced in the form and carried across pages —
/// `max_size` / `min_files` / `max_files` are honoured by the backend for the
/// current request but are not round-tripped.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct UiFilters {
    /// Selected category (must be one of [`CATEGORIES`] to have any effect).
    pub category: Option<String>,
    /// Selected `min_size=` in bytes.
    pub min_size: Option<u64>,
    /// Selected `since=` recency window, in seconds.
    pub since: Option<u64>,
    /// Selected ordering.
    pub order: Order,
}

/// Pagination state for a results page (Python's `total`/`limit`/`offset`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Page {
    /// Total matching torrents, ignoring `limit`/`offset` — the "N match" count
    /// and what decides whether a `next` link exists.
    pub total: usize,
    /// Page size.
    pub limit: usize,
    /// Row offset of this page.
    pub offset: usize,
}

impl Default for Page {
    fn default() -> Self {
        Self {
            total: 0,
            limit: 25,
            offset: 0,
        }
    }
}

/// Local swarm health per infohash, folded into results before rendering.
type Swarm = std::collections::HashMap<String, (u64, u64)>;

fn facet_spans(tags: &str) -> String {
    let mut out = String::new();
    for tok in tags.split_whitespace().take(12) {
        if let Some((_, val)) = tok.split_once(':') {
            if !val.is_empty() {
                out.push_str(&format!("<span class=facet>{}</span>", esc(val)));
            }
        }
    }
    out
}

fn swarm_meta(sw: &Swarm, ih: &str) -> String {
    match sw.get(ih) {
        Some((s, l)) => {
            format!("<span class=sw><b>{s}</b> seeders</span><span class=lc>{l} leechers</span>")
        }
        None => String::new(),
    }
}

fn magnet_anchor(magnet: &str) -> String {
    format!("<a class=\"magnet\" href=\"{}\">magnet</a>", esc(magnet))
}

/// One `<select>` control: `options` is `(label, value)`, and the option whose
/// value equals `current` gets ` selected`. Both label and value are escaped.
fn select_html<L: AsRef<str>, V: AsRef<str>>(
    name: &str,
    options: &[(L, V)],
    current: &str,
) -> String {
    let mut out = format!("<select name={name}>");
    for (label, value) in options {
        let sel = if value.as_ref() == current {
            " selected"
        } else {
            ""
        };
        out.push_str(&format!(
            "<option value='{}'{sel}>{}</option>",
            esc(value.as_ref()),
            esc(label.as_ref())
        ));
    }
    out.push_str("</select>");
    out
}

/// The category `<select>`: "Any type" plus the fixed [`CATEGORIES`] vocabulary.
fn category_select(current: Option<&str>) -> String {
    let mut opts: Vec<(String, String)> = vec![("Any type".to_string(), String::new())];
    opts.extend(CATEGORIES.iter().map(|c| (title_case(c), (*c).to_string())));
    select_html("category", &opts, current.unwrap_or_default())
}

/// The filter/order controls carried on every results page.
fn filters_html(f: &UiFilters) -> String {
    let num = |v: Option<u64>| v.map(|n| n.to_string()).unwrap_or_default();
    format!(
        "<div class=filters>{}{}{}{}</div>",
        category_select(f.category.as_deref()),
        select_html("min_size", SIZE_PRESETS, &num(f.min_size)),
        select_html("since", SINCE_PRESETS, &num(f.since)),
        select_html("order", ORDERS, order_param(f.order)),
    )
}

/// The query string that carries the active query + filters onto another page.
/// Values are percent-encoded (`urllib.parse.quote`), which is also what keeps a
/// hostile `?q=` inert inside the `href` — nothing here is raw.
fn qs(query: &str, f: &UiFilters, limit: usize, offset: usize) -> String {
    let mut pairs: Vec<(&str, String)> = Vec::new();
    if !query.is_empty() {
        pairs.push(("q", query.to_string()));
    }
    pairs.push(("limit", limit.to_string()));
    pairs.push(("offset", offset.to_string()));
    if let Some(c) = f.category.as_deref().filter(|c| !c.is_empty()) {
        pairs.push(("category", c.to_string()));
    }
    if let Some(v) = f.min_size {
        pairs.push(("min_size", v.to_string()));
    }
    if let Some(v) = f.since {
        pairs.push(("since", v.to_string()));
    }
    if f.order != Order::Relevance {
        pairs.push(("order", order_param(f.order).to_string()));
    }
    pairs
        .iter()
        .map(|(k, v)| format!("{k}={}", crate::store::quote(v)))
        .collect::<Vec<_>>()
        .join("&")
}

/// prev/next links for a page of `total` results (empty when both would be).
fn pager(query: &str, f: &UiFilters, page: Page) -> String {
    let Page {
        total,
        limit,
        offset,
    } = page;
    let mut links = String::new();
    if offset > 0 {
        let prev = offset.saturating_sub(limit);
        links.push_str(&format!(
            "<a href='/search?{}'>&larr; prev</a>",
            qs(query, f, limit, prev)
        ));
    }
    if offset + limit < total {
        links.push_str(&format!(
            "<a href='/search?{}'>next &rarr;</a>",
            qs(query, f, limit, offset + limit)
        ));
    }
    if links.is_empty() {
        String::new()
    } else {
        format!("<div class=pager>{links}</div>")
    }
}

/// Render the search/results page: the query form + filter controls, the result
/// rows, and the prev/next pager.
///
/// `page.total` is the *match* count (`Store::count`), not `results.len()` — it
/// is what the "N match" line reports and what decides the `next` link.
#[must_use]
pub fn render_results(
    query: &str,
    results: &[SearchResult],
    stats: &Stats,
    sw: &Swarm,
    f: &UiFilters,
    page: Page,
) -> Vec<u8> {
    let mut p = String::new();
    p.push_str("<!doctype html><html><head><meta charset=utf-8>");
    p.push_str("<meta name=viewport content='width=device-width,initial-scale=1'>");
    p.push_str(&format!(
        "<title>torrentds search</title><style>{PAGE_CSS}</style></head><body>"
    ));
    p.push_str("<h1>torrentds &mdash; DHT metadata search</h1>");
    p.push_str("<form action='/search' method='get'>");
    p.push_str(&format!(
        "<input type=text name=q value='{}' placeholder='search torrents...' autofocus>",
        esc(query)
    ));
    p.push_str("<button type=submit>Search</button>");
    p.push_str(&filters_html(f));
    p.push_str("</form>");
    p.push_str(&format!(
        "<p class=muted>{} torrents indexed &middot; {} total &middot; {} match</p>",
        stats.torrents,
        human_size(stats.total_size),
        page.total
    ));
    if !query.is_empty() && results.is_empty() {
        p.push_str(&format!(
            "<div class=empty>No results for &ldquo;{}&rdquo;.</div>",
            esc(query)
        ));
    }
    for r in results {
        let ih = esc(&r.infohash);
        p.push_str("<div class=result>");
        p.push_str(&format!(
            "<div class=name><a href='/t/{ih}'>{}</a></div>",
            esc(if r.name.is_empty() {
                "(unnamed)"
            } else {
                &r.name
            })
        ));
        p.push_str(&format!(
            "<div class=meta><span class=cat>{}</span>{}<span>{}</span><span>{} files</span>\
             <span>{} pieces</span><span>seen {}&times;</span>{}{}</div>",
            esc(&r.category),
            facet_spans(&r.tags),
            human_size(r.total_size),
            r.file_count,
            r.piece_count,
            r.seen_count,
            swarm_meta(sw, &r.infohash),
            magnet_anchor(&r.magnet),
        ));
        p.push_str(&format!("<div class=hash>{ih}</div></div>"));
    }
    p.push_str(&pager(query, f, page));
    p.push_str(
        "<footer>Metadata + magnet links only. No content is stored or served. \
         Operators are responsible for legal compliance.</footer></body></html>",
    );
    p.into_bytes()
}

/// Render a torrent detail page.
#[must_use]
pub fn render_detail(t: &TorrentRecord, has_torrent: bool, sw: &Swarm) -> Vec<u8> {
    let ih = esc(&t.infohash);
    let name = if t.name.is_empty() {
        "(unnamed)"
    } else {
        &t.name
    };
    let dl = if has_torrent {
        format!("<a href='/torrent/{ih}.torrent'>download .torrent</a>")
    } else {
        String::new()
    };
    let mut p = String::new();
    p.push_str("<!doctype html><html><head><meta charset=utf-8>");
    p.push_str(&format!(
        "<title>{}</title><style>{PAGE_CSS}</style></head><body>",
        esc(name)
    ));
    p.push_str("<p><a href='/'>&larr; search</a></p>");
    p.push_str(&format!("<h1>{}</h1>", esc(name)));
    p.push_str(&format!(
        "<div class=meta><span class=cat>{}</span><span>{}</span><span>{} files</span>\
         <span>{} pieces</span><span>piece len {}</span><span>seen {}&times;</span>{}</div>",
        esc(&t.category),
        human_size(t.total_size),
        t.file_count,
        t.piece_count,
        human_size(t.piece_length),
        t.seen_count,
        swarm_meta(sw, &t.infohash),
    ));
    p.push_str(&format!(
        "<p class=magnet>{} &nbsp; {dl}</p>",
        magnet_anchor(&t.magnet())
    ));
    p.push_str(&format!(
        "<p class=hash>infohash {ih}</p><h3>Files</h3><ul>"
    ));
    for (path, len) in &t.files {
        p.push_str(&format!(
            "<li>{} <span class=muted>({})</span></li>",
            esc(path),
            human_size(*len)
        ));
    }
    p.push_str("</ul></body></html>");
    p.into_bytes()
}

/// Render the browse landing (category tiles + a recently-added preview).
#[must_use]
pub fn render_browse(counts: &[(&str, usize)], recent: &[SearchResult], stats: &Stats) -> Vec<u8> {
    let mut p = String::new();
    p.push_str("<!doctype html><html><head><meta charset=utf-8>");
    p.push_str("<meta name=viewport content='width=device-width,initial-scale=1'>");
    p.push_str(&format!(
        "<title>torrentds browse</title><style>{PAGE_CSS}</style></head><body>"
    ));
    p.push_str("<h1>torrentds &mdash; browse</h1>");
    p.push_str("<p><a href='/'>&larr; search</a> &middot; <a href='/recent'>recently added</a> &middot; <a href='/rss'>RSS</a></p>");
    p.push_str(&format!(
        "<p class=muted>{} torrents indexed &middot; {} total</p><h3>Categories</h3><div class=filters>",
        stats.torrents,
        human_size(stats.total_size)
    ));
    for (cat, n) in counts {
        // `cat` is a fixed lowercase-ASCII vocabulary today, but escape it in the
        // href too so this never becomes a reflected-XSS sink if it turns dynamic.
        p.push_str(&format!(
            "<a class=cat href='/browse?category={}'>{} ({n})</a> ",
            esc(cat),
            esc(&title_case(cat))
        ));
    }
    p.push_str("</div><h3>Recently added</h3>");
    if recent.is_empty() {
        p.push_str("<div class=empty>Nothing indexed yet.</div>");
    }
    for r in recent {
        let ih = esc(&r.infohash);
        p.push_str(&format!(
            "<div class=result><div class=name><a href='/t/{ih}'>{}</a></div>\
             <div class=meta><span class=cat>{}</span><span>{}</span><span>{} files</span>{}</div></div>",
            esc(if r.name.is_empty() { "(unnamed)" } else { &r.name }),
            esc(&r.category),
            human_size(r.total_size),
            r.file_count,
            magnet_anchor(&r.magnet),
        ));
    }
    p.push_str("<footer>Metadata + magnet links only. No content is stored or served.</footer></body></html>");
    p.into_bytes()
}

fn title_case(s: &str) -> String {
    let mut c = s.chars();
    match c.next() {
        Some(f) => f.to_uppercase().collect::<String>() + c.as_str(),
        None => String::new(),
    }
}

/// Render an RSS 2.0 feed of the newest torrents (optionally for a saved query).
#[must_use]
pub fn render_rss(items: &[SearchResult], base_url: &str, query: &str) -> Vec<u8> {
    let (title, link, desc) = if query.is_empty() {
        (
            "torrentds — newest torrents".to_string(),
            format!("{}/", xml_text(base_url)),
            "Newest metadata harvested from the DHT".to_string(),
        )
    } else {
        (
            format!("torrentds — search: {}", xml_text(query)),
            format!(
                "{}/search?q={}",
                xml_text(base_url),
                crate::store::quote(query)
            ),
            format!("Newest torrents matching {}", xml_text(query)),
        )
    };
    let mut p = String::new();
    p.push_str("<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"><channel>");
    p.push_str(&format!("<title>{title}</title>"));
    p.push_str(&format!("<link>{link}</link>"));
    p.push_str(&format!("<description>{desc}</description>"));
    for r in items {
        let ih = xml_text(&r.infohash);
        p.push_str(&format!(
            "<item><title>{}</title><link>{}/t/{ih}</link>\
             <guid isPermaLink=\"false\">{ih}</guid>\
             <enclosure url=\"{}\" type=\"application/x-bittorrent\"/>\
             <description>{}, {} files</description>\
             <pubDate>{}</pubDate></item>",
            xml_text(if r.name.is_empty() {
                &r.infohash
            } else {
                &r.name
            }),
            xml_text(base_url),
            xml_text(&r.magnet),
            xml_text(&human_size(r.total_size)),
            r.file_count,
            rfc2822(r.last_seen),
        ));
    }
    p.push_str("</channel></rss>");
    p.into_bytes()
}

// --- Torznab (Prowlarr / Jackett / *arr) -----------------------------------

const TORZNAB_NS: &str = "http://torznab.com/schemas/2015/feed";

fn torznab_is_search(t: &str) -> bool {
    matches!(
        t,
        "search"
            | "tvsearch"
            | "tv-search"
            | "movie"
            | "movie-search"
            | "music"
            | "audio"
            | "audio-search"
            | "book"
            | "book-search"
    )
}

/// The Torznab category id for a result (TV vs Movie split via the `kind:tv` tag).
fn torznab_category(cat: &str, tags: &str) -> &'static str {
    match cat {
        "video" => {
            if tags.split_whitespace().any(|t| t == "kind:tv") {
                "5000"
            } else {
                "2000"
            }
        }
        "audio" => "3000",
        "document" => "7000",
        "software" => "4000",
        _ => "8000",
    }
}

/// Map a Torznab `cat=` id (comma list) to a store category filter (or `None`).
fn torznab_store_cat(cat_param: &str) -> Option<&'static str> {
    for part in cat_param.split(',') {
        match part.trim() {
            "2000" | "5000" => return Some("video"),
            "3000" => return Some("audio"),
            "7000" => return Some("document"),
            "4000" => return Some("software"),
            _ => {}
        }
    }
    None
}

/// The Torznab capabilities document (`?t=caps`).
#[must_use]
pub fn torznab_caps() -> Vec<u8> {
    concat!(
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>",
        "<caps><server title=\"torrentds\"/>",
        "<limits max=\"100\" default=\"25\"/>",
        "<searching>",
        "<search available=\"yes\" supportedParams=\"q\"/>",
        "<tv-search available=\"yes\" supportedParams=\"q,season,ep\"/>",
        "<movie-search available=\"yes\" supportedParams=\"q\"/>",
        "<audio-search available=\"yes\" supportedParams=\"q\"/>",
        "<book-search available=\"yes\" supportedParams=\"q\"/>",
        "</searching><categories>",
        "<category id=\"2000\" name=\"Movies\"/>",
        "<category id=\"5000\" name=\"TV\"/>",
        "<category id=\"3000\" name=\"Audio\"/>",
        "<category id=\"4000\" name=\"PC\"/>",
        "<category id=\"7000\" name=\"Books\"/>",
        "<category id=\"8000\" name=\"Other\"/>",
        "</categories></caps>"
    )
    .as_bytes()
    .to_vec()
}

/// A Torznab/Newznab RSS feed for a result list (with local swarm health folded).
#[must_use]
pub fn torznab_search_xml(items: &[SearchResult], base_url: &str, sw: &Swarm) -> Vec<u8> {
    let mut p = String::new();
    p.push_str("<?xml version=\"1.0\" encoding=\"UTF-8\"?>");
    p.push_str(&format!(
        "<rss version=\"2.0\" xmlns:torznab=\"{TORZNAB_NS}\"><channel>"
    ));
    p.push_str("<title>torrentds</title>");
    p.push_str(&format!("<link>{}/</link>", xml_text(base_url)));
    p.push_str("<description>DHT torrent-metadata index</description>");
    for r in items {
        let ih = &r.infohash;
        let name = xml_text(if r.name.is_empty() { ih } else { &r.name });
        let size = r.total_size;
        let dl = format!("{base_url}/torrent/{ih}.torrent");
        let (seeders, leechers) = sw.get(ih).copied().unwrap_or((0, 0));
        let cat = torznab_category(&r.category, &r.tags);
        p.push_str(&format!(
            "<item><title>{name}</title>\
             <guid isPermaLink=\"false\">{}</guid>\
             <pubDate>{}</pubDate><size>{size}</size><link>{}</link>\
             <enclosure url=\"{}\" length=\"{size}\" type=\"application/x-bittorrent\"/>\
             <torznab:attr name=\"category\" value=\"{cat}\"/>\
             <torznab:attr name=\"size\" value=\"{size}\"/>\
             <torznab:attr name=\"seeders\" value=\"{seeders}\"/>\
             <torznab:attr name=\"peers\" value=\"{}\"/>\
             <torznab:attr name=\"infohash\" value=\"{}\"/>\
             <torznab:attr name=\"magneturl\" value=\"{}\"/></item>",
            xml_text(ih),
            rfc2822(r.last_seen),
            xml_text(&dl),
            xml_text(&dl),
            seeders + leechers,
            xml_text(ih),
            xml_text(&r.magnet),
        ));
    }
    p.push_str("</channel></rss>");
    p.into_bytes()
}

// --- JSON API rows ---------------------------------------------------------

fn api_row(r: &SearchResult, sw: &Swarm) -> String {
    let mut j = String::from("{");
    j.push_str(&format!("\"infohash\":{},", json_str(&r.infohash)));
    j.push_str(&format!("\"name\":{},", json_str(&r.name)));
    j.push_str(&format!("\"total_size\":{},", r.total_size));
    j.push_str(&format!("\"file_count\":{},", r.file_count));
    j.push_str(&format!("\"piece_count\":{},", r.piece_count));
    j.push_str(&format!("\"seen_count\":{},", r.seen_count));
    j.push_str(&format!("\"category\":{},", json_str(&r.category)));
    j.push_str(&format!("\"version\":{},", json_str(&r.version)));
    if let Some(v2) = &r.infohash_v2 {
        j.push_str(&format!("\"infohash_v2\":{},", json_str(v2)));
    }
    if let Some((s, l)) = sw.get(&r.infohash) {
        j.push_str(&format!("\"seeders\":{s},\"leechers\":{l},"));
    }
    if r.dup_count > 1 {
        j.push_str(&format!("\"dup_count\":{},", r.dup_count));
        let alts: Vec<String> = r.alt_infohashes.iter().map(|a| json_str(a)).collect();
        j.push_str(&format!("\"alt_infohashes\":[{}],", alts.join(",")));
    }
    j.push_str(&format!("\"magnet\":{}", json_str(&r.magnet)));
    j.push('}');
    j
}

fn stats_json(s: &Stats) -> String {
    format!(
        "{{\"torrents\":{},\"files\":{},\"total_size\":{},\"discovered\":{},\"pending\":{},\
         \"blocked_infohash\":{},\"blocked_keyword\":{},\"hybrid_v2\":{},\"spam_flagged\":{}}}",
        s.torrents,
        s.files,
        s.total_size,
        s.discovered,
        s.pending,
        s.blocked_infohash,
        s.blocked_keyword,
        s.hybrid_v2,
        s.spam_flagged,
    )
}

// --- query parsing ---------------------------------------------------------

type Params = std::collections::HashMap<String, String>;

fn parse_qs(query: &str) -> Params {
    let mut out = Params::new();
    for pair in query.split('&') {
        if pair.is_empty() {
            continue;
        }
        let (k, v) = pair.split_once('=').unwrap_or((pair, ""));
        out.entry(url_decode(k)).or_insert_with(|| url_decode(v));
    }
    out
}

fn url_decode(s: &str) -> String {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        match b[i] {
            b'%' if i + 2 < b.len() => {
                if let (Some(h), Some(l)) = (
                    (b[i + 1] as char).to_digit(16),
                    (b[i + 2] as char).to_digit(16),
                ) {
                    out.push((h * 16 + l) as u8);
                    i += 3;
                    continue;
                }
                out.push(b[i]);
                i += 1;
            }
            b'+' => {
                out.push(b' ');
                i += 1;
            }
            c => {
                out.push(c);
                i += 1;
            }
        }
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn clamp_int(v: Option<&String>, default: usize, lo: usize, hi: usize) -> usize {
    let Some(s) = v else {
        return default;
    };
    match s.parse::<i64>() {
        Ok(n) => n.clamp(lo as i64, hi as i64) as usize,
        // Python parses arbitrary precision then clamps, so an out-of-i64
        // magnitude (`?offset=99999999999999999999`) clamps to the bound by sign
        // rather than silently falling back to the default (page 0).
        Err(_) => {
            let t = s.trim();
            let digits = |x: &str| !x.is_empty() && x.bytes().all(|b| b.is_ascii_digit());
            if let Some(rest) = t.strip_prefix('-') {
                if digits(rest) {
                    return lo;
                }
            } else if digits(t) {
                return hi;
            }
            default
        }
    }
}

fn opt_u64(p: &Params, name: &str) -> Option<u64> {
    p.get(name).filter(|s| !s.is_empty())?.parse().ok()
}

fn filters_from(p: &Params, show_spam: bool) -> Filters {
    let category = p
        .get("category")
        .filter(|c| CATEGORIES.contains(&c.as_str()))
        .cloned();
    let min_last_seen = opt_u64(p, "since").map(|since| now_secs().saturating_sub(since));
    Filters {
        min_size: opt_u64(p, "min_size"),
        max_size: opt_u64(p, "max_size"),
        min_files: opt_u64(p, "min_files").map(|n| n as usize),
        max_files: opt_u64(p, "max_files").map(|n| n as usize),
        category,
        min_last_seen,
        tag: p.get("tag").filter(|s| !s.is_empty()).cloned(),
        include_spam: show_spam,
    }
}

fn order_from(p: &Params) -> Order {
    order_from_str(p.get("order").map_or("", String::as_str))
}

/// The UI-visible filter state for this request (what the form reflects back and
/// the pager preserves). The store-side counterpart is [`filters_from`].
fn ui_filters_from(p: &Params) -> UiFilters {
    UiFilters {
        category: p
            .get("category")
            .filter(|c| CATEGORIES.contains(&c.as_str()))
            .cloned(),
        min_size: opt_u64(p, "min_size"),
        since: opt_u64(p, "since"),
        order: order_from(p),
    }
}

fn now_secs() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0)
}

/// RFC 2822 GMT date, matching Python's `email.utils.formatdate(secs, usegmt=True)`
/// — e.g. `Thu, 01 Jan 1970 00:00:00 GMT`. Used for feed `pubDate`s.
#[must_use]
pub fn rfc2822(secs: u64) -> String {
    const DOW: [&str; 7] = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    const MON: [&str; 12] = [
        "Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
    ];
    let days = (secs / 86400) as i64;
    let rem = secs % 86400;
    let (h, mi, s) = (rem / 3600, (rem % 3600) / 60, rem % 60);
    let dow = DOW[((days + 4).rem_euclid(7)) as usize];
    // civil_from_days (Howard Hinnant).
    let z = days + 719_468;
    let era = if z >= 0 { z } else { z - 146_096 } / 146_097;
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36524 - doe / 146_096) / 365;
    let y = yoe + era * 400;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = y + i64::from(m <= 2);
    format!(
        "{dow}, {d:02} {} {year} {h:02}:{mi:02}:{s:02} GMT",
        MON[(m - 1) as usize]
    )
}

// --- response + routing ----------------------------------------------------

struct Resp {
    status: u16,
    ctype: &'static str,
    body: Vec<u8>,
    extra: Vec<(String, String)>,
}

impl Resp {
    fn html(body: Vec<u8>) -> Self {
        Self {
            status: 200,
            ctype: "text/html; charset=utf-8",
            body,
            extra: Vec::new(),
        }
    }
    fn json(status: u16, body: String) -> Self {
        Self {
            status,
            ctype: "application/json",
            body: body.into_bytes(),
            extra: Vec::new(),
        }
    }
    fn text(ctype: &'static str, body: Vec<u8>) -> Self {
        Self {
            status: 200,
            ctype,
            body,
            extra: Vec::new(),
        }
    }
    fn not_found() -> Self {
        Self {
            status: 404,
            ctype: "text/html; charset=utf-8",
            body: b"<h1>404 not found</h1>".to_vec(),
            extra: Vec::new(),
        }
    }
}

/// Immutable per-server configuration.
#[derive(Clone)]
pub struct SearchServer {
    store: Arc<Mutex<Store>>,
    peer_store: Option<Arc<Mutex<PeerStore>>>,
    admin_token: String,
    base_url: String,
}

impl std::fmt::Debug for SearchServer {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("SearchServer")
            .field("base_url", &self.base_url)
            .field("admin", &!self.admin_token.is_empty())
            .finish_non_exhaustive()
    }
}

impl SearchServer {
    /// A server over `store`, optionally folding swarm health from `peer_store`.
    /// A non-empty `admin_token` enables `POST /api/block`.
    #[must_use]
    pub fn new(
        store: Arc<Mutex<Store>>,
        peer_store: Option<Arc<Mutex<PeerStore>>>,
        admin_token: impl Into<String>,
    ) -> Self {
        Self {
            store,
            peer_store,
            admin_token: admin_token.into(),
            base_url: String::new(),
        }
    }

    /// Set the self-describing base URL (e.g. `http://127.0.0.1:8804`) used for
    /// the absolute links in the RSS feed and the Torznab `<enclosure>`/download
    /// URLs. Empty (the default) leaves those links host-relative.
    #[must_use]
    pub fn with_base_url(mut self, base_url: impl Into<String>) -> Self {
        self.base_url = base_url.into();
        self
    }

    /// Compute local swarm health (seeders, leechers) for a set of results.
    fn swarm_for(&self, ihs: impl Iterator<Item = String>) -> Swarm {
        let mut sw = Swarm::new();
        let Some(ps) = &self.peer_store else {
            return sw;
        };
        let mut store = ps.lock().unwrap();
        let now = now_secs();
        for ih in ihs {
            if let Some(raw) = hex20(&ih) {
                let c = store.counts(&raw, now);
                sw.insert(ih, (c.complete, c.incomplete));
            }
        }
        sw
    }

    /// The exact body `/metrics` returns.
    ///
    /// Public so the suite-wide exposition contract can be asserted without a
    /// socket: `astrx/tests/metrics_contract.rs` feeds this to
    /// `suitedash::metrics::parse_metrics`, the parser that actually consumes it
    /// in production. `route` itself stays private because the rest of its
    /// surface (`Resp`, `Params`) is internal.
    #[must_use]
    pub fn metrics_text(&self) -> String {
        String::from_utf8_lossy(&self.metrics()).into_owned()
    }

    fn route(&self, method: &str, target: &str, headers: &Params, body: &str) -> Resp {
        let (path, query) = target.split_once('?').unwrap_or((target, ""));
        let p = parse_qs(query);
        if method == "POST" {
            if path == "/api/block" {
                return self.do_block(&p, body, headers);
            }
            return Resp::json(404, "{\"error\":\"not found\"}".into());
        }
        let q = p.get("q").cloned().unwrap_or_default();
        let limit = clamp_int(p.get("limit"), 25, 1, 100);
        let offset = clamp_int(p.get("offset"), 0, 0, 1_000_000);
        let show_spam = matches!(
            p.get("show_spam").map(String::as_str),
            Some("1" | "true" | "on")
        );

        match path {
            "/" | "/search" => self.results_page(&q, limit, offset, &p, show_spam),
            "/recent" => self.recent_page(limit, offset, &p, show_spam),
            "/browse" => self.browse_page(&p, limit, offset, show_spam),
            "/api/search" => self.api_search(&q, limit, offset, &p, show_spam),
            "/api/stats" => Resp::json(200, stats_json(&self.store.lock().unwrap().stats())),
            "/feed" | "/rss" | "/feed.xml" => self.rss(&q, limit, &p, show_spam),
            "/health" => {
                let s = self.store.lock().unwrap().stats();
                Resp::json(
                    200,
                    format!(
                        "{{\"status\":\"ok\",\"torrents\":{},\"pending\":{}}}",
                        s.torrents, s.pending
                    ),
                )
            }
            "/metrics" => Resp::text("text/plain; version=0.0.4; charset=utf-8", self.metrics()),
            "/torznab/api" | "/torznab" => self.torznab(&p),
            _ if path.starts_with("/api/torrent/") => {
                self.api_detail(path.trim_start_matches("/api/torrent/").trim_matches('/'))
            }
            _ if path.starts_with("/torrent/") && path.ends_with(".torrent") => {
                let ih = &path[("/torrent/".len())..(path.len() - ".torrent".len())];
                self.serve_torrent(ih)
            }
            _ if path.starts_with("/t/") => self.detail_page(path[3..].trim_matches('/')),
            _ => Resp::not_found(),
        }
    }

    fn search(
        &self,
        q: &str,
        limit: usize,
        offset: usize,
        f: &Filters,
        order: Order,
    ) -> Vec<SearchResult> {
        self.store
            .lock()
            .unwrap()
            .search(q, limit, offset, order, f, true)
    }

    /// Search, count and snapshot stats under ONE store lock, so the "N match"
    /// total and the rows it labels can't come from two different store states.
    fn search_and_count(
        &self,
        q: &str,
        limit: usize,
        offset: usize,
        f: &Filters,
        order: Order,
    ) -> (Vec<SearchResult>, usize, Stats) {
        let store = self.store.lock().unwrap();
        (
            store.search(q, limit, offset, order, f, true),
            store.count(q, f),
            store.stats(),
        )
    }

    fn results_page(&self, q: &str, limit: usize, offset: usize, p: &Params, spam: bool) -> Resp {
        let f = filters_from(p, spam);
        let ui = ui_filters_from(p);
        let (results, total, stats) = self.search_and_count(q, limit, offset, &f, ui.order);
        let sw = self.swarm_for(results.iter().map(|r| r.infohash.clone()));
        let page = Page {
            total,
            limit,
            offset,
        };
        Resp::html(render_results(q, &results, &stats, &sw, &ui, page))
    }

    fn recent_page(&self, limit: usize, offset: usize, p: &Params, spam: bool) -> Resp {
        let f = filters_from(p, spam);
        // `/recent` is "newest first" by definition; the form reflects that back.
        let ui = UiFilters {
            order: Order::Latest,
            ..ui_filters_from(p)
        };
        let (results, total, stats) = self.search_and_count("", limit, offset, &f, Order::Latest);
        let sw = self.swarm_for(results.iter().map(|r| r.infohash.clone()));
        let page = Page {
            total,
            limit,
            offset,
        };
        Resp::html(render_results("", &results, &stats, &sw, &ui, page))
    }

    fn browse_page(&self, p: &Params, limit: usize, offset: usize, spam: bool) -> Resp {
        let f = filters_from(p, spam);
        if f.category.is_none() {
            let (counts, recent, stats) = {
                let store = self.store.lock().unwrap();
                (
                    store.category_counts(spam),
                    store.search("", 15, 0, Order::Latest, &f, true),
                    store.stats(),
                )
            };
            let sw = self.swarm_for(recent.iter().map(|r| r.infohash.clone()));
            let _ = &sw;
            Resp::html(render_browse(&counts, &recent, &stats))
        } else {
            let ui = UiFilters {
                order: Order::Latest,
                ..ui_filters_from(p)
            };
            let (results, total, stats) =
                self.search_and_count("", limit, offset, &f, Order::Latest);
            let sw = self.swarm_for(results.iter().map(|r| r.infohash.clone()));
            let page = Page {
                total,
                limit,
                offset,
            };
            Resp::html(render_results("", &results, &stats, &sw, &ui, page))
        }
    }

    fn detail_page(&self, ih: &str) -> Resp {
        let ih = ih.to_ascii_lowercase();
        let (rec, has_blob) = {
            let store = self.store.lock().unwrap();
            (store.get(&ih).cloned(), store.info_bytes(&ih).is_some())
        };
        match rec {
            None => Resp::not_found(),
            Some(t) => {
                let sw = self.swarm_for(std::iter::once(t.infohash.clone()));
                Resp::html(render_detail(&t, has_blob, &sw))
            }
        }
    }

    fn api_search(&self, q: &str, limit: usize, offset: usize, p: &Params, spam: bool) -> Resp {
        let f = filters_from(p, spam);
        let (results, total) = {
            let store = self.store.lock().unwrap();
            (
                store.search(q, limit, offset, order_from(p), &f, true),
                store.count(q, &f),
            )
        };
        let sw = self.swarm_for(results.iter().map(|r| r.infohash.clone()));
        let rows: Vec<String> = results.iter().map(|r| api_row(r, &sw)).collect();
        let has_more = offset + results.len() < total;
        let next = if offset + limit < total {
            format!("{}", offset + limit)
        } else {
            "null".into()
        };
        let body = format!(
            "{{\"query\":{},\"count\":{},\"total\":{},\"limit\":{},\"offset\":{},\
             \"has_more\":{},\"next_offset\":{},\"results\":[{}]}}",
            json_str(q),
            results.len(),
            total,
            limit,
            offset,
            has_more,
            next,
            rows.join(",")
        );
        Resp::json(200, body)
    }

    fn api_detail(&self, ih: &str) -> Resp {
        let ih = ih.to_ascii_lowercase();
        let rec = self.store.lock().unwrap().get(&ih).cloned();
        match rec {
            None => Resp::json(404, "{\"error\":\"not found\"}".into()),
            Some(t) => {
                let files: Vec<String> = t
                    .files
                    .iter()
                    .map(|(pth, l)| format!("{{\"path\":{},\"length\":{}}}", json_str(pth), l))
                    .collect();
                let has_torrent = t.info_bytes.is_some();
                let sr = record_as_result(&t);
                let sw = self.swarm_for(std::iter::once(t.infohash.clone()));
                let mut body = api_row(&sr, &sw);
                body.pop(); // drop closing brace to append detail fields
                body.push_str(&format!(
                    ",\"piece_length\":{},\"first_seen\":{},\"last_seen\":{},\
                     \"has_torrent\":{},\"files\":[{}]}}",
                    t.piece_length,
                    t.first_seen,
                    t.last_seen,
                    has_torrent,
                    files.join(",")
                ));
                Resp::json(200, body)
            }
        }
    }

    fn rss(&self, q: &str, limit: usize, p: &Params, spam: bool) -> Resp {
        let mut f = filters_from(p, spam);
        f.include_spam = spam;
        let items = self.search(q, limit, 0, &f, Order::Latest);
        Resp::text(
            "application/rss+xml; charset=utf-8",
            render_rss(&items, &self.base_url, q),
        )
    }

    fn serve_torrent(&self, ih: &str) -> Resp {
        let ih = ih.trim_matches('/').to_ascii_lowercase();
        let info = self
            .store
            .lock()
            .unwrap()
            .info_bytes(&ih)
            .map(<[u8]>::to_vec);
        match info {
            None => Resp::not_found(),
            Some(bytes) => {
                let torrent = build_torrent_file(&bytes, None, &[], None);
                let name = self
                    .store
                    .lock()
                    .unwrap()
                    .get(&ih)
                    .map_or_else(|| ih.clone(), |t| t.name.clone());
                let safe: String = name
                    .chars()
                    .filter(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.' | ' '))
                    .take(80)
                    .collect();
                let safe = if safe.is_empty() { ih.clone() } else { safe };
                let mut r = Resp::text("application/x-bittorrent", torrent);
                r.extra.push((
                    "Content-Disposition".into(),
                    format!("attachment; filename=\"{safe}.torrent\""),
                ));
                r
            }
        }
    }

    fn torznab(&self, p: &Params) -> Resp {
        let t = p.get("t").map_or("search", String::as_str);
        if t == "caps" || !torznab_is_search(t) {
            return Resp::text("application/xml; charset=utf-8", torznab_caps());
        }
        let q = p.get("q").cloned().unwrap_or_default();
        let limit = clamp_int(p.get("limit"), 25, 1, 100);
        let offset = clamp_int(p.get("offset"), 0, 0, 1_000_000);
        // Torznab hides spam and applies the `cat=` category filter.
        let mut f = filters_from(p, false);
        if let Some(cat) = p.get("cat").and_then(|c| torznab_store_cat(c)) {
            f.category = Some(cat.to_string());
        }
        let results = self.search(&q, limit, offset, &f, Order::Relevance);
        let sw = self.swarm_for(results.iter().map(|r| r.infohash.clone()));
        Resp::text(
            "application/rss+xml; charset=utf-8",
            torznab_search_xml(&results, &self.base_url, &sw),
        )
    }

    /// `/metrics` — Prometheus text exposition.
    ///
    /// The store gauges keep their exact `torrentds_<name>` spellings and their
    /// order, because the dashboards key on them. The request block every engine
    /// shares ([`crawlcore::metrics`]) is appended after them: before it, this
    /// endpoint could report how many torrents were indexed but not whether the
    /// search server was answering anyone.
    fn metrics(&self) -> Vec<u8> {
        let s = self.store.lock().unwrap().stats();
        let mut lines = vec![
            format!("torrentds_torrents {}", s.torrents),
            format!("torrentds_files {}", s.files),
            format!("torrentds_total_size {}", s.total_size),
            format!("torrentds_discovered {}", s.discovered),
            format!("torrentds_pending {}", s.pending),
            format!("torrentds_hybrid_v2 {}", s.hybrid_v2),
            format!("torrentds_spam_flagged {}", s.spam_flagged),
        ];
        if let Some(ps) = &self.peer_store {
            let ps = ps.lock().unwrap();
            lines.push(format!("torrentds_tracker_swarms {}", ps.swarm_count()));
        }
        let mut out = lines.join("\n") + "\n";
        out.push_str(&crate::metrics::registry().render(crate::metrics::PREFIX));
        out.into_bytes()
    }

    fn do_block(&self, qs: &Params, body: &str, headers: &Params) -> Resp {
        if self.admin_token.is_empty() {
            return Resp::json(403, "{\"error\":\"blocklist admin disabled\"}".into());
        }
        let form = parse_qs(body);
        let provided = headers
            .get("x-admin-token")
            .cloned()
            .or_else(|| form.get("token").cloned())
            .unwrap_or_default();
        if !ct_eq(provided.as_bytes(), self.admin_token.as_bytes()) {
            return Resp::json(403, "{\"error\":\"invalid admin token\"}".into());
        }
        let kind = form
            .get("kind")
            .or_else(|| qs.get("kind"))
            .cloned()
            .unwrap_or_default();
        let value = form
            .get("value")
            .or_else(|| qs.get("value"))
            .cloned()
            .unwrap_or_default();
        let value = value.trim();
        let mut store = self.store.lock().unwrap();
        match kind.as_str() {
            "infohash" => {
                let ih = value.to_ascii_lowercase();
                if !ih.chars().all(|c| c.is_ascii_hexdigit()) || !matches!(ih.len(), 40 | 64) {
                    return Resp::json(400, "{\"error\":\"infohash must be 40 or 64 hex\"}".into());
                }
                store.add_block_infohash(&ih);
            }
            "keyword" if !value.is_empty() => store.add_block_keyword(value),
            _ => {
                return Resp::json(
                    400,
                    "{\"error\":\"kind must be infohash|keyword and value non-empty\"}".into(),
                )
            }
        }
        let purged = store.purge_blocked();
        Resp::json(
            200,
            format!(
                "{{\"ok\":true,\"kind\":{},\"purged\":{}}}",
                json_str(&kind),
                purged
            ),
        )
    }
}

fn record_as_result(t: &TorrentRecord) -> SearchResult {
    SearchResult {
        infohash: t.infohash.clone(),
        name: t.name.clone(),
        total_size: t.total_size,
        file_count: t.file_count,
        piece_count: t.piece_count,
        seen_count: t.seen_count,
        last_seen: t.last_seen,
        category: t.category.clone(),
        version: t.version.clone(),
        infohash_v2: t.infohash_v2.clone(),
        tags: t.tags.clone(),
        magnet: t.magnet(),
        dup_count: 1,
        alt_infohashes: Vec::new(),
    }
}

/// Constant-time byte comparison (admin-token check).
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

fn hex20(s: &str) -> Option<[u8; 20]> {
    let b = s.as_bytes();
    if b.len() != 40 {
        return None;
    }
    let mut out = [0u8; 20];
    for (i, pair) in b.chunks_exact(2).enumerate() {
        let hi = (pair[0] as char).to_digit(16)?;
        let lo = (pair[1] as char).to_digit(16)?;
        out[i] = (hi << 4 | lo) as u8;
    }
    Some(out)
}

// --- async HTTP/1.1 server -------------------------------------------------

async fn handle_conn(
    mut stream: TcpStream,
    server: SearchServer,
    peer: &str,
) -> std::io::Result<()> {
    let mut buf = Vec::new();
    let mut tmp = [0u8; 2048];
    let read_head = async {
        loop {
            let n = stream.read(&mut tmp).await?;
            if n == 0 {
                break;
            }
            buf.extend_from_slice(&tmp[..n]);
            if buf.windows(4).any(|w| w == b"\r\n\r\n") || buf.len() > 32768 {
                break;
            }
        }
        Ok::<(), std::io::Error>(())
    };
    if tokio::time::timeout(Duration::from_secs(15), read_head)
        .await
        .is_err()
    {
        return Ok(());
    }

    let sep = buf.windows(4).position(|w| w == b"\r\n\r\n");
    let (head, rest) = match sep {
        Some(i) => (
            String::from_utf8_lossy(&buf[..i]).into_owned(),
            &buf[i + 4..],
        ),
        None => (String::from_utf8_lossy(&buf).into_owned(), &b""[..]),
    };
    let mut lines = head.lines();
    let request = lines.next().unwrap_or("");
    let mut it = request.split_whitespace();
    let method = it.next().unwrap_or("GET");
    let target = it.next().unwrap_or("/");

    let mut headers = Params::new();
    let mut content_length = 0usize;
    for line in lines {
        if let Some((k, v)) = line.split_once(':') {
            let key = k.trim().to_ascii_lowercase();
            let val = v.trim().to_string();
            if key == "content-length" {
                content_length = val.parse().unwrap_or(0).min(1_000_000);
            }
            headers.insert(key, val);
        }
    }

    // Read the POST body up to Content-Length, bounded in size (Content-Length is
    // capped at 1 MB above) AND by one OVERALL deadline — a per-read timeout alone
    // lets a client drip one byte per window and hold the connection open forever
    // (slowloris); the whole body read gets a single 15s budget, like the head.
    let mut body = rest.to_vec();
    let read_body = async {
        while body.len() < content_length {
            let n = stream.read(&mut tmp).await?;
            if n == 0 {
                break;
            }
            body.extend_from_slice(&tmp[..n]);
        }
        Ok::<(), std::io::Error>(())
    };
    let _ = tokio::time::timeout(Duration::from_secs(15), read_body).await;
    body.truncate(content_length.max(rest.len().min(content_length)));
    let body_str = String::from_utf8_lossy(&body[..content_length.min(body.len())]).into_owned();

    let started = std::time::Instant::now();
    crate::metrics::registry().begin();
    let resp = server.route(method, target, &headers, &body_str);
    let elapsed = started.elapsed().as_secs_f64();
    let action = crate::metrics::action_of(target);
    crate::metrics::registry().end(resp.status, action, elapsed);
    crawlcore::logfmt::access(
        crate::metrics::PREFIX,
        &crawlcore::logfmt::Request {
            method,
            path: target,
            status: resp.status,
            duration_ms: elapsed * 1000.0,
            peer,
            action,
        },
    );
    let mut out = format!(
        "HTTP/1.1 {} {}\r\nContent-Type: {}\r\nContent-Length: {}\r\nConnection: close\r\n",
        resp.status,
        status_text(resp.status),
        resp.ctype,
        resp.body.len()
    );
    for (k, v) in &resp.extra {
        out.push_str(&format!("{k}: {v}\r\n"));
    }
    out.push_str("\r\n");
    stream.write_all(out.as_bytes()).await?;
    stream.write_all(&resp.body).await?;
    Ok(())
}

fn status_text(code: u16) -> &'static str {
    match code {
        200 => "OK",
        400 => "Bad Request",
        403 => "Forbidden",
        404 => "Not Found",
        _ => "OK",
    }
}

/// Start the no-JS search server on `addr`. Returns the bound address and the
/// accept-loop handle (abort it to stop).
pub async fn serve_search(
    server: SearchServer,
    addr: std::net::SocketAddr,
) -> std::io::Result<(std::net::SocketAddr, tokio::task::JoinHandle<()>)> {
    let listener = TcpListener::bind(addr).await?;
    let bound = listener.local_addr()?;
    let handle = tokio::spawn(async move {
        loop {
            match listener.accept().await {
                Ok((stream, peer)) => {
                    let server = server.clone();
                    tokio::spawn(async move {
                        let _ = handle_conn(stream, server, &peer.to_string()).await;
                    });
                }
                Err(_) => tokio::time::sleep(Duration::from_millis(5)).await,
            }
        }
    });
    Ok((bound, handle))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn human_size_examples() {
        assert_eq!(human_size(0), "0 B");
        assert_eq!(human_size(512), "512 B");
        assert_eq!(human_size(1024), "1.0 KiB");
        assert_eq!(human_size(1536), "1.5 KiB");
        assert_eq!(human_size(1_400_000_000), "1.3 GiB");
    }

    #[test]
    fn escaping() {
        assert_eq!(esc("a<b>&\"'"), "a&lt;b&gt;&amp;&quot;&#x27;");
        assert_eq!(xml_text("a&b<c>\u{0}"), "a&amp;b&lt;c&gt;");
    }

    // -- pure render tests: filters, pager, totals, escaping ----------------

    fn stats() -> Stats {
        Stats {
            torrents: 1234,
            files: 9,
            total_size: 9_876_543_210,
            discovered: 0,
            pending: 0,
            blocked_infohash: 0,
            blocked_keyword: 0,
            hybrid_v2: 0,
            spam_flagged: 0,
        }
    }

    fn row(name: &str) -> SearchResult {
        SearchResult {
            infohash: "aa".repeat(20),
            name: name.to_string(),
            total_size: 1_400_000_000,
            file_count: 3,
            piece_count: 5340,
            seen_count: 7,
            last_seen: 1_600_000_000,
            category: "video".into(),
            version: "v1".into(),
            infohash_v2: None,
            tags: "res:1080p".into(),
            magnet: "magnet:?xt=urn:btih:aa".into(),
            dup_count: 1,
            alt_infohashes: Vec::new(),
        }
    }

    fn page_html(query: &str, rows: &[SearchResult], f: &UiFilters, page: Page) -> String {
        String::from_utf8(render_results(
            query,
            rows,
            &stats(),
            &Swarm::new(),
            f,
            page,
        ))
        .unwrap()
    }

    #[test]
    fn css_has_every_reference_rule() {
        // Byte-for-byte parity with the Python `PAGE_CSS` is pinned in
        // `tests/xcheck_search.rs`; these are the two rules that were missing.
        assert_eq!(PAGE_CSS.len(), 1427);
        assert_eq!(PAGE_CSS.matches('}').count(), 26);
        assert!(PAGE_CSS.contains("\n.filters{margin:.4rem 0}\n"));
        assert!(PAGE_CSS.contains("\n.meta .facet{margin-right:.25rem}\n"));
    }

    #[test]
    fn renders_the_four_filter_selects() {
        let html = page_html("", &[], &UiFilters::default(), Page::default());
        for name in ["category", "min_size", "since", "order"] {
            assert!(
                html.contains(&format!("<select name={name}>")),
                "missing {name} select"
            );
        }
        assert!(html.contains("<div class=filters>"));
        // Every category is offered, plus the "any" sentinel.
        for c in CATEGORIES {
            assert!(
                html.contains(&format!("<option value='{c}'>")),
                "missing {c}"
            );
        }
        // Nothing chosen => the empty/relevance options carry `selected`.
        assert!(html.contains("<option value='' selected>Any type</option>"));
        assert!(html.contains("<option value='' selected>Any size</option>"));
        assert!(html.contains("<option value='' selected>Any time</option>"));
        assert!(html.contains("<option value='relevance' selected>Relevance</option>"));
    }

    #[test]
    fn filter_selects_reflect_the_active_filters() {
        let f = UiFilters {
            category: Some("audio".into()),
            min_size: Some(1_073_741_824),
            since: Some(604_800),
            order: Order::Size,
        };
        let html = page_html("q", &[], &f, Page::default());
        assert!(html.contains("<option value='audio' selected>Audio</option>"));
        assert!(html.contains("<option value='1073741824' selected>&gt; 1 GB</option>"));
        assert!(html.contains("<option value='604800' selected>Past week</option>"));
        assert!(html.contains("<option value='size' selected>Largest</option>"));
        // Exactly one option per select is selected.
        assert_eq!(html.matches(" selected>").count(), 4);
        // ...and the un-chosen options are NOT marked.
        assert!(html.contains("<option value='video'>Video</option>"));
        assert!(html.contains("<option value='relevance'>Relevance</option>"));
    }

    #[test]
    fn order_oldest_selects_nothing() {
        // `oldest` is a valid `order=` but has no option — the reference then
        // renders the order select with no selected option at all.
        let f = UiFilters {
            order: Order::Oldest,
            ..UiFilters::default()
        };
        let html = page_html("", &[], &f, Page::default());
        assert!(html.contains("<option value='relevance'>Relevance</option>"));
        assert_eq!(html.matches(" selected>").count(), 3); // category/min_size/since only
    }

    #[test]
    fn total_is_the_match_count_not_the_page_size() {
        let rows = vec![row("a"), row("b")];
        let page = Page {
            total: 137,
            limit: 2,
            offset: 0,
        };
        let html = page_html("ubuntu", &rows, &UiFilters::default(), page);
        assert!(html.contains("&middot; 137 match</p>"), "{html}");
        assert!(!html.contains("2 match"));
        assert!(!html.contains("shown"));
    }

    #[test]
    fn pager_absent_when_everything_fits() {
        let page = Page {
            total: 1,
            limit: 25,
            offset: 0,
        };
        let html = page_html("x", &[row("a")], &UiFilters::default(), page);
        assert!(!html.contains("class=pager"));
    }

    #[test]
    fn pager_first_page_has_next_only() {
        let page = Page {
            total: 30,
            limit: 25,
            offset: 0,
        };
        let html = page_html("ubuntu", &[], &UiFilters::default(), page);
        assert!(html.contains(
            "<div class=pager><a href='/search?q=ubuntu&limit=25&offset=25'>next &rarr;</a></div>"
        ));
        assert!(!html.contains("prev"));
    }

    #[test]
    fn pager_last_page_has_prev_only() {
        let page = Page {
            total: 42,
            limit: 25,
            offset: 25,
        };
        let html = page_html("ubuntu", &[], &UiFilters::default(), page);
        assert!(html.contains(
            "<div class=pager><a href='/search?q=ubuntu&limit=25&offset=0'>&larr; prev</a></div>"
        ));
        assert!(!html.contains("next"));
    }

    #[test]
    fn pager_preserves_query_and_filters_across_pages() {
        let f = UiFilters {
            category: Some("video".into()),
            min_size: Some(104_857_600),
            since: Some(86400),
            order: Order::Latest,
        };
        let page = Page {
            total: 100,
            limit: 10,
            offset: 10,
        };
        let html = page_html("ubuntu lts", &[], &f, page);
        let tail = "&limit=10&offset=20&category=video&min_size=104857600&since=86400&order=latest";
        assert!(
            html.contains(&format!(
                "<a href='/search?q=ubuntu%20lts{tail}'>next &rarr;</a>"
            )),
            "{html}"
        );
        assert!(html.contains(
            "<a href='/search?q=ubuntu%20lts&limit=10&offset=0&category=video\
             &min_size=104857600&since=86400&order=latest'>&larr; prev</a>"
        ));
    }

    #[test]
    fn pager_omits_relevance_order_and_empty_query() {
        let page = Page {
            total: 60,
            limit: 25,
            offset: 25,
        };
        let html = page_html("", &[], &UiFilters::default(), page);
        // No `q=` (empty) and no `order=relevance` (the default), but `offset=0`
        // is kept — matching the reference's `_qs`.
        assert!(html.contains("<a href='/search?limit=25&offset=0'>&larr; prev</a>"));
        assert!(html.contains("<a href='/search?limit=25&offset=50'>next &rarr;</a>"));
        assert!(!html.contains("order="));
    }

    #[test]
    fn hostile_query_and_category_stay_inert() {
        let f = UiFilters {
            // A hostile category can never come from `ui_filters_from` (it is
            // validated against CATEGORIES) — the renderer escapes it anyway.
            category: Some("\"><script>alert(1)</script>".into()),
            ..UiFilters::default()
        };
        let page = Page {
            total: 100,
            limit: 10,
            offset: 10,
        };
        let html = page_html(
            "<script>alert(2)</script>'\"&",
            &[row("<b>x</b>")],
            &f,
            page,
        );
        // No hostile markup survives anywhere: no tag opener, and the category
        // (which is only ever url-encoded into an href) never appears literally.
        assert!(!html.contains("<script"), "unescaped script tag: {html}");
        assert!(!html.contains("</script"), "unescaped script close: {html}");
        assert!(!html.contains("alert(1)"), "raw category: {html}");
        assert!(!html.contains("<b>x</b>"));
        // The query is escaped in the input value...
        assert!(html.contains(
            "value='&lt;script&gt;alert(2)&lt;/script&gt;&#x27;&quot;&amp;' \
             placeholder='search torrents...'"
        ));
        // ...and percent-encoded in the pager hrefs.
        assert!(html.contains("q=%3Cscript%3Ealert%282%29%3C/script%3E%27%22%26"));
        assert!(html.contains("category=%22%3E%3Cscript%3Ealert%281%29%3C/script%3E"));
    }

    #[test]
    fn browse_categories_use_the_filters_class() {
        let html = String::from_utf8(render_browse(&[("video", 3)], &[], &stats())).unwrap();
        assert!(html.contains("<h3>Categories</h3><div class=filters>"));
    }

    #[test]
    fn order_param_round_trips() {
        for o in [
            Order::Relevance,
            Order::Latest,
            Order::Oldest,
            Order::Size,
            Order::Seen,
        ] {
            assert_eq!(order_from_str(order_param(o)), o);
        }
        assert_eq!(order_from_str("bogus"), Order::Relevance);
        assert_eq!(order_from_str(""), Order::Relevance);
    }

    #[test]
    fn ui_filters_reject_an_unknown_category() {
        let mut p = Params::new();
        p.insert("category".into(), "<script>".into());
        p.insert("min_size".into(), "104857600".into());
        p.insert("since".into(), "86400".into());
        p.insert("order".into(), "latest".into());
        let f = ui_filters_from(&p);
        assert_eq!(f.category, None);
        assert_eq!(f.min_size, Some(104_857_600));
        assert_eq!(f.since, Some(86400));
        assert_eq!(f.order, Order::Latest);
    }
}
