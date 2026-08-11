//! HTML extraction — a dependency-free port of the Python `websearch.htmlparse`
//! (which is built on the stdlib `html.parser`).
//!
//! **Stage 1 — core extraction** (what the crawler + indexer consume): the
//! `<title>`, the meta `description`, the visible body text (with
//! `script`/`style`/`noscript`/`template`/`svg`/`math` excluded and nav/header/
//! footer/aside/form *boilerplate* text dropped — though links inside boilerplate
//! are still harvested), every followable `<a href>` (in document order), the
//! `<link rel=canonical>`, the `<base href>`, the `meta robots` directives, and a
//! coarse stop-word language guess. Whitespace is collapsed to single spaces
//! (matching the Python `\s+`→" " normalisation).
//!
//! **Stage 2 — structured-data harvesting + SPA recovery** (over the shared
//! [`crate::structured`] primitives and [`crawlcore::json`]): `<img>` metadata,
//! HTML5 `<video>`/`<source>` + known-player `<iframe>` + direct-media `<a>` +
//! Open Graph / Twitter-card + JSON-LD `VideoObject` video signals, JSON-LD
//! `ImageObject` images, and `_recover` — backfilling a thin (<200-char) body /
//! title / description from JSON-LD readable text, inline SPA state
//! (`__INITIAL_STATE__`/`__NUXT__`/…), Open Graph / Twitter, and `<noscript>`.
//! All parsing is bounded (per-blob, total-capture, node, depth, and count caps),
//! so hostile input cannot blow memory or CPU.
//!
//! The structured outputs are cross-checked byte-identical to Python in
//! `tests/xcheck_htmlparse.rs` (stage 1) and `tests/xcheck_htmlparse_stage2.rs`
//! (stage 2).
//!
//! Numeric character references are decoded exactly as Python's `html.unescape`
//! (Windows-1252 remap of the C1 range, U+FFFD for a surrogate / out-of-range /
//! `0x00`, `""` for the HTML invalid-codepoints) when semicolon-terminated.
//! Known, documented divergences (behaviourally faithful, not bit-identical):
//! *named* entities are a common-set subset — the full HTML5 table (~2000
//! entries) and the legacy no-semicolon forms (`&copy`, `&nbsp`) are not
//! reproduced; `U+001C`–`U+001F` are not treated as whitespace (Python `\s`
//! does); and a JSON-LD / state blob that only `json.loads` accepts (`NaN` /
//! `Infinity`, nesting past `crawlcore::json`'s depth-200 cap, or a lone
//! surrogate) is skipped rather than salvaged for recovery.

use crate::structured::{
    classify_player, collect_readable, extract_state_json, first_str, first_url, is_direct_media,
    iter_dicts, parse_duration, truthy, type_of, Video,
};
use crawlcore::json::{parse as json_parse, Value};

/// One harvested `<img>` / JSON-LD `ImageObject` signal (src resolved by the
/// crawler). Mirrors the Python `(raw_src, alt, title, context)` tuple.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Image {
    /// The raw `src` / `data-src` / JSON-LD URL.
    pub src: String,
    /// The `alt` text (or JSON-LD caption/name/description).
    pub alt: String,
    /// The `title` attribute.
    pub title: String,
    /// Nearby preceding body text (whitespace-collapsed).
    pub context: String,
}

/// The result of [`extract`] — stage-1 core fields plus stage-2 structured data.
#[derive(Clone, Debug, PartialEq, Eq, Default)]
pub struct Extracted {
    /// The page `<title>`, whitespace-collapsed.
    pub title: String,
    /// The meta `description`, whitespace-collapsed.
    pub description: String,
    /// The visible body text, whitespace-collapsed to single spaces.
    pub text: String,
    /// Outbound `<a href>` strings, in document order (resolved by the crawler).
    pub links: Vec<String>,
    /// `<link rel=canonical href>`, if present.
    pub canonical: Option<String>,
    /// `<base href>`, if present.
    pub base_href: Option<String>,
    /// The guessed two-letter language code.
    pub lang: Option<String>,
    /// The raw (lower-cased) `meta robots` content.
    pub meta_robots: String,
    /// Harvested images (doc order, then JSON-LD), capped.
    pub images: Vec<Image>,
    /// Harvested video signals (doc order, then JSON-LD / OG / Twitter), capped.
    pub videos: Vec<Video>,
    /// Retained Open Graph properties (allow-listed, first value wins).
    pub og: Vec<(String, String)>,
    /// Retained Twitter-card properties (allow-listed, first value wins).
    pub twitter: Vec<(String, String)>,
    /// Raw `application/ld+json` script bodies (bounded).
    pub ldjson_blobs: Vec<String>,
    /// Raw inline-state JSON strings (bounded).
    pub state_blobs: Vec<String>,
    /// Recovered `<noscript>` text chunks (bounded).
    pub noscript_parts: Vec<String>,
}

impl Extracted {
    /// True if `meta robots` requests `noindex`.
    #[must_use]
    pub fn noindex(&self) -> bool {
        self.meta_robots.contains("noindex")
    }

    /// True if `meta robots` requests `nofollow`.
    #[must_use]
    pub fn nofollow(&self) -> bool {
        self.meta_robots.contains("nofollow")
    }
}

/// Elements whose text is never indexable (excluded from body text).
const SKIP_TAGS: &[&str] = &["script", "style", "noscript", "template", "svg", "math"];
/// Boilerplate: text excluded, but links/tags inside are still processed.
const BOILER_TAGS: &[&str] = &["nav", "header", "footer", "aside", "form"];
/// Block-level elements that introduce whitespace between text runs.
const BLOCK_TAGS: &[&str] = &[
    "p",
    "div",
    "br",
    "li",
    "tr",
    "td",
    "th",
    "h1",
    "h2",
    "h3",
    "h4",
    "h5",
    "h6",
    "section",
    "article",
    "ul",
    "ol",
    "table",
    "blockquote",
    "pre",
    "hr",
];
/// Elements whose content is raw CDATA in the HTML spec (no inner tokenization).
const RAW_TEXT_TAGS: &[&str] = &["script", "style"];

