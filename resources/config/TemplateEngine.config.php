<?php

declare(strict_types = 1);

return [
    // -----------------------------
    // TemplateEngine
    // -----------------------------
    'TemplateEngine' => [
        // NOTE: these are resources/ paths
        'template_dir'       => TEMPLATE_DIR, // define this constant
        'template_extension' => '.html',
        'template_cache_dir' => TEMPLATE_CACHE_DIR,             // define this
        // constant
        'cache_templates'    => true,

        // compile mode
        'parse_mode'         => 1, // 0 plain, 1 template (if you keep that switch)
    ]
];
