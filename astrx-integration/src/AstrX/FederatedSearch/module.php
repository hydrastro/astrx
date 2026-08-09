<?php
declare(strict_types=1);

/**
 * Unified (federated) search module manifest (the /search-all page). A thin
 * aggregator that fans ONE query out to four sources behind no-JS `?source=`
 * tabs: internal AstrX content (in-process, via SiteSearchService) plus the three
 * standalone localhost engines — websearch, onioncrawler and torrentds — queried
 * over their JSON APIs by a zero-dependency, bounded, size-capped bridge. It owns
 * no database tables; every external index lives in its engine.
 *
 * No nav contributor or guard needed: its navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module), and the core
 * ModulePageGuard 404s its page. This page does NOT replace the internal site
 * search (module 'search') or the dedicated per-engine pages (websearch /
 * onionsearch / torrentsearch); it aggregates them. See
 * {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'fedsearch',
    'name'         => 'Federated search',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'fedsearch.down.sql',
];
