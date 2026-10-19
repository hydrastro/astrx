-- Teardown for the Bot-trap module (tools/module.php purge bottrap).
-- Destructive: removes the module's pages and drops its table. Re-enabling
-- afterwards requires reinstalling the schema (tools/install.php).

DELETE FROM `page` WHERE `module` = 'bottrap';

DROP TABLE IF EXISTS `bot_trap_log`;

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
