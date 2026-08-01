-- Teardown for the Canary module (tools/module.php purge canary).
-- Destructive: removes its pages and its site_config data. It owns no dedicated
-- table (the attestation lives in the shared site_config KV). Reinstall the schema
-- (tools/install.php) then re-run migrations to restore. Other data is untouched.

DELETE FROM `page` WHERE `module` = 'canary';

DELETE FROM `site_config`
 WHERE `key` IN ('canary_statement', 'canary_interval_days', 'canary_enabled', 'canary_updated_at');

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;
