# AstrX search bridges: `websearch` (clear-web) + `onionsearch`

Two drop-in AstrX modules that add **two new, separate search pages** to the CMS,
each a thin, zero-dependency PHP bridge to a standalone localhost Python search
engine's JSON API:

| Module        | Page slug      | Controller               | Backend engine (localhost)                     | Default base URL        |
|---------------|----------------|--------------------------|------------------------------------------------|-------------------------|
| `websearch`   | `/websearch`   | `WebSearchController`    | `astrx-suite/websearch` (`python3 -m websearch serve`) | `http://127.0.0.1:8803` |
| `onionsearch` | `/onionsearch` | `OnionSearchController`  | `astrx-suite/onioncrawler` (`python3 -m onioncrawler search`) | `http://127.0.0.1:8802` |

They own **no database tables** — the crawl/index lives entirely in the external
engine. AstrX only makes a short, localhost HTTP GET to the engine's
`/api/search?q=…&page=…`, parses the JSON, sanitises every field, and renders it.

## The three search pages, kept separate (by design)

AstrX now has **three independent search pages**. They are intentionally NOT
unified or merged:

1. **Internal site search** — *existing, untouched.* Module `search`,
   `SiteSearchController` + `SiteSearchService`, page slug `/search`. Full-text
   search over the CMS's own content (news, pages, comments, board posts) via the
   local `search_index` table. No network calls.
2. **Clear-web search** — *new (this module `websearch`).* Bridges to the
   `websearch` Python engine's crawl of the clear web.
3. **Onion search** — *new (this module `onionsearch`).* Bridges to the
   `onioncrawler` Python engine's crawl of `.onion` services (the engine owns the
   Tor SOCKS hop; AstrX never speaks Tor).

Each has its own controller, template, config, lang domain, module manifest and
nav entry. Disabling or purging one never touches the others.

---

## Files and where they go in the AstrX tree

All paths are relative to the AstrX repo root. Copy each file to the same path.

### `websearch` (clear-web)
```
src/AstrX/WebSearch/module.php                 # manifest (key 'websearch')
src/AstrX/WebSearch/WebSearchConfig.php        # #[InjectConfig] config holder
src/AstrX/WebSearch/WebSearchClient.php        # the bridge: fetch + parse + sanitise
src/AstrX/Controller/WebSearchController.php    # resolves from file_name 'web_search'
resources/template/web_search.html             # no-JS GET form + results loop
resources/config/WebSearch.config.php          # section 'WebSearchConfig'
resources/lang/en/WebSearch.en.php             # UI strings (domain 'WebSearch')
resources/lang/it/WebSearch.it.php             # key-for-key IT
src/setup/modules/websearch.down.sql           # teardown for `module.php purge websearch`
```

### `onionsearch`
```
src/AstrX/OnionSearch/module.php               # manifest (key 'onionsearch')
src/AstrX/OnionSearch/OnionSearchConfig.php     # #[InjectConfig] config holder
src/AstrX/OnionSearch/OnionSearchClient.php     # the bridge: fetch + parse + sanitise
src/AstrX/Controller/OnionSearchController.php   # resolves from file_name 'onion_search'
resources/template/onion_search.html           # no-JS GET form + results loop
resources/config/OnionSearch.config.php        # section 'OnionSearchConfig'
resources/lang/en/OnionSearch.en.php           # UI strings (domain 'OnionSearch')
resources/lang/it/OnionSearch.it.php           # key-for-key IT
src/setup/modules/onionsearch.down.sql         # teardown for `module.php purge onionsearch`
```

### Test (not shipped into the app)
```
tests/bridge_test.php                          # standalone; boots the mock, asserts sanitise/paging/failure
tests/mock_search_server.php                   # mock engine used by bridge_test.php
```

### How resolution works (AstrX conventions used)
- **Controller**: a `page` row with `file_name = 'web_search'` resolves to
  `AstrX\Controller\WebSearchController` via the reflection router
  (`str_replace('_','',ucwords('web_search','_')) . 'Controller'`). Same for
  `onion_search` → `OnionSearchController`.
- **Template**: the shell renders `{{>include}}`, which is built from the page's
  `file_name`, so `web_search` → `resources/template/web_search.html`.
- **Config holder binding**: there is no `WebSearchConfig.config.php`, so
  `ModuleLoader` falls back to the parent-namespace file `WebSearch.config.php`
  and applies its `'WebSearchConfig'` section to the holder (the exact pattern
  `BotTrapConfig`/`ChatConfig` use). Keys and `#[InjectConfig]` setters match
  1:1, so no unused-key / missing-key diagnostics fire.
- **Lang**: each controller calls `loadDomain(langDir(), 'WebSearch'|'OnionSearch')`.

---

## Configuration

`resources/config/WebSearch.config.php` (and the analogous `OnionSearch.config.php`):

