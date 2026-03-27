<?php
declare(strict_types=1);

return [
    'Routing' => [
        'url_rewrite' => true,
        'base_path' => '/',
        'entry_point' => 'index.php',
        'locale_key' => 'langg',
        'session_key' => 'sid',
        'page_key' => 'page',
        'default_page' => 'WORDING_MAIN',
        'default_keys' => [
            'locale_key',
            'session_key',
            'page_key',
        ],
    ],
];
