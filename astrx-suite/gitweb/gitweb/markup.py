"""HTML-safe rendering helpers: escaping, dates, minimal Markdown, diff parsing.

The guiding rule for anything in this module is *escape first*.  Every function
that turns untrusted repository content into HTML runs it through
:func:`html.escape` before applying any structure, so a value can never inject
markup.  The Markdown renderer only re-introduces a fixed, safe subset of tags
onto already-escaped text.
"""

from __future__ import annotations

import html
import re
import time
from dataclasses import dataclass, field
from typing import List, Optional


def esc(value) -> str:
    """HTML-escape *value* (quotes included) for safe placement anywhere."""
    return html.escape("" if value is None else str(value), quote=True)


#: C0 control characters that are *illegal* in XML 1.0 even as numeric
#: references (everything below U+0020 except TAB/LF/CR).  ``html.escape`` does
#: not touch them, so a single such byte in a commit subject/author/email would
#: make an entire Atom feed non-well-formed; :func:`xml_escape` drops them.
_XML_INVALID_RE = re.compile("[\x00-\x08\x0b\x0c\x0e-\x1f￾￿]")


def xml_escape(value) -> str:
    """Escape *value* for XML text/attributes, dropping XML-illegal controls.

    Identical to :func:`esc` but first replaces the C0 control characters that
    XML 1.0 forbids with U+FFFD, so repository-derived text (which git preserves
    verbatim) can never break feed well-formedness.
    """
    text = "" if value is None else str(value)
    return esc(_XML_INVALID_RE.sub("�", text))


# --------------------------------------------------------------------------- #
# Dates
# --------------------------------------------------------------------------- #


def relative_date(ts: Optional[int], now: Optional[float] = None) -> str:
    """Human "3 days ago" style string from a unix timestamp."""
    if not ts:
        return "unknown"
    now = time.time() if now is None else now
    delta = now - ts
    if delta < 0:
        delta = 0
    units = (
        (31536000, "year"),
        (2592000, "month"),
        (604800, "week"),
        (86400, "day"),
        (3600, "hour"),
        (60, "minute"),
    )
    for secs, label in units:
        if delta >= secs:
            n = int(delta // secs)
            return f"{n} {label}{'s' if n != 1 else ''} ago"
    return "just now"


def iso_date(ts: Optional[int]) -> str:
    if not ts:
        return ""
    return time.strftime("%Y-%m-%d %H:%M", time.gmtime(ts)) + " UTC"


def atom_date(ts: Optional[int]) -> str:
    """RFC 3339 / Atom timestamp (UTC) from a unix timestamp."""
    if not ts:
        ts = 0
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(ts))


# --------------------------------------------------------------------------- #
# Minimal, safe Markdown
# --------------------------------------------------------------------------- #

#: Upper bound on a document handed to :func:`render_markdown`.  Above this we
#: fall back to an escaped ``<pre>`` (no inline parsing) so a hostile blob or
#: README can never drive the renderer into a large amount of work regardless of
#: how the per-pattern constants below behave.
MAX_MARKDOWN_BYTES = 256 * 1024
#: Longest span an inline construct (link/image label or URL, code span, angle
#: autolink) may cover.  Every such body is bounded to ``{…,N}`` so a *failed*
#: match attempt costs O(N) rather than O(remaining); that turns what used to be
#: O(n^2) backtracking on a long run of ``[`` / ``![`` / ``<http://`` into O(n).
#: A construct longer than this simply degrades to literal text (rare, safe).
_MD_MAX_SPAN = 512

