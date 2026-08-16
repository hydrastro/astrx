//! An append-only segment store: immutable numbered segments plus a small
//! manifest, with compaction.
//!
//! # What goes wrong without this
//!
//! Every engine in this suite persists its index the same way — build the whole
//! thing in memory, serialise it to one blob, publish the blob to one file
//! (`Index::snapshot()` → [`crate::atomicfile::write_atomic`]) — and loads it by
//! parsing that blob back. [`crate::atomicfile`] already made the *file* safe: a
//! torn write can no longer destroy the previous good index. What it cannot fix
//! is that there is only ever one write, of everything, at one moment. Four
//! separate problems fall out of that single decision:
//!
//! 1. **A save briefly doubles memory.** The index is resident *and* the
//!    serialised copy of it is resident, at the same time, because `write_atomic`
//!    wants a finished `&[u8]`. An 800 MB index needs 1.6 GB to save.
//! 2. **Every save rewrites everything.** A crawl that adds 100 documents to a
//!    1 000 000-document index writes 1 000 000 documents back out. The cost of a
//!    save is a function of the corpus, not of the change — which is *why* saves
//!    are periodic and coarse instead of continuous.
//! 3. **A crash between saves loses everything since the last one.** The file is
//!    never corrupt, and that is not the same as safe: kill a six-hour crawl five
//!    minutes before its single terminal save and six hours are gone.
//! 4. **You cannot usefully resume.** "Continue where you left off" means reading
//!    the world back in and writing the world back out, so nobody does it; runs
//!    are one-shot and restarting means starting over.
//!
//! A segment store fixes 2, 3 and 4 outright and 1 partially (see
//! [Honest limits](#honest-limits)). It is the standard log-structured shape:
//!
//! - A **segment** is an immutable file. It is written exactly once, by
//!   [`crate::atomicfile::write_atomic_with`], holds one batch of records, and is
//!   never opened for writing again. Immutability is what makes a reader safe
//!   without a lock: nothing can change under it.
//! - A **manifest** is a small text file naming the live segments **in order**,
//!   oldest first, plus a generation number. Committing a batch is: write the
//!   segment, fsync it, then publish a new manifest. Because the manifest is
//!   small, a commit costs a segment write plus a few hundred bytes — so commits
//!   can be *frequent*, which is the whole point of problem 3.
//! - **Reads** merge the live segments **newest-first**: a record in a later
//!   segment shadows the same key in an earlier one, and a **tombstone**
//!   ([`Batch::delete`]) shadows it with nothing.
//! - **Compaction** ([`Store::compact`], [`Store::start_merge`]) folds the oldest
//!   run of segments into one, dropping shadowed and tombstoned records, and
//!   commits a manifest naming the result in their place. The old segments are
//!   deleted only *after* a manifest that does not name them is durable — and
//!   even then, one generation late (see [`Store::sweep`]).
//! - **Recovery** is: read the newest manifest that parses, open the segments it
//!   names. A segment file the manifest does not name is a crashed writer's
//!   debris; it is ignored, and [`Store::sweep`] removes it.
//!
//! # Why the crash window closes
//!
//! The ordering is the guarantee, and it is worth stating exactly:
//!
//! ```text
//! write segment file  → fsync file → fsync dir      (atomicfile::write_atomic_with)
//! write manifest file → fsync file → fsync dir      (atomicfile::write_atomic)
//! ```
//!
//! A manifest is therefore *never* durable before the segments it names. Crash at
//! any instant and the newest durable manifest names only segments that are fully
//! on disk, so recovery is total for every batch that committed and total *loss*
//! for the batch in flight. "You lose at most the batch you were writing" is not
//! a hope about timing; it is a consequence of the fsync order.
//!
//! # Honest limits
//!
//! This is a real store, not a marketing document. What it does **not** buy:
//!
//! - **Compaction is a fold, not a level.** [`Store::compact`] merges a *prefix*
//!   of the manifest — the oldest N segments — so write amplification is the
//!   classic un-levelled kind: fold the base segment repeatedly and you rewrite
//!   it each time. A real LSM would put segments in size tiers. This does not,
//!   and a store with a very large base and a fast writer will spend real I/O
//!   re-folding it. The prefix restriction is also load-bearing for correctness,
//!   not just simplicity: because the run starts at the *oldest* segment, a
//!   tombstone inside it has nothing older left to shadow and can be dropped. A
//!   middle run would have to carry its tombstones forward, and this code would
//!   get that wrong before it got it right.
//! - **"Background" is precise, and narrow.** [`Store::start_merge`] moves the
//!   expensive half — reading the old segments, folding them, writing the new
//!   segment — onto a `std::thread`. The cheap half, swapping the manifest, still
//!   happens on the owning thread in [`Store::finish_merge`], and takes about as
//!   long as one small file write. It is not a daemon: nothing polls, nothing
//!   merges unless a caller asks. [`Store::compact`] is the same work done
//!   synchronously, and is what the tests mostly use because it is deterministic.
//! - **The read path is bounded; a caller that folds into a `HashMap` is not.**
//!   [`Reader::for_each`] is a true k-way merge over per-segment iterators: it
//!   holds one decoded record and one 8 KiB buffer per *segment*, never a
//!   corpus's worth, and [`Reader::peak_resident_bytes`] reports the high-water
//!   mark so that claim is an assertion rather than a sentence. But the store
//!   cannot make the caller streaming. `websearch` rebuilds a fully-resident
//!   `Index` from the stream because its BM25 pass needs the whole corpus in
//!   memory anyway; see `websearch::segindex` for why that is the right trade
//!   *there* and why it means problem 1 above is only half-solved for that
//!   engine. Halved, though, is real: the *save* side no longer doubles, because
//!   a batch — not the corpus — is what has to be resident to be written.
//! - **One writer.** There is no lock file and no cross-process mutual exclusion.
//!   Two processes committing to the same directory will both allocate segment
//!   ids from the manifest they read and one will lose. The suite's engines have
//!   a single writer process by construction; if that ever stops being true this
//!   needs an `O_EXCL` lock, and until then a lock file would be decoration.
//! - **No point lookup.** Segments are sorted by key, so a binary search over a
//!   per-segment index block is the obvious next step, but there is no index
//!   block: everything here is a scan. This store is built for "load it all back
//!   at startup", which is what the engines actually do.
//!
//! # Format
//!
//! Segment (`seg-<id>.seg`, all integers little-endian):
//!
//! ```text
//! "ASTRXSG1"                                    magic + version
//! record*                                       ascending key order, keys unique
//!     kind:u8  klen:u32  vlen:u32  key  value   kind 0 = put, 1 = tombstone
//! "ASTRXEND" count:u64 crc32:u32                footer, crc over everything before it
//! ```
//!
//! Manifest (`manifest-<generation, 20 digits>`) is **text**, deliberately: it is
//! the one file an operator reads at 3am to find out what the store thinks it
//! has, and `cat` should be enough.
//!
//! ```text
//! ASTRX-SEGSTORE 1
//! generation 7
//! segment <id> <records> <bytes>
//! ...
//! sha256 <hex of everything above>
//! ```

use crate::atomicfile::{write_atomic, write_atomic_with};
use crate::hash::{sha256, to_hex};
use std::collections::BTreeMap;
use std::fmt;
use std::fs::File;
use std::io::{BufReader, Read, Seek, SeekFrom, Write};
use std::marker::PhantomData;
use std::path::{Path, PathBuf};

// ---------------------------------------------------------------------------
// Wire constants
// ---------------------------------------------------------------------------

const SEG_MAGIC: &[u8; 8] = b"ASTRXSG1";
const SEG_FOOTER_MAGIC: &[u8; 8] = b"ASTRXEND";
/// `SEG_FOOTER_MAGIC` + `count:u64` + `crc32:u32`.
const SEG_FOOTER_LEN: u64 = 8 + 8 + 4;
const SEG_HEADER_LEN: u64 = 8;

const MANIFEST_HEADER: &str = "ASTRX-SEGSTORE 1";
const MANIFEST_PREFIX: &str = "manifest-";
const SEGMENT_PREFIX: &str = "seg-";
const SEGMENT_SUFFIX: &str = ".seg";

/// How many manifest generations stay on disk, and — more importantly — how long
/// a segment outlives the manifest that stopped naming it.
///
/// Two, not one. The point of keeping the previous manifest is that a *corrupt
/// newest* manifest can fall back to it, and a manifest whose segments have been
/// deleted is not something to fall back to. So [`Store::sweep`] deletes a
/// segment only when neither retained manifest names it, which buys the fallback
/// path an actual, loadable target. One would be a fallback to a dangling
/// pointer; three would just be more disk.
const MANIFEST_RETAIN: usize = 2;

/// Largest key or value a single record frame may declare, and the reason it
/// exists: `klen`/`vlen` are read straight off the disk, and a flipped bit in a
/// length field is a request to allocate that many bytes. 64 MiB is far above any
/// honest record (`websearch`'s biggest is a document body, capped in the
/// crawler at ~1 MiB) and far below "the process dies".
const MAX_FRAME_FIELD: usize = 64 << 20;

/// Per-segment read buffer. Also the unit [`Reader::peak_resident_bytes`] is
/// counted in, so it is named rather than left as `BufReader`'s default.
const READ_BUF: usize = 8 << 10;

// ---------------------------------------------------------------------------
// The record trait
// ---------------------------------------------------------------------------

/// What a segment store needs to know about the thing it is storing.
///
/// Deliberately three methods. The framing (lengths, ordering, checksums,
/// tombstones) is the store's problem; the record only has to say who it is and
/// how to turn into and out of bytes.
///
/// [`Record::encode`] appends into a buffer the store owns rather than returning
/// a `Vec`, so writing a batch of 10 000 records is not 10 000 allocations;
/// [`Record::decode`] is handed the exact frame payload, so it never has to be
/// self-delimiting and can be a straight field-by-field read.
///
/// # Contract
///
/// - [`Record::key`] must be deterministic. It is called once, when the record
///   enters a [`Batch`], and the returned bytes are what the store stores,
///   orders and shadows by. A key that changes between calls does not corrupt a
///   segment, but it does mean the record you read back is filed under a name you
///   no longer compute.
/// - `decode(encode(r))` must reproduce `r`. Nothing checks this; the
///   round-trip tests in each adopting engine are what does.
pub trait Record: Sized {
    /// This record's identity. A record in a later segment shadows an earlier
    /// record with the same key; a tombstone with this key deletes it.
    fn key(&self) -> Vec<u8>;

