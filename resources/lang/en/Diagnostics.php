<?php
declare(strict_types=1);

use AstrX\Config\Diagnostic\ConfigFileInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigNotFoundDiagnostic;
use AstrX\Config\Diagnostic\ConfigSetterInvalidDiagnostic;
use AstrX\ErrorHandler\UncaughtThrowableDiagnostic;
use AstrX\Http\Diagnostic\HeadersAlreadySentDiagnostic;
use AstrX\Http\Diagnostic\InvalidParameterTypeDiagnostic;
use AstrX\Http\Diagnostic\MoveFailedDiagnostic;
use AstrX\Http\Diagnostic\NotAnUploadedFileDiagnostic;
use AstrX\Http\Diagnostic\UnknownMethodDiagnostic;
use AstrX\Http\Diagnostic\UploadErrorDiagnostic;
use AstrX\I18n\Diagnostic\InvalidLanguageArrayDiagnostic;
use AstrX\I18n\Diagnostic\InvalidLanguageFileDiagnostic;
use AstrX\I18n\Diagnostic\MissingTranslationDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Injector\Diagnostic\ClassNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\ClassReflectionDiagnostic;
use AstrX\Injector\Diagnostic\HelperInvalidSignatureDiagnostic;
use AstrX\Injector\Diagnostic\HelperMethodNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\HelperReflectionDiagnostic;
use AstrX\Injector\Diagnostic\MethodNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\UnresolvableParameterDiagnostic;
use AstrX\Result\DiagnosticInterface;
use AstrX\Session\Diagnostic\InvalidPrgIdDiagnostic;
use AstrX\Template\Diagnostic\InvalidDereferenceDiagnostic;
use AstrX\Template\Diagnostic\TemplateEvaluationDiagnostic;
use AstrX\Template\Diagnostic\TemplateFileNotFoundDiagnostic;
use AstrX\Template\Diagnostic\TemplateFileReadFailedDiagnostic;
use AstrX\Template\Diagnostic\UndefinedTokenArgumentDiagnostic;

/**
 * Diagnostic message callables — en locale.
 *
 * Every entry: callable(DiagnosticInterface $d, Translator $t): string
 *
 * Cast $d to the concrete class to access typed getters — no string key
 * coupling, and any rename is caught by static analysis / your IDE.
 *
 * If an ID has no entry here, DiagnosticRenderer produces a stamped fallback:
 *   [FALLBACK:LEVEL] id {key=value, ...}
 * That tells you exactly which callable to add and which vars are available.
 */