// ---- stage-2 bounds (mirror the Python `_MAX_*` / `_*_MAX` caps) -----------

const MAX_IMAGES: usize = 200;
const IMG_CONTEXT: usize = 200;
const MAX_VIDEOS: usize = 200;
const MAX_BLOB_BYTES: usize = 512 * 1024;
const MAX_CAPTURE_TOTAL: usize = 2 * 1024 * 1024;
const MAX_LD_BLOBS: usize = 32;
const MAX_STATE_BLOBS: usize = 16;
const MAX_SCRIPT_SCANS: usize = 40;
const MAX_NOSCRIPT_BYTES: usize = 64 * 1024;
const RECOVER_BODY_MAX: usize = 8 * 1024;
const THIN_BODY: usize = 200;
const TITLE_MAX: usize = 300;
const DESC_MAX: usize = 500;

/// Open Graph properties retained (a fixed allow-list, so a hostile page emitting
/// thousands of distinct `og:*` keys cannot grow the dict unbounded).
const OG_KEYS: &[&str] = &[
    "og:title",
    "og:description",
    "og:site_name",
    "og:type",
    "og:url",
    "og:image",
    "og:image:url",
    "og:image:secure_url",
    "og:video",
    "og:video:url",
    "og:video:secure_url",
    "og:video:type",
];
/// Twitter-card properties retained (fixed allow-list).
const TWITTER_KEYS: &[&str] = &[
    "twitter:card",
    "twitter:title",
    "twitter:description",
    "twitter:image",
    "twitter:player",
    "twitter:player:stream",
];

// ---- language guess -------------------------------------------------------

const STOP_EN: &[&str] = &[
    "the", "and", "of", "to", "in", "a", "is", "that", "for", "it", "with", "as", "on", "are",
    "be", "this", "was", "by", "an",
];
const STOP_ES: &[&str] = &[
    "el", "la", "de", "que", "y", "en", "los", "una", "por", "con", "para", "es", "un", "las",
    "se", "no", "su", "al",
];
const STOP_FR: &[&str] = &[
    "le", "la", "de", "et", "les", "des", "une", "que", "est", "pour", "dans", "un", "du", "au",
    "en", "qui", "sur", "ne",
];
const STOP_DE: &[&str] = &[
    "der", "die", "und", "den", "von", "zu", "das", "mit", "ist", "auf", "ein", "im", "nicht",
    "eine", "als", "auch", "es", "an",
];
/// Ordered (Python dict order) so ties resolve toward the earlier language.
const STOP: &[(&str, &[&str])] = &[
    ("en", STOP_EN),
    ("es", STOP_ES),
    ("fr", STOP_FR),
    ("de", STOP_DE),
];

/// Maximal runs of Unicode letters in `s` (the Python `[^\W\d_]+` word regex).
fn alpha_runs(s: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    for c in s.chars() {
        if c.is_alphabetic() {
            cur.push(c);
        } else if !cur.is_empty() {
            out.push(std::mem::take(&mut cur));
        }
    }
    if !cur.is_empty() {
        out.push(cur);
    }
    out
}

/// Guess a two-letter language code (default `en`). An explicit `hint` (e.g. an
/// `<html lang>` / `Content-Language`) wins if it is a 2-letter alpha prefix;
/// otherwise a cheap stop-word count over the first 500 words decides. Mirrors
/// the Python `guess_lang`.
#[must_use]
pub fn guess_lang(text: &str, hint: Option<&str>) -> String {
    if let Some(h) = hint {
        let hh: String = h.trim().to_lowercase().chars().take(2).collect();
        if hh.chars().count() == 2 && hh.chars().all(char::is_alphabetic) {
            return hh;
        }
    }
    let lower = text.to_lowercase();
    let words: Vec<String> = alpha_runs(&lower).into_iter().take(500).collect();
    if words.is_empty() {
        return "en".to_string();
    }
    let mut best = "en";
    let mut best_score: i64 = -1;
    for (lang, sw) in STOP {
        let score = words.iter().filter(|w| sw.contains(&w.as_str())).count() as i64;
        if score > best_score {
            best = lang;
            best_score = score;
        }
    }
    best.to_string()
}

// ---- whitespace + code-point slicing --------------------------------------

/// Collapse every run of Unicode whitespace to a single space, then trim — the
/// Python `_WS.sub(" ", s).strip()`.
fn collapse_ws(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut prev_ws = false;
    for c in s.chars() {
        if c.is_whitespace() {
            if !prev_ws {
                out.push(' ');
            }
            prev_ws = true;
        } else {
            out.push(c);
            prev_ws = false;
        }
    }
    out.trim().to_string()
}

/// First `n` Unicode code points of `s` (the Python `s[:n]`).
fn take_chars(s: &str, n: usize) -> String {
    s.chars().take(n).collect()
}

/// Last `n` Unicode code points of `s` (the Python `s[-n:]`).
fn last_chars(s: &str, n: usize) -> String {
    let count = s.chars().count();
    if count <= n {
        s.to_string()
    } else {
        s.chars().skip(count - n).collect()
    }
}

// ---- the handler ----------------------------------------------------------

/// The kind of structured-data capture currently open.
#[derive(Clone, Copy, PartialEq, Eq)]
enum CapKind {
    Ldjson,
    StateJson,
    ScriptScan,
    Noscript,
}

struct Parser {
    out: Extracted,
    in_title: bool,
    title_parts: String,
    text_parts: String,
    skip: u32,
    boiler: u32,
    html_lang: Option<String>,
    // stage-2 state
    recent: String,
    in_video: u32,
    video_poster: String,
    cap_kind: Option<CapKind>,
    cap_tag: Option<String>,
    cap_parts: String,
    cap_len: usize,
    cap_total: usize,
    script_scans: usize,
    noscript_len: usize,
}

/// Last-wins attribute lookup — mirrors Python `dict((k.lower(), v or "") …)`,
/// which keeps the *last* value for a duplicated attribute.
fn attr<'a>(attrs: &'a [(String, String)], key: &str) -> Option<&'a str> {
    attrs
        .iter()
        .rev()
        .find(|(k, _)| k == key)
        .map(|(_, v)| v.as_str())
}

