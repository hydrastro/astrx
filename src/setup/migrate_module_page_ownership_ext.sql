-- ============================================================
-- AstrX migration: extend page ownership to search + webmail (Phase 3)
-- ============================================================
-- Follows migrate_module_page_ownership.sql (which added page.module and tagged
-- imageboard/chat/bottrap). Tags the site-search and webmail pages so those
-- modules can be gated too. Only touches still-untagged rows, so it never
-- clobbers a manual assignment. Idempotent — safe to re-run.
-- ============================================================

-- Site-wide search: the /search page and the admin crawler page. (Board search
-- is file_name 'board_search', already owned by the imageboard module.)
UPDATE `page` SET `module` = 'search'
 WHERE `module` = '' AND `file_name` IN ('site_search', 'admin_search');

-- Webmail client page + its admin config editor (the shared Mail backend is core
-- and stays untagged). Tagging the admin page too means disabling the webmail
-- module also 404s /admin-config-webmail and drops its admin-nav entry, matching
-- how the other modules own their admin-config pages.
UPDATE `page` SET `module` = 'webmail'
 WHERE `module` = '' AND `file_name` IN ('webmail', 'admin_config_webmail');
