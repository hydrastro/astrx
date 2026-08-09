<?php
declare(strict_types=1);

/**
 * Blocklist editor module manifest (the /admin-blocklist page). An AstrX ADMIN
 * page that pushes abuse-blocklist entries to the two write-capable astrx-suite
 * engines — onioncrawler (POST /blocklist) and torrentds (POST /api/block) — over
 * loopback HTTP with a server-side admin token. It owns no database tables; the
 * blocklists live inside the external engines.
 *
 * No nav contributor or guard needed: its admin-navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module); the core
 * ModulePageGuard 404s its page, and the controller additionally gates on
 * Permission::ADMIN_ACCESS. See {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'blocklist',
    'name'         => 'Blocklist editor',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'blocklist.down.sql',
];
