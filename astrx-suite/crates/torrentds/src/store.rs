//! Persistent index store (port of `legacy-python/torrentds/store.py`).
//!
//! The Python reference backs every subcommand with one SQLite database +
//! an FTS5 full-text index. This is a **dependency-free** in-memory equivalent
//! with a hand-rolled inverted index and BM25 ranking, persisted with the crate's
//! own bencode codec (no database, no third-party crates) — the same
//! snapshot/restore approach the swarm `peerstore` already uses.
//!
//! It holds parsed torrents (name, sizes, first/last-seen, seen-count, derived
//! category + classifier tags + spam score + content signature), a work queue of
//! `discovered` infohashes awaiting metadata fetch, and an operator blocklist
//! (by infohash or keyword).
//!
//! Determinism: timestamps are injected (`now: u64`, unix seconds) rather than
//! read from the clock, exactly like `peerstore`, so ingest/retention are
//! reproducible and unit-testable.
//!
//! Byte-identical parts (cross-checked against Python): [`categorize`],
//! [`content_signature`], [`magnet_link`], and the FTS query tokenizer. The BM25
//! relevance blend follows the same algorithm as the reference's
//! `bm25(search_fts)` + popularity/size weighting; its exact float output is a
//! SQLite-internal detail, so ordering is verified behaviorally, not bit-for-bit.

use crate::bencode::{decode, encode, Ben};
use crate::infohash::sha256;
use crate::metadata::TorrentMeta;
use std::collections::{BTreeMap, BTreeSet, HashMap};

/// The canonical category order (mirrors Python's `CATEGORIES`).
pub const CATEGORIES: &[&str] = &[
    "video", "audio", "image", "document", "archive", "software", "other",
];

/// Default spam flag threshold, re-exported for callers.
pub use crate::spam::DEFAULT_THRESHOLD as DEFAULT_SPAM_THRESHOLD;

// (extension, category) table — flattened from Python's `_CATEGORY_EXT`.
const CATEGORY_EXTS: &[(&str, &[&str])] = &[
    (
        "video",
        &[
            "mkv", "mp4", "avi", "mov", "wmv", "flv", "m4v", "mpg", "mpeg", "webm", "ts", "m2ts",
            "vob", "ogv",
        ],
    ),
    (
        "audio",
        &[
            "mp3", "flac", "wav", "aac", "ogg", "m4a", "wma", "opus", "ape", "alac", "aiff",
        ],
    ),
    (
        "image",
        &[
            "jpg", "jpeg", "png", "gif", "bmp", "tiff", "webp", "svg", "heic",
        ],
    ),
    (
        "document",
        &[
            "pdf", "epub", "mobi", "azw3", "doc", "docx", "txt", "djvu", "rtf", "odt", "cbz", "cbr",
        ],
    ),
    (
        "archive",
        &["zip", "rar", "7z", "tar", "gz", "bz2", "xz", "iso", "img"],
    ),
    (
        "software",
        &[
            "exe", "msi", "dmg", "apk", "deb", "rpm", "bin", "app", "pkg",
        ],
    ),
];

fn ext_category(ext: &str) -> Option<&'static str> {
    for (cat, exts) in CATEGORY_EXTS {
        if exts.contains(&ext) {
            return Some(cat);
        }
    }
    None
}

/// Classify a torrent by its most common categorised file extension. Ties are
/// broken by first appearance (matching Python's `Counter.most_common`).
#[must_use]
pub fn categorize(name: &str, files: &[(String, u64)]) -> String {
    // (count, first-seen order) per category, in insertion order.
    let mut counts: Vec<(&'static str, u64, usize)> = Vec::new();
    let mut order = 0usize;
    let mut bump = |cat: &'static str, order: &mut usize| {
        if let Some(e) = counts.iter_mut().find(|(c, _, _)| *c == cat) {
            e.1 += 1;
        } else {
            counts.push((cat, 1, *order));
            *order += 1;
        }
    };
    let ext_of = |p: &str| -> String {
        if p.contains('.') {
            p.rsplit_once('.')
                .map(|(_, e)| e.to_ascii_lowercase())
                .unwrap_or_default()
        } else {
            String::new()
        }
    };
    if let Some(cat) = ext_category(&ext_of(name)) {
        bump(cat, &mut order);
    }
    for (p, _) in files {
        if let Some(cat) = ext_category(&ext_of(p)) {
            bump(cat, &mut order);
        }
    }
    // Most common; tie -> earliest first-seen order.
    counts
        .into_iter()
        .max_by(|a, b| a.1.cmp(&b.1).then(b.2.cmp(&a.2)))
        .map_or_else(|| "other".to_string(), |(c, _, _)| c.to_string())
}

/// A content signature = SHA-256 over the bencoded sorted `(path, length)` list,
/// optionally folding in a name-independent `content_id`. `None` when there are no
/// files. Two torrents describing the same content share a signature (so a v1 and
/// a v2/hybrid hash collapse); folding in `content_id` stops a layout-copy attack.
#[must_use]
pub fn content_signature(files: &[(String, u64)], content_id: Option<&[u8]>) -> Option<String> {
    if files.is_empty() {
        return None;
    }
    let mut norm: Vec<(String, u64)> = files.to_vec();
    norm.sort();
    let list = Ben::List(
        norm.into_iter()
            .map(|(p, l)| Ben::List(vec![Ben::Bytes(p.into_bytes()), Ben::Int(l as i64)]))
            .collect(),
    );
    let mut blob = encode(&list);
    if let Some(cid) = content_id {
        blob = encode(&Ben::List(vec![Ben::Bytes(blob), Ben::Bytes(cid.to_vec())]));
    }
    Some(hex(&sha256(&blob)))
}

