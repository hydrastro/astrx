-- ============================================================
-- AstrX migration: brute-force login lockout column (fix M4)
-- Adds a per-account temporary-lockout expiry to the `user` table.
-- Safe to re-run (idempotent ADD COLUMN IF NOT EXISTS).
-- ============================================================

-- login_locked_until : unix timestamp until which login is refused for this
--                      account, set once `login_lockout_threshold` consecutive
--                      failed logins are reached and held for
--                      `login_lockout_cooldown` seconds. NULL = not locked.
ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `login_locked_until` INT UNSIGNED NULL AFTER `login_attempts`;
