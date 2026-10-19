-- Teardown for the Webmail client module (tools/module.php purge webmail).
-- Destructive: removes the webmail page and drops its table. The shared Mail
-- backend (used by auth) is core and untouched. Reinstall to restore.

DELETE FROM `page` WHERE `module` = 'webmail';

DROP TABLE IF EXISTS `webmail_trusted_sender`;

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
