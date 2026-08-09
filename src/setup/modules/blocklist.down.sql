-- Teardown for the Blocklist editor module (tools/module.php purge blocklist).
-- The editor owns NO database tables (the blocklists live inside the external
-- onioncrawler / torrentds engines; AstrX only POSTs entries to them), so this
-- only removes the admin page and sweeps any now-orphaned navbar rows. Reinstall
-- via the module SQL in the integration README to restore. No engine state and no
-- other admin page is touched.

DELETE FROM `page` WHERE `module` = 'blocklist';   -- cascades to meta/robots/closure/navbar_internal

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
