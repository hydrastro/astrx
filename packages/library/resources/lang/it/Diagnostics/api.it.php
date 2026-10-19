<?php
declare(strict_types=1);

use AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — it: API key authentication.
 */
return [
    'astrx.api/key_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'La chiave API fornita non è valida, è scaduta o è stata revocata.';
        },
];
