# astrx-suite (Rust) — roadmap & status

One language: **Rust**, for the whole suite. This tracks what is implemented and
what remains. Updated 2026-08-11.

## The shape of the work

Six engines + one shared library, ported from the Python suite (now in
`legacy-python/`, retired one engine at a time). Each engine is a crate that
stands up behind the **identical JSON/loopback API** the AstrX CMS already speaks,
so the PHP bridge (and its 145 tests) never change and the suite keeps running
throughout the migration. Zero third-party dependencies by default — literally,
and asserted in CI: each crate's default build pulls no third-party crates, and
live networking is opt-in behind feature flags (`net`/`rand` → `tokio`,
`getrandom`). `#![forbid(unsafe_code)]` across the tree; the hostile-input
parsers have `cargo fuzz` harnesses (`fuzz/`).

## Status at a glance

| Component        | State        | Done | Remaining |
|------------------|--------------|------|-----------|
| **crawlcore**    | ✅ complete   | globmatch, dedup (SimHash), scheduler, traps, hashing (SHA-1/SHA-256/MD5 + BLAKE2b), INFLATE (DEFLATE/gzip/zlib), **`urlparse`** (shared `urllib.parse`/`posixpath` subset used by both crawlers) — 32 tests, byte-identical to Python | — |
| **torrentds**    | ✅ parity    | SHA-1 + **SHA-256** + bencode (+ `decode_prefix`/`decode_lenient`) + KRPC codec + async UDP transport (anti-spoof) + Kademlia routing + **live DHT node** (all 4 queries + BEP-51; harvest; token guard; iterative `get_peers` lookup; Sybil crawl) + **metadata fetch** (BEP-3/10/9 ut_metadata + **BEP-52 v2/hybrid**: SHA-256 infohash, bounded file-tree walk → `TorrentMeta`) + **magnet** (btih/btmh) + **HTTP + UDP tracker servers** (BEP-3/23 + BEP-15) on a shared **swarm peer store** (LRU-bounded, TTL-reaped, bencode snapshot/restore) + **BEP-33 scrape** + **release classifier** (regex-free, Unicode `\b`) + **spam heuristics** + **persistent index store** (records + ingest, categorize, content-sig dedup, discovered queue, blocklist, retention, stats, **dependency-free FTS inverted index + BM25 search**, bencode snapshot/restore) + **no-JS search UI + JSON API** (`/search`, `/browse`, `/recent`, `/t/<ih>`, `/api/*`, RSS, rebuilt `.torrent`, `/metrics`, `/health`, token-gated `POST /api/block`) + **Torznab/Newznab feed** + **indexer** (harvest sink → discovery queue → bounded concurrent fetch pool → store; BEP-51 sampler; DHT-resolve fetch; maintenance/retention; routing-contact persistence for warm restart) — 100 tests (workspace: 114); DHT/ut_metadata/tracker wire, v1/v2/hybrid infohashes, magnet, SimHash, BEP-33, classifier, spam, store helpers, serving-layer helpers all cross-checked byte-identical to Python + loopback round-trips of the HTTP server **and the harvest→store→fetch path**. Layered into `default`/`rand`/`net` feature tiers so the pure wire core is dependency-free | at parity — retire `legacy-python/torrentds/` (kept for now as the golden reference) |
| **onioncrawler** | ✅ engine complete | 137 | **darknet host gate as a type** — `OnionHost`/`I2pHost`/`DarknetHost` construct-only-if-valid (`normalize_host`, v3/v2 onion, i2p b32/name, `is_darknet_host`) so a clearnet/localhost/IP leak is a *compile* error; in-text `.onion` discovery scanner (look-behind, 56/16, port clamp, path-stop set); stdlib language-guess; **URL canonicalizer** (dependency-free `urllib.parse`/`posixpath` port: `urljoin` RFC-3986 resolution, percent quote/unquote, `normpath` incl. leading-`//`, query clean/sort/`+`↔space, template + skeleton trap keys); **entity extractor** (PGP-armor SHA-1 fingerprint + backtracking-free btc/xmr/eth recognition); **abuse filter** (host/keyword/media blocklists + Ahmia `md5(domain)` bans, every-page hot path); **robots.txt parser** (UA groups, Allow/Disallow + `$` over the ReDoS-safe glob, Crawl-delay, Sitemap); **sitemap parser** (hand-rolled XXE/bomb-safe XML parser reproducing ElementTree's entity decoding, `el.text` and parse-error behaviour); plus the **`net` tier** (functionally complete) — a token-bucket **rate limiter**; the **SOCKS5 client** (RFC-1928/1929 encoders + async `socks5_connect`, remote DNS + per-host stream isolation); the **HTTP client** (`build_request`/status/header parse, async `perform_request` with content-length/chunked/close framing + keep-alive reuse + **gzip/deflate `decompress`** with a bomb cap over crawlcore's **INFLATE**); the **I2P proxy** encoders + `read_connect_reply`; and the **fetcher** (`FetchResult` + a `&OnionHost`-gated `open`, redirect loop, `TorSocks` + testing `Direct` transports) — cross-checked byte-identical to Python **plus loopback round-trips** of the SOCKS handshake and the full fetch (redirects, gzip, clearnet-refusal). plus the **storage layer** — a hand-rolled **dependency-free store** ported from the SQLite/FTS5 Python `Storage`: the resumable, leased **frontier** with trap-cap admission control (same `ok`/`dup-url`/`unique-budget`/`host-budget`/`template-cap`/`skeleton-cap`/`host-dead` codes), the host **state machine** + politeness + robots + dead-onion **liveness aging**, the **page store** with exact-content dedup (`stored`/`updated`/`unchanged`/`duplicate`), the **entity verticals**, the inter-onion **link graph** with offline **PageRank**, **SimHash** mirror clustering, and a versioned **snapshot/restore** blob (native `i64`/`f64`, no DB) — with **`simhash64`** (BLAKE2b token hash, now in `crawlcore`) as the fuzzy fingerprint. Cross-checked byte-identical to Python **plus loopback round-trips** of the SOCKS handshake and the full fetch (redirects, gzip, clearnet-refusal); the store's PageRank + clustering + `simhash64` cross-checked against the Python `Storage`, and a snapshot round-trip covered. Plus a dependency-free **FTS search** over the stored bodies — a hand-rolled inverted-index **BM25** (title-10×/body-1× field weighting, implicit-AND + quoted phrases, host/lang/date filters, host+language facets, near-duplicate collapse, optional authority blend, hidden-host exclusion), the behaviourally-faithful stdlib replacement for the Python SQLite/FTS5 `bm25` path (unit-tested; not bit-identical, since stdlib has no FTS5). Plus the **no-JS HTTP serving layer** — pure renderers + a pure `route()` over an `Arc<Mutex<Store>>` (HTML search UI + `/api/search`, entity `/find` + `/api/find`, `/stats` + `/api/stats`, `/cached`, `/health`, Prometheus `/metrics`, `/robots.txt`, `/opensearch.xml`), the async accept loop behind `net`, verified by a loopback round-trip + pure route tests — now including the **write endpoints** (`POST /add` / `/purge` / `/recrawl` behind a `Bearer` admin token, with an optional capped public-submit path) over the ported **submit** intake (canonicalize → darknet-only gate → abuse-check → trusted/untrusted `add_seed`). Zero third-party deps by default (net tier behind `net`/`rand` → tokio + getrandom; reuses first-party `crawlcore` + its shared `hash`/`inflate`/`blake2b` modules; `https` awaits an opt-in `tls` feature). the **HTML extractor** (`extract_html`: title + visible text with block line-breaks, followable `<a href>` links with `rel=nofollow`, `<base href>`, robots `<meta>`, charset decode) — a dependency-free port of the stdlib `html.parser` extractor, cross-checked to Python on a corpus; and the capstone **crawl orchestration loop** (`Crawler`: lease → fetch → `extract_html` → index → capped frontier expansion, with darknet gate, abuse blocks, content-type allowlist, X-Robots/meta-robots, `content_hash` dedup, path-traps + caps + deduped link edges, in-body `.onion` discovery, liveness + dead-onion aging, host trap-scoring, politeness, optional concurrent workers, and **`robots.txt`** fetch+cache+obey with capped Crawl-delay) — verified by end-to-end loopback crawls (drain, cross-host edge, depth cap, robots-disallow) + a `content_hash` cross-check. **Engine functionally complete** (crawl→index→serve end-to-end); Python `onioncrawler/` ready to retire. Deferred refinements: conditional GET, media-hash blocking, jitter, scheduled reseed, opt-in `tls` |
| **websearch**    | 🚧 crawl→index→search | 72 | **SSRF gate as a type** — `SafeIp` wraps an `IpAddr` only after `ip_is_internal` clears it (loopback/private/link-local/reserved/multicast/unspecified, IPv4-mapped unwrapped, **fail-closed**), so the net-tier connect taking a `&SafeIp` makes an SSRF to an internal address a *compile* error; cross-checked byte-identical to Python over a 60+-case IPv4/IPv6 special-range corpus. Plus **`dedup`** (FNV-1a word-bigram-shingled 64-bit SimHash, reusing crawlcore's bit-math, cross-checked), the **URL canonicalizer** (`canonicalize`/`host_of`/`authority_of`/`is_http_url`/`in_scope`: lower-case scheme+host, default-port drop, userinfo/fragment strip, RFC-3986 dot-segments, multi-slash collapse, query sort, IPv6 brackets) on the now-shared **`crawlcore::urlparse`** (lifted out of onioncrawler so both crawlers share one `urllib.parse` port), and the **robots.txt parser** (UA-group precedence, Allow/Disallow with `*`/`$`, longest-match + Allow tie-break, Crawl-delay, over crawlcore's ReDoS-safe glob) — all cross-checked byte-identical to Python. Plus the **HTTP wire core** where the gate reaches the socket: **`vet_addrs`** turns a host's resolved addresses into `SafeIp`s or refuses the host (any-internal → `Blocked`, first offender named — the pure form of the Python `_resolve_checked`, DNS-rebind-safe by pinning), the **`allow_hosts`** authority exemption (`SafeIp::exempt`, `pub(crate)` — the one audit-visible bypass for an operator-allow-listed internal host), `Content-Type` parsing, `Content-Encoding` decompression (gzip/deflate/zlib over crawlcore's INFLATE, bomb-capped), charset body decode, and the HTTP/1.1 request/response/chunked wire helpers — `vet_addrs`/`authority_exempt`/`parse_content_type`/`decompress`/`decode_body` cross-checked byte-identical to Python. Plus the **`net` tier** — the async SSRF-checked fetch: a TTL DNS cache, `resolve_checked` (resolve → `vet_addrs` → `Vec<SafeIp>`), `connect_pinned` (dials **only** a `&SafeIp` — the connect can't be pointed at an unvetted address), `perform_request` (content-length/chunked/close framing + gzip/deflate over crawlcore's INFLATE), and the redirect-following `fetch` re-running the SSRF gate on **every hop** — loopback round-trips incl. that a loopback address is refused by default and reachable only via the explicit `allow_hosts` exemption. Plus **`htmlparse`** (stage 1 — core extraction): a dependency-free `html.parser` port → `<title>`, meta `description`, visible body text (script/style/noscript/template/svg/math excluded; nav/header/footer/aside/form boilerplate text dropped, its links kept), `<a href>` in document order, `<link rel=canonical>`, `<base href>`, `meta robots`, stop-word language guess — cross-checked byte-identical to Python on realistic pages. Plus the **crawl `frontier`** (dependency-free port of the SQLite queue): per-URL state, deterministic leasing (shallowest queued, ties by insertion) with per-host politeness (`next_time`/`crawl_delay`/fetch counter), host budgets, missing-host lease fallback, `reclaim`-for-resume, robots cache — lease ordering + politeness + budgets cross-checked byte-identical to Python. Plus the **`index` store core** (dependency-free port of the SQLite doc store): `content_hash` (SHA-256 exact-dup key), `upsert_document` (stable rowids + update-in-place), conditional-GET validators + `touch_revalidated`, recrawl due-list, the `(src → dst)` link graph + `recompute_incoming`, index `stats` — `content_hash` + store behaviour cross-checked byte-identical to Python. And the resumable **crawler** (single-worker) tying it together: lease → SSRF-checked fetch → htmlparse → index → capped frontier expansion, with per-host/total/depth budgets, content-type allowlist, canonicalization + dedup, trap guards, `robots.txt` fetch+cache+obey (with `Crawl-delay`), politeness, `rel=canonical` + `meta robots`, content-hash dedup, and conditional GET (304) — verified by end-to-end loopback crawls (drain, index, robots-block, link graph) + that the crawl stays SSRF-gated. And the query side — **`ranking`** (search): `parse_query` (phrases, `+`/`-`/optional, `site:`/`lang:`/`filetype:`/`intitle:`/`before:`/`after:`/`date:`/`boost:`/`penalize:` operators → structured filters), an explicit ranking (field-weighted BM25 base + link + host authority + freshness + proximity/phrase + content-quality + boost/penalize optics), cross-host SimHash near-dup collapse, pagination + `fresh`/`only_files` verticals, and a query-biased HTML-safe `make_snippet` — the parser/scoring/snippet cross-checked byte-identical, BM25 a behaviourally-faithful hand-rolled Okapi stand-in. **The crawl→index→search pipeline runs end-to-end.** Zero third-party deps by default (net → tokio + getrandom) | PageRank/host-authority ranking signals + the no-JS UI + JSON API **server** (`/api/search`, `/stats`, …); **htmlparse stage 2** (verticals + JSON-LD/SPA `_recover`, needs a bounded JSON parser); keep-alive **`Fetcher`** pool; federation (sharding + scatter-gather); multi-worker crawler; pdftext; suggest |
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
(single-piece, multi-piece, and corrupt-rejection). `parse_magnet` turns a
`magnet:` link (btih hex/base32 or btmh v2 multihash) into that infohash.

