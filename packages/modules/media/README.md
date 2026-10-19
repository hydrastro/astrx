# astrx/module-media

Uploaded media manager with image validation and re-encoding — the **Media** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `media` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `admin_media`, `media_file`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-media
```

Then list `AstrX\Media\MediaModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/media` directory.
