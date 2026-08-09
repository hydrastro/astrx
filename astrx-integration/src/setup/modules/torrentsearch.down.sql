-- Teardown for the Torrent search module (tools/module.php purge torrentsearch).
-- The bridge owns NO database tables (the DHT crawl / metadata store lives
-- entirely in the external torrentds engine), so this only removes the page and
-- sweeps any now-orphaned navbar rows. Reinstall via the module SQL in the
-- integration README to restore. None of the sibling search pages — internal
-- ('search'), clear-web ('websearch') or onion ('onionsearch') — is affected.

DELETE FROM `page` WHERE `module` = 'torrentsearch';   -- cascades to meta/robots/closure/navbar_internal

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
