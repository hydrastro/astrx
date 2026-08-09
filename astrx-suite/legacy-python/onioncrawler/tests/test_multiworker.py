"""Roadmap #3 - multi-worker crawl driver (already lease-based) + HTTP keep-alive
connection reuse per host + Tor circuit reuse via stream isolation."""

import os
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from onioncrawler.config import Config
from onioncrawler.storage import Storage
from onioncrawler.crawler import Crawler
from onioncrawler.fetcher import DirectFetcher, TorSocksFetcher
from onioncrawler.abuse import AbuseFilter

HOST = "a" * 56 + ".onion"


class _KeepAliveHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"   # keep-alive by default with Content-Length

    def log_message(self, *a):
        pass

    def do_GET(self):
        body = b"<!doctype html><title>t</title><body>keepalivetoken body</body>"
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()          # NB: no Connection: close -> reusable
        self.wfile.write(body)


class _CountingServer(ThreadingHTTPServer):
    def __init__(self, *a, **k):
        super().__init__(*a, **k)
        self.conn_count = 0
        self._lk = threading.Lock()

    def get_request(self):
        req = super().get_request()   # one call per accepted TCP connection
        with self._lk:
            self.conn_count += 1
        return req


class TestConnectionReuse(unittest.TestCase):
    def setUp(self):
        self.srv = _CountingServer(("127.0.0.1", 0), _KeepAliveHandler)
        self.port = self.srv.server_address[1]
        threading.Thread(target=self.srv.serve_forever, daemon=True).start()

    def tearDown(self):
        self.srv.shutdown()
        self.srv.server_close()

    def _fetch_twice(self, reuse):
        f = DirectFetcher(hostmap={HOST: ("127.0.0.1", self.port)},
                          reuse_connections=reuse)
        r1 = f.fetch(f"http://{HOST}/a")
        r2 = f.fetch(f"http://{HOST}/b")
        f.close()
        self.assertTrue(r1.ok and r2.ok)

    def test_reuse_uses_one_connection(self):
        self._fetch_twice(reuse=True)
        self.assertEqual(self.srv.conn_count, 1,
                         "keep-alive pool should reuse one connection")

    def test_no_reuse_opens_two_connections(self):
        self._fetch_twice(reuse=False)
        self.assertEqual(self.srv.conn_count, 2,
                         "without reuse each fetch opens a fresh connection")


class TestCircuitReuse(unittest.TestCase):
    def test_stream_isolation_gives_stable_per_host_circuit(self):
        f = TorSocksFetcher(proxy_host="127.0.0.1", proxy_port=1,
                            stream_isolation=True, isolation_secret="s")
        host_b = "b" * 56 + ".onion"
        a1 = f._iso_creds(HOST)
        a2 = f._iso_creds(HOST)
        b1 = f._iso_creds(host_b)
        self.assertEqual(a1, a2, "same host -> same circuit (reused)")
        self.assertNotEqual(a1, b1, "different host -> different circuit")
        self.assertTrue(a1[0].startswith("host-"))
        # isolation off -> a single shared circuit (no per-host creds)
        f2 = TorSocksFetcher(proxy_host="127.0.0.1", proxy_port=1,
                             stream_isolation=False)
        self.assertEqual(f2._iso_creds(HOST), (None, None))


def _multi_handler(hosts):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _host(self):
            return self.headers.get("Host", "").split(":")[0].lower()

        def do_GET(self):
            if self.path == "/robots.txt":
                b = b""
                self.send_response(404)
            else:
                who = self._host()
                b = (f"<!doctype html><title>{who[:6]}</title><body>"
                     f"multitoken page for {who[:6]} "
                     f'<a href="/p1">1</a></body>').encode()
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(b)))
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD" and b:
                self.wfile.write(b)
    return H


class TestMultiWorkerDrains(unittest.TestCase):
    def test_three_workers_crawl_three_hosts(self):
        hosts = [c * 56 + ".onion" for c in "cde"]
        srv = ThreadingHTTPServer(("127.0.0.1", 0), _multi_handler(hosts))
        port = srv.server_address[1]
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        db = os.path.join(tempfile.mkdtemp(), "mw.db")
        cfg = Config()
        cfg.db_path = db
        cfg.fetcher = "direct"
        cfg.crawl_delay = 0.0
        cfg.crawl_delay_jitter = 0.0
        cfg.workers = 3
        st = Storage(db)
        fetcher = DirectFetcher(
            hostmap={h: ("127.0.0.1", port) for h in hosts},
            reuse_connections=True)
        cr = Crawler(cfg, st, fetcher, AbuseFilter())

        done = {}

        def _go():
            done["stats"] = cr.run([f"http://{h}/" for h in hosts])

        t = threading.Thread(target=_go)
        t.start()
        t.join(30)
        try:
            self.assertFalse(t.is_alive(), "multi-worker crawl did not terminate")
            # every seeded host was crawled + indexed by the worker pool
            crawled = {r["host"] for r in
                       st.db.execute("SELECT DISTINCT host FROM pages")}
            for h in hosts:
                self.assertIn(h, crawled, f"worker pool missed host {h[:6]}")
            self.assertEqual(done["stats"]["frontier_by_status"].get("queued", 0), 0)
        finally:
            cr.stop.set()
            srv.shutdown()
            srv.server_close()
            st.close()


if __name__ == "__main__":
    unittest.main()
