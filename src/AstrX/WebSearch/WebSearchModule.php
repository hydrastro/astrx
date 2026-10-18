<?php
declare(strict_types=1);

namespace AstrX\WebSearch;

use AstrX\Module\Module;

/**
 * Clear-web search module (the /websearch page). A thin bridge that
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
final class WebSearchModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'websearch',
            'name'         => 'Clear-web search',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'web_search' => \AstrX\WebSearch\Controller\WebSearchController::class,
            ],
            'teardown'     => 'websearch.down.sql',
        ];
    }
}
