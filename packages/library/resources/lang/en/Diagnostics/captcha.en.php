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
            return "Captcha not found or already used.";
        },

    'astrx.captcha/expired' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaExpiredDiagnostic);
            return "Captcha expired at " . date('H:i:s', $d->expiredAt()) . ". Please try again.";
        },

    'astrx.captcha/wrong_text' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaWrongDiagnostic);
            return "Incorrect captcha text. Please try again.";
        },

    'astrx.captcha/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaDbDiagnostic);
            return "Database error in captcha: {$d->message()}.";
        },

    // Raised when the "new captcha" button cannot swap the challenge text —
    // the per-captcha regeneration cap or its cooldown blocked the UPDATE, or
    // the write failed. Without this entry the user saw the raw
    // "[FALLBACK:ERROR] astrx.captcha/regenerate_failed" stamp.
    'astrx.captcha/regenerate_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaDbDiagnostic);
            return 'Could not generate a new captcha. Please reload the page.';
        },
];
