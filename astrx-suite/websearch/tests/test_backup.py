"""Backup subcommand: safe VACUUM INTO copy of a live index DB (local only)."""

import contextlib
import io
import os
import sqlite3
import tempfile
import unittest

import websearch.__main__ as cli
from websearch import index


def _run_cli(argv):
    """Run the CLI, swallowing its stdout/stderr, and return the exit code."""
    buf = io.StringIO()
    with contextlib.redirect_stdout(buf), contextlib.redirect_stderr(buf):
        return cli.main(argv)


class BackupTest(unittest.TestCase):
    def setUp(self):
        fd, self.db = tempfile.mkstemp(suffix=".db")
        os.close(fd)
        # A live, still-open source connection (backup must work while in use).
        self.conn = index.connect(self.db)
        for i in range(5):
            did = index.upsert_document(
                self.conn, "http://b.test/%d" % i, "Title %d" % i, "",
                "body word number %d" % i, host="b.test")
            index.replace_images(self.conn, did, "http://b.test/%d" % i,
                                 "b.test", [("http://b.test/%d.png" % i,
                                             "alt %d" % i, "", "ctx")])
        self.conn.commit()
        self.out = self.db + ".bak"

    def tearDown(self):
        self.conn.close()
        for p in (self.db, self.db + "-wal", self.db + "-shm", self.out):
            try:
                os.remove(p)
            except OSError:
                pass

    def test_backup_produces_valid_readable_db(self):
        n = index.backup(self.db, self.out)        # source still open
        self.assertEqual(n, 5)
        self.assertTrue(os.path.exists(self.out))
        copy = sqlite3.connect(self.out)
        try:
            self.assertEqual(copy.execute("PRAGMA integrity_check").fetchone()[0],
                             "ok")
            self.assertEqual(
                copy.execute("SELECT COUNT(*) FROM docs").fetchone()[0], 5)
            # FTS index and image tables survived the copy and still query.
            self.assertGreaterEqual(copy.execute(
                "SELECT COUNT(*) FROM fts WHERE fts MATCH 'word'").fetchone()[0],
                1)
            self.assertEqual(
                copy.execute("SELECT COUNT(*) FROM images").fetchone()[0], 5)
        finally:
            copy.close()

    def test_refuses_existing_destination(self):
        with open(self.out, "w") as fh:
            fh.write("do not clobber")
        with self.assertRaises(FileExistsError):
            index.backup(self.db, self.out)
        # The pre-existing file must be left untouched (never clobbered).
        with open(self.out) as fh:
            self.assertEqual(fh.read(), "do not clobber")

    def test_refuses_uri_destination(self):
        with self.assertRaises(ValueError):
            index.backup(self.db, "http://evil.test/x.db")

    def test_refuses_file_uri_destination(self):
        # A file: URI has no "://" yet VACUUM INTO resolves it (incl. ?mode=rwc),
        # so the old "://" substring check missed it.  Any leading scheme: is now
        # refused.
        for dest in ("file:" + self.out, "file:relative.db", "FILE:/tmp/x.db"):
            with self.assertRaises(ValueError):
                index.backup(self.db, dest)

    def test_file_uri_does_not_clobber_empty_dest(self):
        # An existing EMPTY file used to be clobbered via a file: URI because the
        # scheme dodged both the "://" check and os.path.exists (which tests the
        # literal string).  The scheme check now refuses it before any write.
        open(self.out, "w").close()
        with self.assertRaises(ValueError):
            index.backup(self.db, "file:" + self.out)
        self.assertEqual(os.path.getsize(self.out), 0)          # untouched

    def test_cli_refuses_file_uri(self):
        open(self.out, "w").close()
        rc = _run_cli(["backup", "--db", self.db, "--out", "file:" + self.out])
        self.assertEqual(rc, 2)
        self.assertEqual(os.path.getsize(self.out), 0)

    def test_cli_backup_ok(self):
        rc = _run_cli(["backup", "--db", self.db, "--out", self.out])
        self.assertEqual(rc, 0)
        self.assertTrue(os.path.exists(self.out))

    def test_cli_backup_refuses_existing(self):
        open(self.out, "w").close()
        rc = _run_cli(["backup", "--db", self.db, "--out", self.out])
        self.assertEqual(rc, 2)

    def test_cli_parses_backup_subcommand(self):
        args = cli.build_parser().parse_args(
            ["backup", "--db", "x.db", "--out", "y.db"])
        self.assertEqual(args.cmd, "backup")
        self.assertEqual(args.out, "y.db")
        self.assertTrue(callable(args.func))


if __name__ == "__main__":
    unittest.main()
