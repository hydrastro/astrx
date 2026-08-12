//! Cross-check: `gitweb::markup` is byte-identical to the Python
//! `gitweb.markup` — HTML/XML escaping (including the Atom noncharacter cases
//! that `tests/test_atom_noncharacters.py` pins), the date/relative-time
//! formatters, the minimal Markdown subset (headings, lists, task lists, tables,
//! fences, blockquotes, reference links, autolinks, emphasis, and hostile HTML
//! in every position), and the unified-diff parser.
//!
//! Goldens emitted by `tests/regen_goldens.py` (sections `gen_markup_escape`,
//! `gen_markup_dates`, `gen_markup_markdown`, `gen_markup_diff`). Beyond this
//! curated corpus the port was validated against the reference on ~5 000
//! randomly generated Markdown documents and ~2 000 random patches/timestamps.

use gitweb::markup::{
    atom_date, esc, iso_date, parse_patch, relative_date, render_markdown, render_readme,
    xml_escape, FileStatus, MAX_MARKDOWN_BYTES,
};

#[test]
fn escaping_matches_python() {
    let cases: &[(&str, &str, &str)] = &[
        ("", "", ""),
        ("plain text", "plain text", "plain text"),
        (
            "<script>alert(1)</script>",
            "&lt;script&gt;alert(1)&lt;/script&gt;",
            "&lt;script&gt;alert(1)&lt;/script&gt;",
        ),
        ("a & b", "a &amp; b", "a &amp; b"),
        (
            "\"quoted\" and 'single'",
            "&quot;quoted&quot; and &#x27;single&#x27;",
            "&quot;quoted&quot; and &#x27;single&#x27;",
        ),
        (
            "<a href=\"x\" onclick='y'>&amp;</a>",
            "&lt;a href=&quot;x&quot; onclick=&#x27;y&#x27;&gt;&amp;amp;&lt;/a&gt;",
            "&lt;a href=&quot;x&quot; onclick=&#x27;y&#x27;&gt;&amp;amp;&lt;/a&gt;",
        ),
        (
            "&<>\"'",
            "&amp;&lt;&gt;&quot;&#x27;",
            "&amp;&lt;&gt;&quot;&#x27;",
        ),
        ("café — naïve", "café — naïve", "café — naïve"),
        (
            "line1\nline2\ttabbed",
            "line1\nline2\ttabbed",
            "line1\nline2\ttabbed",
        ),
        ("fix￾bug", "fix￾bug", "fix�bug"),
        ("fix￿bug", "fix￿bug", "fix�bug"),
        ("fix\u{1}bug", "fix\u{1}bug", "fix�bug"),
        ("fix\u{0}bug", "fix\u{0}bug", "fix�bug"),
        ("a﷐b", "a﷐b", "a﷐b"),
        ("\u{8}\u{b}\u{c}\u{1f}", "\u{8}\u{b}\u{c}\u{1f}", "����"),
        (
            "tab\there\nnl\rcr",
            "tab\there\nnl\rcr",
            "tab\there\nnl\rcr",
        ),
        ("😀 emoji", "😀 emoji", "😀 emoji"),
        (
            "mixed ￾ & <b>\u{2}",
            "mixed ￾ &amp; &lt;b&gt;\u{2}",
            "mixed � &amp; &lt;b&gt;�",
        ),
    ];
    for (input, want_esc, want_xml) in cases {
        assert_eq!(&esc(input), want_esc, "esc({input:?})");
        assert_eq!(&xml_escape(input), want_xml, "xml_escape({input:?})");
    }
}

