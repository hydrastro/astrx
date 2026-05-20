-- ============================================================
-- AstrX migration: enable the profile page as the first API endpoint (fix100)
-- ============================================================

-- The profile page is now a public read-only endpoint exposing
-- safe-to-share user fields. Tagged at the controller via
-- ContextScope::SHARED — see ProfileController.php.
UPDATE `page` SET `api_enabled` = 1 WHERE `url_id` = 'WORDING_PROFILE';
