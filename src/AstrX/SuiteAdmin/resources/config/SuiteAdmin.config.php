<?php
declare(strict_types=1);

/**
 * Suite admin / status-panel — config. The section key 'SuiteAdminConfig'
 * matches the holder's short class name; there is no SuiteAdminConfig.config.php,
 * so ModuleLoader falls back to this file (named after the parent namespace
 * segment 'SuiteAdmin'), exactly like BotTrapConfig → BotTrap.config.php.
 *
 * The four *_base_url values MUST be the loopback astrx-suite engines. Only the
 * scheme+host from each is ever used; SuiteAdminClient appends fixed paths
 * (/health, /healthz, /metrics, /api/stats, /add) and no user input ever reaches
 * these URLs. A non-http(s) value is rejected to that engine's localhost default.
 */
return [
    'SuiteAdminConfig' => [
        'gitweb_base_url'       => 'http://127.0.0.1:8801', // gitweb (read-only git viewer)
        'onioncrawler_base_url' => 'http://127.0.0.1:8802', // onioncrawler (onion search / crawler)
        'websearch_base_url'    => 'http://127.0.0.1:8803', // websearch (clear-web search)
        'torrentds_base_url'    => 'http://127.0.0.1:8804', // torrentds (torrent DHT indexer)
        'timeout_seconds'       => 2,                        // per-probe network timeout (clamped 1–5)
    ],
];
