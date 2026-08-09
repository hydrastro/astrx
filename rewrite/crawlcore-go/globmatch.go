// Package crawlcore is the Go port of the shared crawl library.
//
// This file is the backtracking-free, ReDoS-safe robots.txt path-glob matcher
// (a port of crawlcore/globmatch.py). A robots path pattern uses '*' as a
// wildcard and a trailing '$' as an end-anchor; matching is always anchored at
// the start of the path. It is implemented with prefix/index/suffix scans only —
// no regex, so a hostile Disallow pattern like "/a*a*a*...*$" can never hang the
// crawl. Semantics are identical to re.match of the translated pattern
// ('*' -> '.*', optional trailing '$', start-anchored).
package crawlcore

import "strings"

// MaxPatternLen bounds the pattern length as belt-and-suspenders. The matcher is
// linear regardless, but truncating keeps even pathological input trivially
// cheap. Truncating a literal only makes a Disallow prefix shorter (matching
// more, i.e. fetching less), so it can never become an accidental over-fetch.
const MaxPatternLen = 4096

// CompileGlob splits a robots path pattern into its end-anchor flag and the
// literal segments between '*' wildcards. Runs of '*' collapse to one so the
// segment list stays minimal.
func CompileGlob(pattern string) (anchored bool, segments []string) {
	if len(pattern) > MaxPatternLen {
		pattern = pattern[:MaxPatternLen]
	}
	anchored = strings.HasSuffix(pattern, "$")
	if anchored {
		pattern = pattern[:len(pattern)-1]
	}
	return anchored, strings.Split(collapseStars(pattern), "*")
}

// collapseStars replaces every run of '*' with a single '*' (linear, no regex).
func collapseStars(s string) string {
	if !strings.Contains(s, "**") {
		return s
	}
	var b strings.Builder
	b.Grow(len(s))
	prevStar := false
	for i := 0; i < len(s); i++ {
		if s[i] == '*' {
			if prevStar {
				continue
			}
			prevStar = true
		} else {
			prevStar = false
		}
		b.WriteByte(s[i])
	}
	return b.String()
}

// GlobMatch reports whether path matches the pre-split robots segments: anchored
// at the start of path, wildcards between literal segments, optional end-anchor.
func GlobMatch(segments []string, anchored bool, path string) bool {
	n := len(segments)
	if n == 1 { // no wildcard
		seg := segments[0]
		if anchored {
			return path == seg
		}
		return strings.HasPrefix(path, seg)
	}
	if !strings.HasPrefix(path, segments[0]) {
		return false
	}
	pos := len(segments[0])
	for _, seg := range segments[1 : n-1] {
		if seg == "" {
			continue
		}
		idx := strings.Index(path[pos:], seg)
		if idx == -1 {
			return false
		}
		pos += idx + len(seg)
	}
	last := segments[n-1]
	if last == "" { // pattern ended with '*' (or '*$')
		return true
	}
	if anchored {
		return strings.HasSuffix(path, last) && len(path)-len(last) >= pos
	}
	return strings.Index(path[pos:], last) != -1
}
