<?php
declare(strict_types=1);

/**
 * Anonymous tip-line module manifest — the public /tipline encrypted submission
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
return [
    'key'          => 'tipline',
    'name'         => 'Tipline',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'tipline.down.sql',
];
