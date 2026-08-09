"""Video vertical + structured-data / SPA content recovery.

Two additive, zero-fetch features symmetric with the image vertical:

  * VIDEO vertical -- ``<video>/<source>``, known-player ``<iframe>``, Open Graph
    / Twitter player cards, schema.org ``VideoObject`` and direct media
    ``<a href>`` are harvested from ALREADY-crawled HTML.  Nothing is fetched:
    resolution is pure string work, internal-IP URLs are dropped at index time,
    and the results view links out to the ORIGINAL page/thumbnail so the browser
    (never the server) loads anything.

  * Structured-data recovery -- JSON-LD, Open Graph, ``<noscript>`` and inline
    state blobs (``__NEXT_DATA__`` / ``__INITIAL_STATE__`` / ...) are parsed
    (never eval'd) to recover title/description/body for JS-heavy/SPA pages that
    ship little static text.

The crown property, as with images: NO socket is ever opened by these features.
"""

import json
import os
import tempfile
import threading
import time
import unittest
from urllib.parse import urlencode
from urllib.request import urlopen

from websearch import htmlparse, index, server
from websearch.htmlparse import parse_duration
from websearch.crawler import Crawler, CrawlConfig
try:
    from tests.common import crawl_fixture
except ImportError:  # discover -s tests (top-level = tests/)
    from common import crawl_fixture
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


def _by_source(ex, source):
    return [v for v in ex.videos if v["source"] == source]


