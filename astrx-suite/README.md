# astrx-suite

Four zero-dependency tools for running your own corner of the internet: a git
browser, an onion crawler + search engine, a clearnet search engine, and a
torrent DHT indexer + tracker. Pure Python 3.11 standard library — no pip, no
build step, no JavaScript. Same rule as AstrX: if it needs a dependency, it
doesn't ship.

Everything binds to `127.0.0.1` by default and is happy behind a Tor hidden
service. Nothing phones home. Each tool is a self-contained package with its own
SQLite database, CLI, tests, and README.

## The four

### gitweb — read-only git browser
A cgit/Gitea-style web view over local repos: repo list, commit log, diffs, tree
and blob browsing, blame, refs, raw download. Read-only by construction — it only
runs read-only git commands, through an argument vector, never a shell — confined
to a repo root, escapes everything, no JS.
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
an operator abuse-blocklist (hosts + keywords); configure it before you index
anything.
```
python3 -m onioncrawler crawl  --seeds seeds.txt --db onion.db
python3 -m onioncrawler search --db onion.db --port 8802
```

### websearch — clearnet search engine
A real crawler + inverted index + ranker, not a metasearch proxy. Polite,
resumable crawl; an FTS5 index; and an explicit scoring function — BM25 over
title/description/body weights, plus a PageRank-lite link boost, freshness, and
phrase proximity. No-JS results UI and a JSON API. Query input is reduced to
tokens so nobody breaks the FTS syntax, and snippets are escaped before they're
highlighted.
```
python3 -m websearch crawl --seeds seeds.txt --db web.db
python3 -m websearch serve --db web.db --port 8803
```

### torrentds — torrent DHT search + tracker
A magnetico/btdig-style metadata indexer (names, file lists, infohashes → magnet
links, never content) plus a standards-compliant tracker. Hand-rolled bencode, a
Mainline DHT node (BEP-5) that harvests infohashes and pulls metadata over the
wire (BEP-9/10), an HTTP tracker (BEP-3/23), and a UDP tracker (BEP-15). No-JS
search UI + JSON API, and an infohash/keyword blocklist.
```
python3 -m torrentds index   --db t.db --port 6881
python3 -m torrentds search  --db t.db --port 8804
python3 -m torrentds tracker --http-port 8805 --udp-port 6969
```

## Behind Tor

Each server binds loopback. Point a hidden service at it:
```
HiddenServiceDir /var/lib/tor/gitweb/
HiddenServicePort 80 127.0.0.1:8801
```
None of these ship their own auth. If a service isn't meant to be public, put
onion client authentication or a reverse proxy in front.

## Verification

200 unit tests across the four (20 / 54 / 38 / 88), all green, plus end-to-end
CLI smoke tests: gitweb served a real repository, the onion crawler
stopped-and-resumed straight through a bot trap and returned ranked results, web
search ranked the right page first, and the tracker exchanged compact peers and
answered a scrape.

The count jumped from 171 to 200 because every component went through an
adversarial security review afterwards, and each finding became a regression
test. What that review caught and fixed: a **CRITICAL SSRF** in websearch (the
crawler would fetch and index `169.254.169.254`/loopback/RFC1918 — now every
connect and redirect hop is checked against an internal-IP denylist), an unauth
**memory-exhaustion DoS** in gitweb (git output was buffered whole before the cap
applied — now read incrementally and killed at the cap), **decompression bombs**
in both crawlers (now capped mid-inflate), and in torrentds a single malicious
peer that could halt all indexing plus a UDP tracker that honored the client `ip`
field (a DDoS reflector) — both closed. The SSRF block, both decompression caps,
and the torrentds fixes were re-verified with independent PoCs.
```
for c in gitweb onioncrawler websearch torrentds; do ( cd "$c" && python3 -m unittest discover -s tests ); done
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

## Status

These are solid, tested cores — not finished clones of Gitea, Google, or btdig.
Each component's README has an honest "Status / limitations" section. Natural next
steps: authentication for gitweb, multi-worker crawl drivers, IPv6/µTP in the
DHT, and wiring the two search engines into AstrX as PHP-facing modules so they
share its templates and admin.

## Layout

```
astrx-suite/
  gitweb/            read-only git web browser
  onioncrawler/      resumable .onion crawler + no-JS search
  websearch/         clearnet crawler + FTS5 index + BM25 ranking + no-JS search
  torrentds/         DHT metadata indexer + torrent search + HTTP/UDP tracker
  astrx-integration/ drop-in PHP: clear-web + onion search pages for AstrX
```

### AstrX integration

`astrx-integration/` holds two drop-in AstrX modules that surface the clear-web
and onion engines as native, no-JS AstrX pages, each a thin zero-dependency PHP
bridge to the engine's JSON API (config-fixed localhost host, `rawurlencode`'d
query, no redirect-follow — no SSRF surface; crawled result text is `strip_tags`'d
then rendered through AstrX's escaping, so untrusted content has zero XSS surface
on the AstrX side). They are deliberately **three separate pages** — AstrX's
existing internal site search, plus these two — not one unified search. Passes
AstrX's own gates in-tree: PHPStan level 10 clean, module integrity, en/it parity.
See `astrx-integration/README.md`.
Each folder is independent: its own package, `tests/`, README, and database. Pick
the one you want and run it; nothing is shared, nothing is global.
