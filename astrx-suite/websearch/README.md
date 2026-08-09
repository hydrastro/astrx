# astrx-websearch

A **from-scratch clearnet search engine** — real crawler + inverted index +
explicit ranking + a no-JavaScript query UI — in **Python 3.11 standard library
only**. No pip, no third-party packages, no metasearch proxying. It crawls,
indexes into SQLite FTS5, ranks with a documented scoring function, and serves
results over a server-rendered UI and a JSON API.

Inspiration: the crawl discipline of a production crawler, the *simplicity* of
SearXNG's UI, and the "run-your-own-index" spirit of YaCy / mnoGoSearch — but
this indexes its own crawl rather than proxying other engines.

```
python3 -m websearch crawl  --seeds seeds.example --db web.db --scope-domain example.com
python3 -m websearch serve  --db web.db --port 8803        # http://127.0.0.1:8803/
python3 -m websearch stats  --db web.db
```

---

## Architecture

One SQLite file (`web.db`, WAL mode) holds everything: the crawl frontier, the
document store, the FTS5 inverted index and the link graph.

```
seeds ─► frontier(SQLite) ─► crawler ─► httpclient ─► htmlparse ─► index(FTS5)
             ▲  lease/resume      │ robots + politeness           │  docs + fts + links
             └────── outlinks ────┘                               ▼
                                              ranking (bm25 + signals) ─► server (HTML + JSON)
```

| Module | Responsibility |
|---|---|
| `websearch/canonical.py`  | URL canonicalization (RFC 3986 dot-segments, default-port/fragment stripping, query sort), origin/authority, scope test, trap heuristics. |
| `websearch/robots.py`     | robots.txt parser: `User-agent` grouping, `Allow`/`Disallow` with `*`/`$`, longest-match-wins, `Crawl-delay`. |
| `websearch/httpclient.py` | HTTP/1.1 fetcher on `http.client`: gzip/deflate, **manual** capped redirects (each hop re-checked), timeouts, byte cap, charset decode. |
| `websearch/htmlparse.py`  | `html.parser` extraction: title, meta description, visible text (drops `script`/`style` + best-effort nav/header/footer boilerplate), outlinks, `rel=canonical`, `<base>`, `meta robots`, language guess. |
| `websearch/frontier.py`   | SQLite frontier: `add`/dedup, atomic `lease` (`BEGIN IMMEDIATE`), `reclaim` of expired leases (resume), per-host politeness state, robots cache. |
| `websearch/index.py`      | Schema, document upsert, external-content FTS5 kept in sync by triggers, link graph, incoming-link counts, PageRank-lite, stats. |
| `websearch/ranking.py`    | Query parser, **safe** FTS5 `MATCH` builder, the scoring function, query-biased escaped snippets. |
| `websearch/crawler.py`    | The crawl loop: budgets, politeness, robots, extraction, dedup, canonical/redirect handling, indexing. |
| `websearch/server.py`     | `http.server` no-JS UI, JSON API, `/about` stats, CSS. Binds `127.0.0.1` by default. |
| `websearch/__main__.py`   | `crawl` / `serve` / `stats` CLI. |

### Data model

```sql
docs (id, url UNIQUE, title, description, body, host, lang,
      fetched_at, content_hash, http_status, incoming, rank)
fts  USING fts5(title, description, body, content='docs', content_rowid='id')  -- triggers keep it in sync
links(src, dst, internal)                    -- link graph
frontier(url PK, host, depth, status, lease_until, tries, reason)  -- status: queued|leased|done|error|skipped
hosts(host PK, next_time, crawl_delay, robots_done, fetched)       -- per-origin politeness/budget
```

---

## Crawler discipline

* **robots.txt + Crawl-delay** honoured per origin (scheme+host+port), including
  on every redirect hop (the fetch `allow` callback re-checks robots and scope,
  and the robots.txt fetch itself re-checks scope + the SSRF denylist on its
  redirects). Missing/4xx robots ⇒ allow-all; parse errors ⇒ allow-all.