_INLINE_CODE_RE = re.compile(r"`([^`]{1,%d})`" % _MD_MAX_SPAN)
# Operates on already-escaped text, so we match the escaped forms of the
# delimiters where relevant.  Images: ![alt](url)  Links: [text](url).  Bracket
# and URL bodies are length-bounded (``_MD_MAX_SPAN``) to keep matching linear.
_IMAGE_RE = re.compile(r"!\[([^\]]{0,%d})\]\(([^)\s]{1,%d})\)" % (_MD_MAX_SPAN, _MD_MAX_SPAN))
_LINK_RE = re.compile(r"\[([^\]]{1,%d})\]\(([^)\s]{1,%d})\)" % (_MD_MAX_SPAN, _MD_MAX_SPAN))
#: Reference-style link ``[text][id]`` / collapsed ``[text][]`` (resolved against
#: the ``[id]: url`` definitions collected up front).
_REF_FULL_RE = re.compile(r"\[([^\]]{1,%d})\]\[([^\]]{0,%d})\]" % (_MD_MAX_SPAN, _MD_MAX_SPAN))
#: A reference definition line ``[id]: url "optional title"`` (url is captured
#: verbatim and later escaped + scheme-checked; the title, if any, is ignored).
_REF_DEF_RE = re.compile(r'^\s{0,3}\[([^\]]{1,%d})\]:\s*(\S+)(?:\s+.*)?$' % _MD_MAX_SPAN)
#: Angle autolink ``<https://…>``.  The angle brackets are already HTML-escaped
#: (escape-first) by the time inline runs, so we match their escaped forms.  The
#: body is length-bounded so the non-greedy scan for the closing ``&gt;`` cannot
#: go quadratic on a long run of ``&lt;http://`` with no terminator.
_ANGLE_AUTOLINK_RE = re.compile(r"&lt;(https?://[^\s<>]{1,%d}?)&gt;" % _MD_MAX_SPAN)
_AUTOLINK_RE = re.compile(r"https?://[^\s<>()\[\]]+")
_BOLD_RE = re.compile(r"\*\*([^*]+)\*\*")
_BOLD2_RE = re.compile(r"__([^_]+)__")
_ITALIC_RE = re.compile(r"(?<![*\w])\*([^*\n]+)\*(?!\*)")
_ITALIC2_RE = re.compile(r"(?<![_\w])_([^_\n]+)_(?!_)")

_SAFE_URL_RE = re.compile(r"^(https?:|mailto:|/|#|[^:]*$)", re.IGNORECASE)


def _safe_url(url: str) -> Optional[str]:
    """Return ``url`` if its scheme is safe, else ``None``.

    ``url`` is already HTML-escaped.  We allow http/https/mailto, root-relative
    and fragment links, and scheme-less relative links; everything else
    (notably ``javascript:``) is rejected.
    """
    probe = url.strip()
    if not probe:
        return None
    # A ``\x00`` here is one of our placeholder sentinels (input NULs are stripped
    # at _inline entry) that got captured *as a URL* — e.g. an image or code span
    # nested in a link's ``(...)``.  It must never become an ``href``/``src``,
    # because on restore its content (which contains literal ``"``) would break
    # out of the attribute.  Refuse it; the construct falls back to literal text.
    if "\x00" in url:
        return None
    if probe.lower().startswith("javascript:") or probe.lower().startswith("data:"):
        return None
    if _SAFE_URL_RE.match(probe):
        return url
    return None


