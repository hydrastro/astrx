<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.imageboard/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred on the board. Please try again.',

    'astrx.imageboard/empty' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your post needs text or an image.',

    'astrx.imageboard/too_long' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your post is too long.',

    'astrx.imageboard/no_board' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That board does not exist.',

    'astrx.imageboard/no_thread' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That thread does not exist.',

    'astrx.imageboard/locked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That thread is locked.',

    'astrx.imageboard/image_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The image could not be saved. Please try again.',

    'astrx.imageboard/disabled' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The imageboard is currently disabled.',

    'astrx.imageboard/cooldown' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You are posting too fast. Please wait a moment and try again.',

    'astrx.imageboard/thread_full' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'This thread has reached its reply limit and is now locked.',

    'astrx.imageboard/censored' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your post was blocked by the word filter.',
];
