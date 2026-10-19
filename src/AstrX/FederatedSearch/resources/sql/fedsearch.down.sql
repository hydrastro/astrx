-- Teardown for the Federated search module (tools/module.php purge fedsearch).
-- The unified page owns NO database tables (internal hits come from the core
-- search service; the three engine indexes live entirely in the external
-- astrx-suite engines), so this only removes the page and sweeps any now-orphaned
-- navbar rows. Reinstall via the module SQL in the integration README to restore.
-- The internal site search ('search') and the dedicated per-engine pages
-- (websearch / onionsearch / torrentsearch) are NOT affected.

DELETE FROM `page` WHERE `module` = 'fedsearch';   -- cascades to meta/robots/closure/navbar_internal

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
