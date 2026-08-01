-- Teardown for the Mirrors module (tools/module.php purge mirrors).
-- Destructive: removes its pages and its site_config data (the signed onion mirror
-- list). It owns no dedicated table. Reinstall the schema (tools/install.php) then
-- re-run migrations to restore.

DELETE FROM `page` WHERE `module` = 'mirrors';

DELETE FROM `site_config` WHERE `key` = 'onion_mirrors';

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
