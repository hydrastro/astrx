# AstrX

A PHP 8.4 web framework that produces plain, fast HTML — designed for
clients that don't run JavaScript (Tor Browser's safest mode, lynx,
w3m, elinks, screen readers, slow networks). The same controllers also
expose an opt-in JSON API per page, an experimental JavaScript browser
at `/<locale>/js/`, and a compiled single-file bundle for production
benchmarking.

```
PHP 8.4 strict · PSR-4 · MariaDB / PDO · GD · OpenSSL · Mustache
```

## What's distinctive

- **No JavaScript required.** Every page renders fully server-side. The
  `<script>` tag appears in exactly one place: the experimental `/js/`
  shell. Everything else — comments, captcha, forms, navigation, admin —
  works with JS off.
- **Same code for HTML and API.** Opting a page in via `page.api_enabled = 1`
  exposes it at `/<locale>/api/<slug>` using the same controller code
  that rendered the HTML. No parallel "API controllers" to drift out of
  sync.
- **Themes are CSS-only.** Eleven themes (amber, cybermono, default,
  dracula, light, newsprint, reader, sepia, synthwave, terminal, tor),
  switchable per-user, all plain CSS.
- **Result monad + Diagnostics.** Errors flow through `Result<T>` and a
  `Diagnostics` collector. Exceptions are reserved for genuinely
  exceptional faults; expected failure modes are values.

## Quick start

```bash
git clone <repo>
cd astrx
docker compose up --build -d
sleep 10                                  # MariaDB initialises
# Visit http://localhost/setup.php to create the admin user.
```

Then:

| URL | What you get |
|---|---|
| `http://localhost/en/` | Main page, JS-free |
| `http://localhost/en/api/main?html=1` | The same page as JSON |
| `http://localhost/en/js/` | Experimental JS browser |

## Three operating modes

### 1. Normal (default)

```
public/index.php  →  src/bootstrap.php  →  PSR-4 autoload from src/
```

Plain server-rendered HTML. Each request is a full page render. URLs
follow `/<locale>/<page-slug>[/<sub-segments>]` with no client-side
routing. This is the canonical mode and the one to use for Tor /
text-only browsers.

### 2. JS browser (experimental)

```
/<locale>/js/  →  JsController  →  shell + runtime.js + manifest + templates
```

