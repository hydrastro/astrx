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
│   └── onioncrawler/     darknet (.onion/.i2p) crawler — gate, canon, entities 🚧
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
| `crawlcore`    | ✅ done | globmatch, dedup, scheduler, traps, **hashing (SHA-1/SHA-256/MD5)**, **INFLATE (DEFLATE/gzip/zlib decompressor)** — 22 tests; SimHash + hashes + inflate byte-identical to Python |
| `torrentds`    | ✅ parity | bencode + SHA-1/SHA-256 infohash + KRPC (BEP-5) + live DHT node + metadata fetch (BEP-9/10, incl. BEP-52 v2/hybrid) + HTTP/UDP trackers (BEP-3/15/23) on a shared swarm store + BEP-33 scrape + release classifier + spam heuristics + **dependency-free index store** (records, dedup, FTS + BM25 search, bencode snapshot) + **no-JS search UI + JSON API + RSS + Torznab** + **indexer** (harvest → queue → concurrent fetch pool → store, BEP-51 sampler, warm-restart node persistence) — **100 tests**; wire formats, infohashes, classifier, BEP-33, spam, store + serving helpers all cross-checked byte-identical to Python + loopback round-trips of the HTTP server and the harvest→store→fetch path. Python `torrentds/` is ready to retire (kept as the golden reference). |
| `onioncrawler` | 🚧 | **darknet host gate as a type** — `OnionHost` / `I2pHost` / `DarknetHost` are constructible only through a validating parser, so the (forthcoming) fetcher taking an `&OnionHost` makes a clearnet/localhost/IP leak a *compile* error, not a runtime check — plus the in-text `.onion` discovery scanner (v3/v2, look-behind, port clamp), the stdlib language-guess, a full **URL canonicalizer** (a dependency-free port of the `urllib.parse`/`posixpath` surface: `urljoin`, percent quote/unquote, `normpath`, query clean/sort, template + skeleton trap keys) the **entity extractor** (PGP-armor SHA-1 fingerprint + btc/xmr/eth address recognition), the **abuse filter** (host/keyword/media blocklists + Ahmia `md5(domain)` bans, on the every-page hot path) the **robots.txt parser** (User-agent groups, Allow/Disallow with `$` anchor over the ReDoS-safe glob, Crawl-delay, Sitemap) and the **sitemap parser** (a hand-rolled, XXE/bomb-safe XML parser reproducing ElementTree's entity decoding + `el.text` + parse-error behaviour). Pure core complete; **`net` tier underway**: a token-bucket **rate limiter**, the **SOCKS5 client** (RFC-1928/1929 — pure encoders + async connect with remote name resolution, loopback-tested), and the **HTTP wire helpers** (request build, status/header parse, chunked decode, **gzip/deflate decompression** via crawlcore's INFLATE, with a decompression-bomb output cap). **60 tests**, all pure modules + the SOCKS/HTTP/ratelimit helpers cross-checked byte-identical to Python (+ a mock-SOCKS loopback round-trip); zero third-party deps by default (net tier behind `net`/`rand` → tokio + getrandom only). Next: the async `perform_request` tying the reader + framing + decompression together, then the fetcher (the `&OnionHost`-gated socket) + no-JS search |
| `websearch`    | ⏳ | crawler + FTS + BM25/PageRank ranking + verticals |
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
