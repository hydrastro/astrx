# astrx/module-chat

No-JavaScript chat: rooms, private messages, attachments, moderation — the **Chat** module of [AstrX](https://github.com/hydrastro/astrx).

- Module key: `chat` (switch it on in the site's `resources/config/Modules.config.php`)
- Pages: `admin_chat_filters`, `admin_config_chat`, `chat_admin`, `chat`, `chat_file`, `chat_help`, `chat_login`, `chat_pm`, `chat_settings`, `chat_stream`, `chat_users`, `chat_wait`
- Requires: `astrx/cms`, `astrx/framework`, `astrx/library`

It ships its own templates, catalogs (en, it), default configuration and SQL in
`resources/`; the site overrides any of them from its own `resources/`.

```sh
composer require astrx/module-chat
```

Then list `AstrX\Chat\ChatModule::class` in the
site's `ApplicationModules::INSTALLED` and run the installer to create its
tables. See [modules](https://github.com/hydrastro/astrx/blob/master/docs/MODULES.md).

Development happens in the [main repository](https://github.com/hydrastro/astrx);
this package is published from its `packages/modules/chat` directory.