```php
return [
    'WebSearchConfig' => [
        'base_url'        => 'http://127.0.0.1:8803', // loopback engine ONLY
        'timeout_seconds' => 3,                       // clamped 1–5
        'per_page'        => 10,                       // engine paginates; hint only, clamped 1–50
    ],
];
```

- `base_url` is operator-trusted but hardened: it is normalised to a bare
  `http(s)` origin (trailing slash stripped); any other scheme (`file://`, …) is
  rejected back to the loopback default. Keep it pointed at localhost.
- The end user only ever supplies `q` and `page`. `q` is `rawurlencode()`d and
  `page` is cast to `int`, so neither can alter the host/scheme — no SSRF surface.
- Editable later from AstrX admin config the same way other module configs are
  (values persist back to this file via `ConfigWriter`) if you wire an admin page;
  not required for the pages to work.

---

## One-time SQL (run once; NOT kept as a repo migration)

This registers the two pages, tags `page.module`, and adds the public-navbar
entries — mirroring exactly how the internal site-search page is seeded in
`src/setup/tables.sql`. Run it once against your AstrX database (it is idempotent;
`INSERT IGNORE` + guarded nav inserts). It assumes the standard
`migrate_module_page_ownership.sql` has already run (it adds `page.module`), which
is true for any current install.

```sql
-- ============================================================
-- CLEAR-WEB SEARCH PAGE (module 'websearch') + public navbar
-- file_name 'web_search' → WebSearchController; slug WORDING_WEBSEARCH.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_WEBSEARCH', 1, 'web_search', 1, 1, 0, 0);

UPDATE `page` SET `module` = 'websearch'
 WHERE `file_name` = 'web_search' AND `module` = '';

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_WEBSEARCH';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_WEBSEARCH';

-- noindex/nofollow: this page triggers a backend network call; don't invite
-- search-engine crawlers to hammer it. (Change to 1,1 to mirror /search.)
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_WEBSEARCH';

SET @ws_page_id       := (SELECT id FROM `page`   WHERE url_id = 'WORDING_WEBSEARCH' LIMIT 1);
SET @ws_pub_navbar_id := (SELECT id FROM `navbar` WHERE name  = 'public' LIMIT 1);
SET @ws_pub_pin_id    := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @ws_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_ws_nav  := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id  = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @ws_page_id AND np.navbar_id = @ws_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @ws_page_id IS NOT NULL AND @ws_pub_pin_id IS NOT NULL AND @existing_ws_nav IS NULL;
SET @ws_nav_id := COALESCE(@existing_ws_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @ws_nav_id, @ws_pub_pin_id, 1, 'WORDING_WEBSEARCH', 1, 1, 0
 WHERE @ws_page_id IS NOT NULL AND @ws_pub_pin_id IS NOT NULL AND @ws_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @ws_nav_id, @ws_page_id
 WHERE @ws_page_id IS NOT NULL AND @ws_nav_id IS NOT NULL;

-- ============================================================
-- ONION SEARCH PAGE (module 'onionsearch') + public navbar
-- file_name 'onion_search' → OnionSearchController; slug WORDING_ONIONSEARCH.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ONIONSEARCH', 1, 'onion_search', 1, 1, 0, 0);

UPDATE `page` SET `module` = 'onionsearch'
 WHERE `file_name` = 'onion_search' AND `module` = '';

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ONIONSEARCH';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ONIONSEARCH';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ONIONSEARCH';

SET @os_page_id       := (SELECT id FROM `page`   WHERE url_id = 'WORDING_ONIONSEARCH' LIMIT 1);
SET @os_pub_navbar_id := (SELECT id FROM `navbar` WHERE name  = 'public' LIMIT 1);
SET @os_pub_pin_id    := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @os_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_os_nav  := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id  = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @os_page_id AND np.navbar_id = @os_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @os_page_id IS NOT NULL AND @os_pub_pin_id IS NOT NULL AND @existing_os_nav IS NULL;
SET @os_nav_id := COALESCE(@existing_os_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @os_nav_id, @os_pub_pin_id, 1, 'WORDING_ONIONSEARCH', 1, 1, 0
 WHERE @os_page_id IS NOT NULL AND @os_pub_pin_id IS NOT NULL AND @os_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @os_nav_id, @os_page_id
 WHERE @os_page_id IS NOT NULL AND @os_nav_id IS NOT NULL;
```

### Required core lang additions (slug + nav label)

The page URL slug and the nav-link label live in the **core** `pages` and
`Navbar` lang domains (the module lang files only hold the search-UI strings).
Add these keys, keeping en/it parity (checked by `tools/check_lang_parity.php`).

