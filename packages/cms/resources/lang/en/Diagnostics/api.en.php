<?php
declare(strict_types=1);

use AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic;
use AstrX\Api\Diagnostic\InvalidApiKeyLabelDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — en: API key management (the account settings and admin pages).
 */
return [
    'astrx.api/key_create_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'Could not create the API key. Please try again later.';
        },

    'astrx.api/key_label_required' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyLabelDiagnostic);
            return 'Please give the new key a label so you can remember what it\'s for.';
        },

    'astrx.api/key_label_too_long' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyLabelDiagnostic);
            return 'API key label must be 64 characters or fewer.';
        },

    'astrx.api/key_create_forbidden' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'You do not have permission to create API keys.';
        },

    'astrx.api/key_revoke_forbidden' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'You do not have permission to revoke API keys.';
        },
];