#[test]
fn relative_date_matches_python() {
    let cases: &[(i64, f64, &str)] = &[
        (1700000000, 1700000000.0, "just now"),
        (1699999999, 1700000000.0, "just now"),
        (1699999970, 1700000000.0, "just now"),
        (1699999941, 1700000000.0, "just now"),
        (1699999940, 1700000000.0, "1 minute ago"),
        (1699999939, 1700000000.0, "1 minute ago"),
        (1699999881, 1700000000.0, "1 minute ago"),
        (1699999880, 1700000000.0, "2 minutes ago"),
        (1699996401, 1700000000.0, "59 minutes ago"),
        (1699996400, 1700000000.0, "1 hour ago"),
        (1699996399, 1700000000.0, "1 hour ago"),
        (1699992800, 1700000000.0, "2 hours ago"),
        (1699913601, 1700000000.0, "23 hours ago"),
        (1699913600, 1700000000.0, "1 day ago"),
        (1699913599, 1700000000.0, "1 day ago"),
        (1699827200, 1700000000.0, "2 days ago"),
        (1699395201, 1700000000.0, "6 days ago"),
        (1699395200, 1700000000.0, "1 week ago"),
        (1699395199, 1700000000.0, "1 week ago"),
        (1698790400, 1700000000.0, "2 weeks ago"),
        (1697408001, 1700000000.0, "4 weeks ago"),
        (1697408000, 1700000000.0, "1 month ago"),
        (1697407999, 1700000000.0, "1 month ago"),
        (1694816000, 1700000000.0, "2 months ago"),
        (1668464001, 1700000000.0, "12 months ago"),
        (1668464000, 1700000000.0, "1 year ago"),
        (1668463999, 1700000000.0, "1 year ago"),
        (1636928000, 1700000000.0, "2 years ago"),
        (1384640000, 1700000000.0, "10 years ago"),
        (0, 1700000000.0, "unknown"),
        (1700005000, 1700000000.0, "just now"),
        (1, 1700000000.0, "53 years ago"),
    ];
    for (ts, now, want) in cases {
        assert_eq!(
            &relative_date(Some(*ts), Some(*now)),
            want,
            "relative_date({ts}, {now})"
        );
    }
    // `None` is the same falsy case as `Some(0)`.
    assert_eq!(relative_date(None, Some(1.0)), "unknown");
}

#[test]
fn iso_and_atom_date_match_python() {
    let cases: &[(i64, &str, &str)] = &[
        (0, "", "1970-01-01T00:00:00Z"),
        (1, "1970-01-01 00:00 UTC", "1970-01-01T00:00:01Z"),
        (59, "1970-01-01 00:00 UTC", "1970-01-01T00:00:59Z"),
        (60, "1970-01-01 00:01 UTC", "1970-01-01T00:01:00Z"),
        (3599, "1970-01-01 00:59 UTC", "1970-01-01T00:59:59Z"),
        (3600, "1970-01-01 01:00 UTC", "1970-01-01T01:00:00Z"),
        (86399, "1970-01-01 23:59 UTC", "1970-01-01T23:59:59Z"),
        (86400, "1970-01-02 00:00 UTC", "1970-01-02T00:00:00Z"),
        (604800, "1970-01-08 00:00 UTC", "1970-01-08T00:00:00Z"),
        (2592000, "1970-01-31 00:00 UTC", "1970-01-31T00:00:00Z"),
        (31536000, "1971-01-01 00:00 UTC", "1971-01-01T00:00:00Z"),
        (951782400, "2000-02-29 00:00 UTC", "2000-02-29T00:00:00Z"),
        (1000000000, "2001-09-09 01:46 UTC", "2001-09-09T01:46:40Z"),
        (1700000000, "2023-11-14 22:13 UTC", "2023-11-14T22:13:20Z"),
        (253402300799, "9999-12-31 23:59 UTC", "9999-12-31T23:59:59Z"),
        (-1, "1969-12-31 23:59 UTC", "1969-12-31T23:59:59Z"),
        (-86400, "1969-12-31 00:00 UTC", "1969-12-31T00:00:00Z"),
        (-62135596800, "1-01-01 00:00 UTC", "1-01-01T00:00:00Z"),
        (1583020800, "2020-03-01 00:00 UTC", "2020-03-01T00:00:00Z"),
        (1614556800, "2021-03-01 00:00 UTC", "2021-03-01T00:00:00Z"),
    ];
    for (ts, want_iso, want_atom) in cases {
        assert_eq!(&iso_date(Some(*ts)), want_iso, "iso_date({ts})");
        assert_eq!(&atom_date(Some(*ts)), want_atom, "atom_date({ts})");
    }
    assert_eq!(iso_date(None), "");
    assert_eq!(atom_date(None), "1970-01-01T00:00:00Z");
}

