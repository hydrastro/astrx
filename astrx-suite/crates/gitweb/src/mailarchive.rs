//! Read-only email/patch archive — the Sourcehut collaboration model.
//!
//! The write path lives entirely OUTSIDE gitweb: an operator feeds an mbox via
//! their MTA / mailing list (`git send-email` → list → `public-inbox`/procmail),
//! and gitweb only *renders* it read-only. No accounts, no writable web state,
//! no spam surface on an anonymous Tor endpoint — just threaded patchsets,
//! inline diffs, and a `git am`-able mbox download.
//!
//! `render_*` return an inner HTML fragment (the caller wraps it in the page
//! shell) and take a `u(action, params)` URL builder so this module stays
//! decoupled from the server's routing/prefix.
//!
//! A faithful port of the Python `gitweb.mailarchive`, which leans on the
//! stdlib `mailbox`/`email` packages. Those are reproduced here directly: the
//! `mailbox.mbox` table-of-contents scan, `email.feedparser`'s header block and
//! multipart split, `Message.get_payload(decode=True)` (base64 /
//! quoted-printable), `email.header.decode_header` (RFC 2047), and
//! `BytesGenerator`'s `as_bytes()` serialisation that `thread_mbox` concatenates.
//! Cross-checked byte-identical in `tests/xcheck_mailarchive.rs`.
//!
//! # Documented divergences
//!
//! * **Raw 8-bit header bytes.** CPython decodes header text as
//!   `ascii`+`surrogateescape`, so a non-ASCII byte in a `Subject:` survives as
//!   a lone surrogate — which a Rust `String` cannot hold (and which makes the
//!   Python renderer raise on the final `.encode("utf-8")` anyway). Such bytes
//!   are decoded UTF-8-lossily here. RFC 2047 encoded words — the interoperable
//!   spelling, and what every real MUA emits — are byte-identical.
//! * **A `Date:` with no timezone** (or the RFC 2822 `-0000` "unknown zone"
//!   form) is interpreted as UTC. CPython falls back to `time.mktime`, i.e. the
//!   *server's* local zone, which no stdlib-only Rust port can reproduce
//!   (and which makes the Python result depend on `TZ`).
//! * **An out-of-range `Date:`** (year outside 1–9999, month outside 1–12)
//!   raises `ValueError` out of `read_archive` in Python; here the timestamp is
//!   computed arithmetically instead of aborting the whole archive.

use std::path::Path;

use crawlcore::hash::{sha1, to_hex};

use crate::pycompat::{gmtime, html_escape, is_space, lstrip, splitlines, strip, timegm};

/// Cap on the number of messages parsed from one archive.
pub const MAX_MESSAGES: usize = 2000;
/// Cap on the rendered body of one message, in code points.
pub const MAX_BODY: usize = 512 * 1024;

/// CSS injected into the page for patch colouring (appended to the doc
/// `<style>`).
pub const PATCH_CSS: &str = concat!(
    "pre.patch,pre.msg{white-space:pre-wrap;overflow-x:auto;font-size:.85rem;",
    "background:#f6f8fa;border:1px solid #e1e4e8;border-radius:4px;padding:.6rem}",
    "pre.patch .add{color:#116329;background:#e6ffec;display:block}",
    "pre.patch .del{color:#82071e;background:#ffebe9;display:block}",
    "pre.patch .hunk{color:#0550ae;display:block}",
    "pre.patch .fh{color:#57606a;font-weight:bold;display:block}"
);

fn esc(s: &str) -> String {
    html_escape(s)
}

/// One parsed archive message.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Msg {
    /// The decoded `Subject:` (or `(no subject)`).
    pub subject: String,
    /// The decoded `From:` (or `(unknown)`).
    pub sender: String,
    /// The `Date:` as a unix timestamp (0 when absent/unparseable).
    pub ts: i64,
    /// The `Message-ID:`, stripped.
    pub mid: String,
    /// The `In-Reply-To:`, stripped.
    pub in_reply_to: String,
    /// The best-effort plain-text body, capped at [`MAX_BODY`] code points.
    pub body: String,
    /// True when the message looks like a patch (diff markers or `[PATCH …]`).
    pub is_patch: bool,
    /// The re-serialised message (`Message.as_bytes()`), for the mbox download.
    pub raw: Vec<u8>,
}

/// A group of messages sharing a normalized subject.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Thread {
    /// A stable 16-hex-character id derived from the normalized subject.
    pub id: String,
    /// The earliest message's subject, used as the thread title.
    pub subject: String,
    /// The thread's messages, oldest first.
    pub msgs: Vec<Msg>,
    /// The newest message timestamp in the thread.
    pub ts: i64,
}

// --------------------------------------------------------------------------- //
// `^(diff --git |Index: |--- |\+\+\+ |@@ )` (MULTILINE)
// --------------------------------------------------------------------------- //

const DIFF_STARTS: [&str; 5] = ["diff --git ", "Index: ", "--- ", "+++ ", "@@ "];

/// `_DIFF_START.match(line)` — anchored at the start of `line`.
fn diff_start_match(line: &str) -> bool {
    DIFF_STARTS.iter().any(|p| line.starts_with(p))
}

/// `_DIFF_START.search(text)` with `re.MULTILINE` — any line may match.
fn diff_start_search(text: &str) -> bool {
    text.split('\n').any(diff_start_match)
}

// --------------------------------------------------------------------------- //
// Subjects and threading
// --------------------------------------------------------------------------- //

/// One application of `^\s*(?:re:|fwd?:|\[[^\]]*\]\s*)+` (case-insensitive):
/// returns the byte length of the match, or `None`.
fn subj_prefix_len(s: &str) -> Option<usize> {
    let c: Vec<char> = s.chars().collect();
    let mut i = 0usize;
    while i < c.len() && is_space(c[i]) {
        i += 1;
    }
    let mut reps = 0usize;
    loop {
        let lower: String = c[i..c.len().min(i + 4)]
            .iter()
            .collect::<String>()
            .to_lowercase();
        if lower.starts_with("re:") {
            i += 3;
        } else if lower.starts_with("fwd:") {
            i += 4;
        } else if lower.starts_with("fw:") {
            i += 3;
        } else if i < c.len() && c[i] == '[' {
            let mut j = i + 1;
            while j < c.len() && c[j] != ']' {
                j += 1;
            }
            if j >= c.len() {
                break;
            }
            i = j + 1;
            while i < c.len() && is_space(c[i]) {
                i += 1;
            }
        } else {
            break;
        }
        reps += 1;
    }
    if reps == 0 {
        return None;
    }
    Some(c[..i].iter().map(|ch| ch.len_utf8()).sum())
}

/// Strip `Re:`/`Fwd:`/`[PATCH …]` prefixes to a stable thread key.
#[must_use]
pub fn normalize_subject(subject: &str) -> String {
    let mut s = subject.to_string();
    loop {
        // Python: `s = _SUBJ_PREFIX.sub("", s).strip()` — the strip runs on
        // every pass, including the one where the pattern does not match.
        let next = match subj_prefix_len(&s) {
            Some(n) => strip(&s[n..]).to_string(),
            None => strip(&s).to_string(),
        };
        if next == s {
            break;
        }
        s = next;
    }
    s.to_lowercase()
}

