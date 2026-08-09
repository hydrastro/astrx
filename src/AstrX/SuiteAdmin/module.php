<?php
declare(strict_types=1);

/**
 * Suite admin / status-panel module manifest (the /admin-suite page). An AstrX
 * ADMIN page that probes the four standalone astrx-suite engines (gitweb,
 * onioncrawler, websearch, torrentds) for health + key metrics and exposes the
 * single control action any of them offers — submitting an onion seed to
 * onioncrawler's /add. It owns no database tables; all engine state lives in the
 * external engines, reached over loopback HTTP only.
 *
 * No nav contributor or guard needed: its admin-navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module); the core
 * ModulePageGuard 404s its page, and the controller additionally gates on
 * Permission::ADMIN_ACCESS. See {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'suiteadmin',
    'name'         => 'Suite admin',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'suiteadmin.down.sql',
];
