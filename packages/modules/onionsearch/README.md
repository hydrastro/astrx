# astrx/module-onionsearch

Search page backed by the astrx-suite onion crawler — the **Onion search** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `onionsearch` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `onion_search`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-onionsearch
```

Then list `AstrX\OnionSearch\OnionSearchModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/onionsearch` directory.
