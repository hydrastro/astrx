"""SQLite-backed crawl frontier with leasing, resume and per-host politeness.

Tables (created in the shared crawl DB):

``frontier``  one row per discovered URL: status is one of
              ``queued`` / ``leased`` / ``done`` / ``error`` / ``skipped``.
``hosts``     per-host politeness state: ``next_time`` (earliest next fetch),
              ``crawl_delay`` (from robots), and a fetch counter for budgeting.
``meta``      small key/value store (e.g. robots.txt cache).

Resumability: on :meth:`reclaim`, leases whose ``lease_until`` has passed are
returned to ``queued``; ``done`` rows are never touched, so a restarted crawl
does not refetch completed URLs.
"""

import time

FRONTIER_SCHEMA = """
CREATE TABLE IF NOT EXISTS frontier (
    url         TEXT PRIMARY KEY,
    host        TEXT NOT NULL,
    depth       INTEGER NOT NULL DEFAULT 0,
    status      TEXT NOT NULL DEFAULT 'queued',
    lease_until REAL NOT NULL DEFAULT 0,
    added_at    REAL NOT NULL DEFAULT 0,
    updated_at  REAL NOT NULL DEFAULT 0,
    tries       INTEGER NOT NULL DEFAULT 0,
    reason      TEXT
) WITHOUT ROWID;
CREATE INDEX IF NOT EXISTS ix_frontier_status ON frontier(status, host, depth);

CREATE TABLE IF NOT EXISTS hosts (
    host        TEXT PRIMARY KEY,
    next_time   REAL NOT NULL DEFAULT 0,
    crawl_delay REAL,
    robots_done INTEGER NOT NULL DEFAULT 0,
    fetched     INTEGER NOT NULL DEFAULT 0
) WITHOUT ROWID;

CREATE TABLE IF NOT EXISTS meta (
    k TEXT PRIMARY KEY,
    v TEXT
) WITHOUT ROWID;
"""