    /// Append this record's bytes to `out`.
    fn encode(&self, out: &mut Vec<u8>);

    /// Rebuild a record from exactly the bytes [`Record::encode`] appended.
    /// Returns `None` for anything malformed — a corrupt segment must never
    /// panic, only fail.
    fn decode(bytes: &[u8]) -> Option<Self>;
}

// ---------------------------------------------------------------------------
// Errors
// ---------------------------------------------------------------------------

/// Everything that can go wrong in a segment store.
#[derive(Debug)]
pub enum Error {
    /// Underlying I/O, with the path that provoked it — a bare `ErrorKind` is
    /// useless in a store made of dozens of files.
    Io {
        /// The file being operated on.
        path: PathBuf,
        /// The underlying error.
        source: std::io::Error,
    },
    /// A file exists but does not say what it must. Carries the path and a
    /// human-readable reason, because "corrupt" alone is not actionable.
    Corrupt {
        /// The offending file.
        path: PathBuf,
        /// What was wrong with it.
        detail: String,
    },
    /// The directory holds manifests but not one of them could be loaded — the
    /// only unrecoverable state, and distinct from "empty directory", which is a
    /// perfectly good empty store.
    NoUsableManifest {
        /// The store directory.
        dir: PathBuf,
        /// Why each candidate was rejected, newest first.
        rejected: Vec<String>,
    },
    /// A background merge thread panicked. Reported rather than propagated as a
    /// panic on the caller's thread, so a merge bug cannot take down a crawl.
    MergePanicked,
}

impl fmt::Display for Error {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Error::Io { path, source } => write!(f, "{}: {source}", path.display()),
            Error::Corrupt { path, detail } => write!(f, "{}: {detail}", path.display()),
            Error::NoUsableManifest { dir, rejected } => write!(
                f,
                "{}: no usable manifest ({})",
                dir.display(),
                rejected.join("; ")
            ),
            Error::MergePanicked => write!(f, "segment merge thread panicked"),
        }
    }
}

impl std::error::Error for Error {
    fn source(&self) -> Option<&(dyn std::error::Error + 'static)> {
        match self {
            Error::Io { source, .. } => Some(source),
            _ => None,
        }
    }
}

/// A segment-store result.
pub type Result<T> = std::result::Result<T, Error>;

fn io_err(path: &Path, source: std::io::Error) -> Error {
    Error::Io {
        path: path.to_path_buf(),
        source,
    }
}

fn corrupt(path: &Path, detail: impl Into<String>) -> Error {
    Error::Corrupt {
        path: path.to_path_buf(),
        detail: detail.into(),
    }
}

// ---------------------------------------------------------------------------
// CRC-32 (IEEE), for segment integrity
// ---------------------------------------------------------------------------

/// CRC-32/IEEE, table built at compile time.
///
/// Not a cryptographic hash and not trying to be: the segment checksum's job is
/// to notice a truncated file, a half-flushed page or a flipped bit, and to do it
/// while the bytes are already streaming past. `sha256` is used for the manifest,
/// which is small enough to hash in one shot and is the file whose *authority*
/// matters. Rolling our own here rather than adding a dependency is the same
/// trade the rest of the crate makes; 12 lines is cheaper than a supply chain.
const fn crc32_table() -> [u32; 256] {
    let mut table = [0u32; 256];
    let mut i = 0usize;
    while i < 256 {
        let mut c = i as u32;
        let mut k = 0;
        while k < 8 {
            c = if c & 1 != 0 {
                0xEDB8_8320 ^ (c >> 1)
            } else {
                c >> 1
            };
            k += 1;
        }
        table[i] = c;
        i += 1;
    }
    table
}

static CRC32_TABLE: [u32; 256] = crc32_table();

fn crc32_update(crc: u32, bytes: &[u8]) -> u32 {
    let mut c = crc ^ 0xFFFF_FFFF;
    for &b in bytes {
        c = CRC32_TABLE[((c ^ u32::from(b)) & 0xFF) as usize] ^ (c >> 8);
    }
    c ^ 0xFFFF_FFFF
}

// ---------------------------------------------------------------------------
// Batches
// ---------------------------------------------------------------------------

/// One record's fate in a batch: a value, or a tombstone.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Item<R> {
    /// Store this record under its key.
    Put(R),
    /// Delete whatever this key held. A tombstone is a *record*, not an absence:
    /// segments are immutable, so the only way to say "this is gone" is to write
    /// it down in a newer one.
    Delete,
}

/// A set of writes destined for one segment.
///
/// Keyed and sorted, so two writes to the same key inside one batch collapse
/// (last wins) and the segment comes out in ascending key order — which is what
/// makes [`Reader::for_each`] a streaming k-way merge instead of something that
/// has to remember every key it has seen.
///
/// A batch is fully resident. That is the intended bound and the reason a save no
/// longer doubles the process: what has to be in memory to be written is the
/// change, not the corpus.
pub struct Batch<R: Record> {
    entries: BTreeMap<Vec<u8>, Item<R>>,
}

impl<R: Record> Default for Batch<R> {
    fn default() -> Self {
        Batch {
            entries: BTreeMap::new(),
        }
    }
}

impl<R: Record> Batch<R> {
    /// An empty batch.
    #[must_use]
    pub fn new() -> Self {
        Batch::default()
    }

    /// Stage a record, replacing any earlier staging of the same key.
    pub fn put(&mut self, record: R) {
        self.entries.insert(record.key(), Item::Put(record));
    }

    /// Stage a deletion of `key`, replacing any earlier staging of it.
    pub fn delete(&mut self, key: Vec<u8>) {
        self.entries.insert(key, Item::Delete);
    }

    /// How many distinct keys this batch will write.
    #[must_use]
    pub fn len(&self) -> usize {
        self.entries.len()
    }

    /// Whether the batch would write nothing.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.entries.is_empty()
    }
}

// ---------------------------------------------------------------------------
// Manifest
// ---------------------------------------------------------------------------

/// One live segment as the manifest describes it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct SegmentInfo {
    /// Segment id; also its filename (`seg-<id>.seg`).
    pub id: u64,
    /// Records the segment holds, tombstones included.
    pub records: u64,
    /// Total file size, header and footer included. Checked against the real file
    /// on open, which is how a truncation is caught before a single record is
    /// decoded.
    pub bytes: u64,
}

/// The list of live segments, in age order, at one generation.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Manifest {
    /// Monotonic commit counter. Also the manifest's filename suffix, so "newest
    /// manifest" is a directory listing rather than a pointer file to keep
    /// consistent.
    pub generation: u64,
    /// Live segments, **oldest first**. Order is the shadowing order and is a
    /// property of this list, *not* of the ids: compaction gives the merged
    /// segment a fresh (higher) id but puts it back where the run it replaced
    /// was, so sorting by id would silently invert which record wins.
    pub segments: Vec<SegmentInfo>,
}

impl Manifest {
    fn render(&self) -> Vec<u8> {
        let mut body = format!("{MANIFEST_HEADER}\ngeneration {}\n", self.generation);
        for s in &self.segments {
            body.push_str(&format!("segment {} {} {}\n", s.id, s.records, s.bytes));
        }
        let digest = to_hex(&sha256(body.as_bytes()));
        body.push_str(&format!("sha256 {digest}\n"));
        body.into_bytes()
    }

    fn parse(path: &Path, bytes: &[u8]) -> Result<Manifest> {
        let text =
            std::str::from_utf8(bytes).map_err(|_| corrupt(path, "manifest is not valid UTF-8"))?;
        // The checksum covers everything before the final `sha256` line, so split
        // there first and verify before trusting a single field.
        let idx = text
            .rfind("\nsha256 ")
            .ok_or_else(|| corrupt(path, "manifest has no sha256 line"))?;
        let (body, tail) = text.split_at(idx + 1);
        let want = tail
            .strip_prefix("sha256 ")
            .and_then(|s| s.strip_suffix('\n'))
            .ok_or_else(|| corrupt(path, "malformed sha256 line"))?;
        let got = to_hex(&sha256(body.as_bytes()));
        if got != want {
            return Err(corrupt(
                path,
                format!("manifest checksum mismatch (want {want}, got {got})"),
            ));
        }

        let mut lines = body.lines();
        if lines.next() != Some(MANIFEST_HEADER) {
            return Err(corrupt(path, "unrecognised manifest header"));
        }
        let generation = lines
            .next()
            .and_then(|l| l.strip_prefix("generation "))
            .and_then(|n| n.parse::<u64>().ok())
            .ok_or_else(|| corrupt(path, "missing generation"))?;
        let mut segments = Vec::new();
        for line in lines {
            let rest = line
                .strip_prefix("segment ")
                .ok_or_else(|| corrupt(path, format!("unexpected manifest line {line:?}")))?;
            let mut f = rest.split(' ');
            let mut num = |what: &str| -> Result<u64> {
                f.next()
                    .and_then(|s| s.parse::<u64>().ok())
                    .ok_or_else(|| corrupt(path, format!("segment line missing {what}")))
            };
            let info = SegmentInfo {
                id: num("id")?,
                records: num("records")?,
                bytes: num("bytes")?,
            };
            if f.next().is_some() {
                return Err(corrupt(path, "trailing junk on segment line"));
            }
            segments.push(info);
        }
        Ok(Manifest {
            generation,
            segments,
        })
    }
}

// ---------------------------------------------------------------------------
// Segment writing
// ---------------------------------------------------------------------------

/// A `Write` that checksums and counts what passes through it, so the footer can
/// be produced without a second pass over the bytes.
struct CrcWriter<'a> {
    inner: &'a mut dyn Write,
    crc: u32,
    bytes: u64,
}

impl Write for CrcWriter<'_> {
    fn write(&mut self, buf: &[u8]) -> std::io::Result<usize> {
        self.inner.write_all(buf)?;
        self.crc = crc32_update(self.crc, buf);
        self.bytes += buf.len() as u64;
        Ok(buf.len())
    }
    fn flush(&mut self) -> std::io::Result<()> {
        self.inner.flush()
    }
}

