"""Roadmap #7 - link-graph authority: persist inter-onion edges, compute an
offline PageRank-lite, and blend it into FTS ranking."""

import os
import tempfile
import unittest

from onioncrawler.storage import Storage
from onioncrawler.config import Config
from onioncrawler.crawler import Crawler
from onioncrawler.abuse import AbuseFilter
from onioncrawler.canonical import canonicalize
from onioncrawler.extract import extract_html

_B32 = "abcdefghijklmnopqrstuvwxyz234567"


def _h(ch):
    return ch * 56 + ".onion"


def _onion(n):
    """A distinct valid v3 onion host (56 base32 chars) for each integer n."""
    s, x = "", n
    for _ in range(56):
        s += _B32[x % 32]
        x //= 32
    return s + ".onion"


class _NoFetch:
    def fetch(self, *a, **k):
        raise AssertionError("no fetch expected in this test")


class TestAuthority(unittest.TestCase):
    def setUp(self):
        self.db = os.path.join(tempfile.mkdtemp(), "auth.db")
        self.st = Storage(self.db)

    def tearDown(self):
        self.st.close()

    def test_pagerank_favors_well_linked_host(self):
        st = self.st
        hub, a, b, c = _h("a"), _h("b"), _h("c"), _h("d")
        for x in (hub, a, b, c):
            st.ensure_host(x)
        # a, b, c all point at hub; hub points only at a
        st.add_link_edge(a, hub)
        st.add_link_edge(b, hub)
        st.add_link_edge(c, hub)
        st.add_link_edge(hub, a)
        self.assertGreaterEqual(st.compute_authority(), 4)
        auth = {r["host"]: r["authority"] for r in
                st.db.execute("SELECT host, authority FROM hosts")}
        self.assertEqual(auth[hub], max(auth.values()))
        self.assertGreater(auth[hub], auth[b])   # hub outranks a leaf
        # normalized to max=1
        self.assertAlmostEqual(max(auth.values()), 1.0, places=6)

    def test_edges_accumulate_counts(self):
        st = self.st
        s, d = _h("e"), _h("f")
        st.add_link_edge(s, d)
        st.add_link_edge(s, d, delta=2)
        st.add_link_edge(s, s)  # self-link ignored
        row = st.db.execute(
            "SELECT cnt FROM link_edges WHERE src_host=? AND dst_host=?",
            (s, d)).fetchone()
        self.assertEqual(row["cnt"], 3)
        self.assertEqual(st.db.execute(
            "SELECT count(*) c FROM link_edges").fetchone()["c"], 1)

    def test_authority_blend_breaks_bm25_tie(self):
        st = self.st
        high, low = _h("g"), _h("j")
        for x in (high, low):
            st.ensure_host(x)
        # several distinct hosts link to `high`, none to `low`
        for ch in "klmnop":
            src = _h(ch)
            st.ensure_host(src)
            st.add_link_edge(src, high)
        st.compute_authority()
        auth = {r["host"]: r["authority"] for r in
                st.db.execute("SELECT host, authority FROM hosts")}
        self.assertGreater(auth[high], auth[low])

        # identical bm25 (same token once, same title) but distinct content
        st.store_page(f"http://{high}/p", high, "title",
                      "sharedtoken uniquehigh", "c1", 200, "text/html", 10, 1.0)
        st.store_page(f"http://{low}/p", low, "title",
                      "sharedtoken uniquelow", "c2", 200, "text/html", 10, 2.0)

        # with a strong authority weight, the high-authority page ranks first
        res, total = st.search("sharedtoken", authority_weight=10.0)
        self.assertEqual(total, 2)
        self.assertTrue(res[0]["url"].endswith(f"{high}/p"),
                        "authority did not lift the well-linked host")


class TestLinkEdgeCaps(unittest.TestCase):
    def setUp(self):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "edgecap.db"))

    def tearDown(self):
        self.st.close()

    def test_extract_caps_links(self):
        # extract_html must honour max_links (bounds per-page link harvesting).
        html = b"<html><body>" + b"".join(
            b'<a href="/p%d">x</a>' % i for i in range(5000)) + b"</body></html>"
        self.assertEqual(len(extract_html(html, max_links=500).links), 500)
        self.assertEqual(len(extract_html(html).links), 5000)  # unlimited default

    def test_link_edges_only_for_admitted_urls(self):
        # Regression: a hostile page linking to thousands of distinct onion hosts
        # must not grow link_edges without bound. Edges are recorded only AFTER a
        # URL is admitted by the frontier caps, so they can never exceed the
        # number of admitted URLs (itself bounded by max_unique_urls).
        cfg = Config()
        cfg.max_links_per_page = 500
        cfg.max_unique_urls = 50          # frontier backstop bounds edges too
        cfg.max_pages_per_host = 100000
        cr = Crawler(cfg, self.st, _NoFetch(), AbuseFilter())
        parent = canonicalize("http://" + ("z" * 56) + ".onion/")
        links = ["http://" + _onion(i) + "/" for i in range(1000)]
        cr._enqueue_links(parent, links, depth=0)
        edges = self.st.db.execute(
            "SELECT count(*) c FROM link_edges").fetchone()["c"]
        admitted = self.st.db.execute(
            "SELECT count(*) c FROM frontier").fetchone()["c"]
        self.assertLessEqual(admitted, cfg.max_unique_urls)
        self.assertLessEqual(edges, admitted,
                             "edges must be recorded only for admitted URLs")
        self.assertGreater(edges, 0)      # the admitted cross-host links do count

    def test_compute_authority_edge_load_is_bounded(self):
        # A defensive max_edges LIMIT means a huge table can't force an unbounded
        # in-memory load; scoring still completes over the (capped) edge set.
        for i in range(50):
            self.st.ensure_host(_onion(i))
        for i in range(49):
            self.st.add_link_edge(_onion(i), _onion(i + 1))
        self.assertEqual(self.st.compute_authority(max_edges=5), 50)


if __name__ == "__main__":
    unittest.main()
