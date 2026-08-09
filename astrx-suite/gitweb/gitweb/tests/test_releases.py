"""Releases-from-tags: the release list + Atom feed built from git tags."""
import os
import subprocess
import tempfile
import unittest
import xml.sax

from gitweb import gitcmd, views


def _run(args, cwd, env):
    subprocess.run(args, cwd=cwd, check=True,
                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=env)


def _env():
    return dict(os.environ,
                GIT_AUTHOR_NAME="A", GIT_AUTHOR_EMAIL="a@b.c",
                GIT_COMMITTER_NAME="A", GIT_COMMITTER_EMAIL="a@b.c",
                GIT_AUTHOR_DATE="2020-01-01T00:00:00 +0000",
                GIT_COMMITTER_DATE="2020-01-01T00:00:00 +0000")


class TestReleases(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp()
        cls.root = os.path.join(cls.tmp, "repos")
        os.makedirs(cls.root)
        rp = os.path.join(cls.root, "myrepo")
        os.makedirs(rp)
        env = _env()
        _run(["git", "init", "-q", "-b", "main"], rp, env)
        with open(os.path.join(rp, "README.md"), "w") as f:
            f.write("# hi\n")
        _run(["git", "add", "-A"], rp, env)
        _run(["git", "commit", "-q", "-m", "init"], rp, env)
        _run(["git", "tag", "-a", "v1.0", "-m", "First release"], rp, env)
        _run(["git", "tag", "-a", "v2.0", "-m", "Second release"], rp, env)
        cls.repo = gitcmd.resolve_repo(cls.root, "myrepo")

    def test_tags_listed(self):
        names = [t.name for t in gitcmd.tags(self.repo)]
        self.assertIn("v1.0", names)
        self.assertIn("v2.0", names)

    def test_releases_page(self):
        html = views.releases(self.repo, gitcmd.tags(self.repo))
        self.assertIn("v1.0", html)
        self.assertIn("First release", html)
        self.assertIn("archive", html)          # source snapshot download
        self.assertIn(">Releases<", html)        # box head / nav
        self.assertIn("releases.atom", html)     # feed link

    def test_releases_empty_state(self):
        rp2 = os.path.join(self.root, "notags")
        os.makedirs(rp2)
        env = _env()
        _run(["git", "init", "-q", "-b", "main"], rp2, env)
        with open(os.path.join(rp2, "x"), "w") as f:
            f.write("x")
        _run(["git", "add", "-A"], rp2, env)
        _run(["git", "commit", "-q", "-m", "c"], rp2, env)
        repo2 = gitcmd.resolve_repo(self.root, "notags")
        html = views.releases(repo2, gitcmd.tags(repo2))
        self.assertIn("No releases yet", html)

    def test_releases_atom_wellformed(self):
        atom = views.releases_atom(self.repo, gitcmd.tags(self.repo), "http://h")
        # raises if not well-formed
        xml.sax.parseString(atom.encode("utf-8"), xml.sax.ContentHandler())
        self.assertIn("<title>myrepo: releases</title>", atom)
        self.assertIn("v1.0", atom)


if __name__ == "__main__":
    unittest.main()
