//! Coordination for atomically published snapshot stores.
//!
//! Every writer must hold a lease from before loading until after publication.
//! Use one canonical path on a local filesystem. Never delete a live lock file.
use std::fs::{File, OpenOptions};
use std::io;
use std::path::Path;
use std::sync::{Arc, Mutex};

/// An OS-owned exclusive lease, released by drop or process exit.
#[derive(Debug)]
pub struct WriterLease {
    _file: File,
}

impl WriterLease {
    /// Acquire without waiting. The parent directory must already exist.
    ///
    /// # Errors
    /// Returns `WouldBlock` for another writer, or a filesystem error.
    pub fn acquire(path: impl AsRef<Path>) -> io::Result<Self> {
        let path = path.as_ref();
        let name = path
            .file_name()
            .ok_or_else(|| io::Error::other("store needs a filename"))?;
        let mut lock_name = name.to_os_string();
        lock_name.push(".writer.lock");
        let file = OpenOptions::new()
            .read(true)
            .write(true)
            .create(true)
            .truncate(false)
            .open(path.with_file_name(lock_name))?;
        file.try_lock().map_err(io::Error::from)?;
        Ok(Self { _file: file })
    }
}

fn revision(path: &Path) -> io::Result<Option<String>> {
    let meta = match path.metadata() {
        Ok(m) => m,
        Err(e) if e.kind() == io::ErrorKind::NotFound => return Ok(None),
        Err(e) => return Err(e),
    };
    if meta.is_dir() {
        let mut latest = None;
        for entry in std::fs::read_dir(path)? {
            let entry = entry?;
            let name = entry.file_name();
            if let Some(n) = name
                .to_str()
                .and_then(|s| s.strip_prefix("manifest-"))
                .and_then(|s| s.parse::<u64>().ok())
            {
                latest = Some(latest.map_or(n, |old: u64| old.max(n)));
            }
        }
        return match latest {
            Some(n) => revision(&path.join(format!("manifest-{n:020}"))),
            None => Ok(Some("segments-empty".into())),
        };
    }
    let modified = meta
        .modified()?
        .duration_since(std::time::UNIX_EPOCH)
        .map_err(io::Error::other)?
        .as_nanos();
    #[cfg(unix)]
    let identity = {
        use std::os::unix::fs::MetadataExt;
        format!("{}-{}", meta.dev(), meta.ino())
    };
    #[cfg(not(unix))]
    let identity = meta
        .created()
        .ok()
        .and_then(|t| t.duration_since(std::time::UNIX_EPOCH).ok())
        .map_or(0, |d| d.as_nanos())
        .to_string();
    Ok(Some(format!("{identity}-{modified}-{}", meta.len())))
}

/// Publication cursor shared by every clone of one server.
#[derive(Clone, Debug, Default)]
pub struct SnapshotReader(Arc<Mutex<Option<String>>>);

impl SnapshotReader {
    /// Validate a changed publication completely before replacing `current`.
    /// Call while holding the mutex that protects `current`.
    ///
    /// # Errors
    /// Returns an error for a corrupt, missing or repeatedly changing publication.
    pub fn refresh<T>(
        &self,
        path: impl AsRef<Path>,
        current: &mut T,
        load: impl Fn(&Path) -> io::Result<T>,
    ) -> io::Result<()> {
        let path = path.as_ref();
        let mut seen = self.0.lock().expect("publication mutex");
        for _ in 0..3 {
            let before = revision(path)?;
            if before == *seen {
                return Ok(());
            }
            if before.is_none() {
                return Err(io::Error::other("published store disappeared"));
            }
            let next = load(path);
            if revision(path)? != before {
                continue;
            }
            *current = next?;
            *seen = before;
            return Ok(());
        }
        Err(io::Error::other("store changed repeatedly; retry"))
    }

    /// An opaque publication token, without filesystem paths.
    #[must_use]
    pub fn status_json(&self) -> String {
        let seen = self.0.lock().expect("publication mutex");
        // The token is composed only of numeric metadata, hyphens and ASCII.
        match seen.as_ref() {
            Some(s) => format!("{{\"generation\":\"{s}\"}}"),
            None => "{\"generation\":null}".into(),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn refresh_validates_before_swap() {
        let dir = std::env::temp_dir().join(format!("astrx-reader-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let path = dir.join("store");
        let reader = SnapshotReader::default();
        let mut current = 0u64;
        let load = |p: &Path| {
            std::fs::read_to_string(p)?
                .parse::<u64>()
                .map_err(io::Error::other)
        };
        crate::atomicfile::write_atomic(&path, b"42").unwrap();
        reader.refresh(&path, &mut current, load).unwrap();
        assert_eq!(current, 42);
        crate::atomicfile::write_atomic(&path, b"bad").unwrap();
        assert!(reader.refresh(&path, &mut current, load).is_err());
        assert_eq!(current, 42);
        std::fs::remove_file(&path).unwrap();
        assert!(reader.refresh(&path, &mut current, load).is_err());
        std::fs::remove_dir_all(dir).unwrap();
    }
}
