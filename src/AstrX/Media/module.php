<?php
declare(strict_types=1);

/**
 * Media module manifest — a general uploaded-media manager (list / upload /
 * rename / delete, re-usable across content pages) with the same image
 * validation + re-encode the imageboard already ships.
 *
 * No nav contributor or guard needed: its "Media" admin navbar entry is a DB row
 * NavbarHandler drops via page.module when the module is off, and the core
 * ModulePageGuard 404s its pages (admin manager AND the raw file endpoint). See
 * docs/MODULES.md.
 */
return [
    'key'          => 'media',
    'name'         => 'Media',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'media.down.sql',
];
