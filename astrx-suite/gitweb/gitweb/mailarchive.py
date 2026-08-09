"""Read-only email/patch archive — the Sourcehut collaboration model.

The write path lives entirely OUTSIDE gitweb: an operator feeds an mbox via their
MTA / mailing list (`git send-email` → list → `public-inbox`/procmail), and
gitweb only *renders* it read-only.  No accounts, no writable web state, no spam
surface on an anonymous Tor endpoint — just threaded patchsets, inline diffs, and
a `git am`-able mbox download.  Pure stdlib (`mailbox`, `email`).

`render_*` return an inner HTML fragment (the caller wraps it in the page shell)
and take a ``u(action, **params)`` URL builder so this module stays decoupled
from the server's routing/prefix.
"""

import email.header
import email.utils
import hashlib
import html
import mailbox
import re
import time

MAX_MESSAGES = 2000          # cap messages parsed from one archive
MAX_BODY = 512 * 1024        # cap the rendered body of one message

_SUBJ_PREFIX = re.compile(r"^\s*(?:re:|fwd?:|\[[^\]]*\]\s*)+", re.IGNORECASE)
_DIFF_START = re.compile(r"^(diff --git |Index: |--- |\+\+\+ |@@ )", re.MULTILINE)


def _esc(s):
    return html.escape(s or "")


class Msg:
    __slots__ = ("subject", "sender", "ts", "mid", "in_reply_to", "body",
                 "is_patch", "raw")

    def __init__(self, subject, sender, ts, mid, in_reply_to, body, is_patch,
                 raw):
        self.subject = subject
        self.sender = sender
        self.ts = ts
        self.mid = mid
        self.in_reply_to = in_reply_to
        self.body = body
        self.is_patch = is_patch
        self.raw = raw


def _decode_header(value):
    if not value:
        return ""
    try:
        parts = email.header.decode_header(value)
        out = []
        for text, enc in parts:
            if isinstance(text, bytes):
                out.append(text.decode(enc or "utf-8", "replace"))
            else:
                out.append(text)
        return "".join(out)
    except Exception:
        return str(value)


def _body_text(message):
    """Best-effort plain-text body of an email.message.Message."""
    try:
        if message.is_multipart():
            for part in message.walk():
                if part.get_content_type() == "text/plain":
                    payload = part.get_payload(decode=True) or b""
                    return payload.decode(
                        part.get_content_charset() or "utf-8", "replace")
            return ""
        payload = message.get_payload(decode=True)
        if payload is None:
            return str(message.get_payload())
        return payload.decode(message.get_content_charset() or "utf-8", "replace")
    except Exception:
        return ""


def normalize_subject(subject):
    """Strip Re:/Fwd:/[PATCH …] prefixes to a stable thread key."""
    s = subject or ""
    prev = None
    while prev != s:
        prev = s
        s = _SUBJ_PREFIX.sub("", s).strip()
    return s.lower()


def read_archive(path, max_messages=MAX_MESSAGES):
    """Parse an mbox file into bounded :class:`Msg` records (newest data kept
    per message).  Returns [] for a missing/unreadable archive."""
    msgs = []
    try:
        box = mailbox.mbox(path, create=False)
    except (OSError, IOError, mailbox.Error):
        return msgs
    try:
        for key in box.iterkeys():
            if len(msgs) >= max_messages:
                break
            try:
                m = box[key]
            except Exception:
                continue
            subject = _decode_header(m.get("Subject", "")) or "(no subject)"
            sender = _decode_header(m.get("From", "")) or "(unknown)"
            mid = (m.get("Message-ID", "") or "").strip()
            irt = (m.get("In-Reply-To", "") or "").strip()
            ts = 0
            dt = m.get("Date")
            if dt:
                parsed = email.utils.parsedate_tz(dt)
                if parsed:
                    ts = email.utils.mktime_tz(parsed)
            body = _body_text(m)[:MAX_BODY]
            is_patch = bool(_DIFF_START.search(body or "")) or \
                "[PATCH" in subject.upper() or subject.upper().startswith("PATCH")
            try:
                raw = m.as_bytes()
            except Exception:
                raw = b""
            msgs.append(Msg(subject, sender, ts, mid, irt, body, is_patch, raw))
    finally:
        try:
            box.close()
        except Exception:
            pass
    return msgs


def thread_id(subject):
    return hashlib.sha1(
        normalize_subject(subject).encode("utf-8", "replace")).hexdigest()[:16]


def group_threads(msgs):
    """Group messages by normalized subject.  Returns a list of dicts
    ``{id, subject, msgs, ts}`` sorted newest-thread-first."""
    threads = {}
    for m in msgs:
        key = normalize_subject(m.subject)
        t = threads.get(key)
        if t is None:
            t = {"id": thread_id(m.subject), "subject": m.subject,
                 "msgs": [], "ts": 0}
            threads[key] = t
        t["msgs"].append(m)
        t["ts"] = max(t["ts"], m.ts)
    out = list(threads.values())
    for t in out:
        t["msgs"].sort(key=lambda x: x.ts)
        t["subject"] = t["msgs"][0].subject   # the earliest subject as the title
    out.sort(key=lambda t: t["ts"], reverse=True)
    return out


