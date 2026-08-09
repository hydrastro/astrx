"""Entity indexing + pivot: store_page extracts entities, find_by_entity and the
/find app endpoint return the pages that carry a given key/address."""
import os
import tempfile
import time
import types
import unittest

try:
    from onioncrawler.storage import Storage
    from onioncrawler.search import SearchApp
    from onioncrawler import entities
except ImportError:
    from storage import Storage
    from search import SearchApp
    import entities


ETH = "0x52908400098527886E0F7030069857D2E4169EE7"
BTC = "1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa"
HOST = "abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion"


def _store(path):
    st = Storage(path)
    now = time.time()
    st.store_page("http://%s/pay" % HOST, HOST, "Vendor",
                  "pay to %s or btc %s thanks" % (ETH, BTC),
                  "hash-a", 200, "text/html", 120, now)
    st.store_page("http://%s/other" % HOST, HOST, "Other",
                  "we also take %s here" % ETH,
                  "hash-b", 200, "text/html", 90, now)
    return st


class TestEntityIndex(unittest.TestCase):
    def test_find_by_entity(self):
        st = _store(os.path.join(tempfile.mkdtemp(), "c.db"))
        eth_pages = {r["url"] for r in st.find_by_entity("eth", ETH)}
        self.assertEqual(eth_pages,
                         {"http://%s/pay" % HOST, "http://%s/other" % HOST})
        btc_pages = {r["url"] for r in st.find_by_entity("btc", BTC)}
        self.assertEqual(btc_pages, {"http://%s/pay" % HOST})

    def test_entity_counts(self):
        st = _store(os.path.join(tempfile.mkdtemp(), "c.db"))
        counts = st.entity_counts()
        self.assertEqual(counts.get("eth"), 1)   # one distinct eth address
        self.assertEqual(counts.get("btc"), 1)

    def test_reindex_on_update_replaces_entities(self):
        path = os.path.join(tempfile.mkdtemp(), "c.db")
        st = Storage(path)
        now = time.time()
        st.store_page("http://%s/p" % HOST, HOST, "P", "addr " + ETH,
                      "h1", 200, "text/html", 50, now)
        self.assertEqual(len(st.find_by_entity("eth", ETH)), 1)
        # re-store same URL with new content + no address -> entity removed
        st.store_page("http://%s/p" % HOST, HOST, "P", "nothing here now",
                      "h2", 200, "text/html", 50, now + 1)
        self.assertEqual(st.find_by_entity("eth", ETH), [])

    def test_find_app_endpoint(self):
        st = _store(os.path.join(tempfile.mkdtemp(), "c.db"))
        cfg = types.SimpleNamespace(results_per_page=10, rate_limit_rps=5.0,
                                    rate_limit_burst=20.0)
        app = SearchApp(st, cfg)
        data = app.api_find("eth", ETH, 1)
        self.assertEqual(data["kind"], "eth")
        self.assertEqual({r["url"] for r in data["results"]},
                         {"http://%s/pay" % HOST, "http://%s/other" % HOST})
        # HTML render is escape-safe and lists the pages
        html_bytes = app.render_find("eth", ETH, 1)
        self.assertIn(b"/pay", html_bytes)
        # unknown kind -> empty, no crash
        self.assertEqual(app.api_find("bogus", "x", 1)["results"], [])

    def test_find_endpoint_escapes_value(self):
        st = _store(os.path.join(tempfile.mkdtemp(), "c.db"))
        cfg = types.SimpleNamespace(results_per_page=10, rate_limit_rps=5.0,
                                    rate_limit_burst=20.0)
        app = SearchApp(st, cfg)
        page = app.render_find("btc", "<script>alert(1)</script>", 1)
        self.assertNotIn(b"<script>alert(1)</script>", page)


if __name__ == "__main__":
    unittest.main()