fn frame(w: &mut CrcWriter<'_>, kind: u8, key: &[u8], value: &[u8]) -> std::io::Result<()> {
    let mut head = [0u8; 9];
    head[0] = kind;
    head[1..5].copy_from_slice(&(key.len() as u32).to_le_bytes());
    head[5..9].copy_from_slice(&(value.len() as u32).to_le_bytes());
    w.write_all(&head)?;
    w.write_all(key)?;
    w.write_all(value)
}

/// Write `items` (which MUST already be in ascending, unique key order) as a
/// segment at `path`. Returns `(records, bytes)` for the manifest entry.
///
/// Streams: peak memory is one encoded record, not the segment. That is why
/// compaction can fold a corpus-sized base segment without a corpus-sized
/// allocation — see [`crate::atomicfile::write_atomic_with`].
fn write_segment<R, I>(path: &Path, items: I) -> Result<(u64, u64)>
where
    R: Record,
    I: IntoIterator<Item = (Vec<u8>, Item<R>)>,
{
    let mut records = 0u64;
    let mut bytes = 0u64;
    let mut oversized: Option<String> = None;
    write_atomic_with(path, |sink| {
        let mut w = CrcWriter {
            inner: sink,
            crc: 0,
            bytes: 0,
        };
        w.write_all(SEG_MAGIC)?;
        let mut value = Vec::new();
        for (key, item) in items {
            value.clear();
            let kind = match &item {
                Item::Put(r) => {
                    r.encode(&mut value);
                    0u8
                }
                Item::Delete => 1u8,
            };
            if key.len() > MAX_FRAME_FIELD || value.len() > MAX_FRAME_FIELD {
                // Refuse rather than write a segment this build could not read
                // back. Reported through a captured slot because the closure's
                // error channel is `io::Error` and this is not an I/O fault.
                oversized = Some(format!(
                    "record key/value exceeds {MAX_FRAME_FIELD} bytes (key {}, value {})",
                    key.len(),
                    value.len()
                ));
                return Err(std::io::Error::new(
                    std::io::ErrorKind::InvalidInput,
                    "record too large",
                ));
            }
            frame(&mut w, kind, &key, &value)?;
            records += 1;
        }
        let count = records;
        w.write_all(SEG_FOOTER_MAGIC)?;
        w.write_all(&count.to_le_bytes())?;
        // The crc covers the header, every frame, and the footer magic + count —
        // everything except the four bytes that hold it.
        let crc = w.crc;
        w.inner.write_all(&crc.to_le_bytes())?;
        bytes = w.bytes + 4;
        Ok(())
    })
    .map_err(|e| match oversized {
        Some(detail) => corrupt(path, detail),
        None => io_err(path, e),
    })?;
    Ok((records, bytes))
}

// ---------------------------------------------------------------------------
// Segment reading
// ---------------------------------------------------------------------------

/// One segment's file handle, held open for as long as a [`Reader`] lives.
///
/// Holding the descriptor is what makes "a reader is never pulled out from under"
/// true rather than merely likely: on POSIX an unlinked file stays fully readable
/// through an open descriptor, so a compaction that commits and deletes segments
/// mid-scan is invisible to a reader that already opened them.
struct SegFile {
    info: SegmentInfo,
    path: PathBuf,
    file: File,
}

/// A streaming cursor over one segment, yielding `(key, item)` in ascending key
/// order. Holds exactly one decoded record plus its read buffer.
struct SegCursor<'a> {
    reader: BufReader<&'a mut File>,
    path: &'a Path,
    left: u64,
    /// The segment's total record count, so an error can name the record index.
    info_records: u64,
    key: Vec<u8>,
    value: Vec<u8>,
    /// The previous record's key, kept only to check that keys ascend. See
    /// [`SegCursor::advance`].
    prev_key: Vec<u8>,
    seen_one: bool,
    /// `Some(kind)` when `key`/`value` hold an un-consumed record.
    pending: Option<u8>,
}

impl<'a> SegCursor<'a> {
    fn new(seg: &'a mut SegFile) -> Result<Self> {
        seg.file
            .seek(SeekFrom::Start(0))
            .map_err(|e| io_err(&seg.path, e))?;
        let left = seg.info.records;
        let path: &Path = &seg.path;
        let mut c = SegCursor {
            reader: BufReader::with_capacity(READ_BUF, &mut seg.file),
            path,
            left,
            info_records: left,
            key: Vec::new(),
            value: Vec::new(),
            prev_key: Vec::new(),
            seen_one: false,
            pending: None,
        };
        let mut magic = [0u8; 8];
        c.read_exact(&mut magic)?;
        if &magic != SEG_MAGIC {
            return Err(corrupt(c.path, "bad segment magic"));
        }
        c.advance()?;
        Ok(c)
    }

    fn read_exact(&mut self, buf: &mut [u8]) -> Result<()> {
        self.reader
            .read_exact(buf)
            .map_err(|e| io_err(self.path, e))
    }

    /// Decode the next frame into `key`/`value`, or set `pending = None` at the
    /// record count the manifest promised.
    fn advance(&mut self) -> Result<()> {
        if self.left == 0 {
            self.pending = None;
            return Ok(());
        }
        self.left -= 1;
        let mut head = [0u8; 9];
        self.read_exact(&mut head)?;
        let kind = head[0];
        if kind > 1 {
            return Err(corrupt(self.path, format!("unknown record kind {kind}")));
        }
        let klen = u32::from_le_bytes([head[1], head[2], head[3], head[4]]) as usize;
        let vlen = u32::from_le_bytes([head[5], head[6], head[7], head[8]]) as usize;
        if klen > MAX_FRAME_FIELD || vlen > MAX_FRAME_FIELD {
            return Err(corrupt(
                self.path,
                format!("record frame declares {klen}+{vlen} bytes"),
            ));
        }
        // `resize` rather than a fresh Vec: the buffers are reused for the whole
        // scan, so a segment costs one allocation that grows to its largest
        // record and then stops. This is the entire memory story of a read.
        self.key.resize(klen, 0);
        self.value.resize(vlen, 0);
        let mut key = std::mem::take(&mut self.key);
        let mut value = std::mem::take(&mut self.value);
        let r = self.read_exact(&mut key).and_then(|()| {
            if vlen == 0 {
                Ok(())
            } else {
                self.read_exact(&mut value)
            }
        });
        self.key = key;
        self.value = value;
        r?;
        // Keys within a segment are strictly ascending by construction: a `Batch`
        // is a `BTreeMap`, and a fold emits in merge order. Checking it makes the
        // k-way merge's precondition ENFORCED rather than assumed — an
        // out-of-order segment would not crash anything, it would quietly resolve
        // shadowing the wrong way round and serve a superseded record, which is
        // the worst failure this design has. One key-sized comparison and memcpy
        // per record, against I/O that costs orders of magnitude more.
        if self.seen_one && self.key <= self.prev_key {
            return Err(corrupt(
                self.path,
                format!(
                    "keys out of order at record {} ({} after {})",
                    self.info_records.saturating_sub(self.left),
                    to_hex(&self.key),
                    to_hex(&self.prev_key)
                ),
            ));
        }
        self.prev_key.clear();
        self.prev_key.extend_from_slice(&self.key);
        self.seen_one = true;
        self.pending = Some(kind);
        Ok(())
    }

    fn resident_bytes(&self) -> usize {
        READ_BUF + self.key.capacity() + self.value.capacity() + self.prev_key.capacity()
    }
}

/// Verify a whole segment file: magic, footer, record count, checksum.
///
/// One streaming pass with a fixed buffer — a 4 GB segment verifies in 64 KiB of
/// memory. Called once per segment at [`Store::open`], which is the moment the
/// question "did the last crash leave this file whole?" actually needs an answer;
/// [`Reader`] creation deliberately does not repeat it, so opening a reader per
/// query stays cheap.
fn verify_segment(path: &Path, info: &SegmentInfo) -> Result<()> {
    let mut f = File::open(path).map_err(|e| io_err(path, e))?;
    let len = f.metadata().map_err(|e| io_err(path, e))?.len();
    if len != info.bytes {
        return Err(corrupt(
            path,
            format!(
                "size {len} but the manifest says {} — truncated or overwritten",
                info.bytes
            ),
        ));
    }
    if len < SEG_HEADER_LEN + SEG_FOOTER_LEN {
        return Err(corrupt(path, "file too short to be a segment"));
    }
    let body = len - 4; // everything the crc covers
    let mut crc = 0u32;
    let mut buf = vec![0u8; 64 << 10];
    let mut done = 0u64;
    while done < body {
        let want = ((body - done) as usize).min(buf.len());
        f.read_exact(&mut buf[..want])
            .map_err(|e| io_err(path, e))?;
        crc = crc32_update(crc, &buf[..want]);
        done += want as u64;
    }
    let mut tail = [0u8; 4];
    f.read_exact(&mut tail).map_err(|e| io_err(path, e))?;
    let stored = u32::from_le_bytes(tail);
    if stored != crc {
        return Err(corrupt(
            path,
            format!("checksum mismatch (stored {stored:08x}, computed {crc:08x})"),
        ));
    }
    // The footer sits at the end of the region we just hashed; re-read it rather
    // than remembering it, because the loop above is chunked and the footer can
    // straddle a chunk boundary.
    f.seek(SeekFrom::Start(len - SEG_FOOTER_LEN))
        .map_err(|e| io_err(path, e))?;
    let mut footer = [0u8; SEG_FOOTER_LEN as usize];
    f.read_exact(&mut footer).map_err(|e| io_err(path, e))?;
    if &footer[..8] != SEG_FOOTER_MAGIC {
        return Err(corrupt(path, "missing footer magic — segment is torn"));
    }
    let count = u64::from_le_bytes(footer[8..16].try_into().unwrap_or([0; 8]));
    if count != info.records {
        return Err(corrupt(
            path,
            format!(
                "footer says {count} records, manifest says {}",
                info.records
            ),
        ));
    }
    Ok(())
}

