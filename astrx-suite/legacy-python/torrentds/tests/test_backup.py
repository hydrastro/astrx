"""Backup subcommand: a safe, self-contained SQLite copy of the store."""

import contextlib
import hashlib
import io
import os
import sqlite3
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from torrentds.cli import main
from torrentds.metadata import TorrentMeta
from torrentds.store import Store


def make_meta(name, files):
    total = sum(l for _, l in files)
    ih = hashlib.sha1(name.encode()).digest()
    return TorrentMeta(info_hash=ih, name=name, total_size=total,
                       piece_length=262144, piece_count=max(1, total // 262144),
                       files=files)


class TestBackup(unittest.TestCase):
    def setUp(self):
        fd, self.path = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        fd2, self.dest = tempfile.mkstemp(suffix=".bak.db")
        os.close(fd2)
        self.store = Store(self.path)

    def tearDown(self):
        self.store.close()
        for p in (self.path, self.dest):
            with contextlib.suppress(FileNotFoundError):
                os.unlink(p)

    def test_backup_produces_valid_db(self):
        self.store.store_metadata(make_meta("Alpha", [("a.iso", 1000)]))
        self.store.store_metadata(make_meta("Beta", [("b.mkv", 2000), ("c.mkv", 3000)]))
        info = self.store.backup(self.dest)
        self.assertEqual(info["torrents"], 2)
        self.assertGreater(info["bytes"], 0)

        # The backup opens standalone and carries the same data.
        conn = sqlite3.connect(self.dest)
        try:
            n = conn.execute("SELECT COUNT(*) FROM torrents").fetchone()[0]
            files = conn.execute("SELECT COUNT(*) FROM files").fetchone()[0]
            names = {r[0] for r in conn.execute("SELECT name FROM torrents")}
        finally:
            conn.close()
        self.assertEqual(n, 2)
        self.assertEqual(files, 3)
        self.assertEqual(names, {"Alpha", "Beta"})

    def test_backup_is_readable_as_store(self):
        self.store.store_metadata(make_meta("Gamma", [("g.iso", 42)]))
        self.store.backup(self.dest)
        # Reopen the backup through a full Store and search it.
        backup_store = Store(self.dest)
        try:
            self.assertEqual(len(backup_store.search("gamma")), 1)
        finally:
            backup_store.close()

    def test_backup_cli(self):
        self.store.store_metadata(make_meta("CliBackup", [("x.bin", 7)]))
        with contextlib.redirect_stdout(io.StringIO()) as out:
            rc = main(["backup", "--db", self.path, "--out", self.dest])
        self.assertEqual(rc, 0)
        self.assertIn("[backup]", out.getvalue())
        conn = sqlite3.connect(self.dest)
        try:
            self.assertEqual(
                conn.execute("SELECT COUNT(*) FROM torrents").fetchone()[0], 1)
        finally:
            conn.close()


if __name__ == "__main__":
    unittest.main()
