//! Minimal, safe sitemap XML parser (no third-party deps).
//!
//! Handles both a `<urlset>` (page `<loc>`s) and a `<sitemapindex>` (child-sitemap
//! `<loc>`s), matching by *local* tag name so any namespace works. The crawler
//! drives fetching + bounded recursion; this module parses one document into
//! `(kind, locs)`.
//!
//! Safety: content is already byte-capped by the fetcher, and any document
//! containing a `<!DOCTYPE`/`<!ENTITY` declaration is refused before parsing —
//! shutting the door on entity-expansion ("billion laughs") and external-entity
//! (XXE) attacks without a hardened third-party parser.
//!
//! Ported from the Python `sitemap.py` (which uses `xml.etree.ElementTree`); the
//! hand-rolled parser reproduces ElementTree's observable behaviour — entity
//! decoding in text (`&amp;`/`&lt;`/…/`&#38;`/`&#x26;`), CDATA, the `el.text`
//! rule (text up to the first child element), namespace-agnostic local-name
//! matching, and treating undefined entities / mismatched or unclosed tags /
//! non-whitespace outside the root / multiple roots as parse errors (→ an empty
//! `unknown` doc). Cross-checked in `tests/xcheck_sitemap.rs`.

/// What kind of sitemap document was parsed.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SitemapKind {
    /// A `<urlset>` of page URLs.
    Urlset,
    /// A `<sitemapindex>` of child-sitemap URLs.
    Sitemapindex,
    /// Neither (or a rejected / malformed document).
    Unknown,
}

impl SitemapKind {
    /// The lowercase tag used by the Python reference.
    #[must_use]
    pub fn as_str(self) -> &'static str {
        match self {
            SitemapKind::Urlset => "urlset",
            SitemapKind::Sitemapindex => "sitemapindex",
            SitemapKind::Unknown => "unknown",
        }
    }
}

/// A parsed sitemap document: its kind and the `<loc>` URLs it contains.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SitemapDoc {
    /// `urlset` / `sitemapindex` / `unknown`.
    pub kind: SitemapKind,
    /// The `<loc>` texts, in document order, capped at `max_locs`.
    pub locs: Vec<String>,
}

impl SitemapDoc {
    fn unknown() -> Self {
        SitemapDoc {
            kind: SitemapKind::Unknown,
            locs: Vec::new(),
        }
    }
}

/// The default `<loc>` cap (mirrors the Python default).
pub const DEFAULT_MAX_LOCS: usize = 50_000;

/// Parse sitemap *body* bytes into a [`SitemapDoc`]. Never panics: on an empty
/// body, a rejected (entity-bearing) document, invalid UTF-8, or any XML
/// well-formedness error, returns an empty `unknown` doc.
#[must_use]
pub fn parse_sitemap(body: &[u8], max_locs: usize) -> SitemapDoc {
    if body.is_empty() {
        return SitemapDoc::unknown();
    }
    if has_doctype_or_entity(body) {
        return SitemapDoc::unknown(); // bomb / XXE defense
    }
    let Ok(text) = std::str::from_utf8(body) else {
        return SitemapDoc::unknown();
    };
    match parse_xml(text, max_locs) {
        Some((kind, locs)) => SitemapDoc { kind, locs },
        None => SitemapDoc::unknown(),
    }
}

/// The Python `rb"<!(?:DOCTYPE|ENTITY)"` (case-insensitive) byte pre-check.
fn has_doctype_or_entity(body: &[u8]) -> bool {
    let starts_ci =
        |s: &[u8], pat: &[u8]| s.len() >= pat.len() && s[..pat.len()].eq_ignore_ascii_case(pat);
    body.windows(2).enumerate().any(|(i, w)| {
        w == b"<!" && {
            let rest = &body[i + 2..];
            starts_ci(rest, b"DOCTYPE") || starts_ci(rest, b"ENTITY")
        }
    })
}

/// One open element on the parse stack.
struct Elem {
    qname: String,    // raw (prefixed) name, matched exactly against the end tag
    is_loc: bool,     // local name == "loc"
    child_seen: bool, // has a child element started (closing `el.text`)?
    acc: String,      // accumulated `el.text` (only used for a loc)
}