/// URL percent-encoding matching Python's `urllib.parse.quote(s)` (default
/// `safe='/'`): unreserved `A-Za-z0-9_.-~` and `/` pass through, everything else
/// becomes `%XX` over the UTF-8 bytes.
#[must_use]
pub fn quote(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    for &b in s.as_bytes() {
        let keep = b.is_ascii_alphanumeric() || matches!(b, b'_' | b'.' | b'-' | b'~' | b'/');
        if keep {
            out.push(b as char);
        } else {
            out.push('%');
            out.push_str(&format!("{b:02X}"));
        }
    }
    out
}

/// Build a magnet URI: `xt=urn:btih:` for a v1 hash and/or `xt=urn:btmh:1220…`
/// for a BEP-52 v2 (SHA-256) hash; a hybrid carries both. `dn` is percent-encoded.
#[must_use]
pub fn magnet_link(
    infohash_hex: Option<&str>,
    name: Option<&str>,
    infohash_v2_hex: Option<&str>,
) -> String {
    let mut xts: Vec<String> = Vec::new();
    if let Some(ih) = infohash_hex {
        xts.push(format!("urn:btih:{ih}"));
    }
    if let Some(v2) = infohash_v2_hex {
        xts.push(format!("urn:btmh:1220{v2}"));
    }
    let joined = xts
        .iter()
        .map(|xt| format!("xt={xt}"))
        .collect::<Vec<_>>()
        .join("&");
    let mut link = format!("magnet:?{joined}");
    if let Some(n) = name {
        if !n.is_empty() {
            link.push_str("&dn=");
            link.push_str(&quote(n));
        }
    }
    link
}

fn hex(bytes: &[u8]) -> String {
    let mut s = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        s.push_str(&format!("{b:02x}"));
    }
    s
}

// --- FTS tokenization ------------------------------------------------------

/// Split free text into lowercased alphanumeric tokens (FTS index terms).
fn tokenize(text: &str) -> Vec<String> {
    let mut out = Vec::new();
    let mut cur = String::new();
    for c in text.chars() {
        if c.is_alphanumeric() {
            cur.extend(c.to_lowercase());
        } else if !cur.is_empty() {
            out.push(std::mem::take(&mut cur));
        }
    }
    if !cur.is_empty() {
        out.push(cur);
    }
    out
}

/// Turn a free-text query into prefix tokens (each keeps only alnum chars,
/// lowercased), mirroring Python's `_fts_query` (`"tok"*` AND-joined).
fn query_tokens(text: &str) -> Vec<String> {
    text.split_whitespace()
        .map(|raw| {
            raw.chars()
                .filter(|c| c.is_alphanumeric())
                .flat_map(char::to_lowercase)
                .collect::<String>()
        })
        .filter(|t| !t.is_empty())
        .collect()
}

// --- records ---------------------------------------------------------------

/// A stored torrent (mirrors the Python `torrents` row + its file list).
#[derive(Debug, Clone, PartialEq)]
pub struct TorrentRecord {
    pub infohash: String,
    pub name: String,
    pub total_size: u64,
    pub piece_length: u64,
    pub piece_count: usize,
    pub file_count: usize,
    pub first_seen: u64,
    pub last_seen: u64,
    pub seen_count: u64,
    pub category: String,
    pub infohash_v2: Option<String>,
    pub version: String,
    pub content_sig: Option<String>,
    pub spam_score: f64,
    pub tags: String,
    pub files: Vec<(String, u64)>,
    pub info_bytes: Option<Vec<u8>>,
}

impl TorrentRecord {
    /// The magnet URI for this record.
    #[must_use]
    pub fn magnet(&self) -> String {
        magnet_link(
            Some(&self.infohash),
            Some(&self.name),
            self.infohash_v2.as_deref(),
        )
    }
}

/// A queued infohash awaiting metadata fetch (the Python `discovered` table).
#[derive(Debug, Clone, PartialEq, Eq)]
struct Discovered {
    first_seen: u64,
    attempts: u32,
    fetched: bool,
    peer: Option<(String, u16)>,
}

/// A peer address `(host, port)` a discovered infohash was seen from.
pub type Peer = (String, u16);

/// A pending fetch: a 20-byte infohash and the peer it was harvested from.
pub type PendingFetch = ([u8; 20], Option<Peer>);

/// Outcome of [`Store::store_metadata`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Ingest {
    Stored,
    Updated,
    Blocked,
}

/// Ordering for [`Store::search`].
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default)]
pub enum Order {
    #[default]
    Relevance,
    Latest,
    Oldest,
    Size,
    Seen,
}

/// Search filters (all optional; `None` = unconstrained).
#[derive(Debug, Clone, Default)]
pub struct Filters {
    pub min_size: Option<u64>,
    pub max_size: Option<u64>,
    pub min_files: Option<usize>,
    pub max_files: Option<usize>,
    pub category: Option<String>,
    /// Absolute lower bound on `last_seen` (caller computes `now - since`).
    pub min_last_seen: Option<u64>,
    /// Facet tokens that must all be substrings of the tag string.
    pub tag: Option<String>,
    /// Include spam-flagged rows (default: hidden).
    pub include_spam: bool,
}

/// One search result row (a record reference plus its magnet + dedup info).
#[derive(Debug, Clone)]
pub struct SearchResult {
    pub infohash: String,
    pub name: String,
    pub total_size: u64,
    pub file_count: usize,
    pub piece_count: usize,
    pub seen_count: u64,
    pub last_seen: u64,
    pub category: String,
    pub version: String,
    pub infohash_v2: Option<String>,
    pub tags: String,
    pub magnet: String,
    /// Number of collapsed duplicates (>=1); >1 means siblings were folded in.
    pub dup_count: usize,
    pub alt_infohashes: Vec<String>,
}

/// The in-memory index store.
#[derive(Debug, Clone)]
pub struct Store {
    torrents: BTreeMap<String, TorrentRecord>,
    /// Insertion order per infohash — the LIFO tiebreaker for stable ordering.
    seq: HashMap<String, u64>,
    next_seq: u64,
    /// Per-doc FTS terms (for tf / doc length).
    doc_terms: HashMap<String, Vec<String>>,
    discovered: BTreeMap<String, Discovered>,
    block_infohash: BTreeSet<String>,
    block_keyword: BTreeSet<String>,
    /// Persisted DHT routing contacts (node id → host, port) for warm restart.
    dht_nodes: BTreeMap<[u8; 20], (String, u16)>,
    spam_threshold: f64,
}

