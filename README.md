# AstrX

A modular PHP web framework.

- **Overengineered from the core** - because I'm the CEO of Overengineering
- **No JavaScript.** - for the love of TOR sysadmins
- **Security by structure** - paranoia is never too much
- **Zero dependencies.** - what's composer?
- **i18n by default.** - mamma mia
- **Highly configurable.** - if you can't change it, it's a bug

---

## Requirements

PHP `8.4+` with extensions: `pdo_mysql`, `openssl`, `gd`, `mbstring`.  
A webserver with url rewriting (like nginx or Apache).  
A SQL database (like MariaDB / MySQL).

---

## Quick Start

```bash
git clone https://github.com/hydrastro/astrx.git
cd astrx
cp .env.example .env      # it refuses to start until you fill it in. good.
docker compose up -d
```

Or point your web server root at `public/` with URL rewriting to `index.php`.

Then visit `http://localhost/setup.php` and follow the five-step wizard: requirements check → database → admin account → security → done.

> **Note:** the wizard locks itself on completion, but it's advised to delete `public/setup.php` afterwards anyway.

For nginx:
```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

---

## Architecture

### Bootstrap & Dependency Injection

`public/index.php` is the single entry point.  
`src/bootstrap.php` defines path constants, registers the PSR-4 autoloader, and instantiates `Prelude` — the composition root.

`Prelude` manually constructs the singletons of the core classes (`Config`, `Translator`, `ModuleLoader`, `Injector`, `Gate`) and registers them with the `Injector`. Everything else is resolved on demand.

Config files in `resources/config/` are plain PHP arrays keyed by domain:

```php
// resources/config/Mail.config.php
return [
    'WebmailService' => ['mail_domain' => 'example.com', 'mailserver_is_local' => false],
];
```

Classes declare which keys they consume with `#[InjectConfig]` attributes on setters.  
`ModuleLoader` wires these automatically every time a class is created.

```php
#[InjectConfig('mail_domain')]
public function setMailDomain(string $v): void { $this->mailDomain = $v; }
```

---

### Result Monad & Diagnostics

This is the core of the system.  
All recoverable runtime errors flow through `Result<T>` and the `Diagnostics` channel.  
Exceptions are reserved for programmer-contract violations — never for business logic.

```php
$ok  = Result::ok($value);
$err = Result::err(null, Diagnostics::of($diagnostic));

$result->isOk();           // bool
$result->unwrap();         // T (throws only on programmer error)
$result->drainTo($collector); // propagate diagnostics up the stack
$result->map(fn($v) => $v * 2);
$result->flatMap(fn($v) => doSomethingElse($v));
```

`Diagnostics` is an immutable, append-only collection of `DiagnosticInterface` objects.  
Each carries a string ID (e.g. `astrx.user/wrong_password`), a `DiagnosticLevel`, and typed fields specific to that diagnostic class.

`DiagnosticRenderer` turns diagnostics into human-readable strings via callable entries in the lang files:

```php
// resources/lang/en/Diagnostics/i18n.en.php
'astrx.user/wrong_password' => fn(WrongPasswordDiagnostic $d): string =>
    "Login failed for \"{$d->username()}\" after {$d->attempts()} attempt(s).",
```

The `DiagnosticsCollector` accumulates diagnostics across the entire request.  
At render time, `DiagnosticRenderer` can surface them — to admins as a visible panel on the error page, to logs as structured entries, to tests as assertable values.  
The admin panel lets you configure which severity levels are visible to which user groups and override individual diagnostic levels per deployment.

---

### Routing

Two modes, seamlessly switchable via config:

- **Rewrite mode:** `/{locale}/{page-url-id}/...` — uses the URL path as a stack.
- **Query mode:** `/?page=main&lang=en` — classic `$_GET` style, works without server-side rewriting.

Internationalization of the urls is supported.

Pages live in the `page` table. Each has a `url_id` (an i18n key for localised slugs), a `file_name` (maps to a template and controller), and flags for controller, template, and comments. `ContentManager` resolves the request to a `Page` object, dispatches the controller, and renders the template. `UrlGenerator` produces fully localised, mode-aware URLs from page IDs.

---

### Authentication & PBAC

The system implements a Policy-Based Access Control.  
Roles (`ADMIN`, `MOD`, `USER`, `GUEST`) map to named `Permission` enum values.  
Per-resource decisions delegate to typed `PolicyInterface` classes:

```php
// Simple permission check
if ($this->gate->cannot(Permission::ADMIN_ACCESS)) { ... }

// Resource-level — delegates to a Policy class
if ($this->gate->cannot(Permission::EDIT_POST, $postResource)) { ... }
```