class VideoExtractionTest(unittest.TestCase):
    def test_video_element_source_and_poster(self):
        html = (
            "<html><body><p>intro clip text</p>"
            '<video src="/media/clip.mp4" poster="/media/poster.png"></video>'
            '<video poster="/media/p2.png">'
            '<source src="/media/a.webm"><source src="/media/b.ogv"></video>'
            "</body></html>")
        ex = htmlparse.extract(html)
        html5 = _by_source(ex, "html5")
        srcs = [v["video_url"] for v in html5]
        self.assertIn("/media/clip.mp4", srcs)
        self.assertIn("/media/a.webm", srcs)
        self.assertIn("/media/b.ogv", srcs)
        clip = next(v for v in html5 if v["video_url"] == "/media/clip.mp4")
        self.assertEqual(clip["thumbnail"], "/media/poster.png")
        webm = next(v for v in html5 if v["video_url"] == "/media/a.webm")
        self.assertEqual(webm["thumbnail"], "/media/p2.png")  # inherits poster
        self.assertIn("clip", clip["context"])                # nearby text

    def test_source_outside_video_ignored(self):
        # <source> inside <picture>/<audio> must not be harvested as a video.
        html = ('<html><body><picture><source src="/img.avif"></picture>'
                '<audio><source src="/a.mp3"></audio></body></html>')
        ex = htmlparse.extract(html)
        self.assertEqual(ex.videos, [])

    def test_iframe_known_players_and_watch_urls(self):
        html = (
            "<html><body>"
            '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'
            '<iframe src="https://youtu.be/abc123DEF"></iframe>'
            '<iframe src="https://player.vimeo.com/video/12345"></iframe>'
            '<iframe src="https://www.dailymotion.com/embed/video/x7tgad">'
            "</iframe>"
            '<iframe src="https://tube.example.org/videos/embed/uuid-9">'
            "</iframe>"
            '<iframe src="https://odysee.com/$/embed/foo/bar"></iframe>'
            '<iframe src="https://rumble.com/embed/v123/"></iframe>'
            "</body></html>")
        ex = htmlparse.extract(html)
        got = {v["source"]: v for v in ex.videos}
        self.assertEqual(got["youtube"]["watch_url"],
                         "https://www.youtube.com/watch?v=abc123DEF")
        # both youtube embeds recorded
        yts = _by_source(ex, "youtube")
        self.assertTrue(any("dQw4w9WgXcQ" in v["watch_url"] for v in yts))
        self.assertEqual(got["vimeo"]["watch_url"], "https://vimeo.com/12345")
        self.assertEqual(got["dailymotion"]["watch_url"],
                         "https://www.dailymotion.com/video/x7tgad")
        self.assertEqual(got["peertube"]["watch_url"],
                         "https://tube.example.org/videos/watch/uuid-9")
        # Odysee / Rumble recorded as players without a derivable watch URL.
        self.assertEqual(got["odysee"]["watch_url"], "")
        self.assertEqual(got["rumble"]["embed_url"],
                         "https://rumble.com/embed/v123/")

    def test_iframe_non_player_ignored(self):
        html = ('<html><body><iframe src="https://ads.test/banner.html">'
                "</iframe></body></html>")
        ex = htmlparse.extract(html)
        self.assertEqual(ex.videos, [])

    def test_opengraph_video(self):
        html = (
            "<html><head>"
            '<meta property="og:title" content="OG Movie">'
            '<meta property="og:video:secure_url" content="https://c.test/v.mp4">'
            '<meta property="og:image" content="https://c.test/poster.jpg">'
            "</head><body>x</body></html>")
        ex = htmlparse.extract(html)
        v = _by_source(ex, "opengraph")[0]
        self.assertEqual(v["video_url"], "https://c.test/v.mp4")
        self.assertEqual(v["title"], "OG Movie")
        self.assertEqual(v["thumbnail"], "https://c.test/poster.jpg")

    def test_twitter_player_card(self):
        html = (
            "<html><head>"
            '<meta name="twitter:player" content="https://c.test/player">'
            '<meta name="twitter:player:stream" content="https://c.test/s.mp4">'
            '<meta name="twitter:title" content="TW Clip">'
            "</head><body>x</body></html>")
        ex = htmlparse.extract(html)
        v = _by_source(ex, "twitter")[0]
        self.assertEqual(v["embed_url"], "https://c.test/player")
        self.assertEqual(v["video_url"], "https://c.test/s.mp4")
        self.assertEqual(v["title"], "TW Clip")

    def test_ldjson_videoobject(self):
        html = (
            '<html><head><script type="application/ld+json">'
            '{"@context":"https://schema.org","@type":"VideoObject",'
            '"name":"LD Clip","thumbnailUrl":["https://c.test/t.jpg"],'
            '"duration":"PT1H2M3S","uploadDate":"2024-01-01",'
            '"embedUrl":"https://c.test/embed","contentUrl":"https://c.test/c.mp4"}'
            "</script></head><body>x</body></html>")
        ex = htmlparse.extract(html)
        v = _by_source(ex, "ld-json")[0]
        self.assertEqual(v["title"], "LD Clip")
        self.assertEqual(v["thumbnail"], "https://c.test/t.jpg")
        self.assertEqual(v["embed_url"], "https://c.test/embed")
        self.assertEqual(v["video_url"], "https://c.test/c.mp4")
        self.assertEqual(v["duration"], 3723)     # ISO-8601 -> seconds

    def test_ldjson_numeric_duration(self):
        html = (
            '<html><head><script type="application/ld+json">'
            '{"@type":"VideoObject","name":"N","contentUrl":"https://c.test/n.mp4",'
            '"duration":95}</script></head><body>x</body></html>')
        ex = htmlparse.extract(html)
        self.assertEqual(_by_source(ex, "ld-json")[0]["duration"], 95)

    def test_direct_media_links(self):
        html = ("<html><body>"
                '<a href="/a.mp4">a</a><a href="/b.webm">b</a>'
                '<a href="/c.ogv">c</a><a href="/d.mov">d</a>'
                '<a href="https://x.test/e.m3u8">e</a>'
                '<a href="https://x.test/f.mpd">f</a>'
                '<a href="/plain.html">not media</a>'
                "</body></html>")
        ex = htmlparse.extract(html)
        srcs = {v["video_url"] for v in _by_source(ex, "direct")}
        for m in ("/a.mp4", "/b.webm", "/c.ogv", "/d.mov",
                  "https://x.test/e.m3u8", "https://x.test/f.mpd"):
            self.assertIn(m, srcs)
        self.assertFalse(any(s.endswith(".html") for s in srcs))
        # a direct-media <a href> is still an outbound link for the crawler
        self.assertIn("/plain.html", ex.links)
        self.assertIn("/a.mp4", ex.links)

    def test_videos_capped_per_page(self):
        html = ("<html><body>"
                + "".join('<video src="/v%d.mp4"></video>' % i
                          for i in range(500))
                + "</body></html>")
        ex = htmlparse.extract(html)
        self.assertLessEqual(len(ex.videos), htmlparse._MAX_VIDEOS)

    def test_hostile_title_captured_verbatim(self):
        # Extraction stores raw text; escaping happens only at render time.  (A
        # literal </script> inside a script legitimately ends it in any HTML
        # parser, so a realistic hostile payload uses other metacharacters; the
        # render-time escaping is covered by VideoServerTest.)
        html = (
            '<html><head><script type="application/ld+json">'
            '{"@type":"VideoObject","name":"\\"><img src=x onerror=evil()>",'
            '"contentUrl":"https://c.test/x.mp4"}</script></head><body>x</body>'
            "</html>")
        ex = htmlparse.extract(html)
        self.assertIn('<img src=x onerror=evil()>',
                      _by_source(ex, "ld-json")[0]["title"])