/// A stable thread id: the first 16 hex characters of
/// `sha1(normalize_subject(subject))`.
#[must_use]
pub fn thread_id(subject: &str) -> String {
    let digest = to_hex(&sha1(normalize_subject(subject).as_bytes()));
    digest[..16].to_string()
}

/// Group messages by normalized subject, newest thread first.
#[must_use]
pub fn group_threads(msgs: Vec<Msg>) -> Vec<Thread> {
    // Python builds a dict keyed by the normalized subject; the final
    // `sort(reverse=True)` is stable, so first-seen order breaks ties.
    let mut keys: Vec<String> = Vec::new();
    let mut out: Vec<Thread> = Vec::new();
    for m in msgs {
        let key = normalize_subject(&m.subject);
        let idx = match keys.iter().position(|k| *k == key) {
            Some(i) => i,
            None => {
                keys.push(key);
                out.push(Thread {
                    id: thread_id(&m.subject),
                    subject: m.subject.clone(),
                    msgs: Vec::new(),
                    ts: 0,
                });
                out.len() - 1
            }
        };
        out[idx].ts = out[idx].ts.max(m.ts);
        out[idx].msgs.push(m);
    }
    for t in &mut out {
        t.msgs.sort_by_key(|m| m.ts); // stable, like Python's list.sort
        if let Some(first) = t.msgs.first() {
            t.subject = first.subject.clone(); // the earliest subject as title
        }
    }
    out.sort_by_key(|t| std::cmp::Reverse(t.ts)); // stable: ties keep insertion order
    out
}

// --------------------------------------------------------------------------- //
// Rendering
// --------------------------------------------------------------------------- //

/// `%Y-%m-%d` of a unix timestamp (empty for 0).
fn fmt_date(ts: i64) -> String {
    if ts == 0 {
        return String::new();
    }
    let t = gmtime(ts);
    format!("{}-{:02}-{:02}", t.year, t.mon, t.day)
}

/// Escape-first `<pre>` with per-line diff colouring (no markup survives).
fn render_patch_body(body: &str) -> String {
    let mut out: Vec<String> = vec!["<pre class=\"patch\">".to_string()];
    for line in body.split('\n') {
        let e = esc(line);
        if line.starts_with('+') && !line.starts_with("+++") {
            out.push(format!("<span class=\"add\">{e}</span>"));
        } else if line.starts_with('-') && !line.starts_with("---") {
            out.push(format!("<span class=\"del\">{e}</span>"));
        } else if line.starts_with("@@") {
            out.push(format!("<span class=\"hunk\">{e}</span>"));
        } else if diff_start_match(line) {
            out.push(format!("<span class=\"fh\">{e}</span>"));
        } else {
            out.push(e);
        }
    }
    out.push("</pre>".to_string());
    out.join("\n")
}

fn contribute_help() -> &'static str {
    concat!(
        "<div class=\"box\"><div class=\"box-head\">Contribute</div>",
        "<div class=\"box-body\">Send patches the mailing-list way — no account ",
        "needed:<pre class=\"msg\">git clone &lt;this repo&gt;\n",
        "git commit -s\n",
        "git send-email --to=&lt;list address&gt; HEAD~1</pre>",
        "gitweb renders the resulting thread here, read-only.</div></div>"
    )
}

/// Inner HTML for the patch-archive index.
///
/// `u` is the caller's URL builder — `u(action, params)`, mirroring the Python
/// `u(action, **params)`. `repo_name` is accepted for parity with the reference
/// (which does not use it either).
pub fn render_list<F>(repo_name: &str, threads: &[Thread], u: F, configured: bool) -> String
where
    F: Fn(&str, &[(&str, &str)]) -> String,
{
    let _ = repo_name;
    if !configured {
        return format!(
            "{}{}",
            concat!(
                "<div class=\"box\"><div class=\"box-head\">Patches</div>",
                "<div class=\"box-body muted\">No patch archive is configured for ",
                "this repo. An operator can point one at an mbox fed by ",
                "<code>git send-email</code> to a mailing list.</div></div>"
            ),
            contribute_help()
        );
    }
    let mut rows = String::new();
    for t in threads {
        let url = u("patches", &[("thread", &t.id)]);
        let n = t.msgs.len();
        let patchy = if t.msgs.iter().any(|m| m.is_patch) {
            " &middot; patch"
        } else {
            ""
        };
        let sender = t.msgs.first().map(|m| m.sender.as_str()).unwrap_or("");
        rows.push_str(&format!(
            "<tr><td><a href=\"{}\">{}</a></td><td class=\"muted\">{}</td>\
             <td class=\"muted\">{n} msg{}{patchy}</td><td class=\"muted\">{}</td></tr>",
            esc(&url),
            esc(&t.subject),
            esc(sender),
            if n != 1 { "s" } else { "" },
            esc(&fmt_date(t.ts)),
        ));
    }
    let inner = if rows.is_empty() {
        "<div class=\"box-body muted\">The archive is empty.</div>".to_string()
    } else {
        format!(
            "<table class=\"list\"><thead><tr><th>Subject</th><th>From</th>\
             <th></th><th>Updated</th></tr></thead><tbody>{rows}</tbody></table>"
        )
    };
    format!(
        "<div class=\"box\"><div class=\"box-head\">Patches</div>{inner}</div>{}",
        contribute_help()
    )
}

/// Inner HTML for one thread (its messages, patches rendered inline).
///
/// `repo_name` is accepted for parity with the reference (which does not use it).
pub fn render_thread<F>(repo_name: &str, thread: &Thread, u: F) -> String
where
    F: Fn(&str, &[(&str, &str)]) -> String,
{
    let _ = repo_name;
    let dl = u("patches.mbox", &[("thread", &thread.id)]);
    let mut parts = format!(
        "<p><a href=\"{}\">&larr; all patches</a> &middot; \
         <a href=\"{}\">download mbox (git am)</a></p><h2>{}</h2>",
        esc(&u("patches", &[])),
        esc(&dl),
        esc(&thread.subject),
    );
    for m in &thread.msgs {
        parts.push_str("<div class=\"box\">");
        parts.push_str(&format!(
            "<div class=\"box-head\">{} <span class=\"muted\">{}</span></div>",
            esc(&m.sender),
            esc(&fmt_date(m.ts))
        ));
        if m.is_patch {
            parts.push_str(&render_patch_body(&m.body));
        } else {
            parts.push_str(&format!("<pre class=\"msg\">{}</pre>", esc(&m.body)));
        }
        parts.push_str("</div>");
    }
    parts
}

/// Concatenate a thread's raw messages into an mbox for `git am`.
#[must_use]
pub fn thread_mbox(thread: &Thread) -> Vec<u8> {
    let mut out: Vec<u8> = Vec::new();
    for m in &thread.msgs {
        if !m.raw.starts_with(b"From ") {
            out.extend_from_slice(b"From git@localhost Mon Sep 17 00:00:00 2001\n");
        }
        out.extend_from_slice(&m.raw);
        if !m.raw.ends_with(b"\n") {
            out.push(b'\n');
        }
        out.push(b'\n');
    }
    out
}

