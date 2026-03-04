<?php

declare(strict_types = 1);

use AstrX\Routing\UrlMode;

return [
    // -----------------------------
    // Routing (mechanics + policy)
    // -----------------------------
    // Domain name "Routing" is fine; you can also call it "UrlHandler" for legacy familiarity.
    'Routing' => [
        // UrlMode::REWRITE or UrlMode::QUERY
        'mode' => UrlMode::REWRITE->value,

        // where the app is mounted (reverse proxy etc.)
        'base_path'   => '/',

        // used only in query mode
        'entry_point' => 'index.php',

        // canonical keys used internally by RouteState / RequestBag
        'locale_key'  => 'lang',
        'session_key' => 'sid',
        'page_key'    => 'page',

        // page default if empty URL: / or ?page=
        'default_page' => 'main',

        // Optional: whether to include default locale in rewrite URLs
        // if false, /en/... is omitted when locale==default_language
        'include_default_locale_in_rewrite' => false,
    ]
];