-- ============================================================
-- AstrX migration: fix banlist_ip.prefix_len overflow (IPv4 bans)
--
-- Bug: `banlist_ip.prefix_len` was TINYINT — signed, max 127. But an IPv4
-- address is stored as an IPv4-mapped IPv6 network (::ffff:a.b.c.d), so
-- BanlistRepository::parseCidr() reports a /128 prefix (a bare IPv4 /32 + 96).
-- 128 > 127, so EVERY IPv4 ban overflowed the column and the INSERT failed
-- silently (banCidr returned an error the callers treat as best-effort). Net
-- effect: kicked/banned guests were only nick-banned, never IP-banned, so they
-- could rejoin from the same IP by changing nickname.
--
-- Fix: widen to TINYINT UNSIGNED (0-255), which holds 128. Existing values
-- (0-127) are preserved. Idempotent — MODIFY to the same type is a no-op.
--
-- This is a framework-level banlist fix; it also repairs admin IPv4/`/32` bans,
-- not just chat kicks.
-- ============================================================

ALTER TABLE `banlist_ip` MODIFY COLUMN `prefix_len` TINYINT UNSIGNED NOT NULL;

-- ============================================================
-- VERIFICATION:
--   SHOW COLUMNS FROM `banlist_ip` LIKE 'prefix_len';   -- Type: tinyint(3) unsigned
-- ============================================================
