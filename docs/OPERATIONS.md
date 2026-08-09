# astrx-suite — operations guide

How to run and manage the whole system: the standalone engines, the crawls, the
blocklists, health/metrics, the CMS admin pages, and scaling. Everything binds
loopback and is happy behind Tor; nothing here needs JavaScript.

## Bring the stack up

One command builds and starts every service behind Tor (one v3 onion per surface):

```
cd astrx-suite/legacy-python
cp deploy/.env.example .env      # optional: image tag / profiles
docker compose up -d
docker compose exec tor sh -c 'cat /var/lib/tor/*/hostname'   # your .onion addresses
```

No app port is published to the host — the only ingress is Tor. Each stateful
service keeps its SQLite DB in a named volume, and Tor keeps its keys in
`tor-keys`, so the generated .onion addresses are stable across restarts.

Prefer systemd? Each engine is a plain `python3 -m <tool> …` process bound to
`127.0.0.1`; run it under a unit with `Restart=on-failure` and point a hidden
service at the port (see each tool's README).

## Run and schedule crawls

The search UIs are read-only; crawling/indexing are separate worker processes:

```
# onion crawl (needs a Tor SOCKS at --tor-host/--tor-port)
python3 -m onioncrawler crawl --db /data/crawl.db --seeds seeds.txt --fetcher tor --tor-host tor -v
# clear-web crawl (talks to the clearnet directly)
python3 -m websearch crawl --db /var/lib/websearch/web.db --seeds seeds --scope-domain example.com
# torrent DHT harvester (long-running)
python3 -m torrentds index --db /data/torrentds.db --port 6881
```

Under compose these are opt-in profiles: `docker compose --profile crawl up onioncrawler-crawl`,
`… websearch-crawl`, and `--profile index up torrentds-index`. To schedule
recrawls, wrap the crawl command in a systemd timer or cron; the crawlers are
resumable (the frontier lives in SQLite, workers lease URLs, finished URLs are
never refetched), so a kill loses nothing and a restart picks up where it left off.

## Seeds, blocklists, and the CMS admin pages

Two admin pages ship as native CMS modules (admin-gated, CSRF-protected, no-JS):

- **/admin-suite** — live status, latency and key metrics for all four engines,
  plus the one write action any engine exposes: submitting a `.onion` seed to the
  crawler (rejected if it isn't a valid v3 onion, is blocked, or is already known).
- **/admin-blocklist** — block onion hosts/keywords and torrent infohashes/
  keywords; applied at both index and query time.

Configure the abuse blocklists **before** you index anything. onioncrawler also
ships an operator host/keyword list (and an Ahmia-style md5 hostlist); torrentds
an infohash/keyword list. Running an index makes you responsible for what it
surfaces — know the law where you operate. torrentds stores metadata + magnet
links only, never file content.

> Note: starting/pausing a crawl or triggering a reindex from the admin panel is
> not yet wired — the engines currently expose status + seed + block over HTTP,
> and the crawl/index processes are managed as services (compose/systemd/CLI).
> Full crawler control from the admin is on the roadmap.

## Navbar order

The suite pages are added to the public navbar by the one-time registration SQL
(see `docs/suite-search-modules.md`). To make them read in a deliberate order,
set each entry's `sort_order` when you register it — recommended sequence:

```
Search = 10   Web = 20   Onion = 30   Torrent = 40   Git = 50
```

(The internal `site_search` page keeps its existing pin; the suite pages slot in
after it via ascending `sort_order`.)

## Health and metrics

Every engine answers a health path (`/health` or `/healthz`) and a metrics
endpoint (`/metrics` Prometheus text, or `/api/stats` JSON for torrentds). The
compose healthchecks use these; `/admin-suite` renders them; scrape `/metrics`
with Prometheus if you want history. suitedash is a no-JS dashboard that
health-checks and links every service.

## Backups and upgrades

- **Back up** the named volumes (`onioncrawler-data`, `websearch-data`,
  `torrentds-data`, `gitweb-repos`, and especially `tor-keys` — losing it changes
  your .onion addresses). A stopped-service `sqlite3 db '.backup'` or a volume
  snapshot is enough; the DBs are plain SQLite.
- **Upgrade** by rebuilding images (`docker compose build && docker compose up -d`);
  data lives in volumes, so it survives. The search servers are stateless beyond
  their DB.

## Scale out

For multi-node / Hetzner deployments (sharded websearch with a scatter-gather
aggregator, a Tor fetch pool for onioncrawler, single-box DHT for torrentds, and
capacity tiers), see `astrx-suite/legacy-python/deploy/FLEET.md`.
