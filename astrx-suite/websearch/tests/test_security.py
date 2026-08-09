"""Security + correctness regression tests for the round-26 fixes.

Covers: the internal-IP SSRF denylist (initial connect + redirect hops + robots
fetch), the capped decompressor (gzip-bomb), the linear robots wildcard matcher
(ReDoS), IPv6 URL canonicalization round-trip, and the pager total cap.
"""

import gzip
import os
import tempfile
import time
import unittest
from urllib.parse import urlsplit

from websearch import canonical, httpclient, index, ranking, robots
from websearch.crawler import Crawler, CrawlConfig
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite

INTERNAL_URLS = [
    "http://169.254.169.254/latest/meta-data/",   # cloud metadata (link-local)
    "http://127.0.0.1:1/",                          # loopback
    "http://[::1]/",                                # IPv6 loopback
    "http://10.0.0.1/",                             # RFC1918
    "http://192.168.1.1/",                          # RFC1918
    "http://169.254.169.254/",                      # link-local
]


class SsrfTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()

    # ---- Fix #1: internal-IP denylist ------------------------------------
    def test_fetch_refuses_internal_addresses(self):
        for u in INTERNAL_URLS:
            res = httpclient.fetch(u, timeout=2)
            self.assertEqual(res.status, 0, u)
            self.assertTrue(
                (res.error or "").startswith("blocked-internal"),
                "%s not blocked (error=%r)" % (u, res.error))

    def test_allow_hosts_exemption_bypasses_denylist(self):
        # An explicitly allow-listed authority is NOT blocked as internal; it
        # gets a normal connection error instead (nothing is listening).
        res = httpclient.fetch("http://127.0.0.1:9/", timeout=2,
                               allow_hosts=["127.0.0.1:9"])
        self.assertFalse((res.error or "").startswith("blocked-internal"),
                         "allow-listed host was wrongly blocked: %r" % res.error)

    # ---- Fix #2: redirect / robots hops re-checked -----------------------
    def test_redirect_hop_to_internal_is_blocked(self):
        # /redirect-internal 302s to http://169.254.169.254/. The first (fixture)
        # hop is allow-listed and connects; the redirect target must be refused.
        res = httpclient.fetch(
            self.site.url("/redirect-internal"),
            allow=lambda u: True,  # scope permits it; the IP denylist must not
            allow_hosts=[canonical.authority_of(self.site.base)])
        self.assertEqual(res.redirects, 1)
        self.assertTrue((res.error or "").startswith("blocked-internal"),
                        "redirect to internal IP not blocked: %r" % res.error)

    def test_crawler_does_not_fetch_or_index_internal(self):
        fd, db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        try:
            conn = index.connect(db)
            cfg = CrawlConfig(
                scope_hosts=["169.254.169.254", "127.0.0.1"],
                base_delay=0.0, jitter=0.0, respect_robots=True,
                total_budget=10, allow_hosts=[])  # nothing exempt
            cr = Crawler(conn, cfg)
            cr.add_seeds(["http://169.254.169.254/latest/meta-data/",
                          "http://127.0.0.1:9/secret"])
            cr.run()
            self.assertEqual(
                conn.execute("SELECT COUNT(*) FROM docs").fetchone()[0], 0,
                "an internal URL got indexed")
            rows = {r[0]: (r[1], r[2]) for r in conn.execute(
                "SELECT url, status, reason FROM frontier")}
            for u in ("http://169.254.169.254/latest/meta-data/",
                      "http://127.0.0.1:9/secret"):
                self.assertIn(u, rows)
                status, reason = rows[u]
                self.assertEqual(status, "error", u)
                self.assertTrue((reason or "").startswith("blocked-internal"),
                                "%s reason=%r" % (u, reason))
            conn.close()
        finally:
            for suffix in ("", "-wal", "-shm"):
                try:
                    os.remove(db + suffix)
                except OSError:
                    pass

    # ---- Fix #3: decompression bomb capped -------------------------------
    def test_decompress_caps_output(self):
        bomb = gzip.compress(b"\0" * (40 * 1024 * 1024))  # 40 MiB -> ~40 KiB
        out = httpclient._decompress(bomb, "gzip", 10_000)
        self.assertLessEqual(len(out), 10_001,
                             "decompressor did not cap the bomb")

    def test_gzip_bomb_capped_end_to_end(self):
        res = httpclient.fetch(
            self.site.url("/bomb"), max_bytes=100_000,
            allow_hosts=[canonical.authority_of(self.site.base)])
        self.assertEqual(res.status, 200)
        self.assertLessEqual(len(res.body), 100_000)
        self.assertTrue(res.truncated)


