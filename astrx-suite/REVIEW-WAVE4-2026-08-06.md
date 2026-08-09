# astrx-suite — Wave 4: features, gap-closers, review, and verification

Date: 2026-08-06. Scope: everything in the ranked feature list plus every "lacks
vs the originals" gap that could be closed without breaking the two ground rules
(zero third-party dependencies; no JavaScript). Two items were deliberately left
out and are called out at the end. Everything below was built, adversarially
reviewed, fixed, and then re-verified from a cold checkout.

---

## 1. What shipped this wave

### gitweb (110 → 117 tests)
Code and commit-message search (`git grep -n -I --fixed-strings` and
`git log --grep`, both literal — no ReDoS, hard caps on match count, output size
and wall-clock), a rendered commit graph (parent-lane drawing over
`git log --parents`), patch export (`format-patch -1 --stdout`, capped), an
OpenSearch descriptor (per-repo and site-level), and optional HTTP Basic auth
that gates every route (default off; constant-time username+digest compare).
Gap-closers: **LFS content serving** (a confined filesystem read of the local
object store — it never contacts a remote), and a **fuller Markdown** renderer
(reference-style links, angle autolinks, nested lists and blockquotes, setext
headings, hard breaks) that stays escape-first with a scheme allow-list.

### onioncrawler (173 → 182 tests)
A known-onions **seed importer + scheduled re-seed** (`seedlist.py`, validated
and blocklist-checked), **media-hash abuse filtering** (`hash_media` /
`media_bytes_blocked` against an operator hash list, shipping empty), an
OpenSearch descriptor, an online **backup** subcommand (`VACUUM INTO`), and a
token-gated **`POST /blocklist`** admin endpoint. Gap-closer: **I2P support** —
the anti-leak gate widened from "onion-only" to "**darknet-only**" (`.onion` OR
`.i2p`, nothing else), with a dedicated HTTP-proxy fetcher (`i2p.py`) that is
**off unless `--enable-i2p`** is passed.

### websearch (135 → 142 tests)
An OpenSearch descriptor, a **suggest/autocomplete** endpoint (prefix completion
plus a bounded edit-distance "did you mean"), **more-like-this** (SimHash
neighbours), and a **backup** subcommand. Gap-closer: an **image-search
vertical** that indexes `<img>` `src`/`alt`/`title`/context already present in
crawled HTML and renders a no-JS image results view — **no new fetch and no new
SSRF surface**: the images are loaded by the viewer's browser, never by the
server.

### torrentds (165 → 252 tests)
**BEP-52 v2 / hybrid torrents** (parse the v2 info dict and file tree, SHA-256
infohash with the 20-byte DHT truncation, `urn:btmh:1220…` magnets, byte-exact
verification), **tracker-scrape aggregation** (BEP-48 HTTP and BEP-15 UDP, folded
into swarm health), **browse-by-category + recently-added + per-query RSS**, a
token-gated **`POST /api/block`**, and a **backup** subcommand. Gap-closers:
**fake/spam-torrent heuristics** (`spam.py`, hidden by default, operator-tunable)
and **cross-infohash dedup** (a content signature that collapses the same content
across v1/v2/hybrid).

### suitedash (39 → 92 tests)
**Alerting** (threshold and up/down rules with per-service state and debounce),
a bounded **history ring buffer** with hand-emitted inline-**SVG sparklines**,
and an aggregate **`/metrics`** exporter that federates every service's
Prometheus text under one exposition relabelled by `service="…"`.

### astrx-integration (bridge tests 115 → 123)
A **unified federated search page** (one query, per-source `?source=` tabs across
internal AstrX content, clear-web, onion and torrent, zero-dep PHP over
config-only loopback endpoints), and a **blocklist editor** admin module
(`ADMIN_ACCESS` + CSRF, posting to onioncrawler `POST /blocklist` and torrentds
`POST /api/block` with server-side tokens).

---

## 2. The review

Six adversarial reviewers, one per module, each told to break the crown
invariants and prove every finding with a working PoC before reporting it. The
crown invariants all **held** under probing and again after the fixes:

