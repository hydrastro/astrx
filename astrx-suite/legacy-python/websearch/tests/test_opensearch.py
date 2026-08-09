"""OpenSearch 1.1 descriptor: well-formedness, templates, head link, escaping."""

import io
import os
import tempfile
import threading
import unittest
import xml.sax
from urllib.request import urlopen

from websearch import index, server


def _parse_ok(data):
    """Parse *data* as XML via xml.sax; raises SAXParseException if malformed."""
    if isinstance(data, str):
        data = data.encode("utf-8")
    xml.sax.parse(io.BytesIO(data), xml.sax.ContentHandler())


class OpenSearchXmlUnitTest(unittest.TestCase):
    def test_descriptor_is_well_formed_and_complete(self):
        xmls = server._opensearch_xml("http://search.example:8803")
        _parse_ok(xmls)
        self.assertIn("http://a9.com/-/spec/opensearch/1.1/", xmls)
        self.assertIn("<ShortName>", xmls)
        self.assertIn("{searchTerms}", xmls)
        self.assertIn("http://search.example:8803/search?q={searchTerms}", xmls)
        self.assertIn("http://search.example:8803/api/search?q={searchTerms}",
                      xmls)
        self.assertIn("http://search.example:8803/suggest?q={searchTerms}", xmls)

    def test_hostile_base_is_escaped_and_still_well_formed(self):
        # XML metacharacters in the base must be escaped so the doc stays valid
        # (a raw '<' would make xml.sax raise).
        xmls = server._opensearch_xml("http://a&b\"<x>'.test")
        _parse_ok(xmls)
        self.assertIn("&amp;", xmls)
        self.assertNotIn("<x>", xmls)          # the injected tag was escaped


class OpenSearchServerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fd, cls.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        index.connect(cls.db).close()          # create the schema (empty index)
        cls.httpd = server.make_server(cls.db, host="127.0.0.1", port=0)
        cls.port = cls.httpd.server_address[1]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever,
                                      kwargs={"poll_interval": 0.05}, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()
        cls.thread.join(timeout=3)
        for suffix in ("", "-wal", "-shm"):
            try:
                os.remove(cls.db + suffix)
            except OSError:
                pass

    def _get(self, path, auth_header=None):
        from urllib.request import Request
        req = Request("http://127.0.0.1:%d%s" % (self.port, path))
        with urlopen(req, timeout=5) as r:
            return r.status, r.read().decode("utf-8"), r.headers

    def test_descriptor_served_with_correct_type(self):
        status, body, headers = self._get("/opensearch.xml")
        self.assertEqual(status, 200)
        self.assertIn("application/opensearchdescription+xml",
                      headers.get("Content-Type", ""))
        _parse_ok(body)
        # The template carries the server's own authority (from the Host header).
        self.assertIn("127.0.0.1:%d/search?q={searchTerms}" % self.port, body)

    def test_results_head_has_search_link(self):
        _, body, _ = self._get("/")
        self.assertIn("rel=search", body)
        self.assertIn("application/opensearchdescription+xml", body)
        self.assertIn("/opensearch.xml", body)

    def test_descriptor_open_even_under_auth(self):
        httpd = server.make_server(self.db, host="127.0.0.1", port=0,
                                   auth=("u", "p"))
        port = httpd.server_address[1]
        t = threading.Thread(target=httpd.serve_forever,
                             kwargs={"poll_interval": 0.05}, daemon=True)
        t.start()
        try:
            with urlopen("http://127.0.0.1:%d/opensearch.xml" % port,
                         timeout=5) as r:
                self.assertEqual(r.status, 200)
        finally:
            httpd.shutdown()
            httpd.server_close()
            t.join(timeout=3)


if __name__ == "__main__":
    unittest.main()
