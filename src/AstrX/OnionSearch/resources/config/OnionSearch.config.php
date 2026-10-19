<?php
declare(strict_types=1);

/**
 * Onion search backend — config. The section key 'OnionSearchConfig' matches the
 * holder's short class name; there is no OnionSearchConfig.config.php, so
 * ModuleLoader falls back to this file (named after the parent namespace segment
 * 'OnionSearch'), exactly like BotTrapConfig → BotTrap.config.php.
 *
 * base_url MUST be the loopback onioncrawler `search` server. Only the
 * scheme+host from here are ever used to build the request; the user-supplied
 * query and page are appended safely. A non-http(s) value is rejected to the
 * localhost default. AstrX never speaks Tor — the engine owns the SOCKS hop.
 */
return [
    'OnionSearchConfig' => [
        'base_url'        => 'http://127.0.0.1:8802', // onioncrawler `search` endpoint (localhost only)
        'timeout_seconds' => 3,                       // per-request network timeout (clamped 1–5)
        'per_page'        => 10,                       // results-per-page hint (engine paginates; clamped 1–50)
    ],
];
