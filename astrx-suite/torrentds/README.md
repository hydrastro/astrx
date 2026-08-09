# torrentds

A self-contained **DHT torrent-metadata search engine + BitTorrent tracker**,
written in **pure Python 3.11 standard library** (no pip, no third-party
packages). Inspired by [magnetico](https://github.com/boramalper/magnetico)
and [btdig](https://btdig.com/).

It participates in the BitTorrent **Mainline DHT**, harvests infohashes from
DHT traffic, fetches each torrent's **metadata** (the info-dict) over the peer
wire, verifies it against the infohash, and indexes the name + file paths for
full-text search. It also ships a standards-compliant HTTP and UDP tracker.

> **It indexes and serves only *metadata* and *magnet links* — never file
> content.** No torrent payload is ever downloaded, stored, or served. See
> [Responsibility & blocklist](#responsibility--blocklist).

---

## Architecture

```
             Mainline DHT (UDP/KRPC)
                     │  harvest infohashes (get_peers / announce_peer)
                     ▼
   ┌──────────────────────────────────┐
   │ dht.DHTNode  (routing + KRPC)     │──┐ crawl (find_node) widens table
   └──────────────────────────────────┘  │
                     │ on_infohash        │
                     ▼                    │
   ┌──────────────────────────────────┐  │
   │ indexer.Indexer                  │  │
   │  discovery queue → fetch worker  │  │
   └──────────────────────────────────┘  │
        │ ut_metadata (BEP-9) over peer wire (BEP-3 + BEP-10)
        ▼
   ┌──────────────────────────────────┐
   │ metadata.fetch_metadata          │ sha1(info)==infohash → parse info-dict
   └──────────────────────────────────┘
        │ store
        ▼
   ┌──────────────────────────────────┐        ┌───────────────────────────┐
   │ store.Store  (SQLite + FTS5)     │◀───────│ search.* no-JS web UI + API│
   │  torrents, files, dht_nodes,     │        └───────────────────────────┘
   │  discovered queue, blocklist     │
   └──────────────────────────────────┘
                                              ┌───────────────────────────┐
   peerstore.PeerStore (swarms) ◀────────────│ tracker_http (BEP-3/23)    │
                                  ◀────────────│ tracker_udp  (BEP-15)      │
                                              └───────────────────────────┘
```

Every module is transport-testable in isolation; the network-facing pieces are
proven with **loopback** tests (two local nodes / crafted packets on
`127.0.0.1`) because the build sandbox has no internet and cannot reach the
live DHT.

### Modules

| Module            | Responsibility                                              |
|-------------------|-------------------------------------------------------------|
| `bencode.py`      | Canonical bencode encode/decode (BEP-3)                     |
| `routing.py`      | Node IDs, XOR distance, k-buckets, compact codecs           |
| `krpc.py`         | KRPC message codec + asyncio UDP transport (BEP-5)          |
| `dht.py`          | DHT node, four queries, passive infohash harvester (BEP-5)  |
| `metadata.py`     | Peer wire + ut_metadata fetch/verify/parse (BEP-3/10/9)     |
| `store.py`        | SQLite store, FTS5 search, blocklist, DHT-state persistence |
| `search.py`       | No-JS server-rendered search UI + JSON API                  |
| `peerstore.py`    | Tracker swarm store with expiry + allow/denylist            |
| `tracker_http.py` | HTTP tracker: `/announce` + `/scrape` (BEP-3/23)            |
| `tracker_udp.py`  | UDP tracker: connect/announce/scrape (BEP-15)               |
| `indexer.py`      | Orchestrator: crawl → queue → fetch → store                 |
| `cli.py`          | `index` / `search` / `tracker` / `stats` / `block`          |

---

## Protocols implemented (and which BEP)

| BEP    | What                                   | Where                              |
|--------|----------------------------------------|------------------------------------|
| BEP-3  | Bencoding                              | `bencode.py`                       |
| BEP-3  | Peer wire handshake + message framing  | `metadata.py`                      |
| BEP-3  | HTTP tracker announce protocol         | `tracker_http.py`                  |
| BEP-5  | DHT (KRPC): ping/find_node/get_peers/announce_peer, k-buckets, XOR routing | `krpc.py`, `routing.py`, `dht.py` |
| BEP-9  | Extension for peers to send metadata (`ut_metadata`) | `metadata.py`        |
| BEP-10 | Extension protocol (extended handshake, `metadata_size`) | `metadata.py`    |
| BEP-15 | UDP tracker protocol                   | `tracker_udp.py`                   |
| BEP-23 | Compact peer lists in tracker replies  | `tracker_http.py`, `tracker_udp.py`|

**Harvesting strategy** (magnetico-style): the node answers standard DHT
queries and records every infohash seen in inbound `get_peers` /
`announce_peer` requests (passive indexing), while a background crawler walks
the DHT with `find_node` toward random targets to widen its routing table and
attract more traffic. `dht.make_neighbor_id()` implements the optional
"neighbours"/Sybil ID-spoofing trick for more aggressive harvesting (off by
default).

---

## Install

Nothing to install. Python 3.11+ with SQLite compiled with FTS5 (standard on
CPython) is all that is required.

```sh
python3 -c "import sqlite3; sqlite3.connect(':memory:').execute('CREATE VIRTUAL TABLE t USING fts5(x)')"  # should not error
```

## Run

```sh
# 1) Harvest the DHT into t.db (needs real internet to find anything)
python3 -m torrentds index   --db t.db --port 6881

# 2) Serve the no-JS search site (http://127.0.0.1:8804) + JSON API
python3 -m torrentds search  --db t.db --port 8804

# 3) Run the trackers (HTTP + UDP). --db feeds blocked infohashes into the denylist
python3 -m torrentds tracker --http-port 8805 --udp-port 6969 --db t.db

# 4) Store statistics
python3 -m torrentds stats   --db t.db

# 5) Blocklist a torrent by infohash or a keyword, and purge existing matches
python3 -m torrentds block   --db t.db --infohash <40-hex>
python3 -m torrentds block   --db t.db --keyword  "some phrase"
```

All servers **bind `127.0.0.1` by default**. Pass `--host 0.0.0.0` to expose
them (see deployment notes). `index --no-bootstrap` runs without contacting the
public routers (used by the loopback tests).

### Search endpoints

* `GET /` and `GET /search?q=<terms>` — server-rendered HTML (no JavaScript)
* `GET /t/<infohash>` — torrent detail (file list + magnet)
* `GET /api/search?q=<terms>&limit=&offset=` — JSON
* `GET /api/stats` — JSON store stats

Results are ranked by FTS5 **bm25** blended with **seen-count** and **size**
signals. Each result includes a `magnet:?xt=urn:btih:<hash>&dn=<name>` link.

---

## Test

No network is used; correctness is proven offline with unit tests and loopback
round-trips.

```sh
cd /tmp/astrx-suite/torrentds
python3 -m unittest discover -s tests -p 'test_*.py' -v
```

What the suite covers:

* **bencode** — round-trip of every type + strict rejection of malformed /
  non-canonical input (leading zeros, `-0`, unsorted/duplicate keys, trailing
  bytes, truncation).
* **routing** — node-id/XOR distance, bucket indexing, k-bucket fill/refresh,
  `find_closest` ordering, compact node/peer codecs.
* **DHT** — KRPC build/parse; a **two/three-node loopback** exchange running
  `ping`, `find_node`, `get_peers`, `announce_peer`, a crawl step that widens
  the routing table, and a protocol-error path.
* **metadata (BEP-9)** — handshake/extended-handshake/ut_metadata encoders,
  piece assembly + SHA-1 verification, and a **full loopback fetch**: a local
  peer serves a multi-piece info-dict, the client fetches, reassembles,
  verifies `sha1==infohash`, and parses it. Corrupt and wrong-infohash peers
  are rejected.
* **tracker HTTP** — loopback `/announce` (compact + dict peers, a second peer
  sees the first), `/scrape`, `stopped` reaping, invalid-infohash failure.
* **tracker UDP** — full loopback `connect → announce → scrape` with the magic
  protocol id, connection-id validation, and compact peers; bad connection-id
  and bad magic are rejected.
* **search** — ingest + ranked FTS search, magnet correctness, file-path
  matching, popularity/size ranking, blocklist (infohash purge + keyword
  block), and an end-to-end HTTP check of the no-JS HTML + JSON API.
* **indexer** — loopback harvest queues an infohash, and the fetch worker
  pulls verified metadata from a local peer into the searchable store.

---

## Deployment (clearnet / Tor)

### Clearnet

The harvester needs a **reachable UDP port** so DHT nodes can send it
`get_peers`/`announce_peer` (that inbound traffic is the harvest source). Open
the `index --port` in your firewall/NAT.

```sh
python3 -m torrentds index  --db t.db --port 6881 --host 0.0.0.0
python3 -m torrentds search --db t.db --port 8804 --host 127.0.0.1   # keep behind a reverse proxy
```

Put the search site behind a TLS-terminating reverse proxy (nginx/caddy) and
leave it bound to `127.0.0.1`. Run components as separate services; they share
only the SQLite file.

### Tor

The search UI is a plain HTTP server and works well as an onion service — no
JavaScript, no external assets, no analytics. Example `torrc`:

```
HiddenServiceDir /var/lib/tor/torrentds/
HiddenServicePort 80 127.0.0.1:8804
```

The DHT harvester itself speaks UDP/KRPC and cannot run over Tor (Tor carries
TCP only); run `index` on a clearnet host or a VPS and publish only the search
UI as an onion service. The trackers are standard HTTP/UDP; only the HTTP
tracker can be fronted by Tor.

---

## Responsibility & blocklist

torrentds indexes and serves **only torrent metadata and magnet links**. It
does not download, store, host, or serve any file content, and it does not
proxy or seed data between peers. The tracker coordinates peer endpoints for
swarms exactly as any BitTorrent tracker does.

Operating a public instance carries legal obligations that vary by
jurisdiction. **Operators are solely responsible for what their instance
indexes and serves and for compliance with the laws that apply to them.** The
blocklist hook exists for that purpose:

* `torrentds block --db t.db --infohash <hex>` — drop a specific torrent and
  purge it from the index.
* `torrentds block --db t.db --keyword "<text>"` — refuse to index (and purge)
  any torrent whose name contains the substring.
* Blocked infohashes passed to `tracker --db t.db` are also loaded into the
  tracker **denylist**, so the tracker refuses to coordinate those swarms.

Blocklist checks run on ingest and are retroactively enforceable with
`purge_blocked()` / the `block` command. `PeerStore.set_allowlist()` supports
running the tracker as a **private/allowlist-only** tracker.

---

## Status & limitations

* **Live DHT is untested here.** The build/CI environment has no network and
  cannot reach the public DHT, so real-world harvesting throughput and NAT
  behaviour are unverified. All protocol logic is proven by offline unit tests
  and `127.0.0.1` loopback round-trips (two local nodes / crafted packets).
* **IPv4 only.** Compact node/peer codecs and both trackers implement the IPv4
  forms (BEP-7 IPv6 tracker extensions are not implemented).
* **No BEP-42 security / no token enforcement for storage.** The node issues
  and echoes `get_peers` tokens but does not store announced peers for DHT
  `get_peers` responses (it is an indexer, not a public DHT peer store); it
  returns closest nodes. It does not implement DHT `announce` peer retention.
* **No µTP / no encryption (MSE/PE).** Metadata fetch uses plain TCP peer wire;
  peers requiring encryption or µTP-only are skipped.
* **Metadata fetch requires a peer address.** Infohashes learned from
  `announce_peer` carry a peer endpoint and are fetched directly; those learned
  from `get_peers` are resolved via a DHT `get_peers` lookup first (which needs
  a populated routing table on a live network).
* **Single-process SQLite.** Uses WAL mode and a shared guarded connection;
  fine for one harvester + one search server + one tracker sharing a file. Not
  designed for large multi-writer clusters.
* **Best-effort harvesting.** No rate-limiting/ban logic, no peer reputation,
  and the passive strategy (plus optional neighbour-ID trick) trades
  completeness for simplicity.

---

## License / intent

Educational and research reference implementation of the BitTorrent DHT,
ut_metadata, and tracker protocols. Use responsibly.
