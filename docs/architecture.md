# Architecture

This page describes the current repository. The proposed core/application separation is described in [repository direction](repository-structure.md); it has not happened merely because it is documented.

## Request lifecycle

`public/index.php` is the normal entry point. `src/bootstrap.php` defines installation paths, loads `src/autoload.php` and constructs `Prelude`. The autoloader can also be included on its own without booting the application.

`Prelude` builds shared services for configuration, diagnostics, translation, module loading and dependency injection. It registers authorization policies and creates `ContentManager`. That manager initializes the current request, resolves its page/controller, collects diagnostics and renders the response.

Pages and localized route identifiers are part of the bundled application's database model. Routing supports rewritten URLs and query-based requests. This is currently an application bootstrap: including it is not an inert way to load a general-purpose library.

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

`Gate` evaluates `Permission` enum values and resource-specific policies. Session records are database-backed; their encrypted contents are authenticated using keys derived from the installation secret. Session record liveness, current account status, current role and account session epoch are different checks.

The normal request pipeline refreshes account status and role and compares epochs. Any separate web entry point handling privileged data must perform equivalent checks rather than trusting a previously stored role. Encryption of a session does not make its privileges current.

## Templates

`TemplateEngine` compiles templates into PHP and caches the generated result. Ordinary `{{value}}` output is escaped; raw output is explicit. Templates are trusted application code, not a sandbox for arbitrary user-supplied programs.

Cache identity includes canonical source path, content, parse mode, PHP-processing mode and the compiler source fingerprint. Immutable compiled files are published atomically; there is no shared mutable template index. Source content is read to compute identity, so preserved timestamps cannot hide an edit. Bundle builds fingerprint the embedded compiler. See [operations](OPERATIONS.md).

## Modules and services

Application modules register through `src/AstrX/<Module>/module.php`. Disabling a module affects its routes/navigation and preserves stored data; it does not physically remove the source files. Purging is a different, destructive operation—read [the module guide](MODULES.md) before using it.

The Rust services have a separate Cargo workspace. PHP bridge clients communicate with them over HTTP. The core PHP package should eventually define only the contracts it needs; engine clients, service configuration and the full deployment belong with the application or optional integration packages.