* **SSRF denylist (on by default).** Before connecting — on the initial URL and
  *every* redirect hop — the fetcher resolves the host and refuses any address
  that is loopback / private / link-local / reserved / multicast / unspecified
  (incl. `169.254.169.254` cloud-metadata and IPv4-mapped IPv6), then pins the
  socket to the validated address so DNS rebinding can't swap in an internal IP.
  Exempt a specific `host[:port]` with `--allow-host` (for internal testing) or
  disable entirely with `--allow-internal-ips` (dangerous).
* **Politeness**: a base per-host delay plus random jitter, enforced through the
  frontier's `hosts.next_time`; the lease query only returns URLs whose origin
  is due.
* **Budgets / limits**: max depth, per-host budget, total page budget, max
  response bytes, and a content-type allowlist (`text/html`,
  `application/xhtml+xml`, `text/plain`).
* **Canonicalization + dedup**: URLs are canonicalized before entering the
  frontier (`PRIMARY KEY` dedups); identical page bodies are dropped by
  `content_hash`; `rel=canonical` aliases are not indexed (the target is
  enqueued instead).
* **Trap guards**: path-segment-repeat cap (`/x/x/x/x…`) and query-parameter
  explosion cap, plus a hard path-depth cap — on top of the budgets, so a crawl
  always terminates.
* **Transport**: gzip/deflate, capped redirect chain, connect/read timeouts.
* **Resumable**: leases persist in SQLite; on restart, expired leases return to
  `queued` and `done` URLs are never refetched.

---

## Ranking formula (exact)

Candidates come from FTS5 ordered by `bm25`, then the top `CANDIDATE_CAP = 400`
are re-scored in Python with this explicit function (`ranking.score`):

```
final = relevance
      + K_LINK  * ln(1 + incoming)      # link popularity (internal in-links)
      + K_PR    * pagerank              # PageRank-lite, normalised 0..1
      + K_FRESH * freshness             # recency, 0..1
      + K_PROX  * proximity             # phrase / term-closeness, 0..1
```

where

```
relevance  = -bm25(fts, W_TITLE, W_DESC, W_BODY)     # SQLite bm25 is negative; negate so larger = better
freshness  = exp(-age_days / FRESH_HALFLIFE_DAYS)
proximity  = 1.0                         if an exact query phrase occurs in title+body
           = 0.5*coverage + 0.5*tightness  for ≥2 query terms (how many appear, and how close)
           = 0.0                         for a single term
```

Field weights and coefficients (in `ranking.py`, tune freely):

| Constant | Value | Meaning |
|---|---:|---|
| `W_TITLE` / `W_DESC` / `W_BODY` | 10 / 4 / 1 | bm25 per-field weights (**title > description > body**) |
| `K_LINK`  | 0.30 | weight on `ln(1+incoming)` |
| `K_PR`    | 0.80 | weight on PageRank-lite |
| `K_FRESH` | 0.20 | weight on freshness |
| `K_PROX`  | 0.60 | weight on proximity / exact-phrase bonus |
| `FRESH_HALFLIFE_DAYS` | 30 | freshness half-life |

The bm25 text relevance dominates; link/PageRank/freshness/proximity act as
documented boosts and tie-breakers. Each result's per-signal contributions are
returned in the JSON API (`signals`) for transparency.

### Query syntax and safety

* `"exact phrase"` → FTS5 phrase match, `+term` → required (AND), `-term` →
  excluded (`NOT`), bare terms → an OR group (ranking sorts them).
* **Injection-safe**: the parser reduces input to word tokens (`\w+`) and emits
  only double-quoted FTS5 string literals combined with `AND`/`OR`/`NOT`. No
  user character reaches FTS5 as an operator, so a query can never break the
  MATCH syntax (covered by tests). Any residual FTS/SQL error degrades to an
  empty result set, never a 500.
* **Snippets** are HTML-escaped *first*, then whole-word query matches are
  wrapped in `<mark>`, so hostile page content cannot inject markup (XSS test).

---

## Run

```bash
# 1) Crawl (scoped to seed hosts by default; --scope-domain to widen, --broad for open web)
python3 -m websearch crawl --seeds seeds.example --db web.db \
        --scope-domain example.com --max-pages 5000 --delay 0.5

# 2) Serve the no-JS UI + JSON API (127.0.0.1 by default)
python3 -m websearch serve --db web.db --port 8803
#   UI:   http://127.0.0.1:8803/?q=inverted+index
#   JSON: http://127.0.0.1:8803/api/search?q=inverted+index&page=1
#   Stats: http://127.0.0.1:8803/about

# 3) Inspect
python3 -m websearch stats --db web.db
```

