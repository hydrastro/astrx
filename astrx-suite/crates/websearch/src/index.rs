//! The document store + link graph — a dependency-free port of the store core of
//! the Python `websearch.index` (which is SQLite + FTS5).
//!
//! The store the crawler writes to and the search reads from: one [`Document`]
//! per indexed page (upsert by URL, returning a stable rowid), `content_hash`
//! exact-dup detection, conditional-GET validators (`etag` / `last_modified`),
//! the recrawl due-list, the `(src → dst)` link graph with incoming-link counts,
//! index statistics, and the offline ranking signals — [`Index::compute_pagerank`]
//! (internal-graph PageRank-lite → `doc.rank`) and
//! [`Index::compute_host_authority`] (cross-domain host PageRank →
//! `host_authority` and `doc.host_rank`), driven together by [`Index::finalize`].
//! `content_hash`
//! (SHA-256 over crawlcore), the store behaviour, and the PageRank/host-authority
//! scores are cross-checked byte-identical to Python (`tests/xcheck_index.rs`,
//! `tests/xcheck_pagerank.rs`).
//!
//! The image/video vertical — harvested `<img>`/video metadata storage
//! ([`Index::replace_images`] / [`Index::replace_videos`]) plus its FTS search
//! ([`Index::image_search`] / [`Index::video_search`], a behaviourally-faithful
//! hand-rolled BM25 stand-in for FTS5 `bm25()`) — lives here too.
//!
//! The `/suggest` typeahead term source lives here too — the FTS5
//! `fts5vocab('fts', 'row')` term dictionary stand-in ([`Index::vocab_prefix`] /
//! [`Index::vocab_candidates`], consumed by [`crate::suggest`]).
//!
//! **Deferred (documented):** the FTS5 inverted-index BM25 search lives in
//! [`crate::ranking`] (a behaviourally-faithful hand-rolled Okapi stand-in, since
//! the stdlib has no FTS5); `more_like_this` awaits its module.

use crate::canonical::host_of;
use crate::htmlparse::Image;
use crate::structured::Video;
use crawlcore::budget::Budget;
use crawlcore::hash::{sha256, to_hex};
use std::collections::{BTreeMap, BTreeSet, HashMap, HashSet};
use std::sync::{Arc, Mutex};

/// The content hash used for exact-duplicate detection: SHA-256 over each part
/// followed by a NUL separator (matching the Python `content_hash(*parts)`).
#[must_use]
pub fn content_hash(parts: &[&str]) -> String {
    let mut buf = Vec::new();
    for p in parts {
        buf.extend_from_slice(p.as_bytes());
        buf.push(0);
    }
    to_hex(&sha256(&buf))
}

/// One indexed document.
#[derive(Clone, Debug, PartialEq)]
pub struct Document {
    /// Stable rowid (assigned in insertion order, starting at 1).
    pub id: i64,
    /// Canonical URL (unique).
    pub url: String,
    /// Page title.
    pub title: String,
    /// Meta description.
    pub description: String,
    /// Visible body text.
    pub body: String,
    /// Host.
    pub host: String,
    /// Guessed language.
    pub lang: String,
    /// When the page was last fetched (epoch seconds).
    pub fetched_at: f64,
    /// The `content_hash` of (title, description, body).
    pub content_hash: String,
    /// The HTTP status recorded.
    pub http_status: i64,
    /// Incoming internal-link count (from [`Index::recompute_incoming`]).
    pub incoming: i64,
    /// Internal PageRank-lite (set by the ranking pass; 0 until then).
    pub rank: f64,
    /// Cross-domain host authority (set by the ranking pass; 0 until then).
    pub host_rank: f64,
    /// Conditional-GET `ETag`.
    pub etag: String,
    /// Conditional-GET `Last-Modified`.
    pub last_modified: String,
    /// Response content type.
    pub content_type: String,
    /// 64-bit near-duplicate SimHash (stored signed).
    pub simhash: i64,
}

/// Fields for [`Index::upsert_document`] (mirrors the Python keyword args).
#[derive(Clone, Debug, Default)]
pub struct DocFields<'a> {
    /// Page title.
    pub title: &'a str,
    /// Meta description.
    pub description: &'a str,
    /// Visible body text.
    pub body: &'a str,
    /// Host (empty → derived from the URL by the caller).
    pub host: &'a str,
    /// Guessed language.
    pub lang: &'a str,
    /// Fetch timestamp (epoch seconds).
    pub fetched_at: f64,
    /// Precomputed content hash; `None` → computed from title/description/body.
    pub content_hash: Option<String>,
    /// HTTP status.
    pub http_status: i64,
    /// `ETag` validator.
    pub etag: &'a str,
    /// `Last-Modified` validator.
    pub last_modified: &'a str,
    /// Content type.
    pub content_type: &'a str,
    /// Signed 64-bit SimHash.
    pub simhash: i64,
}

/// Index statistics (the scalar + top-N breakdowns for the about/stats page).
#[derive(Clone, Debug, PartialEq)]
pub struct Stats {
    /// Number of documents.
    pub docs: usize,
    /// Number of distinct hosts.
    pub hosts: usize,
    /// Number of `(src, dst)` link edges.
    pub links: usize,
    /// Oldest `fetched_at` (> 0), if any.
    pub oldest: Option<f64>,
    /// Newest `fetched_at` (> 0), if any.
    pub newest: Option<f64>,
    /// Top hosts by document count (desc; ties by host asc), up to 10.
    pub top_hosts: Vec<(String, usize)>,
    /// Top languages by document count (desc; ties by lang asc), up to 10.
    pub languages: Vec<(String, usize)>,
}

// ---- image / video verticals ----------------------------------------------
// Metadata already present in the crawled HTML: NO media byte is ever fetched by
// the store, the crawler, or the server — the browser loads a thumbnail from its
// ORIGINAL URL at view time — so these verticals add no fetch and no SSRF surface.

/// Max stored `<img>` rows per document (Python `MAX_IMAGES_PER_DOC`).
pub const MAX_IMAGES_PER_DOC: usize = 100;
/// Max stored video rows per document (Python `MAX_VIDEOS_PER_DOC`).
pub const MAX_VIDEOS_PER_DOC: usize = 100;
/// Default cap on the fuzzy "did you mean" candidate scan — the bound that keeps
/// a long/adversarial query from provoking an unbounded vocabulary scan (Python
/// `FUZZY_SCAN_CAP`, see [`Index::vocab_candidates`]).
pub const FUZZY_SCAN_CAP: usize = 2000;
/// Link-graph edges accepted by one [`Index::add_links`] call — the crawler makes
/// exactly one call per page, so this is the per-page edge cap. Comfortably above
/// any honest page (a large sitemap-ish index page carries a few hundred links)
/// and far below the 100 000 one 1.9 MB `<a href=/N>` page produced.
pub const MAX_EDGES_PER_CALL: usize = 1000;

/// One stored `<img>` row (the harvested `images` table row).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct StoredImage {
    /// Owning document rowid.
    pub doc_id: i64,
    /// Source page URL (already crawled).
    pub page_url: String,
    /// Absolute (resolved) image URL.
    pub src: String,
    /// `alt` text.
    pub alt: String,
    /// `title` attribute.
    pub title: String,
    /// Nearby context text (for relevance).
    pub context: String,
    /// Host of the source page.
    pub host: String,
}

/// One stored video row (the harvested `videos` table row).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct StoredVideo {
    /// Owning document rowid.
    pub doc_id: i64,
    /// Source page URL (already crawled).
    pub page_url: String,
    /// Direct media / stream URL.
    pub video_url: String,
    /// Player embed URL.
    pub embed_url: String,
    /// Canonical watch URL.
    pub watch_url: String,
    /// Title.
    pub title: String,
    /// Thumbnail URL.
    pub thumbnail_url: String,
    /// Player / source key.
    pub source: String,
    /// Duration in whole seconds, if known (negative coerced to `None`).
    pub duration: Option<i64>,
    /// Nearby context text (for relevance).
    pub context: String,
    /// Host of the source page.
    pub host: String,
}

/// One image-search hit — the Python dict `{src, alt, title, page_url, host}`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ImageResult {
    /// Absolute image URL.
    pub src: String,
    /// `alt` text.
    pub alt: String,
    /// `title` attribute.
    pub title: String,
    /// Source page URL.
    pub page_url: String,
    /// Host of the source page.
    pub host: String,
}

/// One video-search hit — the Python dict `{video_url, embed_url, watch_url,
/// title, thumbnail_url, source, duration, page_url, host}`.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct VideoResult {
    /// Direct media / stream URL.
    pub video_url: String,
    /// Player embed URL.
    pub embed_url: String,
    /// Canonical watch URL.
    pub watch_url: String,
    /// Title.
    pub title: String,
    /// Thumbnail URL.
    pub thumbnail_url: String,
    /// Player / source key.
    pub source: String,
    /// Duration in whole seconds, if known.
    pub duration: Option<i64>,
    /// Source page URL.
    pub page_url: String,
    /// Host of the source page.
    pub host: String,
}

/// Everything a query derives from ONE document's text: per-field term
/// frequencies (what BM25 asks for), per-field lengths, and the distinct term set
/// across all three fields.
///
/// Term frequencies rather than the token *lists* on purpose: the list form of a
/// 100 kB body is ~16 000 `String`s — several times the size of the body it came
/// from — while `tf` and the field length are the only things `bm25` ever asks of
/// it, and the map collapses repeats.
pub(crate) struct DocDerived {
    pub(crate) title_tf: HashMap<String, u32>,
    pub(crate) desc_tf: HashMap<String, u32>,
    pub(crate) body_tf: HashMap<String, u32>,
    pub(crate) title_len: usize,
    pub(crate) desc_len: usize,
    pub(crate) body_len: usize,
    /// Distinct terms over title + description + body — the membership test for
    /// `required` / `excluded` / `optional`, and the per-document contribution to
    /// the suggest vocabulary.
    pub(crate) all: HashSet<String>,
}

impl DocDerived {
    fn of(d: &Document) -> Self {
        let tf = |s: &str| -> (HashMap<String, u32>, usize) {
            let ws = crate::ranking::words(s);
            let mut m: HashMap<String, u32> = HashMap::new();
            for w in &ws {
                *m.entry(w.clone()).or_insert(0) += 1;
            }
            (m, ws.len())
        };
        let (title_tf, title_len) = tf(&d.title);
        let (desc_tf, desc_len) = tf(&d.description);
        let (body_tf, body_len) = tf(&d.body);
        let mut all: HashSet<String> = HashSet::new();
        all.extend(title_tf.keys().cloned());
        all.extend(desc_tf.keys().cloned());
        all.extend(body_tf.keys().cloned());
        DocDerived {
            title_tf,
            desc_tf,
            body_tf,
            title_len,
            desc_len,
            body_len,
            all,
        }
    }
}

