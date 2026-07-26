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
 * NOTE (Phase 1): flipping a module off here removes its UI surface (nav /
 * partials / footer link / page guards). Its pages still exist in the database
 * until per-module install/uninstall migrations land (Phase 2); to remove a
 * module's pages entirely today, also skip/roll back its schema seed. More
 * modules (search, webmail, …) join this list as they are wired to the registry.
 */
return [
    'Modules' => [
        'imageboard' => true,
        'chat'       => true,
        'bottrap'    => true,
    ],
];
