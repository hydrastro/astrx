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
-- NOTE: `banlist_nick` is CORE (defined in tables.sql, an FK-child of the core
-- `banlist`), not a chat table — the core Admin → Banlist page reads it on every
-- render. Dropping it here made that page unable to list ANY bans after a chat
-- purge. It is intentionally NOT dropped by this teardown.
SET FOREIGN_KEY_CHECKS = 1;

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
