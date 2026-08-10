# Security policy — astrx-suite (Rust)

The astrx-suite engines are security-critical: they parse hostile input off the
open internet. `torrentds`, the engine furthest along, speaks BitTorrent to
**anonymous DHT peers** — it decodes bencode/KRPC datagrams and peer-wire frames
that an attacker controls byte-for-byte. This document states the threat model,
the defences that are actually in place, and how to report a vulnerability.

## Scope

This policy covers the Rust workspace under `astrx-suite/` (the `crawlcore` and
`torrentds` crates today; the remaining engines as they land). The retired
Python engines under `legacy-python/` are kept only as the executable reference
spec during migration and are out of scope.

## Threat model

The adversary is a remote, unauthenticated peer. Concretely, `torrentds`
ingests, from parties it has never met:

- **bencode** values — arbitrarily nested, with attacker-chosen lengths and
  integers — arriving as KRPC (BEP-5) DHT datagrams.
- **KRPC messages** — queries/responses whose dict keys, node lists and token
  blobs are all attacker-shaped.
- **peer-wire + extension frames** — the BEP-3/10/9 handshake and `ut_metadata`
  pieces streamed by a peer that may lie about sizes, send garbage, or trickle
  bytes to stall the connection.
- **tracker requests** — HTTP (BEP-3/23) and UDP (BEP-15) announce/scrape
  payloads, including binary query strings and forgeable integer counters.

The assumed capabilities: send any bytes to any of these surfaces; spoof source
addresses on UDP; and try to exhaust memory/CPU, inject off-path responses, or
smuggle unverified data downstream as if it were real torrent metadata.

## Defences in place

These are properties the code actually holds today (see `ROADMAP.md` for the
per-module review record), not aspirations:

- **No `unsafe`.** Every crate carries `#![forbid(unsafe_code)]`, so the whole
  parsing surface is memory-safe by construction — the compiler rejects a raw
  pointer deref or unchecked transmute outright.
- **A strict decoder that returns `Result`, never panics.** The bencode
  decoder reports malformed input as an error instead of unwinding; it has been
  fuzzed extensively (on the order of a million-plus inputs) with zero panics.
  The lenient variant is used only *after* verification (see below), never as a
  trust boundary.
- **Bounded recursion and allocation.** Nested-container depth is capped so a
  deeply nested value can't blow the stack, and the BEP-52 `file tree` walk is
  both depth- and node-bounded, so a hostile info-dict can't fan out into
  unbounded work or allocation.
- **Saturating arithmetic on attacker-controlled lengths.** Sizes and counters
  derived from peer input use saturating (or otherwise checked) arithmetic —
  e.g. a torrent's `total_size` sums with `saturating_add`, piece indexing uses
  `saturating_mul`, and tracker announce/scrape integer fields saturate rather
  than wrap to negative — so an `i64::MAX` length can't overflow-panic (debug)
  or wrap (release) into a small allocation.
- **Verify-before-use on metadata.** Fetched metadata is authenticated against
  the infohash *before* it is parsed or trusted: `sha1(metadata) == info_hash`
  for v1, and a SHA-256 check (truncated or full) for BEP-52 v2/hybrid. The
  hash is computed over the raw received bytes, so a peer cannot substitute
  content for a hash it does not match. `parse_magnet` fails closed on a
  recognised-but-malformed `xt` rather than silently accepting it.
- **Source-address anti-spoofing on the transport.** The async UDP transport
  matches responses to outstanding transactions and rejects off-path injected
  replies, and the trackers derive announced peers from the packet's real source
  address rather than trusting a claimed address in the payload. The DHT
  `announce_peer` path is additionally gated by a per-address token.
- **Time and slow-loris bounds on live I/O.** `fetch_metadata` enforces an
  overall deadline (not just a per-read timeout), so a peer dribbling
  keep-alives can't pin a fetch open indefinitely; the HTTP tracker's
  request-head read is timeout- and size-capped and its accept loop survives a
  transient error instead of dying.

## Dependency-minimization stance

Attack surface is dependency surface. The suite is built to keep both tiny:

- **Zero third-party dependencies by default.** `crawlcore` is stdlib-only, and
  `torrentds`' default (feature-less) build is the pure, independently-auditable
  wire core with no third-party crates. CI asserts this stays true
  (`cargo tree --no-default-features` must show nothing but `torrentds` itself).
- **Two vetted deps, opt-in only.** Live networking adds exactly two crates,
  both behind features: `getrandom` (a CSPRNG, under `rand`) and `tokio` (the
  async runtime, under `net`, which implies `rand`). Their small transitive
  closure is governed by `deny.toml` (advisories + a permissive-only licence
  allowlist + version/wildcard bans).
- **MSRV 1.80**, pinned in `[workspace.package]` and exercised in CI, so builds
  are reproducible against a known-good compiler floor.

## Reporting a vulnerability

> **Placeholder — maintainers to complete before publication.**

Please report suspected vulnerabilities **privately**; do not open a public
issue for anything exploitable.

- **Preferred:** GitHub private vulnerability reporting (repository
  *Security → Report a vulnerability*), which opens a confidential advisory.
- **Email:** `<security contact to be added>` (ideally with a PGP key fingerprint
  published here).

Please include enough to reproduce: affected crate/feature, the input or steps,
and the impact you observed. We aim to acknowledge within **`<N>` business days**
and to coordinate a fix and disclosure timeline with you; we're happy to credit
reporters unless you'd rather stay anonymous. As the suite is pre-1.0 there is
not yet a formal supported-versions matrix — until then, fixes land on the
default branch.