/// `og`/`twitter` are string maps with first-value-wins (`dict.setdefault`).
fn setdefault(map: &mut Vec<(String, String)>, key: &str, val: String) {
    if !map.iter().any(|(k, _)| k == key) {
        map.push((key.to_string(), val));
    }
}

fn map_get<'a>(map: &'a [(String, String)], key: &str) -> Option<&'a str> {
    map.iter().find(|(k, _)| k == key).map(|(_, v)| v.as_str())
}

/// The first present-and-truthy value among `keys` (Python `a or b or …`).
fn or_keys<'a>(node: &'a Value, keys: &[&str]) -> Option<&'a Value> {
    for k in keys {
        if let Some(v) = node.get(k) {
            if truthy(v) {
                return Some(v);
            }
        }
    }
    None
}

/// The first non-empty string among `vals` (Python string `a or b or …`).
fn first_nonempty(vals: &[String]) -> String {
    for v in vals {
        if !v.is_empty() {
            return v.clone();
        }
    }
    String::new()
}

impl Parser {
    fn new() -> Self {
        Parser {
            out: Extracted::default(),
            in_title: false,
            title_parts: String::new(),
            text_parts: String::new(),
            skip: 0,
            boiler: 0,
            html_lang: None,
            recent: String::new(),
            in_video: 0,
            video_poster: String::new(),
            cap_kind: None,
            cap_tag: None,
            cap_parts: String::new(),
            cap_len: 0,
            cap_total: 0,
            script_scans: 0,
            noscript_len: 0,
        }
    }

    fn recent_ctx(&self) -> String {
        collapse_ws(&self.recent)
    }

    fn add_video(&mut self, v: Video) {
        if self.out.videos.len() >= MAX_VIDEOS {
            return;
        }
        self.out.videos.push(v);
    }

    fn start_tag(&mut self, tag: &str, attrs: &[(String, String)]) {
        match tag {
            "html" => {
                if let Some(v) = attr(attrs, "lang") {
                    self.html_lang = Some(v.to_string());
                }
            }
            "base" => {
                if let Some(h) = attr(attrs, "href") {
                    if !h.is_empty() {
                        self.out.base_href = Some(h.to_string());
                    }
                }
            }
            "title" => self.in_title = true,
            "a" => {
                if let Some(href) = attr(attrs, "href") {
                    if !href.is_empty() {
                        self.out.links.push(href.to_string());
                        if is_direct_media(href) {
                            let video_url = href.trim().to_string();
                            let context = self.recent_ctx();
                            self.add_video(Video {
                                video_url,
                                source: "direct".into(),
                                context,
                                ..Default::default()
                            });
                        }
                    }
                }
            }
            "img" => {
                let src = attr(attrs, "src")
                    .filter(|s| !s.is_empty())
                    .or_else(|| attr(attrs, "data-src").filter(|s| !s.is_empty()))
                    .unwrap_or("")
                    .trim()
                    .to_string();
                if !src.is_empty() && self.out.images.len() < MAX_IMAGES {
                    let alt = attr(attrs, "alt").unwrap_or("").trim().to_string();
                    let title = attr(attrs, "title").unwrap_or("").trim().to_string();
                    let context = self.recent_ctx();
                    self.out.images.push(Image {
                        src,
                        alt,
                        title,
                        context,
                    });
                }
            }
            "video" => {
                self.in_video += 1;
                self.video_poster = attr(attrs, "poster").unwrap_or("").trim().to_string();
                let src = attr(attrs, "src").unwrap_or("").trim().to_string();
                if !src.is_empty() {
                    let thumbnail = self.video_poster.clone();
                    let context = self.recent_ctx();
                    self.add_video(Video {
                        video_url: src,
                        thumbnail,
                        source: "html5".into(),
                        context,
                        ..Default::default()
                    });
                }
            }
            "source" if self.in_video > 0 => {
                let src = attr(attrs, "src").unwrap_or("").trim().to_string();
                if !src.is_empty() {
                    let thumbnail = self.video_poster.clone();
                    let context = self.recent_ctx();
                    self.add_video(Video {
                        video_url: src,
                        thumbnail,
                        source: "html5".into(),
                        context,
                        ..Default::default()
                    });
                }
            }
            "iframe" => {
                let src = attr(attrs, "src").unwrap_or("").trim().to_string();
                if !src.is_empty() {
                    let (player, watch) = classify_player(&src);
                    if let Some(pl) = player {
                        let context = self.recent_ctx();
                        self.add_video(Video {
                            embed_url: src,
                            watch_url: watch.unwrap_or_default(),
                            source: pl,
                            context,
                            ..Default::default()
                        });
                    }
                }
            }
            "script" => self.begin_script_capture(attrs),
            "noscript" => self.begin_capture(CapKind::Noscript, "noscript"),
            "link" => {
                let rel = attr(attrs, "rel").unwrap_or("").to_lowercase();
                if rel.split_whitespace().any(|r| r == "canonical") {
                    if let Some(h) = attr(attrs, "href") {
                        if !h.is_empty() {
                            self.out.canonical = Some(h.to_string());
                        }
                    }
                }
            }
            "meta" => {
                let name = attr(attrs, "name").unwrap_or("").to_lowercase();
                let content = attr(attrs, "content").unwrap_or("");
                if name == "description" && !content.is_empty() {
                    if self.out.description.is_empty() {
                        self.out.description = content.trim().to_string();
                    }
                } else if name == "robots" && !content.is_empty() {
                    self.out.meta_robots = content.to_lowercase();
                } else if (name == "content-language" || name == "language") && !content.is_empty()
                {
                    if self.html_lang.is_none() {
                        self.html_lang = Some(content.to_string());
                    }
                } else if attr(attrs, "http-equiv").unwrap_or("").to_lowercase()
                    == "content-language"
                    && self.html_lang.is_none()
                    && !content.is_empty()
                {
                    self.html_lang = Some(content.to_string());
                }
                // Open Graph + Twitter cards (a separate check, not part of the
                // elif chain above) — kept to a fixed allow-list, first-wins.
                if !content.is_empty() {
                    let prop = attr(attrs, "property").unwrap_or("").to_lowercase();
                    let key: &str = if prop.is_empty() { &name } else { &prop };
                    if OG_KEYS.contains(&key) {
                        setdefault(&mut self.out.og, key, content.trim().to_string());
                    } else if TWITTER_KEYS.contains(&key) {
                        setdefault(&mut self.out.twitter, key, content.trim().to_string());
                    }
                }
            }
            _ => {}
        }
        if SKIP_TAGS.contains(&tag) {
            self.skip += 1;
        }
        if BOILER_TAGS.contains(&tag) {
            self.boiler += 1;
        }
        if BLOCK_TAGS.contains(&tag) {
            self.text_parts.push(' ');
        }
    }

