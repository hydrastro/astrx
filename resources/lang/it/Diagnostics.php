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
 * Modelli dei messaggi diagnostici — localizzazione italiana.
 */
return [

    'astrx.config/get_config.not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigNotFoundDiagnostic);
            return "Chiave di configurazione \"{$d->getConfigName()}\""
                   . " non trovata per il modulo \"{$d->getClassShortName()}\".";
        },

    'astrx.config/config_file.invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigFileInvalidDiagnostic);
            return "Il file di configurazione non restituisce un array: \"{$d->getFile()}\".";
        },

    'astrx.config/setter.invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigSetterInvalidDiagnostic);
            return "Il setter \"{$d->getMethodName()}\""
                   . " su \"{$d->getClassName()}\" ha una firma inattesa.";
        },

    'astrx.error_handler/uncaught_throwable' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UncaughtThrowableDiagnostic);
            return "Eccezione non gestita {$d->getThrowableClass()}: {$d->getMessage()}";
        },

    'astrx.http/headers_already_sent' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HeadersAlreadySentDiagnostic);
            return "Header già inviati in \"{$d->file()}\" alla riga {$d->line()}.";
        },

    'astrx.http/invalid_parameter_type' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidParameterTypeDiagnostic);
            return "Il parametro \"{$d->key()}\" non può essere convertito"
                   . " nel tipo \"{$d->expectedType()}\".";
        },

    'astrx.http/unknown_method' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UnknownMethodDiagnostic);
            return "Metodo HTTP sconosciuto: \"{$d->raw()}\".";
        },

    'astrx.http/move_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MoveFailedDiagnostic);
            return "Impossibile spostare il file caricato in \"{$d->destination()}\".";
        },

    'astrx.http/not_an_uploaded_file' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof NotAnUploadedFileDiagnostic);
            return "\"{$d->tempPath()}\" non è un file caricato valido.";
        },

    'astrx.http/upload_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UploadErrorDiagnostic);
            $reason = match ($d->errorCode()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file troppo grande',
                UPLOAD_ERR_PARTIAL                        => 'caricamento parziale',
                UPLOAD_ERR_NO_FILE                        => 'nessun file inviato',
                UPLOAD_ERR_NO_TMP_DIR                     => 'cartella temporanea mancante',
                UPLOAD_ERR_CANT_WRITE                     => 'impossibile scrivere sul disco',
                UPLOAD_ERR_EXTENSION                      => 'bloccato da un\'estensione PHP',
                default                                   => "errore sconosciuto (codice {$d->errorCode()})",
            };
            return "Caricamento fallito per \"{$d->clientFilename()}\": {$reason}.";
        },

    'astrx.i18n/missing_translation' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MissingTranslationDiagnostic);
            return "Chiave di traduzione mancante: \"{$d->key()}\""
                   . " per la lingua \"{$d->locale()}\".";
        },

    'astrx.i18n/invalid_language_file' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidLanguageFileDiagnostic);
            return "Il file di lingua non restituisce un array: \"{$d->file()}\".";
        },

    'astrx.i18n/invalid_language_array' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidLanguageArrayDiagnostic);
            return "Voce non valida per la chiave \"{$d->key()}\""
                   . " nel file \"{$d->file()}\".";
        },

    'astrx.injector/class_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ClassNotFoundDiagnostic);
            return "L'injector non ha trovato la classe \"{$d->getClassName()}\".";
        },

    'astrx.injector/class_reflection_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ClassReflectionDiagnostic);
            return "Errore di reflection nell'injector: {$d->getMessage()}";
        },

    'astrx.injector/method_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof MethodNotFoundDiagnostic);
            return "Metodo \"{$d->getMethodName()}\""
                   . " non trovato su \"{$d->getClassName()}\".";
        },

    'astrx.injector/helper_method_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HelperMethodNotFoundDiagnostic);
            return "Metodo helper \"{$d->getMethodName()}\""
                   . " non trovato su \"{$d->getClassName()}\".";
        },

    'astrx.injector/helper_invalid_signature' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HelperInvalidSignatureDiagnostic);
            return "L'helper \"{$d->getMethodName()}\""
                   . " su \"{$d->getClassName()}\" ha una firma non valida.";
        },

    'astrx.injector/helper_reflection_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof HelperReflectionDiagnostic);
            return "Errore di reflection per \"{$d->getMethodName()}\""
                   . " su \"{$d->getClassName()}\": {$d->getMessage()}";
        },

    'astrx.injector/unresolvable_parameter' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UnresolvableParameterDiagnostic);
            return "Impossibile risolvere \"\${$d->getParameterName()}\""
                   . " per la classe \"{$d->getClassName()}\".";
        },

    'astrx.session/invalid_prg_id' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidPrgIdDiagnostic);
            return "Token PRG"
                   . " \"" . ($d->prgId() ?? '(null)') . "\" non valido nel corpo POST.";
        },

    'astrx.template/template_file_not_found' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TemplateFileNotFoundDiagnostic);
            return "File template non trovato: \"{$d->file()}\".";
        },

    'astrx.template/template_file_read_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TemplateFileReadFailedDiagnostic);
            return "Impossibile leggere il file template: \"{$d->file()}\".";
        },

    'astrx.template/template_evaluation_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof TemplateEvaluationDiagnostic);
            return "Valutazione del template fallita: {$d->message()}";
        },

    'astrx.template/undefined_token_argument' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UndefinedTokenArgumentDiagnostic);
            return "Variabile template non definita: \"{$d->token()}\".";
        },

    'astrx.template/invalid_dereference' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidDereferenceDiagnostic);
            return "Espressione di dereferenziazione non valida: \"{$d->value()}\".";
        },


    // -------------------------------------------------------------------------
    // Etichette di livello
    // -------------------------------------------------------------------------

    'level_labels' => [
        'DEBUG'     => 'Debug',
        'INFO'      => 'Info',
        'NOTICE'    => 'Avviso',
        'WARNING'   => 'Attenzione',
        'ERROR'     => 'Errore',
        'CRITICAL'  => 'Critico',
        'ALERT'     => 'Allerta',
        'EMERGENCY' => 'Emergenza',
    ],

];