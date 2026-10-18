<?php
declare(strict_types=1);

namespace AstrX\Canary;

use AstrX\Module\Module;

/**
 * Warrant-canary module — the public /canary trust document plus its
 * admin editor (WORDING_CANARY / WORDING_ADMIN_CANARY). An independently
 * toggleable module: disabling it 404s both pages and drops their nav entries,
 * without affecting the sibling transparency pages (downloads / mirrors / tipline).
 *
 * No nav contributor or guard needed: its navbar entries are DB rows NavbarHandler
 * drops via page.module when the module is off, and the core ModulePageGuard 404s
 * its pages. Storage is the shared site_config KV (canary_* keys), which the
 * teardown clears on purge. See docs/MODULES.md.
 */
final class CanaryModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'canary',
            'name'         => 'Canary',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Permission enums this module declares (listed in the grants editor).
            'permissions'  => [\AstrX\Canary\CanaryPermission::class],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_canary' => \AstrX\Canary\Controller\AdminCanaryController::class,
                'canary' => \AstrX\Canary\Controller\CanaryController::class,
            ],
            'teardown'     => 'canary.down.sql',
        ];
    }
}
