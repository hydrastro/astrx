-- Teardown for the site-wide Search module (tools/module.php purge search).
-- Destructive: removes its pages and drops the search index tables. Board search
-- (imageboard) is unaffected. Reinstall the schema (tools/install.php) to restore.

DELETE FROM `page` WHERE `module` = 'search';

DROP TABLE IF EXISTS `search_index`;
DROP TABLE IF EXISTS `search_index_job`;

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
