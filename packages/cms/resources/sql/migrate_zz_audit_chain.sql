-- ============================================================
-- AstrX migration: tamper-evident audit-log hash chain (R12)
-- ============================================================
-- Adds prev_hash / entry_hash to admin_audit_log so AuditLogger can chain each
-- entry (entry_hash = SHA-256(prev_hash ‖ fields)). Deleting or editing any past
-- entry then breaks the chain, which the admin viewer's "verify" banner detects.
-- Legacy rows (written before this migration) keep empty hashes and are treated
-- as pre-chain by verifyChain(). Idempotent — safe to re-run.
-- ============================================================

ALTER TABLE `admin_audit_log`
    ADD COLUMN IF NOT EXISTS `prev_hash`  CHAR(64) NOT NULL DEFAULT '' AFTER `created_at`;
ALTER TABLE `admin_audit_log`
    ADD COLUMN IF NOT EXISTS `entry_hash` CHAR(64) NOT NULL DEFAULT '' AFTER `prev_hash`;

-- Anchor rows (head hash + monotonic entry count) that AuditLogger advances in
-- the same transaction as each append and verifyChain() compares against, so
-- truncation of the newest/oldest entries is detected. Seeded empty; the first
-- chained append fills them. (site_config is created in tables.sql.)
INSERT IGNORE INTO `site_config` (`key`, `value`)
VALUES ('audit_chain_head', ''), ('audit_chain_count', '0');