torrentds now also **serves** as a tracker: a shared, LRU-bounded, TTL-reaped
**swarm peer store** (with bencode snapshot/restore for restart survival — no
database needed) backs both a **BEP-15 UDP tracker** (stateless keyed
connection-ids, source-address-only peers) and a **BEP-3/23 HTTP tracker**
(binary query parsing, compact + dict peer lists). Both wire formats are pinned
byte-identical to Python and round-trip over loopback.

The metadata path now also handles **BEP-52 v2 and hybrid** torrents: a
hand-rolled SHA-256 (FIPS-vector-tested) gives the v2 infohash, a depth- and
node-bounded `file tree` walk yields the file list, and `fetch_metadata` verifies
the assembled bytes with SHA-256 (truncated or full) instead of SHA-1 when a v2
hash is supplied. v2, hybrid and the content fingerprints are all cross-checked
byte-identical to Python. Next: a persistent index store, then the no-JS search UI
+ JSON API. The pure wire core (bencode/infohash/krpc/bep33/classify/peerstore)
stays dependency-free; only the live networking adds two vetted deps — `tokio`
(async runtime) and `getrandom`
(CSPRNG). This is the crate with the strongest safety argument, so it goes first.

**Phase 2 — onioncrawler (engine complete).** The crown-jewel safety win: model the
darknet-only gate as an `OnionHost` newtype the fetcher *requires*, so "never
fetch clearnet from the onion crawler" becomes a compile-time guarantee. The
**entire pure core** has landed, cross-checked byte-identical to Python: the gate
(`OnionHost`/`I2pHost`/`DarknetHost` + the `.onion` discovery scanner), the
language-guess, the **URL canonicalizer**, the **entity extractor**, the **abuse
filter**, the **robots.txt parser** and the **sitemap parser**. The `net` tier is now
**functionally complete**: the token-bucket rate limiter, the SOCKS5 client, a
hand-rolled gzip/deflate/zlib **INFLATE** in crawlcore, the async **HTTP client**
(`perform_request` with full framing + decompression), the **I2P proxy** helpers,
and the **fetcher** — where taking an `&OnionHost` makes the anti-leak invariant a
*compile* guarantee — have landed, verified by loopback round-trips of the SOCKS
handshake and the full fetch (redirects, gzip, clearnet-refusal). (`https` awaits
an opt-in `tls` feature, since stdlib has no TLS.) The **storage layer** has now
landed too: a hand-rolled, dependency-free port of the SQLite/FTS5 Python
`Storage` — the resumable **leased frontier** with its trap-cap admission control
(identical reason codes), the host **state machine** + dead-onion liveness aging,
the **page store** with exact-content dedup, the **entity verticals**, the
inter-onion **link graph** with offline **PageRank**, **SimHash** mirror
clustering, and a versioned **snapshot/restore** blob (native `i64`/`f64`, no
database) — with `simhash64` (a hand-rolled RFC 7693 **BLAKE2b** token hash, now
in crawlcore) as the fuzzy fingerprint. Its PageRank, clustering and `simhash64`
are cross-checked against the Python `Storage`, and a snapshot round-trip is
covered. The **FTS search** over the stored bodies has landed too — a
dependency-free inverted-index **BM25** (title-10×/body-1× weighting, implicit-AND
+ quoted phrases, host/lang/date filters, host+language facets, near-duplicate
collapse, optional authority blend, hidden-host exclusion), the behaviourally
faithful stdlib replacement for the SQLite/FTS5 `bm25` path (unit-tested; not
bit-identical, since the stdlib has no FTS5). The no-JS HTTP **serving layer** has
landed too — pure renderers + a pure `route()` over an `Arc<Mutex<Store>>` (HTML
search UI, `/api/search`, entity `/find`, `/stats`, `/cached`, `/health`,
Prometheus `/metrics`, `/opensearch.xml`), with only the async accept loop behind
`net`, verified by pure route tests + a loopback round-trip. The **write
endpoints** have landed too — `POST /add` (submit onion seeds through the abuse
filter + `add_seed` trap caps), `/purge` and `/recrawl`, behind a `Bearer` admin
token with an optional capped public-submit path, over the ported **submit**
intake (canonicalize → darknet-only gate → abuse-check → trusted/untrusted
enqueue). The **HTML extractor** (`extract_html`: title + visible text with
block line-breaks, followable `<a href>` links honouring `rel=nofollow`, `<base
href>`, robots `<meta>`, charset decode — a dependency-free port of the stdlib
`html.parser` extractor, cross-checked to Python) has landed too. And the
capstone — the crawler **orchestration loop** (`Crawler`) — closes the pipeline:
lease → fetch (net tier) → `extract_html` → `store_page` + `add_link_edge` +
capped frontier expansion, enforcing every trap defence (darknet gate, abuse
host/content blocks, content-type allowlist, X-Robots/meta-robots, `content_hash`
dedup, path-traps + caps + deduped edges, in-body `.onion` discovery, liveness +
dead-onion aging, host trap-scoring, politeness) with optional concurrent workers
over the shared store — verified by **end-to-end loopback crawls** (single-host
drain, cross-host link edge, depth cap) + a `content_hash` cross-check. The
**core engine is functionally complete**: the crawl→index→serve pipeline runs
end-to-end and the Python `onioncrawler/` is ready to retire (kept as the golden
reference). The loop obeys **`robots.txt`** (fetched + cached per host, with a
capped `Crawl-delay`). Deferred refinements: conditional GET, media-hash
blocking, politeness jitter, the scheduled-reseed daemon, and the opt-in `tls`
feature for HTTPS/eepsites. Reuses crawlcore
(globmatch/traps/dedup/scheduler + the shared `hash`/`inflate`/`blake2b` modules)
directly.

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

