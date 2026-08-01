<?php
declare(strict_types=1);

/**
 * Warrant-canary module manifest — the public /canary trust document plus its
 * admin editor (WORDING_CANARY / WORDING_ADMIN_CANARY). An independently
 * toggleable module: disabling it 404s both pages and drops their nav entries,
 * without affecting the sibling transparency pages (downloads / mirrors / tipline).
 *
 * No nav contributor or guard needed: its navbar entries are DB rows NavbarHandler
 * drops via page.module when the module is off, and the core ModulePageGuard 404s
 * its pages. Storage is the shared site_config KV (canary_* keys), which the
 * teardown clears on purge. See docs/MODULES.md.
 */
return [
    'key'          => 'canary',
    'name'         => 'Canary',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'canary.down.sql',
];
