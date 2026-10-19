# astrx/module-suiteadmin

Admin status panel for the astrx-suite engines — the **Suite admin** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `suiteadmin` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `admin_suite`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-suiteadmin
```

Then list `AstrX\SuiteAdmin\SuiteAdminModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/suiteadmin` directory.
