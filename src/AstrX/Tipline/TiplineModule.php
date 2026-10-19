<?php
declare(strict_types=1);

namespace AstrX\Tipline;

use AstrX\Module\Module;

/**
 * Anonymous tip-line module — the public /tipline encrypted submission
 * page plus its admin editor / inbox (WORDING_TIPLINE / WORDING_ADMIN_TIPLINE).
 * An independently toggleable module: disabling it 404s both pages and drops their
 * nav entries, without affecting the sibling transparency pages.
 *
 * No nav contributor or guard needed: its navbar entries are DB rows NavbarHandler
 * drops via page.module when the module is off, and the core ModulePageGuard 404s
 * its pages. Unlike the sibling modules it owns a dedicated `tipline` table (sealed
 * tips) plus a site_config pubkey, both dropped by the teardown on purge. The
 * TiplineCrypto / TiplineRepository classes live in this same namespace directory.
 * See docs/MODULES.md.
 */
final class TiplineModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'tipline',
            'name'         => 'Tipline',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Permission enums this module declares (listed in the grants editor).
            'permissions'  => [\AstrX\Tipline\TiplinePermission::class],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_tipline' => \AstrX\Tipline\Controller\AdminTiplineController::class,
                'tipline' => \AstrX\Tipline\Controller\TiplineController::class,
            ],
            'teardown'     => 'tipline.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
        ];
    }
}
