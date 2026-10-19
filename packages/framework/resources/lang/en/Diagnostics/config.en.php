<?php

declare(strict_types = 1);

use AstrX\Config\Diagnostic\ConfigFileInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigNotABoolDiagnostic;
use AstrX\Config\Diagnostic\ConfigNotFoundDiagnostic;
use AstrX\Config\Diagnostic\ConfigWriteDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Config-layer diagnostics that ConfigWriter and ModuleLoader emit — en locale.
 * Without an entry here DiagnosticRenderer stamps "[FALLBACK:ERROR]
 * astrx.config/write_failed" and DefaultTemplateContext renders that literal
 * string into the admin page.
 */
return [
    'astrx.config/write_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigWriteDiagnostic);
            return "Could not save the configuration file ({$d->reason}): {$d->path}. "
                . 'Nothing was changed — check the file permissions.';
        },

    // Emitted when a section is written to a file other than the one that
    // declares it; ConfigWriter redirects the write and says so.
    'astrx.config/write_retargeted' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigWriteDiagnostic);
            return "Configuration section saved to the file that declares it ({$d->path}) "
                . "rather than the one the editor asked for ({$d->reason}).";
        },

    // Only reachable with ModuleLoader's config_optional / lang_optional off.
    'astrx.config/resource_missing' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigFileInvalidDiagnostic);
            return "Expected resource not found: {$d->getFile()} — the code that needs it "
                . 'is running on its built-in defaults.';
        },

    // DEBUG-level: a key that was read but is not declared anywhere, so the
    // caller's inline default stood in for it.
    'astrx.config/get_config.defaulted' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigNotFoundDiagnostic);
            return "Config key \"{$d->getConfigName()}\" is absent from section "
                . "\"{$d->getClassShortName()}\" — the built-in default was used.";
        },

    'astrx.config/not_a_bool' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigNotABoolDiagnostic);
            return "Config flag \"{$d->domain()}.{$d->key()}\" holds a {$d->actual()}, not a boolean. "
                . 'Write true or false. The built-in default was used instead.';
        },
];
