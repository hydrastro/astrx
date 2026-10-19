<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (module) — it: the Content module's messages (loaded with the site's).
 */
return [
    'astrx.content/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante il caricamento della pagina. Riprova più tardi.',
];
