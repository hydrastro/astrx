<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics (module) — en: the Search module's messages (loaded with the site's).
 */
return [
    'astrx.search/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while searching. Please try again later.',

    'astrx.search/index_rebuild_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The search index could not be rebuilt. The previous index is still in place.',

    'astrx.search/index_request_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The search index request could not be queued.',

    'astrx.search/index_reset_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The search index could not be reset.',
];