def _inline(text: str, refs: Optional[dict] = None) -> str:
    """Apply inline Markdown to a line of *already HTML-escaped* text.

    Code spans, images and links (inline, reference-style and angle/bare
    autolinks) are stashed as opaque placeholders before the remaining
    transforms run, so autolinking/emphasis can never reach inside a generated
    ``href``/``src`` or a code span.  Everything emitted is built from
    already-escaped fragments, so no transform can introduce live markup; every
    URL still passes the :func:`_safe_url` scheme allow-list.
    """
    refs = refs or {}
    # NUL is our placeholder sentinel; strip any that came from blob content so
    # a repo-controlled ``\x00<digits>\x00`` sequence cannot collide with it.
    text = text.replace("\x00", "")
    placeholders: List[str] = []

    def stash(html: str) -> str:
        placeholders.append(html)
        return f"\x00{len(placeholders) - 1}\x00"

    # Inline code first so its contents are not further transformed.
    if "`" in text:
        text = _INLINE_CODE_RE.sub(lambda m: stash(f"<code>{m.group(1)}</code>"), text)

    def _img(m: re.Match) -> str:
        alt, url = m.group(1), m.group(2)
        safe = _safe_url(url)
        if safe is None:
            return m.group(0)
        return stash(f'<img src="{safe}" alt="{alt}">')

    def _link(m: re.Match) -> str:
        label, url = m.group(1), m.group(2)
        safe = _safe_url(url)
        if safe is None:
            return m.group(0)
        return stash(f'<a href="{safe}" rel="nofollow noopener">{label}</a>')

    def _ref(m: re.Match) -> str:
        label = m.group(1)
        rid = (m.group(2).strip() or label).lower()
        entry = refs.get(rid)
        if not entry:
            return m.group(0)  # undefined reference: leave the literal text
        safe = _safe_url(entry[0])
        if safe is None:
            return m.group(0)
        return stash(f'<a href="{safe}" rel="nofollow noopener">{label}</a>')

    # Every bracket construct needs a closing ``]``.  Skipping the three subs
    # when none is present makes a long run of ``[`` / ``![`` (which can never
    # match) O(n) instead of paying the bounded-but-nonzero per-position cost.
    if "]" in text:
        text = _IMAGE_RE.sub(_img, text)
        text = _LINK_RE.sub(_link, text)
        text = _REF_FULL_RE.sub(_ref, text)

    def _angle(m: re.Match) -> str:
        url = m.group(1)  # already escaped; scheme is http(s) by construction
        if "\x00" in url:  # a stashed placeholder captured as a URL — not linkable
            return m.group(0)
        return stash(f'<a href="{url}" rel="nofollow noopener">{url}</a>')

    # An angle autolink needs the escaped closing bracket; skip otherwise.
    if "&gt;" in text:
        text = _ANGLE_AUTOLINK_RE.sub(_angle, text)

    def _auto(m: re.Match) -> str:
        url = m.group(0)
        if "\x00" in url:  # a stashed placeholder captured as a URL — not linkable
            return m.group(0)
        trail = ""
        while url and url[-1] in ".,;:!?":
            trail = url[-1] + trail
            url = url[:-1]
        if not url:
            return m.group(0)
        return stash(f'<a href="{url}" rel="nofollow noopener">{url}</a>') + trail

    text = _AUTOLINK_RE.sub(_auto, text)

    text = _BOLD_RE.sub(r"<strong>\1</strong>", text)
    text = _BOLD2_RE.sub(r"<strong>\1</strong>", text)
    text = _ITALIC_RE.sub(r"<em>\1</em>", text)
    text = _ITALIC2_RE.sub(r"<em>\1</em>", text)

    # Restore stashed spans (an out-of-range sentinel is neutralised, not fatal).
    # A stashed fragment can itself contain another sentinel (an image nested
    # inside a link, or a code span inside an image's ``alt``), so a single
    # ``re.sub`` pass would leave the inner ``\x00<idx>\x00`` as literal NUL
    # bytes in the output.  Expand repeatedly until none remain — a stashed
    # fragment only ever references *earlier* placeholders, so this terminates in
    # at most ``len(placeholders)`` passes.
    def _unstash(m: re.Match) -> str:
        idx = int(m.group(1))
        return placeholders[idx] if idx < len(placeholders) else ""

    for _ in range(len(placeholders) + 1):
        if "\x00" not in text:
            break
        text = re.sub(r"\x00(\d+)\x00", _unstash, text)
    return text


_TABLE_SEP_CELL_RE = re.compile(r"^:?-+:?$")


def _split_table_row(line: str) -> List[str]:
    """Split a pipe-table row into trimmed cells (ignoring outer pipes)."""
    line = line.strip()
    if line.startswith("|"):
        line = line[1:]
    if line.endswith("|"):
        line = line[:-1]
    return [c.strip() for c in line.split("|")]


