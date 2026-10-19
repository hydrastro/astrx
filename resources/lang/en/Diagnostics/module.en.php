<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Injector\Diagnostic\CircularDependencyDiagnostic;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics from the optional-module subsystems (content, media, search,
 * invites, bot trap, panic lockdown) plus the injector's cycle guard — en
 * locale. Every id here previously rendered as a raw "[FALLBACK:…]" stamp in
 * both locales.
 *
 * Database errors deliberately render a generic sentence: the diagnostic still
 * carries the driver's message for server-side logs, but that message names
 * tables, columns and sometimes values, and this text can reach a public page.
 * Same policy as astrx.news/db_error.
 */
return [
    'astrx.injector/circular_dependency' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CircularDependencyDiagnostic);
            return "Circular dependency while constructing \"{$d->getClassName()}\" — "
                . 'two classes require each other in their constructors.';
        },

    'astrx.content/controller_missing' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'This page is configured to use a controller that does not exist.',





    'astrx.invite/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while handling invites. Please try again later.',

    // Also used as the error id in the JSON body of an API 503 during a panic
    // lockdown, so the wording matches that response.
    'astrx.panic/locked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Service temporarily locked.',
];
