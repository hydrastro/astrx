<?php
declare(strict_types=1);

use AstrX\Api\Diagnostic\ApiNotEnabledDiagnostic;
use AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic;
use AstrX\Api\Diagnostic\InvalidApiKeyLabelDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.api/not_enabled' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ApiNotEnabledDiagnostic);
            return 'Questa pagina non è esposta tramite API.';
        },

    'astrx.api/key_create_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'Impossibile creare la chiave API. Riprova più tardi.';
        },

    'astrx.api/key_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'La chiave API fornita non è valida, è scaduta o è stata revocata.';
        },

    'astrx.api/key_label_required' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyLabelDiagnostic);
            return 'Dai un\'etichetta alla nuova chiave per ricordarti a cosa serve.';
        },

    'astrx.api/key_label_too_long' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyLabelDiagnostic);
            return "L'etichetta della chiave API deve essere di massimo 64 caratteri.";
        },

    'astrx.api/key_create_forbidden' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'Non hai il permesso di creare chiavi API.';
        },

    'astrx.api/key_revoke_forbidden' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidApiKeyDiagnostic);
            return 'Non hai il permesso di revocare chiavi API.';
        },

    'astrx.api/internal_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
            'Si è verificato un errore interno.',
];
