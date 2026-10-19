-- ============================================================
-- SEARCH INDEX (on-demand FULLTEXT crawl of news/pages/comments/board posts)
-- Populated by tools/search_index.php / the admin "Search index" page — never
-- on write. SiteSearchService queries this table (MATCH ... AGAINST BOOLEAN)
-- and merges a live LIKE fallback for content newer than the last crawl.
-- MariaDB supports FULLTEXT on InnoDB, so the engine stays InnoDB throughout.
-- ============================================================
CREATE TABLE IF NOT EXISTS `search_index` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doc_type`   VARCHAR(16)  NOT NULL,                       -- news|pages|comments|board
    `ref_id`     INT          NOT NULL,                       -- source row primary key
    `title`      VARCHAR(255) NOT NULL DEFAULT '',
    `body`       MEDIUMTEXT   NOT NULL,
    `url`        VARCHAR(512) NOT NULL DEFAULT '',
    `doc_time`   INT UNSIGNED NOT NULL DEFAULT 0,             -- source unix time (0 = pages)
    `indexed_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_doc` (`doc_type`, `ref_id`),
    FULLTEXT KEY `ft_body` (`title`, `body`),
    KEY `idx_indexed_at` (`indexed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row job/status table (id is always 1). Tracks the crawl lifecycle so
-- the admin button, the CLI and the cron path all share one view of state.
CREATE TABLE IF NOT EXISTS `search_index_job` (
    `id`           TINYINT UNSIGNED NOT NULL,
    `status`       ENUM('idle','requested','running') NOT NULL DEFAULT 'idle',
    `doc_count`    INT NOT NULL DEFAULT 0,
    `requested_at` TIMESTAMP NULL DEFAULT NULL,
    `started_at`   TIMESTAMP NULL DEFAULT NULL,
    `finished_at`  TIMESTAMP NULL DEFAULT NULL,
    `message`      VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `search_index_job` (`id`, `status`, `doc_count`, `message`)
VALUES (1, 'idle', 0, '');
