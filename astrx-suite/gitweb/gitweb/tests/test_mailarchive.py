"""Read-only email/patch archive: parse an mbox, thread it, render, mbox download."""
import mailbox
import os
import tempfile
import unittest
from email.message import EmailMessage

from gitweb import mailarchive

PATCH_BODY = ("Here is a fix.\n\n"
              "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-old\n+new\n")


def _make_mbox(path, msgs):
    box = mailbox.mbox(path)
    for subj, frm, body, mid, irt in msgs:
        m = EmailMessage()
        m["Subject"] = subj
        m["From"] = frm
        m["Message-ID"] = mid
        if irt:
            m["In-Reply-To"] = irt
        m["Date"] = "Mon, 01 Jan 2020 00:00:00 +0000"
        m.set_content(body)
        box.add(m)
    box.flush()
    box.close()


def _u(action, **params):
    qs = "&".join("%s=%s" % (k, v) for k, v in params.items())
    return "/repo/%s%s" % (action, ("?" + qs) if qs else "")


class TestMailArchive(unittest.TestCase):
    def setUp(self):
        self.d = tempfile.mkdtemp()
        self.p = os.path.join(self.d, "repo.mbox")
        _make_mbox(self.p, [
            ("[PATCH] fix the bug", "Alice <a@b.c>", PATCH_BODY, "<1@x>", ""),
            ("Re: [PATCH] fix the bug", "Bob <b@b.c>", "Looks good to me.",
             "<2@x>", "<1@x>"),
            ("Question about build", "Carol <c@b.c>", "How do I build?",
             "<3@x>", ""),
        ])

    def test_read_and_group(self):
        msgs = mailarchive.read_archive(self.p)
        self.assertEqual(len(msgs), 3)
        threads = mailarchive.group_threads(msgs)
        self.assertEqual(len(threads), 2)         # patch (2 msgs) + question (1)
        patch = next(t for t in threads
                     if "fix the bug" in t["subject"].lower())
        self.assertEqual(len(patch["msgs"]), 2)   # Re: folded into the thread
        self.assertTrue(patch["msgs"][0].is_patch)

    def test_render_list_and_thread(self):
        threads = mailarchive.group_threads(mailarchive.read_archive(self.p))
        lst = mailarchive.render_list("repo", threads, _u, configured=True)
        self.assertIn("fix the bug", lst)
        self.assertIn("git send-email", lst)       # contribute help
        patch = next(t for t in threads if t["msgs"][0].is_patch)
        thr = mailarchive.render_thread("repo", patch, _u)
        self.assertIn("diff --git", thr)
        self.assertIn('class="add"', thr)          # +new line coloured
        self.assertIn("download mbox", thr)

    def test_thread_mbox_download(self):
        threads = mailarchive.group_threads(mailarchive.read_archive(self.p))
        patch = next(t for t in threads if t["msgs"][0].is_patch)
        mb = mailarchive.thread_mbox(patch)
        self.assertTrue(mb.startswith(b"From "))
        self.assertIn(b"diff --git", mb)

    def test_unconfigured_empty_state(self):
        body = mailarchive.render_list("repo", [], _u, configured=False)
        self.assertIn("No patch archive is configured", body)

    def test_missing_file_is_empty(self):
        self.assertEqual(mailarchive.read_archive("/nonexistent/x.mbox"), [])

    def test_escaping(self):
        p2 = os.path.join(self.d, "h.mbox")
        _make_mbox(p2, [("<script>evil</script>", "x@y",
                         "body <script>bad</script> here", "<9@x>", "")])
        threads = mailarchive.group_threads(mailarchive.read_archive(p2))
        lst = mailarchive.render_list("repo", threads, _u, configured=True)
        thr = mailarchive.render_thread("repo", threads[0], _u)
        self.assertNotIn("<script>evil", lst)
        self.assertNotIn("<script>bad", thr)


if __name__ == "__main__":
    unittest.main()
