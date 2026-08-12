//! Cross-check: `gitweb::mailarchive` is byte-identical to the Python
//! `gitweb.mailarchive` on a real mbox written by the stdlib `mailbox.mbox`.
//!
//! The fixture exercises the parser end to end: a `git send-email`-style patch,
//! a threaded `Re:` reply (with the `>From ` body mangling mbox applies), RFC
//! 2047 encoded subjects in both `Q` and `B` form, quoted-printable (including
//! the `==`, soft-break and non-hex corner cases) and base64 transfer
//! encodings, a `multipart/alternative` message with a preamble and an epilogue,
//! a multipart whose *close* boundary comes first, a `multipart/*` subpart with
//! no boundary of its own, a `multipart/*` with no boundary and an 8-bit body
//! (whose `as_bytes()` raises in CPython, so `raw` is empty), an unknown
//! charset, a folded header, an absent and an unparseable `Date:`, several
//! timezone spellings, and hostile HTML in both the subject and the body.
//!
//! Goldens emitted by `tests/regen_goldens.py` (section `gen_mailarchive`).
//! Beyond this fixture the whole pipeline was validated against the reference
//! on ~4 500 randomly assembled mbox files.

use gitweb::mailarchive::{
    group_threads, normalize_subject, parse_mbox, read_archive, render_list, render_thread,
    thread_id, thread_mbox, Msg, Thread, MAX_BODY, MAX_MESSAGES, PATCH_CSS,
};

const MBOX: &[u8] = b"From gitweb@example Mon Jan  1 00:00:00 2020\nSubject: [PATCH] fix the bug\nFrom: Alice <a@b.c>\nMessage-ID: <1@x>\nDate: Wed, 01 Jan 2020 00:00:00 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nHere is a fix.\n\ndiff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-old\n+new\n\nFrom gitweb@example Mon Jan  1 00:00:00 2020\nSubject: Re: [PATCH] fix the bug\nFrom: Bob <b@b.c>\nMessage-ID: <2@x>\nIn-Reply-To: <1@x>\nDate: Thu, 02 Jan 2020 03:04:05 -0500\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nLooks good to me.\n>From the top, this is fine.\n\nFrom gitweb@example Mon Jan  1 00:00:00 2020\nSubject: Question about build\nFrom: Carol <c@b.c>\nMessage-ID: <3@x>\nDate: Fri, 03 Jan 2020 12:00:00 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nHow do I build?\n\nFrom gitweb@example Mon Jan  1 00:00:00 2020\nSubject: =?utf-8?q?caf=C3=A9_patch_s=C3=A9rie?=\nFrom: =?utf-8?q?D=C3=A9borah?= <d@b.c>\nMessage-ID: <4@x>\nDate: Sat, 04 Jan 2020 06:07:08 +0200\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: quoted-printable\nMIME-Version: 1.0\n\nQuoted printable caf=C3=A9 body with =C3=A9=C3=A8=C3=AA.\n\nFrom gitweb@example Mon Jan  1 00:00:00 2020\nSubject: =?utf-8?b?R3LDvMOfZSBhbmQg4pyT?= done\nFrom: Eve <e@b.c>\nMessage-ID: <5@x>\nDate: Sun, 05 Jan 2020 09:10:11 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: base64\nMIME-Version: 1.0\n\nQmFzZTY0IGJvZHkgd2l0aCDinJMgY2hlY2suCg==\n\nFrom gitweb@example Mon Jan  1 00:00:00 2020\nSubject: <script>evil</script> & \"quotes\"\nFrom: <x@y>\nMessage-ID: <6@x>\nDate:\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nbody <script>bad</script> here\n\nFrom gitweb@example Mon Jan  1 00:00:00 2020\nSubject: PATCH without brackets\nFrom: Frank <f@b.c>\nMessage-ID: <7@x>\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nno date header at all\n\nFrom nobody Mon Jan  1 00:00:00 2020\nSubject: Re: Question about build\nFrom: Grace <g@b.c>\nMessage-ID: <8@x>\nIn-Reply-To: <3@x>\nDate: Sat, 06 Jan 2020 01:02:03 +0100\nMIME-Version: 1.0\nContent-Type: multipart/alternative; boundary=\"BND1\"\n\nThis is the preamble.\n--BND1\nContent-Type: text/plain; charset=us-ascii\n\nthe plain alternative\n--BND1\nContent-Type: text/html; charset=us-ascii\n\n<b>the html alternative</b>\n--BND1--\nthe epilogue\n\nFrom nobody Mon Jan  1 00:00:00 2020\nSubject: a very long subject that the\n mailer folded across lines\nFrom: Heidi <h@b.c>\nX-Weird:    lots   of   space\nMessage-ID: <9@x>\nDate: 7 Jan 2020 08:09:10 -0000\n\n@@ looks like a hunk header\nIndex: something\n\nFrom nobody Mon Jan  1 00:00:00 2020\nSubject: qp corners\nFrom: Ivan <i@b.c>\nMessage-ID: <10@x>\nDate: Wed, 08 Jan 2020 10:11:12 +0000\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: quoted-printable\n\nQ2FmZQ==\nsoft=\njoined =3D done =Zb =g1 tail=\n\nFrom nobody Mon Jan  1 00:00:00 2020\nSubject: only a close boundary\nFrom: Judy <j@b.c>\nMessage-ID: <11@x>\nDate: Thu, 09 Jan 2020 11:12:13 +0000\nContent-Type: multipart/mixed; boundary=\"BND2\"\n\ncaptured as the payload\n--BND2--\ndiscarded epilogue\n\nFrom nobody Mon Jan  1 00:00:00 2020\nSubject: nested multipart without a boundary\nFrom: Karl <k@b.c>\nMessage-ID: <12@x>\nDate: Fri, 10 Jan 2020 12:13:14 +0000\nContent-Type: multipart/mixed; boundary=\"BND3\"\n\n--BND3\nContent-Type: multipart/mixed\n\ninner text with no boundary\n--BND3\nContent-Type: text/plain; charset=us-ascii\n\na normal part\n--BND3--\n\nFrom nobody Mon Jan  1 00:00:00 2020\nSubject: multipart without a boundary\nFrom: Lena <l@b.c>\nMessage-ID: <13@x>\nDate: Sat, 11 Jan 2020 13:14:15 +0000\nContent-Type: multipart/mixed\n\ncaf\xc3\xa9 raw 8-bit body\n\nFrom nobody Mon Jan  1 00:00:00 2020\nSubject: unknown charset\nFrom: Mo <m@b.c>\nMessage-ID: <14@x>\nDate: Sun, 12 Jan 2020 14:15:16 +0000\nContent-Type: text/plain; charset=bogus-nope\n\nthis body is dropped\n\n";

