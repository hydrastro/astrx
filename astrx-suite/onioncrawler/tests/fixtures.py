"""A local fixture "hidden service" for offline testing.

Serves a small interlinked site plus a battery of bot traps over stdlib
http.server on an ephemeral localhost port. The crawler reaches it through
DirectFetcher, which maps synthetic .onion hostnames to 127.0.0.1:<port>, so
the whole onion pipeline runs for real while the transport is local.

Exercised: normal pages, robots.txt disallow, a calendar/query bomb, a cyclic
path trap, a deep-path trap, duplicate-content pages, a gzip page, a chunked
page, a keyword-blocked page, a link to a blocked host, and clearnet links
(which must be refused).
"""

from __future__ import annotations

import gzip
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs


def onion(label: str) -> str:
    """Deterministic, syntactically valid v3 onion host from a letter label."""
    body = (label * 60)[:56]
    assert len(body) == 56
    return body + ".onion"


ONION_MAIN = onion("main")        # served content host
ONION_BLOCKED = onion("blocked")  # abuse-blocklisted host (must never be fetched)

# A keyword we will drop on. Tests write this into the keyword blocklist.
BLOCK_KEYWORD = "verbotenword"

# Unique, searchable tokens embedded in pages so search tests can target them.
TOKEN_PAGE_A = "alphaunicorn"
TOKEN_PAGE_B = "betazebrafindme"
TOKEN_ABOUT = "aboutsynthetic"


def _html(title, body):
    return (f"<!doctype html><html><head><title>{title}</title></head>"
            f"<body>{body}</body></html>").encode("utf-8")


def _page(links, extra=""):
    return "".join(f'<a href="{h}">{t}</a> ' for (h, t) in links) + extra


class FixtureState:
    def __init__(self):
        self.lock = threading.Lock()
        self.requests = []          # list of (host, path+query)
        self.cal_requests = 0
        self.loop_requests = 0
        self.deep_requests = 0

    def record(self, host, target):
        with self.lock:
            self.requests.append((host, target))
            if target.startswith("/trap/cal"):
                self.cal_requests += 1
            elif target.startswith("/loop/"):
                self.loop_requests += 1
            elif target.startswith("/deep/"):
                self.deep_requests += 1

    def paths(self):
        with self.lock:
            return [t for (_h, t) in self.requests]

    def hosts(self):
        with self.lock:
            return [h for (h, _t) in self.requests]