## Review results — torrentds networking + enrichment (2026-08-10)

The metadata fetcher, the tracker stack (peer store + UDP + HTTP), the release
classifier, BEP-33 and the bencode strict/lenient refactor were adversarially
reviewed (three independent passes, incl. fuzzing). Cores confirmed sound: no
memory-unsafety, no verification bypass (`sha1(metadata)==info_hash` runs on the
raw bytes before any lenient decode), the DHT iterative lookup can't loop forever,
and the strict decoder never over-accepts. Fixes applied:

- **metadata/fetch** — enforce an overall deadline on `fetch_metadata` (not just
  per-read), so a peer trickling keep-alives can't pin the fetch open forever.
- **metadata/parse_info** — `total_size` sums with `saturating_add` (hostile
  `i64::MAX` file lengths no longer overflow-panic); `expected_piece_len` guards
  `total_pieces == 0`; `serve_one` uses `saturating_mul` on the piece index.
- **metadata/magnet** — `parse_magnet` fails closed on a recognised-but-malformed
  `xt` (matching Python) instead of silently dropping it.
- **classify** — word-boundary matching now honours *any* non-word char as a `\b`
  boundary (Unicode-aware), not just ASCII space, so tokens adjacent to `,`/`&`/
  `:`/`!` etc. are extracted exactly like Python's `\b`. 15 punctuation cases
  added to the corpus cross-check.
