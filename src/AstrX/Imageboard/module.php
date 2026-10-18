<?php
declare(strict_types=1);

/**
 * Imageboard module manifest.
 *
 * Discovered by {@see \AstrX\Module\ModuleRegistry} (which globs
 * src/AstrX/<Module>/module.php). Declaring a module is dropping one of these —
 * core names no module. `nav` is a NavContributor whose vars are merged when the
 * module is on; `nav_defaults` are merged instead when it is off (inert no-ops).
 * `teardown` is a file under src/setup/modules/ that tools/module.php purge runs.
 */
return [
    'key'          => 'imageboard',
    'name'         => 'Imageboard',
    'version'      => '1.0.0',
    'nav'          => \AstrX\Imageboard\ImageboardNavContributor::class,
    'nav_defaults' => ['board_nav' => false],
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
];
