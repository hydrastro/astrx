<?php

declare(strict_types = 1);

// NOTE: this file previously declared a stale COPY of the 'Session' section,
// which `loadModuleConfig('Translator')` merged over the real Session config on
// load — clobbering server_secret / regenerate_interval / regenerate_grace_period
// (session-fixation defence, depending on class construction order). It must
// declare the 'Translator' section that the Translator reads and that the admin
// System-config page writes (AdminConfigSystemController::saveTranslator).
return [
    'Translator' => [
        // Extra lang-file directory to load on top of the built-in resources/lang
        // ('' = built-in catalogs only).
        'lang_dir' => '',

        // When a translation key is missing, fall back to rendering the key itself
        // (true) rather than an empty string — easier to spot untranslated strings.
        'fallback_to_key' => true,
    ],
];
