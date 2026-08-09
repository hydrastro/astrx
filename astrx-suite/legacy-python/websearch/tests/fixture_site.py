"""A local fixture website served on 127.0.0.1 for offline crawler tests.

Interlinked HTML pages with titles/descriptions/varied text, a ``robots.txt``
that disallows ``/private/``, a redirect, a gzip-encoded page, a ``rel=canonical``
alias, duplicate pages and two crawler traps (path-segment repeat and query
explosion).  All internal links are relative, so the site works on any port.

Request counts per path are recorded in ``FixtureSite.hits`` so tests can assert
that resumed crawls do not refetch completed URLs.
"""

import collections
import gzip
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit, parse_qsl, urlencode

ROBOTS = "User-agent: *\nDisallow: /private/\nCrawl-delay: 0\n"

# Static pages keyed by path.  Links are relative on purpose.
PAGES = {
    "/": """<!doctype html><html lang=en><head>
<title>Fixture Home - Test Corpus</title>
<meta name=description content="Home page linking the whole fixture corpus.">
</head><body>
<nav><a href="/">home</a> | <a href="/about-nav">nav</a></nav>
<h1>Welcome to the fixture corpus</h1>
<p>This tiny site exists to exercise the crawler and the index. Explore:</p>
<ul>
<li><a href="/search-engines">how search engines work</a></li>
<li><a href="/python">the Python programming language</a></li>
<li><a href="/rust">the Rust programming language</a></li>
<li><a href="/go">the Go programming language</a></li>
<li><a href="/gzipped">a gzip-encoded page</a></li>
<li><a href="/redirect-me">a redirect</a></li>
<li><a href="/alias">a canonical alias</a></li>
<li><a href="/dup-a">duplicate a</a> and <a href="/dup-b">duplicate b</a></li>
<li><a href="/private/secret">a private page (robots-disallowed)</a></li>
<li><a href="/trap/">a crawler trap</a></li>
</ul>
</body></html>""",

    "/search-engines": """<!doctype html><html lang=en><head>
<title>How Search Engines Work: Crawler, Inverted Index, Ranking</title>
<meta name=description content="A search engine crawls pages, builds an inverted index, and ranks results with bm25.">
</head><body>
<h1>How a search engine works</h1>
<p>A search engine has three parts: a crawler that fetches pages, an inverted
index that maps each term to the documents containing it, and a ranking
function. This fixture's own engine uses an inverted index stored in SQLite
FTS5, and ranks results by combining bm25 text relevance with a link
popularity signal and freshness. The inverted index is the heart of the search
engine; without an inverted index, query evaluation over a large crawl would be
hopeless. Good ranking turns raw inverted-index matches into a useful search
result ordering.</p>
<p><a href="/python">Python</a> is often used to build a crawler.</p>
</body></html>""",

    "/python": """<!doctype html><html lang=en><head>
<title>The Python Programming Language</title>
<meta name=description content="Python is a high-level programming language.">
</head><body>
<h1>Python</h1>
<p>Python is a popular high-level programming language known for readable
syntax. The Python programming language is widely used for scripting, data
analysis and building a web crawler. Python emphasises developer productivity.</p>
<p>See also <a href="/rust">Rust</a> and <a href="/go">Go</a>.</p>
</body></html>""",

    "/rust": """<!doctype html><html lang=en><head>
<title>The Rust Programming Language</title>
<meta name=description content="Rust is a systems programming language.">
</head><body>
<h1>Rust</h1>
<p>Rust is a systems programming language focused on safety and performance.
The Rust programming language prevents data races at compile time. Rust is a
compiled language with zero-cost abstractions.</p>
<p>Back to <a href="/">home</a>.</p>
</body></html>""",

    "/go": """<!doctype html><html lang=en><head>
<title>The Go Programming Language</title>
<meta name=description content="Go is a compiled programming language.">
</head><body>
<h1>Go</h1>
<p>Go is a compiled programming language designed at Google for simplicity and
concurrency. The Go programming language has goroutines for concurrency. Go is
a statically typed language.</p>
</body></html>""",

    "/about-nav": """<!doctype html><html lang=en><head>
<title>Navigation Target</title></head><body>
<p>Just a page linked from the nav bar.</p>
<a href="/">home</a></body></html>""",

    "/private/secret": """<!doctype html><html lang=en><head>
<title>SECRET private page</title></head><body>
<p>This page is disallowed by robots.txt and must never be indexed. secretword.</p>
</body></html>""",

    "/alias": """<!doctype html><html lang=en><head>
<title>Alias of Python</title>
<link rel="canonical" href="/python">
</head><body><p>This alias should resolve to the Python page via canonical.</p>
</body></html>""",

    "/dup-a": """<!doctype html><html lang=en><head>
<title>Duplicated Content Page</title>
<meta name=description content="Identical duplicate content.">
</head><body><p>This exact paragraph of duplicated content appears twice in the
corpus to exercise content-hash dedup. uniquedupmarker.</p></body></html>""",

    "/dup-b": """<!doctype html><html lang=en><head>
<title>Duplicated Content Page</title>
<meta name=description content="Identical duplicate content.">
</head><body><p>This exact paragraph of duplicated content appears twice in the
corpus to exercise content-hash dedup. uniquedupmarker.</p></body></html>""",
}

GZIP_HTML = """<!doctype html><html lang=en><head>
<title>A Gzip Encoded Page</title>
<meta name=description content="Served with Content-Encoding: gzip.">
</head><body><p>This page is delivered gzip encoded to test transparent
decompression. gzipmarker appears here exactly once.</p></body></html>"""

PLAIN_TXT = ("plaintextmarker\nThis is a plain text document served as "
             "text/plain to exercise the widened content-type allowlist. "
             "It mentions inverted index and ranking in passing.\n")


