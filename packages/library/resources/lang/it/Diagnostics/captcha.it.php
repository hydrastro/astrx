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

    // Emessa quando il pulsante "nuovo captcha" non riesce a sostituire il testo
    // della sfida: il limite di rigenerazioni o il suo intervallo di attesa hanno
    // bloccato l'UPDATE, oppure la scrittura è fallita. Senza questa voce
    // l'utente vedeva il timbro grezzo "[FALLBACK:ERROR]
    // astrx.captcha/regenerate_failed".
    'astrx.captcha/regenerate_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CaptchaDbDiagnostic);
            return 'Impossibile generare un nuovo captcha. Ricarica la pagina.';
        },
];
