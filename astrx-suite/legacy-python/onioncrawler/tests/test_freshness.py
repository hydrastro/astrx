"""Roadmap #2 - freshness + recrawl: Last-Modified/ETag storage, conditional
GET, 304 handling, and the interval-based recrawl scheduler."""

import os
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from onioncrawler.config import Config
from onioncrawler.storage import Storage
from onioncrawler.crawler import Crawler
from onioncrawler.fetcher import DirectFetcher
from onioncrawler.abuse import AbuseFilter

HOST = "f" * 56 + ".onion"
ETAG = '"v1-abc"'


class _State:
    def __init__(self):
        self.conditional_hits = 0
        self.full_hits = 0


def _handler(state):
    class H(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _send(self, body, status=200, ctype="text/html; charset=utf-8",
                  headers=None):
            self.send_response(status)
            if ctype:
                self.send_header("Content-Type", ctype)
            for k, v in (headers or {}).items():
                self.send_header(k, v)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            if self.command != "HEAD" and body:
                self.wfile.write(body)

        def do_GET(self):
            if self.path == "/robots.txt":
                self._send(b"", status=404)
                return
            inm = self.headers.get("If-None-Match")
            if inm == ETAG:
                state.conditional_hits += 1
                self._send(b"", status=304, ctype=None,
                           headers={"ETag": ETAG})
                return
            state.full_hits += 1
            body = (b"<!doctype html><html><head><title>Fresh</title></head>"
                    b"<body>freshtoken stable content body</body></html>")
            self._send(body, headers={"ETag": ETAG,
                                      "Last-Modified": "Wed, 01 Jan 2025 00:00:00 GMT"})

        def do_HEAD(self):
            self.do_GET()
    return H


class _Server:
    def __init__(self, state):
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), _handler(state))
        self.port = self.httpd.server_address[1]
        self.t = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    def start(self):
        self.t.start()
        return self

    def stop(self):
        self.httpd.shutdown()
        self.httpd.server_close()


def _mkcrawler(db, port):
    cfg = Config()
    cfg.db_path = db
    cfg.fetcher = "direct"
    cfg.crawl_delay = 0.0
    cfg.crawl_delay_jitter = 0.0
    cfg.workers = 1
    cfg.max_depth = 1
    cfg.recrawl_ttl = 1000.0
    cfg.recrawl_backoff = 2.0
    st = Storage(db)
    fetcher = DirectFetcher(hostmap={HOST: ("127.0.0.1", port)})
    return cfg, st, Crawler(cfg, st, fetcher, AbuseFilter())


class TestConditionalGetAnd304(unittest.TestCase):
    def setUp(self):
        self.state = _State()
        self.srv = _Server(self.state).start()
        self.db = os.path.join(tempfile.mkdtemp(), "fresh.db")

    def tearDown(self):
        self.srv.stop()

    def test_second_fetch_is_conditional_and_304_bumps_not_reindexes(self):
        cfg, st, cr = _mkcrawler(self.db, self.srv.port)
        try:
            cr.run([f"http://{HOST}/"])
            row1 = st.get_page(f"http://{HOST}/")
            self.assertIsNotNone(row1)
            self.assertEqual(row1["etag"], ETAG)                      # #2 stored ETag
            self.assertEqual(row1["last_modified"][:3], "Wed")       # stored Last-Modified
            self.assertEqual(self.state.full_hits, 1)
            fetched1, seen1 = row1["fetched_at"], row1["last_seen"]

            time.sleep(0.02)
            # make it due for recrawl and run again
            st.db.execute("UPDATE frontier SET status='queued' WHERE url=?",
                          (f"http://{HOST}/",))
            cr.run()

            # the re-fetch used a conditional GET and got a 304
            self.assertEqual(self.state.conditional_hits, 1,
                             "second fetch was not a conditional GET / 304")
            row2 = st.get_page(f"http://{HOST}/")
            # 304 => last_seen bumped, fetched_at (index time) unchanged (no reindex)
            self.assertGreater(row2["last_seen"], seen1)
            self.assertEqual(row2["fetched_at"], fetched1)
            # recrawl interval backed off (unchanged page => crawl less often)
            self.assertGreater(row2["recrawl_interval"], cfg.recrawl_ttl)
        finally:
            st.close()


class TestRecrawlScheduler(unittest.TestCase):
    def setUp(self):
        self.db = os.path.join(tempfile.mkdtemp(), "sched.db")

    def test_due_pages_requeued_respecting_interval(self):
        st = Storage(self.db)
        try:
            host = "g" * 56 + ".onion"
            st.ensure_host(host)
            now = time.time()
            # a fresh page (not due) and a stale page (due)
            st.store_page(f"http://{host}/fresh", host, "F", "body one",
                          "h1", 200, "text/html", 10, now, interval=10000)
            st.store_page(f"http://{host}/stale", host, "S", "body two",
                          "h2", 200, "text/html", 10, now - 5000, interval=1000)
            for u in (f"http://{host}/fresh", f"http://{host}/stale"):
                st.db.execute(
                    "INSERT INTO frontier(url,host,depth,status,priority,"
                    "enqueued_at,lease_expires) VALUES(?,?,0,'done',0,?,0)",
                    (u, host, now))
            n = st.mark_recrawl_due(now=now, default_interval=99999)
            self.assertEqual(n, 1, "exactly the stale page should be due")
            statuses = {r["url"]: r["status"] for r in st.db.execute(
                "SELECT url, status FROM frontier")}
            self.assertEqual(statuses[f"http://{host}/stale"], "queued")
            self.assertEqual(statuses[f"http://{host}/fresh"], "done")
        finally:
            st.close()


if __name__ == "__main__":
    unittest.main()
