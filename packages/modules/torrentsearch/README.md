# astrx/module-torrentsearch

Search page backed by the astrx-suite torrent index — the **Torrent search** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `torrentsearch` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `torrent_search`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-torrentsearch
```

Then list `AstrX\TorrentSearch\TorrentSearchModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/torrentsearch` directory.
