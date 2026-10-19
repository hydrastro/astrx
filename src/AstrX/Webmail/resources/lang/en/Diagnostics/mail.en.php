<?php
declare(strict_types=1);

use AstrX\Webmail\Diagnostic\TrustAddFailedDiagnostic;
use AstrX\Webmail\Diagnostic\TrustCheckFailedDiagnostic;
use AstrX\Webmail\Diagnostic\TrustListFailedDiagnostic;
use AstrX\Webmail\Diagnostic\TrustRemoveFailedDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (mail) — en: the Webmail module's messages (loaded with the site's).
 */
return [
    'astrx.mail/trust.check_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustCheckFailedDiagnostic);
            return 'Database error checking sender trust: ' . $d->detail();
        },

    'astrx.mail/trust.add_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustAddFailedDiagnostic);
            return 'Database error adding trusted sender: ' . $d->detail();
        },

    'astrx.mail/trust.remove_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustRemoveFailedDiagnostic);
            return 'Database error removing trusted sender: ' . $d->detail();
        },

    'astrx.mail/trust.list_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustListFailedDiagnostic);
            return 'Database error listing trusted senders: ' . $d->detail();
        },
];
