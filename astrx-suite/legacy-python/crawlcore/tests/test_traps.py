"""crawlcore.traps: pure structural trap predicates (both calling conventions)."""

import unittest

from crawlcore import traps


class PathBasedTest(unittest.TestCase):
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

    def test_pagination_and_numericish(self):
        self.assertTrue(traps.looks_like_pagination(
            [("year", "2026"), ("month", "8")]))
        self.assertFalse(traps.looks_like_pagination(
            [("q", "search"), ("page", "2")]))
        self.assertFalse(traps.looks_like_pagination([]))
        self.assertTrue(traps.numericish("2026-08-04"))
        self.assertTrue(traps.numericish("12345"))
        self.assertFalse(traps.numericish("august"))


class CountHelperTest(unittest.TestCase):
    def test_depth(self):
        self.assertEqual(traps.depth("/"), 0)
        self.assertEqual(traps.depth("/a/b/c"), 3)

    def test_segment_repeat_max(self):
        self.assertEqual(traps.segment_repeat_max("/a/b/a/a"), 3)
        self.assertEqual(traps.segment_repeat_max("/a/b/c"), 1)
        self.assertEqual(traps.segment_repeat_max("/"), 0)

    def test_query_param_count(self):
        self.assertEqual(traps.query_param_count(""), 0)
        self.assertEqual(traps.query_param_count("a=1&b=2&c="), 3)


if __name__ == "__main__":
    unittest.main()