def _is_table_separator(line: str) -> bool:
    if "|" not in line and "-" not in line:
        return False
    cells = _split_table_row(line)
    return bool(cells) and all(_TABLE_SEP_CELL_RE.match(c) for c in cells if c != "") and any(
        "-" in c for c in cells
    )


def _render_table(lines: List[str], i: int, n: int, refs: Optional[dict] = None):
    """Parse a GitHub pipe table starting at ``lines[i]``; return (html, new_i)."""
    header = _split_table_row(lines[i])
    seps = _split_table_row(lines[i + 1])
    aligns: List[str] = []
    for cell in seps:
        left = cell.startswith(":")
        right = cell.endswith(":")
        if left and right:
            aligns.append("center")
        elif right:
            aligns.append("right")
        elif left:
            aligns.append("left")
        else:
            aligns.append("")
    out = ['<table class="md-table"><thead><tr>']
    for idx, cell in enumerate(header):
        align = aligns[idx] if idx < len(aligns) else ""
        style = f' style="text-align:{align}"' if align else ""
        out.append(f"<th{style}>{_inline(esc(cell), refs)}</th>")
    out.append("</tr></thead><tbody>")
    j = i + 2
    while j < n and "|" in lines[j] and lines[j].strip():
        row = _split_table_row(lines[j])
        out.append("<tr>")
        for idx in range(len(header)):
            cell = row[idx] if idx < len(row) else ""
            align = aligns[idx] if idx < len(aligns) else ""
            style = f' style="text-align:{align}"' if align else ""
            out.append(f"<td{style}>{_inline(esc(cell), refs)}</td>")
        out.append("</tr>")
        j += 1
    out.append("</tbody></table>")
    return "".join(out), j


_TASK_RE = re.compile(r"^\[([ xX])\]\s+(.*)$")


def _list_item_parts(content: str, refs: Optional[dict] = None):
    """Return ``(li_attrs, inner_html)`` for one list item (task-list aware).

    The ``<li>`` wrapper is emitted by the caller (so a nested list can be
    placed *inside* the still-open item), hence we return the attributes and the
    inner HTML separately rather than a closed ``<li>…</li>``.
    """
    task = _TASK_RE.match(content)
    if task:
        checked = " checked" if task.group(1) in "xX" else ""
        # A *disabled* checkbox: it never submits (our CSP forbids that anyway).
        return (
            ' class="task"',
            f'<input type="checkbox" disabled{checked}> '
            f"{_inline(esc(task.group(2)), refs)}",
        )
    return "", _inline(esc(content), refs)


def _is_setext_underline(s: str, char: str) -> bool:
    """True if ``s`` (stripped) is a run of only ``char`` (a setext underline)."""
    s = s.strip()
    return bool(s) and set(s) == {char}


def _strip_atx_close(text: str) -> str:
    """Strip an ATX heading's optional trailing ``\\s*#*\\s*`` closing sequence.

    Pure string ops (always linear) reproducing exactly what the old
    ``(.*?)\\s*#*\\s*$`` capture removed: drop trailing whitespace, then a run of
    ``#``, then the whitespace that preceded them.
    """
    t = text.rstrip()
    t2 = t.rstrip("#")
    if t2 != t:  # there was a run of trailing '#'
        t = t2.rstrip()
    return t


