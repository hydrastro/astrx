-- Teardown for the Mirrors module (tools/module.php purge mirrors).
-- Destructive: removes its pages and ALL its site_config data. It owns no dedicated
-- table. Reinstall the schema (tools/install.php) then re-run migrations to restore.
--
-- Deletes all THREE keys AdminMirrorsController writes: onion_mirrors (the list),
-- onion_signed (the offline-signed statement), AND onion_primary — the canonical
-- .onion that ContentManager emits as the site-wide Onion-Location header. Leaving
-- onion_primary behind would keep that header firing after purge with the editor
-- gone (404), so a purge must clear it too.

DELETE FROM `page` WHERE `module` = 'mirrors';

DELETE FROM `site_config` WHERE `key` IN ('onion_mirrors', 'onion_signed', 'onion_primary');

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
