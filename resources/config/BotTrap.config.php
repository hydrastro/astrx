<?php
declare(strict_types=1);

/**
 * Bot-trap (honeypot labyrinth) — global config. The section key 'BotTrapConfig'
 * matches the holder's short class name; there is no BotTrapConfig.config.php, so
 * ModuleLoader falls back to this file (named after the parent namespace segment
 * 'BotTrap'), exactly like ImageboardConfig → Imageboard.config.php.
 *
 * Toggle these from the admin panel (Admin → Bot trap) — that persists changes
 * back to this file via ConfigWriter.
 */
return [
    'BotTrapConfig' => [
        'enabled'        => true,  // master switch — while off, /trap renders the normal 404 error page
        'tarpit_seconds' => 1,     // sleep() per hit to waste the bot's time (clamped 0–10)
        'links_per_page' => 5,     // maze links emitted per page (clamped 1–20); each hit stays O(1)
        'log_hits'       => true,  // record each hit (Tor-safe: hashed identity, never a raw IP)
    ],
];
