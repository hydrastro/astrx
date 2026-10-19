<?php
declare(strict_types=1);

use AstrX\Api\Diagnostic\ApiNotEnabledDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — en: the site's JSON API endpoint.
 */
return [
    'astrx.api/not_enabled' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ApiNotEnabledDiagnostic);
            return 'This page is not exposed via the API.';
        },

    'astrx.api/internal_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
            'An internal error occurred.',
];
