-- ============================================================
-- fix124: This migration is now a no-op.
--
-- Its content was folded into the comprehensive src/setup/tables.sql
-- (and setup/02-tables.sql). Keeping this file as a stub so that the
-- setup.php migration runner finds it harmless instead of running the
-- old, now-incorrect version.
--
-- Safe to delete entirely.
-- ============================================================

SELECT 1;