class RobotsRedosTest(unittest.TestCase):
    # ---- Fix #4: linear wildcard matcher ---------------------------------
    def test_pathological_pattern_matches_fast(self):
        pat = "/" + "a*" * 40 + "b"       # would be catastrophic as a regex
        rob = robots.parse("User-agent: *\nDisallow: %s\n" % pat,
                           "astrx-websearch")
        path = "/" + "a" * 300            # no trailing 'b' -> forces worst case
        t0 = time.perf_counter()
        for _ in range(50):
            self.assertTrue(rob.can_fetch(path))  # pattern never matches -> allowed
        self.assertLess(time.perf_counter() - t0, 0.1,
                        "robots matcher is not linear (possible ReDoS)")

    def test_wildcard_semantics_preserved(self):
        def mk(rule):
            return robots.parse("User-agent: *\nDisallow: %s\n" % rule, "x")
        self.assertFalse(mk("/private/").can_fetch("/private/x"))  # prefix
        self.assertTrue(mk("/private/").can_fetch("/public"))
        self.assertFalse(mk("/*.php$").can_fetch("/a/b.php"))      # * + end anchor
        self.assertTrue(mk("/*.php$").can_fetch("/a/b.phpx"))
        self.assertFalse(mk("/x$").can_fetch("/x"))                # exact
        self.assertTrue(mk("/x$").can_fetch("/xy"))


class CanonicalIpv6Test(unittest.TestCase):
    # ---- Fix #5: IPv6 canonicalization round-trip ------------------------
    def test_ipv6_url_round_trips(self):
        c = canonical.canonicalize("http://[::1]:8080/admin")
        self.assertEqual(c, "http://[::1]:8080/admin")
        self.assertEqual(canonical.canonicalize(c), c)  # stable / idempotent
        self.assertEqual(urlsplit(c).hostname, "::1")
        self.assertEqual(urlsplit(c).port, 8080)
        self.assertEqual(canonical.authority_of("http://[::1]/"), "[::1]")
        self.assertEqual(canonical.authority_of("http://[::1]:8080/"),
                         "[::1]:8080")


class PagerCapTest(unittest.TestCase):
    # ---- Fix #6: pager total capped to candidate window ------------------
    def test_total_capped_to_candidates(self):
        old_cap = ranking.CANDIDATE_CAP
        ranking.CANDIDATE_CAP = 2
        fd, db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        try:
            conn = index.connect(db)
            for i in range(5):
                index.upsert_document(
                    conn, "http://t/%d" % i, "alpha title", "alpha desc",
                    "alpha body text number %d" % i, lang="en")
            conn.commit()
            results, total, _, _ = ranking.search(conn, "alpha")
            # 5 docs match, but only CANDIDATE_CAP=2 are re-ranked / paginable.
            self.assertEqual(total, 2)
            self.assertLessEqual(len(results), 2)
            conn.close()
        finally:
            ranking.CANDIDATE_CAP = old_cap
            for suffix in ("", "-wal", "-shm"):
                try:
                    os.remove(db + suffix)
                except OSError:
                    pass


if __name__ == "__main__":
    unittest.main()
