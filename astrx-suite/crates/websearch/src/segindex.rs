//! The segmented index store: `websearch`'s [`Index`] persisted as
//! [`crawlcore::segstore`] records instead of one blob.
//!
//! # What this is for
//!
//! [`Index::snapshot`] serialises the whole store to one `Vec<u8>` and
//! [`Index::restore`] parses it back. That is still here, still the default, and
//! still what the binary, the tests and the goldens use — this module does not
//! replace it, it sits beside it, and `--store=segments` chooses. What it fixes
//! is that a blob save is *all or nothing, once*:
//!
//! - a crawl that adds 100 pages to a 1 000 000-page index rewrites 1 000 000
//!   pages, so saving is something you do at the end rather than as you go;
//! - and therefore a `SIGKILL` four hours into a five-hour crawl loses four
//!   hours, even though `atomicfile` guarantees the file on disk is intact —
//!   intact and four hours stale.
//!
//! Here a flush writes only what changed since the last flush, so it is cheap
//! enough to do every few hundred pages, so a kill costs at most those pages.
//! `tests/segindex_crash.rs` kills a writer with `SIGKILL` and checks exactly
//! that.
//!
//! # The record split, and why `Rank` is its own record
//!
//! Six record kinds, keyed so that a key-ordered scan replays them in dependency
//! order (documents before the ranking signals that overlay them):
//!
//! | tag | record       | key                     | written by                    |
//! |-----|--------------|-------------------------|-------------------------------|
//! | 0   | `Doc`        | `0 ‖ url`               | `upsert_document`, `touch_revalidated` |
//! | 1   | `Link`       | `1 ‖ src ‖ NUL ‖ dst`   | `add_links`                   |
//! | 2   | `HostAuth`   | `2 ‖ host`              | `compute_host_authority`      |
//! | 3   | `Images`     | `3 ‖ doc_id`            | `replace_images`              |
//! | 4   | `Videos`     | `4 ‖ doc_id`            | `replace_videos`              |
//! | 5   | `Rank`       | `5 ‖ doc_id`            | `finalize`                    |
//! | 6   | `Meta`       | `6`                     | any rowid allocation          |
//!
//! The split that matters is `Doc` versus `Rank`. `Index::finalize` recomputes
//! `incoming`, `rank` and `host_rank` for **every** document in the corpus. If
//! those three numbers lived on the `Doc` record, every finalize would rewrite
//! every document *body* — a full snapshot wearing a segment store's clothes, and
//! the incremental win would evaporate at the exact moment the crawl ends. As a
//! separate ~40-byte record they cost about 0.3 % of a corpus-sized rewrite. The
//! ordering falls out for free: tag 5 sorts after tag 0, so a scan always has the
//! document in hand before the score that decorates it.
//!
//! `Images`/`Videos` use tombstones for real: `replace_images(id, &[])` clears a
//! document's harvested rows, and "cleared" has to be *written down*, because
//! segments are immutable and the old rows are still sitting in an older one.
//! `HostAuth` too — `compute_host_authority` clears and rebuilds its map, so a
//! host that drops out of the graph is tombstoned rather than left behind as a
//! stale score.
//!
//! # What this does NOT buy
//!
//! **The read path is still fully resident, on purpose.** [`SegmentedIndex::load`]
//! streams the segments — one record at a time, bounded memory, per
//! [`crawlcore::segstore::Reader`] — and folds them into an ordinary, complete
//! [`Index`]. It does not hand out a lazy, disk-backed index, and that is a
//! decision rather than an omission:
//!
//! - [`crate::ranking::search`] computes BM25 over the corpus. It needs every
//!   document's term frequencies *and* corpus-wide document frequencies and
//!   average field lengths for a single query. There is no inverted index here
//!   (see the `ranking` module docs on why the FTS5 stand-in is what it is), so a
//!   query is a full pass, and a "streaming" search would stream the whole corpus
//!   per query — strictly worse than holding it.
//! - `Index` already memoises a corpus-sized tokenisation (`Derived`) precisely
//!   because rebuilding it per request was measured at 790 ms on a *small*
//!   corpus. Backing documents with disk while keeping their tokenisations in RAM
//!   would save the smaller half.
//!
//! So of the four problems in [`crawlcore::segstore`]'s docs, this adoption fixes
//! 2 (incremental writes), 3 (a bounded crash window) and 4 (resume) outright,
//! and 1 (memory) only on the **write** side: a save no longer needs a second
//! copy of the corpus, because what has to be resident to be written is the
//! batch. Peak RSS while *serving* is unchanged. Fixing that means an inverted
//! index with on-disk postings, which is a different project.
//!
//! **Harvested media row order is canonicalised.** The blob keeps
//! `Index`'s image/video rows in the order `replace_images` last touched each
//! document; the segmented store groups them by document id, ascending. The two
//! agree for any crawl that indexes a document once — which is every crawl except
//! a partial recrawl of an already-indexed page — and the difference can only
//! move rows whose media-BM25 scores are exactly equal (see
//! `media_search_indices`, which breaks ties by row position). It is pinned by a
//! test rather than left to be discovered.