/// Read whatever prefix of a damaged segment is intact.
///
/// Used only by [`Store::open_recovering`]. Frames are decoded until one runs off
/// the end or fails to make sense; everything before that is returned. This is a
/// salvage operation and is *not* automatic — losing the tail of a segment
/// silently is how you end up debugging a search result that was never indexed.
/// A frame recovered from a damaged segment: its key, and its value bytes
/// uninterpreted. Salvage deliberately does not know the record type.
type SalvagedFrame = (Vec<u8>, Item<Vec<u8>>);

fn salvage_segment(path: &Path) -> Result<Vec<SalvagedFrame>> {
    let bytes = std::fs::read(path).map_err(|e| io_err(path, e))?;
    if bytes.len() < SEG_HEADER_LEN as usize || &bytes[..8] != SEG_MAGIC {
        return Err(corrupt(path, "bad segment magic; nothing to salvage"));
    }
    let mut out = Vec::new();
    let mut pos = SEG_HEADER_LEN as usize;
    loop {
        if bytes.len() - pos >= 8 && &bytes[pos..pos + 8] == SEG_FOOTER_MAGIC {
            break; // reached an intact footer
        }
        if pos + 9 > bytes.len() {
            break;
        }
        let kind = bytes[pos];
        if kind > 1 {
            break;
        }
        let klen =
            u32::from_le_bytes(bytes[pos + 1..pos + 5].try_into().unwrap_or([0; 4])) as usize;
        let vlen =
            u32::from_le_bytes(bytes[pos + 5..pos + 9].try_into().unwrap_or([0; 4])) as usize;
        if klen > MAX_FRAME_FIELD || vlen > MAX_FRAME_FIELD {
            break;
        }
        let end = match pos
            .checked_add(9)
            .and_then(|p| p.checked_add(klen))
            .and_then(|p| p.checked_add(vlen))
        {
            Some(e) if e <= bytes.len() => e,
            _ => break,
        };
        let key = bytes[pos + 9..pos + 9 + klen].to_vec();
        let item = if kind == 0 {
            Item::Put(bytes[pos + 9 + klen..end].to_vec())
        } else {
            Item::Delete
        };
        out.push((key, item));
        pos = end;
    }
    Ok(out)
}

// ---------------------------------------------------------------------------
// Reader
// ---------------------------------------------------------------------------

/// A consistent view of the store, pinned by open file descriptors.
///
/// A `Reader` is bound to the manifest that was live when it was created. A
/// compaction may commit a new manifest and delete every segment this reader
/// names, mid-scan, and the reader keeps returning exactly the records it was
/// created to see — the descriptors outlive the directory entries. That is the
/// property that lets compaction run without a quiescent point.
pub struct Reader<R: Record> {
    segments: Vec<SegFile>,
    generation: u64,
    peak_bytes: usize,
    _marker: PhantomData<fn() -> R>,
}

impl<R: Record> Reader<R> {
    /// The manifest generation this reader is pinned to.
    #[must_use]
    pub fn generation(&self) -> u64 {
        self.generation
    }

    /// How many segments this view spans.
    #[must_use]
    pub fn segment_count(&self) -> usize {
        self.segments.len()
    }

    /// High-water mark, in bytes, of the buffers a scan has held.
    ///
    /// Counted, not sampled — the same discipline `websearch`'s
    /// `Index::derived_rebuilds` uses, and for the same reason: "the read path
    /// does not materialise the corpus" is a fact about control flow, and a test
    /// that asserts it by watching RSS is a test that fails on a busy machine.
    /// The figure is `segments × (8 KiB + largest key + largest value)`, so it
    /// scales with segment *count* and record size, never with corpus size.
    #[must_use]
    pub fn peak_resident_bytes(&self) -> usize {
        self.peak_bytes
    }

    /// The store's logical contents: every live record, in ascending key order,
    /// with shadowed and tombstoned keys already resolved away.
    ///
    /// # Errors
    /// I/O or corruption in any segment.
    pub fn for_each<F>(&mut self, mut f: F) -> Result<()>
    where
        F: FnMut(R),
    {
        self.for_each_resolved(|_, item| {
            if let Some(r) = item {
                f(r);
            }
        })
    }

    /// The same k-way merge, but tombstoned keys are reported as `None` — the
    /// view compaction needs, and the view a test needs to prove a tombstone is
    /// really there rather than merely not contradicted.
    ///
    /// # Errors
    /// I/O or corruption in any segment.
    pub fn for_each_resolved<F>(&mut self, mut f: F) -> Result<()>
    where
        F: FnMut(&[u8], Option<R>),
    {
        self.merge(|path, key, kind, value| {
            let item = if kind == 0 {
                // The path is threaded through the merge so this names the file
                // an operator has to look at. "a record failed to decode" without
                // one is a bug report nobody can act on.
                Some(R::decode(value).ok_or_else(|| {
                    corrupt(
                        path,
                        format!("record under key {} failed to decode", to_hex(key)),
                    )
                })?)
            } else {
                None
            };
            f(key, item);
            Ok(())
        })
    }

    /// The k-way merge itself, handing out raw frames.
    ///
    /// Linear scan over the cursor heads rather than a heap: `k` is the segment
    /// count (single digits after any compaction), and an obviously-correct O(k)
    /// scan beats a `BinaryHeap` whose tie-break — *newest segment wins on an
    /// equal key* — is the one thing in this file that must not be subtly wrong.
    fn merge<F>(&mut self, mut emit: F) -> Result<()>
    where
        F: FnMut(&Path, &[u8], u8, &[u8]) -> Result<()>,
    {
        let mut cursors: Vec<SegCursor<'_>> = Vec::with_capacity(self.segments.len());
        for seg in &mut self.segments {
            cursors.push(SegCursor::new(seg)?);
        }
        let mut peak = 0usize;
        loop {
            // Smallest pending key; among equal keys the highest cursor index,
            // because the manifest lists segments oldest-first.
            let mut best: Option<usize> = None;
            for (i, c) in cursors.iter().enumerate() {
                if c.pending.is_none() {
                    continue;
                }
                match best {
                    None => best = Some(i),
                    Some(b) => {
                        if c.key <= cursors[b].key {
                            best = Some(i);
                        }
                    }
                }
            }
            let Some(winner) = best else { break };
            peak = peak.max(cursors.iter().map(SegCursor::resident_bytes).sum());
            // Emit before advancing the losers: `emit` borrows the winner's
            // buffers, and advancing would overwrite them.
            {
                let c = &cursors[winner];
                let kind = c.pending.unwrap_or(1);
                emit(c.path, &c.key, kind, &c.value)?;
            }
            let key = cursors[winner].key.clone();
            for c in &mut cursors {
                if c.pending.is_some() && c.key == key {
                    c.advance()?;
                }
            }
        }
        self.peak_bytes = self.peak_bytes.max(peak);
        Ok(())
    }
}

// ---------------------------------------------------------------------------
// Store
// ---------------------------------------------------------------------------

/// What a [`Store::commit`] did.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Committed {
    /// The new generation.
    pub generation: u64,
    /// The segment written, or `None` for an empty batch (which is a no-op, not
    /// an empty segment — a store should not accumulate files for doing nothing).
    pub segment: Option<SegmentInfo>,
}

/// What a compaction did.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct MergeOutcome {
    /// Segments folded away.
    pub replaced: Vec<u64>,
    /// The segment they became.
    pub produced: SegmentInfo,
    /// Records read from the inputs.
    pub records_in: u64,
    /// Records that survived — the difference is shadowed versions and
    /// tombstoned keys, i.e. the space compaction actually reclaimed.
    pub records_out: u64,
    /// The generation the new manifest committed at.
    pub generation: u64,
}

/// What [`Store::open_recovering`] had to do to get the store open.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Recovery {
    /// Manifests that would not load, newest first, with the reason.
    pub rejected_manifests: Vec<String>,
    /// Segments repaired by salvaging their intact prefix: `(id, records lost)`.
    pub repaired_segments: Vec<(u64, u64)>,
}

impl Recovery {
    /// Whether anything at all had to be repaired. `false` is the normal case and
    /// is what a clean shutdown produces.
    #[must_use]
    pub fn is_clean(&self) -> bool {
        self.rejected_manifests.is_empty() && self.repaired_segments.is_empty()
    }
}

/// An append-only store of `R`, on disk, in `dir`.
///
/// See the [module docs](self) for the design and its limits.
pub struct Store<R: Record> {
    dir: PathBuf,
    manifest: Manifest,
    next_segment_id: u64,
    _marker: PhantomData<fn() -> R>,
}

impl<R: Record> Store<R> {
    /// Open (or create) the store in `dir`, strictly.
    ///
    /// Reads the newest manifest that parses and whose segments are all present
    /// and intact. An empty or non-existent directory is a valid empty store at
    /// generation 0 — "nothing has been written yet" is not an error.
    ///
    /// Every segment named by the chosen manifest is fully verified (size, record
    /// count, CRC) before this returns, because open is exactly when you want to
    /// find out that the last crash left something half-written. That costs one
    /// streaming pass over the data and a fixed 64 KiB.
    ///
    /// # Errors
    /// [`Error::NoUsableManifest`] if manifests exist but none loads;
    /// [`Error::Corrupt`] if the newest loadable manifest names a damaged
    /// segment (use [`Store::open_recovering`] to salvage instead); I/O errors
    /// otherwise.
    pub fn open(dir: impl AsRef<Path>) -> Result<Store<R>> {
        Self::open_inner(dir.as_ref(), false).map(|(s, _)| s)
    }

    /// Open the store, repairing what can be repaired, and report what that took.
    ///
    /// Differs from [`Store::open`] in exactly one way: a segment that fails
    /// verification is not fatal. Its intact prefix is salvaged into a fresh
    /// segment, a new manifest naming that segment in its place is committed, and
    /// the loss is recorded in [`Recovery::repaired_segments`] — so the damage is
    /// something an operator is *told*, rather than something they infer from a
    /// document that stopped turning up in search.
    ///
    /// Does **not** sweep: see [`Store::sweep`], which a writer calls.
    ///
    /// # Errors
    /// As [`Store::open`], minus the recoverable segment case.
    pub fn open_recovering(dir: impl AsRef<Path>) -> Result<(Store<R>, Recovery)> {
        Self::open_inner(dir.as_ref(), true)
    }

