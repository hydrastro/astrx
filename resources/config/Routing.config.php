<?php

declare(strict_types = 1);


return [
    // Domain name "Routing" is fine; you can also call it "UrlHandler" for legacy familiarity.
    'Routing' => [
        'url_rewrite' => true,

        // where the app is mounted (reverse proxy etc.)
        'base_path'   => '/',

        // used only in query mode
        'entry_point' => 'index.php',

        // canonical keys used internally by RouteState / RequestBag
        'locale_key'  => 'lang',
        'session_key' => 'sid',
        'page_key'    => 'page',

        // page default if empty URL: / or ?page=
        'default_page' => 'WORDING_MAIN',

        // this is something i'll work on later
        'default_keys' => [
            'locale_key',
            'session_key',
            'page_key'
        ],
    ]
];