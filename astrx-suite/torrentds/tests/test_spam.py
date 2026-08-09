"""Fake / spam-torrent heuristics + store hide-by-default integration."""

import hashlib
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds import spam
from torrentds.metadata import TorrentMeta
from torrentds.store import Store, categorize


def make_meta(name, files, piece_len=262144, piece_count=None):
    total = sum(l for _, l in files)
    if piece_count is None:
        piece_count = max(1, total // piece_len)
    ih = hashlib.sha1(name.encode()).digest()
    return TorrentMeta(info_hash=ih, name=name, total_size=total,
                       piece_length=piece_len, piece_count=piece_count, files=files)


class TestSpamScoring(unittest.TestCase):
    def _score(self, name, files, piece_len=262144, piece_count=None,
               category=None):
        total = sum(l for _, l in files)
        if piece_count is None:
            piece_count = max(1, total // piece_len)
        if category is None:
            category = categorize(name, files)
        return spam.score(name, files, total, piece_len, piece_count, category)

    def test_clean_torrents_not_flagged(self):
        # The exact shapes used across the existing test-suite must score 0.
        clean = [
            ("Debian 12 netinst amd64 ISO", [("debian-12-netinst.iso", 660 * 1024 * 1024)]),
            ("Linux Collection", [("distros/archlinux-2024.iso", 900_000_000),
                                  ("distros/readme.txt", 1000)]),
            ("Ubuntu Desktop 24.04 LTS", [("ubuntu-24.04.iso", 5_000_000_000)]),
            ("Fedora Workstation 40", [("Fedora-40.iso", 2_000_000_000)]),
            ("Photo Bundle", [("a.jpg", 1000), ("b.jpg", 2000), ("c.jpg", 3000)]),
            ("Small Song", [("track.mp3", 4_000_000)]),
        ]
        for name, files in clean:
            s, reasons = self._score(name, files)
            self.assertLess(s, spam.DEFAULT_THRESHOLD, (name, s, reasons))

    def test_exe_in_media_flagged(self):
        s, reasons = self._score(
            "Great Movie 1080p", [("Great.Movie.1080p.mkv", 1_400_000_000),
                                  ("Setup.exe", 400_000)])
        self.assertGreaterEqual(s, spam.DEFAULT_THRESHOLD)
        self.assertTrue(any("executable" in r for r in reasons))

    def test_decoy_layout_flagged(self):
        files = [("BigFakeMovie.mkv", 2_000_000_000)]
        files += [("readme%d.txt" % i, 500) for i in range(2)]
        files += [("visit-us.url", 200), ("shortcut.lnk", 300)]
        s, reasons = self._score("Movie Pack", files)
        self.assertGreaterEqual(s, spam.DEFAULT_THRESHOLD)
        self.assertTrue(any("decoy" in r for r in reasons))

    def test_piece_mismatch_flagged(self):
        # Claims 50 GB but only 2 pieces of 256 KiB -> impossible.
        s, reasons = self._score("Impossible Size",
                                 [("huge.bin", 50 * 1024 * 1024 * 1024)],
                                 piece_len=262144, piece_count=2, category="other")
        self.assertGreaterEqual(s, spam.DEFAULT_THRESHOLD)
        self.assertTrue(any("piece_count" in r for r in reasons))

    def test_name_spam_flagged(self):
        s, reasons = self._score(
            "FREE MOVIE DOWNLOAD www.piratesite.com XXX",
            [("movie.mkv", 700_000_000)])
        self.assertGreaterEqual(s, spam.DEFAULT_THRESHOLD)
        self.assertTrue(any("name" in r for r in reasons))

    def test_config_is_tunable(self):
        files = [("Great.Movie.mkv", 1_400_000_000), ("Setup.exe", 400_000)]
        # Raising the exe weight to 0 and threshold high suppresses the flag.
        cfg = spam.SpamConfig(threshold=100.0)
        self.assertFalse(spam.is_spam("Great Movie", files,
                                      sum(l for _, l in files), 262144, 5000,
                                      "video", cfg))


class TestSpamStoreIntegration(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    def test_flagged_hidden_by_default_shown_on_request(self):
        clean = make_meta("Clean Ubuntu ISO", [("ubuntu.iso", 3_000_000_000)])
        spammy = make_meta("Malware Movie 1080p",
                           [("Movie.1080p.mkv", 1_400_000_000),
                            ("Movie.mkv", 1_000_000_000),
                            ("INSTALL.exe", 300_000)])
        self.store.store_metadata(clean)
        self.store.store_metadata(spammy)

        # Stored score reflects the flag.
        t = self.store.get_torrent(spammy.info_hash.hex())
        self.assertGreaterEqual(t["spam_score"], self.store.spam_threshold)

        # Default (store-level) search includes everything...
        self.assertEqual(len(self.store.search("")), 2)
        # ...but hiding spam drops the flagged one.
        shown = {r["name"] for r in self.store.search("", include_spam=False)}
        self.assertIn("Clean Ubuntu ISO", shown)
        self.assertNotIn("Malware Movie 1080p", shown)
        # It reappears when explicitly requested.
        shown2 = {r["name"] for r in self.store.search("", include_spam=True)}
        self.assertIn("Malware Movie 1080p", shown2)
        # And the count parity holds.
        self.assertEqual(self.store.count("", include_spam=False), 1)
        self.assertEqual(self.store.stats()["spam_flagged"], 1)


if __name__ == "__main__":
    unittest.main()