#[test]
fn render_markdown_matches_python() {
    let cases: &[(&str, &str)] = &[
    ("# Guide\n\n| Name | Value |\n| --- | --- |\n| alpha | 1 |\n| beta | 2 |\n\n![logo](logo.png)\n\nVisit https://autolink.example.com now.\n\n- [x] done task\n- [ ] pending task\n", "<h1>Guide</h1><table class=\"md-table\"><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>alpha</td><td>1</td></tr><tr><td>beta</td><td>2</td></tr></tbody></table><p><img src=\"logo.png\" alt=\"logo\"></p><p>Visit <a href=\"https://autolink.example.com\" rel=\"nofollow noopener\">https://autolink.example.com</a> now.</p><ul><li class=\"task\"><input type=\"checkbox\" disabled checked> done task</li><li class=\"task\"><input type=\"checkbox\" disabled> pending task</li></ul>"),
    ("Project Title\n=============\n\nA short **intro** paragraph with _emphasis_, `inline code`, a\n[link](https://example.com/a?b=1&c=2) and an ![image](/img/logo.png \"t\").\n\nSub Heading\n-----------\n\n## Features ##\n\n1. First item\n2. Second item\n   1. Nested ordered\n   2. Another\n3. Third\n\n* bullet one\n* bullet two\n  * nested bullet\n  * another nested\n+ switched marker\n\n- [ ] todo item\n- [X] done item\n\n| Left | Center | Right | Plain |\n|:-----|:------:|------:|-------|\n| a    | b      | c     | d     |\n| `x`  | **y**  | [z](/z) | <script>bad</script> |\n\n> A quote with a [ref link][site].\n>\n> > A nested quote with `code`.\n> > And a second line.\n\n```python\ndef f(x):\n    return x < 1 and \"<b>not html</b>\"\n```\n\n~~~\ntilde fenced <i>literal</i>\n~~~\n\nAutolinks: https://bare.example.com/path?q=1, and <https://angle.example.com/x>,\nand a trailing-punctuation one https://dot.example.com/end.\n\nHostile: <script>alert(1)</script> and <img src=x onerror=alert(1)> and\n[js](javascript:alert(1)) and ![js](JavaScript:alert(2)) and [d](data:text/html,x).\n\nHard break here\nnext line after break.\n\n[site]: https://example.org/site \"The Site\"\n[unused]: https://example.net/unused\n[evil]: javascript:alert(3)\n\nReference use: [good][site], [bad][evil], [missing][nope], [collapsed][].\n\nTrailing paragraph with a lone * star and _underscore_ and 2*3*4.\n", "<h1>Project Title</h1><p>A short <strong>intro</strong> paragraph with <em>emphasis</em>, <code>inline code</code>, a <a href=\"https://example.com/a?b=1&amp;c=2\" rel=\"nofollow noopener\">link</a> and an ![image](/img/logo.png &quot;t&quot;).</p><h2>Sub Heading</h2><h2>Features</h2><ol><li>First item</li><li>Second item<ol><li>Nested ordered</li><li>Another</li></ol></li><li>Third</li></ol><ul><li>bullet one</li><li>bullet two<ul><li>nested bullet</li><li>another nested</li></ul></li><li>switched marker</li></ul><ul><li class=\"task\"><input type=\"checkbox\" disabled> todo item</li><li class=\"task\"><input type=\"checkbox\" disabled checked> done item</li></ul><table class=\"md-table\"><thead><tr><th style=\"text-align:left\">Left</th><th style=\"text-align:center\">Center</th><th style=\"text-align:right\">Right</th><th>Plain</th></tr></thead><tbody><tr><td style=\"text-align:left\">a</td><td style=\"text-align:center\">b</td><td style=\"text-align:right\">c</td><td>d</td></tr><tr><td style=\"text-align:left\"><code>x</code></td><td style=\"text-align:center\"><strong>y</strong></td><td style=\"text-align:right\"><a href=\"/z\" rel=\"nofollow noopener\">z</a></td><td>&lt;script&gt;bad&lt;/script&gt;</td></tr></tbody></table><blockquote><p>A quote with a <a href=\"https://example.org/site\" rel=\"nofollow noopener\">ref link</a>.</p><blockquote><p>A nested quote with <code>code</code>. And a second line.</p></blockquote></blockquote><pre><code>def f(x):\n    return x &lt; 1 and &quot;&lt;b&gt;not html&lt;/b&gt;&quot;</code></pre><pre><code>tilde fenced &lt;i&gt;literal&lt;/i&gt;</code></pre><p>Autolinks: <a href=\"https://bare.example.com/path?q=1\" rel=\"nofollow noopener\">https://bare.example.com/path?q=1</a>, and <a href=\"https://angle.example.com/x\" rel=\"nofollow noopener\">https://angle.example.com/x</a>, and a trailing-punctuation one <a href=\"https://dot.example.com/end\" rel=\"nofollow noopener\">https://dot.example.com/end</a>.</p><p>Hostile: &lt;script&gt;alert(1)&lt;/script&gt; and &lt;img src=x onerror=alert(1)&gt; and [js](javascript:alert(1)) and ![js](JavaScript:alert(2)) and [d](data:text/html,x).</p><p>Hard break here next line after break.</p><p>Reference use: <a href=\"https://example.org/site\" rel=\"nofollow noopener\">good</a>, [bad][evil], [missing][nope], [collapsed][].</p><p>Trailing paragraph with a lone <em> star and <em>underscore</em> and 2</em>3*4.</p>"),
    ("", ""),
    ("\n", ""),
    ("# Hi", "<h1>Hi</h1>"),
    ("# Hi ##", "<h1>Hi</h1>"),
    ("# Hi #########   ", "<h1>Hi</h1>"),
    ("####### seven hashes x", "<p>####### seven hashes x</p>"),
    ("#no space", "<p>#no space</p>"),
    ("Title\n===\n", "<h1>Title</h1>"),
    ("Sub\n---\n", "<h2>Sub</h2>"),
    ("<script>evil</script>\n===\n", "<h1>&lt;script&gt;evil&lt;/script&gt;</h1>"),
    ("a  \nb\n", "<p>a<br>b</p>"),
    ("a\nb\n", "<p>a b</p>"),
    ("- a\n  - b\n  - c\n- d\n", "<ul><li>a<ul><li>b</li><li>c</li></ul></li><li>d</li></ul>"),
    ("- a\n- b\n1. c\n2. d\n", "<ul><li>a</li><li>b</li></ul><ol><li>c</li><li>d</li></ol>"),
    ("1. a\n2. b\n", "<ol><li>a</li><li>b</li></ol>"),
    ("1) a\n2) b\n", "<ol><li>a</li><li>b</li></ol>"),
    ("\t- tabbed item\n\t\t- deeper\n", "<ul><li>tabbed item<ul><li>deeper</li></ul></li></ul>"),
    ("> outer\n>\n> > inner\n", "<blockquote><p>outer</p><blockquote><p>inner</p></blockquote></blockquote>"),
    (">deep\n>>deeper\n>>>deepest\n", "<blockquote><p>deep</p><blockquote><p>deeper</p><blockquote><p>deepest</p></blockquote></blockquote></blockquote>"),
    ("> > > > > > > > > > too deep\n", "<blockquote><blockquote><blockquote><blockquote><blockquote><blockquote><blockquote><blockquote><blockquote><pre>&gt; too deep</pre></blockquote></blockquote></blockquote></blockquote></blockquote></blockquote></blockquote></blockquote></blockquote>"),
    ("![x](javascript:alert(1))\n\n[y](javascript:alert(2))\n\n<script>alert(3)</script>\n", "<p>![x](javascript:alert(1))</p><p>[y](javascript:alert(2))</p><p>&lt;script&gt;alert(3)&lt;/script&gt;</p>"),
    ("a `code` b\u{0}1\u{0} c\n", "<p>a <code>code</code> b1 c</p>"),
    ("x\u{0}99\u{0} y\n", "<p>x99 y</p>"),
    ("[![logo](/logo.png)](/home)", "<p><a href=\"/home\" rel=\"nofollow noopener\"><img src=\"/logo.png\" alt=\"logo\"></a></p>"),
    ("![a`b`c](/x.png)", "<p><img src=\"/x.png\" alt=\"a<code>b</code>c\"></p>"),
    ("[label](![alt](/i.png))", "<p>[label](<img src=\"/i.png\" alt=\"alt\">)</p>"),
    ("[a](`code`)", "<p>[a](<code>code</code>)</p>"),
    ("![outer](`code`)", "<p>![outer](<code>code</code>)</p>"),
    ("[![i](/p)](`c`)", "<p>[<img src=\"/p\" alt=\"i\">](<code>c</code>)</p>"),
    ("See [good][a] and [evil][b] and [x][c].\n\n[a]: https://example.com\n[b]: javascript:alert(1)\n", "<p>See <a href=\"https://example.com\" rel=\"nofollow noopener\">good</a> and [evil][b] and [x][c].</p>"),
    ("[A]: <https://example.com/angle>\n\nUse [text][a].\n", "<p>Use <a href=\"https://example.com/angle\" rel=\"nofollow noopener\">text</a>.</p>"),
    ("[dup]: https://first.example\n[dup]: https://second.example\n\n[t][dup]\n", "<p><a href=\"https://first.example\" rel=\"nofollow noopener\">t</a></p>"),
    ("<https://ang.example.com>\n", "<p><a href=\"https://ang.example.com\" rel=\"nofollow noopener\">https://ang.example.com</a></p>"),
    ("<https://ang.example.com/a>b<https://ang.example.com/c>\n", "<p><a href=\"https://ang.example.com/a\" rel=\"nofollow noopener\">https://ang.example.com/a</a>b<a href=\"https://ang.example.com/c\" rel=\"nofollow noopener\">https://ang.example.com/c</a></p>"),
    ("http://plain.example.com/x, then more.", "<p><a href=\"http://plain.example.com/x\" rel=\"nofollow noopener\">http://plain.example.com/x</a>, then more.</p>"),
    ("https://trail.example.com/a.b.c!?", "<p><a href=\"https://trail.example.com/a.b.c\" rel=\"nofollow noopener\">https://trail.example.com/a.b.c</a>!?</p>"),
    ("**bold** and __bold2__ and *em* and _em2_", "<p><strong>bold</strong> and <strong>bold2</strong> and <em>em</em> and <em>em2</em></p>"),
    ("**a*b**", "<p>**a*b**</p>"),
    ("a**b**c", "<p>a<strong>b</strong>c</p>"),
    ("snake_case_word and _real em_", "<p>snake_case_word and <em>real em</em></p>"),
    ("2*3*4 and x*y*z", "<p>2*3*4 and x*y*z</p>"),
    ("*multi\nline* no", "<p>*multi line* no</p>"),
    ("`unclosed code", "<p>`unclosed code</p>"),
    ("``", "<p>``</p>"),
    ("`a``b`", "<p><code>a</code><code>b</code></p>"),
    ("| h1 | h2 |\n| --- | --- |\n| a | b |\n", "<table class=\"md-table\"><thead><tr><th>h1</th><th>h2</th></tr></thead><tbody><tr><td>a</td><td>b</td></tr></tbody></table>"),
    ("| h1 | h2 |\n|:--|--:|\n| a |\n| a | b | c |\n", "<table class=\"md-table\"><thead><tr><th style=\"text-align:left\">h1</th><th style=\"text-align:right\">h2</th></tr></thead><tbody><tr><td style=\"text-align:left\">a</td><td style=\"text-align:right\"></td></tr><tr><td style=\"text-align:left\">a</td><td style=\"text-align:right\">b</td></tr></tbody></table>"),
    ("h1 | h2\n--- | ---\na | b\n", "<table class=\"md-table\"><thead><tr><th>h1</th><th>h2</th></tr></thead><tbody><tr><td>a</td><td>b</td></tr></tbody></table>"),
    ("not | a table\njust text\n", "<p>not | a table just text</p>"),
    ("```\nunclosed fence\n", "<pre><code>unclosed fence\n</code></pre>"),
    ("```js\ncode `tick` <b>\n```\nafter\n", "<pre><code>code `tick` &lt;b&gt;</code></pre><p>after</p>"),
    ("  #x", "<p>#x</p>"),
    ("  ####### x", "<p>####### x</p>"),
    ("* * *\n", "<ul><li><em> </em></li></ul>"),
    ("- \n", "<ul><li></li></ul>"),
    ("-  spaced   content\n", "<ul><li>spaced   content</li></ul>"),
    ("[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[", "<p>[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[</p>"),
    ("[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[]", "<p>[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[]</p>"),
    ("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\nbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb", "<p>aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb</p>"),
    ("line one\r\nline two\rline three\n", "<p>line one line two line three</p>"),
    ("éè café **gras** `codeé`\n", "<p>éè café <strong>gras</strong> <code>codeé</code></p>"),
    ("[é](/café)\n", "<p><a href=\"/café\" rel=\"nofollow noopener\">é</a></p>"),
    ];
    for (src, want) in cases {
        assert_eq!(&render_markdown(src), want, "render_markdown({src:?})");
    }
}