/// The generator's `_u(action, **params)` URL builder.
fn u(action: &str, params: &[(&str, &str)]) -> String {
    let qs = params
        .iter()
        .map(|(k, v)| format!("{k}={v}"))
        .collect::<Vec<_>>()
        .join("&");
    if qs.is_empty() {
        format!("/repo/{action}")
    } else {
        format!("/repo/{action}?{qs}")
    }
}

fn parsed() -> Vec<Msg> {
    parse_mbox(MBOX, MAX_MESSAGES)
}

fn threads() -> Vec<Thread> {
    group_threads(parsed())
}

#[test]
fn read_archive_matches_python() {
    // (subject, sender, ts, mid, in_reply_to, is_patch, body)
    let want: &[(&str, &str, i64, &str, &str, bool, &str)] = &[
        (
            "[PATCH] fix the bug",
            "Alice <a@b.c>",
            1577836800,
            "<1@x>",
            "",
            true,
            "Here is a fix.\n\ndiff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-old\n+new\n",
        ),
        (
            "Re: [PATCH] fix the bug",
            "Bob <b@b.c>",
            1577952245,
            "<2@x>",
            "<1@x>",
            true,
            "Looks good to me.\n>From the top, this is fine.\n",
        ),
        (
            "Question about build",
            "Carol <c@b.c>",
            1578052800,
            "<3@x>",
            "",
            false,
            "How do I build?\n",
        ),
        (
            "café patch série",
            "Déborah <d@b.c>",
            1578110828,
            "<4@x>",
            "",
            false,
            "Quoted printable café body with éèê.\n",
        ),
        (
            "Grüße and ✓ done",
            "Eve <e@b.c>",
            1578215411,
            "<5@x>",
            "",
            false,
            "Base64 body with ✓ check.\n",
        ),
        (
            "<script>evil</script> & \"quotes\"",
            "<x@y>",
            0,
            "<6@x>",
            "",
            false,
            "body <script>bad</script> here\n",
        ),
        (
            "PATCH without brackets",
            "Frank <f@b.c>",
            0,
            "<7@x>",
            "",
            true,
            "no date header at all\n",
        ),
        (
            "Re: Question about build",
            "Grace <g@b.c>",
            1578268923,
            "<8@x>",
            "<3@x>",
            false,
            "the plain alternative",
        ),
        (
            "a very long subject that the\n mailer folded across lines",
            "Heidi <h@b.c>",
            1578384550,
            "<9@x>",
            "",
            true,
            "@@ looks like a hunk header\nIndex: something\n",
        ),
        (
            "qp corners",
            "Ivan <i@b.c>",
            1578478272,
            "<10@x>",
            "",
            false,
            "Q2FmZQ=\nsoftjoined = done =Zb =g1 tail",
        ),
        (
            "only a close boundary",
            "Judy <j@b.c>",
            1578568333,
            "<11@x>",
            "",
            false,
            "captured as the payload\n",
        ),
        (
            "nested multipart without a boundary",
            "Karl <k@b.c>",
            1578658394,
            "<12@x>",
            "",
            false,
            "a normal part",
        ),
        (
            "multipart without a boundary",
            "Lena <l@b.c>",
            1578748455,
            "<13@x>",
            "",
            false,
            "café raw 8-bit body\n",
        ),
        (
            "unknown charset",
            "Mo <m@b.c>",
            1578838516,
            "<14@x>",
            "",
            false,
            "",
        ),
    ];
    let msgs = parsed();
    assert_eq!(msgs.len(), want.len(), "message count");
    for (m, w) in msgs.iter().zip(want.iter()) {
        assert_eq!(m.subject, w.0, "subject");
        assert_eq!(m.sender, w.1, "sender of {:?}", w.0);
        assert_eq!(m.ts, w.2, "ts of {:?}", w.0);
        assert_eq!(m.mid, w.3, "mid of {:?}", w.0);
        assert_eq!(m.in_reply_to, w.4, "in_reply_to of {:?}", w.0);
        assert_eq!(m.is_patch, w.5, "is_patch of {:?}", w.0);
        assert_eq!(m.body, w.6, "body of {:?}", w.0);
    }
}

