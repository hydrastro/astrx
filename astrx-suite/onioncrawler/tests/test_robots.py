"""Robots.txt parser: semantics + ReDoS regression.

The path-glob matcher is the shared, backtracking-free ``crawlcore.globmatch``
(``*`` = any run, ``$`` = end-anchor, start-anchored). A hostile robots.txt whose
Disallow pattern is ``/a*a*a*...*$`` used to compile to a backtracking regex and
could hang a crawl worker (and, holding the GIL, the whole process) for
seconds -> hours. These tests pin both the ReDoS bound and the allow/disallow/
crawl-delay semantics.
"""

import time
import unittest

from onioncrawler.robots import parse_robots, empty_rules


def _rules(body):
    return parse_robots(body)


class TestRobotsSemantics(unittest.TestCase):
    def test_prefix_disallow(self):
        r = _rules("User-agent: *\nDisallow: /secret\n")
        self.assertFalse(r.allowed("/secret"))
        self.assertFalse(r.allowed("/secret/deep/page"))
        self.assertTrue(r.allowed("/public"))
        self.assertTrue(r.allowed("/"))

    def test_wildcard_and_end_anchor(self):
        r = _rules("User-agent: *\nDisallow: /*.php$\n")
        self.assertFalse(r.allowed("/a/b.php"))     # matches * + .php$
        self.assertTrue(r.allowed("/a/b.phpx"))     # $ anchors: no match
        self.assertTrue(r.allowed("/a/b.ph"))

    def test_exact_end_anchor(self):
        r = _rules("User-agent: *\nDisallow: /x$\n")
        self.assertFalse(r.allowed("/x"))           # exact
        self.assertTrue(r.allowed("/xy"))           # longer -> no match

    def test_allow_overrides_longer(self):
        # Longest match wins; on equal length Allow wins.
        r = _rules("User-agent: *\nDisallow: /a/\nAllow: /a/b\n")
        self.assertTrue(r.allowed("/a/b"))          # Allow (len 4) > Disallow (3)
        self.assertFalse(r.allowed("/a/c"))         # only Disallow matches

    def test_empty_disallow_allows_all(self):
        r = _rules("User-agent: *\nDisallow:\n")
        self.assertTrue(r.allowed("/anything"))

    def test_crawl_delay(self):
        r = _rules("User-agent: *\nCrawl-delay: 2.5\nDisallow: /q\n")
        self.assertEqual(r.crawl_delay("onioncrawler"), 2.5)

    def test_missing_robots_allows_all(self):
        r = empty_rules()
        self.assertTrue(r.allowed("/whatever"))

    def test_most_specific_user_agent_group(self):
        r = _rules(
            "User-agent: *\nDisallow: /\n"
            "User-agent: onioncrawler\nDisallow: /private\n")
        # the specific group applies: everything but /private is allowed
        self.assertTrue(r.allowed("/open", "OnionCrawler/1.0"))
        self.assertFalse(r.allowed("/private", "OnionCrawler/1.0"))


class TestRobotsReDoS(unittest.TestCase):
    def test_pathological_pattern_is_bounded(self):
        # The classic catastrophic-backtracking family: many `a*` groups plus an
        # end anchor, matched against a long path that cannot satisfy `$`.
        pattern = "/" + "a*" * 40 + "$"
        rules = _rules("User-agent: *\nDisallow: " + pattern + "\n")
        path = "/" + "a" * 400 + "!"
        start = time.perf_counter()
        result = rules.allowed(path, "onioncrawler")
        elapsed = time.perf_counter() - start
        # Linear matcher: microseconds. The old regex took ~8.5s at 30 groups
        # and grew exponentially. A generous 0.5s bound still fails hard on any
        # reintroduced backtracking.
        self.assertLess(elapsed, 0.5,
                        "robots matcher is not linear (possible ReDoS): "
                        "%.3fs" % elapsed)
        self.assertIn(result, (True, False))

    def test_many_wildcards_no_anchor_bounded(self):
        pattern = "/" + "x*" * 60
        rules = _rules("User-agent: *\nDisallow: " + pattern + "\n")
        path = "/" + "y" * 2000
        start = time.perf_counter()
        rules.allowed(path, "onioncrawler")
        self.assertLess(time.perf_counter() - start, 0.5)


if __name__ == "__main__":
    unittest.main()