/// The whole corpus's text-derived state, tagged with the [`Index`] generation it
/// was built from.
///
/// It exists because both readers used to rebuild it per request, under the
/// global index mutex: `build_vocab()` re-tokenised the entire corpus on EVERY
/// `/suggest` (twice when the fuzzy pass runs), and `search()` re-tokenised and
/// re-lowercased every document on EVERY query. On a *small* 200-document ×
/// 100 kB corpus that measured 923 ms for `/suggest?q=lor` and 790 ms for
/// `/search?q=lorem` — and `/suggest` is a per-keystroke typeahead with no rate
/// limit, so one person typing costs the whole server.
pub(crate) struct Derived {
    generation: u64,
    pub(crate) docs: HashMap<i64, DocDerived>,
    /// `term -> document frequency`, the `/suggest` term dictionary.
    pub(crate) vocab: BTreeMap<String, u32>,
}

/// Which keys have been written since the last flush — the *change*, so a
/// segmented store can persist it without persisting the corpus.
///
/// # Why this exists
///
/// `snapshot()` answers "what does the index hold?". Nothing answered "what has
/// changed since you last asked?", so the only honest way to persist was to write
/// everything — which is why a crawl that adds 100 documents to a million-document
/// index rewrites a million documents, and why saves are therefore rare, and why a
/// crash between them costs the whole run. This log is the missing question.
///
/// The sets are of *keys*, not of records: the records are read out of the index
/// at flush time. So the log costs a `BTreeSet` entry per touched key and is
/// emptied by [`Index::drain_changes`] on every flush — its size is a batch, not a
/// corpus.
///
/// Ranking signals are tracked **separately** from document text
/// ([`DirtyLog::ranks`] vs [`DirtyLog::docs`]) and that separation is the point:
/// `finalize()` changes `incoming`/`rank`/`host_rank` on every document in the
/// corpus. If those rode along in the document record, every finalize would
/// rewrite every document body — a full snapshot by another name. Split out, a
/// finalize writes ~40 bytes per document instead of a page of text.
#[derive(Default)]
pub(crate) struct DirtyLog {
    /// Documents whose stored fields changed (`d|url` records).
    pub(crate) docs: BTreeSet<i64>,
    /// Documents whose ranking signals changed (`r|id` records).
    pub(crate) ranks: BTreeSet<i64>,
    /// Link edges added (`l|src|dst` records). Edges are never removed, so this
    /// never needs a tombstone.
    pub(crate) links: BTreeSet<(String, String)>,
    /// Hosts whose authority entry was *touched*, which includes being dropped:
    /// `compute_host_authority` clears the map and rebuilds it, so a host that
    /// stops being reachable must be tombstoned, not merely not-rewritten.
    pub(crate) hosts: BTreeSet<String>,
    /// Documents whose harvested image rows were replaced (`i|id`); an empty
    /// replacement becomes a tombstone.
    pub(crate) images: BTreeSet<i64>,
    /// Documents whose harvested video rows were replaced (`v|id`).
    pub(crate) videos: BTreeSet<i64>,
    /// Whether `next_id` moved and the `m` record needs rewriting.
    pub(crate) meta: bool,
}

impl DirtyLog {
    pub(crate) fn is_empty(&self) -> bool {
        self.docs.is_empty()
            && self.ranks.is_empty()
            && self.links.is_empty()
            && self.hosts.is_empty()
            && self.images.is_empty()
            && self.videos.is_empty()
            && !self.meta
    }
}

/// A dependency-free document store + link graph.
#[derive(Default)]
pub struct Index {
    docs: BTreeMap<i64, Document>,
    url_to_id: HashMap<String, i64>,
    /// Corpus tokenisations performed, for [`Index::derived_rebuilds`].
    derived_rebuilds: std::sync::atomic::AtomicU64,
    links: HashMap<(String, String), bool>, // (src, dst) → internal
    host_authority: HashMap<String, f64>,   // cross-domain host PageRank (0..1)
    images: Vec<StoredImage>,               // harvested <img> metadata rows
    videos: Vec<StoredVideo>,               // harvested video-signal rows
    next_id: i64,
    /// Whether writes append to [`Index::dirty`]. Off unless someone asked for it
    /// with [`Index::track_changes`], so the blob path — which has no use for a
    /// change log — pays one predictable branch per write and not a `BTreeSet`
    /// insert.
    tracking: bool,
    /// The pending change set; meaningful only while `tracking`.
    dirty: DirtyLog,
    /// Bumped by every write that changes document TEXT, and only by those. The
    /// ranking signals (`incoming`, `rank`, `host_rank`) and `fetched_at` are
    /// deliberately NOT covered: readers take them straight off the [`Document`],
    /// so a `finalize()` between two searches is visible immediately without
    /// throwing away a corpus-sized tokenisation.
    generation: u64,
    /// The memoised [`Derived`] for `generation`, or `None`/stale.
    derived: Mutex<Option<Arc<Derived>>>,
}

impl Index {
    /// A fresh, empty index.
    #[must_use]
    pub fn new() -> Self {
        Index {
            next_id: 1,
            ..Index::default()
        }
    }

    /// The corpus's text-derived state, rebuilt only if a write has changed
    /// document text since it was last built.
    ///
    /// Returns an `Arc` rather than a borrow so the internal lock is released
    /// before the caller uses it — the guard must never be held across a search.
    /// How many times THIS index has rebuilt its derived cache — one per corpus
    /// tokenisation. A query that finds the cache valid does not increment it,
    /// which is what makes "the corpus is not re-tokenised per request" a
    /// deterministic assertion rather than a stopwatch reading. Per-index and
    /// not a process-wide static, because the test suite shares one process and
    /// runs in parallel: a global counter would move under unrelated tests.
    #[must_use]
    pub fn derived_rebuilds(&self) -> u64 {
        self.derived_rebuilds
            .load(std::sync::atomic::Ordering::Relaxed)
    }

    pub(crate) fn derived(&self) -> Arc<Derived> {
        let mut slot = self.derived.lock().expect("derived cache mutex");
        if let Some(d) = slot.as_ref() {
            if d.generation == self.generation {
                return Arc::clone(d);
            }
        }
        // Counted, not timed. Whether the corpus is re-tokenised per request is
        // a fact about control flow; asserting it with a stopwatch made the
        // test flake at 366 ms vs 360 ms on a loaded machine, because ten
        // cached queries and one uncached one are the same order of magnitude.
        self.derived_rebuilds
            .fetch_add(1, std::sync::atomic::Ordering::Relaxed);
        let mut docs: HashMap<i64, DocDerived> = HashMap::with_capacity(self.docs.len());
        let mut vocab: BTreeMap<String, u32> = BTreeMap::new();
        for d in self.docs.values() {
            let dd = DocDerived::of(d);
            // The vocabulary counts each term once per DOCUMENT, which is exactly
            // the distinct-term set already computed for the match tests.
            for t in &dd.all {
                *vocab.entry(t.clone()).or_insert(0) += 1;
            }
            docs.insert(d.id, dd);
        }
        let built = Arc::new(Derived {
            generation: self.generation,
            docs,
            vocab,
        });
        *slot = Some(Arc::clone(&built));
        built
    }

    /// Declare that document TEXT changed, so the next reader rebuilds
    /// [`Derived`]. Every write path that touches `title`/`description`/`body`
    /// must call this; forgetting it serves stale search results.
    fn invalidate_derived(&mut self) {
        self.generation = self.generation.wrapping_add(1);
    }

    // ---- incremental persistence: the change log ---------------------------

    /// Start (or stop) recording which keys each write touches, so
    /// [`crate::segindex`] can persist the change rather than the corpus.
    ///
    /// Off by default. Turning it on marks **everything currently in the index**
    /// dirty, which is the only safe answer: a store that started tracking
    /// half-way through would flush a batch that silently omitted whatever came
    /// before it, and the omission would only surface as a document that stopped
    /// being findable after a restart. Turning it off clears the log.
    ///
    /// This changes no observable behaviour of any read — `snapshot`, `search`,
    /// `stats` and the goldens are untouched by it.
    pub fn track_changes(&mut self, on: bool) {
        self.tracking = on;
        self.dirty = DirtyLog::default();
        if on {
            self.dirty.docs = self.docs.keys().copied().collect();
            self.dirty.ranks.clone_from(&self.dirty.docs);
            self.dirty.links = self.links.keys().cloned().collect();
            self.dirty.hosts = self.host_authority.keys().cloned().collect();
            self.dirty.images = self.images.iter().map(|r| r.doc_id).collect();
            self.dirty.videos = self.videos.iter().map(|r| r.doc_id).collect();
            self.dirty.meta = true;
        }
    }

    /// Whether change tracking is on.
    #[must_use]
    pub fn is_tracking_changes(&self) -> bool {
        self.tracking
    }

    /// Take the pending change set, leaving an empty one behind.
    pub(crate) fn drain_changes(&mut self) -> DirtyLog {
        std::mem::take(&mut self.dirty)
    }

    /// Peek at the pending change set (for tests and for a flush that wants to
    /// know whether it has anything to do).
    pub(crate) fn pending_changes(&self) -> &DirtyLog {
        &self.dirty
    }

    /// Note that document `id`'s stored fields changed.
    fn mark_doc(&mut self, id: i64) {
        if self.tracking {
            self.dirty.docs.insert(id);
        }
    }

    /// Note that document `id`'s ranking signals changed.
    fn mark_rank(&mut self, id: i64) {
        if self.tracking {
            self.dirty.ranks.insert(id);
        }
    }

    /// Note that the whole corpus's ranking signals were recomputed.
    fn mark_all_ranks(&mut self) {
        if self.tracking {
            self.dirty.ranks.extend(self.docs.keys().copied());
        }
    }