#[test]
fn render_readme_matches_python() {
    let cases: &[(&str, bool, &str)] = &[
        ("# Title\n\ntext\n", true, "<h1>Title</h1><p>text</p>"),
        ("# Title\n\ntext\n", false, "<pre># Title\n\ntext\n</pre>"),
        (
            "<script>x</script>",
            false,
            "<pre>&lt;script&gt;x&lt;/script&gt;</pre>",
        ),
        ("", true, ""),
        ("", false, "<pre></pre>"),
    ];
    for (src, is_md, want) in cases {
        assert_eq!(&render_readme(src, *is_md), want, "render_readme({src:?})");
    }
}

#[test]
fn oversized_markdown_falls_back_to_pre() {
    let big = "[".repeat(MAX_MARKDOWN_BYTES + 1);
    let out = render_markdown(&big);
    assert!(out.starts_with("<pre>"));
    assert!(out.ends_with("</pre>"));
    assert!(!out.contains('\0'));
    // Exactly at the cap the document is still parsed (the check is `>`).
    let at_cap = "[".repeat(MAX_MARKDOWN_BYTES);
    let want_at_cap_parsed: bool = true; // golden: `at_cap_is_parsed`
    assert_eq!(
        !render_markdown(&at_cap).starts_with("<pre>["),
        want_at_cap_parsed
    );
    // The cap counts *code points*, matching Python's `len(str)`.
    let wide = "é".repeat(MAX_MARKDOWN_BYTES);
    assert!(!render_markdown(&wide).starts_with("<pre>"));
}

