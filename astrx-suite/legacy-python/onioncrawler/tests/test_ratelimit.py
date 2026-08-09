"""Roadmap #9 - token-bucket rate limiting on public endpoints + Basic-auth
gating of admin actions (submit/purge/recrawl)."""

import base64
import http.client
import os
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer

from onioncrawler.storage import Storage
from onioncrawler.config import Config
from onioncrawler.ratelimit import TokenBucket
from onioncrawler.search import SearchApp, make_handler

HOST = "a" * 56 + ".onion"


class TestTokenBucket(unittest.TestCase):
    def test_burst_then_refill(self):
        clock = [0.0]
        tb = TokenBucket(rate=1.0, capacity=2.0, now=lambda: clock[0])
        self.assertTrue(tb.allow("k"))     # 2 -> 1
        self.assertTrue(tb.allow("k"))     # 1 -> 0
        self.assertFalse(tb.allow("k"))    # empty
        clock[0] = 1.0                      # refill 1 token
        self.assertTrue(tb.allow("k"))
        self.assertFalse(tb.allow("k"))
        # a different key has its own bucket
        self.assertTrue(tb.allow("other"))

    def test_overflow_evicts_lru_not_whole_table(self):
        # Regression: on overflow we must evict the least-recently-used key(s),
        # NOT clear the whole table (which would hand every active client a
        # fresh full burst and defeat the limiter).
        clock = [0.0]
        tb = TokenBucket(rate=0.0, capacity=1.0, now=lambda: clock[0], max_keys=2)
        self.assertTrue(tb.allow("hot"))   # hot spends its only token -> 0
        self.assertTrue(tb.allow("k1"))    # k1 -> 0
        self.assertTrue(tb.allow("k2"))    # inserting k2 overflows -> evict LRU (hot)
        # a SURVIVING key keeps its (now empty) bucket -> still limited, i.e. the
        # table was NOT cleared:
        self.assertFalse(tb.allow("k1"))
        # the evicted LRU key legitimately starts fresh (expected LRU behavior):
        self.assertTrue(tb.allow("hot"))
        # and the table stayed bounded to max_keys
        self.assertLessEqual(len(tb._buckets), 2)


class _Serve(unittest.TestCase):
    def _serve(self, cfg):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "rl.db"))
        self.app = SearchApp(self.st, cfg)
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.app))
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.st.close()

    def _req(self, method, path, auth=None, body=""):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        headers = {}
        if body:
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        if auth:
            headers["Authorization"] = "Basic " + base64.b64encode(
                auth.encode()).decode()
        c.request(method, path, body=body, headers=headers)
        r = c.getresponse()
        r.read()
        c.close()
        return r.status


class TestPublicRateLimit(_Serve):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = True
        cfg.rate_limit_rps = 0.0        # no refill within the test
        cfg.rate_limit_burst = 2.0
        self._serve(cfg)

    def test_search_gets_429_after_burst(self):
        codes = [self._req("GET", "/search?q=x") for _ in range(4)]
        self.assertEqual(codes[0], 200)
        self.assertEqual(codes[1], 200)
        self.assertEqual(codes[2], 429, f"expected rate limit, got {codes}")

    def test_metrics_and_health_exempt(self):
        # exhaust the bucket, then monitoring endpoints must still answer
        for _ in range(5):
            self._req("GET", "/search?q=x")
        self.assertEqual(self._req("GET", "/metrics"), 200)
        self.assertEqual(self._req("GET", "/health"), 200)


class TestAdminAuthGate(_Serve):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = False
        cfg.admin_user = "op"
        cfg.admin_pass = "pw"
        self._serve(cfg)

    def test_purge_and_recrawl_require_auth(self):
        for path in ("/purge", "/recrawl"):
            self.assertEqual(self._req("POST", path, body="host=" + HOST), 401)
            self.assertEqual(
                self._req("POST", path, body="host=" + HOST, auth="op:bad"), 401)
        self.assertEqual(
            self._req("POST", "/recrawl", auth="op:pw"), 200)
        self.assertEqual(
            self._req("POST", "/purge", body="host=" + HOST, auth="op:pw"), 200)

    def test_non_ascii_credentials_return_clean_401(self):
        # Regression: a crafted Authorization header decoding to a non-ASCII
        # username/password used to raise TypeError inside hmac.compare_digest
        # (an unauthenticated dropped connection / 500). It must now be a clean
        # 401 with no traceback -- if the handler crashed, getresponse() would
        # raise and this call would error out instead of returning 401.
        self.assertEqual(self._req("POST", "/recrawl", auth="adïn:pw"), 401)
        self.assertEqual(
            self._req("POST", "/purge", body="host=" + HOST,
                      auth="op:péss"), 401)


class TestAdminDisabledWithoutCreds(_Serve):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = False
        # no admin_user/admin_pass -> admin actions disabled (403)
        self._serve(cfg)

    def test_admin_disabled_returns_403(self):
        self.assertEqual(self._req("POST", "/recrawl"), 403)
        self.assertEqual(self._req("POST", "/purge", body="host=" + HOST), 403)


if __name__ == "__main__":
    unittest.main()