- **Darknet-only anti-leak** — with a socket tripwire, the Tor fetcher only ever
  dials `127.0.0.1:9050` and the I2P fetcher only `127.0.0.1:4444`; clearnet,
  localhost, raw IPv4/IPv6, embedded-credential hosts, IDNA homographs, and
  onion↔i2p cross-network were all refused before any socket. I2P stays off by
  default.
- **websearch server-side SSRF denylist** — `httpclient.py` is byte-identical
  (unchanged), and the image vertical opens **no** socket (tripwire-proven); a
  hostile `<img src>` is either dropped or rendered fully escaped.
- **torrentds byte-exact verification** — v1 SHA-1 unchanged; v2 `verify_v2`
  rejects any tampered info dict and any malformed `btmh` multihash; KRPC
  hardening and tracker anti-spoof were AST-verified untouched.
- **gitweb** — argv-only git (no shell, `--` separators, option-injection
  refused), repo/path/ref/LFS-oid confinement, escape-first HTML, and
  auth-on-every-route all held.
- **astrx-integration** — SSRF-config-only, admin-token secrecy, XSS escaping,
  and CSRF + `ADMIN_ACCESS` gating all held under hostile-backend rendering.

### Findings and fixes

Every High and Medium was fixed and covered by a regression test built from the
reviewer's own PoC; the Lows and hardening items were fixed in the same pass.

| Module | Sev | Finding | Fix |
|---|---|---|---|
| gitweb | HIGH | Markdown link/ref regexes were quadratic (unbounded) → unauthenticated CPU-exhaustion DoS. The fix pass found **two more** quadratic paths (ATX-heading strip, angle autolink). | Bounded every span, linearized the heading strip, and added a 256 KiB size cap with an escaped-`<pre>` fallback. `[`×40000: 12 s → 2.5 ms. |
| gitweb | LOW | Nested Markdown placeholders leaked literal NUL bytes. | Loop the un-stash to a fixed point — and, on noticing the naive fix would open a URL-position XSS, rejected NUL sentinels inside `_safe_url`/autolinks. |
| gitweb | LOW | Non-ASCII HTTP Basic username raised `TypeError` → 500. | Compare usernames as UTF-8 bytes; still constant-time. |
| gitweb | LOW | `--auth-file` with only comments silently disabled auth; empty digest locked everyone out. | Fail loud at startup; reject empty salt/digest. |
| onioncrawler | MED | A trailing-dot host (`…onion.`) canonicalized to a distinct key, evading the host blocklist for already-indexed pages. | `normalize_host` strips **all** trailing dots so the canonical host equals the blocklist key. |
| onioncrawler | MED | Blocklisted media served larger than the response cap was never hashed → host never flagged. | Hash media up to a dedicated media cap **before** the ok-guard; fail closed when it can't be verified. |
| onioncrawler | LOW | Seed-list read the whole file with `readlines()`. | Bounded streaming read + seed cap. |
| onioncrawler | LOW | Admin blocklist `value` could inject extra lines via `\n`. | Strip CR/LF/control chars before write. |
| onioncrawler | — | Four test modules only ran under package-mode discovery. | Made all test imports invocation-robust; the suite now runs under any discovery. |
| websearch | MED | `/suggest` raised `UnicodeEncodeError` → 500 for a query word ending in U+D7FF (lone-surrogate increment). | Guard the surrogate range in `_prefix_upper`; widen the bind-site except. |
| websearch | LOW | `backup` could clobber an empty file via a `file:` URI that slipped both guards. | Reject any RFC-3986 `scheme:` dest, not just `://`. |
| websearch | LOW | The image vertical could render `<img src>` at internal IPs → client-side SSRF in the viewer's browser. | Drop internal/loopback/link-local literal-IP srcs at index time (no DNS, no socket). |
| torrentds | HIGH | `scrape_http` followed 302s via the default opener → a hostile tracker could redirect to `127.0.0.1`, cloud metadata, or `ftp://` (blind SSRF). | Private opener with a public-IP-only redirect guard and no FTP/File/Data handlers. |
| torrentds | MED | UDP scrape accepted replies from any source with a predictable transaction id. | `connect()` the socket + `os.urandom(4)` transaction id. |
| torrentds | MED | A control char in a torrent name broke the shared `/rss` feed for every subscriber. | Strip XML-1.0-illegal code points before escaping, on both RSS paths. |
| torrentds | MED | Swarm-health folding ran synchronously per result → up to 100×trackers blocking calls per request. | Cap total scrapes per request + a hard wall-clock budget. |
| torrentds | LOW | `_decode_btih` 40-hex branch could yield a sub-20-byte infohash; v1 lengths weren't clamped; v2 `dht_info_hash` was ignored; `.torrent` download 500'd on non-Latin-1 names. | Length check, `max(0,…)`, verify-against-requested-hash, ASCII-safe filename. |
| torrentds | LOW | Cross-infohash dedup keyed on path+length only → a copied layout could poison it. | Fold the pieces-root / file-tree digest into the content signature; collapse only on the stronger match. |
| suitedash | MED | A hostile upstream `/metrics` could break the whole federated exposition (bad escape, duplicate labels, reserved-name spoof). | Unescape then re-escape upstream label values, dedup all label names, and reserve the `suitedash_` namespace. |
| suitedash | MED | A deeply-nested JSON upstream raised `RecursionError` → `/metrics` 500. | Broaden the guard so it degrades to no series. |
| suitedash | LOW | Duplicate alert-rule ids silently dropped a rule. | Validate id uniqueness; auto-ids can't collide with explicit ones. |
| astrx-integration | MED | The federated torrent tab rendered a dead `.torrent` link and empty seeders/leechers/category (a template nested-section scoping quirk, shared with the existing torrent page). | Hoist row values out of the flag sections in both templates; add a rendered-row bridge test. |
| astrx-integration | LOW | `normaliseBase` filtered scheme only, never enforced loopback despite its docstring (a mistyped base could ship the admin token off-box). | Require a loopback host and reject embedded userinfo, in both configs. |
| astrx-integration | INFO | Internal-tab URL wasn't run through a safe-href filter. | Added a relative-path-aware `safeInternalHref`. |

