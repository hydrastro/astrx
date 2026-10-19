<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Module\Module;

/**
 * Imageboard module.
 *
 * Installed by listing it in ApplicationModules::INSTALLED; read by
 * {@see \AstrX\Module\ModuleRegistry}. Core names no module. `nav` is a NavContributor whose vars are merged when the
 * module is on; `nav_defaults` are merged instead when it is off (inert no-ops).
 * `teardown` is a file in the module's resources/sql/ that tools/module.php purge runs.
 */
final class ImageboardModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'imageboard',
            'name'         => 'Imageboard',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Permission enums this module declares (listed in the grants editor).
            'permissions'  => [\AstrX\Imageboard\ImageboardPermission::class],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_boards' => \AstrX\Imageboard\Controller\AdminBoardsController::class,
                'admin_config_imageboard' => \AstrX\Imageboard\Controller\AdminConfigImageboardController::class,
                'board' => \AstrX\Imageboard\Controller\BoardController::class,
                'board_feed' => \AstrX\Imageboard\Controller\BoardFeedController::class,
                'board_file' => \AstrX\Imageboard\Controller\BoardFileController::class,
                'board_mod' => \AstrX\Imageboard\Controller\BoardModController::class,
                'board_overboard' => \AstrX\Imageboard\Controller\BoardOverboardController::class,
                'board_search' => \AstrX\Imageboard\Controller\BoardSearchController::class,
            ],
            'teardown'     => 'imageboard.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
            // Core extension points this module answers (see docs/MODULES.md).
            'extensions'   => [
                \AstrX\Retention\RetentionSource::class => [\AstrX\Imageboard\ImageboardRetention::class],
            ],
            // The board navbars join the nav stack (they show only on board pages).
            'layout'       => ['nav' => ['partials/board_nav']],
        ];
    }
}
