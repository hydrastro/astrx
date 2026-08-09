"""Horizontal federation: HRW host-sharding, shard-scoped crawling, and the
scatter-gather aggregator (parallel fan-out + merge + cross-host SimHash dedup +
partial-results flag).  Shards are real in-process servers on ephemeral ports, so
the aggregator is exercised over the wire exactly as in a fleet."""
import json
import os
import tempfile
import threading
import unittest
import urllib.parse
import urllib.request
from collections import Counter

from websearch import federation, index, ranking, dedup, server
from websearch.crawler import Crawler, CrawlConfig


def _seed_db(path, docs):
    conn = index.connect(path)
    for i, (url, host, title, body) in enumerate(docs):
        index.upsert_document(conn, url, title, "", body, host=host,
                              fetched_at=1_700_000_000 + i,
                              simhash=dedup.signed64(dedup.simhash(body)))
    conn.commit()
    conn.close()


class _Shard:
    """A live shard server on an ephemeral port, in a daemon thread."""

    def __init__(self, docs):
        self.dir = tempfile.mkdtemp()
        self.db = os.path.join(self.dir, "shard.db")
        _seed_db(self.db, docs)
        self.httpd = server.make_server(self.db, host="127.0.0.1", port=0)
        self.port = self.httpd.server_address[1]
        self.base = "http://127.0.0.1:%d" % self.port
        self.t = threading.Thread(target=self.httpd.serve_forever, daemon=True)
        self.t.start()

    def stop(self):
        self.httpd.shutdown()
        self.httpd.server_close()


# --------------------------------------------------------------------------
# HRW / rendezvous sharding
# --------------------------------------------------------------------------

class TestHRW(unittest.TestCase):
    def test_single_node_owns_everything(self):
        self.assertTrue(federation.owns("anything.com", None, ()))
        self.assertTrue(federation.owns("x.com", "a", ()))   # no shard set

    def test_deterministic_and_within_set(self):
        shards = ["a", "b", "c"]
        for host in ("example.com", "b.org", "sub.deep.example.net"):
            o = federation.shard_for(host, shards)
            self.assertIn(o, shards)
            self.assertEqual(o, federation.shard_for(host, shards))

    def test_norm_host(self):
        self.assertEqual(federation.norm_host("Example.COM."), "example.com")
        self.assertEqual(federation.norm_host("host.net:8803"), "host.net")
        self.assertEqual(federation.norm_host("[::1]:9000"), "[::1]")

    def test_even_split_and_stability(self):
        shards = ["a", "b", "c", "d"]
        hosts = ["h%d.net" % i for i in range(2000)]
        counts = Counter(federation.shard_for(h, shards) for h in hosts)
        for c in counts.values():                      # even-ish: mean 500
            self.assertGreater(c, 300)
            self.assertLess(c, 700)
        smaller = ["a", "b", "c"]                       # drop one shard
        moved = sum(1 for h in hosts
                    if federation.shard_for(h, shards)
                    != federation.shard_for(h, smaller))
        # only hosts that lived on the dropped shard move (~1/4), never a full
        # rebalance -- the whole point of rendezvous hashing.
        self.assertLess(moved, len(hosts) * 0.40)
        self.assertGreater(moved, len(hosts) * 0.10)

    def test_partition_is_total_and_disjoint(self):
        shards = ["s0", "s1", "s2"]
        for i in range(500):
            h = "host%d.example" % i
            owners = [s for s in shards if federation.owns(h, s, shards)]
            self.assertEqual(len(owners), 1)   # exactly one shard owns each host


# --------------------------------------------------------------------------
# Shard-scoped crawling
# --------------------------------------------------------------------------

class TestShardScopedCrawl(unittest.TestCase):
    def test_seeds_only_kept_if_owned(self):
        shards = ["a", "b", "c"]
        me = "a"
        hosts = ["site%d.example" % i for i in range(45)]
        mine = [h for h in hosts if federation.shard_for(h, shards) == me]
        theirs = [h for h in hosts if federation.shard_for(h, shards) != me]
        self.assertTrue(mine and theirs)          # both non-empty (sanity)
        conn = index.connect(os.path.join(tempfile.mkdtemp(), "c.db"))
        try:
            cr = Crawler(conn, CrawlConfig(shard_id=me, shards=shards,
                                           respect_robots=False))
            added = cr.add_seeds(["http://%s/" % h for h in hosts])
            self.assertEqual(added, len(mine))    # only owned hosts enqueued
        finally:
            conn.close()

    def test_single_node_keeps_all_seeds(self):
        conn = index.connect(os.path.join(tempfile.mkdtemp(), "c.db"))
        try:
            cr = Crawler(conn, CrawlConfig(respect_robots=False))  # no shards
            added = cr.add_seeds(["http://a.example/", "http://b.example/",
                                  "http://c.example/"])
            self.assertEqual(added, 3)
        finally:
            conn.close()


# --------------------------------------------------------------------------
# Aggregator (scatter-gather over live shard servers)
# --------------------------------------------------------------------------

