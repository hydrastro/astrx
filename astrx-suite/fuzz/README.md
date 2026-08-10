# torrentds fuzzing (`cargo-fuzz` / libFuzzer)

`torrentds`'s value proposition is parsing **hostile input from anonymous DHT
peers without ever panicking**. These harnesses fuzz every pure parser on that
attack surface. Each target drives one entry point with arbitrary bytes and
asserts the crate's contract: return `Result`, never panic, and (where it is a
real invariant) round-trip stably.

## Targets

| Target            | Entry point                                            | What it stresses |
|-------------------|--------------------------------------------------------|------------------|
| `bencode_decode`  | `bencode::{decode, decode_lenient, decode_prefix}`     | The core wire codec — every datagram/info-dict/tracker payload passes through it. Also checks the canonical round-trip `encode(decode(x)) == x`. |
| `krpc_parse`      | `krpc::parse_message` (+ the `encode_*` reply path)    | Hostile KRPC (BEP-5) datagrams → typed message or error; re-encode/parse stability. |
| `metadata_info`   | `metadata::decode_info_dict` → `metadata::parse_info`  | Hostile info-dicts: v1/v2/hybrid routing, the depth/node-bounded `file tree` walk, saturating length sums. |
| `magnet_parse`    | `metadata::parse_magnet`                               | `magnet:` URIs: hex/base32 btih, btmh multihash, percent/`+` decoding. |

## Prerequisites

`cargo-fuzz` builds with libFuzzer, which requires a **nightly** toolchain:

```sh
cargo install cargo-fuzz
rustup toolchain install nightly
```

The engine workspace pins **stable** (`../rust-toolchain.toml`), so always invoke
fuzzing with an explicit `+nightly` override (the fuzz crate is detached from
that workspace via its own `[workspace]` table, so the pin does not otherwise
interfere):

```sh
# from the repo root or this fuzz/ directory:
cargo +nightly fuzz list                     # -> bencode_decode, krpc_parse, metadata_info, magnet_parse
cargo +nightly fuzz run bencode_decode       # fuzz the bencode decoder
cargo +nightly fuzz run krpc_parse
cargo +nightly fuzz run metadata_info
cargo +nightly fuzz run magnet_parse
```

Useful flags (passed through to libFuzzer after `--`):

```sh
# bounded CI smoke run: stop after N executions or T seconds
cargo +nightly fuzz run bencode_decode -- -runs=1000000
cargo +nightly fuzz run bencode_decode -- -max_total_time=60

# reproduce / minimise a crash artifact
cargo +nightly fuzz run bencode_decode fuzz/artifacts/bencode_decode/crash-<hash>
cargo +nightly fuzz tmin bencode_decode fuzz/artifacts/bencode_decode/crash-<hash>

# coverage report
cargo +nightly fuzz coverage bencode_decode
```

## Corpus

libFuzzer keeps a per-target corpus under `corpus/<target>/` (git-ignored).
Seeding it makes campaigns converge far faster — most of these parsers gate on a
magic prefix or a specific structure, so random bytes alone waste cycles:

- `bencode_decode` / `krpc_parse` — seed with real captured KRPC datagrams and
  small bencoded values (`d1:ad2:id20:....e...e`, `l...e`, `i0e`, `0:`).
- `metadata_info` — seed with real `.torrent` **info-dict** bytes (v1, v2, and
  hybrid) so the v1/v2/hybrid and `file tree` branches are exercised early.
- `magnet_parse` — seed with real `magnet:?xt=urn:btih:...` and `urn:btmh:1220...`
  URIs; a dictionary of tokens (`magnet:?`, `xt=`, `urn:btih:`, `urn:btmh:`,
  `dn=`, `&`) via `-dict=` also helps.

The `magnet_parse` harness additionally re-feeds each input behind a `magnet:?`
prefix so the query/xt-decoding logic is reached even without a seed corpus.

## Honesty note

These files are the **harnesses**, not a fuzzing campaign. Committing them makes
the "every parser is fuzzed" claim reproducible: anyone can run the commands
above. The large-input figures cited in the design docs come from
**out-of-band** campaigns (long libFuzzer runs on dedicated hardware / OSS-Fuzz-
style continuous fuzzing); CI should run only a **bounded smoke** pass (e.g.
`-max_total_time=60` per target) to catch regressions without blocking merges.
Treat any headline "N inputs, zero panics" number as valid only for a campaign
that was actually run and recorded — not as a property these harness files
assert on their own.
