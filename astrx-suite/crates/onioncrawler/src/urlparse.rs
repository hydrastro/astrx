//! A faithful, dependency-free port of the slice of Python's `urllib.parse` (and
//! `posixpath.normpath`) that the canonicalizer and robots parser rely on.
//!
//! These reproduce CPython's exact behaviour — scheme/netloc splitting, RFC-3986
//! reference resolution (`urljoin`), percent `quote`/`unquote` (uppercase hex on
//! encode, `errors="replace"` on decode), `parse_qsl`/`urlencode` with
//! `+`↔space, and the `normpath` dot-segment collapse including its leading
//! double-slash special case — so `canonical.rs` can be cross-checked
//! byte-identical to the Python reference. Only the subset actually used is
//! implemented; unimplemented corners (e.g. `;params`) are noted at call sites.

// --- percent-encoding -------------------------------------------------------

#[inline]
fn hex_upper(nibble: u8) -> u8 {
    match nibble {
        0..=9 => b'0' + nibble,
        _ => b'A' + (nibble - 10),
    }
}

#[inline]
fn hex_val(b: u8) -> Option<u8> {
    match b {
        b'0'..=b'9' => Some(b - b'0'),
        b'a'..=b'f' => Some(b - b'a' + 10),
        b'A'..=b'F' => Some(b - b'A' + 10),
        _ => None,
    }
}

/// `[A-Za-z0-9_.-~]` — CPython's `quote` always-safe set.
#[inline]
fn always_safe(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.' | b'-' | b'~')
}

/// `urllib.parse.quote(s, safe=…)` — percent-encode the UTF-8 bytes of *s*,
/// leaving the always-safe set and any byte in *safe* literal. Uppercase hex.
#[must_use]
pub fn quote(s: &str, safe: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for &b in s.as_bytes() {
        if always_safe(b) || safe.as_bytes().contains(&b) {
            out.push(b as char);
        } else {
            out.push('%');
            out.push(hex_upper(b >> 4) as char);
            out.push(hex_upper(b & 0xf) as char);
        }
    }
    out
}

/// `urllib.parse.quote_plus(s)` — like [`quote`] with an empty safe set, but a
/// space becomes `+`.
#[must_use]
pub fn quote_plus(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for &b in s.as_bytes() {
        if always_safe(b) {
            out.push(b as char);
        } else if b == b' ' {
            out.push('+');
        } else {
            out.push('%');
            out.push(hex_upper(b >> 4) as char);
            out.push(hex_upper(b & 0xf) as char);
        }
    }
    out
}

