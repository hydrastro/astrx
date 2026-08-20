<?php

declare(strict_types = 1);

use AstrX\I18n\Diagnostic\LangWriteDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Language-editor diagnostics (AstrX\I18n\LangCatalog) — en locale.
 *
 * All ten of these had no catalog entry in either locale, so the admin
 * Language editor rendered the raw stamp — e.g. saving a translation with
 * resources/lang/ read-only (the state secure-config.sh leaves behind) put the
 * literal "[FALLBACK:ERROR] astrx.i18n/lang_write_failed" on the page.
 *
 * LangWriteDiagnostic::message() carries the offending path, which is a
 * server-side filesystem path: it is shown because this whole surface is
 * admin-only and the path is exactly what the operator has to go and fix.
 */
return [
    'astrx.i18n/lang_write_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Could not save the translation file: ' . $d->message()
                . ' Nothing was written — check that resources/lang/ is writable.';
        },

    'astrx.i18n/lang_domain_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Invalid translation domain: ' . $d->message();
        },

    'astrx.i18n/lang_not_editable' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'That translation file is not editable from the admin UI: ' . $d->message();
        },

    'astrx.i18n/lang_code_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Invalid language code: ' . $d->message();
        },

    'astrx.i18n/lang_exists' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'That language already exists: ' . $d->message();
        },

    'astrx.i18n/lang_source_missing' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Source language not found: ' . $d->message();
        },

    'astrx.i18n/lang_primary_protected' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'The primary language cannot be deleted: ' . $d->message();
        },

    'astrx.i18n/lang_delete_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Could not delete the language: ' . $d->message();
        },

    'astrx.i18n/lang_mkdir_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Could not create the language directory: ' . $d->message();
        },

    'astrx.i18n/lang_copy_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Could not copy the source translation files: ' . $d->message();
        },
];
