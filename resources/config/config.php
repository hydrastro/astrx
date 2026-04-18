<?php
declare(strict_types=1);

use AstrX\ErrorHandler\EnvironmentType;

$env = getenv('APP_ENV') ?: 'development';

return [
    'Prelude' => [
        'environment'         => $env === 'production'
                                    ? EnvironmentType::PRODUCTION->value
                                    : EnvironmentType::DEVELOPMENT->value,
        'available_languages' => ['en', 'it'],
        'default_language'    => 'en',
    ],
    'ModuleLoader' => [
        'config_dir'      => CONFIG_DIR,
        'lang_dir'        => LANG_DIR,
        'config_optional' => true,
        'lang_optional'   => true,
    ],
    'ErrorHandler' => [
        'failsafe_template' => TEMPLATE_DIR . '/failsafe.html',
        'production_mask'   => E_ALL & ~E_NOTICE,
    ],
    'Injector' => [
        'helpers_strict' => true,
    ],
];
