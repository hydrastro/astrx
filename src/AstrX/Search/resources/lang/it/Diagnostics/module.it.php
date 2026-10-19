<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (module) — it: the Search module's messages (loaded with the site's).
 */
return [
    'astrx.search/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante la ricerca. Riprova più tardi.',

    'astrx.search/index_rebuild_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Non è stato possibile ricostruire l'indice di ricerca. L'indice precedente è ancora attivo.",

    'astrx.search/index_request_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Non è stato possibile accodare la richiesta di indicizzazione.",

    'astrx.search/index_reset_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Non è stato possibile azzerare l'indice di ricerca.",
];
