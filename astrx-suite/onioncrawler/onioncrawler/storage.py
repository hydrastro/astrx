"""Crash-safe crawl state + search index in a single SQLite database (WAL).

All durable state lives here so that killing the process at any point and
restarting continues exactly where it left off:

  frontier   - every URL ever seen + its status (queued|leased|done|error)
  hosts      - per-host state, politeness clock, budgets, trap counters, robots
  pages      - fetched page metadata + content hash
  search_index (FTS5) - rowid == pages.id, title+body full-text
  seen_hashes- global content-dedup set
  host_templates / skeletons - query-explosion + shape backstops
  meta       - global counters (pages stored, urls enqueued)
  trap_log   - human-readable record of why URLs/hosts were dropped

Leasing is atomic (single UPDATE ... RETURNING guarded by BEGIN IMMEDIATE) and
parks the host for the lease duration, which gives both crash-safety (an
in-flight URL is reclaimed on restart once its lease expires) and per-host
serialization/politeness.

A single connection (check_same_thread=False) is shared by all worker threads
under one lock. WAL + committed transactions provide the durability; workers
spend nearly all their time in network I/O, so serializing the short DB ops is
cheap and keeps the logic obviously correct.
"""

from __future__ import annotations

import os
import sqlite3
import threading
import time

from crawlcore.scheduler import backoff_interval

from .lang import guess_lang
from .simhash import simhash64, hamming
from .onion import normalize_host
from .entities import extract as _extract_entities, KINDS as ENTITY_KINDS


SCHEMA = """
CREATE TABLE IF NOT EXISTS frontier(
  id INTEGER PRIMARY KEY,
  url TEXT UNIQUE NOT NULL,
  host TEXT NOT NULL,
  depth INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'queued',
  priority INTEGER NOT NULL DEFAULT 0,
  template TEXT,
  skeleton TEXT,
  enqueued_at REAL,
  lease_expires REAL NOT NULL DEFAULT 0,
  tries INTEGER NOT NULL DEFAULT 0,
  last_error TEXT
);
CREATE INDEX IF NOT EXISTS ix_frontier_pick ON frontier(status, priority, depth, id);
CREATE INDEX IF NOT EXISTS ix_frontier_host ON frontier(host, status);

CREATE TABLE IF NOT EXISTS hosts(
  host TEXT PRIMARY KEY,
  state TEXT NOT NULL DEFAULT 'active',
  next_allowed REAL NOT NULL DEFAULT 0,
  crawl_delay REAL,
  enq_count INTEGER NOT NULL DEFAULT 0,
  pages_count INTEGER NOT NULL DEFAULT 0,
  fetch_count INTEGER NOT NULL DEFAULT 0,
  dup_count INTEGER NOT NULL DEFAULT 0,
  error_count INTEGER NOT NULL DEFAULT 0,
  robots_body TEXT,
  robots_fetched_at REAL,
  robots_present INTEGER NOT NULL DEFAULT 0,
  sitemaps_done INTEGER NOT NULL DEFAULT 0,
  trapped_reason TEXT,
  first_seen REAL,
  last_seen REAL,
  consecutive_failures INTEGER NOT NULL DEFAULT 0,
  last_ok REAL,
  last_down REAL,
  down_recrawls INTEGER NOT NULL DEFAULT 0,
  up INTEGER NOT NULL DEFAULT 1,
  authority REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS pages(
  id INTEGER PRIMARY KEY,
  url TEXT UNIQUE NOT NULL,
  host TEXT NOT NULL,
  title TEXT,
  content_hash TEXT,
  http_status INTEGER,
  content_type TEXT,
  bytes INTEGER,
  fetched_at REAL,
  last_seen REAL,
  etag TEXT,
  last_modified TEXT,
  recrawl_interval REAL,
  lang TEXT,
  simhash INTEGER,
  cluster_id INTEGER
);
CREATE INDEX IF NOT EXISTS ix_pages_host ON pages(host);
CREATE INDEX IF NOT EXISTS ix_pages_hash ON pages(content_hash);
CREATE INDEX IF NOT EXISTS ix_pages_cluster ON pages(cluster_id);

CREATE TABLE IF NOT EXISTS link_edges(
  src_host TEXT NOT NULL,
  dst_host TEXT NOT NULL,
  cnt INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(src_host, dst_host)
);
CREATE INDEX IF NOT EXISTS ix_edges_dst ON link_edges(dst_host);

CREATE TABLE IF NOT EXISTS host_uptime(
  id INTEGER PRIMARY KEY, host TEXT NOT NULL, ts REAL, up INTEGER
);
CREATE INDEX IF NOT EXISTS ix_uptime_host ON host_uptime(host, id);

CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
  title, body, url UNINDEXED, host UNINDEXED, tokenize='unicode61'
);

CREATE TABLE IF NOT EXISTS seen_hashes(
  hash TEXT PRIMARY KEY, url TEXT, host TEXT, first_seen REAL
);

CREATE TABLE IF NOT EXISTS host_templates(
  host TEXT, template TEXT, cnt INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(host, template)
);

CREATE TABLE IF NOT EXISTS skeletons(
  skeleton TEXT PRIMARY KEY, cnt INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT);

CREATE TABLE IF NOT EXISTS trap_log(
  id INTEGER PRIMARY KEY, ts REAL, host TEXT, url TEXT, reason TEXT
);

-- Entity-extraction verticals: PGP keys + crypto addresses per page, so an
-- analyst can pivot from one onion to every other that shares an entity.
CREATE TABLE IF NOT EXISTS entities(
  page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
  kind    TEXT NOT NULL,          -- 'pgp' | 'btc' | 'xmr' | 'eth'
  value   TEXT NOT NULL,
  PRIMARY KEY(page_id, kind, value)
);
CREATE INDEX IF NOT EXISTS ix_entities_kv ON entities(kind, value);
"""


def fts5_available() -> bool:
    try:
        c = sqlite3.connect(":memory:")
        c.execute("CREATE VIRTUAL TABLE _t USING fts5(a)")
        c.close()
        return True
    except sqlite3.OperationalError:
        return False