Key crawl flags: `--max-depth`, `--max-pages`, `--per-host-budget`,
`--max-bytes`, `--delay`, `--jitter`, `--timeout`, `--user-agent`,
`--scope-domain` (repeatable), `--broad`, `--no-robots`, `--allow-host`
(repeatable; exempt a `host[:port]` from the SSRF denylist), `--allow-internal-ips`
(disable the SSRF denylist entirely — dangerous).

## Test

Offline, against a local fixture site on loopback (no network needed):

```bash
cd /tmp/astrx-suite/websearch
python3 -m unittest discover -s tests -p 'test_*.py' -v
```

The suite (`tests/`) stands up a fixture website (`tests/fixture_site.py`) with
interlinked pages, a `robots.txt` disallowing `/private/`, a redirect, a
gzip-encoded page, a `rel=canonical` alias, duplicate pages and two crawler
traps, then covers:

* **crawler** — fetches the corpus, honours robots, URL + content dedup,
  follows redirects, decodes gzip, bounds the trap, and **resumes** without
  refetching completed URLs;
* **index** — FTS population, in-place upsert (trigger sync), incoming-link
  counts and PageRank;
* **search** — best page ranked first, phrase and `+`/`-` operators, malicious
  input can't crash it, snippets are escaped;
* **server** — `/search` and `/api/search` return 200 / well-formed JSON,
  `/about` renders, and query echo + snippets are XSS-safe.

---

## Tor hidden-service deployment

The server is plain HTTP on loopback, which is exactly what a Tor onion service
expects to reverse-proxy. Keep it bound to `127.0.0.1` (the default) so it is
reachable *only* through Tor.

1. Install Tor and add an onion service to `torrc`:

   ```
   HiddenServiceDir /var/lib/tor/astrx_search/
   HiddenServicePort 80 127.0.0.1:8803
   ```

2. Run the engine bound to loopback (never `0.0.0.0` for a hidden service):

   ```bash
   python3 -m websearch serve --db web.db --host 127.0.0.1 --port 8803
   ```

3. `sudo systemctl reload tor` (or restart), then read the address:

   ```bash
   sudo cat /var/lib/tor/astrx_search/hostname     # xxxxxxxx.onion
   ```

Notes: the UI needs **no JavaScript**, so it works with the Tor Browser
"Safest" setting. For crawling onion sites you would route `httpclient` through
Tor's SOCKS port (`127.0.0.1:9050`) — not wired up here (see limitations).
Consider a dedicated low-privilege user, and set a descriptive `--user-agent`.

---

## Status / limitations

**Working, tested, no stubs in core paths:** crawler (robots + Crawl-delay,
politeness, budgets, canonicalization, dedup, redirects, gzip, trap guards,
resume), FTS5 inverted index with trigger sync, incoming-link counts +
PageRank-lite, the documented ranking function, safe query parsing, escaped
query-biased snippets, and the no-JS UI + JSON API + stats page. 28 tests pass
offline.

**Limitations / honest gaps:**

* **Single process.** The frontier's lease design is concurrency-ready, but the
  crawl loop is single-threaded; there is no multi-worker driver yet.
* **No JavaScript rendering.** Content injected client-side by JS is invisible
  (a from-scratch stdlib crawler has no headless browser).
* **robots `5xx` is treated as allow-all**, not the stricter "disallow-all while
  unavailable"; sitemaps and `robots.txt` `Sitemap:` directives are ignored.
* **Live-serving during an active crawl** may momentarily miss the newest rows,
  because the read-only server connection relies on a WAL checkpoint that
  `crawl` performs on finalize. Re-run `crawl`/finalize to refresh.
* **PageRank-lite** runs over the internal link graph of *indexed* pages only —
  a genuine popularity hint, not a full web-scale PageRank.
* **Language guess** is a tiny stop-word heuristic (en/es/fr/de), English default.
* **No SOCKS/Tor egress** for the crawler yet; the Tor section covers serving as
  a hidden service, not crawling .onion sites.
* **TLS** uses the system trust store; there is no crawl-time certificate-pinning
  or SNI policy beyond Python defaults.
