//! URL canonicalization, joining and cheap crawler-trap heuristics — a
//! dependency-free port of the Python `websearch.canonical`.
//!
//! Two URLs that name the same resource map to the same string so the frontier
//! can dedup them: lower-case scheme + host, drop default ports (80/443) and any
//! userinfo / fragment, resolve `.`/`..` (RFC 3986 §5.2.4), collapse duplicate
//! slashes, and sort the query. Built on the shared `crawlcore::urlparse`
//! (`urlsplit`/`urlunsplit`/`urljoin`/`parse_qsl`/`urlencode`/`host_port`) and
//! `crawlcore::traps`; cross-checked byte-identical to the Python reference.

use crawlcore::traps;
use crawlcore::urlparse::{host_port, parse_qsl, urlencode, urljoin, urlsplit, urlunsplit};

fn default_port(scheme: &str) -> &'static str {
    match scheme {
        "http" => "80",
        "https" => "443",
        _ => "",
    }
}

/// Wrap an IPv6 literal in `[]` so it round-trips through `urlsplit`.
fn bracket(host: &str) -> String {
    if host.contains(':') && !host.starts_with('[') {
        format!("[{host}]")
    } else {
        host.to_string()
    }
}

/// A parsed authority port: absent, valid, or malformed (Python `ValueError`).
enum Port {
    None,
    Some(u16),
    Invalid,
}

fn parse_port(p: Option<String>) -> Port {
    match p {
        Option::None => Port::None,
        Option::Some(s) if s.is_empty() => Port::None,
        Option::Some(s) => match s.parse::<u16>() {
            Ok(v) => Port::Some(v),
            Err(_) => Port::Invalid,
        },
    }
}

/// True for absolute http/https URLs with a host.
#[must_use]
pub fn is_http_url(url: &str) -> bool {
    let s = urlsplit(url, "");
    let scheme = s.scheme.to_lowercase();
    (scheme == "http" || scheme == "https") && !host_port(&s.netloc).0.is_empty()
}

/// Lower-cased host of `url` (empty string if none).
#[must_use]
pub fn host_of(url: &str) -> String {
    host_port(&urlsplit(url, "").netloc).0
}

/// Lower-cased origin authority `host[:port]` with default ports dropped — the
/// per-origin key for the frontier, politeness and robots.txt.
#[must_use]
pub fn authority_of(url: &str) -> String {
    let s = urlsplit(url, "");
    let (host, port_str) = host_port(&s.netloc);
    if host.is_empty() {
        return String::new();
    }
    let scheme = s.scheme.to_lowercase();
    let disp = bracket(&host);
    match parse_port(port_str) {
        Port::Invalid => disp,
        Port::None => disp,
        Port::Some(p) => {
            if p.to_string() != default_port(&scheme) {
                format!("{disp}:{p}")
            } else {
                disp
            }
        }
    }
}

/// RFC 3986 §5.2.4 dot-segment removal.
fn remove_dot_segments(path: &str) -> String {
    let mut segs: Vec<String> = Vec::new();
    let mut inp = path.to_string();
    while !inp.is_empty() {
        if let Some(r) = inp.strip_prefix("../") {
            inp = r.to_string();
        } else if let Some(r) = inp.strip_prefix("./") {
            inp = r.to_string();
        } else if let Some(r) = inp.strip_prefix("/./") {
            inp = format!("/{r}");
        } else if inp == "/." {
            inp = "/".to_string();
        } else if let Some(r) = inp.strip_prefix("/../") {
            inp = format!("/{r}");
            segs.pop();
        } else if inp == "/.." {
            inp = "/".to_string();
            segs.pop();
        } else if inp == "." || inp == ".." {
            inp = String::new();
        } else {
            let idx = if let Some(rest) = inp.strip_prefix('/') {
                rest.find('/').map(|i| i + 1)
            } else {
                inp.find('/')
            };
            match idx {
                None => {
                    segs.push(std::mem::take(&mut inp));
                }
                Some(i) => {
                    segs.push(inp[..i].to_string());
                    inp = inp[i..].to_string();
                }
            }
        }
    }
    segs.concat()
}

