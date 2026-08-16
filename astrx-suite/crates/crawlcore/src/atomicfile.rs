//! Publish a file by rename, so a reader never sees a half-written one.
//!
//! # Why this exists
//!
//! All four engines persist their index the same way, and all four did it with
//! `std::fs::write`, which **truncates the destination and then writes**. A
//! crash, a `SIGKILL`, a full disk or a power cut in that window leaves a
//! truncated file — and because every engine correctly refuses to load a corrupt
//! snapshot, the result is that the *previous good index is already gone*.
//! Measured on an 89 MB snapshot truncated at 5 %, 50 % and 99.9 %: `restore`
//! returned `None` in all three, and both `index` and `search` then refused to
//! start.
//!
//! This is the worst class of bug in the tree, because unlike everything the
//! audit found it needs **no attacker at all** — just bad luck during a periodic
//! save. It is also the easiest to get subtly wrong by hand (forget the fsync
//! and the rename can beat the data to disk, publishing a file of zeroes), which
//! is why it lives here once rather than four times.
//!
//! The sequence is the standard durable-publish dance:
//!
//! 1. write to a uniquely-named temp file **in the same directory** (a rename
//!    across filesystems is not atomic, and `/tmp` is often a different one);
//! 2. `sync_all` the data — *before* the rename, so the rename cannot publish
//!    bytes that never reached the platter;
//! 3. `rename` over the destination, which is atomic on POSIX;
//! 4. best-effort fsync of the directory, so the rename itself survives a power
//!    cut.
//!
//! On any failure the temp file is removed, so a failing writer never litters
//! the data directory.

use std::io::Write;
use std::path::{Path, PathBuf};

/// Write `bytes` to `path` atomically: readers see either the old file or the
/// new one, never a partial one.
///
/// # Errors
/// Any I/O error from creating, writing, syncing or renaming the temp file. The
/// temp file is removed before returning, and `path` is left untouched — so a
/// failed write costs you the new data, never the old.
pub fn write_atomic(path: impl AsRef<Path>, bytes: &[u8]) -> std::io::Result<()> {
    let dst = path.as_ref();
    let dir = match dst.parent() {
        Some(p) if !p.as_os_str().is_empty() => p,
        // A bare filename: the temp file belongs beside it, in the cwd.
        _ => Path::new("."),
    };
    let tmp = temp_sibling(dst, dir);

    let written = std::fs::File::create(&tmp).and_then(|mut f| {
        f.write_all(bytes)?;
        // Durable BEFORE the rename publishes it. Without this the metadata
        // operation can reach disk first and a power cut publishes a file of
        // zeroes — which looks exactly like a successful save until you reload.
        f.sync_all()
    });
    if let Err(e) = written.and_then(|()| std::fs::rename(&tmp, dst)) {
        let _ = std::fs::remove_file(&tmp); // never leave debris behind
        return Err(e);
    }
    // Best effort: flush the directory entry, so the rename survives a crash.
    // Not all platforms allow opening a directory for this; failure is harmless.
    if let Ok(d) = std::fs::File::open(dir) {
        let _ = d.sync_all();
    }
    Ok(())
}

