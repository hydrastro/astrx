"""Round-27 security/correctness fixes (adversarial review of the new surface).

Covers, one regression per fix:

  1. PDF text extraction has an AGGREGATE inflate/scan budget -- a PDF packed
     with many near-cap FlateDecode "bomb" streams that yield no text is bounded
     in time and bytes (previously ~90s of CPU for a 2 MB input).
  2. The keep-alive Fetcher retries an idempotent GET ONCE on a fresh,
     re-validated connection when a pooled socket has gone stale (peer closed an
     idle keep-alive connection) -- the URL is served, not dropped -- and the
     SSRF denylist still blocks an internal redirect reached on that retry.
  3. The DNS cache is bounded (expired entries purged / size capped) so a broad
     crawl cannot leak memory.
  4. ``site:`` / ``filetype:`` LIKE filters escape ``%``/``_`` so ``site:%``
     cannot broaden the filter to every host.
"""

import os
import socket
import tempfile
import threading
import time
import unittest
import zlib

from websearch import canonical, httpclient, index, pdftext, ranking


# ---------------------------------------------------------------------------
# Fix 1: PDF aggregate CPU/inflation budget
# ---------------------------------------------------------------------------
class PdfBudgetTest(unittest.TestCase):
    def _flate_stream(self, payload):
        comp = zlib.compress(payload, 9)
        return b"<< /Filter /FlateDecode >>\nstream\n" + comp + b"\nendstream\n"

    def test_no_text_bomb_is_time_and_byte_bounded(self):
        # Each stream inflates to ~8 MB (the per-stream cap), contains "BT" so it
        # is scanned, but has NO string operands -> yields no text -> the old
        # max_chars break never fires. The aggregate budget must stop it anyway.
        one = b"BT " + b"A" * 8_000_000
        pdf = b"%PDF-1.4\n" + self._flate_stream(one) * 60 + b"%%EOF"
        t0 = time.perf_counter()
        out = pdftext.extract_text(pdf)
        dt = time.perf_counter() - t0
        self.assertEqual(out, "")                 # genuinely no recoverable text
        self.assertLess(dt, 5.0, "PDF extraction not bounded (%.1fs)" % dt)

    def test_many_streams_capped(self):
        # A pathological stream count must not be walked in full.
        tiny = b"BT " + b"A" * 1000
        pdf = b"%PDF-1.4\n" + self._flate_stream(tiny) * 20000 + b"%%EOF"
        t0 = time.perf_counter()
        pdftext.extract_text(pdf)
        self.assertLess(time.perf_counter() - t0, 5.0)

    def test_legit_flate_pdf_still_extracts(self):
        # The budget must not break ordinary text-first PDFs.
        content = b"BT (roundtwentysevenmarker) Tj ET"
        pdf = b"%PDF-1.4\n" + self._flate_stream(content) + b"%%EOF"
        self.assertIn("roundtwentysevenmarker", pdftext.extract_text(pdf))


# ---------------------------------------------------------------------------
# Fix 2: keep-alive retry on a stale pooled connection (still SSRF-checked)
# ---------------------------------------------------------------------------
class _ServeOnceServer:
    """HTTP/1.1 server that answers ONE request per connection then closes it.

    Mimics a server/load-balancer dropping an idle persistent connection between
    two (politeness-delayed) crawler fetches. ``/redir`` 302s to an internal IP.
    """

    def __init__(self):
        self.sock = socket.socket()
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(("127.0.0.1", 0))
        self.sock.listen(16)
        self.port = self.sock.getsockname()[1]
        self.thread = threading.Thread(target=self._accept, daemon=True)
        self.thread.start()

    @property
    def base(self):
        return "http://127.0.0.1:%d" % self.port

    def _accept(self):
        while True:
            try:
                conn, _ = self.sock.accept()
            except OSError:
                return
            threading.Thread(target=self._handle, args=(conn,),
                             daemon=True).start()

    def _handle(self, conn):
        try:
            data = conn.recv(65536)
            path = data.split(b" ")[1] if b" " in data else b"/"
            if path == b"/redir":
                conn.sendall(
                    b"HTTP/1.1 302 Found\r\n"
                    b"Location: http://169.254.169.254/\r\n"
                    b"Content-Length: 0\r\nConnection: keep-alive\r\n\r\n")
            else:
                body = b"<html><title>t</title>okbody roundtwentyseven</html>"
                conn.sendall(
                    b"HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n"
                    b"Content-Length: %d\r\nConnection: keep-alive\r\n\r\n"
                    % len(body) + body)
        except OSError:
            pass
        finally:
            try:
                conn.close()          # drop the now-idle keep-alive connection
            except OSError:
                pass

    def stop(self):
        try:
            self.sock.close()
        except OSError:
            pass


