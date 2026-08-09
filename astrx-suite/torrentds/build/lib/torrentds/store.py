"""SQLite persistence: torrents + FTS5 search index + DHT state + blocklist.

One :class:`Store` backs every subcommand, so it is resumable across
restarts.  It holds:

* ``torrents`` / ``files`` -- parsed metadata (name, sizes, piece info,
  first/last-seen, seen-count, derived category) with a companion FTS5 index
  over the name and file paths.
* ``torrent_info`` -- the RAW, SHA-1-verified info-dict bytes, so a valid
  ``.torrent`` can be rebuilt on demand (magnet links are derived too).
* ``discovered`` -- a work queue of infohashes harvested from the DHT that
  still need their metadata fetched.
* ``dht_nodes`` -- routing-table contacts, so the crawler resumes warm.
* ``blocklist_infohash`` / ``blocklist_keyword`` -- the operator blocklist
  hook; matching torrents are dropped on ingest and purgeable retroactively.

Concurrency
-----------
The single writer connection is guarded by ``_lock`` (the harvester and the
tracker both write through it).  Reads go through a *separate* pool of
read-only connections (:meth:`_reader`), so a search query never has to wait
behind a harvester write for the write lock -- WAL lets the readers see the
last committed snapshot while a write is in flight.
"""

from __future__ import annotations

import hashlib
import math
import queue
import threading
import time
from contextlib import contextmanager
from collections import Counter
from typing import Iterable, Iterator, List, Optional, Sequence, Tuple
from urllib.parse import quote

import sqlite3

from . import spam as spam_mod
from . import classify as classify_mod
from .bencode import encode
from .metadata import TorrentMeta

SCHEMA = """
CREATE TABLE IF NOT EXISTS torrents (
    id           INTEGER PRIMARY KEY,
    infohash     TEXT UNIQUE NOT NULL,        -- 40-char hex
    name         TEXT NOT NULL,
    total_size   INTEGER NOT NULL DEFAULT 0,
    piece_length INTEGER NOT NULL DEFAULT 0,
    piece_count  INTEGER NOT NULL DEFAULT 0,
    file_count   INTEGER NOT NULL DEFAULT 0,
    first_seen   REAL NOT NULL,
    last_seen    REAL NOT NULL,
    seen_count   INTEGER NOT NULL DEFAULT 1,
    category     TEXT NOT NULL DEFAULT 'other',
    infohash_v2  TEXT,                        -- 64-char hex SHA-256 (BEP-52)
    version      TEXT NOT NULL DEFAULT 'v1',  -- 'v1' | 'v2' | 'hybrid'
    content_sig  TEXT,                        -- content signature for dedup
    spam_score   REAL NOT NULL DEFAULT 0,     -- fake/spam heuristic score
    tags         TEXT NOT NULL DEFAULT ''     -- classifier attribute facets
);
CREATE INDEX IF NOT EXISTS idx_torrents_last_seen ON torrents(last_seen);
CREATE INDEX IF NOT EXISTS idx_torrents_size      ON torrents(total_size);
CREATE INDEX IF NOT EXISTS idx_torrents_category  ON torrents(category);
CREATE INDEX IF NOT EXISTS idx_torrents_content_sig ON torrents(content_sig);

CREATE TABLE IF NOT EXISTS files (
    id          INTEGER PRIMARY KEY,
    torrent_id  INTEGER NOT NULL REFERENCES torrents(id) ON DELETE CASCADE,
    path        TEXT NOT NULL,
    length      INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_files_torrent ON files(torrent_id);

-- Raw, verified info-dict bytes, for /torrent/<infohash>.torrent rebuilds.
CREATE TABLE IF NOT EXISTS torrent_info (
    infohash  TEXT PRIMARY KEY,
    info      BLOB NOT NULL,
    stored_at REAL NOT NULL
);

CREATE VIRTUAL TABLE IF NOT EXISTS search_fts USING fts5(name, paths);

CREATE TABLE IF NOT EXISTS discovered (
    infohash   TEXT PRIMARY KEY,
    first_seen REAL NOT NULL,
    attempts   INTEGER NOT NULL DEFAULT 0,
    fetched    INTEGER NOT NULL DEFAULT 0,
    peer_host  TEXT,
    peer_port  INTEGER
);
CREATE INDEX IF NOT EXISTS idx_discovered_pending
    ON discovered(fetched, attempts);

CREATE TABLE IF NOT EXISTS dht_nodes (
    node_id   BLOB PRIMARY KEY,
    host      TEXT NOT NULL,
    port      INTEGER NOT NULL,
    last_seen REAL NOT NULL
);

CREATE TABLE IF NOT EXISTS blocklist_infohash (infohash TEXT PRIMARY KEY);
CREATE TABLE IF NOT EXISTS blocklist_keyword  (keyword  TEXT PRIMARY KEY);
"""


