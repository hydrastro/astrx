<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (module) — it: the Media module's messages (loaded with the site's).
 */
return [
    'astrx.media/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante il caricamento della libreria multimediale. Riprova più tardi.',

    'astrx.media/upload_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Non è stato possibile salvare il file caricato. Riprova.',
];
