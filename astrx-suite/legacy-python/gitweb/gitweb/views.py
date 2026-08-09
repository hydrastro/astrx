"""Server-rendered HTML views.  No JavaScript is emitted anywhere.

Every view returns a complete HTML document as a ``str``.  All dynamic values
are passed through :func:`gitweb.markup.esc` (or the already-safe Markdown /
diff renderers) before they reach the output.
"""

from __future__ import annotations

import contextvars
from typing import List, Optional
from urllib.parse import quote, urlencode

from . import gitcmd
from .markup import (
    DiffFile,
    atom_date,
    esc,
    highlight_source,
    iso_date,
    relative_date,
    xml_escape,
)

# --------------------------------------------------------------------------- #
# Reverse-proxy sub-path mounting (``--url-prefix``)
# --------------------------------------------------------------------------- #
#
# The mount prefix is held in a :class:`~contextvars.ContextVar` set at the top
# of each request.  Because every connection runs in its own thread (and each
# thread gets its own context), concurrent requests with different prefixes can
# never cross-contaminate, unlike a plain module global.

_URL_PREFIX: "contextvars.ContextVar[str]" = contextvars.ContextVar(
    "gitweb_url_prefix", default=""
)


def push_url_prefix(prefix: str) -> None:
    """Bind the URL prefix for the current request/thread."""
    _URL_PREFIX.set(prefix or "")


def url_prefix() -> str:
    return _URL_PREFIX.get()

# --------------------------------------------------------------------------- #
# Styling (inline, no external assets, no JS)
# --------------------------------------------------------------------------- #

CSS = """
:root{--fg:#1f2328;--muted:#656d76;--bg:#ffffff;--soft:#f6f8fa;
--border:#d0d7de;--link:#0969da;--add-bg:#e6ffec;--add-br:#a6f0b8;
--del-bg:#ffebe9;--del-br:#f7c4c0;--hunk:#57606a;--hunk-bg:#eef4ff;}
*{box-sizing:border-box}
body{margin:0;color:var(--fg);background:var(--bg);
font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
a{color:var(--link);text-decoration:none}
a:hover{text-decoration:underline}
code,pre,.mono,td.line,td.diff-line{font-family:ui-monospace,SFMono-Regular,
Menlo,Consolas,monospace}
.wrap{max-width:1080px;margin:0 auto;padding:0 16px 48px}
header.top{background:var(--soft);border-bottom:1px solid var(--border);
padding:10px 0;margin-bottom:20px}
header.top .wrap{padding-top:0;padding-bottom:0}
header.top a.brand{font-weight:700;font-size:16px;color:var(--fg)}
.repo-head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;
margin:6px 0 2px}
.repo-head h1{font-size:20px;margin:0}
.desc{color:var(--muted);margin:2px 0 12px}
nav.tabs{border-bottom:1px solid var(--border);margin:0 0 18px;
display:flex;gap:4px;flex-wrap:wrap}
nav.tabs a{padding:6px 12px;border:1px solid transparent;border-bottom:none;
border-radius:6px 6px 0 0;color:var(--muted)}
nav.tabs a.active{color:var(--fg);border-color:var(--border);
background:var(--bg);position:relative;top:1px}
table{border-collapse:collapse;width:100%}
table.list td,table.list th{padding:8px 10px;border-bottom:1px solid var(--border);
text-align:left;vertical-align:top}
table.list th{color:var(--muted);font-weight:600;font-size:12px;
text-transform:uppercase;letter-spacing:.03em}
tr:hover td{background:var(--soft)}
.muted{color:var(--muted)}
.mono{font-size:12.5px}
.sha{color:var(--muted)}
.pill{display:inline-block;padding:0 7px;border:1px solid var(--border);
border-radius:999px;font-size:12px;color:var(--muted);background:var(--soft)}
.crumbs{margin:0 0 12px;font-size:13px}
.box{border:1px solid var(--border);border-radius:6px;overflow:hidden;
margin:0 0 20px}
.box .box-head{background:var(--soft);border-bottom:1px solid var(--border);
padding:8px 12px;font-weight:600}
.box .box-body{padding:16px}
.readme :first-child{margin-top:0}
.readme pre{background:var(--soft);padding:12px;border-radius:6px;overflow:auto}
.readme code{background:var(--soft);padding:.1em .3em;border-radius:4px}
.readme pre code{background:none;padding:0}
.readme blockquote{margin:0;padding:0 12px;color:var(--muted);
border-left:3px solid var(--border)}
.readme h1,.readme h2{border-bottom:1px solid var(--border);padding-bottom:.2em}
.readme table.md-table{border-collapse:collapse;margin:0 0 12px}
.readme table.md-table th,.readme table.md-table td{border:1px solid var(--border);
padding:6px 12px}
.readme table.md-table th{background:var(--soft)}
.readme li.task{list-style:none;margin-left:-1.2em}
.readme li.task input{margin-right:.4em}
.readme img{max-width:100%}
/* blob + blame line tables */
table.code{border:1px solid var(--border);border-radius:6px;overflow:hidden}
table.code td{padding:0 10px;vertical-align:top;white-space:pre;
font-size:12.5px;line-height:1.45}
td.lineno{width:1%;text-align:right;color:var(--muted);
background:var(--soft);border-right:1px solid var(--border);
user-select:none;-webkit-user-select:none}
td.lineno a{color:var(--muted)}
td.line{width:100%}
td.b-sha{background:var(--soft);border-right:1px solid var(--border);
white-space:nowrap}
td.b-auth{color:var(--muted);border-right:1px solid var(--border);
white-space:nowrap;max-width:160px;overflow:hidden;text-overflow:ellipsis}
/* diff */
.file{border:1px solid var(--border);border-radius:6px;overflow:hidden;
margin:0 0 16px}
.file-head{background:var(--soft);border-bottom:1px solid var(--border);
padding:8px 12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.file-head .path{font-family:ui-monospace,monospace;font-size:13px}
.stat-add{color:#1a7f37;font-weight:600}
.stat-del{color:#cf222e;font-weight:600}
table.diff{width:100%}
table.diff td{padding:0 12px;white-space:pre;font-size:12.5px;line-height:1.45}
tr.diff-add td{background:var(--add-bg)}
tr.diff-del td{background:var(--del-bg)}
tr.diff-hunk td{background:var(--hunk-bg);color:var(--hunk)}
tr.diff-add:hover td,tr.diff-del:hover td,tr.diff-hunk:hover td{filter:none}
.pager{display:flex;gap:10px;margin:16px 0;align-items:center}
.pager a,.pager span{padding:5px 12px;border:1px solid var(--border);
border-radius:6px}
.pager span.disabled{color:var(--muted);background:var(--soft)}
footer{color:var(--muted);font-size:12px;border-top:1px solid var(--border);
margin-top:32px;padding-top:12px}
.warn{background:#fff8c5;border:1px solid #eac54f;border-radius:6px;
padding:10px 12px;margin:0 0 16px}
img.blob-img{max-width:100%;height:auto;background:var(--soft);
border:1px solid var(--border)}
.badge-ok{display:inline-block;padding:0 8px;border-radius:999px;font-size:12px;
color:#0f5132;background:#d1e7dd;border:1px solid #a3cfbb}
.badge-warn{display:inline-block;padding:0 8px;border-radius:999px;font-size:12px;
color:#842029;background:#f8d7da;border:1px solid #f1aeb5}
tr.hl td,tr:target td{background:#fff8c5}
details.switch{margin:0 0 12px}
details.switch summary{cursor:pointer;display:inline-block;padding:5px 12px;
border:1px solid var(--border);border-radius:6px;background:var(--soft)}
details.switch .menu{border:1px solid var(--border);border-radius:6px;
margin-top:6px;padding:8px 12px;max-height:280px;overflow:auto}
details.switch .menu a{display:inline-block;margin:2px 8px 2px 0}
pre.clone-cmd{background:var(--soft);border:1px solid var(--border);
border-radius:6px;padding:10px 12px;margin:0;overflow:auto;font-size:12.5px}
form.search{display:flex;gap:8px;margin:0 0 16px;flex-wrap:wrap;align-items:center}
form.search input[type=search]{flex:1 1 220px;min-width:160px;padding:6px 10px;
border:1px solid var(--border);border-radius:6px;font:inherit}
form.search select,form.search button{padding:6px 12px;border:1px solid var(--border);
border-radius:6px;background:var(--soft);font:inherit;cursor:pointer}
.search-file{margin:0 0 16px}
.search-file .box-head{font-family:ui-monospace,monospace;font-size:13px}
table.grep td.line{white-space:pre-wrap;word-break:break-word}
/* commit graph */
.graph-wrap{display:flex;align-items:flex-start;border:1px solid var(--border);
border-radius:6px;overflow:auto;margin:0 0 16px}
svg.graph-svg{flex:none;display:block;background:var(--bg)}
.graph-rows{flex:1;min-width:0}
.graph-row{height:24px;line-height:24px;padding:0 10px;white-space:nowrap;
overflow:hidden;text-overflow:ellipsis;border-bottom:1px solid var(--border)}
.graph-row:last-child{border-bottom:none}
.graph-row:hover{background:var(--soft)}
"""


