# Modules

AstrX ships several features — the imageboard, the chat, the bot-trap honeypot,
site-wide search, the webmail client — as **optional modules**. A deployment can
turn any of them off (its navigation disappears, its pages return the themed 404,
and its schema can be dropped) without touching core, and you can add your own
module the same way.

Core code never names a module. Everything below is data- and manifest-driven:
`ContentManager`, `DefaultTemplateContext` and `NavbarHandler` ask
`AstrX\Module\ModuleRegistry` what the *enabled* modules contribute.

## Turning modules on and off

The switchboard is `resources/config/Modules.config.php` — one boolean per module
key (default **on**; unlisted modules also default on):

```php
return ['Modules' => [
    'imageboard' => true,
    'chat'       => false,   // ← off: no nav entry, its pages 404
    'bottrap'    => true,
    'search'     => true,
    'webmail'    => true,
]];
```

Or use the CLI (it edits that file for you, and can drop a module's schema):

```
php tools/module.php status            # each module: enabled/disabled + page count
php tools/module.php disable chat       # nav drops, pages 404 — reversible, data kept
php tools/module.php enable  chat       # back on
php tools/module.php purge   chat       # disable + DROP its tables + DELETE its pages (destructive)
```

Disabling is reversible and touches no data. `purge` is destructive and one-way —
reinstall the schema (`tools/install.php`) to bring a purged module back.

## How a module is wired

A module is four things, none of which live in core:

1. **A manifest** — `src/AstrX/<Module>/module.php` — that declares the module.
2. **Page ownership** — every page carries a `page.module` tag; the core
   `ModulePageGuard` 404s a page whose module is off, and `NavbarHandler` drops
   nav entries pointing at it. Pages are tagged by a migration.
3. **(Optional) a nav contributor and/or page guards** — small classes the
   manifest points at.
4. **(Optional) a teardown file** — `src/setup/modules/<key>.down.sql` — that
   `purge` runs.

### The manifest

```php
<?php
declare(strict_types=1);

return [
    'key'          => 'imageboard',                 // on/off key + page.module tag; [a-z][a-z0-9_]*
    'name'         => 'Imageboard',                 // display name
    'version'      => '1.0.0',
    'nav'          => \AstrX\Imageboard\ImageboardNavContributor::class, // or null
    'nav_defaults' => ['board_nav' => false],       // vars merged when the module is OFF
    'guards'       => [],                            // list of PageGuard classes
    'teardown'     => 'imageboard.down.sql',         // file in src/setup/modules/, or null
];
```

`ModuleRegistry` discovers every `src/AstrX/*/module.php` at runtime (cached per
request), so a module exists the moment its manifest does.

### Nav contributor (optional)

Only needed when a module adds a **section navbar or footer hook** to the shell
(the imageboard/chat sub-navs, the bot-trap footer link). A module whose only
navigation is a normal main-nav entry needs none — that entry is a DB
`navbar_entry` row, and `NavbarHandler` already drops it via `page.module`.

```php
namespace AstrX\Imageboard;

use AstrX\Module\NavContributor;

final class ImageboardNavContributor implements NavContributor
{
    /** @return array<string,mixed> */
    public function vars(): array
    {
        return ['board_nav' => 'partials/board_nav']; // a partial slot; default.html renders {{> board_nav}}
    }
}
```

When the module is **off**, the registry merges the manifest's `nav_defaults`
instead (e.g. `['board_nav' => false]`) — a `false` slot makes `{{> board_nav}}`
render nothing, with no "undefined variable" diagnostic.

### Page guard (optional)

The core `ModulePageGuard` already hides a disabled module's pages. Add your own
`PageGuard` only for finer, feature-level gating (the bot-trap uses one to look
missing while the trap *feature* is off even though the module is on):

```php
namespace AstrX\BotTrap;

use AstrX\Module\PageGuard;
use AstrX\Page\Page;

final class BotTrapPageGuard implements PageGuard
{
    public function shouldSwapToError(Page $page): bool
    {
        return $page->urlId === 'WORDING_TRAP' && !$this->config->enabled();
    }
}
```

### Page ownership (tagging)

Add the `page.module` tag for your module's pages in a migration
(`src/setup/migrate_*.sql`, applied by `tools/install.php`):

```sql
UPDATE `page` SET `module` = 'imageboard'
 WHERE `module` = '' AND `file_name` LIKE 'board%';
```

`page.module = ''` means core/always-on. The `page.module` column and the
`resolved_page` view are added by `migrate_module_page_ownership.sql`.

### Teardown (optional)

`src/setup/modules/<key>.down.sql` — what `module.php purge <key>` runs. Deleting
the pages cascades to their metadata and navbar rows (via existing `ON DELETE
CASCADE` FKs); drop the module's own tables and sweep orphaned navbar entries:

```sql
DELETE FROM `page` WHERE `module` = 'imageboard';   -- cascades to meta/robots/closure/navbar_internal
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `board_post`; DROP TABLE IF EXISTS `board_thread`; DROP TABLE IF EXISTS `board`;
SET FOREIGN_KEY_CHECKS = 1;
DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
```

## Adding a module

```
php tools/make_module.php forum --nav --guard
```

scaffolds `src/AstrX/Forum/module.php` (+ a `ForumNavContributor` and
`ForumPageGuard` if you passed `--nav`/`--guard`) and
`src/setup/modules/forum.down.sql`, then prints the remaining steps:

1. Add `'forum' => true` to `resources/config/Modules.config.php` (optional —
   unlisted defaults on).
2. Tag its pages in a migration: `UPDATE page SET module='forum' WHERE file_name LIKE 'forum%';`
3. Fill in the teardown `DROP TABLE`s and the nav/guard stubs.
4. `php tools/check_modules.php`.

The module's controllers, services, templates, config and language files follow
AstrX's normal conventions (a page row with `file_name` `forum` resolves to
`AstrX\Controller\ForumController`; `Forum.config.php` and
`resources/lang/{en,it}/Forum.*.php` auto-load by naming convention).

## Verifying

Three gates, run in CI (`.github/workflows/ci.yml`) and locally:

```
php phpstan.phar analyse -l 10 --no-progress   # types (level 10)
php tools/check_lang_parity.php                # en/it language parity
php tools/check_modules.php                     # every manifest wired correctly
```

`check_modules.php` verifies each manifest loads, has a `key`/`version`, its nav
contributor implements `NavContributor`, its guards implement `PageGuard`, and its
teardown file exists. CI additionally installs against MariaDB and boots the app
with each module off (and with all modules off) to prove they are independently
optional.
