-- ============================================================
-- AstrX migration: unhide chat pages
--
-- Symptom: an admin viewing a chat page (e.g. the in-chat Admin panel or the
-- chat configuration page) sees the banner
--     "⚠ Admin view: this page is hidden from public visitors."
--
-- Cause: that page's row carries hidden=1 on a long-lived install. The
-- framework's ContentManager 404s a hidden page for non-admins AND shows
-- admins that banner (astrx.content/page_hidden):
--
--     $adminViewingHidden = $page->hidden && $gate->can(ADMIN_ACCESS);
--     if (!$adminViewingHidden && $page->hidden) { http_response_code(404); }
--
-- The chat pages are internal / gated by their own controllers, not public
-- navbar entries — the navbar is built from the `navbar` table, so `hidden`
-- has ZERO impact on navigation (same rationale as migrate_captcha_unhide.sql).
-- The correct value is hidden=0: routable, with the controller enforcing
-- access (CHAT_MODERATE / ADMIN_CONFIG_CHAT). A registration migration's
-- INSERT IGNORE cannot rewrite an existing row, so this UPDATE corrects it.
--
-- Named zzz_* so it runs after every page-registration migration. Idempotent,
-- safe to re-run (the guard skips rows already at 0).
-- ============================================================

UPDATE `page`
   SET `hidden` = 0
 WHERE `hidden` <> 0
   AND `url_id` IN (
       'WORDING_CHAT',
       'WORDING_CHAT_STREAM',
       'WORDING_CHAT_LOGIN',
       'WORDING_CHAT_WAIT',
       'WORDING_CHAT_USERS',
       'WORDING_CHAT_PM',
       'WORDING_CHAT_SETTINGS',
       'WORDING_CHAT_HELP',
       'WORDING_CHAT_ADMIN',
       'WORDING_ADMIN_CONFIG_CHAT',
       'WORDING_ADMIN_CHAT_FILTERS'
   );

-- ============================================================
-- VERIFICATION:
--   SELECT url_id, file_name, hidden FROM `page`
--    WHERE url_id LIKE '%CHAT%';
--   Expected: every chat page hidden=0.
-- ============================================================
