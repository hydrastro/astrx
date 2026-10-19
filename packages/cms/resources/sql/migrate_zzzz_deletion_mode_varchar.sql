-- Widen user.deletion_mode from the legacy TINYINT to VARCHAR(16) so the
-- string-backed DeletionMode enum (none|full_delete|hard_redact|soft_redact|
-- keep_visible|keep_suspended) stores correctly.
--
-- Fresh installs already ship VARCHAR(16) via tables.sql; this migration upgrades
-- databases first provisioned when the column was TINYINT. Without it, every
-- redact/delete writes an enum STRING into a TINYINT column: under strict SQL
-- mode (the modern MariaDB default) the UPDATE throws and the account is never
-- deleted/redacted (PII silently retained); under non-strict mode the string
-- coerces to 0, corrupting the marker.
--
-- The runner auto-applies every migrate_*.sql (glob) and ignores only
-- 42S01/42S21/23000, so a real failure here is NOT swallowed. Named "zzzz" so it
-- sorts after the other migrations. Safe to run repeatedly: once the column is
-- VARCHAR the integer-mapping UPDATE matches nothing and the MODIFY is a no-op.

-- 1) Map any legacy integer codes to their enum string BEFORE widening.
--    Historical TINYINT mapping: 0=full 1=hard_redact 2=soft_redact
--    3=keep_visible 4=keep_suspended (NULL = none, left untouched).
UPDATE `user`
   SET `deletion_mode` = CASE `deletion_mode`
       WHEN '0' THEN 'full_delete'
       WHEN '1' THEN 'hard_redact'
       WHEN '2' THEN 'soft_redact'
       WHEN '3' THEN 'keep_visible'
       WHEN '4' THEN 'keep_suspended'
       ELSE `deletion_mode`
   END
 WHERE `deletion_mode` IN ('0', '1', '2', '3', '4');

-- 2) Widen the column so the enum strings fit.
ALTER TABLE `user` MODIFY `deletion_mode` VARCHAR(16) NULL;