/// Collapse runs of 2+ `/` to a single `/` (mirrors `re.sub(r"/{2,}", "/")`).
fn collapse_slashes(path: &str) -> String {
    let mut out = String::with_capacity(path.len());
    let mut prev_slash = false;
    for c in path.chars() {
        if c == '/' {
            if !prev_slash {
                out.push('/');
            }
            prev_slash = true;
        } else {
            out.push(c);
            prev_slash = false;
        }
    }
    out
}

/// The canonical form of `url` (optionally resolved against `base`). `None` for
/// non-http(s) URLs or unparseable / malformed-port input.
#[must_use]
pub fn canonicalize(url: &str, base: Option<&str>) -> Option<String> {
    let url = url.trim();
    if url.is_empty() {
        return None;
    }
    let joined;
    let url = match base {
        Some(b) if !b.is_empty() => {
            joined = urljoin(b, url);
            joined.as_str()
        }
        _ => url,
    };

    let s = urlsplit(url, "");
    let scheme = s.scheme.to_lowercase();
    if scheme != "http" && scheme != "https" {
        return None;
    }
    let (host, port_str) = host_port(&s.netloc);
    if host.is_empty() {
        return None;
    }

    let netloc = match parse_port(port_str) {
        Port::Invalid => return None,
        Port::None => bracket(&host),
        Port::Some(p) => {
            if p.to_string() != default_port(&scheme) {
                format!("{}:{}", bracket(&host), p)
            } else {
                bracket(&host)
            }
        }
    };

    let raw_path = if s.path.is_empty() { "/" } else { &s.path };
    let mut path = collapse_slashes(&remove_dot_segments(raw_path));
    if !path.starts_with('/') {
        path = format!("/{path}");
    }

    let mut pairs = parse_qsl(&s.query, true);
    pairs.sort();
    let query = urlencode(&pairs);

    Some(urlunsplit(&scheme, &netloc, &path, &query, ""))
}

/// Resolve `href` against `base` and canonicalize; `None` if unusable.
#[must_use]
pub fn join(base: &str, href: &str) -> Option<String> {
    canonicalize(href, Some(base))
}

/// Scope test. `scope_hosts` is either `None` (crawl broadly) or a set of host
/// suffixes; a URL is in scope when its host equals or is a sub-domain of one.
#[must_use]
pub fn in_scope(url: &str, scope_hosts: Option<&[String]>) -> bool {
    let Some(scope) = scope_hosts else {
        return true;
    };
    let h = host_of(url);
    if h.is_empty() {
        return false;
    }
    scope.iter().any(|d| {
        let d = d.to_lowercase();
        let d = d.trim_start_matches('.');
        h == d || h.ends_with(&format!(".{d}"))
    })
}

// ---- cheap trap heuristics (thin URL wrappers over crawlcore::traps) --------

/// Path segments of `url`.
#[must_use]
pub fn path_segments(url: &str) -> Vec<String> {
    traps::path_segments(&urlsplit(url, "").path)
        .into_iter()
        .map(str::to_string)
        .collect()
}

/// Path depth of `url`.
#[must_use]
pub fn path_depth(url: &str) -> usize {
    traps::depth(&urlsplit(url, "").path)
}

/// Largest number of times any single path segment repeats.
#[must_use]
pub fn max_segment_repeat(url: &str) -> usize {
    traps::segment_repeat_max(&urlsplit(url, "").path)
}

/// Number of query parameters in `url`.
#[must_use]
pub fn query_param_count(url: &str) -> usize {
    traps::query_param_count(&urlsplit(url, "").query)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn drops_default_port_and_sorts_query() {
        assert_eq!(
            canonicalize("HTTP://example.com:80/x?b=2&a=1", None).as_deref(),
            Some("http://example.com/x?a=1&b=2")
        );
    }

    #[test]
    fn rejects_non_http() {
        assert_eq!(canonicalize("ftp://example.com/x", None), None);
        assert_eq!(canonicalize("not a url", None), None);
    }

    #[test]
    fn scope() {
        let scope = vec!["example.com".to_string()];
        assert!(in_scope("http://a.example.com/x", Some(&scope)));
        assert!(!in_scope("http://evil.com/x", Some(&scope)));
        assert!(in_scope("http://x/y", None));
    }
}
