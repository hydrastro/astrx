# astrx-suite — review & roadmap

An engineering review of the four tools (gitweb, onioncrawler, websearch, torrentds)
plus the AstrX bridge, and a prioritized roadmap. The security audit was a separate
pass and is done; this is about capability, maturity, and what to build next. Every
item below is grounded in the actual code.

## Verdict, per tool

**gitweb** — a genuinely well-built read-only viewer. Clean 4-layer split, disciplined
resource-bounding (kills git the instant output hits the cap), coherent security. It's
above toy grade. What it isn't yet: cgit/Gitea-competitive on *features* (no syntax
highlighting, no search, no per-file history, no feeds, no compare view) or on *Tor
performance* (no caching/ETag, no gzip, 4 git forks per blob view). Maturity: solid v1
viewer, small-to-medium repos.

**onioncrawler** — the best-engineered of the four at its core: the crash-safe leased
frontier is excellent, the onion-only anti-leak is real, the trap defenses are
comprehensive. The gap to Ahmia is *discovery and freshness*, not plumbing: it only
finds onions via `<a href>` links (ignores plaintext `.onion` in body text and
`Sitemap:` directives), never meaningfully recrawls (one-shot 7-day requeue at startup),
tracks no liveness so dead onions rot in the index, and ranks on bm25 alone despite
crawling a link graph it never stores. Maturity: a working crawler+index; not yet a
self-sustaining search engine.

**websearch** — real crawl discipline and a transparent, hackable ranker (bm25f + link +
freshness + proximity, each signal exposed in the API). It's a legitimate *small-corpus*
engine. Four structural things separate it from a real web engine, in order: (1) it
computes cross-domain authority signals and **discards them** — `K_PR=0.80`, its heaviest
weight, is applied to one site's internal nav graph; (2) no recrawl → the index is
write-once-then-stale; (3) single in-flight fetch + single SQLite file cap it at small
scale; (4) no JS/PDF means a big slice of the web is invisible. Maturity: strong
single-site/niche engine.

**torrentds** — clean, correct, hostile-input-hardened, and unusually well-tested
(loopback DHT round-trips, real BEP-9 fetch, tracker announce/scrape). As an *indexer*
it's throttled by two things and one dead function: no **BEP-51** (`sample_infohashes` —
the single biggest harvest lever, entirely absent), a **single serial** metadata-fetch
worker, and `make_neighbor_id` (the Sybil-harvest booster) sitting as dead code. It also
discards the verified raw info-dict, so it can only emit magnets, never `.torrent` files.
As a *tracker* it's standards-correct and safe but IPv4-only, in-memory (restart wipes
swarms), and reaps too eagerly. Maturity: correct core, harvest throttled by design.

**AstrX bridge** — thin and solid: two zero-dep PHP modules proxying the engines' JSON
APIs, SSRF-hardened, XSS-safe, passing AstrX's own PHPStan-L10 / module / parity gates.
It does exactly what it should and nothing more.

## Cross-cutting themes (these matter most — they repeat in all four)

1. **Wire up what's already there.** The highest-ROI work isn't new subsystems, it's
   connecting signals each tool already produces: websearch's cross-domain inlinks,
   torrentds's raw info-dict and dead Sybil trick, gitweb's allow-listed-but-unused
   `diff-tree`, onioncrawler's own onion-regex (never run over body text) and dropped
   `Sitemap:` lines. Several are S-effort, H-impact.

2. **Nothing recrawls.** onioncrawler and websearch are both effectively write-once; their
   indexes rot. A recrawl scheduler + real freshness (store `Last-Modified`/`ETag`, do
   conditional GET) is the same fix for both and is table-stakes for "search engine."

3. **Everything is single-in-flight.** onioncrawler processes one URL per host, websearch
   one fetch at a time, torrentds one metadata fetch at a time. All three frontiers/leases
   are concurrency-*ready*; none has a multi-worker driver. This is the throughput ceiling
   across the board.

