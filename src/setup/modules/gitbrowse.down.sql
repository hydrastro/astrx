-- Teardown for the Git browser link-through module (tools/module.php purge gitbrowse).
-- The page owns NO database tables and makes NO backend calls (gitweb is a
-- standalone HTML app this page only links to), so this only removes the page
-- and sweeps any now-orphaned navbar rows. Reinstall via the module SQL in the
-- integration README to restore.

DELETE FROM `page` WHERE `module` = 'gitbrowse';   -- cascades to meta/robots/closure/navbar_internal

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
