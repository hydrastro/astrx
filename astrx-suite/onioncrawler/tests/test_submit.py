"""Roadmap #4 - runtime onion submission: submit CLI, /add POST, bulk import.
All intake goes through the same onion-only + abuse-blocklist gate."""

import base64
import contextlib
import http.client
import io
import json
import os
import tempfile
import threading
import unittest
import urllib.parse
from http.server import ThreadingHTTPServer

from onioncrawler.storage import Storage
from onioncrawler.config import Config
from onioncrawler.abuse import AbuseFilter
from onioncrawler.submit import submit_seed, submit_many
from onioncrawler.search import SearchApp, make_handler
from onioncrawler.__main__ import main

GOOD = "a" * 56 + ".onion"
GOOD2 = "b" * 56 + ".onion"
BAD = "c" * 56 + ".onion"   # abuse-blocked


class TestSubmitHelper(unittest.TestCase):
    def setUp(self):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "sub.db"))
        self.abuse = AbuseFilter(hosts=[BAD])

    def tearDown(self):
        self.st.close()

    def test_valid_dup_clearnet_blocked(self):
        r = submit_seed(self.st, self.abuse, f"http://{GOOD}/x")
        self.assertEqual(r["status"], "ok")
        # frontier now has a queued seed
        row = self.st.db.execute(
            "SELECT status FROM frontier WHERE url=?", (f"http://{GOOD}/x",)
        ).fetchone()
        self.assertEqual(row["status"], "queued")
        # duplicate submission
        self.assertEqual(
            submit_seed(self.st, self.abuse, f"http://{GOOD}/x")["status"], "dup")
        # clearnet refused
        self.assertEqual(
            submit_seed(self.st, self.abuse, "http://example.com/")["status"],
            "not-onion")
        # blocked host refused (never enqueued)
        self.assertEqual(
            submit_seed(self.st, self.abuse, f"http://{BAD}/y")["status"],
            "blocked")
        self.assertIsNone(self.st.db.execute(
            "SELECT 1 FROM frontier WHERE host=?", (BAD,)).fetchone())

    def test_bare_host_gets_default_scheme(self):
        # a bare .onion (no scheme) is accepted; clearnet still refused
        self.assertEqual(submit_seed(self.st, self.abuse, GOOD)["status"], "ok")
        self.assertEqual(
            submit_seed(self.st, self.abuse, GOOD2 + "/wiki")["status"], "ok")
        self.assertEqual(
            submit_seed(self.st, self.abuse, "example.com")["status"], "not-onion")

    def test_bulk_import_counts(self):
        urls = [f"http://{GOOD}/1", f"http://{GOOD2}/2", "http://example.com/",
                f"http://{BAD}/3", "# comment", f"http://{GOOD}/1"]
        res = submit_many(self.st, self.abuse, urls)
        self.assertEqual(res["ok"], 2)
        self.assertEqual(res["not-onion"], 1)
        self.assertEqual(res["blocked"], 1)
        self.assertEqual(res["dup"], 1)

    def test_untrusted_caps_vs_trusted_force(self):
        # Untrusted (public) submissions pass caps and honour the frontier
        # backstops; trusted (operator/authed) submissions (caps=None) still
        # force past them.
        caps = {"max_unique_urls": 2}
        pub = submit_many(self.st, self.abuse,
                          [f"http://{GOOD}/{i}" for i in range(10)], caps=caps)
        self.assertEqual(pub["ok"], 2)             # only 2 admitted (budget=2)
        self.assertGreaterEqual(pub["capped"], 8)  # the rest refused, not enqueued
        self.assertEqual(pub["not-onion"], 0)
        # trusted path on a different host: force bypasses the same budget
        trust = submit_many(self.st, self.abuse,
                            [f"http://{GOOD2}/{i}" for i in range(10)])
        self.assertEqual(trust["ok"], 10)

    def test_submit_onto_trapped_host_refused(self):
        # A crawler-trapped host (NOT on the abuse blocklist) must not receive a
        # new queued frontier row from a submission: it could never be leased
        # (lease requires state='active') and would stall crawl termination.
        self.st.ensure_host(GOOD)
        self.st.set_host_state(GOOD, "trapped", "duplicate-ratio")
        r = submit_seed(self.st, self.abuse, f"http://{GOOD}/x", caps={})
        self.assertEqual(r["status"], "capped")
        self.assertEqual(self.st.db.execute(
            "SELECT count(*) c FROM frontier WHERE host=? AND status='queued'",
            (GOOD,)).fetchone()["c"], 0)
        # the trusted path refuses it too (add_seed only revives 'dead')
        self.assertEqual(
            submit_seed(self.st, self.abuse, f"http://{GOOD}/y")["status"],
            "capped")
        self.assertEqual(self.st.db.execute(
            "SELECT count(*) c FROM frontier WHERE host=?",
            (GOOD,)).fetchone()["c"], 0)


