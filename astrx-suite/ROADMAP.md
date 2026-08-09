# astrx-suite (Rust) — roadmap & status

One language: **Rust**, for the whole suite. This tracks what is implemented and
what remains. Updated 2026-08-09.

## The shape of the work

Six engines + one shared library, ported from the Python suite (now in
`legacy-python/`, retired one engine at a time). Each engine is a crate that
stands up behind the **identical JSON/loopback API** the AstrX CMS already speaks,
so the PHP bridge (and its 145 tests) never change and the suite keeps running
throughout the migration. Zero third-party dependencies by default;
`#![forbid(unsafe_code)]`; every parser gets `cargo fuzz`.

## Status at a glance

| Component        | State        | Done | Remaining |
|------------------|--------------|------|-----------|
| **crawlcore**    | ✅ complete   | globmatch, dedup (SimHash), scheduler, traps — 14 tests, SimHash byte-identical to Python | — |
| **torrentds**    | 🚧 indexer   | bencode (+ `decode_prefix`/`decode_lenient`) + infohash + KRPC codec + async UDP transport (anti-spoof) + Kademlia routing + **live DHT node** (all 4 queries + BEP-51 `sample_infohashes`; harvest; announce-token guard + **served peer store**; **iterative `get_peers` lookup**; Sybil crawl) + **metadata fetch** (BEP-3/10/9 peer wire + ut_metadata: handshake → extended handshake → piece request/assembly → SHA-1 verify → info-dict `TorrentMeta`, loopback round-trip) + **BEP-33 scrape** (swarm size from bloom filters) + **release classifier** (resolution/source/codec/HDR/year/SxxExx/group/lang → tag string, regex-free) — 52 tests; node datagrams, ut_metadata wire, SimHash, BEP-33 filters & the classifier all cross-checked byte-identical to Python | HTTP+UDP trackers (BEP-3/15/23); BEP-52 v2/hybrid metadata; store (SQLite); no-JS search + JSON API; blocklist; Torznab |
| **onioncrawler** | ⏳ not started | — | SOCKS5 fetcher + **darknet gate as a type** (clearnet leak = compile error); resumable frontier (lease/resume); robots + trap wiring (uses crawlcore); FTS search; abuse blocklist; entity index; Tor fetch-pool |
| **websearch**    | ⏳ not started | — | polite resumable crawler; SSRF denylist (as a type); FTS index; BM25 + PageRank ranking; verticals; no-JS UI + JSON API; federation (sharding + scatter-gather) |
| **gitweb**       | ⏳ not started | — | argv-only git exec (confined); repo/log/diff/tree/blob/blame/refs; releases; patch/mail archive; no-JS UI |
| **suitedash**    | ⏳ not started | — | no-JS ops dashboard; health/metrics aggregation |
| **deploy**       | ⏳ not started | — | `FROM scratch` static-binary images; Tor; compose; FLEET.md |

Legend: ✅ done · 🚧 in progress · ⏳ queued.

## Phased timeline (sequenced by risk/leverage)

**Phase 0 — foundation (done).** Workspace, CI bar (fmt + clippy `-D warnings` +
test), crawlcore. ✅

