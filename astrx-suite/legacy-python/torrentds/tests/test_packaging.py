"""Packaging: pyproject metadata, console entry point, Docker/systemd files."""

import contextlib
import io
import os
import sys
import tempfile
import tomllib
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


class TestPackaging(unittest.TestCase):
    def test_pyproject_parses_and_declares_entry_point(self):
        with open(os.path.join(ROOT, "pyproject.toml"), "rb") as fh:
            data = tomllib.load(fh)
        self.assertEqual(data["project"]["name"], "torrentds")
        self.assertEqual(data["project"]["requires-python"], ">=3.11")
        # Zero runtime dependencies (stdlib only).
        self.assertEqual(data["project"].get("dependencies", []), [])
        # Console entry point wired to cli.main.
        self.assertEqual(data["project"]["scripts"]["torrentds"], "torrentds.cli:main")

    def test_entry_point_is_importable_and_runs(self):
        from torrentds.cli import main
        self.assertTrue(callable(main))
        # `stats` on a fresh temp db must run cleanly and return 0.
        fd, path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        try:
            with contextlib.redirect_stdout(io.StringIO()):
                rc = main(["stats", "--db", path])
            self.assertEqual(rc, 0)
        finally:
            os.unlink(path)

    def test_docker_and_systemd_files_present(self):
        self.assertTrue(os.path.exists(os.path.join(ROOT, "Dockerfile")))
        self.assertTrue(os.path.exists(
            os.path.join(ROOT, "packaging", "torrentds-index.service")))
        self.assertTrue(os.path.exists(
            os.path.join(ROOT, "packaging", "torrentds-tracker.service")))
        # Systemd unit must invoke the console script and be [Install]-able.
        with open(os.path.join(ROOT, "packaging", "torrentds-index.service")) as fh:
            unit = fh.read()
        self.assertIn("ExecStart=", unit)
        self.assertIn("torrentds index", unit)
        self.assertIn("[Install]", unit)


if __name__ == "__main__":
    unittest.main()
