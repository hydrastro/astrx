# astrx-suite

The companion service suite for [AstrX](https://github.com/hydrastro/astrx),
being rewritten in **Rust** — one language for the whole suite. A Cargo
workspace; each engine is a crate; `crawlcore` is the shared library. Zero
third-party dependencies by default (stdlib only), matching AstrX's austere,
auditable ethos, and `#![forbid(unsafe_code)]` across the tree.

The engines are standalone, loopback-bound services the CMS talks to over a
small JSON API, and they're happy behind Tor. Nothing here needs JavaScript.

## Layout

```
astrx-suite/
├── Cargo.toml            workspace (release: LTO + strip + 1 codegen unit)
├── rust-toolchain.toml   pinned stable + rustfmt + clippy
├── rustfmt.toml
├── crates/
│   ├── crawlcore/        shared crawl library  ✅
│   └── torrentds/        DHT indexer + tracker (wire core done) 🚧
└── legacy-python/        the Python engines being retired, one at a time
```

`legacy-python/` holds the current, working Python suite (it still builds and
deploys via `legacy-python/docker-compose.yml`). Each engine is deleted from
there as its Rust replacement reaches parity — when the folder is empty, the
migration is done.

## Why Rust

These are security-critical services that parse hostile input (bencode/KRPC from
anonymous DHT peers, untrusted HTML/HTTP) behind Tor. Rust gives memory safety
with no GC and lets the crown-jewel invariants become **types** — e.g. the
darknet-only gate will be an `OnionHost` the fetcher requires, so a clearnet leak
becomes a *compile* error, and the infohash a verified 20/32-byte newtype.
Parsers are fuzzed (`cargo fuzz`).

## Migration approach (strangler)

Port one component at a time; keep the Python tests as the executable spec; stand
each new engine up behind the **identical JSON/loopback API** so the AstrX PHP
bridge (and its 145 tests) never change. The suite runs mixed-language until the
last engine is swapped, and the CMS never notices.

## Status

| Crate          | Status | What's done / next |
|----------------|:------:|--------------------|
| `crawlcore`    | ✅ done | globmatch, dedup, scheduler, traps — 13 tests; SimHash byte-identical to Python |
| `torrentds`    | 🚧 wire core | bencode codec + byte-exact infohash (SHA-1) — 5 tests, golden-matched. Next: DHT (BEP-5), trackers (BEP-3/15/23), metadata (BEP-9/10) |
| `onioncrawler` | ⏳ | SOCKS5 + darknet gate as a type, resumable frontier, no-JS search |
| `websearch`    | ⏳ | crawler + FTS + BM25/PageRank ranking + verticals |
| `gitweb`       | ⏳ | read-only git viewer |
| `suitedash`    | ⏳ | no-JS ops dashboard |

## Build / test

```
cargo test --workspace
cargo clippy --workspace --all-targets -- -D warnings
cargo fmt --check
```

Everything above is green today. See `ROADMAP.md` for the full timeline.
