#!/usr/bin/env python3
"""Regenerate the byte-identical cross-check goldens for gitweb.

Drives the **real** Python `gitweb` modules (`markup`, `auth`, `metrics`,
`mailarchive`) and prints Rust literals. Re-running this and diffing against the
literals embedded in `tests/xcheck_*.rs` proves the Rust ports stay
byte-identical to the reference.

    cd astrx-suite
    PYTHONPATH=legacy-python/gitweb TZ=UTC \
        python3 crates/gitweb/tests/regen_goldens.py

`TZ=UTC` matters only for `gen_mailarchive`: a `Date:` header with no timezone
makes CPython fall back to `time.mktime` (server-local), which the Rust port
resolves as UTC.
"""

from __future__ import annotations

import os
import tempfile


# --------------------------------------------------------------------------- #
# Rust literal helpers
# --------------------------------------------------------------------------- #


def rs(s: str) -> str:
    """Render `s` as a Rust `&str` literal (always escaped, never raw)."""
    out = ['"']
    for ch in s:
        o = ord(ch)
        if ch == "\\":
            out.append("\\\\")
        elif ch == '"':
            out.append('\\"')
        elif ch == "\n":
            out.append("\\n")
        elif ch == "\r":
            out.append("\\r")
        elif ch == "\t":
            out.append("\\t")
        elif o < 0x20 or o == 0x7F or 0x80 <= o <= 0x9F:
            out.append("\\u{%x}" % o)
        else:
            out.append(ch)
    out.append('"')
    return "".join(out)


def rb(b: bytes) -> str:
    """Render `b` as a Rust `&[u8]` literal."""
    out = ['b"']
    for byte in b:
        if byte == 0x5C:
            out.append("\\\\")
        elif byte == 0x22:
            out.append('\\"')
        elif byte == 0x0A:
            out.append("\\n")
        elif byte == 0x0D:
            out.append("\\r")
        elif byte == 0x09:
            out.append("\\t")
        elif 0x20 <= byte < 0x7F:
            out.append(chr(byte))
        else:
            out.append("\\x%02x" % byte)
    out.append('"')
    return "".join(out)


# --------------------------------------------------------------------------- #
# markup: escaping
# --------------------------------------------------------------------------- #


def gen_markup_escape() -> None:
    from gitweb import markup

    print("// ==== markup::esc / xml_escape (input, esc, xml_escape) ====")
    cases = [
        "",
        "plain text",
        "<script>alert(1)</script>",
        "a & b",
        "\"quoted\" and 'single'",
        "<a href=\"x\" onclick='y'>&amp;</a>",
        "&<>\"'",
        "café — naïve",
        "line1\nline2\ttabbed",
        # Atom noncharacters + C0 controls (the test_atom_noncharacters spec).
        "fix￾bug",
        "fix￿bug",
        "fix\x01bug",
        "fix\x00bug",
        "a﷐b",  # XML-legal noncharacter: must be preserved
        "\x08\x0b\x0c\x1f",
        "tab\there\nnl\rcr",  # \t \n \r are XML-legal: preserved
        "\U0001f600 emoji",
        "mixed ￾ & <b>\x02",
    ]
    for s in cases:
        print(
            "    (%s, %s, %s),"
            % (rs(s), rs(markup.esc(s)), rs(markup.xml_escape(s)))
        )


# --------------------------------------------------------------------------- #
# markup: dates
# --------------------------------------------------------------------------- #


