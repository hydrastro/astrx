# Modules

AstrX ships several features — the imageboard, the chat, the bot-trap honeypot,
site-wide search, the webmail client — as **optional modules**. A deployment can
turn any of them off (its navigation disappears, its pages return the themed 404,
and its schema can be dropped) without touching core, and you can add your own
module the same way.

Core code never names a module. Everything below is data- and manifest-driven:
`ContentManager`, `DefaultTemplateContext` and `NavbarHandler` ask
`AstrX\Module\ModuleRegistry` what the *enabled* modules contribute.

A module goes through three separate states:

| State | Decided by | Effect |
|---|---|---|
| **installed** | listed in `ApplicationModules::INSTALLED` (`src/AstrX/ApplicationModules.php`) | the application knows it: its controllers resolve, its permissions show in the grants editor |
| **enabled** | `true` in `resources/config/Modules.config.php` | its pages, nav, guards and policies are live |
| **migrated** | `tools/install.php` / `tools/module.php` | its tables exist |

Nothing scans directories: a module's code being present does nothing until it
is listed, and a listed module with no flag is **off**.

## Turning modules on and off

The switchboard is `resources/config/Modules.config.php`: one boolean per module
key. A module with no entry is **off**, and `tools/check_modules.php` fails the
build when an installed module has no entry, so the file always states every
module explicitly:

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

A module is these things, none of which live in core:

1. **A module class** — `src/AstrX/<Module>/<Module>Module.php`, implementing
   `AstrX\Module\Module` — whose `manifest()` declares the module, listed in
   `ApplicationModules::INSTALLED`.
2. **Page ownership** — every page carries a `page.module` tag; the core
   `ModulePageGuard` 404s a page whose module is off, and `NavbarHandler` drops
   nav entries pointing at it. Pages are tagged by a migration.
3. **(Optional) a nav contributor, page guards, resource policies and page
   controllers** — classes the manifest points at, all inside the module's own
   namespace (`AstrX\<Module>\…`).
