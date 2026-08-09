package crawlcore

import (
	"regexp"
	"strings"
)

// Pure, stateless structural bot-trap / tarpit predicates — shape checks on an
// already-parsed path (and, for the calendar-bomb check, query pairs). The
// stateful counters stay in each crawler's own crash-safe store.

var (
	reNumericish = regexp.MustCompile(`^[0-9]+$`)
	reDateish    = regexp.MustCompile(`^\d{4}(-\d{1,2}(-\d{1,2})?)?$`)
)

// PathSegments returns the non-empty '/'-separated segments of path.
func PathSegments(path string) []string {
	out := make([]string, 0, 8)
	for _, s := range strings.Split(path, "/") {
		if s != "" {
			out = append(out, s)
		}
	}
	return out
}

// Depth is the number of non-empty path segments.
func Depth(path string) int { return len(PathSegments(path)) }

// TooDeep reports whether the path has more than maxSegments non-empty segments.
func TooDeep(path string, maxSegments int) bool {
	return len(PathSegments(path)) > maxSegments
}

// SegmentRepeatMax is the largest number of times any single segment repeats
// (e.g. "/a/b/a/a" -> 3). Detects "/x/x/x/..." traps.
func SegmentRepeatMax(path string) int {
	counts := map[string]int{}
	top := 0
	for _, s := range PathSegments(path) {
		counts[s]++
		if counts[s] > top {
			top = counts[s]
		}
	}
	return top
}

// RepeatedSegment reports whether any single segment repeats more than
// maxRepeats times.
func RepeatedSegment(path string, maxRepeats int) bool {
	counts := map[string]int{}
	for _, s := range PathSegments(path) {
		counts[s]++
		if counts[s] > maxRepeats {
			return true
		}
	}
	return false
}

// CyclicPath detects a repeating sequence of segments, e.g. /a/b/a/b/a/b. For
// cycle length L in 1..maxCycleLen, if the path tail is the same L-gram repeated
// more than maxCycles times, it is a trap.
func CyclicPath(path string, maxCycleLen, maxCycles int) bool {
	segs := PathSegments(path)
	n := len(segs)
	for l := 1; l <= maxCycleLen; l++ {
		if n < l*(maxCycles+1) {
			continue
		}
		block := segs[n-l:]
		reps := 1
		for i := n - 2*l; i >= 0 && sliceEqual(segs[i:i+l], block); i -= l {
			reps++
		}
		if reps > maxCycles {
			return true
		}
	}
	return false
}

func sliceEqual(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

// IsPathTrap is the combined path-shape check (too deep OR repeated OR cyclic),
// using the historical cyclic defaults (maxCycleLen=3, maxCycles=2).
func IsPathTrap(path string, maxSegments, maxRepeats int) bool {
	return TooDeep(path, maxSegments) ||
		RepeatedSegment(path, maxRepeats) ||
		CyclicPath(path, 3, 2)
}

// Numericish reports whether a query value looks like a counter or a date
// (a calendar-bomb signal).
func Numericish(value string) bool {
	v := strings.ToLower(strings.TrimSpace(value))
	return reNumericish.MatchString(v) || reDateish.MatchString(v)
}

// LooksLikePagination reports whether every non-empty query value is
// numeric/date-ish (page=/year= explosion).
func LooksLikePagination(pairs [][2]string) bool {
	if len(pairs) == 0 {
		return false
	}
	for _, kv := range pairs {
		if kv[1] != "" && !Numericish(kv[1]) {
			return false
		}
	}
	return true
}

// QueryParamCount counts the parameters in a raw query string ("a=1&b=2" -> 2),
// keeping blank values and skipping empty pairs (parse_qsl semantics).
func QueryParamCount(query string) int {
	count := 0
	for _, part := range strings.Split(query, "&") {
		if part == "" {
			continue
		}
		count++
	}
	return count
}