def _render_patch_body(body):
    """Escape-first <pre> with per-line diff colouring (no markup survives)."""
    out = ['<pre class="patch">']
    for line in (body or "").split("\n"):
        e = _esc(line)
        if line.startswith("+") and not line.startswith("+++"):
            out.append('<span class="add">%s</span>' % e)
        elif line.startswith("-") and not line.startswith("---"):
            out.append('<span class="del">%s</span>' % e)
        elif line.startswith("@@"):
            out.append('<span class="hunk">%s</span>' % e)
        elif _DIFF_START.match(line):
            out.append('<span class="fh">%s</span>' % e)
        else:
            out.append(e)
    out.append("</pre>")
    return "\n".join(out)


def _fmt_date(ts):
    if not ts:
        return ""
    try:
        return time.strftime("%Y-%m-%d", time.gmtime(ts))
    except Exception:
        return ""


def render_list(repo_name, threads, u, configured):
    """Inner HTML for the patch-archive index."""
    if not configured:
        return (
            '<div class="box"><div class="box-head">Patches</div>'
            '<div class="box-body muted">No patch archive is configured for '
            "this repo. An operator can point one at an mbox fed by "
            "<code>git send-email</code> to a mailing list.</div></div>"
            + _contribute_help(repo_name, u))
    rows = []
    for t in threads:
        url = u("patches", thread=t["id"])
        n = len(t["msgs"])
        patchy = " &middot; patch" if any(m.is_patch for m in t["msgs"]) else ""
        rows.append(
            "<tr>"
            f'<td><a href="{_esc(url)}">{_esc(t["subject"])}</a></td>'
            f'<td class="muted">{_esc(t["msgs"][0].sender)}</td>'
            f'<td class="muted">{n} msg{"s" if n != 1 else ""}{patchy}</td>'
            f'<td class="muted">{_esc(_fmt_date(t["ts"]))}</td>'
            "</tr>"
        )
    if rows:
        inner = ('<table class="list"><thead><tr><th>Subject</th><th>From</th>'
                 "<th></th><th>Updated</th></tr></thead><tbody>"
                 + "".join(rows) + "</tbody></table>")
    else:
        inner = '<div class="box-body muted">The archive is empty.</div>'
    return (f'<div class="box"><div class="box-head">Patches</div>{inner}</div>'
            + _contribute_help(repo_name, u))


def render_thread(repo_name, thread, u):
    """Inner HTML for one thread (its messages, patches rendered inline)."""
    subject = thread["subject"]
    dl = u("patches.mbox", thread=thread["id"])
    parts = [
        f'<p><a href="{_esc(u("patches"))}">&larr; all patches</a> &middot; '
        f'<a href="{_esc(dl)}">download mbox (git am)</a></p>',
        f"<h2>{_esc(subject)}</h2>",
    ]
    for m in thread["msgs"]:
        parts.append('<div class="box">')
        parts.append(
            '<div class="box-head">%s <span class="muted">%s</span></div>'
            % (_esc(m.sender), _esc(_fmt_date(m.ts))))
        if m.is_patch:
            parts.append(_render_patch_body(m.body))
        else:
            parts.append('<pre class="msg">%s</pre>' % _esc(m.body))
        parts.append("</div>")
    return "".join(parts)


def _contribute_help(repo_name, u):
    return (
        '<div class="box"><div class="box-head">Contribute</div>'
        '<div class="box-body">Send patches the mailing-list way — no account '
        "needed:<pre class=\"msg\">git clone &lt;this repo&gt;\n"
        "git commit -s\n"
        "git send-email --to=&lt;list address&gt; HEAD~1</pre>"
        "gitweb renders the resulting thread here, read-only.</div></div>"
    )


def thread_mbox(thread):
    """Concatenate a thread's raw messages into an mbox for ``git am``."""
    out = []
    for m in thread["msgs"]:
        raw = m.raw or b""
        if not raw.startswith(b"From "):
            out.append(b"From git@localhost Mon Sep 17 00:00:00 2001\n")
        out.append(raw)
        if not raw.endswith(b"\n"):
            out.append(b"\n")
        out.append(b"\n")
    return b"".join(out)


# CSS injected into the page for patch colouring (appended to the doc <style>).
PATCH_CSS = (
    "pre.patch,pre.msg{white-space:pre-wrap;overflow-x:auto;font-size:.85rem;"
    "background:#f6f8fa;border:1px solid #e1e4e8;border-radius:4px;padding:.6rem}"
    "pre.patch .add{color:#116329;background:#e6ffec;display:block}"
    "pre.patch .del{color:#82071e;background:#ffebe9;display:block}"
    "pre.patch .hunk{color:#0550ae;display:block}"
    "pre.patch .fh{color:#57606a;font-weight:bold;display:block}"
)
