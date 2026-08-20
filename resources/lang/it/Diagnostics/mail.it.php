<?php
declare(strict_types=1);

use AstrX\Mail\Diagnostic\ImapAppendDiagnostic;
use AstrX\Mail\Diagnostic\ImapCommandFailedDiagnostic;
use AstrX\Mail\Diagnostic\ImapConnectDiagnostic;
use AstrX\Mail\Diagnostic\ImapFetchDiagnostic;
use AstrX\Mail\Diagnostic\ImapStartTlsDiagnostic;
use AstrX\Mail\Diagnostic\MailApiErrorDiagnostic;
use AstrX\Mail\Diagnostic\MailInvalidPayloadDiagnostic;
use AstrX\Mail\Diagnostic\MailInvalidRecipientDiagnostic;
use AstrX\Mail\Diagnostic\MailSendFailedDiagnostic;
use AstrX\Mail\Diagnostic\MailerNotConfiguredDiagnostic;
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
            return 'Impossibile connettersi al server di posta: ' . $d->detail();
        },

    'astrx.mail/imap.command' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapCommandFailedDiagnostic);
            return 'Comando del server di posta non riuscito: ' . $d->detail();
        },

    'astrx.mail/imap.fetch' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapFetchDiagnostic);
            return 'Impossibile recuperare il messaggio: ' . $d->detail();
        },

    'astrx.mail/imap.append' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapAppendDiagnostic);
            return 'Impossibile salvare il messaggio nella cartella: ' . $d->detail();
        },

    'astrx.mail/imap.starttls' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ImapStartTlsDiagnostic);
            return 'Negoziazione STARTTLS non riuscita: ' . $d->detail();
        },

    // ── SMTP / Mailer ─────────────────────────────────────────────────────────

    'astrx.mail/send_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MailSendFailedDiagnostic);
            return 'Invio del messaggio non riuscito: ' . $d->detail();
        },

    // Volutamente generico: il valore non valido è riportato nella diagnostica
    // solo per i log e non deve mai essere mostrato all'utente.
    'astrx.mail/invalid_recipient' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Impossibile inviare il messaggio: un indirizzo del destinatario, del mittente o un campo di intestazione non è valido.",

    // ── API di gestione delle caselle di posta ────────────────────────────────

    'astrx.mail/mailapi_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MailApiErrorDiagnostic);
            return 'Errore di gestione della casella di posta: ' . $d->detail();
        },

    'astrx.mail/invalid_payload' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'La API di gestione delle caselle di posta ha restituito una risposta non analizzabile.',

    // ── Database dei mittenti attendibili ──────────────────────────────────────

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

    // ── Mailer non configurato ─────────────────────────────────────────────────

    'astrx.mail/not_configured' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MailerNotConfiguredDiagnostic);
            return "Il mailer non è configurato. Le email in uscita (ad es. i token di verifica) non possono essere inviate. Collega PHPMailer in RegisterController per silenziare l'avviso.";
        },

    // ── Errore generico del mailer / modello email mancante ───────────────────

    'astrx.mail/error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Impossibile inviare il messaggio. Riprova più tardi.',

    'astrx.email/template_missing' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il modello dell\'email è mancante, quindi non è stato inviato alcun messaggio. '
        . 'Verifica che resources/template/email/ sia installato.',
];
