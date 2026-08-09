//! Backtracking-free, ReDoS-safe robots.txt path-glob matcher.
//!
//! A robots path pattern uses `*` as a wildcard and a trailing `$` as an
//! end-anchor; matching is always anchored at the start of the path. It uses
//! `starts_with` / `find` / `ends_with` scans only — no regex — so a hostile
//! Disallow like `/a*a*a*...*$` can never hang the crawl. Semantics are identical
//! to `re.match` of the translated pattern (`*` -> `.*`, optional trailing `$`,
//! start-anchored).

/// Bound the pattern length as belt-and-suspenders. The matcher is linear
/// regardless; truncating a literal only makes a Disallow prefix shorter
/// (matching more, i.e. fetching less), so it can never become an over-fetch.
pub const MAX_PATTERN_LEN: usize = 4096;

/// Split a robots path pattern into its end-anchor flag and the literal segments
/// between `*` wildcards. Runs of `*` collapse to one.
pub fn compile_glob(pattern: &str) -> (bool, Vec<String>) {
    let capped = if pattern.len() > MAX_PATTERN_LEN {
        let mut end = MAX_PATTERN_LEN;
        while !pattern.is_char_boundary(end) {
            end -= 1;
        }
        &pattern[..end]
    } else {
        pattern
    };
    let anchored = capped.ends_with('$');
    let body = if anchored {
        &capped[..capped.len() - 1]
    } else {
        capped
    };
    let collapsed = collapse_stars(body);
    (anchored, collapsed.split('*').map(str::to_string).collect())
}

/// Replace every run of `*` with a single `*` (linear, no regex).
fn collapse_stars(s: &str) -> String {
    if !s.contains("**") {
        return s.to_string();
    }
    let mut out = String::with_capacity(s.len());
    let mut prev_star = false;
    for c in s.chars() {
        if c == '*' {
            if prev_star {
                continue;
            }
            prev_star = true;
        } else {
            prev_star = false;
        }
        out.push(c);
    }
    out
}

/// Report whether `path` matches the pre-split robots `segments`: anchored at the
/// start of `path`, wildcards between literal segments, optional end-anchor.
pub fn glob_match(segments: &[String], anchored: bool, path: &str) -> bool {
    let n = segments.len();
    if n == 1 {
        let seg = segments[0].as_str();
        return if anchored {
            path == seg
        } else {
            path.starts_with(seg)
        };
    }
    if !path.starts_with(segments[0].as_str()) {
        return false;
    }
    let mut pos = segments[0].len();
    for seg in &segments[1..n - 1] {
        if seg.is_empty() {
            continue;
        }
        match path[pos..].find(seg.as_str()) {
            Some(idx) => pos += idx + seg.len(),
            None => return false,
        }
    }
    let last = segments[n - 1].as_str();
    if last.is_empty() {
        return true;
    }
    if anchored {
        return path.ends_with(last) && path.len() >= last.len() && path.len() - last.len() >= pos;
    }
    path[pos..].find(last).is_some()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn m(pattern: &str, path: &str) -> bool {
        let (anchored, segs) = compile_glob(pattern);
        glob_match(&segs, anchored, path)
    }

    /// The old regex translation, used ONLY as a correctness oracle.
    fn regex_ref(pattern: &str, path: &str) -> bool {
        // Hand-rolled equivalent of re.match("^" + translate(body) + ("$"|""))
        // to avoid a regex dependency in the test.
        let end = pattern.ends_with('$');
        let body: Vec<char> = if end {
            pattern[..pattern.len() - 1].chars().collect()
        } else {
            pattern.chars().collect()
        };
        let p: Vec<char> = path.chars().collect();
        anchored_match(&body, &p, end)
    }

    // Minimal wildcard matcher (start-anchored, '*' = any run, optional end $).
    fn anchored_match(pat: &[char], s: &[char], end_anchor: bool) -> bool {
        // dp over positions; small inputs in the test, clarity over speed.
        fn go(pat: &[char], pi: usize, s: &[char], si: usize, end: bool) -> bool {
            if pi == pat.len() {
                return if end { si == s.len() } else { true };
            }
            if pat[pi] == '*' {
                for k in si..=s.len() {
                    if go(pat, pi + 1, s, k, end) {
                        return true;
                    }
                }
                return false;
            }
            if si < s.len() && pat[pi] == s[si] {
                return go(pat, pi + 1, s, si + 1, end);
            }
            false
        }
        go(pat, 0, s, 0, end_anchor)
    }

    #[test]
    fn semantics() {
        assert!(m("/private/", "/private/x"));
        assert!(!m("/private/", "/public"));
        assert!(m("/*.php$", "/a/b.php"));
        assert!(!m("/*.php$", "/a/b.phpx"));
        assert!(m("/x$", "/x"));
        assert!(!m("/x$", "/xy"));
        assert!(m("/a**b", "/a-----b"));
        assert!(m("/a**b", "/ab"));
        assert!(m("/a*", "/a/anything/here"));
        assert!(!m("/a*", "/b"));
    }

    #[test]
    fn matches_oracle() {
        let pats = [
            "/", "/a", "/a/", "/*.php$", "/x$", "/a*b*c", "/a**b", "/p/*/q$", "*", "$", "/a*",
        ];
        let paths = [
            "/", "/a", "/a/b", "/a/b.php", "/x", "/xy", "/abc", "/a-b-c", "/p/1/q", "/p/1/q/2",
            "/ab",
        ];
        for p in pats {
            for s in paths {
                assert_eq!(m(p, s), regex_ref(p, s), "pattern={p:?} path={s:?}");
            }
        }
    }

    #[test]
    fn pathological_is_bounded() {
        let (anchored, segments) = compile_glob(&format!("/{}$", "a*".repeat(50)));
        let path = format!("/{}!", "a".repeat(500));
        let start = std::time::Instant::now();
        let _ = glob_match(&segments, anchored, &path);
        assert!(start.elapsed().as_millis() < 100);
    }

    #[test]
    fn pattern_length_capped() {
        let (_, segments) = compile_glob(&format!("/{}", "a".repeat(100_000)));
        assert!(segments[0].len() <= MAX_PATTERN_LEN + 1);
    }
}
