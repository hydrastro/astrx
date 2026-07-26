# AstrX

A zero-dependency PHP 8.4 content-management framework with a built-in
real-time chat, engineered for privacy-first deployments — Tor hidden services,
hostile networks, JavaScript-disabled clients — where every feature must work
with scripting off and nothing may phone home.

AstrX ships no Composer packages at runtime. The framework, the CMS, the
moderation stack, the mail/webmail layer, and the live chat are all pure PHP on
top of MariaDB and a handful of standard extensions. There is no build step
required to run it, no CDN dependency, and no client-side framework.

## What's distinctive

- **No JavaScript required.** Every page, form, moderation control, and the live
  chat function with scripting disabled. Navigation is server-rendered and
  Post/Redirect/Get throughout; the chat "streams" by auto-refreshing iframes,
  not XHR.
- **One controller, HTML *and* JSON.** The web view and the JSON API are the
  same code path. A page opts into the API with a flag, and per-value
  `ContextScope` tags decide exactly what crosses into JSON — no parallel "API
  controllers" to keep in sync.
- **CSS-only themes.** A theme is a directory of metadata plus a stylesheet;
  switching it changes no markup and ships no script.
- **Errors are values, not control flow.** Operations return a `Result<T>` monad
  carrying typed `Diagnostic` objects rather than throwing — all the way to the
  edge, and into the API envelope.
- **Zero runtime dependencies**, **native English/Italian i18n** enforced by a
  parity check, and a tree that is clean at **PHPStan level 10**.

## Operating modes

The same application runs through three front controllers, selected at the
web-server level:

- **Server-rendered (default)** — `public/index.php`. Plain, fast HTML with PRG
  navigation; the mode the whole framework is designed around.
- **Experimental `/js/` browser** — a progressive-enhancement client served
  under `/<locale>/js/`, with a same-origin API index at
  `/<locale>/js/api.json`. Entirely optional; the site is fully usable without
  it.
- **Compiled bundle** — `php tools/compile.php` packs all of `src/` and the
  read-only resources into a single `build/astrx.compiled.php` with an on-demand
  autoloader (not naive concatenation). Serve it directly via
  `public/compiled.php`, or benchmark it side-by-side under `/compile`
  (`public/compile/index.php`). Config and uploaded state stay external and
  mutable. See `docs/COMPILED_BUILD.md`.

## Optional modules

Several features — the imageboard, the chat, the bot-trap honeypot, site-wide
search, and the webmail client — are **optional modules** that a deployment can
turn off without touching core: the module's navigation disappears, its pages
return the themed 404, and its schema can be dropped. Each module self-declares
in a `src/AstrX/<Module>/module.php` manifest that `AstrX\Module\ModuleRegistry`
discovers, so adding one is a manifest plus a page tag — core names no module.

```
php tools/module.php status              # each module: enabled/disabled + page count
php tools/module.php disable chat        # nav drops, pages 404 — reversible
php tools/module.php purge   chat        # also drop its tables (destructive)
php tools/make_module.php forum --nav    # scaffold a new module
php tools/check_modules.php              # CI gate: validate every manifest
```

Toggle them in `resources/config/Modules.config.php`. See `docs/MODULES.md` for
the manifest contract and a walkthrough of writing your own.

## Architecture

A single front controller resolves the request through the router into a page
record, then into a controller. Controllers are constructed by a
reflection-based dependency injector (`AstrX\Injector`) that autowires services
from their constructor type-hints, and configuration is bound declaratively
through the `#[InjectConfig]` attribute — a config setter is matched to a key in
a `*.config.php` file and invoked at wiring time.

Pages live in a database table with a closure table for the hierarchy, are
rendered through a Mustache-style template engine (escape-by-default, with
path-traversal-guarded partial loading), and can be decorated with per-page
meta, robots, keywords, and templates. A navbar builder assembles the menu tree
from pinned and grouped entries. Optional modules — news, comments, the API, a
feed, mail and IMAP webmail — hang off the same core.

| Concern | Where it lives |
|---|---|
| Front controllers | `public/index.php`, `public/compiled.php`, `public/compile/` |
| Routing & dispatch | `src/AstrX/Routing/`, `src/AstrX/ContentManager.php` |
| Dependency injection | `src/AstrX/Injector/` (reflection autowiring) |
| Configuration | `#[InjectConfig]` → `resources/config/*.config.php` |
| Templating | `src/AstrX/Template/` (Mustache-style, escape-by-default) |
| Result / diagnostics | `src/AstrX/Result/` |
| Internationalization | `src/AstrX/I18n/`, `resources/lang/{en,it}/` |
| Auth, sessions, CSRF | `src/AstrX/Auth/`, `src/AstrX/Session/`, `src/AstrX/Csrf/` |
| Pages & hierarchy | `src/AstrX/Page/` (+ closure table) |
| Chat | `src/AstrX/Chat/`, `src/AstrX/Controller/Chat*` |
| Admin & moderation | `src/AstrX/Admin/`, `src/AstrX/Controller/Admin*` |
| HTTP API | `src/AstrX/Api/` |
| Mail & webmail | `src/AstrX/Mail/` |
| Themes | `src/AstrX/Theme/`, `resources/template/themes/` |

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

## The HTTP API

Any page becomes a JSON endpoint by setting `api_enabled = 1`; the controller
that renders the web view serves the API unchanged. A response carries both
structured `data` and the fully-rendered `html` in a single round trip (drop the
HTML with `?html=0`). What appears in `data` is governed by per-value
`ContextScope` tags, so enabling the API never blanket-exposes a controller's
internals — an enabled page with nothing tagged returns `"data": {}`.

| Scope | Web HTML | API JSON |
|---|---|---|
| `WEB_ONLY` (default) | yes | never |
| `SHARED` | yes | yes |
| `API_PUBLIC` | no | yes |
| `API_ADMIN` | no | admin callers only |

Authentication is by `astrx_`-prefixed bearer key (192-bit, scoped to one user),
an existing session cookie, or anonymous — and permissions and policies apply
exactly as they do on the web. Full reference in `docs/API.md`.

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

## Themes

Themes are CSS-only. Each lives in `resources/template/themes/<name>/` as a
`theme.config.php` (name, description, author, version) plus a stylesheet;
selecting one swaps the stylesheet without touching markup or shipping any
script. A site-wide default and per-user overrides are set from the admin and
user-settings surfaces.

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
php tools/compile.php           # (re)build the single-file compiled bundle
```

## Documentation

- `docs/API.md` — the HTTP API: opt-in, context scopes, auth, response envelope.
- `docs/COMPILED_BUILD.md` — the compiled single-file bundle and how to serve it.
- `docs/PROFILING.md` — profiling normal vs. compiled front controllers.

## Project layout

```
public/      entry points — index.php (dev), compiled.php + compile/ (bundle),
             setup.php, avatar/captcha endpoints
src/AstrX/   the framework — Chat/, Controller/, Auth/, Admin/, Http/, Api/,
             Template/, Routing/, Injector/, Result/, Mail/, News/, Comment/,
             Theme/, Navbar/, Page/, …
src/setup/   init.sql (database bootstrap) + tables.sql (the complete schema)
resources/   templates (Mustache-style + themes/), lang/ (en, it), config/
docker/      Dockerfiles and service config for the compose stack
tools/       maintenance scripts (check_lang_parity.php, compile.php, …)
docs/        API.md, COMPILED_BUILD.md, PROFILING.md
```