    fn end_tag(&mut self, tag: &str) {
        if self.cap_kind.is_some() && self.cap_tag.as_deref() == Some(tag) {
            self.finish_capture();
        }
        if tag == "title" {
            self.in_title = false;
        }
        if tag == "video" && self.in_video > 0 {
            self.in_video -= 1;
            if self.in_video == 0 {
                self.video_poster = String::new();
            }
        }
        if SKIP_TAGS.contains(&tag) && self.skip > 0 {
            self.skip -= 1;
        }
        if BOILER_TAGS.contains(&tag) && self.boiler > 0 {
            self.boiler -= 1;
        }
        if BLOCK_TAGS.contains(&tag) {
            self.text_parts.push(' ');
        }
    }

    fn data(&mut self, data: &str) {
        if self.in_title {
            self.title_parts.push_str(data);
            return;
        }
        if self.cap_kind.is_some() {
            if self.cap_len < MAX_BLOB_BYTES && self.cap_total < MAX_CAPTURE_TOTAL {
                let room = (MAX_BLOB_BYTES - self.cap_len).min(MAX_CAPTURE_TOTAL - self.cap_total);
                let chunk: String = data.chars().take(room).collect();
                let clen = chunk.chars().count();
                self.cap_parts.push_str(&chunk);
                self.cap_len += clen;
                self.cap_total += clen;
            }
            return;
        }
        if self.skip > 0 {
            return;
        }
        // Bounded rolling tail of recent text for <img>/<video> context. Updated
        // AFTER the skip check but BEFORE the boiler check, so boilerplate text
        // feeds context but not the body.
        let mut combined = std::mem::take(&mut self.recent);
        combined.push_str(data);
        self.recent = last_chars(&combined, IMG_CONTEXT);
        if self.boiler > 0 {
            return;
        }
        self.text_parts.push_str(data);
    }

    // ---- structured-data capture ------------------------------------------

    fn begin_script_capture(&mut self, attrs: &[(String, String)]) {
        if self.cap_kind.is_some() || attr(attrs, "src").filter(|s| !s.is_empty()).is_some() {
            return; // external scripts carry no inline payload
        }
        let typ = attr(attrs, "type").unwrap_or("").trim().to_lowercase();
        if typ == "application/ld+json" {
            self.begin_capture(CapKind::Ldjson, "script");
        } else if typ == "application/json" || attr(attrs, "id").unwrap_or("") == "__NEXT_DATA__" {
            self.begin_capture(CapKind::StateJson, "script");
        } else if matches!(
            typ.as_str(),
            "" | "text/javascript"
                | "application/javascript"
                | "module"
                | "text/ecmascript"
                | "application/ecmascript"
        ) && self.script_scans < MAX_SCRIPT_SCANS
        {
            self.begin_capture(CapKind::ScriptScan, "script");
        }
    }

    fn begin_capture(&mut self, kind: CapKind, tag: &str) {
        if self.cap_kind.is_some() || self.cap_total >= MAX_CAPTURE_TOTAL {
            return;
        }
        self.cap_kind = Some(kind);
        self.cap_tag = Some(tag.to_string());
        self.cap_parts = String::new();
        self.cap_len = 0;
    }

    fn finish_capture(&mut self) {
        let buf = std::mem::take(&mut self.cap_parts);
        let kind = self.cap_kind.take();
        self.cap_tag = None;
        self.cap_len = 0;
        let Some(kind) = kind else { return };
        match kind {
            CapKind::Ldjson
                if !buf.trim().is_empty() && self.out.ldjson_blobs.len() < MAX_LD_BLOBS =>
            {
                self.out.ldjson_blobs.push(buf);
            }
            CapKind::StateJson
                if !buf.trim().is_empty() && self.out.state_blobs.len() < MAX_STATE_BLOBS =>
            {
                self.out.state_blobs.push(buf);
            }
            CapKind::ScriptScan => {
                self.script_scans += 1;
                if let Some(js) = extract_state_json(&buf) {
                    if !js.is_empty() && self.out.state_blobs.len() < MAX_STATE_BLOBS {
                        self.out.state_blobs.push(js);
                    }
                }
            }
            CapKind::Noscript
                if !buf.trim().is_empty() && self.noscript_len < MAX_NOSCRIPT_BYTES =>
            {
                let take = take_chars(&buf, MAX_NOSCRIPT_BYTES - self.noscript_len);
                self.noscript_len += take.chars().count();
                self.out.noscript_parts.push(take);
            }
            _ => {}
        }
    }

    // ---- structured-data recovery -----------------------------------------

    fn add_video_from_ldjson(&mut self, node: &Value) {
        let name = node.get("name").map(first_str).unwrap_or_default();
        let embed = node.get("embedUrl").map(first_str).unwrap_or_default();
        let content = node.get("contentUrl").map(first_str).unwrap_or_default();
        let thumb = node.get("thumbnailUrl").map(first_url).unwrap_or_default();
        let duration = match node.get("duration") {
            Some(Value::Bool(_)) => None,
            Some(Value::Num(n)) => Some(*n as i64),
            Some(Value::Int(i)) => Some(*i),
            Some(Value::Str(s)) => parse_duration(s),
            _ => None,
        };
        if !name.is_empty() || !embed.is_empty() || !content.is_empty() || !thumb.is_empty() {
            self.add_video(Video {
                video_url: content,
                embed_url: embed,
                title: name.clone(),
                thumbnail: thumb,
                source: "ld-json".into(),
                duration,
                context: name,
                ..Default::default()
            });
        }
    }

