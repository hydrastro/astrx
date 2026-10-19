-- Content: the foreign key on content_link.to_id.
--
-- Core's migrate_zzzz_indexes_and_content_fk.sql adds this key — but only if
-- content_link already exists when that migration runs. A site that installs
-- the Content module later (or purges it and installs it again: purge also
-- forgets this module's migrations, so they re-run) would never get it, and
-- a ledgered migration cannot be edited once shipped. This one belongs to the
-- module, runs whenever its tables are new, and is a no-op where the key is
-- already there.
--
-- to_id is the resolved target of a [[wiki]] link; NULL means "broken". With
-- ON DELETE SET NULL the database keeps it honest when a page goes away by any
-- route, not just ContentPageRepository::delete(). Orphans are cleaned first:
-- the constraint cannot be added over them.

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
