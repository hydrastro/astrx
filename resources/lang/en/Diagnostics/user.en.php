<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;
use AstrX\User\Diagnostic\UserDbDiagnostic;
use AstrX\User\Diagnostic\UserLoginFailedDiagnostic;
use AstrX\User\Diagnostic\UserLoginRestrictedDiagnostic;
use AstrX\User\Diagnostic\UserNotVerifiedDiagnostic;
use AstrX\User\Diagnostic\UserRegistrationClosedDiagnostic;
use AstrX\User\Diagnostic\UserUsernameTakenDiagnostic;
use AstrX\User\Diagnostic\UserEmailTakenDiagnostic;
use AstrX\User\Diagnostic\UserMailboxTakenDiagnostic;
use AstrX\User\Diagnostic\UserInvalidUsernameDiagnostic;
use AstrX\User\Diagnostic\UserInvalidPasswordDiagnostic;
use AstrX\User\Diagnostic\UserInvalidMailboxDiagnostic;
use AstrX\User\Diagnostic\UserPasswordsMismatchDiagnostic;
use AstrX\User\Diagnostic\UserInvalidDateDiagnostic;
use AstrX\User\Diagnostic\UserTooYoungDiagnostic;
use AstrX\User\Diagnostic\UserEmptyFieldsDiagnostic;
use AstrX\User\Diagnostic\UserWrongPasswordDiagnostic;
use AstrX\User\Diagnostic\UserTokenNotFoundDiagnostic;
use AstrX\User\Diagnostic\UserTokenExpiredDiagnostic;
use AstrX\User\Diagnostic\UserTokenAlreadySentDiagnostic;
use AstrX\User\Diagnostic\UserNotFoundDiagnostic;
use AstrX\User\Diagnostic\UserAvatarSizeDiagnostic;
use AstrX\User\Diagnostic\UserAvatarExtensionDiagnostic;
use AstrX\User\Diagnostic\UserAvatarInvalidDiagnostic;
use AstrX\User\Diagnostic\UserAvatarUploadErrorDiagnostic;
use AstrX\User\Diagnostic\UserAvatarMoveFailedDiagnostic;

return [
    'astrx.user/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred. Please try again.',

    'astrx.user/login_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Incorrect username or password.',

    'astrx.user/login_restricted' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Your account type is not allowed to log in.',

    'astrx.user/not_verified' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You must verify your email before logging in.',

    'astrx.user/registration_closed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Registrations are currently closed.',

    'astrx.user/username_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That username is already taken.',

    'astrx.user/email_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That recovery email is already in use.',

    'astrx.user/mailbox_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That email address is already registered.',

    'astrx.user/invalid_username' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserInvalidUsernameDiagnostic);
            return $d->detail() !== '' ? $d->detail() : 'Invalid username format.';
        },

    'astrx.user/invalid_password' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserInvalidPasswordDiagnostic);
            return $d->detail() !== '' ? $d->detail() : 'Invalid password format.';
        },

    'astrx.user/invalid_mailbox' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Invalid email address (login) format.',

    'astrx.user/passwords_mismatch' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The passwords do not match.',

    'astrx.user/invalid_date' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The date of birth is not valid.',

    'astrx.user/too_young' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'You do not meet the minimum age requirement.',

    'astrx.user/empty_fields' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Please fill in all required fields.',

    'astrx.user/wrong_password' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Incorrect password.',

    'astrx.user/token_not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The link is invalid or has already been used.',

    'astrx.user/token_expired' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The link has expired. Please request a new one.',

    'astrx.user/token_already_sent' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A link was already sent recently. Please check your inbox.',

    'astrx.user/not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'No account found with that username or email.',

    'astrx.user/avatar_size' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The uploaded file is too large.',

    'astrx.user/avatar_extension' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That file type is not allowed. Please upload a PNG, JPEG, GIF, or WebP.',

    'astrx.user/avatar_invalid' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The uploaded file is not a valid image.',

    'astrx.user/avatar_upload_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'An error occurred during the file upload.',

    'astrx.user/avatar_move_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Failed to save the uploaded file.',
];