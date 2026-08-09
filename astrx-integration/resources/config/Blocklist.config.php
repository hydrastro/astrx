<?php
declare(strict_types=1);

/**
 * Blocklist editor backend — config. The section key 'BlocklistConfig' matches
 * the holder's short class name; there is no BlocklistConfig.config.php, so
 * ModuleLoader falls back to this file (named after the parent namespace segment
 * 'Blocklist'), exactly like BotTrapConfig → BotTrap.config.php.
 *
 * The two *_base_url values MUST be the loopback astrx-suite engines; a
 * non-http(s) value is rejected to that engine's localhost default. The
 * *_admin_token values are SECRETS that authorise a control write on the engine
 * (onioncrawler --admin-token / torrentds admin token). They are read
 * server-side only and never rendered, returned or logged. Leave a token EMPTY to
 * disable pushes to that engine — the editor then reports "token not configured"
 * for it instead of making a call that could only be refused.
 *
 * Keep the real tokens OUT of version control — set them via your deployment's
 * secret mechanism (e.g. secure-config.sh / environment-substituted config).
 */
return [
    'BlocklistConfig' => [
        'onioncrawler_base_url'    => 'http://127.0.0.1:8802', // onioncrawler (POST /blocklist)
        'onioncrawler_admin_token' => '',                       // onioncrawler --admin-token (secret; empty = disabled)
        'torrentds_base_url'       => 'http://127.0.0.1:8804', // torrentds (POST /api/block)
        'torrentds_admin_token'    => '',                       // torrentds admin token (secret; empty = disabled)
        'timeout_seconds'          => 3,                        // per-request network timeout (clamped 1–5)
    ],
];