    /// Insert or update the document for `url`; returns its rowid.
    pub fn upsert_document(&mut self, url: &str, f: DocFields) -> i64 {
        self.invalidate_derived();
        let chash = f
            .content_hash
            .clone()
            .unwrap_or_else(|| content_hash(&[f.title, f.description, f.body]));
        if let Some(&id) = self.url_to_id.get(url) {
            let d = self.docs.get_mut(&id).expect("indexed doc exists");
            d.title = f.title.to_string();
            d.description = f.description.to_string();
            d.body = f.body.to_string();
            d.host = f.host.to_string();
            d.lang = f.lang.to_string();
            d.fetched_at = f.fetched_at;
            d.content_hash = chash;
            d.http_status = f.http_status;
            d.etag = f.etag.to_string();
            d.last_modified = f.last_modified.to_string();
            d.content_type = f.content_type.to_string();
            d.simhash = f.simhash;
            self.mark_doc(id);
            id
        } else {
            let id = self.next_id;
            self.next_id += 1;
            if self.tracking {
                self.dirty.docs.insert(id);
                self.dirty.meta = true;
            }
            self.docs.insert(
                id,
                Document {
                    id,
                    url: url.to_string(),
                    title: f.title.to_string(),
                    description: f.description.to_string(),
                    body: f.body.to_string(),
                    host: f.host.to_string(),
                    lang: f.lang.to_string(),
                    fetched_at: f.fetched_at,
                    content_hash: chash,
                    http_status: f.http_status,
                    incoming: 0,
                    rank: 0.0,
                    host_rank: 0.0,
                    etag: f.etag.to_string(),
                    last_modified: f.last_modified.to_string(),
                    content_type: f.content_type.to_string(),
                    simhash: f.simhash,
                },
            );
            self.url_to_id.insert(url.to_string(), id);
            id
        }
    }

    /// True if any document has this `content_hash`.
    #[must_use]
    pub fn hash_exists(&self, chash: &str) -> bool {
        self.docs.values().any(|d| d.content_hash == chash)
    }

    /// The URL of the lowest-id document with this `content_hash` (the crawler's
    /// exact-dup lookup; `SELECT url FROM docs WHERE content_hash=? LIMIT 1`).
    #[must_use]
    pub fn url_with_content_hash(&self, chash: &str) -> Option<String> {
        self.docs
            .values()
            .find(|d| d.content_hash == chash)
            .map(|d| d.url.clone())
    }

    /// The `(etag, last_modified)` validators for `url`, or `("", "")`.
    #[must_use]
    pub fn get_validators(&self, url: &str) -> (String, String) {
        match self.url_to_id.get(url).and_then(|id| self.docs.get(id)) {
            Some(d) => (d.etag.clone(), d.last_modified.clone()),
            None => (String::new(), String::new()),
        }
    }

    /// A conditional GET returned 304: bump `fetched_at` and refresh any re-sent
    /// validators, without touching the indexed content. No-op if `url` unknown.
    pub fn touch_revalidated(
        &mut self,
        url: &str,
        fetched_at: f64,
        etag: Option<&str>,
        last_modified: Option<&str>,
    ) {
        if let Some(&id) = self.url_to_id.get(url) {
            let d = self.docs.get_mut(&id).expect("doc exists");
            d.fetched_at = fetched_at;
            if let Some(e) = etag {
                if !e.is_empty() {
                    d.etag = e.to_string();
                }
            }
            if let Some(lm) = last_modified {
                if !lm.is_empty() {
                    d.last_modified = lm.to_string();
                }
            }
            // A 304 changes stored fields (`fetched_at`, validators) and so must
            // reach the segmented store: skip this and a resumed crawl refetches
            // every revalidated page, which is exactly the work the validators
            // exist to avoid.
            self.mark_doc(id);
        }
    }

    /// Documents whose `fetched_at + interval` has passed → `[(url, host), …]`,
    /// ordered by `fetched_at` (ties by rowid). Only rows with `fetched_at > 0`.
    #[must_use]
    pub fn due_for_recrawl(&self, interval: f64, now: f64) -> Vec<(String, String)> {
        let cutoff = now - interval;
        let mut due: Vec<&Document> = self
            .docs
            .values()
            .filter(|d| d.fetched_at > 0.0 && d.fetched_at <= cutoff)
            .collect();
        due.sort_by(|a, b| {
            a.fetched_at
                .partial_cmp(&b.fetched_at)
                .unwrap_or(std::cmp::Ordering::Equal)
                .then(a.id.cmp(&b.id))
        });
        due.into_iter()
            .map(|d| (d.url.clone(), d.host.clone()))
            .collect()
    }

    /// Record outbound links: `edges` is `(dst, internal)`. Duplicate `(src, dst)`
    /// pairs are ignored (first write wins), matching `INSERT OR IGNORE`.
    ///
    /// At most [`MAX_EDGES_PER_CALL`] edges are taken and the number REFUSED is
    /// returned, so a caller can report what it lost. The link graph is a map of
    /// owned URL pairs and is the one structure a single hostile page can grow
    /// without bound: 1.9 MB of `<a href=/N>` produced 100 000 edges and +13 MB
    /// of RSS, which at the default `per_host_budget` of 500 pages is ~6.5 GB from
    /// one host. The cap is here as well as in the crawler because the store must
    /// not depend on every caller remembering it.
    pub fn add_links(&mut self, src: &str, edges: &[(String, bool)]) -> usize {
        let mut budget = Budget::new(MAX_EDGES_PER_CALL);
        // Disjoint field borrows, so the change log can be written from inside the
        // `entry` match without cloning the key twice.
        let tracking = self.tracking;
        let dirty = &mut self.dirty;
        let links = &mut self.links;
        for (dst, internal) in edges {
            if budget.take(1) == 0 {
                break;
            }
            if let std::collections::hash_map::Entry::Vacant(slot) =
                links.entry((src.to_string(), dst.clone()))
            {
                // Only a NEW edge is a change: `INSERT OR IGNORE` semantics mean a
                // repeat writes nothing, and flushing it would put an identical
                // record in every segment for no reason.
                if tracking {
                    dirty.links.insert(slot.key().clone());
                }
                slot.insert(*internal);
            }
        }
        edges.len().saturating_sub(budget.spent())
    }

    /// Refresh every document's `incoming` count from the internal link graph
    /// (`links.dst == docs.url AND internal`).
    pub fn recompute_incoming(&mut self) {
        let mut counts: HashMap<&str, i64> = HashMap::new();
        for ((_, dst), internal) in &self.links {
            if *internal {
                *counts.entry(dst.as_str()).or_insert(0) += 1;
            }
        }
        // Snapshot to avoid borrowing docs immutably + mutably at once.
        let updates: Vec<(i64, i64)> = self
            .docs
            .values()
            .map(|d| (d.id, *counts.get(d.url.as_str()).unwrap_or(&0)))
            .collect();
        for (id, c) in updates {
            self.docs.get_mut(&id).expect("doc exists").incoming = c;
            self.mark_rank(id);
        }
    }

    /// The document for `url`, if indexed.
    #[must_use]
    pub fn get_doc(&self, url: &str) -> Option<&Document> {
        self.url_to_id.get(url).and_then(|id| self.docs.get(id))
    }

    /// Number of indexed documents.
    #[must_use]
    pub fn doc_count(&self) -> usize {
        self.docs.len()
    }

    /// All documents, in ascending rowid (insertion) order — the corpus a search
    /// pass iterates over.
    pub fn all_docs(&self) -> impl Iterator<Item = &Document> {
        self.docs.values()
    }

    /// Index statistics for the about/stats page.
    #[must_use]
    pub fn stats(&self) -> Stats {
        let docs = self.docs.len();
        let mut host_counts: BTreeMap<String, usize> = BTreeMap::new();
        let mut lang_counts: BTreeMap<String, usize> = BTreeMap::new();
        let mut oldest: Option<f64> = None;
        let mut newest: Option<f64> = None;
        for d in self.docs.values() {
            *host_counts.entry(d.host.clone()).or_insert(0) += 1;
            if !d.lang.is_empty() {
                *lang_counts.entry(d.lang.clone()).or_insert(0) += 1;
            }
            if d.fetched_at > 0.0 {
                oldest = Some(oldest.map_or(d.fetched_at, |m: f64| m.min(d.fetched_at)));
                newest = Some(newest.map_or(d.fetched_at, |m: f64| m.max(d.fetched_at)));
            }
        }
        Stats {
            docs,
            hosts: host_counts.len(),
            links: self.links.len(),
            oldest,
            newest,
            top_hosts: top_n(&host_counts, 10),
            languages: top_n(&lang_counts, 10),
        }
    }

    /// Cross-domain host authority for `host` (0..1), if computed.
    #[must_use]
    pub fn host_authority(&self, host: &str) -> Option<f64> {
        self.host_authority.get(host).copied()
    }

    /// PageRank-lite over the internal link graph, written to `doc.rank`
    /// (normalised so the max is 1). No edges → every rank is 0. Mirrors the
    /// Python `compute_pagerank`.
    pub fn compute_pagerank(&mut self, damping: f64, iterations: usize, tol: f64) {
        // Every document's `rank` is (re)written by this pass — including to zero
        // on the no-edges paths below — so mark them all up front rather than at
        // each of the three assignment sites. Marking at entry cannot miss a
        // branch, and an O(n) set insert is invisible beside an O(n × iterations)
        // power iteration. Ranks are a SEPARATE record from the document, so this
        // costs the segmented store one small record per document, not a rewrite
        // of every body.
        self.mark_all_ranks();
        let urls: Vec<String> = self.docs.values().map(|d| d.url.clone()).collect();
        let n = urls.len();
        if n == 0 {
            return;
        }
        let idx: HashMap<&str, usize> = urls
            .iter()
            .enumerate()
            .map(|(i, u)| (u.as_str(), i))
            .collect();
        let mut out: Vec<Vec<usize>> = vec![Vec::new(); n];
        let mut have_edges = false;
        let mut keys: Vec<&(String, String)> = self
            .links
            .iter()
            .filter(|(_, v)| **v)
            .map(|(k, _)| k)
            .collect();
        keys.sort();
        for (src, dst) in keys {
            if let (Some(&si), Some(&di)) = (idx.get(src.as_str()), idx.get(dst.as_str())) {
                if si != di {
                    out[si].push(di);
                    have_edges = true;
                }
            }
        }
        if !have_edges {
            for d in self.docs.values_mut() {
                d.rank = 0.0;
            }
            return;
        }
        let nf = n as f64;
        let mut pr = vec![1.0 / nf; n];
        let base = (1.0 - damping) / nf;
        for _ in 0..iterations {
            let mut new = vec![base; n];
            let mut dangling = 0.0;
            for i in 0..n {
                if out[i].is_empty() {
                    dangling += damping * pr[i] / nf;
                } else {
                    let share = damping * pr[i] / out[i].len() as f64;
                    for &j in &out[i] {
                        new[j] += share;
                    }
                }
            }
            if dangling != 0.0 {
                for v in &mut new {
                    *v += dangling;
                }
            }
            let delta: f64 = (0..n).map(|i| (new[i] - pr[i]).abs()).sum();
            pr = new;
            if delta < tol {
                break;
            }
        }
        let top = normalizer(&pr);
        let ranks: Vec<(i64, f64)> = self
            .docs
            .values()
            .map(|d| (d.id, pr[idx[d.url.as_str()]] / top))
            .collect();
        for (id, r) in ranks {
            self.docs.get_mut(&id).expect("doc").rank = r;
        }
    }