return [

    // -------------------------------------------------------------------------
    // Csrf
    // -------------------------------------------------------------------------

    'astrx.csrf/token_missing' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Csrf\Diagnostic\CsrfTokenMissingDiagnostic);
            return "CSRF token missing for form \"{$d->formId()}\".";
        },

    'astrx.csrf/token_mismatch' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Csrf\Diagnostic\CsrfTokenMismatchDiagnostic);
            return "CSRF token mismatch for form \"{$d->formId()}\".";
        },

    'astrx.csrf/token_expired' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Csrf\Diagnostic\CsrfTokenExpiredDiagnostic);
            return "CSRF token expired for form \"{$d->formId()}\""
                   . " (expired at " . date('H:i:s', $d->expiredAt()) . ").";
        },

    // -------------------------------------------------------------------------
    // Level labels — plain strings, keyed by DiagnosticLevel::name.
    // Loaded into DiagnosticRenderer::$levelLabels, not the callable catalog.
    // -------------------------------------------------------------------------

    'level_labels' => [
        'DEBUG'     => 'Debug',
        'INFO'      => 'Info',
        'NOTICE'    => 'Notice',
        'WARNING'   => 'Warning',
        'ERROR'     => 'Error',
        'CRITICAL'  => 'Critical',
        'ALERT'     => 'Alert',
        'EMERGENCY' => 'Emergency',
    ],

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    'astrx.config/get_config.not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigNotFoundDiagnostic);
            return "Config key \"{$d->getConfigName()}\" not found"
                   . " for module \"{$d->getClassShortName()}\".";
        },

    'astrx.config/config_file.invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigFileInvalidDiagnostic);
            return "Config file does not return an array: \"{$d->getFile()}\".";
        },

    'astrx.config/setter.invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigSetterInvalidDiagnostic);
            return "Config setter \"{$d->getMethodName()}\""
                   . " on \"{$d->getClassName()}\" has an unexpected signature.";
        },

    // -------------------------------------------------------------------------
    // ErrorHandler
    // -------------------------------------------------------------------------

    'astrx.error_handler/uncaught_throwable' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UncaughtThrowableDiagnostic);
            return "Uncaught {$d->getThrowableClass()}: {$d->getMessage()}";
        },

    // -------------------------------------------------------------------------
    // Http
    // -------------------------------------------------------------------------

    'astrx.http/headers_already_sent' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HeadersAlreadySentDiagnostic);
            return "Headers already sent in \"{$d->file()}\" on line {$d->line()}.";
        },

    'astrx.http/invalid_parameter_type' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidParameterTypeDiagnostic);
            return "Parameter \"{$d->key()}\" could not be cast"
                   . " to \"{$d->expectedType()}\".";
        },

    'astrx.http/unknown_method' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UnknownMethodDiagnostic);
            return "Unknown HTTP method: \"{$d->raw()}\".";
        },

    'astrx.http/move_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MoveFailedDiagnostic);
            return "Could not move uploaded file to \"{$d->destination()}\".";
        },

    'astrx.http/not_an_uploaded_file' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof NotAnUploadedFileDiagnostic);
            return "\"{$d->tempPath()}\" is not a valid uploaded file.";
        },

    'astrx.http/upload_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UploadErrorDiagnostic);
            $reason = match ($d->errorCode()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file too large',
                UPLOAD_ERR_PARTIAL                        => 'upload was partial',
                UPLOAD_ERR_NO_FILE                        => 'no file was sent',
                UPLOAD_ERR_NO_TMP_DIR                     => 'missing temp directory',
                UPLOAD_ERR_CANT_WRITE                     => 'failed to write to disk',
                UPLOAD_ERR_EXTENSION                      => 'blocked by a PHP extension',
                default                                   => "unknown error (code {$d->errorCode()})",
            };
            return "Upload failed for \"{$d->clientFilename()}\": {$reason}.";
        },

    // -------------------------------------------------------------------------
    // I18n
    // -------------------------------------------------------------------------

    'astrx.i18n/missing_translation' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MissingTranslationDiagnostic);
            return "Missing translation key \"{$d->key()}\""
                   . " for locale \"{$d->locale()}\".";
        },

    'astrx.i18n/invalid_language_file' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidLanguageFileDiagnostic);
            return "Language file does not return an array: \"{$d->file()}\".";
        },

    'astrx.i18n/invalid_language_array' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidLanguageArrayDiagnostic);
            return "Invalid entry for key \"{$d->key()}\" in \"{$d->file()}\".";
        },

    // -------------------------------------------------------------------------
    // Injector
    // -------------------------------------------------------------------------

    'astrx.injector/class_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ClassNotFoundDiagnostic);
            return "Injector could not find class \"{$d->getClassName()}\".";
        },

    'astrx.injector/class_reflection_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ClassReflectionDiagnostic);
            return "Injector reflection error: {$d->getMessage()}";
        },

    'astrx.injector/method_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MethodNotFoundDiagnostic);
            return "Method \"{$d->getMethodName()}\""
                   . " not found on \"{$d->getClassName()}\".";
        },

    'astrx.injector/helper_method_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HelperMethodNotFoundDiagnostic);
            return "Helper method \"{$d->getMethodName()}\""
                   . " not found on \"{$d->getClassName()}\".";
        },

    'astrx.injector/helper_invalid_signature' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HelperInvalidSignatureDiagnostic);
            return "Helper \"{$d->getMethodName()}\""
                   . " on \"{$d->getClassName()}\" has an invalid signature.";
        },

    'astrx.injector/helper_reflection_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HelperReflectionDiagnostic);
            return "Helper reflection error for \"{$d->getMethodName()}\""
                   . " on \"{$d->getClassName()}\": {$d->getMessage()}";
        },

    'astrx.injector/unresolvable_parameter' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UnresolvableParameterDiagnostic);
            return "Cannot resolve \"\${$d->getParameterName()}\""
                   . " for class \"{$d->getClassName()}\".";
        },

    // -------------------------------------------------------------------------
    // Session
    // -------------------------------------------------------------------------

    'astrx.session/invalid_prg_id' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidPrgIdDiagnostic);
            return "Invalid or unknown PRG token"
                   . " \"" . ($d->prgId() ?? '(null)') . "\" in POST body.";
        },

    // -------------------------------------------------------------------------
    // Template
    // -------------------------------------------------------------------------

    'astrx.template/template_file_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TemplateFileNotFoundDiagnostic);
            return "Template file not found: \"{$d->file()}\".";
        },

    'astrx.template/template_file_read_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TemplateFileReadFailedDiagnostic);
            return "Could not read template file: \"{$d->file()}\".";
        },

    'astrx.template/template_evaluation_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TemplateEvaluationDiagnostic);
            return "Template evaluation failed: {$d->message()}";
        },

    'astrx.template/undefined_token_argument' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UndefinedTokenArgumentDiagnostic);
            return "Undefined template variable: \"{$d->token()}\".";
        },

    'astrx.template/invalid_dereference' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidDereferenceDiagnostic);
            return "Invalid dereference expression: \"{$d->value()}\".";
        },

];