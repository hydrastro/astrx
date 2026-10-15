# Review fixes and upgrade notes

These changes address findings F01–F11 from the review of commit
`0b22bbbfa4da70e96b87ba0d4ac0b520b95360ed` (14 October 2026).
They are a patch for that revision, not a claim that every part of AstrX has been
proved secure or that all future architecture work is finished.

## Changes

| Finding | Change | Regression coverage |
|---|---|---|
| F01: unreadable TOTP treated as disabled | TOTP queries return `Result`; password login, challenge and management reject unavailable state. Management announces success only after a successful write. | Real UserService/UserRepository fixtures: wrong key, corrupt envelope, invalid flag, missing account, failed query, correct password with unreadable factor. |
| F02: snapshot writers lose updates | OS writer leases cover load through publication. Admin mutations reload under the lease, stage a copy, and publish before replacing memory. | Independent production server instances preserve both additions; a subprocess proves lease exclusion/release; busy writers refuse mutation. |
| F03: stale administrator access to diagnostics | Remove `public/info.php`; account-refresh failures return 503 instead of trusting cached authorization. | Endpoint removed; PHP static analysis and existing request/security tests. Full revocation-over-HTTP scenarios remain a deployment check. |
| F04: default Docker build includes Xdebug | Shared base, explicit production target, separate dev override; updated nginx and deliberate MariaDB release-series setting. | Shell/YAML checks locally; new production-image/install CI workflow. |
| F05: setup write permissions and token failures | Explicit runtime volumes, initialization as their owner, read-only source; checked, locked, private token creation; checked completion-marker write. | Token persistence/reuse/mode/failure tests; production CI tests FPM setup and runtime writes. |
| F06: inconsistent password floor | Shared policy: 12 Unicode characters, at most 4096 UTF-8 bytes, valid UTF-8. Installers enforce it at prompts and at `makeAdmin`. | Policy bounds and both actual installer functions reject short passwords before SQL. |
| F07: incomplete template cache identity | Content/path/settings/compiler fingerprint determine immutable cache files and class names. Remove mutable shared index; warmer uses configured paths/mode. | 44 rendering checks; 17 checks across fresh PHP processes and compiled bundles, including preserved mtimes and different source directories. |
| F08: stale running search indexes | Search CLIs configure snapshot sources; changed publications are validated and adopted on the next request. Segmented reads never repair/sweep. | Blob and segmented refresh, corrupt publication rejection, generation endpoint. |
| F09: ephemeral torrent blocklist | Persist rules and purge results under the same writer lease before acknowledging durable success. | Restart/deserialization retains infohash and keyword rules; other readers refresh; busy/corrupt state refuses requests. |
| F10: obsolete operations documentation | Rewrite operations, installation and architecture guidance against the Rust implementations. | Commands and APIs checked against source; Docker execution remains a CI gate. |
| F11: inert configuration | Wire CSRF and registration domains, translation overrides/fallback and extra language domains; remove inert keys and editor field; remove checker exceptions. | Strict integrity check; eight behavioral injection/translation checks. |

Also included: inert `src/autoload.php`, application-level Composer metadata,
`examples/components.php`, and a shorter README. A flaky existing MAC test now
flips a bit instead of sometimes replacing a byte with its existing value.

## Before applying

The complete patch supersedes earlier partial patches. Apply it once to the base
commit above in a clean checkout, or review and port it to a newer revision.
Do not stack the complete patch on top of earlier parts. Commit hashes for the
local implementation are not upstream releases.

## Compatibility and rollout

- **TOTP API:** `UserService::totpInfo()` and `isTotpEnabled()` now return `Result`.
  Update external callers to handle failure before unwrapping. A wrong installation
  key intentionally prevents login to enrolled accounts. Restore the matching key
  and database, or use a verified operator recovery/re-enrollment procedure; do not
  clear enabled flags automatically. The shipped controller callers are updated.
- **Authorization:** a database failure during current-account validation returns
  503 while preserving the cookie session for retry. Delete any old deployed
  `public/info.php` left by a deployment process that does not remove obsolete files.
- **Passwords:** the shared bounds apply to newly created/changed passwords. This
  does not force an automatic reset of existing passwords or change hash algorithms.
- **Templates:** clear/warm with `php tools/warm-template-cache.php --clear` and
  restart long-lived PHP workers on a code upgrade. Template source is trusted
  executable input. Cache identity now requires reading source bytes.
- **Configuration:** ship `Csrf.config.php`. Remove obsolete
  `ErrorHandler.failsafe_template`, `ErrorHandler.production_mask`,
  `Routing.default_keys` and `ContentManager.main_page_id` from local configuration.
  `Routing.default_page` continues to control the landing page. Extra language
  domains now load before ancestor/page-specific domains. Translation overrides
  augment built-in catalogs; an explicit per-call fallback takes precedence.
- **Rust:** the minimum supported Rust version is now **1.89**, for standard-library
  file locking. Upgrade every process writing a shared store together. Use one
  canonical store path on a local filesystem with working advisory locks and
  atomic rename. Never unlink a live `.writer.lock` file. Long-running writers
  cause admin writes to return 503 until they can be retried; no queue was added.
  Reloading large indexes is synchronous. See [operations](OPERATIONS.md) for
  publication, crash-window and durability limits.
- **Docker:** preserve the database's existing release series through
  `MARIADB_IMAGE` before rebuilding an existing deployment. The 11.4 default is
  for fresh databases; never downgrade an existing data volume. Runtime config,
  catalogs and uploads move to named volumes seeded from the checkout once.
  Thereafter those volumes are authoritative: host edits do not update them.
  Back them up and merge configuration/catalog upgrades deliberately. New config
  files are seeded only if absent. CLI commands run as `www-data` through the
  entrypoint; use `exec --user www-data` for commands in a running container.

## Remaining scope

The PHP project's explicit license is still the owner's decision. This patch does
not invent a license grant. The PHP core/application package extraction and any
repository move remain future work; loading a component independently is a first
step, not a completed standalone framework distribution. HTTPS crawling remains
unsupported by the existing web fetcher. No repository push, release or promotional
post is performed by these local changes.

See the delivery's `VALIDATION.md` for executed checks and environment limitations.
