-- ============================================================
-- AstrX migration: public navbar layout normalizer (+ admin-roles sweep)
-- ============================================================
-- Reshapes the PUBLIC navbar to match the user navbar's shape:
--   [ Home ]  (first, manual)
--   [ everything else, ALPHABETICAL by label ]  (middle)
--   [ User Area ]  (last, manual)
-- so the public links read Home, Boards, Canary, Chat, Downloads, Mirrors, Pages,
-- Search, Tip Line, User Area instead of insertion order.
--
-- Named `zzz` so it runs AFTER every migrate_zz_* that seeds a public entry
-- (chat/board/search live in tables.sql; pages + the transparency pages in their
-- own migrations). Keyed entirely by entry NAME and pin (sort_order, sort_mode)
-- signatures, so it is fully idempotent — safe to re-run, and safe to run once by
-- hand against an already-installed database.
-- ============================================================

SET @pub := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);

-- The originally-seeded single public pin (sort_order 0, sort_mode 1) is kept as
-- the HOME pin (manual, stays first). It remains the only public pin at that
-- (sort_order, sort_mode) signature after this script, so re-selecting it is stable.
SET @home_pin := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @pub AND sort_order = 0 AND sort_mode = 1
     ORDER BY id ASC LIMIT 1
);

-- ALPHA (middle) pin: sort_order 1, sort_mode 0 (alphabetical). Create once.
INSERT INTO `navbar_pin` (navbar_id, sort_order, sort_mode)
SELECT @pub, 1, 0
 WHERE @pub IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `navbar_pin` WHERE navbar_id = @pub AND sort_mode = 0);
SET @alpha_pin := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @pub AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);

-- LAST (User Area) pin: sort_order 2, sort_mode 1 (manual, endcap). Create once.
INSERT INTO `navbar_pin` (navbar_id, sort_order, sort_mode)
SELECT @pub, 2, 1
 WHERE @pub IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `navbar_pin` WHERE navbar_id = @pub AND sort_order = 2 AND sort_mode = 1);
SET @last_pin := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @pub AND sort_order = 2 AND sort_mode = 1
     ORDER BY id ASC LIMIT 1
);

-- Distribute the entries. Keyed by name → idempotent (re-runs are no-ops).
-- Everything except Home / User Area → the alphabetical pin.
UPDATE `navbar_entry` e JOIN `navbar_pin` p ON p.id = e.pin_id
   SET e.pin_id = @alpha_pin
 WHERE p.navbar_id = @pub AND @alpha_pin IS NOT NULL
   AND e.name NOT IN ('WORDING_HOME', 'WORDING_USER');
-- User Area → the last pin.
UPDATE `navbar_entry` e JOIN `navbar_pin` p ON p.id = e.pin_id
   SET e.pin_id = @last_pin
 WHERE p.navbar_id = @pub AND @last_pin IS NOT NULL AND e.name = 'WORDING_USER';
-- Home → the home pin (already there on a fresh seed; explicit for safety on re-run).
UPDATE `navbar_entry` e JOIN `navbar_pin` p ON p.id = e.pin_id
   SET e.pin_id = @home_pin
 WHERE p.navbar_id = @pub AND @home_pin IS NOT NULL AND e.name = 'WORDING_HOME';

-- ── Sweep the retired admin-roles entry (removed in round 18) ─────────────────
-- No-op on a fresh install (never created). On an upgraded DB it deletes the
-- leftover navbar entry BY NAME — via the top of the entry_ids -> entry -> internal
-- cascade chain — so it works even if the entry is orphaned or its page link is
-- broken, then removes the page row itself.
DELETE nid FROM `navbar_entry_ids` nid
  JOIN `navbar_entry` e ON e.id = nid.id
 WHERE e.`name` = 'WORDING_ADMIN_ROLES';
DELETE FROM `page` WHERE `url_id` = 'WORDING_ADMIN_ROLES';
