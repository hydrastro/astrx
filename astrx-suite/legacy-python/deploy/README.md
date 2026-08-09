# astrx-suite — one-command Tor deployment

Bring the whole astrx-suite up as a set of **v3 Tor hidden services** with a
single command. Nothing is exposed on the host: the only way in is Tor.

```
┌──────────────────────── host ────────────────────────┐
│                                                       │
│   docker network "internal"  (internal: true)         │
│   ┌───────────────────────────────────────────────┐  │
│   │ gitweb  onioncrawler-search  websearch-serve   │  │
│   │ torrentds-search  torrentds-tracker  suitedash │  │
│   │                     ▲                          │  │
│   │                     │ resolves + connects      │  │
│   └─────────────────────┼──────────────────────────┘  │
│                      ┌───┴───┐                         │
│                      │  tor  │── SOCKS :9050 ─┐        │
│                      └───┬───┘  (internal)    │        │
│   docker network "egress"│                    ▼        │
│   ┌──────────────────────┼───────────  onioncrawler-   │
│   │  (bridge, outbound)  │             crawl (opt)     │
│   │  websearch-crawl  torrentds-index (opt)            │
│   └──────────────────────┼──────────────────────────┘ │
└──────────────────────────┼────────────────────────────┘
                           ▼
                     Tor network  ──►  six .onion addresses
```

## TL;DR

```bash
cd /path/to/astrx-suite
cp deploy/.env.example .env          # optional (profiles, image tag, TZ)
docker compose up -d                 # builds every image, starts the stack
# ...wait ~30–60s for Tor to bootstrap and publish the descriptors...
docker compose exec tor sh -c 'for d in /var/lib/tor/*/; do \
  printf "%-22s %s\n" "$(basename "$d")" "$(cat "$d/hostname")"; done'
```

Or use the helpers in `deploy/`:

```bash
make -C deploy up          # docker compose up -d
make -C deploy onions      # print the six .onion hostnames
make -C deploy logs SVC=tor
# no make? use the shell wrapper:
./deploy/run.sh up
./deploy/run.sh onions
```

## What comes up

`docker compose up -d` (no profile) starts the **published** surfaces plus Tor:

| service              | internal addr            | onion maps      | health    |
|----------------------|--------------------------|-----------------|-----------|
| `gitweb`             | `gitweb:8801`            | onion:80 → 8801 | `/health` |
| `onioncrawler-search`| `onioncrawler-search:8802`| onion:80 → 8802| `/healthz`|
| `websearch-serve`    | `websearch-serve:8803`   | onion:80 → 8803 | `/healthz`|
| `torrentds-search`   | `torrentds-search:8804`  | onion:80 → 8804 | `/health` |
| `torrentds-tracker`  | `torrentds-tracker:8805` | onion:80 → 8805 | `/`       |
| `suitedash`          | `suitedash:8805`         | onion:80 → 8805 | `/healthz`|
| `tor`                | ingress + SOCKS `:9050`  | —               | —         |

Optional workers (behind Compose profiles, off by default):

| service              | profile | role                                          |
|----------------------|---------|-----------------------------------------------|
| `onioncrawler-crawl` | `crawl` | resumable .onion crawler; egress via Tor SOCKS|
| `websearch-crawl`    | `crawl` | one-off clear-web crawler (egress network)    |
| `torrentds-index`    | `index` | long-running Mainline DHT harvester (egress)  |

Enable them per-invocation or via `.env`:

```bash
# one-off, no profile needed — see "Running a crawl / index" below
docker compose run --rm onioncrawler-crawl crawl --db /data/crawl.db --seed http://xxxx.onion -v

# or keep torrentds-index running:
COMPOSE_PROFILES=index docker compose up -d      # or set it in .env
```

## Where the `.onion` hostnames appear

Tor writes one `hostname` file per hidden service under `/var/lib/tor/<name>/`
inside the `tor` container. That directory is the **`tor-keys` named volume**,
so the keys — and therefore the `.onion` addresses — are **stable across
restarts, rebuilds, and `docker compose down`** (they are lost only if you
delete the volume, e.g. `docker compose down -v`).

Read them any time after Tor has bootstrapped:

```bash
docker compose exec tor sh -c 'cat /var/lib/tor/gitweb/hostname'
make -C deploy onions      # all six at once
```