class TestAggregator(unittest.TestCase):
    def test_merge_across_shards(self):
        s1 = _Shard([("http://a.example/1", "a.example", "Zebra facts",
                      "the zebra is a striped african mammal grazing plains")])
        s2 = _Shard([("http://b.example/1", "b.example", "Lion facts",
                      "the lion is a large african cat living in prides")])
        try:
            fed = federation.federated_search([s1.base, s2.base], "african",
                                              page=1, page_size=10, timeout=5.0)
            self.assertEqual({r["url"] for r in fed["results"]},
                             {"http://a.example/1", "http://b.example/1"})
            self.assertFalse(fed["partial"])
            self.assertEqual(fed["ok_count"], 2)
        finally:
            s1.stop()
            s2.stop()

    def test_partial_when_shard_down(self):
        s1 = _Shard([("http://a.example/1", "a.example", "Zebra",
                      "striped african zebra")])
        dead = "http://127.0.0.1:1"                 # nothing listening
        try:
            fed = federation.federated_search([s1.base, dead], "zebra",
                                              page=1, page_size=10, timeout=2.0)
            self.assertTrue(fed["partial"])
            self.assertEqual(fed["ok_count"], 1)
            self.assertIn("http://a.example/1",
                          {r["url"] for r in fed["results"]})
        finally:
            s1.stop()

    def test_cross_host_mirror_dedup(self):
        # The SAME content on two DIFFERENT hosts is a mirror -> collapse to one.
        body = ("the quick brown fox jumps over the lazy dog beside the calm "
                "river bank at dawn while birds sing in the tall green trees")
        s1 = _Shard([("http://a.example/x", "a.example", "Fox A", body)])
        s2 = _Shard([("http://b.example/x", "b.example", "Fox B", body)])
        try:
            h1 = dedup.signed64(dedup.simhash(body))
            self.assertTrue(dedup.near(h1, h1, ranking.SIMHASH_HAMMING))
            fed = federation.federated_search([s1.base, s2.base], "quick fox",
                                              page=1, page_size=10, timeout=5.0)
            self.assertEqual(len(fed["results"]), 1)   # one mirror survives
        finally:
            s1.stop()
            s2.stop()

    def test_no_cross_shard_url_duplication(self):
        # A host lives on exactly one shard, so the same URL never appears twice;
        # even if two shards (mis)report it, the merge keeps a single copy.
        dup = ("http://same.example/p", "same.example", "Same",
               "unique alpha token content here")
        s1 = _Shard([dup])
        s2 = _Shard([dup])
        try:
            fed = federation.federated_search([s1.base, s2.base], "alpha",
                                              page=1, page_size=10, timeout=5.0)
            urls = [r["url"] for r in fed["results"]]
            self.assertEqual(urls.count("http://same.example/p"), 1)
        finally:
            s1.stop()
            s2.stop()

    def test_aggregator_http_endpoints(self):
        s1 = _Shard([("http://a.example/1", "a.example", "Zebra",
                      "striped african zebra grazing plains")])
        agg = federation.make_server([s1.base], host="127.0.0.1", port=0,
                                     timeout=5.0)
        port = agg.server_address[1]
        threading.Thread(target=agg.serve_forever, daemon=True).start()
        base = "http://127.0.0.1:%d" % port
        try:
            page = urllib.request.urlopen(
                base + "/search?q=zebra", timeout=5).read().decode()
            self.assertIn("a.example", page)
            data = json.loads(urllib.request.urlopen(
                base + "/api/search?q=zebra", timeout=5).read())
            self.assertEqual({r["url"] for r in data["results"]},
                             {"http://a.example/1"})
            self.assertIn("partial", data)
            self.assertEqual(urllib.request.urlopen(
                base + "/healthz", timeout=5).read(), b"ok")
            css = urllib.request.urlopen(base + "/style.css", timeout=5)
            self.assertEqual(css.headers.get("Content-Type"),
                             "text/css; charset=utf-8")
        finally:
            agg.shutdown()
            agg.server_close()
            s1.stop()

    def test_aggregator_hostile_query_escaped(self):
        s1 = _Shard([("http://a.example/1", "a.example", "Zebra", "zebra")])
        agg = federation.make_server([s1.base], host="127.0.0.1", port=0)
        port = agg.server_address[1]
        threading.Thread(target=agg.serve_forever, daemon=True).start()
        try:
            url = ("http://127.0.0.1:%d/search?q=%s"
                   % (port, urllib.parse.quote("<script>x</script>")))
            body = urllib.request.urlopen(url, timeout=5).read().decode()
            self.assertNotIn("<script>x</script>", body)   # reflected-XSS safe
            self.assertIn("&lt;script&gt;", body)
        finally:
            agg.shutdown()
            agg.server_close()
            s1.stop()

    def test_limit_param_on_shard(self):
        docs = [("http://a.example/%d" % i, "a.example", "Doc %d" % i,
                 "alpha beta gamma document number %d body text" % i)
                for i in range(25)]
        s1 = _Shard(docs)
        try:
            d0 = json.loads(urllib.request.urlopen(
                s1.base + "/api/search?q=alpha", timeout=5).read())
            self.assertLessEqual(len(d0["results"]), 10)     # default paging
            d1 = json.loads(urllib.request.urlopen(
                s1.base + "/api/search?q=alpha&limit=20", timeout=5).read())
            self.assertGreater(len(d1["results"]), 10)       # top-N pull
            self.assertLessEqual(len(d1["results"]), 20)
        finally:
            s1.stop()


if __name__ == "__main__":
    unittest.main()
