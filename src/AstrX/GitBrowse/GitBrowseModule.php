<?php
declare(strict_types=1);

namespace AstrX\GitBrowse;

use AstrX\Module\Module;

/**
 * Git browser link-through module (the /gitbrowse page). gitweb is a
 * standalone, server-rendered HTML app with NO JSON API, so this module never
 * reimplements or proxies it — the page is a single card that links OUT to the
 * configured gitweb service URL. It owns no database tables and makes no backend
 * calls.
 *
 * No nav contributor or guard needed: its navbar entry is a DB row that
 * NavbarHandler drops when the module is off (via page.module), and the core
 * ModulePageGuard 404s its page. See {@see \AstrX\Module\ModuleRegistry}.
 */
final class GitBrowseModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'gitbrowse',
            'name'         => 'Git browser',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'git_browse' => \AstrX\GitBrowse\Controller\GitBrowseController::class,
            ],
            'teardown'     => 'gitbrowse.down.sql',
        ];
    }
}
