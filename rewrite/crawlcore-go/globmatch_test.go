package crawlcore

import (
	"regexp"
	"strings"
	"testing"
	"time"
)

func match(pattern, path string) bool {
	anchored, segments := CompileGlob(pattern)
	return GlobMatch(segments, anchored, path)
}

// regexRef is the old regex translation, used ONLY as a correctness oracle
// (mirrors the Python test's _regex_ref: '*' -> '.*', start-anchored, optional $).
func regexRef(pattern, path string) bool {
	end := strings.HasSuffix(pattern, "$")
	body := pattern
	if end {
		body = pattern[:len(pattern)-1]
	}
	var b strings.Builder
	b.WriteString("^")
	for _, c := range body {
		if c == '*' {
			b.WriteString(".*")
		} else {
			b.WriteString(regexp.QuoteMeta(string(c)))
		}
	}
	if end {
		b.WriteString("$")
	}
	return regexp.MustCompile(b.String()).MatchString(path)
}

func TestGlobSemantics(t *testing.T) {
	cases := []struct {
		pattern, path string
		want          bool
	}{
		{"/private/", "/private/x", true},
		{"/private/", "/public", false},
		{"/*.php$", "/a/b.php", true},
		{"/*.php$", "/a/b.phpx", false},
		{"/x$", "/x", true},
		{"/x$", "/xy", false},
		{"/a**b", "/a-----b", true},
		{"/a**b", "/ab", true},
		{"/a*", "/a/anything/here", true},
		{"/a*", "/b", false},
	}
	for _, c := range cases {
		if got := match(c.pattern, c.path); got != c.want {
			t.Errorf("match(%q, %q) = %v, want %v", c.pattern, c.path, got, c.want)
		}
	}
}

// Cross-check every (pattern, path) against the regex oracle — the property the
// linear matcher must preserve.
func TestMatchesRegexOracle(t *testing.T) {
	pats := []string{"/", "/a", "/a/", "/*.php$", "/x$", "/a*b*c", "/a**b", "/p/*/q$", "*", "$", "/a*"}
	paths := []string{"/", "/a", "/a/b", "/a/b.php", "/x", "/xy", "/abc", "/a-b-c", "/p/1/q", "/p/1/q/2", "/ab"}
	for _, p := range pats {
		for _, s := range paths {
			if got, want := match(p, s), regexRef(p, s); got != want {
				t.Errorf("mismatch pattern=%q path=%q: linear=%v oracle=%v", p, s, got, want)
			}
		}
	}
}

// A pathological "/a*a*...*$" pattern against a long non-matching path must stay
// fast (this is the ReDoS the linear matcher exists to prevent).
func TestPathologicalIsBounded(t *testing.T) {
	anchored, segments := CompileGlob("/" + strings.Repeat("a*", 50) + "$")
	path := "/" + strings.Repeat("a", 500) + "!"
	start := time.Now()
	GlobMatch(segments, anchored, path)
	if d := time.Since(start); d > 100*time.Millisecond {
		t.Errorf("took %v, expected < 100ms", d)
	}
}

func TestPatternLengthCapped(t *testing.T) {
	_, segments := CompileGlob("/" + strings.Repeat("a", 100000))
	if len(segments[0]) > MaxPatternLen+1 {
		t.Errorf("segment len %d exceeds cap %d", len(segments[0]), MaxPatternLen+1)
	}
}
