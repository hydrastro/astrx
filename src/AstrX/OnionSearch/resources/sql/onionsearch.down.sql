-- Teardown for the Onion search module (tools/module.php purge onionsearch).
-- The bridge owns NO database tables (the .onion crawl/index lives entirely in
-- the external onioncrawler engine), so this only removes the page and sweeps
-- any now-orphaned navbar rows. Reinstall via the module SQL in the integration
-- README to restore. Neither the internal site search ('search') nor the
-- clear-web search ('websearch') is affected.

DELETE FROM `page` WHERE `module` = 'onionsearch';   -- cascades to meta/robots/closure/navbar_internal

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