#[test]
fn msg_raw_matches_python_as_bytes() {
    let want: &[&[u8]] = &[
    b"Subject: [PATCH] fix the bug\nFrom: Alice <a@b.c>\nMessage-ID: <1@x>\nDate: Wed, 01 Jan 2020 00:00:00 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nHere is a fix.\n\ndiff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-old\n+new\n",
    b"Subject: Re: [PATCH] fix the bug\nFrom: Bob <b@b.c>\nMessage-ID: <2@x>\nIn-Reply-To: <1@x>\nDate: Thu, 02 Jan 2020 03:04:05 -0500\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nLooks good to me.\n>From the top, this is fine.\n",
    b"Subject: Question about build\nFrom: Carol <c@b.c>\nMessage-ID: <3@x>\nDate: Fri, 03 Jan 2020 12:00:00 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nHow do I build?\n",
    b"Subject: =?utf-8?q?caf=C3=A9_patch_s=C3=A9rie?=\nFrom: =?utf-8?q?D=C3=A9borah?= <d@b.c>\nMessage-ID: <4@x>\nDate: Sat, 04 Jan 2020 06:07:08 +0200\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: quoted-printable\nMIME-Version: 1.0\n\nQuoted printable caf=C3=A9 body with =C3=A9=C3=A8=C3=AA.\n",
    b"Subject: =?utf-8?b?R3LDvMOfZSBhbmQg4pyT?= done\nFrom: Eve <e@b.c>\nMessage-ID: <5@x>\nDate: Sun, 05 Jan 2020 09:10:11 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: base64\nMIME-Version: 1.0\n\nQmFzZTY0IGJvZHkgd2l0aCDinJMgY2hlY2suCg==\n",
    b"Subject: <script>evil</script> & \"quotes\"\nFrom: <x@y>\nMessage-ID: <6@x>\nDate: \nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nbody <script>bad</script> here\n",
    b"Subject: PATCH without brackets\nFrom: Frank <f@b.c>\nMessage-ID: <7@x>\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nno date header at all\n",
    b"Subject: Re: Question about build\nFrom: Grace <g@b.c>\nMessage-ID: <8@x>\nIn-Reply-To: <3@x>\nDate: Sat, 06 Jan 2020 01:02:03 +0100\nMIME-Version: 1.0\nContent-Type: multipart/alternative; boundary=\"BND1\"\n\nThis is the preamble.\n--BND1\nContent-Type: text/plain; charset=us-ascii\n\nthe plain alternative\n--BND1\nContent-Type: text/html; charset=us-ascii\n\n<b>the html alternative</b>\n--BND1--\nthe epilogue\n",
    b"Subject: a very long subject that the\n mailer folded across lines\nFrom: Heidi <h@b.c>\nX-Weird: lots   of   space\nMessage-ID: <9@x>\nDate: 7 Jan 2020 08:09:10 -0000\n\n@@ looks like a hunk header\nIndex: something\n",
    b"Subject: qp corners\nFrom: Ivan <i@b.c>\nMessage-ID: <10@x>\nDate: Wed, 08 Jan 2020 10:11:12 +0000\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: quoted-printable\n\nQ2FmZQ==\nsoft=\njoined =3D done =Zb =g1 tail=\n",
    b"Subject: only a close boundary\nFrom: Judy <j@b.c>\nMessage-ID: <11@x>\nDate: Thu, 09 Jan 2020 11:12:13 +0000\nContent-Type: multipart/mixed; boundary=\"BND2\"\n\ncaptured as the payload\n",
    b"Subject: nested multipart without a boundary\nFrom: Karl <k@b.c>\nMessage-ID: <12@x>\nDate: Fri, 10 Jan 2020 12:13:14 +0000\nContent-Type: multipart/mixed; boundary=\"BND3\"\n\n--BND3\nContent-Type: multipart/mixed\n\ninner text with no boundary\n\n--BND3\nContent-Type: text/plain; charset=us-ascii\n\na normal part\n--BND3--\n",
    b"",
    b"Subject: unknown charset\nFrom: Mo <m@b.c>\nMessage-ID: <14@x>\nDate: Sun, 12 Jan 2020 14:15:16 +0000\nContent-Type: text/plain; charset=bogus-nope\n\nthis body is dropped\n",
    ];
    let msgs = parsed();
    assert_eq!(msgs.len(), want.len());
    for (m, w) in msgs.iter().zip(want.iter()) {
        assert_eq!(
            String::from_utf8_lossy(&m.raw),
            String::from_utf8_lossy(w),
            "raw of {:?}",
            m.subject
        );
        assert_eq!(&m.raw, w, "raw bytes of {:?}", m.subject);
    }
}

