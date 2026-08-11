//! HTML extraction — a dependency-free port of the Python `onioncrawler.extract`
//! (which uses the stdlib `html.parser`).
//!
//! Pulls the `<title>`, the visible text (with `script`/`style`/… dropped and
//! block elements forcing line breaks), the followable `<a href>` links
//! (`rel=nofollow` excluded from the link set but their anchor text kept), the
//! `<base href>`, and the robots `<meta>` directives (`noindex` / `nofollow` /
//! `none`). Charset is decoded from the Content-Type hint, then a `<meta
//! charset>`, then UTF-8, then Latin-1.
//!
//! The structured outputs (title / links / meta flags / base) are cross-checked
//! against Python in `tests/xcheck_extract.rs`. Named-entity decoding covers the
//! common HTML set + all numeric refs (the full HTML5 named table is ~2000
//! entries and is deliberately not reproduced); this is documented as
//! behaviourally faithful, not bit-identical on exotic named entities.

/// The result of [`extract_html`].
#[derive(Clone, Debug, PartialEq, Eq, Default)]
pub struct Extracted {
    pub title: String,
    pub text: String,
    pub links: Vec<String>,
    pub meta_noindex: bool,
    pub meta_nofollow: bool,
    pub base_href: Option<String>,
}

const SKIP_TAGS: &[&str] = &["script", "style", "noscript", "template", "svg"];
const BLOCK_TAGS: &[&str] = &[
    "p",
    "div",
    "br",
    "li",
    "ul",
    "ol",
    "tr",
    "table",
    "section",
    "article",
    "header",
    "footer",
    "h1",
    "h2",
    "h3",
    "h4",
    "h5",
    "h6",
    "blockquote",
    "pre",
    "hr",
    "nav",
    "aside",
];
/// Elements whose content is raw CDATA in the HTML spec (no inner tokenization).
const RAW_TEXT_TAGS: &[&str] = &["script", "style"];

/// Whitespace collapsed by the text cleaner: `[ \t\r\f\v]` (note: **not** `\n`,
/// which structures the text, nor `\u{a0}`, a non-breaking space that survives).
fn is_ws(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\r' | '\u{0c}' | '\u{0b}')
}

/// Collapse runs of `[ \t\r\f\v]` to a single space.
fn collapse_ws(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut prev_ws = false;
    for c in s.chars() {
        if is_ws(c) {
            if !prev_ws {
                out.push(' ');
            }
            prev_ws = true;
        } else {
            out.push(c);
            prev_ws = false;
        }
    }
    out
}

struct Extractor {
    title_parts: String,
    in_title: bool,
    skip_depth: u32,
    text_parts: String,
    links: Vec<String>,
    max_links: Option<usize>,
    meta_noindex: bool,
    meta_nofollow: bool,
    base_href: Option<String>,
}

impl Extractor {
    fn new(max_links: Option<usize>) -> Self {
        Extractor {
            title_parts: String::new(),
            in_title: false,
            skip_depth: 0,
            text_parts: String::new(),
            links: Vec::new(),
            max_links,
            meta_noindex: false,
            meta_nofollow: false,
            base_href: None,
        }
    }

    fn start_tag(&mut self, tag: &str, attrs: &[(String, String)]) {
        if SKIP_TAGS.contains(&tag) {
            self.skip_depth += 1;
            return;
        }
        let attr = |k: &str| attrs.iter().find(|(n, _)| n == k).map(|(_, v)| v.as_str());
        match tag {
            "title" => self.in_title = true,
            "base" => {
                if let Some(h) = attr("href") {
                    if !h.is_empty() {
                        self.base_href = Some(h.to_string());
                    }
                }
            }
            "a" => {
                if let Some(href) = attr("href") {
                    let rel = attr("rel").unwrap_or("").to_lowercase();
                    let nofollow = rel.split_whitespace().any(|r| r == "nofollow");
                    let under_cap = self.max_links.map_or(true, |m| self.links.len() < m);
                    if !href.is_empty() && !nofollow && under_cap {
                        self.links.push(href.to_string());
                    }
                }
            }
            "meta" => {
                let name = attr("name").unwrap_or("").to_lowercase();
                if name == "robots" || name == "onioncrawler" {
                    let content = attr("content").unwrap_or("").to_lowercase();
                    if content.contains("noindex") {
                        self.meta_noindex = true;
                    }
                    if content.contains("nofollow") {
                        self.meta_nofollow = true;
                    }
                    if content.contains("none") {
                        self.meta_noindex = true;
                        self.meta_nofollow = true;
                    }
                }
            }
            _ => {}
        }
        if BLOCK_TAGS.contains(&tag) {
            self.text_parts.push('\n');
        }
    }

    fn end_tag(&mut self, tag: &str) {
        if SKIP_TAGS.contains(&tag) {
            self.skip_depth = self.skip_depth.saturating_sub(1);
            return;
        }
        if tag == "title" {
            self.in_title = false;
        }
        if BLOCK_TAGS.contains(&tag) {
            self.text_parts.push('\n');
        }
    }

