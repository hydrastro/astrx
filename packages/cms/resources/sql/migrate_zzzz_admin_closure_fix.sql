-- ============================================================
-- AstrX migration: put admin-content / admin-language back under the admin root
-- ============================================================
-- migrate_zz_content_module.sql and migrate_zz_admin_language.sql inserted only
-- the SELF row (id, id) into `page_closure` for their admin pages. They never
-- inserted the parent row (WORDING_ADMIN → page), which migrate_zz_media.sql
-- does insert and documents as the contract:
--
--     "admin_media is a child of the admin root so its template resolves under
--      admin/ and it inherits the ADMIN_ACCESS page guard."
--
-- Two things broke because those rows were missing:
--
--   1. ContentManager's framework admin guard is an ancestry walk
--      (file_name === 'admin' OR any closure ancestor is 'admin'). With no
--      ancestor row, `GET /en/admin-content` with no session is not recognised
--      as an admin page: hidden = 0 so the 404 branch does not fire, the login
--      redirect is skipped, and dispatch reaches AdminContentController::handle().
--      The controller's own gate->cannot(ADMIN_ACCESS) still answers 403, so
--      there was never an auth bypass — but the framework guard was inert for
--      these two surfaces, and an anonymous request rendered the admin page
--      shell under a 403 status.
--
--   2. DefaultTemplateContext::buildIncludePath() derives the template path from
--      the same ancestor list, so these two pages resolved to `admin_content` /
--      `admin_language` at the template ROOT while the other 30 admin templates
--      live under `admin/`.
--
-- IMPORTANT — this migration is not optional on upgrade. The templates now ship
-- at resources/template/admin/admin_content.html and admin/admin_language.html
-- (matching every other admin page). Until these rows exist, the include path
-- for those two pages is still the old root-level name and their content area
-- renders EMPTY (the missing partial resolves to '', it is not a 500).
--
-- Why a NEW file instead of editing the two originals: tools/install.php records
-- every migration in `migration` with a sha256 of the file, and refuses to
-- re-run a file whose checksum changed ("already ran with a different
-- checksum"). Editing an applied migration bricks the upgrade for every
-- existing install; a new file is the only replayable fix.
--
-- Idempotent — INSERT IGNORE against the (ancestor, descendant) primary key,
-- so re-running is a no-op, and the SELECTs are empty (not an error) on an
-- install where a module page does not exist.
-- ============================================================

-- Self rows: already inserted by the original migrations. Repeated so this file
-- is correct standalone on an install that somehow has the page but no closure.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page`
 WHERE url_id IN ('WORDING_ADMIN_CONTENT', 'WORDING_ADMIN_LANGUAGE');

-- The missing parent rows — the whole point of this migration.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id
  FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN'
   AND d.url_id IN ('WORDING_ADMIN_CONTENT', 'WORDING_ADMIN_LANGUAGE');

-- Neither page is indexable (both originals already assert this; repeated so a
-- hand-built install cannot end up with an admin page open to crawlers).
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page`
 WHERE url_id IN ('WORDING_ADMIN_CONTENT', 'WORDING_ADMIN_LANGUAGE');
