# astrx-suite — horizontal fleet deployment

The single-node `docker-compose.yml` in this directory runs the whole suite on
one host behind Tor. This guide covers **scaling out** across several hosts (e.g.
Hetzner), using the zero-dependency federation built into the tools. Nothing here
adds a dependency: coordination is plain HTTP between nodes.

Only the **clearnet crawler+index (websearch)** needs sharding. The onion crawler
scales by adding Tor circuits (torfleet), and the DHT indexer scales by giving its
single database a bigger box. Recognizing that is what keeps the fleet stdlib-only.

## 1. websearch — sharded clearnet fleet

Assign every host to exactly one shard by rendezvous (HRW) hashing, so per-host
politeness and URL-seen dedup need no cross-node coordination.

**On each shard node `i` (ids must be identical across the fleet):**

```
# crawl only the hosts this shard owns:
python3 -m websearch crawl --db /data/web.db \
        --shard-id A --shards A,B,C,D,E,F --seeds /data/seeds.txt --broad
# serve this shard's slice (the aggregator queries this):
python3 -m websearch serve --db /data/web.db --host 0.0.0.0 --port 8803
```

**On a coordinator node** (stateless — just a pure HRW function + fan-out):

```
python3 -m websearch fed-serve --host 0.0.0.0 --port 8809 \
        --shards http://nodeA:8803,http://nodeB:8803,http://nodeC:8803,\
http://nodeD:8803,http://nodeE:8803,http://nodeF:8803
```

`fed-serve` fans each query to all shards in parallel (per-shard deadline),
merges by normalized score, collapses cross-host mirrors with SimHash, paginates,
and flags **partial** results if a shard is slow/down. It reuses the shard UI
verbatim, so `/`, `/search`, `/api/search` behave exactly like a single node.

**Cross-shard discovery.** Because a host lives on one shard, discovered
out-links to *other* shards' hosts aren't crawled locally. Seed each shard with a
broad seed list (each shard keeps only the hosts it owns), or periodically feed a
shared discovered-hosts list to `crawl --seeds` on every node; each node keeps
only its slice.

**Example shard-node compose** (no host ports; front with Tor or a proxy):

```yaml
services:
  websearch-shard:
    build: { context: ./websearch }
    command: ["serve", "--db", "/data/web.db", "--host", "0.0.0.0", "--port", "8803"]
    volumes: ["websearch-data:/data"]
    expose: ["8803"]
    security_opt: ["no-new-privileges:true"]
    cap_drop: ["ALL"]
volumes: { websearch-data: {} }
```

The `fed-serve` aggregator node runs the same image with the `fed-serve` command
and `--shards` pointed at the shard nodes' internal addresses.

### Capacity (rough, mid-2026 Hetzner)
- **Tier 0 (today):** 1× AX52 (~€64/mo) — full onion index, ~5–15M clearnet docs,
  ~10M infohashes.
- **Tier 1 (this fleet, ~€500/mo):** 1× CPX21 coordinator + 6× AX52 shards + 1×
  AX52 onion/torfleet + 1× AX41 DHT → ~40–80M clearnet docs, full onion, tens of
  millions of infohashes. All stdlib.
- Swap shards to AX102 for ~80–150M docs. Beyond ~10⁸, add the optional
  PostgreSQL/OpenSearch tier (a deliberate, separate dependency — not built here).

## 2. onioncrawler — torfleet (scale the Tor fetch tier)

The onion index is small; you scale *fetch concurrency* by running several Tor
daemons and spreading crawling across them. A host is pinned to one daemon (stable
hash), so its circuit reuse + politeness stay consistent.

Run N `tor` services (each its own SOCKS port), then:

```
python3 -m onioncrawler crawl --db /data/crawl.db --fetcher tor \
        --tor-host tor1 --tor-port 9050 \
        --tor-pool tor2:9050,tor3:9050,tor4:9050 \
        --workers 8 --seeds /data/seeds.txt
```

`--tor-pool` *adds* daemons to the base `--tor-port`; total throughput scales with
the pool size. The darknet-only anti-leak gate is enforced per fetch on every
endpoint.

## 3. torrentds — single big box

One DHT indexer surveys the whole Mainline DHT (BEP-51 sampling) in hours; extra
node-IDs only diversify routing position. The **database** is the only limit, so
scale vertically (RAM + NVMe) rather than sharding. The tracker already serves
announce/scrape from an in-memory swarm table with periodic flush, so it needs no
database on the hot path.

## 4. Provisioning

Terraform (`hcloud` provider) for the nodes + Ansible to install Python 3.11 and
drop the compose files; Docker Compose (or Swarm) per node. Skip k3s until the
fleet exceeds ~15–20 nodes. Keep shard databases on their own volumes; the
coordinator is stateless and can be replicated behind a round-robin.
