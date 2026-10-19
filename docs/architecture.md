# Architecture

This page describes the current repository. The proposed core/application separation is described in [repository direction](repository-structure.md); it has not happened merely because it is documented.

## Request lifecycle

`public/index.php` is the normal entry point. `src/bootstrap.php` defines installation paths (`src/paths.php`), loads `src/autoload.php` and constructs `Prelude`. The paths and the autoloader can also be included on their own without booting the application: `src/classloader.php` loads classes from the map in `src/sources.php`, and `src/support.php` loads the `AstrX\Support` functions.

`ApplicationContainer::build()` wires the shared services: configuration (the site's files over the installed modules' defaults), diagnostics, translation, module loading, dependency injection and the installed-module registry. `Prelude` builds it for a web request; a CLI tool (`tools/retention.php`, `tools/search_index.php`) builds the same container, so it sees the configuration, translations and module extensions the site does. `Prelude` then binds the account session as the request's `Auth\Identity` and `Theme\ThemePreference`, registers authorization policies (`ApplicationPolicies` plus each enabled module's manifest) and creates `ContentManager`. That manager initializes the current request, resolves its page, picks the controller from `Site\ControllerMap`, collects diagnostics and renders the response.

Pages and localized route identifiers are part of the bundled application's database model. Routing supports rewritten URLs and query-based requests. This is currently an application bootstrap: including it is not an inert way to load a general-purpose library.

## Layers

Every top-level AstrX namespace belongs to one layer. A namespace may depend on its own layer or a lower one, and the namespace graph has no cycles. `tools/check_architecture.php` enforces both in CI, with the offending `file:line`. The layers are the package boundaries:

| Layer | Namespaces | Package |
|---|---|---|
| foundation | `Result`, `Support` | `astrx/framework` (`packages/framework`) |
| component | `Config`, `Injector`, `Http`, `Routing`, `ErrorHandler`, `I18n`, `Template`, `Session`, `Csrf`, `Module`, `Pagination`, `Kernel` | `astrx/framework` |
| library | `Database`, `Image`, `Auth`, `Api`, `Captcha`, `Identicon`, `Mail` | `astrx/library` (`packages/library`) |
| feature | `User`, `Admin`, `Page`, `Navbar`, `Theme`, `Comment`, `News`, `Invite`, `Retention` | `astrx/cms` (`packages/cms`) |
| feature | `Chat`, `Imageboard`, `Content`, `Search`, `Webmail`, … (every optional module) | one `astrx/module-<key>` each (`packages/modules/<key>`) |
| application | `Site`, `ContentManager`, `Prelude`, `ApplicationContainer`, `ApplicationPolicies`, `ApplicationPermissions`, `ApplicationModules` | this website (`src/AstrX`, the root `composer.json`) |

## Packages

Each package has its own `composer.json` naming the packages it requires, and `tools/packages.php` checks in CI that this is exactly the set of packages its code uses — so each one can be installed without the others above it: `astrx/framework` alone runs `examples/minimal` (`tests/framework_isolation_test.php` serves it from a directory holding nothing else), and the site runs with no module package present at all. Modules depend on `astrx/framework`, `astrx/cms` and usually `astrx/library`; no package depends on the application.

The root `composer.json` reaches the packages through path repositories (`packages/*`, `packages/modules/*`), so `composer install` links them into `vendor/` for anyone who consumes them through Composer. The site does not need Composer at runtime: `src/classloader.php` reads `src/sources.php`, which `php tools/packages.php --write` generates from the packages' PSR-4 maps (CI fails when it is stale). The packages have no tags and no license yet; both come before any of them is published separately.

Lower layers reach upward only through narrow contracts that the application binds:

- `Auth\Identity`: the caller as authorization sees it (`isLoggedIn`, `userId`, `roleName`). `Gate`, policies and diagnostic visibility use it. `User\UserSession` implements it.
- `Theme\ThemePreference`: the visitor's own theme choice. `UserSession` implements it, and without it `ThemeService` uses the global theme.
- `Template\TemplateVars`: the write side of a page's variables (`set`, `setShared`, `ContextScope`). Feature controllers use it and never touch `Site\DefaultTemplateContext`.
- `Result\DiagnosticFilter`: which diagnostics a viewer may see. `I18n\DiagnosticRenderer` uses it, and `Admin\DiagnosticVisibilityChecker` implements it.