// --------------------------------------------------------------------------- //
// mbox reading
// --------------------------------------------------------------------------- //

/// Parse an mbox file into bounded [`Msg`] records. Returns `[]` for a
/// missing/unreadable archive, exactly like the Python reference.
#[must_use]
pub fn read_archive(path: &Path, max_messages: usize) -> Vec<Msg> {
    match std::fs::read(path) {
        Ok(data) => parse_mbox(&data, max_messages),
        Err(_) => Vec::new(),
    }
}

/// Parse mbox *bytes* into bounded [`Msg`] records — the pure core of
/// [`read_archive`].
#[must_use]
pub fn parse_mbox(data: &[u8], max_messages: usize) -> Vec<Msg> {
    let mut msgs = Vec::new();
    for chunk in mbox_chunks(data) {
        if msgs.len() >= max_messages {
            break;
        }
        msgs.push(parse_message(chunk));
    }
    msgs
}

/// `mailbox.mbox._generate_toc`: message chunks *after* the `From ` line.
fn mbox_chunks(data: &[u8]) -> Vec<&[u8]> {
    let mut starts: Vec<usize> = Vec::new();
    let mut stops: Vec<usize> = Vec::new();
    let mut last_was_empty = false;
    let mut pos = 0usize;
    loop {
        let line_pos = pos;
        if pos >= data.len() {
            // EOF
            stops.push(if last_was_empty {
                line_pos.saturating_sub(1)
            } else {
                line_pos
            });
            break;
        }
        let end = match data[pos..].iter().position(|b| *b == b'\n') {
            Some(i) => pos + i + 1,
            None => data.len(),
        };
        let line = &data[pos..end];
        pos = end;
        if line.starts_with(b"From ") {
            if stops.len() < starts.len() {
                stops.push(if last_was_empty {
                    line_pos.saturating_sub(1)
                } else {
                    line_pos
                });
            }
            starts.push(line_pos);
            last_was_empty = false;
        } else {
            last_was_empty = line == b"\n";
        }
    }
    if stops.len() > starts.len() {
        stops.truncate(starts.len());
    }
    starts
        .iter()
        .zip(stops.iter())
        .map(|(&s, &e)| {
            // `get_message` drops the `From ` line and reads through `stop`.
            let nl = match data[s..].iter().position(|b| *b == b'\n') {
                Some(i) => s + i + 1,
                None => data.len(),
            };
            let end = e.max(s);
            let begin = nl.min(end);
            &data[begin..end]
        })
        .collect()
}

// --------------------------------------------------------------------------- //
// The `email` package, reproduced
// --------------------------------------------------------------------------- //

/// A parsed MIME entity: raw header items plus a payload.
struct Part {
    headers: Vec<(Vec<u8>, Vec<u8>)>,
    payload: Payload,
}

enum Payload {
    Bytes(Vec<u8>),
    /// `(preamble, parts, epilogue)` — the `multipart/*` decomposition.
    Multi(Option<Vec<u8>>, Vec<Part>, Option<Vec<u8>>),
}

impl Part {
    /// `Message.get(name)` — the first matching header, case-insensitively.
    fn get(&self, name: &str) -> Option<&[u8]> {
        self.headers
            .iter()
            .find(|(k, _)| k.eq_ignore_ascii_case(name.as_bytes()))
            .map(|(_, v)| v.as_slice())
    }

    fn get_str(&self, name: &str) -> String {
        self.get(name)
            .map(|v| String::from_utf8_lossy(v).into_owned())
            .unwrap_or_default()
    }

    fn is_multipart(&self) -> bool {
        matches!(self.payload, Payload::Multi(..))
    }

    /// `Message.get_content_type()`.
    fn content_type(&self) -> String {
        let Some(v) = self.get("content-type") else {
            return "text/plain".to_string();
        };
        let raw = String::from_utf8_lossy(v);
        let ctype = strip(raw.split(';').next().unwrap_or("")).to_lowercase();
        if ctype.matches('/').count() != 1 {
            return "text/plain".to_string();
        }
        ctype
    }

    /// `Message.get_param(name)` on `content-type`.
    fn content_type_param(&self, name: &str) -> Option<String> {
        let v = self.get("content-type")?;
        let raw = String::from_utf8_lossy(v).into_owned();
        for p in split_params(&raw).into_iter().skip(1) {
            let (k, val) = match p.split_once('=') {
                Some((k, val)) => (strip(k).to_string(), strip(val).to_string()),
                None => (strip(&p).to_string(), String::new()),
            };
            if k.eq_ignore_ascii_case(name) {
                return Some(unquote(&val));
            }
        }
        None
    }

    /// `Message.get_content_charset()` — lowercased, `None` when absent or
    /// non-ASCII.
    fn charset(&self) -> Option<String> {
        let cs = self.content_type_param("charset")?;
        if !cs.is_ascii() {
            return None;
        }
        Some(cs.to_lowercase())
    }

    /// `Message.get_payload(decode=True)` — `None` for a multipart.
    fn payload_decoded(&self) -> Option<Vec<u8>> {
        let Payload::Bytes(raw) = &self.payload else {
            return None;
        };
        let cte = self.get_str("content-transfer-encoding").to_lowercase();
        let cte = strip(&cte);
        Some(match cte {
            "quoted-printable" => decode_quopri(raw),
            "base64" => {
                // `decode_b(b"".join(payload.splitlines()))`.
                let joined: Vec<u8> = raw
                    .split(|b| *b == b'\n' || *b == b'\r')
                    .flatten()
                    .copied()
                    .collect();
                decode_b(&joined)
            }
            _ => raw.clone(),
        })
    }

    /// `Message.walk()` — pre-order, the message itself first.
    fn walk<'a>(&'a self, out: &mut Vec<&'a Part>) {
        out.push(self);
        if let Payload::Multi(_, parts, _) = &self.payload {
            for p in parts {
                p.walk(out);
            }
        }
    }
}

/// `_parseparam(';' + value)`: split on `;` outside double quotes. The first
/// element is the bare type.
fn split_params(value: &str) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    let mut cur = String::new();
    let mut in_quotes = false;
    let mut escaped = false;
    for ch in value.chars() {
        if escaped {
            cur.push(ch);
            escaped = false;
            continue;
        }
        match ch {
            '\\' if in_quotes => {
                cur.push(ch);
                escaped = true;
            }
            '"' => {
                in_quotes = !in_quotes;
                cur.push(ch);
            }
            ';' if !in_quotes => {
                out.push(strip(&cur).to_string());
                cur.clear();
            }
            _ => cur.push(ch),
        }
    }
    out.push(strip(&cur).to_string());
    out
}

/// `email.utils.unquote`.
fn unquote(s: &str) -> String {
    if s.chars().count() > 1 {
        if s.starts_with('"') && s.ends_with('"') {
            return s[1..s.len() - 1]
                .replace("\\\\", "\\")
                .replace("\\\"", "\"");
        }
        if s.starts_with('<') && s.ends_with('>') {
            return s[1..s.len() - 1].to_string();
        }
    }
    s.to_string()
}

