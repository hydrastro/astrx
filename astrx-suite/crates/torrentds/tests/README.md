# torrentds integration tests — the `xcheck_*` cross-checks

This directory holds the crate's **cross-check** suite: the `xcheck_*.rs` files.
They are the executable proof that the Rust rewrite is *byte-identical* on the
wire to the Python reference it replaces.

## What a cross-check is

`torrentds` is a from-scratch Rust port of the Python engine still living under
`legacy-python/torrentds/`. For every module that touches the wire, we don't
just assert "the Rust output looks reasonable" — we assert it is **the exact same
bytes** the Python produces for the same input. That is what lets the Rust engine
drop in behind the identical protocol surface (real BitTorrent/DHT peers, and the
CMS's JSON/loopback API) without anything downstream noticing the swap.

Each cross-check encodes the reference output as a **golden**: a hex (or, for
`parse_info`, a parsed-field) literal baked into the test. Those goldens were
produced by *driving the Python reference directly* — running the corresponding
`legacy-python/torrentds/` function over the same inputs and emitting its output
as hex. The Rust test then re-derives the value and asserts equality. A
divergence between the two stacks is therefore a failing test, not a silent wire
incompatibility.

Where the two stacks deliberately differ, the golden captures the *reference's*
behaviour on purpose — e.g. `xcheck_classify` pins Python's quirks (`DDP5.1`
misses the audio codec because `.` splits the token; `HDR10+` folds to `hdr10`
because `+` is a separator) so the Rust classifier reproduces them exactly rather
than "fixing" them and drifting.

## Which file covers which module

| Test file                 | Module(s) under test                                   | What is pinned                                                        |
|---------------------------|--------------------------------------------------------|----------------------------------------------------------------------|
| `xcheck_classify.rs`      | `classify`                                              | `tag_string` over a real-world release-name corpus (incl. quirks)    |
| `xcheck_metadata.rs`      | `metadata` (builders + `parse_info`)                   | BEP-3/9/10 handshake / `ut_metadata` wire bytes and parsed info-dict |
| `xcheck_dht.rs`           | `krpc` + `routing` (`encode_query`/`encode_response`, `encode_nodes`/`Node`) | the DHT node's on-wire KRPC datagrams          |
| `xcheck_peerstore.rs`     | `peerstore`                                             | restore of a Python-emitted bencode swarm snapshot (interop)         |
| `xcheck_tracker_http.rs`  | `tracker_http`                                          | BEP-3/23 announce / scrape / failure response bytes                  |
| `xcheck_tracker_udp.rs`   | `tracker_udp`                                           | BEP-15 connect / announce / scrape / error `struct.pack` layouts     |
| `xcheck_search.rs`        | `search` (+ `build_torrent_file`)                       | `human_size`, `rfc2822`, Torznab caps, and whole rendered search / browse **pages** (`goldens/search.rs`) |

## Feature-gating — some cross-checks only run under `--all-features`

The crate is layered (`default = []` pure core, `rand`, `net`), and each test is
gated to the tier of the module it exercises via a crate-level
`#![cfg(feature = "…")]`:

- **Pure core (no gate)** — `xcheck_classify.rs`, `xcheck_metadata.rs` run on the
  default, dependency-free build.
- **`rand`** — `xcheck_dht.rs`, `xcheck_peerstore.rs` (the routing table and swarm
  peer store live behind `rand`).
- **`net`** — `xcheck_tracker_http.rs`, `xcheck_tracker_udp.rs`, `xcheck_search.rs`
  (the tracker servers and the search server live behind `net`).

A gated test file compiles to an empty crate when its feature is off, so:

```sh
cargo test -p torrentds                 # runs only the two pure-core cross-checks
cargo test -p torrentds --all-features  # runs ALL of them  ← the CI invocation
```

Run with `--all-features` (as CI does) to exercise the whole matrix; a bare
`cargo test` silently skips the `rand`/`net` cross-checks because their code
isn't compiled in.

## Golden regeneration

`regen_goldens.py` (in this directory) re-derives the goldens by driving the
Python reference directly, so their provenance is reproducible and reviewable
rather than resting on hand-copied constants:

```
python3 crates/torrentds/tests/regen_goldens.py
```

It prints `LABEL = <value>` lines grouped by cross-check; compare them against the
literals embedded in the `xcheck_*.rs` files (and the `spam`/`store` corpora). Any
drift between the Rust port and the Python reference shows up as a diff, so a CI
job can run it and fail on mismatch, and regenerating after an intentional
reference change is one command rather than hand-editing hex.

It currently covers the modules the Python exposes as standalone functions
(`spam`, `store`'s `categorize`/`content_signature`/`magnet_link`, `classify`).
The tracker / DHT / metadata / infohash goldens are outputs of wire *builders*
that live inside request-handler classes in the Python reference; they are
regenerated via their own harnesses today and can be lifted into the script as
those entrypoints are made callable. Treat any golden not yet covered by the
script as a hand-verified fixture and update it deliberately.

### The rendered-page corpus (`goldens/search.rs`)

`search.render_results` / `render_browse` *are* callable standalone, so the whole
served HTML document is pinned rather than a handful of helpers.
`regen_search_goldens.py` drives the real Python over a fixed fixture set —
including a hostile one whose `?q=`, torrent name, facet tags, magnet and
category all embed `<script>`, quotes and `&` — and writes the corpus as a Rust
fragment that `xcheck_search.rs` `include!`s:

```sh
PYTHONPATH=legacy-python/torrentds \
    python3 crates/torrentds/tests/regen_search_goldens.py \
    > crates/torrentds/tests/goldens/search.rs
```

The constant inline stylesheet is elided from each page as
`<style>@CSS@</style>` and pinned once on its own as `PY_CSS` (compared against
`search::PAGE_CSS`), which keeps the literals readable while still failing on a
one-byte CSS drift. Regenerating is a diff, not a hand edit; `goldens/` is not a
Cargo test target (only top-level `tests/*.rs` are), so the fragment is compiled
solely through that `include!`.