    fn data(&mut self, data: &str) {
        if self.skip_depth > 0 {
            return;
        }
        if self.in_title {
            self.title_parts.push_str(data);
            return;
        }
        if data.is_empty() {
            return;
        }
        if data.chars().all(char::is_whitespace) {
            self.text_parts.push(' ');
        } else {
            self.text_parts.push_str(data);
        }
    }
}

/// Parse a run of characters into an [`Extracted`]. `max_links` caps how many
/// `<a href>` links are harvested (`None` = unlimited).
fn parse(html: &str, max_links: Option<usize>) -> Extractor {
    let ch: Vec<char> = html.chars().collect();
    let n = ch.len();
    let mut i = 0;
    let mut ex = Extractor::new(max_links);
    while i < n {
        if ch[i] == '<' {
            // comment / declaration / PI
            if starts_with(&ch, i, "<!--") {
                i = find_seq(&ch, i + 4, "-->").map_or(n, |p| p + 3);
                continue;
            }
            if i + 1 < n && (ch[i + 1] == '!' || ch[i + 1] == '?') {
                i = find_char(&ch, i + 1, '>').map_or(n, |p| p + 1);
                continue;
            }
            if i + 1 < n && ch[i + 1] == '/' {
                let (name, j) = read_name(&ch, i + 2);
                let end = find_char(&ch, j, '>').map_or(n, |p| p + 1);
                if !name.is_empty() {
                    ex.end_tag(&name);
                }
                i = end;
                continue;
            }
            if i + 1 < n && is_name_start(ch[i + 1]) {
                let (name, j) = read_name(&ch, i + 1);
                let (attrs, self_close, end) = read_attrs(&ch, j);
                ex.start_tag(&name, &attrs);
                i = end;
                // Raw-text elements (script/style): consume until the close tag
                // without tokenizing their content (matches html.parser CDATA).
                if !self_close && RAW_TEXT_TAGS.contains(&name.as_str()) {
                    let close = format!("</{name}");
                    let after = find_seq_ci(&ch, i, &close).unwrap_or(n);
                    // its raw content is dropped by skip_depth; jump to the close
                    i = find_char(&ch, after, '>').map_or(n, |p| p + 1);
                    ex.end_tag(&name);
                }
                continue;
            }
            // a stray '<' that is not a tag → literal data
            let start = i;
            i += 1;
            ex.data(&decode_entities(&ch[start..i]));
            continue;
        }
        let start = i;
        while i < n && ch[i] != '<' {
            i += 1;
        }
        ex.data(&decode_entities(&ch[start..i]));
    }
    ex
}

/// Decode + parse HTML bytes into an [`Extracted`]. `max_links` caps the number
/// of `<a href>` links harvested (`None` = unlimited).
#[must_use]
pub fn extract_html(
    bytes: &[u8],
    charset_hint: Option<&str>,
    max_links: Option<usize>,
) -> Extracted {
    let text = decode(bytes, charset_hint);
    let ex = parse(&text, max_links);
    let title = collapse_ws(&ex.title_parts).trim().to_string();
    let body = clean_text(&ex.text_parts);
    Extracted {
        title,
        text: body,
        links: ex.links,
        meta_noindex: ex.meta_noindex,
        meta_nofollow: ex.meta_nofollow,
        base_href: ex.base_href,
    }
}

/// Whitespace-normalise the collected text: collapse `[ \t\r\f\v]` to a space,
/// strip each line, drop blank lines, then collapse 3+ newlines to 2 and trim.
fn clean_text(raw: &str) -> String {
    let collapsed = collapse_ws(raw);
    let lines: Vec<&str> = collapsed
        .split('\n')
        .map(str::trim)
        .filter(|l| !l.is_empty())
        .collect();
    // (empty lines already dropped, so there are no 3+ newline runs to collapse)
    lines.join("\n").trim().to_string()
}

// ------------------------------------------------------------- tokenizer bits

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

/// Parse attributes up to `>` (or `/>`). Returns (attrs, self_closing, index
/// past the `>`).
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
        // attribute name
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
                    i += 1; // closing quote
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
    if p.is_empty() || from + p.len() > ch.len() {
        return (from..=ch.len().saturating_sub(p.len().max(1))).find(|&k| starts_with(ch, k, pat));
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

// --------------------------------------------------------------- entities

/// Decode `&name;`, `&#DDD;` and `&#xHH;` character references. Unrecognised
/// sequences are left verbatim.
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
        // find the terminating ';' within a small window
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

/// The common HTML named entities (a curated subset; numeric refs cover the
/// rest). Names are case-sensitive, as in HTML.
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
        "agrave" => 'à',
        "acirc" => 'â',
        "atilde" => 'ã',
        "auml" => 'ä',
        "aring" => 'å',
        "aelig" => 'æ',
        "ccedil" => 'ç',
        "eacute" => 'é',
        "egrave" => 'è',
        "ecirc" => 'ê',
        "euml" => 'ë',
        "iacute" => 'í',
        "igrave" => 'ì',
        "icirc" => 'î',
        "iuml" => 'ï',
        "ntilde" => 'ñ',
        "oacute" => 'ó',
        "ograve" => 'ò',
        "ocirc" => 'ô',
        "otilde" => 'õ',
        "ouml" => 'ö',
        "oslash" => 'ø',
        "uacute" => 'ú',
        "ugrave" => 'ù',
        "ucirc" => 'û',
        "uuml" => 'ü',
        "yacute" => 'ý',
        "yuml" => 'ÿ',
        "szlig" => 'ß',
        "Aacute" => 'Á',
        "Eacute" => 'É',
        "Iacute" => 'Í',
        "Oacute" => 'Ó',
        "Uacute" => 'Ú',
        "Ntilde" => 'Ñ',
        "Ccedil" => 'Ç',
        "Auml" => 'Ä',
        "Ouml" => 'Ö',
        "Uuml" => 'Ü',
        _ => return None,
    })
}

