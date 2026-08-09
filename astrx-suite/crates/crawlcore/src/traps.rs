//! Pure, stateless structural bot-trap / tarpit predicates — shape checks on an
//! already-parsed path (and, for the calendar-bomb check, query pairs). The
//! stateful counters stay in each crawler's own crash-safe store. Regex-free
//! (the numeric/date checks are hand-rolled) so there is no dependency and no
//! ReDoS surface.

/// Non-empty `/`-separated segments of `path`.
pub fn path_segments(path: &str) -> Vec<&str> {
    path.split('/').filter(|s| !s.is_empty()).collect()
}

/// Number of non-empty path segments.
pub fn depth(path: &str) -> usize {
    path_segments(path).len()
}

/// True if the path has more than `max_segments` non-empty segments.
pub fn too_deep(path: &str, max_segments: usize) -> bool {
    path_segments(path).len() > max_segments
}

/// Largest number of times any single segment repeats (`/a/b/a/a` -> 3).
pub fn segment_repeat_max(path: &str) -> usize {
    use std::collections::HashMap;
    let mut counts: HashMap<&str, usize> = HashMap::new();
    let mut top = 0;
    for s in path_segments(path) {
        let c = counts.entry(s).or_insert(0);
        *c += 1;
        top = top.max(*c);
    }
    top
}

/// True if any single segment repeats more than `max_repeats` times.
pub fn repeated_segment(path: &str, max_repeats: usize) -> bool {
    use std::collections::HashMap;
    let mut counts: HashMap<&str, usize> = HashMap::new();
    for s in path_segments(path) {
        let c = counts.entry(s).or_insert(0);
        *c += 1;
        if *c > max_repeats {
            return true;
        }
    }
    false
}

/// Detect a repeating sequence of segments, e.g. `/a/b/a/b/a/b`. For cycle length
/// L in `1..=max_cycle_len`, if the path tail is the same L-gram repeated more
/// than `max_cycles` times, it is a trap.
pub fn cyclic_path(path: &str, max_cycle_len: usize, max_cycles: usize) -> bool {
    let segs = path_segments(path);
    let n = segs.len();
    for l in 1..=max_cycle_len {
        if n < l.saturating_mul(max_cycles + 1) {
            continue;
        }
        let block = &segs[n - l..];
        let mut reps = 1usize;
        let mut i = n as isize - 2 * l as isize;
        while i >= 0 && &segs[i as usize..i as usize + l] == block {
            reps += 1;
            i -= l as isize;
        }
        if reps > max_cycles {
            return true;
        }
    }
    false
}

/// Combined path-shape check (too deep OR repeated OR cyclic), using the
/// historical cyclic defaults (max_cycle_len=3, max_cycles=2).
pub fn is_path_trap(path: &str, max_segments: usize, max_repeats: usize) -> bool {
    too_deep(path, max_segments) || repeated_segment(path, max_repeats) || cyclic_path(path, 3, 2)
}

fn all_ascii_digits(s: &str) -> bool {
    !s.is_empty() && s.bytes().all(|b| b.is_ascii_digit())
}

// `^\d{4}(-\d{1,2}(-\d{1,2})?)?$` without a regex.
fn is_dateish(s: &str) -> bool {
    let parts: Vec<&str> = s.split('-').collect();
    let digs =
        |p: &str, min: usize, max: usize| p.len() >= min && p.len() <= max && all_ascii_digits(p);
    match parts.as_slice() {
        [y] => digs(y, 4, 4),
        [y, m] => digs(y, 4, 4) && digs(m, 1, 2),
        [y, m, d] => digs(y, 4, 4) && digs(m, 1, 2) && digs(d, 1, 2),
        _ => false,
    }
}

/// A single query value that looks like a counter/date (a calendar-bomb signal).
///
/// Digits are ASCII `0-9` only (deliberate: this is a crawl heuristic, not a
/// locale parser — a URL param in Arabic-Indic numerals is not a signal we act
/// on, and staying ASCII keeps the crate dependency-free). The trim also drops
/// the ASCII separator controls `\x1c-\x1f`, matching Python's `str.strip()`.
pub fn numericish(value: &str) -> bool {
    let v = value
        .trim_matches(|c: char| c.is_whitespace() || ('\u{1c}'..='\u{1f}').contains(&c))
        .to_ascii_lowercase();
    all_ascii_digits(&v) || is_dateish(&v)
}

/// True if every non-empty query value is numeric/date-ish (`page=`/`year=`).
pub fn looks_like_pagination(pairs: &[(&str, &str)]) -> bool {
    if pairs.is_empty() {
        return false;
    }
    pairs.iter().all(|(_, v)| v.is_empty() || numericish(v))
}

/// Number of parameters in a raw query string (`a=1&b=2` -> 2), keeping blank
/// values and skipping empty pairs.
pub fn query_param_count(query: &str) -> usize {
    query.split('&').filter(|p| !p.is_empty()).count()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn path_shape() {
        assert!(!too_deep("/a/b/c", 5));
        assert!(too_deep("/a/b/c/d/e/f/g", 5));
        assert!(!repeated_segment("/a/b/a/b", 2));
        assert!(repeated_segment("/a/a/a/a", 2));
        assert_eq!(segment_repeat_max("/a/b/a/a"), 3);
        assert!(cyclic_path("/a/b/a/b/a/b", 3, 2));
        assert!(!cyclic_path("/a/b/c/d", 3, 2));
    }

    #[test]
    fn numeric_and_pagination() {
        for v in ["123", "2020", "2020-01", "2020-1-2"] {
            assert!(numericish(v), "{v}");
        }
        for v in ["abc", "12a", "2020-", "20200102extra"] {
            assert!(!numericish(v), "{v}");
        }
        assert!(looks_like_pagination(&[("page", "2"), ("year", "2020")]));
        assert!(!looks_like_pagination(&[("q", "hello")]));
        assert!(!looks_like_pagination(&[]));
        assert_eq!(query_param_count("a=1&b=2"), 2);
        assert_eq!(query_param_count(""), 0);
    }
}
