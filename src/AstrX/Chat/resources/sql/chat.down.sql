-- Teardown for the Chat module (tools/module.php purge chat).
-- Destructive: removes the module's pages and drops its tables. Re-enabling
-- afterwards requires reinstalling the schema (tools/install.php).

DELETE FROM `page` WHERE `module` = 'chat';

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `chat_attachment`;
DROP TABLE IF EXISTS `chat_filters`;
DROP TABLE IF EXISTS `chat_report`;
DROP TABLE IF EXISTS `chat_pm`;
DROP TABLE IF EXISTS `chat_message`;
DROP TABLE IF EXISTS `chat_presence`;
DROP TABLE IF EXISTS `chat_settings`;
DROP TABLE IF EXISTS `chat_room`;
-- NOTE: `banlist_nick` is CORE (defined in the core base schema, an FK-child of the core
-- `banlist`), not a chat table — the core Admin → Banlist page reads it on every
-- render. Dropping it here made that page unable to list ANY bans after a chat
-- purge. It is intentionally NOT dropped by this teardown.
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