Each feature owns its controllers (`<Feature>/Controller/` in its package), its policies and its manifest. `Site` holds this website's own pages and its page context.

Core never names an optional module: only `ApplicationModules`, the list of installed modules, does. Where core needs something from modules it owns an interface — an extension point such as `Retention\RetentionSource`, `Page\SitemapSource` or `User\LoginListener` — and asks `Module\ModuleRegistry::extensions()` which modules answer it; a module's partials join the page layout through its manifest's `layout` slots. See [Modules](MODULES.md).

## Results and diagnostics

Recoverable operations use `AstrX\Result\Result`. The value and the diagnostics are separate: an operation can produce useful diagnostics along with a successful value. `unwrap()` expects a successful result; callers must handle failure before unwrapping unless success is already established by their contract.

```php
use AstrX\Result\Result;

$result = Result::ok(4)
    ->map(static fn (int $value): int => $value * 2)
    ->andThen(static fn (int $value): Result => Result::ok($value + 1));

if ($result->isOk()) {
    $value = $result->unwrap(); // 9
}
```

`andThen()` composes an operation returning another `Result`. `drainTo($collector)` forwards diagnostics to a sink. Diagnostic classes carry identifiers and typed data; language catalogs render them for the selected locale. Tests check both locale parity and that emitted diagnostic identifiers exist.

Do not turn an unavailable security decision into a permissive default. A result representing “could not read the account's TOTP state” is different from “the account disabled TOTP”. The same distinction matters for current account privileges and persistence acknowledgements.

## Injection and configuration

`Injector` resolves dependencies and holds explicitly registered instances. `getClass()` returns a `Result`; it is not a bare-object `get()` API. `ModuleLoader` participates in configuration injection through `#[InjectConfig]` setters.

Configuration files under `resources/config/` return PHP arrays. The relevant section names and injectable keys are part of the current application contract. Run `tools/check_config.php` when changing them. A field saved by the administrator UI must also have an effective consumer.

The handwritten composition root is useful for making application-wide decisions explicit. A future reusable package should preserve that explicit construction while accepting paths and adapters supplied by its consumer.

## Authorization and sessions

`Gate` evaluates permissions against the caller's `Identity`. `Auth\Permission` is an interface: each feature declares its own string-backed enum (`Chat\ChatPermission`, `Admin\AdminPermission`, …), and `ApplicationPermissions` plus the module manifests collect them into `Auth\PermissionRegistry` for the grants editor. Resource-specific policies live with the feature they protect (`User\UserPolicy`, `Comment\CommentPolicy`, `News\NewsPolicy`, `Chat\ChatPolicy`). `ApplicationPolicies` registers the always-on ones; an optional module declares its own in its manifest's `policies` key, and they are registered only while it is enabled. A resource-scoped check that no policy rules on is denied. Session records are database-backed; their encrypted contents are authenticated using keys derived from the installation secret. Session record liveness, current account status, current role and account session epoch are different checks.

The normal request pipeline refreshes account status and role and compares epochs. Any separate web entry point handling privileged data must perform equivalent checks rather than trusting a previously stored role. Encryption of a session does not make its privileges current.

## Templates

`TemplateEngine` compiles templates into PHP and caches the generated result. Ordinary `{{value}}` output is escaped; raw output is explicit. Templates are trusted application code, not a sandbox for arbitrary user-supplied programs.

Cache identity includes canonical source path, content, parse mode, PHP-processing mode and the compiler source fingerprint. Immutable compiled files are published atomically; there is no shared mutable template index. Source content is read to compute identity, so preserved timestamps cannot hide an edit. Bundle builds fingerprint the embedded compiler. See [operations](OPERATIONS.md).

## Modules and services

Application modules are classes implementing `Module\Module`, installed by listing them in `ApplicationModules::INSTALLED`; a listed module with no `true` flag in `Modules.config.php` is off. See [the module guide](MODULES.md). Disabling a module affects its routes/navigation and preserves stored data; it does not physically remove the source files. Purging is a different, destructive operation—read [the module guide](MODULES.md) before using it.

The Rust services have a separate Cargo workspace. PHP bridge clients communicate with them over HTTP. The core PHP package should eventually define only the contracts it needs; engine clients, service configuration and the full deployment belong with the application or optional integration packages.
