"""Feature 9: packaging -- pyproject metadata, console entry point, ops files."""

import os
import tomllib
import unittest

import websearch.__main__ as cli

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def _read(*parts):
    with open(os.path.join(ROOT, *parts), "r", encoding="utf-8") as fh:
        return fh.read()


class PackagingTest(unittest.TestCase):
    def setUp(self):
        with open(os.path.join(ROOT, "pyproject.toml"), "rb") as fh:
            self.pp = tomllib.load(fh)

    def test_project_metadata(self):
        proj = self.pp["project"]
        self.assertEqual(proj["name"], "astrx-websearch")
        self.assertTrue(proj["requires-python"].startswith(">=3.11"))
        # Zero third-party dependencies -- the whole point.
        self.assertEqual(proj["dependencies"], [])

    def test_console_entry_point(self):
        scripts = self.pp["project"]["scripts"]
        self.assertEqual(scripts["websearch"], "websearch.__main__:main")
        # ...and that target actually exists and is callable.
        self.assertTrue(callable(cli.main))

    def test_entry_point_parses_serve(self):
        # The parser the console script drives handles the documented commands.
        args = cli.build_parser().parse_args(
            ["serve", "--db", "x.db", "--rate", "5", "--auth", "u:p"])
        self.assertEqual(args.cmd, "serve")
        self.assertEqual(args.rate, 5.0)
        self.assertEqual(args.auth, "u:p")
        crawl = cli.build_parser().parse_args(
            ["crawl", "http://e.test/", "--workers", "4", "--recrawl",
             "--keep-alive", "--index-pdf"])
        self.assertEqual(crawl.workers, 4)
        self.assertTrue(crawl.recrawl and crawl.keep_alive and crawl.index_pdf)

    def test_dockerfile_present(self):
        df = _read("Dockerfile")
        self.assertIn("FROM python:3.11", df)
        self.assertIn("ENTRYPOINT", df)
        self.assertIn("websearch", df)

    def test_systemd_units_present(self):
        svc = _read("deploy", "websearch.service")
        self.assertIn("ExecStart", svc)
        self.assertIn("websearch serve", svc)
        self.assertIn("[Install]", svc)
        # Recrawl timer wires the freshness feature into ops.
        timer = _read("deploy", "websearch-recrawl.timer")
        self.assertIn("[Timer]", timer)


class BundledCrawlcoreShimTest(unittest.TestCase):
    """The bundled-suite import shim (websearch/__init__.py) must APPEND the
    sibling crawlcore/ dir to sys.path (lowest priority) -- never insert it at the
    front -- so a user's own top-level package or a pip/PYTHONPATH crawlcore that
    shares a name with the bundle's contents always wins."""

    def test_shim_uses_append_not_insert(self):
        src = _read("websearch", "__init__.py")
        self.assertIn("_sys.path.append(_cc)", src)
        self.assertNotIn("insert(0, _cc)", src)

    def test_bundle_path_not_at_front_of_sys_path(self):
        import sys
        import websearch  # noqa: F401  (ensures the shim has already run)
        cc = os.path.join(os.path.dirname(ROOT), "crawlcore")
        if os.path.isdir(cc) and cc in sys.path:
            # Appended => never at index 0, so it cannot shadow earlier entries
            # (a user's own packages / a pip-installed or PYTHONPATH crawlcore).
            self.assertGreater(sys.path.index(cc), 0)

    def test_crawlcore_submodule_resolves(self):
        from crawlcore.dedup import simhash_vector
        self.assertTrue(callable(simhash_vector))


if __name__ == "__main__":
    unittest.main()