impl Default for Store {
    fn default() -> Self {
        Self::new()
    }
}

impl Store {
    /// A fresh, empty store with the default spam threshold.
    #[must_use]
    pub fn new() -> Self {
        Self::with_spam_threshold(DEFAULT_SPAM_THRESHOLD)
    }

    /// A store with a custom spam-flag threshold.
    #[must_use]
    pub fn with_spam_threshold(spam_threshold: f64) -> Self {
        Self {
            torrents: BTreeMap::new(),
            seq: HashMap::new(),
            next_seq: 0,
            doc_terms: HashMap::new(),
            discovered: BTreeMap::new(),
            block_infohash: BTreeSet::new(),
            block_keyword: BTreeSet::new(),
            dht_nodes: BTreeMap::new(),
            spam_threshold,
        }
    }

    // -- DHT routing persistence (warm restart) -----------------------------

    /// Persist routing contacts (`node_id`, host, port); existing ids are updated.
    pub fn save_nodes(&mut self, nodes: &[([u8; 20], String, u16)]) {
        for (id, host, port) in nodes {
            self.dht_nodes.insert(*id, (host.clone(), *port));
        }
    }

    /// Up to `limit` persisted routing contacts.
    #[must_use]
    pub fn load_nodes(&self, limit: usize) -> Vec<([u8; 20], String, u16)> {
        self.dht_nodes
            .iter()
            .take(limit)
            .map(|(id, (h, p))| (*id, h.clone(), *p))
            .collect()
    }

    /// Number of indexed torrents.
    #[must_use]
    pub fn len(&self) -> usize {
        self.torrents.len()
    }

