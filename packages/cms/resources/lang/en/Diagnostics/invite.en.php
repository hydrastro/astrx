<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — en: invites.
 */
return [
    'astrx.invite/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while handling invites. Please try again later.',
];