#[test]
fn normalize_subject_and_thread_id_match_python() {
    let cases: &[(&str, &str, &str)] = &[
        ("[PATCH] fix the bug", "fix the bug", "a58947a1ee9bd67c"),
        ("Re: [PATCH] fix the bug", "fix the bug", "a58947a1ee9bd67c"),
        (
            "RE: re: [PATCH v2 3/7] fix the bug",
            "fix the bug",
            "a58947a1ee9bd67c",
        ),
        ("Fwd: [list] Re: something", "something", "1af17e73721dbe0c"),
        ("FW: plain", "plain", "68c46e84d76d2e7e"),
        ("  [a][b]  [c] tail  ", "tail", "fbf5f2a2875b3bb6"),
        ("no prefixes here", "no prefixes here", "007e0744c79547b5"),
        ("", "", "da39a3ee5e6b4b0d"),
        ("Re:", "", "da39a3ee5e6b4b0d"),
        ("[unclosed bracket", "[unclosed bracket", "17d9a1a7ae4d66f9"),
        ("ÉLÉGANT Subject", "élégant subject", "25ee48576133d004"),
        ("Re: Re: Re: nested", "nested", "b4b3e0a278988bc1"),
        ("  ", "", "da39a3ee5e6b4b0d"),
        ("  \t  ", "", "da39a3ee5e6b4b0d"),
        (" padded subject ", "padded subject", "3ef516125d962c55"),
        (" Re: padded ", "padded", "35b1ac6f9cc1a7d2"),
        ("\u{1c}", "", "da39a3ee5e6b4b0d"),
        (
            "\u{1c}Re: c0 separators\u{1f}",
            "c0 separators",
            "5d2b03f608a9bbcd",
        ),
    ];
    for (subject, want_norm, want_id) in cases {
        assert_eq!(
            &normalize_subject(subject),
            want_norm,
            "normalize({subject:?})"
        );
        assert_eq!(&thread_id(subject), want_id, "thread_id({subject:?})");
    }
}

