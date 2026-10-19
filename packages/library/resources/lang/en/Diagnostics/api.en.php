<?php
declare(strict_types=1);

use AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — en: API key authentication.
 */
return [
    'astrx.api/key_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'The supplied API key is invalid, expired, or revoked.';
        },
];
