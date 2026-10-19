<?php
declare(strict_types=1);

use AstrX\Injector\Diagnostic\CircularDependencyDiagnostic;
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
use AstrX\I18n\Translator;
use AstrX\Injector\Diagnostic\ClassNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\ClassReflectionDiagnostic;
use AstrX\Injector\Diagnostic\HelperInvalidSignatureDiagnostic;
use AstrX\Injector\Diagnostic\HelperMethodNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\HelperReflectionDiagnostic;
use AstrX\Injector\Diagnostic\MethodNotFoundDiagnostic;
use AstrX\Injector\Diagnostic\UnresolvableParameterDiagnostic;
use AstrX\Result\DiagnosticInterface;
use AstrX\Template\Diagnostic\InvalidDereferenceDiagnostic;
use AstrX\Template\Diagnostic\TemplateEvaluationDiagnostic;
use AstrX\Template\Diagnostic\TemplateFileNotFoundDiagnostic;
use AstrX\Template\Diagnostic\TemplateFileReadFailedDiagnostic;
use AstrX\Template\Diagnostic\UndefinedTokenArgumentDiagnostic;

/**
 * Diagnostics — it: the framework's (configuration, injection, HTTP, error
 * handling, templates).
 */
return [
    // Config
    'astrx.config/get_config.not_found' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof ConfigNotFoundDiagnostic);

        return "Chiave di configurazione \"{$d->getConfigName()}\" non trovata per il modulo \"{$d->getClassShortName()}\".";
    },
    'astrx.config/config_file.invalid' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof ConfigFileInvalidDiagnostic);

        return "Il file di configurazione non restituisce un array: \"{$d->getFile()}\".";
    },
    'astrx.config/setter.invalid' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof ConfigSetterInvalidDiagnostic);

        return "Il setter \"{$d->getMethodName()}\" su \"{$d->getClassName()}\" ha una firma inattesa.";
    },

    // ErrorHandler
    'astrx.error_handler/uncaught_throwable' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof UncaughtThrowableDiagnostic);

        return "Eccezione non gestita {$d->getThrowableClass()}: {$d->getMessage()}";
    },

    'astrx.error_handler/php_error' => function (
        DiagnosticInterface $d,
        Translator $t
    ): string {
        assert($d instanceof UncaughtThrowableDiagnostic);

        return "Errore PHP: {$d->getMessage()}";
    },

    'astrx.error_handler/fatal_error' => function (
        DiagnosticInterface $d,
        Translator $t
    ): string {
        assert($d instanceof UncaughtThrowableDiagnostic);

        return "Errore fatale: {$d->getMessage()}";
    },

    // Http
    'astrx.http/headers_already_sent' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof HeadersAlreadySentDiagnostic);

        return "Header già inviati in \"{$d->file()}\" alla riga {$d->line()}.";
    },
    'astrx.http/invalid_parameter_type' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof InvalidParameterTypeDiagnostic);

        return "Il parametro \"{$d->key()}\" non può essere convertito nel tipo \"{$d->expectedType()}\".";
    },
    'astrx.http/unknown_method' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof UnknownMethodDiagnostic);

        return "Metodo HTTP sconosciuto: \"{$d->raw()}\".";
    },
    'astrx.http/move_failed' => function (DiagnosticInterface $d, Translator $t)
    : string {
        assert($d instanceof MoveFailedDiagnostic);

        return "Impossibile spostare il file caricato in \"{$d->destination()}\".";
    },
    'astrx.http/not_an_uploaded_file' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof NotAnUploadedFileDiagnostic);

        return "\"{$d->tempPath()}\" non è un file caricato valido.";
    },
    'astrx.http/upload_error' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof UploadErrorDiagnostic);
        $reason = match ($d->errorCode()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file troppo grande',
            UPLOAD_ERR_PARTIAL => 'caricamento parziale',
            UPLOAD_ERR_NO_FILE => 'nessun file inviato',
            UPLOAD_ERR_NO_TMP_DIR => 'cartella temporanea mancante',
            UPLOAD_ERR_CANT_WRITE => 'impossibile scrivere sul disco',
            UPLOAD_ERR_EXTENSION => 'bloccato da un\'estensione PHP',
            default => "errore sconosciuto (codice {$d->errorCode()})",
        };

        return "Caricamento fallito per \"{$d->clientFilename()}\": {$reason}.";
    },

    // Injector
    'astrx.injector/class_not_found' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof ClassNotFoundDiagnostic);

        return "L'injector non ha trovato la classe \"{$d->getClassName()}\".";
    },
    'astrx.injector/class_reflection_error' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof ClassReflectionDiagnostic);

        return "Errore di reflection nell'injector: {$d->getMessage()}";
    },
    'astrx.injector/method_not_found' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof MethodNotFoundDiagnostic);

        return "Metodo \"{$d->getMethodName()}\" non trovato su \"{$d->getClassName()}\".";
    },
    'astrx.injector/helper_method_not_found' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof HelperMethodNotFoundDiagnostic);

        return "Metodo helper \"{$d->getMethodName()}\" non trovato su \"{$d->getClassName()}\".";
    },
    'astrx.injector/helper_invalid_signature' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof HelperInvalidSignatureDiagnostic);

        return "L'helper \"{$d->getMethodName()}\" su \"{$d->getClassName()}\" ha una firma non valida.";
    },
    'astrx.injector/helper_reflection_error' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof HelperReflectionDiagnostic);

        return "Errore di reflection per \"{$d->getMethodName()}\" su \"{$d->getClassName()}\": {$d->getMessage()}";
    },
    'astrx.injector/unresolvable_parameter' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof UnresolvableParameterDiagnostic);

        return "Impossibile risolvere \"\${$d->getParameterName()}\" per la classe \"{$d->getClassName()}\".";
    },

    // Template
    'astrx.template/template_file_not_found' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof TemplateFileNotFoundDiagnostic);

        return "File template non trovato: \"{$d->file()}\".";
    },
    'astrx.template/template_file_read_failed' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof TemplateFileReadFailedDiagnostic);

        return "Impossibile leggere il file template: \"{$d->file()}\".";
    },
    'astrx.template/template_evaluation_failed' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof TemplateEvaluationDiagnostic);

        return "Valutazione del template fallita: {$d->message()}";
    },
    'astrx.template/undefined_token_argument' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof UndefinedTokenArgumentDiagnostic);

        return "Variabile template non definita: \"{$d->token()}\".";
    },
    'astrx.template/invalid_dereference' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof InvalidDereferenceDiagnostic);

        return "Espressione di dereferenziazione non valida: \"{$d->value()}\".";
    },

    // Config — unused key / missing setter key (from fix41)
    'astrx.config/key_unused' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Config\Diagnostic\ConfigKeyUnusedDiagnostic);
            return "La chiave di configurazione \"{$d->key()}\" nel dominio \"{$d->domain()}\" è definita ma non viene mai letta.";
        },

    'astrx.config/setter.key_missing' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Config\Diagnostic\ConfigNotFoundDiagnostic);
            return "Il setter di configurazione dichiara la chiave \"{$d->getConfigName()}\" ma è assente dalla configurazione di \"{$d->getClassShortName()}\".";
        },

    // Injector — method call threw a runtime exception
    'astrx.injector/method_call_exception' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Injector\Diagnostic\MethodCallExceptionDiagnostic);
            return "Eccezione nel metodo Injector {$d->getClassName()}::{$d->getMethodName()}(): {$d->getMessage()}"
;        },

    'astrx.injector/circular_dependency' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CircularDependencyDiagnostic);
            return "Dipendenza circolare durante la costruzione di \"{$d->getClassName()}\" — "
                . 'due classi si richiedono a vicenda nei costruttori.';
        },

    'level_labels' => [
        'DEBUG' => 'Debug',
        'INFO' => 'Info',
        'NOTICE' => 'Avviso',
        'WARNING' => 'Attenzione',
        'ERROR' => 'Errore',
        'CRITICAL' => 'Critico',
        'ALERT' => 'Allerta',
        'EMERGENCY' => 'Emergenza',
    ],
];