    /// Cross-domain host-level PageRank — the authority signal — over the graph of
    /// hosts linked by cross-domain edges (weight = distinct source pages). Written
    /// to `host_authority` and denormalised onto `doc.host_rank` (max = 1). No
    /// cross-domain edges → every `host_rank` is 0. Mirrors the Python
    /// `compute_host_authority`.
    pub fn compute_host_authority(&mut self, damping: f64, iterations: usize, tol: f64) {
        // Same reasoning as `compute_pagerank` for `host_rank`; plus every host
        // already in the map, because this pass CLEARS it and a host that no
        // longer appears must be tombstoned in the segmented store rather than
        // left behind as a stale authority score from a previous crawl.
        self.mark_all_ranks();
        if self.tracking {
            let existing: Vec<String> = self.host_authority.keys().cloned().collect();
            self.dirty.hosts.extend(existing);
        }
        let mut adj: HashMap<String, HashMap<String, f64>> = HashMap::new();
        let mut hosts: std::collections::BTreeSet<String> = std::collections::BTreeSet::new();
        let mut keys: Vec<&(String, String)> = self.links.keys().collect();
        keys.sort();
        for (src, dst) in keys {
            let sh = host_of(src);
            let dh = host_of(dst);
            if sh.is_empty() || dh.is_empty() || sh == dh {
                continue;
            }
            hosts.insert(sh.clone());
            hosts.insert(dh.clone());
            *adj.entry(sh).or_default().entry(dh).or_insert(0.0) += 1.0;
        }
        for d in self.docs.values() {
            if !d.host.is_empty() {
                hosts.insert(d.host.clone());
            }
        }
        self.host_authority.clear();
        if hosts.is_empty() {
            for d in self.docs.values_mut() {
                d.host_rank = 0.0;
            }
            return;
        }
        let nodes: Vec<String> = hosts.into_iter().collect();
        let idx: HashMap<&str, usize> = nodes
            .iter()
            .enumerate()
            .map(|(i, h)| (h.as_str(), i))
            .collect();
        let n = nodes.len();
        let mut out: Vec<Vec<(usize, f64)>> = vec![Vec::new(); n];
        let mut out_w = vec![0.0f64; n];
        let mut have_edges = false;
        for (i, h) in nodes.iter().enumerate() {
            if let Some(targets) = adj.get(h) {
                let mut tks: Vec<&String> = targets.keys().collect();
                tks.sort();
                for dh in tks {
                    let w = targets[dh];
                    out[i].push((idx[dh.as_str()], w));
                    out_w[i] += w;
                    have_edges = true;
                }
            }
        }
        if !have_edges {
            for d in self.docs.values_mut() {
                d.host_rank = 0.0;
            }
            return;
        }
        let nf = n as f64;
        let mut pr = vec![1.0 / nf; n];
        let base = (1.0 - damping) / nf;
        for _ in 0..iterations {
            let mut new = vec![base; n];
            let mut dangling = 0.0;
            for i in 0..n {
                if out_w[i] > 0.0 {
                    let factor = damping * pr[i] / out_w[i];
                    for &(j, w) in &out[i] {
                        new[j] += factor * w;
                    }
                } else {
                    dangling += damping * pr[i] / nf;
                }
            }
            if dangling != 0.0 {
                for v in &mut new {
                    *v += dangling;
                }
            }
            let delta: f64 = (0..n).map(|i| (new[i] - pr[i]).abs()).sum();
            pr = new;
            if delta < tol {
                break;
            }
        }
        let top = normalizer(&pr);
        for (i, h) in nodes.iter().enumerate() {
            if self.tracking {
                self.dirty.hosts.insert(h.clone());
            }
            self.host_authority.insert(h.clone(), pr[i] / top);
        }
        let updates: Vec<(i64, f64)> = self
            .docs
            .values()
            .map(|d| {
                (
                    d.id,
                    self.host_authority.get(&d.host).copied().unwrap_or(0.0),
                )
            })
            .collect();
        for (id, hr) in updates {
            self.docs.get_mut(&id).expect("doc").host_rank = hr;
        }
    }

    /// Replace the stored `<img>` metadata for document `doc_id`.
    ///
    /// Old rows for the doc are cleared first (so a recrawl refreshes them), then
    /// up to [`MAX_IMAGES_PER_DOC`] rows are stored — skipping any image with an
    /// empty `src`. Returns the number stored. Performs no network I/O whatsoever.
    /// Mirrors the Python `replace_images`.
    pub fn replace_images(
        &mut self,
        doc_id: i64,
        page_url: &str,
        host: &str,
        images: &[Image],
    ) -> usize {
        if self.tracking {
            self.dirty.images.insert(doc_id);
        }
        self.images.retain(|r| r.doc_id != doc_id);
        let mut added = 0usize;
        for im in images {
            if im.src.is_empty() {
                continue;
            }
            self.images.push(StoredImage {
                doc_id,
                page_url: page_url.to_string(),
                src: im.src.clone(),
                alt: im.alt.clone(),
                title: im.title.clone(),
                context: im.context.clone(),
                host: host.to_string(),
            });
            added += 1;
            if added >= MAX_IMAGES_PER_DOC {
                break;
            }
        }
        added
    }

    /// Replace the stored video metadata for document `doc_id`.
    ///
    /// Old rows for the doc are cleared first, then up to [`MAX_VIDEOS_PER_DOC`]
    /// rows are stored. A video with no linkable URL at all (`video_url`,
    /// `embed_url` and `watch_url` all empty) is skipped; a negative duration is
    /// coerced to `None`. Returns the number stored. Performs no network I/O.
    /// Mirrors the Python `replace_videos`.
    pub fn replace_videos(
        &mut self,
        doc_id: i64,
        page_url: &str,
        host: &str,
        videos: &[Video],
    ) -> usize {
        if self.tracking {
            self.dirty.videos.insert(doc_id);
        }
        self.videos.retain(|r| r.doc_id != doc_id);
        let mut added = 0usize;
        for v in videos {
            if v.video_url.is_empty() && v.embed_url.is_empty() && v.watch_url.is_empty() {
                continue;
            }
            let duration = match v.duration {
                Some(d) if d < 0 => None,
                other => other,
            };
            self.videos.push(StoredVideo {
                doc_id,
                page_url: page_url.to_string(),
                video_url: v.video_url.clone(),
                embed_url: v.embed_url.clone(),
                watch_url: v.watch_url.clone(),
                title: v.title.clone(),
                thumbnail_url: v.thumbnail.clone(),
                source: v.source.clone(),
                duration,
                context: v.context.clone(),
                host: host.to_string(),
            });
            added += 1;
            if added >= MAX_VIDEOS_PER_DOC {
                break;
            }
        }
        added
    }

    /// Full-text search over harvested image `alt`/`title`/`context`.
    ///
    /// The query is tokenised with the same `[^\W_]+` tokeniser as the main search
    /// (first 12 terms), matched implicit-AND (every term must appear in the row),
    /// and ordered by a hand-rolled BM25 over the three FTS columns — the
    /// behaviourally-faithful stand-in for FTS5 `bm25(images_fts)`, exactly as
    /// [`crate::ranking::search`] stands in for the document FTS. Bounded by
    /// `limit`. An empty query returns `[]`. Mirrors the Python `image_search`.
    #[must_use]
    pub fn image_search(&self, query: &str, limit: usize) -> Vec<ImageResult> {
        let words: Vec<String> = crate::ranking::words(query).into_iter().take(12).collect();
        if words.is_empty() {
            return Vec::new();
        }
        media_search_indices(&self.images, &words, limit, |im| {
            vec![im.alt.as_str(), im.title.as_str(), im.context.as_str()]
        })
        .into_iter()
        .map(|i| {
            let r = &self.images[i];
            ImageResult {
                src: r.src.clone(),
                alt: r.alt.clone(),
                title: r.title.clone(),
                page_url: r.page_url.clone(),
                host: r.host.clone(),
            }
        })
        .collect()
    }

    /// Full-text search over harvested video `title`/`context`.
    ///
    /// Same tokenisation, implicit-AND matching, and BM25 ordering as
    /// [`Index::image_search`] (over the two `videos_fts` columns), bounded by
    /// `limit`. An empty query returns `[]`. Mirrors the Python `video_search`.
    #[must_use]
    pub fn video_search(&self, query: &str, limit: usize) -> Vec<VideoResult> {
        let words: Vec<String> = crate::ranking::words(query).into_iter().take(12).collect();
        if words.is_empty() {
            return Vec::new();
        }
        media_search_indices(&self.videos, &words, limit, |v| {
            vec![v.title.as_str(), v.context.as_str()]
        })
        .into_iter()
        .map(|i| {
            let r = &self.videos[i];
            VideoResult {
                video_url: r.video_url.clone(),
                embed_url: r.embed_url.clone(),
                watch_url: r.watch_url.clone(),
                title: r.title.clone(),
                thumbnail_url: r.thumbnail_url.clone(),
                source: r.source.clone(),
                duration: r.duration,
                page_url: r.page_url.clone(),
                host: r.host.clone(),
            }
        })
        .collect()
    }

    // ---- suggest / autocomplete term source --------------------------------
    // The FTS5 `fts5vocab('fts', 'row')` term dictionary stand-in: the set of
    // distinct tokens over the corpus, each carrying its DOCUMENT frequency (the
    // `doc` column). Tokenised with the same [`crate::ranking::words`] the BM25
    // search uses as the FTS5 `unicode61` stand-in, so counts are byte-identical
    // to SQLite on the ASCII/diacritic-free subset (diacritic folding is not
    // reproduced — the documented "behaviourally faithful" standard).

    /// The term dictionary: `term -> document frequency`. For each document the
    /// DISTINCT term set across `title` + `description` + `body` is taken (so a
    /// term repeated within or across a document's fields counts that document
    /// once), and every such term's count is incremented by one.
    ///
    /// Served from the [`Derived`] memo: it used to be rebuilt from the raw corpus
    /// on every call, and `/suggest` calls it up to twice per keystroke.
    fn build_vocab(&self) -> Arc<Derived> {
        self.derived()
    }