def gen_markup_dates() -> None:
    from gitweb import markup

    now = 1_700_000_000.0
    print("// ==== markup::relative_date (ts, now, expected) ====")
    deltas = [
        0, 1, 30, 59, 60, 61, 119, 120, 3599, 3600, 3601, 7200, 86399, 86400,
        86401, 172800, 604799, 604800, 604801, 1209600, 2591999, 2592000,
        2592001, 5184000, 31535999, 31536000, 31536001, 63072000, 315360000,
    ]
    for d in deltas:
        ts = int(now) - d
        print("    (%d, %r, %s)," % (ts, now, rs(markup.relative_date(ts, now))))
    # Falsy / future timestamps.
    print("    (0, %r, %s)," % (now, rs(markup.relative_date(0, now))))
    print(
        "    (%d, %r, %s)," % (int(now) + 5000, now, rs(markup.relative_date(int(now) + 5000, now)))
    )
    print("    (1, %r, %s)," % (now, rs(markup.relative_date(1, now))))

    print("// ==== markup::iso_date / atom_date (ts, iso, atom) ====")
    stamps = [
        0, 1, 59, 60, 3599, 3600, 86399, 86400, 604800, 2592000, 31536000,
        951782400, 1000000000, 1700000000, 253402300799, -1, -86400,
        -62135596800, 1583020800, 1614556800,
    ]
    for ts in stamps:
        print(
            "    (%d, %s, %s),"
            % (ts, rs(markup.iso_date(ts)), rs(markup.atom_date(ts)))
        )


# --------------------------------------------------------------------------- #
# markup: the Markdown subset
# --------------------------------------------------------------------------- #

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

BIG_DOC = """\
Project Title
=============

A short **intro** paragraph with _emphasis_, `inline code`, a
[link](https://example.com/a?b=1&c=2) and an ![image](/img/logo.png "t").

Sub Heading
-----------

## Features ##

1. First item
2. Second item
   1. Nested ordered
   2. Another
3. Third

* bullet one
* bullet two
  * nested bullet
  * another nested
+ switched marker

- [ ] todo item
- [X] done item

| Left | Center | Right | Plain |
|:-----|:------:|------:|-------|
| a    | b      | c     | d     |
| `x`  | **y**  | [z](/z) | <script>bad</script> |

> A quote with a [ref link][site].
>
> > A nested quote with `code`.
> > And a second line.

```python
def f(x):
    return x < 1 and "<b>not html</b>"
```

~~~
tilde fenced <i>literal</i>
~~~

Autolinks: https://bare.example.com/path?q=1, and <https://angle.example.com/x>,
and a trailing-punctuation one https://dot.example.com/end.

Hostile: <script>alert(1)</script> and <img src=x onerror=alert(1)> and
[js](javascript:alert(1)) and ![js](JavaScript:alert(2)) and [d](data:text/html,x).

Hard break here
next line after break.

[site]: https://example.org/site "The Site"
[unused]: https://example.net/unused
[evil]: javascript:alert(3)

Reference use: [good][site], [bad][evil], [missing][nope], [collapsed][].

Trailing paragraph with a lone * star and _underscore_ and 2*3*4.
"""

MD_CASES = [
    "",
    "\n",
    "# Hi",
    "# Hi ##",
    "# Hi #########   ",
    "####### seven hashes x",
    "#no space",
    "Title\n===\n",
    "Sub\n---\n",
    "<script>evil</script>\n===\n",
    "a  \nb\n",
    "a\nb\n",
    "- a\n  - b\n  - c\n- d\n",
    "- a\n- b\n1. c\n2. d\n",
    "1. a\n2. b\n",
    "1) a\n2) b\n",
    "\t- tabbed item\n\t\t- deeper\n",
    "> outer\n>\n> > inner\n",
    ">deep\n>>deeper\n>>>deepest\n",
    "> > > > > > > > > > too deep\n",
    "![x](javascript:alert(1))\n\n[y](javascript:alert(2))\n\n<script>alert(3)</script>\n",
    "a `code` b\x001\x00 c\n",
    "x\x0099\x00 y\n",
    "[![logo](/logo.png)](/home)",
    "![a`b`c](/x.png)",
    "[label](![alt](/i.png))",
    "[a](`code`)",
    "![outer](`code`)",
    "[![i](/p)](`c`)",
    "See [good][a] and [evil][b] and [x][c].\n\n[a]: https://example.com\n[b]: javascript:alert(1)\n",
    "[A]: <https://example.com/angle>\n\nUse [text][a].\n",
    "[dup]: https://first.example\n[dup]: https://second.example\n\n[t][dup]\n",
    "<https://ang.example.com>\n",
    "<https://ang.example.com/a>b<https://ang.example.com/c>\n",
    "http://plain.example.com/x, then more.",
    "https://trail.example.com/a.b.c!?",
    "**bold** and __bold2__ and *em* and _em2_",
    "**a*b**",
    "a**b**c",
    "snake_case_word and _real em_",
    "2*3*4 and x*y*z",
    "*multi\nline* no",
    "`unclosed code",
    "``",
    "`a``b`",
    "| h1 | h2 |\n| --- | --- |\n| a | b |\n",
    "| h1 | h2 |\n|:--|--:|\n| a |\n| a | b | c |\n",
    "h1 | h2\n--- | ---\na | b\n",
    "not | a table\njust text\n",
    "```\nunclosed fence\n",
    "```js\ncode `tick` <b>\n```\nafter\n",
    # NOTE: `"  # x"` (an *indented* ATX heading) is deliberately absent — the
    # Python reference never returns for it (see the divergence note in
    # `markup.rs`); the Rust behaviour is asserted directly in the xcheck.
    "  #x",
    "  ####### x",
    "* * *\n",
    "- \n",
    "-  spaced   content\n",
    "[" * 40,
    "[" * 40 + "]",
    "a" * 100 + "\n" + "b" * 100,
    "line one\r\nline two\rline three\n",
    "éè café **gras** `codeé`\n",
    "[é](/café)\n",
]


