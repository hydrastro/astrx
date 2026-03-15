<?php
declare(strict_types=1);

use AstrX\Comment\Diagnostic\CommentDbDiagnostic;
use AstrX\Comment\Diagnostic\CommentDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.comment/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CommentDbDiagnostic);
            return "A database error occurred while processing your comment.";
        },
    'astrx.comment/operation' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CommentDiagnostic);
            return match ($d->operation()) {
                'not_allowed'      => "You are not permitted to post comments.",
                'flood'            => "You are posting too quickly. Please wait a moment.",
                'antispam'         => $d->detail() !== '' ? $d->detail() : "Your comment was flagged as spam.",
                'empty_content'    => "Comment cannot be empty.",
                'reply_not_found'  => "The comment you are replying to does not exist.",
                'reply_wrong_page' => "The comment you are replying to is on a different page.",
                'invalid_email'    => "Please provide a valid email address.",
                'comment_not_found'=> "Comment not found.",
                'gate_denied'      => "You do not have permission to perform this action.",
                default            => "An error occurred (" . $d->operation() . ").",
            };
        },
];
