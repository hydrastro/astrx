# AstrX — Security & Correctness Fixes (overlay)

This archive is an **overlay**: unzip it at the repository root and it overwrites/creates
exactly the files listed below. It does **not** delete anything (a zip can't) — see
*Manual steps* for the few files to remove by hand.

All 54 changed/created files pass `php -l` on PHP 8.4.21. The email-sanitizer rewrite and
the privilege-rank guard were verified with executable PoCs, not just linted. Nothing here
changes the framework's idioms: failures still flow through `Result`/`Diagnostics`, new
user-facing strings are translation keys (en + it), and there are zero new dependencies.

Companion document: `astrx-security-review.md` (the full review these fixes close out).

---

## 1. Manual steps required after unzipping

**Run these SQL migrations on existing databases** (fresh installs get them from the schema):

    src/setup/migrate_add_login_lockout.sql      -- adds user.login_locked_until
    src/setup/migrate_remove_captcha_test.sql    -- drops the leftover test page

**Delete these files by hand** (an overlay zip cannot remove files):

    setup/fix124/                                 -- legacy duplicate tree; still contains the
                                                     fixed-hash seeded 'Administrator' account
    src/AstrX/Controller/CaptchaTestController.php -- test-only, now unseeded/unreachable
    public/print.php                              -- empty stub (optional tidy-up)

`public/info.php` is now admin-gated (not deleted) — keep it. `public/setup.php` is now
fail-closed; after you install via `php tools/install.php` you may delete it entirely.

**Review these config values before deploying:**

- `resources/config/config.php` — `environment` is now `1` (PRODUCTION). Set `0` for local dev.
- `resources/config/Session.config.php` — `server_secret` is now `''`. A unique per-install
  secret is auto-generated on first run (or set by `tools/install.php`). Do **not** paste a
  shared value; the old committed default is now hard-ignored.
- `resources/config/User.config.php` — `login_lockout_threshold` (default 10; `0` disables)
  and `login_lockout_cooldown` (default 900s).
- `resources/config/Mail.config.php` — `imap_verify_ssl` / `smtp_verify_ssl` (default true;
  set false only for onion-only mail hosts that can't present a CA cert).
- `resources/config/ContentManager.config.php` — `content_security_policy` (strict
  `default-src 'none'` for the JS-off canonical site; `frame-src 'self'` is included so the
  same-origin captcha iframe still loads).

---

## 2. Fixes by finding

### Critical

**C1 — `public/info.php` phpinfo() exposure.** Rewritten to boot the framework's own
DB-backed session and serve `phpinfo()` **only** to an authenticated ADMIN (with
`Cache-Control: no-store`); everyone else — including on missing config / DB / session — gets
a fail-closed 404. `docker/nginx/default.conf`: `autoindex off`.

**C2 — `setup.php` unauthenticated takeover.** `public/setup.php` now (a) treats *"an admin
user row already exists"* as installed → 404 (closes the Docker-auto-init hole that the
`.setup_complete` lock left open), and (b) requires a per-install `.setup_token` (generated
0600 in the config dir, outside the docroot) on every write step, compared with `hash_equals`.
New `tools/install.php`: a zero-dependency **CLI installer** (the recommended path) that writes
the PDO config, generates a unique `server_secret`, creates the first admin, and sets the
environment — all outside the web docroot.

### High

**H1 — Webmail sanitizer remote-resource / deanonymization bypasses.** `HtmlEmailSanitizer`
rewritten from denylist to **allowlist**: unknown tags unwrapped, all attributes dropped except
a tiny per-tag allowlist, `srcset` + `on*` stripped unconditionally, `style` dropped for
untrusted senders (and `url()`/`image-set()`/escaped-`url` scrubbed for trusted), and URL
schemes normalised (whitespace/control-char stripped, lowercased) before an allowlist test
(`http/https/mailto/#`/`cid`). PoC-verified: `srcset`, `<video|audio|source> src`, CSS
`image-set()`, CSS-escaped `\75rl(...)`, `javascript:`/`data:` hrefs, and `onerror` **all**
neutralised while `cid:` inline images survive.

**H2 — SMTP header/command injection.** `Mailer` gained a header-safety guard (rejects
`\r`/`\n`/`\0`) + recipient validation (`FILTER_VALIDATE_EMAIL`, with an injection-safe
single-label fallback so `noreply@localhost` still works); it validates to/from/cc/bcc + names
+ subject **before opening the socket** and returns a new `MailInvalidRecipientDiagnostic`.
The same guard is applied in `WebmailService` (draft/APPEND path).

**H3 — Dev environment default leaked internals.** `config.php` → `environment = 1`
(PRODUCTION); `Prelude` fallback → PRODUCTION. `ErrorHandler`'s verbose `print_r` stays
dev/staging-gated, so anonymous users now get a generic page.

**H4 — Committed session secret.** `Session.config.php` → `server_secret = ''` (triggers the
per-install generated fallback); `SecureSessionHandler::ikm()` now `hash_equals`-ignores the
old committed constant so no deployment can silently run on the public secret.

**H5 — `session.use_strict_mode=0`.** `docker/php/php.ini` → `1`, plus a defensive
`ini_set('session.use_strict_mode','1')` immediately before `session_start()` in
`ContentManager`. This activates the handler's existing `validateId()` and closes
fixation/adoption.

**H6 — Role mass-assignment / privilege escalation.** `AdminUsersController` now blocks any
`type` change unless the actor holds `USER_PROMOTE`, and — critically — compares **privilege
rank**, not the raw enum value. (`UserGroup` values are `USER=0, ADMIN=1, MOD=2, GUEST=3` — not
privilege-ordered — so the naive `<=` would have let a delegated MOD mint an ADMIN. A new
`UserGroup::rank()` fixes the ordering; self-promotion to a higher rank is also blocked.)

**H7 — TLS fail-open on the Tor/SOCKS path.** `ImapClient` + `Mailer` now set an SSL context
(`peer_name` pinned to the real host, `verify_*` from `imap_verify_ssl`/`smtp_verify_ssl`) on
every TLS-upgrade path and **capture the `stream_socket_enable_crypto` return, failing closed**
instead of sending credentials after a failed handshake. Plaintext-to-`.onion` remains a
legitimate configured mode (Tor provides that transport's encryption/authentication).

### Medium

**M1 (now optimization) — `templates.js` bundle.** `JsController` excludes the `admin/` prefix
from the browser bundle (bandwidth/hygiene) and emits its own scoped CSP for the `/js/` shell
(`script-src 'self' 'nonce-…'`).

**M2 — `CommentPolicy` inert.** `CommentRepository::findById`/`fetchAll` now `LEFT JOIN user`
to select `user_type`, and `CommentService::hide/unhide/delete` pass the comment resource into
the gate — so "mods can't moderate admin comments" is actually enforced (admin panel path
included).

**M3 — Reachable fatal crashes.** Implemented the missing `UserService::adminSetPassword()` /
`adminSetPasswordHash()`; fixed `BanlistRepository::countBansForRoute` (`ban`→`banlist`,
`dbErr`→`err`); fixed `NewsRepository`'s 2-arg `NewsDbDiagnostic` construction (routed through
the 3-arg helper). Raw `PDOException` text no longer rendered to users (news/admin diagnostics,
en **and** it).

**M4 — Brute-force lockout.** Configurable per-account lockout (`login_locked_until` column +
threshold/cooldown config), checked before `password_verify`, cleared on success. Opt-in via
config; default 10/900s.

**M5 — Account enumeration.** Login now runs a constant-cost `password_verify` against a dummy
Argon2id hash for unknown users (timing); the login captcha is driven by a session counter that
increments for unknown usernames too (existence-independent); password recovery treats
"user not found" as a non-leaking success (same flash + 302 as the hit path).

**M6 — MIME recursion DoS.** `ImapClient::parseMultipart` bounded by depth (20) + part count.

**M7 — CSP / security headers.** Central strict CSP + `Referrer-Policy: no-referrer` +
`X-Content-Type-Options: nosniff` + `X-Frame-Options: DENY` on the main render path
(`ContentManager`) and controller responses (`Response`). `frame-src 'self'` included so the
captcha iframe survives; the `/js/` shell gets its own permissive CSP.

### Low / hygiene

Remember-me cookie reordered (regenerate → set) with `SameSite=Lax`; rehash-on-verify added;
`data:` href closed (part of H1); `JsonRenderer` no longer reflects arbitrary diagnostic getters
(explicit `DiagnosticContextInterface`), and its envelope is id-rendered not hardcoded English;
`IdenticonRenderer` size/tiles/colors capped; `TemplateEngine` realpath-contains template paths,
uses explicit `htmlspecialchars` flags, and `addslashes`-parity on token codegen; `PrgHandler::getUrl`
returns `''` instead of throwing; `CaptchaFrameController` strings localized; comment `Anonymous`
stored as NULL + localized on render; leftover test-captcha page unseeded; new
`tools/check_lang_parity.php`.

---

## 3. QA performed

`php -l` on all 54 files (0 errors). Executable PoCs: the sanitizer (9 remote-resource / scheme
vectors → all neutralised, `cid:` preserved) and the `UserGroup::rank()` guard (MOD cannot set
ADMIN; ADMIN can set MOD). Cross-checked one integration regression (global frame-deny CSP would
have blocked the same-origin captcha iframe → added `frame-src 'self'`). Confirmed the four
clusters touched disjoint files.

## 4. Known follow-ups

- **i18n backfill — ✅ DONE (this pass). See §5a.**
- **CLI installer deepening — ✅ DONE (this pass). See §5b.**
- **Admin-in-prod error detail.** `ErrorHandler` verbose output is still dev/staging-gated; showing
  full detail to an authenticated admin in production needs session access at shutdown — deferred,
  and the current default is production-safe.

---

## 5. Follow-up pass — i18n parity + CLI installer

### 5a. Italian (it) locale brought to full parity with en
`resources/lang/it/**` now matches `resources/lang/en/**` exactly — `php tools/check_lang_parity.php`
reports **“en and it match across 20 files”, exit 0**. ~475 previously-missing keys were translated
across Admin (256), User (83), Http (66), Diagnostics/user (24), Comment (18 + the whole webmail
label block), Diagnostics/comment (12, incl. the emitted-but-uncatalogued `unknown` id — also added
to en), Navbar, pages, and Diagnostics/core. Placeholders, HTML, and closure signatures were
preserved; diagnostic closures render generic text and never interpolate raw driver/exception strings.

Corrections made while reconciling (worth a glance):
- **`it` comment/user/admin diagnostics used a dead `…/operation` catch-all closure** the code never
  emits (the renderer looks up by the per-id `id()`, e.g. `astrx.comment/flood`). Rewrote them to the
  per-id keys the code actually emits (`CommentService`/`UserService`/`AvatarService`), so `it`
  comment/login errors now render in Italian instead of falling back. Verified by invoking the real
  closures with real diagnostic instances.
- **`resources/lang/it/Mail.it.php`** was a stale orphan (no `en` counterpart; no code loads a
  top-level `Mail` domain; its ids are a subset of `Diagnostics/mail`). Removed — see cleanup script.
- **5 dead `admin.banlist.*` keys** (from the dropped per-route banlist feature) removed from `it`.
- **Latent bug fixed:** `it/Diagnostics/user.it.php`’s `invalid_theme` closure referenced
  `InvalidThemeDiagnostic` with no import (its `assert` never bound) — import added.

Wire `tools/check_lang_parity.php` into CI to fail on future drift.

### 5b. CLI installer hardened and tested end-to-end
`tools/install.php` gained: a **quote/comment-aware SQL splitter** (the previous `explode(';')` + `--`
strip could corrupt seed strings), an optional **`--create-db`** (utf8mb4), and **early fail-fast
checks** (pdo_mysql present, config files writable). It was then **run end-to-end against a real
MariaDB 10.11**: created the DB, applied the 33-table schema, ran all 16 migrations (including the two
new ones), created the Argon2id admin, generated a unique `server_secret`, set `environment=1` —
exit 0. Verified the `login_locked_until` column exists and the `captcha-test` page is absent
post-migration.

---

## 6. Merge with your uncommitted local changes

This build **merges your uncommitted changes** (the diff you supplied) with everything above, via a
git 3-way merge from the common base — nothing of yours was clobbered. Auto-merge succeeded on every
overlapping file except one; both sides' intent was verified to survive (your `page_hidden` → `DEBUG`,
`Accept` `is_string` guard, type-safety guards, unused-import removals, phpstan docblocks, attachment
typing — alongside my CSP/strict-mode, `UserGroup::rank()` promotion guard, Mailer header-safety + TLS
`peer_name`, and the `DiagnosticContextInterface` JSON change).

Two spots needed a decision:
- **`UserService::adminSetPassword` / `adminSetPasswordHash`** — we both added these. Kept **your**
  versions (they add the empty-field check and `passwordRegex` policy validation); dropped my simpler
  pair. `AdminUsersController` calls them with the same signatures, so nothing else changed.
- **`NewsRepository` DB-error branch** — we both fixed the 2-arg `NewsDbDiagnostic` crash. Kept the
  `$this->pdoDiagnostic($e)` helper form (identical effect, one call site).
- **`README.md`** — kept the **official** README, not the `fix123` one, per your note.

The merged tree was re-verified whole: `php -l` clean across all changed files, `it`/`en` parity still
exits 0, the email-sanitizer PoC still closes every vector, and `tools/install.php` again booted a
complete 33-table database (admin created, lockout column present, `captcha-test` absent) against
MariaDB — exit 0.