def gen_markup_markdown() -> None:
    from gitweb import markup

    print("// ==== markup::render_markdown (source, expected) ====")
    for src in [GUIDE_MD, BIG_DOC] + MD_CASES:
        print("    (%s, %s)," % (rs(src), rs(markup.render_markdown(src))))

    print("// ==== markup::render_readme (source, is_markdown, expected) ====")
    for src, is_md in [
        ("# Title\n\ntext\n", True),
        ("# Title\n\ntext\n", False),
        ("<script>x</script>", False),
        ("", True),
        ("", False),
    ]:
        print(
            "    (%s, %s, %s),"
            % (rs(src), "true" if is_md else "false", rs(markup.render_readme(src, is_md)))
        )

    print("// ==== markup::render_markdown oversized -> <pre> ====")
    big = "[" * (markup.MAX_MARKDOWN_BYTES + 1)
    out = markup.render_markdown(big)
    print("    // len(src) = %d, len(out) = %d" % (len(big), len(out)))
    print("    starts_with_pre = %s;" % str(out.startswith("<pre>")).lower())
    print("    ends_with_pre = %s;" % str(out.endswith("</pre>")).lower())
    exact = "[" * markup.MAX_MARKDOWN_BYTES
    print(
        "    at_cap_is_parsed = %s;"
        % str(not markup.render_markdown(exact).startswith("<pre>[")).lower()
    )


# --------------------------------------------------------------------------- #
# markup: unified-diff parsing
# --------------------------------------------------------------------------- #

PATCH = """\
commit deadbeef
Author: A U Thor <a@example.com>
Date:   Mon Jan 1 00:00:00 2020 +0000

    subject line

diff --git a/src/keep.txt b/src/keep.txt
index 1111111..2222222 100644
--- a/src/keep.txt
+++ b/src/keep.txt
@@ -1,4 +1,4 @@
 context one
-removed line
+added line
 context two
\\ No newline at end of file
diff --git a/new/file.txt b/new/file.txt
new file mode 100644
index 0000000..3333333
--- /dev/null
+++ b/new/file.txt
@@ -0,0 +1,2 @@
+first
+second
diff --git a/old/gone.txt b/old/gone.txt
deleted file mode 100644
index 4444444..0000000
--- a/old/gone.txt
+++ /dev/null
@@ -1,1 +0,0 @@
-bye
diff --git a/a/from.txt b/b/to.txt
similarity index 92%
rename from a/from.txt
rename to b/to.txt
index 5555555..6666666 100644
--- a/a/from.txt
+++ b/b/to.txt
@@ -1 +1 @@
-old name
+new name
diff --git a/img/logo.png b/img/logo.png
index 7777777..8888888 100644
Binary files a/img/logo.png and b/img/logo.png differ
diff --git a/bin/blob.dat b/bin/blob.dat
new file mode 100644
index 0000000..9999999
GIT binary patch
literal 12
abcdefgh
diff --git a/tricky b/name b/other b/tricky b/name b/final
index aaaaaaa..bbbbbbb 100644
--- a/tricky b/name b/other
+++ b/tricky b/name b/final
@@ -1 +1 @@
-x
+y
diff --git a/same.txt b/same.txt
dissimilarity index 5%
rename from same.txt
rename to same.txt
"""