use std::path::Path;

use crawlcore::segstore::{Batch, Error, MergeOutcome, Record, Result, Store};

use crate::index::{Document, Index, Reader, StoredImage, StoredVideo, Writer};

/// Record kind tags. The numeric order is the replay order — see the module docs.
const TAG_DOC: u8 = 0;
const TAG_LINK: u8 = 1;
const TAG_HOST_AUTH: u8 = 2;
const TAG_IMAGES: u8 = 3;
const TAG_VIDEOS: u8 = 4;
const TAG_RANK: u8 = 5;
const TAG_META: u8 = 6;

/// How many segments a store may accumulate before [`SegmentedIndex::maybe_compact`]
/// folds them. Each live segment costs a descriptor, an 8 KiB buffer and one
/// cursor comparison per record on every read, so the number that matters is the
/// count, not the bytes.
pub const DEFAULT_MAX_SEGMENTS: usize = 8;

/// One unit of index state, as it lives in a segment.
///
/// A single enum rather than six stores: the engine's state is one consistent
/// thing, and a crash must not be able to land between "the document committed"
/// and "its links committed". One store, one manifest, one commit.
#[derive(Clone, Debug, PartialEq)]
pub enum IndexRecord {
    /// A document, minus its ranking signals.
    Doc(Box<Document>),
    /// One `(src → dst)` link edge and whether it is internal.
    Link {
        /// Source URL.
        src: String,
        /// Destination URL.
        dst: String,
        /// Whether the edge stays inside the source's host.
        internal: bool,
    },
    /// One host's cross-domain authority score.
    HostAuth {
        /// Host.
        host: String,
        /// Authority in `0..1`.
        value: f64,
    },
    /// All harvested `<img>` rows for one document.
    Images {
        /// Owning document rowid.
        doc_id: i64,
        /// The rows, in stored order.
        rows: Vec<StoredImage>,
    },
    /// All harvested video rows for one document.
    Videos {
        /// Owning document rowid.
        doc_id: i64,
        /// The rows, in stored order.
        rows: Vec<StoredVideo>,
    },
    /// One document's ranking signals — see the module docs for why these are not
    /// part of [`IndexRecord::Doc`].
    Rank {
        /// Document rowid.
        doc_id: i64,
        /// Incoming internal-link count.
        incoming: i64,
        /// Internal PageRank-lite.
        rank: f64,
        /// Cross-domain host authority, denormalised onto the document.
        host_rank: f64,
    },
    /// Store-wide scalars. Exactly one live instance.
    Meta {
        /// The next rowid `upsert_document` will hand out.
        next_id: i64,
    },
}

/// The key a `Doc` record is filed under.
fn doc_key(url: &str) -> Vec<u8> {
    let mut k = Vec::with_capacity(url.len() + 1);
    k.push(TAG_DOC);
    k.extend_from_slice(url.as_bytes());
    k
}

/// The key a `Link` record is filed under.
///
/// `src ‖ NUL ‖ dst`: a canonicalised URL cannot contain a NUL byte (the
/// canonicaliser percent-encodes control characters), so the separator is
/// unambiguous and two different edges cannot collide onto one key by having a
/// concatenation in common.
fn link_key(src: &str, dst: &str) -> Vec<u8> {
    let mut k = Vec::with_capacity(src.len() + dst.len() + 2);
    k.push(TAG_LINK);
    k.extend_from_slice(src.as_bytes());
    k.push(0);
    k.extend_from_slice(dst.as_bytes());
    k
}

fn host_key(host: &str) -> Vec<u8> {
    let mut k = Vec::with_capacity(host.len() + 1);
    k.push(TAG_HOST_AUTH);
    k.extend_from_slice(host.as_bytes());
    k
}

/// Big-endian, so key order is rowid order — which keeps a scan's document ids
/// ascending and makes the media groups reassemble in a stable, explainable
/// sequence rather than a byte-order accident.
fn id_key(tag: u8, doc_id: i64) -> Vec<u8> {
    let mut k = Vec::with_capacity(9);
    k.push(tag);
    k.extend_from_slice(&doc_id.to_be_bytes());
    k
}

impl Record for IndexRecord {
    fn key(&self) -> Vec<u8> {
        match self {
            IndexRecord::Doc(d) => doc_key(&d.url),
            IndexRecord::Link { src, dst, .. } => link_key(src, dst),
            IndexRecord::HostAuth { host, .. } => host_key(host),
            IndexRecord::Images { doc_id, .. } => id_key(TAG_IMAGES, *doc_id),
            IndexRecord::Videos { doc_id, .. } => id_key(TAG_VIDEOS, *doc_id),
            IndexRecord::Rank { doc_id, .. } => id_key(TAG_RANK, *doc_id),
            IndexRecord::Meta { .. } => vec![TAG_META],
        }
    }

