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

1. **A Composer package** — `packages/modules/<key>/` (`astrx/module-<key>`,
   type `astrx-module`), whose root is the namespace `AstrX\<Module>` — holding
   **a module class**, `<Module>Module.php`, implementing `AstrX\Module\Module`,
   whose `manifest()` declares the module, listed in
   `ApplicationModules::INSTALLED` (see [Packages](#packages)).
2. **Page ownership** — every page carries a `page.module` tag; the core
   `ModulePageGuard` 404s a page whose module is off, and `NavbarHandler` drops
   nav entries pointing at it. Pages are tagged by a migration.
3. **(Optional) a nav contributor, page guards, resource policies and page
   controllers** — classes the manifest points at, all inside the module's own
   namespace (`AstrX\<Module>\…`).
4. **(Optional) its resources** — templates, translations, default config and
   SQL in the package's `resources/`, layered under the site's own
   `resources/` (see [Resources](#resources) and [Database](#database)).
5. **(Optional) a teardown file** — `resources/sql/<key>.down.sql` — that
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
            'nav'          => null,                          // a NavContributor class, or null
            'nav_defaults' => [],                            // its vars' values when the module is OFF
            'guards'       => [],                            // list of PageGuard classes
            'policies'     => [],                            // resource class => policy class (Gate)
            'permissions'  => [\AstrX\Imageboard\ImageboardPermission::class], // permission enums it declares
            'controllers'  => [                              // page file_name => controller class
                'board'     => \AstrX\Imageboard\Controller\BoardController::class,
                'board_mod' => \AstrX\Imageboard\Controller\BoardModController::class,
                // …
            ],
            'teardown'     => 'imageboard.down.sql',         // file in resources/sql/, or null
            'resources'    => 'resources',                   // dir next to this class, or omit
            'extensions'   => [                              // core extension point => its classes
                \AstrX\Retention\RetentionSource::class => [\AstrX\Imageboard\ImageboardRetention::class],
            ],
            'layout'       => ['nav' => ['partials/board_nav']], // layout slot => partials
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
packages/modules/imageboard/resources/
├── template/                 board.html, admin/admin_boards.html, partials/board_nav.html …
├── lang/
│   ├── en/                   Imageboard.en.php, pages.en.php, Navbar.en.php, Admin.en.php,
│   │                         Diagnostics/imageboard.en.php
│   └── it/                   the same files, same keys
├── config/                   Imageboard.config.php
└── sql/                      schema/0270_imageboard_tables.sql …, migrate_zzzzz_imageboard_pages.sql,
                              imageboard.down.sql   (see Database)
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
installed with Composer it lives under `vendor/`.

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

### Extension points (optional)

Core never names a module. Where a core feature needs something from modules,
it owns an interface — an *extension point* — and asks the registry which
modules answer it. A module answers by listing classes under that interface in
its manifest's `extensions`; they are built through the injector, so they take
the module's own services as constructor arguments.

| Extension point | Asked by | Answer |
|-----------------|----------|--------|
| `Retention\RetentionSource` | the retention engine, the admin Retention page, `tools/retention.php` | the tables it prunes (`RetentionTarget`: key, table, column, age or expiry) and the upload directories whose unreferenced files it reaps (`UploadStore`) |
| `Page\SitemapSource` | `sitemap.xml` | the public pages it serves |
| `User\LoginListener` | the login forms | told the password once it is accepted (the webmail keeps it for the IMAP login) |
| `User\UserHomeSection` | the user home page | tiles: page url_id => label key |
| `Admin\AdminDashboardSection` | the admin dashboard | tiles: page url_id => label key |

```php
'extensions' => [
    \AstrX\Retention\RetentionSource::class => [\AstrX\Chat\ChatRetention::class],
],
```

Only enabled modules answer (`ModuleRegistry::extensions()`), except where the
question is about data that exists whether or not the module is switched on:
retention asks every installed module (`installedToo: true`), so switching a
module off never stops its data expiring. Labels such as a retention target's
`admin.retention.label.<key>` or a dashboard tile's text go in the module's own
`Admin`/`User` catalog.

A new extension point is an interface in the core feature that asks, plus one
`$registry->extensions(TheInterface::class)` call there. Keep it small: one
question, plain values back.

### Layout slots and nav contributor (optional)

A module that adds something to **every page's chrome** — a section navbar
under the header, a hidden footer link — names partials for the layout's slots
in `layout`:

```php
'layout' => ['nav' => ['partials/board_nav']],     // the imageboard's board navbars
'layout' => ['footer' => ['partials/trap_link']],  // the bot trap's honeypot link
```

`default.html` renders each slot's list (`{{#nav_partials}}{{> .}}{{/nav_partials}}`,
`footer_partials`), enabled modules only, in module-key order. The slots are
`nav` and `footer` (`ModuleRegistry::LAYOUT_SLOTS`). A partial decides for
itself whether it shows on the current page: the board navbars render only when
a board controller set `board_nav_show`.

When a partial needs values on *every* page — the trap link's URL — the module
also has a nav contributor (`nav`), whose `vars()` are merged into every page's
context while the module is enabled; `nav_defaults` are merged instead when it
is off.

```php
final class BotTrapNavContributor implements NavContributor
{
    /** @return array<string,mixed> */
    public function vars(): array
    {
        return ['trap_enabled' => true, 'trap_url' => '…', 'trap_link_text' => '…'];
    }
}
```

A module whose only navigation is a main-nav entry needs neither — that entry
is a DB `navbar_entry` row, and `NavbarHandler` drops it when the module is off.

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

A module's controllers live in its package's `Controller/` and are listed
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

### Database

A module ships its SQL in `resources/sql/`; the installers
(`tools/install.php`, `public/setup.php`) apply core's and every installed
module's together (`AstrX\Database\SchemaFiles` decides the order):

| File | Applied | Notes |
|------|---------|-------|
| `schema/NNNN_<key>_<what>.sql` | on **every** install run, after core's and the other modules' pieces in file-name order | the base schema: write it to be re-applied (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`). The number places it among core's pieces (`0000_core_base.sql` first); a new module rarely needs one |
| `migrate_<name>.sql` | **once**, recorded by name + checksum in `migration` | merged with core's and sorted by file name. A shipped migration must never be edited or renamed — the next upgrade would abort on the checksum. Fix forward with a new one |
| `<key>.down.sql` (manifest `teardown`) | by `tools/module.php purge <key>` | drop the module's tables and pages |

A file name may exist only once across core and the modules
(`tools/check_modules.php` checks it). A new module normally ships its tables
and pages as migrations; nothing about it goes in core's `src/setup/`.

**Page ownership.** Tag every page the module creates with `page.module`, in
its own SQL (`page.module = ''` means core, always on):

```sql
UPDATE `page` SET `module` = 'forum' WHERE `module` = '' AND `file_name` LIKE 'forum%';
```

Untagged, a page counts as core and is served even while the module is off.

**Nav entries.** Insert them the way the existing modules do — look the page
up by `url_id`, reuse an existing entry, and only allocate a new id
(`navbar_entry_ids`) when there is none — into the public navbar's
alphabetical pin (`sort_mode = 0`) and the admin navbar's main menu pin. The
modules' `migrate_zzzzz_<key>_pages.sql` show the pattern, including moving an
entry that an older migration put in the first (Home/Dashboard) pin.

**Installing later, purging, restoring.** A module can be added to an existing
site: its schema pieces and migrations run on the next
`php tools/install.php --schema-only`. `tools/module.php purge <key>` runs the
teardown and forgets the module's migrations (and core's that created its
tables or tagged its pages), so the same command restores it exactly — the
pages, their tags, their nav placement and the module's tables.

### Teardown (optional)

`resources/sql/<key>.down.sql` — what `module.php purge <key>` runs. Deleting
the pages cascades to their metadata and to `navbar_internal` (via `ON DELETE
CASCADE` FKs); drop the module's own tables, then remove the nav entries left
pointing nowhere through their id-allocator rows (which cascade to the entries):

```sql
DELETE FROM `page` WHERE `module` = 'imageboard';   -- cascades to meta/robots/closure/navbar_internal
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `board_post`; DROP TABLE IF EXISTS `board_thread`; DROP TABLE IF EXISTS `board`;
SET FOREIGN_KEY_CHECKS = 1;
DELETE nid FROM `navbar_entry_ids` nid
  JOIN `navbar_entry` ne ON ne.id = nid.id
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
DELETE nid FROM `navbar_entry_ids` nid
  LEFT JOIN `navbar_entry` ne ON ne.id = nid.id
 WHERE ne.id IS NULL;
```

## Packages

A module is a Composer package of type `astrx-module` in `packages/modules/<key>/`,
with its namespace at the package root (the module class, `Controller/`,
`resources/` side by side):

```json
{
    "name": "astrx/module-chat",
    "type": "astrx-module",
    "require": {
        "php": ">=8.4",
        "astrx/cms": "self.version",
        "astrx/framework": "self.version",
        "astrx/library": "self.version"
    },
    "autoload": { "psr-4": { "AstrX\\Chat\\": "" } }
}
```

It requires the packages whose namespaces its code uses — almost always
`astrx/framework` and `astrx/cms`, often `astrx/library`; the federated search
also requires `astrx/module-search` — and `tools/packages.php` checks that list
against the code. The root `composer.json` reaches every package through path
repositories (`packages/*`, `packages/modules/*`), so `composer install` links
them into `vendor/`. The site itself does not need Composer: `src/classloader.php`
loads classes from `src/sources.php`, which `tools/packages.php --write`
generates from the packages' `composer.json` maps.

## Adding a module

```
php tools/make_module.php forum --nav --guard
```

scaffolds the package `packages/modules/forum/` (`astrx/module-forum`): its
`composer.json`, `ForumModule.php`, its controller, a `resources/` directory
with the page's template and its slug and title in both locales, and its
teardown `resources/sql/forum.down.sql` — plus a section navbar partial in the
layout's `nav` slot with `--nav`, and a `ForumPageGuard` with `--guard` — then
prints the remaining steps:

1. Install it: add `Forum\ForumModule::class` to `ApplicationModules::INSTALLED`,
   `'forum' => true` to `resources/config/Modules.config.php` (no flag = off)
   and `"astrx/module-forum": "*@dev"` to the root `composer.json`; then
   `php tools/packages.php --write` updates the class loader's map.
2. Add its tables and page rows in a migration in its `resources/sql/`, tagging
   the pages: `UPDATE page SET module='forum' WHERE file_name LIKE 'forum%';`
3. Fill in the teardown `DROP TABLE`s and the nav/guard stubs.
4. `php tools/packages.php && php tools/check_modules.php && php tools/check_architecture.php`.

Its namespace joins the architecture's `feature` layer because it is a module
package; `tools/packages.php` tells you when its `composer.json` requires a
package it does not use, or uses one it does not require.

The scaffold includes `Controller/ForumController.php`, already mapped to the
`forum` page in the manifest's `controllers`. Further templates, catalogs and
config go in the module's `resources/` ([Resources](#resources)); a class's
config section and its translation domain are still found by naming convention
(`Forum.config.php`, `lang/{en,it}/Forum.*.php`).

## Verifying

Five gates, run in CI (`.github/workflows/ci.yml`) and locally:

```
php phpstan.phar analyse -l 10 --no-progress   # types (level 10)
php tools/check_lang_parity.php                # en/it language parity
php tools/check_modules.php                     # every manifest wired correctly
php tools/check_architecture.php                # layered, acyclic namespaces
php tools/packages.php                          # packages require what they use
```

`check_modules.php` verifies each manifest loads, has a `key`/`version`, its nav
contributor implements `NavContributor`, its guards implement `PageGuard`, its
policies implement `PolicyInterface`, its controllers implement
`Kernel\Controller` and live in the module's namespace, its extensions
implement the interface they are listed under and live in the module's
namespace, its layout partials exist in a slot the layout has, its teardown file
exists, its `resources` directory (if declared) is next to it, and no two SQL
files share a name. CI additionally installs against MariaDB and boots the app
with each module off (and with all modules off) to prove they are independently
optional.
