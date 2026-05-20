-- ============================================================
-- AstrX migration: JS browser namespace hardening (fix-js-browser)
-- Ensures /<locale>/js/ is a visible, template-less controller page.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_JS_APP', 1, 'js', 0, 1, 0, 0);

UPDATE `page`
   SET `file_name` = 'js',
       `template` = 0,
       `controller` = 1,
       `hidden` = 0,
       `comments` = 0
 WHERE `url_id` = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, 'JS browser', 'Experimental client-side browser runtime.'
  FROM `page`
 WHERE url_id = 'WORDING_JS_APP';

UPDATE `page_meta` pm
JOIN `page` p ON p.id = pm.page_id
   SET pm.title = CASE WHEN pm.title = '' THEN 'JS browser' ELSE pm.title END,
       pm.description = CASE WHEN pm.description = '' THEN 'Experimental client-side browser runtime.' ELSE pm.description END
 WHERE p.url_id = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_JS_APP';

UPDATE `page_robots` pr
JOIN `page` p ON p.id = pr.page_id
   SET pr.`index` = 0,
       pr.follow = 0
 WHERE p.url_id = 'WORDING_JS_APP';