/// `re.compile(r'^(From |[\041-\071\073-\176]*:|[\t ])')`
fn is_header_line(line: &[u8]) -> bool {
    if line.starts_with(b"From ") {
        return true;
    }
    match line.first() {
        Some(b'\t') | Some(b' ') => return true,
        _ => {}
    }
    for (i, &b) in line.iter().enumerate() {
        if b == b':' {
            return true;
        }
        if !matches!(b, 0x21..=0x39 | 0x3b..=0x7e) {
            let _ = i;
            return false;
        }
    }
    false
}

/// Split `data` into lines that keep their terminators.
fn lines_with_ends(data: &[u8]) -> Vec<&[u8]> {
    let mut out = Vec::new();
    let mut start = 0usize;
    while start < data.len() {
        let end = match data[start..].iter().position(|b| *b == b'\n') {
            Some(i) => start + i + 1,
            None => data.len(),
        };
        out.push(&data[start..end]);
        start = end;
    }
    out
}

/// Strip exactly one trailing `\r\n` / `\r` / `\n` (`NLCRE_eol`).
fn strip_eol(b: &[u8]) -> &[u8] {
    if b.ends_with(b"\r\n") {
        &b[..b.len() - 2]
    } else if b.ends_with(b"\n") || b.ends_with(b"\r") {
        &b[..b.len() - 1]
    } else {
        b
    }
}

/// `FeedParser` for one entity: the header block, then the payload.
fn parse_part(data: &[u8]) -> Part {
    let lines = lines_with_ends(data);
    let mut header_lines: Vec<&[u8]> = Vec::new();
    let mut i = 0usize;
    while i < lines.len() {
        let line = lines[i];
        if !is_header_line(line) {
            // A bare newline is the RFC separator and is thrown away; anything
            // else is the first body line (MissingHeaderBodySeparatorDefect).
            if line == b"\n" || line == b"\r\n" || line == b"\r" {
                i += 1;
            }
            break;
        }
        header_lines.push(line);
        i += 1;
    }
    let body_start: usize = lines[..i].iter().map(|l| l.len()).sum();
    let headers = parse_header_block(&header_lines);
    let body = &data[body_start..];

    let part = Part {
        headers,
        payload: Payload::Bytes(body.to_vec()),
    };
    let ctype = part.content_type();
    if !ctype.starts_with("multipart/") {
        return part;
    }
    let Some(boundary) = part.content_type_param("boundary") else {
        return part;
    };
    match split_multipart(body, &boundary) {
        MultipartParse::Split {
            preamble,
            parts,
            epilogue,
        } => Part {
            headers: part.headers,
            payload: Payload::Multi(
                preamble,
                parts
                    .into_iter()
                    .map(|p| {
                        let mut sub = parse_part(&p);
                        strip_boundary_newline(&mut sub);
                        sub
                    })
                    .collect(),
                epilogue,
            ),
        },
        MultipartParse::NotMultipart(payload) => Part {
            headers: part.headers,
            payload: Payload::Bytes(payload),
        },
    }
}

/// RFC 2046: the newline preceding a boundary belongs to the boundary, not to
/// the subpart before it. `_parsegen` removes it from the subpart's **epilogue**
/// when that subpart is itself a `multipart/*` (and an empty epilogue becomes
/// `None`), and from its **payload** otherwise — so a `multipart/*` subpart that
/// never got split keeps its trailing newline.
fn strip_boundary_newline(part: &mut Part) {
    if part.content_type().starts_with("multipart/") {
        if let Payload::Multi(_, _, epilogue) = &mut part.payload {
            match epilogue {
                Some(e) if e.is_empty() => *epilogue = None,
                Some(e) => {
                    let n = strip_eol(e).len();
                    e.truncate(n);
                }
                None => {}
            }
        }
        return;
    }
    if let Payload::Bytes(b) = &mut part.payload {
        let n = strip_eol(b).len();
        b.truncate(n);
    }
}

/// `_parse_headers` + compat32's `header_source_parse`.
fn parse_header_block(lines: &[&[u8]]) -> Vec<(Vec<u8>, Vec<u8>)> {
    /// compat32's `header_source_parse`: name before the first `:`, value
    /// `lstrip(' \t')`-ed, continuation lines appended verbatim, then
    /// `rstrip('\r\n')`.
    fn flush(cur: &mut Vec<Vec<u8>>, out: &mut Vec<(Vec<u8>, Vec<u8>)>) {
        if cur.is_empty() {
            return;
        }
        let first = &cur[0];
        let colon = first.iter().position(|b| *b == b':').unwrap_or(0);
        let name = first[..colon].to_vec();
        let mut value: Vec<u8> = first[colon + 1..].to_vec();
        let lead = value
            .iter()
            .take_while(|b| **b == b' ' || **b == b'\t')
            .count();
        value.drain(..lead);
        for extra in &cur[1..] {
            value.extend_from_slice(extra);
        }
        while value.last().is_some_and(|b| *b == b'\r' || *b == b'\n') {
            value.pop();
        }
        out.push((name, value));
        cur.clear();
    }
    let mut out: Vec<(Vec<u8>, Vec<u8>)> = Vec::new();
    let mut cur: Vec<Vec<u8>> = Vec::new();
    for (lineno, line) in lines.iter().enumerate() {
        if matches!(line.first(), Some(b' ') | Some(b'\t')) {
            if !cur.is_empty() {
                cur.push(line.to_vec());
            }
            continue;
        }
        flush(&mut cur, &mut out);
        if line.starts_with(b"From ") {
            // An envelope header at line 0, or a misplaced one: never a header.
            let _ = lineno;
            continue;
        }
        match line.iter().position(|b| *b == b':') {
            Some(0) | None => continue, // "Missing header name." / not a header
            Some(_) => cur.push(line.to_vec()),
        }
    }
    flush(&mut cur, &mut out);
    out
}

/// The outcome of scanning a `multipart/*` body for its boundaries.
enum MultipartParse {
    /// `StartBoundaryNotFoundDefect`: only the *close* boundary (or EOF) was
    /// seen, so `_parsegen` sets the payload to the captured text and the
    /// message stays non-multipart.
    NotMultipart(Vec<u8>),
    /// A real decomposition: `(preamble, part bodies, epilogue)`.
    Split {
        preamble: Option<Vec<u8>>,
        parts: Vec<Vec<u8>>,
        epilogue: Option<Vec<u8>>,
    },
}

