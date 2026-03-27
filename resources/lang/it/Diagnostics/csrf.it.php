<?php

declare(strict_types = 1);

use AstrX\Csrf\Diagnostic\CsrfTokenExpiredDiagnostic;
use AstrX\Csrf\Diagnostic\CsrfTokenMismatchDiagnostic;
use AstrX\Csrf\Diagnostic\CsrfTokenMissingDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.csrf/token_missing' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof CsrfTokenMissingDiagnostic);

        return "Token CSRF mancante per il modulo \"{$d->formId()}\".";
    },
    'astrx.csrf/token_mismatch' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof CsrfTokenMismatchDiagnostic);

        return "Token CSRF non corrispondente per il modulo \"{$d->formId()}\".";
    },
    'astrx.csrf/token_expired' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof CsrfTokenExpiredDiagnostic);

        return "Token CSRF scaduto per il modulo \"{$d->formId()}\"" .
               " (scaduto alle " .
               date('H:i:s', $d->expiredAt()) .
               ").";
    },
];