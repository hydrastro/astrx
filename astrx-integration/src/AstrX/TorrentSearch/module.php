<?php
declare(strict_types=1);

/**
 * Torrent search module manifest (the /torrentsearch page). A thin bridge that
 * queries a standalone, localhost-only Python engine (torrentds) over its JSON
 * API and renders the hits with AstrX's own template escaping. It owns no
 * database tables — the DHT crawl / torrent metadata store lives entirely in the
 * external engine, which owns the BitTorrent/DHT hop (AstrX never speaks it).
 *
 * No nav contributor or guard needed: its navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module), and the core
 * ModulePageGuard 404s its page. This is the FOURTH, SEPARATE search page: it is
 * intentionally kept distinct from the internal site search (module 'search'),
 * the clear-web search (module 'websearch') and the onion search (module
 * 'onionsearch'). See {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'torrentsearch',
    'name'         => 'Torrent search',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'torrentsearch.down.sql',
];
