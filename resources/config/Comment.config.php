<?php
declare(strict_types=1);

return [
    'CommentService' => [
        'comments_per_page'  => 20,
        'allow_replies'      => true,
        'require_email'      => false,
        'minimum_flood_secs' => 10,
        'antispam_time_secs' => 30,
        'antispam_regex'     => [
            1 => [
                'regex'   => '/(.+\n){10,}/',
                'enabled' => true,
                'message' => 'Comment contains too many line breaks.',
            ],
            2 => [
                'regex'   => '/(?s).{3000,}/',
                'enabled' => true,
                'message' => 'Comment is too long (max 3000 characters).',
            ],
        ],
    ],
];