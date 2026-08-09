"""Torznab endpoint: caps + search feed (well-formed XML, torznab attrs,
category mapping, escape-safety)."""
import unittest
import xml.sax

from torrentds import torznab

IH = "aa" * 20


class TestTorznab(unittest.TestCase):
    def test_caps_wellformed(self):
        caps = torznab.caps_xml()
        xml.sax.parseString(caps, xml.sax.ContentHandler())   # well-formed
        self.assertIn(b"<caps>", caps)
        self.assertIn(b'id="2000"', caps)     # Movies
        self.assertIn(b'id="5000"', caps)     # TV

    def test_category_of(self):
        self.assertEqual(
            torznab.category_of({"category": "video", "tags": "kind:tv year:2020"}),
            "5000")
        self.assertEqual(
            torznab.category_of({"category": "video", "tags": "kind:movie"}), "2000")
        self.assertEqual(torznab.category_of({"category": "audio"}), "3000")
        self.assertEqual(torznab.category_of({"category": "document"}), "7000")
        self.assertEqual(torznab.category_of({"category": "other"}), "8000")

    def test_store_category_for_cat(self):
        self.assertEqual(torznab.store_category_for_cat("5000"), "video")
        self.assertEqual(torznab.store_category_for_cat("3000"), "audio")
        self.assertIsNone(torznab.store_category_for_cat("8000"))
        self.assertIsNone(torznab.store_category_for_cat(""))

    def test_search_xml_attrs(self):
        rows = [{"infohash": IH, "name": "Some.Show.S01E01.1080p.WEB.x264",
                 "total_size": 1_500_000_000, "file_count": 1,
                 "last_seen": 1_700_000_000, "category": "video",
                 "tags": "kind:tv resolution:1080p",
                 "magnet": "magnet:?xt=urn:btih:" + IH,
                 "seeders": 10, "leechers": 3}]
        body = torznab.search_xml(rows, base_url="http://h")
        xml.sax.parseString(body, xml.sax.ContentHandler())   # well-formed
        s = body.decode()
        self.assertIn("xmlns:torznab", s)
        self.assertIn('name="seeders" value="10"', s)
        self.assertIn('name="peers" value="13"', s)           # seeders+leechers
        self.assertIn('name="category" value="5000"', s)      # TV
        self.assertIn("/torrent/%s.torrent" % IH, s)
        self.assertIn('name="size" value="1500000000"', s)
        self.assertIn('name="magneturl"', s)

    def test_search_xml_prefers_swarm_totals(self):
        rows = [{"infohash": IH, "name": "x", "total_size": 1, "file_count": 1,
                 "last_seen": 0, "category": "other", "magnet": "magnet:x",
                 "seeders": 2, "leechers": 1,
                 "swarm_seeders": 50, "swarm_leechers": 5}]
        s = torznab.search_xml(rows).decode()
        self.assertIn('name="seeders" value="50"', s)          # combined health
        self.assertIn('name="peers" value="55"', s)

    def test_search_xml_escapes_hostile_name(self):
        rows = [{"infohash": "bb" * 20, "name": "<script>&bad</script>",
                 "total_size": 1, "file_count": 1, "last_seen": 0,
                 "category": "other", "magnet": "magnet:x",
                 "seeders": 0, "leechers": 0}]
        body = torznab.search_xml(rows)
        xml.sax.parseString(body, xml.sax.ContentHandler())    # still well-formed
        self.assertNotIn(b"<script>", body)


if __name__ == "__main__":
    unittest.main()