def make_handler(state: FixtureState):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _host(self):
            h = self.headers.get("Host", "")
            return h.split(":")[0].lower()

        def _send(self, body: bytes, ctype="text/html; charset=utf-8",
                  status=200, encoding=None, chunked=False, extra_headers=None):
            self.send_response(status)
            self.send_header("Content-Type", ctype)
            if encoding:
                self.send_header("Content-Encoding", encoding)
            for k, v in (extra_headers or {}).items():
                self.send_header(k, v)
            self.send_header("Connection", "close")
            if chunked:
                self.send_header("Transfer-Encoding", "chunked")
                self.end_headers()
                # emit two chunks then terminator
                mid = len(body) // 2
                for part in (body[:mid], body[mid:]):
                    self.wfile.write(b"%X\r\n%s\r\n" % (len(part), part))
                self.wfile.write(b"0\r\n\r\n")
            else:
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                if self.command != "HEAD":
                    self.wfile.write(body)

        def do_GET(self):
            u = urlparse(self.path)
            path = u.path
            target = self.path
            state.record(self._host(), target)
            qs = parse_qs(u.query)

            if path == "/robots.txt":
                self._send(b"User-agent: *\nDisallow: /secret\nCrawl-delay: 0\n",
                           "text/plain; charset=utf-8")
                return

            if path == "/":
                links = [
                    ("/page-a", "A"), ("/page-b", "B"), ("/about", "About"),
                    ("/secret/hidden", "secret"),
                    ("/dup-a", "d1"), ("/dup-b", "d2"),
                    ("/trap/cal?year=2000&month=1", "cal"),
                    ("/loop/a/b", "loop"),
                    ("/deep/1", "deep"),
                    ("/blocked-keyword", "kw"),
                    ("/noindex", "ni"),
                    (f"http://{ONION_BLOCKED}/evil", "blockedhost"),
                    ("http://example.com/", "clearnet1"),
                    ("https://not-an-onion.example/", "clearnet2"),
                ]
                self._send(_html("Index", _page(links, "welcome to the index")))
                return

            if path == "/page-a":
                body = _html("Page A " + TOKEN_PAGE_A,
                             _page([("/", "home"), ("/page-b", "B")],
                                   f"mountains {TOKEN_PAGE_A} content"))
                # exercise chunked transfer decoding
                self._send(body, chunked=True)
                return

            if path == "/page-b":
                body = _html("Page B " + TOKEN_PAGE_B,
                             _page([("/page-a", "A")],
                                   f"oceans {TOKEN_PAGE_B} content"))
                self._send(body)
                return

            if path == "/about":
                body = _html("About " + TOKEN_ABOUT,
                             f"about this synthetic onion site {TOKEN_ABOUT}")
                # exercise gzip decoding
                self._send(gzip.compress(body), encoding="gzip")
                return

            if path.startswith("/secret"):
                # robots disallows this; if the crawler ever asks, the test fails
                self._send(_html("Secret", "SECRET SHOULD NOT BE FETCHED"))
                return

            if path in ("/dup-a", "/dup-b"):
                # identical visible content -> content dedup
                self._send(_html("Dup", "this exact duplicate content is repeated verbatim"))
                return

            if path == "/trap/cal":
                year = int(qs.get("year", ["2000"])[0])
                month = int(qs.get("month", ["1"])[0])
                nm, ny = (month + 1, year) if month < 12 else (1, year + 1)
                nxt = f"/trap/cal?year={ny}&month={nm}"
                prv = f"/trap/cal?year={year}&month={month - 1 if month > 1 else 12}"
                self._send(_html(f"Cal {year}-{month}",
                                 _page([(nxt, "next"), (prv, "prev")],
                                       "calendar bomb")))
                return

            if path.startswith("/loop/"):
                # /loop/a/b -> /loop/a/b/a/b (cyclic path)
                seg = path[len("/loop/"):]
                nxt = f"/loop/{seg}/a/b"
                self._send(_html("Loop", _page([(nxt, "deeper")], "loop trap")))
                return

            if path.startswith("/deep/"):
                # /deep/1 -> /deep/1/2 -> ... (ever-deeper numeric path)
                tail = path[len("/deep/"):]
                nxt = f"/deep/{tail}/{tail.count('/') + 2}"
                self._send(_html("Deep", _page([(nxt, "deeper")], "deep trap")))
                return

            if path == "/blocked-keyword":
                body = _html("Totally Normal Title",
                             f"this page secretly contains {BLOCK_KEYWORD} in the body")
                self._send(body)
                return

            if path == "/noindex":
                body = (b"<!doctype html><html><head><title>NoIndex</title>"
                        b"<meta name=robots content=noindex></head>"
                        b"<body>should not be indexed noindexmarker</body></html>")
                self._send(body)
                return

            self._send(_html("404", "not found"), status=404)

        def do_HEAD(self):
            self.do_GET()

    return Handler


class Fixture:
    """Context-manager-ish fixture server."""

    def __init__(self):
        self.state = FixtureState()
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.state))
        self.port = self.httpd.server_address[1]
        self.thread = threading.Thread(target=self.httpd.serve_forever, daemon=True)

    def start(self):
        self.thread.start()
        return self

    def stop(self):
        self.httpd.shutdown()
        self.httpd.server_close()

    @property
    def hostmap(self):
        return {
            ONION_MAIN: ("127.0.0.1", self.port),
            ONION_BLOCKED: ("127.0.0.1", self.port),
        }

    def seed_url(self):
        return f"http://{ONION_MAIN}/"

    def __enter__(self):
        return self.start()

    def __exit__(self, *exc):
        self.stop()
        return False