/// `urllib.parse.unquote(s)` — decode `%XX` (either case) to bytes and UTF-8
/// decode the result with replacement (`errors="replace"`); invalid/incomplete
/// escapes are left literal.
#[must_use]
pub fn unquote(s: &str) -> String {
    if !s.contains('%') {
        return s.to_string();
    }
    let b = s.as_bytes();
    let mut out: Vec<u8> = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'%' && i + 3 <= b.len() {
            if let (Some(hi), Some(lo)) = (hex_val(b[i + 1]), hex_val(b[i + 2])) {
                out.push((hi << 4) | lo);
                i += 3;
                continue;
            }
        }
        out.push(b[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// `urllib.parse.unquote_plus(s)` — `+` → space, then [`unquote`].
#[must_use]
pub fn unquote_plus(s: &str) -> String {
    if !s.contains('+') {
        return unquote(s);
    }
    unquote(&s.replace('+', " "))
}

// --- query strings ----------------------------------------------------------

/// `urllib.parse.parse_qsl(qs, keep_blank_values=…)` with the default `&`
/// separator (modern CPython no longer splits on `;`). Values are
/// `unquote_plus`-decoded; empty pairs are skipped.
#[must_use]
pub fn parse_qsl(qs: &str, keep_blank_values: bool) -> Vec<(String, String)> {
    let mut out = Vec::new();
    if qs.is_empty() {
        return out;
    }
    for part in qs.split('&') {
        if part.is_empty() {
            continue;
        }
        let (k, v) = match part.split_once('=') {
            Some((a, b)) => (a, b),
            None => (part, ""),
        };
        if !v.is_empty() || keep_blank_values {
            out.push((unquote_plus(k), unquote_plus(v)));
        }
    }
    out
}

/// `urllib.parse.urlencode(pairs, doseq=True)` for scalar values —
/// `quote_plus(k)=quote_plus(v)` joined by `&`.
#[must_use]
pub fn urlencode(pairs: &[(String, String)]) -> String {
    let mut out = String::new();
    for (i, (k, v)) in pairs.iter().enumerate() {
        if i > 0 {
            out.push('&');
        }
        out.push_str(&quote_plus(k));
        out.push('=');
        out.push_str(&quote_plus(v));
    }
    out
}

// --- posixpath.normpath -----------------------------------------------------

/// `posixpath.normpath(path)` — collapse `//`→`/` and resolve `.`/`..`, keeping
/// POSIX's special case that a path beginning with exactly two slashes retains
/// them (three-or-more collapse to one).
#[must_use]
pub fn normpath(path: &str) -> String {
    if path.is_empty() {
        return ".".to_string();
    }
    let initial_slashes: usize = if path.starts_with('/') {
        if path.starts_with("//") && !path.starts_with("///") {
            2
        } else {
            1
        }
    } else {
        0
    };
    let mut comps: Vec<&str> = Vec::new();
    for comp in path.split('/') {
        if comp.is_empty() || comp == "." {
            continue;
        }
        if comp != ".."
            || (initial_slashes == 0 && comps.is_empty())
            || (comps.last() == Some(&".."))
        {
            comps.push(comp);
        } else if !comps.is_empty() {
            comps.pop();
        }
    }
    let mut result = comps.join("/");
    if initial_slashes > 0 {
        result = "/".repeat(initial_slashes) + &result;
    }
    if result.is_empty() {
        ".".to_string()
    } else {
        result
    }
}

// --- urlsplit / urlunsplit --------------------------------------------------

/// The five components of a URL, as `urllib.parse.urlsplit` returns them
/// (`;params` are not separated out — they stay in `path`).
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct SplitUrl {
    pub scheme: String,
    pub netloc: String,
    pub path: String,
    pub query: String,
    pub fragment: String,
}

#[inline]
fn is_scheme_char(c: char) -> bool {
    c.is_ascii_alphanumeric() || matches!(c, '+' | '-' | '.')
}

/// Strip the chars CPython removes/trims before parsing: tab/CR/LF anywhere, and
/// leading/trailing C0-control-or-space (code point ≤ 0x20).
fn sanitize(url: &str) -> String {
    let stripped: String = url
        .chars()
        .filter(|&c| c != '\t' && c != '\r' && c != '\n')
        .collect();
    stripped
        .trim_matches(|c: char| (c as u32) <= 0x20)
        .to_string()
}

/// `urllib.parse.urlsplit(url, scheme=default_scheme)`.
#[must_use]
pub fn urlsplit(url: &str, default_scheme: &str) -> SplitUrl {
    let url = sanitize(url);
    let mut rest = url.as_str();
    let mut scheme = default_scheme.to_string();

    // scheme: leading run of scheme-chars before the first ':'
    if let Some(i) = rest.find(':') {
        if i > 0 && rest[..i].chars().all(is_scheme_char) {
            scheme = rest[..i].to_lowercase();
            rest = &rest[i + 1..];
        }
    }

    let mut netloc = String::new();
    if let Some(after) = rest.strip_prefix("//") {
        let end = after.find(['/', '?', '#']).unwrap_or(after.len());
        netloc = after[..end].to_string();
        rest = &after[end..];
    }

    let mut fragment = String::new();
    if let Some(pos) = rest.find('#') {
        fragment = rest[pos + 1..].to_string();
        rest = &rest[..pos];
    }
    let mut query = String::new();
    if let Some(pos) = rest.find('?') {
        query = rest[pos + 1..].to_string();
        rest = &rest[..pos];
    }

    SplitUrl {
        scheme,
        netloc,
        path: rest.to_string(),
        query,
        fragment,
    }
}

/// `urllib.parse.urlunsplit((scheme, netloc, path, query, fragment))`, for the
/// http(s) case where a netloc is always present.
#[must_use]
pub fn urlunsplit(scheme: &str, netloc: &str, path: &str, query: &str, fragment: &str) -> String {
    let mut url = if !netloc.is_empty() || (!scheme.is_empty() && !path.starts_with("//")) {
        let p = if !path.is_empty() && !path.starts_with('/') {
            format!("/{path}")
        } else {
            path.to_string()
        };
        format!("//{netloc}{p}")
    } else {
        path.to_string()
    };
    if !scheme.is_empty() {
        url = format!("{scheme}:{url}");
    }
    if !query.is_empty() {
        url = format!("{url}?{query}");
    }
    if !fragment.is_empty() {
        url = format!("{url}#{fragment}");
    }
    url
}

/// `(hostname, port_string)` from a netloc — userinfo and IPv6 brackets removed,
/// hostname lowercased. `port_string` is `None` when there is no `:port`.
#[must_use]
pub fn host_port(netloc: &str) -> (String, Option<String>) {
    let hostinfo = match netloc.rfind('@') {
        Some(i) => &netloc[i + 1..],
        None => netloc,
    };
    if let Some(after_lb) = hostinfo.strip_prefix('[') {
        // IPv6 literal — host between the brackets, optional :port after ']'
        let (host, tail) = match after_lb.find(']') {
            Some(e) => (&after_lb[..e], &after_lb[e + 1..]),
            None => (after_lb, ""),
        };
        let port = tail.strip_prefix(':').map(str::to_string);
        (host.to_lowercase(), port)
    } else {
        match hostinfo.rfind(':') {
            Some(i) => (
                hostinfo[..i].to_lowercase(),
                Some(hostinfo[i + 1..].to_string()),
            ),
            None => (hostinfo.to_lowercase(), None),
        }
    }
}

// --- urljoin (RFC 3986 §5.3, CPython's algorithm) ---------------------------

/// `urllib.parse.urljoin(base, url)` for hierarchical (http/https/relative)
/// schemes. `;params` are not separated (folded into path); onion paths don't
/// use them, and the corpus confirms parity.
#[must_use]
pub fn urljoin(base: &str, url: &str) -> String {
    if base.is_empty() {
        return url.to_string();
    }
    if url.is_empty() {
        return base.to_string();
    }
    let b = urlsplit(base, "");
    let r = urlsplit(url, &b.scheme);

    // Only hierarchical, same-scheme references resolve; anything else is opaque.
    if r.scheme != b.scheme {
        return url.to_string();
    }
    // uses_netloc: a reference with its own authority replaces wholesale.
    if !r.netloc.is_empty() {
        return urlunsplit(&r.scheme, &r.netloc, &r.path, &r.query, &r.fragment);
    }
    let netloc = b.netloc.clone();
    if r.path.is_empty() {
        let path = b.path.clone();
        let query = if r.query.is_empty() {
            b.query.clone()
        } else {
            r.query.clone()
        };
        return urlunsplit(&r.scheme, &netloc, &path, &query, &r.fragment);
    }

    // Merge paths.
    let segments: Vec<&str> = if r.path.starts_with('/') {
        r.path.split('/').collect()
    } else {
        let mut base_parts: Vec<&str> = b.path.split('/').collect();
        if base_parts.last() != Some(&"") {
            base_parts.pop();
        }
        let rel_parts: Vec<&str> = r.path.split('/').collect();
        let mut merged: Vec<&str> = base_parts;
        merged.extend(rel_parts);
        // segments[1:-1] = filter(None, segments[1:-1]) — drop empty interior parts.
        if merged.len() > 2 {
            let first = merged[0];
            let last = merged[merged.len() - 1];
            let mut interior: Vec<&str> = merged[1..merged.len() - 1]
                .iter()
                .copied()
                .filter(|s| !s.is_empty())
                .collect();
            let mut out = Vec::with_capacity(interior.len() + 2);
            out.push(first);
            out.append(&mut interior);
            out.push(last);
            out
        } else {
            merged
        }
    };

    let mut resolved: Vec<&str> = Vec::new();
    for &seg in &segments {
        if seg == ".." {
            resolved.pop();
        } else if seg == "." {
            continue;
        } else {
            resolved.push(seg);
        }
    }
    if matches!(segments.last(), Some(&".") | Some(&"..")) {
        resolved.push("");
    }
    let joined = resolved.join("/");
    let path = if joined.is_empty() {
        "/".to_string()
    } else {
        joined
    };
    urlunsplit(&r.scheme, &netloc, &path, &r.query, &r.fragment)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quote_uppercases_and_respects_safe() {
        assert_eq!(quote("a b/c", "/-._~!$&'()*+,;=:@"), "a%20b/c");
        assert_eq!(quote("café", "/"), "caf%C3%A9");
        assert_eq!(quote("/x/y", ""), "%2Fx%2Fy");
    }

    #[test]
    fn unquote_roundtrips_and_replaces() {
        assert_eq!(unquote("caf%C3%A9"), "café");
        assert_eq!(unquote("%7Euser"), "~user");
        assert_eq!(unquote("%zz"), "%zz"); // invalid escape left literal
        assert_eq!(unquote_plus("a+b%20c"), "a b c");
    }

    #[test]
    fn normpath_double_slash_and_dots() {
        assert_eq!(normpath("/a/./b/../c"), "/a/c");
        assert_eq!(normpath("//a///b"), "//a/b");
        assert_eq!(normpath("/../../etc"), "/etc");
        assert_eq!(normpath("///a"), "/a");
    }

    #[test]
    fn urljoin_basics() {
        assert_eq!(urljoin("http://h/a/b", "sub/page"), "http://h/a/sub/page");
        assert_eq!(urljoin("http://h/a/b/c", "../c"), "http://h/a/c");
        assert_eq!(urljoin("http://h/a", "//h2/x"), "http://h2/x");
        assert_eq!(urljoin("http://h/a/b?q=1", ""), "http://h/a/b?q=1");
    }
}