    fn add_image_from_ldjson(&mut self, node: &Value) {
        let src = or_keys(node, &["contentUrl", "url"])
            .map(first_url)
            .unwrap_or_default();
        if !src.is_empty() && self.out.images.len() < MAX_IMAGES {
            let alt = or_keys(node, &["caption", "name", "description"])
                .map(first_str)
                .unwrap_or_default();
            self.out.images.push(Image {
                src,
                alt,
                title: String::new(),
                context: String::new(),
            });
        }
    }

    fn og_get(&self, key: &str) -> String {
        map_get(&self.out.og, key)
            .map(str::trim)
            .unwrap_or("")
            .to_string()
    }

    fn tw_get(&self, key: &str) -> String {
        map_get(&self.out.twitter, key)
            .map(str::trim)
            .unwrap_or("")
            .to_string()
    }

    fn add_video_from_meta(&mut self) {
        let ogv = first_nonempty(&[
            self.og_get("og:video:secure_url"),
            self.og_get("og:video:url"),
            self.og_get("og:video"),
        ]);
        if !ogv.is_empty() {
            let title = self.og_get("og:title");
            let thumbnail = first_nonempty(&[
                self.og_get("og:image"),
                self.og_get("og:image:url"),
                self.og_get("og:image:secure_url"),
            ]);
            self.add_video(Video {
                video_url: ogv,
                title: title.clone(),
                thumbnail,
                source: "opengraph".into(),
                context: title,
                ..Default::default()
            });
        }
        let tp = self.tw_get("twitter:player");
        let ts = self.tw_get("twitter:player:stream");
        if !tp.is_empty() || !ts.is_empty() {
            let title = first_nonempty(&[self.tw_get("twitter:title"), self.og_get("og:title")]);
            let thumbnail =
                first_nonempty(&[self.tw_get("twitter:image"), self.og_get("og:image")]);
            self.add_video(Video {
                embed_url: tp,
                video_url: ts,
                title: title.clone(),
                thumbnail,
                source: "twitter".into(),
                context: title,
                ..Default::default()
            });
        }
    }

    fn recover(&mut self) {
        let mut rec_title = String::new();
        let mut rec_desc = String::new();
        let mut body_parts: Vec<String> = Vec::new();

        // 1. JSON-LD blobs (taken out so the harvesters can mutate `self`).
        let ldjson = std::mem::take(&mut self.out.ldjson_blobs);
        for blob in &ldjson {
            let parsed = match json_parse(blob) {
                Ok(p) => p,
                Err(_) => continue, // malformed JSON-LD -> skip, never crash
            };
            for node in iter_dicts(&parsed) {
                let types = type_of(node);
                if rec_title.is_empty() {
                    rec_title = or_keys(node, &["name", "headline"])
                        .map(first_str)
                        .unwrap_or_default();
                }
                if rec_desc.is_empty() {
                    rec_desc = node.get("description").map(first_str).unwrap_or_default();
                }
                for k in ["articleBody", "text"] {
                    if let Some(Value::Str(s)) = node.get(k) {
                        if !s.trim().is_empty() {
                            body_parts.push(s.trim().to_string());
                        }
                    }
                }
                if types.iter().any(|t| t == "videoobject") {
                    self.add_video_from_ldjson(node);
                }
                if types.iter().any(|t| t == "imageobject") {
                    self.add_image_from_ldjson(node);
                }
            }
        }
        self.out.ldjson_blobs = ldjson;

        // 2. Open Graph / Twitter player cards -> video vertical.
        self.add_video_from_meta();

        // 3. og/twitter title + description fallbacks.
        if rec_title.is_empty() {
            rec_title = first_nonempty(&[self.og_get("og:title"), self.tw_get("twitter:title")]);
        }
        if rec_desc.is_empty() {
            rec_desc = first_nonempty(&[
                self.og_get("og:description"),
                self.tw_get("twitter:description"),
            ]);
        }

        // 4. <noscript> text.
        if !self.out.noscript_parts.is_empty() {
            body_parts.push(self.out.noscript_parts.join(" "));
        }

        // 5. inline state blobs.
        for blob in &self.out.state_blobs {
            let parsed = match json_parse(blob) {
                Ok(p) => p,
                Err(_) => continue,
            };
            let strings = collect_readable(&parsed);
            if !strings.is_empty() {
                body_parts.push(strings.join(" "));
            }
        }

        // ---- backfill thin fields ----
        if self.out.title.is_empty() && !rec_title.is_empty() {
            self.out.title = take_chars(&collapse_ws(&rec_title), TITLE_MAX);
        }
        if self.out.description.is_empty() && !rec_desc.is_empty() {
            self.out.description = take_chars(&collapse_ws(&rec_desc), DESC_MAX);
        }
        if self.out.text.chars().count() < THIN_BODY {
            if !rec_desc.is_empty() {
                body_parts.insert(0, rec_desc);
            }
            if !rec_title.is_empty() {
                body_parts.insert(0, rec_title);
            }
            let recovered = take_chars(&collapse_ws(&body_parts.join(" ")), RECOVER_BODY_MAX);
            if !recovered.is_empty() {
                self.out.text = if self.out.text.is_empty() {
                    recovered
                } else {
                    format!("{} {}", self.out.text, recovered)
                        .trim()
                        .to_string()
                };
            }
        }
    }

