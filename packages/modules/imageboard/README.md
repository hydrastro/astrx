# astrx/module-imageboard

Imageboard: boards, threads, image posts, moderation and feeds — the **Imageboard** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `imageboard` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `admin_boards`, `admin_config_imageboard`, `board`, `board_feed`, `board_file`, `board_mod`, `board_overboard`, `board_search`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-imageboard
```

Then list `AstrX\Imageboard\ImageboardModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/imageboard` directory.