    /// Indexed terms beginning with `prefix`, most-frequent first — the prefix
    /// completion source for `/suggest`. The (lower-cased) `prefix` selects the
    /// term range `[prefix, prefix_upper(prefix))` (or a `starts_with` scan when
    /// [`prefix_upper`] yields `None`), ordered `doc` DESC then term ASC, capped at
    /// `limit`. An empty prefix returns `[]`. Mirrors the Python `vocab_prefix`.
    #[must_use]
    pub fn vocab_prefix(&self, prefix: &str, limit: usize) -> Vec<(String, u32)> {
        let prefix = prefix.to_lowercase();
        if prefix.is_empty() {
            return Vec::new();
        }
        let derived = self.build_vocab();
        let vocab = &derived.vocab;
        let mut rows: Vec<(String, u32)> = match prefix_upper(&prefix) {
            Some(hi) => vocab
                .range(prefix.clone()..hi)
                .map(|(t, &d)| (t.clone(), d))
                .collect(),
            None => vocab
                .range(prefix.clone()..)
                .take_while(|(t, _)| t.starts_with(&prefix))
                .map(|(t, &d)| (t.clone(), d))
                .collect(),
        };
        // The range scan is already term-ASC; a stable sort by `doc` DESC yields
        // the exact `ORDER BY doc DESC, term` (ties broken term-ASC).
        rows.sort_by_key(|&(_, d)| std::cmp::Reverse(d));
        rows.truncate(limit);
        rows
    }

    /// A BOUNDED sample of frequent terms sharing `word`'s first character — the
    /// candidate set the edit-distance fallback scans (typos usually preserve the
    /// first letter). The (lower-cased) `word`'s first character `c0` selects the
    /// range `[c0, prefix_upper(c0))`, ordered `doc` DESC (ties term-ASC via the
    /// stable sort over the term-ASC range), capped at `limit` (callers pass
    /// [`FUZZY_SCAN_CAP`]). An empty word returns `[]`. Mirrors the Python
    /// `vocab_candidates`.
    #[must_use]
    pub fn vocab_candidates(&self, word: &str, limit: usize) -> Vec<(String, u32)> {
        let word = word.to_lowercase();
        let c0 = match word.chars().next() {
            Some(c) => c.to_string(),
            None => return Vec::new(),
        };
        let derived = self.build_vocab();
        let vocab = &derived.vocab;
        let mut rows: Vec<(String, u32)> = match prefix_upper(&c0) {
            Some(hi) => vocab.range(c0..hi).map(|(t, &d)| (t.clone(), d)).collect(),
            None => vocab.range(c0..).map(|(t, &d)| (t.clone(), d)).collect(),
        };
        rows.sort_by_key(|&(_, d)| std::cmp::Reverse(d));
        rows.truncate(limit);
        rows
    }

    /// Post-crawl finalise: recompute incoming counts, page PageRank (30 iters),
    /// and cross-domain host authority (50 iters), with the Python defaults.
    pub fn finalize(&mut self) {
        self.recompute_incoming();
        self.compute_pagerank(0.85, 30, 1e-6);
        self.compute_host_authority(0.85, 50, 1e-6);
    }

    /// Serialise the whole in-memory store to a self-describing binary blob — the
    /// persistence unit.
    ///
    /// The Python engine persists to SQLite; the Rust store is in-memory, so a
    /// crawl's state survives to a later `serve`/`stats` only through this
    /// hand-rolled, dependency-free, length-prefixed snapshot (the same
    /// [`Writer`]/[`Reader`] approach `onioncrawler`/`torrentds` use for their
    /// database-free stores). Every [`Document`] field, the `(src → dst)` link
    /// graph, the cross-domain `host_authority`, and the harvested image/video
    /// rows are written; the `url → id` map and the suggest vocab are *derived*
    /// (rebuilt on [`Index::restore`] and on demand), so they are not stored.
    ///
    /// Collections with a non-deterministic iteration order (the link graph and
    /// host authority, both `HashMap`s) are emitted in a stable key order, so the
    /// blob is reproducible for a given logical state; `docs` is a `BTreeMap`
    /// (already rowid-ordered) and the image/video `Vec`s keep insertion order.
    #[must_use]
    pub fn snapshot(&self) -> Vec<u8> {
        let mut w = Writer::new();
        w.u8(INDEX_SNAPSHOT_VERSION);
        w.i64(self.next_id);

        w.len(self.docs.len());
        for d in self.docs.values() {
            w.i64(d.id);
            w.str(&d.url);
            w.str(&d.title);
            w.str(&d.description);
            w.str(&d.body);
            w.str(&d.host);
            w.str(&d.lang);
            w.f64(d.fetched_at);
            w.str(&d.content_hash);
            w.i64(d.http_status);
            w.i64(d.incoming);
            w.f64(d.rank);
            w.f64(d.host_rank);
            w.str(&d.etag);
            w.str(&d.last_modified);
            w.str(&d.content_type);
            w.i64(d.simhash);
        }

        let mut links: Vec<(&(String, String), &bool)> = self.links.iter().collect();
        links.sort_by(|a, b| a.0.cmp(b.0));
        w.len(links.len());
        for ((src, dst), internal) in links {
            w.str(src);
            w.str(dst);
            w.bool(*internal);
        }

        let mut auth: Vec<(&String, &f64)> = self.host_authority.iter().collect();
        auth.sort_by(|a, b| a.0.cmp(b.0));
        w.len(auth.len());
        for (host, a) in auth {
            w.str(host);
            w.f64(*a);
        }

        w.len(self.images.len());
        for im in &self.images {
            w.i64(im.doc_id);
            w.str(&im.page_url);
            w.str(&im.src);
            w.str(&im.alt);
            w.str(&im.title);
            w.str(&im.context);
            w.str(&im.host);
        }

        w.len(self.videos.len());
        for v in &self.videos {
            w.i64(v.doc_id);
            w.str(&v.page_url);
            w.str(&v.video_url);
            w.str(&v.embed_url);
            w.str(&v.watch_url);
            w.str(&v.title);
            w.str(&v.thumbnail_url);
            w.str(&v.source);
            w.opt_i64(v.duration);
            w.str(&v.context);
            w.str(&v.host);
        }

        w.into_bytes()
    }

    /// Rebuild an index from a [`Index::snapshot`] blob. Returns `None` if the blob
    /// is truncated, malformed, or carries a version this build does not
    /// understand — a corrupt blob can never panic.
    ///
    /// Every [`Document`] field round-trips exactly (id, url, title, description,
    /// body, host, lang, fetched_at, content_hash, http_status, incoming, rank,
    /// host_rank, etag, last_modified, content_type, simhash), as do the link
    /// edges, `host_authority`, and the stored images/videos. The `url → id` map
    /// is rebuilt from the docs and the suggest vocab is rebuilt on demand, so
    /// `restore(&snapshot())` reproduces the index's full observable state.
    #[must_use]
    pub fn restore(blob: &[u8]) -> Option<Index> {
        let mut r = Reader::new(blob);
        if r.u8()? != INDEX_SNAPSHOT_VERSION {
            return None;
        }
        let mut ix = Index::new();
        ix.next_id = r.i64()?;

        let ndocs = r.len()?;
        for _ in 0..ndocs {
            let d = Document {
                id: r.i64()?,
                url: r.str()?,
                title: r.str()?,
                description: r.str()?,
                body: r.str()?,
                host: r.str()?,
                lang: r.str()?,
                fetched_at: r.f64()?,
                content_hash: r.str()?,
                http_status: r.i64()?,
                incoming: r.i64()?,
                rank: r.f64()?,
                host_rank: r.f64()?,
                etag: r.str()?,
                last_modified: r.str()?,
                content_type: r.str()?,
                simhash: r.i64()?,
            };
            ix.url_to_id.insert(d.url.clone(), d.id);
            ix.docs.insert(d.id, d);
        }

        let nlinks = r.len()?;
        for _ in 0..nlinks {
            let src = r.str()?;
            let dst = r.str()?;
            let internal = r.bool()?;
            ix.links.insert((src, dst), internal);
        }

        let nauth = r.len()?;
        for _ in 0..nauth {
            let host = r.str()?;
            let a = r.f64()?;
            ix.host_authority.insert(host, a);
        }

        let nimg = r.len()?;
        for _ in 0..nimg {
            ix.images.push(StoredImage {
                doc_id: r.i64()?,
                page_url: r.str()?,
                src: r.str()?,
                alt: r.str()?,
                title: r.str()?,
                context: r.str()?,
                host: r.str()?,
            });
        }

        let nvid = r.len()?;
        for _ in 0..nvid {
            ix.videos.push(StoredVideo {
                doc_id: r.i64()?,
                page_url: r.str()?,
                video_url: r.str()?,
                embed_url: r.str()?,
                watch_url: r.str()?,
                title: r.str()?,
                thumbnail_url: r.str()?,
                source: r.str()?,
                duration: r.opt_i64()?,
                context: r.str()?,
                host: r.str()?,
            });
        }

        Some(ix)
    }

    // ---- segmented persistence: read and rebuild ---------------------------
    //
    // The narrow, crate-private seam [`crate::segindex`] uses to take the index
    // apart into records and put it back together from them. Deliberately not
    // `pub`: `snapshot`/`restore` remain THE public persistence API — the binary,
    // the tests and the goldens all speak it and none of them change — and these
    // are field-level accessors that would be a maintenance liability as public
    // surface. They set stored state verbatim, with no rowid allocation and no
    // recomputation, because a restore must reproduce what was persisted rather
    // than derive something adjacent to it.

    /// A single link edge's `internal` flag.
    pub(crate) fn link_internal(&self, edge: &(String, String)) -> Option<bool> {
        self.links.get(edge).copied()
    }

    /// A document by rowid.
    pub(crate) fn doc_by_id(&self, id: i64) -> Option<&Document> {
        self.docs.get(&id)
    }

    /// The harvested image rows for one document, in stored order.
    pub(crate) fn images_of(&self, doc_id: i64) -> impl Iterator<Item = &StoredImage> {
        self.images.iter().filter(move |r| r.doc_id == doc_id)
    }

    /// The harvested video rows for one document, in stored order.
    pub(crate) fn videos_of(&self, doc_id: i64) -> impl Iterator<Item = &StoredVideo> {
        self.videos.iter().filter(move |r| r.doc_id == doc_id)
    }

    /// The next rowid `upsert_document` would hand out.
    pub(crate) fn next_rowid(&self) -> i64 {
        self.next_id
    }

    /// Force the next rowid. Only meaningful while rebuilding.
    pub(crate) fn set_next_rowid(&mut self, next: i64) {
        self.next_id = next.max(1);
    }

    /// Install a document exactly as stored, ranking signals included.
    pub(crate) fn put_stored_doc(&mut self, d: Document) {
        self.url_to_id.insert(d.url.clone(), d.id);
        self.next_id = self.next_id.max(d.id + 1);
        self.docs.insert(d.id, d);
    }

