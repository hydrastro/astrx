<?php
declare(strict_types=1);

use AstrX\Webmail\Diagnostic\TrustAddFailedDiagnostic;
use AstrX\Webmail\Diagnostic\TrustCheckFailedDiagnostic;
use AstrX\Webmail\Diagnostic\TrustListFailedDiagnostic;
use AstrX\Webmail\Diagnostic\TrustRemoveFailedDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (mail) — it: the Webmail module's messages (loaded with the site's).
 */
return [
    'astrx.mail/trust.check_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustCheckFailedDiagnostic);
            return "Errore del database durante la verifica dell'attendibilità del mittente: " . $d->detail();
        },

    'astrx.mail/trust.add_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustAddFailedDiagnostic);
            return "Errore del database durante l'aggiunta del mittente attendibile: " . $d->detail();
        },

    'astrx.mail/trust.remove_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustRemoveFailedDiagnostic);
            return 'Errore del database durante la rimozione del mittente attendibile: ' . $d->detail();
        },

    'astrx.mail/trust.list_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustListFailedDiagnostic);
            return "Errore del database durante l'elenco dei mittenti attendibili: " . $d->detail();
        },
];
