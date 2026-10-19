<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (module) — it: the Bot trap module's messages (loaded with the site's).
 */
return [
    'astrx.bottrap/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante la registrazione della trappola per bot.',
];