    fn finish(mut self) -> Extracted {
        if self.cap_kind.is_some() {
            self.finish_capture(); // flush an unclosed capture at EOF
        }
        self.out.title = collapse_ws(&self.title_parts);
        self.out.text = collapse_ws(&self.text_parts);
        if !self.out.description.is_empty() {
            self.out.description = collapse_ws(&self.out.description);
        }
        self.recover();
        self.out.videos.truncate(MAX_VIDEOS);
        let lang = {
            let basis = if self.out.text.is_empty() {
                &self.out.title
            } else {
                &self.out.text
            };
            guess_lang(basis, self.html_lang.as_deref())
        };
        self.out.lang = Some(lang);
        self.out
    }
}

// ---- tokenizer ------------------------------------------------------------

/// Parse `html` into an [`Extracted`].
#[must_use]
pub fn extract(html: &str) -> Extracted {
    let ch: Vec<char> = html.chars().collect();
    let n = ch.len();
    let mut i = 0;
    let mut p = Parser::new();
    while i < n {
        if ch[i] == '<' {
            if starts_with(&ch, i, "<!--") {
                i = find_seq(&ch, i + 4, "-->").map_or(n, |q| q + 3);
                continue;
            }
            if starts_with(&ch, i, "<![CDATA[") {
                // A CDATA marked section ends at `]]>`, not the first `>`; Python
                // routes it to `unknown_decl` (dropped entirely).
                i = find_seq(&ch, i + 9, "]]>").map_or(n, |q| q + 3);
                continue;
            }
            if i + 1 < n && (ch[i + 1] == '!' || ch[i + 1] == '?') {
                i = find_char(&ch, i + 1, '>').map_or(n, |q| q + 1);
                continue;
            }
            if i + 1 < n && ch[i + 1] == '/' {
                let (name, j) = read_name(&ch, i + 2);
                let end = find_char(&ch, j, '>').map_or(n, |q| q + 1);
                if !name.is_empty() {
                    p.end_tag(&name);
                }
                i = end;
                continue;
            }
            if i + 1 < n && is_name_start(ch[i + 1]) {
                let (name, j) = read_name(&ch, i + 1);
                let (attrs, self_close, end) = read_attrs(&ch, j);
                p.start_tag(&name, &attrs);
                i = end;
                if self_close {
                    // handle_startendtag → start then close.
                    p.end_tag(&name);
                } else if RAW_TEXT_TAGS.contains(&name.as_str()) {
                    // CDATA: the raw (undecoded) body is routed to `data()` — the
                    // active capture keeps it (JSON-LD/state), otherwise `skip`
                    // drops it (script/style are never indexed) — then close.
                    // The end tag closes only when `</name` is followed by
                    // whitespace, `/`, `>`, or EOF (HTML5 raw-text end state), so
                    // `</scriptx>` does NOT terminate a `<script>`.
                    let close = format!("</{name}");
                    let clen = close.chars().count();
                    let mut search = i;
                    let after = loop {
                        match find_seq_ci(&ch, search, &close) {
                            Some(pos) => {
                                let closes = match ch.get(pos + clen) {
                                    None => true,
                                    Some(c) => c.is_whitespace() || *c == '/' || *c == '>',
                                };
                                if closes {
                                    break pos;
                                }
                                search = pos + 1;
                            }
                            None => break n,
                        }
                    };
                    let raw: String = ch[i..after].iter().collect();
                    p.data(&raw);
                    i = find_char(&ch, after, '>').map_or(n, |q| q + 1);
                    p.end_tag(&name);
                }
                continue;
            }
            // a stray '<' that is not a tag → literal data
            let start = i;
            i += 1;
            p.data(&decode_entities(&ch[start..i]));
            continue;
        }
        let start = i;
        while i < n && ch[i] != '<' {
            i += 1;
        }
        p.data(&decode_entities(&ch[start..i]));
    }
    p.finish()
}

fn is_name_start(c: char) -> bool {
    c.is_ascii_alphabetic()
}

fn is_name_char(c: char) -> bool {
    c.is_ascii_alphanumeric() || c == '-' || c == ':' || c == '_'
}

fn read_name(ch: &[char], mut i: usize) -> (String, usize) {
    let start = i;
    while i < ch.len() && is_name_char(ch[i]) {
        i += 1;
    }
    (ch[start..i].iter().collect::<String>().to_lowercase(), i)
}

fn read_attrs(ch: &[char], mut i: usize) -> (Vec<(String, String)>, bool, usize) {
    let n = ch.len();
    let mut attrs = Vec::new();
    let mut self_close = false;
    loop {
        while i < n && ch[i].is_whitespace() {
            i += 1;
        }
        if i >= n {
            break;
        }
        if ch[i] == '>' {
            i += 1;
            break;
        }
        if ch[i] == '/' {
            // Self-close only on a trailing `/>`; a stray `/` elsewhere in the
            // start tag is ignored (matches `html.parser`, not "any slash").
            if ch.get(i + 1) == Some(&'>') {
                self_close = true;
                i += 2;
                break;
            }
            i += 1;
            continue;
        }
        let ns = i;
        while i < n && !ch[i].is_whitespace() && ch[i] != '=' && ch[i] != '>' && ch[i] != '/' {
            i += 1;
        }
        let name: String = ch[ns..i].iter().collect::<String>().to_lowercase();
        while i < n && ch[i].is_whitespace() {
            i += 1;
        }
        let mut value = String::new();
        if i < n && ch[i] == '=' {
            i += 1;
            while i < n && ch[i].is_whitespace() {
                i += 1;
            }
            if i < n && (ch[i] == '"' || ch[i] == '\'') {
                let q = ch[i];
                i += 1;
                let vs = i;
                while i < n && ch[i] != q {
                    i += 1;
                }
                value = decode_entities(&ch[vs..i]);
                if i < n {
                    i += 1;
                }
            } else {
                let vs = i;
                while i < n && !ch[i].is_whitespace() && ch[i] != '>' {
                    i += 1;
                }
                value = decode_entities(&ch[vs..i]);
            }
        }
        if !name.is_empty() {
            attrs.push((name, value));
        }
    }
    (attrs, self_close, i)
}

fn starts_with(ch: &[char], i: usize, pat: &str) -> bool {
    pat.chars()
        .enumerate()
        .all(|(k, c)| ch.get(i + k) == Some(&c))
}