/// `FeedParser._parsegen`'s `multipart/*` branch, line for line.
fn split_multipart(body: &[u8], boundary: &str) -> MultipartParse {
    let sep = format!("--{boundary}");
    // `(?P<sep>…)(?P<end>--)?(?P<ws>[ \t]*)(?P<linesep>\r\n|\r|\n)?$` — `Some`
    // carries the `end` flag, `None` means "not a boundary line".
    let classify = |line: &[u8]| -> Option<bool> {
        let rest = line.strip_prefix(sep.as_bytes())?;
        let (rest, end) = match rest.strip_prefix(b"--".as_slice()) {
            Some(r) => (r, true),
            None => (rest, false),
        };
        let rest = lstrip_bytes(rest, b" \t");
        if rest.is_empty() || rest == b"\n" || rest == b"\r" || rest == b"\r\n" {
            Some(end)
        } else {
            None
        }
    };
    let lines = lines_with_ends(body);
    let mut preamble_lines: Vec<&[u8]> = Vec::new();
    let mut parts: Vec<Vec<u8>> = Vec::new();
    let mut capturing_preamble = true;
    let mut close_seen = false;
    let mut i = 0usize;
    let mut close_at = 0usize;
    while i < lines.len() {
        let line = lines[i];
        i += 1;
        match classify(line) {
            Some(true) => {
                // The end boundary: done with this multipart either way.
                close_seen = true;
                close_at = i;
                break;
            }
            Some(false) if capturing_preamble => {
                capturing_preamble = false;
                i -= 1; // `unreadline`: the boundary is re-processed below
            }
            Some(false) => {
                // Consume any run of boundary lines that follows — RFC 2046
                // produces no body part between double boundaries.
                while i < lines.len() && classify(lines[i]).is_some() {
                    i += 1;
                }
                // The subpart runs to the next boundary line (or EOF). The
                // newline before that boundary belongs to it, but *where* it is
                // removed depends on the subpart's own type, so the removal is
                // done after parsing (see `strip_boundary_newline`).
                let start = i;
                while i < lines.len() && classify(lines[i]).is_none() {
                    i += 1;
                }
                parts.push(concat_lines(&lines[start..i]));
            }
            None => {
                debug_assert!(capturing_preamble);
                preamble_lines.push(line);
            }
        }
    }
    if capturing_preamble {
        return MultipartParse::NotMultipart(concat_lines(&preamble_lines));
    }
    let preamble = if preamble_lines.is_empty() {
        None
    } else {
        Some(strip_eol(&concat_lines(&preamble_lines)).to_vec())
    };
    if !close_seen {
        // `CloseBoundaryNotFoundDefect`: the epilogue stays `None`.
        return MultipartParse::Split {
            preamble,
            parts,
            epilogue: None,
        };
    }
    // Everything after the end boundary is epilogue; a leading newline belongs
    // to the boundary, not to it.
    let rest = concat_lines(&lines[close_at..]);
    let epilogue = if lines[close_at - 1].ends_with(b"\n") || lines[close_at - 1].ends_with(b"\r") {
        rest
    } else if rest.is_empty() {
        Vec::new()
    } else {
        strip_bol(&rest).to_vec()
    };
    MultipartParse::Split {
        preamble,
        parts,
        epilogue: Some(epilogue),
    }
}

/// Strip one leading `\r\n` / `\r` / `\n` (`NLCRE_bol`).
fn strip_bol(b: &[u8]) -> &[u8] {
    if b.starts_with(b"\r\n") {
        &b[2..]
    } else if b.starts_with(b"\n") || b.starts_with(b"\r") {
        &b[1..]
    } else {
        b
    }
}

fn concat_lines(lines: &[&[u8]]) -> Vec<u8> {
    let mut out = Vec::new();
    for l in lines {
        out.extend_from_slice(l);
    }
    out
}

fn lstrip_bytes<'a>(b: &'a [u8], cut: &[u8]) -> &'a [u8] {
    let mut i = 0;
    while i < b.len() && cut.contains(&b[i]) {
        i += 1;
    }
    &b[i..]
}

/// `BytesGenerator.flatten(msg, unixfrom=False)` — `Message.as_bytes()`.
///
/// `None` where CPython raises, which `read_archive` answers with `b""`. The one
/// reachable raise is a `multipart/*` **content type** whose payload never got
/// split (no boundary, or only a close boundary) and holds a non-ASCII byte:
/// `_handle_multipart` writes `msg.get_payload()`, whose lossy re-decode turns
/// those bytes into `U+FFFD`, which the generator's `encode("ascii",
/// "surrogateescape")` then rejects.
fn as_bytes(part: &Part) -> Option<Vec<u8>> {
    let mut out: Vec<u8> = Vec::new();
    for (name, value) in &part.headers {
        out.extend_from_slice(name);
        out.extend_from_slice(b": ");
        write_lines_preserving(&mut out, value);
        out.push(b'\n');
    }
    out.push(b'\n');
    match &part.payload {
        Payload::Bytes(body) => {
            if part.content_type().starts_with("multipart/") {
                if !body.is_ascii() {
                    return None;
                }
                out.extend_from_slice(body);
            } else if body.is_ascii() {
                out.extend_from_slice(body);
            } else {
                // `_has_surrogates` → the 8-bit path, which normalises the line
                // endings through `_write_lines`.
                write_lines_normalising(&mut out, body);
            }
        }
        Payload::Multi(preamble, parts, epilogue) => {
            let boundary = part.content_type_param("boundary").unwrap_or_default();
            if let Some(p) = preamble {
                write_lines_normalising(&mut out, p);
                out.push(b'\n');
            }
            out.extend_from_slice(format!("--{boundary}\n").as_bytes());
            for (i, sub) in parts.iter().enumerate() {
                if i > 0 {
                    out.extend_from_slice(format!("\n--{boundary}\n").as_bytes());
                }
                out.extend_from_slice(&as_bytes(sub)?);
            }
            out.extend_from_slice(format!("\n--{boundary}--\n").as_bytes());
            if let Some(e) = epilogue {
                write_lines_normalising(&mut out, e);
            }
        }
    }
    Some(out)
}

/// A header value is written back verbatim (its folding is preserved).
fn write_lines_preserving(out: &mut Vec<u8>, value: &[u8]) {
    out.extend_from_slice(value);
}

/// `Generator._write_lines`: `\r\n`/`\r` become `\n`, and a trailing separator
/// does not add an extra blank line.
fn write_lines_normalising(out: &mut Vec<u8>, data: &[u8]) {
    if data.is_empty() {
        return;
    }
    for line in lines_with_ends(data) {
        let body = strip_eol(line);
        out.extend_from_slice(body);
        if body.len() != line.len() {
            out.push(b'\n');
        }
    }
}

// --------------------------------------------------------------------------- //
// Transfer encodings and charsets
// --------------------------------------------------------------------------- //

/// `quopri.decodestring` (the `email` flavour: `=\n` soft breaks, `=XX` hex).
fn decode_quopri(data: &[u8]) -> Vec<u8> {
    let mut out = Vec::with_capacity(data.len());
    let mut i = 0usize;
    let hex = |b: u8| -> Option<u8> {
        match b {
            b'0'..=b'9' => Some(b - b'0'),
            b'a'..=b'f' => Some(b - b'a' + 10),
            b'A'..=b'F' => Some(b - b'A' + 10),
            _ => None,
        }
    };
    while i < data.len() {
        if data[i] != b'=' {
            out.push(data[i]);
            i += 1;
            continue;
        }
        let Some(&next) = data.get(i + 1) else {
            break; // a trailing `=` is dropped
        };
        if next == b'\n' {
            i += 2; // soft line break
            continue;
        }
        if next == b'\r' {
            // Skip to the newline that ends the soft break (or to the end).
            i += 2;
            while i < data.len() && data[i] != b'\n' {
                i += 1;
            }
            if i < data.len() {
                i += 1;
            }
            continue;
        }
        if next == b'=' {
            // "broken case from broken python qp": one `=`, both consumed.
            out.push(b'=');
            i += 2;
            continue;
        }
        match (hex(next), data.get(i + 2).copied().and_then(hex)) {
            (Some(h), Some(l)) => {
                out.push((h << 4) | l);
                i += 3;
            }
            // Not an escape: the `=` is literal and the next byte is
            // re-examined by the loop.
            _ => {
                out.push(b'=');
                i += 1;
            }
        }
    }
    out
}

