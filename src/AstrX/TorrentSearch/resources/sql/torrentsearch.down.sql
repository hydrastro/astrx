-- Teardown for the Torrent search module (tools/module.php purge torrentsearch).
-- The bridge owns NO database tables (the DHT crawl / metadata store lives
-- entirely in the external torrentds engine), so this only removes the page and
-- sweeps any now-orphaned navbar rows. Reinstall via the module SQL in the
-- integration README to restore. None of the sibling search pages — internal
-- ('search'), clear-web ('websearch') or onion ('onionsearch') — is affected.

DELETE FROM `page` WHERE `module` = 'torrentsearch';   -- cascades to meta/robots/closure/navbar_internal

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
