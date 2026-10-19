<?php
declare(strict_types=1);

namespace AstrX\Search;

use AstrX\Module\Module;

/**
 * Site-wide search module (the /search page, the admin crawler page and
 * the search_index tables). No nav contributor or guard needed: its navbar entry
 * is a DB row that NavbarHandler drops when the module is off (via page.module),
 * and the core ModulePageGuard 404s its pages. Board search stays with the
 * imageboard module. See {@see \AstrX\Module\ModuleRegistry}.
 */
final class SearchModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'search',
            'name'         => 'Search',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_search' => \AstrX\Search\Controller\AdminSearchController::class,
                'site_search' => \AstrX\Search\Controller\SiteSearchController::class,
            ],
            'teardown'     => 'search.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
        ];
    }
}
