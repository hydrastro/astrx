# AstrX

A zero-dependency PHP 8.4 content-management framework with a built-in
real-time chat, engineered for privacy-first deployments — Tor hidden services,
hostile networks, JavaScript-disabled clients — where every feature must work
with scripting off and nothing may phone home.

AstrX ships no Composer packages at runtime. The framework, the CMS, the
moderation stack, the mail/webmail layer, and the live chat are all pure PHP on
top of MariaDB and a handful of standard extensions. There is no build step
required to run it, no CDN dependency, and no client-side framework.

## Design principles

**No external dependencies.** Runtime is PHP 8.4 plus `pdo_mysql`, `gd`,
`fileinfo`, and `mbstring`. Everything else — routing, templating, DI, i18n,
sessions, auth, the crypto envelope — is in `src/`.

**No-JavaScript-first.** Every page, form, moderation control, and the live
chat itself function with scripting disabled. Navigation is server-rendered and
Post/Redirect/Get throughout; the chat "streams" by auto-refreshing iframes, not
XHR. JavaScript, where present, is strictly progressive enhancement.

**Errors are values, not control flow.** Operations return a `Result<T>` monad
carrying typed `Diagnostic` objects rather than throwing. Diagnostics have
stable ids, severity levels, and locale-resolved messages, and they compose up
the call stack — so a failure deep in a repository surfaces as structured,
translatable data at the edge instead of an exception or a bare `false`.

**Native internationalization.** No user-facing string is hardcoded. Every
message is a translation key shipped in English and Italian and kept in lockstep
by `tools/check_lang_parity.php`, which fails the build if the two locales drift.

**Verified, not asserted.** The whole `src/`, `public/`, and `tools/` tree is
clean at **PHPStan level 10**, the strictest setting.

## Architecture

A single front controller (`public/index.php`) resolves the request through the
router into a page record, then into a controller. Controllers are constructed
by a reflection-based dependency injector (`AstrX\Injector`) that autowires
services from their constructor type-hints, and configuration is bound
declaratively through the `#[InjectConfig]` attribute — a config setter is
matched to a key in a `*.config.php` file and invoked at wiring time.

Pages live in a database table with a closure table for the hierarchy, are
rendered through a Mustache-style template engine (escape-by-default, with
path-traversal-guarded partial loading), and can be decorated with per-page
meta, robots, keywords, and templates. A navbar builder assembles the menu tree
from pinned and grouped entries. Optional modules — news, comments, an API, a
feed, mail and IMAP webmail — hang off the same core.

## The chat

A real-time chat that needs no JavaScript: an auto-refreshing message stream,
guest and member posting, private messages, per-user settings and themes, and a
complete moderation surface. Moderators get an in-chat admin panel — live
sessions, kick and ban routed through the shared banlist, broadcast, room topic,
and bulk clean — plus guest-access modes with optional moderator approval before
a guest may post. Abuse control is layered: managed **word / link / nick
filters** with automatic kick, a user **report → moderator queue** that can turn
a reported link into a kick filter in one action, per-identity **flood
protection** with auto-mute, and **EXIF-stripped image attachments** (uploads
are re-encoded through GD, so metadata and polyglot payloads are discarded and
only pixels survive). Every limit — retention, message count, refresh interval,
upload size and dimensions, capacity — is admin-configurable.

## Security model

Passwords are hashed with Argon2id and transparently rehashed on verify; a dummy
verification runs on unknown users to flatten timing. Sessions use 128-byte
CSPRNG identifiers stored as SHA-512 digests, with session data sealed under
AES-256-CTR encrypt-then-HMAC using domain-separated HKDF-derived keys and a
constant-time MAC check. CSRF protection is per-session, single-use, 256-bit, and
compared with `hash_equals` on every state-changing POST. Authorization goes
through a default-deny `Gate` with a permission enum and per-resource policies.

The **banlist** is a first-class identity-ban engine spanning IP/CIDR, email,
nick, and user, with penalty rounds and expiry; IPv4 is handled as IPv4-mapped
IPv6 so v4 and v6 bans share one code path. An append-only **admin audit log**
records significant admin actions — who did what, to which resource, from which
address, and when — across user management, moderation, the banlist, and every
configuration save.

## Requirements

- PHP **8.4** with `pdo_mysql`, `gd`, `fileinfo`, and `mbstring`
- MariaDB **10.4+** (or MySQL 8+)
- A web server with the document root pointed at `public/`

## Quick start (Docker)

```
docker compose up --build
```

On first boot the MariaDB container runs `src/setup/init.sql`, which creates the
`content_manager` database and the application user. Then open `public/setup.php`
and follow the wizard — it installs the schema from `src/setup/tables.sql` and
writes the config files under `resources/config/`.

> The init script runs only against a fresh data directory. If you have booted
> the stack before, reset the database volume first: `docker compose down -v`.

## Manual setup

1. Point the web server's document root at `public/`.
2. Create an empty database named `content_manager` and a user that can access
   it (see `src/setup/init.sql` for the grants the Docker path uses).
3. Visit `public/setup.php` and complete the wizard, or initialise by hand:
   import `src/setup/tables.sql` and fill in `resources/config/PDO.config.php`.
4. Make the upload directories writable by the web server — the chat upload dir
   (`upload_dir` in the chat config, default `resources/chat_uploads`) and
   `resources/avatar`.

The schema is a **single file**, `src/setup/tables.sql`; there are no
incremental migration files. Schema changes are folded into that file, and an
existing database is brought forward by applying the delta by hand.

## Configuration

Runtime configuration lives in `resources/config/*.config.php`. Each file is a
plain PHP array bound to a typed config object via `#[InjectConfig]`, and almost
everything is editable from the in-app admin panels (access rules, captcha,
chat, mail, webmail, themes, system) rather than by hand-editing files. The chat
alone exposes 60+ settings through its admin surface.

## Internationalization

Locale catalogs live under `resources/lang/<locale>/`, split by domain, with a
matching pair for English (`en`) and Italian (`it`). `tools/check_lang_parity.php`
verifies that both locales define exactly the same keys and exits non-zero on any
divergence — run it before committing.

## Development

```
php phpstan.phar analyse        # static analysis, level 10, must be clean
php tools/check_lang_parity.php # en / it translation parity
```

## Project layout

```
public/      entry points (index.php router, setup.php, avatar/captcha endpoints)
src/AstrX/   the framework — Chat/, Controller/, Auth/, Admin/, Http/, Api/,
             Template/, Routing/, Injector/, Result/, Mail/, News/, Comment/, …
src/setup/   init.sql (database bootstrap) + tables.sql (the complete schema)
resources/   templates (Mustache-style), lang/ (en, it), config/ (*.config.php)
docker/      Dockerfiles and service config for the compose stack
tools/       maintenance scripts (e.g. check_lang_parity.php)
docs/        API.md, COMPILED_BUILD.md, PROFILING.md
```
