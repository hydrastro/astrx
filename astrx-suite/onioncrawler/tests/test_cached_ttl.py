"""Cached text snapshot view + submission-TTL reaper."""
import os
import tempfile
import time
import types
import unittest

try:
    from onioncrawler.storage import Storage
    from onioncrawler.search import SearchApp
    from onioncrawler.canonical import canonicalize
except ImportError:
    from storage import Storage
    from search import SearchApp
    from canonical import canonicalize

HOST = "abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion"


def _store():
    st = Storage(os.path.join(tempfile.mkdtemp(), "c.db"))
    return st


class TestCachedSnapshot(unittest.TestCase):
    def test_snapshot_returns_text(self):
        st = _store()
        st.store_page("http://%s/p" % HOST, HOST, "Title Here",
                      "the body text content", "h", 200, "text/html", 10,
                      time.time())
        snap = st.get_page_snapshot("http://%s/p" % HOST)
        self.assertIsNotNone(snap)
        self.assertEqual(snap["title"], "Title Here")
        self.assertIn("body text", snap["body"])
        self.assertIsNone(st.get_page_snapshot("http://%s/missing" % HOST))

    def _app(self, st):
        cfg = types.SimpleNamespace(results_per_page=10, rate_limit_rps=5.0,
                                    rate_limit_burst=20.0)
        return SearchApp(st, cfg)

    def test_render_cached(self):
        st = _store()
        st.store_page("http://%s/p" % HOST, HOST, "Title Here",
                      "the body text content", "h", 200, "text/html", 10,
                      time.time())
        app = self._app(st)
        page = app.render_cached("http://%s/p" % HOST)
        self.assertIn(b"Title Here", page)
        self.assertIn(b"body text", page)
        self.assertIn(b"No cached copy", app.render_cached("http://x/none"))

    def test_render_cached_escapes(self):
        st = _store()
        st.store_page("http://%s/x" % HOST, HOST, "<script>t</script>",
                      "body <script>bad</script> here", "h", 200, "text/html",
                      10, time.time())
        page = self._app(st).render_cached("http://%s/x" % HOST)
        self.assertNotIn(b"<script>t</script>", page)
        self.assertNotIn(b"<script>bad</script>", page)


class TestSubmissionTTL(unittest.TestCase):
    def _seed(self, st, age, tries=0):
        cu = canonicalize("http://%s/" % HOST)
        self.assertIsNotNone(cu)
        st.add_seed(cu, force=True)
        st.db.execute("UPDATE frontier SET enqueued_at=?, tries=?",
                      (time.time() - age, tries))

    def test_reap_old_unverified(self):
        st = _store()
        self._seed(st, age=100_000)                 # ~1.15 days old, never tried
        self.assertEqual(st.reap_unverified(ttl=86_400), 1)

    def test_reap_keeps_recent(self):
        st = _store()
        self._seed(st, age=10)                       # fresh
        self.assertEqual(st.reap_unverified(ttl=86_400), 0)

    def test_reap_keeps_attempted(self):
        st = _store()
        self._seed(st, age=100_000, tries=2)         # old but was attempted
        self.assertEqual(st.reap_unverified(ttl=86_400), 0)


if __name__ == "__main__":
    unittest.main()
