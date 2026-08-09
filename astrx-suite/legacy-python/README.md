# astrx-suite

Zero-dependency companion services for [AstrX](https://github.com/hydrastro/astrx):
a git web browser, a `.onion` crawler + search engine, a clearnet search engine,
and a torrent DHT indexer + tracker — plus a shared crawl library, a no-JS ops
dashboard, and a one-command Tor deployment. Pure Python 3.11 standard library:
no pip, no build step, no JavaScript. Same rule as AstrX — if it needs a
dependency, it doesn't ship.

Everything binds to `127.0.0.1` by default and is happy behind a Tor hidden
service. Nothing phones home. Each tool is a self-contained package with its own
SQLite database, CLI, tests, and README.

> **Repository layout.** The standalone services live here in `astrx-suite/`.
> The PHP that plugs these engines into the AstrX CMS as native pages lives in a
> **sibling** folder at the repo root, [`../astrx-integration/`](../astrx-integration/) —
> because those files' home is the CMS tree (`src/AstrX/…`, `resources/…`), not
> this one. See [AstrX integration](#astrx-integration) below.

## The services

### gitweb — read-only git browser
A cgit/Gitea-style web view over local repos: repo list, commit log, diffs, tree
and blob browsing, blame, refs, raw download, per-tag **releases** (with an Atom
feed), and a Sourcehut-style **patch / mail archive**. Read-only by construction —
it only runs read-only git commands, through an argument vector, never a shell —
confined to a repo root, escapes everything, no JS.
```
python3 -m gitweb --root /srv/git --port 8801
```

### onioncrawler — resumable onion crawler + search
Crawls Tor hidden services (`.onion` only — it refuses clearnet, ever) over a
hand-rolled SOCKS5 to your local Tor daemon, and serves a no-JS search UI over
what it finds. Stop it and start it again and it picks up exactly where it left
off: the frontier lives in SQLite, workers *lease* URLs so a kill loses nothing,
and finished URLs are never refetched. It survives bot traps — depth and budget
caps, robots.txt, URL canonicalization, query-explosion and path-cycle guards,
content dedup, and per-host trap-scoring that blacklists a tarpit mid-run. Ships
an operator abuse-blocklist (hosts + keywords, plus an Ahmia-style md5 hostlist),
an **entity index** (find pages by PGP key or BTC/XMR/ETH address), and a
multi-Tor **fetch pool** for throughput. Configure the blocklist before you index
anything.
```
python3 -m onioncrawler crawl  --seeds seeds.txt --db onion.db
python3 -m onioncrawler search --db onion.db --port 8802
```

### websearch — clearnet search engine
A real crawler + inverted index + ranker, not a metasearch proxy. Polite,
resumable crawl; an FTS5 index; and an explicit scoring function — BM25 over
title/description/body weights, plus a PageRank-lite link boost, freshness, and
phrase proximity. Web / News / Images / Videos / Files **verticals**, ranking
**optics** (`boost:`/`penalize:`), a no-JS results UI and a JSON API. Query input
is reduced to tokens so nobody breaks the FTS syntax, and snippets are escaped
before they're highlighted. Scales horizontally: HRW/rendezvous host-sharding
with a stateless scatter-gather aggregator (`fed-serve`) — see
[`deploy/FLEET.md`](deploy/FLEET.md).
```
python3 -m websearch crawl --seeds seeds.txt --db web.db
python3 -m websearch serve --db web.db --port 8803
```

### torrentds — torrent DHT search + tracker
A magnetico/btdig-style metadata indexer (names, file lists, infohashes → magnet
links, never content) plus a standards-compliant tracker. Hand-rolled bencode, a
Mainline DHT node (BEP-5) that harvests infohashes and pulls metadata over the
wire (BEP-9/10), an HTTP tracker (BEP-3/23), and a UDP tracker (BEP-15). Searches
**filenames inside torrents** (btdig-style), tags results with a release-name
**classifier** (resolution/source/codec/season/episode/group → filter chips), and
exports a **Torznab** feed for Prowlarr/*arr. No-JS search UI + JSON API, and an
infohash/keyword blocklist.
```
python3 -m torrentds index   --db t.db --port 6881
python3 -m torrentds search  --db t.db --port 8804
python3 -m torrentds tracker --http-port 8805 --udp-port 6969
```

### Also in the suite
- **crawlcore** — the shared fetch / parse / URL-canonicalization / robots / dedup
  library the crawlers are built on.
- **suitedash** — a no-JS ops dashboard that health-checks and links each service.
- **deploy/** + **docker-compose.yml** — one command brings the whole stack up
  behind Tor, one v3 onion per surface. Multi-node / Hetzner scaling is in
  [`deploy/FLEET.md`](deploy/FLEET.md).

## Behind Tor

Each server binds loopback. Point a hidden service at it:
```
HiddenServiceDir /var/lib/tor/gitweb/
HiddenServicePort 80 127.0.0.1:8801
```
None of these ship their own auth. If a service isn't meant to be public, put
onion client authentication or a reverse proxy in front. Or just bring the whole
stack up with `docker compose up -d`, which wires one onion per surface for you.

## Verification

**858 unit tests** across the components — crawlcore 36, gitweb 127,
onioncrawler 216, websearch 200, torrentds 279 — all green, plus end-to-end CLI
smoke tests: gitweb served a real repository, the onion crawler
stopped-and-resumed straight through a bot trap and returned ranked results, web
search ranked the right page first, and the tracker exchanged compact peers and
answered a scrape. The PHP bridge in `../astrx-integration/` adds **145**
mock-backed assertions.

Every component went through an adversarial security review, and each finding
became a regression test. What that review caught and fixed: a **CRITICAL SSRF**
in websearch (the crawler would fetch and index
`169.254.169.254`/loopback/RFC1918 — now every connect and redirect hop is
checked against an internal-IP denylist), an unauth **memory-exhaustion DoS** in
gitweb (git output was buffered whole before the cap applied — now read
incrementally and killed at the cap), **decompression bombs** in both crawlers
(now capped mid-inflate), a **quadratic-regex ReDoS** in the onion entity
extractor (now a linear scan), and in torrentds a single malicious peer that
could halt all indexing plus a UDP tracker that honored the client `ip` field (a
DDoS reflector) — both closed.
```
for c in crawlcore gitweb onioncrawler websearch torrentds; do ( cd "$c" && python3 -m unittest discover ); done
```
The machine this was built on has no network, so two things are proven by
protocol unit tests and loopback rather than by live traffic: the real Tor path
in onioncrawler (the SOCKS5 byte layout and the onion-only gate are tested; a
live `.onion` fetch isn't) and the global Mainline DHT in torrentds (two local
nodes talk to each other; the worldwide swarm is untested). Everything else runs
for real.

## Responsibility

onioncrawler and torrentds index other people's material. The onion
abuse-blocklist and the torrent infohash/keyword blocklist exist because running
an index makes you responsible for what it surfaces — configure them, and know
the law where you operate. torrentds stores metadata and magnet links only, never
file content.

## Layout

```
astrx-suite/            ← standalone services (this folder)
  crawlcore/            shared fetch / parse / dedup library
  gitweb/              read-only git web browser
  onioncrawler/        resumable .onion crawler + no-JS search
  websearch/           clearnet crawler + FTS5 index + BM25 + no-JS search
  torrentds/           DHT metadata indexer + torrent search + HTTP/UDP tracker
  suitedash/           no-JS ops dashboard
  deploy/              Tor image + fleet guide
  docker-compose.yml   one-command Tor stack
  docs/                design notes, reviews, roadmap

astrx-integration/      ← sibling at the CMS repo root: PHP that adds the
                          engines above as native, themed AstrX pages
```

## AstrX integration

The sibling [`../astrx-integration/`](../astrx-integration/) holds **seven
drop-in AstrX modules** — WebSearch, OnionSearch, TorrentSearch, FederatedSearch,
GitBrowse, Blocklist, and a SuiteAdmin control panel — that surface these engines
as native, no-JS AstrX pages. Each is a thin, zero-dependency PHP bridge to an
engine's localhost JSON API: the host is config-fixed, the query is
`rawurlencode`'d and redirects aren't followed (no SSRF surface), and crawled
result text is `strip_tags`'d then rendered through AstrX's escaping (no XSS
surface).

The pages **inherit the site's exact look**: each template is a theme-neutral
partial (`color:inherit`, `currentColor` borders, no palette of its own) rendered
inside the AstrX shell, mirroring the CMS's existing internal `site_search`
page — so they read as part of the website, not a bolted-on tool. It lives at the
repo root rather than under this folder because its files' canonical home is the
CMS tree (`src/AstrX/…`, `resources/…`); its README has the one-step install and
the one-time SQL. Passes AstrX's own gates: PHPStan level 10, module integrity,
en/it parity, and the 145 bridge-test assertions above.

Each folder is independent: its own package, tests, README, and database. Pick
the one you want and run it; nothing is shared, nothing is global.