    /// Whether the store holds no torrents.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.torrents.is_empty()
    }

    // -- discovery queue ----------------------------------------------------

    /// Queue an infohash (20 raw bytes) for metadata fetch. Returns `true` if
    /// newly added.
    pub fn add_discovered(&mut self, infohash: &[u8; 20], peer: Option<Peer>, now: u64) -> bool {
        let ih = hex(infohash);
        if self.discovered.contains_key(&ih) {
            return false;
        }
        self.discovered.insert(
            ih,
            Discovered {
                first_seen: now,
                attempts: 0,
                fetched: false,
                peer,
            },
        );
        true
    }

    /// Up to `limit` unfetched infohashes under `max_attempts`, oldest-first.
    #[must_use]
    pub fn pending_infohashes(&self, limit: usize, max_attempts: u32) -> Vec<PendingFetch> {
        let mut rows: Vec<(&String, &Discovered)> = self
            .discovered
            .iter()
            .filter(|(_, d)| !d.fetched && d.attempts < max_attempts)
            .collect();
        rows.sort_by(|a, b| {
            a.1.attempts
                .cmp(&b.1.attempts)
                .then(a.1.first_seen.cmp(&b.1.first_seen))
        });
        rows.into_iter()
            .take(limit)
            .filter_map(|(ih, d)| unhex20(ih).map(|h| (h, d.peer.clone())))
            .collect()
    }

    /// Increment the attempt counter for a queued infohash.
    pub fn mark_attempt(&mut self, infohash: &[u8; 20]) {
        if let Some(d) = self.discovered.get_mut(&hex(infohash)) {
            d.attempts += 1;
        }
    }

    /// Mark a queued infohash as fetched.
    pub fn mark_fetched(&mut self, infohash: &[u8; 20]) {
        if let Some(d) = self.discovered.get_mut(&hex(infohash)) {
            d.fetched = true;
        }
    }

    /// Drop fetched / attempt-exhausted queue rows. Returns the count removed.
    pub fn prune_discovered(&mut self, max_attempts: u32) -> usize {
        let before = self.discovered.len();
        self.discovered
            .retain(|_, d| !d.fetched && d.attempts < max_attempts);
        before - self.discovered.len()
    }

    /// Number of queued (and still-pending) infohashes.
    #[must_use]
    pub fn discovered_counts(&self) -> (usize, usize) {
        let total = self.discovered.len();
        let pending = self.discovered.values().filter(|d| !d.fetched).count();
        (total, pending)
    }

    // -- blocklist ----------------------------------------------------------

    /// Add an infohash (hex) to the blocklist.
    pub fn add_block_infohash(&mut self, infohash_hex: &str) {
        self.block_infohash
            .insert(infohash_hex.to_ascii_lowercase());
    }

    /// Add a keyword (matched case-insensitively as a name substring).
    pub fn add_block_keyword(&mut self, keyword: &str) {
        self.block_keyword.insert(keyword.to_ascii_lowercase());
    }

    /// Is this infohash/name blocked?
    #[must_use]
    pub fn is_blocked(&self, infohash_hex: &str, name: &str) -> bool {
        if self
            .block_infohash
            .contains(&infohash_hex.to_ascii_lowercase())
        {
            return true;
        }
        let lname = name.to_lowercase();
        self.block_keyword
            .iter()
            .any(|kw| lname.contains(kw.as_str()))
    }

    /// Delete already-indexed torrents matching the blocklist. Returns the count.
    pub fn purge_blocked(&mut self) -> usize {
        let victims: Vec<String> = self
            .torrents
            .values()
            .filter(|r| self.is_blocked(&r.infohash, &r.name))
            .map(|r| r.infohash.clone())
            .collect();
        for ih in &victims {
            self.remove(ih);
        }
        victims.len()
    }

    // -- ingest -------------------------------------------------------------

    /// Insert or refresh a torrent from parsed metadata. Returns the outcome.
    pub fn store_metadata(&mut self, meta: &TorrentMeta, now: u64) -> Ingest {
        let ih = hex(&meta.info_hash);
        let category = categorize(&meta.name, &meta.files);
        let files_ref: Vec<(&str, u64)> =
            meta.files.iter().map(|(p, l)| (p.as_str(), *l)).collect();
        let tags = crate::classify::tag_string(&meta.name, &files_ref);
        let content_sig =
            content_signature(&meta.files, meta.content_id.as_ref().map(|c| c.as_slice()));
        let (spam_score, _) = crate::spam::score(
            &meta.name,
            &meta.files,
            meta.total_size,
            meta.piece_length,
            meta.piece_count,
            &category,
            &crate::spam::SpamConfig::default(),
        );
        let version = meta.version.to_string();
        let ih_v2 = meta.info_hash_v2.as_ref().map(|h| hex(h));

        if self.is_blocked(&ih, &meta.name) {
            // Never retain a blocked torrent; also drop any prior copy.
            if self.torrents.contains_key(&ih) {
                self.remove(&ih);
            }
            return Ingest::Blocked;
        }

        if let Some(rec) = self.torrents.get_mut(&ih) {
            rec.last_seen = now;
            rec.seen_count += 1;
            if ih_v2.is_some() {
                rec.infohash_v2 = ih_v2;
            }
            rec.version = version;
            rec.content_sig = content_sig;
            rec.spam_score = spam_score;
            rec.tags = tags;
            if meta.info_bytes.is_some() {
                rec.info_bytes = meta.info_bytes.clone();
            }
            return Ingest::Updated;
        }

        let rec = TorrentRecord {
            infohash: ih.clone(),
            name: meta.name.clone(),
            total_size: meta.total_size,
            piece_length: meta.piece_length,
            piece_count: meta.piece_count,
            file_count: meta.files.len(),
            first_seen: now,
            last_seen: now,
            seen_count: 1,
            category,
            infohash_v2: ih_v2,
            version,
            content_sig,
            spam_score,
            tags,
            files: meta.files.clone(),
            info_bytes: meta.info_bytes.clone(),
        };
        self.index_terms(&ih, &rec);
        self.seq.insert(ih.clone(), self.next_seq);
        self.next_seq += 1;
        self.torrents.insert(ih, rec);
        Ingest::Stored
    }

    fn index_terms(&mut self, ih: &str, rec: &TorrentRecord) {
        let mut terms = tokenize(&rec.name);
        for (p, _) in &rec.files {
            terms.extend(tokenize(p));
        }
        self.doc_terms.insert(ih.to_string(), terms);
    }

    fn remove(&mut self, ih: &str) {
        self.torrents.remove(ih);
        self.doc_terms.remove(ih);
        self.seq.remove(ih);
    }

    /// Bound index growth: drop torrents past a count and/or age cap. Returns the
    /// number removed. `max_age` removes torrents whose `last_seen < now - max_age`;
    /// `max_torrents` keeps only the N most-recently-seen.
    pub fn enforce_retention(
        &mut self,
        max_torrents: Option<usize>,
        max_age: Option<u64>,
        now: u64,
    ) -> usize {
        let mut victims: BTreeSet<String> = BTreeSet::new();
        if let Some(age) = max_age {
            let cutoff = now.saturating_sub(age);
            for r in self.torrents.values() {
                if r.last_seen < cutoff {
                    victims.insert(r.infohash.clone());
                }
            }
        }
        if let Some(keep) = max_torrents {
            let mut by_recency: Vec<&TorrentRecord> = self.torrents.values().collect();
            // Most-recently-seen first; tie -> higher insertion seq first.
            by_recency.sort_by(|a, b| {
                b.last_seen
                    .cmp(&a.last_seen)
                    .then_with(|| self.seq.get(&b.infohash).cmp(&self.seq.get(&a.infohash)))
            });
            for r in by_recency.into_iter().skip(keep) {
                victims.insert(r.infohash.clone());
            }
        }
        for ih in &victims {
            self.remove(ih);
        }
        victims.len()
    }

    // -- lookups ------------------------------------------------------------

    /// Fetch a full record by infohash (hex).
    #[must_use]
    pub fn get(&self, infohash_hex: &str) -> Option<&TorrentRecord> {
        self.torrents.get(infohash_hex)
    }

    /// The raw info-dict bytes for a torrent, if stored.
    #[must_use]
    pub fn info_bytes(&self, infohash_hex: &str) -> Option<&[u8]> {
        self.torrents
            .get(infohash_hex)
            .and_then(|r| r.info_bytes.as_deref())
    }

    /// Other infohashes sharing this torrent's content signature.
    #[must_use]
    pub fn find_duplicates(&self, infohash_hex: &str) -> Vec<String> {
        let Some(sig) = self
            .torrents
            .get(infohash_hex)
            .and_then(|r| r.content_sig.clone())
        else {
            return Vec::new();
        };
        self.torrents
            .values()
            .filter(|r| {
                r.content_sig.as_deref() == Some(sig.as_str()) && r.infohash != infohash_hex
            })
            .map(|r| r.infohash.clone())
            .collect()
    }

    /// Torrent count per category in the canonical order.
    #[must_use]
    pub fn category_counts(&self, include_spam: bool) -> Vec<(&'static str, usize)> {
        CATEGORIES
            .iter()
            .map(|&cat| {
                let n = self
                    .torrents
                    .values()
                    .filter(|r| {
                        r.category == cat && (include_spam || r.spam_score < self.spam_threshold)
                    })
                    .count();
                (cat, n)
            })
            .collect()
    }

    /// Aggregate store statistics.
    #[must_use]
    pub fn stats(&self) -> Stats {
        let (discovered, pending) = self.discovered_counts();
        Stats {
            torrents: self.torrents.len(),
            files: self.torrents.values().map(|r| r.file_count).sum(),
            total_size: self
                .torrents
                .values()
                .map(|r| r.total_size)
                .fold(0u64, u64::saturating_add),
            discovered,
            pending,
            blocked_infohash: self.block_infohash.len(),
            blocked_keyword: self.block_keyword.len(),
            hybrid_v2: self
                .torrents
                .values()
                .filter(|r| r.version == "v2" || r.version == "hybrid")
                .count(),
            spam_flagged: self
                .torrents
                .values()
                .filter(|r| r.spam_score >= self.spam_threshold)
                .count(),
        }
    }

    // -- search -------------------------------------------------------------

    fn passes_filters(&self, r: &TorrentRecord, f: &Filters) -> bool {
        if let Some(mn) = f.min_size {
            if r.total_size < mn {
                return false;
            }
        }
        if let Some(mx) = f.max_size {
            if r.total_size > mx {
                return false;
            }
        }
        if let Some(mn) = f.min_files {
            if r.file_count < mn {
                return false;
            }
        }
        if let Some(mx) = f.max_files {
            if r.file_count > mx {
                return false;
            }
        }
        if let Some(cat) = &f.category {
            if &r.category != cat {
                return false;
            }
        }
        if let Some(ls) = f.min_last_seen {
            if r.last_seen < ls {
                return false;
            }
        }
        if let Some(tag) = &f.tag {
            for tok in tag.split_whitespace().take(8) {
                if !r.tags.contains(tok) {
                    return false;
                }
            }
        }
        if !f.include_spam && r.spam_score >= self.spam_threshold {
            return false;
        }
        true
    }

    /// Does the doc for `ih` match every prefix token (AND + prefix)?
    fn doc_matches(&self, ih: &str, qtokens: &[String]) -> bool {
        let Some(terms) = self.doc_terms.get(ih) else {
            return false;
        };
        qtokens
            .iter()
            .all(|q| terms.iter().any(|t| t.starts_with(q.as_str())))
    }

    /// The BM25 relevance blend for a matching doc (lower = better), mirroring the
    /// Python `bm - 2·ln(1+seen) - 0.5·ln(1+size/1e6)`.
    fn relevance(&self, ih: &str, qtokens: &[String], matches: &[&String], avgdl: f64) -> f64 {
        const K1: f64 = 1.2;
        const B: f64 = 0.75;
        let n = self.torrents.len().max(1) as f64;
        let terms = &self.doc_terms[ih];
        let doc_len = terms.len().max(1) as f64;
        let mut bm25 = 0.0;
        for q in qtokens {
            // df = docs having some term with this prefix.
            let df = matches
                .iter()
                .filter(|m| {
                    self.doc_terms[**m]
                        .iter()
                        .any(|t| t.starts_with(q.as_str()))
                })
                .count() as f64;
            let tf = terms.iter().filter(|t| t.starts_with(q.as_str())).count() as f64;
            if tf == 0.0 {
                continue;
            }
            let idf = ((n - df + 0.5) / (df + 0.5)).ln();
            bm25 += idf * (tf * (K1 + 1.0)) / (tf + K1 * (1.0 - B + B * doc_len / avgdl));
        }
        let rec = &self.torrents[ih];
        // FTS5 returns the NEGATED bm25 (lower = better); apply the same blend.
        (-bm25)
            - 2.0 * (1.0 + rec.seen_count as f64).ln()
            - 0.5 * (1.0 + rec.total_size as f64 / 1_000_000.0).ln()
    }

    /// Search the index. Empty `query` browses (filtered) by the chosen order.
    /// Blocklisted rows are dropped; when `collapse`, rows sharing a content
    /// signature fold into one representative.
    #[must_use]
    pub fn search(
        &self,
        query: &str,
        limit: usize,
        offset: usize,
        order: Order,
        filters: &Filters,
        collapse: bool,
    ) -> Vec<SearchResult> {
        let qtokens = query_tokens(query);
        let avgdl = {
            let total: usize = self.doc_terms.values().map(Vec::len).sum();
            (total as f64 / self.torrents.len().max(1) as f64).max(1.0)
        };

        // Candidate infohashes: filtered, non-blocked, and (if a query) matching.
        let mut cands: Vec<&String> = self
            .torrents
            .values()
            .filter(|r| {
                !self.is_blocked(&r.infohash, &r.name)
                    && self.passes_filters(r, filters)
                    && (qtokens.is_empty() || self.doc_matches(&r.infohash, &qtokens))
            })
            .map(|r| &r.infohash)
            .collect();

        // Order.
        let effective_order = if order == Order::Relevance && qtokens.is_empty() {
            Order::Latest
        } else {
            order
        };
        match effective_order {
            Order::Relevance => {
                let matches = cands.clone();
                let mut scored: Vec<(f64, &String)> = cands
                    .iter()
                    .map(|ih| (self.relevance(ih, &qtokens, &matches, avgdl), *ih))
                    .collect();
                scored.sort_by(|a, b| {
                    a.0.partial_cmp(&b.0)
                        .unwrap_or(std::cmp::Ordering::Equal)
                        .then_with(|| self.seq[b.1].cmp(&self.seq[a.1]))
                });
                cands = scored.into_iter().map(|(_, ih)| ih).collect();
            }
            Order::Latest => self.sort_cands(&mut cands, |r| r.last_seen, true),
            Order::Oldest => self.sort_cands(&mut cands, |r| r.first_seen, false),
            Order::Size => self.sort_cands(&mut cands, |r| r.total_size, true),
            Order::Seen => self.sort_cands(&mut cands, |r| r.seen_count, true),
        }

        // Collapse duplicates by content signature, then page.
        let mut results: Vec<SearchResult> = Vec::new();
        let mut sig_index: HashMap<String, usize> = HashMap::new();
        for ih in cands {
            let r = &self.torrents[ih];
            if collapse {
                if let Some(sig) = &r.content_sig {
                    if let Some(&idx) = sig_index.get(sig) {
                        results[idx].dup_count += 1;
                        results[idx].alt_infohashes.push(r.infohash.clone());
                        continue;
                    }
                    sig_index.insert(sig.clone(), results.len());
                }
            }
            results.push(self.to_result(r));
        }
        results.into_iter().skip(offset).take(limit).collect()
    }

    fn sort_cands<F: Fn(&TorrentRecord) -> u64>(&self, cands: &mut [&String], key: F, desc: bool) {
        cands.sort_by(|a, b| {
            let (ra, rb) = (&self.torrents[*a], &self.torrents[*b]);
            let ord = key(ra).cmp(&key(rb));
            let ord = if desc { ord.reverse() } else { ord };
            // Stable tiebreaker: most-recently-inserted first.
            ord.then_with(|| self.seq[*b].cmp(&self.seq[*a]))
        });
    }

    fn to_result(&self, r: &TorrentRecord) -> SearchResult {
        SearchResult {
            infohash: r.infohash.clone(),
            name: r.name.clone(),
            total_size: r.total_size,
            file_count: r.file_count,
            piece_count: r.piece_count,
            seen_count: r.seen_count,
            last_seen: r.last_seen,
            category: r.category.clone(),
            version: r.version.clone(),
            infohash_v2: r.infohash_v2.clone(),
            tags: r.tags.clone(),
            magnet: r.magnet(),
            dup_count: 1,
            alt_infohashes: Vec::new(),
        }
    }

    /// Total matching torrents (ignores limit/offset/collapse), for pagination.
    #[must_use]
    pub fn count(&self, query: &str, filters: &Filters) -> usize {
        let qtokens = query_tokens(query);
        self.torrents
            .values()
            .filter(|r| {
                !self.is_blocked(&r.infohash, &r.name)
                    && self.passes_filters(r, filters)
                    && (qtokens.is_empty() || self.doc_matches(&r.infohash, &qtokens))
            })
            .count()
    }

    // -- snapshot / restore -------------------------------------------------

    /// Serialise the whole store to a bencode blob (records + queue + blocklist).
    /// No database file — this blob is the persistence unit.
    #[must_use]
    pub fn snapshot(&self) -> Vec<u8> {
        let recs = Ben::List(self.torrents.values().map(|r| self.record_ben(r)).collect());
        let disc = Ben::List(
            self.discovered
                .iter()
                .map(|(ih, d)| {
                    let mut m = BTreeMap::new();
                    m.insert(b"ih".to_vec(), Ben::Bytes(ih.clone().into_bytes()));
                    m.insert(b"first_seen".to_vec(), Ben::Int(d.first_seen as i64));
                    m.insert(b"attempts".to_vec(), Ben::Int(i64::from(d.attempts)));
                    m.insert(b"fetched".to_vec(), Ben::Int(i64::from(d.fetched)));
                    if let Some((h, p)) = &d.peer {
                        m.insert(b"host".to_vec(), Ben::Bytes(h.clone().into_bytes()));
                        m.insert(b"port".to_vec(), Ben::Int(i64::from(*p)));
                    }
                    Ben::Dict(m)
                })
                .collect(),
        );
        let mut root = BTreeMap::new();
        root.insert(b"v".to_vec(), Ben::Int(1));
        root.insert(b"torrents".to_vec(), recs);
        root.insert(b"discovered".to_vec(), disc);
        root.insert(
            b"block_ih".to_vec(),
            Ben::List(
                self.block_infohash
                    .iter()
                    .map(|s| Ben::Bytes(s.clone().into_bytes()))
                    .collect(),
            ),
        );
        root.insert(
            b"block_kw".to_vec(),
            Ben::List(
                self.block_keyword
                    .iter()
                    .map(|s| Ben::Bytes(s.clone().into_bytes()))
                    .collect(),
            ),
        );
        root.insert(
            b"nodes".to_vec(),
            Ben::List(
                self.dht_nodes
                    .iter()
                    .map(|(id, (h, p))| {
                        Ben::List(vec![
                            Ben::Bytes(id.to_vec()),
                            Ben::Bytes(h.clone().into_bytes()),
                            Ben::Int(i64::from(*p)),
                        ])
                    })
                    .collect(),
            ),
        );
        encode(&Ben::Dict(root))
    }

    fn record_ben(&self, r: &TorrentRecord) -> Ben {
        let mut m = BTreeMap::new();
        let put_s = |m: &mut BTreeMap<Vec<u8>, Ben>, k: &[u8], v: &str| {
            m.insert(k.to_vec(), Ben::Bytes(v.as_bytes().to_vec()));
        };
        put_s(&mut m, b"infohash", &r.infohash);
        put_s(&mut m, b"name", &r.name);
        m.insert(b"total_size".to_vec(), Ben::Int(r.total_size as i64));
        m.insert(b"piece_length".to_vec(), Ben::Int(r.piece_length as i64));
        m.insert(b"piece_count".to_vec(), Ben::Int(r.piece_count as i64));
        m.insert(b"first_seen".to_vec(), Ben::Int(r.first_seen as i64));
        m.insert(b"last_seen".to_vec(), Ben::Int(r.last_seen as i64));
        m.insert(b"seen_count".to_vec(), Ben::Int(r.seen_count as i64));
        put_s(&mut m, b"category", &r.category);
        put_s(&mut m, b"version", &r.version);
        if let Some(v2) = &r.infohash_v2 {
            put_s(&mut m, b"infohash_v2", v2);
        }
        if let Some(sig) = &r.content_sig {
            put_s(&mut m, b"content_sig", sig);
        }
        // spam_score stored as milli-units to stay integer in bencode.
        m.insert(
            b"spam_milli".to_vec(),
            Ben::Int((r.spam_score * 1000.0).round() as i64),
        );
        put_s(&mut m, b"tags", &r.tags);
        m.insert(
            b"files".to_vec(),
            Ben::List(
                r.files
                    .iter()
                    .map(|(p, l)| {
                        Ben::List(vec![
                            Ben::Bytes(p.clone().into_bytes()),
                            Ben::Int(*l as i64),
                        ])
                    })
                    .collect(),
            ),
        );
        if let Some(ib) = &r.info_bytes {
            m.insert(b"info".to_vec(), Ben::Bytes(ib.clone()));
        }
        Ben::Dict(m)
    }

    /// Rebuild a store from a [`Store::snapshot`] blob.
    #[must_use]
    pub fn restore(blob: &[u8], spam_threshold: f64) -> Option<Self> {
        let Ok(Ben::Dict(root)) = decode(blob) else {
            return None;
        };
        let mut store = Self::with_spam_threshold(spam_threshold);
        if let Some(Ben::List(recs)) = root.get(b"torrents".as_slice()) {
            for r in recs {
                if let Ben::Dict(d) = r {
                    if let Some(rec) = record_from_ben(d) {
                        let ih = rec.infohash.clone();
                        store.index_terms(&ih, &rec);
                        store.seq.insert(ih.clone(), store.next_seq);
                        store.next_seq += 1;
                        store.torrents.insert(ih, rec);
                    }
                }
            }
        }
        if let Some(Ben::List(ds)) = root.get(b"discovered".as_slice()) {
            for d in ds {
                if let Ben::Dict(m) = d {
                    if let Some((ih, disc)) = discovered_from_ben(m) {
                        store.discovered.insert(ih, disc);
                    }
                }
            }
        }
        for (key, set) in [
            (b"block_ih".as_slice(), &mut store.block_infohash),
            (b"block_kw".as_slice(), &mut store.block_keyword),
        ] {
            if let Some(Ben::List(items)) = root.get(key) {
                for it in items {
                    if let Ben::Bytes(b) = it {
                        set.insert(String::from_utf8_lossy(b).into_owned());
                    }
                }
            }
        }
        if let Some(Ben::List(nodes)) = root.get(b"nodes".as_slice()) {
            for n in nodes {
                if let Ben::List(t) = n {
                    if let [Ben::Bytes(id), Ben::Bytes(h), Ben::Int(p)] = t.as_slice() {
                        if let Ok(id) = <[u8; 20]>::try_from(id.as_slice()) {
                            let port = (*p).clamp(0, i64::from(u16::MAX)) as u16;
                            store
                                .dht_nodes
                                .insert(id, (String::from_utf8_lossy(h).into_owned(), port));
                        }
                    }
                }
            }
        }
        Some(store)
    }
}

