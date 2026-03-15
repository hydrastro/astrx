<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;
use AstrX\User\Diagnostic\UserDbDiagnostic;
use AstrX\User\Diagnostic\UserDiagnostic;

return [
    // DB error
    'astrx.user/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserDbDiagnostic);
            return "A database error occurred. Please try again.";
        },

    // Business logic — keyed on operation slug
    'astrx.user/operation' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserDiagnostic);
            return match ($d->operation()) {
                'login_failed'          => "Incorrect username or password.",
                'login_restricted'      => "Your account type is not allowed to log in.",
                'not_verified'          => "You must verify your email before logging in.",
                'registration_closed'   => "Registrations are currently closed.",
                'username_taken'        => "That username is already taken.",
                'email_taken'           => "That recovery email is already in use.",
                'mailbox_taken'         => "That email address is already registered.",
                'invalid_username'      => $d->detail() !== '' ? $d->detail() : "Invalid username format.",
                'invalid_mailbox'       => "Invalid email address (login) format.",
                'invalid_password'      => $d->detail() !== '' ? $d->detail() : "Invalid password format.",
                'passwords_mismatch'    => "The passwords do not match.",
                'invalid_date'          => "The date of birth is not valid.",
                'too_young'             => "You do not meet the minimum age requirement.",
                'empty_fields'          => "Please fill in all required fields.",
                'wrong_password'        => "Incorrect password.",
                'token_not_found'       => "The link is invalid or has already been used.",
                'token_expired'         => "The link has expired. Please request a new one.",
                'token_already_sent'    => "A link was already sent recently. Please check your inbox.",
                'user_not_found'        => "No account found with that username or email.",
                'avatar_size'           => "The uploaded file is too large.",
                'avatar_extension'      => "That file type is not allowed. Please upload a PNG, JPEG, GIF, or WebP.",
                'avatar_invalid'        => "The uploaded file is not a valid image.",
                'avatar_upload_error'   => "An error occurred during the file upload.",
                'avatar_move_failed'    => "Failed to save the uploaded file.",
                default                 => "An error occurred (" . $d->operation() . ").",
            };
        },
];