<?php
declare(strict_types=1);

namespace AstrX\Mirrors;

use AstrX\Module\Module;

/**
 * Onion-mirrors module — the public /mirrors signed mirror list plus its
 * admin editor (WORDING_MIRRORS / WORDING_ADMIN_MIRRORS). An independently
 * toggleable module: disabling it 404s both pages and drops their nav entries,
 * without affecting the sibling transparency pages.
 *
 * No nav contributor or guard needed: its navbar entries are DB rows NavbarHandler
 * drops via page.module when the module is off, and the core ModulePageGuard 404s
 * its pages. Storage is the shared site_config KV (onion_mirrors, onion_signed,
 * and onion_primary — the latter drives the site-wide Onion-Location header), all
 * of which the teardown clears on purge. See docs/MODULES.md.
 */
final class MirrorsModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'mirrors',
            'name'         => 'Mirrors',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Permission enums this module declares (listed in the grants editor).
            'permissions'  => [\AstrX\Mirrors\MirrorsPermission::class],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_mirrors' => \AstrX\Mirrors\Controller\AdminMirrorsController::class,
                'mirrors' => \AstrX\Mirrors\Controller\MirrorsController::class,
            ],
            'teardown'     => 'mirrors.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
        ];
    }
}
