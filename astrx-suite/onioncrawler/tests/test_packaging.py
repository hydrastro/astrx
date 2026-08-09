"""Roadmap #11 - packaging: pyproject.toml + console entry point + Dockerfile +
systemd unit + example torrc all present and internally consistent."""

import os
import tomllib
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def _read(rel):
    with open(os.path.join(ROOT, rel), "r", encoding="utf-8") as fh:
        return fh.read()


class TestPyproject(unittest.TestCase):
    def setUp(self):
        with open(os.path.join(ROOT, "pyproject.toml"), "rb") as fh:
            self.pp = tomllib.load(fh)

    def test_project_metadata(self):
        proj = self.pp["project"]
        self.assertEqual(proj["name"], "onioncrawler")
        self.assertTrue(proj["requires-python"].startswith(">=3.11"))
        # zero third-party dependencies (stdlib only)
        self.assertEqual(proj.get("dependencies", []), [])

    def test_console_entry_point(self):
        scripts = self.pp["project"]["scripts"]
        self.assertEqual(scripts["onioncrawler"], "onioncrawler.__main__:main")

    def test_build_backend(self):
        self.assertIn("setuptools", self.pp["build-system"]["build-backend"])
        self.assertEqual(self.pp["tool"]["setuptools"]["packages"], ["onioncrawler"])

    def test_entry_point_target_is_callable(self):
        from onioncrawler.__main__ import main
        self.assertTrue(callable(main))


class TestDeploymentArtifacts(unittest.TestCase):
    def test_dockerfile(self):
        df = _read("Dockerfile")
        self.assertIn("FROM python:3.11", df)
        self.assertIn("pip install", df)
        self.assertIn('ENTRYPOINT ["onioncrawler"]', df)

    def test_systemd_units(self):
        for unit in ("deploy/onioncrawler-crawl.service",
                     "deploy/onioncrawler-search.service"):
            txt = _read(unit)
            self.assertIn("[Service]", txt)
            self.assertIn("ExecStart=", txt)
            self.assertIn("onioncrawler", txt)
            self.assertIn("[Install]", txt)
        # crawl unit runs the crawler; search unit runs the server
        self.assertIn("onioncrawler crawl",
                      _read("deploy/onioncrawler-crawl.service"))
        self.assertIn("onioncrawler search",
                      _read("deploy/onioncrawler-search.service"))

    def test_torrc_example(self):
        torrc = _read("deploy/torrc.example")
        self.assertIn("SocksPort", torrc)
        self.assertIn("IsolateSOCKSAuth", torrc)
        self.assertIn("HiddenServiceDir", torrc)
        self.assertIn("HiddenServicePort 80 127.0.0.1:8802", torrc)


class TestBundledCrawlcoreShim(unittest.TestCase):
    """The bundled-suite import shim (onioncrawler/__init__.py) must APPEND the
    sibling crawlcore/ dir to sys.path (lowest priority) -- never insert it at the
    front -- so a user's own top-level package or a pip/PYTHONPATH crawlcore that
    shares a name with the bundle's contents always wins."""

    def test_shim_uses_append_not_insert(self):
        src = _read("onioncrawler/__init__.py")
        self.assertIn("_sys.path.append(_cc)", src)
        self.assertNotIn("insert(0, _cc)", src)

    def test_bundle_path_not_at_front_of_sys_path(self):
        import sys
        import onioncrawler  # noqa: F401  (ensures the shim has already run)
        cc = os.path.join(os.path.dirname(ROOT), "crawlcore")
        if os.path.isdir(cc) and cc in sys.path:
            # Appended => never at index 0, so it cannot shadow earlier entries
            # (a user's own packages / a pip-installed or PYTHONPATH crawlcore).
            self.assertGreater(sys.path.index(cc), 0)

    def test_crawlcore_submodule_resolves(self):
        from crawlcore.scheduler import backoff_interval
        self.assertTrue(callable(backoff_interval))


if __name__ == "__main__":
    unittest.main()
