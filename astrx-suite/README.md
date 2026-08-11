# astrx-suite

The companion service suite for [AstrX](https://github.com/hydrastro/astrx),
being rewritten in **Rust** — one language for the whole suite. A Cargo
workspace; each engine is a crate; `crawlcore` is the shared library. Zero
third-party dependencies by default (stdlib only), matching AstrX's austere,
auditable ethos, and `#![forbid(unsafe_code)]` across the tree.

"Zero deps by default" is literal and machine-checked: `crawlcore` is
stdlib-only, and `torrentds` builds its entire pure wire core with **no**
third-party crates under its default (empty) feature set. Live networking is
opt-in behind the `net`/`rand` features, which pull in the only two vetted
dependencies — `tokio` and `getrandom`. CI asserts `cargo tree
--no-default-features` over `torrentds` is empty, so the claim can't silently rot.

The engines are standalone, loopback-bound services the CMS talks to over a
small JSON API, and they're happy behind Tor. Nothing here needs JavaScript.

## Layout

```
astrx-suite/
├── Cargo.toml            workspace (release: LTO + strip + 1 codegen unit)
├── rust-toolchain.toml   pinned stable + rustfmt + clippy
├── rustfmt.toml
├── deny.toml             cargo-deny: advisories + tiny-dependency policy
├── SECURITY.md           threat model + hardening notes
├── crates/
│   ├── crawlcore/        shared crawl library  ✅
│   ├── torrentds/        DHT indexer + tracker (node, trackers, metadata) ✅
│   ├── onioncrawler/     darknet (.onion/.i2p) crawler — engine complete ✅
│   └── websearch/        clearnet search engine — SSRF gate as a type 🚧
├── fuzz/                 cargo-fuzz harnesses for the wire parsers
└── legacy-python/        the Python engines being retired, one at a time
```

`torrentds` is organised in feature tiers so the auditable core stays dep-free:
the pure wire modules (`bencode`, `krpc`, `infohash`, `bep33`, `classify`, and
all of `metadata`'s parsing) always compile; `rand` adds the CSPRNG-backed
routing table + swarm store; `net` adds the async DHT node, the HTTP/UDP
trackers and the metadata fetch client.

`legacy-python/` holds the current, working Python suite (it still builds and
deploys via `legacy-python/docker-compose.yml`). Each engine is deleted from
there as its Rust replacement reaches parity — when the folder is empty, the
migration is done.

## Why Rust

These are security-critical services that parse hostile input (bencode/KRPC from
anonymous DHT peers, untrusted HTML/HTTP) behind Tor. Rust gives memory safety
with no GC and lets the crown-jewel invariants become **types** — e.g. the
darknet-only gate is now an `OnionHost` / `DarknetHost` newtype the fetcher will
require, so a clearnet leak becomes a *compile* error, and the infohash a
verified 20/32-byte newtype.
The hostile-input parsers (bencode, KRPC, info-dict, magnet) have `cargo fuzz`
harnesses under `fuzz/`.

## Migration approach (strangler)

Port one component at a time; keep the Python tests as the executable spec; stand
each new engine up behind the **identical JSON/loopback API** so the AstrX PHP
bridge (and its 145 tests) never change. The suite runs mixed-language until the
last engine is swapped, and the CMS never notices.

## Status

| Crate          | Status | What's done / next |
|----------------|:------:|--------------------|
| `crawlcore`    | ✅ done | globmatch, dedup, scheduler, traps, **hashing (SHA-1/SHA-256/MD5 + BLAKE2b)**, **INFLATE (DEFLATE/gzip/zlib decompressor)**, **`urlparse`** (shared `urllib.parse`/`posixpath` subset: quote/unquote, parse_qsl/urlencode, urlsplit/urljoin/normpath) — 32 tests; SimHash + hashes (incl. RFC 7693 BLAKE2b) + inflate + urlparse byte-identical to Python |
| `torrentds`    | ✅ parity | bencode + SHA-1/SHA-256 infohash + KRPC (BEP-5) + live DHT node + metadata fetch (BEP-9/10, incl. BEP-52 v2/hybrid) + HTTP/UDP trackers (BEP-3/15/23) on a shared swarm store + BEP-33 scrape + release classifier + spam heuristics + **dependency-free index store** (records, dedup, FTS + BM25 search, bencode snapshot) + **no-JS search UI + JSON API + RSS + Torznab** + **indexer** (harvest → queue → concurrent fetch pool → store, BEP-51 sampler, warm-restart node persistence) — **100 tests**; wire formats, infohashes, classifier, BEP-33, spam, store + serving helpers all cross-checked byte-identical to Python + loopback round-trips of the HTTP server and the harvest→store→fetch path. Python `torrentds/` is ready to retire (kept as the golden reference). |
| `onioncrawler` | ✅ engine complete | **darknet host gate as a type** — `OnionHost` / `I2pHost` / `DarknetHost` are constructible only through a validating parser, so the fetcher taking an `&OnionHost` makes a clearnet/localhost/IP leak a *compile* error, not a runtime check — plus the in-text `.onion` discovery scanner (v3/v2, look-behind, port clamp), the stdlib language-guess, a full **URL canonicalizer** (a dependency-free port of the `urllib.parse`/`posixpath` surface: `urljoin`, percent quote/unquote, `normpath`, query clean/sort, template + skeleton trap keys) the **entity extractor** (PGP-armor SHA-1 fingerprint + btc/xmr/eth address recognition), the **abuse filter** (host/keyword/media blocklists + Ahmia `md5(domain)` bans, on the every-page hot path) the **robots.txt parser** (User-agent groups, Allow/Disallow with `$` anchor over the ReDoS-safe glob, Crawl-delay, Sitemap) and the **sitemap parser** (a hand-rolled, XXE/bomb-safe XML parser reproducing ElementTree's entity decoding + `el.text` + parse-error behaviour). Pure core complete; **`net` tier functionally complete**: a token-bucket **rate limiter**, the **SOCKS5 client** (RFC-1928/1929, remote name resolution + per-host stream isolation), the **HTTP client** (async `perform_request`: content-length/chunked/close framing, keep-alive reuse, **gzip/deflate decompression** via crawlcore's INFLATE with a bomb cap), the **I2P proxy helpers**, and the **fetcher** — the `&OnionHost`-gated socket orchestration with a redirect loop and a testing `DirectFetcher`. Net tier complete; **storage layer complete**: a hand-rolled **dependency-free store** (the resumable, leased **frontier** with its trap-cap admission control + the same reason codes as Python, the host **state machine** + politeness + robots, the **page store** with exact-content dedup, the **entity verticals**, the inter-onion **link graph** with offline **PageRank**, **SimHash** mirror clustering, dead-onion **liveness aging**, and a versioned **snapshot/restore** blob — no database), plus the **`simhash64`** fuzzy fingerprint (BLAKE2b token hash), and a dependency-free **FTS search** over the stored bodies — a hand-rolled inverted-index **BM25** (title-10×/body-1× field weighting, implicit-AND + quoted phrases, host/lang/date filters, host+language facets, near-duplicate collapse, optional authority blend, hidden-host exclusion), the behaviourally-faithful stdlib replacement for the Python SQLite/FTS5 `bm25` path, and the **no-JS HTTP serving layer** — pure renderers + a pure `route()` over an `Arc<Mutex<Store>>` (search UI, `/api/search`, entity `/find` + `/api/find`, `/stats` + `/api/stats`, `/cached`, `/health`, Prometheus `/metrics`, `/robots.txt`, `/opensearch.xml`), with only the async accept loop behind `net` — now including the **write endpoints** (`POST /add` submit through the abuse filter + `add_seed` trap caps, `/purge`, `/recrawl`, behind a `Bearer` admin token with an optional public-submit path) over the ported **submit** intake (canonicalize → darknet-only gate → abuse-check → trusted/untrusted enqueue), and the **HTML extractor** (`extract_html`: `<title>` + visible text with block-element line breaks, followable `<a href>` links with `rel=nofollow` handling, `<base href>`, robots `<meta>` directives, charset decode) — a dependency-free port of the stdlib `html.parser` extractor, and — closing the pipeline — the **crawl orchestration loop** (`Crawler`): lease → fetch (net) → extract → index → frontier expansion, enforcing every trap defence (canonicalize + darknet gate, abuse host/content blocks, content-type allowlist, X-Robots-Tag + meta-robots, `content_hash` dedup, path-traps + admission caps + deduped link edges, in-body `.onion` discovery, liveness + dead-onion aging, host trap-scoring, politeness) with optional concurrent workers over the shared store, obeying **`robots.txt`** (fetched + cached per host, with capped `Crawl-delay`). **137 tests**, all pure + SOCKS/HTTP/ratelimit/I2P helpers + `simhash64` + the store's PageRank & clustering + the HTML extractor + `content_hash` cross-checked to Python **plus loopback round-trips** of the SOCKS handshake, the full fetch pipeline (redirects, gzip, clearnet-refusal), the HTTP server, a store snapshot round-trip, the BM25 search, the submit/admin endpoints, and **end-to-end crawls** (single-host drain, cross-host link edge, depth cap, robots-disallow) over a mock server; zero third-party deps by default (net tier behind `net`/`rand` → tokio + getrandom only). The core engine is **functionally complete** — the crawl→index→serve pipeline runs end-to-end; deferred refinements: conditional GET, media-hash blocking, politeness jitter, the scheduled-reseed daemon, and the opt-in `tls` feature for HTTPS/eepsites. Python `onioncrawler/` is ready to retire (kept as the golden reference) |
| `websearch`    | 🚧 | **SSRF gate as a type** — `SafeIp` wraps an `IpAddr` only after `ip_is_internal` clears it (loopback/private/link-local/reserved/multicast/unspecified, IPv4-mapped unwrapped, fail-closed), so the net-tier connect taking a `&SafeIp` makes an SSRF to an internal address a *compile* error; cross-checked byte-identical to Python over a 60+-case IPv4/IPv6 special-range corpus. Plus **`dedup`** (FNV-1a word-bigram-shingled 64-bit SimHash), the **URL canonicalizer** (`canonicalize`/`host_of`/`authority_of`/`is_http_url`/`in_scope`: lower-case scheme+host, default-port drop, userinfo/fragment strip, RFC-3986 dot-segments, multi-slash collapse, query sort, IPv6 brackets) on the now-shared `crawlcore::urlparse`, and the **robots.txt parser** (UA-group precedence, Allow/Disallow with `*`/`$`, longest-match + Allow tie-break, Crawl-delay, over crawlcore's ReDoS-safe glob), and now the **pure HTTP wire core** where the gate reaches the socket — `vet_addrs` turns a host's resolved addresses into `SafeIp`s or refuses the whole host (any-internal → blocked, first offender named; DNS-rebind-safe pinning), the `allow_hosts` authority exemption (the sole, audit-visible way an internal address becomes connectable), `Content-Type`/`Content-Encoding` handling (gzip/deflate/zlib over crawlcore's INFLATE, bomb-capped), charset body decode, and the HTTP/1.1 request/response/chunked helpers — all cross-checked byte-identical to Python (`vet`, content-type, decompress, decode). Plus the **`net` tier** — the async SSRF-checked fetch: a TTL DNS cache, `resolve_checked` (resolve → `vet_addrs` → `Vec<SafeIp>`), `connect_pinned` (dials **only** a `&SafeIp`, so the compiler forbids a connect to an unvetted address), `perform_request` (content-length/chunked/close framing + gzip/deflate), and the redirect-following `fetch` that re-runs the SSRF gate on **every hop** — verified by loopback round-trips incl. that a loopback address is refused by default and reachable only through the explicit `allow_hosts` exemption. Plus **`htmlparse`** (stage 1 — core extraction): a dependency-free `html.parser` port pulling `<title>`, meta `description`, visible body text (script/style/noscript/template/svg/math excluded, nav/header/footer/aside/form boilerplate text dropped but its links kept), every `<a href>` in document order, `<link rel=canonical>`, `<base href>`, `meta robots`, and a stop-word language guess — cross-checked byte-identical to Python on realistic pages (the image/video verticals + JSON-LD/SPA recovery are stage 2). **45 tests**, zero third-party deps by default (net → tokio + getrandom only). Next: htmlparse verticals + keep-alive `Fetcher` pool + resumable crawler + FTS index + BM25/PageRank ranking + no-JS UI + federation |
| `gitweb`       | ⏳ | read-only git viewer |
| `suitedash`    | ⏳ | no-JS ops dashboard |

## Build / test

```
cargo test --workspace --all-features                       # full suite (net + rand)
cargo clippy --workspace --all-targets --all-features -- -D warnings
cargo fmt --check

# austere-core guarantees:
cargo build -p torrentds --no-default-features               # pure wire core, zero deps
cargo tree -p torrentds --no-default-features                # → just `torrentds`
```

Everything above is green today. The feature matrix (`--no-default-features`,
`--features rand`, `--features net`, `--all-features`) all build clean, and the
zero-dependency default is asserted in CI. See `ROADMAP.md` for the full timeline.
