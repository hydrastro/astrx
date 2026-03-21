<?php
declare(strict_types=1);

use AstrX\Captcha\Diagnostic\CaptchaDbDiagnostic;
use AstrX\Captcha\Diagnostic\CaptchaExpiredDiagnostic;
use AstrX\Captcha\Diagnostic\CaptchaNotFoundDiagnostic;
use AstrX\Captcha\Diagnostic\CaptchaWrongDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.captcha/not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaNotFoundDiagnostic);
            return "Captcha non trovato o già utilizzato.";
        },

    'astrx.captcha/expired' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaExpiredDiagnostic);
            return "Captcha scaduto alle " . date('H:i:s', $d->expiredAt()) . ". Riprova.";
        },

    'astrx.captcha/wrong_text' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaWrongDiagnostic);
            return "Testo captcha errato. Riprova.";
        },

    'astrx.captcha/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaDbDiagnostic);
            return "Errore database nel captcha: {$d->message()}.";
        },
];
