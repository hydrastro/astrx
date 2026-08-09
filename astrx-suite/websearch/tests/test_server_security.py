"""Feature 7: token-bucket rate limiting + optional HTTP Basic auth."""

import base64
import os
import tempfile
import threading
import unittest
import urllib.error
import urllib.request

from websearch import index, server
try:
    from tests.common import crawl_fixture
except ImportError:  # discover -s tests (top-level = tests/)
    from common import crawl_fixture
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


class ServerSecurityTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(cls.db)
        conn, _ = crawl_fixture(cls.site, cls.db)
        conn.close()

    @classmethod
    def tearDownClass(cls):
        cls.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _start(self, **kw):
        httpd = server.make_server(self.db, host="127.0.0.1", port=0, **kw)
        port = httpd.server_address[1]
        t = threading.Thread(target=httpd.serve_forever,
                             kwargs={"poll_interval": 0.05}, daemon=True)
        t.start()

        def stop():
            httpd.shutdown()
            httpd.server_close()
            t.join(timeout=3)
        self.addCleanup(stop)
        return port

    def _get(self, port, path, auth=None):
        req = urllib.request.Request("http://127.0.0.1:%d%s" % (port, path))
        if auth:
            token = base64.b64encode(("%s:%s" % auth).encode()).decode()
            req.add_header("Authorization", "Basic " + token)
        try:
            with urllib.request.urlopen(req, timeout=5) as r:
                return r.status, r.headers
        except urllib.error.HTTPError as e:
            return e.code, e.headers

    def test_basic_auth_required_on_search(self):
        port = self._start(auth=("alice", "s3cret"))
        # No credentials -> 401 with a challenge.
        code, headers = self._get(port, "/search?q=python")
        self.assertEqual(code, 401)
        self.assertIn("Basic", headers.get("WWW-Authenticate", ""))
        # Health, metrics and CSS stay open.
        self.assertEqual(self._get(port, "/healthz")[0], 200)
        self.assertEqual(self._get(port, "/metrics")[0], 200)
        # Correct credentials pass; wrong password is rejected.
        self.assertEqual(
            self._get(port, "/search?q=python", auth=("alice", "s3cret"))[0], 200)
        self.assertEqual(
            self._get(port, "/api/search?q=python", auth=("alice", "s3cret"))[0],
            200)
        self.assertEqual(
            self._get(port, "/search?q=python", auth=("alice", "wrong"))[0], 401)

    def test_rate_limit_returns_429(self):
        # 2-token bucket, no refill within the test window.
        port = self._start(rate=0.0, burst=2)
        codes = [self._get(port, "/search?q=python")[0] for _ in range(6)]
        self.assertEqual(codes[0], 200)             # first request allowed
        self.assertIn(429, codes)                   # bucket drains -> throttled
        # Open endpoints are never rate limited.
        self.assertEqual(self._get(port, "/healthz")[0], 200)


if __name__ == "__main__":
    unittest.main()
