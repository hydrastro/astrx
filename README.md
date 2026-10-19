# AstrX

A PHP framework and application for server-rendered websites.

AstrX grew out of the sites I wanted to build. It has a template engine, typed results and diagnostics, authorization policies, and modules for features such as chat, search and webmail. The main interface works without JavaScript. The PHP runtime uses no external Composer packages.

The repository currently includes both the framework components and my application. The PHP core is not yet distributed as a separate library. The Rust services live in their own workspace under [`astrx-suite/`](astrx-suite/README.md) and communicate with the application over HTTP.

## Getting started

```sh
git clone https://github.com/hydrastro/astrx.git
cd astrx
php tools/install.php --help
```

The bundled application requires PHP 8.4 or newer, MariaDB/MySQL, and PHP extensions including PDO MySQL, OpenSSL, GD and mbstring. Optional features have additional requirements. Set your web server's document root to `public/`.

Follow [getting started](docs/getting-started.md) for installation, writable directories and Docker deployment. Start with a local development installation.

## Use individual components

```php
require 'src/autoload.php';

$result = AstrX\Result\Result::ok(21)
    ->map(static fn (int $n): int => $n * 2);
```

This loader starts no application, session or database connection. Run
`php examples/components.php` for a small example.

The code is organised as Composer packages in `packages/`: `astrx/framework`
(results, configuration, injection, HTTP, routing, templates, translation,
sessions, the module contract), `astrx/library` (database, authorization,
captcha, images, mail), `astrx/cms` (accounts, admin, pages, navigation,
comments, news) and one `astrx/module-<key>` per optional module. Each requires
only the packages it uses; `astrx/framework` runs an application on its own
(`examples/minimal`). They are not published or tagged yet — see
[architecture](docs/architecture.md#packages) and
[repository direction](docs/repository-structure.md).

## How it works

- Controllers handle requests and return data to server-rendered templates.
- `Result<T>` carries successful values or failures. Typed diagnostics can be collected, translated and rendered consistently.
- `Gate` checks named permissions and delegates resource-specific decisions to policies.
- Configuration is stored in PHP arrays. Injectable settings are declared on setters with attributes.
- Module manifests register application features. Disabling a module removes its exposed functionality while retaining its source and stored data.

The [architecture guide](docs/architecture.md) follows a request through the current application. It also explains which parts still need to be separated before the core is convenient to use on its own.

## Modules and Rust services

The application includes content pages, an imageboard, chat, media, webmail and search integrations. See [modules](docs/MODULES.md) for their lifecycle and configuration.

The Rust workspace contains a Git browser, onion and web search services, torrent discovery/search, and an operations dashboard. The PHP application uses HTTP clients to integrate them; they can also be built independently. Network-enabled Rust builds have external crate dependencies.

## Documentation

- [Installation](docs/getting-started.md)
- [Architecture](docs/architecture.md)
- [Modules](docs/MODULES.md)
- [JSON API](docs/API.md)
- [Operations and storage](docs/OPERATIONS.md)
- [Repository direction](docs/repository-structure.md)
- [Profiling](docs/PROFILING.md)
- [Review fixes and upgrade notes](docs/REVIEW-FIXES.md)

## Contributing

Start with a reproducible bug, a small example, or a focused change. [CONTRIBUTING.md](CONTRIBUTING.md) explains the layout, the rules the checks enforce and what a pull request needs; report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Project status

The API and repository layout are still developing. There are no comparative performance results attached to this README, and the project should not be described as independently security-audited.

The PHP project's license needs an explicit top-level license file. Rust manifests currently declare MIT. Clarify the repository-wide licensing and notices before distributing a reusable core release.