- **tracker_http** — the accept loop survives a transient `accept()` error
  instead of dying; the request-head read has a timeout (slowloris) + size cap.
- **tracker_udp / tracker_http** — scrape/announce integer fields saturate rather
  than wrap to negative; out-of-`i64` `left` clamps (stays a leecher) not defaults.
- **peerstore** — restored swarms get increasing recency so post-restore LRU
  eviction is deterministic. **bep33** — round-half-to-even to match Python's
  `round()` at the exact-`.5` (single-set-bit) case.

## Structure & professionalization (2026-08-10)

A structure pass hardened the crate for the long haul, before the index store and
the remaining engines land on top:

- **Feature tiers make the austere claim real.** `torrentds` was pulling `tokio`
  + `getrandom` unconditionally (13 crates) even for a pure-bencode consumer. It
  now layers into `default` (pure wire core, **zero** third-party deps), `rand`
  (+`getrandom` for the routing table / swarm store) and `net` (+`tokio` for the
  live node, trackers and metadata fetch). CI asserts `cargo tree
  --no-default-features` is empty, so the dependency-free core can't silently rot.
- **`metadata.rs` (1227 lines) split** into `metadata/{wire,info,magnet,fetch}` —
  the byte-exact, fuzzable framing/parsing (pure) is now cleanly separated from
  the async I/O (`fetch`, behind `net`).