class DurationParseTest(unittest.TestCase):
    def test_iso8601_components(self):
        self.assertEqual(parse_duration("PT1H2M3S"), 3723)
        self.assertEqual(parse_duration("PT30S"), 30)
        self.assertEqual(parse_duration("PT1M"), 60)
        self.assertEqual(parse_duration("P1DT1H"), 90000)
        self.assertEqual(parse_duration("PT0S"), 0)
        self.assertEqual(parse_duration("P1W"), 604800)
        self.assertEqual(parse_duration("PT1.5S"), 2)      # rounded

    def test_invalid_returns_none(self):
        for bad in ("garbage", "P", "PT", "", None, "1H2M", "PT1X"):
            self.assertIsNone(parse_duration(bad))


class VideoResolveStoreTest(unittest.TestCase):
    def setUp(self):
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.conn = index.connect(self.db)

    def tearDown(self):
        self.conn.close()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def test_relative_resolution_and_scheme_filtering(self):
        html = ("<html><body>"
                '<video src="media/clip.mp4" poster="media/p.png"></video>'
                '<iframe src="https://www.youtube.com/embed/ABCDEFabcd1"></iframe>'
                '<a href="mailto:x@y.z">nope</a>'
                "</body></html>")
        ex = htmlparse.extract(html)
        page = "http://ex.test/dir/index.html"
        doc_id = index.upsert_document(self.conn, page, "P", "", "b",
                                       host="ex.test")
        cr = Crawler(self.conn, CrawlConfig(scope_hosts=["ex.test"]))
        cr._index_videos(doc_id, page, page, ex)   # base = page URL; NO fetch
        self.conn.commit()
        rows = self.conn.execute(
            "SELECT video_url, embed_url, watch_url, thumbnail_url FROM videos"
        ).fetchall()
        vals = " ".join(" ".join(c or "" for c in r) for r in rows)
        self.assertIn("http://ex.test/dir/media/clip.mp4", vals)  # relative
        self.assertIn("http://ex.test/dir/media/p.png", vals)     # rel poster
        self.assertIn("https://www.youtube.com/embed/ABCDEFabcd1", vals)

    def test_internal_ip_video_and_thumbnail_dropped_without_socket(self):
        # Video/embed/thumbnail URLs whose host is an internal-range IP LITERAL
        # are dropped at index time (a client-side SSRF / internal-port-scan
        # vector), while hostnames and public IPs are kept.  Classification must
        # open NO socket (no DNS, no connect) -- proven with a dial-out tripwire.
        import socket
        html = (
            "<html><body><p>ctx footage reel</p>"
            '<video src="http://169.254.169.254/meta.mp4"></video>'
            '<video src="http://127.0.0.1:8803/a.mp4"></video>'
            '<iframe src="http://192.168.0.1/embed"></iframe>'
            '<video src="http://[::1]/v.mp4"></video>'
            '<video src="http://10.1.2.3/x.mp4"></video>'
            '<video src="https://cdn.public.test/ok.mp4" '
            'poster="http://127.0.0.1/secret.png"></video>'
            '<iframe src="https://www.youtube.com/embed/PUBLICvidX"></iframe>'
            "</body></html>")
        ex = htmlparse.extract(html)
        page = "http://ex.test/p"
        doc_id = index.upsert_document(self.conn, page, "P", "", "b",
                                       host="ex.test")
        cr = Crawler(self.conn, CrawlConfig(scope_hosts=["ex.test"]))

        def _boom(*a, **k):
            raise AssertionError("video indexing must not open a socket")
        saved = (socket.getaddrinfo, socket.create_connection,
                 socket.socket.connect)
        socket.getaddrinfo = _boom
        socket.create_connection = _boom
        socket.socket.connect = _boom
        try:
            cr._index_videos(doc_id, page, page, ex)   # base = page; NO fetch
        finally:
            (socket.getaddrinfo, socket.create_connection,
             socket.socket.connect) = saved
        self.conn.commit()

        rows = self.conn.execute(
            "SELECT video_url, embed_url, watch_url, thumbnail_url FROM videos"
        ).fetchall()
        vals = " ".join(" ".join(c or "" for c in r) for r in rows)
        for bad in ("169.254.169.254", "127.0.0.1", "[::1]", "192.168.0.1",
                    "10.1.2.3"):
            self.assertNotIn(bad, vals, "internal URL leaked: %s" % bad)
        # public media + public player kept
        self.assertIn("https://cdn.public.test/ok.mp4", vals)
        self.assertIn("https://www.youtube.com/embed/PUBLICvidX", vals)
        # the public video's internal-IP poster was dropped, video retained
        pub = self.conn.execute(
            "SELECT thumbnail_url FROM videos WHERE video_url=?",
            ("https://cdn.public.test/ok.mp4",)).fetchone()
        self.assertEqual(pub[0], "")

    def test_video_search_shape(self):
        html = ('<html><body><p>rare kestrel footage</p>'
                '<video src="https://c.test/k.mp4" poster="https://c.test/k.png">'
                "</video></body></html>")
        ex = htmlparse.extract(html)
        page = "http://ex.test/p"
        doc_id = index.upsert_document(self.conn, page, "P", "", "b",
                                       host="ex.test")
        cr = Crawler(self.conn, CrawlConfig(scope_hosts=["ex.test"]))
        cr._index_videos(doc_id, page, page, ex)
        self.conn.commit()
        hits = index.video_search(self.conn, "kestrel")
        self.assertTrue(hits)
        for h in hits:
            self.assertEqual(
                set(h), {"video_url", "embed_url", "watch_url", "title",
                         "thumbnail_url", "source", "duration", "page_url",
                         "host"})
        self.assertTrue(any("k.mp4" in h["video_url"] for h in hits))

    def test_recrawl_replaces_videos_and_syncs_fts(self):
        page = "http://ex.test/p"
        doc_id = index.upsert_document(self.conn, page, "P", "", "b",
                                       host="ex.test")
        index.replace_videos(self.conn, doc_id, page, "ex.test",
                             [("https://ex.test/a.mp4", "", "", "alphaclip",
                               "", "html5", 10, "ctx")])
        index.replace_videos(self.conn, doc_id, page, "ex.test",
                             [("https://ex.test/b.mp4", "", "", "betaclip",
                               "", "html5", 20, "ctx")])
        self.conn.commit()
        rows = [r[0] for r in self.conn.execute(
            "SELECT video_url FROM videos WHERE doc_id=?", (doc_id,))]
        self.assertEqual(rows, ["https://ex.test/b.mp4"])   # old row replaced

        def match(term):
            return self.conn.execute(
                "SELECT COUNT(*) FROM videos_fts WHERE videos_fts MATCH ?",
                (term,)).fetchone()[0]
        self.assertEqual(match("betaclip"), 1)
        self.assertEqual(match("alphaclip"), 0)

    def test_replace_videos_skips_linkless_and_caps(self):
        page = "http://ex.test/p"
        doc_id = index.upsert_document(self.conn, page, "P", "", "b",
                                       host="ex.test")
        # a row with no usable URL is skipped
        n = index.replace_videos(self.conn, doc_id, page, "ex.test",
                                 [("", "", "", "t", "http://x/th.png",
                                   "s", None, "c")])
        self.assertEqual(n, 0)
        many = [("https://ex.test/%d.mp4" % i, "", "", "", "", "html5", None,
                 "") for i in range(index.MAX_VIDEOS_PER_DOC + 50)]
        n = index.replace_videos(self.conn, doc_id, page, "ex.test", many)
        self.assertEqual(n, index.MAX_VIDEOS_PER_DOC)


