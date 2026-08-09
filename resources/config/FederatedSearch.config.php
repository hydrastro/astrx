<?php
declare(strict_types=1);

/**
 * Unified (federated) search backend — config. The section key
 * 'FederatedSearchConfig' matches the holder's short class name; there is no
 * FederatedSearchConfig.config.php, so ModuleLoader falls back to this file
 * (named after the parent namespace segment 'FederatedSearch'), exactly like
 * BotTrapConfig → BotTrap.config.php.
 *
 * The three *_base_url values MUST be the loopback astrx-suite engines. Only the
 * scheme+host from each is ever used; FederatedSearchClient appends the fixed
 * path `/api/search?…` with a rawurlencode()d query and no other user input ever
 * reaches these URLs. A non-http(s) value is rejected to that engine's localhost
 * default. The fourth source (internal AstrX content) needs no URL — it is served
 * in-process by SiteSearchService.
 */
return [
    'FederatedSearchConfig' => [
        'websearch_base_url'    => 'http://127.0.0.1:8803', // websearch (clear-web search)
        'onioncrawler_base_url' => 'http://127.0.0.1:8802', // onioncrawler (onion search)
        'torrentds_base_url'    => 'http://127.0.0.1:8804', // torrentds (torrent DHT index)
        'timeout_seconds'       => 3,                        // per-source network timeout (clamped 1–5)
        'per_page'              => 10,                       // results shown per source (clamped 1–50)
    ],
];