#[test]
fn group_threads_matches_python() {
    // (id, subject, ts, n_msgs, comma-joined message ids)
    let want: &[(&str, &str, i64, usize, &str)] = &[
        (
            "4d8418da6f9d1fbb",
            "unknown charset",
            1578838516,
            1,
            "<14@x>",
        ),
        (
            "f50afafd563ebb1a",
            "multipart without a boundary",
            1578748455,
            1,
            "<13@x>",
        ),
        (
            "48d657905922a373",
            "nested multipart without a boundary",
            1578658394,
            1,
            "<12@x>",
        ),
        (
            "0732389ce53f2aac",
            "only a close boundary",
            1578568333,
            1,
            "<11@x>",
        ),
        ("9bdaf6f214dcf9bf", "qp corners", 1578478272, 1, "<10@x>"),
        (
            "c713016423bc67e6",
            "a very long subject that the\n mailer folded across lines",
            1578384550,
            1,
            "<9@x>",
        ),
        (
            "8e872633d59ccdad",
            "Question about build",
            1578268923,
            2,
            "<3@x>,<8@x>",
        ),
        (
            "c6be966378867b22",
            "Grüße and ✓ done",
            1578215411,
            1,
            "<5@x>",
        ),
        (
            "26b371811c763cd9",
            "café patch série",
            1578110828,
            1,
            "<4@x>",
        ),
        (
            "a58947a1ee9bd67c",
            "[PATCH] fix the bug",
            1577952245,
            2,
            "<1@x>,<2@x>",
        ),
        (
            "8817b36957abf3f0",
            "<script>evil</script> & \"quotes\"",
            0,
            1,
            "<6@x>",
        ),
        ("3715fee1a070fa94", "PATCH without brackets", 0, 1, "<7@x>"),
    ];
    let got = threads();
    assert_eq!(got.len(), want.len(), "thread count");
    for (t, w) in got.iter().zip(want.iter()) {
        assert_eq!(t.id, w.0, "id of {:?}", w.1);
        assert_eq!(t.subject, w.1, "subject");
        assert_eq!(t.ts, w.2, "ts of {:?}", w.1);
        assert_eq!(t.msgs.len(), w.3, "msg count of {:?}", w.1);
        let mids = t
            .msgs
            .iter()
            .map(|m| m.mid.as_str())
            .collect::<Vec<_>>()
            .join(",");
        assert_eq!(mids, w.4, "mids of {:?}", w.1);
    }
}

#[test]
fn render_list_matches_python() {
    let want_list = "<div class=\"box\"><div class=\"box-head\">Patches</div><table class=\"list\"><thead><tr><th>Subject</th><th>From</th><th></th><th>Updated</th></tr></thead><tbody><tr><td><a href=\"/repo/patches?thread=4d8418da6f9d1fbb\">unknown charset</a></td><td class=\"muted\">Mo &lt;m@b.c&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\">2020-01-12</td></tr><tr><td><a href=\"/repo/patches?thread=f50afafd563ebb1a\">multipart without a boundary</a></td><td class=\"muted\">Lena &lt;l@b.c&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\">2020-01-11</td></tr><tr><td><a href=\"/repo/patches?thread=48d657905922a373\">nested multipart without a boundary</a></td><td class=\"muted\">Karl &lt;k@b.c&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\">2020-01-10</td></tr><tr><td><a href=\"/repo/patches?thread=0732389ce53f2aac\">only a close boundary</a></td><td class=\"muted\">Judy &lt;j@b.c&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\">2020-01-09</td></tr><tr><td><a href=\"/repo/patches?thread=9bdaf6f214dcf9bf\">qp corners</a></td><td class=\"muted\">Ivan &lt;i@b.c&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\">2020-01-08</td></tr><tr><td><a href=\"/repo/patches?thread=c713016423bc67e6\">a very long subject that the\n mailer folded across lines</a></td><td class=\"muted\">Heidi &lt;h@b.c&gt;</td><td class=\"muted\">1 msg &middot; patch</td><td class=\"muted\">2020-01-07</td></tr><tr><td><a href=\"/repo/patches?thread=8e872633d59ccdad\">Question about build</a></td><td class=\"muted\">Carol &lt;c@b.c&gt;</td><td class=\"muted\">2 msgs</td><td class=\"muted\">2020-01-06</td></tr><tr><td><a href=\"/repo/patches?thread=c6be966378867b22\">Grüße and ✓ done</a></td><td class=\"muted\">Eve &lt;e@b.c&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\">2020-01-05</td></tr><tr><td><a href=\"/repo/patches?thread=26b371811c763cd9\">café patch série</a></td><td class=\"muted\">Déborah &lt;d@b.c&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\">2020-01-04</td></tr><tr><td><a href=\"/repo/patches?thread=a58947a1ee9bd67c\">[PATCH] fix the bug</a></td><td class=\"muted\">Alice &lt;a@b.c&gt;</td><td class=\"muted\">2 msgs &middot; patch</td><td class=\"muted\">2020-01-02</td></tr><tr><td><a href=\"/repo/patches?thread=8817b36957abf3f0\">&lt;script&gt;evil&lt;/script&gt; &amp; &quot;quotes&quot;</a></td><td class=\"muted\">&lt;x@y&gt;</td><td class=\"muted\">1 msg</td><td class=\"muted\"></td></tr><tr><td><a href=\"/repo/patches?thread=3715fee1a070fa94\">PATCH without brackets</a></td><td class=\"muted\">Frank &lt;f@b.c&gt;</td><td class=\"muted\">1 msg &middot; patch</td><td class=\"muted\"></td></tr></tbody></table></div><div class=\"box\"><div class=\"box-head\">Contribute</div><div class=\"box-body\">Send patches the mailing-list way — no account needed:<pre class=\"msg\">git clone &lt;this repo&gt;\ngit commit -s\ngit send-email --to=&lt;list address&gt; HEAD~1</pre>gitweb renders the resulting thread here, read-only.</div></div>";
    assert_eq!(render_list("repo", &threads(), u, true), want_list);
}