---

### Session Security

Sessions are database-backed and AES-256-CTR encrypted with HMAC-SHA-256 authentication.  
Keys are derived per-session via HKDF, mixed with a server-side secret.  
Session ID regeneration is configurable per user group (time-based) and fires unconditionally on login, logout, and admin role changes (event-based), with a grace-period handover window for in-flight requests.

---

### Template Engine

Mustache-inspired syntax, compiled to PHP and cached on disk:

```
{{variable}}           escaped output
{{&unescaped}}         raw output
{{#section}}...{{/section}}    loop / truthy block
{{^inverted}}...{{/inverted}}  falsy block
{{>partial}}           include partial
{{>*dynamic}}          dynamic partial (name from variable)
{{*dereference}}       variable dereference
{{!comment}}           ignored at render time
{{=<% %>=}}            custom delimiters
```

Logic stays in PHP. Templates stay clean.

---

### Internationalisation

Every user-facing string in the framework lives in a lang file.  
The `Translator` loads domain files on demand and caches them for the request lifetime:

```php
$this->t->t('user.register.success');
// → "Your account has been created."

$this->t->t('http.status.404.name');
// → "Not Found"
```

---

## Modules

Roughly half the feature surface is optional. Imageboard, chat, webmail, search, the bot trap, the canary, downloads, mirrors, the tip line — each one self-declares in a `src/AstrX/<Module>/module.php` manifest, and core never names any of them.

```bash
php tools/module.php status           # who's alive
php tools/module.php disable chat     # nav entry gone, pages 404, data untouched
php tools/module.php purge chat       # data also gone. no undo. you were warned.
```

Turning one off is not a feature flag wrapped around an `if`. The navbar entry disappears, the routes return the themed 404, and the schema can be dropped. If you don't run an imageboard, you don't ship one.

---

## astrx-suite

The heavy lifting that has no business being in PHP lives next door in `astrx-suite/` — a Cargo workspace of five engines, in Rust, with **zero third-party crates** in the default build. Yes, that includes the HTTP stack, the DEFLATE decoder, the BLAKE2b, the JSON parser and the BitTorrent DHT. What's cargo?

| | |
|---|---|
| `gitweb` | read-only git frontend. no JS, obviously |
| `websearch` | clearnet crawler, BM25 + PageRank, its own query language |
| `onioncrawler` | the same, for `.onion`, through SOCKS |
| `torrentds` | DHT indexer, tracker, search |
| `suitedash` | tells you which of the above is on fire |

They're separate processes speaking JSON, and AstrX renders them as ordinary CMS pages in the site theme — so `/websearch` isn't an iframe, it's a page. Run them, don't run them, run them on another box; the site notices and says so politely.

```bash
cd astrx-suite && bash install_rust_suite.sh   # builds, tests, asserts the zero-dep claim
./target/release/astrx doctor                  # what's broken, in one command
```

Why they live in this repository instead of their own is written up in [`docs/repository-structure.md`](docs/repository-structure.md), because that argument happens about once a year and I'd like to stop re-deriving it from memory.

---

## Other things that exist

- **A JSON API that isn't a second codebase.** The same controller serves HTML and JSON; a `ContextScope` tag on each value decides what crosses over. No parallel API controllers drifting apart at leisure.
- **A compiled build.** `php tools/compile.php` packs `src/` into one file with a real on-demand autoloader, not a `cat`. See `docs/COMPILED_BUILD.md`.
- **An experimental `/js/` runtime**, for the three people who want it. The rest of the site doesn't know it exists, which is rather the point.
- **CSS-only themes.** A directory, some metadata, a stylesheet. Switching one changes zero markup.

---

## Contributing

Contributions are welcome. Before submitting a pull request:

- All code must pass `phpstan --level=10` with no new suppressions.
- No external runtime dependencies may be introduced. That one isn't a joke, despite the tone of this file.
- New user-facing strings must go through the `Translator` — no hardcoded English.
- Rust changes keep `cargo clippy -D warnings` clean in every feature config, and add no crate.

CI runs all of that, plus the installer, plus the tests, plus a check that the Rust engines still resolve to zero third-party crates. It is not there to be pleasant.

---

## Roadmap

- [ ] Template cache invalidation mechanism
- [ ] Advanced template caching (segment-level)
- [ ] REST API layer
- [ ] phpcs / PHP-CS-Fixer integration
- [ ] README screenshot
- [ ] Stop adding modules at 3am
