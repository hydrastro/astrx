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

    'astrx.content/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while loading this page. Please try again later.',

    'astrx.content/controller_missing' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'This page is configured to use a controller that does not exist.',

    'astrx.media/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while loading the media library. Please try again later.',

    'astrx.media/upload_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The upload could not be stored. Please try again.',

    'astrx.search/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while searching. Please try again later.',

    'astrx.search/index_rebuild_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The search index could not be rebuilt. The previous index is still in place.',

    'astrx.search/index_request_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The search index request could not be queued.',

    'astrx.search/index_reset_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The search index could not be reset.',

    'astrx.invite/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while handling invites. Please try again later.',

    'astrx.bottrap/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while recording the bot-trap hit.',

    // Also used as the error id in the JSON body of an API 503 during a panic
    // lockdown, so the wording matches that response.
    'astrx.panic/locked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Service temporarily locked.',
];