`resources/lang/en/pages.en.php`:
```php
'WORDING_WEBSEARCH'               => 'websearch',
'WORDING_WEBSEARCH.title'         => 'Web search',
'WORDING_WEBSEARCH.description'   => 'Search the clear-web crawl index.',
'WORDING_ONIONSEARCH'             => 'onionsearch',
'WORDING_ONIONSEARCH.title'       => 'Onion search',
'WORDING_ONIONSEARCH.description' => 'Search the indexed .onion crawl.',
```
`resources/lang/it/pages.it.php`:
```php
'WORDING_WEBSEARCH'               => 'websearch',
'WORDING_WEBSEARCH.title'         => 'Ricerca web',
'WORDING_WEBSEARCH.description'   => "Cerca nell'indice di scansione del web in chiaro.",
'WORDING_ONIONSEARCH'             => 'onionsearch',
'WORDING_ONIONSEARCH.title'       => 'Ricerca onion',
'WORDING_ONIONSEARCH.description' => 'Cerca tra le pagine .onion indicizzate.',
```
`resources/lang/en/Navbar.en.php`:
```php
'WORDING_WEBSEARCH.label'   => 'Web search',
'WORDING_ONIONSEARCH.label' => 'Onion search',
```
`resources/lang/it/Navbar.it.php`:
```php
'WORDING_WEBSEARCH.label'   => 'Ricerca web',
'WORDING_ONIONSEARCH.label' => 'Ricerca onion',
```

### Optional: explicit module toggles
Modules default **on** when unlisted, so nothing is required. To make them
explicit / toggleable, add to `resources/config/Modules.config.php`:
```php
'websearch'   => true,
'onionsearch' => true,
```
Then `php tools/module.php disable websearch` (nav drops, page 404s; reversible)
or `php tools/module.php purge websearch` (also runs `websearch.down.sql`).

### Undo (remove the pages)
Run `src/setup/modules/websearch.down.sql` / `onionsearch.down.sql` (what
`module.php purge` runs), then delete the added lang keys.

---

## Running the two Python engines behind the pages

Both engines are stdlib-only, bind to `127.0.0.1`, and are server-rendered no-JS
themselves — AstrX just consumes their `/api/search` JSON.

### Clear-web (`websearch`, port 8803)
```
cd astrx-suite/websearch
python3 -m websearch crawl --seeds seeds.example --db web.db --scope-domain example.com
python3 -m websearch serve --db web.db --port 8803
```

### Onion (`onioncrawler`, port 8802)
Requires a running Tor SOCKS proxy for crawling (default `127.0.0.1:9050`); the
`search` server itself only needs the built DB.
```
cd astrx-suite/onioncrawler
python3 -m onioncrawler crawl  --seeds seeds.example --db crawl.db --tor-port 9050
python3 -m onioncrawler search --db crawl.db --port 8802
```

If an engine is down, empty, or returns non-JSON, the AstrX page shows a friendly
"search backend unavailable" / "no results" message — never a 500.

---

## Security: how untrusted crawled content is kept XSS-safe

Result fields come from **crawled, untrusted** pages. Two independent boundaries,
both on the AstrX side:

1. **PHP sanitisation in the client** (`WebSearchClient` / `OnionSearchClient`):
   every text field is `html_entity_decode()`d (to surface entity-hidden tags),
   then `strip_tags()`d, then whitespace-collapsed. The array handed to the
   template therefore contains **no markup at all** — not the engine's `<mark>`
   highlight, not a crawled `<script>`. Result URLs are additionally reduced to a
   safe href: `http`/`https` only, otherwise `#` (blocks `javascript:`/`data:`).
2. **Template escaping**: `web_search.html` / `onion_search.html` render every
   engine value through plain `{{ }}` (HTML-escaped) — **never** `{{&}}` (raw).
   `{{&}}` is used only for `sid_input`, which is AstrX-generated, not engine data.

This matters in practice: the onion engine's JSON API strips only `<mark>` and
will pass a crawled `<script>` straight through in `title`/`snippet`; the AstrX
`strip_tags` is the real boundary that removes it (verified end-to-end).

Network hardening: no redirects are followed (`follow_location: 0`), the timeout
is short (1–5s), the base URL is forced to a localhost-style http(s) origin, and
only `q`/`page` are user-controlled (encoded), so there is no SSRF surface.

---

# Wave 2: `torrentsearch`, `suiteadmin` (admin), `gitbrowse` (link-through)

Three more drop-in modules extend the same pattern to the rest of the suite. They
follow the WebSearch/OnionSearch shape exactly (zero-dependency, no-JS,
warning→500-safe, XSS-safe, SSRF-safe: config-only localhost hosts, `rawurlencode`,
no redirect-follow, `@`-suppressed transport warnings, `strip_tags` on all
untrusted engine output then escaped `{{ }}` rendering).

