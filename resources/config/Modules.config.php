<?php
declare(strict_types=1);

/**
 * Optional-module switchboard — the single place that turns AstrX's optional
 * modules on or off.
 *
 * Each key is a module; `false` removes that module's navigation, footer hooks
 * and page guards site-wide. Core code never names a module — it reads this list
 * through {@see \AstrX\Module\ModuleRegistry}, so this file is the only thing you
 * touch to drop a module's UI surface. Default is ON, so an existing install
 * behaves exactly as before until you flip something off. Unlisted modules also
 * default ON (ModuleRegistry::enabled() falls back to true).
 *
 * Flipping a module off removes its whole surface: its nav entries drop, its
 * pages 404 (themed error page), and its footer/section hooks disappear. Data is
 * untouched — `tools/module.php enable` restores it; `tools/module.php purge`
 * drops its schema. The module list is discovered from each module's module.php
 * manifest, so this file only holds the on/off flags (unlisted modules default ON).
 */
return [
    'Modules' => [
        'imageboard' => true,
        'chat'       => true,
        'bottrap'    => true,
        'search'     => true,
        'webmail'    => true,
        'content'    => true,
        'media'      => true,
    ],
];