    /// Overlay the ranking signals for an already-installed document. A rank
    /// record for a document that is not present is *dropped*, not an error: the
    /// only way to reach that state is a store whose document record was
    /// tombstoned after the rank was written, and a dangling score is not worth
    /// refusing to start over.
    pub(crate) fn put_stored_rank(&mut self, id: i64, incoming: i64, rank: f64, host_rank: f64) {
        if let Some(d) = self.docs.get_mut(&id) {
            d.incoming = incoming;
            d.rank = rank;
            d.host_rank = host_rank;
        }
    }

    /// Install a link edge as stored.
    pub(crate) fn put_stored_link(&mut self, src: String, dst: String, internal: bool) {
        self.links.insert((src, dst), internal);
    }

    /// Install a host-authority score as stored.
    pub(crate) fn put_stored_host_authority(&mut self, host: String, value: f64) {
        self.host_authority.insert(host, value);
    }

    /// Append harvested image rows as stored.
    pub(crate) fn put_stored_images(&mut self, rows: Vec<StoredImage>) {
        self.images.extend(rows);
    }

    /// Append harvested video rows as stored.
    pub(crate) fn put_stored_videos(&mut self, rows: Vec<StoredVideo>) {
        self.videos.extend(rows);
    }
}

/// Snapshot format version. Bump on any breaking change to the field layout so a
/// blob written by an older build is rejected (returns `None`) rather than
/// mis-decoded.
const INDEX_SNAPSHOT_VERSION: u8 = 1;

/// A tiny, self-describing, append-only little-endian writer — the encoder half of
/// the dependency-free snapshot codec (mirrors the `onioncrawler` store codec).
/// Native `f64` timestamps/ranks are carried as IEEE-754 bits, `i64` verbatim,
/// strings length-prefixed, so every field round-trips exactly.
///
/// Crate-visible (not public) because [`crate::segindex`] encodes its records
/// with the same codec: one field encoding for the whole engine means a
/// `Document` written into a segment and a `Document` written into a snapshot
/// have identical field semantics, and a fix to either is a fix to both.
#[derive(Default)]
pub(crate) struct Writer {
    buf: Vec<u8>,
}

impl Writer {
    fn new() -> Self {
        Writer::default()
    }

    /// Encode into a buffer the caller already owns, so a record encoder that is
    /// handed an `&mut Vec<u8>` can append without an intermediate allocation.
    pub(crate) fn from_vec(buf: Vec<u8>) -> Self {
        Writer { buf }
    }

    pub(crate) fn into_bytes(self) -> Vec<u8> {
        self.buf
    }

    pub(crate) fn u8(&mut self, x: u8) {
        self.buf.push(x);
    }

    pub(crate) fn i64(&mut self, x: i64) {
        self.buf.extend_from_slice(&x.to_le_bytes());
    }

    /// Write a length or count as an unsigned 64-bit value.
    pub(crate) fn len(&mut self, x: usize) {
        self.buf.extend_from_slice(&(x as u64).to_le_bytes());
    }

    pub(crate) fn f64(&mut self, x: f64) {
        self.buf.extend_from_slice(&x.to_bits().to_le_bytes());
    }

    pub(crate) fn bool(&mut self, x: bool) {
        self.buf.push(u8::from(x));
    }

    pub(crate) fn str(&mut self, s: &str) {
        self.len(s.len());
        self.buf.extend_from_slice(s.as_bytes());
    }

    pub(crate) fn opt_i64(&mut self, x: Option<i64>) {
        match x {
            Some(v) => {
                self.u8(1);
                self.i64(v);
            }
            None => self.u8(0),
        }
    }
}

/// Bounds-checked little-endian reader — the decoder half of the snapshot codec.
/// Every accessor returns `None` on an out-of-range read, so a truncated or
/// corrupt blob yields `None` from [`Index::restore`] rather than a panic.
pub(crate) struct Reader<'a> {
    buf: &'a [u8],
    pos: usize,
}

impl<'a> Reader<'a> {
    pub(crate) fn new(buf: &'a [u8]) -> Self {
        Reader { buf, pos: 0 }
    }

    /// Whether every byte has been consumed — the check a fixed-shape record
    /// decoder wants at the end, so trailing junk is a decode failure rather than
    /// something silently ignored.
    pub(crate) fn is_at_end(&self) -> bool {
        self.pos == self.buf.len()
    }

    fn take(&mut self, n: usize) -> Option<&'a [u8]> {
        let end = self.pos.checked_add(n)?;
        let slice = self.buf.get(self.pos..end)?;
        self.pos = end;
        Some(slice)
    }

    pub(crate) fn u8(&mut self) -> Option<u8> {
        Some(self.take(1)?[0])
    }

    pub(crate) fn i64(&mut self) -> Option<i64> {
        let b = self.take(8)?;
        let mut a = [0u8; 8];
        a.copy_from_slice(b);
        Some(i64::from_le_bytes(a))
    }

    pub(crate) fn len(&mut self) -> Option<usize> {
        let b = self.take(8)?;
        let mut a = [0u8; 8];
        a.copy_from_slice(b);
        usize::try_from(u64::from_le_bytes(a)).ok()
    }

    pub(crate) fn f64(&mut self) -> Option<f64> {
        let b = self.take(8)?;
        let mut a = [0u8; 8];
        a.copy_from_slice(b);
        Some(f64::from_bits(u64::from_le_bytes(a)))
    }

    pub(crate) fn bool(&mut self) -> Option<bool> {
        Some(self.u8()? != 0)
    }

    pub(crate) fn str(&mut self) -> Option<String> {
        let n = self.len()?;
        let b = self.take(n)?;
        String::from_utf8(b.to_vec()).ok()
    }

    pub(crate) fn opt_i64(&mut self) -> Option<Option<i64>> {
        match self.u8()? {
            0 => Some(None),
            1 => Some(Some(self.i64()?)),
            _ => None,
        }
    }
}

/// The PageRank normaliser: the max score, or `1.0` if it is zero
/// (Python `max(pr) or 1.0`).
fn normalizer(pr: &[f64]) -> f64 {
    let m = pr.iter().copied().fold(f64::MIN, f64::max);
    if m == 0.0 {
        1.0
    } else {
        m
    }
}

/// The smallest string strictly greater than every string starting with
/// `prefix` — the exclusive upper bound of a term range scan. Increments the
/// last code point; returns `None` (so callers fall back to a `starts_with`
/// scan) when no valid successor exists: the successor lands in the UTF-16
/// surrogate gap `U+D800..=U+DFFF`, or past the maximum code point (`U+10FFFF`,
/// where [`char::from_u32`] fails). Mirrors the Python `_prefix_upper`.
#[must_use]
pub fn prefix_upper(prefix: &str) -> Option<String> {
    let last = prefix.chars().next_back()?;
    let n = last as u32 + 1;
    if (0xD800..=0xDFFF).contains(&n) {
        return None;
    }
    let c = char::from_u32(n)?;
    let mut s = prefix[..prefix.len() - last.len_utf8()].to_string();
    s.push(c);
    Some(s)
}

/// The top `n` `(key, count)` by count desc, ties by key asc (deterministic).
fn top_n(counts: &BTreeMap<String, usize>, n: usize) -> Vec<(String, usize)> {
    let mut v: Vec<(String, usize)> = counts.iter().map(|(k, c)| (k.clone(), *c)).collect();
    v.sort_by(|a, b| b.1.cmp(&a.1).then(a.0.cmp(&b.0)));
    v.truncate(n);
    v
}

// BM25 tuning for the media-vertical relevance base — the same constants the
// document search uses in `crate::ranking`, kept local so this module stays
// decoupled from the ranking internals.
const BM25_K1: f64 = 1.2;
const BM25_B: f64 = 0.75;