/// The XML local name (after the last `:`), lowercased — matching ElementTree's
/// `{ns}local` split plus the reference's `.lower()`.
fn local_lower(qname: &str) -> String {
    qname.rsplit(':').next().unwrap_or(qname).to_lowercase()
}

/// XML whitespace `S ::= (#x20 | #x9 | #xD | #xA)+` (NOT Unicode whitespace).
fn is_xml_space(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\r' | '\n')
}

/// `chars[i..]` starts with `pat`.
fn at(chars: &[char], i: usize, pat: &str) -> bool {
    let plen = pat.chars().count();
    i + plen <= chars.len() && chars[i..i + plen].iter().copied().eq(pat.chars())
}

/// First index ≥ `from` where `pat` begins, else `None`.
fn find_from(chars: &[char], from: usize, pat: &str) -> Option<usize> {
    let p: Vec<char> = pat.chars().collect();
    if p.is_empty() {
        return None;
    }
    (from..=chars.len().saturating_sub(p.len())).find(|&i| chars[i..i + p.len()] == p[..])
}

/// Decode XML entities in a text run; `None` on an undefined entity or bad
/// char-ref (an ElementTree parse error).
fn decode_entities(chars: &[char]) -> Option<String> {
    if !chars.contains(&'&') {
        return Some(chars.iter().collect());
    }
    let mut out = String::with_capacity(chars.len());
    let mut i = 0;
    while i < chars.len() {
        if chars[i] == '&' {
            let semi = chars[i + 1..]
                .iter()
                .position(|&c| c == ';')
                .map(|p| i + 1 + p)?;
            let ent: String = chars[i + 1..semi].iter().collect();
            let decoded = match ent.as_str() {
                "amp" => '&',
                "lt" => '<',
                "gt" => '>',
                "quot" => '"',
                "apos" => '\'',
                _ => {
                    if let Some(hex) = ent.strip_prefix("#x") {
                        char::from_u32(u32::from_str_radix(hex, 16).ok()?)?
                    } else if let Some(dec) = ent.strip_prefix('#') {
                        char::from_u32(dec.parse().ok()?)?
                    } else {
                        return None; // undefined named entity
                    }
                }
            };
            out.push(decoded);
            i = semi + 1;
        } else {
            out.push(chars[i]);
            i += 1;
        }
    }
    Some(out)
}

/// Parse a start tag at `<` (index `i`); return `(qname, self_closing, end)` where
/// `end` is the index just past `>`. Attribute values are skipped respecting
/// quotes. `None` on an unterminated tag.
fn parse_start_tag(chars: &[char], i: usize) -> Option<(String, bool, usize)> {
    let n = chars.len();
    let name_start = i + 1;
    let mut j = name_start;
    while j < n && !is_xml_space(chars[j]) && chars[j] != '>' && chars[j] != '/' {
        j += 1;
    }
    if j == name_start {
        return None; // empty name (`<>`, `< `, `</`… handled elsewhere)
    }
    let qname: String = chars[name_start..j].iter().collect();
    let mut in_quote: Option<char> = None;
    let mut prev_slash = false;
    while j < n {
        let c = chars[j];
        match in_quote {
            Some(q) => {
                if c == q {
                    in_quote = None;
                }
                prev_slash = false;
            }
            None => {
                if c == '"' || c == '\'' {
                    in_quote = Some(c);
                    prev_slash = false;
                } else if c == '>' {
                    return Some((qname, prev_slash, j + 1));
                } else {
                    prev_slash = c == '/';
                }
            }
        }
        j += 1;
    }
    None
}

/// Parse an end tag at `</` (index `i`); return `(qname, end)`.
fn parse_end_tag(chars: &[char], i: usize) -> Option<(String, usize)> {
    let n = chars.len();
    let name_start = i + 2;
    let mut j = name_start;
    while j < n && !is_xml_space(chars[j]) && chars[j] != '>' {
        j += 1;
    }
    if j == name_start {
        return None; // `</>`
    }
    let qname: String = chars[name_start..j].iter().collect();
    while j < n && is_xml_space(chars[j]) {
        j += 1;
    }
    if j < n && chars[j] == '>' {
        Some((qname, j + 1))
    } else {
        None
    }
}

