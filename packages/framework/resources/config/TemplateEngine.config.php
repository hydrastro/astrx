<?php

declare(strict_types = 1);

return [
    // -----------------------------
    // TemplateEngine
    // -----------------------------
    'TemplateEngine' => [
        // The site's template directory and compiled-template cache: the
        // TEMPLATE_DIR / TEMPLATE_CACHE_DIR the front controller defines.
        'template_dir'       => \AstrX\Support\templateDir(),
        'template_extension' => '.html',
        'template_cache_dir' => \AstrX\Support\cacheDir(),
        'cache_templates'    => true,

        // compile mode
        'parse_mode'         => 1, // 0 plain, 1 template (if you keep that switch)
    ]
];