def render_markdown(source: str, refs: Optional[dict] = None, _depth: int = 0) -> str:
    """Render a *tiny* safe subset of Markdown to HTML (closer to CommonMark).

    Supported: ATX **and** setext (``===``/``---``) headings, fenced code
    blocks, nested unordered/ordered lists (including ``[ ]``/``[x]`` task
    lists), GitHub pipe tables, multi-line and nested blockquotes, paragraphs
    with hard line breaks (two trailing spaces), reference-style links
    (``[text][id]`` + ``[id]: url``), and the inline set handled by
    :func:`_inline` (bold/italic, inline code, inline/reference links, images,
    and bare/angle ``<https://…>`` autolinks).  Everything is HTML-escaped
    before any structure is applied and every URL passes the :func:`_safe_url`
    allow-list, so no raw HTML is ever passed through.  Parsing is single-pass
    and linear (no backtracking regexes), and blockquote recursion is depth
    bounded, so it cannot be driven into pathological time.
    """
    if _depth > 8:  # bound nested-blockquote recursion
        return "<pre>" + esc(source) + "</pre>"

    # Hard size cap: above this, skip inline parsing entirely and serve the
    # source as one escaped ``<pre>`` block.  Combined with the length-bounded
    # inline patterns this makes render time strictly linear and small for any
    # input the endpoints can hand us (README <= 512 KiB, blob <= max_blob_bytes).
    if len(source) > MAX_MARKDOWN_BYTES:
        return "<pre>" + esc(source) + "</pre>"

    lines = source.replace("\r\n", "\n").replace("\r", "\n").split("\n")

    # -- pass 1: collect reference definitions outside code fences ---------- #
    refs = dict(refs or {})
    ref_def_lines: set = set()
    fenced = False
    for idx, ln in enumerate(lines):
        if re.match(r"^\s*(```+|~~~+)", ln):
            fenced = not fenced
            continue
        if fenced:
            continue
        md = _REF_DEF_RE.match(ln)
        if md:
            url = md.group(2)
            if url.startswith("<") and url.endswith(">"):
                url = url[1:-1]
            # Store the escaped URL so it can be dropped straight into an href.
            refs.setdefault(md.group(1).strip().lower(), (esc(url), ""))
            ref_def_lines.add(idx)
    if ref_def_lines:
        lines = [ln for j, ln in enumerate(lines) if j not in ref_def_lines]

    out: List[str] = []
    i = 0
    n = len(lines)

    # A stack of open lists: each entry is {"type": "ul"|"ol", "indent": int}.
    # The last <li> of each open list is left *open* so a nested list can be
    # emitted inside it; items/lists are closed as indentation decreases.
    list_stack: List[dict] = []

    def close_lists() -> None:
        while list_stack:
            top = list_stack.pop()
            out.append(f"</li></{top['type']}>")

    def start_item(indent: int, typ: str, content: str) -> None:
        # Close any deeper lists; the parent item that contained a nested list
        # is closed by the sibling branch below (or by close_lists at the end).
        while list_stack and list_stack[-1]["indent"] > indent:
            top = list_stack.pop()
            out.append(f"</li></{top['type']}>")
        if list_stack and list_stack[-1]["indent"] == indent:
            if list_stack[-1]["type"] != typ:  # marker changed at this level
                top = list_stack.pop()
                out.append(f"</li></{top['type']}><{typ}>")
                list_stack.append({"type": typ, "indent": indent})
            else:
                out.append("</li>")  # close the previous sibling item
        else:  # deeper (or the very first) list: open a new one
            out.append(f"<{typ}>")
            list_stack.append({"type": typ, "indent": indent})
        attrs, inner = _list_item_parts(content, refs)
        out.append(f"<li{attrs}>{inner}")  # left open

    while i < n:
        line = lines[i]

        # Fenced code block.
        fence = re.match(r"^\s*(```+|~~~+)(.*)$", line)
        if fence:
            close_lists()
            i += 1
            buf: List[str] = []
            while i < n and not re.match(r"^\s*(```+|~~~+)\s*$", lines[i]):
                buf.append(esc(lines[i]))
                i += 1
            i += 1  # consume closing fence (if present)
            out.append("<pre><code>" + "\n".join(buf) + "</code></pre>")
            continue

        # ATX heading.  The content is captured greedily (linear) and the
        # optional trailing ``\s*#*\s*`` closing sequence is stripped in Python;
        # a regex for that strip (``(.*?)\s*#*\s*$``) backtracks quadratically on
        # a line like ``# ` + `#`*n``, so we avoid it.
        heading = re.match(r"^(#{1,6})\s+(.*)$", line)
        if heading:
            close_lists()
            level = len(heading.group(1))
            htext = _strip_atx_close(heading.group(2))
            out.append(f"<h{level}>{_inline(esc(htext), refs)}</h{level}>")
            i += 1
            continue

        # GitHub pipe table (header row followed by a separator row).
        if (
            "|" in line
            and line.strip()
            and i + 1 < n
            and _is_table_separator(lines[i + 1])
        ):
            close_lists()
            html, i = _render_table(lines, i, n, refs)
            out.append(html)
            continue

        # List item (unordered/ordered, with indentation-based nesting).
        lm = re.match(r"^(\s*)([-*+]|\d+[.)])\s+(.*)$", line)
        if lm:
            indent = len(lm.group(1).expandtabs(4))
            typ = "ol" if lm.group(2)[0].isdigit() else "ul"
            start_item(indent, typ, lm.group(3))
            i += 1
            continue

        # Blockquote: gather consecutive '>' lines and render them recursively
        # (so nested '>>' and block elements inside a quote work).
        if re.match(r"^\s*>\s?(.*)$", line):
            close_lists()
            buf = []
            while i < n:
                m = re.match(r"^\s*>\s?(.*)$", lines[i])
                if m is None:
                    break
                buf.append(m.group(1))
                i += 1
            inner = render_markdown("\n".join(buf), refs=refs, _depth=_depth + 1)
            out.append(f"<blockquote>{inner}</blockquote>")
            continue

        # Blank line.
        if line.strip() == "":
            close_lists()
            i += 1
            continue

        # Setext heading: a single text line underlined by '===' or '---'.
        if i + 1 < n and _is_setext_underline(lines[i + 1], "="):
            close_lists()
            out.append(f"<h1>{_inline(esc(line.strip()), refs)}</h1>")
            i += 2
            continue
        if i + 1 < n and _is_setext_underline(lines[i + 1], "-"):
            close_lists()
            out.append(f"<h2>{_inline(esc(line.strip()), refs)}</h2>")
            i += 2
            continue

        # Paragraph (consecutive non-blank, non-structural lines).  A soft break
        # joins with a space; a hard break (two trailing spaces) emits <br>.
        close_lists()
        para: List[str] = []
        while i < n and lines[i].strip() != "" and not re.match(
            r"^\s*(#{1,6}\s|```|~~~|[-*+]\s|\d+[.)]\s|>\s?)", lines[i]
        ):
            # Stop before a setext underline so it can retitle the paragraph
            # above (handled on the next loop turn only for a single-line para).
            if para and (
                _is_setext_underline(lines[i], "=") or _is_setext_underline(lines[i], "-")
            ):
                break
            para.append(lines[i])
            i += 1
        pieces: List[str] = []
        for k, raw in enumerate(para):
            rendered = _inline(esc(raw.strip()), refs)
            if k < len(para) - 1:
                rendered += "<br>" if raw.endswith("  ") else " "
            pieces.append(rendered)
        out.append("<p>" + "".join(pieces) + "</p>")

    close_lists()
    return "".join(out)


