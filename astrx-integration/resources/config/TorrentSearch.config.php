<?php
declare(strict_types=1);

/**
 * Torrent search backend — config. The section key 'TorrentSearchConfig' matches
 * the holder's short class name; there is no TorrentSearchConfig.config.php, so
 * ModuleLoader falls back to this file (named after the parent namespace segment
 * 'TorrentSearch'), exactly like BotTrapConfig → BotTrap.config.php.
 *
 * base_url MUST be the loopback torrentds `search` server. Only the scheme+host
 * from here are ever used to build the request; the user-supplied query, page
 * and (validated hex) infohash are appended safely. A non-http(s) value is
 * rejected to the localhost default. The same origin is used for the user-facing
 * `.torrent` link — magnet links (client-side) work regardless of where base_url
 * points; the `.torrent` link is only reachable if base_url is reachable by the
 * visitor (loopback by default).
 */
return [
    'TorrentSearchConfig' => [
        'base_url'        => 'http://127.0.0.1:8804', // torrentds `search` endpoint (localhost only)
        'timeout_seconds' => 3,                       // per-request network timeout (clamped 1–5)
        'per_page'        => 25,                       // results-per-page (torrentds clamps `limit`; clamped 1–100)
    ],
];
