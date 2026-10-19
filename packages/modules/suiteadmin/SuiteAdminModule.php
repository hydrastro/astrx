<?php
declare(strict_types=1);

namespace AstrX\SuiteAdmin;

use AstrX\Module\Module;

/**
 * Suite admin / status-panel module (the /admin-suite page). An AstrX
 * ADMIN page that probes the four standalone astrx-suite engines (gitweb,
 * onioncrawler, websearch, torrentds) for health + key metrics and exposes the
 * single control action any of them offers — submitting an onion seed to
 * onioncrawler's /add. It owns no database tables; all engine state lives in the
 * external engines, reached over loopback HTTP only.
 *
 * No nav contributor or guard needed: its admin-navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module); the core
 * ModulePageGuard 404s its page, and the controller additionally gates on
 * AdminPermission::ACCESS. See {@see \AstrX\Module\ModuleRegistry}.
 */
final class SuiteAdminModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'suiteadmin',
            'name'         => 'Suite admin',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_suite' => \AstrX\SuiteAdmin\Controller\AdminSuiteController::class,
            ],
            'teardown'     => 'suiteadmin.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
        ];
    }
}
