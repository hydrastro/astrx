<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — en: the site's request pipeline (a page with no controller,
 * the panic lockdown).
 */
return [
    'astrx.content/controller_missing' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'This page is configured to use a controller that does not exist.',

    // Also used as the error id in the JSON body of an API 503 during a panic
    // lockdown, so the wording matches that response.
    'astrx.panic/locked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Service temporarily locked.',
];
