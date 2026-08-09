<?php
declare(strict_types=1);

/**
 * Onion search module manifest (the /onionsearch page). A thin bridge that
 * queries a standalone, localhost-only Python engine (onioncrawler) over its
 * JSON API and renders the hits with AstrX's own template escaping. It owns no
 * database tables — the .onion crawl/index lives entirely in the external
 * engine, which reaches Tor through its own SOCKS proxy (AstrX never does).
 *
 * No nav contributor or guard needed: its navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module), and the core
 * ModulePageGuard 404s its page. This is a SEPARATE page from the internal site
 * search (module 'search') and the clear-web search (module 'websearch'); the
 * three are intentionally kept distinct. See {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'onionsearch',
    'name'         => 'Onion search',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'onionsearch.down.sql',
];