#[test]
fn render_list_unconfigured_matches_python() {
    let want_unconfigured = "<div class=\"box\"><div class=\"box-head\">Patches</div><div class=\"box-body muted\">No patch archive is configured for this repo. An operator can point one at an mbox fed by <code>git send-email</code> to a mailing list.</div></div><div class=\"box\"><div class=\"box-head\">Contribute</div><div class=\"box-body\">Send patches the mailing-list way — no account needed:<pre class=\"msg\">git clone &lt;this repo&gt;\ngit commit -s\ngit send-email --to=&lt;list address&gt; HEAD~1</pre>gitweb renders the resulting thread here, read-only.</div></div>";
    assert_eq!(render_list("repo", &[], u, false), want_unconfigured);
}

#[test]
fn render_list_empty_matches_python() {
    let want_empty = "<div class=\"box\"><div class=\"box-head\">Patches</div><div class=\"box-body muted\">The archive is empty.</div></div><div class=\"box\"><div class=\"box-head\">Contribute</div><div class=\"box-body\">Send patches the mailing-list way — no account needed:<pre class=\"msg\">git clone &lt;this repo&gt;\ngit commit -s\ngit send-email --to=&lt;list address&gt; HEAD~1</pre>gitweb renders the resulting thread here, read-only.</div></div>";
    assert_eq!(render_list("repo", &[], u, true), want_empty);
}

