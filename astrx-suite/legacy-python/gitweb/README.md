# gitweb — a read-only, no-JavaScript web git browser

A minimal, self-contained git web viewer in the spirit of **cgit** / a stripped
down **Gitea**. It serves a browsable, read-only view of local git
repositories: repo list, summary with rendered README, refs, paginated log,
commit + diff view, tree browser, blob view with line numbers, a raw endpoint,
and blame.

It is built to be **privacy-first and dependency-free**:

- **Python 3.11 standard library only.** No pip, no third-party packages, no
  build step, no network access required.
- **No JavaScript** is ever sent to the browser. Every page is server-rendered
  HTML with inline CSS and works with scripting fully disabled.
- **Read-only.** It only ever runs a small allow-list of read-only git
  plumbing/porcelain commands (`log`, `show`, `cat-file`, `ls-tree`,
  `rev-parse`, `rev-list`, `for-each-ref`, `blame`, `diff-tree`, `archive`,
  `symbolic-ref`). It never runs a git command that writes.
- **Loopback by default**, designed to sit behind a Tor hidden service.
- **Tor-friendly:** conditional GET (ETag/304) on immutable views, gzip HTML,
  a bounded worker pool with per-connection timeouts, and a persistent
  `git cat-file --batch` reader that collapses the four-forks-per-blob cost.

## Requirements

- Python 3.11+
- A `git` binary on `PATH`

Both are used exactly as-is; nothing is downloaded or installed.

## Layout

```
gitweb/
├── README.md
└── gitweb/                 # the package (python3 -m gitweb)
    ├── __init__.py
    ├── __main__.py         # CLI entry point / argument parsing
    ├── gitcmd.py           # read-only git subprocess layer + validation + discovery
    ├── markup.py           # HTML escaping, safe Markdown, dates, diff parsing
    ├── views.py            # server-rendered HTML views + inline CSS
    ├── server.py           # ThreadingHTTPServer, router, request handlers
    └── tests/
        └── test_gitweb.py  # end-to-end unittest suite
```

## Running

Point `--root` at a directory that **directly contains** your repositories.
Both layouts are discovered one level deep:

- bare repos, conventionally named `something.git`
- normal repos (a directory containing a `.git`)

```console
# from inside the delivered gitweb/ directory (so that the package is importable)
$ cd /tmp/astrx-suite/gitweb
$ python3 -m gitweb --root /srv/git --host 127.0.0.1 --port 8801
gitweb serving repos in /srv/git at http://127.0.0.1:8801/
```

Then open <http://127.0.0.1:8801/>.

A repository's **description** is read from its `description` file (bare:
`<repo>.git/description`; normal: `<repo>/.git/description`). The default git
placeholder text is treated as "no description".

### CLI options

| Flag | Default | Meaning |
| --- | --- | --- |
| `--root` | *(required)* | Directory that directly contains the repositories. |
| `--host` | `127.0.0.1` | Bind address. Keep it on loopback. |
| `--port` | `8801` | TCP port. |
| `--page-size` | `50` | Commits shown per log page. |
| `--max-blob-mb` | `2` | Max blob size (MiB) rendered inline; larger files show a raw link. |
| `--raw-max-mb` | `50` | Max bytes streamed by the `/raw` endpoint. |
| `--archive-max-mb` | `200` | Max bytes streamed by the `/archive` endpoint. |
| `--tree-page-size` | `500` | Tree entries shown per page. |
| `--max-workers` | `32` | Max concurrent connections handled at once. |
| `--socket-timeout` | `30` | Per-connection socket read timeout (seconds). |
| `--url-prefix` | *(none)* | Mount under a reverse-proxy sub-path, e.g. `/git`. |
| `--highlight` | off | Enable optional Pygments highlighting (falls back to escaped plaintext if Pygments is absent). |
| `--enable-clone` / `--no-enable-clone` | on | Serve read-only `git clone`/`git fetch` over HTTP (Git Smart HTTP, `upload-pack` only). Disable to make the clone endpoints `404`. |
| `--clone-timeout` | `120` | Overall wall-clock timeout (s) for one `upload-pack` call; a clone/fetch exceeding it is killed. |
| `--clone-max-body-mb` | `25` | Max size (MiB) of a clone/fetch POST body, after gzip inflation. |
| `--clone-max-concurrency` | `4` | Max concurrent `upload-pack` RPCs (keep below `--max-workers`). |
| `--clone-base-url` | *(Host header)* | External origin shown in the `git clone` command on the summary, e.g. an onion address. |
| `-q`, `--quiet` | off | Suppress per-request access logging. |

### URL map