class Storage:
    def __init__(self, path: str):
        if not fts5_available():
            raise RuntimeError(
                "SQLite FTS5 is not available in this Python build; "
                "the search index requires FTS5."
            )
        self.path = path
        d = os.path.dirname(os.path.abspath(path))
        os.makedirs(d, exist_ok=True)
        self._lock = threading.RLock()
        self.db = sqlite3.connect(path, check_same_thread=False, isolation_level=None)
        self.db.row_factory = sqlite3.Row
        self._init_db()

    def _init_db(self):
        with self._lock:
            self.db.execute("PRAGMA journal_mode=WAL")
            self.db.execute("PRAGMA synchronous=NORMAL")
            self.db.execute("PRAGMA busy_timeout=10000")
            self.db.execute("PRAGMA foreign_keys=ON")
            self.db.executescript(SCHEMA)
            self._migrate()
            # ensure counters exist
            for k in ("pages_stored", "urls_enqueued"):
                self.db.execute(
                    "INSERT OR IGNORE INTO meta(key,value) VALUES(?, '0')", (k,)
                )

    # Columns added after the original schema shipped. CREATE TABLE only runs
    # for a brand-new DB, so an existing crawl.db needs these added in place.
    _ADDED_COLUMNS = {
        "pages": {
            "etag": "TEXT", "last_modified": "TEXT", "recrawl_interval": "REAL",
            "lang": "TEXT", "simhash": "INTEGER", "cluster_id": "INTEGER",
        },
        "hosts": {
            "sitemaps_done": "INTEGER NOT NULL DEFAULT 0",
            "consecutive_failures": "INTEGER NOT NULL DEFAULT 0",
            "last_ok": "REAL", "last_down": "REAL",
            "down_recrawls": "INTEGER NOT NULL DEFAULT 0",
            "up": "INTEGER NOT NULL DEFAULT 1",
            "authority": "REAL NOT NULL DEFAULT 0",
        },
    }

    def _migrate(self):
        for table, cols in self._ADDED_COLUMNS.items():
            have = {r["name"] for r in self.db.execute(
                f"PRAGMA table_info({table})")}
            for col, decl in cols.items():
                if col not in have:
                    self.db.execute(
                        f"ALTER TABLE {table} ADD COLUMN {col} {decl}")

    def close(self):
        with self._lock:
            try:
                self.db.execute("PRAGMA wal_checkpoint(TRUNCATE)")
            except sqlite3.Error:
                pass
            self.db.close()

    def backup_to(self, dest_path: str) -> str:
        """Write a consistent standalone copy of the DB to *dest_path* via
        SQLite ``VACUUM INTO`` (compact, deterministic), falling back to the
        online backup API on an older SQLite. Returns the absolute dest path.
        The source DB stays fully usable throughout."""
        dest_path = os.path.abspath(dest_path)
        d = os.path.dirname(dest_path)
        if d:
            os.makedirs(d, exist_ok=True)
        with self._lock:
            try:
                self.db.execute("PRAGMA wal_checkpoint(TRUNCATE)")
            except sqlite3.Error:
                pass
            try:
                self.db.execute("VACUUM INTO ?", (dest_path,))
            except sqlite3.OperationalError:
                # SQLite < 3.27 has no VACUUM INTO: use the online backup API.
                dst = sqlite3.connect(dest_path)
                try:
                    self.db.backup(dst)
                finally:
                    dst.close()
        return dest_path

    # ------------------------------------------------------------------ meta
    def counter(self, key: str) -> int:
        with self._lock:
            row = self.db.execute("SELECT value FROM meta WHERE key=?", (key,)).fetchone()
            return int(row["value"]) if row else 0

    def _incr(self, key: str, delta: int = 1):
        self.db.execute(
            "INSERT INTO meta(key,value) VALUES(?, ?) "
            "ON CONFLICT(key) DO UPDATE SET value=CAST(value AS INTEGER)+?",
            (key, str(delta), delta),
        )

    # --------------------------------------------------------------- hosts
    def ensure_host(self, host: str, now: float | None = None):
        now = now if now is not None else time.time()
        self.db.execute(
            "INSERT OR IGNORE INTO hosts(host, first_seen, last_seen) VALUES(?,?,?)",
            (host, now, now),
        )

    def get_host(self, host: str):
        with self._lock:
            return self.db.execute("SELECT * FROM hosts WHERE host=?", (host,)).fetchone()

    def set_host_state(self, host: str, state: str, reason: str | None = None):
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                self.db.execute(
                    "UPDATE hosts SET state=?, trapped_reason=? WHERE host=?",
                    (state, reason, host),
                )
                if state in ("trapped", "blocked"):
                    # dead-letter this host's still-queued URLs so the crawl can
                    # terminate instead of waiting on a host it will never fetch.
                    self.db.execute(
                        "UPDATE frontier SET status='error', last_error=? "
                        "WHERE host=? AND status='queued'",
                        (f"host-{state}:{reason}", host),
                    )
                self.db.execute("COMMIT")
            except Exception:
                self.db.execute("ROLLBACK")
                raise

    def set_next_allowed(self, host: str, when: float):
        with self._lock:
            self.db.execute("UPDATE hosts SET next_allowed=? WHERE host=?", (when, host))

    def set_host_crawl_delay(self, host: str, delay: float | None):
        with self._lock:
            self.db.execute("UPDATE hosts SET crawl_delay=? WHERE host=?", (delay, host))

    def save_robots(self, host: str, body: str | None, present: bool, now: float,
                    crawl_delay: float | None):
        with self._lock:
            self.db.execute(
                "UPDATE hosts SET robots_body=?, robots_present=?, "
                "robots_fetched_at=?, crawl_delay=? WHERE host=?",
                (body, 1 if present else 0, now, crawl_delay, host),
            )

    def mark_sitemaps_done(self, host: str):
        with self._lock:
            self.db.execute(
                "UPDATE hosts SET sitemaps_done=1 WHERE host=?", (host,))

    def host_counter_bump(self, host: str, field: str, delta: int = 1):
        assert field in ("fetch_count", "dup_count", "error_count", "pages_count")
        with self._lock:
            self.db.execute(
                f"UPDATE hosts SET {field}={field}+?, last_seen=? WHERE host=?",
                (delta, time.time(), host),
            )

    # ------------------------------------------------------------- frontier
    def add_seed(self, canon, depth: int = 0, priority: int = 0,
                 now: float | None = None, caps: dict | None = None,
                 force: bool = True) -> str:
        """Enqueue a seed. A deliberate (re)seed revives a host previously
        demoted to 'dead' so an operator can un-age it by resubmitting
        (trapped/blocked are NOT revived here, and enqueue never creates a
        frontier row on a still-inactive host — such a row could never be leased
        and would stall crawl termination).

        *force* (the default) is the trusted operator/authed path: it bypasses
        the per-host / template / skeleton / unique-URL trap caps. Untrusted
        (public) submissions pass force=False + *caps* so they can never grow
        the frontier past the configured backstops.
        """
        with self._lock:
            self.db.execute(
                "UPDATE hosts SET state='active', up=1, down_recrawls=0, "
                "consecutive_failures=0 WHERE host=? AND state='dead'",
                (canon.host,))
        return self.enqueue(canon, depth=depth, priority=priority, caps=caps,
                            now=now, force=force)

    def reseed_url(self, canon, caps: dict | None = None, force: bool = True,
                   now: float | None = None) -> str:
        """Re-enqueue a curated seed root (scheduled/known-onions reseed).

        Revives a 'dead' host (operator un-age), then:
          * if the URL already exists on an ACTIVE host, flip it back to
            'queued' so the root is recrawled -> returns 'requeued'; this is
            idempotent and never grows the frontier (respects recrawl);
          * otherwise enqueue it fresh (respecting the trap caps unless force).

        A trapped/blocked host is never revived and never receives a queued row
        (it could not be leased and would stall termination) -> 'host-dead'.
        """
        now = now if now is not None else time.time()
        with self._lock:
            # revive a dead host to active (same trusted un-age path as add_seed)
            self.db.execute(
                "UPDATE hosts SET state='active', up=1, down_recrawls=0, "
                "consecutive_failures=0 WHERE host=? AND state='dead'",
                (canon.host,))
            hostrow = self.db.execute(
                "SELECT state FROM hosts WHERE host=?", (canon.host,)).fetchone()
            existing = self.db.execute(
                "SELECT status FROM frontier WHERE url=?", (canon.url,)).fetchone()
            if existing is not None:
                # inactive host (trapped/blocked) -> leave the row, refuse reseed
                if hostrow is not None and hostrow["state"] != "active":
                    return "host-dead"
                # only requeue a settled row; never disturb a leased/queued one
                self.db.execute(
                    "UPDATE frontier SET status='queued', lease_expires=0 "
                    "WHERE url=? AND status IN ('done','error')", (canon.url,))
                return "requeued"
        # brand-new curated root: enqueue via the normal capped/forced path
        return self.enqueue(canon, depth=0, priority=0, caps=caps,
                            now=now, force=force)

    def enqueue(self, canon, depth: int, priority: int, caps: dict | None = None,
                now: float | None = None, force: bool = False) -> str:
        """Try to add canon (a CanonicalUrl) to the frontier.

        Returns 'ok' or a reason code: 'dup-url', 'unique-budget',
        'template-cap', 'skeleton-cap', 'host-budget', 'host-dead'.
        Stateful trap caps are enforced here so trap URLs never enter the
        frontier. Structural (pure) checks are done by the caller first.
        """
        now = now if now is not None else time.time()
        caps = caps or {}
        url = canon.url
        host = canon.host
        template = canon.template_key()
        skeleton = canon.skeleton_key()
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                # already known? (any status) -> no duplicate work
                existing = self.db.execute(
                    "SELECT status FROM frontier WHERE url=?", (url,)
                ).fetchone()
                if existing is not None:
                    self.db.execute("COMMIT")
                    return "dup-url"

                # Global unique-URL budget (backstop, trap #9). Checked BEFORE we
                # create a host row so an untrusted flood of distinct hosts/paths
                # cannot grow either the frontier OR the hosts table past the cap.
                if not force:
                    mub = caps.get("max_unique_urls")
                    if mub and self.counter("urls_enqueued") >= mub:
                        self.db.execute("COMMIT")
                        return "unique-budget"

                self.db.execute(
                    "INSERT OR IGNORE INTO hosts(host, first_seen, last_seen) "
                    "VALUES(?,?,?)", (host, now, now),
                )
                hostrow = self.db.execute(
                    "SELECT state, enq_count FROM hosts WHERE host=?", (host,)
                ).fetchone()

                # An inactive host (trapped/blocked/dead) never gets a new
                # frontier row, even for a trusted/forced seed: such a row could
                # never be leased (lease requires state='active') and would sit
                # 'queued' forever, blocking crawl termination. add_seed() revives
                # a 'dead' host to 'active' BEFORE this call, so a still-inactive
                # state here means the caller must not enqueue.
                if hostrow["state"] in ("trapped", "blocked", "dead"):
                    self.db.execute("COMMIT")
                    return "host-dead"

                if not force:
                    # per-host page budget (trap #3): counts urls admitted
                    mph = caps.get("max_pages_per_host")
                    if mph and hostrow["enq_count"] >= mph:
                        self.db.execute("COMMIT")
                        return "host-budget"
                    # query-explosion template cap (trap #5)
                    mpt = caps.get("max_urls_per_template")
                    if mpt:
                        tc = self.db.execute(
                            "SELECT cnt FROM host_templates WHERE host=? AND template=?",
                            (host, template),
                        ).fetchone()
                        if tc and tc["cnt"] >= mpt:
                            self.db.execute("COMMIT")
                            return "template-cap"
                    # skeleton cap (trap #9 shape backstop)
                    mps = caps.get("max_urls_per_skeleton")
                    if mps:
                        sc = self.db.execute(
                            "SELECT cnt FROM skeletons WHERE skeleton=?", (skeleton,)
                        ).fetchone()
                        if sc and sc["cnt"] >= mps:
                            self.db.execute("COMMIT")
                            return "skeleton-cap"

                self.db.execute(
                    "INSERT INTO frontier(url,host,depth,status,priority,template,"
                    "skeleton,enqueued_at,lease_expires,tries) "
                    "VALUES(?,?,?,'queued',?,?,?,?,0,0)",
                    (url, host, depth, priority, template, skeleton, now),
                )
                self.db.execute(
                    "UPDATE hosts SET enq_count=enq_count+1 WHERE host=?", (host,)
                )
                self.db.execute(
                    "INSERT INTO host_templates(host,template,cnt) VALUES(?,?,1) "
                    "ON CONFLICT(host,template) DO UPDATE SET cnt=cnt+1",
                    (host, template),
                )
                self.db.execute(
                    "INSERT INTO skeletons(skeleton,cnt) VALUES(?,1) "
                    "ON CONFLICT(skeleton) DO UPDATE SET cnt=cnt+1",
                    (skeleton,),
                )
                self._incr("urls_enqueued", 1)
                self.db.execute("COMMIT")
                return "ok"
            except Exception:
                self.db.execute("ROLLBACK")
                raise

    def reclaim_expired(self, now: float | None = None) -> int:
        now = now if now is not None else time.time()
        with self._lock:
            cur = self.db.execute(
                "UPDATE frontier SET status='queued', lease_expires=0 "
                "WHERE status='leased' AND lease_expires < ?",
                (now,),
            )
            return cur.rowcount

    def reclaim_all_leased(self) -> int:
        """Graceful-shutdown reclaim: return every leased row to the queue so a
        restart re-crawls it. (The crash path relies on reclaim_expired.)"""
        with self._lock:
            cur = self.db.execute(
                "UPDATE frontier SET status='queued', lease_expires=0 "
                "WHERE status='leased'"
            )
            return cur.rowcount

    def lease(self, now: float | None = None, lease_ttl: float = 300.0):
        """Atomically lease the best eligible queued URL. Returns a dict or
        None. Reclaims expired leases first and parks the chosen host until the
        lease expires (per-host serialization)."""
        now = now if now is not None else time.time()
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                self.db.execute(
                    "UPDATE frontier SET status='queued', lease_expires=0 "
                    "WHERE status='leased' AND lease_expires < ?",
                    (now,),
                )
                row = self.db.execute(
                    "UPDATE frontier SET status='leased', lease_expires=?, "
                    "tries=tries+1 WHERE id=(SELECT f.id FROM frontier f "
                    "JOIN hosts h ON h.host=f.host WHERE f.status='queued' "
                    "AND h.state='active' AND h.next_allowed<=? "
                    "ORDER BY f.priority ASC, f.depth ASC, f.id ASC LIMIT 1) "
                    "RETURNING id, url, host, depth, template, skeleton, tries",
                    (now + lease_ttl, now),
                ).fetchone()
                if row is not None:
                    # park the host for the lease window
                    self.db.execute(
                        "UPDATE hosts SET next_allowed=? WHERE host=?",
                        (now + lease_ttl, row["host"]),
                    )
                self.db.execute("COMMIT")
            except Exception:
                self.db.execute("ROLLBACK")
                raise
            if row is None:
                return None
            return dict(row)

    def mark_done(self, frontier_id: int):
        with self._lock:
            self.db.execute(
                "UPDATE frontier SET status='done', lease_expires=0 WHERE id=?",
                (frontier_id,),
            )

    def mark_error(self, frontier_id: int, reason: str):
        with self._lock:
            self.db.execute(
                "UPDATE frontier SET status='error', last_error=?, lease_expires=0 "
                "WHERE id=?", (reason[:500], frontier_id),
            )

    def pending_summary(self, now: float | None = None):
        """(queued_count, leased_active_count) for termination decisions."""
        now = now if now is not None else time.time()
        with self._lock:
            q = self.db.execute(
                "SELECT count(*) c FROM frontier WHERE status='queued'"
            ).fetchone()["c"]
            l = self.db.execute(
                "SELECT count(*) c FROM frontier WHERE status='leased' "
                "AND lease_expires > ?", (now,),
            ).fetchone()["c"]
            return q, l

    # ---------------------------------------------------------------- pages
    def _index_entities(self, pid, text):
        """Replace the entity rows for page *pid*.  Runs inside the caller's
        store_page transaction + lock, so it takes neither itself."""
        self.db.execute("DELETE FROM entities WHERE page_id=?", (pid,))
        ents = _extract_entities(text or "")
        if ents:
            self.db.executemany(
                "INSERT OR IGNORE INTO entities(page_id,kind,value) VALUES(?,?,?)",
                [(pid, k, v) for k, v in ents])

    def find_by_entity(self, kind, value, limit=50, offset=0):
        """Pages containing the entity (*kind*, *value*), newest first."""
        with self._lock:
            rows = self.db.execute(
                "SELECT p.url AS url, p.host AS host, p.title AS title, "
                "p.last_seen AS last_seen FROM entities e "
                "JOIN pages p ON p.id=e.page_id "
                "WHERE e.kind=? AND e.value=? ORDER BY p.last_seen DESC "
                "LIMIT ? OFFSET ?",
                (kind, value, max(0, int(limit)), max(0, int(offset)))
            ).fetchall()
        return [dict(r) for r in rows]

    def entities_for_page(self, pid):
        """The (kind, value) entities on one page (for the detail view)."""
        with self._lock:
            rows = self.db.execute(
                "SELECT kind, value FROM entities WHERE page_id=? ORDER BY kind",
                (pid,)).fetchall()
        return [(r["kind"], r["value"]) for r in rows]

    def entity_counts(self):
        """Distinct entity count per kind (for /stats)."""
        with self._lock:
            rows = self.db.execute(
                "SELECT kind, COUNT(DISTINCT value) AS n FROM entities "
                "GROUP BY kind").fetchall()
        return {r["kind"]: r["n"] for r in rows}

    def store_page(self, url, host, title, text, content_hash, http_status,
                   content_type, nbytes, now, dedup=True, etag=None,
                   last_modified=None, interval=None):
        """Insert / update a page + FTS row. Returns 'stored'|'updated'|
        'duplicate'.

        Language guess and a 64-bit SimHash fingerprint are computed here from
        title+text so every stored page carries them (used by the language
        facet and near-duplicate collapsing). etag/last_modified/interval feed
        conditional-GET freshness + the recrawl scheduler.
        """
        lang = guess_lang(text or title or "")
        shash = simhash64(((title or "") + "\n" + (text or "")))
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                existing = self.db.execute(
                    "SELECT id, content_hash FROM pages WHERE url=?", (url,)
                ).fetchone()
                if existing is not None:
                    pid = existing["id"]
                    changed = existing["content_hash"] != content_hash
                    self.db.execute(
                        "UPDATE pages SET title=?, content_hash=?, http_status=?, "
                        "content_type=?, bytes=?, fetched_at=?, last_seen=?, "
                        "etag=?, last_modified=?, lang=?, simhash=?, cluster_id=NULL"
                        + (", recrawl_interval=?" if interval is not None else "")
                        + " WHERE id=?",
                        (title, content_hash, http_status, content_type, nbytes,
                         now, now, etag, last_modified, lang, shash)
                        + ((interval,) if interval is not None else ())
                        + (pid,),
                    )
                    self.db.execute("DELETE FROM search_index WHERE rowid=?", (pid,))
                    self.db.execute(
                        "INSERT INTO search_index(rowid,title,body,url,host) "
                        "VALUES(?,?,?,?,?)", (pid, title, text, url, host),
                    )
                    self._index_entities(pid, text)
                    self.db.execute("COMMIT")
                    return "updated" if changed else "unchanged"

                if dedup and content_hash:
                    dup = self.db.execute(
                        "SELECT url FROM seen_hashes WHERE hash=?", (content_hash,)
                    ).fetchone()
                    if dup is not None:
                        self.db.execute(
                            "UPDATE hosts SET dup_count=dup_count+1 WHERE host=?",
                            (host,),
                        )
                        self.db.execute("COMMIT")
                        return "duplicate"

                cur = self.db.execute(
                    "INSERT INTO pages(url,host,title,content_hash,http_status,"
                    "content_type,bytes,fetched_at,last_seen,etag,last_modified,"
                    "recrawl_interval,lang,simhash) "
                    "VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    (url, host, title, content_hash, http_status, content_type,
                     nbytes, now, now, etag, last_modified, interval, lang, shash),
                )
                pid = cur.lastrowid
                self.db.execute(
                    "INSERT INTO search_index(rowid,title,body,url,host) "
                    "VALUES(?,?,?,?,?)", (pid, title, text, url, host),
                )
                self._index_entities(pid, text)
                if content_hash:
                    self.db.execute(
                        "INSERT OR IGNORE INTO seen_hashes(hash,url,host,first_seen) "
                        "VALUES(?,?,?,?)", (content_hash, url, host, now),
                    )
                self.db.execute(
                    "UPDATE hosts SET pages_count=pages_count+1 WHERE host=?", (host,)
                )
                self._incr("pages_stored", 1)
                self.db.execute("COMMIT")
                return "stored"
            except Exception:
                self.db.execute("ROLLBACK")
                raise

    def touch_page(self, url, now, grow_interval=None, max_interval=None,
                   base_interval=None):
        """Record that a page was re-seen unchanged (a 304 or same content-hash):
        bump last_seen and, if *grow_interval* is given, back the recrawl
        interval off multiplicatively (capped at *max_interval*). Falls back to
        *base_interval* when the page has no interval yet. No re-index."""
        with self._lock:
            row = self.db.execute(
                "SELECT recrawl_interval FROM pages WHERE url=?", (url,)
            ).fetchone()
            if row is None:
                return
            if grow_interval:
                # multiplicative back-off (shared pure arithmetic), capped
                nxt = backoff_interval(
                    row["recrawl_interval"], grow_interval,
                    max_interval=max_interval, base=base_interval)
                if nxt:
                    self.db.execute(
                        "UPDATE pages SET last_seen=?, recrawl_interval=? WHERE url=?",
                        (now, nxt, url))
                    return
            self.db.execute("UPDATE pages SET last_seen=? WHERE url=?", (now, url))

    def get_page(self, url: str):
        with self._lock:
            return self.db.execute("SELECT * FROM pages WHERE url=?", (url,)).fetchone()

    def get_page_snapshot(self, url: str):
        """Title + indexed body text for *url* — a read-only cached snapshot,
        useful when the live onion is offline. ``None`` if not indexed."""
        with self._lock:
            row = self.db.execute(
                "SELECT p.url AS url, p.host AS host, p.title AS title, "
                "p.fetched_at AS fetched_at, s.body AS body "
                "FROM pages p JOIN search_index s ON s.rowid = p.id "
                "WHERE p.url=?", (url,)).fetchone()
        return dict(row) if row else None

    def reap_unverified(self, ttl: float, now: float | None = None) -> int:
        """Expire never-attempted queued URLs older than *ttl* seconds.

        A public submission (or a discovered seed) that has sat ``queued`` with
        ``tries=0`` past the TTL was never reachable, so it is dropped — this is
        the submission-funnel TTL that stops a dead onion someone submitted from
        lingering in the frontier forever. Returns the number removed."""
        now = now if now is not None else time.time()
        cutoff = now - float(ttl)
        with self._lock:
            cur = self.db.execute(
                "DELETE FROM frontier WHERE status='queued' AND tries=0 "
                "AND enqueued_at IS NOT NULL AND enqueued_at < ?", (cutoff,))
            return cur.rowcount

    def requeue_stale(self, ttl: float, now: float | None = None) -> int:
        """Reset 'done' frontier rows whose page is older than ttl to 'queued'
        (recrawl). Returns count. Kept for back-compat; mark_recrawl_due is the
        per-page-interval-aware scheduler the crawler now uses."""
        now = now if now is not None else time.time()
        cutoff = now - ttl
        with self._lock:
            cur = self.db.execute(
                "UPDATE frontier SET status='queued', lease_expires=0 "
                "WHERE status='done' AND url IN "
                "(SELECT url FROM pages WHERE fetched_at < ?)", (cutoff,),
            )
            return cur.rowcount

    def mark_recrawl_due(self, now: float | None = None,
                         default_interval: float = 0.0) -> int:
        """Recrawl scheduler: requeue every 'done' frontier row whose page is due
        (fetched_at + per-page recrawl_interval <= now). Pages with no stored
        interval fall back to *default_interval*. The requeued rows re-enter the
        normal lease path, so per-host politeness is preserved automatically.
        Returns the number of pages made due."""
        now = now if now is not None else time.time()
        with self._lock:
            cur = self.db.execute(
                "UPDATE frontier SET status='queued', lease_expires=0 "
                "WHERE status='done' "
                # never requeue onto an inactive host (dead/trapped/blocked) -
                # those rows would sit queued forever and stall termination.
                "AND host IN (SELECT host FROM hosts WHERE state='active') "
                "AND url IN ("
                "  SELECT url FROM pages "
                "  WHERE fetched_at + COALESCE(recrawl_interval, ?) <= ?)",
                (default_interval, now),
            )
            return cur.rowcount

    # ------------------------------------------------------------- liveness
    def record_fetch_up(self, host: str, now: float | None = None) -> bool:
        """A successful (or 304) fetch: clear the failure streak, mark the host
        up, reset dead-onion aging, and revive a host previously demoted to
        'dead'. Returns True if this was a down->up transition."""
        now = now if now is not None else time.time()
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                row = self.db.execute(
                    "SELECT up, state FROM hosts WHERE host=?", (host,)).fetchone()
                was_down = row is not None and row["up"] == 0
                self.db.execute(
                    "UPDATE hosts SET consecutive_failures=0, last_ok=?, up=1, "
                    "down_recrawls=0, last_seen=?, "
                    "state=CASE WHEN state='dead' THEN 'active' ELSE state END "
                    "WHERE host=?", (now, now, host))
                if was_down:
                    self.db.execute(
                        "INSERT INTO host_uptime(host,ts,up) VALUES(?,?,1)",
                        (host, now))
                self.db.execute("COMMIT")
                return was_down
            except Exception:
                self.db.execute("ROLLBACK")
                raise

    def record_fetch_down(self, host: str, now: float | None = None,
                          threshold: int = 3) -> bool:
        """A failed page fetch: bump the consecutive-failure counter and, once it
        reaches *threshold*, flip the host to down and log the transition.
        Returns True if this call caused an up->down transition."""
        now = now if now is not None else time.time()
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                row = self.db.execute(
                    "SELECT up, consecutive_failures FROM hosts WHERE host=?",
                    (host,)).fetchone()
                if row is None:
                    self.db.execute("COMMIT")
                    return False
                cf = (row["consecutive_failures"] or 0) + 1
                went_down = (row["up"] == 1 and cf >= threshold)
                new_up = 0 if (row["up"] == 0 or went_down) else 1
                self.db.execute(
                    "UPDATE hosts SET consecutive_failures=?, last_down=?, up=? "
                    "WHERE host=?", (cf, now, new_up, host))
                if went_down:
                    self.db.execute(
                        "INSERT INTO host_uptime(host,ts,up) VALUES(?,?,0)",
                        (host, now))
                self.db.execute("COMMIT")
                return went_down
            except Exception:
                self.db.execute("ROLLBACK")
                raise

    def age_dead_hosts(self, threshold: int = 5, now: float | None = None) -> int:
        """One dead-onion aging cycle. Each still-active host that is currently
        down accrues one down-recrawl; any that have now been down across
        >= *threshold* cycles are demoted to 'dead' (hidden from search, never
        leased, never deleted). Returns the count newly demoted."""
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                self.db.execute(
                    "UPDATE hosts SET down_recrawls=down_recrawls+1 "
                    "WHERE up=0 AND state='active'")
                newly = [r["host"] for r in self.db.execute(
                    "SELECT host FROM hosts WHERE up=0 AND state='active' "
                    "AND down_recrawls>=?", (threshold,))]
                for h in newly:
                    self.db.execute(
                        "UPDATE hosts SET state='dead', trapped_reason='dead-onion' "
                        "WHERE host=?", (h,))
                    # dead-letter still-queued URLs so a frontier of only-dead
                    # hosts can't stall termination (dead hosts never lease).
                    self.db.execute(
                        "UPDATE frontier SET status='error', last_error='host-dead' "
                        "WHERE host=? AND status='queued'", (h,))
                self.db.execute("COMMIT")
                return len(newly)
            except Exception:
                self.db.execute("ROLLBACK")
                raise

    def uptime_history(self, host: str, limit: int = 50):
        with self._lock:
            return [dict(r) for r in self.db.execute(
                "SELECT ts, up FROM host_uptime WHERE host=? ORDER BY id DESC "
                "LIMIT ?", (host, limit))]

    # ----------------------------------------------------------- link graph
    def add_link_edge(self, src_host: str, dst_host: str, delta: int = 1):
        """Persist one inter-onion link edge (src -> dst). Self-links ignored."""
        if not src_host or not dst_host or src_host == dst_host:
            return
        with self._lock:
            self.db.execute(
                "INSERT INTO link_edges(src_host,dst_host,cnt) VALUES(?,?,?) "
                "ON CONFLICT(src_host,dst_host) DO UPDATE SET cnt=cnt+?",
                (src_host, dst_host, delta, delta))

    def compute_authority(self, iterations: int = 20, damping: float = 0.85,
                          max_edges: int = 5_000_000) -> int:
        """Offline PageRank-lite over the onion host link graph. Writes a
        normalized (max=1.0) authority score to hosts.authority. Returns the
        number of hosts scored.

        The edge set is loaded with a hard *max_edges* LIMIT so a pathologically
        large link_edges table can never force an unbounded in-memory load (the
        per-page link cap already keeps the table bounded during crawling)."""
        with self._lock:
            hosts = [r["host"] for r in self.db.execute("SELECT host FROM hosts")]
            edges = self.db.execute(
                "SELECT src_host, dst_host, cnt FROM link_edges LIMIT ?",
                (max_edges,)).fetchall()
        n = len(hosts)
        if n == 0:
            return 0
        hostset = set(hosts)
        out_edges: dict[str, list[tuple[str, int]]] = {}
        outsum: dict[str, int] = {}
        for e in edges:
            s, d, c = e["src_host"], e["dst_host"], e["cnt"]
            if s not in hostset or d not in hostset or s == d:
                continue
            out_edges.setdefault(s, []).append((d, c))
            outsum[s] = outsum.get(s, 0) + c
        rank = {h: 1.0 / n for h in hosts}
        base = (1.0 - damping) / n
        for _ in range(max(1, iterations)):
            dangling = sum(rank[h] for h in hosts if outsum.get(h, 0) == 0)
            newrank = {h: base + damping * dangling / n for h in hosts}
            for s, targets in out_edges.items():
                share = damping * rank[s] / outsum[s]
                for (d, c) in targets:
                    newrank[d] += share * c
            rank = newrank
        mx = max(rank.values()) if rank else 1.0
        if mx <= 0:
            mx = 1.0
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                for h, r in rank.items():
                    self.db.execute(
                        "UPDATE hosts SET authority=? WHERE host=?", (r / mx, h))
                self.db.execute("COMMIT")
            except Exception:
                self.db.execute("ROLLBACK")
                raise
        return n

    # ------------------------------------------------------- mirror clusters
    def cluster_mirrors(self, threshold: int = 3, max_pages: int = 20000) -> int:
        """Offline near-duplicate clustering via SimHash. Assigns every scanned
        page a cluster_id (the smallest page id in its near-dup group); a page
        with no near-dup is its own singleton cluster. Returns the number of
        multi-page (mirror) clusters found. O(n^2) over the scanned window, so
        it is bounded by *max_pages*."""
        with self._lock:
            rows = self.db.execute(
                "SELECT id, simhash FROM pages WHERE simhash IS NOT NULL "
                "ORDER BY id LIMIT ?", (max_pages,)).fetchall()
        ids = [r["id"] for r in rows]
        sh = [r["simhash"] for r in rows]
        parent = {i: i for i in ids}

        def find(x):
            while parent[x] != x:
                parent[x] = parent[parent[x]]
                x = parent[x]
            return x

        def union(a, b):
            ra, rb = find(a), find(b)
            if ra != rb:
                parent[max(ra, rb)] = min(ra, rb)  # keep smallest id as root

        m = len(ids)
        for i in range(m):
            if not sh[i]:
                continue
            for j in range(i + 1, m):
                if sh[j] and hamming(sh[i], sh[j]) <= threshold:
                    union(ids[i], ids[j])
        groups: dict[int, list[int]] = {}
        for i in ids:
            groups.setdefault(find(i), []).append(i)
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                for root, members in groups.items():
                    for pid in members:
                        self.db.execute(
                            "UPDATE pages SET cluster_id=? WHERE id=?", (root, pid))
                self.db.execute("COMMIT")
            except Exception:
                self.db.execute("ROLLBACK")
                raise
        return sum(1 for g in groups.values() if len(g) > 1)

    # ---------------------------------------------------------------- traps
    def log_trap(self, host: str, url: str, reason: str):
        with self._lock:
            self.db.execute(
                "INSERT INTO trap_log(ts,host,url,reason) VALUES(?,?,?,?)",
                (time.time(), host, url, reason),
            )

    # --------------------------------------------------------------- search
    # Hidden host states never appear in results (defense-in-depth; blocked
    # pages are also never stored, dead hosts are demoted by aging).
    _HIDDEN_STATES = ("blocked", "dead")

    def _search_where(self, match, host=None, since=None, until=None, lang=None):
        """Build the parameterized WHERE for a search. Every user value is bound,
        never interpolated."""
        placeholders = ",".join("?" for _ in self._HIDDEN_STATES)
        where = [f"search_index MATCH ?", f"h.state NOT IN ({placeholders})"]
        params = [match, *self._HIDDEN_STATES]
        if host:
            where.append("p.host = ?")
            params.append(normalize_host(host))
        if since is not None:
            where.append("p.last_seen >= ?")
            params.append(float(since))
        if until is not None:
            where.append("p.last_seen <= ?")
            params.append(float(until))
        if lang:
            where.append("p.lang = ?")
            params.append(str(lang))
        return " AND ".join(where), params

    def search(self, query: str, limit: int = 10, offset: int = 0, host=None,
               since=None, until=None, lang=None, authority_weight: float = 0.0,
               collapse: bool = False, simhash_threshold: int = 3):
        """Ranked FTS search. Returns (results, total).

        * total is the raw number of matching pages (before collapsing).
        * authority_weight>0 blends host PageRank into the bm25 ordering.
        * collapse=True drops near-duplicate/mirror pages from the result window,
          keeping the best-ranked representative of each SimHash cluster.
        Filters (host/since/until/lang) are all parameterized.
        """
        match = _fts_query(query)
        if not match:
            return [], 0
        wsql, params = self._search_where(match, host, since, until, lang)
        rank_expr = "bm25(search_index, 10.0, 1.0)"
        if authority_weight:
            rank_expr = f"({rank_expr}) - ? * h.authority"
        with self._lock:
            total = self.db.execute(
                "SELECT count(*) c FROM search_index "
                "JOIN pages p ON p.id=search_index.rowid "
                "JOIN hosts h ON h.host=p.host "
                f"WHERE {wsql}", params,
            ).fetchone()["c"]

            # The authority-weight placeholder lives in the SELECT (rank_expr),
            # which precedes the WHERE in the SQL text, so it must be bound FIRST.
            rank_params = [float(authority_weight)] if authority_weight else []

            if not collapse:
                rows = self.db.execute(
                    "SELECT p.url AS url, p.title AS title, p.host AS host, "
                    "p.fetched_at AS fetched_at, p.last_seen AS last_seen, "
                    "p.lang AS lang, "
                    "snippet(search_index, 1, '<mark>', '</mark>', '…', 14) AS snippet, "
                    f"{rank_expr} AS rank "
                    "FROM search_index JOIN pages p ON p.id=search_index.rowid "
                    "JOIN hosts h ON h.host=p.host "
                    f"WHERE {wsql} ORDER BY rank ASC LIMIT ? OFFSET ?",
                    rank_params + params + [limit, offset],
                ).fetchall()
                return [dict(r) for r in rows], total

            # Collapse path: pull a bounded ranked candidate window, drop near
            # duplicates in rank order (cluster_id when known, else SimHash
            # Hamming distance), then apply offset/limit to the survivors.
            cap = min(1000, offset + limit * 4 + 20)
            rows = self.db.execute(
                "SELECT p.url AS url, p.title AS title, p.host AS host, "
                "p.fetched_at AS fetched_at, p.last_seen AS last_seen, "
                "p.lang AS lang, p.simhash AS simhash, p.cluster_id AS cluster_id, "
                "snippet(search_index, 1, '<mark>', '</mark>', '…', 14) AS snippet, "
                f"{rank_expr} AS rank "
                "FROM search_index JOIN pages p ON p.id=search_index.rowid "
                "JOIN hosts h ON h.host=p.host "
                f"WHERE {wsql} ORDER BY rank ASC LIMIT ?",
                rank_params + params + [cap],
            ).fetchall()
        kept = []
        kept_sig = []  # (cluster_id, simhash) of already-kept reps
        for r in rows:
            d = dict(r)
            cid, sh = d.get("cluster_id"), d.get("simhash")
            dup = False
            for (kc, ksh) in kept_sig:
                if cid is not None and kc is not None and cid == kc:
                    dup = True
                    break
                if sh and ksh and hamming(sh, ksh) <= simhash_threshold:
                    dup = True
                    break
            if dup:
                continue
            kept_sig.append((cid, sh))
            d.pop("simhash", None)
            d.pop("cluster_id", None)
            kept.append(d)
        return kept[offset:offset + limit], total

    def search_facets(self, query: str, host=None, since=None, until=None,
                      lang=None, top: int = 10):
        """Facet counts for a query: top hosts and languages among matches, plus
        the overall match total. Used by the no-JS UI and the JSON API."""
        match = _fts_query(query)
        if not match:
            return {"total": 0, "hosts": [], "langs": []}
        wsql, params = self._search_where(match, host, since, until, lang)
        base = ("FROM search_index JOIN pages p ON p.id=search_index.rowid "
                "JOIN hosts h ON h.host=p.host WHERE " + wsql)
        with self._lock:
            total = self.db.execute(
                "SELECT count(*) c " + base, params).fetchone()["c"]
            hosts = [dict(r) for r in self.db.execute(
                "SELECT p.host AS host, count(*) AS n " + base +
                " GROUP BY p.host ORDER BY n DESC LIMIT ?", params + [top])]
            langs = [dict(r) for r in self.db.execute(
                "SELECT COALESCE(p.lang,'un') AS lang, count(*) AS n " + base +
                " GROUP BY p.lang ORDER BY n DESC LIMIT ?", params + [top])]
        return {"total": total, "hosts": hosts, "langs": langs}

    def purge_host(self, host: str) -> dict:
        """Admin action: block a host and delete its indexed pages (both `pages`
        and the FTS index). Never touches other hosts. Returns counts."""
        host = normalize_host(host)
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                ids = [r["id"] for r in self.db.execute(
                    "SELECT id FROM pages WHERE host=?", (host,))]
                for pid in ids:
                    self.db.execute(
                        "DELETE FROM search_index WHERE rowid=?", (pid,))
                    self.db.execute("DELETE FROM pages WHERE id=?", (pid,))
                self.db.execute(
                    "INSERT INTO hosts(host, state, trapped_reason) "
                    "VALUES(?, 'blocked', 'admin-purge') "
                    "ON CONFLICT(host) DO UPDATE SET state='blocked', "
                    "trapped_reason='admin-purge'", (host,))
                self.db.execute(
                    "UPDATE frontier SET status='error', "
                    "last_error='host-blocked:admin-purge' "
                    "WHERE host=? AND status IN ('queued','leased')", (host,))
                self.db.execute("COMMIT")
            except Exception:
                self.db.execute("ROLLBACK")
                raise
        return {"host": host, "pages_removed": len(ids)}

    # --------------------------------------------------------------- abuse
    def apply_abuse_blocklist(self, abuse) -> dict:
        """Reconcile the stored index against the *current* abuse filter so that
        adding a host/keyword AFTER pages were indexed removes them from search.

        1. Every blocklisted host is marked state='blocked' (the search query
           excludes those) and its still-queued frontier rows are dead-lettered.
        2. Every already-indexed page whose title/body now matches a keyword is
           deleted from both `pages` and the FTS index.

        Idempotent; safe to run at every search-server startup. Returns counts.
        """
        hosts_blocked = 0
        pages_removed = 0
        with self._lock:
            self.db.execute("BEGIN IMMEDIATE")
            try:
                for h in abuse.hosts:
                    cur = self.db.execute(
                        "UPDATE hosts SET state='blocked', trapped_reason='abuse-host' "
                        "WHERE host=? AND state!='blocked'", (h,),
                    )
                    hosts_blocked += cur.rowcount
                    self.db.execute(
                        "UPDATE frontier SET status='error', "
                        "last_error='host-blocked:abuse-host' "
                        "WHERE host=? AND status='queued'", (h,),
                    )
                if abuse.keywords:
                    rows = self.db.execute(
                        "SELECT rowid AS id, title, body FROM search_index"
                    ).fetchall()
                    for r in rows:
                        if abuse.content_hit(r["title"] or "", r["body"] or ""):
                            self.db.execute(
                                "DELETE FROM search_index WHERE rowid=?", (r["id"],))
                            self.db.execute(
                                "DELETE FROM pages WHERE id=?", (r["id"],))
                            pages_removed += 1
                self.db.execute("COMMIT")
            except Exception:
                self.db.execute("ROLLBACK")
                raise
        return {"hosts_blocked": hosts_blocked, "pages_removed": pages_removed}

    # ---------------------------------------------------------------- stats
    def stats(self):
        with self._lock:
            out = {}
            out["frontier_by_status"] = {
                r["status"]: r["c"] for r in self.db.execute(
                    "SELECT status, count(*) c FROM frontier GROUP BY status")
            }
            out["pages"] = self.db.execute(
                "SELECT count(*) c FROM pages").fetchone()["c"]
            out["hosts"] = self.db.execute(
                "SELECT count(*) c FROM hosts").fetchone()["c"]
            out["hosts_by_state"] = {
                r["state"]: r["c"] for r in self.db.execute(
                    "SELECT state, count(*) c FROM hosts GROUP BY state")
            }
            out["pages_stored"] = self.counter("pages_stored")
            out["urls_enqueued"] = self.counter("urls_enqueued")
            out["duplicates"] = self.db.execute(
                "SELECT COALESCE(sum(dup_count),0) s FROM hosts").fetchone()["s"]
            out["errors"] = self.db.execute(
                "SELECT COALESCE(sum(error_count),0) s FROM hosts").fetchone()["s"]
            out["trapped_hosts"] = [
                dict(r) for r in self.db.execute(
                    "SELECT host, trapped_reason FROM hosts "
                    "WHERE state IN ('trapped','blocked')")
            ]
            out["recent_traps"] = [
                dict(r) for r in self.db.execute(
                    "SELECT host, url, reason FROM trap_log ORDER BY id DESC LIMIT 15")
            ]
            out["hosts_up"] = self.db.execute(
                "SELECT count(*) c FROM hosts WHERE up=1").fetchone()["c"]
            out["hosts_down"] = self.db.execute(
                "SELECT count(*) c FROM hosts WHERE up=0").fetchone()["c"]
            out["hosts_dead"] = self.db.execute(
                "SELECT count(*) c FROM hosts WHERE state='dead'").fetchone()["c"]
            out["link_edges"] = self.db.execute(
                "SELECT count(*) c FROM link_edges").fetchone()["c"]
            out["trap_events"] = self.db.execute(
                "SELECT count(*) c FROM trap_log").fetchone()["c"]
            return out

    def metrics(self) -> dict:
        """A flat dict of numeric gauges for /metrics + /health. Cheap counts
        only (no row scans of large tables beyond grouped counts)."""
        with self._lock:
            fs = {r["status"]: r["c"] for r in self.db.execute(
                "SELECT status, count(*) c FROM frontier GROUP BY status")}
            hs = {r["state"]: r["c"] for r in self.db.execute(
                "SELECT state, count(*) c FROM hosts GROUP BY state")}
            m = {
                "frontier_queued": fs.get("queued", 0),
                "frontier_leased": fs.get("leased", 0),
                "frontier_done": fs.get("done", 0),
                "frontier_error": fs.get("error", 0),
                "pages": self.db.execute(
                    "SELECT count(*) c FROM pages").fetchone()["c"],
                "pages_stored": self.counter("pages_stored"),
                "urls_enqueued": self.counter("urls_enqueued"),
                "hosts": self.db.execute(
                    "SELECT count(*) c FROM hosts").fetchone()["c"],
                "hosts_active": hs.get("active", 0),
                "hosts_trapped": hs.get("trapped", 0),
                "hosts_blocked": hs.get("blocked", 0),
                "hosts_dead": hs.get("dead", 0),
                "hosts_up": self.db.execute(
                    "SELECT count(*) c FROM hosts WHERE up=1").fetchone()["c"],
                "hosts_down": self.db.execute(
                    "SELECT count(*) c FROM hosts WHERE up=0").fetchone()["c"],
                "duplicates": self.db.execute(
                    "SELECT COALESCE(sum(dup_count),0) s FROM hosts").fetchone()["s"],
                "errors": self.db.execute(
                    "SELECT COALESCE(sum(error_count),0) s FROM hosts").fetchone()["s"],
                "trap_events": self.db.execute(
                    "SELECT count(*) c FROM trap_log").fetchone()["c"],
                "link_edges": self.db.execute(
                    "SELECT count(*) c FROM link_edges").fetchone()["c"],
            }
        return m


# FTS5 query builder: turn arbitrary user text into a safe MATCH expression.
import re as _re
_TOKEN = _re.compile(r"[0-9A-Za-z_]+", _re.UNICODE)


def _fts_query(q: str) -> str:
    if not q:
        return ""
    # keep quoted phrases intact
    phrases = _re.findall(r'"([^"]+)"', q)
    remainder = _re.sub(r'"[^"]+"', " ", q)
    terms = _TOKEN.findall(remainder)
    parts = []
    for ph in phrases:
        toks = _TOKEN.findall(ph)
        if toks:
            parts.append('"' + " ".join(toks) + '"')
    for t in terms:
        parts.append('"' + t + '"')
    # implicit AND between terms
    return " ".join(parts)