/// Rank `rows` by an equal-weight Okapi BM25 over the query `words`, keeping only
/// rows whose combined tokens contain EVERY word (FTS5 implicit-AND), ordered
/// best-first with an insertion-order (rowid) tie-break, and truncated to
/// `limit`. `fields(row)` yields that row's FTS columns. This is the
/// behaviourally-faithful stand-in for FTS5 `bm25()` used by both media verticals,
/// mirroring the field-weighted BM25 in [`crate::ranking`] with unit weights.
fn media_search_indices<T>(
    rows: &[T],
    words: &[String],
    limit: usize,
    fields: impl Fn(&T) -> Vec<&str>,
) -> Vec<usize> {
    let n = rows.len();
    if n == 0 {
        return Vec::new();
    }
    // Tokenise every row's FTS columns once (the same `[^\W_]+` tokeniser the main
    // search uses), then derive per-field lengths and each row's term set.
    let per_row: Vec<Vec<Vec<String>>> = rows
        .iter()
        .map(|r| fields(r).into_iter().map(crate::ranking::words).collect())
        .collect();
    let nfields = per_row[0].len();
    let mut sum_len = vec![0.0f64; nfields];
    let mut row_terms: Vec<HashSet<String>> = Vec::with_capacity(n);
    for fv in &per_row {
        let mut all: HashSet<String> = HashSet::new();
        for (fi, fw) in fv.iter().enumerate() {
            sum_len[fi] += fw.len() as f64;
            for w in fw {
                all.insert(w.clone());
            }
        }
        row_terms.push(all);
    }
    let avg: Vec<f64> = sum_len.iter().map(|s| s / n as f64).collect();

    // Document frequency (number of rows containing the term) per query term.
    let mut df: HashMap<&str, usize> = HashMap::new();
    for w in words {
        df.entry(w.as_str()).or_insert(0);
    }
    for terms in &row_terms {
        for (w, c) in df.iter_mut() {
            if terms.contains(*w) {
                *c += 1;
            }
        }
    }

    // Keep rows containing every query term; score each by BM25 over its columns.
    let mut scored: Vec<(f64, usize)> = Vec::new();
    for (i, fv) in per_row.iter().enumerate() {
        if !words.iter().all(|w| row_terms[i].contains(w)) {
            continue;
        }
        let mut score = 0.0f64;
        for w in words {
            let term = w.as_str();
            let d = *df.get(term).unwrap_or(&0);
            if d == 0 {
                continue;
            }
            let idf = (1.0 + (n as f64 - d as f64 + 0.5) / (d as f64 + 0.5)).ln();
            for (fi, fw) in fv.iter().enumerate() {
                let f = fw.iter().filter(|x| x.as_str() == term).count() as f64;
                if f == 0.0 {
                    continue;
                }
                let len = fw.len() as f64;
                let denom = f + BM25_K1 * (1.0 - BM25_B + BM25_B * len / avg[fi].max(1.0));
                score += idf * (f * (BM25_K1 + 1.0)) / denom;
            }
        }
        scored.push((score, i));
    }
    // Best-first; a stable sort keeps equal-score rows in insertion (rowid) order.
    scored.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap_or(std::cmp::Ordering::Equal));
    scored.into_iter().take(limit).map(|(_, i)| i).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fields(fetched_at: f64) -> DocFields<'static> {
        DocFields {
            fetched_at,
            http_status: 200,
            ..DocFields::default()
        }
    }

    #[test]
    fn content_hash_matches_shape() {
        // Empty single part → sha256("\0"); deterministic + non-empty hex.
        let h = content_hash(&["a", "b", "c"]);
        assert_eq!(h.len(), 64);
        assert_ne!(content_hash(&["a", "b"]), content_hash(&["ab", ""]));
    }

    #[test]
    fn upsert_and_dedup() {
        let mut ix = Index::new();
        let id1 = ix.upsert_document(
            "http://x/1",
            DocFields {
                title: "T",
                body: "hello world body",
                host: "x",
                ..fields(100.0)
            },
        );
        assert_eq!(id1, 1);
        // re-upsert same url → same id, updated fields
        let id1b = ix.upsert_document(
            "http://x/1",
            DocFields {
                title: "T2",
                ..fields(200.0)
            },
        );
        assert_eq!(id1b, 1);
        assert_eq!(ix.get_doc("http://x/1").unwrap().title, "T2");
        // a second doc with the SAME content hash is found by the dedup lookup
        let ch = content_hash(&["T2", "", ""]);
        assert_eq!(ix.url_with_content_hash(&ch).as_deref(), Some("http://x/1"));
    }

    #[test]
    fn validators_and_recrawl() {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://x/1",
            DocFields {
                etag: "\"v1\"",
                fetched_at: 100.0,
                host: "x",
                http_status: 200,
                ..DocFields::default()
            },
        );
        assert_eq!(ix.get_validators("http://x/1").0, "\"v1\"");
        ix.touch_revalidated("http://x/1", 500.0, Some("\"v2\""), None);
        assert_eq!(ix.get_validators("http://x/1").0, "\"v2\"");
        assert_eq!(ix.get_doc("http://x/1").unwrap().fetched_at, 500.0);
        // due at now=1000, interval=100 → cutoff 900; fetched_at 500 <= 900 → due
        assert_eq!(
            ix.due_for_recrawl(100.0, 1000.0),
            vec![("http://x/1".to_string(), "x".to_string())]
        );
        // not due yet (cutoff 400 < 500)
        assert!(ix.due_for_recrawl(100.0, 500.0).is_empty());
    }

    #[test]
    fn links_and_incoming() {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://x/a",
            DocFields {
                host: "x",
                ..fields(1.0)
            },
        );
        ix.upsert_document(
            "http://x/b",
            DocFields {
                host: "x",
                ..fields(2.0)
            },
        );
        ix.add_links(
            "http://x/a",
            &[
                ("http://x/b".to_string(), true),
                ("http://ext/z".to_string(), false),
            ],
        );
        ix.add_links("http://x/a", &[("http://x/b".to_string(), true)]); // dup ignored
        ix.recompute_incoming();
        assert_eq!(ix.get_doc("http://x/b").unwrap().incoming, 1);
        assert_eq!(ix.get_doc("http://x/a").unwrap().incoming, 0);
        assert_eq!(ix.stats().links, 2);
    }

    fn img(src: &str, alt: &str, title: &str, ctx: &str) -> Image {
        Image {
            src: src.to_string(),
            alt: alt.to_string(),
            title: title.to_string(),
            context: ctx.to_string(),
        }
    }

    #[allow(clippy::too_many_arguments)]
    fn vid(
        video: &str,
        embed: &str,
        watch: &str,
        title: &str,
        thumb: &str,
        source: &str,
        dur: Option<i64>,
        ctx: &str,
    ) -> Video {
        Video {
            video_url: video.to_string(),
            embed_url: embed.to_string(),
            watch_url: watch.to_string(),
            title: title.to_string(),
            thumbnail: thumb.to_string(),
            source: source.to_string(),
            duration: dur,
            context: ctx.to_string(),
        }
    }

    #[test]
    fn replace_images_skips_caps_and_clears() {
        let mut ix = Index::new();
        let imgs = vec![
            img("", "empty src is skipped", "", ""),
            img("https://h/a.jpg", "a cat", "", "on a mat"),
            img("https://h/b.jpg", "a dog", "", ""),
        ];
        assert_eq!(ix.replace_images(1, "https://h/p", "h", &imgs), 2); // empty-src skipped
        assert_eq!(ix.image_search("cat", 30).len(), 1);
        assert_eq!(ix.image_search("dog", 30).len(), 1);
        // a recrawl clears the doc's old rows first, then stores the fresh set
        assert_eq!(
            ix.replace_images(
                1,
                "https://h/p",
                "h",
                &[img("https://h/c.jpg", "a cat", "", "")]
            ),
            1
        );
        assert_eq!(ix.image_search("cat", 30).len(), 1);
        assert!(ix.image_search("dog", 30).is_empty()); // old rows gone
                                                        // stored count is capped at MAX_IMAGES_PER_DOC
        let many: Vec<Image> = (0..MAX_IMAGES_PER_DOC + 50)
            .map(|i| img(&format!("https://h/{i}.jpg"), "x", "", ""))
            .collect();
        assert_eq!(ix.replace_images(2, "", "h", &many), MAX_IMAGES_PER_DOC);
    }

    #[test]
    fn image_search_tokenizes_matches_and_limits() {
        let mut ix = Index::new();
        ix.replace_images(
            1,
            "https://h/p",
            "h",
            &[
                img("https://h/1.jpg", "Fluffy Cat", "pet", "a cat on a mat"),
                img("https://h/2.jpg", "Happy Dog", "", "a dog runs"),
                img("https://h/3.jpg", "Cat and Dog", "", "both here"),
            ],
        );
        // a single term matches every row that contains it (in any FTS column)
        assert_eq!(ix.image_search("cat", 30).len(), 2);
        // implicit-AND: both terms must appear in the SAME row
        let both = ix.image_search("cat dog", 30);
        assert_eq!(both.len(), 1);
        assert_eq!(both[0].src, "https://h/3.jpg");
        assert_eq!(both[0].host, "h");
        assert_eq!(both[0].page_url, "https://h/p");
        // empty / whitespace-only query -> []
        assert!(ix.image_search("", 30).is_empty());
        assert!(ix.image_search("   ", 30).is_empty());
        // limit caps the number of results
        assert_eq!(ix.image_search("cat", 1).len(), 1);
    }

    #[test]
    fn replace_videos_skips_coerces_and_caps() {
        let mut ix = Index::new();
        let vids = vec![
            vid("", "", "", "no linkable url", "", "", Some(9), "cats"), // skipped: no URL
            vid(
                "https://h/a.mp4",
                "",
                "",
                "clip a",
                "",
                "direct",
                Some(-5),
                "cats play",
            ), // dur<0
            vid(
                "",
                "https://h/e",
                "",
                "clip b",
                "",
                "youtube",
                Some(65),
                "dogs run",
            ),
        ];
        assert_eq!(ix.replace_videos(1, "https://h/p", "h", &vids), 2);
        let hits = ix.video_search("cats", 30);
        assert_eq!(hits.len(), 1);
        assert_eq!(hits[0].video_url, "https://h/a.mp4");
        assert_eq!(hits[0].duration, None); // negative duration coerced to None
        assert_eq!(ix.video_search("dogs", 30)[0].duration, Some(65)); // positive survives
                                                                       // a recrawl with an empty list clears the doc's rows
        assert_eq!(ix.replace_videos(1, "https://h/p", "h", &[]), 0);
        assert!(ix.video_search("cats", 30).is_empty());
        // stored count is capped at MAX_VIDEOS_PER_DOC
        let many: Vec<Video> = (0..MAX_VIDEOS_PER_DOC + 50)
            .map(|i| vid(&format!("https://h/{i}.mp4"), "", "", "x", "", "", None, ""))
            .collect();
        assert_eq!(ix.replace_videos(2, "", "h", &many), MAX_VIDEOS_PER_DOC);
    }

    #[test]
    fn video_search_matches_title_and_context_only() {
        let mut ix = Index::new();
        ix.replace_videos(
            1,
            "https://h/p",
            "h",
            &[
                vid(
                    "https://h/1.mp4",
                    "",
                    "",
                    "Rust tutorial",
                    "",
                    "direct",
                    Some(120),
                    "learn rust",
                ),
                vid(
                    "https://h/2.mp4",
                    "",
                    "",
                    "Java basics",
                    "",
                    "direct",
                    None,
                    "the jvm",
                ),
            ],
        );
        assert_eq!(ix.video_search("rust", 30).len(), 1);
        // AND across DIFFERENT rows does not match
        assert!(ix.video_search("rust jvm", 30).is_empty());
        // the `source` column ("direct") is NOT an FTS field, so it never matches
        assert!(ix.video_search("direct", 30).is_empty());
        assert!(ix.video_search("", 30).is_empty());
        let r = ix.video_search("rust", 30);
        assert_eq!(r[0].duration, Some(120));
        assert_eq!(r[0].watch_url, "");
    }

    /// Build an index with docs (every field populated), a link graph, harvested
    /// images + videos, and the finalised ranking signals, then assert
    /// `restore(&snapshot())` reproduces every observable: the exact per-`Document`
    /// state, the stats, the media-search results, and the host authority — and
    /// that the round-trip is byte-idempotent.
    #[test]
    fn snapshot_restore_roundtrip_is_exact() {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://a.example/one",
            DocFields {
                title: "First",
                description: "the first page",
                body: "alpha beta gamma cat",
                host: "a.example",
                lang: "en",
                fetched_at: 1_700_000_000.5,
                http_status: 200,
                etag: "\"v1\"",
                last_modified: "Mon, 01 Jan 2024 00:00:00 GMT",
                content_type: "text/html",
                simhash: -1234567890123456789,
                ..DocFields::default()
            },
        );
        ix.upsert_document(
            "http://b.example/two",
            DocFields {
                title: "Second",
                description: "another",
                body: "delta epsilon dog",
                host: "b.example",
                lang: "de",
                fetched_at: 1_700_000_500.25,
                http_status: 301,
                content_type: "application/xhtml+xml",
                simhash: 42,
                ..DocFields::default()
            },
        );
        // Cross-domain + internal edges so pagerank/host-authority are non-trivial.
        ix.add_links(
            "http://a.example/one",
            &[
                ("http://b.example/two".to_string(), false),
                ("http://a.example/one".to_string(), true),
            ],
        );
        ix.add_links(
            "http://b.example/two",
            &[("http://a.example/one".to_string(), false)],
        );
        ix.replace_images(
            1,
            "http://a.example/one",
            "a.example",
            &[
                img("http://a.example/c.jpg", "a cat", "kitty", "on a mat"),
                img("http://a.example/d.jpg", "a dog", "", "runs"),
            ],
        );
        ix.replace_videos(
            2,
            "http://b.example/two",
            "b.example",
            &[vid(
                "http://b.example/v.mp4",
                "",
                "",
                "Rust clip",
                "http://b.example/t.jpg",
                "direct",
                Some(120),
                "learn rust",
            )],
        );
        ix.finalize();

        let blob = ix.snapshot();
        let restored = Index::restore(&blob).expect("restore a well-formed blob");

        // Same document population, and every Document field survives verbatim.
        assert_eq!(restored.doc_count(), ix.doc_count());
        for d in ix.all_docs() {
            assert_eq!(restored.get_doc(&d.url), Some(d), "doc {} differs", d.url);
        }
        // The finalised signals are non-zero (so we know they were exercised).
        assert!(ix.get_doc("http://a.example/one").unwrap().host_rank > 0.0);
        assert_eq!(
            restored.get_doc("http://a.example/one").unwrap().incoming,
            ix.get_doc("http://a.example/one").unwrap().incoming
        );

        // Aggregate stats (docs, hosts, links, fetch range, top-N) are identical.
        assert_eq!(restored.stats(), ix.stats());

        // Host authority round-trips.
        assert_eq!(
            restored.host_authority("a.example"),
            ix.host_authority("a.example")
        );
        assert!(restored.host_authority("a.example").is_some());

        // The media verticals return identical results.
        assert_eq!(restored.image_search("cat", 30), ix.image_search("cat", 30));
        assert_eq!(restored.image_search("dog", 30), ix.image_search("dog", 30));
        assert_eq!(
            restored.video_search("rust", 30),
            ix.video_search("rust", 30)
        );

        // The round-trip is byte-idempotent, and `next_id` continues correctly:
        // the next NEW url gets the id the original index would have handed out.
        assert_eq!(restored.snapshot(), blob);
        let mut restored = restored;
        let id = restored.upsert_document("http://c.example/three", DocFields::default());
        assert_eq!(id, 3);
    }

    #[test]
    fn restore_rejects_corrupt_and_versioned_blobs() {
        let mut ix = Index::new();
        ix.upsert_document(
            "http://x/1",
            DocFields {
                title: "T",
                host: "x",
                http_status: 200,
                ..DocFields::default()
            },
        );
        let blob = ix.snapshot();
        // A truncated blob decodes to None, never panics.
        assert!(Index::restore(&blob[..blob.len() - 3]).is_none());
        assert!(Index::restore(&[]).is_none());
        // A wrong version byte is rejected.
        let mut bad = blob.clone();
        bad[0] = 0xFF;
        assert!(Index::restore(&bad).is_none());
        // An empty index round-trips to an empty index.
        let empty = Index::new().snapshot();
        assert_eq!(Index::restore(&empty).unwrap().doc_count(), 0);
    }
}

