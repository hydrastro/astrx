-- ============================================================
-- Indexes for the two hottest sweep queries, removal of index duplicates, and a
-- real foreign key on content_link.to_id.
--
-- PORTABILITY: every conditional step below uses information_schema +
-- PREPARE/EXECUTE instead of MariaDB's `ADD|DROP … IF [NOT] EXISTS` clause
-- forms. Those clauses are a MariaDB extension; MySQL 8 answers them with
-- ER_PARSE_ERROR and the installer stops. Written this way the file runs
-- unchanged on both engines and through BOTH installers (tools/install.php and
-- public/setup.php), and it stays idempotent.
--
-- PREPARE / EXECUTE / DEALLOCATE PREPARE are rejected by MySQL's NATIVE
-- prepared-statement protocol (ER_UNSUPPORTED_PS, 1295), which is what
-- PDO::query() uses when PDO::ATTR_EMULATE_PREPARES is false. Both installers
-- run with emulation on for exactly this reason; tools/install.php now says so
-- explicitly in tryConn().
--
-- Idempotent — safe to re-run.
-- ============================================================


-- ── 1. `session`: two full table scans per GC, under write locks ─────────────
-- SecureSessionHandler::gc() runs
--     DELETE FROM `session` WHERE `timestamp` < :cutoff
--     DELETE FROM `session` WHERE `replace_at` IS NOT NULL AND `replace_at` < :gc
-- and the table had NO index other than its primary key, so both statements
-- scanned every row while holding write locks. GC fires on roughly 1% of
-- session_start() calls (gc_probability=1 / gc_divisor=1000), i.e. constantly on
-- a live site, and on a Tor hidden service the latency budget is already spent
-- getting through the circuit.
SET @sql := IF((SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'session'
                   AND `INDEX_NAME` = 'idx_session_timestamp') > 0,
    'DO 0',
    'ALTER TABLE `session` ADD INDEX `idx_session_timestamp` (`timestamp`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- `replace_at` is one of the OPTIONAL handover columns — a `session` table from
-- before the regeneration-grace migration does not have it, and indexing a
-- column that is not there aborts the migration for everyone on that schema.
SET @has_replace_at := (
    SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
     WHERE `TABLE_SCHEMA` = DATABASE()
       AND `TABLE_NAME`   = 'session'
       AND `COLUMN_NAME`  = 'replace_at'
);
SET @has_replace_idx := (
    SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
     WHERE `TABLE_SCHEMA` = DATABASE()
       AND `TABLE_NAME`   = 'session'
       AND `INDEX_NAME`   = 'idx_session_replace_at'
);
SET @sql := IF(@has_replace_at = 1 AND @has_replace_idx = 0,
    'ALTER TABLE `session` ADD INDEX `idx_session_replace_at` (`replace_at`)',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── 2. `news`: the front page's own query ────────────────────────────────────
-- NewsRepository does
--     SELECT … FROM news WHERE hidden = 0 ORDER BY created_at DESC, id DESC LIMIT :lim
-- against a table with no index at all: a full scan plus a filesort on every
-- render of the front page. The composite covers the filter and both sort keys,
-- so the LIMIT can stop early instead of sorting the whole table first.
SET @sql := IF((SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'news'
                   AND `INDEX_NAME` = 'idx_news_hidden_created') > 0,
    'DO 0',
    'ALTER TABLE `news` ADD INDEX `idx_news_hidden_created` (`hidden`, `created_at`, `id`)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── 3. Drop indexes that duplicate an existing one ───────────────────────────
-- `user`.username / email / mailbox are each declared UNIQUE, which already
-- creates a unique B-tree on the column; the separate plain INDEX on the same
-- column is a second copy that every INSERT and UPDATE has to maintain and that
-- the planner never prefers over the unique one. `diagnostic_visibility`'s
-- idx_code duplicates the leading column of its own PRIMARY KEY (code,
-- group_name), which is already usable as a prefix.
SET @sql := IF((SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'user'
                   AND `INDEX_NAME` = 'idx_username') > 0,
    'ALTER TABLE `user` DROP INDEX `idx_username`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'user'
                   AND `INDEX_NAME` = 'idx_email') > 0,
    'ALTER TABLE `user` DROP INDEX `idx_email`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'user'
                   AND `INDEX_NAME` = 'idx_mailbox') > 0,
    'ALTER TABLE `user` DROP INDEX `idx_mailbox`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM `information_schema`.`STATISTICS`
                 WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'diagnostic_visibility'
                   AND `INDEX_NAME` = 'idx_code') > 0,
    'ALTER TABLE `diagnostic_visibility` DROP INDEX `idx_code`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── 4. content_link.to_id: an index, but no foreign key ──────────────────────
-- to_id is the resolved target of a [[wiki]] link; NULL means "broken". Its
-- integrity was maintained entirely by hand, in one method
-- (ContentPageRepository::delete), so anything that removed a content_page any
-- other way — a manual DELETE, a partial restore, a future code path that
-- forgets — left inbound rows pointing at an id that no longer exists. Those
-- rows are not NULL, so the broken-link report calls them healthy while the
-- rendered page marks them broken and following one 404s. ON DELETE SET NULL
-- makes the database do what that one method does.
--
-- Guarded on the table existing: `tools/module.php purge content` drops it, and
-- an unguarded statement here would abort the whole install for anyone in that
-- state. Orphans are cleaned first — the constraint cannot be added over them.
SET @content_link_exists := (
    SELECT COUNT(*) FROM `information_schema`.`TABLES`
     WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'content_link'
);
SET @sql := IF(@content_link_exists = 1,
    'UPDATE `content_link` l LEFT JOIN `content_page` p ON p.`id` = l.`to_id` SET l.`to_id` = NULL WHERE l.`to_id` IS NOT NULL AND p.`id` IS NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM `information_schema`.`TABLE_CONSTRAINTS`
     WHERE `CONSTRAINT_SCHEMA` = DATABASE()
       AND `TABLE_NAME`        = 'content_link'
       AND `CONSTRAINT_NAME`   = 'fk_content_link_to_id'
);
SET @sql := IF(@content_link_exists = 1 AND @fk_exists = 0,
    'ALTER TABLE `content_link` ADD CONSTRAINT `fk_content_link_to_id` FOREIGN KEY (`to_id`) REFERENCES `content_page` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
