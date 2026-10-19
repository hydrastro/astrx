<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — it: invites.
 */
return [
    'astrx.invite/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante la gestione degli inviti. Riprova più tardi.',
];
