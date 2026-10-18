<?php
declare(strict_types=1);

/**
 * Content module manifest — W/wcms-inspired Markdown pages with `[[wiki]]`
 * interlinking, backlinks, a static-SVG page graph and a broken-link checker.
 *
 * No nav contributor or guard needed: its "Pages"/"Content" navbar entries are DB
 * rows NavbarHandler drops via page.module when the module is off, and the core
 * ModulePageGuard 404s its pages. See docs/MODULES.md.
 */
return [
    'key'          => 'content',
    'name'         => 'Content',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    // Page file_name => controller. Resolved by Site\ControllerMap.
    'controllers'  => [
        'admin_content' => \AstrX\Content\Controller\AdminContentController::class,
        'content' => \AstrX\Content\Controller\ContentController::class,
    ],
    'teardown'     => 'content.down.sql',
];
