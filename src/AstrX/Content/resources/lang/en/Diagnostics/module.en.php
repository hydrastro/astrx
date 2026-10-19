<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (module) — en: the Content module's messages (loaded with the site's).
 */
return [
    'astrx.content/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while loading this page. Please try again later.',
];
