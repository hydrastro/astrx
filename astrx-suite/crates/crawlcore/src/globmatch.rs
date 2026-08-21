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
    // Cap by CHARACTERS (Python's `pattern[:MAX_PATTERN_LEN]` counts chars, not
    // bytes) so a multi-byte pattern truncates to the same point.
    let capped = match pattern.char_indices().nth(MAX_PATTERN_LEN) {
        Some((i, _)) => &pattern[..i],
        None => pattern,
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

#[cfg(test)]
thread_local! {
    /// Scans of the path made by [`glob_match`] on this thread. Test-only:
    /// nothing in the shipped build counts, reads or allocates it.
    ///
    /// Every scan below is one forward `starts_with` / `find` / `ends_with` over
    /// what is left of the path, and no segment is ever revisited, so a matcher
    /// that behaves takes at most `segments.len()` of them — exactly one per
    /// segment it actually looks at, which is every segment but an empty
    /// trailing one. That is the whole of the ReDoS property: a backtracking
    /// matcher takes a number of them exponential in the segment count. See
    /// `pathological_is_bounded`, which pins the count exactly, so that a scan
    /// loop which stops reporting its work is a failure and not a pass.
    static GLOB_SCANS: std::cell::Cell<usize> = const { std::cell::Cell::new(0) };
}

/// Record one forward scan of the path (test builds only).
#[cfg(test)]
fn note_glob_scan() {
    GLOB_SCANS.with(|c| c.set(c.get() + 1));
}

/// Run `f` and report how many scans [`glob_match`] made while it ran. The
/// counter is thread-local, so a test using it is unaffected by the other tests
/// the harness runs beside it.
#[cfg(test)]
fn counting_glob_scans<T>(f: impl FnOnce() -> T) -> (T, usize) {
    GLOB_SCANS.with(|c| c.set(0));
    let out = f();
    (out, GLOB_SCANS.with(std::cell::Cell::get))
}

/// Report whether `path` matches the pre-split robots `segments`: anchored at the
/// start of `path`, wildcards between literal segments, optional end-anchor.
pub fn glob_match(segments: &[String], anchored: bool, path: &str) -> bool {
    // The first scan: the whole-path compare below, or the prefix check under it.
    #[cfg(test)]
    note_glob_scan();
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
        #[cfg(test)]
        note_glob_scan();
        match path[pos..].find(seg.as_str()) {
            Some(idx) => pos += idx + seg.len(),
            None => return false,
        }
    }
    let last = segments[n - 1].as_str();
    if last.is_empty() {
        return true;
    }
    #[cfg(test)]
    note_glob_scan();
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

    /// `/a*a*a*…$` against 500 `a`s is the input a backtracking matcher dies on:
    /// 51 segments, and a regex engine explores a number of paths exponential in
    /// that. What is asserted is the number of scans of the path, because that
    /// is the property — one per segment, none of them ever repeated.
    ///
    /// This was `elapsed().as_millis() < 100`, the tightest wall-clock bound in
    /// the workspace, measured at 92 ms against it under load. The bound could
    /// not have been measuring the guarded failure anyway: the matcher does about
    /// 50 scans and returns in microseconds, while backtracking does not take
    /// 150 ms, it takes 2⁵⁰ steps and never returns. The 92 ms was a scheduler
    /// preemption, i.e. the test was one quantum from failing on a fact about the
    /// runner rather than about the matcher.
    ///
    /// The scan count bounds the whole matcher because each scan is a single
    /// forward `starts_with` / `find` / `ends_with` over what is left of the
    /// path, which the stdlib does in linear time: at most `segments.len()`
    /// scans, so at most `segments.len() * path.len()` character comparisons.
    ///
    /// The count is pinned with `assert_eq!`, not bounded from above, because an
    /// upper bound alone is satisfied by zero. Two mutations make that concrete,
    /// and both pass `scans <= segments.len()`:
    ///
    /// * delete the two `note_glob_scan` calls inside the scan loops and leave
    ///   the one at the top of `glob_match`: `scans` becomes 1 and the counter no
    ///   longer tracks the loop whose repetitions are the ReDoS risk. Nothing
    ///   else notices — it is not dead code and clippy is silent under
    ///   `-D warnings`.
    /// * replace the matcher with a backtracking one that reports no scans at
    ///   all: `scans` becomes 0.
    ///
    /// `segments.len() - 1` rather than `segments.len()`: the pattern ends in
    /// `*`, so the last segment is empty and `glob_match` returns before the
    /// third scan site. The documented `segments.len()` is a correct bound, but
    /// this input never reaches it, so asserting it would be asserting slack.
    #[test]
    fn pathological_is_bounded() {
        let (anchored, segments) = compile_glob(&format!("/{}$", "a*".repeat(50)));
        let path = format!("/{}!", "a".repeat(500));
        let (matched, scans) = counting_glob_scans(|| glob_match(&segments, anchored, &path));
        // The pattern ends in `*`, so the last segment is empty and everything
        // after the final `a` matches — including the trailing `!`.
        assert!(matched);
        // 51 segments: `/a`, then 49 × `a`, then the empty one after the final
        // `*`. One prefix scan plus one `find` per interior segment = 50.
        assert_eq!(
            segments.len(),
            51,
            "the input this test's count is taken over"
        );
        assert_eq!(
            scans,
            segments.len() - 1,
            "matching {} segments took {scans} scans of the path, not {}: either \
             the matcher is revisiting segments (which is where the exponent \
             comes from) or a scan is no longer counted, in which case this test \
             is no longer watching the loop it exists to bound",
            segments.len(),
            segments.len() - 1
        );
    }

    #[test]
    fn pattern_length_capped() {
        let (_, segments) = compile_glob(&format!("/{}", "a".repeat(100_000)));
        assert!(segments[0].len() <= MAX_PATTERN_LEN + 1);
    }
}
