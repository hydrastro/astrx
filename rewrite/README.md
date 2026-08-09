# astrx-suite — Rust rewrite

The full-Rust rewrite of the suite engines: **one language for everything**.
A Cargo workspace; each engine becomes a crate; `crawlcore` is the shared library.
Zero third-party dependencies by default (stdlib only), matching the suite's
austere, auditable ethos. `#![forbid(unsafe_code)]` across the tree.

## Why Rust (recap)

For security-critical services that parse hostile input (bencode/KRPC from
anonymous DHT peers, untrusted HTML/HTTP) behind Tor, Rust gives memory safety
with no GC and lets the crown-jewel invariants become **types** — e.g. a
darknet-only `OnionHost` the fetcher requires, so a clearnet leak is a *compile*
error. Parsers get fuzzed with `cargo fuzz`.

## Migration approach

Strangler pattern: port one component at a time, keep the Python tests as the
executable spec, and stand each new engine up behind the **identical JSON/loopback
API** so the AstrX PHP bridge (and its 145 tests) never change. The suite keeps
running mixed-language until the last engine is swapped.

## Status

| Crate        | Status | Notes |
|--------------|:------:|-------|
| `crawlcore`  |  ✅ done | globmatch, dedup, scheduler, traps — 13 tests, clippy `-D warnings` clean, **SimHash byte-identical to Python** |
| `torrentds`  |  ⏳ next | bencode + infohash core already spiked and passing golden tests; DHT (BEP-5), tracker (BEP-3/15/23), metadata (BEP-9/10) |
| `onioncrawler` | ⏳ | SOCKS5 + darknet gate (as a type), resumable frontier, no-JS search |
| `websearch`  |  ⏳ | crawler + FTS index + BM25/PageRank ranking, verticals |
| `gitweb`     |  ⏳ | read-only git viewer |
| `suitedash`  |  ⏳ | no-JS ops dashboard |

## Layout

```
rewrite/
├── Cargo.toml            workspace (release: LTO + strip)
└── crates/
    └── crawlcore/        shared crawl library (done)
        └── src/{lib,globmatch,dedup,scheduler,traps}.rs
```

## Build / test

```
cd rewrite
cargo test                       # all crates
cargo clippy --all-targets -- -D warnings
cargo fmt --check
```

## CI bar (applies to every crate)

`cargo fmt --check` + `cargo clippy -- -D warnings` + `cargo test` + (for parsers)
`cargo fuzz` smoke — the same discipline you asked for, enforced from day one.
