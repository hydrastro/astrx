<?php
declare(strict_types=1);

namespace AstrX\Media;

use AstrX\Module\Module;

/**
 * Media module — a general uploaded-media manager (list / upload /
 * rename / delete, re-usable across content pages) with the same image
 * validation + re-encode the imageboard already ships.
 *
 * No nav contributor or guard needed: its "Media" admin navbar entry is a DB row
 * NavbarHandler drops via page.module when the module is off, and the core
 * ModulePageGuard 404s its pages (admin manager AND the raw file endpoint). See
 * docs/MODULES.md.
 */
final class MediaModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'media',
            'name'         => 'Media',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_media' => \AstrX\Media\Controller\AdminMediaController::class,
                'media_file' => \AstrX\Media\Controller\MediaFileController::class,
            ],
            'teardown'     => 'media.down.sql',
            // Templates, translations and default config (see docs/MODULES.md).
            'resources'    => 'resources',
        ];
    }
}