def gen_markup_diff() -> None:
    from gitweb import markup

    print("// ==== markup::parse_patch ====")
    print("    let patch = %s;" % rs(PATCH))
    files = markup.parse_patch(PATCH)
    print("    // %d files" % len(files))
    for f in files:
        print(
            "    (%s, %s, %s, %s, %d, %d, %s, %d),"
            % (
                rs(f.old_path),
                rs(f.new_path),
                rs(f.status),
                "true" if f.binary else "false",
                f.additions,
                f.deletions,
                rs(f.display_path),
                len(f.lines),
            )
        )
    print("// ==== markup::parse_patch lines (file_idx, kind, text) ====")
    for i, f in enumerate(files):
        for ln in f.lines:
            print("    (%d, %s, %s)," % (i, rs(ln.kind), rs(ln.text)))
    print("// ==== markup::parse_patch degenerate inputs ====")
    for src in ["", "no diff here\n", "diff --git a/x b/y\n+orphan\n", "+lonely\n"]:
        got = markup.parse_patch(src)
        print(
            "    (%s, %d, %s),"
            % (
                rs(src),
                len(got),
                rs("|".join("%s:%s:%s" % (f.old_path, f.new_path, f.status) for f in got)),
            )
        )


# --------------------------------------------------------------------------- #
# auth
# --------------------------------------------------------------------------- #


def gen_auth() -> None:
    import base64

    from gitweb import auth

    print("// ==== auth::hash_password (password, salt, expected) ====")
    for pw, salt in [
        ("hunter2", "deadbeef"),
        ("s3cret", "abcd1234"),
        ("", "00"),
        ("p@ss w/ spaces", "0123456789abcdef"),
        ("café—naïve", "fedcba9876543210"),
        ("a" * 200, "ff"),
    ]:
        print("    (%s, %s, %s)," % (rs(pw), rs(salt), rs(auth.hash_password(pw, salt=salt))))

    print("// ==== auth::verify_password (stored, password, expected) ====")
    good = auth.hash_password("hunter2", salt="deadbeef")
    for stored, pw in [
        (good, "hunter2"),
        (good, "wrong"),
        (good, ""),
        ("garbage", "hunter2"),
        ("", ""),
        ("sha256$deadbeef$", "hunter2"),
        ("sha256$$" + good.split("$")[2], "hunter2"),
        ("md5$deadbeef$abc", "hunter2"),
        ("sha256$deadbeef$abc$def", "hunter2"),
        (auth.hash_password("", salt=""), ""),
    ]:
        print(
            "    (%s, %s, %s),"
            % (rs(stored), rs(pw), str(auth.verify_password(stored, pw)).lower())
        )

    print("// ==== auth::parse_auth_spec (spec, ok/none/err, user, message) ====")
    specs = [
        "",
        "   ",
        "  bob:" + good + "  ",
        "bob:" + good,
        "nocolon",
        "bob:plaintext",
        ":" + good,
        "bob:",
        "bob:sha256$deadbeef",
        "bob:sha256$deadbeef$abc$def",
        "bob:md5$deadbeef$abc",
        "bob:sha256$$abc",
        "bob:sha256$deadbeef$",
        "b:o:b:" + good,
    ]
    for spec in specs:
        try:
            cred = auth.parse_auth_spec(spec)
        except ValueError as e:
            print("    (%s, \"err\", \"\", %s)," % (rs(spec), rs(str(e))))
            continue
        if cred is None:
            print("    (%s, \"none\", \"\", \"\")," % rs(spec))
        else:
            print(
                "    (%s, \"cred\", %s, %s),"
                % (rs(spec), rs(cred.user), rs(cred.stored))
            )

    print("// ==== auth::check_basic_auth (header, expected) ====")
    cred = auth.parse_auth_spec("alice:" + auth.hash_password("s3cret", salt="abcd1234"))
    headers = [
        None,
        "",
        "Basic " + base64.b64encode(b"alice:s3cret").decode(),
        "basic " + base64.b64encode(b"alice:s3cret").decode(),
        "BASIC " + base64.b64encode(b"alice:s3cret").decode(),
        "Basic  " + base64.b64encode(b"alice:s3cret").decode() + "  ",
        "\tBasic\t" + base64.b64encode(b"alice:s3cret").decode(),
        "Basic " + base64.b64encode(b"alice:nope").decode(),
        "Basic " + base64.b64encode(b"bob:s3cret").decode(),
        "Basic " + base64.b64encode(b"alice").decode(),
        "Basic " + base64.b64encode(b"alice:s3cret:extra").decode(),
        "Basic " + base64.b64encode(b":s3cret").decode(),
        "Bearer " + base64.b64encode(b"alice:s3cret").decode(),
        "Basic",
        "Basic ",
        "Basic !!!not-base64!!!",
        "Basic QQ=",
        "Basic QQ",
        "Basic Q",
        "Basic ",
        "Basic " + base64.b64encode("ü:pw".encode()).decode(),
        "Basic " + base64.b64encode("alice:s3cret".encode()).decode() + "==",
        "Basic " + base64.b64encode(b"\xff\xfe:s3cret").decode(),
        "Basic YWxpY2U6czNjcmV0",
    ]
    for h in headers:
        print(
            "    (%s, %s),"
            % ("None" if h is None else "Some(%s)" % rs(h), str(auth.check_basic_auth(h, cred)).lower())
        )


