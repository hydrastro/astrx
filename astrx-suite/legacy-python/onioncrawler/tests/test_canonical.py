"""(c) URL canonicalization + trap guards (path-repeat, query-explosion)."""

import unittest
from urllib.parse import parse_qsl

from onioncrawler.canonical import canonicalize
from onioncrawler import traps

H = "c" * 56 + ".onion"


class TestCanonicalization(unittest.TestCase):
    def c(self, url, base=None):
        return canonicalize(url, base=base)

    def test_lowercase_host_strip_port_fragment(self):
        cu = self.c("HTTP://" + H.upper() + ":80/Path?a=1#frag")
        self.assertEqual(cu.host, H)
        self.assertEqual(cu.scheme, "http")
        self.assertIsNone(cu.port)
        self.assertNotIn("#", cu.url)
        self.assertTrue(cu.url.startswith("http://" + H + "/Path"))

    def test_drops_tracking_params_sorts_rest(self):
        cu = self.c(f"http://{H}/x?utm_source=ml&b=2&a=1&fbclid=zz")
        self.assertEqual(cu.query, "a=1&b=2")

    def test_dot_segments_resolved(self):
        cu = self.c(f"http://{H}/a/b/../c/./d")
        self.assertEqual(cu.path, "/a/c/d")

    def test_equivalent_urls_same_canonical(self):
        a = self.c(f"http://{H}/p?x=1&y=2#top")
        b = self.c(f"http://{H.upper()}:80/p?y=2&x=1")
        self.assertEqual(a.url, b.url)

    def test_relative_resolution(self):
        base = f"http://{H}/dir/page.html"
        self.assertEqual(self.c("../other", base).url, f"http://{H}/other")
        self.assertEqual(self.c("/abs", base).url, f"http://{H}/abs")

    def test_rejects_non_onion(self):
        self.assertIsNone(self.c("http://example.com/"))
        self.assertIsNone(self.c("https://not-an-onion.example/"))
        self.assertIsNone(self.c("ftp://" + H + "/"))
        self.assertIsNone(self.c("mailto:a@b.onion"))
        self.assertIsNone(self.c("javascript:alert(1)"))

    def test_template_key_collapses_query_values(self):
        a = self.c(f"http://{H}/cal?year=2000&month=1")
        b = self.c(f"http://{H}/cal?year=2025&month=12")
        self.assertEqual(a.template_key(), b.template_key())

    def test_skeleton_collapses_numeric_and_hex(self):
        a = self.c(f"http://{H}/post/12345/x")
        b = self.c(f"http://{H}/post/99999/x")
        self.assertEqual(a.skeleton_key(), b.skeleton_key())
        self.assertIn("#", a.skeleton_key())


class TestTrapGuards(unittest.TestCase):
    def test_too_deep(self):
        self.assertFalse(traps.too_deep("/a/b/c", 5))
        self.assertTrue(traps.too_deep("/a/b/c/d/e/f/g", 5))

    def test_repeated_segment(self):
        self.assertFalse(traps.repeated_segment("/a/b/a/b", 2))
        self.assertTrue(traps.repeated_segment("/a/a/a/a", 2))

    def test_cyclic_path(self):
        self.assertFalse(traps.cyclic_path("/a/b"))
        self.assertTrue(traps.cyclic_path("/a/b/a/b/a/b"))
        self.assertTrue(traps.cyclic_path("/x/x/x/x"))

    def test_is_path_trap_combined(self):
        self.assertFalse(traps.is_path_trap("/wiki/Article", 12, 3))
        self.assertTrue(traps.is_path_trap("/loop/a/b/a/b/a/b/a/b", 12, 3))
        self.assertTrue(traps.is_path_trap("/" + "/".join(["s"] * 20), 12, 3))

    def test_pagination_detection(self):
        pairs = parse_qsl("year=2000&month=1")
        self.assertTrue(traps.looks_like_pagination(pairs))
        pairs2 = parse_qsl("q=hello&page=2")
        self.assertFalse(traps.looks_like_pagination(pairs2))
        self.assertTrue(traps.numericish("2026-08-04"))
        self.assertFalse(traps.numericish("august"))


if __name__ == "__main__":
    unittest.main()