| Module          | Page slug        | Controller                | Backend engine (localhost)     | Default base URL        |
|-----------------|------------------|---------------------------|--------------------------------|-------------------------|
| `torrentsearch` | `/torrentsearch` | `TorrentSearchController`  | `torrentds` (`search`)          | `http://127.0.0.1:8804` |
| `suiteadmin`    | `/admin-suite`   | `AdminSuiteController`     | all four engines (health probes) | (four URLs, see below)  |
| `gitbrowse`     | `/gitbrowse`     | `GitBrowseController`      | `gitweb` (LINK-THROUGH only)     | `http://127.0.0.1:8801` |

## `torrentsearch` — 4th separate search page (bridges to torrentds)

A carbon copy of the WebSearch page, bridging to torrentds' JSON API. Torrent
**names** and **file paths** are attacker-controlled (harvested off the DHT), so
every one is `strip_tags`'d in `TorrentSearchClient` and rendered through escaped
`{{ }}`. The magnet URI and `.torrent` URL are **rebuilt** from a validated hex
infohash (a row with a non-hex infohash is dropped), never trusted verbatim.

* **Search view** consumes `GET /api/search` and shows name, size, file count,
  seen count, swarm, magnet + `.torrent`.
* **Detail view** (`?ih=<hex>`) consumes `GET /api/torrent/<infohash>` and lists
  the (sanitised) **file paths**, magnet + `.torrent`.
* The `.torrent` link is `<base_url>/torrent/<infohash>.torrent`; the magnet is
  client-side and works anywhere. torrentds paginates by `limit`/`offset`, **not
  `page`** — the page-based UI is translated to `limit`/`offset` in the client.

```
src/AstrX/TorrentSearch/module.php               # manifest (key 'torrentsearch')
src/AstrX/TorrentSearch/TorrentSearchConfig.php   # #[InjectConfig] holder (base_url 8804)
src/AstrX/TorrentSearch/TorrentSearchClient.php   # fetch + parse + sanitise + safe links
src/AstrX/Controller/TorrentSearchController.php   # resolves from file_name 'torrent_search'
resources/template/torrent_search.html           # no-JS search + detail views
resources/config/TorrentSearch.config.php         # section 'TorrentSearchConfig'
resources/lang/en/TorrentSearch.en.php            # UI strings (domain 'TorrentSearch')
resources/lang/it/TorrentSearch.it.php            # key-for-key IT
src/setup/modules/torrentsearch.down.sql          # teardown
```

One-time SQL (public page + public navbar, `noindex` because it triggers a
backend call — mirrors the websearch seed):

```sql
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_TORRENTSEARCH', 1, 'torrent_search', 1, 1, 0, 0);
UPDATE `page` SET `module` = 'torrentsearch'
 WHERE `file_name` = 'torrent_search' AND `module` = '';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_TORRENTSEARCH';
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_TORRENTSEARCH';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_TORRENTSEARCH';

SET @ts_page_id       := (SELECT id FROM `page`   WHERE url_id = 'WORDING_TORRENTSEARCH' LIMIT 1);
SET @ts_pub_navbar_id := (SELECT id FROM `navbar` WHERE name  = 'public' LIMIT 1);
SET @ts_pub_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @ts_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_ts_nav  := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id  = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @ts_page_id AND np.navbar_id = @ts_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @ts_page_id IS NOT NULL AND @ts_pub_pin_id IS NOT NULL AND @existing_ts_nav IS NULL;
SET @ts_nav_id := COALESCE(@existing_ts_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @ts_nav_id, @ts_pub_pin_id, 1, 'WORDING_TORRENTSEARCH', 1, 1, 0
 WHERE @ts_page_id IS NOT NULL AND @ts_pub_pin_id IS NOT NULL AND @ts_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @ts_nav_id, @ts_page_id WHERE @ts_page_id IS NOT NULL AND @ts_nav_id IS NOT NULL;
```

## `suiteadmin` — AstrX ADMIN status/control panel

An AstrX **admin** page (file_name `admin_suite`, child of the admin root),
gated with `Permission::ADMIN_ACCESS` — the same permission the admin section
root (`AdminController`) uses. It probes all four engines and renders a status
panel; a down backend degrades to a friendly **DOWN** row and can never 500 the
page (`@`-suppressed transport, tolerant parse).

* **Status (display).** gitweb `/health`+`/metrics`, onioncrawler
  `/healthz`+`/metrics`, websearch `/healthz` (falls back to `/stats`)+`/metrics`,
  torrentds `/health`+`/api/stats` (JSON, on purpose — exercises the JSON parser).
  `SuiteAdminClient` parses **Prometheus text OR JSON** tolerantly and surfaces a
  few configured key numbers per engine plus UP/DOWN + latency.
* **Control — honest inventory.** The panel wires the **one** write action any
  suite engine exposes: **onioncrawler `POST /add`** (onion-seed submission), via
  a CSRF-protected AstrX admin PRG form. **gitweb, websearch and torrentds expose
  NO control endpoint (GET-only servers) — they are DISPLAY-ONLY.** onioncrawler
  additionally has `/purge` and `/recrawl`; those are destructive and are
  **deliberately NOT wired** (out of scope). onioncrawler gates `/add` unless
  `allow_public_submit` is on (or admin creds are set); a 401/403 surfaces as a
  clear "submission refused" flash, not a crash.

