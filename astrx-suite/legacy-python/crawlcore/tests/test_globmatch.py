"""crawlcore.globmatch: the shared, backtracking-free robots path-glob matcher.

Equivalence to ``re.match`` of the translated pattern (``*`` -> ``.*``, optional
trailing ``$``, start-anchored) plus the ReDoS bound that motivated the linear
implementation.
"""

import re
import time
import unittest

from crawlcore import globmatch


def _match(pattern, path):
    anchored, segments = globmatch.compile_glob(pattern)
    return globmatch.glob_match(segments, anchored, path)


def _regex_ref(pattern, path):
    """The old regex translation, used ONLY as a correctness oracle here."""
    end = pattern.endswith("$")
    body = pattern[:-1] if end else pattern
    rx = "^" + "".join(".*" if c == "*" else re.escape(c) for c in body)
    rx += "$" if end else ""
    return re.match(rx, path) is not None


class GlobSemanticsTest(unittest.TestCase):
    def test_prefix(self):
        self.assertTrue(_match("/private/", "/private/x"))
        self.assertFalse(_match("/private/", "/public"))

    def test_end_anchor(self):
        self.assertTrue(_match("/*.php$", "/a/b.php"))
        self.assertFalse(_match("/*.php$", "/a/b.phpx"))

    def test_exact_anchor(self):
        self.assertTrue(_match("/x$", "/x"))
        self.assertFalse(_match("/x$", "/xy"))

    def test_star_runs_collapse(self):
        self.assertTrue(_match("/a**b", "/a-----b"))
        self.assertTrue(_match("/a**b", "/ab"))

    def test_trailing_star(self):
        self.assertTrue(_match("/a*", "/a/anything/here"))
        self.assertFalse(_match("/a*", "/b"))

    def test_matches_regex_oracle(self):
        pats = ["/", "/a", "/a/", "/*.php$", "/x$", "/a*b*c", "/a**b",
                "/p/*/q$", "*", "$", "/a*"]
        paths = ["/", "/a", "/a/b", "/a/b.php", "/x", "/xy", "/abc", "/a-b-c",
                 "/p/1/q", "/p/1/q/2", "/ab"]
        for p in pats:
            for s in paths:
                self.assertEqual(
                    _match(p, s), _regex_ref(p, s),
                    "mismatch for pattern=%r path=%r" % (p, s))


class GlobReDoSTest(unittest.TestCase):
    def test_pathological_bounded(self):
        anchored, segments = globmatch.compile_glob("/" + "a*" * 50 + "$")
        path = "/" + "a" * 500 + "!"
        start = time.perf_counter()
        globmatch.glob_match(segments, anchored, path)
        self.assertLess(time.perf_counter() - start, 0.5)

    def test_pattern_length_capped(self):
        anchored, segments = globmatch.compile_glob("/" + "a" * 100000)
        # Truncated to MAX_PATTERN_LEN; still a single (no-wildcard) segment.
        self.assertLessEqual(len(segments[0]), globmatch.MAX_PATTERN_LEN + 1)


if __name__ == "__main__":
    unittest.main()
