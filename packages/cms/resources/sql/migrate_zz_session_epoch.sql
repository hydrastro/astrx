-- ============================================================
-- AstrX migration: per-user session epoch (R13)
-- ============================================================
-- Powers admin "force logout" / evict-everywhere. The per-request cookie-session
-- re-validation (ContentManager, R11-04) adopts this value into the session on
-- the first check after login; bumping it (bumpSessionEpoch) makes every session
-- that adopted the old value fail the match and be dropped on its next request —
-- so a compromised or rogue account can be evicted instantly, without waiting for
-- the session to expire. Idempotent.
-- ============================================================

ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `session_epoch` INT UNSIGNED NOT NULL DEFAULT 0;