```
src/AstrX/SuiteAdmin/module.php                  # manifest (key 'suiteadmin')
src/AstrX/SuiteAdmin/SuiteAdminConfig.php         # #[InjectConfig] holder (four base URLs)
src/AstrX/SuiteAdmin/SuiteAdminClient.php         # probe + Prometheus/JSON parse + POST /add
src/AstrX/Controller/AdminSuiteController.php      # resolves from file_name 'admin_suite'
resources/template/admin/admin_suite.html         # status table + CSRF/PRG seed form
resources/config/SuiteAdmin.config.php            # section 'SuiteAdminConfig'
resources/lang/en/SuiteAdmin.en.php               # UI strings (domain 'SuiteAdmin')
resources/lang/it/SuiteAdmin.it.php               # key-for-key IT
src/setup/modules/suiteadmin.down.sql             # teardown
```

Config — the four base URLs via `#[InjectConfig]` (`resources/config/SuiteAdmin.config.php`):

```php
return [
    'SuiteAdminConfig' => [
        'gitweb_base_url'       => 'http://127.0.0.1:8801',
        'onioncrawler_base_url' => 'http://127.0.0.1:8802',
        'websearch_base_url'    => 'http://127.0.0.1:8803',
        'torrentds_base_url'    => 'http://127.0.0.1:8804',
        'timeout_seconds'       => 2,   // per-probe, clamped 1–5
    ],
];
```

One-time SQL (admin page as a child of the admin root + **admin** navbar entry —
mirrors the `admin_mirrors` seed):

```sql
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_SUITE', 1, 'admin_suite', 1, 1, 0, 0);
UPDATE `page` SET `module` = 'suiteadmin'
 WHERE `file_name` = 'admin_suite' AND `module` = '';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_SUITE';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_SUITE';
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_SUITE';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_SUITE';

SET @su_page_id     := (SELECT id FROM `page`   WHERE url_id = 'WORDING_ADMIN_SUITE' LIMIT 1);
SET @su_admin_nb_id := (SELECT id FROM `navbar` WHERE name  = 'admin' LIMIT 1);
SET @su_admin_pin   := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @su_admin_nb_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_su_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id  = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @su_page_id AND np.navbar_id = @su_admin_nb_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @su_page_id IS NOT NULL AND @su_admin_pin IS NOT NULL AND @existing_su_nav IS NULL;
SET @su_nav_id := COALESCE(@existing_su_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @su_nav_id, @su_admin_pin, 1, 'WORDING_ADMIN_SUITE', 1, 1, 0
 WHERE @su_page_id IS NOT NULL AND @su_admin_pin IS NOT NULL AND @su_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @su_nav_id, @su_page_id WHERE @su_page_id IS NOT NULL AND @su_nav_id IS NOT NULL;
```

## `gitbrowse` — gitweb LINK-THROUGH (not a bridge)

gitweb is a standalone, server-rendered HTML app with **no JSON API**, so this
module does **not** reimplement, embed or proxy it. `/gitbrowse` is a single card
that **links OUT** to the configured gitweb `service_url` (its loopback default,
or whatever public/onion URL the operator exposes). The only dynamic value is
that URL, which `GitBrowseConfig` forces to an http(s) address before it becomes
an href.

```
src/AstrX/GitBrowse/module.php                   # manifest (key 'gitbrowse')
src/AstrX/GitBrowse/GitBrowseConfig.php           # #[InjectConfig] holder (service_url 8801)
src/AstrX/Controller/GitBrowseController.php       # resolves from file_name 'git_browse'
resources/template/git_browse.html                # link-through card
resources/config/GitBrowse.config.php             # section 'GitBrowseConfig'
resources/lang/en/GitBrowse.en.php                # UI strings (domain 'GitBrowse')
resources/lang/it/GitBrowse.it.php                # key-for-key IT
src/setup/modules/gitbrowse.down.sql              # teardown
```

One-time SQL (public page + public navbar; indexable — it is a static link page,
no backend call):