Back them up by copying the volume (each dir holds `hostname`,
`hs_ed25519_secret_key`, `hs_ed25519_public_key`):

```bash
docker run --rm -v astrx-suite_tor-keys:/keys -v "$PWD":/out alpine \
  tar czf /out/tor-keys-backup.tgz -C /keys .
```

## Running a one-off crawl / index

The crawlers write into the **same named volumes** the serving containers read,
so a crawl and a live search share one database.

```bash
# Onion crawl — egress is Tor's SOCKS on the internal network (--tor-host tor).
docker compose run --rm onioncrawler-crawl \
    crawl --db /data/crawl.db --seed http://someonionservice.onion \
          --fetcher tor --tor-host tor --tor-port 9050 -v

# Clear-web crawl — talks to the clearnet directly (egress network).
docker compose run --rm websearch-crawl \
    crawl --db /var/lib/websearch/web.db --scope-domain example.com https://example.com

# torrentds DHT harvester — long-running; harvests the clearnet Mainline DHT.
docker compose --profile index up -d torrentds-index
docker compose logs -f torrentds-index
```

`make` shortcuts: `make -C deploy crawl-onion ARGS="--seed http://x.onion -v"`,
`make -C deploy crawl-web ARGS="--scope-domain x.com https://x.com"`,
`make -C deploy index`.

Seed files: drop `seeds.txt` (onion) or `seeds` (web) into the corresponding
volume, or pass seeds inline as shown above.

## Privacy posture (read this)

- **No host ports.** There is no `ports:` mapping anywhere in
  `docker-compose.yml` — nothing is bound on the host. `docker compose ps` will
  show no published ports. **Tor is the only ingress.**
- **App services are on an `internal: true` network** with no route to the host
  or the internet. Only `tor` (and the opt-in clearnet crawlers) touch the
  `egress` bridge. Tor is dual-homed to bridge the two.
- **Tor never relays or exits** (`ExitPolicy reject *:*`) — it only serves the
  suite's onions and provides SOCKS to the crawler.
- **The crawler egresses through Tor**, not directly: `onioncrawler-crawl` uses
  `--tor-host tor --tor-port 9050`, with per-`.onion` circuit isolation
  (`IsolateSOCKSAuth`).
- **SOCKS is not host-published.** `SocksPort 0.0.0.0:9050` is reachable only
  from sibling containers on the internal Docker network.
- **v3 onions are TCP-only**, so the torrentds **UDP** tracker (6969) and the
  DHT (6881/udp) are *not* published as onions; only the HTTP tracker is.
- Hardening: app containers run as non-root with `cap_drop: ALL` and
  `no-new-privileges`. Tor starts as root only to fix the key-volume ownership,
  then setuids to `debian-tor`.

## Operations

```bash
docker compose config          # validate + render the resolved config
docker compose build           # build all images (needs python:3.11-slim base)
docker compose up -d           # start
docker compose ps              # status (note: no published ports)
docker compose logs -f tor     # watch Tor bootstrap + descriptor upload
docker compose down            # stop; KEEPS volumes (onions stay stable)
docker compose down -v         # DANGER: also deletes volumes -> new onions
docker compose run --rm tor --verify-config   # parse-check deploy/torrc
```

## Files

```
astrx-suite/
├── docker-compose.yml        # the whole stack (build contexts ./<tool>)
└── deploy/
    ├── torrc                 # Tor config: 6 hidden services + crawler SOCKS
    ├── Dockerfile.tor        # tor image (python:3.11-slim + Debian tor)
    ├── tor-entrypoint.sh     # fixes key-volume perms, then execs tor
    ├── .dockerignore         # keeps the tor build context minimal
    ├── .env.example          # copy to ../.env (profiles / image tag / TZ)
    ├── Makefile              # up / down / onions / logs / verify-tor / crawl…
    ├── run.sh                # same, for hosts without make
    └── README.md             # this file
```

## Requirements & notes

- Docker Engine with Compose v2 (`docker compose`, not `docker-compose`).
- The app images are **zero-dependency pure Python**; each build is essentially
  a file copy on top of `python:3.11-slim`. The `tor` image reuses that same
  base, so a deployment pulls **one** base image total.
- Publishing the onions needs **real network access** so Tor can bootstrap and
  upload the service descriptors. The config is complete and valid, but Tor
  cannot advertise `.onion` addresses in an offline/no-network environment.
