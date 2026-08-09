<?php
declare(strict_types=1);

/**
 * Git browser link-through — config. The section key 'GitBrowseConfig' matches
 * the holder's short class name; there is no GitBrowseConfig.config.php, so
 * ModuleLoader falls back to this file (named after the parent namespace segment
 * 'GitBrowse'), exactly like BotTrapConfig → BotTrap.config.php.
 *
 * service_url is the user-facing address of the standalone gitweb HTML app that
 * this page links out to. gitweb has no JSON API, so AstrX never fetches it —
 * this value only ever becomes the href of a link. Set it to wherever visitors
 * can actually reach gitweb (its loopback default here, or a public/onion URL if
 * you expose it). A non-http(s) value is rejected to the localhost default.
 */
return [
    'GitBrowseConfig' => [
        'service_url' => 'http://127.0.0.1:8801', // gitweb service (user-facing link target)
    ],
];
