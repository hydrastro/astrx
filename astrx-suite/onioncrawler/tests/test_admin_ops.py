"""AstrX admin ops: token-gated POST /blocklist (edit + apply the blocklist),
the /opensearch.xml descriptor, and the `backup` (VACUUM INTO) subcommand."""

import contextlib
import http.client
import io
import json
import os
import tempfile
import threading
import unittest
import urllib.parse
import xml.etree.ElementTree as ET
from http.server import ThreadingHTTPServer

from onioncrawler.storage import Storage
from onioncrawler.config import Config
from onioncrawler.abuse import AbuseFilter
from onioncrawler.search import SearchApp, make_handler
from onioncrawler.__main__ import main

HOST = "a" * 56 + ".onion"
OK_HOST = "b" * 56 + ".onion"


class _Serve(unittest.TestCase):
    def _serve(self, cfg, abuse=None):
        self.st = Storage(os.path.join(tempfile.mkdtemp(), "admin.db"))
        # pre-index one page per host so "actually blocks" is observable
        for h, tok in ((HOST, "blockmetoken"), (OK_HOST, "keepmetoken")):
            self.st.ensure_host(h)
            self.st.store_page(f"http://{h}/p", h, "T", f"{tok} body content",
                               "c-" + h[:4], 200, "text/html", 10, 1.0)
        self.app = SearchApp(self.st, cfg, abuse=abuse or AbuseFilter())
        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(self.app))
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def tearDown(self):
        self.httpd.shutdown()
        self.httpd.server_close()
        self.st.close()

    def _post(self, path, body="", headers=None):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        h = {"Content-Type": "application/x-www-form-urlencoded"}
        h.update(headers or {})
        c.request("POST", path, body=body, headers=h)
        r = c.getresponse()
        data = r.read()
        c.close()
        return r.status, data

    def _get(self, path):
        c = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        c.request("GET", path)
        r = c.getresponse()
        data = r.read()
        ct = r.getheader("Content-Type", "")
        c.close()
        return r.status, data, ct


class TestBlocklistDisabled(_Serve):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = False
        # admin_token unset -> endpoint must 403
        self._serve(cfg)

    def test_403_when_no_token_configured(self):
        status, data = self._post("/blocklist", "kind=host&value=" + HOST)
        self.assertEqual(status, 403)
        # nothing was blocked
        self.assertEqual(self.st.search("blockmetoken")[1], 1)