    fn encode(&self, out: &mut Vec<u8>) {
        // Encode straight into the caller's buffer via the snapshot codec: the
        // same field encoding `Index::snapshot` uses, so a `Document` means the
        // same bytes in both persistence paths and neither can drift.
        let mut w = Writer::from_vec(std::mem::take(out));
        match self {
            IndexRecord::Doc(d) => {
                w.u8(TAG_DOC);
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
                w.str(&d.etag);
                w.str(&d.last_modified);
                w.str(&d.content_type);
                w.i64(d.simhash);
                // `incoming` / `rank` / `host_rank` deliberately absent: they are
                // the `Rank` record. See the module docs.
            }
            IndexRecord::Link { src, dst, internal } => {
                w.u8(TAG_LINK);
                w.str(src);
                w.str(dst);
                w.bool(*internal);
            }
            IndexRecord::HostAuth { host, value } => {
                w.u8(TAG_HOST_AUTH);
                w.str(host);
                w.f64(*value);
            }
            IndexRecord::Images { doc_id, rows } => {
                w.u8(TAG_IMAGES);
                w.i64(*doc_id);
                w.len(rows.len());
                for r in rows {
                    w.str(&r.page_url);
                    w.str(&r.src);
                    w.str(&r.alt);
                    w.str(&r.title);
                    w.str(&r.context);
                    w.str(&r.host);
                }
            }
            IndexRecord::Videos { doc_id, rows } => {
                w.u8(TAG_VIDEOS);
                w.i64(*doc_id);
                w.len(rows.len());
                for r in rows {
                    w.str(&r.page_url);
                    w.str(&r.video_url);
                    w.str(&r.embed_url);
                    w.str(&r.watch_url);
                    w.str(&r.title);
                    w.str(&r.thumbnail_url);
                    w.str(&r.source);
                    w.opt_i64(r.duration);
                    w.str(&r.context);
                    w.str(&r.host);
                }
            }
            IndexRecord::Rank {
                doc_id,
                incoming,
                rank,
                host_rank,
            } => {
                w.u8(TAG_RANK);
                w.i64(*doc_id);
                w.i64(*incoming);
                w.f64(*rank);
                w.f64(*host_rank);
            }
            IndexRecord::Meta { next_id } => {
                w.u8(TAG_META);
                w.i64(*next_id);
            }
        }
        *out = w.into_bytes();
    }

