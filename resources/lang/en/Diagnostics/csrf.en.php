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

        return "CSRF token missing for form \"{$d->formId()}\".";
    },
    'astrx.csrf/token_mismatch' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof CsrfTokenMismatchDiagnostic);

        return "CSRF token mismatch for form \"{$d->formId()}\".";
    },
    'astrx.csrf/token_expired' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof CsrfTokenExpiredDiagnostic);

        return "CSRF token expired for form \"{$d->formId()}\"" .
               " (expired at " .
               date('H:i:s', $d->expiredAt()) .
               ").";
    },
];