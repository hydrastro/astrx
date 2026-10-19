# astrx/cms

The always-on content-management features of [AstrX](https://github.com/hydrastro/astrx):
accounts (registration, login, two-factor, recovery, profiles), the admin panel
(users, grants, pages, navigation, themes, configuration, audit log, panic
lockdown), comments, news, invites and data retention.

It also defines the extension points optional modules answer
(`Retention\RetentionSource`, `Page\SitemapSource`, `User\LoginListener`,
`User\UserHomeSection`, `Admin\AdminDashboardSection`; see
[modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md)).

`resources/` holds the admin and account templates, their catalogs (en, it),
the diagnostic messages and the default configuration. A site overrides any of
them from its own `resources/`.

```sh
composer require astrx/cms
```

Requires `astrx/framework` and `astrx/library`. Development happens in the
[main repository](https://github.com/hydrastro/astrx); this package is
published from its `packages/cms` directory.