fn b64_value(c: u8) -> Option<u32> {
    match c {
        b'A'..=b'Z' => Some(u32::from(c - b'A')),
        b'a'..=b'z' => Some(u32::from(c - b'a') + 26),
        b'0'..=b'9' => Some(u32::from(c - b'0') + 52),
        b'+' => Some(62),
        b'/' => Some(63),
        _ => None,
    }
}

/// `binascii.a2b_base64` in *non-strict* mode: characters outside the alphabet
/// are skipped, `=` terminates a quad once it is complete, and a leftover
/// partial quad is `binascii.Error` (`None` here).
fn a2b_base64(data: &[u8]) -> Option<Vec<u8>> {
    // CPython emits a byte on *each* character after the first of a quad, so a
    // pad sequence that terminates a 2- or 3-character quad keeps what has
    // already been written.
    let mut out = Vec::with_capacity(data.len() * 3 / 4 + 3);
    let mut quad_pos = 0u32;
    let mut leftchar: u32 = 0;
    let mut pads = 0u32;
    for &c in data {
        if c == b'=' {
            pads += 1;
            if quad_pos >= 2 && quad_pos + pads >= 4 {
                return Some(out); // a valid quad with padding: stop here
            }
            continue;
        }
        let Some(v) = b64_value(c) else { continue };
        pads = 0;
        match quad_pos {
            0 => {
                quad_pos = 1;
                leftchar = v;
            }
            1 => {
                quad_pos = 2;
                out.push(((leftchar << 2) | (v >> 4)) as u8);
                leftchar = v & 0x0f;
            }
            2 => {
                quad_pos = 3;
                out.push(((leftchar << 4) | (v >> 2)) as u8);
                leftchar = v & 0x03;
            }
            _ => {
                quad_pos = 0;
                out.push(((leftchar << 6) | v) as u8);
                leftchar = 0;
            }
        }
    }
    if quad_pos == 0 {
        Some(out)
    } else {
        None // "Incorrect padding" / "cannot be 1 more than a multiple of 4"
    }
}

/// `base64.b64decode(s, validate=True)`: reject anything outside
/// `[A-Za-z0-9+/]*={0,2}` before decoding.
fn b64_strict(data: &[u8]) -> Option<Vec<u8>> {
    let n = data.iter().take_while(|c| **c != b'=').count();
    if data.len() - n > 2 || data[n..].iter().any(|c| *c != b'=') {
        return None;
    }
    if data[..n].iter().any(|c| b64_value(*c).is_none()) {
        return None;
    }
    a2b_base64(data)
}

/// `email._encoded_words.decode_b`: pad to a multiple of four and try the strict
/// decoder, then the lenient one, then the lenient one with `==` appended — and
/// finally (bpo-27397) give the input back undecoded rather than raising.
fn decode_b(encoded: &[u8]) -> Vec<u8> {
    let pad_err = encoded.len() % 4;
    let mut padded = encoded.to_vec();
    if pad_err != 0 {
        padded.extend_from_slice(&b"==="[..4 - pad_err]);
    }
    if let Some(v) = b64_strict(&padded) {
        return v;
    }
    if let Some(v) = a2b_base64(encoded) {
        return v;
    }
    let mut retry = encoded.to_vec();
    retry.extend_from_slice(b"==");
    // Only a length of `4k+1` reaches the last branch, and there is no way to
    // decode that — CPython returns the encoded string itself.
    a2b_base64(&retry).unwrap_or_else(|| encoded.to_vec())
}

/// Decode `data` in `charset` with Python's `errors="replace"`. `None` when the
/// charset is one CPython would raise `LookupError` for.
fn decode_charset(data: &[u8], charset: &str) -> Option<String> {
    // CPython's codec-name normalisation: lowercase, and non-alphanumerics
    // collapse to `_`.
    let norm: String = charset
        .to_lowercase()
        .chars()
        .map(|c| if c.is_ascii_alphanumeric() { c } else { '_' })
        .collect();
    match norm.as_str() {
        "utf_8" | "utf8" | "u8" | "utf" | "utf_8_sig" | "ascii" | "us_ascii" | "usascii"
        | "646" | "ansi_x3_4_1968" | "iso_8859_1" | "iso8859_1" | "latin_1" | "latin1"
        | "latin" | "l1" | "8859" | "cp819" | "iso_ir_100" | "windows_1252" | "cp1252" | "1252" => {
        }
        _ => return None,
    }
    Some(match norm.as_str() {
        "iso_8859_1" | "iso8859_1" | "latin_1" | "latin1" | "latin" | "l1" | "8859" | "cp819"
        | "iso_ir_100" => data.iter().map(|&b| b as char).collect(),
        "windows_1252" | "cp1252" | "1252" => data.iter().map(|&b| cp1252(b)).collect(),
        "ascii" | "us_ascii" | "usascii" | "646" | "ansi_x3_4_1968" => data
            .iter()
            .map(|&b| if b < 0x80 { b as char } else { '\u{fffd}' })
            .collect(),
        _ => String::from_utf8_lossy(data).into_owned(),
    })
}

/// The 27 windows-1252 bytes that are not latin-1.
fn cp1252(b: u8) -> char {
    const HIGH: [char; 32] = [
        '\u{20ac}', '\u{fffd}', '\u{201a}', '\u{192}', '\u{201e}', '\u{2026}', '\u{2020}',
        '\u{2021}', '\u{2c6}', '\u{2030}', '\u{160}', '\u{2039}', '\u{152}', '\u{fffd}', '\u{17d}',
        '\u{fffd}', '\u{fffd}', '\u{2018}', '\u{2019}', '\u{201c}', '\u{201d}', '\u{2022}',
        '\u{2013}', '\u{2014}', '\u{2dc}', '\u{2122}', '\u{161}', '\u{203a}', '\u{153}',
        '\u{fffd}', '\u{17e}', '\u{178}',
    ];
    if (0x80..0xa0).contains(&b) {
        HIGH[(b - 0x80) as usize]
    } else {
        b as char
    }
}

// --------------------------------------------------------------------------- //
// RFC 2047 header decoding
// --------------------------------------------------------------------------- //

/// One `decode_header` word: raw bytes plus the charset it was labelled with.
struct HeaderWord {
    bytes: Vec<u8>,
    charset: Option<String>,
}

/// `email.base64mime.decode` — `a2b_base64` after the `decode_header` padding
/// fix; a leftover partial quad raises `HeaderParseError` (`None` here).
fn base64mime_decode(s: &str) -> Option<Vec<u8>> {
    let mut b = s.as_bytes().to_vec();
    let pad_err = b.len() % 4;
    if pad_err != 0 {
        b.extend_from_slice(&b"==="[..4 - pad_err]);
    }
    a2b_base64(&b)
}