class VideoObjectRoutingTest(unittest.TestCase):
    def test_videoobject_on_bodyless_page_routes_to_video_vertical(self):
        html = (
            "<html><head><title></title>"
            '<script type="application/ld+json">'
            '{"@type":"VideoObject","name":"Routed Clip",'
            '"contentUrl":"https://c.test/r.mp4","duration":"PT2M"}'
            "</script></head><body><div id=app></div></body></html>")
        ex = htmlparse.extract(html)
        self.assertTrue(any(v["source"] == "ld-json"
                            and v["video_url"] == "https://c.test/r.mp4"
                            for v in ex.videos))

    def test_imageobject_routes_to_image_vertical(self):
        html = (
            "<html><head>"
            '<script type="application/ld+json">'
            '{"@type":"ImageObject","contentUrl":"https://c.test/pic.jpg",'
            '"caption":"a caption"}'
            "</script></head><body>x</body></html>")
        ex = htmlparse.extract(html)
        self.assertTrue(any(im[0] == "https://c.test/pic.jpg"
                            for im in ex.images))


class StructuredRecoveryTest(unittest.TestCase):
    def test_ldjson_recovers_bodyless_spa(self):
        html = (
            "<html><head></head><body><div id=root></div>"
            '<script type="application/ld+json">'
            '{"@type":"NewsArticle","headline":"SPA Recovered",'
            '"description":"Recovered description.",'
            '"articleBody":"Full body text only in JSON-LD. quantumwidget."}'
            "</script></body></html>")
        ex = htmlparse.extract(html)
        self.assertEqual(ex.title, "SPA Recovered")
        self.assertEqual(ex.description, "Recovered description.")
        self.assertIn("quantumwidget", ex.text)      # body now searchable

    def test_malformed_ldjson_skipped(self):
        html = ("<html><head><title>Real Title</title></head><body>"
                "<p>real static body text here that is fine</p>"
                '<script type="application/ld+json">{ not, valid json ,,, }'
                "</script></body></html>")
        ex = htmlparse.extract(html)          # must not raise
        self.assertEqual(ex.title, "Real Title")

    def test_next_data_state_blob_recovered(self):
        html = (
            "<html><head><title></title></head><body><div id=__next></div>"
            '<script id="__NEXT_DATA__" type="application/json">'
            '{"props":{"pageProps":{"title":"Next Title",'
            '"description":"nextdesc","post":{"body":"nextbody marker"}}}}'
            "</script></body></html>")
        ex = htmlparse.extract(html)
        self.assertIn("nextbody", ex.text)
        self.assertIn("Next Title", ex.text)

    def test_inline_initial_state_recovered(self):
        html = ("<html><body><div id=app></div>"
                "<script>window.__INITIAL_STATE__ = "
                '{"headline":"Redux","content":"reduxmarker body"};</script>'
                "</body></html>")
        ex = htmlparse.extract(html)
        self.assertIn("reduxmarker", ex.text)

    def test_opengraph_recovers_title_description(self):
        html = ("<html><head>"
                '<meta property="og:title" content="OG Title">'
                '<meta property="og:description" content="OG Desc">'
                "</head><body><div id=app></div></body></html>")
        ex = htmlparse.extract(html)
        self.assertEqual(ex.title, "OG Title")
        self.assertEqual(ex.description, "OG Desc")

    def test_noscript_recovered(self):
        html = ("<html><body><div id=app></div>"
                "<noscript>noscriptmarker enable javascript</noscript>"
                "</body></html>")
        ex = htmlparse.extract(html)
        self.assertIn("noscriptmarker", ex.text)

    def test_rich_static_body_not_overwritten(self):
        # A page with a real title and a substantial static body must keep them:
        # recovery only backfills thin/empty fields.
        body = "This is a long real article body. " * 20
        html = ("<html><head><title>Static Title</title></head><body>"
                "<p>%s</p>"
                '<meta property="og:title" content="OG Should Not Win">'
                "</body></html>" % body)
        ex = htmlparse.extract(html)
        self.assertEqual(ex.title, "Static Title")
        self.assertNotIn("OG Should Not Win", ex.text)


