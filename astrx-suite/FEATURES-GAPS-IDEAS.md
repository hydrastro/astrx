# astrx-suite — what each tool does, what it lacks vs the originals, and what's next

Honest, per-tool. "Does" = what's actually implemented and tested now. "Lacks" =
the real gap to the tool it takes after. All four are zero-dependency, no-JS,
read-only-facing, and built to sit behind a Tor hidden service.

---

## gitweb — vs cgit / Gitea / Forgejo (read side)

**Does.** Browses local repos with no JavaScript: repo list, paginated commit log,
commit view with full diff, tree/blob browsing, blame, branches/tags, raw
download. On top of that: per-file history, compare-between-refs, Atom feeds (per
repo and per branch), `git archive` tar.gz snapshots, inline image rendering,
signed-commit "verified" badges, optional syntax highlighting (Pygments if present,
safe escaped fallback if not), a real markdown renderer (tables, images, autolinks,
task lists), a ref switcher, sha/line-range permalinks, submodule and LFS-pointer
display, gzip + ETag/304 caching, a persistent `git cat-file --batch` reader,
`/metrics` + `/health`, `--url-prefix` for sub-path mounting, and — new this round
— **`git clone` / `git fetch` over HTTP** (Smart HTTP, protocol v2), read-only and
resource-bounded (wall-clock timeout, body cap, concurrency limit, whole
process-group kill so a clone can't orphan a `pack-objects`).

**Lacks.** It's a cgit-class *viewer*, not a Gitea/Forgejo *forge*. No push/write
(deliberate), no issues / pull requests / wiki / web-based repo creation, no code
or commit-message search, no rendered commit graph (the log is flat), no
per-repo settings UI, no webhooks/CI, no built-in auth or access control (it
relies on the Tor layer or a reverse proxy), no LFS *content* serving (it only
detects pointers), and markdown is a strong subset, not full CommonMark.

---

## onioncrawler — vs Ahmia

**Does.** Crawls `.onion` hidden services and only those — a hard anti-leak gate
refuses any non-onion host before a socket opens — over a hand-rolled SOCKS5 to a
local Tor daemon. It's crash-safe resumable (a leased SQLite frontier), survives
bot traps (depth/budget/cycle/query-explosion guards, a now-linear robots matcher),
recrawls with conditional GET for freshness, runs multiple workers with per-host
politeness, discovers new onions from link text and sitemaps, tracks host
liveness and ages out dead ones, computes a cross-onion link-graph authority and
folds it into ranking, collapses near-duplicate mirrors with SimHash, rate-limits
and (optionally token-gates) its endpoints, accepts runtime seed submissions, and
serves a no-JS FTS5 search UI + JSON API. Ships an operator abuse-blocklist.

**Lacks.** Ahmia is a *run-at-scale service*, not just an engine. It lacks Ahmia's
operational scale (Elasticsearch-backed index vs a single SQLite file; continuous
large-fleet crawling), a big curated known-onions seed list and public submission
funnel, i2p support, and — most importantly — Ahmia's mature abuse pipeline
(known-hash CSAM blocking against maintained hash lists). The blocklist here is a
first-class, tested hook, but it's only as good as the lists the operator feeds it.

---

## websearch — vs SearXNG / YaCy / a real web engine

**Does.** A from-scratch crawler + inverted index, not a metasearch proxy. Polite
resumable crawl with a strict SSRF denylist (every connect, redirect hop, cached
resolution and keep-alive reuse is re-checked against internal ranges), an FTS5
index, and an explicit ranking function — BM25 over title/description/body weights,
a cross-domain PageRank-lite link authority, freshness, and phrase proximity.
Query operators (`site:`/`lang:`/`filetype:`/`intitle:`/`date:`), SimHash
cross-host dedup, wider content types incl. best-effort PDF text (now O(n)),
recrawl/freshness, multi-worker with a DNS cache, rate-limiting, a no-JS results
UI + JSON API, and metrics.

**Lacks.** SearXNG is a different model (it aggregates other engines); the closer
comparison is YaCy, and the gap is scale and reach. Single-node, single SQLite
index (fine to ~10⁷ docs, not web-scale — no sharding/distribution like YaCy's
P2P), no JavaScript-rendered page support (no headless browser), no image/video/
news verticals, no spelling/autocomplete/suggest, no large live index, and no
SEO-spam defense at scale. It's a genuine niche/site engine, not a Google.

---

## torrentds — vs magnetico / btdig / opentracker

**Does.** A DHT metadata indexer *and* a standards tracker. The indexer joins the
Mainline DHT (BEP-5), harvests infohashes both passively and actively (BEP-51
`sample_infohashes` + Sybil neighbor placement + multiple node IDs), fetches
metadata over the wire (BEP-9/10) with byte-exact SHA-1 verification, a concurrent
fetch pool with per-fetch deadlines and per-piece size caps, persists the verified
raw info-dict so it can serve real `.torrent` files (not just magnets), searches
with FTS5 + filters (size/files/category/recency) + swarm health, and offers
RSS + a JSON API. The tracker speaks HTTP (BEP-3/23) and UDP (BEP-15), with
IPv6/BEP-7, a durable peer store with live LRU caps, a stateless HMAC connection-id,
source-address anti-spoofing, and store growth control. KRPC is hardened (random
transaction IDs + source-address matching). Blocklists throughout.

**Lacks.** btdig runs at scale with years of harvested data and a huge public
index; that scale and curation is the main gap. Protocol-wise it's fairly
complete but lacks **BEP-52 (v2 / SHA-256 torrents)**, cross-infohash dedup,
fake/spam-torrent heuristics, and tracker-*scrape* aggregation across many
trackers for accurate seeder counts (it reports its own swarm + DHT, not the
global picture).

---

## Supporting pieces (no single "original")

**suitedash** — a no-JS ops dashboard that polls every service's health/metrics
and shows up/down + numbers; bounded and escaping-safe. **astrx-integration** —
five AstrX modules: clear-web, onion and torrent search *pages* (separate, as you
wanted), an admin/status page (with onion-seed submission), and a gitweb
link-through. **deploy** — a one-command `docker compose` that runs the whole
suite behind a Tor daemon as v3 onion services, no host ports, non-root, cap-dropped.

---

## New features worth considering (ranked, roughly by value ÷ effort)

1. **gitweb: code/commit search + a rendered commit graph.** The two things a cgit
   user reaches for that aren't here. Search is `git grep`/`log --grep` behind a
   box; the graph is parent-lane drawing over `log --parents`.
2. **onioncrawler: a known-onions seed importer + scheduled re-seed**, and
   **hash-list abuse filtering** (match uploaded media against maintained hash
   lists) — the two things that separate a toy index from an Ahmia-grade one.
3. **A unified search page** that federates internal + clear-web + onion + torrent
   behind one query (you said "maybe later" — this is the later), with per-source
   tabs so it isn't a mush.
4. **torrentds: BEP-52 v2 torrents** + **tracker-scrape aggregation** for real
   seeder counts, and a "recently added / browse by category" UI.
5. **websearch: an OpenSearch descriptor** (so a browser can add it as a search
   engine) + spelling/autocomplete; and, as a deliberate architecture call, either
   commit to single-SQLite forever or add a sharded index for scale-out.
6. **AstrX admin, deepened:** crawl scheduling, a blocklist editor, and live
   stats dashboards as real AstrX admin modules — turning "edit a file and restart"
   into a UI.
7. **Cross-suite ops:** Prometheus/Grafana-friendly metrics + alerting in
   suitedash, a backup/restore story for the SQLite stores, and an optional auth
   layer in front of the admin surfaces.
8. **A headless-render worker** for JS-heavy pages (clear-web) and page
   screenshots/thumbnails (onion) — this one breaks the zero-dependency rule, so
   it'd be an optional, separate service, not part of the core.
