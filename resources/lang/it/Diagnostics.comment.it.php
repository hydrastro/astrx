<?php
declare(strict_types=1);

use AstrX\Comment\Diagnostic\CommentDbDiagnostic;
use AstrX\Comment\Diagnostic\CommentDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.comment/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            return "Si è verificato un errore durante l'elaborazione del commento.";
        },
    'astrx.comment/operation' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CommentDiagnostic);
            return match ($d->operation()) {
                'not_allowed'      => "Non sei autorizzato a pubblicare commenti.",
                'flood'            => "Stai pubblicando troppo velocemente. Aspetta un momento.",
                'antispam'         => $d->detail() !== '' ? $d->detail() : "Il tuo commento è stato rilevato come spam.",
                'empty_content'    => "Il commento non può essere vuoto.",
                'reply_not_found'  => "Il commento a cui stai rispondendo non esiste.",
                'reply_wrong_page' => "Il commento a cui stai rispondendo è su una pagina diversa.",
                'invalid_email'    => "Inserisci un indirizzo email valido.",
                'comment_not_found'=> "Commento non trovato.",
                'gate_denied'      => "Non hai i permessi per eseguire questa azione.",
                default            => "Si è verificato un errore (" . $d->operation() . ").",
            };
        },
];
