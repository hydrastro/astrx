"""Cross-infohash dedup, hardened against layout-copy poisoning (L6).

The content signature folds in a name-independent *content* fingerprint (the v1
piece-hash blob or the v2 ``file tree`` digest), so two torrents collapse only
when they describe the SAME actual content -- not merely the same path+length
layout.  A torrent that copies a popular release's file names and sizes but
carries different content (different pieces roots / piece hashes) no longer
poisons the collapse.
"""

import hashlib
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import encode
from torrentds.metadata import TorrentMeta, parse_info
from torrentds.store import Store, content_signature

FILES = [("movie/film.mkv", 1_400_000_000), ("movie/subs.srt", 40_000)]
# Two distinct contents that share the identical path+length LAYOUT.
ROOTS_A = (b"\xaa" * 32, b"\xbb" * 32)
ROOTS_B = (b"\xcc" * 32, b"\xdd" * 32)   # same layout, DIFFERENT content
PIECES_A = b"\x01" * 40
PIECES_B = b"\x02" * 40                   # same layout, DIFFERENT content


def v2_meta(name, roots):
    """A v2 info-dict describing FILES with the given per-file pieces roots."""
    tree = {b"movie": {b"film.mkv": {b"": {b"length": FILES[0][1],
                                           b"pieces root": roots[0]}},
                       b"subs.srt": {b"": {b"length": FILES[1][1],
                                           b"pieces root": roots[1]}}}}
    info = {b"meta version": 2, b"name": name.encode(),
            b"piece length": 262144, b"file tree": tree}
    return parse_info(info, info_bytes=encode(info))


def v1_meta(name, pieces):
    """A v1 info-dict describing FILES with the given piece-hash blob."""
    info = {b"name": name.encode(), b"piece length": 262144, b"pieces": pieces,
            b"files": [{b"length": l, b"path": p.encode().split(b"/")}
                       for p, l in FILES]}
    raw = encode(info)
    return parse_info(info, info_hash=hashlib.sha1(raw).digest(), info_bytes=raw)


class TestContentSignature(unittest.TestCase):
    def test_same_content_diff_name_same_signature(self):
        a = v2_meta("Film Release A", ROOTS_A)
        b = v2_meta("Totally Different Name", ROOTS_A)
        self.assertNotEqual(a.info_hash, b.info_hash)     # different infohashes
        self.assertEqual(content_signature(a.files, a.content_id),
                         content_signature(b.files, b.content_id))

    def test_same_layout_different_content_differ(self):
        # THE POISONING CASE: identical path+length layout, different content.
        good = v2_meta("Ubuntu 24.04 LTS", ROOTS_A)
        evil = v2_meta("Ubuntu 24.04 FREE www.evil.xyz", ROOTS_B)
        self.assertEqual([f for f in good.files], [f for f in evil.files])  # same layout
        self.assertNotEqual(content_signature(good.files, good.content_id),
                            content_signature(evil.files, evil.content_id))

    def test_v1_same_layout_different_pieces_differ(self):
        a = v1_meta("Film v1", PIECES_A)
        b = v1_meta("Film v1 poison", PIECES_B)
        self.assertEqual(a.files, b.files)
        self.assertNotEqual(content_signature(a.files, a.content_id),
                            content_signature(b.files, b.content_id))

    def test_layout_only_fallback_still_collapses(self):
        # No content_id (metadata without piece data) => best-effort layout dedup.
        self.assertEqual(content_signature(FILES), content_signature(list(FILES)))
        self.assertIsNotNone(content_signature(FILES))

    def test_different_layout_different_signature(self):
        self.assertNotEqual(content_signature(FILES),
                            content_signature([("other.mkv", 5)]))


class TestDedupCollapse(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)
        # a, b: SAME content (ROOTS_A), different names -> should collapse.
        self.a = v2_meta("Film 2024 group-a", ROOTS_A)
        self.b = v2_meta("Film 2024 group-b", ROOTS_A)
        # c: SAME layout as a/b but DIFFERENT content -> must NOT collapse.
        self.c = v2_meta("Film 2024 poison", ROOTS_B)
        self.store.store_metadata(self.a)
        self.store.store_metadata(self.b)
        self.store.store_metadata(self.c)

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    def test_same_content_rows_share_sig(self):
        a = self.store.get_torrent(self.a.info_hash.hex())
        b = self.store.get_torrent(self.b.info_hash.hex())
        c = self.store.get_torrent(self.c.info_hash.hex())
        self.assertIsNotNone(a["content_sig"])
        self.assertEqual(a["content_sig"], b["content_sig"])       # same content
        self.assertNotEqual(a["content_sig"], c["content_sig"])    # poison differs

    def test_search_collapses_same_content_only(self):
        # Without collapse all three rows appear...
        self.assertEqual(len(self.store.search("film")), 3)
        # ...with collapse a+b fold, c stays separate (2 content groups).
        collapsed = self.store.search("film", collapse=True)
        self.assertEqual(len(collapsed), 2)
        counts = sorted(r.get("dup_count", 1) for r in collapsed)
        self.assertEqual(counts, [1, 2])   # the poison row is its own group

    def test_collapse_indexed_order(self):
        collapsed = self.store.search("", order="latest", collapse=True)
        self.assertEqual(len(collapsed), 2)

    def test_poison_layout_not_collapsed(self):
        # The copied-layout/different-content torrent survives as a distinct row.
        collapsed = self.store.search("poison", collapse=True)
        self.assertEqual(len(collapsed), 1)
        self.assertEqual(collapsed[0]["infohash"], self.c.info_hash.hex())

    def test_find_duplicates_excludes_poison(self):
        ah, bh, ch = (self.a.info_hash.hex(), self.b.info_hash.hex(),
                      self.c.info_hash.hex())
        dups = self.store.find_duplicates(ah)
        self.assertIn(bh, dups)         # genuine same-content sibling
        self.assertNotIn(ch, dups)      # poison is NOT a duplicate

    def test_unique_torrent_not_collapsed(self):
        uniq = TorrentMeta(info_hash=hashlib.sha1(b"uniq").digest(),
                           name="Unique", total_size=5,
                           piece_length=262144, piece_count=1,
                           files=[("unique-only.iso", 5)])
        self.store.store_metadata(uniq)
        collapsed = self.store.search("", collapse=True)
        # a+b (1 group) + c (1) + uniq (1) = 3 groups.
        self.assertEqual(len(collapsed), 3)


if __name__ == "__main__":
    unittest.main()