    fn decode(bytes: &[u8]) -> Option<Self> {
        let mut r = Reader::new(bytes);
        let rec = match r.u8()? {
            TAG_DOC => IndexRecord::Doc(Box::new(Document {
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
                // Zero until a `Rank` record overlays them, which is exactly what
                // a freshly-indexed, not-yet-finalised document looks like in
                // memory too.
                incoming: 0,
                rank: 0.0,
                host_rank: 0.0,
                etag: r.str()?,
                last_modified: r.str()?,
                content_type: r.str()?,
                simhash: r.i64()?,
            })),
            TAG_LINK => IndexRecord::Link {
                src: r.str()?,
                dst: r.str()?,
                internal: r.bool()?,
            },
            TAG_HOST_AUTH => IndexRecord::HostAuth {
                host: r.str()?,
                value: r.f64()?,
            },
            TAG_IMAGES => {
                let doc_id = r.i64()?;
                let n = r.len()?;
                let mut rows = Vec::new();
                for _ in 0..n {
                    rows.push(StoredImage {
                        doc_id,
                        page_url: r.str()?,
                        src: r.str()?,
                        alt: r.str()?,
                        title: r.str()?,
                        context: r.str()?,
                        host: r.str()?,
                    });
                }
                IndexRecord::Images { doc_id, rows }
            }
            TAG_VIDEOS => {
                let doc_id = r.i64()?;
                let n = r.len()?;
                let mut rows = Vec::new();
                for _ in 0..n {
                    rows.push(StoredVideo {
                        doc_id,
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
                IndexRecord::Videos { doc_id, rows }
            }
            TAG_RANK => IndexRecord::Rank {
                doc_id: r.i64()?,
                incoming: r.i64()?,
                rank: r.f64()?,
                host_rank: r.f64()?,
            },
            TAG_META => IndexRecord::Meta { next_id: r.i64()? },
            _ => return None,
        };
        // Every record has a fixed shape, so leftover bytes mean this is not the
        // record it claims to be. Failing here beats accepting a half-understood
        // record and serving whatever it decoded to.
        r.is_at_end().then_some(rec)
    }
}

/// What one [`SegmentedIndex::flush`] wrote.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct FlushStats {
    /// Records written, tombstones included. Zero means nothing had changed and
    /// no segment was created.
    pub records: u64,
    /// Bytes the new segment occupies.
    pub bytes: u64,
    /// The generation the store is now at — every flush that wrote something
    /// bumps it, so this doubles as "how many batches are durable".
    pub generation: u64,
}

/// An [`Index`] persisted as segments.
///
/// Owns the [`Store`]; the `Index` itself stays where it always was (in the
/// crawler, or behind the server's mutex). Flushing is a push from the index into
/// the store, not a wrapper around it, so nothing about how the engine uses its
/// index changes.
pub struct SegmentedIndex {
    store: Store<IndexRecord>,
}

impl SegmentedIndex {
    /// Open (or create) the store in `dir`, strictly.
    ///
    /// # Errors
    /// See [`Store::open`]: a directory with manifests but no loadable one, or a
    /// damaged segment. Use [`SegmentedIndex::open_recovering`] to salvage.
    pub fn open(dir: impl AsRef<Path>) -> Result<Self> {
        Ok(SegmentedIndex {
            store: Store::open(dir)?,
        })
    }

    /// Open the store, repairing what can be repaired.
    ///
    /// # Errors
    /// See [`Store::open_recovering`].
    pub fn open_recovering(dir: impl AsRef<Path>) -> Result<(Self, crawlcore::segstore::Recovery)> {
        let (store, rec) = Store::open_recovering(dir)?;
        Ok((SegmentedIndex { store }, rec))
    }

    /// The underlying store, for stats and compaction policy.
    #[must_use]
    pub fn store(&self) -> &Store<IndexRecord> {
        &self.store
    }

    /// The live generation — the number of durable commits.
    #[must_use]
    pub fn generation(&self) -> u64 {
        self.store.generation()
    }

    /// How many segments are live.
    #[must_use]
    pub fn segment_count(&self) -> usize {
        self.store.segment_count()
    }

    /// Rebuild a complete, in-memory [`Index`] from the store.
    ///
    /// The *scan* is bounded — one record at a time out of each segment — but the
    /// *result* is a fully-resident index, because that is what
    /// [`crate::ranking::search`] needs. See the module docs for why that is the
    /// right trade here and what it costs.
    ///
    /// The returned index has change tracking **on** and an empty change log, so
    /// the first flush after a load writes only what has happened since. That is
    /// what makes `crawl --store=segments` a resume rather than a restart.
    ///
    /// # Errors
    /// I/O or corruption in any segment; a record that fails to decode.
    pub fn load(&self) -> Result<Index> {
        let mut ix = Index::new();
        let mut reader = self.store.reader()?;
        let mut pending_meta: Option<i64> = None;
        reader.for_each(|rec: IndexRecord| match rec {
            IndexRecord::Doc(d) => ix.put_stored_doc(*d),
            IndexRecord::Link { src, dst, internal } => ix.put_stored_link(src, dst, internal),
            IndexRecord::HostAuth { host, value } => ix.put_stored_host_authority(host, value),
            IndexRecord::Images { rows, .. } => ix.put_stored_images(rows),
            IndexRecord::Videos { rows, .. } => ix.put_stored_videos(rows),
            IndexRecord::Rank {
                doc_id,
                incoming,
                rank,
                host_rank,
            } => ix.put_stored_rank(doc_id, incoming, rank, host_rank),
            IndexRecord::Meta { next_id } => pending_meta = Some(next_id),
        })?;
        if let Some(next_id) = pending_meta {
            ix.set_next_rowid(next_id);
        }
        // Tracking on, log empty: from here the store persists deltas. Order
        // matters — `track_changes(true)` marks the whole index dirty (so a store
        // that was never flushed cannot silently lose its head start), and this
        // index came *from* the store, so that head start is already durable.
        ix.track_changes(true);
        let _ = ix.drain_changes();
        Ok(ix)
    }

    /// Write everything `index` has changed since the last flush, as one segment,
    /// and commit it.
    ///
    /// This is the operation the whole design exists for: its cost is the size of
    /// the change, so calling it every few hundred pages is affordable, and
    /// calling it every few hundred pages is what bounds a crash to a few hundred
    /// pages.
    ///
    /// Requires [`Index::track_changes`] to be on; without it the index has no
    /// idea what changed and a flush would be silently empty, so this turns it on
    /// (which marks everything dirty) rather than writing nothing.
    ///
    /// # Errors
    /// I/O errors writing the segment or the manifest. On failure the change log
    /// has already been drained into the batch that failed — see the note in the
    /// body — so the caller should treat a flush error as fatal to the run.
    pub fn flush(&mut self, index: &mut Index) -> Result<FlushStats> {
        if !index.is_tracking_changes() {
            index.track_changes(true);
        }
        if index.pending_changes().is_empty() {
            return Ok(FlushStats {
                records: 0,
                bytes: 0,
                generation: self.store.generation(),
            });
        }
        let log = index.drain_changes();
        let mut batch: Batch<IndexRecord> = Batch::new();

        for id in &log.docs {
            if let Some(d) = index.doc_by_id(*id) {
                batch.put(IndexRecord::Doc(Box::new(d.clone())));
            }
            // A dirty id with no document cannot happen — `Index` never removes
            // one — and if that ever changes, the fix is a tombstone here, not a
            // panic now.
        }
        for id in &log.ranks {
            if let Some(d) = index.doc_by_id(*id) {
                batch.put(IndexRecord::Rank {
                    doc_id: d.id,
                    incoming: d.incoming,
                    rank: d.rank,
                    host_rank: d.host_rank,
                });
            }
        }
        for edge in &log.links {
            if let Some(internal) = index.link_internal(edge) {
                batch.put(IndexRecord::Link {
                    src: edge.0.clone(),
                    dst: edge.1.clone(),
                    internal,
                });
            }
        }
        for host in &log.hosts {
            match index.host_authority(host) {
                Some(value) => batch.put(IndexRecord::HostAuth {
                    host: host.clone(),
                    value,
                }),
                // The host dropped out of the graph on the last recompute. Without
                // this tombstone the score in an older segment stays visible and
                // the store slowly accumulates authority for hosts that are no
                // longer linked from anywhere.
                None => batch.delete(host_key(host)),
            }
        }
        for id in &log.images {
            let rows: Vec<StoredImage> = index.images_of(*id).cloned().collect();
            if rows.is_empty() {
                batch.delete(id_key(TAG_IMAGES, *id));
            } else {
                batch.put(IndexRecord::Images { doc_id: *id, rows });
            }
        }
        for id in &log.videos {
            let rows: Vec<StoredVideo> = index.videos_of(*id).cloned().collect();
            if rows.is_empty() {
                batch.delete(id_key(TAG_VIDEOS, *id));
            } else {
                batch.put(IndexRecord::Videos { doc_id: *id, rows });
            }
        }
        if log.meta {
            batch.put(IndexRecord::Meta {
                next_id: index.next_rowid(),
            });
        }

        let committed = self.store.commit(batch)?;
        let seg = committed
            .segment
            .unwrap_or(crawlcore::segstore::SegmentInfo {
                id: 0,
                records: 0,
                bytes: 0,
            });
        Ok(FlushStats {
            records: seg.records,
            bytes: seg.bytes,
            generation: committed.generation,
        })
    }

    /// Fold the store's segments down if it has accumulated more than
    /// `max_segments`, and sweep what that retires.
    ///
    /// Synchronous. A crawl calls it between batches, where a pause is free;
    /// [`SegmentedIndex::start_compaction`] is the off-thread version for a
    /// caller that cannot stop.
    ///
    /// # Errors
    /// I/O or corruption during the fold or the manifest commit.
    pub fn maybe_compact(&mut self, max_segments: usize) -> Result<Option<MergeOutcome>> {
        if !self.store.should_compact(max_segments) {
            return Ok(None);
        }
        // Fold every live segment. A partial fold would leave the store with a
        // long tail that never gets smaller, and the segments are a prefix run by
        // construction, which is what lets the fold drop tombstones (see the
        // `crawlcore::segstore` docs).
        let out = self.store.compact(self.store.segment_count())?;
        self.store.sweep()?;
        Ok(out)
    }

    /// Start an off-thread fold of everything but the newest segment.
    ///
    /// The reading, folding and writing happen on a `std::thread`; installing the
    /// result is [`SegmentedIndex::finish_compaction`] and costs one small file
    /// write. Flushes may continue while it runs.
    ///
    /// # Errors
    /// I/O errors preparing the job.
    pub fn start_compaction(
        &mut self,
        max_segments: usize,
    ) -> Result<Option<crawlcore::segstore::MergeJob<IndexRecord>>> {
        if !self.store.should_compact(max_segments) {
            return Ok(None);
        }
        // Everything, as in `maybe_compact`. Flushes during the fold append
        // segments AFTER the run being folded, so the prefix the merge captured
        // is still the prefix when it is installed.
        self.store.start_merge(self.store.segment_count())
    }

    /// Install a finished background fold and sweep what it retired.
    ///
    /// # Errors
    /// Whatever the merge failed with, or I/O committing the manifest.
    pub fn finish_compaction(
        &mut self,
        job: crawlcore::segstore::MergeJob<IndexRecord>,
    ) -> Result<MergeOutcome> {
        let out = self.store.finish_merge(job)?;
        self.store.sweep()?;
        Ok(out)
    }

    /// Remove files no retained manifest names — a killed writer's half-committed
    /// segments, and segments a fold retired.
    ///
    /// # Errors
    /// I/O errors listing the directory.
    pub fn sweep(&mut self) -> Result<usize> {
        self.store.sweep()
    }

    /// Replace the store's entire contents with `index`, as one segment.
    ///
    /// The bridge from the blob world: importing a `snapshot` file, or seeding a
    /// segmented store from a run that built its index some other way. Not
    /// incremental by nature — writing everything is its *job* — so it is the one
    /// place this module behaves like a blob save, and it says so.
    ///
    /// [`crawlcore::segstore::Store::truncate`] first, and that is not an
    /// optimisation: without it, any key the new index does not mention keeps its
    /// old value from an older segment. "Replace" has to mean replace.
    ///
    /// # Errors
    /// I/O errors truncating, or writing the segment or the manifest.
    pub fn write_whole(&mut self, index: &mut Index) -> Result<FlushStats> {
        self.store.truncate()?;
        index.track_changes(true); // marks the entire index dirty
        let stats = self.flush(index)?;
        self.store.sweep()?;
        Ok(stats)
    }
}

/// A convenience for callers that just want the index back off disk.
///
/// # Errors
/// As [`SegmentedIndex::load`].
pub fn load_index(dir: impl AsRef<Path>) -> Result<Index> {
    SegmentedIndex::open(dir)?.load()
}

/// Run `crawler` into `seg`, committing a segment every `flush_every` pages.
///
/// # Why the crawl is sliced
///
/// [`crate::crawler::Crawler::run`] crawls to exhaustion and the caller then
/// saves. With a blob that is the only sane shape, because saving costs a corpus
/// rewrite — and it is exactly why a `SIGKILL` mid-run loses the run. Here a
/// commit costs a batch, so the loop is: crawl a little, commit, repeat. At every
/// instant the store is durable up to the last completed slice, which makes
/// `flush_every` the crash window in pages: a number an operator sets, rather
/// than however long the crawl happened to be.
///
/// Folding happens between slices too, where a pause costs nothing — otherwise a
/// long crawl accumulates hundreds of segments and every later read pays for all
/// of them.
///
/// Stops when the page budget is spent, or when a slice makes no progress (the
/// frontier is drained, or what is left is blocked by robots or a host budget).
/// Returns the run's accumulated statistics, as [`crate::crawler::Crawler::run`]
/// would.
///
/// # Errors
/// A failed flush or fold. Treat it as fatal to the run: the change log has
/// already been drained into the batch that failed, so continuing would silently
/// skip those pages.
#[cfg(feature = "net")]
pub async fn crawl_into(
    crawler: &mut crate::crawler::Crawler,
    seg: &mut SegmentedIndex,
    max_pages: u64,
    flush_every: u64,
) -> Result<crate::crawler::CrawlStats> {
    let flush_every = flush_every.max(1);
    loop {
        let before = crawler.stats().fetched;
        if before >= max_pages {
            break;
        }
        let slice = flush_every.min(max_pages - before);
        // `Box::pin`, not a bare `.await`: `run_slice` wraps `Crawler::run`, whose
        // future holds the entire fetch → parse → index pipeline and is very large
        // in an unoptimised build. Embedding it in THIS future makes the loop's
        // state machine larger again, and constructing the nest costs a copy of it
        // on the stack per layer — which overflowed a test thread outright.
        // Boxing puts the crawl's future on the heap and keeps this one small.
        Box::pin(crawler.run_slice(slice)).await;
        seg.flush(crawler.index_mut())?;
        seg.maybe_compact(DEFAULT_MAX_SEGMENTS)?;
        if crawler.stats().fetched == before {
            break; // no progress: nothing left that can be fetched
        }
    }
    Ok(crawler.stats().clone())
}

/// Turn a [`crawlcore::segstore::Error`] into the engine's usual `error: …`
/// string, so the CLI reports a store failure the way it reports every other one.
#[must_use]
pub fn describe(e: &Error) -> String {
    format!("error: index store: {e}")
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::index::DocFields;
    use crate::ranking::{search, SearchOpts};
    use std::path::PathBuf;

    fn scratch(tag: &str) -> PathBuf {
        use std::sync::atomic::{AtomicU64, Ordering};
        static SEQ: AtomicU64 = AtomicU64::new(0);
        let n = SEQ.fetch_add(1, Ordering::Relaxed);
        let d = std::env::temp_dir().join(format!(
            "websearch-segindex-{tag}-{}-{n}",
            std::process::id()
        ));
        let _ = std::fs::remove_dir_all(&d);
        std::fs::create_dir_all(&d).expect("mkdir");
        d
    }

    fn add(ix: &mut Index, url: &str, title: &str, body: &str, host: &str, t: f64) -> i64 {
        ix.upsert_document(
            url,
            DocFields {
                title,
                body,
                host,
                lang: "en",
                fetched_at: t,
                http_status: 200,
                ..DocFields::default()
            },
        )
    }

    #[test]
    fn every_record_kind_round_trips() {
        let cases = vec![
            IndexRecord::Doc(Box::new(Document {
                id: 7,
                url: "http://x/a".into(),
                title: "T".into(),
                description: "D".into(),
                body: "B".into(),
                host: "x".into(),
                lang: "en".into(),
                fetched_at: 1234.5,
                content_hash: "deadbeef".into(),
                http_status: 200,
                incoming: 0,
                rank: 0.0,
                host_rank: 0.0,
                etag: "\"v\"".into(),
                last_modified: "yesterday".into(),
                content_type: "text/html".into(),
                simhash: -42,
            })),
            IndexRecord::Link {
                src: "http://x/a".into(),
                dst: "http://y/b".into(),
                internal: false,
            },
            IndexRecord::HostAuth {
                host: "x".into(),
                value: 0.25,
            },
            IndexRecord::Images {
                doc_id: 3,
                rows: vec![StoredImage {
                    doc_id: 3,
                    page_url: "http://x/a".into(),
                    src: "http://x/i.png".into(),
                    alt: "alt".into(),
                    title: "ti".into(),
                    context: "ctx".into(),
                    host: "x".into(),
                }],
            },
            IndexRecord::Videos {
                doc_id: 3,
                rows: vec![StoredVideo {
                    doc_id: 3,
                    page_url: "http://x/a".into(),
                    video_url: "http://x/v.mp4".into(),
                    embed_url: String::new(),
                    watch_url: String::new(),
                    title: "vid".into(),
                    thumbnail_url: String::new(),
                    source: "direct".into(),
                    duration: Some(90),
                    context: "ctx".into(),
                    host: "x".into(),
                }],
            },
            IndexRecord::Rank {
                doc_id: 3,
                incoming: 5,
                rank: 0.5,
                host_rank: 0.75,
            },
            IndexRecord::Meta { next_id: 99 },
        ];
        for rec in cases {
            let mut buf = Vec::new();
            rec.encode(&mut buf);
            let back = IndexRecord::decode(&buf).expect("decode");
            assert_eq!(back, rec);
            // Trailing junk must be refused, not tolerated.
            buf.push(0);
            assert!(
                IndexRecord::decode(&buf).is_none(),
                "trailing junk for {rec:?}"
            );
        }
    }

    #[test]
    fn encode_appends_rather_than_replacing() {
        // `encode` takes `&mut Vec<u8>` so the store can reuse one buffer; if it
        // ever started by clearing, segments would come out with only the last
        // record's bytes and nothing here would notice until a scan.
        let rec = IndexRecord::Meta { next_id: 5 };
        let mut buf = b"PREFIX".to_vec();
        rec.encode(&mut buf);
        assert!(buf.starts_with(b"PREFIX"));
        assert!(buf.len() > 6);
    }

    #[test]
    fn a_flush_writes_only_the_change() {
        let dir = scratch("delta");
        let mut seg = SegmentedIndex::open(&dir).expect("open");
        let mut ix = Index::new();
        ix.track_changes(true);

        // A big first batch.
        let body = "lorem ipsum ".repeat(500);
        for i in 0..20 {
            add(&mut ix, &format!("http://x/{i}"), "T", &body, "x", 100.0);
        }
        let first = seg.flush(&mut ix).expect("flush");
        assert_eq!(first.records, 20 + 1, "20 docs + the meta record");

        // One more document. THE test: the second flush must be about one
        // document, not twenty-one — that is the whole difference from a blob.
        add(&mut ix, "http://x/new", "T", &body, "x", 200.0);
        let second = seg.flush(&mut ix).expect("flush");
        assert_eq!(second.records, 2, "one doc + meta");
        assert!(
            second.bytes * 10 < first.bytes,
            "an incremental flush wrote {} bytes against a first flush of {} — \
             that is not incremental",
            second.bytes,
            first.bytes
        );

        // And a flush with nothing to say writes nothing at all.
        let third = seg.flush(&mut ix).expect("flush");
        assert_eq!(third.records, 0);
        assert_eq!(
            third.generation, second.generation,
            "no commit, no generation"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn finalize_writes_ranks_not_documents() {
        // The reason `Rank` is a separate record. A finalize over a corpus of
        // fat documents must cost kilobytes, not megabytes.
        let dir = scratch("ranks");
        let mut seg = SegmentedIndex::open(&dir).expect("open");
        let mut ix = Index::new();
        ix.track_changes(true);
        let body = "alpha beta gamma ".repeat(600);
        for i in 0..25 {
            add(&mut ix, &format!("http://x/{i}"), "T", &body, "x", 100.0);
        }
        for i in 0..25 {
            ix.add_links(
                &format!("http://x/{i}"),
                &[(format!("http://x/{}", (i + 1) % 25), true)],
            );
        }
        let corpus = seg.flush(&mut ix).expect("flush");

        ix.finalize();
        let after = seg.flush(&mut ix).expect("flush");
        // 25 rank records and nothing else: one host means no cross-domain edges,
        // so `compute_host_authority` writes no authority scores at all.
        assert_eq!(after.records, 25);
        assert!(
            after.bytes * 20 < corpus.bytes,
            "finalize wrote {} bytes against a {}-byte corpus — the ranking pass is \
             dragging document bodies with it",
            after.bytes,
            corpus.bytes
        );

        // And the ranks actually landed.
        let back = seg.load().expect("load");
        for d in back.all_docs() {
            assert!(d.rank > 0.0, "{} has no rank", d.url);
            assert_eq!(d.incoming, 1);
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn clearing_a_documents_images_tombstones_them() {
        let dir = scratch("mediatomb");
        let mut seg = SegmentedIndex::open(&dir).expect("open");
        let mut ix = Index::new();
        ix.track_changes(true);
        let id = add(&mut ix, "http://x/a", "T", "body", "x", 100.0);
        ix.replace_images(
            id,
            "http://x/a",
            "x",
            &[crate::htmlparse::Image {
                src: "http://x/i.png".into(),
                alt: "kitten".into(),
                title: String::new(),
                context: "a kitten".into(),
            }],
        );
        seg.flush(&mut ix).expect("flush");
        assert_eq!(
            seg.load().expect("load").image_search("kitten", 10).len(),
            1
        );

        // Clear them: the rows are still sitting in the first segment, so the
        // store MUST write down that they are gone.
        ix.replace_images(id, "http://x/a", "x", &[]);
        seg.flush(&mut ix).expect("flush");
        assert_eq!(
            seg.load().expect("load").image_search("kitten", 10).len(),
            0,
            "cleared image rows came back from an older segment"
        );

        // Survives a fold, which is where a mishandled tombstone would resurrect
        // them.
        seg.maybe_compact(1).expect("compact");
        assert_eq!(
            seg.load().expect("load").image_search("kitten", 10).len(),
            0
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_host_that_loses_its_authority_is_tombstoned() {
        // `compute_host_authority` CLEARS its map and rebuilds it, and one of its
        // rebuild paths ends with the map empty (no cross-domain edges → every
        // `host_rank` is zero and no host has an authority score). A store that
        // only ever wrote host records would keep serving the previous crawl's
        // scores from an older segment. This is the flush emitting deletes for
        // hosts the recompute dropped.
        let dir = scratch("hosttomb");
        let mut seg = SegmentedIndex::open(&dir).expect("open");
        let mut ix = Index::new();
        ix.track_changes(true);
        add(&mut ix, "http://a/1", "T", "body", "a", 100.0);
        add(&mut ix, "http://b/1", "T", "body", "b", 100.0);
        ix.add_links("http://a/1", &[("http://b/1".to_string(), false)]);
        ix.finalize();
        seg.flush(&mut ix).expect("flush");
        assert!(
            seg.load().expect("load").host_authority("b").is_some(),
            "the cross-domain edge should have produced an authority score"
        );

        // Now the state a resumed crawl can find itself in: the scores are on
        // disk, the graph they came from is not. `Index::restore` of a snapshot
        // taken before the edges were discovered is exactly this shape, and so is
        // a store seeded from one engine and extended by another.
        let mut revived = Index::new();
        revived.track_changes(true);
        add(&mut revived, "http://a/1", "T", "body", "a", 100.0);
        add(&mut revived, "http://b/1", "T", "body", "b", 100.0);
        revived.put_stored_host_authority("a".into(), 1.0);
        revived.put_stored_host_authority("b".into(), 0.5);
        let _ = revived.drain_changes();
        // No cross-domain edges → the recompute clears the map and keeps it clear.
        revived.finalize();
        assert!(revived.host_authority("b").is_none());
        seg.flush(&mut revived).expect("flush");

        let back = seg.load().expect("load");
        assert!(
            back.host_authority("a").is_none() && back.host_authority("b").is_none(),
            "authority scores outlived the graph that produced them"
        );
        // And they stay gone through a fold, which is where a mishandled
        // tombstone resurrects what it deleted.
        seg.maybe_compact(1).expect("compact");
        let folded = seg.load().expect("load");
        assert!(folded.host_authority("a").is_none() && folded.host_authority("b").is_none());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn write_whole_replaces_rather_than_layers() {
        let dir = scratch("whole");
        let mut seg = SegmentedIndex::open(&dir).expect("open");
        let mut first = Index::new();
        first.track_changes(true);
        add(&mut first, "http://x/gone", "T", "body", "x", 100.0);
        add(&mut first, "http://x/kept", "T", "body", "x", 100.0);
        seg.flush(&mut first).expect("flush");

        let mut second = Index::new();
        add(
            &mut second,
            "http://x/kept",
            "T",
            "different body",
            "x",
            200.0,
        );
        seg.write_whole(&mut second).expect("write whole");

        let back = seg.load().expect("load");
        assert_eq!(
            back.doc_count(),
            1,
            "the replaced document survived a replace"
        );
        assert!(back.get_doc("http://x/gone").is_none());
        assert_eq!(
            back.get_doc("http://x/kept").expect("kept").fetched_at,
            200.0
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_segmented_load_equals_a_blob_restore() {
        let dir = scratch("equal");
        let mut seg = SegmentedIndex::open(&dir).expect("open");
        let mut ix = Index::new();
        ix.track_changes(true);
        for i in 0..12 {
            add(
                &mut ix,
                &format!("http://h{}/p{i}", i % 3),
                &format!("Title {i}"),
                &format!("body words number {i} lorem ipsum dolor"),
                &format!("h{}", i % 3),
                1000.0 + f64::from(i),
            );
            ix.add_links(
                &format!("http://h{}/p{i}", i % 3),
                &[(
                    format!("http://h{}/p{}", (i + 1) % 3, (i + 1) % 12),
                    i % 2 == 0,
                )],
            );
            // Flush mid-way, several times, so the store is genuinely multi-segment
            // and shadowing is exercised.
            if i % 3 == 0 {
                seg.flush(&mut ix).expect("flush");
            }
        }
        ix.finalize();
        seg.flush(&mut ix).expect("flush");
        assert!(seg.segment_count() > 1, "the store should be multi-segment");

        let blob = Index::restore(&ix.snapshot()).expect("restore");
        let segd = seg.load().expect("load");
        assert_eq!(segd.doc_count(), blob.doc_count());
        assert_eq!(segd.stats(), blob.stats());
        for d in blob.all_docs() {
            assert_eq!(segd.get_doc(&d.url), Some(d), "document {} differs", d.url);
        }
        let opts = SearchOpts {
            now: 2000.0,
            ..SearchOpts::default()
        };
        for q in ["lorem", "body words", "title", "h1", "number 5"] {
            assert_eq!(
                search(&segd, q, &opts),
                search(&blob, q, &opts),
                "query {q:?} differs between stores"
            );
        }
        let _ = std::fs::remove_dir_all(&dir);
    }
}
