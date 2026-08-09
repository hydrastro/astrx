"""Feature 4: query operators/filters -- site:, lang:, filetype:, intitle:, dates."""

import os
import tempfile
import unittest

from websearch import index, ranking


class OperatorTest(unittest.TestCase):
    def setUp(self):
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        self.conn = index.connect(self.db)
        self._seed()

    def tearDown(self):
        self.conn.close()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(self.db + suffix)
            except OSError:
                pass

    def _seed(self):
        # (url, title, body, lang, content_type, fetched_at)
        rows = [
            ("http://a.example.com/p", "Python on A",
             "python widget alpha", "en", "text/html", 1_600_000_000.0),
            ("http://b.other.org/p", "Python on B",
             "python widget beta", "en", "text/html", 1_600_000_000.0),
            ("http://c.example.com/doc.pdf", "Report",
             "python widget gamma pdf", "en", "application/pdf",
             1_600_000_000.0),
            ("http://d.example.com/es", "Guia Python",
             "python widget delta", "es", "text/html", 1_500_000_000.0),
            ("http://e.example.com/old", "Ancient Python",
             "python widget epsilon", "en", "text/html", 1_000_000_000.0),
        ]
        for url, title, body, lang, ct, ts in rows:
            index.upsert_document(self.conn, url, title, "", body, lang=lang,
                                  fetched_at=ts, content_type=ct)
        self.conn.commit()

    def _hosts(self, results):
        return {r.host for r in results}

    def test_site_operator_restricts_host(self):
        q = ranking.parse_query("python site:example.com")
        self.assertEqual(q.site, "example.com")
        self.assertEqual(q.optional, ["python"])   # operator stripped from terms
        results, total, _, _ = ranking.search(self.conn, "python site:example.com")
        self.assertGreater(total, 0)
        self.assertNotIn("b.other.org", self._hosts(results))
        for r in results:
            self.assertTrue(r.host == "example.com"
                            or r.host.endswith(".example.com"), r.host)

    def test_lang_operator(self):
        results, _, _, _ = ranking.search(self.conn, "python lang:es")
        hosts = self._hosts(results)
        self.assertEqual(hosts, {"d.example.com"})

    def test_filetype_operator(self):
        results, _, _, _ = ranking.search(self.conn, "python filetype:pdf")
        hosts = self._hosts(results)
        self.assertEqual(hosts, {"c.example.com"})

    def test_intitle_operator(self):
        # "guia" only appears in the Spanish page's TITLE.
        results, total, _, _ = ranking.search(self.conn, "intitle:guia")
        self.assertEqual(total, 1)
        self.assertEqual(results[0].host, "d.example.com")
        # A body-only term must not satisfy intitle:.
        _, none_total, _, _ = ranking.search(self.conn, "intitle:epsilon")
        self.assertEqual(none_total, 0)

    def test_date_range_operator(self):
        # after: excludes the very old page (fetched 2001), keeps the 2020 ones.
        results, _, _, _ = ranking.search(self.conn, "python after:2015-01-01")
        self.assertNotIn("e.example.com", self._hosts(results))
        # before: keeps only the old page.
        results, _, _, _ = ranking.search(self.conn, "python before:2010-01-01")
        self.assertEqual(self._hosts(results), {"e.example.com"})

    def test_bare_filter_browse(self):
        # A pure-filter query (no free terms) still lists matching docs.
        results, total, _, _ = ranking.search(self.conn, "site:other.org")
        self.assertEqual(total, 1)
        self.assertEqual(results[0].host, "b.other.org")

    def test_operators_combine(self):
        results, total, _, _ = ranking.search(
            self.conn, "python site:example.com filetype:pdf")
        self.assertEqual(total, 1)
        self.assertEqual(results[0].host, "c.example.com")

    def test_operator_query_never_crashes(self):
        for bad in ("site:", "lang:", "before:not-a-date", "date:..",
                    "filetype: intitle:", "site::: junk"):
            results, total, _, _ = ranking.search(self.conn, bad)
            self.assertIsInstance(results, list)
            self.assertIsInstance(total, int)


if __name__ == "__main__":
    unittest.main()