4. **Counters exist; observability doesn't.** All four collect stats and then drop them
   (torrentds prints once at shutdown; websearch's `verbose` flag is dead code). A shared
   `/metrics` + structured logs makes long crawls operable.

5. **Not deployable yet.** None ships `pyproject.toml`, a Dockerfile, or a systemd unit;
   all say "cd into the dir and `python3 -m`." A unified compose that runs all four behind
   Tor as onion services is a small, high-value suite-level win.

6. **The zero-dep line is now a decision, not a rule.** You said other languages/deps are
   fine where better suited. A few high-value items genuinely want that (flagged ⚠ below):
   syntax highlighting, PDF extraction, real index scale-out, and DHT harvest throughput.

## Roadmap

Effort S/M/L, impact H/M/L. Ordered within each tier by ROI.

### Tier 1 — quick wins (mostly S, wire-up or config)

| Tool | Item | E | I |
|---|---|---|---|
| websearch | Use cross-domain inlinks (`internal=0`, already recorded) for authority; today discarded under the heaviest weight | M | H |
| torrentds | Concurrent metadata-fetch pool (Semaphore over N workers) — the serial worker is the ceiling | S | H |
| torrentds | Persist the verified raw info-dict → serve real `.torrent`, not just magnets (currently discarded irreversibly) | S | H |
| onioncrawler | Plaintext `.onion` discovery (run the existing regex over body text) + honor `Sitemap:` | S–M | H |
| gitweb | ETag + 304 on sha-immutable views + gzip HTML — kills re-fork + re-download on every Tor navigation | S–M | H |
| gitweb | Cache last-commit-ts in `discover_repos` — removes the N-forks-per-homepage cliff | S | H |
| all | Expose the counters that already exist via `/metrics` + wire the dead `verbose`/stats | S | M |
| torrentds | Store growth control: prune fetched/exhausted rows, retention + `VACUUM` (it grows unbounded) | S | M |
| all | Packaging: `pyproject.toml` + console entry point + Dockerfile + systemd unit | S | M |

### Tier 2 — high-impact (M)

| Tool | Item | E | I |
|---|---|---|---|
| torrentds | **BEP-51 `sample_infohashes`** — directed enumeration vs passive sniffing; the single biggest harvest lever, and it's absent | M | H |
| torrentds | Activate harvesting: wire the dead `make_neighbor_id` + run multiple DHT node-IDs for ID-space coverage | M | H |
| onioncrawler + websearch | Recrawl scheduler + real freshness (conditional GET on `Last-Modified`/`ETag`); ends index rot | M | H |
| onioncrawler + websearch | Multi-worker crawl driver (leases are ready); + HTTP keep-alive / circuit reuse; + DNS cache (web) | M | H |
| gitweb | Persistent `git cat-file --batch` reader — collapse the 4 forks-per-blob into one process | M | H |
| all engines | Search filters/facets/operators (`site:`/host, size/filecount/category, date, lang) — all indexed, none queryable | M | M–H |
| onioncrawler | Runtime add-onion submission + external seed import (Ahmia-style intake) | M | H |
| onioncrawler | Liveness/uptime tracking + dead-onion aging (index currently never forgets) | M | H |
| torrentds | Swarm health on results: scrape known trackers / wire the local PeerStore into the index | L | H |
| gitweb | Atom feed per repo/branch (makes it followable) + per-file history (machinery exists) | M | H |

### Tier 3 — bigger bets / architectural (L, or crosses the zero-dep line ⚠)

| Tool | Item | E | I |
|---|---|---|---|
| websearch + onioncrawler | Real link-graph authority/PageRank at scale (not per-site) — the core ranking upgrade | L | H |
| websearch + onioncrawler | Near-dup / mirror clustering (simhash/minhash) instead of exact-hash drop | L | M–H |
| all | Rate-limiting + optional auth on the public search/API endpoints (none have it) | M | M |
| torrentds | Tracker: IPv6/BEP-7, durable peer store, randomized peer selection, stateless UDP conn-id | M | M |
| gitweb | ⚠ Syntax highlighting (optional Pygments w/ escaped fallback) — biggest UX gap vs peers | M | M–H |
| websearch | ⚠ PDF/office extraction (widen content types) — a real slice of the web | M | M |
| websearch | ⚠ Index scale-out (shard FTS by host-hash + fan-out) — only if web-scale is the goal | L | M |
| torrentds | ⚠ Consider Go/Rust for the DHT harvester if you want 10–100× throughput; asyncio multi-worker gets most of the way first | L | M |

## New features (suite-level, forward-looking)

- **One-command Tor deployment.** A `docker-compose.yml` that runs all four services + a
  Tor daemon, each published as its own v3 onion service, with the AstrX bridge pointed at
  them. Turns "cd and python3 -m" into "compose up." Highest-value suite glue.
- **Shared `crawlcore` library.** onioncrawler and websearch duplicate ~70% (frontier,
  robots, trap guards, dedup, HTTP client) — deliberately, for independence. A shared core
  with two fetchers (Tor / direct) means every crawl improvement lands once, not twice.
  Refactor, not a rewrite.
- **A no-JS UI kit.** The three search pages, gitweb, and the torrent search share no
  styling. A tiny common CSS/template kit (matching AstrX's themes) makes the suite look
  like one product and lets the AstrX pages inherit it.
- **AstrX admin surfaces.** Beyond the search bridges: an AstrX admin panel that reads each
  engine's `/stats`+`/metrics` (health badges, crawl progress) and drives the abuse-queue
  moderation + crawl start/stop — turning the "edit a .txt and restart" workflow into a UI.
- **Two more AstrX pages if wanted:** a torrent-search page (fourth search surface) and a
  gitweb page (repo browsing inside AstrX), both the same thin-bridge pattern.
- **A suite ops page.** One onion page aggregating `/metrics` from all services — the thing
  you actually open to see if the crawlers are alive.

## What I'd do first (my pick)

If you want the highest impact for the least work, in order:

1. **torrentds: fetch-pool + BEP-51 + activate the dead Sybil code.** Three changes,
   mostly S/M, that move harvest throughput by orders of magnitude. Biggest single leap in
   the suite.
2. **websearch: use the inlinks you already record.** One ranking change that fixes the
   heaviest, currently-misapplied signal.
3. **Recrawl + freshness for both crawlers.** Stops the indexes from rotting; same fix
   twice (or once, if you build `crawlcore` first).
4. **gitweb: ETag + gzip + cat-file batch.** Makes it genuinely pleasant over Tor.
5. **The compose-up-behind-Tor deployment + a shared `/metrics`.** Makes the whole thing
   operable and shippable.

Everything here is additive — the current suite is correct and tested (200 tests,
security-hardened) and each item lands independently. I'd suggest picking a tier-1 batch
and I'll build + test it the same way as before. The one thing worth an explicit decision
before Tier 3 is whether the index stays a single SQLite file (fine to ~10⁷ docs, keeps
the zero-dep ethos) or scales out (new dependencies/architecture) — that fork determines
several of the bigger items.