fn find_char(ch: &[char], from: usize, target: char) -> Option<usize> {
    (from..ch.len()).find(|&k| ch[k] == target)
}

fn find_seq(ch: &[char], from: usize, pat: &str) -> Option<usize> {
    let p: Vec<char> = pat.chars().collect();
    if p.is_empty() || ch.len() < p.len() {
        return None;
    }
    (from..=ch.len() - p.len()).find(|&k| ch[k..k + p.len()] == p[..])
}

fn find_seq_ci(ch: &[char], from: usize, pat: &str) -> Option<usize> {
    let p: Vec<char> = pat.to_lowercase().chars().collect();
    if p.is_empty() || from >= ch.len() || ch.len() < p.len() {
        return None;
    }
    (from..=ch.len() - p.len())
        .find(|&k| (0..p.len()).all(|j| ch[k + j].to_ascii_lowercase() == p[j]))
}

// ---- entities -------------------------------------------------------------

fn decode_entities(ch: &[char]) -> String {
    let n = ch.len();
    let mut out = String::with_capacity(n);
    let mut i = 0;
    while i < n {
        if ch[i] != '&' {
            out.push(ch[i]);
            i += 1;
            continue;
        }
        let window_end = n.min(i + 32);
        let mut semi = None;
        for (off, &c) in ch[i + 1..window_end].iter().enumerate() {
            if c == ';' {
                semi = Some(i + 1 + off);
                break;
            }
            if c == '&' || c.is_whitespace() {
                break;
            }
        }
        let Some(sc) = semi else {
            out.push('&');
            i += 1;
            continue;
        };
        let body: String = ch[i + 1..sc].iter().collect();
        let decoded = if let Some(rest) = body.strip_prefix('#') {
            let hex = rest.strip_prefix('x').or_else(|| rest.strip_prefix('X'));
            // A well-formed digit string always decodes (a too-large value
            // saturates to `u32::MAX`, which `numeric_charref` maps to U+FFFD);
            // a malformed one (no digits) is left literal.
            let cp: Option<u32> = if let Some(hex) = hex {
                if !hex.is_empty() && hex.chars().all(|c| c.is_ascii_hexdigit()) {
                    Some(u32::from_str_radix(hex, 16).unwrap_or(u32::MAX))
                } else {
                    None
                }
            } else if !rest.is_empty() && rest.chars().all(|c| c.is_ascii_digit()) {
                Some(rest.parse::<u32>().unwrap_or(u32::MAX))
            } else {
                None
            };
            cp.map(numeric_charref)
        } else {
            named_entity(&body).map(String::from)
        };
        match decoded {
            Some(s) => {
                out.push_str(&s);
                i = sc + 1;
            }
            None => {
                out.push('&');
                i += 1;
            }
        }
    }
    out
}

/// Decode a numeric character reference exactly as Python's `html.unescape`:
/// the Windows-1252 remap of the C1 range (plus `U+0000`→U+FFFD, `0x0D`→CR),
/// U+FFFD for a surrogate / out-of-range value, the empty string for the HTML
/// "invalid codepoints", otherwise the scalar itself.
fn numeric_charref(num: u32) -> String {
    if let Some(c) = win1252_remap(num) {
        return c.to_string();
    }
    if (0xD800..=0xDFFF).contains(&num) || num > 0x10FFFF {
        return "\u{fffd}".to_string();
    }
    if is_invalid_codepoint(num) {
        return String::new();
    }
    char::from_u32(num).map(String::from).unwrap_or_default()
}

/// The `html.unescape` `_invalid_charrefs` table (`U+0000`, `0x0D`, `0x80`–`0x9F`).
fn win1252_remap(num: u32) -> Option<char> {
    Some(match num {
        0x00 => '\u{fffd}',
        0x0d => '\r',
        0x80 => '\u{20ac}',
        0x81 => '\u{81}',
        0x82 => '\u{201a}',
        0x83 => '\u{192}',
        0x84 => '\u{201e}',
        0x85 => '\u{2026}',
        0x86 => '\u{2020}',
        0x87 => '\u{2021}',
        0x88 => '\u{2c6}',
        0x89 => '\u{2030}',
        0x8a => '\u{160}',
        0x8b => '\u{2039}',
        0x8c => '\u{152}',
        0x8d => '\u{8d}',
        0x8e => '\u{17d}',
        0x8f => '\u{8f}',
        0x90 => '\u{90}',
        0x91 => '\u{2018}',
        0x92 => '\u{2019}',
        0x93 => '\u{201c}',
        0x94 => '\u{201d}',
        0x95 => '\u{2022}',
        0x96 => '\u{2013}',
        0x97 => '\u{2014}',
        0x98 => '\u{2dc}',
        0x99 => '\u{2122}',
        0x9a => '\u{161}',
        0x9b => '\u{203a}',
        0x9c => '\u{153}',
        0x9d => '\u{9d}',
        0x9e => '\u{17e}',
        0x9f => '\u{178}',
        _ => return None,
    })
}

/// The `html.unescape` `_invalid_codepoints` set (returns `""`), excluding the
/// `0x80`–`0x9F` range already handled by [`win1252_remap`]: the C0 controls
/// (bar tab/LF/FF/CR), `0x7F`, and the Unicode noncharacters.
fn is_invalid_codepoint(num: u32) -> bool {
    matches!(num, 0x01..=0x08 | 0x0b | 0x0e..=0x1f | 0x7f | 0xfdd0..=0xfdef)
        || (num & 0xffff) >= 0xfffe
}