```sql
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_GITBROWSE', 1, 'git_browse', 1, 1, 0, 0);
UPDATE `page` SET `module` = 'gitbrowse'
 WHERE `file_name` = 'git_browse' AND `module` = '';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_GITBROWSE';
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_GITBROWSE';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_GITBROWSE';

SET @gb_page_id       := (SELECT id FROM `page`   WHERE url_id = 'WORDING_GITBROWSE' LIMIT 1);
SET @gb_pub_navbar_id := (SELECT id FROM `navbar` WHERE name  = 'public' LIMIT 1);
SET @gb_pub_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @gb_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_gb_nav  := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id  = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @gb_page_id AND np.navbar_id = @gb_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @gb_page_id IS NOT NULL AND @gb_pub_pin_id IS NOT NULL AND @existing_gb_nav IS NULL;
SET @gb_nav_id := COALESCE(@existing_gb_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @gb_nav_id, @gb_pub_pin_id, 1, 'WORDING_GITBROWSE', 1, 1, 0
 WHERE @gb_page_id IS NOT NULL AND @gb_pub_pin_id IS NOT NULL AND @gb_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @gb_nav_id, @gb_page_id WHERE @gb_page_id IS NOT NULL AND @gb_nav_id IS NOT NULL;
```

### Required core lang additions (slugs + nav labels), en/it parity

`resources/lang/en/pages.en.php` / `pages.it.php`:
```php
// en
'WORDING_TORRENTSEARCH'             => 'torrentsearch',
'WORDING_TORRENTSEARCH.title'       => 'Torrent search',
'WORDING_TORRENTSEARCH.description' => 'Search the DHT-crawled torrent index.',
'WORDING_ADMIN_SUITE'              => 'admin-suite',
'WORDING_ADMIN_SUITE.title'        => 'Suite status',
'WORDING_ADMIN_SUITE.description'  => 'Health and control panel for the suite engines.',
'WORDING_GITBROWSE'                => 'gitbrowse',
'WORDING_GITBROWSE.title'          => 'Browse the code',
'WORDING_GITBROWSE.description'    => 'Open the standalone gitweb source browser.',
// it
'WORDING_TORRENTSEARCH'             => 'torrentsearch',
'WORDING_TORRENTSEARCH.title'       => 'Ricerca torrent',
'WORDING_TORRENTSEARCH.description' => "Cerca nell'indice torrent scansionato dalla DHT.",
'WORDING_ADMIN_SUITE'              => 'admin-suite',
'WORDING_ADMIN_SUITE.title'        => 'Stato della suite',
'WORDING_ADMIN_SUITE.description'  => 'Pannello di salute e controllo dei motori della suite.',
'WORDING_GITBROWSE'                => 'gitbrowse',
'WORDING_GITBROWSE.title'          => 'Sfoglia il codice',
'WORDING_GITBROWSE.description'    => "Apri il browser del codice sorgente gitweb.",
```
`resources/lang/en/Navbar.en.php` / `Navbar.it.php`:
```php
// en
'WORDING_TORRENTSEARCH.label' => 'Torrent search',
'WORDING_ADMIN_SUITE.label'   => 'Suite status',
'WORDING_GITBROWSE.label'     => 'Code',
// it
'WORDING_TORRENTSEARCH.label' => 'Ricerca torrent',
'WORDING_ADMIN_SUITE.label'   => 'Stato suite',
'WORDING_GITBROWSE.label'     => 'Codice',
```

### Optional: explicit module toggles
```php
// resources/config/Modules.config.php  →  'Modules' => [ … ]
'torrentsearch' => true,
'suiteadmin'    => true,
'gitbrowse'     => true,
```

### Running the engines behind the pages
```
cd astrx-suite/torrentds    && python3 -m torrentds search --port 8804      # torrentsearch
cd astrx-suite/onioncrawler && python3 -m onioncrawler search --port 8802   # suiteadmin control (/add)
cd astrx-suite/gitweb       && python3 -m gitweb --root /srv/git --port 8801 # gitbrowse link target
```
onioncrawler's `/add` accepts a submission only if it was started with
`allow_public_submit` (or admin credentials); otherwise the suiteadmin form
reports a clear "submission refused" and nothing crashes.

---

## Verifying

```
php -l <each .php>                        # syntax (all clean)
php tests/bridge_test.php                 # wave-1 bridge test (websearch/onionsearch; boots a mock)
php tests/suite_bridge_test.php           # wave-2+3 bridge test (torrentsearch/suiteadmin/fedsearch/blocklist; boots a mock; 115 assertions)
php tools/check_modules.php               # all module manifests wired correctly
php tools/check_lang_parity.php           # en/it parity (module + the pages/Navbar additions)
php phpstan.phar analyse -l 10            # types (level 10) — the code follows the level-10 cast helpers
```

---

# Wave 3: `fedsearch` (unified search) + `blocklist` (admin editor)

Two more drop-in modules, same house style as every module above (zero-dependency,
no-JS, warning→500-safe, XSS-safe, SSRF-safe: config-only localhost hosts,
`rawurlencode`, no redirect-follow, `@`-suppressed transport, **bounded timeouts
AND bounded response bodies**, `strip_tags` on all untrusted engine output then
escaped `{{ }}` rendering).