/// Aggregate store statistics (mirrors Python `Store.stats`).
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Stats {
    pub torrents: usize,
    pub files: usize,
    pub total_size: u64,
    pub discovered: usize,
    pub pending: usize,
    pub blocked_infohash: usize,
    pub blocked_keyword: usize,
    pub hybrid_v2: usize,
    pub spam_flagged: usize,
}

fn ben_int(d: &BTreeMap<Vec<u8>, Ben>, key: &[u8]) -> i64 {
    match d.get(key) {
        Some(Ben::Int(n)) => *n,
        _ => 0,
    }
}

fn ben_str(d: &BTreeMap<Vec<u8>, Ben>, key: &[u8]) -> Option<String> {
    match d.get(key) {
        Some(Ben::Bytes(b)) => Some(String::from_utf8_lossy(b).into_owned()),
        _ => None,
    }
}

fn record_from_ben(d: &BTreeMap<Vec<u8>, Ben>) -> Option<TorrentRecord> {
    let infohash = ben_str(d, b"infohash")?;
    let files = match d.get(b"files".as_slice()) {
        Some(Ben::List(items)) => items
            .iter()
            .filter_map(|it| match it {
                Ben::List(pair) if pair.len() == 2 => {
                    let p = match &pair[0] {
                        Ben::Bytes(b) => String::from_utf8_lossy(b).into_owned(),
                        _ => return None,
                    };
                    let l = match &pair[1] {
                        Ben::Int(n) => (*n).max(0) as u64,
                        _ => return None,
                    };
                    Some((p, l))
                }
                _ => None,
            })
            .collect(),
        _ => Vec::new(),
    };
    Some(TorrentRecord {
        name: ben_str(d, b"name").unwrap_or_default(),
        total_size: ben_int(d, b"total_size").max(0) as u64,
        piece_length: ben_int(d, b"piece_length").max(0) as u64,
        piece_count: ben_int(d, b"piece_count").max(0) as usize,
        file_count: files.len(),
        first_seen: ben_int(d, b"first_seen").max(0) as u64,
        last_seen: ben_int(d, b"last_seen").max(0) as u64,
        seen_count: ben_int(d, b"seen_count").max(0) as u64,
        category: ben_str(d, b"category").unwrap_or_else(|| "other".to_string()),
        infohash_v2: ben_str(d, b"infohash_v2"),
        version: ben_str(d, b"version").unwrap_or_else(|| "v1".to_string()),
        content_sig: ben_str(d, b"content_sig"),
        spam_score: ben_int(d, b"spam_milli") as f64 / 1000.0,
        tags: ben_str(d, b"tags").unwrap_or_default(),
        files,
        info_bytes: match d.get(b"info".as_slice()) {
            Some(Ben::Bytes(b)) => Some(b.clone()),
            _ => None,
        },
        infohash,
    })
}