#[test]
fn render_thread_matches_python() {
    let want: &[&str] = &[
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=4d8418da6f9d1fbb\">download mbox (git am)</a></p><h2>unknown charset</h2><div class=\"box\"><div class=\"box-head\">Mo &lt;m@b.c&gt; <span class=\"muted\">2020-01-12</span></div><pre class=\"msg\"></pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=f50afafd563ebb1a\">download mbox (git am)</a></p><h2>multipart without a boundary</h2><div class=\"box\"><div class=\"box-head\">Lena &lt;l@b.c&gt; <span class=\"muted\">2020-01-11</span></div><pre class=\"msg\">café raw 8-bit body\n</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=48d657905922a373\">download mbox (git am)</a></p><h2>nested multipart without a boundary</h2><div class=\"box\"><div class=\"box-head\">Karl &lt;k@b.c&gt; <span class=\"muted\">2020-01-10</span></div><pre class=\"msg\">a normal part</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=0732389ce53f2aac\">download mbox (git am)</a></p><h2>only a close boundary</h2><div class=\"box\"><div class=\"box-head\">Judy &lt;j@b.c&gt; <span class=\"muted\">2020-01-09</span></div><pre class=\"msg\">captured as the payload\n</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=9bdaf6f214dcf9bf\">download mbox (git am)</a></p><h2>qp corners</h2><div class=\"box\"><div class=\"box-head\">Ivan &lt;i@b.c&gt; <span class=\"muted\">2020-01-08</span></div><pre class=\"msg\">Q2FmZQ=\nsoftjoined = done =Zb =g1 tail</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=c713016423bc67e6\">download mbox (git am)</a></p><h2>a very long subject that the\n mailer folded across lines</h2><div class=\"box\"><div class=\"box-head\">Heidi &lt;h@b.c&gt; <span class=\"muted\">2020-01-07</span></div><pre class=\"patch\">\n<span class=\"hunk\">@@ looks like a hunk header</span>\n<span class=\"fh\">Index: something</span>\n\n</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=8e872633d59ccdad\">download mbox (git am)</a></p><h2>Question about build</h2><div class=\"box\"><div class=\"box-head\">Carol &lt;c@b.c&gt; <span class=\"muted\">2020-01-03</span></div><pre class=\"msg\">How do I build?\n</pre></div><div class=\"box\"><div class=\"box-head\">Grace &lt;g@b.c&gt; <span class=\"muted\">2020-01-06</span></div><pre class=\"msg\">the plain alternative</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=c6be966378867b22\">download mbox (git am)</a></p><h2>Grüße and ✓ done</h2><div class=\"box\"><div class=\"box-head\">Eve &lt;e@b.c&gt; <span class=\"muted\">2020-01-05</span></div><pre class=\"msg\">Base64 body with ✓ check.\n</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=26b371811c763cd9\">download mbox (git am)</a></p><h2>café patch série</h2><div class=\"box\"><div class=\"box-head\">Déborah &lt;d@b.c&gt; <span class=\"muted\">2020-01-04</span></div><pre class=\"msg\">Quoted printable café body with éèê.\n</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=a58947a1ee9bd67c\">download mbox (git am)</a></p><h2>[PATCH] fix the bug</h2><div class=\"box\"><div class=\"box-head\">Alice &lt;a@b.c&gt; <span class=\"muted\">2020-01-01</span></div><pre class=\"patch\">\nHere is a fix.\n\n<span class=\"fh\">diff --git a/x b/x</span>\n<span class=\"fh\">--- a/x</span>\n<span class=\"fh\">+++ b/x</span>\n<span class=\"hunk\">@@ -1 +1 @@</span>\n<span class=\"del\">-old</span>\n<span class=\"add\">+new</span>\n\n</pre></div><div class=\"box\"><div class=\"box-head\">Bob &lt;b@b.c&gt; <span class=\"muted\">2020-01-02</span></div><pre class=\"patch\">\nLooks good to me.\n&gt;From the top, this is fine.\n\n</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=8817b36957abf3f0\">download mbox (git am)</a></p><h2>&lt;script&gt;evil&lt;/script&gt; &amp; &quot;quotes&quot;</h2><div class=\"box\"><div class=\"box-head\">&lt;x@y&gt; <span class=\"muted\"></span></div><pre class=\"msg\">body &lt;script&gt;bad&lt;/script&gt; here\n</pre></div>",
    "<p><a href=\"/repo/patches\">&larr; all patches</a> &middot; <a href=\"/repo/patches.mbox?thread=3715fee1a070fa94\">download mbox (git am)</a></p><h2>PATCH without brackets</h2><div class=\"box\"><div class=\"box-head\">Frank &lt;f@b.c&gt; <span class=\"muted\"></span></div><pre class=\"patch\">\nno date header at all\n\n</pre></div>",
    ];
    let got = threads();
    assert_eq!(got.len(), want.len());
    for (t, w) in got.iter().zip(want.iter()) {
        assert_eq!(
            &render_thread("repo", t, u),
            w,
            "render_thread({:?})",
            t.subject
        );
    }
    // Escape-first: no repository-controlled markup survives.
    for w in want {
        assert!(!w.contains("<script>evil"));
        assert!(!w.contains("<script>bad"));
    }
}

