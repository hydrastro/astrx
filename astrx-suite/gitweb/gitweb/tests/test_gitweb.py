"""End-to-end tests for gitweb.

A throwaway git repository is created in a temp directory (identity set
locally, several commits across two branches, a tag, a binary file and a
Markdown README).  The server is then started on an ephemeral port in a
background thread and exercised over HTTP with :mod:`urllib`.
"""

from __future__ import annotations

import base64
import gzip
import hashlib
import io
import os
import re
import shutil
import subprocess
import tarfile
import tempfile
import threading
import unittest
import urllib.error
import urllib.request
import xml.dom.minidom
from urllib.parse import urlencode

from gitweb.server import Config, make_server

# Content used in the fixture repo, kept as module constants so the tests can
# assert on exact bytes / escaping.
README_MD = """\
# Project Title

Some **bold** and _italic_ and `inline<code>` text.

- item one
- item two

```python
danger = "<script>"
```

Danger: <script>alert('xss')</script> <b>hi</b>

Links: [example](https://example.com) and [bad](javascript:alert(1)).
"""

MAIN_PY = (
    "#!/usr/bin/env python3\n"
    "print(\"<hello> & 'world'\")\n"
    "value = 1 < 2 and 3 > 2\n"
)

MAIN_PY_FEATURE = MAIN_PY + "extra = 'feature branch line'\n"

BINARY = b"\x89PNG\r\n\x00\x00fake-binary\x00\xff\xfe\x01\x02payload"

# A real 1x1 transparent PNG (so /raw can serve a genuine image/png body).
PNG_1x1 = __import__("base64").b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA"
    "60e6kgAAAABJRU5ErkJggg=="
)

# A git-lfs pointer file (must be shown as a pointer, not rendered as source).
LFS_POINTER = (
    "version https://git-lfs.github.com/spec/v1\n"
    "oid sha256:1111111111111111111111111111111111111111111111111111111111111111\n"
    "size 12345\n"
)

# Markdown exercising the upgraded renderer: table, image, autolink, task list.
GUIDE_MD = """\
# Guide

| Name | Value |
| --- | --- |
| alpha | 1 |
| beta | 2 |

![logo](logo.png)

Visit https://autolink.example.com now.

- [x] done task
- [ ] pending task
"""


def _run(args, cwd, env):
    subprocess.run(
        args, cwd=cwd, env=env, check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )


def _blob_read_rss_probe(root, repo_name, path, q):
    """Run in a forked child: read an 8 KiB slice of a (large) blob and report
    ``(len, peak_rss_growth_kib)`` so the parent can assert the read stayed
    bounded and never buffered the whole object.
    """
    import resource

    from gitweb import gitcmd

    repo = gitcmd.resolve_repo(root, repo_name)
    base = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    data = gitcmd.read_blob(repo, "main", path, 8192)
    peak = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    q.put((len(data), peak - base))


