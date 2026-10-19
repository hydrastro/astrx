<?php
declare(strict_types=1);

use AstrX\Api\Diagnostic\ApiNotEnabledDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — it: the site's JSON API endpoint.
 */
return [
    'astrx.api/not_enabled' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ApiNotEnabledDiagnostic);
            return 'Questa pagina non è esposta tramite API.';
        },

    'astrx.api/internal_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
            'Si è verificato un errore interno.',
];