# --------------------------------------------------------------------------- #
# metrics
# --------------------------------------------------------------------------- #


def gen_metrics() -> None:
    import time as _time

    from gitweb import metrics as M

    m = M.Metrics()
    m.begin()
    m.begin()
    m.begin()
    m.end(200, "repo", 0.0125)
    m.end(404, "", 0.5)
    m.end(200, "log", 1.0 / 3.0)
    m.reject()
    m.reject()
    m.end(500, "blob", 2.5)
    m.end(200, "repo", 0.001)
    m.end(301, "atom", 0.0)
    m.begin()

    snap = m.snapshot()
    print("// ==== metrics::Snapshot fields ====")
    print("    total = %d;" % snap["total"])
    print("    in_flight = %d;" % snap["in_flight"])
    print("    rejected = %d;" % snap["rejected"])
    print("    latency_count = %d;" % snap["latency_count"])
    print("    latency_sum = %r;" % snap["latency_sum"])
    print("    by_status = %r;" % sorted(snap["by_status"].items()))
    print("    by_action = %r;" % sorted(snap["by_action"].items()))

    real = _time.time
    m.started = 1000.0
    M.time.time = lambda: 1000.0 + 12.3456789
    try:
        out = m.render_prometheus()
    finally:
        M.time.time = real
    print("// ==== metrics::render_prometheus (uptime frozen at 12.3456789) ====")
    print("    let want = %s;" % rs(out))

    empty = M.Metrics()
    empty.started = 1000.0
    M.time.time = lambda: 1000.0
    try:
        out2 = empty.render_prometheus()
    finally:
        M.time.time = real
    print("// ==== metrics::render_prometheus (empty registry, uptime 0.0) ====")
    print("    let want_empty = %s;" % rs(out2))


# --------------------------------------------------------------------------- #
# mailarchive
# --------------------------------------------------------------------------- #

PATCH_BODY = (
    "Here is a fix.\n\n"
    "diff --git a/x b/x\n--- a/x\n+++ b/x\n@@ -1 +1 @@\n-old\n+new\n"
)


def _u(action, **params):
    qs = "&".join("%s=%s" % (k, v) for k, v in params.items())
    return "/repo/%s%s" % (action, ("?" + qs) if qs else "")


