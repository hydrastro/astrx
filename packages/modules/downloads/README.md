# astrx/module-downloads

Signed release downloads page with its admin editor — the **Downloads** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `downloads` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `admin_downloads`, `downloads`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`
- Suggests `ext-sodium`: Signs and verifies the release manifest (the page refuses to run without it)

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-downloads
```

Then list `AstrX\Downloads\DownloadsModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/downloads` directory.
