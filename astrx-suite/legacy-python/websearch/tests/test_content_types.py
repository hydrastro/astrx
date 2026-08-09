"""Feature 6: text/plain (widened allowlist) + optional best-effort PDF text."""

import os
import tempfile
import unittest
import zlib

from websearch import canonical, index, pdftext
from websearch.crawler import Crawler
try:
    from tests.common import make_config
except ImportError:  # discover -s tests (top-level = tests/)
    from common import make_config
try:
    from tests.fixture_site import FixtureSite, PDF_BYTES
except ImportError:
    from fixture_site import FixtureSite, PDF_BYTES


class PdfTextUnitTest(unittest.TestCase):
    def test_extracts_uncompressed_content_stream(self):
        text = pdftext.extract_text(PDF_BYTES)
        self.assertIn("pdftextmarker", text)
        self.assertIn("ranking", text)

    def test_extracts_flatedecode_stream(self):
        content = b"BT (flatemarker in a compressed stream) Tj ET"
        comp = zlib.compress(content)
        pdf = (b"%PDF-1.4\n1 0 obj\n<< /Length " + str(len(comp)).encode()
               + b" /Filter /FlateDecode >>\nstream\n" + comp
               + b"\nendstream\nendobj\n%%EOF")
        self.assertIn("flatemarker", pdftext.extract_text(pdf))

    def test_extracts_title(self):
        self.assertEqual(pdftext.extract_title(PDF_BYTES), "Fixture PDF Title")

    def test_non_pdf_returns_empty(self):
        self.assertEqual(pdftext.extract_text(b"not a pdf, just bytes"), "")
        self.assertEqual(pdftext.extract_text(b""), "")


class ContentTypeCrawlTest(unittest.TestCase):
    def setUp(self):
        self.site = FixtureSite().start()
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(self.db)

    def tearDown(self):
        self.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def _crawl(self, seed, **overrides):
        conn = index.connect(self.db)
        cr = Crawler(conn, make_config(self.site, **overrides))
        cr.add_seeds([self.site.url(seed)])
        stats = cr.run()
        conn.commit()
        return conn, stats

    def test_indexes_text_plain(self):
        conn, stats = self._crawl("/plain.txt")
        self.assertEqual(stats["indexed"], 1)
        row = conn.execute(
            "SELECT body, content_type FROM docs WHERE url LIKE '%/plain.txt'"
        ).fetchone()
        self.assertIn("plaintextmarker", row["body"])
        self.assertEqual(row["content_type"], "text/plain")
        conn.close()

    def test_pdf_skipped_by_default(self):
        conn, _ = self._crawl("/doc.pdf")           # index_pdf defaults False
        self.assertEqual(
            conn.execute("SELECT COUNT(*) FROM docs").fetchone()[0], 0)
        reason = conn.execute(
            "SELECT reason FROM frontier WHERE url LIKE '%/doc.pdf'"
        ).fetchone()[0]
        self.assertTrue((reason or "").startswith("ctype-"))
        conn.close()

    def test_pdf_indexed_when_enabled(self):
        conn, stats = self._crawl("/doc.pdf", index_pdf=True)
        self.assertEqual(stats["indexed"], 1)
        row = conn.execute(
            "SELECT body, title, content_type FROM docs "
            "WHERE url LIKE '%/doc.pdf'").fetchone()
        self.assertIn("pdftextmarker", row["body"])
        self.assertEqual(row["title"], "Fixture PDF Title")
        self.assertEqual(row["content_type"], "application/pdf")
        # And it is searchable through the normal index path.
        from websearch import ranking
        results, total, _, _ = ranking.search(conn, "pdftextmarker")
        self.assertGreaterEqual(total, 1)
        conn.close()


if __name__ == "__main__":
    unittest.main()
