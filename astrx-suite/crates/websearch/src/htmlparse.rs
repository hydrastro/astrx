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
//! The structured outputs are cross-checked byte-identical to Python in
//! `tests/xcheck_htmlparse.rs` on realistic (≥200-char-body) pages, where the
//! Python `_recover` structured-data backfill is a no-op.
//!
//! **Deferred to stage 2** (documented, not yet ported): the image/video
//! verticals (`<img>`, `<video>/<source>`, known-player `<iframe>`, Open Graph /
//! Twitter cards) and the JSON-LD / inline-state SPA recovery (`_recover`), which
//! need a bounded JSON parser. On a thin (<200-char) body with recoverable
//! structured data, Python backfills the body/title/description; stage 1 does not.
//!
//! Named-entity decoding covers the common HTML set + all numeric refs (the full
//! HTML5 named table is ~2000 entries and is deliberately not reproduced); this is
//! behaviourally faithful, not bit-identical on exotic named entities.

/// The core result of [`extract`] (stage 1 — verticals/recovery are stage 2).
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

// ---- whitespace -----------------------------------------------------------

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

// ---- the handler ----------------------------------------------------------

struct Parser {
    out: Extracted,
    in_title: bool,
    title_parts: String,
    text_parts: String,
    skip: u32,
    boiler: u32,
    html_lang: Option<String>,
}

fn attr<'a>(attrs: &'a [(String, String)], key: &str) -> Option<&'a str> {
    attrs
        .iter()
        .find(|(k, _)| k == key)
        .map(|(_, v)| v.as_str())
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
        }
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
                    }
                }
            }
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
        if tag == "title" {
            self.in_title = false;
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
        if self.skip > 0 {
            return;
        }
        if self.boiler > 0 {
            return;
        }
        self.text_parts.push_str(data);
    }

    fn finish(mut self) -> Extracted {
        self.out.title = collapse_ws(&self.title_parts);
        self.out.text = collapse_ws(&self.text_parts);
        if !self.out.description.is_empty() {
            self.out.description = collapse_ws(&self.out.description);
        }
        let basis = if self.out.text.is_empty() {
            &self.out.title
        } else {
            &self.out.text
        };
        self.out.lang = Some(guess_lang(basis, self.html_lang.as_deref()));
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
                    // CDATA: consume raw content to the close tag (dropped from
                    // body — script/style are never indexed), then close.
                    let close = format!("</{name}");
                    let after = find_seq_ci(&ch, i, &close).unwrap_or(n);
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
            self_close = true;
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
            let cp = if let Some(hex) = hex {
                u32::from_str_radix(hex, 16).ok()
            } else {
                rest.parse::<u32>().ok()
            };
            cp.and_then(char::from_u32).map(String::from)
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
}
