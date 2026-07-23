<?php
declare(strict_types=1);

use AstrX\Api\Diagnostic\ApiNotEnabledDiagnostic;
use AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic;
use AstrX\Api\Diagnostic\InvalidApiKeyLabelDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostic message catalog for the Api module.
 * Each entry receives the typed diagnostic object so callable bodies can
 * access its fields without string-formatting at emit time.
 */
return [
    'astrx.api/not_enabled' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ApiNotEnabledDiagnostic);
            return 'This page is not exposed via the API.';
        },

    'astrx.api/key_create_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'Could not create the API key. Please try again later.';
        },

    'astrx.api/key_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'The supplied API key is invalid, expired, or revoked.';
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

    'astrx.api/internal_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
            'An internal error occurred.',
];