#[cfg(test)]
mod audit_regression {
    use super::*;
    use crate::ranking::{search, SearchOpts};

    fn put(ix: &mut Index, url: &str, title: &str, body: &str) {
        ix.upsert_document(
            url,
            DocFields {
                title,
                body,
                host: url.split('/').nth(2).unwrap_or("h"),
                lang: "en",
                fetched_at: 1_000_000.0,
                http_status: 200,
                ..DocFields::default()
            },
        );
    }

    /// AUDIT REGRESSION (MEDIUM). Caching the tokenisation is only safe if a write
    /// invalidates it. Re-indexing a URL with different text must change both what
    /// `search` matches and what the `/suggest` vocabulary offers — in the same
    /// process, with no other signal that anything moved.
    #[test]
    fn a_text_change_invalidates_the_derived_cache() {
        let mut ix = Index::new();
        put(&mut ix, "http://a/1", "Rust guide", "learning rust today");
        // Warm the cache through both readers.
        assert_eq!(search(&ix, "rust", &SearchOpts::default()).total, 1);
        assert_eq!(ix.vocab_prefix("rus", 10), vec![("rust".to_string(), 1)]);

        put(&mut ix, "http://a/1", "Java guide", "learning java today");
        assert_eq!(
            search(&ix, "rust", &SearchOpts::default()).total,
            0,
            "search still matched the OLD body: the cache was not invalidated"
        );
        assert_eq!(search(&ix, "java", &SearchOpts::default()).total, 1);
        assert!(
            ix.vocab_prefix("rus", 10).is_empty(),
            "the suggest vocabulary still holds the OLD term"
        );
        assert_eq!(ix.vocab_prefix("jav", 10), vec![("java".to_string(), 1)]);

        // …and restoring the original text restores the original answers exactly.
        put(&mut ix, "http://a/1", "Rust guide", "learning rust today");
        assert_eq!(search(&ix, "rust", &SearchOpts::default()).total, 1);
        assert_eq!(search(&ix, "java", &SearchOpts::default()).total, 0);
        assert_eq!(ix.vocab_prefix("rus", 10), vec![("rust".to_string(), 1)]);
    }

    /// A new document added after the cache is warm must be searchable, and one
    /// index's cache must never answer for another's — [`Index::restore`] builds a
    /// fresh store, so it starts with a cold cache.
    #[test]
    fn additions_and_restore_see_their_own_corpus() {
        let mut ix = Index::new();
        put(&mut ix, "http://a/1", "First", "alpha content here");
        assert_eq!(search(&ix, "beta", &SearchOpts::default()).total, 0);
        put(&mut ix, "http://a/2", "Second", "beta content here");
        assert_eq!(
            search(&ix, "beta", &SearchOpts::default()).total,
            1,
            "a document added after the cache was warm is invisible"
        );

        let restored = Index::restore(&ix.snapshot()).expect("snapshot round-trips");
        assert_eq!(search(&restored, "beta", &SearchOpts::default()).total, 1);
        assert_eq!(search(&restored, "alpha", &SearchOpts::default()).total, 1);
        assert_eq!(
            restored.vocab_prefix("bet", 10),
            vec![("beta".to_string(), 1)]
        );
    }

    /// The cache covers TEXT only. `add_links` + `finalize` change `incoming` and
    /// `host_rank`, which feed the score directly — those must be read off the
    /// live `Document`, so the score moves even though no text changed and the
    /// cache is (correctly) not rebuilt.
    #[test]
    fn link_signals_are_read_live_not_from_the_cache() {
        let mut ix = Index::new();
        put(&mut ix, "http://a/1", "Target", "alpha content here");
        put(&mut ix, "http://b/1", "Source", "alpha content here");
        let before = search(&ix, "alpha", &SearchOpts::default());
        let score_before = before
            .results
            .iter()
            .find(|r| r.url == "http://a/1")
            .expect("target is a hit")
            .score;

        ix.add_links(
            "http://b/1",
            &[
                ("http://a/1".to_string(), true),
                ("http://a/1".to_string(), true),
            ],
        );
        ix.add_links("http://a/1", &[("http://b/1".to_string(), true)]);
        ix.finalize();

        let after = search(&ix, "alpha", &SearchOpts::default());
        let score_after = after
            .results
            .iter()
            .find(|r| r.url == "http://a/1")
            .expect("target is still a hit")
            .score;
        assert!(
            score_after > score_before,
            "an incoming link did not change the score ({score_before} -> {score_after}): \
             the ranking signals are being served from a stale cache"
        );
    }

    /// AUDIT REGRESSION (MEDIUM). The store's own guard against a link bomb: even
    /// if a caller forgets its per-page cap, one `add_links` call cannot put more
    /// than [`MAX_EDGES_PER_CALL`] owned URL pairs into the graph, and it says how
    /// many it refused.
    #[test]
    fn add_links_caps_one_pages_edges_and_reports_the_refusals() {
        let mut ix = Index::new();
        let edges: Vec<(String, bool)> = (0..100_000)
            .map(|i| (format!("http://x/{i}"), true))
            .collect();
        let dropped = ix.add_links("http://x/p", &edges);
        assert_eq!(ix.stats().links, MAX_EDGES_PER_CALL);
        assert_eq!(dropped, 100_000 - MAX_EDGES_PER_CALL);

        // An honest page is untouched, and its refusal count is zero.
        let mut ix2 = Index::new();
        let few: Vec<(String, bool)> = (0..40).map(|i| (format!("http://x/{i}"), true)).collect();
        assert_eq!(ix2.add_links("http://x/p", &few), 0);
        assert_eq!(ix2.stats().links, 40);
    }

    /// AUDIT REGRESSION (MEDIUM). The cost of a query must not scale with the
    /// corpus every time. This is self-calibrating rather than a wall-clock
    /// threshold: the FIRST query pays the corpus tokenisation, and ten further
    /// queries must together cost less than that one — before the cache each of
    /// the ten paid it again in full (measured: `/search?q=lorem` 790 ms and
    /// `/suggest?q=lor` 923 ms on a 200-document × 100 kB corpus, per request).
    #[test]
    fn repeat_queries_do_not_re_tokenise_the_corpus() {
        let body = "lorem ipsum dolor sit amet consectetur adipiscing elit ".repeat(400); // ~21 kB
        let mut ix = Index::new();
        for i in 0..100 {
            put(&mut ix, &format!("http://h{i}/p"), "Lorem page", &body);
        }

        let before = ix.derived_rebuilds();
        assert_eq!(search(&ix, "lorem", &SearchOpts::default()).total, 100);
        let after_first = ix.derived_rebuilds();
        assert_eq!(
            after_first - before,
            1,
            "the first query must tokenise the corpus exactly once"
        );

        for _ in 0..10 {
            assert_eq!(search(&ix, "lorem", &SearchOpts::default()).total, 100);
            assert!(!ix.vocab_prefix("lor", 10).is_empty());
        }
        assert_eq!(
            ix.derived_rebuilds(),
            after_first,
            "ten more searches and ten more /suggest lookups re-tokenised the \
             corpus: before the memo each of them paid for the whole corpus \
             again (measured: /search?q=lorem 790 ms and /suggest?q=lor 923 ms \
             on a 200-document x 100 kB corpus, per request, under the index lock)"
        );

        // …and a write must invalidate it, or the cache would serve stale results.
        put(&mut ix, "http://new/p", "Lorem page", &body);
        assert_eq!(search(&ix, "lorem", &SearchOpts::default()).total, 101);
        assert_eq!(
            ix.derived_rebuilds() - after_first,
            1,
            "a write must invalidate the cache exactly once"
        );
    }
}
