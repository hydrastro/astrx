-- Teardown for the Downloads module (tools/module.php purge downloads).
-- Destructive: removes its pages and its site_config data (the signed release
-- manifest text, public key and signature). It owns no dedicated table. Reinstall
-- the schema (tools/install.php) then re-run migrations to restore.

DELETE FROM `page` WHERE `module` = 'downloads';

DELETE FROM `site_config`
 WHERE `key` IN ('manifest_text', 'manifest_pubkey', 'manifest_sig');

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