class _Base(unittest.TestCase):
    def _serve(self, cfg, abuse=None):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "add.db"))
        self.app = SearchApp(self.st, cfg, abuse=abuse)
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.app))
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.st.close()

    def _post(self, path, body="", auth=None):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        headers = {"Content-Type": "application/x-www-form-urlencoded"}
        if auth:
            token = base64.b64encode(auth.encode()).decode()
            headers["Authorization"] = "Basic " + token
        c.request("POST", path, body=body, headers=headers)
        r = c.getresponse()
        data = r.read()
        c.close()
        return r.status, data


class TestAddPublic(_Base):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = False
        cfg.allow_public_submit = True
        self._serve(cfg, abuse=AbuseFilter(hosts=[BAD]))

    def test_public_add_enqueues(self):
        status, data = self._post("/add", f"url=http://{GOOD}/p")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(data)["ok"], 1)
        self.assertIsNotNone(self.st.db.execute(
            "SELECT 1 FROM frontier WHERE host=?", (GOOD,)).fetchone())

    def test_public_add_blocklist_enforced(self):
        status, data = self._post("/add", f"url=http://{BAD}/p")
        self.assertEqual(json.loads(data)["blocked"], 1)
        self.assertIsNone(self.st.db.execute(
            "SELECT 1 FROM frontier WHERE host=?", (BAD,)).fetchone())


class TestAddPublicFrontierCaps(_Base):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = False
        cfg.allow_public_submit = True
        cfg.max_unique_urls = 100          # frontier backstop (trap #9)
        cfg.max_public_add_urls = 10000    # allow a big batch in one request
        self._serve(cfg, abuse=AbuseFilter())

    def test_public_add_10k_urls_respects_unique_budget(self):
        # 10k DISTINCT paths on ONE valid onion host in a single request: the
        # public path is NON-force, so the frontier must stay bounded by
        # max_unique_urls (was: force-enqueue ignored the cap -> unbounded flood).
        urls = "\n".join(f"http://{GOOD}/{i}" for i in range(10000))
        body = urllib.parse.urlencode({"urls": urls})
        status, data = self._post("/add", body)
        self.assertEqual(status, 200)
        res = json.loads(data)
        n = self.st.db.execute(
            "SELECT count(*) c FROM frontier").fetchone()["c"]
        self.assertLessEqual(n, 100,
                             "public /add must not grow frontier past max_unique_urls")
        self.assertLessEqual(res["ok"], 100)
        self.assertGreaterEqual(res.get("capped", 0), 1,
                                "excess submissions must be reported as capped")
        # hosts table is bounded too (budget checked before host creation)
        self.assertLessEqual(self.st.db.execute(
            "SELECT count(*) c FROM hosts").fetchone()["c"], 2)

    def test_public_add_per_request_count_capped(self):
        self.app.cfg.max_public_add_urls = 100   # tighten just for this request
        self.app.cfg.max_unique_urls = 200000     # not the binding cap here
        urls = "\n".join(f"http://{GOOD}/{i}" for i in range(500))
        body = urllib.parse.urlencode({"urls": urls})
        status, data = self._post("/add", body)
        res = json.loads(data)
        self.assertEqual(status, 200)
        self.assertEqual(res["skipped"], 400)     # only first 100 accepted
        self.assertEqual(self.st.db.execute(
            "SELECT count(*) c FROM frontier").fetchone()["c"], 100)


class TestAddAdminGated(_Base):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = False
        cfg.allow_public_submit = False
        cfg.admin_user = "admin"
        cfg.admin_pass = "secret"
        self._serve(cfg, abuse=AbuseFilter())

    def test_requires_auth(self):
        status, _ = self._post("/add", f"url=http://{GOOD}/p")
        self.assertEqual(status, 401)
        status, _ = self._post("/add", f"url=http://{GOOD}/p", auth="admin:wrong")
        self.assertEqual(status, 401)
        status, data = self._post("/add", f"url=http://{GOOD}/p", auth="admin:secret")
        self.assertEqual(status, 200)
        self.assertEqual(json.loads(data)["ok"], 1)


class TestSubmitCLI(unittest.TestCase):
    def test_cli_submit_enqueues(self):
        d = tempfile.mkdtemp()
        db = os.path.join(d, "cli.db")
        empty = os.path.join(d, "empty.txt")
        open(empty, "w").close()
        with contextlib.redirect_stdout(io.StringIO()):
            rc = main(["submit", "--db", db, f"http://{GOOD}/cli",
                       "--blocklist-hosts", empty, "--blocklist-keywords", empty])
        self.assertEqual(rc, 0)
        st = Storage(db)
        row = st.db.execute(
            "SELECT status FROM frontier WHERE url=?", (f"http://{GOOD}/cli",)
        ).fetchone()
        st.close()
        self.assertIsNotNone(row)
        self.assertEqual(row["status"], "queued")


if __name__ == "__main__":
    unittest.main()
