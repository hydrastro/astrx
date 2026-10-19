<?php

declare(strict_types = 1);

return [
    // -----------------------------
    // TemplateEngine
    // -----------------------------
    'TemplateEngine' => [
        // The site's template directory and compiled-template cache. Empty
        // means the application's own (AstrX\Support\Paths): set them only to
        // move templates or the cache somewhere else.
        'template_dir'       => '',
        'template_extension' => '.html',
        'template_cache_dir' => '',
        'cache_templates'    => true,

        // compile mode
        'parse_mode'         => 1, // 0 plain, 1 template (if you keep that switch)
    ]
];
