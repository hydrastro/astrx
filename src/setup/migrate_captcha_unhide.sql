-- ============================================================
-- AstrX migration: unhide captcha-iframe pages (fix112)
--
-- Context: fix104 created the captcha-image and captcha-frame page rows
-- with hidden=1, on the assumption that `hidden` only meant "hide from
-- the navbar". It doesn't — the framework's ContentManager also 404s
-- any hidden page for non-admin users:
--
--     if (!$adminViewingHidden && $page->hidden) {
--         http_response_code(HttpStatus::NOT_FOUND->value);
--     }
--
-- These captcha endpoints are hit by anonymous users during registration,
-- so they MUST be reachable without admin perms. The right pattern (same
-- as 'avatar' id=10 and 'WORDING_LOGOUT' id=19) is hidden=0 — internal,
-- not user-facing, but routable. The navbar is built from the `navbar`
-- table anyway, not by listing non-hidden pages, so flipping the flag
-- has zero impact on what users see in navigation.
--
-- Safe to re-run.
-- ============================================================

UPDATE `page`
   SET `hidden` = 0
 WHERE `url_id` IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- ============================================================
-- VERIFICATION:
--
-- SELECT id, url_id, file_name, template, controller, hidden
--   FROM `page`
--  WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');
--
-- Expected: 2 rows, template=0, controller=1, hidden=0.
-- ============================================================
