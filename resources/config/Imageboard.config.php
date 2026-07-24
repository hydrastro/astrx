<?php
declare(strict_types=1);

/**
 * Imageboard module — global defaults. Per-board settings live on the `board`
 * DB row and are edited from the board admin panel, not here.
 */
return [
    'ImageboardConfig' => [
        'enabled'             => true,
        'upload_dir'          => '/app/resources/board_uploads',
        'upload_max_kb'       => 4096,
        'full_max_dimension'  => 1600,
        'thumb_max_dimension' => 250,
        'upload_types'        => 'jpg,jpeg,png,gif,webp',
        'anon_name'           => 'Anonymous',
        'guest_captcha'       => true,
        'flag_base_path'      => '/flags',
        'threads_per_page'    => 10,
        'preview_replies'     => 5,
    ],
];
