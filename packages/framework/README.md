# astrx/framework

The foundation of [AstrX](https://github.com/hydrastro/astrx), a framework for
server-rendered sites that work without JavaScript:

- **Result** — typed results and diagnostics (`Result<T>`, `DiagnosticsCollector`).
- **Config** — PHP-array configuration with layered defaults and `#[InjectConfig]` setters.
- **Injector** — constructor injection with explicit shared instances.
- **Http**, **Routing** — requests, responses, uploads and URL generation.
- **Template** — a Mustache-like engine compiled to PHP, with a content-addressed cache.
- **I18n** — translation catalogs and diagnostic rendering.
- **Session**, **Csrf** — encrypted sessions, POST/redirect/GET, CSRF tokens.
- **Module**, **Kernel** — the module contract, extension points and controllers.
- **ErrorHandler**, **Pagination**, **Support**.

It depends on no other package. `resources/` holds its diagnostic messages
(en, it) and its default configuration.

```sh
composer require astrx/framework
```

`examples/minimal` in the main repository is an application built on this
package alone. The [architecture guide](https://github.com/hydrastro/astrx/blob/master/docs/architecture.md)
describes the layers.

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/framework` directory.