class KeepAliveRetryTest(unittest.TestCase):
    def setUp(self):
        self.srv = _ServeOnceServer()
        self.ah = [canonical.authority_of(self.srv.base)]
        httpclient.clear_dns_cache()

    def tearDown(self):
        self.srv.stop()

    def test_stale_pooled_connection_is_retried_not_dropped(self):
        f = httpclient.Fetcher(keep_alive=True)
        try:
            r1 = f.fetch(self.srv.base + "/ok", allow_hosts=self.ah)
            self.assertEqual(r1.status, 200)          # pools a conn; server closes it
            time.sleep(0.1)
            r2 = f.fetch(self.srv.base + "/ok", allow_hosts=self.ah)
            # The pooled socket was dead; instead of erroring, the client retried
            # on a fresh connection and served the page.
            self.assertEqual(r2.status, 200, "stale reuse dropped the URL: %r"
                             % r2.error)
            self.assertIn(b"okbody", r2.body)
            self.assertGreaterEqual(f.reused, 1)      # it *did* try the pool
            self.assertGreaterEqual(f.opened, 2)      # and opened a fresh socket
        finally:
            f.close()

    def test_internal_redirect_on_retry_still_blocked(self):
        f = httpclient.Fetcher(keep_alive=True)
        try:
            f.fetch(self.srv.base + "/ok", allow_hosts=self.ah)   # leave a stale conn
            time.sleep(0.1)
            res = f.fetch(self.srv.base + "/redir",
                          allow=lambda u: True, allow_hosts=self.ah)
            # Reuse of the stale conn failed -> fresh retry -> 302 -> the internal
            # redirect target must still be refused by the denylist.
            self.assertEqual(res.redirects, 1)
            self.assertTrue((res.error or "").startswith("blocked-internal"),
                            "internal redirect not blocked on retry: %r" % res.error)
        finally:
            f.close()


# ---------------------------------------------------------------------------
# Fix 3: DNS cache is bounded
# ---------------------------------------------------------------------------
class DnsCacheBoundTest(unittest.TestCase):
    def tearDown(self):
        httpclient.clear_dns_cache()

    def test_cache_stays_bounded(self):
        httpclient.clear_dns_cache()
        real = httpclient.socket.getaddrinfo
        old_max = httpclient.DNS_CACHE_MAX
        httpclient.DNS_CACHE_MAX = 16

        def stub(host, port, *a, **k):
            return [(socket.AF_INET, socket.SOCK_STREAM, 6, "",
                     ("93.184.216.34", port))]

        httpclient.socket.getaddrinfo = stub
        try:
            for i in range(500):
                httpclient._getaddrinfo_cached("h%d.example" % i, 80)
            self.assertLessEqual(len(httpclient._DNS_CACHE),
                                 httpclient.DNS_CACHE_MAX)
        finally:
            httpclient.socket.getaddrinfo = real
            httpclient.DNS_CACHE_MAX = old_max

    def test_expired_entries_purged(self):
        httpclient.clear_dns_cache()
        real = httpclient.socket.getaddrinfo
        old_max = httpclient.DNS_CACHE_MAX
        httpclient.DNS_CACHE_MAX = 4

        def stub(host, port, *a, **k):
            return [(socket.AF_INET, socket.SOCK_STREAM, 6, "",
                     ("93.184.216.34", port))]

        httpclient.socket.getaddrinfo = stub
        try:
            # Seed the cache full of ALREADY-EXPIRED entries...
            past = time.monotonic() - 1.0
            for i in range(4):
                httpclient._DNS_CACHE[("stale%d" % i, 80)] = (past, [])
            # ...then resolve a new host: the expired ones must be purged, not
            # accumulate past the bound.
            httpclient._getaddrinfo_cached("fresh.example", 80)
            self.assertIn(("fresh.example", 80), httpclient._DNS_CACHE)
            self.assertLessEqual(len(httpclient._DNS_CACHE),
                                 httpclient.DNS_CACHE_MAX)
        finally:
            httpclient.socket.getaddrinfo = real
            httpclient.DNS_CACHE_MAX = old_max


# ---------------------------------------------------------------------------
# Fix 4: site:/filetype: LIKE wildcards escaped
# ---------------------------------------------------------------------------
class LikeWildcardTest(unittest.TestCase):
    def setUp(self):
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.conn = index.connect(self.db)
        for h in ("aaa.com", "bbb.net", "ccc.org"):
            index.upsert_document(self.conn, "http://%s/p" % h, "alpha", "d",
                                  "alpha body", host=h, lang="en",
                                  content_type="text/html")
        self.conn.commit()

    def tearDown(self):
        self.conn.close()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def test_site_percent_does_not_match_all_hosts(self):
        for site in ("%", "_%", "%%"):
            _, total, _, _ = ranking.search(self.conn, "alpha site:%s" % site)
            self.assertEqual(total, 0, "site:%s broadened to %d hosts"
                             % (site, total))

    def test_normal_site_filter_still_works(self):
        _, total, _, _ = ranking.search(self.conn, "alpha site:aaa.com")
        self.assertEqual(total, 1)


if __name__ == "__main__":
    unittest.main()