**Phase 1 — torrentds (in progress).** The wire core is done and fuzzed: bencode
+ infohash + the pure KRPC message codec (BEP-5) — the byte-level parsing of
hostile datagrams from anonymous peers, where Rust earns its keep. On top of it:
the async UDP transport (transaction matching + off-path injection defence), the
Kademlia routing table, and now the **live DHT node** — it answers all four
queries plus BEP-51 `sample_infohashes`, harvests infohashes magnetico-style out
of inbound `get_peers`/`announce_peer` traffic, guards announces with a per-address
token, and can run the Sybil-neighbour crawl to attract more of a target's
traffic. Two `DhtNode`s exchange the full query set over loopback in the tests,
and all nine of the node's on-wire messages are pinned byte-identical to the
Python reference. On top of the node: a bounded **served peer store** (announces
populate it; `get_peers` answers with `values`) and an **iterative `get_peers`
lookup** that hops closer-and-closer to an infohash to turn a harvested hash into
a fetchable swarm — proven end-to-end on a loopback A→B→C topology. Alongside,
two pure enrichment/health modules landed: **BEP-33 scrape** (recover a swarm's
seeder/leecher count from the DHT's own bloom filters) and a **regex-free release
classifier** (name → resolution/source/codec/HDR/year/season·episode/group/lang
facets + a stable tag string), both cross-checked byte-for-byte against the
Python over a corpus. And the payoff step: **metadata fetch** — the BEP-3/10/9
peer-wire client that connects to a peer, negotiates the extension protocol,
pulls each 16 KiB `ut_metadata` piece, verifies `sha1(metadata) == info_hash`, and
parses the info-dict into a `TorrentMeta` (name, files, sizes). Its builders are
pinned byte-identical to Python and a loopback peer proves the full round-trip
(single-piece, multi-piece, and corrupt-rejection). Next: the HTTP/UDP trackers,
BEP-52 v2 metadata, the SQLite store, and the search/JSON API. The pure wire core
(bencode/infohash/krpc/bep33/classify)
stays dependency-free; only the live node adds two vetted deps — `tokio` (async
runtime) and `getrandom` (CSPRNG for the unguessable transaction ids). This is the
crate with the strongest safety argument, so it goes first.

**Phase 2 — onioncrawler.** The crown-jewel safety win: model the darknet-only
gate as an `OnionHost` newtype the fetcher *requires*, so "never fetch clearnet
from the onion crawler" becomes a compile-time guarantee. Reuses crawlcore
(robots/traps/dedup/scheduler) directly.

**Phase 3 — websearch.** Crawler + FTS + ranking. Folds in the requested ranking
work (BM25 + real PageRank + freshness + popularity as selectable methods) and
the verticals, behind the same `/api/search` the PHP bridge already calls.

**Phase 4 — gitweb + suitedash.** Smaller, lower-risk; gitweb is argv-only git
exec + rendering, suitedash is aggregation.

**Phase 5 — deploy + cutover.** `FROM scratch` images, Tor, compose; then delete
`legacy-python/` engine-by-engine as each Rust engine reaches parity. When
`legacy-python/` is empty, the migration is complete.

## Cross-cutting feature backlog (folded into the engines above as they land)

These were requested against the old suite and will be built into the Rust
engines rather than the Python: per-page controls (done in the PHP page already),
**multi-method/PageRank ranking**, **NotEvil-style click-through ranking** (a
redirect endpoint + aggregate click counts feeding rank), a **guarded submission
page** (robots/bot-trap/blocklist checks before a URL is queued), **crawler
control in the admin**, and onion/torrent UI parity. The CMS/PHP bridge stays put;
engines are swapped underneath it.

## Review results (2026-08-09)

Everything written so far was adversarially reviewed (three independent passes).
Headline: the code is sound — bencode `decode` is provably panic-free (~1.45M
fuzzed inputs, zero panics), SHA-1 is correct at every padding boundary, and the
PHP search feature is XSS/SSRF-clean. Fixes applied from the review:

- **crawlcore/dedup** — accumulate SimHash columns in `i128` so hostile `i64`
  weights can't overflow-panic (debug) or wrap (release). Regression test added.
- **crawlcore/globmatch** — cap `MAX_PATTERN_LEN` by *characters* (matching
  Python), not bytes, so a multi-byte pattern truncates identically.
- **crawlcore/traps** — `numericish` now also strips ASCII controls `\x1c–\x1f`
  (matching Python's `str.strip()`); `cyclic_path` uses `saturating_mul`;
  ASCII-digit scope documented as deliberate (a crawl heuristic, not a locale
  parser — keeps the crate dependency-free).
- **torrentds/bencode** — the one divergence (Python's arbitrary-precision int vs
  Rust `i64`) is documented as a deliberate, safe protocol bound with a test;
  every real BitTorrent/KRPC integer fits `i64`.

Known-minor, deferred (in the PHP CMS, not the rewrite): an out-of-range `?page=`
hides the "Prev" link (UX), and a config/controller per-page ceiling mismatch
that can't fire with the current engine. Both low; tracked, not blocking.

## The CI bar (enforced on every crate)

`cargo fmt --check` · `cargo clippy --all-targets -- -D warnings` · `cargo test`
· `cargo fuzz` smoke for parsers. All green today.
