<?php
declare(strict_types=1);

use AstrX\Mail\Diagnostic\ImapAppendDiagnostic;
use AstrX\Mail\Diagnostic\ImapCommandFailedDiagnostic;
use AstrX\Mail\Diagnostic\ImapConnectDiagnostic;
use AstrX\Mail\Diagnostic\ImapFetchDiagnostic;
use AstrX\Mail\Diagnostic\ImapStartTlsDiagnostic;
use AstrX\Mail\Diagnostic\MailApiErrorDiagnostic;
use AstrX\Mail\Diagnostic\MailInvalidPayloadDiagnostic;
use AstrX\Mail\Diagnostic\MailSendFailedDiagnostic;
use AstrX\Mail\Diagnostic\TrustAddFailedDiagnostic;
use AstrX\Mail\Diagnostic\TrustCheckFailedDiagnostic;
use AstrX\Mail\Diagnostic\TrustListFailedDiagnostic;
use AstrX\Mail\Diagnostic\TrustRemoveFailedDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    // ── IMAP ─────────────────────────────────────────────────────────────────

    'astrx.mail/imap.connect' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapConnectDiagnostic);
            return 'Could not connect to the mail server: ' . $d->detail();
        },

    'astrx.mail/imap.command' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapCommandFailedDiagnostic);
            return 'Mail server command failed: ' . $d->detail();
        },

    'astrx.mail/imap.fetch' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapFetchDiagnostic);
            return 'Failed to fetch message: ' . $d->detail();
        },

    'astrx.mail/imap.append' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapAppendDiagnostic);
            return 'Failed to save message to folder: ' . $d->detail();
        },

    'astrx.mail/imap.starttls' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapStartTlsDiagnostic);
            return 'STARTTLS negotiation failed: ' . $d->detail();
        },

    // ── SMTP / Mailer ─────────────────────────────────────────────────────────

    'astrx.mail/send_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MailSendFailedDiagnostic);
            return 'Failed to send message: ' . $d->detail();
        },

    // ── Mailbox management API ────────────────────────────────────────────────

    'astrx.mail/mailapi_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MailApiErrorDiagnostic);
            return 'Mailbox management error: ' . $d->detail();
        },

    'astrx.mail/invalid_payload' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Mailbox API returned an unparseable response.',

    // ── Trusted-sender database ───────────────────────────────────────────────

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