/// `=\?(?P<charset>[^?]*?)\?(?P<encoding>[qQbB])\?(?P<encoded>.*?)\?=` — the
/// span of an encoded word starting at `start`, if any.
fn match_encoded_word(c: &[char], start: usize) -> Option<(String, char, String, usize)> {
    if c.get(start) != Some(&'=') || c.get(start + 1) != Some(&'?') {
        return None;
    }
    let mut i = start + 2;
    while i < c.len() && c[i] != '?' {
        i += 1;
    }
    if i >= c.len() {
        return None;
    }
    let charset: String = c[start + 2..i].iter().collect();
    let enc = *c.get(i + 1)?;
    if !matches!(enc, 'q' | 'Q' | 'b' | 'B') {
        return None;
    }
    if c.get(i + 2) != Some(&'?') {
        return None;
    }
    // The body is lazy (`.*?`), and `.` does not match a newline.
    let body_start = i + 3;
    let mut j = body_start;
    loop {
        if j + 1 >= c.len() {
            return None;
        }
        if c[j] == '?' && c[j + 1] == '=' {
            break;
        }
        if c[j] == '\n' {
            return None;
        }
        j += 1;
    }
    Some((charset, enc, c[body_start..j].iter().collect(), j + 2))
}

/// `email.quoprimime.header_decode` (`_` is a space, `=XX` is hex).
fn quopri_header_decode(s: &str) -> Vec<u8> {
    let b = s.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let hex = |x: u8| -> Option<u8> {
        match x {
            b'0'..=b'9' => Some(x - b'0'),
            b'a'..=b'f' => Some(x - b'a' + 10),
            b'A'..=b'F' => Some(x - b'A' + 10),
            _ => None,
        }
    };
    let mut i = 0usize;
    while i < b.len() {
        if b[i] == b'_' {
            out.push(b' ');
            i += 1;
            continue;
        }
        if b[i] == b'=' {
            if let (Some(h), Some(l)) = (
                b.get(i + 1).copied().and_then(hex),
                b.get(i + 2).copied().and_then(hex),
            ) {
                out.push((h << 4) | l);
                i += 3;
                continue;
            }
        }
        out.push(b[i]);
        i += 1;
    }
    out
}

/// `email.header.decode_header`, returning `None` when the header holds no
/// encoded word at all (CPython's `[(header, None)]` fast path, where the value
/// stays a `str`).
fn decode_header(header: &str) -> Option<Vec<HeaderWord>> {
    let mut words: Vec<(String, Option<char>, Option<String>)> = Vec::new();
    let mut found = false;
    for line in splitlines(header) {
        let c: Vec<char> = line.chars().collect();
        let mut i = 0usize;
        let mut chunk = String::new();
        let mut first = true;
        while i < c.len() {
            if let Some((charset, enc, body, end)) = match_encoded_word(&c, i) {
                found = true;
                let mut unencoded = std::mem::take(&mut chunk);
                if first {
                    unencoded = lstrip(&unencoded).to_string();
                    first = false;
                }
                if !unencoded.is_empty() {
                    words.push((unencoded, None, None));
                }
                words.push((
                    body,
                    Some(enc.to_ascii_lowercase()),
                    Some(charset.to_lowercase()),
                ));
                i = end;
                continue;
            }
            chunk.push(c[i]);
            i += 1;
        }
        if first {
            chunk = lstrip(&chunk).to_string();
        }
        if !chunk.is_empty() {
            words.push((chunk, None, None));
        }
    }
    if !found {
        return None;
    }
    // Drop whitespace-only words sitting between two encoded words.
    let mut drop: Vec<usize> = Vec::new();
    for n in 0..words.len() {
        if n > 1
            && words[n].1.is_some()
            && words[n - 2].1.is_some()
            && !words[n - 1].0.is_empty()
            && words[n - 1].0.chars().all(is_space)
        {
            drop.push(n - 1);
        }
    }
    for d in drop.into_iter().rev() {
        words.remove(d);
    }

    // Decode each encoded word, then collapse consecutive same-charset runs.
    let mut decoded: Vec<(Vec<u8>, Option<String>)> = Vec::new();
    for (text, enc, charset) in words {
        match enc {
            None => decoded.push((raw_unicode_escape(&text), charset)),
            Some('q') => decoded.push((quopri_header_decode(&text), charset)),
            // A `HeaderParseError` propagates out of `_decode_header`, which
            // answers with the raw value.
            Some(_) => decoded.push((base64mime_decode(&text)?, charset)),
        }
    }
    let mut collapsed: Vec<HeaderWord> = Vec::new();
    for (word, charset) in decoded {
        match collapsed.last_mut() {
            Some(last) if last.charset == charset => {
                if charset.is_none() {
                    last.bytes.push(b' ');
                }
                last.bytes.extend_from_slice(&word);
            }
            _ => collapsed.push(HeaderWord {
                bytes: word,
                charset,
            }),
        }
    }
    Some(collapsed)
}

/// `bytes(word, 'raw-unicode-escape')` — a code point below 256 becomes one
/// byte; anything above is `\uXXXX` in ASCII.
fn raw_unicode_escape(s: &str) -> Vec<u8> {
    let mut out = Vec::with_capacity(s.len());
    for ch in s.chars() {
        let cp = ch as u32;
        if cp < 0x100 {
            out.push(cp as u8);
        } else if cp < 0x10000 {
            out.extend_from_slice(format!("\\u{cp:04x}").as_bytes());
        } else {
            out.extend_from_slice(format!("\\U{cp:08x}").as_bytes());
        }
    }
    out
}

/// The Python `mailarchive._decode_header`: join the decoded words, falling
/// back to the raw value if any charset is unknown.
fn decode_header_text(value: &str) -> String {
    if value.is_empty() {
        return String::new();
    }
    let Some(words) = decode_header(value) else {
        return value.to_string();
    };
    let mut out = String::new();
    for w in words {
        let charset = w.charset.as_deref().unwrap_or("utf-8");
        match decode_charset(&w.bytes, charset) {
            Some(text) => out.push_str(&text),
            // CPython raises LookupError, which `_decode_header` catches and
            // answers with `str(value)`.
            None => return value.to_string(),
        }
    }
    out
}

// --------------------------------------------------------------------------- //
// Dates
// --------------------------------------------------------------------------- //

const DAYNAMES: [&str; 7] = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
const MONTHNAMES: [&str; 24] = [
    "jan",
    "feb",
    "mar",
    "apr",
    "may",
    "jun",
    "jul",
    "aug",
    "sep",
    "oct",
    "nov",
    "dec",
    "january",
    "february",
    "march",
    "april",
    "may",
    "june",
    "july",
    "august",
    "september",
    "october",
    "november",
    "december",
];
const TIMEZONES: [(&str, i64); 14] = [
    ("UT", 0),
    ("UTC", 0),
    ("GMT", 0),
    ("Z", 0),
    ("AST", -400),
    ("ADT", -300),
    ("EST", -500),
    ("EDT", -400),
    ("CST", -600),
    ("CDT", -500),
    ("MST", -700),
    ("MDT", -600),
    ("PST", -800),
    ("PDT", -700),
];