| Path | View |
| --- | --- |
| `/` | Repository list |
| `/<repo>/` | Summary (default branch, latest commits, README) |
| `/<repo>/refs` | Branches and tags |
| `/<repo>/log?ref=<ref>&page=<n>` | Paginated commit log |
| `/<repo>/commit?id=<rev>` | Commit metadata + full diff |
| `/<repo>/tree?ref=<ref>&path=<path>` | Directory listing |
| `/<repo>/blob?ref=<ref>&path=<path>` | File contents (line numbers, inline images, rendered Markdown, LFS pointer info) |
| `/<repo>/raw?ref=<ref>&path=<path>` | Raw bytes (safe content-type; images served inline) |
| `/<repo>/blame?ref=<ref>&path=<path>` | Per-line blame |
| `/<repo>/history?ref=<ref>&path=<path>&follow=1` | Per-file history (`git log -- <path>`) |
| `/<repo>/compare?from=<ref>&to=<ref>` | Diff between two refs |
| `/<repo>/atom?ref=<ref>` | Atom feed of recent commits |
| `/<repo>/archive?ref=<ref>` | `tar.gz` snapshot (streamed) |
| `GET /<repo>/info/refs?service=git-upload-pack` | Git Smart HTTP ref advertisement (clone/fetch) |
| `POST /<repo>/git-upload-pack` | Git Smart HTTP pack negotiation (clone/fetch); streamed |
| `/health` | Liveness probe (`ok`) |
| `/metrics` | Prometheus-style request counters/latency |

Refs and paths are passed as query parameters (so branch names and paths
containing `/` need no special encoding in the route). The blob view adds a
no-JavaScript ref switcher, a sha-pinned permalink, and `?highlight=<a>-<b>`
line-range highlighting; `/blob`, `/raw`, `/tree` and `/commit` (at a full sha)
answer conditional GETs with `ETag`/`304`. `/health` and `/metrics` are reserved
top-level paths (a repository named `health` or `metrics` is shadowed by them).

## Deploying behind a Tor hidden service

gitweb binds to loopback and speaks plain HTTP; Tor terminates the onion
connection and forwards to it. Add to your `torrc`:

```
HiddenServiceDir /var/lib/tor/gitweb/
HiddenServicePort 80 127.0.0.1:8801
```

Then run gitweb bound to the same loopback address/port:

```console
$ python3 -m gitweb --root /srv/git --host 127.0.0.1 --port 8801 --quiet
```

Reload Tor and read the onion hostname from
`/var/lib/tor/gitweb/hostname`. Because the pages contain **no JavaScript**, no
third-party assets, and set `Referrer-Policy: no-referrer` plus a strict
`Content-Security-Policy`, the site renders safely in the Tor Browser at its
most restrictive setting.

Recommended hardening for a real deployment:

- Run gitweb as an unprivileged user that only has **read** access to the repos.
- Keep `--host 127.0.0.1`; never expose the HTTP port directly to a network.
- Consider a systemd unit with `ProtectSystem=strict`, `PrivateTmp=yes`,
  `NoNewPrivileges=yes`, and a read-only bind mount of the repo root.

## Security notes

- **Repo allow-list.** A requested repo id must be a single path component from
  a restricted charset. It is resolved with `realpath` and only served if its
  parent is exactly the configured root and it is a git repository. This blocks
  path traversal, absolute paths, and `..`.
- **Untrusted refs/paths.** Refs and object paths from the URL are validated
  before use: refs are restricted to a safe charset and may not begin with `-`
  (option-injection); paths may not be absolute, begin with `-`, or contain a
  `..` component. git is always invoked as an **argument vector** (never
  `shell=True`, never string interpolation), and refs/paths are separated from
  options with `--`.
- **Output escaping.** Every dynamic value — repo names, paths, commit
  messages, diffs, blame, file contents — is HTML-escaped before it reaches the
  page. The Markdown renderer escapes first and only re-introduces a fixed safe
  subset of tags; `javascript:`/`data:` links are dropped.
- **Resource limits.** git subprocesses run with a timeout; captured output is
  size-capped; inline blob rendering is capped (`--max-blob-mb`) and `/raw` is
  capped (`--raw-max-mb`) and streamed in chunks.
