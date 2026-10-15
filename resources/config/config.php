<?php
declare(strict_types=1);

return [
    'Prelude' => [
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
    'Injector' => [
        'helpers_strict' => true,
    ],
];
