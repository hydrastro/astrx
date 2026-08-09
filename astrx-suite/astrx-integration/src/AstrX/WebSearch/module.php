<?php
declare(strict_types=1);

/**
 * Clear-web search module manifest (the /websearch page). A thin bridge that
 * queries a standalone, localhost-only Python engine (astrx-websearch) over its
 * JSON API and renders the hits with AstrX's own template escaping. It owns no
 * database tables — the crawl/index lives entirely in the external engine.
 *
 * No nav contributor or guard needed: its navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module), and the core
 * ModulePageGuard 404s its page. This is a SEPARATE page from the internal site
 * search (module 'search') and the onion search (module 'onionsearch'); the
 * three are intentionally kept distinct. See {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'websearch',
    'name'         => 'Clear-web search',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'websearch.down.sql',
];
