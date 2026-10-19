# astrx/module-gitbrowse

Link-through page to a standalone gitweb instance — the **Git browser** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `gitbrowse` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `git_browse`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-gitbrowse
```

Then list `AstrX\GitBrowse\GitBrowseModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/gitbrowse` directory.