# --------------------------------------------------------------------------- #
# URL builders
# --------------------------------------------------------------------------- #


def u_home() -> str:
    return url_prefix() + "/"


def u_repo(name: str) -> str:
    return url_prefix() + "/" + quote(name, safe="") + "/"


def u_action(name: str, action: str, **params) -> str:
    base = url_prefix() + "/" + quote(name, safe="") + "/" + action
    clean = {k: v for k, v in params.items() if v not in (None, "")}
    if clean:
        base += "?" + urlencode(clean)
    return base


def _safe_external(url: str) -> Optional[str]:
    """Return an escaped href for an http(s) URL, else ``None`` (not linkable)."""
    probe = (url or "").strip()
    low = probe.lower()
    if low.startswith("https://") or low.startswith("http://"):
        return esc(probe)
    return None


# --------------------------------------------------------------------------- #
# Document shell
# --------------------------------------------------------------------------- #


def _tabs(repo_name: str, active: str) -> str:
    items = [
        ("summary", "Summary", u_repo(repo_name)),
        ("refs", "Refs", u_action(repo_name, "refs")),
        ("log", "Log", u_action(repo_name, "log")),
        ("graph", "Graph", u_action(repo_name, "graph")),
        ("tree", "Tree", u_action(repo_name, "tree")),
        ("releases", "Releases", u_action(repo_name, "releases")),
        ("patches", "Patches", u_action(repo_name, "patches")),
        ("search", "Search", u_action(repo_name, "search")),
    ]
    out = ['<nav class="tabs">']
    for key, label, url in items:
        cls = ' class="active"' if key == active else ""
        out.append(f'<a{cls} href="{esc(url)}">{esc(label)}</a>')
    out.append("</nav>")
    return "".join(out)


def page(
    title: str,
    body: str,
    *,
    repo_name: Optional[str] = None,
    active_tab: str = "",
    repo_desc: str = "",
) -> str:
    """Wrap ``body`` in the full HTML document (header, tabs, footer)."""
    head = [
        "<!doctype html>",
        '<html lang="en"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width,initial-scale=1">',
        '<meta name="referrer" content="no-referrer">',
        f"<title>{esc(title)}</title>",
        f"<style>{CSS}</style>",
        "</head><body>",
        '<header class="top"><div class="wrap">',
        f'<a class="brand" href="{esc(u_home())}">gitweb</a>',
        "</div></header>",
        '<div class="wrap">',
    ]
    # OpenSearch autodiscovery: a browser can add gitweb as a search engine.
    # The site-level descriptor (repository finder) is advertised on every page.
    head.insert(
        6,
        '<link rel="search" type="application/opensearchdescription+xml" '
        f'title="gitweb repositories" href="{esc(url_prefix() + "/opensearch.xml")}">',
    )
    if repo_name is not None:
        # Atom autodiscovery so feed readers find the repo feed.
        head.insert(
            6,
            '<link rel="alternate" type="application/atom+xml" '
            f'title="{esc(repo_name)} commits" href="{esc(u_action(repo_name, "atom"))}">',
        )
        # Per-repo code-search descriptor.
        os_href = url_prefix() + "/" + quote(repo_name, safe="") + "/opensearch.xml"
        head.insert(
            6,
            '<link rel="search" type="application/opensearchdescription+xml" '
            f'title="{esc(repo_name)} code" href="{esc(os_href)}">',
        )
        head.append('<div class="repo-head">')
        head.append(f'<h1><a href="{esc(u_repo(repo_name))}">{esc(repo_name)}</a></h1>')
        head.append("</div>")
        if repo_desc:
            head.append(f'<div class="desc">{esc(repo_desc)}</div>')
        head.append(_tabs(repo_name, active_tab))
    head.append(body)
    head.append(
        '<footer>Served by gitweb — a read-only, no-JavaScript git viewer.'
        "</footer></div></body></html>"
    )
    return "".join(head)


def error_page(code: int, message: str) -> str:
    body = (
        f'<div class="box"><div class="box-head">Error {esc(code)}</div>'
        f'<div class="box-body"><p>{esc(message)}</p>'
        f'<p><a href="{esc(u_home())}">&larr; Back to repositories</a></p>'
        "</div></div>"
    )
    return page(f"Error {code}", body)


# --------------------------------------------------------------------------- #
# Repo list
# --------------------------------------------------------------------------- #


def repo_list(repos: List[gitcmd.Repo], q: str = "") -> str:
    rows = []
    for r in repos:
        when = relative_date(r.last_commit_ts) if r.last_commit_ts else "empty"
        kind = "bare" if r.bare else "worktree"
        rows.append(
            "<tr>"
            f'<td><a href="{esc(u_repo(r.name))}">{esc(r.name)}</a> '
            f'<span class="pill">{esc(kind)}</span></td>'
            f'<td class="muted">{esc(r.description) or "&mdash;"}</td>'
            f'<td class="muted">{esc(when)}</td>'
            "</tr>"
        )
    if not rows:
        empty = (
            "No repositories match that filter."
            if q
            else "No repositories found under the configured root."
        )
        table = f'<p class="muted">{esc(empty)}</p>'
    else:
        table = (
            '<table class="list"><thead><tr><th>Repository</th>'
            "<th>Description</th><th>Last commit</th></tr></thead><tbody>"
            + "".join(rows)
            + "</tbody></table>"
        )
    # A no-JS repository finder (form-action 'self').  Submitting filters the
    # list by name/description; this is also the target of the site-level
    # OpenSearch descriptor, so a browser can jump to a repo from the URL bar.
    form = (
        f'<form class="search" method="get" action="{esc(u_home())}" role="search">'
        f'<input type="search" name="q" value="{esc(q)}" '
        'placeholder="Filter repositories…" aria-label="Filter repositories">'
        "<button type=\"submit\">Filter</button>"
        "</form>"
    )
    body = (
        '<div class="repo-head"><h1>Repositories</h1></div>'
        '<div class="desc">Read-only browser</div>' + form + table
    )
    return page("Repositories", body)


# --------------------------------------------------------------------------- #
# Summary
# --------------------------------------------------------------------------- #


def _commit_rows(repo_name: str, rows: List[gitcmd.CommitRow]) -> str:
    out = []
    for c in rows:
        url = u_action(repo_name, "commit", id=c.sha)
        out.append(
            "<tr>"
            f'<td class="mono"><a href="{esc(url)}">{esc(c.short)}</a></td>'
            f"<td>{esc(c.subject)}</td>"
            f'<td class="muted">{esc(c.author)}</td>'
            f'<td class="muted">{esc(relative_date(c.ts))}</td>'
            "</tr>"
        )
    return "".join(out)


