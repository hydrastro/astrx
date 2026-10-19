<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (module) — en: the Media module's messages (loaded with the site's).
 */
return [
    'astrx.media/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while loading the media library. Please try again later.',

    'astrx.media/upload_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The upload could not be stored. Please try again.',
];
