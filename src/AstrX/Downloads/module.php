<?php
declare(strict_types=1);

/**
 * Signed-downloads module manifest — the public /downloads release-manifest page
 * plus its admin editor (WORDING_DOWNLOADS / WORDING_ADMIN_DOWNLOADS). An
 * independently toggleable module: disabling it 404s both pages and drops their
 * nav entries, without affecting the sibling transparency pages.
 *
 * No nav contributor or guard needed: its navbar entries are DB rows NavbarHandler
 * drops via page.module when the module is off, and the core ModulePageGuard 404s
 * its pages. Storage is the shared site_config KV (manifest_* keys), which the
 * teardown clears on purge. See docs/MODULES.md.
 */
return [
    'key'          => 'downloads',
    'name'         => 'Downloads',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'downloads.down.sql',
];
