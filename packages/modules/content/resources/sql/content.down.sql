-- Teardown for the Content module (tools/module.php purge content).
-- Destructive: removes its pages and drops the content tables. Reinstall the
-- schema (tools/install.php) to restore.

DELETE FROM `page` WHERE `module` = 'content';

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `content_link`;
DROP TABLE IF EXISTS `content_page`;
SET FOREIGN_KEY_CHECKS = 1;

-- A nav entry whose page went away is dangling: delete its id-allocator row,
-- which cascades to the entry (navbar_entry.id references navbar_entry_ids).
-- Deleting only the entry, as this used to, left the allocator row behind for
-- good. Allocator rows with no entry at all are swept too (a normal install
-- has none; older purges left them).
DELETE nid FROM `navbar_entry_ids` nid
  JOIN `navbar_entry` ne ON ne.id = nid.id
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
DELETE nid FROM `navbar_entry_ids` nid
  LEFT JOIN `navbar_entry` ne ON ne.id = nid.id
 WHERE ne.id IS NULL;