/// A temp path beside `dst`, unique per process **and per call**, so a second
/// writer — or a retry after a failure — can never share and truncate an
/// in-flight temp file.
fn temp_sibling(dst: &Path, dir: &Path) -> PathBuf {
    use std::sync::atomic::{AtomicU64, Ordering};
    static SEQ: AtomicU64 = AtomicU64::new(0);

    let stem = dst
        .file_name()
        .unwrap_or_else(|| std::ffi::OsStr::new("snapshot"));
    let nonce = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map_or(0, |d| d.as_nanos());
    let seq = SEQ.fetch_add(1, Ordering::Relaxed);
    let mut name = stem.to_os_string();
    name.push(format!(".tmp-{}-{nonce}-{seq}", std::process::id()));
    dir.join(name)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn scratch(tag: &str) -> PathBuf {
        use std::sync::atomic::{AtomicU64, Ordering};
        static SEQ: AtomicU64 = AtomicU64::new(0);
        let n = SEQ.fetch_add(1, Ordering::Relaxed);
        let d =
            std::env::temp_dir().join(format!("crawlcore-atomic-{tag}-{}-{n}", std::process::id()));
        std::fs::create_dir_all(&d).expect("mkdir");
        d
    }

    #[test]
    fn a_reader_holding_the_old_file_still_sees_all_of_it() {
        // The property that makes this atomic: the rename gives the destination
        // a NEW inode, so a reader that opened the old one keeps reading the
        // whole old file. `fs::write` truncates in place, and that reader would
        // see the file shrink under it.
        let dir = scratch("reader");
        let path = dir.join("index.bin");
        let old = vec![b'O'; 64 * 1024];
        write_atomic(&path, &old).expect("first write");

        let handle = std::fs::File::open(&path).expect("open old");
        write_atomic(&path, b"NEW").expect("second write");

        let mut seen = Vec::new();
        {
            use std::io::Read;
            let mut h = handle;
            h.read_to_end(&mut seen).expect("read");
        }
        assert_eq!(
            seen, old,
            "the open handle must still see the whole old file"
        );
        assert_eq!(std::fs::read(&path).unwrap(), b"NEW");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn no_temp_files_are_left_behind() {
        let dir = scratch("debris");
        let path = dir.join("index.bin");
        for i in 0..8 {
            write_atomic(&path, format!("gen{i}").as_bytes()).expect("write");
        }
        let leftovers: Vec<_> = std::fs::read_dir(&dir)
            .unwrap()
            .filter_map(Result::ok)
            .map(|e| e.file_name().to_string_lossy().into_owned())
            .filter(|n| n.contains(".tmp-"))
            .collect();
        assert!(leftovers.is_empty(), "temp files left: {leftovers:?}");
        assert_eq!(std::fs::read(&path).unwrap(), b"gen7");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_failed_write_leaves_the_previous_file_intact() {
        let dir = scratch("failure");
        let path = dir.join("index.bin");
        write_atomic(&path, b"GOOD").expect("first write");
        // A destination whose parent does not exist: the temp create fails.
        let bad = dir.join("missing").join("index.bin");
        assert!(write_atomic(&bad, b"NEW").is_err());
        assert_eq!(
            std::fs::read(&path).unwrap(),
            b"GOOD",
            "an unrelated failure must not disturb an existing file"
        );
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_bare_filename_writes_beside_itself() {
        // `dst.parent()` is `Some("")` for a bare name; the temp must land in
        // the cwd rather than at the filesystem root.
        let dir = scratch("bare");
        let path = dir.join("plain.bin");
        write_atomic(&path, b"x").expect("write");
        assert_eq!(std::fs::read(&path).unwrap(), b"x");
        let _ = std::fs::remove_dir_all(&dir);
    }

    #[test]
    fn concurrent_writers_do_not_share_a_temp_file() {
        let dir = scratch("concurrent");
        let path = std::sync::Arc::new(dir.join("index.bin"));
        let hs: Vec<_> = (0..8)
            .map(|i| {
                let p = std::sync::Arc::clone(&path);
                std::thread::spawn(move || {
                    let body = vec![b'a' + i as u8; 4096];
                    write_atomic(&*p, &body).expect("write");
                })
            })
            .collect();
        for h in hs {
            h.join().expect("thread");
        }
        // Whichever writer won, the file is one writer's bytes in full — never
        // an interleaving of two.
        let got = std::fs::read(&*path).unwrap();
        assert_eq!(got.len(), 4096);
        assert!(got.iter().all(|&b| b == got[0]), "temp files were shared");
        let _ = std::fs::remove_dir_all(&dir);
    }
}