---

## 3. Verification (cold checkout, delivery-simulating)

All Python suites were run with the sandbox's convenience `crawlcore.pth`
**disabled**, so the bundled-import shim is exercised exactly as a recipient would
hit it. Green across the board:

- gitweb **117**, onioncrawler **182** (identical under both `discover` and
  `discover -s tests`), websearch **142**, torrentds **252**, suitedash **92** —
  **785 tests**, zero failures. The crawlcore bundle resolves with no `.pth`.
- AstrX in-tree gates on the shipped integration source: `php -l` clean,
  **PHPStan level 10 — no errors** (424 files), language parity across 45 files,
  `check_modules` 18-OK, and the suite bridge test **123 passed / 0 failed**.
- `docker compose config` validates (exit 0). The deploy `torrc` is a valid Tor
  configuration whose hidden-service targets are Docker service names; it
  validates inside the network per its documented `docker compose run --rm tor`
  procedure (Tor accepts hostname targets — confirmed — and resolves them via
  Docker's embedded DNS at runtime, which the stack guarantees by making `tor`
  depend on every service's healthcheck).

---

## 4. Deliberately deferred (unchanged from the proposal)

- **Headless-render worker** for JavaScript-heavy pages / screenshots — this
  breaks the zero-dependency rule (it needs a browser), so it stays an optional,
  separate service, not part of the core.
- **Index sharding / scale-out** — a gated architecture decision, not a bug. The
  single-SQLite design is honest for the target scale; committing to shards is a
  choice to make deliberately, not by default.

One consequence worth stating plainly: after the dedup fix, a pure-v1 and a
pure-v2 torrent of the same content are no longer linked by file layout alone —
that link *was* the poisonable heuristic, and without a shared cryptographic
content hash it shouldn't be treated as identity. suitedash history is in-memory
by design and resets on restart.

---

## 5. Verdict

Every feature from the ranked proposal — and the extra ones surfaced while
building — is implemented, and every "lacks vs the originals" gap that respects
the zero-dependency / no-JavaScript rules is closed. The full adversarial review
is done: all High and Medium findings are fixed and regression-tested, the Lows
swept up in the same pass, and every crown security invariant was proven intact
before and after. The suite is a clean, drop-in replacement.
