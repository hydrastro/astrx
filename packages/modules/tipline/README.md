# astrx/module-tipline

Encrypted anonymous tip line with an offline-decrypted inbox — the **Tipline** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `tipline` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `admin_tipline`, `tipline`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`
- Suggests `ext-sodium`: Seals the tips (the tip line refuses to run without it)

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-tipline
```

Then list `AstrX\Tipline\TiplineModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/tipline` directory.
