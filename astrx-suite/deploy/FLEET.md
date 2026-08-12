# astrx-suite — horizontal fleet deployment

The single-node `docker-compose.yml` at the suite root runs the whole suite on
one host behind Tor. This guide covers **scaling out** across several hosts,
using the zero-dependency federation built into the tools. Nothing here adds a
dependency: coordination is plain HTTP between nodes.

Only the **clearnet crawler+index (websearch)** needs sharding. The onion
crawler scales by adding Tor circuits, and the DHT indexer scales by giving its
single database a bigger box. Recognising that is what keeps the fleet
dependency-free.

## 1. websearch — sharded clearnet fleet

Every registrable host is assigned to exactly one shard by **rendezvous (HRW)
hashing**, so per-host politeness and URL-seen dedup need no cross-node
coordination: a host lives on one shard, so the same URL can never be enqueued
twice, and only one node is ever polite to a given site.

**On each shard node `i` — the shard id list must be identical across the
fleet, and each node passes its own `--shard-id`:**

```sh
# crawl only the hosts this shard owns:
websearch crawl --db /data/web.db \
    --shard-id A --shards A,B,C,D,E,F \
    --seeds /data/seeds.txt --broad --workers 8 --keep-alive

# serve this shard's slice (the aggregator queries this):
websearch serve --db /data/web.db --host 0.0.0.0 --port 8803
```

Adding or removing a shard only remaps the fraction of hosts that HRW hashing
must move — the rest stay put, so a resize does not invalidate the fleet's
crawl state.

The sharding decision is a pure function you can check by hand on any node:

* `norm_host(host)` — lower-cases, drops the port and a trailing dot.
* `shard_for(host, shards)` — hashes `sha256(shard_id ‖ 0x00 ‖ host)` for every
  shard id and picks the greatest digest.
* `owns(host, my_id, shards)` — true iff this node owns that host. With no
  shard set configured (single-node mode) everything is owned.

### The aggregator

A stateless aggregator fans a query out to every shard's JSON API in parallel
and merges the answers: cross-host near-duplicate mirrors are collapsed with the
very same SimHash used single-node, each shard gets a wall-clock deadline, and
the response is flagged **partial** when a shard is slow or down.

Shard base URLs are **operator configuration, never a user-supplied address**,
the query is URL-encoded into a fixed base, and every shard response is size-
and time-bounded. The shard servers keep their own SSRF-checked crawl path.

## 2. onioncrawler — scale by circuits, not shards

The darknet crawler is bounded by Tor circuit throughput, not CPU. Scale it by
running more crawler workers against more circuits rather than by sharding the
index:

```sh
onioncrawler crawl --db /data/crawl.db --seeds /data/seeds.txt \
    --tor-host tor --tor-port 9050 --workers 8
```

Per-host stream isolation means each `.onion` already gets its own circuit, so
adding workers adds parallel circuits. Keep one database per crawler node and
point separate `onioncrawler search` instances at each, or rsync the snapshots
to a single serving node.

## 3. torrentds — one database, a bigger box

The DHT harvester is a single writer against one store. Scale vertically:

```sh
torrentds index  --db /data/torrentds.db --concurrency 64
torrentds search --db /data/torrentds.db --host 0.0.0.0 --port 8804
torrentds tracker --db /data/torrentds.db --host 0.0.0.0 --http-port 8805
```

Run the harvester and the search UI as separate containers against the same
volume (the compose file already does this), so a harvest pause never takes the
UI down.

## 4. gitweb + suitedash

`gitweb` is stateless apart from the repositories it serves read-only; run one
per repository host, or one against a shared read-only mount. It needs the real
`git` binary, which is why its image is alpine + git rather than `FROM scratch`.

`suitedash` is the fleet's status page. Point one instance at every node's
service URLs:

```sh
suitedash --host 0.0.0.0 --port 8806 \
    --service websearch-a=http://node-a:8803 \
    --service websearch-b=http://node-b:8803 \
    --service torrentds=http://node-c:8804
```

Its `--check` mode exits non-zero if any configured service is DOWN, so it
doubles as a fleet-wide health probe for an external monitor:

```sh
suitedash --check --quiet || alert
```

## 5. Health and observability

Every service exposes a Prometheus `/metrics` endpoint, and `suitedash`
federates all of them into a single aggregate exposition at its own `/metrics`
— so one scrape target covers the fleet.

The four `FROM scratch` images carry **no HEALTHCHECK of their own**: they have
no shell to run one in. That is deliberate — `suitedash` is the health monitor,
and it probes each service's health endpoint (trying the configured path then a
set of known fallbacks) from the internal network.
