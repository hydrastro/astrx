# fix123 — Single comprehensive fix

This replaces the broken three-file `setup/` layout with **two files** that
produce a fully-working database on first boot. No more manual migrations.

## What's in this fix

```
fix123/
├── setup/
│   ├── 01-init.sql       # creates content_manager DB + user account
│   └── 02-tables.sql     # complete schema + all data seeds (everything baked in)
└── README.md             # this file
```

## What's been fixed at the SQL level

`02-tables.sql` is the OLD `tables.sql` with all migration content folded in:

| Change                                                        | Was in (manual migration)         |
|---------------------------------------------------------------|-----------------------------------|
| `page.api_enabled` column (TINYINT DEFAULT 0)                 | migrate_api.sql                   |
| `resolved_page` view now selects `p.api_enabled`              | migrate_api.sql                   |
| `api_key` table CREATE                                        | migrate_api.sql                   |
| `captcha.regen_count` + `captcha.last_regen_at` columns       | migrate_captcha_abuse.sql         |
| Page row `WORDING_CAPTCHA_IMAGE` (template=0, hidden=0)       | migrate_captcha_iframe + unhide   |
| Page row `WORDING_CAPTCHA_FRAME` (template=0, hidden=0)       | migrate_captcha_iframe + unhide   |
| Page row `WORDING_FEED` (template=0, controller=1, index=1)   | migrate_feed.sql                  |
| Page row `WORDING_JS_APP` (template=0, controller=1)          | migrate_js_spa.sql                |
| `api_enabled=1` on MAIN/USER_HOME/PROFILE/LOGIN/REGISTER/RECOVER | migrate_api_profile + migrate_spa_api_enable |

The old `setup/migrate_themes.sql` is gone — its content (theme column on
user, admin_themes page row) was already in `tables.sql`, and its presence
in the Docker init dir was causing the alphabetical-ordering bug (it ran
BEFORE tables.sql, on an empty database, and the ALTER silently failed).

The numeric file prefixes (`01-`, `02-`) make the init order explicit
instead of relying on alphabetical coincidence.

## How to apply

You're in dev mode, so the cleanest path is a **clean DB rebuild**:

```bash
# 1. Apply the fix (REPLACE setup/ contents — both old files go away)
unzip fix123.zip
rm -f setup/init.sql setup/migrate_themes.sql setup/tables.sql
cp -r fix123/setup/. setup/

# 2. Nuke the database volume and rebuild everything
docker compose down -v
docker compose up --build -d

# 3. Wait ~10 seconds for MariaDB to finish initialising, then verify:
sleep 10
docker compose exec -T mariadb mysql -u user -ppassword content_manager -e "
SELECT id, url_id, api_enabled, \`index\`, follow, title, template_file_name
  FROM resolved_page
 WHERE url_id IN ('WORDING_MAIN','WORDING_FEED','WORDING_JS_APP','WORDING_CAPTCHA_FRAME')
 ORDER BY id;
"
```

Expected: 4 rows, all without errors. If you see this, the framework
will boot cleanly:
- The main page loads (no more `Unknown column 'index'`)
- `/en/api/main?html=1` returns JSON instead of 404
- `/en/js/#main` SPA fetches and renders
- `/en/feed.xml` serves Atom XML
- Captcha iframe pages route correctly

## Optional cleanup

Two harmless brace-expansion artifact directories exist in your repo from
earlier `cp -r` commands where brace expansion didn't fire (likely zsh
shell behavior). They're invisible to the PSR-4 autoloader. To remove:

```bash
rm -rf 'src/{src' 'src/AstrX/{Controller,User,Auth'
```

(The quoting is essential — those directory names literally start with `{`.)

## Why this is different from earlier fixes

The previous twelve attempts to fix the view were piecemeal patches that
assumed your Docker init was already producing a correct base state. It
wasn't. Every `docker compose down -v` reset you back to a broken init
(`migrate_themes.sql` failing before `tables.sql`, no `api_enabled`
column, no view rebuild). The manual migrations in `src/setup/` could
restore correctness, but only if you ran ALL of them in the right
order — and we both lost track of which had been run.

This fix removes the moving parts. The Docker init dir contains exactly
two files that always produce a complete, correct schema. No manual
migrations are needed for a fresh boot. The files in `src/setup/` are
now historical reference only.
