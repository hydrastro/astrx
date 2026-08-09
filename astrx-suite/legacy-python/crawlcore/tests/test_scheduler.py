"""crawlcore.scheduler: pure recrawl arithmetic."""

import unittest

from crawlcore import scheduler


class IsDueTest(unittest.TestCase):
    def test_due_boundary(self):
        self.assertTrue(scheduler.is_due(1000.0, 100.0, 1100.0))   # exactly due
        self.assertTrue(scheduler.is_due(1000.0, 100.0, 1200.0))
        self.assertFalse(scheduler.is_due(1000.0, 100.0, 1099.9))
        self.assertFalse(scheduler.is_due(0, 100.0, 1e9))          # never fetched

    def test_next_due(self):
        self.assertEqual(scheduler.next_due(1000.0, 250.0), 1250.0)


class BackoffTest(unittest.TestCase):
    def test_grows_and_caps(self):
        self.assertEqual(scheduler.backoff_interval(100.0, 2.0), 200.0)
        self.assertEqual(scheduler.backoff_interval(100.0, 2.0, max_interval=150.0),
                         150.0)

    def test_falls_back_to_base(self):
        self.assertEqual(scheduler.backoff_interval(0.0, 2.0, base=50.0), 100.0)

    def test_nothing_to_grow(self):
        # no current interval and no base -> leave it alone (0.0)
        self.assertEqual(scheduler.backoff_interval(None, 2.0), 0.0)


if __name__ == "__main__":
    unittest.main()