fn discovered_from_ben(m: &BTreeMap<Vec<u8>, Ben>) -> Option<(String, Discovered)> {
    let ih = ben_str(m, b"ih")?;
    let peer = match (ben_str(m, b"host"), m.get(b"port".as_slice())) {
        (Some(h), Some(Ben::Int(p))) => Some((h, (*p).clamp(0, i64::from(u16::MAX)) as u16)),
        _ => None,
    };
    Some((
        ih,
        Discovered {
            first_seen: ben_int(m, b"first_seen").max(0) as u64,
            attempts: ben_int(m, b"attempts").max(0) as u32,
            fetched: ben_int(m, b"fetched") != 0,
            peer,
        },
    ))
}

fn unhex20(s: &str) -> Option<[u8; 20]> {
    let b = s.as_bytes();
    if b.len() != 40 {
        return None;
    }
    let mut out = [0u8; 20];
    for (i, pair) in b.chunks_exact(2).enumerate() {
        let hi = (pair[0] as char).to_digit(16)?;
        let lo = (pair[1] as char).to_digit(16)?;
        out[i] = (hi << 4 | lo) as u8;
    }
    Some(out)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn meta(name: &str, files: &[(&str, u64)], ih: [u8; 20]) -> TorrentMeta {
        let files: Vec<(String, u64)> = files.iter().map(|(p, l)| (p.to_string(), *l)).collect();
        let total = files.iter().map(|(_, l)| *l).sum();
        TorrentMeta {
            info_hash: ih,
            name: name.to_string(),
            total_size: total,
            piece_length: 262_144,
            piece_count: 1,
            files,
            info_bytes: None,
            info_hash_v2: None,
            version: "v1",
            content_id: None,
        }
    }

    #[test]
    fn categorize_by_dominant_ext() {
        assert_eq!(
            categorize("movie", &[("a.mkv".into(), 1), ("b.srt".into(), 1)]),
            "video"
        );
        assert_eq!(
            categorize("pack", &[("x.mp3".into(), 1), ("y.flac".into(), 1)]),
            "audio"
        );
        assert_eq!(categorize("readme", &[]), "other");
    }

    #[test]
    fn ingest_and_search() {
        let mut s = Store::new();
        assert_eq!(
            s.store_metadata(
                &meta(
                    "Ubuntu 22.04 ISO",
                    &[("ubuntu.iso", 4_000_000_000)],
                    [1u8; 20]
                ),
                100
            ),
            Ingest::Stored
        );
        assert_eq!(
            s.store_metadata(
                &meta("Debian netinst", &[("debian.iso", 700_000_000)], [2u8; 20]),
                101
            ),
            Ingest::Stored
        );
        // re-ingest bumps seen_count
        assert_eq!(
            s.store_metadata(
                &meta(
                    "Ubuntu 22.04 ISO",
                    &[("ubuntu.iso", 4_000_000_000)],
                    [1u8; 20]
                ),
                102
            ),
            Ingest::Updated
        );
        assert_eq!(s.get(&hex(&[1u8; 20])).unwrap().seen_count, 2);

        let f = Filters {
            include_spam: true,
            ..Default::default()
        };
        let r = s.search("ubuntu", 25, 0, Order::Relevance, &f, true);
        assert_eq!(r.len(), 1);
        assert_eq!(r[0].name, "Ubuntu 22.04 ISO");
        // prefix match
        assert_eq!(s.search("deb", 25, 0, Order::Relevance, &f, true).len(), 1);
        // browse by size desc
        let all = s.search("", 25, 0, Order::Size, &f, true);
        assert_eq!(all[0].name, "Ubuntu 22.04 ISO");
        assert_eq!(s.count("", &f), 2);
    }

    #[test]
    fn blocklist_and_filters() {
        let mut s = Store::new();
        s.store_metadata(
            &meta("Linux ISO", &[("a.iso", 1_000_000_000)], [1u8; 20]),
            100,
        );
        s.store_metadata(
            &meta("spammy keygen crack", &[("x.exe", 1000)], [2u8; 20]),
            100,
        );
        s.add_block_keyword("keygen");
        assert!(s.is_blocked(&hex(&[2u8; 20]), "spammy keygen crack"));
        assert_eq!(s.purge_blocked(), 1);
        let f = Filters {
            include_spam: true,
            ..Default::default()
        };
        assert_eq!(s.count("", &f), 1);
    }

    #[test]
    fn snapshot_round_trips() {
        let mut s = Store::new();
        s.store_metadata(
            &meta("Movie 1080p", &[("m.mkv", 2_000_000_000)], [7u8; 20]),
            500,
        );
        s.add_discovered(&[9u8; 20], Some(("1.2.3.4".to_string(), 6881)), 500);
        s.add_block_keyword("banned");
        s.save_nodes(&[([0xaau8; 20], "9.9.9.9".to_string(), 6881)]);
        let blob = s.snapshot();
        let s2 = Store::restore(&blob, DEFAULT_SPAM_THRESHOLD).unwrap();
        assert_eq!(s2.len(), 1);
        assert_eq!(s2.get(&hex(&[7u8; 20])).unwrap().name, "Movie 1080p");
        assert_eq!(s2.discovered_counts(), (1, 1));
        assert!(s2.is_blocked("deadbeef", "this is banned content"));
        // DHT routing contacts survive the snapshot (warm restart).
        assert_eq!(
            s2.load_nodes(10),
            vec![([0xaau8; 20], "9.9.9.9".to_string(), 6881)]
        );
        // search still works after restore (terms reindexed)
        let f = Filters {
            include_spam: true,
            ..Default::default()
        };
        assert_eq!(
            s2.search("movie", 25, 0, Order::Relevance, &f, true).len(),
            1
        );
    }

    #[test]
    fn dedup_collapses_by_content_sig() {
        let mut s = Store::new();
        // same layout => same content_sig => collapse
        let mut a = meta("Release v1", &[("file.bin", 100)], [1u8; 20]);
        let mut b = meta("Release v2", &[("file.bin", 100)], [2u8; 20]);
        a.content_id = Some([0u8; 32]);
        b.content_id = Some([0u8; 32]);
        s.store_metadata(&a, 100);
        s.store_metadata(&b, 101);
        let f = Filters {
            include_spam: true,
            ..Default::default()
        };
        let r = s.search("", 25, 0, Order::Latest, &f, true);
        assert_eq!(r.len(), 1);
        assert_eq!(r[0].dup_count, 2);
    }

    #[test]
    fn retention_caps_count() {
        let mut s = Store::new();
        for i in 0..5u8 {
            let mut ih = [0u8; 20];
            ih[0] = i;
            s.store_metadata(
                &meta(&format!("t{i}"), &[("a.bin", 100)], ih),
                100 + u64::from(i),
            );
        }
        assert_eq!(s.enforce_retention(Some(3), None, 200), 2);
        assert_eq!(s.len(), 3);
    }
}
