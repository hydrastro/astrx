"""Roadmap #6 - search filters/facets: host, last-seen date range, language
guess, and a total count, in both storage and the JSON API."""

import http.client
import json
import os
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer

from onioncrawler.storage import Storage
from onioncrawler.config import Config
from onioncrawler.lang import guess_lang
from onioncrawler.search import SearchApp, make_handler

HOST_A = "a" * 56 + ".onion"
HOST_B = "b" * 56 + ".onion"
EN = ("the quick brown fox and the lazy dog is in the house that we have "
      "seen for you not with this")
RU = "и в не на что с по как это из за для же бы он она мы вы то был при"


class TestLangGuess(unittest.TestCase):
    def test_en_and_ru(self):
        self.assertEqual(guess_lang(EN), "en")
        self.assertEqual(guess_lang(RU), "ru")
        self.assertEqual(guess_lang("word"), "un")  # too few tokens


def _seed(db):
    st = Storage(db)
    st.ensure_host(HOST_A)
    st.ensure_host(HOST_B)
    # (url, host, body, hash, last_seen)
    st.store_page(f"http://{HOST_A}/en", HOST_A, "A-en",
                  EN + " filtertoken alpha padding one two three", "c1",
                  200, "text/html", 10, 1000.0)
    st.store_page(f"http://{HOST_A}/ru", HOST_A, "A-ru",
                  RU + " filtertoken", "c2", 200, "text/html", 10, 2000.0)
    st.store_page(f"http://{HOST_B}/en", HOST_B, "B-en",
                  EN + " filtertoken bravo other four five six seven", "c3",
                  200, "text/html", 10, 3000.0)
    return st


class TestStorageFilters(unittest.TestCase):
    def setUp(self):
        self.st = _seed(os.path.join(tempfile.mkdtemp(), "filt.db"))

    def tearDown(self):
        self.st.close()

    def test_total_count_and_no_filter(self):
        _, total = self.st.search("filtertoken")
        self.assertEqual(total, 3)

    def test_host_filter(self):
        rows, total = self.st.search("filtertoken", host=HOST_A)
        self.assertEqual(total, 2)
        self.assertTrue(all(r["host"] == HOST_A for r in rows))

    def test_lang_filter(self):
        rows, total = self.st.search("filtertoken", lang="ru")
        self.assertEqual(total, 1)
        self.assertTrue(rows[0]["url"].endswith("/ru"))

    def test_date_range_filter(self):
        # only last_seen in [1500, 2500] -> just the /ru page (2000)
        rows, total = self.st.search("filtertoken", since=1500, until=2500)
        self.assertEqual(total, 1)
        self.assertTrue(rows[0]["url"].endswith("/ru"))

    def test_facets(self):
        fac = self.st.search_facets("filtertoken")
        self.assertEqual(fac["total"], 3)
        hosts = {h["host"]: h["n"] for h in fac["hosts"]}
        self.assertEqual(hosts[HOST_A], 2)
        self.assertEqual(hosts[HOST_B], 1)
        langs = {l["lang"]: l["n"] for l in fac["langs"]}
        self.assertEqual(langs.get("en"), 2)
        self.assertEqual(langs.get("ru"), 1)


class TestApiFilters(unittest.TestCase):
    def setUp(self):
        self.st = _seed(os.path.join(tempfile.mkdtemp(), "filtapi.db"))
        cfg = Config()
        cfg.rate_limit_enabled = False
        self.app = SearchApp(self.st, cfg)
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.app))
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.st.close()

    def _get(self, path):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        c.request("GET", path)
        r = c.getresponse()
        data = r.read()
        c.close()
        return r.status, data

    def test_api_host_filter_and_facets(self):
        status, data = self._get(f"/api/search?q=filtertoken&host={HOST_A}")
        self.assertEqual(status, 200)
        obj = json.loads(data)
        self.assertEqual(obj["total"], 2)
        self.assertEqual(obj["filters"]["host"], HOST_A)
        self.assertTrue(all(r["host"] == HOST_A for r in obj["results"]))
        self.assertIn("facets", obj)
        self.assertTrue(any(h["host"] == HOST_A for h in obj["facets"]["hosts"]))

    def test_api_lang_filter(self):
        status, data = self._get("/api/search?q=filtertoken&lang=ru")
        obj = json.loads(data)
        self.assertEqual(obj["total"], 1)
        self.assertEqual(obj["results"][0]["lang"], "ru")

    def test_ui_shows_filters_and_count(self):
        status, data = self._get("/search?q=filtertoken")
        body = data.decode("utf-8")
        self.assertEqual(status, 200)
        self.assertIn("match(es)", body)      # total count shown
        self.assertIn("name=host", body)      # host filter input present
        self.assertIn("name=lang", body)      # lang filter present
        self.assertNotIn("<script", body.lower())


if __name__ == "__main__":
    unittest.main()
