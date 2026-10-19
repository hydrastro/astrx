<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.imageboard/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database sulla board. Riprova.',

    'astrx.imageboard/empty' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il post deve contenere testo o un\'immagine.',

    'astrx.imageboard/too_long' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il post è troppo lungo.',

    'astrx.imageboard/no_board' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quella board non esiste.',

    'astrx.imageboard/no_thread' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quel thread non esiste.',

    'astrx.imageboard/locked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quel thread è bloccato.',

    'astrx.imageboard/image_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Impossibile salvare l\'immagine. Riprova.',

    'astrx.imageboard/disabled' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'L\'imageboard è attualmente disattivata.',

    'astrx.imageboard/cooldown' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Stai pubblicando troppo velocemente. Attendi un momento e riprova.',

    'astrx.imageboard/thread_full' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Questo thread ha raggiunto il limite di risposte ed è stato bloccato.',

    'astrx.imageboard/censored' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il tuo messaggio è stato bloccato dal filtro parole.',
];