    fn open_inner(dir: &Path, repair: bool) -> Result<(Store<R>, Recovery)> {
        std::fs::create_dir_all(dir).map_err(|e| io_err(dir, e))?;
        let mut rec = Recovery::default();

        let mut candidates = list_manifests(dir)?;
        candidates.sort_unstable_by(|a, b| b.cmp(a)); // newest first
        let mut chosen: Option<Manifest> = None;
        for gen in &candidates {
            let path = manifest_path(dir, *gen);
            let bytes = match std::fs::read(&path) {
                Ok(b) => b,
                Err(e) => {
                    rec.rejected_manifests
                        .push(format!("{}: {e}", path.display()));
                    continue;
                }
            };
            match Manifest::parse(&path, &bytes) {
                Ok(m) => {
                    // A manifest whose segments have gone (swept after a later
                    // compaction) is not a fallback, it is a dangling pointer.
                    let missing: Vec<String> = m
                        .segments
                        .iter()
                        .filter(|s| !segment_path(dir, s.id).exists())
                        .map(|s| format!("seg {}", s.id))
                        .collect();
                    if missing.is_empty() {
                        chosen = Some(m);
                        break;
                    }
                    rec.rejected_manifests.push(format!(
                        "{}: missing {}",
                        path.display(),
                        missing.join(", ")
                    ));
                }
                Err(e) => rec.rejected_manifests.push(format!("{e}")),
            }
        }

        let manifest = match chosen {
            Some(m) => m,
            None if candidates.is_empty() => Manifest::default(),
            None => {
                return Err(Error::NoUsableManifest {
                    dir: dir.to_path_buf(),
                    rejected: rec.rejected_manifests,
                })
            }
        };

        let next_segment_id = manifest
            .segments
            .iter()
            .map(|s| s.id + 1)
            .chain(list_segment_ids(dir)?.into_iter().map(|id| id + 1))
            .max()
            .unwrap_or(0);

        let mut store = Store {
            dir: dir.to_path_buf(),
            manifest,
            next_segment_id,
            _marker: PhantomData,
        };

        // Verify every live segment. Strict mode surfaces the first failure;
        // recovering mode salvages and re-commits.
        let mut damaged: Vec<usize> = Vec::new();
        for (i, info) in store.manifest.segments.iter().enumerate() {
            let path = segment_path(dir, info.id);
            if let Err(e) = verify_segment(&path, info) {
                if !repair {
                    return Err(e);
                }
                damaged.push(i);
            }
        }
        if !damaged.is_empty() {
            store.repair(&damaged, &mut rec)?;
        }
        // Deliberately NOT swept here. Opening is what `serve` and `stats` do, and
        // a read that deletes files is a read that will one day delete the wrong
        // one — and, less dramatically, one that prints "removed 2 orphaned files"
        // at an operator who did not interrupt anything (retired manifests look
        // exactly like debris to a sweep). Housekeeping belongs to the writer:
        // `commit`'s caller sweeps, and compaction sweeps what it retires. Debris
        // is invisible and harmless until then.
        Ok((store, rec))
    }

    /// Rewrite each damaged segment as its salvageable prefix and commit the
    /// resulting manifest.
    fn repair(&mut self, damaged: &[usize], rec: &mut Recovery) -> Result<()> {
        let mut next = self.manifest.clone();
        next.generation += 1;
        for &i in damaged {
            let old = self.manifest.segments[i].clone();
            let old_path = segment_path(&self.dir, old.id);
            let items = salvage_segment(&old_path)?;
            let kept = items.len() as u64;
            let new_id = self.alloc_segment_id();
            let new_path = segment_path(&self.dir, new_id);
            let (records, bytes) = write_segment::<RawRecord, _>(
                &new_path,
                items.into_iter().map(|(k, it)| {
                    (
                        k,
                        match it {
                            Item::Put(v) => Item::Put(RawRecord(v)),
                            Item::Delete => Item::Delete,
                        },
                    )
                }),
            )?;
            rec.repaired_segments
                .push((old.id, old.records.saturating_sub(kept)));
            next.segments[i] = SegmentInfo {
                id: new_id,
                records,
                bytes,
            };
        }
        self.publish(next)
    }

    /// The live manifest.
    #[must_use]
    pub fn manifest(&self) -> &Manifest {
        &self.manifest
    }

    /// The live generation. Every successful [`Store::commit`] or compaction
    /// bumps it, so it doubles as "how many batches are durable".
    #[must_use]
    pub fn generation(&self) -> u64 {
        self.manifest.generation
    }

    /// How many segments are live.
    #[must_use]
    pub fn segment_count(&self) -> usize {
        self.manifest.segments.len()
    }

    /// Total live records, tombstones and shadowed versions included — the
    /// physical count, not the logical one. The gap between this and what a scan
    /// yields is what compaction would reclaim.
    #[must_use]
    pub fn physical_records(&self) -> u64 {
        self.manifest.segments.iter().map(|s| s.records).sum()
    }

    /// The store directory.
    #[must_use]
    pub fn dir(&self) -> &Path {
        &self.dir
    }

    fn alloc_segment_id(&mut self) -> u64 {
        let id = self.next_segment_id;
        self.next_segment_id += 1;
        id
    }

    /// Make `next` the live manifest: write it atomically, then adopt it.
    ///
    /// The order matters and is the whole crash story. Segments are already
    /// fsynced by the time this runs, so a manifest is never durable ahead of
    /// what it names. Adopting only *after* the write succeeds means a failed
    /// commit leaves the in-memory store agreeing with the disk.
    fn publish(&mut self, next: Manifest) -> Result<()> {
        let path = manifest_path(&self.dir, next.generation);
        write_atomic(&path, &next.render()).map_err(|e| io_err(&path, e))?;
        self.manifest = next;
        Ok(())
    }

    /// Write `batch` as a new segment and commit a manifest naming it.
    ///
    /// This is the operation that makes problems 2 and 3 go away: its cost is a
    /// function of the batch, not of the store, so it is cheap enough to do every
    /// few hundred documents instead of once at the end of a run.
    ///
    /// An empty batch commits nothing and returns the current generation.
    ///
    /// # Errors
    /// I/O errors writing the segment or the manifest. On failure the store is
    /// unchanged and the previous manifest is still live.
    pub fn commit(&mut self, batch: Batch<R>) -> Result<Committed> {
        if batch.is_empty() {
            return Ok(Committed {
                generation: self.manifest.generation,
                segment: None,
            });
        }
        let id = self.alloc_segment_id();
        let path = segment_path(&self.dir, id);
        let (records, bytes) = write_segment::<R, _>(&path, batch.entries)?;
        let info = SegmentInfo { id, records, bytes };
        let mut next = self.manifest.clone();
        next.generation += 1;
        next.segments.push(info.clone());
        if let Err(e) = self.publish(next) {
            // The segment is on disk but no manifest names it: harmless debris,
            // and `sweep` will collect it. Better than a manifest that names a
            // file we could not finish.
            let _ = std::fs::remove_file(&path);
            return Err(e);
        }
        Ok(Committed {
            generation: self.manifest.generation,
            segment: Some(info),
        })
    }

    /// Drop every record: commit a manifest that names no segments.
    ///
    /// One small atomic write, whatever the store's size. It exists because
    /// "replace the contents wholesale" is a real operation — importing a blob
    /// snapshot, rebuilding after a format change — and doing it by writing a new
    /// full batch on top of the old one is *wrong*, not merely wasteful: any key
    /// the new contents happen not to mention keeps its old value, visible
    /// forever from an older segment. A caller that means "replace" has to be
    /// able to say so.
    ///
    /// The old segments stay on disk until [`Store::sweep`] takes them, and for a
    /// generation beyond that, so an in-flight reader is unaffected and the
    /// previous manifest remains a usable fallback.
    ///
    /// # Errors
    /// I/O errors writing the manifest.
    pub fn truncate(&mut self) -> Result<u64> {
        let mut next = self.manifest.clone();
        next.generation += 1;
        next.segments.clear();
        self.publish(next)?;
        Ok(self.manifest.generation)
    }

    /// A pinned, streaming view of the store as it is right now.
    ///
    /// Opens every live segment immediately — descriptors, not contents — so the
    /// view survives a compaction deleting those files underneath it.
    ///
    /// # Errors
    /// I/O errors opening a segment.
    pub fn reader(&self) -> Result<Reader<R>> {
        let mut segments = Vec::with_capacity(self.manifest.segments.len());
        for info in &self.manifest.segments {
            let path = segment_path(&self.dir, info.id);
            let file = File::open(&path).map_err(|e| io_err(&path, e))?;
            segments.push(SegFile {
                info: info.clone(),
                path,
                file,
            });
        }
        Ok(Reader {
            segments,
            generation: self.manifest.generation,
            peak_bytes: 0,
            _marker: PhantomData,
        })
    }

    /// Fold every live record through `f`, in ascending key order.
    ///
    /// Convenience over [`Store::reader`]; the memory bound is the same.
    ///
    /// # Errors
    /// I/O or corruption in any segment.
    pub fn for_each<F>(&self, f: F) -> Result<()>
    where
        F: FnMut(R),
    {
        self.reader()?.for_each(f)
    }

    /// Whether compaction is worth doing: more than `max_segments` live.
    ///
    /// A count-based trigger, not a size-based one, because the cost this store
    /// actually pays for being un-compacted is per-segment — one descriptor, one
    /// buffer and one cursor comparison per record, per segment.
    #[must_use]
    pub fn should_compact(&self, max_segments: usize) -> bool {
        self.manifest.segments.len() > max_segments.max(1)
    }

    /// Fold the oldest `count` segments into one, synchronously.
    ///
    /// Returns `None` if there is nothing worth folding (fewer than two segments,
    /// or `count < 2`). See the [module docs](self#honest-limits) for why the run
    /// is always a prefix and what that costs.
    ///
    /// # Errors
    /// I/O or corruption in the inputs; I/O writing the output or the manifest.
    pub fn compact(&mut self, count: usize) -> Result<Option<MergeOutcome>> {
        let Some(job) = self.prepare_merge(count)? else {
            return Ok(None);
        };
        let prepared = run_merge::<R>(job)?;
        self.adopt_merge(prepared).map(Some)
    }