def magnet_link(infohash_hex: Optional[str], name: Optional[str] = None,
                infohash_v2_hex: Optional[str] = None) -> str:
    """Build a magnet URI.

    Emits ``xt=urn:btih:`` for a v1 hash and/or ``xt=urn:btmh:1220<64hex>`` for
    a BEP-52 v2 (SHA-256) hash; a hybrid torrent carries both ``xt`` values.
    """
    xts = []
    if infohash_hex:
        xts.append("urn:btih:" + infohash_hex)
    if infohash_v2_hex:
        xts.append("urn:btmh:1220" + infohash_v2_hex)
    link = "magnet:?" + "&".join("xt=" + xt for xt in xts)
    if name:
        link += "&dn=" + quote(name)
    return link


# --------------------------------------------------------------------------
# Category classification (by dominant file extension)
# --------------------------------------------------------------------------

_CATEGORY_EXT = {
    "video": {"mkv", "mp4", "avi", "mov", "wmv", "flv", "m4v", "mpg", "mpeg",
              "webm", "ts", "m2ts", "vob", "ogv"},
    "audio": {"mp3", "flac", "wav", "aac", "ogg", "m4a", "wma", "opus", "ape",
              "alac", "aiff"},
    "image": {"jpg", "jpeg", "png", "gif", "bmp", "tiff", "webp", "svg", "heic"},
    "document": {"pdf", "epub", "mobi", "azw3", "doc", "docx", "txt", "djvu",
                 "rtf", "odt", "cbz", "cbr"},
    "archive": {"zip", "rar", "7z", "tar", "gz", "bz2", "xz", "iso", "img"},
    "software": {"exe", "msi", "dmg", "apk", "deb", "rpm", "bin", "app", "pkg"},
}
CATEGORIES = ["video", "audio", "image", "document", "archive", "software", "other"]

_EXT_TO_CATEGORY = {ext: cat for cat, exts in _CATEGORY_EXT.items() for ext in exts}


def categorize(name: str, files: Sequence[Tuple[str, int]]) -> str:
    """Classify a torrent by the most common categorised file extension."""
    counts: Counter = Counter()
    paths = [name] + [p for p, _ in files]
    for p in paths:
        ext = p.rsplit(".", 1)[-1].lower() if "." in p else ""
        cat = _EXT_TO_CATEGORY.get(ext)
        if cat:
            counts[cat] += 1
    if not counts:
        return "other"
    return counts.most_common(1)[0][0]


def content_signature(files: Sequence[Tuple[str, int]],
                      content_id: Optional[bytes] = None) -> Optional[str]:
    """A content signature = SHA-256 over the sorted ``(path, length)`` list.

    Two torrents that describe the SAME content share a signature, which lets
    listings collapse them so a hybrid torrent is not double-counted.  ``None``
    when there are no files (nothing to dedup on).

    ``content_id`` is an optional name-independent *content* fingerprint (the v1
    piece-hash blob or the v2 ``file tree`` digest, from
    :attr:`~torrentds.metadata.TorrentMeta.content_id`).  When supplied it is
    folded in, so two torrents that merely copy each other's path+length layout
    but hold DIFFERENT actual content get different signatures and are NOT
    collapsed -- closing a dedup-poisoning vector.  When absent (no piece data)
    the signature falls back to layout-only, preserving best-effort dedup.
    """
    norm = sorted((str(p), int(l)) for p, l in (files or []))
    if not norm:
        return None
    blob = encode([[p.encode("utf-8"), l] for p, l in norm])
    if content_id:
        blob = encode([blob, bytes(content_id)])
    return hashlib.sha256(blob).hexdigest()


def _fts_query(text: str) -> str:
    """Turn free text into a safe FTS5 MATCH expression (prefix + AND)."""
    tokens = []
    for raw in text.split():
        token = "".join(c for c in raw if c.isalnum())
        if token:
            tokens.append('"%s"*' % token)  # prefix match, quoted to be safe
    return " ".join(tokens)


_ORDER_SQL = {
    "latest": "t.last_seen DESC",
    "oldest": "t.first_seen ASC",
    "size": "t.total_size DESC",
    "seen": "t.seen_count DESC",
}


