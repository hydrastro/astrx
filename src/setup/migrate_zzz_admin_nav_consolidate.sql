-- ============================================================
-- AstrX migration: consolidate the admin navbar into one group
-- ============================================================
-- An earlier chat migration created its own admin navbar pin, and the
-- theme-nav migration appends its entry to the LAST admin pin — so upgraded
-- databases could end up with a stray trailing admin group AND a duplicated
-- "Themes" entry living in it. A fresh install is already correct, so on a
-- clean database every statement below is a no-op.
--
-- This migration converges ANY admin navbar to the shipped shape: the
-- Dashboard pin, plus a single alpha-sorted group that holds every other admin
-- entry, with no duplicate entries and no empty pins.
--
-- FK chain (all ON DELETE CASCADE): navbar_entry_ids <- navbar_entry <-
-- navbar_internal / navbar_external. Deleting an id from navbar_entry_ids
-- removes the entry and its internal/external row in one shot.
--
-- Idempotent and destructive-safe: it only ever removes exact duplicates
-- (two entries pointing at the same page) and moves entries between pins.
-- ============================================================

SET @admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);

-- The Dashboard pin = the pin that holds the WORDING_ADMIN entry (kept as-is).
SET @dash_pin_id := (
    SELECT e.pin_id
      FROM `navbar_entry` e
      JOIN `navbar_pin` p ON p.id = e.pin_id
     WHERE p.navbar_id = @admin_navbar_id
       AND e.internal = 1 AND e.name = 'WORDING_ADMIN'
     ORDER BY e.pin_id
     LIMIT 1
);

-- The canonical group pin = the alpha-sorted (sort_mode = 0) admin pin with the
-- lowest id; fall back to the lowest non-dashboard admin pin if none is alpha.
SET @alpha_pin_id := (
    SELECT p.id FROM `navbar_pin` p
     WHERE p.navbar_id = @admin_navbar_id AND p.sort_mode = 0
     ORDER BY p.id
     LIMIT 1
);
SET @alpha_pin_id := COALESCE(@alpha_pin_id, (
    SELECT p.id FROM `navbar_pin` p
     WHERE p.navbar_id = @admin_navbar_id
       AND (@dash_pin_id IS NULL OR p.id <> @dash_pin_id)
     ORDER BY p.id
     LIMIT 1
));

-- 1) Remove duplicate internal admin entries (same page_id): keep the lowest id.
DELETE FROM `navbar_entry_ids`
 WHERE id IN (
    SELECT id FROM (
        SELECT ni.id AS id
          FROM `navbar_internal` ni
          JOIN `navbar_entry` e ON e.id = ni.id
          JOIN `navbar_pin`   p ON p.id = e.pin_id
         WHERE p.navbar_id = @admin_navbar_id
           AND ni.id > (
               SELECT MIN(ni2.id)
                 FROM `navbar_internal` ni2
                 JOIN `navbar_entry` e2 ON e2.id = ni2.id
                 JOIN `navbar_pin`   p2 ON p2.id = e2.pin_id
                WHERE p2.navbar_id = @admin_navbar_id
                  AND ni2.page_id = ni.page_id
           )
    ) AS dupes
 );

-- 2) Move every remaining admin entry EXCEPT the Dashboard onto the group pin.
UPDATE `navbar_entry` e
   JOIN `navbar_pin` p ON p.id = e.pin_id
   SET e.pin_id = @alpha_pin_id
 WHERE @alpha_pin_id IS NOT NULL
   AND p.navbar_id = @admin_navbar_id
   AND e.pin_id <> @alpha_pin_id
   AND NOT (e.internal = 1 AND e.name = 'WORDING_ADMIN');

-- 3) Drop any now-empty admin pins, keeping the Dashboard and the group pin.
DELETE FROM `navbar_pin`
 WHERE navbar_id = @admin_navbar_id
   AND (@dash_pin_id  IS NULL OR id <> @dash_pin_id)
   AND (@alpha_pin_id IS NULL OR id <> @alpha_pin_id)
   AND id NOT IN (SELECT DISTINCT pin_id FROM `navbar_entry`);

-- Verify:
--   SELECT e.id, e.pin_id, e.name FROM navbar_entry e
--     JOIN navbar_pin p ON p.id = e.pin_id JOIN navbar n ON n.id = p.navbar_id
--    WHERE n.name = 'admin' ORDER BY e.pin_id, e.sort_order, e.id;
