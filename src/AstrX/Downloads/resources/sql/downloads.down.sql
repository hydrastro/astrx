-- Teardown for the Downloads module (tools/module.php purge downloads).
-- Destructive: removes its pages and its site_config data (the signed release
-- manifest text, public key and signature). It owns no dedicated table. Reinstall
-- the schema (tools/install.php) then re-run migrations to restore.

DELETE FROM `page` WHERE `module` = 'downloads';

DELETE FROM `site_config`
 WHERE `key` IN ('manifest_text', 'manifest_pubkey', 'manifest_sig');

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