/// `email.utils.parsedate_tz` → `mktime_tz`, as one step.
///
/// Returns `None` when CPython's `parsedate_tz` returns `None`.
fn parse_date_to_ts(value: &str) -> Option<i64> {
    let mut data: Vec<String> = value
        .split(is_space)
        .filter(|t| !t.is_empty())
        .map(str::to_string)
        .collect();
    if data.is_empty() {
        return None;
    }
    if data[0].ends_with(',') || DAYNAMES.contains(&data[0].to_lowercase().as_str()) {
        data.remove(0);
    } else if let Some(i) = data[0].rfind(',') {
        data[0] = data[0][i + 1..].to_string();
    }
    if data.len() == 3 {
        let stuff: Vec<String> = data[0].split('-').map(str::to_string).collect();
        if stuff.len() == 3 {
            let tail: Vec<String> = data[1..].to_vec();
            data = stuff;
            data.extend(tail);
        }
    }
    if data.len() == 4 {
        let s = data[3].clone();
        let i = match s.find('+') {
            Some(i) => Some(i),
            None => s.find('-'),
        };
        match i {
            Some(i) if i > 0 => {
                data.truncate(3);
                data.push(s[..i].to_string());
                data.push(s[i..].to_string());
            }
            _ => data.push(String::new()),
        }
    }
    if data.len() < 5 {
        return None;
    }
    data.truncate(5);
    let (mut dd, mut mm, mut yy, mut tm, mut tz) = (
        data[0].clone(),
        data[1].clone(),
        data[2].clone(),
        data[3].clone(),
        data[4].clone(),
    );
    if dd.is_empty() || mm.is_empty() || yy.is_empty() {
        return None;
    }
    mm = mm.to_lowercase();
    if !MONTHNAMES.contains(&mm.as_str()) {
        // Python: `dd, mm = mm, dd.lower()`.
        let new_dd = mm.clone();
        mm = dd.to_lowercase();
        dd = new_dd;
        if !MONTHNAMES.contains(&mm.as_str()) {
            return None;
        }
    }
    let mut mon = MONTHNAMES.iter().position(|m| *m == mm)? as i64 + 1;
    if mon > 12 {
        mon -= 12;
    }
    if dd.ends_with(',') {
        dd.pop();
    }
    if yy.find(':').is_some_and(|i| i > 0) {
        std::mem::swap(&mut yy, &mut tm);
    }
    if yy.ends_with(',') {
        yy.pop();
        if yy.is_empty() {
            return None;
        }
    }
    if !yy.chars().next().is_some_and(|c| c.is_ascii_digit()) {
        std::mem::swap(&mut yy, &mut tz);
    }
    if tm.ends_with(',') {
        tm.pop();
    }
    let bits: Vec<&str> = tm.split(':').collect();
    let (thh, tmm, tss) = match bits.len() {
        2 => (bits[0].to_string(), bits[1].to_string(), "0".to_string()),
        3 => (
            bits[0].to_string(),
            bits[1].to_string(),
            bits[2].to_string(),
        ),
        1 if bits[0].contains('.') => {
            let dot: Vec<&str> = bits[0].split('.').collect();
            match dot.len() {
                2 => (dot[0].to_string(), dot[1].to_string(), "0".to_string()),
                3 => (dot[0].to_string(), dot[1].to_string(), dot[2].to_string()),
                _ => return None,
            }
        }
        _ => return None,
    };
    let mut year = py_int(&yy)?;
    let day = py_int(&dd)?;
    let hh = py_int(&thh)?;
    let mi = py_int(&tmm)?;
    let ss = py_int(&tss)?;
    if year < 100 {
        year += if year > 68 { 1900 } else { 2000 };
    }
    let tz = tz.to_uppercase();
    let mut tzoffset: Option<i64> = TIMEZONES
        .iter()
        .find(|(n, _)| *n == tz)
        .map(|(_, v)| *v)
        .or_else(|| py_int(&tz));
    if tzoffset == Some(0) && tz.starts_with('-') {
        tzoffset = None;
    }
    let tzoffset = tzoffset.map(|v| {
        if v == 0 {
            0
        } else {
            let sign = if v < 0 { -1 } else { 1 };
            let a = v.abs();
            sign * ((a / 100) * 3600 + (a % 100) * 60)
        }
    });
    let t = timegm(year, mon, day, hh, mi, ss);
    // `mktime_tz`: no zone means local time; this port assumes UTC (see the
    // module-level divergence note).
    Some(t - tzoffset.unwrap_or(0))
}

/// Python `int(s)` (base 10, with `+`/`-` and surrounding whitespace).
fn py_int(s: &str) -> Option<i64> {
    let t = strip(s);
    let body = t.strip_prefix(['+', '-']).unwrap_or(t);
    if body.is_empty() || !body.chars().all(|c| c.is_ascii_digit()) {
        return None;
    }
    t.parse().ok()
}

// --------------------------------------------------------------------------- //
// Message → Msg
// --------------------------------------------------------------------------- //

/// `_body_text`: the best-effort plain-text body of one message.
fn body_text(part: &Part) -> String {
    // Every failure below (an unknown charset, a payload that will not decode)
    // raises inside the Python `try:`, whose `except Exception: return ""`
    // swallows it — so an undecodable body is empty, never salvaged.
    if part.is_multipart() {
        let mut all: Vec<&Part> = Vec::new();
        part.walk(&mut all);
        for p in all {
            if p.content_type() == "text/plain" {
                let payload = p.payload_decoded().unwrap_or_default();
                let cs = p.charset().unwrap_or_else(|| "utf-8".to_string());
                return decode_charset(&payload, &cs).unwrap_or_default();
            }
        }
        return String::new();
    }
    let payload = part.payload_decoded().unwrap_or_default();
    let cs = part.charset().unwrap_or_else(|| "utf-8".to_string());
    decode_charset(&payload, &cs).unwrap_or_default()
}

fn parse_message(chunk: &[u8]) -> Msg {
    let part = parse_part(chunk);
    let mut subject = decode_header_text(&part.get_str("subject"));
    if subject.is_empty() {
        subject = "(no subject)".to_string();
    }
    let mut sender = decode_header_text(&part.get_str("from"));
    if sender.is_empty() {
        sender = "(unknown)".to_string();
    }
    let mid = strip(&part.get_str("message-id")).to_string();
    let irt = strip(&part.get_str("in-reply-to")).to_string();
    let ts = match part.get("date") {
        Some(_) => {
            let raw = part.get_str("date");
            if raw.is_empty() {
                0
            } else {
                parse_date_to_ts(&raw).unwrap_or(0)
            }
        }
        None => 0,
    };
    let body: String = body_text(&part).chars().take(MAX_BODY).collect();
    let upper = subject.to_uppercase();
    let is_patch =
        diff_start_search(&body) || upper.contains("[PATCH") || upper.starts_with("PATCH");
    Msg {
        subject,
        sender,
        ts,
        mid,
        in_reply_to: irt,
        body,
        is_patch,
        raw: as_bytes(&part).unwrap_or_default(),
    }
}