def _capture(args, cwd, env) -> str:
    out = subprocess.run(
        args, cwd=cwd, env=env, check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    return out.stdout.decode().strip()


def _pkt(data: bytes) -> bytes:
    """Encode one git pkt-line (4-hex length prefix + data) for a want request."""
    return b"%04x" % (len(data) + 4) + data


def _pid_running(pid: int) -> bool:
    """True if ``pid`` is a live (non-zombie) process.  Linux /proc based.

    A killed child that has not yet been reaped shows as a zombie ('Z'); that
    counts as *not running* for the orphan-reaping assertions.
    """
    try:
        with open(f"/proc/{pid}/stat", "rb") as fh:
            data = fh.read()
    except (FileNotFoundError, ProcessLookupError):
        return False
    try:  # stat is "pid (comm) state ..."; state char follows the last ')'
        state = data.rsplit(b")", 1)[1].split()[0]
    except IndexError:  # pragma: no cover - defensive
        return False
    return state not in (b"Z", b"X", b"x")


def _child_pids(ppid: int) -> list:
    """PIDs whose parent is ``ppid`` (Linux /proc); used to spot orphaned git."""
    out = []
    try:
        entries = os.listdir("/proc")
    except OSError:  # pragma: no cover - non-Linux
        return out
    for name in entries:
        if not name.isdigit():
            continue
        try:
            with open(f"/proc/{name}/stat", "rb") as fh:
                fields = fh.read().rsplit(b")", 1)[1].split()
            if int(fields[1]) == ppid:  # fields after state: [state, ppid, ...]
                out.append(int(name))
        except (OSError, IndexError, ValueError):
            continue
    return out


class GitwebTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.mkdtemp(prefix="gitweb-test-")
        cls.root = os.path.join(cls.tmp, "repos")
        os.makedirs(cls.root)
        cls.repo = os.path.join(cls.root, "myrepo")
        os.makedirs(cls.repo)

        # Hermetic git environment with a fixed identity.
        env = dict(os.environ)
        env.update(
            {
                "HOME": cls.tmp,
                "GIT_CONFIG_GLOBAL": os.devnull,
                "GIT_CONFIG_SYSTEM": os.devnull,
                "GIT_AUTHOR_NAME": "Test Author",
                "GIT_AUTHOR_EMAIL": "author@example.com",
                "GIT_COMMITTER_NAME": "Test Author",
                "GIT_COMMITTER_EMAIL": "author@example.com",
            }
        )
        cls.env = env

        def git(*args):
            _run(["git", *args], cls.repo, env)

        git("init", "-b", "main")
        git("config", "user.name", "Test Author")
        git("config", "user.email", "author@example.com")

        # Commit 1: README on main.
        cls._write("README.md", README_MD)
        git("add", "README.md")
        git("commit", "-m", "Add README")

        # Commit 2: source file (the commit we assert the diff for).
        os.makedirs(os.path.join(cls.repo, "src"))
        cls._write("src/main.py", MAIN_PY)
        git("add", "src/main.py")
        git("commit", "-m", "Add main.py")
        cls.commit_main_py = _capture(["git", "rev-parse", "HEAD"], cls.repo, env)

        # Branch "feature" with an extra change (a real textual diff).
        git("checkout", "-b", "feature")
        cls._write("src/main.py", MAIN_PY_FEATURE)
        git("commit", "-am", "Tweak main on feature")

        # Back to main; add a binary asset and tag the tip.
        git("checkout", "main")
        os.makedirs(os.path.join(cls.repo, "assets"))
        cls._write_bytes("assets/logo.bin", BINARY)
        git("add", "assets/logo.bin")
        git("commit", "-m", "Add binary asset")
        git("tag", "-a", "v1.0", "-m", "release 1.0")

        # Extras: a real PNG (inline image), an LFS pointer, a Markdown doc,
        # a submodule gitlink + .gitmodules (feature coverage for items 10,
        # 15 and 19).
        cls._write_bytes("assets/pic.png", PNG_1x1)
        cls._write_bytes("assets/big.lfs", LFS_POINTER.encode("utf-8"))
        os.makedirs(os.path.join(cls.repo, "docs"))
        cls._write("docs/guide.md", GUIDE_MD)
        git("add", "assets/pic.png", "assets/big.lfs", "docs/guide.md")
        # A gitlink (submodule pin) recorded directly via the index, plus the
        # matching .gitmodules entry — no network/clone required.
        head_sha = _capture(["git", "rev-parse", "HEAD"], cls.repo, env)
        cls.submodule_sha = head_sha
        _run(
            ["git", "update-index", "--add", "--cacheinfo",
             f"160000,{head_sha},vendor"],
            cls.repo, env,
        )
        cls._write(
            ".gitmodules",
            '[submodule "vendor"]\n\tpath = vendor\n'
            "\turl = https://example.com/vendor.git\n",
        )
        git("add", ".gitmodules")
        git("commit", "-m", "Add extras (png, lfs, md, submodule)")

        # A second repo holding a large blob, to exercise the memory-bounded
        # peek/read path (must not buffer the whole object into RAM).
        cls.bigrepo = os.path.join(cls.root, "bigrepo")
        os.makedirs(cls.bigrepo)

        def biggit(*args):
            _run(["git", *args], cls.bigrepo, env)

        biggit("init", "-b", "main")
        biggit("config", "user.name", "Test Author")
        biggit("config", "user.email", "author@example.com")
        cls.big_bytes = 32 * 1024 * 1024
        with open(os.path.join(cls.bigrepo, "big.txt"), "wb") as fh:
            fh.write(b"A" * cls.big_bytes)  # text (no NUL) => exceeds inline cap
        biggit("add", "big.txt")
        biggit("commit", "-m", "Add big file")

        # An empty (unborn-HEAD) repo, to exercise the empty-log path.
        cls.emptyrepo = os.path.join(cls.root, "emptyrepo")
        os.makedirs(cls.emptyrepo)
        _run(["git", "init", "-b", "main"], cls.emptyrepo, env)

        # A repo containing a file whose NAME embeds a newline.  git allows any
        # byte but NUL and '/' in a path; such a repo-derived path must never be
        # fed to the cat-file batch stdin (it would inject a second request and
        # desync the shared stream => content bleed).  Sorts before README.md.
        cls.hostilerepo = os.path.join(cls.root, "hostilerepo")
        os.makedirs(cls.hostilerepo)
        cls.HOSTILE_NAME = "readme\nHACK.txt"
        cls.NORMAL_TXT = "normal file contents\n"
        cls.REAL_README = "# Real Readme\n\nThe genuine readme body.\n"

        def hostgit(*args):
            _run(["git", *args], cls.hostilerepo, env)

        hostgit("init", "-b", "main")
        hostgit("config", "user.name", "Test Author")
        hostgit("config", "user.email", "author@example.com")
        with open(os.path.join(cls.hostilerepo, cls.HOSTILE_NAME), "w") as fh:
            fh.write("HOSTILE-BLOB-CONTENTS\n")
        with open(os.path.join(cls.hostilerepo, "README.md"), "w") as fh:
            fh.write(cls.REAL_README)
        with open(os.path.join(cls.hostilerepo, "normal.txt"), "w") as fh:
            fh.write(cls.NORMAL_TXT)
        hostgit("add", "-A")
        hostgit("commit", "-m", "hostile filename repo")

        # A repo whose HEAD commit carries C0 control characters (form-feed,
        # ESC) in both the subject and the author name.  git preserves these
        # verbatim; they are ILLEGAL in XML 1.0, so the Atom feed must sanitise
        # them or the whole feed becomes non-well-formed for every reader.
        cls.ctrlrepo = os.path.join(cls.root, "ctrlrepo")
        os.makedirs(cls.ctrlrepo)
        _run(["git", "init", "-b", "main"], cls.ctrlrepo, env)
        _run(["git", "config", "user.email", "author@example.com"], cls.ctrlrepo, env)
        with open(os.path.join(cls.ctrlrepo, "f.txt"), "w") as fh:
            fh.write("hi\n")
        _run(["git", "add", "f.txt"], cls.ctrlrepo, env)
        ctrl_env = dict(env)
        # Control char embedded in the author name (survives into %an).
        ctrl_env["GIT_AUTHOR_NAME"] = "Bad\x1bAuthor"
        ctrl_env["GIT_COMMITTER_NAME"] = "Bad\x1bAuthor"
        _run(
            ["git", "commit", "-m", "subject with \x0c\x1b\x01 control chars"],
            cls.ctrlrepo,
            ctrl_env,
        )

        # A repo exercising search + graph + patch: a "needle" file holding a
        # unique token, a literal that *looks like* a git option (to prove
        # option-injection is refused), and an XSS line (to prove match output is
        # escaped); a distinctive commit message for message search; and a real
        # --no-ff merge (a two-parent commit) so the graph has a branch/merge.
        cls.featrepo = os.path.join(cls.root, "featrepo")
        os.makedirs(cls.featrepo)

        def featgit(*args):
            _run(["git", *args], cls.featrepo, env)

        featgit("init", "-b", "main")
        featgit("config", "user.name", "Test Author")
        featgit("config", "user.email", "author@example.com")
        os.makedirs(os.path.join(cls.featrepo, "search"))
        with open(os.path.join(cls.featrepo, "search", "needle.txt"), "w") as fh:
            fh.write(
                "UNIQUE_NEEDLE_TOKEN here\n"
                "--option-like-needle value\n"
                "danger <script>alert(1)</script> line\n"
                "plain line\n"
            )
        featgit("add", "-A")
        featgit("commit", "-m", "Add needle file mentioning SEARCHKEYWORD")
        cls.needle_sha = _capture(["git", "rev-parse", "HEAD"], cls.featrepo, env)
        featgit("checkout", "-b", "topic")
        with open(os.path.join(cls.featrepo, "topic.txt"), "w") as fh:
            fh.write("topic work\n")
        featgit("add", "-A")
        featgit("commit", "-m", "Topic branch work")
        featgit("checkout", "main")
        with open(os.path.join(cls.featrepo, "mainwork.txt"), "w") as fh:
            fh.write("main work\n")
        featgit("add", "-A")
        featgit("commit", "-m", "Main branch work")
        featgit("merge", "--no-ff", "topic", "-m", "Merge topic into main")
        cls.merge_sha = _capture(["git", "rev-parse", "HEAD"], cls.featrepo, env)

        # A repo with Git LFS pointers whose objects ARE in local storage, to
        # exercise real-content serving (a binary and a text object), plus oid
        # validation / path confinement.  Objects live at the standard
        # .git/lfs/objects/<a>/<b>/<oid> layout (no network / real git-lfs).
        cls.lfsrepo = os.path.join(cls.root, "lfsrepo")
        os.makedirs(cls.lfsrepo)

        def lfsgit(*args):
            _run(["git", *args], cls.lfsrepo, env)

        lfsgit("init", "-b", "main")
        lfsgit("config", "user.name", "Test Author")
        lfsgit("config", "user.email", "author@example.com")
        cls.LFS_BYTES = b"REAL LFS OBJECT CONTENT\n" + b"payload-" * 128
        cls.LFS_OID = hashlib.sha256(cls.LFS_BYTES).hexdigest()
        cls.LFS_TEXT = b"first line\nsecond line of real lfs text\n"
        cls.LFS_TEXT_OID = hashlib.sha256(cls.LFS_TEXT).hexdigest()

        def _lfs_pointer(oid, size):
            return (
                "version https://git-lfs.github.com/spec/v1\n"
                f"oid sha256:{oid}\nsize {size}\n"
            )

        with open(os.path.join(cls.lfsrepo, "asset.dat"), "w") as fh:
            fh.write(_lfs_pointer(cls.LFS_OID, len(cls.LFS_BYTES)))
        with open(os.path.join(cls.lfsrepo, "notes.txt"), "w") as fh:
            fh.write(_lfs_pointer(cls.LFS_TEXT_OID, len(cls.LFS_TEXT)))
        lfsgit("add", "-A")
        lfsgit("commit", "-m", "add lfs pointers")
        for oid, data in ((cls.LFS_OID, cls.LFS_BYTES), (cls.LFS_TEXT_OID, cls.LFS_TEXT)):
            d = os.path.join(cls.lfsrepo, ".git", "lfs", "objects", oid[0:2], oid[2:4])
            os.makedirs(d, exist_ok=True)
            with open(os.path.join(d, oid), "wb") as fh:
                fh.write(data)

        # Start the server on an ephemeral port.
        cls.httpd = make_server(Config(root=cls.root, host="127.0.0.1", port=0, verbose=False))
        cls.port = cls.httpd.server_address[1]
        cls.thread = threading.Thread(target=cls.httpd.serve_forever, daemon=True)
        cls.thread.start()
        cls.base = f"http://127.0.0.1:{cls.port}"

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()
        cls.httpd.server_close()
        cls.thread.join(timeout=5)
        shutil.rmtree(cls.tmp, ignore_errors=True)

    # -- helpers -------------------------------------------------------- #

    @classmethod
    def _write(cls, rel, text):
        with open(os.path.join(cls.repo, rel), "w", encoding="utf-8") as fh:
            fh.write(text)

    @classmethod
    def _write_bytes(cls, rel, data):
        with open(os.path.join(cls.repo, rel), "wb") as fh:
            fh.write(data)

    def get(self, path, query=None):
        url = self.base + path
        if query:
            url += "?" + urlencode(query)
        req = urllib.request.Request(url)
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                return resp.status, dict(resp.headers), resp.read()
        except urllib.error.HTTPError as exc:
            return exc.code, dict(exc.headers), exc.read()

    def get_text(self, path, query=None):
        status, headers, body = self.get(path, query)
        return status, headers, body.decode("utf-8", "replace")

    def get_h(self, path, query=None, headers=None, base=None):
        """GET with custom request headers; returns (status, headers, body)."""
        url = (base or self.base) + path
        if query:
            url += "?" + urlencode(query)
        req = urllib.request.Request(url)
        for key, value in (headers or {}).items():
            req.add_header(key, value)
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                return resp.status, dict(resp.headers), resp.read()
        except urllib.error.HTTPError as exc:
            return exc.code, dict(exc.headers), exc.read()

    def _temp_server(self, **overrides):
        """Start a second server (same root) with config overrides.

        Returns ``(base_url, stop)`` where calling ``stop()`` shuts it down.
        """
        cfg = Config(root=self.root, host="127.0.0.1", port=0, verbose=False)
        for key, value in overrides.items():
            setattr(cfg, key, value)
        httpd = make_server(cfg)
        port = httpd.server_address[1]
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()

        def stop():
            httpd.shutdown()
            httpd.server_close()
            thread.join(timeout=5)

        return f"http://127.0.0.1:{port}", stop

    # -- tests ---------------------------------------------------------- #

    def test_repo_list(self):
        status, _h, body = self.get_text("/")
        self.assertEqual(status, 200)
        self.assertIn("myrepo", body)
        self.assertIn("Repositories", body)

    def test_summary_and_readme_escaping(self):
        status, _h, body = self.get_text("/myrepo/")
        self.assertEqual(status, 200)
        # Default branch shown.
        self.assertIn("main", body)
        # Markdown structure rendered.
        self.assertIn("<h1>Project Title</h1>", body)
        self.assertIn("<strong>bold</strong>", body)
        self.assertIn("<li>item one</li>", body)
        # Safe link kept, javascript: link neutralised.
        self.assertIn('href="https://example.com"', body)
        self.assertNotIn('href="javascript', body)
        # Injection escaped, not live.
        self.assertIn("&lt;script&gt;", body)
        self.assertNotIn("<script>alert", body)

    def test_refs(self):
        status, _h, body = self.get_text("/myrepo/refs")
        self.assertEqual(status, 200)
        self.assertIn("main", body)
        self.assertIn("feature", body)
        self.assertIn("v1.0", body)

    def test_log(self):
        status, _h, body = self.get_text("/myrepo/log", {"ref": "main"})
        self.assertEqual(status, 200)
        self.assertIn("Add main.py", body)
        self.assertIn("Add README", body)
        # Pagination footer present.
        self.assertIn("Older", body)

    def test_commit_view_shows_diff(self):
        status, _h, body = self.get_text("/myrepo/commit", {"id": self.commit_main_py})
        self.assertEqual(status, 200)
        self.assertIn("Add main.py", body)
        self.assertIn("src/main.py", body)
        # An added line from the diff (escaped) and add/del counts.
        self.assertIn("diff-add", body)
        self.assertIn("env python3", body)
        self.assertIn("stat-add", body)

    def test_tree_root_and_subdir(self):
        status, _h, body = self.get_text("/myrepo/tree", {"ref": "main"})
        self.assertEqual(status, 200)
        self.assertIn("src", body)
        self.assertIn("README.md", body)
        self.assertIn("assets", body)

        status, _h, body = self.get_text("/myrepo/tree", {"ref": "main", "path": "src"})
        self.assertEqual(status, 200)
        self.assertIn("main.py", body)

    def test_blob_line_numbers_and_escaping(self):
        status, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "src/main.py"}
        )
        self.assertEqual(status, 200)
        # Line-number gutter anchors.
        self.assertIn('id="L1"', body)
        self.assertIn('href="#L1"', body)
        # Special characters escaped, never live markup.
        self.assertIn("&lt;hello&gt;", body)
        self.assertIn("1 &lt; 2", body)
        self.assertNotIn("<hello>", body)
        # Raw + blame actions offered.
        self.assertIn("Raw", body)
        self.assertIn("Blame", body)

    def test_raw_text(self):
        status, headers, body = self.get("/myrepo/raw", {"ref": "main", "path": "src/main.py"})
        self.assertEqual(status, 200)
        self.assertTrue(headers.get("Content-Type", "").startswith("text/plain"))
        self.assertIn("charset=utf-8", headers.get("Content-Type", ""))
        self.assertEqual(body, MAIN_PY.encode("utf-8"))
        self.assertIn("filename=", headers.get("Content-Disposition", ""))

    def test_raw_conditional_get_304_with_gzip(self):
        # /raw is never content-coded, so its ETag has no encoding suffix.  A
        # browser (which always advertises gzip) revalidating with the exact
        # ETag the server issued must still get a 304 — not a full re-download.
        status, headers, _b = self.get(
            "/myrepo/raw", {"ref": "main", "path": "src/main.py"}
        )
        self.assertEqual(status, 200)
        etag = headers.get("ETag")
        self.assertTrue(etag and etag.startswith('"') and "-gzip" not in etag)
        status2, _h2, body2 = self.get_h(
            "/myrepo/raw",
            {"ref": "main", "path": "src/main.py"},
            headers={"If-None-Match": etag, "Accept-Encoding": "gzip"},
        )
        self.assertEqual(status2, 304)
        self.assertEqual(body2, b"")

    def test_binary_blob_and_raw(self):
        status, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "assets/logo.bin"}
        )
        self.assertEqual(status, 200)
        self.assertIn("Binary file", body)
        self.assertIn("bytes", body)

        status, headers, raw = self.get(
            "/myrepo/raw", {"ref": "main", "path": "assets/logo.bin"}
        )
        self.assertEqual(status, 200)
        self.assertEqual(headers.get("Content-Type"), "application/octet-stream")
        self.assertIn("attachment", headers.get("Content-Disposition", ""))
        self.assertEqual(raw, BINARY)

    def test_blame(self):
        status, _h, body = self.get_text(
            "/myrepo/blame", {"ref": "main", "path": "src/main.py"}
        )
        self.assertEqual(status, 200)
        self.assertIn("Test Author", body)
        # At least one 8-hex commit id column.
        self.assertRegex(body, r">[0-9a-f]{8}<")
        self.assertIn("env python3", body)

    # -- security ------------------------------------------------------- #

    def test_traversal_repo_name_rejected(self):
        status, _h, _b = self.get("/..%2f..%2fetc%2fpasswd")
        self.assertIn(status, (400, 404))

    def test_traversal_path_rejected(self):
        status, _h, _b = self.get(
            "/myrepo/blob", {"ref": "main", "path": "../../../../etc/passwd"}
        )
        self.assertIn(status, (400, 404))

    def test_absolute_path_rejected(self):
        status, _h, _b = self.get("/myrepo/blob", {"ref": "main", "path": "/etc/passwd"})
        self.assertIn(status, (400, 404))

    def test_option_like_ref_rejected(self):
        status, _h, _b = self.get("/myrepo/log", {"ref": "--output=/tmp/x"})
        self.assertEqual(status, 400)

    def test_unknown_repo_404(self):
        status, _h, _b = self.get("/does-not-exist/")
        self.assertEqual(status, 404)

    # -- regression: resource bounding & correctness -------------------- #

    def test_empty_repo_log_renders_empty_not_404(self):
        # An empty / unborn-HEAD repo must show an empty log page, not 404.
        status, _h, body = self.get_text("/emptyrepo/log")
        self.assertEqual(status, 200)
        self.assertIn("No commits", body)

    def test_peek_blob_stops_at_cap(self):
        # peek_blob on a 32 MiB blob returns exactly the requested cap: proof
        # it stops early instead of pulling the whole object.
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "bigrepo")
        peek = gitcmd.peek_blob(repo, "main", "big.txt", 8192)
        self.assertEqual(len(peek), 8192)

    def test_large_blob_read_is_memory_bounded(self):
        # The core regression: an 8 KiB read of a 32 MiB blob must NOT buffer
        # the whole blob (the old communicate() path did).  Measured in a
        # forked child so peak RSS is isolated from the test process.
        import multiprocessing

        try:
            ctx = multiprocessing.get_context("fork")
        except ValueError:  # pragma: no cover - non-fork platforms
            self.skipTest("fork start method unavailable")
        q = ctx.Queue()
        p = ctx.Process(
            target=_blob_read_rss_probe, args=(self.root, "bigrepo", "big.txt", q)
        )
        p.start()
        p.join(60)
        self.assertFalse(p.is_alive(), "memory probe did not finish")
        length, delta_kib = q.get(timeout=5)
        # ~8 KiB returned, and far less than the 32 MiB blob resident.
        self.assertLessEqual(length, 8193)
        self.assertLess(
            delta_kib, 8 * 1024, f"read buffered too much: {delta_kib} KiB grew"
        )

    def test_large_blob_not_dumped_inline(self):
        # The blob view must refuse to inline a 32 MiB file (bounded response),
        # exercising the now-bounded peek + size gate.
        status, _h, body = self.get_text(
            "/bigrepo/blob", {"ref": "main", "path": "big.txt"}
        )
        self.assertEqual(status, 200)
        self.assertIn("inline display limit", body)
        self.assertLess(len(body), 200_000, "32 MiB blob was inlined")

    def test_symlinked_out_repo_not_listed_or_accessible(self):
        # A symlink under the root pointing OUTSIDE it must be neither listed
        # nor reachable by URL.
        outside = os.path.join(self.tmp, "outside_repo")
        os.makedirs(outside, exist_ok=True)
        _run(["git", "init", "-b", "main"], outside, self.env)
        link = os.path.join(self.root, "sneaky")
        if not os.path.islink(link):
            os.symlink(outside, link)
        status, _h, body = self.get_text("/")
        self.assertEqual(status, 200)
        self.assertNotIn("sneaky", body)
        status2, _h2, _b2 = self.get("/sneaky/")
        self.assertIn(status2, (400, 404))


    # -- item 1: persistent cat-file batch reader ----------------------- #

    def test_batch_header_parsing(self):
        from gitweb import gitcmd

        self.assertIsNone(gitcmd._parse_batch_header(b""))
        self.assertIsNone(gitcmd._parse_batch_header(b"deadbeef missing\n"))
        self.assertIsNone(gitcmd._parse_batch_header(b"deadbeef ambiguous\n"))
        st = gitcmd._parse_batch_header(
            b"e69de29bb2d1d6434b8b29ae775ad8c2e48c5391 blob 0\n"
        )
        self.assertEqual((st.type, st.size), ("blob", 0))

    def test_catfile_batch_reader_reused_and_bounded(self):
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "myrepo")
        st = gitcmd.stat_object(repo, "main", "src/main.py")
        self.assertIsNotNone(st)
        self.assertEqual(st.type, "blob")
        self.assertEqual(st.size, len(MAIN_PY.encode("utf-8")))
        self.assertRegex(st.sha, r"^[0-9a-f]{40}$")
        # A missing path resolves to None (not an exception).
        self.assertIsNone(gitcmd.stat_object(repo, "main", "no/such/file"))

        # The --batch-check process is long-lived and reused across lookups.
        cf = gitcmd._catfile(repo.path)
        gitcmd.stat_object(repo, "main", "README.md")
        proc1 = cf._check
        self.assertIsNotNone(proc1)
        self.assertIsNone(proc1.poll())  # alive
        gitcmd.stat_object(repo, "main", "src/main.py")
        self.assertIs(cf._check, proc1)  # same process, not re-forked

        # Content reads honour the byte cap (early stop, not full buffering).
        data = gitcmd.read_blob(repo, "main", "src/main.py", 8)
        self.assertEqual(data, MAIN_PY.encode("utf-8")[:8])

    def test_batch_reader_rejects_newline_spec_no_content_bleed(self):
        # Regression: a repo-derived path containing a newline must not desync
        # the persistent cat-file batch stream (which would return the wrong
        # blob's bytes to later requests).
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "hostilerepo")
        cf = gitcmd._catfile(repo.path)
        self.assertFalse(cf._spec_ok("main:readme\nHACK.txt"))
        self.assertTrue(cf._spec_ok("main:normal.txt"))

        # Reading the hostile (newline) path is refused, not injected.
        self.assertRaises(
            gitcmd.NotFound,
            gitcmd.read_blob,
            repo,
            "main",
            self.HOSTILE_NAME,
            1 << 20,
        )
        # A normal read stays correct afterwards (would be wrong if desynced).
        self.assertEqual(
            gitcmd.read_blob(repo, "main", "normal.txt", 1 << 20),
            self.NORMAL_TXT.encode("utf-8"),
        )
        # And again, interleaved, to be sure the stream is aligned.
        self.assertEqual(
            gitcmd.read_blob(repo, "main", "README.md", 1 << 20),
            self.REAL_README.encode("utf-8"),
        )
        # The summary renders the *genuine* README, not the hostile entry.
        _s, _h, body = self.get_text("/hostilerepo/")
        self.assertIn("genuine readme body", body)
        self.assertNotIn("HOSTILE-BLOB-CONTENTS", body)

    # -- item 2: ETag + 304 conditional GET ----------------------------- #

    def test_etag_and_conditional_get_blob(self):
        status, headers, _b = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "src/main.py"}
        )
        self.assertEqual(status, 200)
        etag = headers.get("ETag")
        self.assertTrue(etag and etag.startswith('"'))
        # Revalidating with the ETag yields 304 and no body.
        status2, headers2, body2 = self.get_h(
            "/myrepo/blob",
            {"ref": "main", "path": "src/main.py"},
            headers={"If-None-Match": etag},
        )
        self.assertEqual(status2, 304)
        self.assertEqual(body2, b"")

    def test_etag_conditional_get_commit_full_sha(self):
        status, headers, _b = self.get_text(
            "/myrepo/commit", {"id": self.commit_main_py}
        )
        self.assertEqual(status, 200)
        etag = headers.get("ETag")
        self.assertIsNotNone(etag)
        status2, _h2, _b2 = self.get_h(
            "/myrepo/commit",
            {"id": self.commit_main_py},
            headers={"If-None-Match": etag},
        )
        self.assertEqual(status2, 304)

    def test_etag_varies_by_render_variant(self):
        # A 304 must never serve the wrong rendered variant of the same blob.
        _s, headers, _b = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "src/main.py"}
        )
        etag = headers.get("ETag")
        # Same URL revalidates to 304 ...
        s_same, _h, _b2 = self.get_h(
            "/myrepo/blob",
            {"ref": "main", "path": "src/main.py"},
            headers={"If-None-Match": etag},
        )
        self.assertEqual(s_same, 304)
        # ... but a highlighted variant with the plain ETag is a fresh 200.
        s_hl, _h2, _b3 = self.get_h(
            "/myrepo/blob",
            {"ref": "main", "path": "src/main.py", "highlight": "1-2"},
            headers={"If-None-Match": etag},
        )
        self.assertEqual(s_hl, 200)
        # Tree pages must not collide either.
        base, stop = self._temp_server(tree_page_size=2)
        try:
            _s2, th, _tb = self.get_h("/myrepo/tree", {"ref": "main"}, base=base)
            tetag = th.get("ETag")
            self.assertIsNotNone(tetag)
            s_p2, _h3, _b4 = self.get_h(
                "/myrepo/tree",
                {"ref": "main", "page": "2"},
                headers={"If-None-Match": tetag},
                base=base,
            )
            self.assertEqual(s_p2, 200)  # page 2 is a distinct response
        finally:
            stop()

    # -- item 3: cached last-commit timestamp --------------------------- #

    def test_last_commit_ts_cached(self):
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "myrepo")
        ts1 = gitcmd.cached_last_commit_ts(repo.path, repo.bare)
        self.assertIsInstance(ts1, int)
        self.assertIn(repo.path, gitcmd._TS_CACHE)
        ts2 = gitcmd.cached_last_commit_ts(repo.path, repo.bare)
        self.assertEqual(ts1, ts2)  # served from cache, same value

    # -- item 4: bounded worker pool ------------------------------------ #

    def test_bounded_server_rejects_when_saturated(self):
        from gitweb import metrics
        from gitweb.server import BoundedThreadingHTTPServer

        self.assertIsInstance(self.httpd, BoundedThreadingHTTPServer)
        # Saturate the pool, then a further connection must be dropped (closed)
        # without spawning a handler thread.
        server = self.httpd
        got = server._slots.acquire(blocking=False)
        # Drain every remaining slot so the pool is full.
        drained = 0
        while server._slots.acquire(blocking=False):
            drained += 1
        try:
            closed = {"n": 0}
            orig = server.shutdown_request
            server.shutdown_request = lambda req: closed.__setitem__("n", closed["n"] + 1)
            before = metrics.REGISTRY.snapshot()["rejected"]
            server.process_request(object(), ("127.0.0.1", 0))
            self.assertEqual(closed["n"], 1)  # rejected path closed the request
            after = metrics.REGISTRY.snapshot()["rejected"]
            self.assertEqual(after, before + 1)
        finally:
            server.shutdown_request = orig
            for _ in range(drained):
                server._slots.release()
            if got:
                server._slots.release()

    def test_socket_timeout_closes_slow_client(self):
        import socket
        import time

        base, stop = self._temp_server(socket_timeout=1.0)
        try:
            port = int(base.rsplit(":", 1)[1])
            sock = socket.create_connection(("127.0.0.1", port), timeout=5)
            # Send a partial request line and then stall (Slowloris).
            sock.sendall(b"GET / HTTP/1.1\r\n")
            sock.settimeout(5)
            start = time.monotonic()
            data = sock.recv(4096)  # server times out reading and closes.
            elapsed = time.monotonic() - start
            sock.close()
            self.assertEqual(data, b"")  # dropped with no response
            self.assertLess(elapsed, 4.0)  # near the 1s server-side timeout
        finally:
            stop()

    # -- item 5: gzip HTML ---------------------------------------------- #

    def test_gzip_html_response(self):
        status, headers, body = self.get_h(
            "/myrepo/", headers={"Accept-Encoding": "gzip"}
        )
        self.assertEqual(status, 200)
        self.assertEqual(headers.get("Content-Encoding"), "gzip")
        self.assertIn("Accept-Encoding", headers.get("Vary", ""))
        text = gzip.decompress(body).decode("utf-8")
        self.assertIn("main", text)

    # -- item 6: tree pagination ---------------------------------------- #

    def test_tree_pagination(self):
        base, stop = self._temp_server(tree_page_size=2)
        try:
            status, _h, body = self.get_h("/myrepo/tree", {"ref": "main"}, base=base)
            text = body.decode("utf-8")
            self.assertEqual(status, 200)
            self.assertIn("page 1 of", text)
            self.assertIn("Next", text)
            # Page 2 exists and links back.
            status2, _h2, body2 = self.get_h(
                "/myrepo/tree", {"ref": "main", "page": "2"}, base=base
            )
            self.assertEqual(status2, 200)
            self.assertIn("Prev", body2.decode("utf-8"))
        finally:
            stop()

    # -- item 7: per-file history --------------------------------------- #

    def test_history_page(self):
        status, _h, body = self.get_text(
            "/myrepo/history", {"ref": "main", "path": "src/main.py"}
        )
        self.assertEqual(status, 200)
        self.assertIn("Add main.py", body)
        self.assertIn("History of", body)
        self.assertIn("View file", body)
        # The blob view links to history.
        _s, _hh, blob = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "src/main.py"}
        )
        self.assertIn("History", blob)

    # -- item 8: Atom feed ---------------------------------------------- #

    def test_atom_feed(self):
        status, headers, body = self.get_text("/myrepo/atom", {"ref": "main"})
        self.assertEqual(status, 200)
        self.assertTrue(
            headers.get("Content-Type", "").startswith("application/atom+xml")
        )
        self.assertIn("<feed", body)
        self.assertIn("http://www.w3.org/2005/Atom", body)
        self.assertIn("Add binary asset", body)  # a recent commit subject
        self.assertIn("<entry>", body)

    def test_atom_feed_wellformed_with_control_chars(self):
        # A commit whose subject/author contain XML-illegal C0 control chars
        # (form-feed / ESC) must NOT break the feed: it has to parse as
        # well-formed XML, exactly as a real feed reader would require.
        status, headers, body = self.get("/ctrlrepo/atom", {"ref": "main"})
        self.assertEqual(status, 200)
        self.assertTrue(
            headers.get("Content-Type", "").startswith("application/atom+xml")
        )
        # The raw control bytes never reach the wire ...
        self.assertNotIn(b"\x0c", body)
        self.assertNotIn(b"\x1b", body)
        # ... and the whole document is well-formed (parses without error).
        doc = xml.dom.minidom.parseString(body)
        self.assertEqual(doc.documentElement.tagName, "feed")

    # -- item 9: compare view ------------------------------------------- #

    def test_compare_view(self):
        status, _h, body = self.get_text(
            "/myrepo/compare", {"from": "main", "to": "feature"}
        )
        self.assertEqual(status, 200)
        self.assertIn("src/main.py", body)
        self.assertIn("feature branch line", body)
        self.assertIn("diff-add", body)

    def test_compare_requires_both_refs(self):
        status, _h, _b = self.get("/myrepo/compare", {"from": "main"})
        self.assertEqual(status, 400)

    # -- item 11: snapshot archive -------------------------------------- #

    def test_archive_targz(self):
        status, headers, body = self.get("/myrepo/archive", {"ref": "main"})
        self.assertEqual(status, 200)
        ctype = headers.get("Content-Type", "")
        self.assertIn("gzip", ctype)
        self.assertIn("attachment", headers.get("Content-Disposition", ""))
        self.assertIn(".tar.gz", headers.get("Content-Disposition", ""))
        # Body is a valid gzip tar that contains the prefixed README.
        raw = gzip.decompress(body)
        with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
            names = tar.getnames()
        self.assertTrue(any(n.endswith("README.md") for n in names))
        self.assertTrue(any(n.startswith("myrepo-main/") for n in names))

    def test_stream_git_argv_terminates_options(self):
        # Defense-in-depth uniformity: EVERY git invocation terminates its
        # options with `--` so a validated ref/spec can never be parsed as an
        # option flag. Capture the argv stream_archive / stream_blob hand to git
        # (without spawning git) and assert the `--` separator is present and
        # positioned so the tree-ish/spec follows an option terminator.
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "myrepo")
        captured = {}

        class _FakeStdout:
            def read(self, _n):
                return b""

            def close(self):
                pass

        class _FakeProc:
            def __init__(self, cmd, **_kw):
                captured["cmd"] = list(cmd)
                self.stdout = _FakeStdout()

            def poll(self):
                return 0

            def wait(self):
                return 0

            def kill(self):
                pass

        orig = gitcmd.subprocess.Popen
        gitcmd.subprocess.Popen = _FakeProc
        try:
            list(gitcmd.stream_archive(repo, "main", "myrepo-main/"))
            archive_cmd = captured["cmd"]
            list(gitcmd.stream_blob(repo, "main", "src/main.py"))
            blob_cmd = captured["cmd"]
        finally:
            gitcmd.subprocess.Popen = orig

        # archive: `git archive ... <tree-ish> --` -- options terminated, the
        # ref precedes the separator and no pathspec is smuggled past it.
        self.assertIn("archive", archive_cmd)
        self.assertIn("--", archive_cmd)
        self.assertEqual(archive_cmd[-1], "--")
        self.assertEqual(archive_cmd[-2], "main")

        # blob: `git ... cat-file -p -- <spec>` -- the separator precedes the
        # (validated) object spec so it can never be read as an option.
        self.assertIn("cat-file", blob_cmd)
        self.assertIn("--", blob_cmd)
        self.assertEqual(blob_cmd[-2], "--")
        self.assertEqual(blob_cmd[-1], "main:src/main.py")
        self.assertLess(blob_cmd.index("cat-file"), blob_cmd.index("--"))

    # -- item 10: inline image rendering -------------------------------- #

    def test_image_blob_inline_and_raw(self):
        status, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "assets/pic.png"}
        )
        self.assertEqual(status, 200)
        self.assertIn("<img", body)
        self.assertIn("blob-img", body)
        self.assertNotIn("Binary file", body)
        # /raw serves a genuine image content-type, inline (not attachment).
        status2, headers2, raw = self.get(
            "/myrepo/raw", {"ref": "main", "path": "assets/pic.png"}
        )
        self.assertEqual(status2, 200)
        self.assertEqual(headers2.get("Content-Type"), "image/png")
        self.assertIn("inline", headers2.get("Content-Disposition", ""))
        self.assertEqual(raw, PNG_1x1)

    # -- item 12: signed-commit verified badge -------------------------- #

    def test_signature_status_and_badge(self):
        from gitweb import gitcmd, views

        repo = gitcmd.resolve_repo(self.root, "myrepo")
        commit = gitcmd.commit_meta(repo, self.commit_main_py)
        # Fixture commits are unsigned.
        self.assertEqual(commit.signature_status, "N")
        self.assertFalse(commit.signature_verified)
        # The rendered page shows no Verified badge for an unsigned commit.
        _s, _h, body = self.get_text("/myrepo/commit", {"id": self.commit_main_py})
        self.assertNotIn("Verified", body)
        # A good-signature commit renders the Verified badge (badge logic).
        import dataclasses

        signed = dataclasses.replace(
            commit, signature_status="G", signing_key="ABCD1234"
        )
        self.assertTrue(signed.signature_verified)
        self.assertIn("Verified", views._signature_badge(signed))
        self.assertIn("Verified", views._signature_detail(signed))

    # -- item 13: optional syntax highlighting (fallback is default) ---- #

    def test_syntax_highlight_default_is_fallback(self):
        from gitweb import views

        # Highlighting is opt-in: the default renderer emits escaped plaintext,
        # so the viewer is fully functional with the standard library alone
        # regardless of whether Pygments happens to be present.
        html = views._numbered_lines("a = 1 < 2\n", path="x.py")  # highlight off
        self.assertIn("1 &lt; 2", html)
        self.assertNotIn("<span", html)
        # The live blob view (default config) uses the fallback path.
        _s, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "src/main.py"}
        )
        self.assertIn("&lt;hello&gt;", body)
        self.assertNotIn("<hello>", body)

    def test_syntax_highlight_optional_path_is_escape_safe(self):
        from gitweb import views

        # Even with highlighting enabled, hostile content is never rendered as
        # live markup (Pygments escapes; the fallback escapes) — no XSS.
        html = views._numbered_lines(
            "x = '<script>alert(1)</script>'\n", path="x.py", highlight=True
        )
        self.assertNotIn("<script>", html)

    # -- item 14: ref switcher, permalink, line-range ------------------- #

    def test_ref_switcher_and_permalink(self):
        status, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "src/main.py"}
        )
        self.assertEqual(status, 200)
        self.assertIn("Switch ref", body)
        self.assertIn("feature", body)  # a branch offered by the switcher
        self.assertIn("Permalink", body)
        # The permalink pins a full commit sha into the blob URL.
        self.assertRegex(body, r"blob\?ref=[0-9a-f]{40}")

    def test_line_range_highlight(self):
        status, _h, body = self.get_text(
            "/myrepo/blob",
            {"ref": "main", "path": "src/main.py", "highlight": "2-3"},
        )
        self.assertEqual(status, 200)
        self.assertIn('id="L2" class="hl"', body)
        self.assertIn('id="L3" class="hl"', body)
        self.assertNotIn('id="L1" class="hl"', body)

    # -- item 15: submodules + LFS -------------------------------------- #

    def test_submodule_pinned_sha_and_url(self):
        status, _h, body = self.get_text("/myrepo/tree", {"ref": "main"})
        self.assertEqual(status, 200)
        self.assertIn("vendor", body)
        self.assertIn("submodule", body)
        self.assertIn(self.submodule_sha[:12], body)  # pinned sha
        self.assertIn("https://example.com/vendor.git", body)  # .gitmodules url

    def test_lfs_pointer_detection(self):
        from gitweb import gitcmd

        p = gitcmd.parse_lfs_pointer(LFS_POINTER.encode("utf-8"))
        self.assertIsNotNone(p)
        self.assertEqual(p.size, 12345)
        self.assertIsNone(gitcmd.parse_lfs_pointer(MAIN_PY.encode("utf-8")))
        # The blob view shows pointer info + raw link, not rendered source.
        status, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "assets/big.lfs"}
        )
        self.assertEqual(status, 200)
        self.assertIn("Git LFS pointer", body)
        self.assertIn("12345", body)
        self.assertNotIn('td class="line"', body)

    def test_parse_gitmodules_unit(self):
        from gitweb import gitcmd

        mapping = gitcmd._parse_gitmodules(
            '[submodule "vendor"]\n\tpath = vendor\n'
            "\turl = https://example.com/vendor.git\n"
        )
        self.assertEqual(mapping.get("vendor"), "https://example.com/vendor.git")

    # -- item 17: reverse-proxy sub-path mounting ----------------------- #

    def test_url_prefix_mounting(self):
        base, stop = self._temp_server(url_prefix="/git")
        try:
            status, _h, body = self.get_h("/git/", base=base)
            self.assertEqual(status, 200)
            text = body.decode("utf-8")
            self.assertIn("myrepo", text)
            self.assertIn('href="/git/', text)  # links carry the prefix
            # Unprefixed paths are not served under a prefixed mount.
            status2, _h2, _b2 = self.get_h("/", base=base)
            self.assertEqual(status2, 404)
            # A prefixed repo page works and keeps its links prefixed.
            status3, _h3, body3 = self.get_h("/git/myrepo/", base=base)
            self.assertEqual(status3, 200)
            self.assertIn('href="/git/myrepo/', body3.decode("utf-8"))
        finally:
            stop()

    # -- item 19: Markdown upgrades + rendered .md blobs ---------------- #

    def test_markdown_upgrades_unit(self):
        from gitweb import markup

        html = markup.render_markdown(GUIDE_MD)
        self.assertIn('table class="md-table"', html)  # tables
        self.assertIn("alpha", html)
        self.assertIn("<img src=", html)  # images
        self.assertIn('href="https://autolink.example.com"', html)  # autolinks
        self.assertIn('type="checkbox"', html)  # task lists
        self.assertIn("checked", html)

    def test_markdown_blob_rendered_with_source_toggle(self):
        status, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "docs/guide.md"}
        )
        self.assertEqual(status, 200)
        self.assertIn("md-table", body)  # rendered by default
        self.assertIn("View source", body)
        status2, _h2, body2 = self.get_text(
            "/myrepo/blob",
            {"ref": "main", "path": "docs/guide.md", "display": "source"},
        )
        self.assertEqual(status2, 200)
        self.assertIn('class="line"', body2)  # numbered source
        self.assertIn("View rendered", body2)

    def test_markdown_link_image_injection_safety(self):
        from gitweb import markup

        html = markup.render_markdown(
            "![x](javascript:alert(1))\n\n[y](javascript:alert(2))\n\n"
            "<script>alert(3)</script>\n"
        )
        self.assertNotIn('src="javascript', html)
        self.assertNotIn('href="javascript', html)
        self.assertNotIn("<script>", html)
        self.assertIn("&lt;script&gt;", html)

    def test_markdown_embedded_nul_does_not_crash(self):
        # A NUL byte in blob content collides with the inline placeholder
        # sentinel (\x00N\x00).  It must be stripped, not raise, so the render
        # still succeeds (rather than silently degrading to the source fallback).
        from gitweb import markup

        html = markup.render_markdown("a `code` b\x001\x00 c\n")
        self.assertIn("<code>code</code>", html)  # rendered, no IndexError
        self.assertNotIn("\x00", html)
        # An out-of-range sentinel index is likewise neutralised.
        self.assertNotIn("\x00", markup.render_markdown("x\x0099\x00 y\n"))

    # -- item 18: packaging --------------------------------------------- #

    def test_packaging_metadata(self):
        import tomllib

        root = os.path.dirname(
            os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        )
        pyproject = os.path.join(root, "pyproject.toml")
        self.assertTrue(os.path.isfile(pyproject))
        with open(pyproject, "rb") as fh:
            data = tomllib.load(fh)
        # Console entry point wired to the CLI main().
        self.assertEqual(
            data["project"]["scripts"]["gitweb"], "gitweb.__main__:main"
        )
        # Zero runtime dependencies (stdlib-only ethos preserved).
        self.assertEqual(data["project"]["dependencies"], [])
        # Deployment artefacts exist.
        self.assertTrue(os.path.isfile(os.path.join(root, "Dockerfile")))
        self.assertTrue(
            os.path.isfile(os.path.join(root, "deploy", "gitweb.service"))
        )
        # The entry point target is importable and callable.
        from gitweb.__main__ import main

        self.assertTrue(callable(main))

    # -- item 16: metrics + health -------------------------------------- #

    def test_health_and_metrics(self):
        status, headers, body = self.get("/health")
        self.assertEqual(status, 200)
        self.assertEqual(body.strip(), b"ok")
        status2, _h2, body2 = self.get_text("/metrics")
        self.assertEqual(status2, 200)
        self.assertIn("gitweb_requests_total", body2)
        self.assertIn("gitweb_responses_total", body2)

    # -- Git Smart HTTP: read-only clone / fetch ------------------------ #

    def post(self, path, body=b"", headers=None, base=None):
        """POST helper returning (status, headers, body)."""
        import http.client

        origin = (base or self.base).split("://", 1)[1]
        host, port = origin.split(":")
        conn = http.client.HTTPConnection(host, int(port), timeout=15)
        try:
            conn.request("POST", path, body=body, headers=headers or {})
            resp = conn.getresponse()
            return resp.status, dict(resp.getheaders()), resp.read()
        finally:
            conn.close()

    def _clone_env(self):
        # Hermetic identity + no proxy interference for the loopback transport.
        env = dict(self.env)
        env["GIT_TERMINAL_PROMPT"] = "0"
        return env

    def _git_clone(self, url, dst, *, version=None):
        args = ["git"]
        if version is not None:
            args += ["-c", f"protocol.version={version}"]
        # Never route the loopback clone through an ambient http proxy.
        args += ["-c", "http.proxy=", "clone", url, dst]
        return subprocess.run(
            args, env=self._clone_env(),
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )

    def test_clone_info_refs_advertisement_wellformed(self):
        # GET info/refs must return a well-formed pkt-line advertisement that
        # opens with the service banner, with the git content-type + no-cache.
        status, headers, body = self.get(
            "/myrepo/info/refs", {"service": "git-upload-pack"}
        )
        self.assertEqual(status, 200)
        self.assertEqual(
            headers.get("Content-Type"),
            "application/x-git-upload-pack-advertisement",
        )
        self.assertIn("no-cache", headers.get("Cache-Control", ""))
        self.assertEqual(headers.get("X-Content-Type-Options"), "nosniff")
        self.assertNotIn("Content-Encoding", headers)  # git frames its own
        # First pkt-line is exactly "# service=git-upload-pack\n" then a flush.
        self.assertTrue(body.startswith(b"001e# service=git-upload-pack\n0000"))
        # The 4-hex length prefix of the banner must equal its byte length.
        self.assertEqual(int(body[:4], 16), 0x1E)

    def test_clone_end_to_end_and_fetch(self):
        # A real `git clone` over HTTP must succeed and reproduce HEAD, both
        # branches and the tag; a subsequent `git fetch` must also succeed.
        dst = os.path.join(tempfile.mkdtemp(prefix="gitweb-clone-"), "myrepo")
        res = self._git_clone(f"{self.base}/myrepo", dst)
        self.assertEqual(res.returncode, 0, res.stderr)

        expected_head = _capture(["git", "rev-parse", "main"], self.repo, self.env)
        cloned_head = _capture(["git", "rev-parse", "HEAD"], dst, self.env)
        self.assertEqual(cloned_head, expected_head)

        remote_branches = _capture(["git", "branch", "-r"], dst, self.env)
        self.assertIn("origin/main", remote_branches)
        self.assertIn("origin/feature", remote_branches)
        self.assertIn("v1.0", _capture(["git", "tag"], dst, self.env))
        log = _capture(["git", "log", "--oneline"], dst, self.env)
        self.assertIn("Add README", log)

        # git fetch against the same endpoint works (no-op, but exercises it).
        fetched = subprocess.run(
            ["git", "-c", "http.proxy=", "fetch", "--all"],
            cwd=dst, env=self._clone_env(),
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        self.assertEqual(fetched.returncode, 0, fetched.stderr)

    def test_clone_protocol_v2(self):
        # Protocol v2 clone works, and its info/refs advertisement carries NO
        # service banner (v2 goes straight to the capability advertisement).
        status, _h, body = self.get_h(
            "/myrepo/info/refs",
            {"service": "git-upload-pack"},
            headers={"Git-Protocol": "version=2"},
        )
        self.assertEqual(status, 200)
        self.assertFalse(body.startswith(b"001e# service="))
        self.assertIn(b"version 2", body[:32])

        dst = os.path.join(tempfile.mkdtemp(prefix="gitweb-clonev2-"), "myrepo")
        res = self._git_clone(f"{self.base}/myrepo", dst, version=2)
        self.assertEqual(res.returncode, 0, res.stderr)
        self.assertEqual(
            _capture(["git", "rev-parse", "HEAD"], dst, self.env),
            _capture(["git", "rev-parse", "main"], self.repo, self.env),
        )

    def test_clone_push_is_refused_403(self):
        # Read-only: both the receive-pack advertisement and a direct
        # receive-pack RPC must be forbidden.  upload-pack is the only transport.
        status, _h, _b = self.get(
            "/myrepo/info/refs", {"service": "git-receive-pack"}
        )
        self.assertEqual(status, 403)
        pstatus, _ph, _pb = self.post(
            "/myrepo/git-receive-pack", b"0000",
            {"Content-Type": "application/x-git-receive-pack-request"},
        )
        self.assertEqual(pstatus, 403)

    def test_clone_upload_pack_only_in_allowlist(self):
        # Defence in depth: the git subcommand allow-list gained upload-pack and
        # must never contain receive-pack (push).
        from gitweb import gitcmd

        self.assertIn("upload-pack", gitcmd.ALLOWED_SUBCOMMANDS)
        self.assertNotIn("receive-pack", gitcmd.ALLOWED_SUBCOMMANDS)

    def test_clone_repo_confinement(self):
        # A well-formed but non-existent repo name -> 404; a traversal attempt
        # is likewise refused (400/404), never resolving outside the root.
        status, _h, _b = self.get(
            "/no-such-repo/info/refs", {"service": "git-upload-pack"}
        )
        self.assertEqual(status, 404)
        tstatus, _th, _tb = self.get(
            "/..%2f..%2fetc/info/refs", {"service": "git-upload-pack"}
        )
        self.assertIn(tstatus, (400, 404))

    def test_clone_gzip_request_body_accepted(self):
        # git may gzip the upload-pack request; a gzipped (flush-only) body must
        # be inflated and served, yielding the git result content-type.
        payload = gzip.compress(b"0000")
        status, headers, _b = self.post(
            "/myrepo/git-upload-pack", payload,
            {
                "Content-Type": "application/x-git-upload-pack-request",
                "Content-Encoding": "gzip",
            },
        )
        self.assertEqual(status, 200)
        self.assertEqual(
            headers.get("Content-Type"), "application/x-git-upload-pack-result"
        )

    def test_clone_body_cap_rejected(self):
        # An over-large request body is rejected before any pack work.
        base, stop = self._temp_server(clone_max_body_bytes=16)
        try:
            status, _h, _b = self.post(
                "/myrepo/git-upload-pack", b"X" * 1024,
                {"Content-Type": "application/x-git-upload-pack-request"},
                base=base,
            )
            self.assertEqual(status, 400)
        finally:
            stop()

    def test_clone_concurrency_cap_returns_503(self):
        # Saturate the (small) upload-pack semaphore; a further RPC is shed with
        # 503 rather than being allowed to starve the worker pool.
        httpd = make_server(
            Config(
                root=self.root, host="127.0.0.1", port=0, verbose=False,
                clone_max_concurrency=1,
            )
        )
        port = httpd.server_address[1]
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()
        base = f"http://127.0.0.1:{port}"
        try:
            # Exhaust the single clone slot, then a POST must be shed with 503.
            self.assertTrue(httpd.clone_slots.acquire(blocking=False))
            try:
                status, headers, _b = self.post(
                    "/myrepo/git-upload-pack", b"0000",
                    {"Content-Type": "application/x-git-upload-pack-request"},
                    base=base,
                )
                self.assertEqual(status, 503)
                self.assertIn("Retry-After", headers)
            finally:
                httpd.clone_slots.release()
        finally:
            httpd.shutdown()
            httpd.server_close()
            thread.join(timeout=5)

    def test_clone_disabled_endpoints_404_browsing_works(self):
        # With clone serving off, every RPC endpoint 404s while browsing works.
        base, stop = self._temp_server(enable_clone=False)
        try:
            s1, _h1, _b1 = self.get_h(
                "/myrepo/info/refs", {"service": "git-upload-pack"}, base=base
            )
            self.assertEqual(s1, 404)
            s2, _h2, _b2 = self.post(
                "/myrepo/git-upload-pack", b"0000",
                {"Content-Type": "application/x-git-upload-pack-request"},
                base=base,
            )
            self.assertEqual(s2, 404)
            s3, _h3, _b3 = self.post(
                "/myrepo/git-receive-pack", b"0000", base=base
            )
            self.assertEqual(s3, 404)
            # Browsing is unaffected, and no clone command is advertised.
            s4, _h4, body4 = self.get_h("/myrepo/", base=base)
            self.assertEqual(s4, 200)
            self.assertNotIn("git clone", body4.decode("utf-8"))
            # A real clone against the disabled server fails.
            dst = os.path.join(tempfile.mkdtemp(prefix="gitweb-nodl-"), "r")
            res = self._git_clone(f"{base}/myrepo", dst)
            self.assertNotEqual(res.returncode, 0)
        finally:
            stop()

    def test_clone_summary_shows_command(self):
        # The summary page surfaces the exact git clone command (Host-derived).
        status, _h, body = self.get_text("/myrepo/")
        self.assertEqual(status, 200)
        self.assertIn(f"git clone {self.base}/myrepo", body)

    def test_clone_summary_shows_command_with_base_url_and_prefix(self):
        # A configured external base URL + reverse-proxy prefix compose into the
        # advertised clone URL (onion-address style deployment).
        base, stop = self._temp_server(
            url_prefix="/git", clone_base_url="http://example.onion"
        )
        try:
            status, _h, body = self.get_h("/git/myrepo/", base=base)
            self.assertEqual(status, 200)
            self.assertIn(
                "git clone http://example.onion/git/myrepo", body.decode("utf-8")
            )
        finally:
            stop()

    # -- security regression: process-group teardown & body-before-slot ----- #

    def test_kill_process_group_reaps_descendants(self):
        # CRITICAL fix: a wall-clock/abort teardown must kill the whole process
        # GROUP, not just the direct child.  git upload-pack forks pack-objects
        # (the heavy step); killing only the parent orphans it to keep burning
        # CPU/RAM.  Prove the mechanism deterministically: a session-leading
        # shell whose grandchild `sleep`s must have BOTH reaped by
        # _kill_process_group (a bare parent kill would leave the sleeper).
        import time

        from gitweb import gitcmd

        if not os.path.isdir("/proc"):
            self.skipTest("requires /proc")
        proc = subprocess.Popen(
            ["/bin/sh", "-c", "sleep 60 & echo $! ; wait"],
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            start_new_session=True,
        )
        try:
            grandchild = int(proc.stdout.readline().strip())
            self.assertTrue(_pid_running(grandchild), "sleeper did not start")
            self.assertEqual(os.getpgid(proc.pid), proc.pid)  # own group leader
            gitcmd._kill_process_group(proc)
            proc.wait(timeout=5)
            deadline = time.monotonic() + 5
            while _pid_running(grandchild) and time.monotonic() < deadline:
                time.sleep(0.05)
            self.assertFalse(
                _pid_running(grandchild),
                "group-kill left the detached grandchild running (orphan bug)",
            )
        finally:
            if proc.stdout is not None:
                proc.stdout.close()
            if proc.poll() is None:  # pragma: no cover - defensive cleanup
                proc.kill()
                proc.wait()

    def test_git_children_spawn_in_own_session(self):
        # Every git Popen must set start_new_session=True so teardown can
        # group-kill the whole subtree (no orphaned pack-objects/archive/blob/
        # cat-file child).  Spy on Popen across all spawn sites.
        from gitweb import gitcmd

        # Capture the sha and resolve the repo BEFORE patching Popen: the spy
        # replaces the shared subprocess.Popen, so any subprocess.run() (e.g. in
        # _capture) made while it is active would also be recorded.
        sha = _capture(["git", "rev-parse", "main"], self.repo, self.env)
        repo = gitcmd.resolve_repo(self.root, "myrepo")

        calls = []
        real_popen = gitcmd.subprocess.Popen

        def spy(*a, **kw):
            calls.append(kw.get("start_new_session"))
            return real_popen(*a, **kw)

        gitcmd.subprocess.Popen = spy
        try:
            gitcmd.default_branch(repo)                          # run_git
            gitcmd.upload_pack_advertise(repo)                   # advertise
            list(gitcmd.stream_archive(repo, "main", "p/"))      # archive
            list(gitcmd.stream_blob(repo, "main", "src/main.py"))  # blob stream
            body = (
                _pkt(b"want " + sha.encode() + b" ofs-delta\n")
                + b"0000" + _pkt(b"done\n")
            )
            gen = gitcmd.upload_pack_rpc(repo, body, timeout=30)  # upload-pack RPC
            try:
                next(gen)  # force the spawn
            except StopIteration:  # pragma: no cover - tiny-repo edge
                pass
            finally:
                gen.close()
        finally:
            gitcmd.subprocess.Popen = real_popen

        self.assertTrue(calls, "no git child was spawned")
        self.assertTrue(
            all(v is True for v in calls),
            f"a git child was spawned without start_new_session=True: {calls}",
        )

    def test_upload_pack_rpc_no_orphan_on_client_abort(self):
        # A real upload-pack RPC runs in its own session; aborting the client
        # (closing the response generator) tears the whole group down so nothing
        # from the spawn -- upload-pack or a pack-objects child -- survives.
        import time

        from gitweb import gitcmd

        if not os.path.isdir("/proc"):
            self.skipTest("requires /proc")
        # Resolve repo + sha before patching the shared Popen (see the sibling
        # session test) so only upload_pack_rpc's own spawn is captured.
        repo = gitcmd.resolve_repo(self.root, "myrepo")
        sha = _capture(["git", "rev-parse", "main"], self.repo, self.env)

        created = []
        real_popen = gitcmd.subprocess.Popen

        def spy(*a, **kw):
            p = real_popen(*a, **kw)
            created.append((p, kw))
            return p

        gitcmd.subprocess.Popen = spy
        try:
            body = (
                _pkt(b"want " + sha.encode()
                     + b" multi_ack_detailed side-band-64k ofs-delta agent=git/x\n")
                + b"0000" + _pkt(b"done\n")
            )
            gen = gitcmd.upload_pack_rpc(repo, body, timeout=30)
            try:
                next(gen)  # force spawn + streaming to begin
            except StopIteration:  # pragma: no cover - tiny-repo edge
                pass
            self.assertTrue(created, "upload-pack was not spawned")
            proc, kw = created[-1]
            self.assertTrue(kw.get("start_new_session"), "not its own session")
            pid = proc.pid
            kids = _child_pids(pid)  # e.g. pack-objects (may be empty on a tiny repo)
            gen.close()  # simulate client abort -> group teardown
            for target in [pid, *kids]:
                deadline = time.monotonic() + 5
                while _pid_running(target) and time.monotonic() < deadline:
                    time.sleep(0.05)
                self.assertFalse(
                    _pid_running(target),
                    f"pid {target} survived the clone abort (orphaned git)",
                )
        finally:
            gitcmd.subprocess.Popen = real_popen

    def test_clone_slot_acquired_before_body_read(self):
        # MEDIUM fix (memory DoS): the clone slot is taken BEFORE the request
        # body is read into RAM.  Proof: with all clone slots exhausted, even a
        # wildly over-cap body is shed with 503 (slot checked first) rather than
        # 400 (body read first) -- so peak buffered body scales with
        # clone_max_concurrency, not the whole worker pool.
        httpd = make_server(
            Config(
                root=self.root, host="127.0.0.1", port=0, verbose=False,
                clone_max_concurrency=1, clone_max_body_bytes=16,
            )
        )
        port = httpd.server_address[1]
        thread = threading.Thread(target=httpd.serve_forever, daemon=True)
        thread.start()
        base = f"http://127.0.0.1:{port}"
        try:
            self.assertTrue(httpd.clone_slots.acquire(blocking=False))
            try:
                status, headers, _b = self.post(
                    "/myrepo/git-upload-pack", b"X" * 4096,  # far over the 16 B cap
                    {"Content-Type": "application/x-git-upload-pack-request"},
                    base=base,
                )
                self.assertEqual(status, 503)  # slot first; body never read -> not 400
                self.assertIn("Retry-After", headers)
            finally:
                httpd.clone_slots.release()
        finally:
            httpd.shutdown()
            httpd.server_close()
            thread.join(timeout=5)


    # -- code + commit-message search ----------------------------------- #

    def test_grep_and_format_patch_in_allowlist(self):
        # Defence in depth: the new read-only subcommands are on the allow-list.
        from gitweb import gitcmd

        self.assertIn("grep", gitcmd.ALLOWED_SUBCOMMANDS)
        self.assertIn("format-patch", gitcmd.ALLOWED_SUBCOMMANDS)

    def test_search_query_validation_unit(self):
        from gitweb import gitcmd

        self.assertFalse(gitcmd.valid_query(""))  # empty
        self.assertFalse(gitcmd.valid_query("x" * (gitcmd.MAX_QUERY_BYTES + 1)))  # too long
        self.assertFalse(gitcmd.valid_query("has\x00nul"))  # NUL forbidden (argv)
        self.assertTrue(gitcmd.valid_query("normal term"))
        self.assertTrue(gitcmd.valid_query("--looks-like-an-option"))  # still just text

    def test_code_search_finds_string_with_file_line_links(self):
        status, _h, body = self.get_text(
            "/featrepo/search", {"q": "UNIQUE_NEEDLE_TOKEN", "type": "code"}
        )
        self.assertEqual(status, 200)
        # The file is named and linked; the specific matching line is anchored.
        self.assertIn("search/needle.txt", body)
        self.assertIn("blob?ref=", body)
        self.assertIn('#L1"', body)  # line-1 anchor link
        self.assertIn("UNIQUE_NEEDLE_TOKEN", body)

    def test_code_search_output_is_escaped(self):
        # A matched line containing markup is rendered escaped, never live.
        status, _h, body = self.get_text(
            "/featrepo/search", {"q": "danger", "type": "code"}
        )
        self.assertEqual(status, 200)
        self.assertIn("&lt;script&gt;", body)
        self.assertNotIn("<script>alert(1)</script>", body)

    def test_code_search_refuses_option_injection(self):
        # A term that *looks like* an option is passed as the operand of `-e`, so
        # git treats it as a literal: the search succeeds and returns the literal
        # line (proving it was data, not a parsed option) and never 500s.
        status, _h, body = self.get_text(
            "/featrepo/search", {"q": "--option-like-needle", "type": "code"}
        )
        self.assertEqual(status, 200)
        self.assertIn("--option-like-needle value", body)  # the literal match line

    def test_code_search_option_injection_has_no_side_effect(self):
        # A classic option-injection attempt (`--output=<path>`) must be treated
        # as a literal search term: no file is written and the request succeeds.
        sentinel = os.path.join(self.tmp, "search_pwned_marker")
        self.assertFalse(os.path.exists(sentinel))
        status, _h, body = self.get_text(
            "/featrepo/search", {"q": f"--output={sentinel}", "type": "code"}
        )
        self.assertEqual(status, 200)
        self.assertFalse(os.path.exists(sentinel), "grep treated --output as an option")
        self.assertIn("No code matches", body)  # literal term matches nothing

    def test_code_search_unit_via_gitcmd(self):
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "featrepo")
        matches, truncated = gitcmd.search_code(repo, "main", "UNIQUE_NEEDLE_TOKEN")
        self.assertFalse(truncated)
        self.assertTrue(any(m.path == "search/needle.txt" and m.lineno == 1 for m in matches))
        # A path that contains a colon parses unambiguously (regression guard).
        # (featrepo has none, but the leading "<ref>:" strip is exercised here.)
        self.assertTrue(all(not m.path.startswith("main:") for m in matches))
        # No matches -> empty, not an error.
        self.assertEqual(gitcmd.search_code(repo, "main", "zzz_no_such_zzz"), ([], False))

    def test_log_search_finds_commit_by_message(self):
        status, _h, body = self.get_text(
            "/featrepo/search", {"q": "SEARCHKEYWORD", "type": "log"}
        )
        self.assertEqual(status, 200)
        self.assertIn("Add needle file mentioning SEARCHKEYWORD", body)
        # Message search that matches nothing is a clean empty result.
        s2, _h2, b2 = self.get_text(
            "/featrepo/search", {"q": "no_such_message_zzz", "type": "log"}
        )
        self.assertEqual(s2, 200)
        self.assertIn("No commit messages match", b2)

    def test_search_empty_query_renders_form(self):
        status, _h, body = self.get_text("/featrepo/search")
        self.assertEqual(status, 200)
        self.assertIn("<form", body)
        self.assertIn('name="q"', body)
        self.assertIn("Enter a term", body)

    def test_search_invalid_query_reported(self):
        # A NUL in the query is rejected (cannot appear in an argv element) and
        # reported to the user instead of 500ing.
        status, _h, body = self.get_text(
            "/featrepo/search", {"q": "bad\x00nul", "type": "code"}
        )
        self.assertEqual(status, 200)
        self.assertIn("invalid character", body)

    # -- commit graph ---------------------------------------------------- #

    def test_graph_renders_valid_svg_for_repo_with_merge(self):
        status, _h, body = self.get_text("/featrepo/graph", {"ref": "main"})
        self.assertEqual(status, 200)
        self.assertIn("<svg", body)
        # The embedded SVG must be well-formed XML on its own.
        m = re.search(r"<svg.*?</svg>", body, re.S)
        self.assertIsNotNone(m)
        doc = xml.dom.minidom.parseString(m.group(0))
        self.assertEqual(doc.documentElement.tagName, "svg")
        # One node per commit (>=4) and, because of the merge, >=1 lane edge.
        self.assertGreaterEqual(len(doc.getElementsByTagName("circle")), 4)
        self.assertGreaterEqual(len(doc.getElementsByTagName("path")), 1)
        # Commit metadata renders beside the graph.
        self.assertIn("Merge topic into main", body)

    def test_assign_lanes_merge_topology_unit(self):
        from gitweb import gitcmd, views

        repo = gitcmd.resolve_repo(self.root, "featrepo")
        rows = gitcmd.log_graph(repo, "main")
        nodes, max_cols = views._assign_lanes(rows)
        self.assertEqual(len(nodes), len(rows))  # one node per commit
        # The merge is newest (row 0) and has two parents on two distinct lanes.
        merge = nodes[0]
        self.assertEqual(len(merge["parents"]), 2)
        self.assertNotEqual(merge["parents"][0][1], merge["parents"][1][1])
        self.assertGreaterEqual(max_cols, 2)  # a second lane was opened
        self.assertTrue(all(nd["col"] >= 0 for nd in nodes))

    def test_graph_empty_repo(self):
        status, _h, body = self.get_text("/emptyrepo/graph")
        self.assertEqual(status, 200)
        self.assertIn("No commits", body)

    # -- commit.patch / patch (git format-patch) ------------------------- #

    def test_commit_patch_is_mailbox_and_git_am_applies(self):
        status, headers, body = self.get(
            "/featrepo/commit.patch", {"id": self.needle_sha}
        )
        self.assertEqual(status, 200)
        self.assertTrue(headers.get("Content-Type", "").startswith("text/plain"))
        self.assertEqual(headers.get("X-Content-Type-Options"), "nosniff")
        self.assertIn("attachment", headers.get("Content-Disposition", ""))
        self.assertIn(".patch", headers.get("Content-Disposition", ""))
        # Valid mbox: opens with the "From <sha> Mon Sep 17 ..." header.
        self.assertTrue(body.startswith(b"From "), body[:32])
        # It applies with `git am` in a fresh repo, reproducing the file.
        dst = tempfile.mkdtemp(prefix="gitweb-am-")
        try:
            _run(["git", "init", "-b", "main"], dst, self.env)
            patch_file = os.path.join(dst, "0001.patch")
            with open(patch_file, "wb") as fh:
                fh.write(body)
            res = subprocess.run(
                ["git", "am", patch_file], cwd=dst, env=self.env,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            )
            self.assertEqual(res.returncode, 0, res.stderr)
            self.assertTrue(
                os.path.isfile(os.path.join(dst, "search", "needle.txt"))
            )
        finally:
            shutil.rmtree(dst, ignore_errors=True)

    def test_patch_alias_and_invalid_id(self):
        # /patch?id= is an alias of /commit.patch?id=.
        s1, _h1, b1 = self.get("/featrepo/patch", {"id": self.needle_sha})
        self.assertEqual(s1, 200)
        self.assertTrue(b1.startswith(b"From "))
        # An option-like id is refused (400) before any git runs.
        s2, _h2, _b2 = self.get(
            "/featrepo/commit.patch", {"id": "--output=/tmp/x"}
        )
        self.assertEqual(s2, 400)

    def test_patch_conditional_get_full_sha(self):
        _s, headers, _b = self.get(
            "/featrepo/commit.patch", {"id": self.needle_sha}
        )
        etag = headers.get("ETag")
        self.assertTrue(etag and etag.startswith('"'))
        s2, _h2, b2 = self.get_h(
            "/featrepo/commit.patch",
            {"id": self.needle_sha},
            headers={"If-None-Match": etag, "Accept-Encoding": "gzip"},
        )
        self.assertEqual(s2, 304)
        self.assertEqual(b2, b"")

    def test_commit_page_links_to_patch(self):
        status, _h, body = self.get_text(
            "/featrepo/commit", {"id": self.needle_sha}
        )
        self.assertEqual(status, 200)
        self.assertIn("commit.patch", body)

    # -- OpenSearch descriptors ----------------------------------------- #

    def test_opensearch_repo_descriptor_wellformed(self):
        status, headers, body = self.get("/featrepo/opensearch.xml")
        self.assertEqual(status, 200)
        self.assertEqual(
            headers.get("Content-Type"), "application/opensearchdescription+xml"
        )
        doc = xml.dom.minidom.parseString(body)  # raises if not well-formed
        self.assertEqual(doc.documentElement.tagName, "OpenSearchDescription")
        template = doc.getElementsByTagName("Url")[0].getAttribute("template")
        self.assertIn("/featrepo/search", template)
        self.assertIn("{searchTerms}", template)  # the token survives verbatim
        self.assertIn("type=code", template)

    def test_opensearch_site_descriptor_wellformed(self):
        status, headers, body = self.get("/opensearch.xml")
        self.assertEqual(status, 200)
        self.assertEqual(
            headers.get("Content-Type"), "application/opensearchdescription+xml"
        )
        doc = xml.dom.minidom.parseString(body)
        self.assertEqual(doc.documentElement.tagName, "OpenSearchDescription")
        self.assertIn(
            "{searchTerms}",
            doc.getElementsByTagName("Url")[0].getAttribute("template"),
        )

    def test_opensearch_respects_url_prefix(self):
        # Under a reverse-proxy sub-path the template carries the prefix so the
        # search engine the browser stores points at the right place.
        base, stop = self._temp_server(url_prefix="/git")
        try:
            s, _h, b = self.get_h("/git/featrepo/opensearch.xml", base=base)
            self.assertEqual(s, 200)
            doc = xml.dom.minidom.parseString(b)
            template = doc.getElementsByTagName("Url")[0].getAttribute("template")
            self.assertIn("/git/featrepo/search", template)
        finally:
            stop()

    def test_opensearch_autodiscovery_link_present(self):
        # Repo pages advertise both the per-repo and site descriptors.
        _s, _h, body = self.get_text("/featrepo/")
        self.assertIn(
            'type="application/opensearchdescription+xml"', body
        )
        self.assertIn("/featrepo/opensearch.xml", body)
        self.assertIn("/opensearch.xml", body)

    # -- home-page repository filter (site search target) --------------- #

    def test_home_filter_narrows_repo_list(self):
        # The filter form is present, filters by name, and reports no matches.
        _s, _h, body = self.get_text("/")
        self.assertIn('name="q"', body)
        s1, _h1, b1 = self.get_text("/", {"q": "featrepo"})
        self.assertEqual(s1, 200)
        self.assertIn("featrepo", b1)
        self.assertNotIn(">myrepo<", b1)  # a non-matching repo is filtered out
        s2, _h2, b2 = self.get_text("/", {"q": "zzz_no_such_repo"})
        self.assertEqual(s2, 200)
        self.assertIn("No repositories match", b2)

    # -- CSP: form submission allowed to self only ---------------------- #

    def test_csp_allows_self_form_action(self):
        _s, headers, _b = self.get_text("/featrepo/search")
        csp = headers.get("Content-Security-Policy", "")
        self.assertIn("form-action 'self'", csp)
        self.assertNotIn("form-action 'none'", csp)

    def test_search_and_patch_git_argv_terminates_options(self):
        # Every new git invocation terminates its options with `--` and passes
        # the search term only as the operand of `-e` / `--grep=` (never bare),
        # so a validated ref/term can never be read as an option flag.  Capture
        # the argv each helper hands to run_git (without spawning git).
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "featrepo")
        captured = []
        real = gitcmd.run_git

        def fake_run_git(repo_path, args, **kw):
            captured.append(list(args))
            return (1, b"", b"")  # rc=1: grep/log "no match", format-patch empty

        gitcmd.run_git = fake_run_git
        try:
            gitcmd.search_code(repo, "main", "-n")  # a term that looks like -n
            gitcmd.log_grep(repo, "main", "-n")
            try:
                gitcmd.format_patch(repo, "main")
            except gitcmd.NotFound:
                pass  # empty fake output -> NotFound; the argv was still captured
        finally:
            gitcmd.run_git = real

        grep_cmd = next(c for c in captured if c[0] == "grep")
        # The term is the operand of -e (never a standalone token); `--` ends the
        # options with the ref before it; matching is literal (--fixed-strings).
        self.assertIn("-e", grep_cmd)
        self.assertEqual(grep_cmd[grep_cmd.index("-e") + 1], "-n")  # term is data
        self.assertEqual(grep_cmd[grep_cmd.index("-e") + 2], "main")  # then the ref
        self.assertEqual(grep_cmd[-1], "--")
        self.assertIn("--fixed-strings", grep_cmd)

        log_cmd = next(c for c in captured if c[0] == "log")
        self.assertIn("--grep=-n", log_cmd)  # term bound to --grep=, single argv
        self.assertIn("--fixed-strings", log_cmd)
        self.assertEqual(log_cmd[-1], "--")

        fp_cmd = next(c for c in captured if c[0] == "format-patch")
        self.assertEqual(fp_cmd[-1], "--")

    # -- Git LFS: serving objects from local storage -------------------- #

    def test_lfs_object_served_from_local_storage(self):
        # /raw serves the REAL object bytes (not the pointer text), with the
        # object's own size and nosniff.
        status, headers, body = self.get(
            "/lfsrepo/raw", {"ref": "main", "path": "asset.dat"}
        )
        self.assertEqual(status, 200)
        self.assertEqual(body, self.LFS_BYTES)
        self.assertEqual(headers.get("Content-Length"), str(len(self.LFS_BYTES)))
        self.assertEqual(headers.get("X-Content-Type-Options"), "nosniff")
        self.assertNotIn(b"git-lfs.github.com", body)  # not the pointer text
        # The blob view notes the content is served from local LFS storage.
        _s, _h, view = self.get_text(
            "/lfsrepo/blob", {"ref": "main", "path": "asset.dat"}
        )
        self.assertIn("served from local", view)
        self.assertNotIn("only the pointer is shown", view)

    def test_lfs_text_object_rendered_as_source(self):
        status, _h, body = self.get_text(
            "/lfsrepo/blob", {"ref": "main", "path": "notes.txt"}
        )
        self.assertEqual(status, 200)
        self.assertIn("second line of real lfs text", body)  # the real content
        self.assertIn('class="line"', body)  # rendered as numbered source
        self.assertIn("served from local", body)

    def test_lfs_pointer_without_local_object_shows_pointer(self):
        # myrepo's assets/big.lfs points at an oid NOT in local storage: the
        # pointer (with a note) is shown, and /raw serves the pointer text.
        status, _h, body = self.get_text(
            "/myrepo/blob", {"ref": "main", "path": "assets/big.lfs"}
        )
        self.assertEqual(status, 200)
        self.assertIn("Git LFS pointer", body)
        self.assertIn("only the pointer is shown", body)
        _s, _h2, raw = self.get(
            "/myrepo/raw", {"ref": "main", "path": "assets/big.lfs"}
        )
        self.assertIn(b"git-lfs.github.com", raw)

    def test_lfs_oid_validation_and_path_confinement(self):
        from gitweb import gitcmd

        repo = gitcmd.resolve_repo(self.root, "lfsrepo")
        # A valid, present oid resolves to a file confined under the repo.
        p = gitcmd.lfs_object_path(repo, self.LFS_OID)
        self.assertIsNotNone(p)
        self.assertTrue(
            os.path.realpath(p).startswith(os.path.realpath(repo.path) + os.sep)
        )
        # Traversal / non-hex / wrong-length oids never build a path.
        self.assertIsNone(gitcmd.lfs_object_path(repo, "../../../../etc/passwd"))
        self.assertIsNone(gitcmd.lfs_object_path(repo, "z" * 64))
        self.assertIsNone(gitcmd.lfs_object_path(repo, self.LFS_OID[:-1]))
        # A well-formed but absent oid -> None (not stored locally).
        self.assertIsNone(gitcmd.lfs_object_path(repo, "0" * 64))
        # A pointer whose oid is not hex is not even recognised as a pointer.
        self.assertIsNone(
            gitcmd.parse_lfs_pointer(
                b"version https://git-lfs.github.com/spec/v1\n"
                b"oid sha256:not-hex\nsize 5\n"
            )
        )

    # -- fuller Markdown (closer to CommonMark) ------------------------- #

    def test_markdown_reference_links_and_safety(self):
        from gitweb import markup

        html = markup.render_markdown(
            "See [good][a] and [evil][b] and [x][c].\n\n"
            "[a]: https://example.com\n"
            "[b]: javascript:alert(1)\n"
        )
        self.assertIn('href="https://example.com"', html)  # resolved reference
        self.assertNotIn("javascript:", html)  # unsafe scheme neutralised
        self.assertIn("[evil][b]", html)  # kept literal (unsafe url)
        self.assertIn("[x][c]", html)  # kept literal (undefined reference)
        # The definition lines themselves are consumed, not rendered.
        self.assertNotIn("[a]:", html)

    def test_markdown_nested_list(self):
        from gitweb import markup

        html = markup.render_markdown("- a\n  - b\n  - c\n- d\n")
        self.assertIn(
            "<ul><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ul>", html
        )

    def test_markdown_setext_autolink_hardbreak(self):
        from gitweb import markup

        self.assertIn("<h1>Title</h1>", markup.render_markdown("Title\n===\n"))
        self.assertIn("<h2>Sub</h2>", markup.render_markdown("Sub\n---\n"))
        # Angle autolink <https://…> (the brackets are escaped before inline).
        self.assertIn(
            'href="https://ang.example.com"',
            markup.render_markdown("<https://ang.example.com>\n"),
        )
        # Two trailing spaces => hard line break.
        self.assertIn("a<br>b", markup.render_markdown("a  \nb\n"))
        # A soft break (no trailing spaces) is just a space, not <br>.
        self.assertNotIn("<br>", markup.render_markdown("a\nb\n"))

    def test_markdown_nested_blockquote(self):
        from gitweb import markup

        html = markup.render_markdown("> outer\n>\n> > inner\n")
        self.assertGreaterEqual(html.count("<blockquote>"), 2)
        self.assertIn("inner", html)

    def test_markdown_setext_injection_safe(self):
        from gitweb import markup

        # A setext heading title is still escaped (no raw HTML passthrough).
        html = markup.render_markdown("<script>evil</script>\n===\n")
        self.assertIn("<h1>", html)
        self.assertIn("&lt;script&gt;", html)
        self.assertNotIn("<script>evil", html)

    # -- optional HTTP Basic auth --------------------------------------- #

    def test_auth_password_hash_roundtrip_unit(self):
        from gitweb import auth

        h = auth.hash_password("hunter2", salt="deadbeef")
        self.assertTrue(h.startswith("sha256$deadbeef$"))
        self.assertTrue(auth.verify_password(h, "hunter2"))
        self.assertFalse(auth.verify_password(h, "wrong"))
        self.assertFalse(auth.verify_password("garbage", "hunter2"))
        cred = auth.parse_auth_spec(f"bob:{h}")
        self.assertEqual(cred.user, "bob")
        self.assertIsNone(auth.parse_auth_spec(""))  # empty => disabled
        self.assertRaises(ValueError, auth.parse_auth_spec, "nocolon")
        self.assertRaises(ValueError, auth.parse_auth_spec, "bob:plaintext")

    def test_auth_default_off_unaffected(self):
        # The primary server has no auth configured: browsing needs no creds.
        s, _h, _b = self.get("/myrepo/")
        self.assertEqual(s, 200)
        self.assertIsNone(getattr(self.httpd, "auth_cred", "missing"))

    def test_auth_required_for_browse_and_clone(self):
        from gitweb import auth

        spec = f"alice:{auth.hash_password('s3cret', salt='abcd1234')}"
        base, stop = self._temp_server(auth=spec)
        try:
            # No credentials -> 401 with a Basic challenge.
            s0, h0, _b0 = self.get_h("/", base=base)
            self.assertEqual(s0, 401)
            self.assertIn("Basic", h0.get("WWW-Authenticate", ""))
            # Wrong password -> 401.
            bad = "Basic " + base64.b64encode(b"alice:nope").decode()
            s1, _h1, _b1 = self.get_h(
                "/", headers={"Authorization": bad}, base=base
            )
            self.assertEqual(s1, 401)
            # Correct credentials -> 200 (browse works).
            good = "Basic " + base64.b64encode(b"alice:s3cret").decode()
            s2, _h2, _b2 = self.get_h(
                "/myrepo/", headers={"Authorization": good}, base=base
            )
            self.assertEqual(s2, 200)
            # Clone is gated too: unauthenticated fails, authenticated succeeds.
            dst = os.path.join(tempfile.mkdtemp(prefix="gw-noauth-"), "r")
            self.assertNotEqual(self._git_clone(f"{base}/myrepo", dst).returncode, 0)
            port = base.rsplit(":", 1)[1]
            dst2 = os.path.join(tempfile.mkdtemp(prefix="gw-auth-"), "r")
            res = self._git_clone(
                f"http://alice:s3cret@127.0.0.1:{port}/myrepo", dst2
            )
            self.assertEqual(res.returncode, 0, res.stderr)
        finally:
            stop()

    def test_auth_hash_password_cli(self):
        import contextlib
        from unittest import mock

        from gitweb import auth
        from gitweb.__main__ import main

        with mock.patch("getpass.getpass", side_effect=["pw123", "pw123"]):
            buf = io.StringIO()
            with contextlib.redirect_stdout(buf):
                rc = main(["--hash-password", "carol"])
        self.assertEqual(rc, 0)
        line = buf.getvalue().strip()
        self.assertTrue(line.startswith("carol:sha256$"))
        _user, stored = line.split(":", 1)
        self.assertTrue(auth.verify_password(stored, "pw123"))

    # ------------------------------------------------------------------ #
    # Regression tests for the four hardening fixes (F1..F4)
    # ------------------------------------------------------------------ #

    def test_f1_markdown_redos_is_linear_and_bounded(self):
        """A long run of '[' (and other former-quadratic vectors) renders fast.

        Before the fix ``render_markdown('[' * 40000)`` took ~12 s (O(n^2)
        backtracking in the link/ref/heading/autolink patterns).  It is now O(n);
        we assert a generous ceiling that still catches any quadratic regression.
        """
        import time

        from gitweb import markup

        def elapsed(src):
            t0 = time.monotonic()
            markup.render_markdown(src)
            return time.monotonic() - t0

        # PoC input from the review: 40 000 unmatched '[' (was ~12 s).
        self.assertLess(elapsed("[" * 40000), 1.0)
        # A larger run stays bounded too (was minutes at O(n^2)).
        self.assertLess(elapsed("[" * 200000), 2.0)
        # Other former-quadratic constructs: ATX closing-hash strip; angle soup.
        self.assertLess(elapsed("# " + "#" * 100000 + " x"), 1.0)
        self.assertLess(elapsed("<http://a" * 20000), 1.0)
        # Even the worst case that defeats the ']' fast-path (a trailing ']')
        # stays bounded by the length cap + bounded spans.
        self.assertLess(elapsed("[" * (256 * 1024 - 1) + "]"), 5.0)

    def test_f1_oversized_markdown_falls_back_to_pre(self):
        """Above the size cap, rendering is a single escaped <pre> (no parsing)."""
        from gitweb import markup

        big = "[" * (512 * 1024)
        out = markup.render_markdown(big)
        self.assertTrue(out.startswith("<pre>"))
        self.assertTrue(out.endswith("</pre>"))
        self.assertNotIn("\x00", out)
        # Normal-sized markdown is unaffected (still parsed to structure).
        self.assertIn("<h1>Hi</h1>", markup.render_markdown("# Hi"))
        self.assertIn("<h1>Hi</h1>", markup.render_markdown("# Hi ##"))  # closing #

    def test_f2_nested_placeholder_no_nul_leak(self):
        """Nested inline constructs no longer leak the \\x00 placeholder sentinel."""
        from gitweb import markup

        # Image inside a link: the image must render *inside* the anchor, no NUL.
        out = markup.render_markdown("[![logo](/logo.png)](/home)")
        self.assertNotIn("\x00", out)
        self.assertIn('<a href="/home"', out)
        self.assertIn('<img src="/logo.png" alt="logo">', out)
        # Code span inside an image's alt: expanded, no NUL.
        out2 = markup.render_markdown("![a`b`c](/x.png)")
        self.assertNotIn("\x00", out2)

    def test_f2_nested_placeholder_no_attribute_breakout(self):
        """A placeholder captured as a URL must never expand inside an attribute.

        ``[label](![alt](/i.png))`` used to leave a NUL in ``href``; naively
        expanding it would inject ``<img src="…">`` (with literal quotes) into the
        ``href`` attribute — an XSS breakout.  The construct must instead fall
        back to literal text with the element rendered in *content* position.
        """
        from gitweb import markup

        for src in (
            "[label](![alt](/i.png))",
            "[a](`code`)",
            "![outer](`code`)",
            "[![i](/p)](`c`)",
        ):
            out = markup.render_markdown(src)
            self.assertNotIn("\x00", out)
            # No '<' may appear inside a quoted href/src value (== no breakout).
            self.assertIsNone(
                re.search(r'(?:href|src)="[^"]*<', out),
                f"attribute breakout for {src!r}: {out}",
            )

    def test_f3_non_ascii_basic_auth_denied_not_500(self):
        """A non-ASCII Basic username is denied (401), never a 500 (TypeError)."""
        from gitweb import auth

        spec = f"admin:{auth.hash_password('pw', salt='abcd')}"
        cred = auth.parse_auth_spec(spec)
        # Unit: constant-time compare on UTF-8 bytes returns False, never raises.
        hdr = "Basic " + base64.b64encode("ü:pw".encode()).decode()
        self.assertFalse(auth.check_basic_auth(hdr, cred))
        # End-to-end: the request is a clean 401, not a 500.
        base, stop = self._temp_server(auth=spec)
        try:
            status, _h, _b = self.get_h("/", headers={"Authorization": hdr}, base=base)
            self.assertEqual(status, 401)
            # A correct credential still authenticates.
            good = "Basic " + base64.b64encode(b"admin:pw").decode()
            s2, _h2, _b2 = self.get_h(
                "/myrepo/", headers={"Authorization": good}, base=base
            )
            self.assertEqual(s2, 200)
        finally:
            stop()

    def test_f4_parse_auth_spec_rejects_empty_salt_or_digest(self):
        """A present-but-empty salt/hash fails loudly instead of locking everyone out."""
        from gitweb import auth

        for spec in ("u:sha256$salt$", "u:sha256$$hash", "u:sha256$$"):
            with self.assertRaises(ValueError):
                auth.parse_auth_spec(spec)
        # Unchanged: empty spec disables auth, valid spec parses.
        self.assertIsNone(auth.parse_auth_spec(""))
        self.assertIsNotNone(
            auth.parse_auth_spec(f"u:{auth.hash_password('x', salt='s')}")
        )

    def test_f4_auth_file_without_credential_refuses_start(self):
        """--auth-file with no usable spec must SystemExit, not silently disable auth."""
        empty = os.path.join(self.tmp, "empty_auth.txt")
        with open(empty, "w", encoding="utf-8") as fh:
            fh.write("# only a comment\n\n")
        with self.assertRaises(SystemExit):
            make_server(
                Config(root=self.root, host="127.0.0.1", port=0,
                       verbose=False, auth_file=empty)
            )
        # A malformed --auth also aborts startup (clean SystemExit, not traceback).
        with self.assertRaises(SystemExit):
            make_server(
                Config(root=self.root, host="127.0.0.1", port=0,
                       verbose=False, auth="bob:plaintext")
            )


if __name__ == "__main__":
    unittest.main(verbosity=2)
