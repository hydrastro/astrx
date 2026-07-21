<?php
declare(strict_types=1);

use AstrX\Comment\Diagnostic\CommentAntispamDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.comment/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Si è verificato un errore durante l'elaborazione del commento.",

    'astrx.comment/not_allowed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Non sei autorizzato a pubblicare commenti.',

    'astrx.comment/flood' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Stai pubblicando troppo velocemente. Aspetta un momento.',

    'astrx.comment/antispam' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CommentAntispamDiagnostic);
            return $d->detail() !== '' ? $d->detail() : 'Il tuo commento è stato rilevato come spam.';
        },

    'astrx.comment/empty_content' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il commento non può essere vuoto.',

    'astrx.comment/reply_not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il commento a cui stai rispondendo non esiste.',

    'astrx.comment/reply_wrong_page' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il commento a cui stai rispondendo è su una pagina diversa.',

    'astrx.comment/invalid_email' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Inserisci un indirizzo email valido.',

    'astrx.comment/not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Commento non trovato.',

    'astrx.comment/muted' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Sei stato temporaneamente silenziato e non puoi pubblicare commenti.',

    'astrx.comment/gate_denied' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Non hai i permessi per eseguire questa azione.',

    'astrx.comment/unknown' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Si è verificato un errore durante l'elaborazione del commento.",
];