fn named_entity(name: &str) -> Option<char> {
    Some(match name {
        "amp" => '&',
        "lt" => '<',
        "gt" => '>',
        "quot" => '"',
        "apos" => '\'',
        "nbsp" => '\u{a0}',
        "copy" => '©',
        "reg" => '®',
        "trade" => '™',
        "hellip" => '…',
        "mdash" => '—',
        "ndash" => '–',
        "lsquo" => '‘',
        "rsquo" => '’',
        "ldquo" => '“',
        "rdquo" => '”',
        "bull" => '•',
        "middot" => '·',
        "deg" => '°',
        "plusmn" => '±',
        "times" => '×',
        "divide" => '÷',
        "frac12" => '½',
        "frac14" => '¼',
        "frac34" => '¾',
        "sect" => '§',
        "para" => '¶',
        "laquo" => '«',
        "raquo" => '»',
        "euro" => '€',
        "pound" => '£',
        "cent" => '¢',
        "yen" => '¥',
        "dagger" => '†',
        "Dagger" => '‡',
        "aacute" => 'á',
        "eacute" => 'é',
        "iacute" => 'í',
        "oacute" => 'ó',
        "uacute" => 'ú',
        "ntilde" => 'ñ',
        "ccedil" => 'ç',
        "auml" => 'ä',
        "ouml" => 'ö',
        "uuml" => 'ü',
        "szlig" => 'ß',
        _ => return None,
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn core_extraction() {
        let html = "<html lang=\"en\"><head><title>Hello &amp; World</title>\
<meta name=\"description\" content=\"A  test   page.\">\
<link rel=\"canonical\" href=\"http://x/canon\"><base href=\"http://x/\">\
<meta name=\"robots\" content=\"NOINDEX, nofollow\"></head>\
<body><nav><a href=\"/nav\">navlink</a>nav text here</nav>\
<p>Body paragraph one has plenty of real words to read.</p>\
<script>var a = 1 < 2;</script>\
<p>Second <a href=\"/x\">link</a> here.</p></body></html>";
        let e = extract(html);
        assert_eq!(e.title, "Hello & World");
        assert_eq!(e.description, "A test page.");
        assert_eq!(
            e.text,
            "Body paragraph one has plenty of real words to read. Second link here."
        );
        assert_eq!(e.links, vec!["/nav", "/x"]); // nav LINK kept, nav TEXT dropped
        assert_eq!(e.canonical.as_deref(), Some("http://x/canon"));
        assert_eq!(e.base_href.as_deref(), Some("http://x/"));
        assert_eq!(e.lang.as_deref(), Some("en"));
        assert!(e.noindex() && e.nofollow());
    }

    #[test]
    fn script_angle_brackets_not_tags() {
        let e = extract("<body><script>if (a<b && c>d) {}</script><p>after</p></body>");
        assert_eq!(e.text, "after");
    }

    #[test]
    fn lang_guess_from_text() {
        // no hint: stop-word scoring
        assert_eq!(guess_lang("le la de et les des une que", None), "fr");
        assert_eq!(guess_lang("the and of to in a is that", None), "en");
        // hint wins when a 2-letter alpha prefix
        assert_eq!(guess_lang("xxxxx", Some("DE-de")), "de");
        assert_eq!(guess_lang("", None), "en");
    }

    // ---- stage-2 unit tests -----------------------------------------------

    #[test]
    fn images_with_context() {
        let e = extract(
            "<body><p>Some preceding words here.</p>\
<img src=\"/a.png\" alt=\"Alt A\" title=\"Title A\">\
<img data-src=\"/b.png\"></body>",
        );
        assert_eq!(e.images.len(), 2);
        assert_eq!(e.images[0].src, "/a.png");
        assert_eq!(e.images[0].alt, "Alt A");
        assert_eq!(e.images[0].title, "Title A");
        assert_eq!(e.images[0].context, "Some preceding words here.");
        assert_eq!(e.images[1].src, "/b.png"); // data-src fallback
    }

    #[test]
    fn html5_video_and_iframe() {
        let e = extract(
            "<body><video src=\"/v.mp4\" poster=\"/p.jpg\"></video>\
<iframe src=\"https://www.youtube.com/embed/dQw4w9WgXcQ\"></iframe>\
<a href=\"/clip.webm\">dl</a></body>",
        );
        assert_eq!(e.videos.len(), 3);
        assert_eq!(e.videos[0].source, "html5");
        assert_eq!(e.videos[0].video_url, "/v.mp4");
        assert_eq!(e.videos[0].thumbnail, "/p.jpg");
        assert_eq!(e.videos[1].source, "youtube");
        assert_eq!(
            e.videos[1].watch_url,
            "https://www.youtube.com/watch?v=dQw4w9WgXcQ"
        );
        assert_eq!(e.videos[2].source, "direct"); // <a> to .webm
    }

    #[test]
    fn ldjson_video_recovery() {
        let e = extract(
            "<html><head><script type=\"application/ld+json\">\
{\"@type\":\"VideoObject\",\"name\":\"Cats\",\"contentUrl\":\"http://x/v.mp4\",\
\"duration\":\"PT1M30S\",\"description\":\"Fun\"}</script></head><body></body></html>",
        );
        assert_eq!(e.videos.len(), 1);
        assert_eq!(e.videos[0].source, "ld-json");
        assert_eq!(e.videos[0].video_url, "http://x/v.mp4");
        assert_eq!(e.videos[0].title, "Cats");
        assert_eq!(e.videos[0].duration, Some(90));
        // thin body backfilled from JSON-LD name/description
        assert!(e.title == "Cats" || e.description == "Fun");
    }

    #[test]
    fn opengraph_video_and_title() {
        let e = extract(
            "<html><head>\
<meta property=\"og:title\" content=\"OG Title\">\
<meta property=\"og:video\" content=\"http://x/og.mp4\">\
<meta property=\"og:image\" content=\"http://x/og.jpg\">\
</head><body></body></html>",
        );
        assert_eq!(e.og.len(), 3);
        assert_eq!(e.videos.len(), 1);
        assert_eq!(e.videos[0].source, "opengraph");
        assert_eq!(e.videos[0].video_url, "http://x/og.mp4");
        assert_eq!(e.videos[0].thumbnail, "http://x/og.jpg");
        assert_eq!(e.title, "OG Title"); // recovered
    }

    #[test]
    fn noscript_recovers_thin_body() {
        let e = extract(
            "<html><body><noscript>This is the noscript fallback content \
that should be recovered into the body.</noscript></body></html>",
        );
        assert!(e.text.contains("noscript fallback content"));
    }
}