def _build_mbox(path: str) -> None:
    """Write an mbox exercising the parser: plain, patch, RFC 2047 subjects,
    quoted-printable, base64, multipart/alternative, folded headers, an
    unparseable date, a body line that mbox mangles to `>From `, and hostile
    HTML in both the subject and the body."""
    import mailbox
    from email.message import EmailMessage

    box = mailbox.mbox(path)

    def add(headers, body, charset="utf-8", cte=None):
        m = EmailMessage()
        for k, v in headers:
            m[k] = v
        m.set_content(body, charset=charset, cte=cte)
        # Without an explicit envelope line `mailbox` writes
        # `From MAILER-DAEMON <asctime>`, which would make this generator
        # non-reproducible. (The line is dropped when the message is read
        # back, so it does not affect any parsed field.)
        m.set_unixfrom("From gitweb@example Mon Jan  1 00:00:00 2020")
        box.add(m)

    add(
        [
            ("Subject", "[PATCH] fix the bug"),
            ("From", "Alice <a@b.c>"),
            ("Message-ID", "<1@x>"),
            ("Date", "Mon, 01 Jan 2020 00:00:00 +0000"),
        ],
        PATCH_BODY,
    )
    add(
        [
            ("Subject", "Re: [PATCH] fix the bug"),
            ("From", "Bob <b@b.c>"),
            ("Message-ID", "<2@x>"),
            ("In-Reply-To", "<1@x>"),
            ("Date", "Tue, 02 Jan 2020 03:04:05 -0500"),
        ],
        "Looks good to me.\nFrom the top, this is fine.\n",
    )
    add(
        [
            ("Subject", "Question about build"),
            ("From", "Carol <c@b.c>"),
            ("Message-ID", "<3@x>"),
            ("Date", "Wed, 03 Jan 2020 12:00:00 GMT"),
        ],
        "How do I build?\n",
    )
    add(
        [
            ("Subject", "=?utf-8?q?caf=C3=A9_patch_s=C3=A9rie?="),
            ("From", "=?utf-8?B?RMOpYm9yYWg=?= <d@b.c>"),
            ("Message-ID", "<4@x>"),
            ("Date", "Thu, 04 Jan 2020 06:07:08 +0200"),
        ],
        "Quoted printable café body with éèê.\n",
        cte="quoted-printable",
    )
    add(
        [
            ("Subject", "=?iso-8859-1?Q?Gr=FC=DFe?= and =?utf-8?B?4pyT?= done"),
            ("From", "Eve <e@b.c>"),
            ("Message-ID", "<5@x>"),
            ("Date", "Fri, 05 Jan 2020 09:10:11 +0000"),
        ],
        "Base64 body with ✓ check.\n",
        cte="base64",
    )
    add(
        [
            ("Subject", "<script>evil</script> & \"quotes\""),
            ("From", "<x@y>"),
            ("Message-ID", "<6@x>"),
            ("Date", "not a date at all"),
        ],
        "body <script>bad</script> here\n",
    )
    add(
        [
            ("Subject", "PATCH without brackets"),
            ("From", "Frank <f@b.c>"),
            ("Message-ID", "<7@x>"),
        ],
        "no date header at all\n",
    )
    box.flush()
    box.close()

    # A multipart/alternative reply and a folded-header message, appended raw so
    # their exact wire form is under test.
    extra = (
        b"From nobody Mon Jan  1 00:00:00 2020\n"
        b"Subject: Re: Question about build\n"
        b"From: Grace <g@b.c>\n"
        b"Message-ID: <8@x>\n"
        b"In-Reply-To: <3@x>\n"
        b"Date: Sat, 06 Jan 2020 01:02:03 +0100\n"
        b"MIME-Version: 1.0\n"
        b'Content-Type: multipart/alternative; boundary="BND1"\n'
        b"\n"
        b"This is the preamble.\n"
        b"--BND1\n"
        b"Content-Type: text/plain; charset=us-ascii\n"
        b"\n"
        b"the plain alternative\n"
        b"--BND1\n"
        b"Content-Type: text/html; charset=us-ascii\n"
        b"\n"
        b"<b>the html alternative</b>\n"
        b"--BND1--\n"
        b"the epilogue\n"
        b"\n"
        b"From nobody Mon Jan  1 00:00:00 2020\n"
        b"Subject: a very long subject that the\n"
        b" mailer folded across lines\n"
        b"From: Heidi <h@b.c>\n"
        b"X-Weird:    lots   of   space\n"
        b"Message-ID: <9@x>\n"
        b"Date: 7 Jan 2020 08:09:10 -0000\n"
        b"\n"
        b"@@ looks like a hunk header\n"
        b"Index: something\n"
        b"\n"
        # Quoted-printable corner cases: `==` (the "broken python qp" form that
        # emits one `=`), a soft line break, `=` before a bare CR, a trailing
        # `=`, and a non-hex escape.
        b"From nobody Mon Jan  1 00:00:00 2020\n"
        b"Subject: qp corners\n"
        b"From: Ivan <i@b.c>\n"
        b"Message-ID: <10@x>\n"
        b"Date: Wed, 08 Jan 2020 10:11:12 +0000\n"
        b"Content-Type: text/plain; charset=utf-8\n"
        b"Content-Transfer-Encoding: quoted-printable\n"
        b"\n"
        b"Q2FmZQ==\n"
        b"soft=\n"
        b"joined =3D done =Zb =g1 tail=\n"
        b"\n"
        # A `multipart/*` whose *close* boundary is the first one seen:
        # `StartBoundaryNotFoundDefect`, so the message stays non-multipart and
        # its payload is only the text before that boundary.
        b"From nobody Mon Jan  1 00:00:00 2020\n"
        b"Subject: only a close boundary\n"
        b"From: Judy <j@b.c>\n"
        b"Message-ID: <11@x>\n"
        b"Date: Thu, 09 Jan 2020 11:12:13 +0000\n"
        b'Content-Type: multipart/mixed; boundary="BND2"\n'
        b"\n"
        b"captured as the payload\n"
        b"--BND2--\n"
        b"discarded epilogue\n"
        b"\n"
        # A `multipart/*` subpart with no boundary of its own: the newline
        # before the parent's next boundary is removed from its *epilogue*
        # (which is `None`), so its payload keeps the newline.
        b"From nobody Mon Jan  1 00:00:00 2020\n"
        b"Subject: nested multipart without a boundary\n"
        b"From: Karl <k@b.c>\n"
        b"Message-ID: <12@x>\n"
        b"Date: Fri, 10 Jan 2020 12:13:14 +0000\n"
        b'Content-Type: multipart/mixed; boundary="BND3"\n'
        b"\n"
        b"--BND3\n"
        b"Content-Type: multipart/mixed\n"
        b"\n"
        b"inner text with no boundary\n"
        b"--BND3\n"
        b"Content-Type: text/plain; charset=us-ascii\n"
        b"\n"
        b"a normal part\n"
        b"--BND3--\n"
        b"\n"
        # A `multipart/*` content type with no boundary at all *and* an 8-bit
        # body: CPython's `as_bytes()` raises, and `read_archive` answers with
        # an empty `raw`.
        b"From nobody Mon Jan  1 00:00:00 2020\n"
        b"Subject: multipart without a boundary\n"
        b"From: Lena <l@b.c>\n"
        b"Message-ID: <13@x>\n"
        b"Date: Sat, 11 Jan 2020 13:14:15 +0000\n"
        b"Content-Type: multipart/mixed\n"
        b"\n"
        b"caf\xc3\xa9 raw 8-bit body\n"
        b"\n"
        # An unknown charset: every failure inside `_body_text` is swallowed, so
        # the body renders empty rather than being salvaged.
        b"From nobody Mon Jan  1 00:00:00 2020\n"
        b"Subject: unknown charset\n"
        b"From: Mo <m@b.c>\n"
        b"Message-ID: <14@x>\n"
        b"Date: Sun, 12 Jan 2020 14:15:16 +0000\n"
        b"Content-Type: text/plain; charset=bogus-nope\n"
        b"\n"
        b"this body is dropped\n"
        b"\n"
    )
    with open(path, "ab") as fh:
        fh.write(extra)