def _build_pdf(content_text, title="Fixture PDF Title"):
    """A minimal (uncompressed) but structurally-plausible PDF for extraction."""
    content = ("BT /F1 12 Tf (%s) Tj ET" % content_text).encode("latin-1")
    objs = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /Contents 4 0 R "
        b"/MediaBox [0 0 612 792] >>",
        b"<< /Length %d >>\nstream\n%s\nendstream" % (len(content), content),
        b"<< /Title (%s) >>" % title.encode("latin-1"),
    ]
    out = bytearray(b"%PDF-1.4\n")
    for i, body in enumerate(objs, 1):
        out += b"%d 0 obj\n" % i + body + b"\nendobj\n"
    out += b"trailer << /Root 1 0 R /Info 5 0 R >>\n%%EOF"
    return bytes(out)


PDF_BYTES = _build_pdf(
    "pdftextmarker extracted from a real content stream about ranking")


def _trap_page(path, query):
    """Generate a trap page that links deeper (path repeat + query explosion)."""
    pairs = parse_qsl(query, keep_blank_values=True)
    links = []
    # path-repeat arm: append another "x" segment each hop
    links.append('<a href="%s">deeper</a>' % (path.rstrip("/") + "/x"))
    # query-explosion arm: add one more parameter each hop
    next_key = "abcdefghijklmnop"[len(pairs)] if len(pairs) < 16 else "z"
    newq = urlencode(pairs + [(next_key, "1")])
    base = path if path.startswith("/trap/cal") else "/trap/cal"
    links.append('<a href="%s?%s">calendar</a>' % (base, newq))
    return ("<!doctype html><html lang=en><head><title>Trap %s</title></head>"
            "<body><p>Infinite trap page. %s</p></body></html>"
            % (path, " ".join(links)))


class _Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    hits = None  # set by make_handler

    def log_message(self, *a):
        pass

    def _write(self, status, body, ctype="text/html; charset=utf-8", extra=None):
        if isinstance(body, str):
            body = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.send_header("Connection", "close")
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(body)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        parts = urlsplit(self.path)
        path = parts.path
        self.hits[self.path] += 1

        if path == "/robots.txt":
            return self._write(200, ROBOTS, "text/plain; charset=utf-8")
        if path == "/etag":
            # A validator-bearing page. A conditional GET (If-None-Match /
            # If-Modified-Since) gets a 304; a plain GET gets 200 + validators.
            inm = self.headers.get("If-None-Match")
            ims = self.headers.get("If-Modified-Since")
            vals = {"ETag": '"etagv1"',
                    "Last-Modified": "Wed, 01 Jan 2020 00:00:00 GMT"}
            if inm == '"etagv1"' or ims:
                return self._write(304, b"", extra=vals)
            body = ("<!doctype html><html lang=en><head><title>Etag Page</title>"
                    "</head><body><p>etagmarker conditional revalidation page."
                    "</p></body></html>")
            return self._write(200, body, extra=vals)
        if path == "/ka":
            # Served WITHOUT "Connection: close" so the HTTP/1.1 server keeps the
            # socket open -- lets tests exercise client-side keep-alive reuse.
            body = ("<!doctype html><html lang=en><head><title>KeepAlive</title>"
                    "</head><body><p>keepalivemarker persistent-connection page."
                    "</p></body></html>").encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(body)
            return
        if path == "/plain.txt":
            return self._write(200, PLAIN_TXT, "text/plain; charset=utf-8")
        if path == "/doc.pdf":
            return self._write(200, PDF_BYTES, "application/pdf")
        if path == "/gzipped":
            gz = gzip.compress(GZIP_HTML.encode("utf-8"))
            return self._write(200, gz, extra={"Content-Encoding": "gzip"})
        if path == "/redirect-me":
            return self._write(302, b"", extra={"Location": "/go"})
        if path == "/redirect-internal":
            # Not linked from any page; only reached by explicit request. Used to
            # prove that redirect hops to internal IPs are refused (SSRF guard).
            return self._write(
                302, b"", extra={"Location": "http://169.254.169.254/"})
        if path == "/bomb":
            # A gzip "bomb": ~32 MiB of zeros -> ~32 KiB on the wire. Used to
            # prove decompression is capped. Not linked from any page.
            gz = gzip.compress(b"\0" * (32 * 1024 * 1024))
            return self._write(200, gz, extra={"Content-Encoding": "gzip"})
        if path == "/trap/" or path.startswith("/trap/"):
            return self._write(200, _trap_page(path, parts.query))
        if path in PAGES:
            return self._write(200, PAGES[path])
        return self._write(404, "<h1>404</h1>")


def make_handler(hits):
    return type("BoundFixtureHandler", (_Handler,), {"hits": hits})


class FixtureSite:
    """Start/stop a fixture site on an ephemeral loopback port."""

    def __init__(self):
        self.hits = collections.Counter()
        self.server = None
        self.thread = None
        self.port = None

    def start(self):
        handler = make_handler(self.hits)
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
        self.port = self.server.server_address[1]
        self.thread = threading.Thread(
            target=self.server.serve_forever, kwargs={"poll_interval": 0.05},
            daemon=True)
        self.thread.start()
        return self

    @property
    def base(self):
        return "http://127.0.0.1:%d" % self.port

    def url(self, path="/"):
        return self.base + path

    def stop(self):
        if self.server is not None:
            self.server.shutdown()
            self.server.server_close()
        if self.thread is not None:
            self.thread.join(timeout=3)


if __name__ == "__main__":  # manual: python3 -m tests.fixture_site
    site = FixtureSite().start()
    print("fixture at", site.base, "(Ctrl-C to stop)")
    try:
        while True:
            import time
            time.sleep(1)
    except KeyboardInterrupt:
        site.stop()