    /// Everything the merge thread needs, computed on the owning thread.
    fn prepare_merge(&mut self, count: usize) -> Result<Option<MergeJobSpec>> {
        let n = count.min(self.manifest.segments.len());
        if n < 2 {
            return Ok(None);
        }
        let inputs: Vec<(SegmentInfo, PathBuf)> = self.manifest.segments[..n]
            .iter()
            .map(|s| (s.clone(), segment_path(&self.dir, s.id)))
            .collect();
        let out_id = self.alloc_segment_id();
        Ok(Some(MergeJobSpec {
            inputs,
            out_id,
            out_path: segment_path(&self.dir, out_id),
        }))
    }

    /// Install a finished merge: swap the folded run for its product and commit.
    ///
    /// The new segment goes back **at the run's position**, not at the end. Its id
    /// is higher than segments that are logically newer, which is exactly why
    /// [`Manifest::segments`] is ordered by age rather than by id.
    fn adopt_merge(&mut self, done: MergeProduct) -> Result<MergeOutcome> {
        let n = done.replaced.len();
        // The prefix must still be the prefix. It always is with one writer —
        // commits append, and compaction is the only thing that removes — but
        // asserting it beats corrupting shadow order if that ever changes.
        let head_matches = self.manifest.segments.len() >= n
            && self.manifest.segments[..n]
                .iter()
                .map(|s| s.id)
                .eq(done.replaced.iter().copied());
        if !head_matches {
            let _ = std::fs::remove_file(&done.out_path);
            return Err(corrupt(
                &self.dir,
                "the segments a merge folded are no longer the oldest run",
            ));
        }
        let mut next = self.manifest.clone();
        next.generation += 1;
        next.segments
            .splice(..n, std::iter::once(done.info.clone()));
        if let Err(e) = self.publish(next) {
            let _ = std::fs::remove_file(&done.out_path);
            return Err(e);
        }
        // Only now are the inputs unreferenced by the live manifest — and even
        // now `sweep` keeps them for one more generation so the previous manifest
        // stays a usable fallback.
        Ok(MergeOutcome {
            replaced: done.replaced,
            produced: done.info,
            records_in: done.records_in,
            records_out: done.records_out,
            generation: self.manifest.generation,
        })
    }

    /// Delete files no retained manifest refers to, returning how many went.
    ///
    /// Two kinds of debris: segments a crashed writer left before it could commit
    /// a manifest naming them, and segments a compaction retired. Neither is ever
    /// deleted while a *retained* manifest still names it, which is what keeps the
    /// fallback in [`Store::open`] pointing at something real. Readers are safe
    /// regardless — they hold descriptors, and an unlinked file stays readable.
    ///
    /// # Errors
    /// I/O errors listing the directory. Failing to remove an individual file is
    /// not an error: a sweep is best-effort housekeeping, and the store is
    /// correct with debris in it.
    pub fn sweep(&mut self) -> Result<usize> {
        let mut keep_segments: std::collections::BTreeSet<u64> =
            self.manifest.segments.iter().map(|s| s.id).collect();
        let mut gens = list_manifests(&self.dir)?;
        gens.sort_unstable_by(|a, b| b.cmp(a));
        for gen in gens.iter().take(MANIFEST_RETAIN).skip(1) {
            let path = manifest_path(&self.dir, *gen);
            if let Ok(bytes) = std::fs::read(&path) {
                if let Ok(m) = Manifest::parse(&path, &bytes) {
                    keep_segments.extend(m.segments.iter().map(|s| s.id));
                }
            }
        }
        let mut removed = 0usize;
        for id in list_segment_ids(&self.dir)? {
            if !keep_segments.contains(&id)
                && std::fs::remove_file(segment_path(&self.dir, id)).is_ok()
            {
                removed += 1;
            }
        }
        for gen in gens.into_iter().skip(MANIFEST_RETAIN) {
            if std::fs::remove_file(manifest_path(&self.dir, gen)).is_ok() {
                removed += 1;
            }
        }
        Ok(removed)
    }
}

/// A record whose "decoding" is the raw bytes — used by the salvage path, which
/// must rewrite frames it cannot interpret (it does not know `R`, and must not
/// need to: a segment damaged in one engine's store is repaired the same way as
/// any other).
struct RawRecord(Vec<u8>);

impl Record for RawRecord {
    fn key(&self) -> Vec<u8> {
        // Never called: the salvage path already has the keys off the disk and
        // feeds `write_segment` `(key, item)` pairs directly.
        Vec::new()
    }
    fn encode(&self, out: &mut Vec<u8>) {
        out.extend_from_slice(&self.0);
    }
    fn decode(bytes: &[u8]) -> Option<Self> {
        Some(RawRecord(bytes.to_vec()))
    }
}

// ---------------------------------------------------------------------------
// Merging
// ---------------------------------------------------------------------------

struct MergeJobSpec {
    inputs: Vec<(SegmentInfo, PathBuf)>,
    out_id: u64,
    out_path: PathBuf,
}

struct MergeProduct {
    replaced: Vec<u64>,
    info: SegmentInfo,
    out_path: PathBuf,
    records_in: u64,
    records_out: u64,
}

/// The expensive half of a compaction: read the run, fold it, write the product.
///
/// Touches no manifest and deletes nothing, so it is safe to run on any thread at
/// any time — the worst a discarded merge costs is one orphan segment, which
/// [`Store::sweep`] collects.
///
/// Decodes and re-encodes through `R` rather than copying frames, so the fold is
/// type-checked end to end: a record that cannot be decoded fails the merge
/// instead of being propagated into the product as bytes nobody can read.
fn run_merge<R: Record>(spec: MergeJobSpec) -> Result<MergeProduct> {
    let mut segments = Vec::with_capacity(spec.inputs.len());
    for (info, path) in &spec.inputs {
        let file = File::open(path).map_err(|e| io_err(path, e))?;
        segments.push(SegFile {
            info: info.clone(),
            path: path.clone(),
            file,
        });
    }
    let records_in: u64 = spec.inputs.iter().map(|(i, _)| i.records).sum();
    let mut reader: Reader<R> = Reader {
        segments,
        generation: 0,
        peak_bytes: 0,
        _marker: PhantomData,
    };

    // Collecting the fold before writing it is a deliberate, bounded exception to
    // the streaming rule: `write_segment` wants an iterator, and handing it one
    // that borrows the reader would need a self-referential struct. What is
    // collected is the *surviving* set — shadowed versions and tombstones are
    // already gone — and it is the same set that was resident when the batches
    // were written. Streaming this too would need a producer/consumer channel and
    // buy a constant factor; the honest note is here rather than in a claim.
    let mut kept: Vec<(Vec<u8>, Item<R>)> = Vec::new();
    reader.for_each_resolved(|key, item| {
        // A prefix run has nothing older behind it, so a tombstone here is
        // deleting something that no longer exists anywhere. Dropping it is the
        // space compaction reclaims — and is only sound *because* the run is a
        // prefix. See the module docs.
        if let Some(r) = item {
            kept.push((key.to_vec(), Item::Put(r)));
        }
    })?;
    let records_out = kept.len() as u64;
    let (records, bytes) = write_segment::<R, _>(&spec.out_path, kept)?;
    Ok(MergeProduct {
        replaced: spec.inputs.iter().map(|(i, _)| i.id).collect(),
        info: SegmentInfo {
            id: spec.out_id,
            records,
            bytes,
        },
        out_path: spec.out_path,
        records_in,
        records_out,
    })
}

/// A compaction running on another thread.
///
/// Created by [`Store::start_merge`] and consumed by [`Store::finish_merge`].
/// Dropping one without finishing it is safe: the thread finishes, the product
/// segment is never named by a manifest, and [`Store::sweep`] removes it.
pub struct MergeJob<R: Record> {
    handle: std::thread::JoinHandle<Result<MergeProduct>>,
    _marker: PhantomData<fn() -> R>,
}

impl<R: Record> MergeJob<R> {
    /// Whether the merge thread has finished, so a caller can poll instead of
    /// blocking in [`Store::finish_merge`].
    #[must_use]
    pub fn is_finished(&self) -> bool {
        self.handle.is_finished()
    }
}

impl<R: Record + 'static> Store<R> {
    /// Start a compaction of the oldest `count` segments on a background thread.
    ///
    /// The thread does the reading, folding and writing; the manifest swap waits
    /// for [`Store::finish_merge`] on this thread and costs one small file write.
    /// The writer can keep committing batches while it runs — new segments get
    /// higher ids and land after the run being folded, so the prefix the merge
    /// captured stays the prefix.
    ///
    /// Returns `None` when there is nothing worth folding.
    ///
    /// # Errors
    /// I/O errors preparing the job.
    pub fn start_merge(&mut self, count: usize) -> Result<Option<MergeJob<R>>> {
        let Some(spec) = self.prepare_merge(count)? else {
            return Ok(None);
        };
        let handle = std::thread::spawn(move || run_merge::<R>(spec));
        Ok(Some(MergeJob {
            handle,
            _marker: PhantomData,
        }))
    }

    /// Wait for a background merge and install it.
    ///
    /// # Errors
    /// Whatever the merge failed with; [`Error::MergePanicked`] if the thread
    /// panicked; I/O errors committing the manifest.
    pub fn finish_merge(&mut self, job: MergeJob<R>) -> Result<MergeOutcome> {
        let product = job.handle.join().map_err(|_| Error::MergePanicked)??;
        self.adopt_merge(product)
    }
}

// ---------------------------------------------------------------------------
// Directory layout helpers
// ---------------------------------------------------------------------------

fn manifest_path(dir: &Path, generation: u64) -> PathBuf {
    dir.join(format!("{MANIFEST_PREFIX}{generation:020}"))
}

fn segment_path(dir: &Path, id: u64) -> PathBuf {
    dir.join(format!("{SEGMENT_PREFIX}{id:020}{SEGMENT_SUFFIX}"))
}

fn list_dir_names(dir: &Path) -> Result<Vec<String>> {
    let rd = match std::fs::read_dir(dir) {
        Ok(rd) => rd,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(Vec::new()),
        Err(e) => return Err(io_err(dir, e)),
    };
    Ok(rd
        .filter_map(std::result::Result::ok)
        .map(|e| e.file_name().to_string_lossy().into_owned())
        .collect())
}

