"""Ahmia md5(domain) banlist interop + the /stats page + banned.md5 republish."""
import hashlib
import os
import tempfile
import time
import types
import unittest

try:
    from onioncrawler.abuse import AbuseFilter, load_abuse_filter
    from onioncrawler.storage import Storage
    from onioncrawler.search import SearchApp
except ImportError:
    from abuse import AbuseFilter, load_abuse_filter
    from storage import Storage
    from search import SearchApp

HOST = "abcdefghijklmnopqrstuvwxyz234567abcdefghijklmnopqrstuvwx.onion"
ETH = "0x52908400098527886E0F7030069857D2E4169EE7"


def _md5(h):
    return hashlib.md5(h.encode("utf-8")).hexdigest()


class TestHashlist(unittest.TestCase):
    def test_md5_ban_blocks_host(self):
        f = AbuseFilter(host_md5s=[_md5(HOST)])
        self.assertTrue(f.host_blocked(HOST))
        self.assertTrue(f.host_blocked(HOST.upper()))       # normalised first
        self.assertFalse(f.host_blocked("zzz" + HOST[3:]))

    def test_republish_roundtrip(self):
        f = AbuseFilter(hosts=[HOST])
        md5s = f.banned_host_md5s()
        self.assertEqual(md5s, [_md5(HOST)])
        # a subscriber ingesting our republished list blocks the same host
        sub = AbuseFilter(host_md5s=md5s)
        self.assertTrue(sub.host_blocked(HOST))

    def test_load_from_file(self):
        p = os.path.join(tempfile.mkdtemp(), "banned.md5")
        with open(p, "w") as fh:
            fh.write(_md5(HOST) + "\n# a comment\n\n")
        f = load_abuse_filter(None, None, host_md5_path=p)
        self.assertTrue(f.host_blocked(HOST))

    def test_page_blocked_via_md5(self):
        f = AbuseFilter(host_md5s=[_md5(HOST)])
        self.assertTrue(f.page_blocked(HOST, "title", "body"))


class TestStatsAndRepublish(unittest.TestCase):
    def _app(self, with_abuse=True):
        st = Storage(os.path.join(tempfile.mkdtemp(), "c.db"))
        st.store_page("http://%s/1" % HOST, HOST, "T", "pay " + ETH,
                      "h", 200, "text/html", 10, time.time())
        cfg = types.SimpleNamespace(results_per_page=10, rate_limit_rps=5.0,
                                    rate_limit_burst=20.0)
        abuse = AbuseFilter(hosts=[HOST]) if with_abuse else None
        return SearchApp(st, cfg, abuse=abuse)

    def test_stats_page(self):
        page = self._app().render_stats()
        self.assertIn(b"pages indexed", page)
        self.assertIn(b"entities: eth", page)      # entity_counts folded in

    def test_banned_md5_republish(self):
        txt = self._app(with_abuse=True).banned_md5_text()
        self.assertIn(_md5(HOST), txt)
        self.assertTrue(txt.endswith("\n"))

    def test_banned_md5_empty_without_abuse(self):
        self.assertEqual(self._app(with_abuse=False).banned_md5_text(), "")


if __name__ == "__main__":
    unittest.main()