def gen_mailarchive() -> None:
    from gitweb import mailarchive

    tmp = tempfile.mkdtemp(prefix="gw-xcheck-")
    path = os.path.join(tmp, "repo.mbox")
    _build_mbox(path)
    with open(path, "rb") as fh:
        data = fh.read()

    print("// ==== mailarchive: the mbox fixture ====")
    print("    let mbox: &[u8] = %s;" % rb(data))

    msgs = mailarchive.read_archive(path)
    print("// ==== mailarchive::read_archive (subject, sender, ts, mid, irt, is_patch, body) ====")
    for m in msgs:
        print(
            "    (%s, %s, %d, %s, %s, %s, %s),"
            % (
                rs(m.subject),
                rs(m.sender),
                m.ts,
                rs(m.mid),
                rs(m.in_reply_to),
                str(m.is_patch).lower(),
                rs(m.body),
            )
        )
    print("// ==== mailarchive: Msg.raw (as_bytes) ====")
    for m in msgs:
        print("    %s," % rb(m.raw))

    print("// ==== mailarchive::normalize_subject / thread_id ====")
    subjects = [
        "[PATCH] fix the bug",
        "Re: [PATCH] fix the bug",
        "RE: re: [PATCH v2 3/7] fix the bug",
        "Fwd: [list] Re: something",
        "FW: plain",
        "  [a][b]  [c] tail  ",
        "no prefixes here",
        "",
        "Re:",
        "[unclosed bracket",
        "ÉLÉGANT Subject",
        "Re: Re: Re: nested",
        # The `.strip()` in `normalize_subject` runs on every pass, including
        # the one where the prefix pattern does not match at all.
        "  ",
        "  \t  ",
        " padded subject ",
        " Re: padded ",
        "\x1c",
        "\x1cRe: c0 separators\x1f",
    ]
    for s in subjects:
        print(
            "    (%s, %s, %s),"
            % (rs(s), rs(mailarchive.normalize_subject(s)), rs(mailarchive.thread_id(s)))
        )

    threads = mailarchive.group_threads(msgs)
    print("// ==== mailarchive::group_threads (id, subject, ts, n_msgs, mids) ====")
    for t in threads:
        print(
            "    (%s, %s, %d, %d, %s),"
            % (
                rs(t["id"]),
                rs(t["subject"]),
                t["ts"],
                len(t["msgs"]),
                rs(",".join(m.mid for m in t["msgs"])),
            )
        )

    print("// ==== mailarchive::render_list (configured) ====")
    print(
        "    let want_list = %s;"
        % rs(mailarchive.render_list("repo", threads, _u, configured=True))
    )
    print("// ==== mailarchive::render_list (unconfigured) ====")
    print(
        "    let want_unconfigured = %s;"
        % rs(mailarchive.render_list("repo", [], _u, configured=False))
    )
    print("// ==== mailarchive::render_list (configured, empty) ====")
    print(
        "    let want_empty = %s;"
        % rs(mailarchive.render_list("repo", [], _u, configured=True))
    )

    print("// ==== mailarchive::render_thread (per thread, in order) ====")
    for t in threads:
        print("    %s," % rs(mailarchive.render_thread("repo", t, _u)))

    print("// ==== mailarchive::thread_mbox (per thread, in order) ====")
    for t in threads:
        print("    %s," % rb(mailarchive.thread_mbox(t)))

    print("// ==== mailarchive: missing file / caps ====")
    print(
        "    missing_is_empty = %s;"
        % str(mailarchive.read_archive("/nonexistent/x.mbox") == []).lower()
    )
    print("    max_messages_2 = %d;" % len(mailarchive.read_archive(path, max_messages=2)))
    print("    MAX_MESSAGES = %d;" % mailarchive.MAX_MESSAGES)
    print("    MAX_BODY = %d;" % mailarchive.MAX_BODY)
    print("// ==== mailarchive::PATCH_CSS ====")
    print("    let want_css = %s;" % rs(mailarchive.PATCH_CSS))


SECTIONS = [
    gen_markup_escape,
    gen_markup_dates,
    gen_markup_markdown,
    gen_markup_diff,
    gen_auth,
    gen_metrics,
    gen_mailarchive,
]

if __name__ == "__main__":
    for section in SECTIONS:
        section()
