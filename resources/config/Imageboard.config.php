<?php
declare(strict_types=1);

/**
 * Imageboard module — global defaults. Per-board settings live on the `board`
 * DB row and are edited from the board admin panel, not here.
 */
return [
    'ImageboardConfig' => [
        'upload_dir'          => '/app/resources/board_uploads',
        'upload_max_kb'       => 4096,
        'upload_max_pixels'   => 16000000, // header pixel-budget: reject decompression bombs pre-decode
        'full_max_dimension'  => 1600,
        'thumb_max_dimension' => 250,
        'upload_types'        => 'jpg,jpeg,png,gif,webp',
        'anon_name'           => 'Anonymous',
        'guest_captcha'       => true,
        'store_poster_ip'     => false, // onion-first: do NOT persist poster IP at rest (enable only on clearnet)
        'default_max_replies' => 500,   // thread auto-locks past this; 0 = unlimited (bounds thread-view cost)
        'flag_base_path'      => '/flags',
        'threads_per_page'    => 10,
        'preview_replies'     => 5,
        'allow_authenticated_posts' => true, // logged-in users may post under their account without a captcha
        // Post-author name colour per role (UserGroup name : CSS colour). Roles
        // match by name, so a role added later is coloured by adding an entry;
        // unlisted roles use the theme's default name colour.
        'role_colors'         => 'ADMIN:red,MOD:purple,USER:white',
    ],
];
