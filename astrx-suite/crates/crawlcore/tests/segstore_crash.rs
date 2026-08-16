//! Kill a segment-store writer with `SIGKILL` mid-batch and see what survived.
//!
//! # Why this is a child process
//!
//! The claim the store makes is about an *unclean* death: not a returned error,
//! not an unwind, not a `Drop` that gets a chance to flush — the process stops
//! existing between two instructions. Nothing inside a single test process can
//! simulate that, because everything inside a single test process still runs
//! destructors. So the writer is a real child, and `Child::kill` is `SIGKILL` on
//! Unix, which cannot be caught, blocked or ignored. Whatever is on disk
//! afterwards is what the fsync ordering actually bought.
//!
//! # What it proves
//!
//! 1. Every batch the writer said it committed is present **in full**. Not "most
//!    of it" — a batch is a segment, a segment enters the manifest whole or not
//!    at all.
//! 2. The batch in flight is absent **in full**, and its half-written segment
//!    file does not confuse recovery. That file exists on disk (the child died
//!    between `write_atomic` finishing the segment and the manifest naming it, or
//!    part-way through the segment write); it is not in any manifest, so it is
//!    invisible, and `sweep` collects it.
//! 3. The store reopens strictly — no salvage, no repair, no reported damage.
//!
//! Together those are the answer to "a crash between saves loses everything since
//! the last one": the blast radius is one batch, and the operator chooses how big
//! a batch is.
//!
//! The child is this same test binary re-executed with `--ignored` and an exact
//! filter, so no extra binary target is needed and the child links the very code
//! under test.

use std::io::{BufRead, BufReader};
use std::path::{Path, PathBuf};
use std::process::{Command, Stdio};

use crawlcore::segstore::{Batch, Record, Store};

/// Records per batch. Big enough that the segment write takes long enough to be
/// interrupted part-way, which is the interesting place to die.
const BATCH: usize = 500;
/// Batches the parent waits for before pulling the trigger.
const KILL_AFTER: u64 = 4;
/// The child's own upper bound, so an orphaned child cannot spin forever if the
/// parent dies first.
const CHILD_MAX_BATCHES: u64 = 20_000;

const DIR_ENV: &str = "CRAWLCORE_SEGSTORE_CRASH_DIR";
const CHILD_TEST: &str = "crash_writer_child";

/// `batch <b>, slot <i>` with a payload, so a partially-present batch is
/// detectable rather than merely suspected.
#[derive(Clone, Debug, PartialEq, Eq)]
struct Row {
    batch: u64,
    slot: u32,
    payload: String,
}

impl Record for Row {
    fn key(&self) -> Vec<u8> {
        format!("b{:08}-s{:06}", self.batch, self.slot).into_bytes()
    }
    fn encode(&self, out: &mut Vec<u8>) {
        out.extend_from_slice(&self.batch.to_le_bytes());
        out.extend_from_slice(&self.slot.to_le_bytes());
        out.extend_from_slice(self.payload.as_bytes());
    }
    fn decode(bytes: &[u8]) -> Option<Self> {
        let batch = u64::from_le_bytes(bytes.get(..8)?.try_into().ok()?);
        let slot = u32::from_le_bytes(bytes.get(8..12)?.try_into().ok()?);
        let payload = std::str::from_utf8(bytes.get(12..)?).ok()?.to_string();
        Some(Row {
            batch,
            slot,
            payload,
        })
    }
}

/// The writer. Runs as a child process; commits batches until it is killed.
///
/// Marked `#[ignore]` so a normal `cargo test` never runs it — it is a program
/// that only terminates by signal, and the parent below is what invokes it.
#[test]
#[ignore = "spawned as a child process by writer_killed_mid_batch_loses_only_that_batch"]
fn crash_writer_child() {
    let Ok(dir) = std::env::var(DIR_ENV) else {
        return; // invoked without a directory: nothing to do
    };
    let mut store: Store<Row> = Store::open(&dir).expect("child: open store");
    let payload = "p".repeat(200);
    for b in 0..CHILD_MAX_BATCHES {
        let mut batch = Batch::new();
        for slot in 0..BATCH as u32 {
            batch.put(Row {
                batch: b,
                slot,
                payload: payload.clone(),
            });
        }
        let c = store.commit(batch).expect("child: commit");
        // The parent's cue. Flushed explicitly: a buffered "committed" that the
        // kill discards would make the parent wait for a batch that is already
        // durable, and the test would hang instead of failing.
        println!("committed {}", c.generation);
        use std::io::Write;
        let _ = std::io::stdout().flush();
    }
}