class Frontier:
    def __init__(self, conn):
        self.conn = conn
        conn.executescript(FRONTIER_SCHEMA)
        conn.commit()

    # ---- queueing ---------------------------------------------------------
    def add(self, url, host, depth):
        """Add a URL if not already known.  Returns True if newly queued."""
        now = time.time()
        cur = self.conn.execute(
            "INSERT OR IGNORE INTO frontier "
            "(url, host, depth, status, added_at, updated_at) "
            "VALUES (?,?,?, 'queued', ?, ?)",
            (url, host, depth, now, now),
        )
        return cur.rowcount > 0

    def add_many(self, triples):
        added = 0
        for url, host, depth in triples:
            if self.add(url, host, depth):
                added += 1
        return added

    def seen(self, url):
        return self.conn.execute(
            "SELECT 1 FROM frontier WHERE url=? LIMIT 1", (url,)
        ).fetchone() is not None

    # ---- host politeness --------------------------------------------------
    def ensure_host(self, host):
        self.conn.execute(
            "INSERT OR IGNORE INTO hosts (host) VALUES (?)", (host,))

    def host_row(self, host):
        self.ensure_host(host)
        return self.conn.execute(
            "SELECT host, next_time, crawl_delay, robots_done, fetched "
            "FROM hosts WHERE host=?", (host,)
        ).fetchone()

    def set_crawl_delay(self, host, delay):
        self.ensure_host(host)
        self.conn.execute(
            "UPDATE hosts SET crawl_delay=?, robots_done=1 WHERE host=?",
            (delay, host))

    def mark_robots_done(self, host):
        self.ensure_host(host)
        self.conn.execute(
            "UPDATE hosts SET robots_done=1 WHERE host=?", (host,))

    def note_fetch(self, host, next_time):
        """Record that we just fetched *host*; it may not be hit again until
        *next_time*."""
        self.ensure_host(host)
        self.conn.execute(
            "UPDATE hosts SET next_time=?, fetched=fetched+1 WHERE host=?",
            (next_time, host))

    def reserve_host(self, host, next_time):
        """Reserve *host* until *next_time* without incrementing the counter
        (used to hold politeness across a fetch that is about to start)."""
        self.ensure_host(host)
        self.conn.execute(
            "UPDATE hosts SET next_time=? WHERE host=?", (next_time, host))

    # ---- leasing / completion --------------------------------------------
    def reclaim(self, now=None):
        """Return expired leases to the queue (called on start and each loop)."""
        if now is None:
            now = time.time()
        self.conn.execute(
            "UPDATE frontier SET status='queued' "
            "WHERE status='leased' AND lease_until < ?", (now,))
        self.conn.commit()

    def lease(self, now=None, lease_seconds=120, host_budget=None):
        """Atomically lease the next fetchable URL.

        Chooses the shallowest queued URL whose host is politeness-ready (and,
        if *host_budget* is set, under budget).  Returns a row or ``None``.
        """
        if now is None:
            now = time.time()
        conn = self.conn
        conn.execute("BEGIN IMMEDIATE")
        try:
            if host_budget is None:
                row = conn.execute(
                    "SELECT f.url, f.host, f.depth FROM frontier f "
                    "JOIN hosts h ON h.host = f.host "
                    "WHERE f.status='queued' AND h.next_time <= ? "
                    "ORDER BY f.depth, f.added_at LIMIT 1", (now,)
                ).fetchone()
            else:
                row = conn.execute(
                    "SELECT f.url, f.host, f.depth FROM frontier f "
                    "JOIN hosts h ON h.host = f.host "
                    "WHERE f.status='queued' AND h.next_time <= ? "
                    "AND h.fetched < ? "
                    "ORDER BY f.depth, f.added_at LIMIT 1", (now, host_budget)
                ).fetchone()
            # Hosts with no row yet in `hosts` have next_time defaulting via the
            # LEFT side; make sure such hosts exist so they become leasable.
            if row is None:
                miss = conn.execute(
                    "SELECT f.url, f.host, f.depth FROM frontier f "
                    "LEFT JOIN hosts h ON h.host=f.host "
                    "WHERE f.status='queued' AND h.host IS NULL "
                    "ORDER BY f.depth, f.added_at LIMIT 1"
                ).fetchone()
                if miss is not None:
                    conn.execute(
                        "INSERT OR IGNORE INTO hosts (host) VALUES (?)",
                        (miss["host"],))
                    row = miss
            if row is None:
                conn.execute("COMMIT")
                return None
            conn.execute(
                "UPDATE frontier SET status='leased', lease_until=?, "
                "tries=tries+1, updated_at=? WHERE url=?",
                (now + lease_seconds, now, row["url"]))
            conn.execute("COMMIT")
            return row
        except Exception:
            conn.execute("ROLLBACK")
            raise

    def complete(self, url, status="done", reason=None):
        self.conn.execute(
            "UPDATE frontier SET status=?, reason=?, updated_at=? WHERE url=?",
            (status, reason, time.time(), url))

    # ---- introspection ----------------------------------------------------
    def next_ready_time(self, host_budget=None):
        """Earliest time a *leasable* queued URL's host becomes fetchable.

        Considers only hosts under *host_budget* (if given); returns ``None``
        when no queued URL can ever be leased (all hosts over budget).
        """
        if host_budget is None:
            row = self.conn.execute(
                "SELECT MIN(h.next_time) FROM frontier f "
                "JOIN hosts h ON h.host=f.host WHERE f.status='queued'"
            ).fetchone()
        else:
            row = self.conn.execute(
                "SELECT MIN(h.next_time) FROM frontier f "
                "JOIN hosts h ON h.host=f.host "
                "WHERE f.status='queued' AND h.fetched < ?", (host_budget,)
            ).fetchone()
        return row[0]

    def counts(self):
        return {
            r[0]: r[1] for r in self.conn.execute(
                "SELECT status, COUNT(*) FROM frontier GROUP BY status")
        }

    def has_queued(self):
        return self.conn.execute(
            "SELECT 1 FROM frontier WHERE status='queued' LIMIT 1"
        ).fetchone() is not None

    def total_done(self):
        return self.conn.execute(
            "SELECT COUNT(*) FROM frontier WHERE status IN ('done','error')"
        ).fetchone()[0]

    # ---- robots cache -----------------------------------------------------
    def cache_get(self, key):
        row = self.conn.execute(
            "SELECT v FROM meta WHERE k=?", (key,)).fetchone()
        return row[0] if row else None

    def cache_set(self, key, value):
        self.conn.execute(
            "INSERT INTO meta (k, v) VALUES (?, ?) "
            "ON CONFLICT(k) DO UPDATE SET v=excluded.v", (key, value))