Inspired by [miniLOL](https://github.com/meh/miniLOL). One HTML shell
document at `/<locale>/js/` boots a small JavaScript runtime which:

1. Fetches a page manifest (`manifest.json`), a compiled template bundle
   (`templates.js`), and the runtime itself (each cacheable with ETag +
   long `max-age`).
2. Renders the layout client-side using the same Mustache templates the
   server uses, via an in-runtime Mustache parser.
3. Intercepts clicks and form submits, fetches the matching
   `/<locale>/<page>` HTML, and swaps in the new content without a full
   page reload. The History API keeps real URLs in the address bar.

The `/js/` mode is **strictly additive**: the normal site at
`/<locale>/<page>` remains the canonical no-JS path. Disable JavaScript
and everything still works exactly the same way.

### 3. Compiled bundle (production / benchmark)

```
public/compiled.php  →  build/astrx.compiled.php  →  Bundle::boot()
```

`php tools/compile.php` packages every PHP source file into a single
`build/astrx.compiled.php`, with an embedded autoloader that fans them
back out per-class on demand. Mainly useful for profiling — turns "N
stat + N read" into "one stat + one read + map lookups". You can run
it side-by-side with normal mode using `/compile/*` to compare numbers
in cachegrind.

See [`docs/COMPILED_BUILD.md`](docs/COMPILED_BUILD.md).

## Architecture at a glance

| Concept | Lives in |
|---|---|
| Request routing + page resolution | `AstrX\ContentManager`, `AstrX\Page\PageHandler` |
| Controllers | `AstrX\Controller\*Controller` (one per page type) |
| Templates | `resources/template/`, rendered by `AstrX\Template\TemplateEngine` |
| Dependency injection | `AstrX\Injector` (constructor-based, reflection-driven) |
| Authentication | `AstrX\User\UserSession`, `AstrX\Auth\AuthService` |
| Permissions | `AstrX\Auth\Gate` + `AstrX\Auth\Permission` enum |
| Result type | `AstrX\Result\Result<T>` |
| Diagnostics | `AstrX\Result\DiagnosticsCollector` |
| Translation | `AstrX\I18n\Translator` |
| Themes | `AstrX\Theme\ThemeService` |
| CAPTCHA | `AstrX\Captcha\*` |
| Mailer | `AstrX\Mail\Mailer` |
| Feeds | Atom XML via `FeedController` |

Every controller takes its dependencies in its constructor. The
injector resolves them via reflection. There's no service locator and
no global state.

## Project layout

```
public/
  index.php             entry point (normal mode)
  compiled.php          entry point (compiled bundle)
  compile/index.php     entry point (/compile/* benchmark prefix)
  setup.php             install wizard
  info.php, print.php   utilities

src/
  bootstrap.php
  AstrX/                PSR-4 root (AstrX\ namespace)
  setup/                tables.sql + migrate_*.sql

resources/
  template/             Mustache templates + themes/
  lang/                 translations (en, it)
  config/               instance config (PDO creds, site config)

setup/                  Docker init SQL (runs on fresh volume)
  01-init.sql           database + user account
  02-tables.sql         complete schema + seeds
  migrate_*.sql         additional idempotent migrations

docker/                 Dockerfiles for nginx, php-fpm, mysql
docs/                   framework documentation
tools/                  build + warm-cache scripts
build/                  compiled-bundle output (gitignored)
```

## Documentation

- [`docs/API.md`](docs/API.md) — HTTP API: routing, payload shape,
  per-page opt-in, the `/js/api.json` discovery index
- [`docs/COMPILED_BUILD.md`](docs/COMPILED_BUILD.md) — building and
  benchmarking the single-file bundle
- [`docs/PROFILING.md`](docs/PROFILING.md) — Xdebug profiler + the
  `Server-Timing` headers emitted by the framework

## Development

```bash
# After editing PHP, drop opcache:
docker compose restart phpfpm

# Tail logs:
docker compose logs -f phpfpm

# Reset DB to fresh schema:
docker compose down -v && docker compose up --build -d && sleep 10

# Build the compiled bundle:
php tools/compile.php

# Apply it inside the running container (rebuilds template cache too):
./fix-compiled-bundle.sh
```

## Conventions

- **PHP 8.4 strict types.** Every file starts with `declare(strict_types=1);`.
  PSR-4 autoloading under `AstrX\…`.
- **Return `Result<T>`, not exceptions, for expected failures.** Use the
  `DiagnosticsCollector` to accumulate non-fatal warnings during a
  request. Reserve `throw` for programmer errors / unrecoverable state.
- **Constructor injection only.** No setter injection, no annotations,
  no compile-time DI containers. The reflection injector wires
  everything together at runtime.
- **Templates are Mustache.** Logicless. Helpers and computed values
  belong in the controller's context object, not the template.

## Cleanup

This repository accumulates intermediate artifacts during development
(build outputs, working zips, debug captures). To remove them:

```bash
bash clean.sh
```

`clean.sh` deletes build outputs (rebuildable with `tools/compile.php`),
working release zips, leftover `.orig` backups, the
`setup/fix124/` and `setup/setup/` directories left over from earlier
installation attempts, and stray zero-byte files at the repo root.

It will NOT delete `build/` if you've made it read-only, and never
touches anything under `src/`, `resources/`, `setup/01-*` `setup/02-*`,
`public/`, `docker/`, `docs/`, or `tools/`.

## License

See `LICENSE` (TODO: add one).
