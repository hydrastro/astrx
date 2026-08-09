"""Search/ranking tests: relevance, phrases, +/- operators, safety, snippets."""

import os
import tempfile
import unittest

from websearch import index, ranking
try:
    from tests.common import crawl_fixture
except ImportError:  # discover -s tests (top-level = tests/)
    from common import crawl_fixture
try:
    from tests.fixture_site import FixtureSite
except ImportError:
    from fixture_site import FixtureSite


class SearchTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.site = FixtureSite().start()
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        os.remove(cls.db)
        conn, _ = crawl_fixture(cls.site, cls.db)
        conn.close()
        cls.conn = index.connect(cls.db, read_only=True)

    @classmethod
    def tearDownClass(cls):
        cls.conn.close()
        cls.site.stop()
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _urls(self, results):
        return [r.url.replace(self.site.base, "") for r in results]

    def test_relevance_best_page_first(self):
        results, total, _, _ = ranking.search(self.conn, "inverted index")
        self.assertGreater(total, 0)
        self.assertEqual(self._urls(results)[0], "/search-engines")

    def test_relevance_query_two(self):
        results, _, _, _ = ranking.search(self.conn, "programming language")
        # A language page should top a query about programming languages,
        # ahead of the search-engines page which only mentions it in passing.
        self.assertIn(self._urls(results)[0],
                      ("/python", "/rust", "/go"))

    def test_phrase_query(self):
        results, total, _, _ = ranking.search(self.conn, '"inverted index"')
        urls = self._urls(results)
        self.assertIn("/search-engines", urls)
        # Pages that never contain the exact phrase must be excluded.
        self.assertNotIn("/go", urls)
        self.assertNotIn("/rust", urls)

    def test_plus_operator_requires(self):
        results, total, _, _ = ranking.search(self.conn, "+rust programming")
        urls = self._urls(results)
        self.assertIn("/rust", urls)
        # Every result must contain the required term.
        for r in results:
            self.assertIn("rust", (r.title + " " + (r.snippet or "")).lower()
                          + " " + r.url.lower())

    def test_minus_operator_excludes(self):
        results, _, _, _ = ranking.search(self.conn,
                                          "programming language -rust")
        urls = self._urls(results)
        self.assertNotIn("/rust", urls)
        self.assertTrue({"/python", "/go"} & set(urls))

    def test_malicious_input_no_crash(self):
        bad_queries = [
            'search") OR 1=1 --',
            '"unclosed phrase',
            '((()) AND OR NOT NEAR',
            '* foo *',
            'a""b"c',
            'title:evil col:injection',
            '-onlynegative',
            '',
            '   ',
            '\\ ^ $ . | ? * + ( ) [ ] { }',
        ]
        for q in bad_queries:
            try:
                results, total, elapsed, _ = ranking.search(self.conn, q)
            except Exception as exc:  # pragma: no cover
                self.fail("query %r raised %r" % (q, exc))
            self.assertIsInstance(results, list)
            self.assertIsInstance(total, int)

    def test_snippet_is_escaped(self):
        # make_snippet must escape HTML and only add <mark> markup.
        snippet = ranking.make_snippet(
            "before <script>alert('xss')</script> the inverted index after",
            ["inverted", "index"])
        self.assertNotIn("<script>", snippet)
        self.assertIn("&lt;script&gt;", snippet)
        self.assertIn("<mark>inverted</mark>", snippet)
        self.assertIn("<mark>index</mark>", snippet)

    def test_build_match_safe(self):
        q = ranking.parse_query('foo "bar baz" +req -no')
        match = ranking.build_match(q)
        # Structure is quoted-literal terms combined with FTS operators.
        self.assertIn('"foo"', match)
        self.assertIn('"bar baz"', match)
        self.assertIn('"req"', match)
        self.assertIn('NOT "no"', match)


if __name__ == "__main__":
    unittest.main()