// --------------------------------------------------------------- charset

/// Decode `data` to a `String`, trying the Content-Type hint, then a `<meta
/// charset>`, then UTF-8, then Latin-1 (which never fails).
fn decode(data: &[u8], charset_hint: Option<&str>) -> String {
    for enc in candidate_encodings(data, charset_hint) {
        if let Some(s) = try_decode(data, &enc) {
            return s;
        }
    }
    String::from_utf8_lossy(data).into_owned()
}

fn candidate_encodings(data: &[u8], hint: Option<&str>) -> Vec<String> {
    let mut seen: Vec<String> = Vec::new();
    let mut add = |e: &str| {
        let e = e.trim().to_lowercase();
        if !e.is_empty() && !seen.contains(&e) {
            seen.push(e);
        }
    };
    if let Some(h) = hint {
        add(h);
    }
    if let Some(cs) = meta_charset(&data[..data.len().min(2048)]) {
        add(&cs);
    }
    add("utf-8");
    add("iso-8859-1");
    seen
}

/// Scan the head for `charset=<name>` (mirrors the Python regex).
fn meta_charset(head: &[u8]) -> Option<String> {
    let needle = b"charset";
    let mut i = 0;
    while i + needle.len() < head.len() {
        if head[i..i + needle.len()].eq_ignore_ascii_case(needle) {
            let mut j = i + needle.len();
            while j < head.len()
                && (head[j] == b'=' || head[j] == b' ' || head[j] == b'"' || head[j] == b'\'')
            {
                j += 1;
            }
            let s = j;
            while j < head.len()
                && (head[j].is_ascii_alphanumeric() || head[j] == b'_' || head[j] == b'-')
            {
                j += 1;
            }
            if j > s {
                return std::str::from_utf8(&head[s..j]).ok().map(str::to_string);
            }
        }
        i += 1;
    }
    None
}

fn try_decode(data: &[u8], enc: &str) -> Option<String> {
    match enc {
        "utf-8" | "utf8" | "ascii" | "us-ascii" => {
            std::str::from_utf8(data).ok().map(str::to_string)
        }
        "iso-8859-1" | "latin-1" | "latin1" | "l1" | "cp819" => {
            Some(data.iter().map(|&b| b as char).collect())
        }
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn nofollow_keeps_text_drops_link_and_scripts() {
        let html = b"<html><head><title>T</title></head><body><p>First para.</p>\
<p>Second <a href='/x'>link</a> here.</p><script>var a=1;</script>\
<a href='http://y.onion/z' rel='nofollow'>skip</a></body></html>";
        let e = extract_html(html, None, None);
        assert_eq!(e.title, "T");
        assert_eq!(e.text, "First para.\nSecond link here.\nskip");
        assert_eq!(e.links, vec!["/x"]);
    }

    #[test]
    fn meta_robots_and_base_and_entities() {
        let html = b"<html><head><meta name='robots' content='noindex, nofollow'>\
<base href='http://b.onion/'><title>A&#233;B</title></head><body>hi</body></html>";
        let e = extract_html(html, None, None);
        assert_eq!(e.title, "AéB");
        assert!(e.meta_noindex && e.meta_nofollow);
        assert_eq!(e.base_href.as_deref(), Some("http://b.onion/"));
    }

    #[test]
    fn raw_script_with_angle_brackets() {
        // a '<' inside <script> must not be parsed as a tag
        let html = b"<body><script>if (a<b && c>d) {}</script><p>after</p></body>";
        let e = extract_html(html, None, None);
        assert_eq!(e.text, "after");
    }

    #[test]
    fn max_links_caps() {
        let html = b"<a href=/1>a</a><a href=/2>b</a><a href=/3>c</a>";
        let e = extract_html(html, None, Some(2));
        assert_eq!(e.links, vec!["/1", "/2"]);
    }

    #[test]
    fn nbsp_survives_ws_collapse() {
        let e = extract_html("<div>y&nbsp;z</div>".as_bytes(), None, None);
        assert_eq!(e.text, "y\u{a0}z");
    }
}