class PathologicalBoundTest(unittest.TestCase):
    def test_pathological_page_terminates_and_is_bounded(self):
        # Thousands of videos/iframes + a huge JSON-LD array + a ~5 MiB inline
        # state blob + many media links.  Must terminate quickly with bounded
        # outputs (proves the parse/index path is linear and capped on hostile
        # input, and -- with no socket anywhere -- cannot be turned into a fetch).
        big = json.dumps([
            {"@type": "VideoObject", "name": "v%d" % i,
             "contentUrl": "https://c.test/%d.mp4" % i,
             "duration": "PT%dS" % (i % 60)} for i in range(20000)])
        parts = ["<html><head><title>t</title>",
                 '<script type="application/ld+json">', big, "</script>",
                 "<script>window.__INITIAL_STATE__={\"content\":\"",
                 "x" * 5_000_000, "\"};</script></head><body>"]
        for i in range(5000):
            parts.append('<video src="/v%d.mp4" poster="/p%d.png"></video>' %
                         (i, i))
            parts.append('<iframe src="https://www.youtube.com/embed/ID%08d">'
                         "</iframe>" % i)
            parts.append('<a href="/m%d.webm">m</a>' % i)
        parts.append("</body></html>")
        page = "".join(parts)

        t0 = time.time()
        ex = htmlparse.extract(page)
        elapsed = time.time() - t0

        self.assertLess(elapsed, 10.0, "extraction did not terminate quickly")
        self.assertLessEqual(len(ex.videos), htmlparse._MAX_VIDEOS)
        self.assertLessEqual(len(ex.ldjson_blobs), htmlparse._MAX_LD_BLOBS)
        self.assertLessEqual(len(ex.state_blobs), htmlparse._MAX_STATE_BLOBS)
        self.assertLessEqual(len(ex.text), htmlparse._RECOVER_BODY_MAX + 4096)

    def test_deeply_nested_ldjson_does_not_crash(self):
        # Deep nesting makes json.loads raise (recursion limit); we skip it.
        blob = "[" * 5000 + "]" * 5000
        html = ('<html><head><title>Deep</title>'
                '<script type="application/ld+json">' + blob +
                "</script></head><body><p>ok</p></body></html>")
        ex = htmlparse.extract(html)          # must not raise
        self.assertEqual(ex.title, "Deep")


class VideoServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(cls.db)
        conn, _ = crawl_fixture(cls.site, cls.db)
        # Inject a video whose media/thumbnail point at a LOOPBACK fixture URL
        # the crawl never visited, with a hostile title.  If the server ever
        # fetched either, the fixture's hit counter for these paths would tick.
        cls.vid_url = cls.site.url("/video-should-not-be-fetched.mp4")
        cls.thumb_url = cls.site.url("/thumb-should-not-be-fetched.jpg")
        row = conn.execute("SELECT id, url FROM docs LIMIT 1").fetchone()
        index.replace_videos(
            conn, row["id"], row["url"], "127.0.0.1",
            [(cls.vid_url, "", "", '"><script>alert(9)</script>',
              cls.thumb_url, "html5", 3723,
              "a wild peregrine falcon in surrounding context")])
        index.finalize(conn)
        conn.close()
        cls.httpd = server.make_server(cls.db, host="127.0.0.1", port=0)
        cls.port = cls.httpd.server_address[1]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever,
                                      kwargs={"poll_interval": 0.05},
                                      daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()
        cls.thread.join(timeout=3)
        cls.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _get(self, path):
        with urlopen("http://127.0.0.1:%d%s" % (self.port, path),
                     timeout=5) as r:
            return r.status, r.read().decode("utf-8"), r.headers

    def test_video_results_render_without_fetching(self):
        status, body, _ = self._get(
            "/videos?" + urlencode({"q": "peregrine"}))
        self.assertEqual(status, 200)
        self.assertIn("<img", body)
        self.assertIn(self.thumb_url, body)        # remote thumbnail as-is
        self.assertIn("1:02:03", body)             # duration formatted
        # Server fetched neither the media nor the thumbnail (browser would).
        self.assertEqual(
            self.site.hits.get("/video-should-not-be-fetched.mp4", 0), 0)
        self.assertEqual(
            self.site.hits.get("/thumb-should-not-be-fetched.jpg", 0), 0)

    def test_hostile_title_is_escaped(self):
        _, body, _ = self._get("/videos?" + urlencode({"q": "peregrine"}))
        self.assertNotIn("<script>alert(9)</script>", body)
        self.assertIn("&lt;script&gt;", body)

    def test_search_type_videos_routes_to_vertical(self):
        _, body, _ = self._get(
            "/search?" + urlencode({"type": "videos", "q": "peregrine"}))
        self.assertIn(self.thumb_url, body)

    def test_results_offer_videos_tab(self):
        _, body, _ = self._get("/search?" + urlencode({"q": "inverted index"}))
        self.assertIn("/videos", body)
        self.assertIn(">Videos<", body)

    def test_api_videos_shape(self):
        status, body, headers = self._get(
            "/api/videos?" + urlencode({"q": "peregrine"}))
        self.assertEqual(status, 200)
        self.assertIn("application/json", headers.get("Content-Type", ""))
        payload = json.loads(body)
        self.assertEqual(payload["query"], "peregrine")
        self.assertEqual(payload["count"], len(payload["results"]))
        self.assertTrue(payload["results"])
        r = payload["results"][0]
        self.assertEqual(
            set(r), {"video_url", "embed_url", "watch_url", "title",
                     "thumbnail_url", "source", "duration", "page_url", "host"})
        self.assertEqual(r["duration"], 3723)

    def test_empty_query_video_view_ok(self):
        status, body, _ = self._get("/videos")
        self.assertEqual(status, 200)
        self.assertIn(">Videos<", body)


if __name__ == "__main__":
    unittest.main()
