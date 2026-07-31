-- Teardown for the Media module (tools/module.php purge media).
-- Destructive: removes its pages and drops the media table. Reinstall the
-- schema (tools/install.php) to restore. On-disk files under the configured
-- media upload dir are NOT removed here — clear them manually if required.

DELETE FROM `page` WHERE `module` = 'media';

DROP TABLE IF EXISTS `media`;

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
