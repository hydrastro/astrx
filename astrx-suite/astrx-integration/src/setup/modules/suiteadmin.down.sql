-- Teardown for the Suite admin module (tools/module.php purge suiteadmin).
-- The panel owns NO database tables (it only reads the external engines' HTTP
-- health/metrics endpoints and POSTs seeds to onioncrawler), so this only
-- removes the admin page and sweeps any now-orphaned navbar rows. Reinstall via
-- the module SQL in the integration README to restore. No engine state and no
-- other admin page is touched.

DELETE FROM `page` WHERE `module` = 'suiteadmin';   -- cascades to meta/robots/closure/navbar_internal

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
