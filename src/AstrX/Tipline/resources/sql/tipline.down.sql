-- Teardown for the Tipline module (tools/module.php purge tipline).
-- Destructive: removes its pages, DROPS the `tipline` table (ALL stored tips are
-- lost), and clears its site_config public key. Reinstall the schema
-- (tools/install.php) then re-run migrations to restore an empty tip line.

DELETE FROM `page` WHERE `module` = 'tipline';

DROP TABLE IF EXISTS `tipline`;

DELETE FROM `site_config` WHERE `key` = 'tipline_pubkey';

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