- **Read-only clone (Git Smart HTTP).** `git clone`/`git fetch` are served via
  `git upload-pack` **only** — the read side of the pack protocol. Push is
  categorically refused: `service=git-receive-pack` and `POST
  /<repo>/git-receive-pack` both return `403`, and `receive-pack` is never in
  the git subcommand allow-list. The endpoints resolve the repo through the same
  `realpath`/allow-list confinement as browsing; `upload-pack` runs argv-only
  with `uploadpack.allowFilter=false` and every `allow*SHA1InWant` **off**
  (default-deny — a client cannot fetch an unadvertised object it merely knows
  the sha of). Each call has an overall wall-clock timeout (`--clone-timeout`,
  killed on exceed), the POST request body is size-capped and gzip-inflated
  under a hard cap (`--clone-max-body-mb`), the pack response is streamed (never
  buffered in RAM), and a small semaphore (`--clone-max-concurrency`) keeps
  concurrent clones from starving interactive browsing. The transport is
  disableable with `--no-enable-clone` (the endpoints then `404`). Protocol v2
  is honoured when the client sends `Git-Protocol: version=2`. The git Smart-HTTP
  responses are never gzipped by the HTML path (git frames its own).
- **Response headers.** `Content-Security-Policy: default-src 'none'; style-src
  'unsafe-inline'; ...`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, and `Referrer-Policy: no-referrer` are sent on every
  page. `/raw` uses `text/plain; charset=utf-8` for text and
  `application/octet-stream` (as an attachment) for binary, always with
  `X-Content-Type-Options: nosniff`.
- git is run with global/system config disabled (`GIT_CONFIG_GLOBAL`/`SYSTEM`
  → `/dev/null`), `GIT_TERMINAL_PROMPT=0`, and `-c safe.directory=*` so it can
  read repos owned by another uid without honouring untrusted per-repo config.

## Testing

The suite creates a real temp git repo (local identity, commits across `main`
and a `feature` branch, an annotated tag, a binary file, and a Markdown
README), starts the server on an ephemeral port in a background thread, and
drives every endpoint over HTTP — asserting status codes and expected
substrings, README/diff/blob escaping, the raw content-types, blame output, and
that path-traversal / option-injection attempts are rejected.

```console
$ cd /tmp/astrx-suite/gitweb
$ python3 -m unittest discover -s gitweb/tests -t . -v
```

## Status / limitations

Implemented: repo discovery (bare + normal) with description and cached
last-commit date; summary with rendered README; refs; paginated log; commit view
with per-file add/del counts, colored diff and a signed-commit "Verified" badge;
tree browser with breadcrumbs, pagination and submodule (gitlink) display; blob
view with line numbers, inline images, rendered Markdown, Git-LFS pointer
detection, a no-JavaScript ref switcher, sha-pinned permalinks and line-range
highlighting; `/raw` streaming; blame; per-file history (with `--follow`);
compare; Atom feeds; `tar.gz` archive snapshots; read-only `git clone`/`git
fetch` over HTTP (Git Smart HTTP, `upload-pack` only, protocol v0/v1/v2);
`/health` + `/metrics`.
Conditional GET (ETag/304) and gzip keep repeat Tor navigation cheap; a
persistent `git cat-file --batch` reader and a cached last-commit timestamp
remove the per-request fork cliffs; a bounded worker pool plus per-connection
socket timeouts bound thread/Slowloris exposure. Syntax highlighting is optional
(`--highlight`, Pygments) and always degrades to escaped plaintext.

Deliberately **not** implemented (out of scope for a minimal read-only viewer):

- No writes of any kind: no push (`git-receive-pack` is refused with `403`), no
  web hooks. Read-only `git clone`/`git fetch` over Smart HTTP *is* supported
  (see Security notes); it can be turned off with `--no-enable-clone`.
- No search and no side-by-side diff.
- No authentication, users, or access control — every discovered repo is world
  readable to anyone who can reach the port. Put access control in front of it
  (Tor onion auth, a reverse proxy, or filesystem permissions on the root).
- Markdown remains a small, safe subset (headings, fenced/inline code,
  bold/italic, links, images, autolinks, lists, task lists, GitHub pipe tables,
  blockquotes, paragraphs). Raw HTML and nested lists are not rendered; anything
  unrecognized degrades to escaped text. Non-Markdown READMEs are escaped
  `<pre>`.
- Repository discovery is one level deep and non-recursive; nested/grouped repo
  trees are not walked.
- Diffs for merge commits are not expanded (git shows no diff by default).
- Blame is computed live per request and is not cached; on very large files it
  can be slow (bounded by the subprocess timeout).
- Conditional-GET ETags are keyed on content (object sha), so an unchanged file
  keeps its cache across commits; ref-list chrome (the switcher/permalink) may
  therefore lag by one navigation after a push until the content changes.

## Packaging

`pyproject.toml` exposes a `gitweb` console script (`gitweb --root …`). A
`Dockerfile` (Python + git, zero Python deps) and a hardened
`deploy/gitweb.service` systemd unit are included for deployment.