def summary(
    repo: gitcmd.Repo,
    branch: str,
    commits: List[gitcmd.CommitRow],
    readme_html: Optional[str],
    readme_name: Optional[str],
    clone_url: Optional[str] = None,
) -> str:
    parts = [
        '<div class="box"><div class="box-head">Overview</div><div class="box-body">',
        f'<p>Default branch: <span class="pill">{esc(branch)}</span></p>',
        f'<p><a href="{esc(u_action(repo.name, "log", ref=branch))}">'
        "Browse commit log</a> &middot; "
        f'<a href="{esc(u_action(repo.name, "tree", ref=branch))}">Browse files</a> &middot; '
        f'<a href="{esc(u_action(repo.name, "refs"))}">Branches &amp; tags</a></p>',
        "</div></div>",
    ]
    if clone_url:
        # Read-only clone transport (Git Smart HTTP).  The URL is fully escaped.
        parts.append(
            '<div class="box"><div class="box-head">Clone (read-only)</div>'
            '<div class="box-body">'
            f'<pre class="clone-cmd">git clone {esc(clone_url)}</pre>'
            "</div></div>"
        )
    if commits:
        parts.append(
            '<div class="box"><div class="box-head">Latest commits</div>'
            '<table class="list"><tbody>'
            + _commit_rows(repo.name, commits)
            + "</tbody></table></div>"
        )
    if readme_html is not None:
        parts.append(
            '<div class="box"><div class="box-head">'
            f"{esc(readme_name or 'README')}</div>"
            f'<div class="box-body readme">{readme_html}</div></div>'
        )
    return page(
        repo.name,
        "".join(parts),
        repo_name=repo.name,
        active_tab="summary",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Refs
# --------------------------------------------------------------------------- #


def refs(
    repo: gitcmd.Repo,
    branch_rows: List[gitcmd.RefRow],
    tag_rows: List[gitcmd.RefRow],
) -> str:
    def render(rows: List[gitcmd.RefRow], title: str) -> str:
        body = []
        for r in rows:
            log_url = u_action(repo.name, "log", ref=r.name)
            tree_url = u_action(repo.name, "tree", ref=r.name)
            commit_url = u_action(repo.name, "commit", id=r.target)
            body.append(
                "<tr>"
                f'<td><a href="{esc(log_url)}">{esc(r.name)}</a></td>'
                f'<td class="mono"><a href="{esc(commit_url)}">{esc(r.target)}</a></td>'
                f"<td>{esc(r.subject)}</td>"
                f'<td class="muted">{esc(relative_date(r.ts))}</td>'
                f'<td><a href="{esc(tree_url)}">tree</a></td>'
                "</tr>"
            )
        if not body:
            inner = f'<div class="box-body muted">No {esc(title.lower())}.</div>'
        else:
            inner = (
                '<table class="list"><thead><tr><th>Name</th><th>Commit</th>'
                "<th>Subject</th><th>Updated</th><th></th></tr></thead><tbody>"
                + "".join(body)
                + "</tbody></table>"
            )
        return f'<div class="box"><div class="box-head">{esc(title)}</div>{inner}</div>'

    body = render(branch_rows, "Branches") + render(tag_rows, "Tags")
    return page(
        f"{repo.name}: refs",
        body,
        repo_name=repo.name,
        active_tab="refs",
        repo_desc=repo.description,
    )


def releases(repo: gitcmd.Repo, tag_rows: List[gitcmd.RefRow]) -> str:
    """A release list built from tags (newest first): notes, date, commit and a
    source snapshot download per tag, plus an Atom feed for release watchers."""
    rows = []
    for r in tag_rows:
        dl = u_action(repo.name, "archive", ref=r.name)
        commit_url = u_action(repo.name, "commit", id=r.target)
        tree_url = u_action(repo.name, "tree", ref=r.name)
        rows.append(
            "<tr>"
            f"<td><strong>{esc(r.name)}</strong></td>"
            f"<td>{esc(r.subject)}</td>"
            f'<td class="muted">{esc(relative_date(r.ts))}</td>'
            f'<td class="mono"><a href="{esc(commit_url)}">{esc(r.target)}</a></td>'
            f'<td><a href="{esc(dl)}">tar.gz</a> &middot; '
            f'<a href="{esc(tree_url)}">browse</a></td>'
            "</tr>"
        )
    if rows:
        inner = (
            '<table class="list"><thead><tr><th>Tag</th><th>Notes</th>'
            "<th>Date</th><th>Commit</th><th>Download</th></tr></thead><tbody>"
            + "".join(rows)
            + "</tbody></table>"
        )
    else:
        inner = ('<div class="box-body muted">No releases yet. Tag a commit '
                 "(<code>git tag -a v1.0</code>) to publish one.</div>")
    feed = u_action(repo.name, "releases.atom")
    body = (
        f'<div class="box"><div class="box-head">Releases '
        f'<a class="muted" href="{esc(feed)}" style="float:right">Atom</a>'
        f"</div>{inner}</div>"
    )
    return page(
        f"{repo.name}: releases",
        body,
        repo_name=repo.name,
        active_tab="releases",
        repo_desc=repo.description,
    )


def releases_atom(repo: gitcmd.Repo, tag_rows: List[gitcmd.RefRow],
                  base_url: str = "") -> str:
    """Atom 1.0 feed of releases (tags).  Every field is XML-escaped (dropping
    XML-illegal control chars) so one hostile tag name can't break the feed."""
    def abs_url(rel: str) -> str:
        return base_url + rel if base_url else rel

    self_link = abs_url(u_action(repo.name, "releases.atom"))
    repo_link = abs_url(u_repo(repo.name))
    updated = atom_date(tag_rows[0].ts) if tag_rows else atom_date(0)
    parts = [
        '<?xml version="1.0" encoding="utf-8"?>',
        '<feed xmlns="http://www.w3.org/2005/Atom">',
        f"<title>{xml_escape(repo.name)}: releases</title>",
        f"<id>{xml_escape(self_link or ('urn:gitweb:' + repo.name + ':releases'))}</id>",
        f"<updated>{xml_escape(updated)}</updated>",
        f'<link rel="self" href="{xml_escape(self_link)}"/>',
        f'<link rel="alternate" href="{xml_escape(repo_link)}"/>',
        "<generator>gitweb</generator>",
    ]
    for r in tag_rows:
        entry_link = abs_url(u_action(repo.name, "archive", ref=r.name))
        parts.append("<entry>")
        parts.append(f"<title>{xml_escape(r.name)}</title>")
        parts.append(
            f"<id>urn:gitweb:release:{xml_escape(repo.name)}:{xml_escape(r.name)}</id>")
        parts.append(f"<updated>{xml_escape(atom_date(r.ts))}</updated>")
        parts.append(f'<link rel="alternate" href="{xml_escape(entry_link)}"/>')
        parts.append(f"<author><name>{xml_escape(r.author)}</name></author>")
        parts.append(f'<summary type="text">{xml_escape(r.subject)}</summary>')
        parts.append("</entry>")
    parts.append("</feed>")
    return "".join(parts)


# --------------------------------------------------------------------------- #
# Log
# --------------------------------------------------------------------------- #


def log_page(
    repo: gitcmd.Repo,
    ref: str,
    rows: List[gitcmd.CommitRow],
    page_num: int,
    total_pages: int,
) -> str:
    header = (
        f'<p class="muted">Log for <span class="pill">{esc(ref)}</span> '
        f"&mdash; page {esc(page_num)} of {esc(max(total_pages, 1))}</p>"
    )
    if rows:
        table = (
            '<table class="list"><thead><tr><th>Commit</th><th>Subject</th>'
            "<th>Author</th><th>When</th></tr></thead><tbody>"
            + _commit_rows(repo.name, rows)
            + "</tbody></table>"
        )
    else:
        table = '<p class="muted">No commits.</p>'

    pager = ['<div class="pager">']
    if page_num > 1:
        prev = u_action(repo.name, "log", ref=ref, page=page_num - 1)
        pager.append(f'<a href="{esc(prev)}">&larr; Newer</a>')
    else:
        pager.append('<span class="disabled">&larr; Newer</span>')
    if page_num < total_pages:
        nxt = u_action(repo.name, "log", ref=ref, page=page_num + 1)
        pager.append(f'<a href="{esc(nxt)}">Older &rarr;</a>')
    else:
        pager.append('<span class="disabled">Older &rarr;</span>')
    pager.append("</div>")

    return page(
        f"{repo.name}: log",
        header + table + "".join(pager),
        repo_name=repo.name,
        active_tab="log",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Commit
# --------------------------------------------------------------------------- #


def _render_diff(files: List[DiffFile]) -> str:
    out = []
    for f in files:
        head = (
            '<div class="file"><div class="file-head">'
            f'<span class="path">{esc(f.display_path)}</span>'
            f'<span class="pill">{esc(f.status)}</span>'
            f'<span class="stat-add">+{esc(f.additions)}</span>'
            f'<span class="stat-del">-{esc(f.deletions)}</span>'
            "</div>"
        )
        if f.binary:
            body = '<div class="box-body muted">Binary file not shown.</div>'
        elif not f.lines:
            body = '<div class="box-body muted">No textual changes.</div>'
        else:
            trs = []
            for ln in f.lines:
                trs.append(
                    f'<tr class="diff-{esc(ln.kind)}"><td class="diff-line">'
                    f"{esc(ln.text)}</td></tr>"
                )
            body = '<table class="diff"><tbody>' + "".join(trs) + "</tbody></table>"
        out.append(head + body + "</div>")
    if not out:
        return '<p class="muted">No changes (empty or merge commit).</p>'
    return "".join(out)


# git %G? status code -> human label.
_SIG_LABELS = {
    "G": "good signature",
    "U": "good signature, unknown validity",
    "X": "good signature that has expired",
    "Y": "good signature made by an expired key",
    "R": "good signature made by a revoked key",
    "B": "bad signature",
    "E": "signature cannot be checked",
}


def _signature_badge(commit: "gitcmd.Commit") -> str:
    """A small inline badge for the commit header (empty when unsigned)."""
    if commit.signature_verified:
        return ' <span class="badge-ok" title="Verified signature">Verified</span>'
    if commit.signature_present:
        return ' <span class="badge-warn" title="Signature problem">Unverified</span>'
    return ""


def _signature_detail(commit: "gitcmd.Commit") -> str:
    label = _SIG_LABELS.get(commit.signature_status, "signed")
    key = f' key {esc(commit.signing_key)}' if commit.signing_key else ""
    if commit.signature_verified:
        return f'<span class="badge-ok">Verified</span> &mdash; {esc(label)}{key}'
    return f'<span class="badge-warn">{esc(label)}</span>{key}'


def commit_page(
    repo: gitcmd.Repo,
    commit: gitcmd.Commit,
    files: List[DiffFile],
) -> str:
    parents = []
    for p in commit.parents:
        url = u_action(repo.name, "commit", id=p)
        parents.append(f'<a class="mono" href="{esc(url)}">{esc(p[:8])}</a>')
    parent_html = ", ".join(parents) if parents else '<span class="muted">none (root)</span>'

    total_add = sum(f.additions for f in files)
    total_del = sum(f.deletions for f in files)

    badge = _signature_badge(commit)
    meta = [
        '<div class="box"><div class="box-head">',
        f"{esc(commit.subject)}{badge}</div><div class=\"box-body\">",
    ]
    if commit.body:
        meta.append(f"<pre>{esc(commit.body)}</pre>")
    sig_row = ""
    if commit.signature_present:
        sig_row = f"<tr><th>Signature</th><td>{_signature_detail(commit)}</td></tr>"
    meta.append(
        '<table class="list"><tbody>'
        f'<tr><th>Commit</th><td class="mono">{esc(commit.sha)}</td></tr>'
        f"<tr><th>Author</th><td>{esc(commit.author_name)} "
        f'&lt;{esc(commit.author_email)}&gt; <span class="muted">'
        f"{esc(commit.author_date)}</span></td></tr>"
        f"<tr><th>Committer</th><td>{esc(commit.committer_name)} "
        f'&lt;{esc(commit.committer_email)}&gt; <span class="muted">'
        f"{esc(commit.committer_date)}</span></td></tr>"
        f"{sig_row}"
        f"<tr><th>Parents</th><td>{parent_html}</td></tr>"
        f"<tr><th>Tree</th><td>"
        f'<a href="{esc(u_action(repo.name, "tree", ref=commit.sha))}">browse</a>'
        "</td></tr>"
        f"<tr><th>Patch</th><td>"
        f'<a href="{esc(u_action(repo.name, "commit.patch", id=commit.sha))}">'
        "download (mbox)</a></td></tr>"
        "</tbody></table>"
        f'<p class="muted">{esc(len(files))} file(s) changed, '
        f'<span class="stat-add">+{esc(total_add)}</span> '
        f'<span class="stat-del">-{esc(total_del)}</span></p>'
        "</div></div>"
    )
    body = "".join(meta) + _render_diff(files)
    return page(
        f"{repo.name}: {commit.short}",
        body,
        repo_name=repo.name,
        active_tab="log",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Tree + breadcrumbs
# --------------------------------------------------------------------------- #


def _breadcrumbs(repo_name: str, ref: str, path: str, leaf_is_blob: bool) -> str:
    crumbs = [
        f'<a href="{esc(u_action(repo_name, "tree", ref=ref))}">{esc(repo_name)}</a>'
    ]
    parts = [p for p in path.split("/") if p]
    accum = ""
    for idx, part in enumerate(parts):
        accum = f"{accum}/{part}" if accum else part
        last = idx == len(parts) - 1
        if last and leaf_is_blob:
            crumbs.append(esc(part))
        else:
            url = u_action(repo_name, "tree", ref=ref, path=accum)
            crumbs.append(f'<a href="{esc(url)}">{esc(part)}</a>')
    sep = ' <span class="muted">/</span> '
    return f'<div class="crumbs mono">{sep.join(crumbs)} '
    # (closing div added by caller after appending the ref pill)


def _crumb_bar(repo_name: str, ref: str, path: str, leaf_is_blob: bool) -> str:
    inner = _breadcrumbs(repo_name, ref, path, leaf_is_blob)
    return inner + f'<span class="pill">{esc(ref)}</span></div>'


def ref_switcher(
    repo_name: str,
    action: str,
    ref: str,
    path: str,
    branches: List[str],
    tags: List[str],
    commit_sha: str = "",
) -> str:
    """A no-JS ref switcher (HTML5 ``<details>``) plus a sha-pinned permalink.

    Every entry is a plain link that keeps the current ``action``/``path`` and
    only swaps the ref, so switching branches/tags needs neither JavaScript nor
    a form (our CSP forbids form submission).  The permalink pins the current
    view to the resolved commit sha for a stable, immutable URL.
    """
    def links(names: List[str]) -> str:
        out = []
        for name in names:
            url = u_action(repo_name, action, ref=name, path=path or None)
            marker = " &check;" if name == ref else ""
            out.append(f'<a href="{esc(url)}">{esc(name)}{marker}</a>')
        return " ".join(out) if out else '<span class="muted">none</span>'

    parts = [
        '<details class="switch"><summary>Switch ref &amp; permalink</summary>',
        '<div class="menu">',
        "<div><strong>Branches:</strong> " + links(branches) + "</div>",
        "<div><strong>Tags:</strong> " + links(tags) + "</div>",
    ]
    if commit_sha:
        perma = u_action(repo_name, action, ref=commit_sha, path=path or None)
        parts.append(
            f'<div><strong>Permalink:</strong> '
            f'<a class="mono" href="{esc(perma)}">{esc(commit_sha[:12])}</a></div>'
        )
    parts.append("</div></details>")
    return "".join(parts)


def _tree_pager(repo_name: str, ref: str, path: str, page_num: int, total_pages: int) -> str:
    if total_pages <= 1:
        return ""
    out = ['<div class="pager">']
    if page_num > 1:
        prev = u_action(repo_name, "tree", ref=ref, path=path, page=page_num - 1)
        out.append(f'<a href="{esc(prev)}">&larr; Prev</a>')
    else:
        out.append('<span class="disabled">&larr; Prev</span>')
    out.append(f'<span class="disabled">page {esc(page_num)} of {esc(total_pages)}</span>')
    if page_num < total_pages:
        nxt = u_action(repo_name, "tree", ref=ref, path=path, page=page_num + 1)
        out.append(f'<a href="{esc(nxt)}">Next &rarr;</a>')
    else:
        out.append('<span class="disabled">Next &rarr;</span>')
    out.append("</div>")
    return "".join(out)


def tree_page(
    repo: gitcmd.Repo,
    ref: str,
    path: str,
    entries: List[gitcmd.TreeEntry],
    readme_html: Optional[str],
    readme_name: Optional[str],
    *,
    page_num: int = 1,
    total_pages: int = 1,
    total_entries: Optional[int] = None,
    branches: Optional[List[str]] = None,
    tags: Optional[List[str]] = None,
    commit_sha: str = "",
    submodules: Optional[dict] = None,
) -> str:
    bar = _crumb_bar(repo.name, ref, path, leaf_is_blob=False)
    switcher = ref_switcher(
        repo.name, "tree", ref, path, branches or [], tags or [], commit_sha
    )
    submodules = submodules or {}
    rows = []
    if path and page_num == 1:
        parent = "/".join(path.split("/")[:-1])
        up = u_action(repo.name, "tree", ref=ref, path=parent)
        rows.append(
            f'<tr><td colspan="3"><a href="{esc(up)}">..</a></td></tr>'
        )
    for e in entries:
        submodule_extra = ""
        if e.type == "tree":
            url = u_action(repo.name, "tree", ref=ref, path=e.path)
            icon = "dir"
        elif e.type == "commit":
            # A gitlink: show the pinned sha and, when known, the upstream URL.
            url = ""
            icon = "submodule"
            sub_url = submodules.get(e.path)
            pin = f'<span class="mono muted"> @ {esc(e.sha[:12])}</span>'
            link = ""
            if sub_url:
                safe = _safe_external(sub_url)
                if safe:
                    link = f' <a href="{safe}" rel="nofollow noopener">{esc(sub_url)}</a>'
                else:
                    link = f' <span class="muted">{esc(sub_url)}</span>'
            submodule_extra = pin + link
        else:
            url = u_action(repo.name, "blob", ref=ref, path=e.path)
            icon = "file"
        name_cell = (
            f'<a href="{esc(url)}">{esc(e.name)}</a>' if url else esc(e.name)
        )
        size = "" if e.size is None else f"{e.size}"
        rows.append(
            "<tr>"
            f'<td class="mono muted">{esc(e.mode)}</td>'
            f'<td>{name_cell} <span class="pill">{esc(icon)}</span>{submodule_extra}</td>'
            f'<td class="muted" style="text-align:right">{esc(size)}</td>'
            "</tr>"
        )
    count_note = ""
    if total_entries is not None and total_pages > 1:
        count_note = (
            f'<div class="box-head muted">{esc(total_entries)} entries</div>'
        )
    table = (
        '<div class="box">' + count_note + '<table class="list"><thead><tr><th>Mode</th>'
        "<th>Name</th><th>Size</th></tr></thead><tbody>"
        + "".join(rows)
        + "</tbody></table></div>"
    )
    pager = _tree_pager(repo.name, ref, path, page_num, total_pages)
    readme_box = ""
    if readme_html is not None:
        readme_box = (
            '<div class="box"><div class="box-head">'
            f"{esc(readme_name or 'README')}</div>"
            f'<div class="box-body readme">{readme_html}</div></div>'
        )
    return page(
        f"{repo.name}: {path or '/'}",
        bar + switcher + table + pager + readme_box,
        repo_name=repo.name,
        active_tab="tree",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Blob
# --------------------------------------------------------------------------- #


def _numbered_lines(text: str, path: str = "", hl_ranges=None, highlight: bool = False) -> str:
    lines = text.split("\n")
    # Drop the trailing empty element produced by a final newline.
    if lines and lines[-1] == "":
        lines.pop()
    hl = hl_ranges or set()

    # Syntax highlighting is strictly opt-in (``highlight=True``); the default
    # is the escaped-plaintext fallback, so the viewer is fully functional with
    # the standard library alone.  Even when enabled, a missing highlighter or
    # any mismatch falls back to escaped text.
    cells = None
    if highlight and path:
        highlighted = highlight_source(text, path)
        if highlighted is not None and len(highlighted) == len(lines):
            cells = highlighted
    if cells is None:
        cells = [esc(line) for line in lines]

    rows = []
    for i, cell in enumerate(cells, start=1):
        cls = ' class="hl"' if i in hl else ""
        rows.append(
            f'<tr id="L{i}"{cls}><td class="lineno"><a href="#L{i}">{i}</a></td>'
            f'<td class="line">{cell}</td></tr>'
        )
    return '<table class="code"><tbody>' + "".join(rows) + "</tbody></table>"


def blob_page(
    repo: gitcmd.Repo,
    ref: str,
    path: str,
    *,
    size: int,
    text: Optional[str],
    binary: bool,
    too_large: bool,
    is_image: bool = False,
    highlight=None,
    syntax: bool = False,
    branches: Optional[List[str]] = None,
    tags: Optional[List[str]] = None,
    commit_sha: str = "",
    lfs: "Optional[gitcmd.LFSPointer]" = None,
    lfs_served: "Optional[gitcmd.LFSPointer]" = None,
    rendered_md: Optional[str] = None,
    show_source: bool = False,
) -> str:
    bar = _crumb_bar(repo.name, ref, path, leaf_is_blob=True)
    switcher = ref_switcher(
        repo.name, "blob", ref, path, branches or [], tags or [], commit_sha
    )
    raw_url = u_action(repo.name, "raw", ref=ref, path=path)
    blame_url = u_action(repo.name, "blame", ref=ref, path=path)
    history_url = u_action(repo.name, "history", ref=ref, path=path)
    actions = (
        f'<div class="pager"><a href="{esc(raw_url)}">Raw</a>'
        f'<a href="{esc(blame_url)}">Blame</a>'
        f'<a href="{esc(history_url)}">History</a>'
        f'<span class="disabled">{esc(size)} bytes</span></div>'
    )
    # When the real content of an LFS-tracked file is being served from local
    # storage, surface a small note (the oid the bytes came from).
    if lfs_served is not None:
        actions += (
            '<p class="muted">Stored with Git LFS &mdash; served from local '
            f'storage (oid sha256:{esc(lfs_served.oid[:16])}&hellip;).</p>'
        )
    if lfs is not None:
        body = (
            '<div class="box"><div class="box-head">Git LFS pointer</div>'
            '<div class="box-body">'
            "<p>This file is stored with Git LFS and its object is not in this "
            "server's local LFS storage, so only the pointer is shown.</p>"
            '<table class="list"><tbody>'
            f'<tr><th>oid</th><td class="mono">sha256:{esc(lfs.oid)}</td></tr>'
            f"<tr><th>size</th><td>{esc(lfs.size)} bytes</td></tr>"
            "</tbody></table>"
            f'<p><a href="{esc(raw_url)}">Download pointer (raw)</a></p>'
            "</div></div>"
        )
    elif is_image:
        # CSP img-src 'self' permits this; /raw serves the correct image type.
        body = (
            '<div class="box"><div class="box-body" style="text-align:center">'
            f'<img class="blob-img" src="{esc(raw_url)}" '
            f'alt="{esc(path)}"></div></div>'
        )
    elif binary:
        body = (
            f'<div class="warn">Binary file &mdash; {esc(size)} bytes. '
            f'<a href="{esc(raw_url)}">Download raw</a>.</div>'
        )
    elif too_large:
        body = (
            f'<div class="warn">File is {esc(size)} bytes, larger than the '
            f"inline display limit. "
            f'<a href="{esc(raw_url)}">View raw</a>.</div>'
        )
    elif rendered_md is not None and not show_source:
        # A Markdown blob rendered, with a toggle to the raw numbered source.
        src_url = u_action(repo.name, "blob", ref=ref, path=path, display="source")
        body = (
            f'<div class="pager"><a href="{esc(src_url)}">View source</a></div>'
            f'<div class="box"><div class="box-body readme">{rendered_md}</div></div>'
        )
    else:
        toggle = ""
        if rendered_md is not None:
            rendered_url = u_action(repo.name, "blob", ref=ref, path=path)
            toggle = (
                f'<div class="pager"><a href="{esc(rendered_url)}">'
                "View rendered</a></div>"
            )
        body = toggle + _numbered_lines(
            text or "", path=path, hl_ranges=highlight, highlight=syntax
        )
    return page(
        f"{repo.name}: {path}",
        bar + switcher + actions + body,
        repo_name=repo.name,
        active_tab="tree",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Blame
# --------------------------------------------------------------------------- #


def blame_page(
    repo: gitcmd.Repo,
    ref: str,
    path: str,
    lines: List[gitcmd.BlameLine],
) -> str:
    bar = _crumb_bar(repo.name, ref, path, leaf_is_blob=True)
    blob_url = u_action(repo.name, "blob", ref=ref, path=path)
    actions = f'<div class="pager"><a href="{esc(blob_url)}">Normal view</a></div>'
    rows = []
    for bl in lines:
        commit_url = u_action(repo.name, "commit", id=bl.short)
        rows.append(
            f'<tr id="L{bl.lineno}">'
            f'<td class="b-sha mono"><a href="{esc(commit_url)}">{esc(bl.short)}</a></td>'
            f'<td class="b-auth">{esc(bl.author)}</td>'
            f'<td class="lineno"><a href="#L{bl.lineno}">{esc(bl.lineno)}</a></td>'
            f'<td class="line">{esc(bl.content)}</td>'
            "</tr>"
        )
    table = '<table class="code"><tbody>' + "".join(rows) + "</tbody></table>"
    return page(
        f"{repo.name}: blame {path}",
        bar + actions + table,
        repo_name=repo.name,
        active_tab="tree",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# History (per-file log)
# --------------------------------------------------------------------------- #


def history_page(
    repo: gitcmd.Repo,
    ref: str,
    path: str,
    rows: List[gitcmd.CommitRow],
    page_num: int,
    total_pages: int,
    follow: bool,
) -> str:
    bar = _crumb_bar(repo.name, ref, path, leaf_is_blob=True)
    blob_url = u_action(repo.name, "blob", ref=ref, path=path)
    if follow:
        toggle = u_action(repo.name, "history", ref=ref, path=path)
        toggle_label = "Disable follow"
    else:
        toggle = u_action(repo.name, "history", ref=ref, path=path, follow="1")
        toggle_label = "Follow renames"
    actions = (
        f'<div class="pager"><a href="{esc(blob_url)}">View file</a>'
        f'<a href="{esc(toggle)}">{esc(toggle_label)}</a></div>'
    )
    header = (
        f'<p class="muted">History of <span class="mono">{esc(path)}</span> on '
        f'<span class="pill">{esc(ref)}</span>'
        + (' <span class="pill">follow</span>' if follow else "")
        + f" &mdash; page {esc(page_num)} of {esc(max(total_pages, 1))}</p>"
    )
    if rows:
        table = (
            '<table class="list"><thead><tr><th>Commit</th><th>Subject</th>'
            "<th>Author</th><th>When</th></tr></thead><tbody>"
            + _commit_rows(repo.name, rows)
            + "</tbody></table>"
        )
    else:
        table = '<p class="muted">No history for this path.</p>'

    pager = ['<div class="pager">']
    if page_num > 1:
        prev = u_action(
            repo.name, "history", ref=ref, path=path,
            page=page_num - 1, follow="1" if follow else None,
        )
        pager.append(f'<a href="{esc(prev)}">&larr; Newer</a>')
    else:
        pager.append('<span class="disabled">&larr; Newer</span>')
    if page_num < total_pages:
        nxt = u_action(
            repo.name, "history", ref=ref, path=path,
            page=page_num + 1, follow="1" if follow else None,
        )
        pager.append(f'<a href="{esc(nxt)}">Older &rarr;</a>')
    else:
        pager.append('<span class="disabled">Older &rarr;</span>')
    pager.append("</div>")

    return page(
        f"{repo.name}: history {path}",
        bar + actions + header + table + "".join(pager),
        repo_name=repo.name,
        active_tab="tree",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Compare (diff between two refs)
# --------------------------------------------------------------------------- #


def compare_page(
    repo: gitcmd.Repo,
    base: str,
    other: str,
    files: List[DiffFile],
) -> str:
    total_add = sum(f.additions for f in files)
    total_del = sum(f.deletions for f in files)
    header = (
        '<div class="box"><div class="box-head">Compare</div><div class="box-body">'
        f'<p><span class="pill">{esc(base)}</span> &rarr; '
        f'<span class="pill">{esc(other)}</span></p>'
        f'<p class="muted">{esc(len(files))} file(s) changed, '
        f'<span class="stat-add">+{esc(total_add)}</span> '
        f'<span class="stat-del">-{esc(total_del)}</span></p>'
        "</div></div>"
    )
    return page(
        f"{repo.name}: compare {base}..{other}",
        header + _render_diff(files),
        repo_name=repo.name,
        active_tab="refs",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Atom feed
# --------------------------------------------------------------------------- #


def atom_feed(
    repo: gitcmd.Repo,
    ref: str,
    rows: List[gitcmd.CommitRow],
    base_url: str = "",
) -> str:
    """Render an Atom 1.0 feed of recent commits on ``ref``.

    All dynamic text is escaped via :func:`xml_escape`, which additionally drops
    the C0 control characters that XML 1.0 forbids — a single such byte in a
    repo-derived field (commit subject/author/email, or the repo description)
    would otherwise make the whole feed non-well-formed for every reader.  Links
    are absolute when a ``base_url`` (scheme://host) is known, otherwise
    relative.
    """

    def abs_url(rel: str) -> str:
        return base_url + rel if base_url else rel

    self_link = abs_url(u_action(repo.name, "atom", ref=ref))
    repo_link = abs_url(u_repo(repo.name))
    updated = atom_date(rows[0].ts) if rows else atom_date(0)
    feed_id = self_link or f"urn:gitweb:{repo.name}:{ref}"

    parts = [
        '<?xml version="1.0" encoding="utf-8"?>',
        '<feed xmlns="http://www.w3.org/2005/Atom">',
        f"<title>{xml_escape(repo.name)}: {xml_escape(ref)}</title>",
        f'<id>{xml_escape(feed_id)}</id>',
        f"<updated>{xml_escape(updated)}</updated>",
        f'<link rel="self" href="{xml_escape(self_link)}"/>',
        f'<link rel="alternate" href="{xml_escape(repo_link)}"/>',
        f"<generator>gitweb</generator>",
    ]
    if repo.description:
        parts.append(f"<subtitle>{xml_escape(repo.description)}</subtitle>")
    for c in rows:
        entry_link = abs_url(u_action(repo.name, "commit", id=c.sha))
        parts.append("<entry>")
        parts.append(f"<title>{xml_escape(c.subject)}</title>")
        parts.append(f"<id>urn:gitweb:commit:{xml_escape(c.sha)}</id>")
        parts.append(f"<updated>{xml_escape(atom_date(c.ts))}</updated>")
        parts.append(f'<link rel="alternate" href="{xml_escape(entry_link)}"/>')
        parts.append(
            f"<author><name>{xml_escape(c.author)}</name>"
            f"<email>{xml_escape(c.email)}</email></author>"
        )
        parts.append(
            f'<summary type="text">{xml_escape(c.subject)}</summary>'
        )
        parts.append("</entry>")
    parts.append("</feed>")
    return "".join(parts)


# --------------------------------------------------------------------------- #
# Search (code + commit message)
# --------------------------------------------------------------------------- #


def _search_form(repo_name: str, q: str, typ: str, ref: str) -> str:
    """A no-JS search box (GET form; needs CSP ``form-action 'self'``)."""
    action = u_action(repo_name, "search")
    options = []
    for value, label in (("code", "Code"), ("log", "Commit messages")):
        sel = " selected" if value == typ else ""
        options.append(f'<option value="{esc(value)}"{sel}>{esc(label)}</option>')
    ref_hidden = (
        f'<input type="hidden" name="ref" value="{esc(ref)}">' if ref else ""
    )
    return (
        f'<form class="search" method="get" action="{esc(action)}" role="search">'
        f'<input type="search" name="q" value="{esc(q)}" '
        'placeholder="Search…" aria-label="Search query">'
        f'<select name="type" aria-label="Search type">{"".join(options)}</select>'
        f"{ref_hidden}"
        '<button type="submit">Search</button>'
        "</form>"
    )


def _search_code_results(
    repo_name: str,
    ref: str,
    matches: List["gitcmd.GrepMatch"],
    truncated: bool,
) -> str:
    if not matches:
        return '<p class="muted">No code matches.</p>'
    out: List[str] = []
    if truncated:
        out.append(
            '<div class="warn">Results were truncated (too many matches); '
            "refine the query.</div>"
        )
    out.append(f'<p class="muted">{esc(len(matches))} match(es).</p>')
    # git grep emits all hits for a file consecutively; group preserving order.
    groups: List = []
    cur_path = None
    cur_list: List = []
    for m in matches:
        if m.path != cur_path:
            cur_path = m.path
            cur_list = []
            groups.append((cur_path, cur_list))
        cur_list.append(m)
    for path, hits in groups:
        blob_base = u_action(repo_name, "blob", ref=ref, path=path)
        head = (
            '<div class="box search-file"><div class="box-head">'
            f'<a href="{esc(blob_base)}">{esc(path)}</a></div>'
        )
        line_rows = []
        for m in hits:
            # ``#L<n>`` is a fragment appended after the (encoded) query string.
            line_url = blob_base + f"#L{m.lineno}"
            line_rows.append(
                '<tr><td class="lineno">'
                f'<a href="{esc(line_url)}">{esc(m.lineno)}</a></td>'
                f'<td class="line">{esc(m.text)}</td></tr>'
            )
        table = (
            '<table class="code grep"><tbody>' + "".join(line_rows) + "</tbody></table>"
        )
        out.append(head + table + "</div>")
    return "".join(out)


def _search_pager(
    repo_name: str, q: str, ref: str, page_num: int, total_pages: int
) -> str:
    if total_pages <= 1:
        return ""
    out = ['<div class="pager">']
    if page_num > 1:
        prev = u_action(
            repo_name, "search", q=q, type="log", ref=ref, page=page_num - 1
        )
        out.append(f'<a href="{esc(prev)}">&larr; Newer</a>')
    else:
        out.append('<span class="disabled">&larr; Newer</span>')
    out.append(
        f'<span class="disabled">page {esc(page_num)} of {esc(total_pages)}</span>'
    )
    if page_num < total_pages:
        nxt = u_action(
            repo_name, "search", q=q, type="log", ref=ref, page=page_num + 1
        )
        out.append(f'<a href="{esc(nxt)}">Older &rarr;</a>')
    else:
        out.append('<span class="disabled">Older &rarr;</span>')
    out.append("</div>")
    return "".join(out)


def _search_log_results(
    repo_name: str,
    q: str,
    ref: str,
    rows: List[gitcmd.CommitRow],
    page_num: int,
    total_pages: int,
) -> str:
    if not rows:
        return '<p class="muted">No commit messages match.</p>'
    table = (
        '<table class="list"><thead><tr><th>Commit</th><th>Subject</th>'
        "<th>Author</th><th>When</th></tr></thead><tbody>"
        + _commit_rows(repo_name, rows)
        + "</tbody></table>"
    )
    return table + _search_pager(repo_name, q, ref, page_num, total_pages)


def search_page(
    repo: gitcmd.Repo,
    q: str,
    typ: str,
    ref: str,
    *,
    code_matches: "Optional[List[gitcmd.GrepMatch]]" = None,
    code_truncated: bool = False,
    log_rows: Optional[List[gitcmd.CommitRow]] = None,
    page_num: int = 1,
    total_pages: int = 1,
    invalid: bool = False,
) -> str:
    parts = [
        f'<p class="muted">Searching <span class="pill">{esc(ref)}</span> '
        "&mdash; literal (fixed-string) matching.</p>",
        _search_form(repo.name, q, typ, ref),
    ]
    if invalid:
        parts.append(
            '<div class="warn">Query is empty, too long, or contains an '
            "invalid character.</div>"
        )
    elif not q:
        parts.append(
            '<p class="muted">Enter a term. <strong>Code</strong> searches file '
            "contents at this ref; <strong>Commit messages</strong> searches log "
            "messages.</p>"
        )
    elif typ == "log":
        parts.append(
            _search_log_results(repo.name, q, ref, log_rows or [], page_num, total_pages)
        )
    else:
        parts.append(
            _search_code_results(repo.name, ref, code_matches or [], code_truncated)
        )
    return page(
        f"{repo.name}: search",
        "".join(parts),
        repo_name=repo.name,
        active_tab="search",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# Commit graph (inline SVG, no JavaScript)
# --------------------------------------------------------------------------- #

_G_ROW_H = 24  # px per commit row (matches .graph-row height)
_G_COL_W = 16  # px per lane column
_G_RADIUS = 4  # commit node radius
_G_PAD = 8  # horizontal padding inside the SVG
#: A small fixed palette cycled by lane column (presentation attributes only —
#: no inline <style>, no scripting; every value here is a constant).
_G_COLORS = (
    "#0969da", "#1a7f37", "#8250df", "#bf3989",
    "#bc4c00", "#0550ae", "#116329", "#a40e26",
)


def _assign_lanes(rows: List["gitcmd.GraphCommit"]):
    """Assign each commit a lane (column) and record its parent lanes.

    A classic column model: ``lanes`` holds, per column, the sha the column is
    currently waiting to emit (or ``None`` when free).  Each commit takes the
    column already waiting for it (or a fresh one); its first parent inherits
    that column and any extra parents open new columns.  Pure and bounded by the
    page size, so it is cheap and unit-testable.

    Returns ``(nodes, max_cols)`` where each node is
    ``{"row", "col", "sha", "parents": [(parent_sha, assigned_col), ...]}``.
    """
    lanes: List[Optional[str]] = []

    def alloc() -> int:
        for j, s in enumerate(lanes):
            if s is None:
                return j
        lanes.append(None)
        return len(lanes) - 1

    def find(sha: str) -> Optional[int]:
        for j, s in enumerate(lanes):
            if s == sha:
                return j
        return None

    nodes = []
    max_cols = 1
    for i, c in enumerate(rows):
        my_col = find(c.sha)
        if my_col is None:
            my_col = alloc()
        # Converging merges: free any *other* lane also waiting for this commit.
        for j, s in enumerate(lanes):
            if s == c.sha and j != my_col:
                lanes[j] = None
        parent_cols: List = []
        if c.parents:
            lanes[my_col] = c.parents[0]
            parent_cols.append((c.parents[0], my_col))
            for p in c.parents[1:]:
                col = find(p)
                if col is None:
                    col = alloc()
                    lanes[col] = p
                parent_cols.append((p, col))
        else:
            lanes[my_col] = None  # root: the lane ends here
        nodes.append(
            {"row": i, "col": my_col, "sha": c.sha, "parents": parent_cols}
        )
        # ``len(lanes)`` here is the highest column index+1 currently allocated
        # (columns can be sparse), i.e. the width this row needs.
        max_cols = max(max_cols, len(lanes))
        while lanes and lanes[-1] is None:  # trim trailing free lanes
            lanes.pop()
    return nodes, max_cols


def _render_graph_svg(
    repo_name: str, nodes: List[dict], rows: List["gitcmd.GraphCommit"], max_cols: int
) -> str:
    """Render the lane graph as inline SVG beside a column of commit rows.

    Geometry is built only from integers and the fixed colour palette, so no
    repository-derived text ever reaches an SVG attribute; the commit metadata
    (escaped) lives in the adjacent HTML rows, which share the row height so the
    nodes line up.
    """
    n = len(rows)
    height = n * _G_ROW_H
    width = _G_PAD * 2 + max(1, max_cols) * _G_COL_W
    sha_row = {nd["sha"]: nd["row"] for nd in nodes}
    sha_col = {nd["sha"]: nd["col"] for nd in nodes}

    def cx(col: int) -> int:
        return _G_PAD + col * _G_COL_W + _G_COL_W // 2

    def cy(row: int) -> int:
        return row * _G_ROW_H + _G_ROW_H // 2

    edges: List[str] = []
    dots: List[str] = []
    for nd in nodes:
        x0, y0 = cx(nd["col"]), cy(nd["row"])
        for psha, assigned_col in nd["parents"]:
            r = sha_row.get(psha)
            if r is None:  # parent off this page: draw a stub to the bottom
                xp, yp = cx(assigned_col), height
            else:  # connect to where the parent node actually renders
                xp, yp = cx(sha_col[psha]), cy(r)
            color = _G_COLORS[(assigned_col if r is None else sha_col[psha]) % len(_G_COLORS)]
            if xp == x0:
                d = f"M{x0} {y0} L{xp} {yp}"
            else:
                # Kink one row below the child, then run straight down the lane.
                ykink = min(y0 + _G_ROW_H, yp) if r is not None else y0 + _G_ROW_H
                d = f"M{x0} {y0} L{xp} {ykink} L{xp} {yp}"
            edges.append(
                f'<path d="{d}" fill="none" stroke="{color}" stroke-width="1.5"/>'
            )
        dot_color = _G_COLORS[nd["col"] % len(_G_COLORS)]
        dots.append(
            f'<circle cx="{x0}" cy="{y0}" r="{_G_RADIUS}" '
            f'fill="{dot_color}" stroke="#ffffff" stroke-width="1"/>'
        )
    svg = (
        f'<svg class="graph-svg" width="{width}" height="{height}" '
        f'viewBox="0 0 {width} {height}" xmlns="http://www.w3.org/2000/svg" '
        'role="img" aria-label="commit graph">'
        + "".join(edges)
        + "".join(dots)
        + "</svg>"
    )
    row_html: List[str] = []
    for nd in nodes:
        c = rows[nd["row"]]
        url = u_action(repo_name, "commit", id=c.sha)
        row_html.append(
            '<div class="graph-row">'
            f'<a class="mono" href="{esc(url)}">{esc(c.short)}</a> '
            f"{esc(c.subject)} "
            f'<span class="muted">&middot; {esc(c.author)}, '
            f"{esc(relative_date(c.ts))}</span>"
            "</div>"
        )
    return (
        '<div class="graph-wrap">'
        + svg
        + '<div class="graph-rows">'
        + "".join(row_html)
        + "</div></div>"
    )


def _graph_pager(repo_name: str, ref: str, page_num: int, total_pages: int) -> str:
    out = ['<div class="pager">']
    if page_num > 1:
        prev = u_action(repo_name, "graph", ref=ref, page=page_num - 1)
        out.append(f'<a href="{esc(prev)}">&larr; Newer</a>')
    else:
        out.append('<span class="disabled">&larr; Newer</span>')
    if page_num < total_pages:
        nxt = u_action(repo_name, "graph", ref=ref, page=page_num + 1)
        out.append(f'<a href="{esc(nxt)}">Older &rarr;</a>')
    else:
        out.append('<span class="disabled">Older &rarr;</span>')
    out.append("</div>")
    return "".join(out)


def graph_page(
    repo: gitcmd.Repo,
    ref: str,
    rows: List["gitcmd.GraphCommit"],
    page_num: int,
    total_pages: int,
) -> str:
    header = (
        f'<p class="muted">Commit graph for <span class="pill">{esc(ref)}</span> '
        f"&mdash; page {esc(page_num)} of {esc(max(total_pages, 1))}</p>"
    )
    if not rows:
        body = header + '<p class="muted">No commits.</p>'
    else:
        nodes, max_cols = _assign_lanes(rows)
        body = (
            header
            + _render_graph_svg(repo.name, nodes, rows, max_cols)
            + _graph_pager(repo.name, ref, page_num, total_pages)
        )
    return page(
        f"{repo.name}: graph",
        body,
        repo_name=repo.name,
        active_tab="graph",
        repo_desc=repo.description,
    )


# --------------------------------------------------------------------------- #
# OpenSearch descriptors
# --------------------------------------------------------------------------- #
#
# ``{searchTerms}`` is the OpenSearch template token the browser substitutes; it
# must survive verbatim into the ``template`` attribute.  We build the URL from
# already-safe pieces (a server-derived base, the url-prefix, a URL-quoted repo
# name) and then run the whole thing through :func:`xml_escape`, which turns the
# single ``&`` between query parameters into ``&amp;`` (required in an XML
# attribute) while leaving the ``{…}`` token untouched.

_SEARCH_TERMS = "{searchTerms}"


def opensearch_repo(repo_name: str, base: str) -> str:
    """Per-repo code-search descriptor.  ``base`` is ``scheme://host`` + prefix."""
    template = f"{base}/{quote(repo_name, safe='')}/search?q={_SEARCH_TERMS}&type=code"
    short = ("gw:" + repo_name)[:16]
    return (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">'
        f"<ShortName>{xml_escape(short)}</ShortName>"
        f"<Description>{xml_escape('Code search in ' + repo_name)}</Description>"
        "<InputEncoding>UTF-8</InputEncoding>"
        f'<Url type="text/html" method="get" template="{xml_escape(template)}"/>'
        "</OpenSearchDescription>"
    )


def opensearch_site(base: str) -> str:
    """Site-level descriptor: its search targets the repository finder (home)."""
    template = f"{base}/?q={_SEARCH_TERMS}"
    return (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">'
        "<ShortName>gitweb</ShortName>"
        "<Description>Find a repository on this gitweb</Description>"
        "<InputEncoding>UTF-8</InputEncoding>"
        f'<Url type="text/html" method="get" template="{xml_escape(template)}"/>'
        "</OpenSearchDescription>"
    )
