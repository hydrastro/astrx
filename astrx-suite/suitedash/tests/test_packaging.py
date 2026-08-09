"""Packaging: pyproject metadata, console entry point, config + Docker/systemd."""

import io
import os
import sys
import tomllib
import unittest
from contextlib import redirect_stdout

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


class TestPackaging(unittest.TestCase):
    def test_pyproject_parses_and_declares_entry_point(self):
        with open(os.path.join(ROOT, "pyproject.toml"), "rb") as fh:
            data = tomllib.load(fh)
        self.assertEqual(data["project"]["name"], "suitedash")
        self.assertEqual(data["project"]["requires-python"], ">=3.11")
        # Zero runtime dependencies (stdlib only).
        self.assertEqual(data["project"].get("dependencies", []), [])
        self.assertEqual(data["project"]["scripts"]["suitedash"], "suitedash.cli:main")

    def test_entry_point_importable_and_check_runs_offline(self):
        from suitedash.cli import main

        self.assertTrue(callable(main))
        # `--check` against a definitely-down service returns non-zero and prints
        # valid JSON, entirely offline (no server bound).
        buf = io.StringIO()
        with redirect_stdout(buf):
            rc = main(["--check", "--timeout", "0.4", "--service",
                       "phantom=http://127.0.0.1:9"])
        self.assertEqual(rc, 1)  # phantom is down
        import json

        payload = json.loads(buf.getvalue())
        self.assertIn("phantom", payload["services"])
        self.assertFalse(payload["services"]["phantom"]["up"])

    def test_supporting_files_present(self):
        for rel in ("Dockerfile", "README.md", "suitedash.example.toml",
                    os.path.join("packaging", "suitedash.service")):
            self.assertTrue(os.path.exists(os.path.join(ROOT, rel)), rel)

    def test_example_config_parses_and_loads(self):
        from suitedash.config import load_config

        path = os.path.join(ROOT, "suitedash.example.toml")
        cfg = load_config(path)
        self.assertTrue(cfg.services)
        self.assertTrue(all(s.name and s.base_url for s in cfg.services))

    def test_systemd_unit_invokes_console_script(self):
        with open(os.path.join(ROOT, "packaging", "suitedash.service")) as fh:
            unit = fh.read()
        self.assertIn("ExecStart=", unit)
        self.assertIn("suitedash", unit)
        self.assertIn("[Install]", unit)


if __name__ == "__main__":
    unittest.main()