fn list_manifests(dir: &Path) -> Result<Vec<u64>> {
    Ok(list_dir_names(dir)?
        .iter()
        .filter_map(|n| n.strip_prefix(MANIFEST_PREFIX))
        .filter_map(|n| n.parse::<u64>().ok())
        .collect())
}

fn list_segment_ids(dir: &Path) -> Result<Vec<u64>> {
    Ok(list_dir_names(dir)?
        .iter()
        .filter_map(|n| n.strip_prefix(SEGMENT_PREFIX))
        .filter_map(|n| n.strip_suffix(SEGMENT_SUFFIX))
        .filter_map(|n| n.parse::<u64>().ok())
        .collect())
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

#[cfg(test)]
mod tests {
    use super::*;

    /// A minimal record: `key => value`, both strings.
    #[derive(Clone, Debug, PartialEq, Eq)]
    struct Kv {
        k: String,
        v: String,
    }

    impl Kv {
        fn new(k: &str, v: &str) -> Self {
            Kv {
                k: k.to_string(),
                v: v.to_string(),
            }
        }
    }

    impl Record for Kv {
        fn key(&self) -> Vec<u8> {
            self.k.as_bytes().to_vec()
        }
        fn encode(&self, out: &mut Vec<u8>) {
            out.extend_from_slice(&(self.k.len() as u32).to_le_bytes());
            out.extend_from_slice(self.k.as_bytes());
            out.extend_from_slice(self.v.as_bytes());
        }
        fn decode(bytes: &[u8]) -> Option<Self> {
            let n = u32::from_le_bytes(bytes.get(..4)?.try_into().ok()?) as usize;
            let k = std::str::from_utf8(bytes.get(4..4 + n)?).ok()?.to_string();
            let v = std::str::from_utf8(bytes.get(4 + n..)?).ok()?.to_string();
            Some(Kv { k, v })
        }
    }

    fn scratch(tag: &str) -> PathBuf {
        use std::sync::atomic::{AtomicU64, Ordering};
        static SEQ: AtomicU64 = AtomicU64::new(0);
        let n = SEQ.fetch_add(1, Ordering::Relaxed);
        let d = std::env::temp_dir().join(format!(
            "crawlcore-segstore-{tag}-{}-{n}",
            std::process::id()
        ));
        let _ = std::fs::remove_dir_all(&d);
        std::fs::create_dir_all(&d).expect("mkdir");
        d
    }

    fn commit_kv(store: &mut Store<Kv>, pairs: &[(&str, &str)]) {
        let mut b = Batch::new();
        for (k, v) in pairs {
            b.put(Kv::new(k, v));
        }
        store.commit(b).expect("commit");
    }

    fn live(store: &Store<Kv>) -> Vec<(String, String)> {
        let mut out = Vec::new();
        store.for_each(|r: Kv| out.push((r.k, r.v))).expect("scan");
        out
    }

    #[test]
    fn an_empty_directory_is_an_empty_store() {
        let dir = scratch("empty");
        let store: Store<Kv> = Store::open(&dir).expect("open");
        assert_eq!(store.generation(), 0);
        assert_eq!(store.segment_count(), 0);
        assert!(live(&store).is_empty());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_committed_batch_survives_reopen() {
        let dir = scratch("reopen");
        {
            let mut s: Store<Kv> = Store::open(&dir).expect("open");
            commit_kv(&mut s, &[("a", "1"), ("b", "2")]);
            commit_kv(&mut s, &[("c", "3")]);
            assert_eq!(s.generation(), 2);
        }
        let s: Store<Kv> = Store::open(&dir).expect("reopen");
        assert_eq!(s.generation(), 2);
        assert_eq!(
            live(&s),
            vec![
                ("a".into(), "1".into()),
                ("b".into(), "2".into()),
                ("c".into(), "3".into())
            ]
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_later_segment_shadows_an_earlier_one() {
        let dir = scratch("shadow");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("k", "old"), ("keep", "yes")]);
        commit_kv(&mut s, &[("k", "new")]);
        assert_eq!(
            live(&s),
            vec![("k".into(), "new".into()), ("keep".into(), "yes".into())]
        );
        // Physically both versions are still there — that is the cost compaction
        // exists to reclaim, and asserting it keeps the merge test honest.
        assert_eq!(s.physical_records(), 3);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_tombstone_deletes_and_a_later_put_resurrects() {
        let dir = scratch("tombstone");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("a", "1"), ("b", "2")]);
        let mut b = Batch::new();
        b.delete(b"a".to_vec());
        s.commit(b).expect("commit delete");
        assert_eq!(live(&s), vec![("b".into(), "2".into())]);

        // A tombstone is a record, not an erasure: writing the key again brings
        // it back, because the newer segment shadows the tombstone.
        commit_kv(&mut s, &[("a", "again")]);
        assert_eq!(
            live(&s),
            vec![("a".into(), "again".into()), ("b".into(), "2".into())]
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn an_empty_batch_writes_no_segment() {
        let dir = scratch("emptybatch");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        let c = s.commit(Batch::new()).expect("commit");
        assert_eq!(c.segment, None);
        assert_eq!(s.generation(), 0);
        assert_eq!(list_segment_ids(&dir).unwrap().len(), 0);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn merge_drops_shadowed_and_tombstoned_records() {
        let dir = scratch("merge");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("a", "1"), ("b", "1"), ("c", "1")]);
        commit_kv(&mut s, &[("a", "2"), ("d", "1")]);
        let mut del = Batch::new();
        del.delete(b"b".to_vec());
        s.commit(del).expect("commit");
        commit_kv(&mut s, &[("a", "3")]);

        let before = live(&s);
        assert_eq!(s.physical_records(), 7);

        let out = s
            .compact(4)
            .expect("compact")
            .expect("something to compact");
        assert_eq!(out.records_in, 7);
        // a(3 versions -> 1), b(put + tombstone -> 0), c(1), d(1) = 3 survivors.
        assert_eq!(out.records_out, 3);
        assert_eq!(s.segment_count(), 1);
        assert_eq!(s.physical_records(), 3);
        assert_eq!(live(&s), before, "the fold must not change what is visible");
        assert_eq!(
            before,
            vec![
                ("a".into(), "3".into()),
                ("c".into(), "1".into()),
                ("d".into(), "1".into())
            ]
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn merging_a_prefix_leaves_newer_segments_shadowing_the_product() {
        // The ordering trap: the merged segment gets a HIGHER id than the newer
        // segments it sits before. If anything sorted by id, `x` would revert.
        let dir = scratch("prefix");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("x", "gen1")]);
        commit_kv(&mut s, &[("x", "gen2")]);
        commit_kv(&mut s, &[("x", "gen3")]);
        let out = s.compact(2).expect("compact").expect("merged");
        assert!(
            out.produced.id > s.manifest().segments[1].id,
            "the product's id must be higher than the segment that outranks it"
        );
        assert_eq!(s.segment_count(), 2);
        assert_eq!(live(&s), vec![("x".into(), "gen3".into())]);
        // And it still works after a reopen, i.e. the ORDER is what got persisted.
        let s2: Store<Kv> = Store::open(&dir).expect("reopen");
        assert_eq!(live(&s2), vec![("x".into(), "gen3".into())]);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_reader_survives_a_merge_deleting_its_segments() {
        let dir = scratch("readerpin");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        for i in 0..6 {
            commit_kv(&mut s, &[(&format!("k{i}"), &format!("v{i}"))]);
        }
        // Pin the pre-merge view: descriptors are open from here on.
        let mut pinned = s.reader().expect("reader");
        let pinned_gen = pinned.generation();

        s.compact(6).expect("compact").expect("merged");
        let swept = s.sweep().expect("sweep");
        // Sweep keeps one generation of grace, so the merged-away inputs go only
        // on the SECOND sweep after two more commits push them out of retention.
        commit_kv(&mut s, &[("later", "1")]);
        commit_kv(&mut s, &[("later2", "1")]);
        s.sweep().expect("sweep again");
        let remaining = list_segment_ids(&dir).expect("list");
        assert!(
            !remaining.contains(&0),
            "the oldest input segment should be gone from disk by now (swept {swept}, left {remaining:?})"
        );

        // The pinned reader still sees exactly its own generation's contents.
        let mut got = Vec::new();
        pinned.for_each(|r: Kv| got.push(r.k)).expect("pinned scan");
        assert_eq!(pinned.generation(), pinned_gen);
        assert_eq!(
            got,
            vec!["k0", "k1", "k2", "k3", "k4", "k5"],
            "a reader must not be pulled out from under by a merge"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_background_merge_produces_the_same_fold() {
        let dir = scratch("bgmerge");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("a", "1"), ("b", "1")]);
        commit_kv(&mut s, &[("a", "2")]);
        commit_kv(&mut s, &[("c", "1")]);
        let before = live(&s);

        let job = s.start_merge(3).expect("start").expect("worth merging");
        // The writer keeps working while the fold runs off-thread.
        commit_kv(&mut s, &[("d", "1")]);
        let out = s.finish_merge(job).expect("finish");
        assert_eq!(out.records_out, 3);
        assert_eq!(s.segment_count(), 2, "product + the batch committed during");

        let mut want = before;
        want.push(("d".into(), "1".into()));
        want.sort();
        assert_eq!(live(&s), want);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_corrupt_newest_manifest_falls_back_to_the_previous_one() {
        let dir = scratch("badmanifest");
        {
            let mut s: Store<Kv> = Store::open(&dir).expect("open");
            commit_kv(&mut s, &[("a", "1")]);
            commit_kv(&mut s, &[("b", "2")]);
        }
        // Corrupt the newest manifest's body. This is the nasty shape: the file
        // still parses as text and still names real segments, and only the
        // checksum knows it is a lie.
        let newest = manifest_path(&dir, 2);
        let text = String::from_utf8(std::fs::read(&newest).expect("read")).expect("utf8");
        std::fs::write(&newest, text.replace("generation 2", "generation 7")).expect("write");

        let (s, rec) = Store::<Kv>::open_recovering(&dir).expect("recovering open");
        assert_eq!(s.generation(), 1, "must fall back one generation");
        assert_eq!(live(&s), vec![("a".into(), "1".into())]);
        assert!(
            rec.rejected_manifests
                .iter()
                .any(|m| m.contains("checksum")),
            "the rejection must say why: {:?}",
            rec.rejected_manifests
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_truncated_segment_is_refused_strictly_and_salvaged_on_request() {
        let dir = scratch("torn");
        {
            let mut s: Store<Kv> = Store::open(&dir).expect("open");
            let mut b = Batch::new();
            for i in 0..20 {
                b.put(Kv::new(&format!("k{i:02}"), &format!("value-number-{i}")));
            }
            s.commit(b).expect("commit");
        }
        let seg = segment_path(&dir, 0);
        let full = std::fs::read(&seg).expect("read");
        // Lop off the last third: the footer and several whole records go with it.
        std::fs::write(&seg, &full[..full.len() * 2 / 3]).expect("truncate");

        let strict = Store::<Kv>::open(&dir);
        assert!(
            matches!(strict, Err(Error::Corrupt { .. })),
            "a strict open must refuse a torn segment, not read a prefix by accident"
        );

        let (s, rec) = Store::<Kv>::open_recovering(&dir).expect("salvage");
        assert_eq!(rec.repaired_segments.len(), 1);
        let (_, lost) = rec.repaired_segments[0];
        assert!(lost > 0, "the truncation must be reported as loss");
        let got = live(&s);
        assert_eq!(
            got.len() as u64,
            20 - lost,
            "exactly the intact prefix must survive"
        );
        // And what survived is a genuine prefix, in order, undamaged.
        for (i, (k, v)) in got.iter().enumerate() {
            assert_eq!(k, &format!("k{i:02}"));
            assert_eq!(v, &format!("value-number-{i}"));
        }
        // The repair is durable: a plain strict open now works.
        let again: Store<Kv> = Store::open(&dir).expect("strict open after repair");
        assert_eq!(live(&again), got);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_segment_no_manifest_names_is_ignored_and_swept() {
        let dir = scratch("orphan");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("a", "1")]);
        // Simulate a writer killed after the segment write and before the
        // manifest commit: a well-formed segment nothing points at.
        let orphan = segment_path(&dir, 999);
        write_segment::<Kv, _>(
            &orphan,
            vec![(b"zzz".to_vec(), Item::Put(Kv::new("zzz", "ghost")))],
        )
        .expect("write orphan");

        let s2: Store<Kv> = Store::open(&dir).expect("reopen");
        assert_eq!(
            live(&s2),
            vec![("a".into(), "1".into())],
            "an uncommitted segment must be invisible"
        );
        assert!(orphan.exists());
        let mut s3: Store<Kv> = Store::open(&dir).expect("reopen");
        assert!(s3.sweep().expect("sweep") >= 1);
        assert!(!orphan.exists(), "sweep must remove it");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_new_segment_id_never_collides_with_orphan_debris() {
        // If ids were allocated from the manifest alone, the orphan above would be
        // overwritten by the next commit — and a reader mid-scan would see a file
        // change under it, which is the one thing immutability promises cannot.
        let dir = scratch("idalloc");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("a", "1")]);
        write_segment::<Kv, _>(
            &segment_path(&dir, 50),
            vec![(b"x".to_vec(), Item::Put(Kv::new("x", "ghost")))],
        )
        .expect("orphan");

        let mut s2: Store<Kv> = Store::open(&dir).expect("reopen");
        commit_kv(&mut s2, &[("b", "2")]);
        assert!(s2.manifest().segments.last().expect("segment").id > 50);
        let _ = std::fs::remove_dir_all(&dir);
        drop(s);
    }

    #[test]
    fn the_read_path_holds_buffers_not_the_corpus() {
        // The memory claim, as a counted fact. 12 segments × 60 records × ~2 KiB
        // is ~1.4 MB on disk; a scan must hold segment-many buffers, not that.
        let dir = scratch("bounded");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        let payload = "x".repeat(2000);
        let mut total = 0usize;
        for seg in 0..12 {
            let mut b = Batch::new();
            for i in 0..60 {
                let k = format!("s{seg:02}-k{i:03}");
                total += k.len() + payload.len();
                b.put(Kv::new(&k, &payload));
            }
            s.commit(b).expect("commit");
        }
        assert!(total > 1_000_000, "corpus should be ~1.4 MB, was {total}");

        let mut r = s.reader().expect("reader");
        let mut seen = 0usize;
        r.for_each(|_: Kv| seen += 1).expect("scan");
        assert_eq!(seen, 12 * 60);

        let peak = r.peak_resident_bytes();
        // 12 segments × (8 KiB buffer + one ~2 KiB record) ≈ 125 KiB.
        assert!(
            peak < 200_000,
            "scan held {peak} bytes; the read path is materialising the corpus"
        );
        assert!(
            peak < total / 5,
            "peak {peak} must be a fraction of the {total}-byte corpus"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_record_that_cannot_decode_fails_the_scan_rather_than_panicking() {
        let dir = scratch("baddecode");
        {
            // Write a segment whose value bytes are nonsense for `Kv::decode`
            // (the length prefix claims more than the frame holds).
            let path = segment_path(&dir, 0);
            let mut raw = Vec::new();
            raw.extend_from_slice(&999_u32.to_le_bytes());
            let (records, bytes) = write_segment::<RawRecord, _>(
                &path,
                vec![(b"k".to_vec(), Item::Put(RawRecord(raw)))],
            )
            .expect("write");
            let m = Manifest {
                generation: 1,
                segments: vec![SegmentInfo {
                    id: 0,
                    records,
                    bytes,
                }],
            };
            write_atomic(manifest_path(&dir, 1), &m.render()).expect("manifest");
        }
        let s: Store<Kv> = Store::open(&dir).expect("open");
        let err = s.for_each(|_: Kv| ()).expect_err("must fail");
        assert!(matches!(err, Error::Corrupt { .. }), "got {err:?}");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn manifest_round_trips_and_rejects_tampering() {
        let m = Manifest {
            generation: 42,
            segments: vec![
                SegmentInfo {
                    id: 7,
                    records: 3,
                    bytes: 100,
                },
                SegmentInfo {
                    id: 9,
                    records: 1,
                    bytes: 50,
                },
            ],
        };
        let bytes = m.render();
        let path = Path::new("manifest-x");
        assert_eq!(Manifest::parse(path, &bytes).expect("parse"), m);
        // The manifest is text on purpose; check it reads like it.
        let text = String::from_utf8(bytes.clone()).expect("utf8");
        assert!(text.starts_with("ASTRX-SEGSTORE 1\ngeneration 42\n"));
        assert!(text.contains("segment 7 3 100\n"));

        let mut tampered = bytes;
        tampered[30] ^= 0x01;
        assert!(Manifest::parse(path, &tampered).is_err());
    }

    #[test]
    fn compaction_is_a_no_op_when_there_is_nothing_to_fold() {
        let dir = scratch("nofold");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        assert!(s.compact(4).expect("compact").is_none());
        commit_kv(&mut s, &[("a", "1")]);
        assert!(s.compact(4).expect("compact").is_none());
        assert!(!s.should_compact(4));
        commit_kv(&mut s, &[("b", "1")]);
        assert!(s.compact(4).expect("compact").is_some());
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn truncate_drops_everything_including_what_a_rewrite_would_miss() {
        let dir = scratch("truncate");
        let mut s: Store<Kv> = Store::open(&dir).expect("open");
        commit_kv(&mut s, &[("stale", "old"), ("shared", "old")]);

        // The wrong way, shown so the test explains itself: writing the new
        // contents on top leaves `stale` visible, because nothing said it is gone.
        commit_kv(&mut s, &[("shared", "new")]);
        assert_eq!(
            live(&s),
            vec![
                ("shared".into(), "new".into()),
                ("stale".into(), "old".into())
            ]
        );

        // The right way.
        s.truncate().expect("truncate");
        assert_eq!(s.segment_count(), 0);
        assert!(live(&s).is_empty());
        commit_kv(&mut s, &[("shared", "new")]);
        assert_eq!(live(&s), vec![("shared".into(), "new".into())]);

        // And it is durable, not just an in-memory forget.
        let s2: Store<Kv> = Store::open(&dir).expect("reopen");
        assert_eq!(live(&s2), vec![("shared".into(), "new".into())]);
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_segment_whose_keys_do_not_ascend_is_refused() {
        // The merge resolves shadowing by walking every segment in key order at
        // once. A segment with keys out of order does not corrupt anything the
        // checksum would catch — it makes the merge pick the WRONG version of a
        // key and serve a superseded record. That is the quietest failure this
        // design has, so the reader enforces the precondition rather than
        // trusting the writer to have held it.
        let dir = scratch("unsorted");
        let path = segment_path(&dir, 0);
        // Written by hand, in the wrong order, with a correct footer and CRC —
        // exactly what a buggy writer would produce.
        let (records, bytes) = write_segment::<Kv, _>(
            &path,
            vec![
                (b"zzz".to_vec(), Item::Put(Kv::new("zzz", "1"))),
                (b"aaa".to_vec(), Item::Put(Kv::new("aaa", "2"))),
            ],
        )
        .expect("write");
        let m = Manifest {
            generation: 1,
            segments: vec![SegmentInfo {
                id: 0,
                records,
                bytes,
            }],
        };
        write_atomic(manifest_path(&dir, 1), &m.render()).expect("manifest");

        // The checksum is fine, so `open` (which verifies) is happy...
        let s: Store<Kv> = Store::open(&dir).expect("open: the file itself is intact");
        // ...and the READ is what refuses it.
        let err = s.for_each(|_: Kv| ()).expect_err("must refuse");
        match err {
            Error::Corrupt { detail, .. } => {
                assert!(detail.contains("out of order"), "{detail}");
            }
            other => panic!("expected a corruption error, got {other:?}"),
        }
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn crc32_matches_the_known_check_value() {
        // The IEEE check value: CRC-32("123456789") == 0xCBF43926.
        assert_eq!(crc32_update(0, b"123456789"), 0xCBF4_3926);
        // And it is incremental, which is what streaming a segment relies on.
        let split = crc32_update(crc32_update(0, b"12345"), b"6789");
        assert_eq!(split, 0xCBF4_3926);
    }
}
