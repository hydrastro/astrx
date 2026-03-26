<?php
declare(strict_types=1);

use AstrX\Comment\Diagnostic\CommentAntispamDiagnostic;
use AstrX\Comment\Diagnostic\CommentDbDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.comment/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred while processing your comment.',

    'astrx.comment/not_allowed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You are not permitted to post comments.',

    'astrx.comment/flood' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You are posting too quickly. Please wait a moment.',

    'astrx.comment/antispam' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CommentAntispamDiagnostic);
            return $d->detail() !== '' ? $d->detail() : 'Your comment was flagged as spam.';
        },

    'astrx.comment/empty_content' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Comment cannot be empty.',

    'astrx.comment/reply_not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The comment you are replying to does not exist.',

    'astrx.comment/reply_wrong_page' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The comment you are replying to is on a different page.',

    'astrx.comment/invalid_email' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Please provide a valid email address.',

    'astrx.comment/not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Comment not found.',

    'astrx.comment/muted' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You have been temporarily muted and cannot post comments.',

    'astrx.comment/gate_denied' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You do not have permission to perform this action.',
];