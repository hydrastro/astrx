# AstrX

A zero-dependency PHP 8.4 content-management framework with a built-in real-time
chat, designed for privacy-first deployments (Tor hidden services) and a
**no-JavaScript-first** experience — every feature works with scripting disabled.

## Highlights

- **No external dependencies.** Pure PHP 8.4 (+ MariaDB/MySQL and the GD
  extension for image handling). No Composer packages at runtime.
- **No-JS-first.** Pages, forms, moderation, and the live chat all function
  without JavaScript; server-rendered, PRG-based navigation throughout.
- **Native i18n.** All user-facing text comes from translation keys, shipped in
  English and Italian and kept in lockstep by `tools/check_lang_parity.php`.
- **`Result`/`Diagnostic` core.** Operations return a `Result<T>` monad carrying
  typed diagnostics rather than throwing; verified at **PHPStan level 10**.
- **Reflection-autowired DI** (`AstrX\Injector`) and attribute-driven config
  (`#[InjectConfig]`).

## The chat

A real-time, no-JS chat: auto-refreshing message stream, guest and member
posting, private messages, per-user settings, themes, and a full moderation
surface — an in-chat admin panel (sessions, kick/ban via the shared banlist,
broadcast, topic, clean), guest-access modes with optional moderator approval, a
public notes board, managed word/link/nick **filters** with auto-kick, a user
**report → moderator queue**, and EXIF-stripped **image attachments**. Every
limit is admin-configurable.

## Requirements

- PHP **8.4** with the `gd`, `pdo_mysql`, `fileinfo`, and `mbstring` extensions
- MariaDB **10.4+** / MySQL 8+
- A web server pointed at `public/`

## Setup

1. Point your web server's document root at `public/`.
2. Create an empty database named `content_manager`.
3. Visit `public/setup.php` and follow the wizard — it loads the schema from
   `src/setup/tables.sql` and writes the config files under `resources/config/`.
   (To initialise manually, import `src/setup/tables.sql` and configure
   `resources/config/PDO.config.php`.)
4. Ensure the image-upload directory (`upload_dir` in the chat config, default
   `resources/chat_uploads`) and `resources/avatar` are writable by the web
   server.

The database schema is a **single file** — `src/setup/tables.sql`. This project
does not use incremental migration files; schema changes are folded into that
file, and an existing database is updated by applying the delta by hand.

## Layout

```
public/      entry points (index.php router, setup.php, avatar/captcha/… endpoints)
src/AstrX/   the framework (Chat/, Controller/, Auth/, Admin/, Http/, Injector/, …)
resources/   templates (Mustache-style), lang/ (en, it), config/ (*.config.php)
src/setup/   tables.sql — the complete database schema
tools/       maintenance scripts (e.g. check_lang_parity.php)
docs/        API.md, COMPILED_BUILD.md, PROFILING.md
```

## Development

- Static analysis: `php phpstan.phar analyse` (level 10, clean).
- Translation parity: `php tools/check_lang_parity.php` (en/it must match).
