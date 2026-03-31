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
            return 'Impossibile connettersi al server mail: ' . $d->detail();
        },

    'astrx.mail/imap.command' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapCommandFailedDiagnostic);
            return 'Comando al server mail fallito: ' . $d->detail();
        },

    'astrx.mail/imap.fetch' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapFetchDiagnostic);
            return 'Errore nel recupero del messaggio: ' . $d->detail();
        },

    'astrx.mail/imap.append' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapAppendDiagnostic);
            return 'Impossibile salvare il messaggio nella cartella: ' . $d->detail();
        },

    'astrx.mail/imap.starttls' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapStartTlsDiagnostic);
            return 'Negoziazione STARTTLS fallita: ' . $d->detail();
        },

    // ── SMTP / Mailer ─────────────────────────────────────────────────────────

    'astrx.mail/send_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MailSendFailedDiagnostic);
            return 'Invio messaggio fallito: ' . $d->detail();
        },

    // ── Mailbox management API ────────────────────────────────────────────────

    'astrx.mail/mailapi_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MailApiErrorDiagnostic);
            return 'Errore gestione casella postale: ' . $d->detail();
        },

    'astrx.mail/invalid_payload' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'L\'API della casella postale ha restituito una risposta non valida.',

    // ── Trusted-sender database ───────────────────────────────────────────────

    'astrx.mail/trust.check_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustCheckFailedDiagnostic);
            return 'Errore database nella verifica del mittente attendibile: ' . $d->detail();
        },

    'astrx.mail/trust.add_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustAddFailedDiagnostic);
            return 'Errore database nell\'aggiunta del mittente attendibile: ' . $d->detail();
        },

    'astrx.mail/trust.remove_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustRemoveFailedDiagnostic);
            return 'Errore database nella rimozione del mittente attendibile: ' . $d->detail();
        },

    'astrx.mail/trust.list_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TrustListFailedDiagnostic);
            return 'Errore database nel recupero dei mittenti attendibili: ' . $d->detail();
        },
];