fn scratch(tag: &str) -> PathBuf {
    let d = std::env::temp_dir().join(format!(
        "crawlcore-segstore-crash-{tag}-{}",
        std::process::id()
    ));
    let _ = std::fs::remove_dir_all(&d);
    std::fs::create_dir_all(&d).expect("mkdir");
    d
}

fn live_rows(dir: &Path) -> Vec<Row> {
    let store: Store<Row> = Store::open(dir).expect("reopen strictly");
    let mut out = Vec::new();
    store.for_each(|r: Row| out.push(r)).expect("scan");
    out
}

#[test]
fn writer_killed_mid_batch_loses_only_that_batch() {
    let dir = scratch("kill");
    let exe = std::env::current_exe().expect("current exe");

    let mut child = Command::new(exe)
        .args([CHILD_TEST, "--exact", "--ignored", "--nocapture"])
        .env(DIR_ENV, &dir)
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn()
        .expect("spawn writer");

    let stdout = child.stdout.take().expect("child stdout");
    let mut reported = 0u64;
    for line in BufReader::new(stdout).lines() {
        let line = line.expect("read child stdout");
        if line.starts_with("committed ") {
            reported += 1;
            if reported >= KILL_AFTER {
                break;
            }
        }
    }
    assert!(
        reported >= KILL_AFTER,
        "the child exited before committing {KILL_AFTER} batches (saw {reported})"
    );

    // SIGKILL. No unwind, no destructors, no flush — exactly the failure the
    // store's fsync ordering is designed against.
    child.kill().expect("SIGKILL the writer");
    let status = child.wait().expect("reap");
    assert!(!status.success(), "the child must have died by signal");

    // --- what survived -----------------------------------------------------
    let rows = live_rows(&dir);
    assert!(!rows.is_empty(), "nothing survived at all");

    let mut per_batch: std::collections::BTreeMap<u64, Vec<u32>> =
        std::collections::BTreeMap::new();
    for r in &rows {
        assert_eq!(
            r.payload.len(),
            200,
            "batch {} slot {} came back mangled",
            r.batch,
            r.slot
        );
        per_batch.entry(r.batch).or_default().push(r.slot);
    }

    // 1. Every surviving batch is whole. A torn batch would show up here as a
    //    short slot list, which is precisely the failure mode "the file is never
    //    corrupt" does NOT rule out for a blob store.
    for (b, slots) in &per_batch {
        assert_eq!(
            slots.len(),
            BATCH,
            "batch {b} came back with {} of {BATCH} records — a batch must be all or nothing",
            slots.len()
        );
    }

    // 2. Batches are a contiguous prefix: 0..n, no holes.
    let batches: Vec<u64> = per_batch.keys().copied().collect();
    let expected: Vec<u64> = (0..batches.len() as u64).collect();
    assert_eq!(batches, expected, "surviving batches must be a prefix");

    // 3. At least what the child announced before we killed it.
    assert!(
        batches.len() as u64 >= KILL_AFTER,
        "the child announced {KILL_AFTER} commits but only {} survived — \
         a commit that was reported must be durable",
        batches.len()
    );

    // 4. The store reopened strictly (`live_rows` used `Store::open`, which
    //    refuses damage), and a recovering open finds nothing to repair: the
    //    half-written segment the child left is debris, not corruption.
    let (mut store, recovery) = Store::<Row>::open_recovering(&dir).expect("recovering open");
    assert!(
        recovery.repaired_segments.is_empty(),
        "a killed writer must not leave a segment the store has to repair: {recovery:?}"
    );
    assert!(
        recovery.is_clean(),
        "recovery should be clean after an unclean kill: {recovery:?}"
    );
    assert_eq!(store.generation(), batches.len() as u64);

    // 5. Sweeping is idempotent and does not disturb the data.
    store.sweep().expect("sweep");
    assert_eq!(live_rows(&dir).len(), rows.len());

    // 6. And the store is *writable* again — resume, not restart. This is the
    //    fourth problem in the module docs: continuing costs one batch, not a
    //    read-and-rewrite of the world.
    let mut more = Batch::new();
    more.put(Row {
        batch: 9_999,
        slot: 0,
        payload: "p".repeat(200),
    });
    let gen_before = store.generation();
    let c = store.commit(more).expect("resume");
    assert_eq!(c.generation, gen_before + 1);
    assert_eq!(live_rows(&dir).len(), rows.len() + 1);

    let _ = std::fs::remove_dir_all(&dir);
}