| Module      | Page slug         | Controller                 | Backends (localhost)                                  |
|-------------|-------------------|----------------------------|-------------------------------------------------------|
| `fedsearch` | `/search-all`     | `FederatedSearchController` | internal (SiteSearchService) + websearch + onioncrawler + torrentds |
| `blocklist` | `/admin-blocklist`| `AdminBlocklistController`  | onioncrawler `POST /blocklist` + torrentds `POST /api/block` |

## `fedsearch` — one query box, four no-JS `?source=` tabs

A unified search page that fans ONE query out to four sources, each behind its own
no-JavaScript tab (`?source=internal|web|onion|torrent`, default `internal`).
"Tabs" are plain links: exactly ONE source is active per request, so the page only
ever does the work of the visible tab — one in-process query (internal, via the
core `SiteSearchService`) OR one bounded, size-capped localhost HTTP call (web /
onion / torrent, via `FederatedSearchClient`). A down HTTP source degrades to a
friendly "source unavailable" panel and never 500s the page; the other tabs keep
working. This page does **not** replace the internal `/search` or the dedicated
`/websearch` `/onionsearch` `/torrentsearch` pages — it aggregates them.

```
src/AstrX/FederatedSearch/module.php               # manifest (key 'fedsearch')
src/AstrX/FederatedSearch/FederatedSearchConfig.php # #[InjectConfig] holder (3 base URLs 8802/8803/8804)
src/AstrX/FederatedSearch/FederatedSearchClient.php # bounded/size-capped fan-out fetch + parse + sanitise
src/AstrX/Controller/FederatedSearchController.php   # resolves from file_name 'federated_search'
resources/template/federated_search.html           # no-JS tab bar + per-source render blocks
resources/config/FederatedSearch.config.php         # section 'FederatedSearchConfig'
resources/lang/en/FederatedSearch.en.php            # UI strings (domain 'FederatedSearch')
resources/lang/it/FederatedSearch.it.php            # key-for-key IT
src/setup/modules/fedsearch.down.sql                # teardown
```

One-time SQL (public page + public navbar, `noindex` because it triggers backend
calls — mirrors the websearch seed):

```sql
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_FEDSEARCH', 1, 'federated_search', 1, 1, 0, 0);
UPDATE `page` SET `module` = 'fedsearch'
 WHERE `file_name` = 'federated_search' AND `module` = '';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_FEDSEARCH';
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_FEDSEARCH';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_FEDSEARCH';

SET @fs_page_id       := (SELECT id FROM `page`   WHERE url_id = 'WORDING_FEDSEARCH' LIMIT 1);
SET @fs_pub_navbar_id := (SELECT id FROM `navbar` WHERE name  = 'public' LIMIT 1);
SET @fs_pub_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @fs_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_fs_nav  := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id  = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @fs_page_id AND np.navbar_id = @fs_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @fs_page_id IS NOT NULL AND @fs_pub_pin_id IS NOT NULL AND @existing_fs_nav IS NULL;
SET @fs_nav_id := COALESCE(@existing_fs_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @fs_nav_id, @fs_pub_pin_id, 1, 'WORDING_FEDSEARCH', 1, 1, 0
 WHERE @fs_page_id IS NOT NULL AND @fs_pub_pin_id IS NOT NULL AND @fs_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @fs_nav_id, @fs_page_id WHERE @fs_page_id IS NOT NULL AND @fs_nav_id IS NOT NULL;
```

## `blocklist` — AstrX ADMIN blocklist editor

An AstrX **admin** page (file_name `admin_blocklist`, child of the admin root),
gated with `Permission::ADMIN_ACCESS` — the same permission the admin section root
uses — and behind the same **CSRF-protected PRG** form the `admin_suite` page uses.
It lets an admin add abuse-blocklist entries and pushes them over loopback HTTP to
the two write-capable engines:

* **onioncrawler `POST /blocklist`** — `kind=host|keyword`, `value=…`.
* **torrentds `POST /api/block`** — `kind=infohash|keyword`, `value=…`.

The admin token for each engine is sent every accepted way at once — the
`X-Admin-Token` header, an `Authorization: Bearer` header AND the `token` body
field. **The tokens come from server-side config only (`BlocklistConfig`); they
are never placed in the template context, rendered or logged.** Each target's
outcome (added / duplicate / forbidden / invalid / unreachable / token-not-
configured) is reported as its own flash. A down engine degrades to a friendly
"unreachable" flash and never 500s the page.

```
src/AstrX/Blocklist/module.php                    # manifest (key 'blocklist')
src/AstrX/Blocklist/BlocklistConfig.php            # #[InjectConfig] holder (2 base URLs + 2 admin TOKENS)
src/AstrX/Blocklist/BlocklistClient.php            # bounded/size-capped POST + token auth shapes
src/AstrX/Controller/AdminBlocklistController.php   # resolves from file_name 'admin_blocklist'
resources/template/admin/admin_blocklist.html      # two CSRF/PRG forms (onion + torrent)
resources/config/Blocklist.config.php              # section 'BlocklistConfig'
resources/lang/en/Blocklist.en.php                 # UI strings (domain 'Blocklist')
resources/lang/it/Blocklist.it.php                 # key-for-key IT
src/setup/modules/blocklist.down.sql               # teardown
```

