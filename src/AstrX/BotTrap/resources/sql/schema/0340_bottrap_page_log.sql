-- ============================================================
-- BOT-TRAP (honeypot labyrinth) PAGE + hit log
-- Slug WORDING_TRAP ('trap'); file_name 'bot_trap' resolves to BotTrapController
-- via the reflection router (str_replace('_','',ucwords('bot_trap','_')).'Controller').
-- template=0 (the controller emits a raw HTML maze and exit()s), controller=1.
--
-- hidden=0 (NOT 1) IS DELIBERATE. A hidden page is swapped to the 404 error page
-- for non-admins BEFORE its controller runs (see the WORDING_CAPTCHA_* note near
-- the top of this file: `if (!$adminViewingHidden && $page->hidden) { 404 }`), so
-- hidden=1 would stop the trap from EVER executing for a bot — the whole point.
-- It is kept out of navigation by adding NO navbar_entry (the navbar is built
-- from the `navbar` table, not by listing non-hidden pages) and out of honest
-- crawlers by robots.txt (Disallow: /*/trap). Reached only by the hidden footer
-- honeypot link + bots that ignore robots.txt. The controller is self-gated
-- (config `enabled`, default OFF → bare 404) and enforces its own behaviour.
-- Safe to re-run.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_TRAP', 1, 'bot_trap', 1, 1, 0, 0);   -- honeypot maze (site-styled: template=1 → default shell + bot_trap.html)

-- Existing installs seeded before the restyle had template=0 (raw HTML). Flip
-- them so the trap renders through the normal site shell (theme + navbars) via
-- the bot_trap.html content template. Idempotent — safe to re-run.
UPDATE `page` SET `template` = 1 WHERE `url_id` = 'WORDING_TRAP' AND `template` = 0;

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_TRAP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_TRAP';

-- noindex, nofollow — belt-and-braces with the controller's X-Robots-Tag header.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_TRAP';

-- Bot-trap hit log. TOR-SAFE: `ident` is a sha256 hex digest of the session id
-- (or REMOTE_ADDR fallback) — a RAW IP IS NEVER STORED. Free-form strings are
-- truncated to their column widths by BotTrapLogRepository before insert.
CREATE TABLE IF NOT EXISTS `bot_trap_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `path`       VARCHAR(255) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
    `referer`    VARCHAR(255) NOT NULL DEFAULT '',
    `ident`      CHAR(64)     NOT NULL DEFAULT '',       -- sha256(session id | REMOTE_ADDR)
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
