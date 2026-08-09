"""The heuristic release classifier: attribute-facet extraction from names."""
import time
import unittest

from torrentds import classify


class TestClassify(unittest.TestCase):
    def test_movie_dotted(self):
        f = classify.classify("The.Great.Film.2019.1080p.BluRay.x265.DTS-HD-GRP")
        self.assertEqual(f["kind"], "movie")
        self.assertEqual(f["year"], 2019)
        self.assertEqual(f["resolution"], "1080p")
        self.assertEqual(f["source"], "bluray")
        self.assertEqual(f["vcodec"], "x265")
        self.assertEqual(f["acodec"], "dts-hd")
        self.assertEqual(f["group"], "grp")

    def test_movie_bracketed_same_result(self):
        a = classify.classify("The Film (2019) [1080p] BluRay x265")
        self.assertEqual(a.get("year"), 2019)
        self.assertEqual(a.get("resolution"), "1080p")
        self.assertEqual(a.get("source"), "bluray")
        self.assertEqual(a.get("vcodec"), "x265")

    def test_tv_season_episode(self):
        f = classify.classify("Some.Show.S02E07.720p.WEB-DL.AAC-XYZ")
        self.assertEqual(f["kind"], "tv")
        self.assertEqual(f["season"], 2)
        self.assertEqual(f["episode"], 7)
        self.assertEqual(f["resolution"], "720p")
        self.assertEqual(f["source"], "web-dl")
        self.assertEqual(f["acodec"], "aac")

    def test_season_pack(self):
        f = classify.classify("Some Show Season 3 1080p WEB")
        self.assertEqual(f["season"], 3)
        self.assertEqual(f["kind"], "tv")

    def test_hdr_and_edition(self):
        f = classify.classify("Movie.2021.2160p.UHD.BluRay.HDR10.x265.Extended")
        self.assertEqual(f["resolution"], "2160p")
        self.assertEqual(f["hdr"], "hdr10")
        self.assertEqual(f["edition"], "extended")

    def test_music_by_extension(self):
        f = classify.classify("Some Artist - Album (2018)",
                              files=[("01 track.flac", 40_000_000),
                                     ("02 track.flac", 38_000_000)])
        self.assertEqual(f["kind"], "music")
        self.assertEqual(f["year"], 2018)

    def test_book_by_extension(self):
        f = classify.classify("Great Novel",
                              files=[("great novel.epub", 1_200_000)])
        self.assertEqual(f["kind"], "book")

    def test_software_iso(self):
        f = classify.classify("Ubuntu 24.04 amd64",
                              files=[("ubuntu-24.04.iso", 4_000_000_000)])
        self.assertEqual(f["kind"], "software")

    def test_no_facets_is_empty(self):
        f = classify.classify("random collection of stuff")
        self.assertNotIn("resolution", f)
        self.assertNotIn("kind", f)   # nothing to go on

    def test_tag_string_stable_order(self):
        f = classify.classify("Film.2019.1080p.WEB-DL.x264")
        ts = classify.tag_string(f)
        # keys appear in FACET_KEYS order
        self.assertIn("year:2019", ts)
        self.assertIn("resolution:1080p", ts)
        self.assertIn("source:web-dl", ts)
        self.assertLess(ts.index("year:2019"), ts.index("resolution:1080p"))
        # identical facets -> identical string
        self.assertEqual(ts, classify.tag_string(
            classify.classify("Film 2019 1080p WEB-DL x264")))

    def test_group_not_confused_with_codec(self):
        f = classify.classify("Movie.2019.1080p.BluRay.x264")
        self.assertNotEqual(f.get("group"), "x264")

    def test_hostile_long_name_is_linear(self):
        name = ("word " * 5000) + "1080p BluRay x265"
        t = time.monotonic()
        f = classify.classify(name)
        dt = time.monotonic() - t
        self.assertLess(dt, 1.0, "classify not linear on long input: %.2fs" % dt)
        # truncated at _MAX_NAME, so trailing tokens beyond the cap may be gone;
        # the point is it terminates fast and never raises.
        self.assertIsInstance(f, dict)


class TestFacetDisplay(unittest.TestCase):
    def test_facet_spans_shows_values(self):
        from torrentds import search as search_mod
        h = search_mod._facet_spans(
            "kind:movie year:2019 resolution:1080p source:bluray vcodec:x265")
        for v in ("1080p", "bluray", "x265", "2019"):
            self.assertIn(v, h)
        self.assertIn("class=facet", h)

    def test_facet_spans_escapes(self):
        from torrentds import search as search_mod
        h = search_mod._facet_spans("x:<script>alert(1)</script>")
        self.assertNotIn("<script>", h)
        self.assertIn("&lt;script&gt;", h)

    def test_facet_spans_empty(self):
        from torrentds import search as search_mod
        self.assertEqual(search_mod._facet_spans(""), "")
        self.assertEqual(search_mod._facet_spans(None), "")


if __name__ == "__main__":
    unittest.main()