- **Error hygiene**: `KrpcError` now implements `Display`/`Error`; the string
  error newtypes gained `message()` accessors; `#[must_use]` was added across the
  pure builders/hashers — notably on `verify_v2`, so a dropped verification result
  can't silently accept unverified data. The crate-root re-export surface was
  completed so `use torrentds::*` can name every type the public APIs hand back.
- **CI is now real, not just claimed** (`.github/workflows/rust.yml`): fmt +
  clippy `-D warnings` + `--all-features` test + an MSRV (1.80) job + a
  feature-powerset check + the zero-dep assertion + a best-effort fuzz smoke.
- **`cargo fuzz` harnesses added** (`fuzz/`) for bencode, KRPC, info-dict and
  magnet parsing; plus `deny.toml` (supply-chain policy), `SECURITY.md` (threat
  model) and `crates/torrentds/tests/README.md` (the cross-check methodology).

A second pass finished the deferred structural items — behavior-neutral, all wire
cross-checks stay byte-identical:

- **Modules grouped into subsystem directories** — `wire/` (bencode, krpc,
  infohash), `enrich/` (classify, bep33, spam), `dht/` (routing, node, transport),
  `tracker/` (peerstore, http, udp). Parents are private with a flat facade
  re-export, so every public path (`torrentds::bencode`, …) is unchanged.