class TestBlocklistTokenGate(_Serve):
    def setUp(self):
        d = tempfile.mkdtemp()
        self.hp = os.path.join(d, "hosts.txt")
        self.kp = os.path.join(d, "kw.txt")
        open(self.hp, "w").close()
        open(self.kp, "w").close()
        cfg = Config()
        cfg.rate_limit_enabled = False
        cfg.admin_token = "s3kr3t"
        cfg.blocklist_hosts_path = self.hp
        cfg.blocklist_keywords_path = self.kp
        self._serve(cfg)

    def test_requires_token_then_blocks_host(self):
        # missing token -> 403
        st, _ = self._post("/blocklist", "kind=host&value=" + HOST)
        self.assertEqual(st, 403)
        # wrong token -> 403
        st, _ = self._post("/blocklist", "kind=host&value=" + HOST,
                           headers={"X-Admin-Token": "nope"})
        self.assertEqual(st, 403)
        self.assertEqual(self.st.search("blockmetoken")[1], 1, "still searchable")

        # correct token -> 200 and the host is ACTUALLY blocked
        st, data = self._post("/blocklist", "kind=host&value=" + HOST,
                             headers={"X-Admin-Token": "s3kr3t"})
        self.assertEqual(st, 200)
        body = json.loads(data)
        self.assertTrue(body["ok"])
        self.assertEqual(body["applied"]["hosts_blocked"], 1)
        # persisted to the file + removed from search
        with open(self.hp) as fh:
            self.assertIn(HOST, fh.read())
        self.assertEqual(self.st.search("blockmetoken")[1], 0,
                         "blocklisted host must vanish from search")
        # the other host is untouched
        self.assertEqual(self.st.search("keepmetoken")[1], 1)

    def test_token_via_bearer_and_keyword_kind(self):
        st, data = self._post(
            "/blocklist", "kind=keyword&value=keepmetoken",
            headers={"Authorization": "Bearer s3kr3t"})
        self.assertEqual(st, 200)
        self.assertEqual(json.loads(data)["applied"]["pages_removed"], 1)
        with open(self.kp) as fh:
            self.assertIn("keepmetoken", fh.read())
        self.assertEqual(self.st.search("keepmetoken")[1], 0)

    def test_rejects_bad_input(self):
        # clearnet host value -> 400
        st, _ = self._post("/blocklist", "kind=host&value=example.com",
                           headers={"X-Admin-Token": "s3kr3t"})
        self.assertEqual(st, 400)
        # unknown kind -> 400
        st, _ = self._post("/blocklist", "kind=bogus&value=x",
                           headers={"X-Admin-Token": "s3kr3t"})
        self.assertEqual(st, 400)

    def test_value_control_chars_sanitized(self):
        # F4 regression: CR/LF in a value must not inject extra blocklist lines.
        st, _ = self._post(
            "/blocklist",
            "kind=keyword&value=" + urllib.parse.quote("aaa\nbbb\r\nccc"),
            headers={"X-Admin-Token": "s3kr3t"})
        self.assertEqual(st, 200)
        with open(self.kp) as fh:
            content = fh.read()
        # collapses to a single sanitized line -- no injected second entry
        self.assertEqual(content, "aaabbbccc\n")


class TestOpenSearch(_Serve):
    def setUp(self):
        cfg = Config()
        cfg.rate_limit_enabled = False
        self._serve(cfg)

    def test_opensearch_descriptor_well_formed(self):
        status, data, ct = self._get("/opensearch.xml")
        self.assertEqual(status, 200)
        self.assertIn("opensearchdescription", ct)
        # parses as XML (well-formed) with the expected root + Url templates
        root = ET.fromstring(data)
        self.assertTrue(root.tag.endswith("OpenSearchDescription"))
        templates = [e.get("template") for e in root.iter()
                     if e.tag.endswith("Url")]
        self.assertTrue(any("{searchTerms}" in (t or "") for t in templates))
        self.assertTrue(any("/search?q=" in (t or "") for t in templates))


class TestBackup(unittest.TestCase):
    def test_backup_produces_valid_db_copy(self):
        d = tempfile.mkdtemp()
        src = os.path.join(d, "src.db")
        st = Storage(src)
        st.ensure_host(HOST)
        st.store_page(f"http://{HOST}/p", HOST, "T", "backuptoken body", "cc",
                      200, "text/html", 10, 1.0)
        dest = os.path.join(d, "backup.db")
        out = st.backup_to(dest)
        st.close()
        self.assertTrue(os.path.exists(out))
        # the copy is a fully usable, consistent DB with the same data
        st2 = Storage(out)
        try:
            self.assertEqual(st2.search("backuptoken")[1], 1)
            self.assertIsNotNone(st2.get_page(f"http://{HOST}/p"))
        finally:
            st2.close()

    def test_backup_cli(self):
        d = tempfile.mkdtemp()
        db = os.path.join(d, "cli.db")
        st = Storage(db)
        st.ensure_host(HOST)
        st.store_page(f"http://{HOST}/c", HOST, "T", "clitoken body", "cd",
                      200, "text/html", 10, 1.0)
        st.close()
        dest = os.path.join(d, "out.db")
        with contextlib.redirect_stdout(io.StringIO()):
            rc = main(["backup", "--db", db, "--out", dest])
        self.assertEqual(rc, 0)
        st2 = Storage(dest)
        try:
            self.assertEqual(st2.search("clitoken")[1], 1)
        finally:
            st2.close()


if __name__ == "__main__":
    unittest.main()