Config — two base URLs + two SECRET tokens (`resources/config/Blocklist.config.php`):

```php
return [
    'BlocklistConfig' => [
        'onioncrawler_base_url'    => 'http://127.0.0.1:8802',
        'onioncrawler_admin_token' => '',   // onioncrawler --admin-token (SECRET; empty = disabled)
        'torrentds_base_url'       => 'http://127.0.0.1:8804',
        'torrentds_admin_token'    => '',   // torrentds admin token (SECRET; empty = disabled)
        'timeout_seconds'          => 3,    // per-request, clamped 1–5
    ],
];
```

Keep the real tokens OUT of version control — set them via your deployment's
secret mechanism (`secure-config.sh` / environment substitution). An empty token
makes the editor report "token not configured" for that engine instead of sending
a call that could only be refused.

One-time SQL (admin page as a child of the admin root + **admin** navbar entry —
mirrors the `admin_suite` seed):

```sql
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_BLOCKLIST', 1, 'admin_blocklist', 1, 1, 0, 0);
UPDATE `page` SET `module` = 'blocklist'
 WHERE `file_name` = 'admin_blocklist' AND `module` = '';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_BLOCKLIST';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_BLOCKLIST';
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_BLOCKLIST';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_BLOCKLIST';

SET @bl_page_id     := (SELECT id FROM `page`   WHERE url_id = 'WORDING_ADMIN_BLOCKLIST' LIMIT 1);
SET @bl_admin_nb_id := (SELECT id FROM `navbar` WHERE name  = 'admin' LIMIT 1);
SET @bl_admin_pin   := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @bl_admin_nb_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_bl_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id  = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @bl_page_id AND np.navbar_id = @bl_admin_nb_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @bl_page_id IS NOT NULL AND @bl_admin_pin IS NOT NULL AND @existing_bl_nav IS NULL;
SET @bl_nav_id := COALESCE(@existing_bl_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @bl_nav_id, @bl_admin_pin, 1, 'WORDING_ADMIN_BLOCKLIST', 1, 1, 0
 WHERE @bl_page_id IS NOT NULL AND @bl_admin_pin IS NOT NULL AND @bl_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @bl_nav_id, @bl_page_id WHERE @bl_page_id IS NOT NULL AND @bl_nav_id IS NOT NULL;
```

### Required core lang additions (slugs + nav labels), en/it parity

`resources/lang/en/pages.en.php` / `pages.it.php`:
```php
// en
'WORDING_FEDSEARCH'                   => 'search-all',
'WORDING_FEDSEARCH.title'             => 'Federated search',
'WORDING_FEDSEARCH.description'       => 'Search this site, the clear web, onion and torrents at once.',
'WORDING_ADMIN_BLOCKLIST'            => 'admin-blocklist',
'WORDING_ADMIN_BLOCKLIST.title'      => 'Blocklist editor',
'WORDING_ADMIN_BLOCKLIST.description' => 'Add abuse-blocklist entries to the suite engines.',
// it
'WORDING_FEDSEARCH'                   => 'search-all',
'WORDING_FEDSEARCH.title'             => 'Ricerca federata',
'WORDING_FEDSEARCH.description'       => 'Cerca in questo sito, nel web in chiaro, onion e torrent in una volta.',
'WORDING_ADMIN_BLOCKLIST'            => 'admin-blocklist',
'WORDING_ADMIN_BLOCKLIST.title'      => 'Editor della blocklist',
'WORDING_ADMIN_BLOCKLIST.description' => 'Aggiungi voci alla blocklist anti-abuso dei motori della suite.',
```
`resources/lang/en/Navbar.en.php` / `Navbar.it.php`:
```php
// en
'WORDING_FEDSEARCH.label'       => 'Search',
'WORDING_ADMIN_BLOCKLIST.label' => 'Blocklist',
// it
'WORDING_FEDSEARCH.label'       => 'Ricerca',
'WORDING_ADMIN_BLOCKLIST.label' => 'Blocklist',
```

### Optional: explicit module toggles
```php
// resources/config/Modules.config.php  →  'Modules' => [ … ]
'fedsearch' => true,
'blocklist' => true,
```

### Running the engines behind the pages
`fedsearch` reuses the same three engines the dedicated pages use (websearch 8803,
onioncrawler 8802, torrentds 8804) plus AstrX's own content. `blocklist` needs the
two write-capable engines started **with an admin token** so their control
endpoints accept the push:
```
python3 -m onioncrawler search --port 8802 --admin-token "<same as onioncrawler_admin_token>"
python3 -m torrentds     search --port 8804 --admin-token "<same as torrentds_admin_token>"
```