/// The single-pass parser. Returns `(kind, locs)` or `None` on any error.
fn parse_xml(text: &str, max_locs: usize) -> Option<(SitemapKind, Vec<String>)> {
    let chars: Vec<char> = text.chars().collect();
    let n = chars.len();
    let mut i = 0;
    let mut stack: Vec<Elem> = Vec::new();
    let mut root_local: Option<String> = None;
    let mut finished_root = false;
    let mut locs: Vec<String> = Vec::new();

    while i < n {
        if chars[i] == '<' {
            if i + 1 >= n {
                return None;
            }
            match chars[i + 1] {
                '?' => {
                    // processing instruction / XML declaration
                    i = find_from(&chars, i + 2, "?>")? + 2;
                }
                '!' => {
                    if at(&chars, i, "<!--") {
                        i = find_from(&chars, i + 4, "-->")? + 3;
                    } else if at(&chars, i, "<![CDATA[") {
                        let end = find_from(&chars, i + 9, "]]>")?;
                        if let Some(top) = stack.last_mut() {
                            if top.is_loc && !top.child_seen {
                                top.acc.extend(&chars[i + 9..end]);
                            }
                        } else {
                            return None; // CDATA outside the root
                        }
                        i = end + 3;
                    } else {
                        return None; // other `<!…` (DOCTYPE/ENTITY already rejected)
                    }
                }
                '/' => {
                    let (qname, next) = parse_end_tag(&chars, i)?;
                    let top = stack.pop()?; // unbalanced end tag
                    if top.qname != qname {
                        return None;
                    }
                    if top.is_loc {
                        let t = top.acc.trim();
                        if !t.is_empty() && locs.len() < max_locs {
                            locs.push(t.to_string());
                        }
                    }
                    if stack.is_empty() {
                        finished_root = true;
                    }
                    i = next;
                }
                _ => {
                    let (qname, self_closing, next) = parse_start_tag(&chars, i)?;
                    let local = local_lower(&qname);
                    if let Some(parent) = stack.last_mut() {
                        parent.child_seen = true;
                    } else {
                        if finished_root {
                            return None; // a second root element
                        }
                        root_local = Some(local.clone());
                    }
                    if !self_closing {
                        stack.push(Elem {
                            qname,
                            is_loc: local == "loc",
                            child_seen: false,
                            acc: String::new(),
                        });
                    }
                    i = next;
                }
            }
        } else {
            let start = i;
            while i < n && chars[i] != '<' {
                i += 1;
            }
            let run = &chars[start..i];
            if let Some(top) = stack.last_mut() {
                let decoded = decode_entities(run)?; // validates entities everywhere
                if top.is_loc && !top.child_seen {
                    top.acc.push_str(&decoded);
                }
            } else if !run.iter().copied().all(is_xml_space) {
                return None; // non-whitespace outside the root element
            }
        }
    }

    if !stack.is_empty() {
        return None; // unclosed element(s)
    }
    let root_local = root_local?; // no root element at all
    let kind = match root_local.as_str() {
        "sitemapindex" => SitemapKind::Sitemapindex,
        "urlset" => SitemapKind::Urlset,
        _ => SitemapKind::Unknown,
    };
    Some((kind, locs))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn parse(b: &[u8]) -> SitemapDoc {
        parse_sitemap(b, DEFAULT_MAX_LOCS)
    }

    #[test]
    fn basic_urlset_and_index() {
        let d = parse(b"<urlset><url><loc>http://a.onion/</loc></url></urlset>");
        assert_eq!(d.kind, SitemapKind::Urlset);
        assert_eq!(d.locs, vec!["http://a.onion/".to_string()]);

        let d2 = parse(
            b"<sitemapindex><sitemap><loc>http://a.onion/s.xml</loc></sitemap></sitemapindex>",
        );
        assert_eq!(d2.kind, SitemapKind::Sitemapindex);
    }

    #[test]
    fn rejects_doctype_and_malformed() {
        assert_eq!(parse(b"").kind, SitemapKind::Unknown);
        assert_eq!(
            parse(b"<!DOCTYPE x><urlset></urlset>").locs,
            Vec::<String>::new()
        );
        assert_eq!(
            parse(b"<urlset><loc>x</wrong></urlset>").kind,
            SitemapKind::Unknown
        );
        assert_eq!(
            parse(b"<urlset><loc>&foo;</loc></urlset>").locs,
            Vec::<String>::new()
        );
    }
}
