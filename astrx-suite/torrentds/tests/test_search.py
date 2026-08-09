"""Metadata store: ingest, ranked search, magnet links, blocklist."""

import contextlib
import hashlib
import json
import os
import sqlite3
import sys
import tempfile
import threading
import time
import unittest
from urllib.request import urlopen
from xml.etree import ElementTree

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.bencode import decode, encode
from torrentds.metadata import TorrentMeta
from torrentds.peerstore import PeerStore
from torrentds.search import _clamp_int, attach_swarm, human_size, make_search_server
from torrentds.store import Store, categorize, magnet_link


class _RecordingConn:
    """Wraps a sqlite3 connection to record every execute() SQL + bind params."""

    def __init__(self, real, calls):
        self._real = real
        self._calls = calls

    def execute(self, sql, params=()):
        self._calls.append((sql, tuple(params)))
        return self._real.execute(sql, params)

    def __getattr__(self, name):
        return getattr(self._real, name)


def make_meta(name, files, seen=1, piece_len=262144, info_bytes=None):
    ih = hashlib.sha1(name.encode()).digest()
    total = sum(l for _, l in files)
    return TorrentMeta(info_hash=ih, name=name, total_size=total,
                       piece_length=piece_len, piece_count=max(1, total // piece_len),
                       files=files, info_bytes=info_bytes)


def make_meta_with_blob(name, length=5000):
    """A meta whose info_bytes are real, self-consistent info-dict bytes."""
    info = {b"name": name.encode(), b"piece length": 16384,
            b"pieces": b"\x00" * 20, b"length": length}
    raw = encode(info)
    ih = hashlib.sha1(raw).digest()
    return TorrentMeta(info_hash=ih, name=name, total_size=length,
                       piece_length=16384, piece_count=1,
                       files=[(name, length)], info_bytes=raw), raw, ih


class TestStoreSearch(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    def test_ingest_and_search(self):
        meta = make_meta("Debian 12 netinst amd64 ISO",
                         [("debian-12-netinst.iso", 660 * 1024 * 1024)])
        self.assertEqual(self.store.store_metadata(meta), "stored")
        results = self.store.search("debian")
        self.assertEqual(len(results), 1)
        self.assertEqual(results[0]["name"], "Debian 12 netinst amd64 ISO")
        # Magnet link must be correct and carry the display name.
        self.assertEqual(results[0]["magnet"],
                         magnet_link(meta.info_hash.hex(), meta.name))
        self.assertTrue(results[0]["magnet"].startswith(
            "magnet:?xt=urn:btih:" + meta.info_hash.hex()))

    def test_search_matches_file_paths(self):
        meta = make_meta("Linux Collection",
                         [("distros/archlinux-2024.iso", 900_000_000),
                          ("distros/readme.txt", 1000)])
        self.store.store_metadata(meta)
        # Query hits a file path, not the torrent name.
        results = self.store.search("archlinux")
        self.assertEqual(len(results), 1)
        self.assertEqual(results[0]["name"], "Linux Collection")

    def test_ranking_prefers_popular_and_larger(self):
        small = make_meta("Ubuntu Mini Remix", [("ubuntu-mini.iso", 200_000_000)])
        big = make_meta("Ubuntu Desktop 24.04 LTS", [("ubuntu-24.04.iso", 5_000_000_000)])
        self.store.store_metadata(small)
        self.store.store_metadata(big)
        # Make 'big' more popular by re-seeing it several times.
        for _ in range(20):
            self.store.store_metadata(big)
        results = self.store.search("ubuntu")
        self.assertEqual(len(results), 2)
        self.assertEqual(results[0]["name"], "Ubuntu Desktop 24.04 LTS")

    def test_reseen_increments_count(self):
        meta = make_meta("Some Release", [("a.bin", 10)])
        self.assertEqual(self.store.store_metadata(meta), "stored")
        self.assertEqual(self.store.store_metadata(meta), "updated")
        t = self.store.get_torrent(meta.info_hash.hex())
        self.assertEqual(t["seen_count"], 2)

    def test_blocklist_infohash_excludes(self):
        meta = make_meta("Blocked By Hash", [("x.bin", 100)])
        self.store.store_metadata(meta)
        self.assertEqual(len(self.store.search("blocked")), 1)
        self.store.add_block_infohash(meta.info_hash.hex())
        removed = self.store.purge_blocked()
        self.assertEqual(removed, 1)
        self.assertEqual(len(self.store.search("blocked")), 0)

    def test_blocklist_keyword_blocks_ingest(self):
        self.store.add_block_keyword("forbidden")
        meta = make_meta("A Forbidden Thing", [("y.bin", 100)])
        self.assertEqual(self.store.store_metadata(meta), "blocked")
        self.assertEqual(len(self.store.search("forbidden")), 0)
        # A non-matching torrent is unaffected.
        ok = make_meta("Allowed Thing", [("z.bin", 100)])
        self.assertEqual(self.store.store_metadata(ok), "stored")

    def test_stats(self):
        self.store.store_metadata(make_meta("One", [("a", 1)]))
        self.store.store_metadata(make_meta("Two", [("b", 2), ("c", 3)]))
        s = self.store.stats()
        self.assertEqual(s["torrents"], 2)
        self.assertEqual(s["files"], 3)
        self.assertEqual(s["total_size"], 6)


class TestSearchFilters(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)
        self.store.store_metadata(make_meta("Big Video Pack", [("movie.mkv", 5_000_000_000)]))
        self.store.store_metadata(make_meta("Small Song", [("track.mp3", 4_000_000)]))
        self.store.store_metadata(make_meta("Photo Bundle",
                                            [("a.jpg", 1000), ("b.jpg", 2000), ("c.jpg", 3000)]))

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    def test_categorize(self):
        self.assertEqual(categorize("x", [("movie.mkv", 1)]), "video")
        self.assertEqual(categorize("x", [("song.MP3", 1)]), "audio")
        self.assertEqual(categorize("readme", [("readme", 1)]), "other")

    def test_size_filter(self):
        r = self.store.search("", min_size=1_000_000_000)
        self.assertEqual([x["name"] for x in r], ["Big Video Pack"])
        r = self.store.search("", max_size=10_000_000)
        self.assertEqual({x["name"] for x in r}, {"Small Song", "Photo Bundle"})

    def test_category_filter(self):
        self.assertEqual([x["name"] for x in self.store.search("", category="audio")],
                         ["Small Song"])
        self.assertEqual([x["name"] for x in self.store.search("", category="video")],
                         ["Big Video Pack"])

    def test_file_count_filter(self):
        r = self.store.search("", min_files=3)
        self.assertEqual([x["name"] for x in r], ["Photo Bundle"])
        r = self.store.search("", max_files=1)
        self.assertEqual({x["name"] for x in r}, {"Big Video Pack", "Small Song"})

    def test_order_by_size(self):
        r = self.store.search("", order="size")
        self.assertEqual(r[0]["name"], "Big Video Pack")
        self.assertEqual([x["name"] for x in r][-1], "Photo Bundle")

    def test_order_by_seen(self):
        for _ in range(5):
            self.store.store_metadata(make_meta("Small Song", [("track.mp3", 4_000_000)]))
        r = self.store.search("", order="seen")
        self.assertEqual(r[0]["name"], "Small Song")

    def test_deep_offset_does_not_materialize_millions(self):
        # Regression: a deep ``offset`` must not become a giant SQL LIMIT.
        # Pre-fix the pool was (limit+offset)*4+50 -> ~4e6 rows fetched into
        # Python at offset 1e6.  Capture the LIMIT bound SQLite actually gets.
        for i in range(200):
            self.store.store_metadata(
                make_meta("bulk row %d" % i, [("f%d.bin" % i, i + 1)]))

        calls = []
        real_reader = self.store._reader

        @contextlib.contextmanager
        def recording_reader():
            with real_reader() as c:
                yield _RecordingConn(c, calls)

        self.store._reader = recording_reader
        try:
            # A deep offset returns nothing, quickly, for both code paths.
            self.assertEqual(self.store.search("", limit=25, offset=1_000_000,
                                               order="relevance"), [])
            self.assertEqual(self.store.search("", limit=25, offset=1_000_000,
                                               order="latest"), [])
        finally:
            self.store._reader = real_reader

        # Every windowed query now pages with LIMIT ? OFFSET ?, and the LIMIT
        # bound stays tiny regardless of how deep the offset is.
        limit_binds = [params[-2] for sql, params in calls
                       if "LIMIT ? OFFSET ?" in sql]
        self.assertTrue(limit_binds)                 # the paged queries ran
        for lb in limit_binds:
            self.assertLess(lb, 1000)                # not ~4,000,450

    def test_recency_filter(self):
        # Backdate one torrent, then a "past hour" window must exclude it.
        with self.store._lock:
            self.store._conn.execute(
                "UPDATE torrents SET last_seen=? WHERE name=?",
                (time.time() - 100000, "Small Song"))
            self.store._conn.commit()
        names = {x["name"] for x in self.store.search("", since=3600)}
        self.assertNotIn("Small Song", names)
        self.assertIn("Big Video Pack", names)

    def test_count_matches_filters(self):
        self.assertEqual(self.store.count(""), 3)
        self.assertEqual(self.store.count("", category="audio"), 1)
        self.assertEqual(self.store.count("", min_size=1_000_000_000), 1)


class TestStoreGrowthControl(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    def test_prune_discovered(self):
        a = hashlib.sha1(b"a").digest()
        b = hashlib.sha1(b"b").digest()
        c = hashlib.sha1(b"c").digest()
        self.store.add_discovered(a); self.store.mark_fetched(a)     # fetched
        self.store.add_discovered(b)
        for _ in range(5):
            self.store.mark_attempt(b)                                # exhausted
        self.store.add_discovered(c)                                  # still pending
        self.assertEqual(self.store.stats()["discovered"], 3)
        removed = self.store.prune_discovered(max_attempts=5)
        self.assertEqual(removed, 2)
        self.assertEqual(self.store.stats()["discovered"], 1)

    def test_enforce_retention_by_count(self):
        for i in range(5):
            self.store.store_metadata(make_meta("T%d" % i, [("f", 10)]))
        removed = self.store.enforce_retention(max_torrents=3)
        self.assertEqual(removed, 2)
        self.assertEqual(self.store.stats()["torrents"], 3)

    def test_enforce_retention_by_age(self):
        self.store.store_metadata(make_meta("Old", [("f", 10)]))
        self.store.store_metadata(make_meta("New", [("g", 10)]))
        with self.store._lock:
            self.store._conn.execute(
                "UPDATE torrents SET last_seen=? WHERE name=?",
                (time.time() - 100000, "Old"))
            self.store._conn.commit()
        removed = self.store.enforce_retention(max_age_seconds=3600)
        self.assertEqual(removed, 1)
        self.assertEqual({r["name"] for r in self.store.search("")}, {"New"})

    def test_vacuum_runs(self):
        self.store.store_metadata(make_meta("V", [("f", 10)]))
        self.store.vacuum()  # must not raise
        self.assertEqual(self.store.stats()["torrents"], 1)


class TestTorrentBlob(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    def test_blob_roundtrip_and_hashes_back(self):
        meta, raw, ih = make_meta_with_blob("blobby.iso")
        self.store.store_metadata(meta)
        got = self.store.get_info_bytes(ih.hex())
        self.assertEqual(got, raw)
        self.assertEqual(hashlib.sha1(got).digest(), ih)
        t = self.store.get_torrent(ih.hex())
        self.assertTrue(t["has_torrent"])
        self.assertEqual(self.store.stats()["torrent_blobs"], 1)

    def test_meta_without_blob_has_no_torrent(self):
        self.store.store_metadata(make_meta("noblob", [("x", 1)]))
        ih = hashlib.sha1(b"noblob").digest().hex()
        self.assertIsNone(self.store.get_info_bytes(ih))
        self.assertFalse(self.store.get_torrent(ih)["has_torrent"])

    def test_blocked_torrent_does_not_leak_blob(self):
        # A blocked torrent must persist neither its row nor its info blob.
        self.store.add_block_keyword("nastyword")
        info = {b"name": b"a nastyword file", b"piece length": 16384,
                b"pieces": b"\x00" * 20, b"length": 10}
        raw = encode(info)
        ih = hashlib.sha1(raw).digest()
        meta = TorrentMeta(info_hash=ih, name="a nastyword file", total_size=10,
                           piece_length=16384, piece_count=1,
                           files=[("a nastyword file", 10)], info_bytes=raw)
        self.assertEqual(self.store.store_metadata(meta), "blocked")
        self.assertIsNone(self.store.get_info_bytes(ih.hex()))
        self.assertEqual(self.store.stats()["torrent_blobs"], 0)


class TestReadPath(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path, read_pool_size=3)

    def tearDown(self):
        self.store.close()
        os.unlink(self.path)

    def test_read_pool_is_readonly(self):
        # The read connections are a separate pool and reject writes.
        self.assertEqual(self.store._read_pool.qsize(), 3)
        with self.store._reader() as rc:
            with self.assertRaises(sqlite3.OperationalError):
                rc.execute("INSERT INTO torrents(infohash,name,first_seen,last_seen) "
                           "VALUES('x','y',0,0)")

    def test_reads_and_writes_do_not_deadlock(self):
        # Concurrent search reads must not serialise behind (or deadlock with)
        # harvester writes -- they run on the separate read pool under WAL.
        errors = []

        def writer():
            try:
                for i in range(60):
                    self.store.store_metadata(make_meta("W%d" % i, [("f", i + 1)]))
            except Exception as e:  # pragma: no cover
                errors.append(e)

        def reader():
            try:
                for _ in range(60):
                    self.store.search("w")
                    self.store.stats()
            except Exception as e:  # pragma: no cover
                errors.append(e)

        threads = [threading.Thread(target=writer)] + \
                  [threading.Thread(target=reader) for _ in range(3)]
        for t in threads:
            t.start()
        for t in threads:
            t.join(timeout=20)
        self.assertEqual(errors, [])
        self.assertEqual(self.store.stats()["torrents"], 60)


class TestSearchServer(unittest.TestCase):
    """End-to-end: no-JS HTML rendering + JSON API over loopback."""

    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)
        self.store.store_metadata(
            make_meta("Fedora Workstation 40", [("Fedora-40.iso", 2_000_000_000)]))
        self.server = make_search_server(self.store, "127.0.0.1", 0)
        self.port = self.server.server_address[1]
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.store.close()
        os.unlink(self.path)

    def _get(self, path):
        with urlopen("http://127.0.0.1:%d%s" % (self.port, path), timeout=5) as r:
            return r.read().decode("utf-8")

    def test_html_search_no_js(self):
        html = self._get("/search?q=fedora")
        self.assertIn("Fedora Workstation 40", html)
        self.assertIn("magnet:?xt=urn:btih:", html)
        self.assertNotIn("<script", html.lower())  # strictly no JavaScript

    def test_json_api(self):
        import json
        data = json.loads(self._get("/api/search?q=fedora"))
        self.assertEqual(data["count"], 1)
        self.assertEqual(data["results"][0]["name"], "Fedora Workstation 40")
        self.assertTrue(data["results"][0]["magnet"].startswith("magnet:?xt=urn:btih:"))

    def test_bad_params_do_not_500(self):
        # Non-numeric limit and an overflowing offset must render a normal 200
        # page, not raise (previously a ValueError / SQLite OverflowError).
        # urlopen raises HTTPError on 5xx, so a clean read proves 200.
        html = self._get("/search?q=fedora&limit=abc&offset=999999999999999999999")
        self.assertIn("torrentds", html)
        html2 = self._get("/api/search?q=fedora&limit=-5&offset=abc")
        self.assertIn("results", html2)


class TestSwarmHealthAttach(unittest.TestCase):
    def test_attach_swarm_counts(self):
        ps = PeerStore(interval=1800)
        ih = hashlib.sha1(b"Swarmy").digest()
        ps.announce(ih, "1.2.3.4", 6881, left=0)     # seeder
        ps.announce(ih, "5.6.7.8", 6882, left=500)   # leecher
        rows = [{"infohash": ih.hex()}]
        attach_swarm(rows, ps)
        self.assertEqual((rows[0]["seeders"], rows[0]["leechers"]), (1, 1))


class TestSearchServerRich(unittest.TestCase):
    """.torrent rebuild, swarm health, RSS, detail API, pagination, metrics."""

    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.store = Store(self.path)
        self.meta, self.raw, self.ih = make_meta_with_blob("Rich Release ISO")
        self.store.store_metadata(self.meta)
        # a couple of extra torrents so pagination/feed have material
        for i in range(3):
            self.store.store_metadata(make_meta("Extra %d" % i, [("f%d.mkv" % i, 100 * (i + 1))]))
        self.ps = PeerStore(interval=1800)
        self.ps.announce(self.ih, "1.2.3.4", 6881, left=0)     # seeder
        self.ps.announce(self.ih, "5.6.7.8", 6882, left=500)   # leecher
        self.server = make_search_server(
            self.store, "127.0.0.1", 0, peer_store=self.ps,
            metrics_provider=lambda: {"indexer_fetched": 7, "krpc_rx_query": 3})
        self.port = self.server.server_address[1]
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.store.close()
        os.unlink(self.path)

    def _get(self, path):
        with urlopen("http://127.0.0.1:%d%s" % (self.port, path), timeout=5) as r:
            return r.read()

    def test_torrent_endpoint_rebuilds_valid_torrent(self):
        body = self._get("/torrent/%s.torrent" % self.ih.hex())
        d = decode(body)
        self.assertIn(b"info", d)
        # The rebuilt .torrent's info section hashes back to the infohash.
        self.assertEqual(hashlib.sha1(encode(d[b"info"])).digest(), self.ih)

    def test_torrent_endpoint_404_without_blob(self):
        missing = hashlib.sha1(b"Extra 0").digest().hex()
        with self.assertRaises(Exception):
            self._get("/torrent/%s.torrent" % missing)  # HTTPError 404

    def test_torrent_download_with_non_ascii_name(self):
        # L9: a CJK torrent name must not 500 the .torrent download.  str.isalnum
        # passes CJK letters that http.server cannot latin-1 encode in a header.
        meta, raw, ih = make_meta_with_blob("你好 电影 movie")
        self.store.store_metadata(meta)
        body = self._get("/torrent/%s.torrent" % ih.hex())   # must not raise
        d = decode(body)
        self.assertEqual(hashlib.sha1(encode(d[b"info"])).digest(), ih)

    def test_swarm_health_in_html_and_api(self):
        html = self._get("/search?q=rich").decode()
        self.assertIn("seeders", html)
        data = json.loads(self._get("/api/search?q=rich").decode())
        row = data["results"][0]
        self.assertEqual((row["seeders"], row["leechers"]), (1, 1))

    def test_rss_feed_is_valid_xml(self):
        body = self._get("/feed")
        root = ElementTree.fromstring(body)          # raises if malformed
        self.assertEqual(root.tag, "rss")
        titles = [t.text for t in root.iter("title")]
        self.assertTrue(any("Rich Release ISO" in (t or "") for t in titles))
        self.assertTrue(any("magnet:?xt=urn:btih:" in (e.get("url") or "")
                            for e in root.iter("enclosure")))

    def test_api_torrent_detail(self):
        data = json.loads(self._get("/api/torrent/%s" % self.ih.hex()).decode())
        self.assertEqual(data["infohash"], self.ih.hex())
        self.assertTrue(data["has_torrent"])
        self.assertEqual(data["torrent"], "/torrent/%s.torrent" % self.ih.hex())
        self.assertTrue(data["magnet"].startswith("magnet:?xt=urn:btih:"))
        self.assertEqual(len(data["files"]), 1)

    def test_api_pagination_metadata(self):
        data = json.loads(self._get("/api/search?q=extra&limit=1&offset=0").decode())
        self.assertIn("total", data)
        self.assertIn("limit", data)
        self.assertIn("offset", data)
        self.assertIn("has_more", data)
        self.assertEqual(data["limit"], 1)
        self.assertGreaterEqual(data["total"], 3)
        self.assertTrue(data["has_more"])
        self.assertEqual(data["next_offset"], 1)

    def test_metrics_and_health(self):
        metrics = self._get("/metrics").decode()
        self.assertIn("torrentds_torrents", metrics)
        self.assertIn("torrentds_tracker_swarms", metrics)
        self.assertIn("torrentds_indexer_fetched 7", metrics)  # from provider
        health = json.loads(self._get("/health").decode())
        self.assertEqual(health["status"], "ok")
        self.assertIn("uptime_seconds", health)


class TestParamClamp(unittest.TestCase):
    def test_clamp_int(self):
        self.assertEqual(_clamp_int("50", 25, 1, 100), 50)
        self.assertEqual(_clamp_int("abc", 25, 1, 100), 25)     # non-numeric
        self.assertEqual(_clamp_int("", 25, 1, 100), 25)        # empty
        self.assertEqual(_clamp_int("999", 25, 1, 100), 100)    # clamp high
        self.assertEqual(_clamp_int("-5", 25, 1, 100), 1)       # clamp low
        self.assertEqual(_clamp_int("10" * 20, 0, 0, 1_000_000), 1_000_000)


class TestHumanSize(unittest.TestCase):
    def test_units(self):
        self.assertEqual(human_size(0), "0 B")
        self.assertEqual(human_size(1023), "1023 B")
        self.assertEqual(human_size(1024), "1.0 KiB")
        self.assertEqual(human_size(5 * 1024 ** 3), "5.0 GiB")


if __name__ == "__main__":
    unittest.main()
