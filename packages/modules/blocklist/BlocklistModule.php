<?php
declare(strict_types=1);

namespace AstrX\Blocklist;

use AstrX\Module\Module;

/**
 * Blocklist editor module (the /admin-blocklist page). An AstrX ADMIN
 * page that pushes abuse-blocklist entries to the two write-capable astrx-suite
 * engines — onioncrawler (POST /blocklist) and torrentds (POST /api/block) — over
 * loopback HTTP with a server-side admin token. It owns no database tables; the
 * blocklists live inside the external engines.
 *
 * No nav contributor or guard needed: its admin-navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module); the core
 * ModulePageGuard 404s its page, and the controller additionally gates on
 * AdminPermission::ACCESS. See {@see \AstrX\Module\ModuleRegistry}.
 */
final class BlocklistModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'blocklist',
            'name'         => 'Blocklist editor',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_blocklist' => \AstrX\Blocklist\Controller\AdminBlocklistController::class,
            ],
            'teardown'     => 'blocklist.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
        ];
    }
}
