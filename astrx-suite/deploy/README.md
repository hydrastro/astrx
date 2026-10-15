# astrx-suite — deployment

One command brings the whole Rust suite up behind Tor:

```sh
cp deploy/.env.example .env      # optional: profiles / image tag
docker compose up -d             # builds + starts the published stack
docker compose exec tor sh -c 'cat /var/lib/tor/*/hostname'   # your .onions
```

or, equivalently, `make -C deploy up` then `make -C deploy onions`.

## What gets built

A single `docker compose build` compiles the Cargo workspace **once** (release,
statically linked against musl) and cuts every image out of that one builder
layer:

| Service        | Image base | Port | What it serves |
|----------------|------------|------|----------------|
| `gitweb`       | alpine + git | 8801 | read-only no-JS git frontend |
| `onioncrawler` | **scratch** | 8802 | darknet crawler + search UI/API |
| `websearch`    | **scratch** | 8803 | clearnet crawler + search UI/API |
| `torrentds`    | **scratch** | 8804/8805 | DHT search UI/API + BitTorrent trackers |
| `suitedash`    | **scratch** | 8806 | the suite status dashboard |
| `tor`          | alpine + tor | 9050 | the only ingress; SOCKS egress for the onion crawler |

### Why the images are empty

The standard-library-only default library builds are distinct from the
network-enabled service binaries. Networking uses external crates, including
Tokio/getrandom and their transitive dependencies.

Four service images use `FROM scratch` with statically linked binaries. This
reduces the operating-system components included in the image, but it does not
remove embedded runtime code, libc where linked, or dependency vulnerabilities.
Track the actual build inputs, scan dependencies and rebuild affected releases.
The absence of a shell is not a general protection against code execution.

`gitweb` is the one documented exception: it is a front-end for the real `git`
binary, which it execs **argv-only, never through a shell**, so its image is
alpine plus git and nothing else.

## Privacy posture

* **No app port is published to the host** — there is no `ports:` mapping
  anywhere in the compose file. The only ingress is Tor.
* The app containers sit on an `internal` Docker network with no route to the
  host or the internet. `tor` is dual-homed (`internal` + `egress`) and bridges
  ingress in.
* The **darknet crawler is not attached to `egress` at all**: its only route out
  is Tor's SOCKS port on the internal network, so a bug cannot leak a clearnet
  request. `torrc`'s `SocksPolicy` additionally allows only the internal
  subnet to use SOCKS, so a compromised clearnet crawler cannot use Tor as an
  open proxy. (Keep that CIDR in sync with the `internal` network's `ipam`
  subnet — both are pinned to `10.63.0.0/24`.)
* Hidden-service keys live in the `tor-keys` volume, so every generated
  `.onion` address is **stable across restarts**.
* Every app container runs unprivileged, `cap_drop: ALL`,
  `no-new-privileges`, and with a read-only root filesystem.

## Opt-in workloads

The always-on services are the read-only, published ones. The crawlers and
indexers are behind compose profiles, so you choose what actually goes out to
the network:

```sh
COMPOSE_PROFILES=crawl-onion docker compose up -d   # darknet crawler (Tor only)
COMPOSE_PROFILES=crawl-web   docker compose up -d   # clearnet crawler (egress)
COMPOSE_PROFILES=index-dht   docker compose up -d   # DHT harvester (egress)
COMPOSE_PROFILES=tracker     docker compose up -d   # BitTorrent trackers
```

or `make -C deploy crawl-onion` / `crawl-web` / `index` / `tracker`.

## Health

`suitedash` is the suite's health monitor: it polls every service over the
internal network (trying the configured health path then known fallbacks,
parsing metrics as both Prometheus text and JSON) and federates every service's
`/metrics` into one aggregate exposition. That is why the `FROM scratch` images
carry no `HEALTHCHECK` of their own — they have no shell to run one in.

```sh
make -C deploy check      # exits non-zero if any service is DOWN
```

## Useful targets

```
make -C deploy help         list every target
make -C deploy config       validate + render the resolved compose config
make -C deploy onions       print each service's generated .onion address
make -C deploy verify-tor   syntax-check torrc inside the tor image
make -C deploy logs S=websearch-serve
make -C deploy test         run the full workspace test + lint gate on the host
make -C deploy clean        remove containers, networks AND volumes (destroys the .onion keys)
```

## Scaling out

See [FLEET.md](FLEET.md) for the multi-host story: HRW-sharded clearnet
crawling with a stateless scatter-gather aggregator, and how the other engines
scale.

## Storage coordination

All snapshot writers must use the patched suite and the same canonical store
path. A crawler/indexer holds an exclusive writer lease; administrative writes
return 503 while it is busy. Search readers adopt valid published changes on the
next request, including segmented web indexes. Torrent blocklist writes persist
before acknowledgement. See [operations](../../docs/OPERATIONS.md) for backup,
crash-window and filesystem assumptions. The web fetcher remains HTTP-only.
