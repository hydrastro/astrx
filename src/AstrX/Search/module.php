<?php
declare(strict_types=1);

/**
 * Site-wide search module manifest (the /search page, the admin crawler page and
 * the search_index tables). No nav contributor or guard needed: its navbar entry
 * is a DB row that NavbarHandler drops when the module is off (via page.module),
 * and the core ModulePageGuard 404s its pages. Board search stays with the
 * imageboard module. See {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'search',
    'name'         => 'Search',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'search.down.sql',
];
