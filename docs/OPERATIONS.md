# Operations

Use the PHP application database and each Rust service's actual storage format.
The old Python/SQLite service commands do not describe this repository.

## Store ownership and publication

Onion, torrent and web crawler/indexer commands now hold an OS writer lease for
the whole load–modify–publish operation. Onion administration and torrent
blocklist writes take the same lease, reload the latest snapshot, stage a change,
and publish before replacing their live state. A failed write is not acknowledged
as persisted and does not change their live copy.

Every writer must run the patched version, use one canonical store path, and use
a local filesystem supporting advisory locks and atomic rename. Old binaries,
manual file writes and different path aliases do not participate in the protocol.
Do not delete a `.writer.lock` file: the file may remain after exit, but the OS
releases its lock. Deleting it while held can create two lock identities.

Long crawls hold the lease until they finish. Admin writes during that time return
HTTP 503 and must be retried after the writer stops; there is no durable queue.
The crawl commands are not a multi-writer database. Onion blob snapshots still
publish at run completion; a crash loses unpublished work. The atomic-file helper
fsyncs file contents and attempts a directory fsync on a best-effort basis, so this
is not an unconditional promise about power-loss durability on every filesystem.

The three search CLIs configure a snapshot source. Before each GET/HEAD they check
publication metadata and validate a changed snapshot before swapping it into
memory. Freshness is therefore the next request after publication, not a restart.
A corrupt publication, or removal of an already observed publication, returns
503 rather than silently serving stale results. Reloads are synchronous and may
be expensive for large indexes. `/api/storage` reports an opaque served generation;
`null` means no publication has been observed. It is not a wall-clock timestamp.

Websearch segmented readers open and validate the newest manifest without creating,
repairing or sweeping files. Recovery remains a writer operation under its lease.
Torrent `/api/block` persists both rules and purges before `persisted:true`; a
library-created server without a configured path explicitly reports
`persisted:false`. The shipped search CLI always supplies its database path.

Websearch's built-in fetcher is HTTP-only. There is no working TLS feature to turn
on. Redirects to HTTPS cannot be crawled with this fetcher.

## Backup and restore

Use a consistent MariaDB/MySQL backup, including all required configuration and
the matching installation secret. Stop or quiesce Rust writers after publication
before backing up a complete segmented directory. A CLI backup reads a committed
snapshot and locks its destination against another writer; it does not capture
unpublished in-memory work.

Restore into a separate environment first and check authentication, uploads, search
results and blocklists. Encrypted TOTP rows need their original installation key.
Do not rotate that key casually or reinterpret decryption failure as disabled 2FA.
Recover the matching key/database pair, or use a separately verified operator
account-recovery and re-enrollment procedure.

In Docker, live config, catalogs and uploads reside in named volumes after their
first initialization. Include those volumes and the database in backups. Template
cache is rebuildable. Never use `docker compose down -v` as routine maintenance on
a deployment containing valuable data. See [installation](getting-started.md).

## Templates and releases

Cache keys include source bytes, path, compiler fingerprint and compilation mode.
Run `php tools/warm-template-cache.php --clear` after upgrading to remove obsolete
cache files and warm the configured source and parse mode. PHP-processing mode is
chosen by the rendering caller; it has its own cache identity.

Reload long-lived PHP workers when deploying code. Build and test the exact
production image. Keep the database image on its existing release series until
an intentional, backed-up database upgrade. Review [upgrade notes](REVIEW-FIXES.md)
for API and deployment behavior changes.