/// Documented divergence: `render_markdown("  # x")` never returns in the
/// Python reference (see the note in `markup.rs`). Here it terminates, and the
/// content is preserved as a paragraph.
#[test]
fn indented_atx_heading_terminates() {
    assert_eq!(render_markdown("  # x"), "<p># x</p>");
    assert_eq!(
        render_markdown("text\n  # x\nmore\n"),
        "<p>text</p><p># x</p><p>more</p>"
    );
    // The neighbouring forms all terminate in Python too, and match exactly.
    assert_eq!(render_markdown("  #x"), "<p>#x</p>");
    assert_eq!(render_markdown("  # x\n===\n"), "<h1># x</h1>");
}

#[test]
fn parse_patch_matches_python() {
    let patch = "commit deadbeef\nAuthor: A U Thor <a@example.com>\nDate:   Mon Jan 1 00:00:00 2020 +0000\n\n    subject line\n\ndiff --git a/src/keep.txt b/src/keep.txt\nindex 1111111..2222222 100644\n--- a/src/keep.txt\n+++ b/src/keep.txt\n@@ -1,4 +1,4 @@\n context one\n-removed line\n+added line\n context two\n\\ No newline at end of file\ndiff --git a/new/file.txt b/new/file.txt\nnew file mode 100644\nindex 0000000..3333333\n--- /dev/null\n+++ b/new/file.txt\n@@ -0,0 +1,2 @@\n+first\n+second\ndiff --git a/old/gone.txt b/old/gone.txt\ndeleted file mode 100644\nindex 4444444..0000000\n--- a/old/gone.txt\n+++ /dev/null\n@@ -1,1 +0,0 @@\n-bye\ndiff --git a/a/from.txt b/b/to.txt\nsimilarity index 92%\nrename from a/from.txt\nrename to b/to.txt\nindex 5555555..6666666 100644\n--- a/a/from.txt\n+++ b/b/to.txt\n@@ -1 +1 @@\n-old name\n+new name\ndiff --git a/img/logo.png b/img/logo.png\nindex 7777777..8888888 100644\nBinary files a/img/logo.png and b/img/logo.png differ\ndiff --git a/bin/blob.dat b/bin/blob.dat\nnew file mode 100644\nindex 0000000..9999999\nGIT binary patch\nliteral 12\nabcdefgh\ndiff --git a/tricky b/name b/other b/tricky b/name b/final\nindex aaaaaaa..bbbbbbb 100644\n--- a/tricky b/name b/other\n+++ b/tricky b/name b/final\n@@ -1 +1 @@\n-x\n+y\ndiff --git a/same.txt b/same.txt\ndissimilarity index 5%\nrename from same.txt\nrename to same.txt\n";
    // (old_path, new_path, status, binary, additions, deletions, display_path, n_lines)
    type WantFile = (
        &'static str,
        &'static str,
        &'static str,
        bool,
        usize,
        usize,
        &'static str,
        usize,
    );
    let want_files: &[WantFile] = &[
        (
            "src/keep.txt",
            "src/keep.txt",
            "modified",
            false,
            1,
            1,
            "src/keep.txt",
            6,
        ),
        (
            "new/file.txt",
            "new/file.txt",
            "added",
            false,
            2,
            0,
            "new/file.txt",
            3,
        ),
        (
            "old/gone.txt",
            "old/gone.txt",
            "deleted",
            false,
            0,
            1,
            "old/gone.txt",
            2,
        ),
        (
            "a/from.txt",
            "b/to.txt",
            "renamed",
            false,
            1,
            1,
            "a/from.txt -> b/to.txt",
            3,
        ),
        (
            "img/logo.png",
            "img/logo.png",
            "binary",
            true,
            0,
            0,
            "img/logo.png",
            0,
        ),
        (
            "bin/blob.dat",
            "bin/blob.dat",
            "binary",
            true,
            0,
            0,
            "bin/blob.dat",
            2,
        ),
        (
            "tricky b/name b/other b/tricky b/name",
            "final",
            "modified",
            false,
            1,
            1,
            "final",
            3,
        ),
        (
            "same.txt", "same.txt", "renamed", false, 0, 0, "same.txt", 1,
        ),
    ];
    let want_lines: &[(usize, &str, &str)] = &[
        (0, "hunk", "@@ -1,4 +1,4 @@"),
        (0, "ctx", " context one"),
        (0, "del", "-removed line"),
        (0, "add", "+added line"),
        (0, "ctx", " context two"),
        (0, "meta", "\\ No newline at end of file"),
        (1, "hunk", "@@ -0,0 +1,2 @@"),
        (1, "add", "+first"),
        (1, "add", "+second"),
        (2, "hunk", "@@ -1,1 +0,0 @@"),
        (2, "del", "-bye"),
        (3, "hunk", "@@ -1 +1 @@"),
        (3, "del", "-old name"),
        (3, "add", "+new name"),
        (5, "meta", "literal 12"),
        (5, "meta", "abcdefgh"),
        (6, "hunk", "@@ -1 +1 @@"),
        (6, "del", "-x"),
        (6, "add", "+y"),
        (7, "meta", ""),
    ];

    let files = parse_patch(patch);
    assert_eq!(files.len(), want_files.len(), "file count");
    for (i, (old, new, status, binary, adds, dels, display, n_lines)) in
        want_files.iter().enumerate()
    {
        let f = &files[i];
        assert_eq!(&f.old_path, old, "old_path[{i}]");
        assert_eq!(&f.new_path, new, "new_path[{i}]");
        assert_eq!(f.status.as_str(), *status, "status[{i}]");
        assert_eq!(f.binary, *binary, "binary[{i}]");
        assert_eq!(f.additions, *adds, "additions[{i}]");
        assert_eq!(f.deletions, *dels, "deletions[{i}]");
        assert_eq!(&f.display_path(), display, "display_path[{i}]");
        assert_eq!(f.lines.len(), *n_lines, "line count[{i}]");
    }
    let got_lines: Vec<(usize, &str, &str)> = files
        .iter()
        .enumerate()
        .flat_map(|(i, f)| {
            f.lines
                .iter()
                .map(move |l| (i, l.kind.as_str(), l.text.as_str()))
        })
        .collect();
    assert_eq!(got_lines.len(), want_lines.len(), "total line count");
    for (got, want) in got_lines.iter().zip(want_lines.iter()) {
        assert_eq!(got, want);
    }
}

#[test]
fn parse_patch_degenerate_inputs_match_python() {
    let cases: &[(&str, usize, &str)] = &[
        ("", 0, ""),
        ("no diff here\n", 0, ""),
        ("diff --git a/x b/y\n+orphan\n", 1, "x:y:modified"),
        ("+lonely\n", 0, ""),
    ];
    for (src, n, summary) in cases {
        let files = parse_patch(src);
        assert_eq!(files.len(), *n, "file count for {src:?}");
        let got = files
            .iter()
            .map(|f| format!("{}:{}:{}", f.old_path, f.new_path, f.status.as_str()))
            .collect::<Vec<_>>()
            .join("|");
        assert_eq!(&got, summary, "summary for {src:?}");
    }
    assert_eq!(FileStatus::default(), FileStatus::Modified);
}