def render_readme(source: str, is_markdown: bool) -> str:
    """Render a README: Markdown subset when appropriate, else escaped <pre>."""
    if is_markdown:
        try:
            return render_markdown(source)
        except Exception:
            pass  # fall through to the always-safe representation
    return "<pre>" + esc(source) + "</pre>"


# --------------------------------------------------------------------------- #
# Optional syntax highlighting (Pygments) with an escaped-plaintext fallback
# --------------------------------------------------------------------------- #


def highlight_source(text: str, filename: str) -> Optional[List[str]]:
    """Return per-line highlighted HTML, or ``None`` to use the safe fallback.

    Highlighting is **entirely optional**: if Pygments is not installed (the
    default in this zero-dependency deployment) this returns ``None`` and the
    caller renders escaped plaintext.  When Pygments *is* present its own
    HTML-escaping is trusted, and the result is only used when it lines up
    one-to-one with the source lines (otherwise we fall back).  There is no
    network access and no import cost unless a highlighter is available.
    """
    try:  # pragma: no cover - exercised only when Pygments is installed
        from pygments import highlight as _hl
        from pygments.formatters import HtmlFormatter
        from pygments.lexers import get_lexer_for_filename, guess_lexer
        from pygments.util import ClassNotFound
    except Exception:
        return None
    try:  # pragma: no cover - optional path
        try:
            lexer = get_lexer_for_filename(filename, text)
        except ClassNotFound:
            lexer = guess_lexer(text)
        # noclasses -> inline styles (no external CSS); nowrap -> no <div>/<pre>
        # wrapper so we get one HTML fragment per source line.
        formatter = HtmlFormatter(nowrap=True, noclasses=True)
        out = _hl(text, lexer, formatter)
    except Exception:
        return None
    lines = out.split("\n")
    if lines and lines[-1] == "":
        lines.pop()
    src_lines = text.split("\n")
    if src_lines and src_lines[-1] == "":
        src_lines.pop()
    if len(lines) != len(src_lines):
        return None  # safety: mismatch -> caller falls back to escaped text
    return lines


