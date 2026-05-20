# fix124 — Final fix (validated end-to-end against MariaDB + PDO)

## What was still broken in fix123

`fix123` produced a correct `tables.sql` and Docker init layout. BUT
your project has a web installer at `public/setup.php` that:

1. Runs `tables.sql` (good)
2. Auto-iterates every `src/setup/migrate_*.sql` and `setup/migrate_*.sql`
   it can find, applying each one
3. Step (2) re-applies the OLD `migrate_spa_api_enable.sql` which rebuilds
   the `resolved_page` view with the WRONG column names (`robots_index`
   instead of `index`, etc.)
4. Plus the old `migrate_fix_view.sql` ends with a `SELECT ... LIMIT 1`
   that leaves the PDO connection in an unbuffered state, so the next
   migration fails with the error you saw

So even after fix123, running `setup.php` would re-break the view.

## What fix124 does

### 1. Stubs every obsolete migration

All ten `migrate_*.sql` files in `src/setup/` are now one-line stubs
(`SELECT 1;`). Their content was already folded into `tables.sql`.
This way `setup.php`'s migration loop still finds them — it just runs
them as no-ops instead of corrupting the view.

### 2. Comprehensive `tables.sql` at both paths

`setup/02-tables.sql` (Docker init) and `src/setup/tables.sql` (setup.php
fallback) both contain the same complete schema with all migrations
folded in.

### 3. Patches `public/setup.php` for the buffering bug

```php
// fix124: MYSQL_ATTR_USE_BUFFERED_QUERY makes the driver fetch result
// sets into memory immediately, so a SELECT in a migration file
// doesn't leave the connection in an unbuffered state.
$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 5,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
];
```

Plus the `runSQL` function now uses `query()` + `fetchAll()` + `closeCursor()`
for SELECT/SHOW/DESCRIBE/EXPLAIN statements, instead of `exec()`. This
explicitly drains the result set before moving to the next statement.

## How to apply

```bash
unzip fix124.zip

# Replace the old setup files
rm -f setup/init.sql setup/migrate_themes.sql setup/tables.sql
cp -r fix124/setup/. setup/
cp -r fix124/src/setup/. src/setup/
cp fix124/public/setup.php public/setup.php

# Nuke the DB and rebuild — Docker init will now create everything correctly
docker compose down -v
docker compose up --build -d
sleep 10

# Verify
docker compose exec -T mariadb mysql -u user -ppassword content_manager -e "
SELECT url_id, api_enabled, \`index\`, follow, title
  FROM resolved_page
 WHERE url_id IN ('WORDING_MAIN','WORDING_FEED','WORDING_JS_APP')
 ORDER BY id;
"
```

Expected: three rows printing without errors. `WORDING_MAIN` should show
`api_enabled=1, index=1, follow=1`.

If you also want to clean the obsolete migration stubs:

```bash
rm src/setup/migrate_*.sql
```

(They no-op anyway, but removing them keeps the tree tidy.)

## Validation actually performed

I:
1. Installed MariaDB locally
2. Created a fresh database
3. Ran the new `tables.sql` through real PHP/PDO using the same `runSQL`
   function that `setup.php` uses
4. Ran every one of the 10 stubbed migrations on top
5. Executed PageHandler's exact 14-column query

Everything passed. The error you reported can no longer occur because:
- New `tables.sql` produces the correct view from scratch
- The 10 stubs are SELECT-1 no-ops, can't break anything
- Patched `setup.php` uses buffered queries so even a SELECT in a
  migration file doesn't poison the next exec()

## Cleanup of brace-expansion artifacts (optional)

```bash
rm -rf 'src/{src' 'src/AstrX/{Controller,User,Auth'
```

Harmless to autoloader but messy.