- **`Dict` moved to its real home** (`bencode`, re-exported from `krpc`), removing
  four false module edges; the DHT's private served-peer store renamed
  `ServedPeers` to end the clash with the tracker `PeerStore`.
- **Positional tuples replaced by named structs** — `ScrapeCounts`,
  `GetPeersOutcome`, `SampleOutcome`. The scrape triple was a real footgun (the
  wire order differs from the count order); the wire encoders now read fields by
  name, so a reshuffle can't silently mislabel seeders/leechers.
- **Encapsulation + `Debug`**: `KBucket`/`RoutingTable` internals are private
  behind accessors (the k-bound can't be bypassed); every public handle type has a
  hand-written `Debug`, enforced by `#![warn(missing_debug_implementations)]`.
- **`InfoHash` type alias** distinguishes a torrent identity from a `NodeId` in
  intent (e.g. the harvest sink, the served store, BEP-51 samples) — deliberately
  an alias, not a newtype, so the byte-exact wire paths stay noise-free.
- **`regen_goldens.py`** re-derives the cross-check goldens from the Python
  reference, so the byte-identical guarantee is reproducible/CI-checkable.

## The CI bar (enforced on every crate)

`cargo fmt --check` · `cargo clippy --all-targets --all-features -- -D warnings` ·
`cargo test --workspace --all-features` · `cargo build --no-default-features`
(+ empty-dependency-tree assertion) · MSRV 1.80 · `cargo fuzz` smoke for parsers.
Wired in `.github/workflows/rust.yml`; all green today.
