<?php
declare(strict_types=1);

use AstrX\EnvironmentType;
use AstrX\Routing\UrlMode;

return [
    // -----------------------------
    // Bootstrap / Prelude
    // -----------------------------
    'Prelude' => [
        'environment' => EnvironmentType::DEVELOPMENT->value,

        // Used by ContentManager + ModuleLoader/Translator
        'available_languages' => ['en', 'it'],
        'default_language'    => 'en',
    ],



    // -----------------------------
    // ModuleLoader policy (optional)
    // -----------------------------
    'ModuleLoader' => [
        // config dir and lang dir already covered by constants;
        // keep these here only if you want to decouple from constants later.
        'config_dir' => CONFIG_DIR,
        'lang_dir'   => LANG_DIR,

        // whether module config/lang files are considered optional (recommended)
        'config_optional' => true,
        'lang_optional'   => true,
    ],

    // -----------------------------
    // ErrorHandler policy
    // -----------------------------
    'ErrorHandler' => [
        // render failsafe template if present
        'failsafe_template' => TEMPLATE_DIR . '/failsafe.html',

        // production policy: hide notices etc. (you already do this in code)
        'production_mask' => E_ALL & ~E_NOTICE,
    ],



    // -----------------------------
    // Injector (optional knobs; you may not need these yet)
    // -----------------------------
    'Injector' => [
        // if true: treat helper failures as fatal result::err
        'helpers_strict' => true,
    ],
];