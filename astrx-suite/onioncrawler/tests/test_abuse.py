"""Abuse-filter unit tests (host + keyword blocklist as a first-class path)."""

import os
import tempfile
import unittest

from onioncrawler.abuse import AbuseFilter, load_abuse_filter

H = "d" * 56 + ".onion"
OTHER = "e" * 56 + ".onion"


class TestAbuseFilter(unittest.TestCase):
    def test_host_blocked(self):
        af = AbuseFilter(hosts=[H])
        self.assertTrue(af.host_blocked(H))
        self.assertTrue(af.host_blocked(H.upper() + ":80"))
        self.assertFalse(af.host_blocked(OTHER))

    def test_keyword_hit_whole_token(self):
        af = AbuseFilter(keywords=["badword"])
        self.assertEqual(af.content_hit("this has BadWord here"), "badword")
        self.assertIsNone(af.content_hit("badwordly is different"))  # boundary
        self.assertIsNone(af.content_hit("nothing to see"))

    def test_page_blocked_reasons(self):
        af = AbuseFilter(hosts=[H], keywords=["illegal"])
        self.assertEqual(af.page_blocked(H, "t", "body"), f"blocked-host:{H}")
        self.assertTrue(
            af.page_blocked(OTHER, "Title", "has illegal stuff").startswith(
                "blocked-keyword:"))
        self.assertIsNone(af.page_blocked(OTHER, "clean", "clean body"))

    def test_load_from_files_ignores_comments(self):
        d = tempfile.mkdtemp()
        hp = os.path.join(d, "hosts.txt")
        kp = os.path.join(d, "kw.txt")
        with open(hp, "w") as fh:
            fh.write("# comment\n\n" + H + "\n")
        with open(kp, "w") as fh:
            fh.write("# kw list\nnastyword\n")
        af = load_abuse_filter(hp, kp)
        self.assertTrue(af.host_blocked(H))
        self.assertEqual(af.keywords, ["nastyword"])

    def test_empty_lists_block_nothing(self):
        af = AbuseFilter()
        self.assertIsNone(af.page_blocked(H, "t", "b"))


if __name__ == "__main__":
    unittest.main()
