<?php
declare(strict_types=1);

return [
    'Prelude' => [
        // EnvironmentType: DEVELOPMENT=0, PRODUCTION=1, TESTING=2, STAGING=3.
        // Default to PRODUCTION so a fresh/undefined install fails closed
        // (errors hidden, assertions off). Set to 0 locally for development.
        'environment' => 1,
        'available_languages' => [
            'en',
            'it',
        ],
        'default_language' => 'en',
    ],
    'ModuleLoader' => [
        'config_dir' => '/app/resources/config/',
        'lang_dir' => '/app/resources/lang/',
        'config_optional' => true,
        'lang_optional' => true,
    ],
    'ErrorHandler' => [
        'failsafe_template' => '/app/resources/template//failsafe.html',
        'production_mask' => 30711,
    ],
    'Injector' => [
        'helpers_strict' => true,
    ],
];
