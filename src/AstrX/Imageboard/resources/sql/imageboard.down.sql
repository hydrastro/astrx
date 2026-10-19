-- Teardown for the Imageboard module (tools/module.php purge imageboard).
-- Destructive: removes the module's pages and drops its tables. Re-enabling
-- afterwards requires reinstalling the schema (tools/install.php).

-- Pages: the DELETE cascades to page_meta / page_robots / page_closure /
-- page_keyword / navbar_internal via their ON DELETE CASCADE foreign keys.
DELETE FROM `page` WHERE `module` = 'imageboard';

-- Tables (FK checks off so drop order doesn't matter).
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `board_watch`;
DROP TABLE IF EXISTS `board_report`;
DROP TABLE IF EXISTS `board_ban`;
DROP TABLE IF EXISTS `board_mod`;
DROP TABLE IF EXISTS `board_image_block`;
DROP TABLE IF EXISTS `board_image`;
DROP TABLE IF EXISTS `board_post`;
DROP TABLE IF EXISTS `board_thread`;
DROP TABLE IF EXISTS `board`;
SET FOREIGN_KEY_CHECKS = 1;

-- Remove navbar_entry rows orphaned by the page cascade (no internal/external).
DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
