"""Regression: PDF stream extraction must be O(n), not O(n^2).

The old splitter used `re.finditer(rb"stream\r?\n(.*?)\r?\nendstream")`; when a
document has many `stream` keywords but no `endstream`, the lazy `.*?` rescans to
EOF at every offset (quadratic) while the budget checks — sitting inside the
match loop — never run. A crafted ~2 MB body then burns minutes/hours of CPU and
pins a crawl worker. The linear `bytes.find` walk terminates in one pass."""
import time
import unittest

from websearch import pdftext


class TestPdfLinear(unittest.TestCase):
    def test_no_endstream_bomb_terminates_fast(self):
        # ~1 MB: many 'stream\n' keywords, zero 'endstream'.
        body = b"%PDF-1.4\n" + b"/FlateDecode stream\nBT junk " * 40000
        t = time.monotonic()
        txt = pdftext.extract_text(body)
        dt = time.monotonic() - t
        self.assertIsInstance(txt, str)
        # The old O(n^2) scan took tens of seconds at ~120 KB; linear is ~1 ms.
        self.assertLess(dt, 3.0, f"PDF stream scan is not linear: {dt:.2f}s")

    def test_wellformed_stream_still_extracted(self):
        import zlib
        payload = b"BT (hello world) Tj ET"
        comp = zlib.compress(payload)
        body = b"%PDF-1.4\n/FlateDecode stream\n" + comp + b"\nendstream\n"
        txt = pdftext.extract_text(body)
        self.assertIn("hello", txt.lower())


if __name__ == "__main__":
    unittest.main()