# --------------------------------------------------------------------------- #
# Unified-diff parsing
# --------------------------------------------------------------------------- #


@dataclass
class DiffLine:
    kind: str  # "add" | "del" | "ctx" | "hunk" | "meta"
    text: str


@dataclass
class DiffFile:
    old_path: str = ""
    new_path: str = ""
    status: str = "modified"  # added | deleted | renamed | modified | binary
    binary: bool = False
    additions: int = 0
    deletions: int = 0
    lines: List[DiffLine] = field(default_factory=list)

    @property
    def display_path(self) -> str:
        if self.status == "renamed" and self.old_path != self.new_path:
            return f"{self.old_path} -> {self.new_path}"
        return self.new_path or self.old_path


_DIFF_GIT_RE = re.compile(r"^diff --git a/(.*) b/(.*)$")


def parse_patch(patch: str) -> List[DiffFile]:
    """Parse a unified diff (``git show`` output) into per-file records."""
    files: List[DiffFile] = []
    cur: Optional[DiffFile] = None
    in_hunk = False

    for line in patch.split("\n"):
        m = _DIFF_GIT_RE.match(line)
        if m:
            cur = DiffFile(old_path=m.group(1), new_path=m.group(2))
            files.append(cur)
            in_hunk = False
            continue
        if cur is None:
            continue

        if line.startswith("new file mode"):
            cur.status = "added"
            continue
        if line.startswith("deleted file mode"):
            cur.status = "deleted"
            continue
        if line.startswith("rename from") or line.startswith("rename to"):
            cur.status = "renamed"
            continue
        if line.startswith("Binary files") or line.startswith("GIT binary patch"):
            cur.binary = True
            cur.status = "binary"
            continue
        if line.startswith("index ") or line.startswith("similarity ") or line.startswith(
            "dissimilarity "
        ):
            continue
        if line.startswith("--- ") or line.startswith("+++ "):
            continue
        if line.startswith("@@"):
            in_hunk = True
            cur.lines.append(DiffLine("hunk", line))
            continue
        if not in_hunk:
            cur.lines.append(DiffLine("meta", line))
            continue
        if line.startswith("+"):
            cur.additions += 1
            cur.lines.append(DiffLine("add", line))
        elif line.startswith("-"):
            cur.deletions += 1
            cur.lines.append(DiffLine("del", line))
        elif line.startswith("\\"):
            cur.lines.append(DiffLine("meta", line))
        else:
            cur.lines.append(DiffLine("ctx", line))

    return files