#[test]
fn thread_mbox_matches_python() {
    let want: &[&[u8]] = &[
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: unknown charset\nFrom: Mo <m@b.c>\nMessage-ID: <14@x>\nDate: Sun, 12 Jan 2020 14:15:16 +0000\nContent-Type: text/plain; charset=bogus-nope\n\nthis body is dropped\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\n\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: nested multipart without a boundary\nFrom: Karl <k@b.c>\nMessage-ID: <12@x>\nDate: Fri, 10 Jan 2020 12:13:14 +0000\nContent-Type: multipart/mixed; boundary=\"BND3\"\n\n--BND3\nContent-Type: multipart/mixed\n\ninner text with no boundary\n\n--BND3\nContent-Type: text/plain; charset=us-ascii\n\na normal part\n--BND3--\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: only a close boundary\nFrom: Judy <j@b.c>\nMessage-ID: <11@x>\nDate: Thu, 09 Jan 2020 11:12:13 +0000\nContent-Type: multipart/mixed; boundary=\"BND2\"\n\ncaptured as the payload\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: qp corners\nFrom: Ivan <i@b.c>\nMessage-ID: <10@x>\nDate: Wed, 08 Jan 2020 10:11:12 +0000\nContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: quoted-printable\n\nQ2FmZQ==\nsoft=\njoined =3D done =Zb =g1 tail=\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: a very long subject that the\n mailer folded across lines\nFrom: Heidi <h@b.c>\nX-Weird: lots   of   space\nMessage-ID: <9@x>\nDate: 7 Jan 2020 08:09:10 -0000\n\n@@ looks like a hunk header\nIndex: something\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: Question about build\nFrom: Carol <c@b.c>\nMessage-ID: <3@x>\nDate: Fri, 03 Jan 2020 12:00:00 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nHow do I build?\n\nFrom git@localhost Mon Sep 17 00:00:00 2001\nSubject: Re: Question about build\nFrom: Grace <g@b.c>\nMessage-ID: <8@x>\nIn-Reply-To: <3@x>\nDate: Sat, 06 Jan 2020 01:02:03 +0100\nMIME-Version: 1.0\nContent-Type: multipart/alternative; boundary=\"BND1\"\n\nThis is the preamble.\n--BND1\nContent-Type: text/plain; charset=us-ascii\n\nthe plain alternative\n--BND1\nContent-Type: text/html; charset=us-ascii\n\n<b>the html alternative</b>\n--BND1--\nthe epilogue\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: =?utf-8?b?R3LDvMOfZSBhbmQg4pyT?= done\nFrom: Eve <e@b.c>\nMessage-ID: <5@x>\nDate: Sun, 05 Jan 2020 09:10:11 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: base64\nMIME-Version: 1.0\n\nQmFzZTY0IGJvZHkgd2l0aCDinJMgY2hlY2suCg==\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: =?utf-8?q?caf=C3=A9_patch_s=C3=A9rie?=\nFrom: =?utf-8?q?D=C3=A9borah?= <d@b.c>\nMessage-ID: <4@x>\nDate: Sat, 04 Jan 2020 06:07:08 +0200\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: quoted-printable\nMIME-Version: 1.0\n\nQuoted printable caf=C3=A9 body with =C3=A9=C3=A8=C3=AA.\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: [PATCH] fix the bug\nFrom: Alice <a@b.c>\nMessage-ID: <1@x>\nDate: Wed, 01 Jan 2020 00:00:00 +0000\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nHere is a fix.\n\ndiff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-old\n+new\n\nFrom git@localhost Mon Sep 17 00:00:00 2001\nSubject: Re: [PATCH] fix the bug\nFrom: Bob <b@b.c>\nMessage-ID: <2@x>\nIn-Reply-To: <1@x>\nDate: Thu, 02 Jan 2020 03:04:05 -0500\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nLooks good to me.\n>From the top, this is fine.\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: <script>evil</script> & \"quotes\"\nFrom: <x@y>\nMessage-ID: <6@x>\nDate: \nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nbody <script>bad</script> here\n\n",
    b"From git@localhost Mon Sep 17 00:00:00 2001\nSubject: PATCH without brackets\nFrom: Frank <f@b.c>\nMessage-ID: <7@x>\nContent-Type: text/plain; charset=\"utf-8\"\nContent-Transfer-Encoding: 7bit\nMIME-Version: 1.0\n\nno date header at all\n\n",
    ];
    let got = threads();
    assert_eq!(got.len(), want.len());
    for (t, w) in got.iter().zip(want.iter()) {
        assert_eq!(&thread_mbox(t), w, "thread_mbox({:?})", t.subject);
        assert!(thread_mbox(t).starts_with(b"From "));
    }
}

#[test]
fn caps_and_missing_file_match_python() {
    assert_eq!(MAX_MESSAGES, 2000);
    assert_eq!(MAX_BODY, 524288);
    assert!(read_archive(std::path::Path::new("/nonexistent/x.mbox"), MAX_MESSAGES).is_empty());
    assert_eq!(parse_mbox(MBOX, 2).len(), 2);
    assert!(parse_mbox(b"", MAX_MESSAGES).is_empty());
    assert!(parse_mbox(b"not an mbox at all\n", MAX_MESSAGES).is_empty());
}

#[test]
fn read_archive_reads_the_same_bytes_from_disk() {
    let dir = std::env::temp_dir().join(format!("gitweb-xcheck-{}", std::process::id()));
    std::fs::create_dir_all(&dir).expect("mkdir");
    let path = dir.join("repo.mbox");
    std::fs::write(&path, MBOX).expect("write");
    assert_eq!(read_archive(&path, MAX_MESSAGES), parsed());
    std::fs::remove_dir_all(&dir).ok();
}

#[test]
fn patch_css_matches_python() {
    let want_css = "pre.patch,pre.msg{white-space:pre-wrap;overflow-x:auto;font-size:.85rem;background:#f6f8fa;border:1px solid #e1e4e8;border-radius:4px;padding:.6rem}pre.patch .add{color:#116329;background:#e6ffec;display:block}pre.patch .del{color:#82071e;background:#ffebe9;display:block}pre.patch .hunk{color:#0550ae;display:block}pre.patch .fh{color:#57606a;font-weight:bold;display:block}";
    assert_eq!(PATCH_CSS, want_css);
}
