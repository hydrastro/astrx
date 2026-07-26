-- Teardown for the Content module (tools/module.php purge content).
-- Destructive: removes its pages and drops the content tables. Reinstall the
-- schema (tools/install.php) to restore.

DELETE FROM `page` WHERE `module` = 'content';

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `content_link`;
DROP TABLE IF EXISTS `content_page`;
SET FOREIGN_KEY_CHECKS = 1;

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
