<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    // Generic, user-safe message. The diagnostic still carries the raw driver
    // error for server-side logs; it is deliberately not rendered to the client.
    'astrx.news/db_error' => fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while loading news. Please try again later.',
];