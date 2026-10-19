<?php
declare(strict_types=1);

namespace AstrX\FederatedSearch;

use AstrX\Module\Module;

/**
 * Unified (federated) search module (the /search-all page). A thin
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
final class FederatedSearchModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'fedsearch',
            'name'         => 'Federated search',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'federated_search' => \AstrX\FederatedSearch\Controller\FederatedSearchController::class,
            ],
            'teardown'     => 'fedsearch.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
        ];
    }
}