class Store:
    def __init__(self, path: str, read_pool_size: int = 4,
                 spam_threshold: Optional[float] = None):
        self.path = path
        # Operator-tunable spam flag threshold (read-time filter); stored per
        # torrent as a raw score so retuning never requires a re-scan.
        self.spam_threshold = (spam_threshold if spam_threshold is not None
                               else spam_mod.DEFAULT_THRESHOLD)
        self._lock = threading.RLock()
        self._conn = sqlite3.connect(path, check_same_thread=False)
        self._conn.row_factory = sqlite3.Row
        self._conn.execute("PRAGMA journal_mode=WAL")
        self._conn.execute("PRAGMA synchronous=NORMAL")
        self._conn.execute("PRAGMA busy_timeout=5000")
        self._conn.execute("PRAGMA foreign_keys=ON")
        with self._lock:
            self._conn.executescript(SCHEMA)
            self._migrate()
            self._conn.commit()
        # Separate read-only connection pool.  Opened AFTER the schema exists
        # so search reads run on their own connections/snapshots and do not
        # serialise behind the writer's lock.
        self._read_pool: "queue.Queue[sqlite3.Connection]" = queue.Queue()
        self._read_conns: List[sqlite3.Connection] = []
        for _ in range(max(1, read_pool_size)):
            rc = sqlite3.connect(path, check_same_thread=False)
            rc.row_factory = sqlite3.Row
            rc.execute("PRAGMA busy_timeout=5000")
            rc.execute("PRAGMA query_only=ON")   # hard read-only guard
            self._read_conns.append(rc)
            self._read_pool.put(rc)

    def _migrate(self) -> None:
        """Add columns introduced after the original schema (idempotent)."""
        cols = {r[1] for r in self._conn.execute(
            "PRAGMA table_info(torrents)").fetchall()}
        if "category" not in cols:
            self._conn.execute(
                "ALTER TABLE torrents ADD COLUMN category TEXT NOT NULL DEFAULT 'other'")
        added_v2 = "infohash_v2" not in cols
        if added_v2:
            self._conn.execute("ALTER TABLE torrents ADD COLUMN infohash_v2 TEXT")
        if "version" not in cols:
            self._conn.execute(
                "ALTER TABLE torrents ADD COLUMN version TEXT NOT NULL DEFAULT 'v1'")
        added_sig = "content_sig" not in cols
        if added_sig:
            self._conn.execute("ALTER TABLE torrents ADD COLUMN content_sig TEXT")
            self._conn.execute(
                "CREATE INDEX IF NOT EXISTS idx_torrents_content_sig "
                "ON torrents(content_sig)")
        added_spam = "spam_score" not in cols
        if added_spam:
            self._conn.execute(
                "ALTER TABLE torrents ADD COLUMN spam_score REAL NOT NULL DEFAULT 0")
        added_tags = "tags" not in cols
        if added_tags:
            self._conn.execute(
                "ALTER TABLE torrents ADD COLUMN tags TEXT NOT NULL DEFAULT ''")
        if added_sig or added_spam or added_tags:
            self._backfill_derived()

    def _backfill_derived(self) -> None:
        """Populate content_sig / spam_score for pre-existing rows (one-off)."""
        rows = self._conn.execute(
            "SELECT id, name, total_size, piece_length, piece_count, category "
            "FROM torrents").fetchall()
        for r in rows:
            files = [(f["path"], f["length"]) for f in self._conn.execute(
                "SELECT path, length FROM files WHERE torrent_id=?", (r["id"],)).fetchall()]
            sig = content_signature(files)
            sc, _ = spam_mod.score(r["name"] or "", files, r["total_size"] or 0,
                                   r["piece_length"] or 0, r["piece_count"] or 0,
                                   r["category"] or "other")
            tags = classify_mod.tag_string(
                classify_mod.classify(r["name"] or "", files))
            self._conn.execute(
                "UPDATE torrents SET content_sig=?, spam_score=?, tags=? WHERE id=?",
                (sig, sc, tags, r["id"]))

    @contextmanager
    def _reader(self) -> Iterator[sqlite3.Connection]:
        rc = self._read_pool.get()
        try:
            yield rc
        finally:
            self._read_pool.put(rc)

    def close(self) -> None:
        with self._lock:
            self._conn.close()
        for rc in self._read_conns:
            try:
                rc.close()
            except Exception:
                pass

    # -- discovery queue ----------------------------------------------------
    def add_discovered(self, infohash: bytes, peer: Optional[Tuple[str, int]] = None) -> bool:
        """Queue an infohash for metadata fetch.  Returns True if newly added."""
        ih = infohash.hex()
        host, port = (peer if peer else (None, None))
        with self._lock:
            cur = self._conn.execute(
                "INSERT INTO discovered(infohash, first_seen, peer_host, peer_port) "
                "VALUES(?,?,?,?) ON CONFLICT(infohash) DO NOTHING",
                (ih, time.time(), host, port),
            )
            self._conn.commit()
            return cur.rowcount > 0

    def pending_infohashes(self, limit: int = 50, max_attempts: int = 5
                           ) -> List[Tuple[bytes, Optional[Tuple[str, int]]]]:
        with self._reader() as c:
            rows = c.execute(
                "SELECT infohash, peer_host, peer_port FROM discovered "
                "WHERE fetched=0 AND attempts<? ORDER BY attempts, first_seen LIMIT ?",
                (max_attempts, limit),
            ).fetchall()
        out = []
        for r in rows:
            peer = (r["peer_host"], r["peer_port"]) if r["peer_host"] else None
            out.append((bytes.fromhex(r["infohash"]), peer))
        return out

    def mark_attempt(self, infohash: bytes) -> None:
        with self._lock:
            self._conn.execute(
                "UPDATE discovered SET attempts=attempts+1 WHERE infohash=?",
                (infohash.hex(),))
            self._conn.commit()

    def mark_fetched(self, infohash: bytes) -> None:
        with self._lock:
            self._conn.execute(
                "UPDATE discovered SET fetched=1 WHERE infohash=?",
                (infohash.hex(),))
            self._conn.commit()

    def prune_discovered(self, max_attempts: int = 5) -> int:
        """Drop already-fetched and attempt-exhausted queue rows.

        Keeps the ``discovered`` work queue from growing without bound: a row
        is useless once its metadata is fetched, or once it has failed
        ``max_attempts`` times.  Returns the number of rows removed.
        """
        with self._lock:
            cur = self._conn.execute(
                "DELETE FROM discovered WHERE fetched=1 OR attempts>=?",
                (max_attempts,))
            self._conn.commit()
            return cur.rowcount

    # -- blocklist ----------------------------------------------------------
    def add_block_infohash(self, infohash_hex: str) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT OR IGNORE INTO blocklist_infohash(infohash) VALUES(?)",
                (infohash_hex.lower(),))
            self._conn.commit()

    def add_block_keyword(self, keyword: str) -> None:
        with self._lock:
            self._conn.execute(
                "INSERT OR IGNORE INTO blocklist_keyword(keyword) VALUES(?)",
                (keyword.lower(),))
            self._conn.commit()

    @staticmethod
    def _blocked_infohashes(conn: sqlite3.Connection) -> set:
        rows = conn.execute("SELECT infohash FROM blocklist_infohash").fetchall()
        return {r["infohash"] for r in rows}

    @staticmethod
    def _blocked_keywords(conn: sqlite3.Connection) -> List[str]:
        rows = conn.execute("SELECT keyword FROM blocklist_keyword").fetchall()
        return [r["keyword"] for r in rows]

    def is_blocked(self, infohash_hex: str, name: str) -> bool:
        with self._lock:
            if infohash_hex.lower() in self._blocked_infohashes(self._conn):
                return True
            lname = name.lower()
            return any(kw in lname for kw in self._blocked_keywords(self._conn))

    def purge_blocked(self) -> int:
        """Delete already-indexed torrents that match the blocklist."""
        with self._lock:
            blocked_ih = self._blocked_infohashes(self._conn)
            keywords = self._blocked_keywords(self._conn)
            rows = self._conn.execute("SELECT id, infohash, name FROM torrents").fetchall()
            victims = []
            for r in rows:
                name = (r["name"] or "").lower()
                if r["infohash"] in blocked_ih or any(kw in name for kw in keywords):
                    victims.append((r["id"], r["infohash"]))
            for tid, ih in victims:
                self._delete_torrent_row(tid, ih)
            self._conn.commit()
            return len(victims)

    # -- metadata ingest ----------------------------------------------------
    def store_metadata(self, meta: TorrentMeta,
                       peer: Optional[Tuple[str, int]] = None) -> str:
        """Insert or refresh a torrent.  Returns 'stored', 'updated' or 'blocked'."""
        ih = meta.info_hash.hex()
        now = time.time()
        category = categorize(meta.name, meta.files)
        tags = classify_mod.tag_string(classify_mod.classify(meta.name, meta.files))
        sig = content_signature(meta.files, getattr(meta, "content_id", None))
        spam_score, _ = spam_mod.score(
            meta.name, meta.files, meta.total_size, meta.piece_length,
            meta.piece_count, category)
        version = getattr(meta, "version", "v1") or "v1"
        ih_v2 = meta.infohash_v2_hex if hasattr(meta, "infohash_v2_hex") else None
        with self._lock:
            row = self._conn.execute(
                "SELECT id FROM torrents WHERE infohash=?", (ih,)).fetchone()
            if row is not None:
                # Refresh derived fields too: a re-seen torrent may arrive with a
                # richer info-dict (e.g. hybrid v2 keys) than the first sighting.
                self._conn.execute(
                    "UPDATE torrents SET last_seen=?, seen_count=seen_count+1, "
                    "infohash_v2=COALESCE(?, infohash_v2), version=?, "
                    "content_sig=?, spam_score=?, tags=? WHERE id=?",
                    (now, ih_v2, version, sig, spam_score, tags, row["id"]))
                if self.is_blocked(ih, meta.name):
                    # Never retain a blocked torrent -- or its info blob.
                    self._delete_torrent_row(row["id"], ih)
                    self._conn.commit()
                    return "blocked"
                self._store_info_blob(ih, meta.info_bytes, now)
                self._conn.commit()
                return "updated"
            if self.is_blocked(ih, meta.name):
                return "blocked"
            cur = self._conn.execute(
                "INSERT INTO torrents(infohash,name,total_size,piece_length,"
                "piece_count,file_count,first_seen,last_seen,seen_count,category,"
                "infohash_v2,version,content_sig,spam_score,tags) "
                "VALUES(?,?,?,?,?,?,?,?,1,?,?,?,?,?,?)",
                (ih, meta.name, meta.total_size, meta.piece_length,
                 meta.piece_count, meta.file_count, now, now, category,
                 ih_v2, version, sig, spam_score, tags))
            tid = cur.lastrowid
            self._conn.executemany(
                "INSERT INTO files(torrent_id, path, length) VALUES(?,?,?)",
                [(tid, path, length) for path, length in meta.files])
            paths = "\n".join(path for path, _ in meta.files)
            self._conn.execute(
                "INSERT INTO search_fts(rowid, name, paths) VALUES(?,?,?)",
                (tid, meta.name, paths))
            self._store_info_blob(ih, meta.info_bytes, now)
            self._conn.commit()
            return "stored"

    def _store_info_blob(self, infohash_hex: str, info_bytes, now: float) -> None:
        if not info_bytes:
            return
        self._conn.execute(
            "INSERT INTO torrent_info(infohash, info, stored_at) VALUES(?,?,?) "
            "ON CONFLICT(infohash) DO UPDATE SET info=excluded.info, "
            "stored_at=excluded.stored_at",
            (infohash_hex, sqlite3.Binary(bytes(info_bytes)), now))

    def _delete_torrent_row(self, tid: int, infohash_hex: str) -> None:
        self._conn.execute("DELETE FROM torrents WHERE id=?", (tid,))
        self._conn.execute("DELETE FROM files WHERE torrent_id=?", (tid,))
        self._conn.execute("DELETE FROM search_fts WHERE rowid=?", (tid,))
        self._conn.execute("DELETE FROM torrent_info WHERE infohash=?", (infohash_hex,))

    def enforce_retention(self, max_torrents: Optional[int] = None,
                          max_age_seconds: Optional[float] = None) -> int:
        """Bound index growth: drop the oldest torrents past a count / age cap.

        ``max_age_seconds`` removes torrents not seen within the window;
        ``max_torrents`` keeps only the *N* most-recently-seen.  Returns the
        number of torrents deleted (files/FTS/blob cascade with them).
        """
        removed = 0
        with self._lock:
            victims: List[Tuple[int, str]] = []
            if max_age_seconds is not None:
                cutoff = time.time() - max_age_seconds
                victims += [(r["id"], r["infohash"]) for r in self._conn.execute(
                    "SELECT id, infohash FROM torrents WHERE last_seen < ?",
                    (cutoff,)).fetchall()]
            if max_torrents is not None:
                keep_ids = {r["id"] for r in self._conn.execute(
                    "SELECT id FROM torrents ORDER BY last_seen DESC, id DESC LIMIT ?",
                    (max_torrents,)).fetchall()}
                victims += [(r["id"], r["infohash"]) for r in self._conn.execute(
                    "SELECT id, infohash FROM torrents").fetchall()
                    if r["id"] not in keep_ids]
            seen = set()
            for tid, ih in victims:
                if tid in seen:
                    continue
                seen.add(tid)
                self._delete_torrent_row(tid, ih)
                removed += 1
            self._conn.commit()
        return removed

    def vacuum(self) -> None:
        """Reclaim free pages.  Serialised with writes via the write lock."""
        with self._lock:
            self._conn.execute("VACUUM")
            self._conn.commit()

    def backup(self, dest_path: str) -> dict:
        """Make a safe, self-contained SQLite copy of the store at *dest_path*.

        Uses SQLite's online backup API under the write lock, so it is
        consistent even while the harvester/tracker are writing (WAL-safe) and
        never touches the network.  Returns a small summary of the backup.
        """
        with self._lock:
            dest = sqlite3.connect(dest_path)
            try:
                self._conn.backup(dest)
                dest.commit()
                torrents = dest.execute("SELECT COUNT(*) FROM torrents").fetchone()[0]
            finally:
                dest.close()
        import os as _os
        size = _os.path.getsize(dest_path) if _os.path.exists(dest_path) else 0
        return {"path": dest_path, "torrents": int(torrents), "bytes": int(size)}

    # -- search -------------------------------------------------------------
    def _build_filters(self, min_size, max_size, min_files, max_files,
                       category, since, include_spam=True,
                       tag=None) -> Tuple[str, list]:
        clauses: List[str] = []
        params: list = []
        if tag:
            # Facet filter: each whitespace token must appear in the tag string
            # (e.g. "resolution:1080p source:web-dl", or just "1080p").  LIKE with
            # escaped wildcards -> a user value can never become a %/_ wildcard.
            for tok in str(tag).split()[:8]:
                esc = (tok.replace("\\", "\\\\").replace("%", "\\%")
                       .replace("_", "\\_"))
                clauses.append("t.tags LIKE ? ESCAPE '\\'")
                params.append("%" + esc + "%")
        if min_size is not None:
            clauses.append("t.total_size >= ?"); params.append(int(min_size))
        if max_size is not None:
            clauses.append("t.total_size <= ?"); params.append(int(max_size))
        if min_files is not None:
            clauses.append("t.file_count >= ?"); params.append(int(min_files))
        if max_files is not None:
            clauses.append("t.file_count <= ?"); params.append(int(max_files))
        if category:
            clauses.append("t.category = ?"); params.append(str(category))
        if since is not None:
            clauses.append("t.last_seen >= ?"); params.append(time.time() - float(since))
        if not include_spam:
            clauses.append("t.spam_score < ?"); params.append(float(self.spam_threshold))
        return (" AND ".join(clauses), params)

    _SELECT_COLS = ("t.infohash AS infohash, t.name AS name, "
                    "t.total_size AS total_size, t.file_count AS file_count, "
                    "t.piece_count AS piece_count, t.piece_length AS piece_length, "
                    "t.seen_count AS seen_count, t.first_seen AS first_seen, "
                    "t.last_seen AS last_seen, t.category AS category, "
                    "t.infohash_v2 AS infohash_v2, t.version AS version, "
                    "t.content_sig AS content_sig, t.spam_score AS spam_score, "
                    "t.tags AS tags")

    @staticmethod
    def _collapse_dupes(results: List[dict]) -> List[dict]:
        """Collapse rows sharing a ``content_sig`` (cross-infohash dedup).

        Keeps the first (highest-ranked) representative and records the count +
        the sibling infohashes on it, so a v1 and a v2/hybrid hash of the same
        content show up once instead of double-counting.
        """
        seen: dict = {}
        out: List[dict] = []
        for row in results:
            sig = row.get("content_sig")
            if not sig:
                out.append(row)
                continue
            keep = seen.get(sig)
            if keep is None:
                row["dup_count"] = 1
                row["alt_infohashes"] = []
                seen[sig] = row
                out.append(row)
            else:
                keep["dup_count"] += 1
                keep["alt_infohashes"].append(row["infohash"])
        return out

    def search(self, query: str, limit: int = 25, offset: int = 0, *,
               min_size: Optional[int] = None, max_size: Optional[int] = None,
               min_files: Optional[int] = None, max_files: Optional[int] = None,
               category: Optional[str] = None, since: Optional[float] = None,
               order: str = "relevance", include_spam: bool = True,
               collapse: bool = False, tag: Optional[str] = None) -> List[dict]:
        query = (query or "").strip()
        limit = max(0, int(limit))
        offset = max(0, int(offset))
        where, fparams = self._build_filters(
            min_size, max_size, min_files, max_files, category, since,
            include_spam, tag=tag)
        order_sql = _ORDER_SQL.get(order)

        with self._reader() as c:
            blocked = self._blocked_infohashes(c)
            if order_sql is not None:
                # Indexed ordering: page the window entirely in SQL (LIMIT +
                # OFFSET) so a deep ``offset`` materialises only ~limit rows
                # instead of (limit+offset)*4.  A bounded cushion covers the
                # blocklisted rows dropped below; no Python-side offset slice.
                cushion = min(len(blocked) + 50, 500)
                row_limit, row_offset = limit + cushion, offset
            else:
                # Relevance re-ranks in Python, so it must over-fetch a pool --
                # but cap that pool so a large offset can't drag millions of
                # rows into memory (the ``*4`` alone reached ~4M at offset 1e6).
                row_limit = min((limit + offset) * 4 + 50, limit * 8 + 200)
                row_offset = 0
            if not query:
                sql = "SELECT %s FROM torrents t" % self._SELECT_COLS
                if where:
                    sql += " WHERE " + where
                sql += (" ORDER BY %s LIMIT ? OFFSET ?"
                        % (order_sql or "t.last_seen DESC"))
                rows = c.execute(sql, (*fparams, row_limit, row_offset)).fetchall()
                candidates = [(dict(r), 0.0) for r in rows]
            else:
                match = _fts_query(query)
                if not match:
                    return []
                sql = ("SELECT %s, bm25(search_fts) AS bm "
                       "FROM search_fts JOIN torrents t ON t.id = search_fts.rowid "
                       "WHERE search_fts MATCH ?" % self._SELECT_COLS)
                if where:
                    sql += " AND " + where
                sql += " ORDER BY %s LIMIT ? OFFSET ?" % (order_sql or "bm")
                rows = c.execute(
                    sql, (match, *fparams, row_limit, row_offset)).fetchall()
                candidates = [(dict(r), r["bm"]) for r in rows]

        results = []
        for row, bm in candidates:
            if row["infohash"] in blocked:
                continue
            if order_sql is None:
                # Relevance: blend bm25 (lower better) with popularity + size.
                row["score"] = bm - 2.0 * math.log1p(row["seen_count"]) \
                    - 0.5 * math.log1p(row["total_size"] / 1_000_000.0)
            row["magnet"] = magnet_link(row["infohash"], row["name"],
                                        row.get("infohash_v2"))
            results.append(row)
        if order_sql is None:
            # Pool was fetched with no SQL offset: re-rank, (collapse,) then page.
            results.sort(key=lambda r: r["score"])
            if collapse:
                results = self._collapse_dupes(results)
            return results[offset:offset + limit]
        # Indexed ordering already paged in SQL; drop blocked rows, collapse, cap.
        if collapse:
            results = self._collapse_dupes(results)
        return results[:limit]

    def count(self, query: str = "", *, min_size: Optional[int] = None,
              max_size: Optional[int] = None, min_files: Optional[int] = None,
              max_files: Optional[int] = None, category: Optional[str] = None,
              since: Optional[float] = None, include_spam: bool = True,
              tag: Optional[str] = None) -> int:
        """Total matching torrents (for pagination), ignoring limit/offset.

        Blocklisted rows are not subtracted (they are excluded lazily at read
        time); this is an upper bound suitable for a page counter.
        """
        query = (query or "").strip()
        where, fparams = self._build_filters(
            min_size, max_size, min_files, max_files, category, since,
            include_spam, tag=tag)
        with self._reader() as c:
            if not query:
                sql = "SELECT COUNT(*) FROM torrents t"
                if where:
                    sql += " WHERE " + where
                return c.execute(sql, fparams).fetchone()[0]
            match = _fts_query(query)
            if not match:
                return 0
            sql = ("SELECT COUNT(*) FROM search_fts "
                   "JOIN torrents t ON t.id = search_fts.rowid "
                   "WHERE search_fts MATCH ?")
            if where:
                sql += " AND " + where
            return c.execute(sql, (match, *fparams)).fetchone()[0]

    def get_torrent(self, infohash_hex: str) -> Optional[dict]:
        with self._reader() as c:
            row = c.execute(
                "SELECT * FROM torrents WHERE infohash=?", (infohash_hex,)).fetchone()
            if row is None:
                return None
            files = c.execute(
                "SELECT path, length FROM files WHERE torrent_id=? ORDER BY id",
                (row["id"],)).fetchall()
            has_blob = c.execute(
                "SELECT 1 FROM torrent_info WHERE infohash=?",
                (infohash_hex,)).fetchone() is not None
            # Sibling infohashes (cross-infohash dedup): same content, other hash.
            sig = row["content_sig"] if "content_sig" in row.keys() else None
            if sig:
                alts = [r2["infohash"] for r2 in c.execute(
                    "SELECT infohash FROM torrents WHERE content_sig=? AND infohash<>?",
                    (sig, infohash_hex)).fetchall()]
            else:
                alts = []
        d = dict(row)
        d["files"] = [dict(f) for f in files]
        d["magnet"] = magnet_link(d["infohash"], d["name"], d.get("infohash_v2"))
        d["has_torrent"] = has_blob
        d["alt_infohashes"] = alts
        return d

    def get_info_bytes(self, infohash_hex: str) -> Optional[bytes]:
        """Return the stored raw info-dict bytes, or ``None``."""
        with self._reader() as c:
            row = c.execute(
                "SELECT info FROM torrent_info WHERE infohash=?",
                (infohash_hex.lower(),)).fetchone()
        return bytes(row["info"]) if row is not None else None

    # -- browse -------------------------------------------------------------
    def category_counts(self, include_spam: bool = False) -> "OrderedDict":
        """Torrent count per category, in the canonical CATEGORIES order."""
        from collections import OrderedDict
        where = "" if include_spam else " WHERE spam_score < ?"
        params = () if include_spam else (float(self.spam_threshold),)
        with self._reader() as c:
            rows = c.execute(
                "SELECT category, COUNT(*) AS n FROM torrents" + where
                + " GROUP BY category", params).fetchall()
        by = {r["category"]: r["n"] for r in rows}
        return OrderedDict((cat, by.get(cat, 0)) for cat in CATEGORIES)

    def find_duplicates(self, infohash_hex: str) -> List[str]:
        """Infohashes of other torrents sharing this one's content signature."""
        with self._reader() as c:
            row = c.execute("SELECT content_sig FROM torrents WHERE infohash=?",
                            (infohash_hex.lower(),)).fetchone()
            if row is None or not row["content_sig"]:
                return []
            return [r["infohash"] for r in c.execute(
                "SELECT infohash FROM torrents WHERE content_sig=? AND infohash<>?",
                (row["content_sig"], infohash_hex.lower())).fetchall()]

    # -- blocklist admin (POST /api/block) ----------------------------------
    def add_blocklist(self, kind: str, value: str) -> Tuple[int, dict]:
        """Add to the blocklist and purge indexed matches (for /api/block).

        Mirrors onioncrawler's ``add_blocklist`` shape but with torrentds kinds:
        ``kind`` in ``{"infohash", "keyword"}``.  Returns ``(http_code, body)``.
        """
        kind = (kind or "").strip().lower()
        value = (value or "").strip()
        if kind not in ("infohash", "keyword") or not value:
            return 400, {"error": "kind must be infohash|keyword and value non-empty"}
        if kind == "infohash":
            ih = value.lower()
            if not all(ch in "0123456789abcdef" for ch in ih) or len(ih) not in (40, 64):
                return 400, {"error": "infohash must be 40 or 64 hex chars"}
            self.add_block_infohash(ih)
            value = ih
        else:
            self.add_block_keyword(value)
        purged = self.purge_blocked()
        return 200, {"ok": True, "kind": kind, "value": value,
                     "purged": purged, "applied": purged}

    # -- DHT routing persistence -------------------------------------------
    def save_nodes(self, nodes: Iterable) -> None:
        with self._lock:
            self._conn.executemany(
                "INSERT INTO dht_nodes(node_id,host,port,last_seen) VALUES(?,?,?,?) "
                "ON CONFLICT(node_id) DO UPDATE SET host=excluded.host, "
                "port=excluded.port, last_seen=excluded.last_seen",
                [(n.id, n.host, n.port, n.last_seen) for n in nodes])
            self._conn.commit()

    def load_nodes(self, limit: int = 1000) -> List[Tuple[bytes, str, int]]:
        with self._reader() as c:
            rows = c.execute(
                "SELECT node_id, host, port FROM dht_nodes "
                "ORDER BY last_seen DESC LIMIT ?", (limit,)).fetchall()
        return [(r["node_id"], r["host"], r["port"]) for r in rows]

    # -- stats --------------------------------------------------------------
    def stats(self) -> dict:
        with self._reader() as c:
            g = lambda sql, p=(): c.execute(sql, p).fetchone()[0]
            return {
                "torrents": g("SELECT COUNT(*) FROM torrents"),
                "files": g("SELECT COUNT(*) FROM files"),
                "total_size": g("SELECT COALESCE(SUM(total_size),0) FROM torrents"),
                "discovered": g("SELECT COUNT(*) FROM discovered"),
                "pending": g("SELECT COUNT(*) FROM discovered WHERE fetched=0"),
                "dht_nodes": g("SELECT COUNT(*) FROM dht_nodes"),
                "torrent_blobs": g("SELECT COUNT(*) FROM torrent_info"),
                "blocked_infohash": g("SELECT COUNT(*) FROM blocklist_infohash"),
                "blocked_keyword": g("SELECT COUNT(*) FROM blocklist_keyword"),
                "hybrid_v2": g("SELECT COUNT(*) FROM torrents "
                               "WHERE version IN ('v2','hybrid')"),
                "spam_flagged": g("SELECT COUNT(*) FROM torrents WHERE spam_score >= ?",
                                  (float(self.spam_threshold),)),
            }
