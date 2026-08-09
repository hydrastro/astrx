# crawlcore (Go) — Phase 1 of the engine rewrite

This is the **Go port of the shared crawl library**, the first phase of the
agreed per-engine hybrid rewrite (Go for the crawlers/search/dashboard, Rust for
the torrentds DHT/tracker core). It is a self-contained module and does not touch
the running Python suite — the migration is strangler-style, one component at a
time, behind the existing JSON APIs, with the Python tests carried over as the
executable spec.

## Status

| Module      | Ported | Notes |
|-------------|:------:|-------|
| `globmatch` |   ✅   | ReDoS-safe robots path-glob; semantics + 121-combo regexp-oracle cross-check + ReDoS bound |
| `dedup`     |   ✅   | SimHash bit-math; verified **byte-identical to Python** on a fixed input |
| `scheduler` |   ✅   | recrawl arithmetic (is-due / next-due / backoff) |
| `traps`     |   ✅   | structural bot-trap predicates (depth / repeat / cycle / calendar-bomb) |
| `interfaces`|   ⏳   | becomes Go interfaces defined alongside the first ported engine |

All ported modules pass `go test`, `go vet`, and `gofmt` clean, with **zero
external dependencies** (stdlib only), matching the suite's ethos.

## Test / build

```
cd crawlcore && go test ./... && go vet ./... && gofmt -l .
```

## What comes next

`interfaces` (as Go interfaces), then the first full engine — likely `websearch`
or `onioncrawler` — behind the existing `/api/search`, so the PHP bridge and its
145 tests keep passing throughout. `torrentds`' DHT/tracker/bencode core is the
Rust half of the hybrid (see the bencode spike).

> This folder lives under `rewrite/` deliberately: it's an in-progress parallel
> codebase, kept out of the running suite so it can't disrupt it. Commit it or
> leave it — nothing depends on it yet.