4. **(Optional) its resources** — templates, translations and default config
   in `src/AstrX/<Module>/resources/`, layered under the site's own
   `resources/` (see [Resources](#resources)).
5. **(Optional) a teardown file** — `src/setup/modules/<key>.down.sql` — that
   `purge` runs.

### The manifest

```php
<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Module\Module;

final class ImageboardModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'imageboard',                 // on/off key + page.module tag; [a-z][a-z0-9_]*
            'name'         => 'Imageboard',                 // display name
            'version'      => '1.0.0',
            'nav'          => \AstrX\Imageboard\ImageboardNavContributor::class, // or null
            'nav_defaults' => ['board_nav' => false],       // vars merged when the module is OFF
            'guards'       => [],                            // list of PageGuard classes
            'policies'     => [],                            // resource class => policy class (Gate)
            'permissions'  => [\AstrX\Imageboard\ImageboardPermission::class], // permission enums it declares
            'controllers'  => [                              // page file_name => controller class
                'board'     => \AstrX\Imageboard\Controller\BoardController::class,
                'board_mod' => \AstrX\Imageboard\Controller\BoardModController::class,
                // …
            ],
            'teardown'     => 'imageboard.down.sql',         // file in src/setup/modules/, or null
            'resources'    => 'resources',                   // dir next to this class, or omit
        ];
    }
}
```

`manifest()` must be cheap and side-effect free: it is read for disabled
modules too (the controller map and the grants editor need their declarations).
`ModuleRegistry` receives the installed list from `Prelude` and builds each
manifest once per request. A listed class that does not implement `Module`, an
empty key, or two modules sharing a key fail loudly instead of being skipped.

### Resources

A module keeps its templates, translations and default configuration in its
own directory, declared by `'resources'` (relative to the module class):

```
src/AstrX/Imageboard/resources/
├── template/                 board.html, admin/admin_boards.html, partials/board_nav.html …
├── lang/
│   ├── en/                   Imageboard.en.php, pages.en.php, Navbar.en.php, Admin.en.php,
│   │                         Diagnostics/imageboard.en.php
│   └── it/                   the same files, same keys
└── config/                   Imageboard.config.php
```

It has the same layout as the site's `resources/`, and the two are layered:

| Kind | Lookup | Writes |
|------|--------|--------|
| Templates | the site's `resources/template/<name>.html` if it exists, else the first module that ships `<name>` | never |
| Catalogs (`lang/`) | each module's file, then the site's: the site overrides a module **key by key** | the language editor writes the site's file, and for a domain a module ships only the keys the site changed |
| Diagnostics catalogs | the same, file by file under `Diagnostics/` | never |
| Config | the module's `<Domain>.config.php` is the default, the site's file overrides it **key by key** | admin config pages write the site's file |

So a site customises a module without touching it: put a template with the
same name in `resources/template/`, or just the keys you want to change in
`resources/lang/<locale>/<Domain>.<locale>.php` or
`resources/config/<Domain>.config.php`. A module's directory is read-only —
once modules are Composer packages it lives under `vendor/`.

A module may contribute keys to a domain it shares with core: its page slugs
and titles go in a partial `pages.<locale>.php`, its nav labels in
`Navbar.<locale>.php`, its admin strings in `Admin.<locale>.php`. Those files
hold only the module's own keys and are loaded underneath the site's catalog of
the same name. A key that core code also uses stays in the site's catalog.

Every installed module contributes its resources whether it is enabled or not
(a disabled module's strings still label its grants in the grants editor; its
templates cost nothing while its pages 404). `ModuleRegistry::resourceRootsFor()`
lists the directories without booting the application, which is how the tools
(`check_lang_parity`, `check_diagnostics`, `check_config`, the template cache
warmer and the compiled build) find them.

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

use AstrX\Page\Page;
use AstrX\Page\PageGuard;

final class BotTrapPageGuard implements PageGuard
{
    public function shouldSwapToError(Page $page): bool
    {
        return $page->urlId === 'WORDING_TRAP' && !$this->config->enabled();
    }
}
```

### Controllers

A module's controllers live in `src/AstrX/<Module>/Controller/` and are listed
in the manifest's `controllers` key: page `file_name` => class.
`Site\ControllerMap` resolves a page through that map (plus its `CORE` list
for always-on features and the site's own pages). Lookup ignores case and
underscores, exactly as the old `AstrX\Controller\<Studly>Controller`
derivation did, so existing page rows keep resolving. A page whose
`file_name` has no entry has no controller. Nothing derives a class name
from a database value any more.

Disabled modules' controllers stay in the map: `ModulePageGuard` 404s their
pages before dispatch, and the entry gate still reads `#[RequiresPermission]`
from the class.

A feature controller extends `Kernel\AbstractController` and writes page
variables through `Template\TemplateVars` (`set()`, `setShared()`), **not**
through the application's concrete `Site\DefaultTemplateContext`. That keeps
the module free of application-layer imports, which
`tools/check_architecture.php` enforces.

### Permissions

A module declares its permissions as a string-backed enum in its own namespace
that implements `Auth\Permission`, and lists it in the manifest:

```php
enum ImageboardPermission: string implements Permission
{
    use PermissionId;

    case VIEW     = 'board.view';
    case MODERATE = 'board.moderate';
}
```

The values are what `Auth.config.php` grants to roles. Wildcards like
`'board.*'` match by prefix. Every module's permissions are listed in the
admin grants editor, including disabled modules', because saving the editor
rebuilds each role's list from what it shows.

### Policies

If the module needs resource-level authorization (e.g. "a moderator may not
delete an admin's message"), put the policy class in the module and declare it:

```php
'policies' => [\AstrX\Chat\ChatMessageResource::class => \AstrX\Chat\ChatPolicy::class],
```

It is registered with the `Gate` only while the module is enabled. Policies
type-hint `Auth\Identity`, not the account session; use
`User\UserGroup::ofIdentity()` when comparing roles.

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

scaffolds `src/AstrX/Forum/ForumModule.php` (+ a `ForumNavContributor` and
`ForumPageGuard` if you passed `--nav`/`--guard`), its controller, a
`resources/` directory with the page's template and its slug and title in both
locales, and `src/setup/modules/forum.down.sql`, then prints the remaining
steps:

1. Install it: add `Forum\ForumModule::class` to `ApplicationModules::INSTALLED`
   and `'forum' => true` to `resources/config/Modules.config.php` (no flag = off).
2. Tag its pages in a migration: `UPDATE page SET module='forum' WHERE file_name LIKE 'forum%';`
3. Fill in the teardown `DROP TABLE`s and the nav/guard stubs.
4. Add `Forum` to the `feature` layer in `tools/check_architecture.php`.
5. `php tools/check_modules.php && php tools/check_architecture.php`.

The scaffold includes `Controller/ForumController.php`, already mapped to the
`forum` page in the manifest's `controllers`. Further templates, catalogs and
config go in the module's `resources/` ([Resources](#resources)); a class's
config section and its translation domain are still found by naming convention
(`Forum.config.php`, `lang/{en,it}/Forum.*.php`).

## Verifying

Four gates, run in CI (`.github/workflows/ci.yml`) and locally:

```
php phpstan.phar analyse -l 10 --no-progress   # types (level 10)
php tools/check_lang_parity.php                # en/it language parity
php tools/check_modules.php                     # every manifest wired correctly
php tools/check_architecture.php                # layered, acyclic namespaces
```

`check_modules.php` verifies each manifest loads, has a `key`/`version`, its nav
contributor implements `NavContributor`, its guards implement `PageGuard`, its
policies implement `PolicyInterface`, its controllers implement
`Kernel\Controller` and live in the module's namespace, its teardown file
exists, and its `resources` directory (if declared) is next to it. CI additionally installs against MariaDB and boots the app
with each module off (and with all modules off) to prove they are independently
optional.
